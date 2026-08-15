/**
 * scripts/run-browser-test.sh の契約テスト。
 *
 * Browser lane は「Chromium / WebKit の 2 レーンを、失敗レーンがあっても両方走らせ、
 * 最後に非ゼロで終わる」ことが契約である (T082 / AGENTS.md ドメイン規約 /
 * docs/supported-browsers.md)。この契約はスクリプトを 1 行編集するだけで壊せるため、
 * 実プロセスで振る舞いを固定する。
 *
 * 2 層構成:
 *   層 1 (sandbox 実走): C1〜C4, C8, C10〜C12, C14 — mkdtemp に最小の repo 骨格を組み、
 *         pest / php / 導入スクリプトをスタブへ差し替えて実スクリプトを走らせる。
 *   層 2 (静的契約):    C5〜C7, C9, C13, C15 — orphan 掃除の振る舞いは「PPID が 1 に
 *         reparent する」という subreaper 依存の前提を要するため、実プロセス化すると
 *         偽赤を生む。守りたいのは「掃除ロジックが消される / bug-hunt 除外が消される /
 *         EXIT trap の所有権が奪われる / 事前確認と証跡初期化の位置がずれる」という
 *         編集による退行なので静的検査で足りる
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
    readdirSync,
    rmSync,
    statSync,
    writeFileSync,
} from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { codeLines, lineIndexOf, mutate } from "../tests/js/support/shell-contract";

const REPO_ROOT = process.cwd();
const SCRIPT_PATH = resolve(REPO_ROOT, "scripts/run-browser-test.sh");
const LOCK_LIB_PATH = resolve(REPO_ROOT, "scripts/global-test-lock.sh");
const GITIGNORE_PATH = resolve(REPO_ROOT, ".gitignore");

/** 実ソース (verbatim)。層 2 の正のコントロールと層 1 の sandbox 元になる。 */
function realSource(): string {
    return readFileSync(SCRIPT_PATH, "utf-8");
}

// --------------------------------------------------------------------------
// 層 2: 静的契約 (C5, C6, C7, C9, C13 + 既定値リテラル)
// --------------------------------------------------------------------------

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

    // C9: ブラウザ導入の事前確認は **グローバルテストロックを取る前** に行う。
    // 後ろに置くと、先行レーンの終了を数分待たされてから「ブラウザが入っていません」と
    // 言うことになる (bug-hunt guard と同じ理由)。
    const provisionAt = lineIndexOf(code, "bash scripts/setup-browser-testing.sh");
    const acquireAt = lineIndexOf(code, "global_test_lock_acquire");
    if (provisionAt < 0) {
        violations.push("C9: ブラウザ導入の事前確認 (setup-browser-testing.sh) が消えている");
    } else if (acquireAt >= 0 && provisionAt > acquireAt) {
        violations.push("C9: 事前確認がグローバルテストロック取得より後ろにある");
    }

    // C13: 証跡ディレクトリの初期化は「ロック取得後・レーンループ前」。
    // ロックより前だと並行実行中の別レーンの証跡を消し、ループ後だと前回の残骸を
    // 今回の失敗として拾い上げる。
    const resetAt = lineIndexOf(code, 'rm -rf "${ARTIFACT_DIR}"');
    const loopAt = lineIndexOf(code, "for lane in");
    if (resetAt < 0) {
        violations.push("C13: 証跡ディレクトリの初期化が消えている (前回の残骸を今回の失敗として扱う)");
    } else {
        if (acquireAt >= 0 && resetAt < acquireAt) {
            violations.push("C13: 証跡初期化がロック取得より前にある (並行実行の証跡を消す)");
        }
        if (loopAt >= 0 && resetAt > loopAt) {
            violations.push("C13: 証跡初期化がレーンループより後ろにある");
        }
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

interface SandboxRun {
    /** スクリプトの終了コード。 */
    status: number;
    /** スタブ pest が記録した呼び出し (1 行 = 1 レーン、argv の JSON 配列)。 */
    pestCalls: string[][];
    /** 導入スクリプトのスタブが呼ばれた回数。 */
    provisionCalls: number;
    /** 実行後に storage/browser-test-artifacts 配下に残ったファイル (相対パス・昇順)。 */
    artifacts: string[];
    stderr: string;
}

interface SandboxOptions {
    /** 何レーン目 (1 始まり) を失敗させるか。 */
    failingLanes?: number[];
    /** 失敗レーンの終了コード (既定 1)。 */
    failExitCode?: number;
    /** 追加の環境変数。 */
    env?: Record<string, string>;
    /** 導入スクリプトのスタブの終了コード (既定 0)。 */
    provisionExitCode?: number;
    /** pest スタブが実挙動 (起動時に Screenshots を消してから 1 枚書く) を模すか。 */
    pestWritesScreenshots?: boolean;
    /** pest スタブの終了直前に差し込む追加 shell 行 (異常系の作り込み用)。 */
    pestExtraLines?: string[];
    /** PATH の先頭へ置く追加スタブ (コマンド名 → shell 本文)。 */
    extraStubs?: Record<string, string>;
    /** 実行前に sandbox へ作っておくファイル (sandbox 相対パス → 内容)。 */
    seedFiles?: Record<string, string>;
}

function writeExecutable(path: string, content: string): void {
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, content, "utf-8");
    chmodSync(path, 0o755);
}

/**
 * dir 配下のファイルを再帰列挙して相対パス (昇順) で返す。
 * 退避先が通常ファイルとして作られている異常系 (C14a) もあるので、
 * ディレクトリでなければ空として扱う。
 */
function listFilesRecursively(dir: string, prefix = ""): string[] {
    if (!existsSync(dir) || !statSync(dir).isDirectory()) return [];
    const found: string[] = [];
    for (const entry of readdirSync(dir, { withFileTypes: true })) {
        const relative = prefix === "" ? entry.name : `${prefix}/${entry.name}`;
        if (entry.isDirectory()) {
            found.push(...listFilesRecursively(join(dir, entry.name), relative));
        } else {
            found.push(relative);
        }
    }
    return found.sort();
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
 * @param options        スタブの挙動と事前状態
 */
function runInSandbox(scriptSource: string, options: SandboxOptions = {}): SandboxRun {
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

        // 導入スクリプトのスタブ: 呼び出しを記録し、指定の終了コードで終わる。
        // 実物は Playwright を起動するので sandbox では差し替える (呼ばれたか / 呼ばれた結果を
        // レーンがどう扱うか、が本テストの関心である)。
        const provisionCallsPath = join(sandbox, "provision-calls.log");
        writeExecutable(
            join(sandbox, "scripts/setup-browser-testing.sh"),
            [
                "#!/usr/bin/env bash",
                "set -u",
                `printf 'called\\n' >> "${provisionCallsPath}"`,
                `exit ${options.provisionExitCode ?? 0}`,
            ].join("\n"),
        );

        // php スタブ: 何もせず即座に成功する。
        //
        // **意図的に sleep を入れない**: T104 は pgid probe の race (既に終了した pid に
        // 対する ps の非ゼロが `set -euo pipefail` 下の代入へ伝播し、レーンごと落ちる)
        // を回避するためここに `sleep 0.1` を置いていた。T112 で global-test-lock.sh 側を
        // `|| pgid=""` で正しく直したので回避策は撤去した。
        // **この「sleep が無いこと」自体が回帰テストである**: race が戻れば
        // スタブが sub-millisecond で終わって本契約テストが落ちる。
        writeExecutable(join(sandbox, "bin/php"), "#!/usr/bin/env bash\nexit 0\n");

        for (const [name, body] of Object.entries(options.extraStubs ?? {})) {
            writeExecutable(join(sandbox, "bin", name), body);
        }

        for (const [relative, content] of Object.entries(options.seedFiles ?? {})) {
            const path = join(sandbox, relative);
            mkdirSync(dirname(path), { recursive: true });
            writeFileSync(path, content, "utf-8");
        }

        const callsPath = join(sandbox, "pest-calls.jsonl");
        const failing = options.failingLanes ?? [];
        const failExitCode = options.failExitCode ?? 1;
        // pest スタブ: argv を JSONL で追記し、指定回目の呼び出しだけ非ゼロで終わる。
        //
        // pestWritesScreenshots のときは pest-plugin-browser の実挙動を模す:
        // **起動のたびに tests/Browser/Screenshots を丸ごと消してから**書く
        // (この挙動があるからレーンごとの退避が要る = C11 が守る不変条件そのもの)。
        writeExecutable(
            join(sandbox, "vendor/bin/pest"),
            [
                "#!/usr/bin/env bash",
                "set -u",
                // bin/php と同じ理由で sleep は入れない (T112 で race を根治済み)
                `CALLS="${callsPath}"`,
                // argv を JSON 配列へ (jq に依存しない素朴なエスケープ: 実引数に " や \\ は現れない)
                'out="["',
                "first=1",
                'for a in "$@"; do',
                '  if [ "$first" = "1" ]; then first=0; else out="${out},"; fi',
                '  esc="${a//\\\\/\\\\\\\\}"',
                '  esc="${esc//\\"/\\\\\\"}"',
                '  out="${out}\\"${esc}\\""',
                "done",
                'out="${out}]"',
                'printf "%s\\n" "$out" >> "$CALLS"',
                'n=$(wc -l < "$CALLS" | tr -d " ")',
                ...(options.pestWritesScreenshots === true
                    ? [
                          'rm -rf "tests/Browser/Screenshots"',
                          'mkdir -p "tests/Browser/Screenshots"',
                          'printf "png\\n" > "tests/Browser/Screenshots/lane-${n}.png"',
                      ]
                    : []),
                ...(options.pestExtraLines ?? []),
                `for f in ${failing.join(" ") || "''"}; do`,
                `  [ -n "$f" ] && [ "$n" = "$f" ] && exit ${failExitCode}`,
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

        const provisionCalls = existsSync(provisionCallsPath)
            ? readFileSync(provisionCallsPath, "utf-8").split("\n").filter((l) => l.trim() !== "").length
            : 0;

        return {
            status: result.status ?? -1,
            pestCalls,
            provisionCalls,
            artifacts: listFilesRecursively(join(sandbox, "storage/browser-test-artifacts")),
            stderr: result.stderr ?? "",
        };
    } finally {
        rmSync(sandbox, { recursive: true, force: true });
    }
}

// --------------------------------------------------------------------------

describe("run-browser-test.sh 層 2: 静的契約 (C5, C6, C7, C9, C13)", () => {
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
            "${BROWSER_TEST_LANES:-chromium webkit}",
            "${BROWSER_TEST_LANES:-chromium}",
        );
        expect(staticContractViolations(broken)).toContain("既定レーンが chromium webkit でない");
    });

    it("C9: 事前確認をロック取得の後ろへ移すと違反を返す", () => {
        const source = realSource();
        const withoutProvision = mutate(source, "bash scripts/setup-browser-testing.sh\n", "");
        const broken = mutate(
            withoutProvision,
            'global_test_lock_acquire "composer test:browser"',
            'global_test_lock_acquire "composer test:browser"\nbash scripts/setup-browser-testing.sh',
        );
        expect(staticContractViolations(broken)).toContain(
            "C9: 事前確認がグローバルテストロック取得より後ろにある",
        );
    });

    it("C9: 事前確認そのものを削ると違反を返す", () => {
        const broken = mutate(realSource(), "bash scripts/setup-browser-testing.sh\n", "");
        expect(staticContractViolations(broken)).toContain(
            "C9: ブラウザ導入の事前確認 (setup-browser-testing.sh) が消えている",
        );
    });

    it("C13: 証跡初期化を削ると違反を返す", () => {
        const broken = mutate(realSource(), 'rm -rf "${ARTIFACT_DIR}"\n', "");
        expect(staticContractViolations(broken)).toContain(
            "C13: 証跡ディレクトリの初期化が消えている (前回の残骸を今回の失敗として扱う)",
        );
    });

    it("C13: 証跡初期化をロック取得より前へ移すと違反を返す", () => {
        const source = realSource();
        const removed = mutate(source, 'rm -rf "${ARTIFACT_DIR}"\n', "");
        const broken = mutate(
            removed,
            "# --- グローバルテストロック (旧 worktree-local ロックを置き換え) ---",
            'rm -rf "${ARTIFACT_DIR}"\n# --- グローバルテストロック (旧 worktree-local ロックを置き換え) ---',
        );
        expect(staticContractViolations(broken)).toContain(
            "C13: 証跡初期化がロック取得より前にある (並行実行の証跡を消す)",
        );
    });
});

describe("run-browser-test.sh 層 2: C15 (.gitignore 登録)", () => {
    it("C15: 退避先が .gitignore に登録されていること", () => {
        // 登録漏れは Browser テスト実行後の worktree を恒常的に dirty にし、
        // scripts/teardown-worktree.sh の dirty チェックを常時失敗させる。
        const lines = readFileSync(GITIGNORE_PATH, "utf-8")
            .split("\n")
            .map((l) => l.trim());
        expect(lines).toContain("/storage/browser-test-artifacts/");
    });
});

describe("run-browser-test.sh 層 1: sandbox 実走 (C1〜C4, C8)", () => {
    it("C1/C4: 既定で chrome → safari の 2 レーンを直列に走らせる", { timeout: 30_000 }, () => {
        const run = runInSandbox(realSource());

        expect(run.status).toBe(0);
        expect(run.pestCalls).toHaveLength(2);
        // 事前確認はレーン起動前にちょうど 1 回
        expect(run.provisionCalls).toBe(1);
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

describe("run-browser-test.sh 層 1: 事前確認と証跡退避 (C10〜C12, C14)", () => {
    it("C10: 事前確認が失敗したらレーンを 1 本も起動しない", { timeout: 30_000 }, () => {
        const run = runInSandbox(realSource(), { provisionExitCode: 1 });

        expect(run.provisionCalls).toBe(1);
        expect(run.pestCalls).toHaveLength(0);
        expect(run.status).not.toBe(0);
    });

    it(
        "C11: 2 レーン走らせても先行レーンの証跡が残る (失敗レーンでも退避される)",
        { timeout: 30_000 },
        () => {
            const run = runInSandbox(realSource(), {
                failingLanes: [1],
                pestWritesScreenshots: true,
            });

            expect(run.pestCalls).toHaveLength(2);
            expect(run.artifacts).toEqual(["chromium/lane-1.png", "webkit/lane-2.png"]);
        },
    );

    it(
        "C11 負のコントロール: 退避をループの外へ移すと先行レーンの証跡が消える",
        { timeout: 30_000 },
        () => {
            const broken = mutate(
                realSource(),
                '    collect_lane_artifacts "${lane}"\n\n    cleanup_orphan_playwright\ndone',
                '    cleanup_orphan_playwright\ndone\n\ncollect_lane_artifacts "${lane}"',
            );
            const run = runInSandbox(broken, { failingLanes: [1], pestWritesScreenshots: true });

            expect(run.pestCalls).toHaveLength(2);
            expect(run.artifacts).toEqual(["webkit/lane-2.png"]);
        },
    );

    it("C12: 前回実行の残骸を持ち越さない", { timeout: 30_000 }, () => {
        const run = runInSandbox(realSource(), {
            seedFiles: { "storage/browser-test-artifacts/stale/old.png": "png\n" },
        });

        expect(run.status).toBe(0);
        expect(run.artifacts).toEqual([]);
    });

    it("C12 負のコントロール: 初期化を削ると残骸が残る", { timeout: 30_000 }, () => {
        const broken = mutate(realSource(), 'rm -rf "${ARTIFACT_DIR}"\n', "");
        const run = runInSandbox(broken, {
            seedFiles: { "storage/browser-test-artifacts/stale/old.png": "png\n" },
        });

        expect(run.artifacts).toEqual(["stale/old.png"]);
    });

    it(
        "C14a: 退避先を作れなくてもレーンの終了コードを上書きしない",
        { timeout: 30_000 },
        () => {
            // **失敗条件は初期化の後に作る**: スクリプトはレーンループの前に
            // rm -rf "${ARTIFACT_DIR}" するので、実行前に置いたファイルは消えてしまい
            // mkdir -p が成功してしまう。pest スタブに「退避先を通常ファイルとして作る」まで
            // やらせることで、mkdir -p を確実に失敗させる。
            const run = runInSandbox(realSource(), {
                env: { BROWSER_TEST_LANES: "chromium" },
                failingLanes: [1],
                failExitCode: 23,
                pestWritesScreenshots: true,
                pestExtraLines: [
                    'mkdir -p "storage"',
                    'printf "not-a-dir\\n" > "storage/browser-test-artifacts"',
                ],
            });

            expect(run.stderr).toContain("WARNING");
            expect(run.status).toBe(23);
        },
    );

    it("C14b: 複製に失敗してもレーンの終了コードを上書きしない", { timeout: 30_000 }, () => {
        // mkdir -p 側と cp 側は別の分岐なので、片方だけでは不変条件を固定できない。
        //
        // スタブは **条件付き** にする: 退避先 (storage/browser-test-artifacts/) を宛先に持つ
        // 複製だけ非ゼロを返し、それ以外は実 cp へ委譲する。無条件スタブにすると
        // 「退避以外で cp を使う将来の変更」にも反応してしまい、検査の意味が広がりすぎる。
        const run = runInSandbox(realSource(), {
            env: { BROWSER_TEST_LANES: "chromium" },
            failingLanes: [1],
            failExitCode: 23,
            pestWritesScreenshots: true,
            extraStubs: {
                cp: [
                    "#!/usr/bin/env bash",
                    "set -u",
                    'for a in "$@"; do',
                    '  case "$a" in',
                    "    storage/browser-test-artifacts/*|*/storage/browser-test-artifacts/*)",
                    '      echo "cp stub: 退避の複製だけ失敗させる" >&2',
                    "      exit 1 ;;",
                    "  esac",
                    "done",
                    'exec /bin/cp "$@"',
                    "",
                ].join("\n"),
            },
        });

        expect(run.stderr).toContain("WARNING");
        expect(run.status).toBe(23);
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
