/**
 * scripts/run-browser-test.sh の契約テスト。
 *
 * Browser lane は「Chromium / WebKit の 2 レーンを、失敗レーンがあっても両方走らせ、
 * 最後に非ゼロで終わる」ことが契約である (T082 / AGENTS.md ドメイン規約 /
 * docs/supported-browsers.md)。この契約はスクリプトを 1 行編集するだけで壊せるため、
 * 実プロセスで振る舞いを固定する。
 *
 * 2 層構成:
 *   層 1 (sandbox 実走): C1〜C4, C8 — mkdtemp に最小の repo 骨格を組み、pest / php を
 *         スタブへ差し替えて実スクリプトを走らせる。
 *   層 2 (静的契約):    C5〜C7 — orphan 掃除の振る舞いは「PPID が 1 に reparent する」
 *         という subreaper 依存の前提を要するため、実プロセス化すると偽赤を生む。
 *         守りたいのは「掃除ロジックが消される / bug-hunt 除外が消される / EXIT trap の
 *         所有権が奪われる」という編集による退行なので静的検査で足りる
 *         (tests/Architecture/GlobalTestLockInventoryTest.php と同方針)。
 *
 * GLOBAL_TEST_LOCK_DIR の使用について: これは scripts/global-test-lock.sh が
 * 「self-test only」として明示サポートする override であり、本テスト自身が
 * `pnpm test` のグローバルロックを保持したまま走っても自己デッドロックしないために使う。
 * GlobalTestLockInventoryTest が禁じているのは **lane スクリプトが自分で設定すること**
 * であって、テストハーネスが env で渡すことは対象外である。
 *
 * 実行: pnpm test (vitest の include に scripts/**\/*.test.ts が含まれる)
 */
import { describe, expect, it } from "vitest";
import { spawnSync } from "node:child_process";
import {
    chmodSync,
    copyFileSync,
    existsSync,
    mkdirSync,
    mkdtempSync,
    readFileSync,
    rmSync,
    writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";

const REPO_ROOT = process.cwd();
const SCRIPT_PATH = resolve(REPO_ROOT, "scripts/run-browser-test.sh");
const LOCK_LIB_PATH = resolve(REPO_ROOT, "scripts/global-test-lock.sh");

/** 実ソース (verbatim)。層 2 の正のコントロールと層 1 の sandbox 元になる。 */
function realSource(): string {
    return readFileSync(SCRIPT_PATH, "utf-8");
}

// --------------------------------------------------------------------------
// 層 2: 静的契約 (C5, C6, C7 + 既定値リテラル)
// --------------------------------------------------------------------------

/**
 * 行頭 (空白除く) が `#` の行を落とす。方針説明コメントで偽赤にしないため
 * (tests/Architecture/GlobalTestLockInventoryTest.php の globalTestLockCodeLines と同方針)。
 */
export function codeLines(source: string): string {
    return source
        .split("\n")
        .filter((line) => !/^\s*#/.test(line))
        .join("\n");
}

/**
 * 静的契約の違反を列挙する純関数。
 * fixture 文字列を渡せるようにして、負のコントロール (検出器が空振りしていないこと) を
 * 同じ層で確認できるようにする。
 */
export function staticContractViolations(source: string): string[] {
    const code = codeLines(source);
    const violations: string[] = [];

    // C5: orphan (PPID==1) の playwright run-server を掃除する
    if (!code.includes('pgrep -f "playwright/cli.js run-server"')) {
        violations.push('C5: pgrep -f "playwright/cli.js run-server" が消えている');
    }
    // `!=` を除外する: 反転条件 (`[ "${ppid}" != "1" ]`) は「orphan **以外**を kill する」
    // という真逆の実装であり、素朴な `= "1"` 検査だと素通りしてしまう (impl-review R1 [Suggestion])。
    if (!/"\$\{ppid\}"\s*(?<![!])=\s*"1"/.test(code) || /"\$\{ppid\}"\s*!=\s*"1"/.test(code)) {
        violations.push("C5: PPID==1 の判定が消えている (または反転している)");
    }

    // C6: bug-hunt (@playwright/cli) は掃除しない
    if (!code.includes('*"@playwright/"*) continue ;;')) {
        violations.push("C6: @playwright/ の除外が消えている (bug-hunt を巻き込む)");
    }

    // C7: EXIT trap の所有者はライブラリ 1 箇所
    if (!code.includes("global_test_lock_on_exit cleanup_orphan_playwright")) {
        violations.push("C7: global_test_lock_on_exit への登録が消えている");
    }
    if (/\btrap\b[^\n]*\bEXIT\b/.test(code)) {
        violations.push("C7: 自前の trap ... EXIT が張られている (ロックが解放されなくなる)");
    }

    // 既定値リテラル (層 1 の振る舞い検査と二重化する保険)
    if (!code.includes("${BROWSER_TEST_PROCESSES:-1}")) {
        violations.push("既定並列度が 1 でない (BROWSER_TEST_PROCESSES:-1 が消えている)");
    }
    if (!code.includes("${BROWSER_TEST_LANES:-chromium webkit}")) {
        violations.push("既定レーンが chromium webkit でない");
    }

    return violations;
}

// --------------------------------------------------------------------------
// 層 1: sandbox 実走
// --------------------------------------------------------------------------

/** 元ソースの `from` を `to` に 1 箇所だけ置換する。置換が成立しなければ throw (空振り防止)。 */
export function mutate(source: string, from: string, to: string): string {
    const occurrences = source.split(from).length - 1;
    if (occurrences !== 1) {
        throw new Error(`mutation target must appear exactly once (found ${occurrences}): ${from}`);
    }
    const mutated = source.replace(from, to);
    if (mutated === source) throw new Error(`mutation did not change the source: ${from}`);
    if (!mutated.includes(to)) throw new Error(`mutated source lacks the expected token: ${to}`);
    return mutated;
}

interface SandboxRun {
    /** スクリプトの終了コード。 */
    status: number;
    /** スタブ pest が記録した呼び出し (1 行 = 1 レーン、argv の JSON 配列)。 */
    pestCalls: string[][];
    stderr: string;
}

function writeExecutable(path: string, content: string): void {
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, content, "utf-8");
    chmodSync(path, 0o755);
}

/**
 * bug-hunt 併走の pre-flight guard (127.0.0.1:8010..8018) と同じ検査をテスト側でも行う。
 * ★ bug-hunt の並列 cap は 4 だが、guard 側と同じく残留 serve 検出のためポート範囲は
 *   cap と同期させず :8018 まで広く取る (広い方が偽赤に倒れて安全)。
 * listen していたら **明示メッセージで fail** させる (silent skip にしない =
 * 「担保されていない」を隠さない)。docs/testing-browser.md が併走を既に非推奨としている。
 */
function assertNoBughuntPorts(): void {
    for (let port = 8010; port <= 8018; port += 1) {
        const probe = spawnSync(
            "bash",
            ["-c", `(exec 3<>/dev/tcp/127.0.0.1/${port}) 2>/dev/null`],
            { encoding: "utf-8" },
        );
        if (probe.status === 0) {
            throw new Error(
                `bug-hunt 環境が 127.0.0.1:${port} で listen 中のため、run-browser-test.sh の ` +
                    "pre-flight guard が発火して契約テストを実行できません " +
                    "(scripts/bug-hunt-shard.sh teardown で停止してから再実行してください)。",
            );
        }
    }
}

/**
 * sandbox に最小の repo 骨格を組み、渡された script source を実行する。
 *
 * @param scriptSource   実行する run-browser-test.sh の内容 (verbatim または改変コピー)
 * @param failingLanes   何レーン目 (1 始まり) を exit 1 にするか
 * @param env            追加の環境変数
 */
function runInSandbox(
    scriptSource: string,
    options: { failingLanes?: number[]; env?: Record<string, string> } = {},
): SandboxRun {
    assertNoBughuntPorts();

    const sandbox = mkdtempSync(join(tmpdir(), "run-browser-test-contract-"));
    try {
        mkdirSync(join(sandbox, "scripts/ci"), { recursive: true });
        mkdirSync(join(sandbox, "vendor/bin"), { recursive: true });
        mkdirSync(join(sandbox, "bin"), { recursive: true });

        writeFileSync(join(sandbox, "scripts/run-browser-test.sh"), scriptSource, "utf-8");
        chmodSync(join(sandbox, "scripts/run-browser-test.sh"), 0o755);
        // ライブラリは実ファイルをコピー (ロック取得の実挙動をそのまま使う)
        copyFileSync(LOCK_LIB_PATH, join(sandbox, "scripts/global-test-lock.sh"));

        // php 自体をスタブ化するので中身は不要
        writeFileSync(join(sandbox, "scripts/ci/ensure-test-db.php"), "<?php\n", "utf-8");
        writeFileSync(join(sandbox, "artisan"), "<?php\n", "utf-8");
        writeFileSync(join(sandbox, "phpunit.browser.xml"), "<phpunit/>\n", "utf-8");

        // php スタブ: 何もせず即座に成功する。
        //
        // **意図的に sleep を入れない**: T104 は pgid probe の race (既に終了した pid に
        // 対する ps の非ゼロが `set -euo pipefail` 下の代入へ伝播し、レーンごと落ちる)
        // を回避するためここに `sleep 0.1` を置いていた。T112 で global-test-lock.sh 側を
        // `|| pgid=""` で正しく直したので回避策は撤去した。
        // **この「sleep が無いこと」自体が回帰テストである**: race が戻れば
        // スタブが sub-millisecond で終わって本契約テストが落ちる。
        writeExecutable(join(sandbox, "bin/php"), "#!/usr/bin/env bash\nexit 0\n");

        const callsPath = join(sandbox, "pest-calls.jsonl");
        const failing = options.failingLanes ?? [];
        // pest スタブ: argv を JSONL で追記し、指定回目の呼び出しだけ exit 1 する
        writeExecutable(
            join(sandbox, "vendor/bin/pest"),
            [
                "#!/usr/bin/env bash",
                "set -u",
                // bin/php と同じ理由で sleep は入れない (T112 で race を根治済み)
                `CALLS="${callsPath}"`,
                // argv を JSON 配列へ (jq に依存しない素朴なエスケープ: 実引数に " や \\ は現れない)
                'out="["',
                'first=1',
                'for a in "$@"; do',
                '  if [ "$first" = "1" ]; then first=0; else out="${out},"; fi',
                '  esc="${a//\\\\/\\\\\\\\}"',
                '  esc="${esc//\\"/\\\\\\"}"',
                '  out="${out}\\"${esc}\\""',
                "done",
                'out="${out}]"',
                'printf "%s\\n" "$out" >> "$CALLS"',
                'n=$(wc -l < "$CALLS" | tr -d " ")',
                `for f in ${failing.join(" ") || "''"}; do`,
                '  [ -n "$f" ] && [ "$n" = "$f" ] && exit 1',
                "done",
                "exit 0",
            ].join("\n"),
        );

        const childEnv: NodeJS.ProcessEnv = {
            ...process.env,
            PATH: `${join(sandbox, "bin")}:${process.env.PATH ?? ""}`,
            // ライブラリが self-test 用として明示サポートする override。
            // 本テストが pnpm test のロック下で走っても自己デッドロックしないために必要。
            GLOBAL_TEST_LOCK_DIR: join(sandbox, "lockdir"),
        };
        // **既定値の契約テストなので、呼び出し元環境のレーン変数を必ず落とす**。
        // 開発者が BROWSER_TEST_LANES を export していると「既定は 2 レーン・直列」の
        // 検査が環境依存で偽赤になる。注入は options.env 経由の明示指定のみに限る。
        delete childEnv.BROWSER_TEST_LANES;
        delete childEnv.BROWSER_TEST_PROCESSES;
        Object.assign(childEnv, options.env ?? {});

        const result = spawnSync("bash", [join(sandbox, "scripts/run-browser-test.sh")], {
            encoding: "utf-8",
            env: childEnv,
        });

        const pestCalls = existsSync(callsPath)
            ? readFileSync(callsPath, "utf-8")
                  .split("\n")
                  .filter((l) => l.trim() !== "")
                  .map((l) => JSON.parse(l) as string[])
            : [];

        return { status: result.status ?? -1, pestCalls, stderr: result.stderr ?? "" };
    } finally {
        rmSync(sandbox, { recursive: true, force: true });
    }
}

// --------------------------------------------------------------------------

describe("run-browser-test.sh 層 2: 静的契約 (C5, C6, C7)", () => {
    it("現行の実ソースは違反 0 件 (正のコントロール)", () => {
        expect(staticContractViolations(realSource())).toEqual([]);
    });

    it("C6: @playwright/ 除外を削ると違反を返す", () => {
        const broken = mutate(
            realSource(),
            '            *"@playwright/"*) continue ;;   # bug-hunt の @playwright/cli は触らない\n',
            "",
        );
        expect(staticContractViolations(broken)).toContain(
            "C6: @playwright/ の除外が消えている (bug-hunt を巻き込む)",
        );
    });

    it("C7: 自前 trap ... EXIT に戻すと違反を返す", () => {
        const broken = mutate(
            realSource(),
            "global_test_lock_on_exit cleanup_orphan_playwright",
            "trap cleanup_orphan_playwright EXIT",
        );
        const violations = staticContractViolations(broken);
        expect(violations).toContain("C7: global_test_lock_on_exit への登録が消えている");
        expect(violations).toContain(
            "C7: 自前の trap ... EXIT が張られている (ロックが解放されなくなる)",
        );
    });

    it("C5: pgrep の掃除を削ると違反を返す", () => {
        const broken = mutate(
            realSource(),
            'pgrep -f "playwright/cli.js run-server"',
            'pgrep -f "nothing-to-clean"',
        );
        expect(staticContractViolations(broken)).toContain(
            'C5: pgrep -f "playwright/cli.js run-server" が消えている',
        );
    });

    it("C5: PPID 判定を反転すると違反を返す (orphan 以外を kill する真逆の実装)", () => {
        const broken = mutate(realSource(), 'if [ "${ppid}" = "1" ]; then', 'if [ "${ppid}" != "1" ]; then');
        expect(staticContractViolations(broken)).toContain(
            "C5: PPID==1 の判定が消えている (または反転している)",
        );
    });

    it("既定レーンを chromium だけに狭めると違反を返す", () => {
        const broken = mutate(
            realSource(),
            '${BROWSER_TEST_LANES:-chromium webkit}',
            '${BROWSER_TEST_LANES:-chromium}',
        );
        expect(staticContractViolations(broken)).toContain(
            "既定レーンが chromium webkit でない",
        );
    });
});

describe("run-browser-test.sh 層 1: sandbox 実走 (C1〜C4, C8)", () => {
    it("C1/C4: 既定で chrome → safari の 2 レーンを直列に走らせる", { timeout: 30_000 }, () => {
        const run = runInSandbox(realSource());

        expect(run.status).toBe(0);
        expect(run.pestCalls).toHaveLength(2);
        // C1: レーン名の写像と順序
        expect(run.pestCalls[0]).toContain("--browser");
        expect(run.pestCalls[0][run.pestCalls[0].indexOf("--browser") + 1]).toBe("chrome");
        expect(run.pestCalls[1][run.pestCalls[1].indexOf("--browser") + 1]).toBe("safari");
        // C1: browser 用の phpunit 設定を使う
        for (const call of run.pestCalls) {
            expect(call).toContain("-c");
            expect(call).toContain("phpunit.browser.xml");
            // C4: 既定は直列 = parallel runner を使わない
            expect(call).not.toContain("--parallel");
            expect(call.some((a) => a.startsWith("--processes"))).toBe(false);
        }
    });

    it(
        "C2/C3: 先頭レーンが失敗しても後続レーンを実行し、overall は非ゼロ",
        { timeout: 30_000 },
        () => {
            const run = runInSandbox(realSource(), { failingLanes: [1] });

            expect(run.pestCalls).toHaveLength(2); // C2: webkit が飛ばされていない
            expect(run.status).not.toBe(0); // C3
        },
    );

    it("C8: 未知のレーン名は exit 2", { timeout: 30_000 }, () => {
        // "chrome" は playwright 側の名前であってレーン名ではない
        const run = runInSandbox(realSource(), { env: { BROWSER_TEST_LANES: "chrome" } });

        expect(run.status).toBe(2);
        expect(run.pestCalls).toHaveLength(0);
    });
});

describe("run-browser-test.sh 層 1 の負のコントロール (検査が空振りしていないこと)", () => {
    it("C2 検査: 失敗時に break する改変では 1 レーンしか走らない", { timeout: 30_000 }, () => {
        const broken = mutate(
            realSource(),
            '    if [ "${code}" -ne 0 ]; then\n        overall="${code}"\n    fi',
            '    if [ "${code}" -ne 0 ]; then\n        overall="${code}"\n        break\n    fi',
        );
        const run = runInSandbox(broken, { failingLanes: [1] });

        expect(run.pestCalls).toHaveLength(1);
    });

    it("C3 検査: 最後に exit 0 する改変では失敗が握り潰される", { timeout: 30_000 }, () => {
        const broken = mutate(realSource(), 'exit "${overall}"', "exit 0");
        const run = runInSandbox(broken, { failingLanes: [1, 2] });

        expect(run.pestCalls).toHaveLength(2);
        expect(run.status).toBe(0);
    });

    it("C4 検査: 既定並列度を 2 にすると --parallel が現れる", { timeout: 30_000 }, () => {
        const broken = mutate(
            realSource(),
            'PROCESSES="${BROWSER_TEST_PROCESSES:-1}"',
            'PROCESSES="${BROWSER_TEST_PROCESSES:-2}"',
        );
        const run = runInSandbox(broken);

        expect(run.pestCalls).toHaveLength(2);
        expect(run.pestCalls[0]).toContain("--parallel");
    });
});

describe("mutate() ヘルパ自身の保証 (負のコントロールが空振りしないこと)", () => {
    it("置換対象が存在しなければ throw", () => {
        expect(() => mutate("abc", "zzz", "yyy")).toThrow(/must appear exactly once \(found 0\)/);
    });

    it("置換対象が複数あれば throw", () => {
        expect(() => mutate("aa", "a", "b")).toThrow(/must appear exactly once \(found 2\)/);
    });

    it("1 箇所だけなら置換して返す", () => {
        expect(mutate("abc", "b", "X")).toBe("aXc");
    });
});
