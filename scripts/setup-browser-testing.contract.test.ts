/**
 * scripts/setup-browser-testing.sh の契約テスト。
 *
 * 導入の知識をスクリプト 1 本に集約した以上、「対象ブラウザは chromium + webkit である」
 * 「OS 共有ライブラリが不足しているのに権限が無ければ特権経路を起こす前に落ちる」
 * 「判定不能は拒否側に倒す」といった契約はこのファイルでしか守れない
 * (ci.yml 側の gate は導入スクリプトを呼んでいることまでしか見ない)。
 *
 * 3 層構成:
 *   層 1: `--self-test` を実プロセスで走らせ、決定表の自己検査が緑であることと
 *         **ケース数の下限**を確認する (自己検査を空にして緑にする逃げを塞ぐ)。
 *   層 2: 静的契約 (P1〜P6) と負のコントロール。
 *   層 3: sandbox 実走 (S1〜S7) と、pin された実 Playwright の出力との突合。
 *
 * 実行: pnpm test (vitest の include に scripts/**\/*.test.ts が含まれる)
 */
import { describe, expect, it } from "vitest";
import { spawnSync } from "node:child_process";
import { chmodSync, existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";
import { codeLines, mutate } from "../tests/js/support/shell-contract";

const REPO_ROOT = process.cwd();
const SCRIPT_PATH = resolve(REPO_ROOT, "scripts/setup-browser-testing.sh");

/** `--self-test` が最低限持つべきケース数。決定表の全網羅が 19 ケースある。 */
const SELF_TEST_MIN_CASES = 19;

function realSource(): string {
    return readFileSync(SCRIPT_PATH, "utf-8");
}

/** スクリプト内の shell 定数 (`NAME='値'`) を取り出す。実 CLI smoke が文言を再実装しないため。 */
export function extractShellConst(source: string, name: string): string {
    const match = new RegExp(`^${name}='([^']*)'`, "m").exec(source);
    if (match === null) throw new Error(`shell const not found: ${name}`);
    return match[1];
}

// --------------------------------------------------------------------------
// 層 2: 静的契約 (P1〜P6)
// --------------------------------------------------------------------------

/**
 * 静的契約の違反を列挙する純関数 (fixture でも駆動できるようにする)。
 */
export function provisioningStaticViolations(source: string): string[] {
    const code = codeLines(source);
    const violations: string[] = [];

    // P1: 対象ブラウザ集合は chromium + webkit (run-browser-test.sh の既定レーンと 1 対 1)
    if (!code.includes("BROWSER_TARGETS=(chromium webkit)")) {
        violations.push("P1: 対象ブラウザ集合が (chromium webkit) でない");
    }

    // P2: レーン変数へ代入しない (ci.yml の W9 が守る「CI がレーンを絞れない」前提を壊さない)
    for (const name of ["BROWSER_TEST_LANES", "BROWSER_TEST_PROCESSES"]) {
        if (new RegExp(`^\\s*(export\\s+)?${name}=`, "m").test(code)) {
            violations.push(`P2: ${name} へ代入している (レーン契約を導入スクリプトから壊せる)`);
        }
    }

    // P3: 導入ロックの置き場は「既定値つき展開 1 箇所」だけ。
    // スクリプト自身が代入すると、テスト用 override が本番経路を変える口になる。
    if (/^\s*(export\s+)?BROWSER_PROVISION_LOCK_DIR=/m.test(code)) {
        violations.push("P3: BROWSER_PROVISION_LOCK_DIR へ代入している");
    }
    const lockDirRefs = code.match(/BROWSER_PROVISION_LOCK_DIR/g) ?? [];
    if (lockDirRefs.length !== 1 || !code.includes("${BROWSER_PROVISION_LOCK_DIR:-/tmp}")) {
        violations.push("P3: 導入ロックの置き場が既定値つき展開 1 箇所でない");
    }

    // P4: --with-deps は「不足あり かつ 権限あり」の 1 経路にだけ現れる。
    // 無条件付与へ広げると、不足していない環境でも apt-get を起こす。
    const withDeps = code.match(/--with-deps/g) ?? [];
    if (withDeps.length !== 1) {
        violations.push(`P4: --with-deps の出現が 1 回でない (${withDeps.length} 回)`);
    }

    // P5: 起こしてよい sudo は非対話 (`sudo -n`) だけ。
    // 対話 sudo はスクリプト経由の呼び出しを無言のパスワード待ちで止める。
    //
    // 走査は「コマンド位置の sudo」= 行頭またはコマンド区切りの直後にあり、
    // 直後に引数が続くものだけを見る。`printf 'sudo\n'` (分類結果の文字列) や
    // `root|sudo)` (case ラベル) を巻き込むと偽陽性になるためである。
    // 案内文 (echo) と存在確認 (`command -v sudo`) も実行ではないので外す。
    const executable = code
        .split("\n")
        .filter((line) => !/^\s*echo\b/.test(line))
        .join("\n")
        .replace(/command -v sudo/g, "");
    for (const [, argument] of executable.matchAll(/(?:^|[\s;&|(])sudo\s+(\S+)/g)) {
        if (argument !== "-n") {
            violations.push(`P5: 非対話でない sudo 実行がある (sudo ${argument})`);
        }
    }

    // P6: flock(1) が無い環境は警告 1 行を出して排他なしで続行する
    // (global-test-lock.sh の既存方針を踏襲。黙って落とさない / 黙って握り潰さない)
    if (!code.includes("command -v flock") || !code.includes("WARNING: flock(1)")) {
        violations.push("P6: flock 不在時の警告つき続行の分岐が消えている");
    }

    return violations;
}

// --------------------------------------------------------------------------
// 層 3: sandbox 実走
// --------------------------------------------------------------------------

interface ProvisionRun {
    status: number;
    stdout: string;
    stderr: string;
    /** スタブが記録した外部コマンド呼び出し ("<コマンド> <argv...>" の行)。 */
    calls: string[];
}

interface ProvisionOptions {
    /** `uname -s` が返す文字列。 */
    os?: string;
    /** `playwright install-deps --dry-run` の標準出力。 */
    depsOutput?: string;
    /** 同・終了コード。 */
    depsExit?: number;
    /** `id -u` が返す値 (0 = root)。 */
    uid?: string;
    /** `sudo -n true` の終了コード (0 = 非対話 sudo が使える)。 */
    sudoExit?: number;
    /** スクリプトへ渡す引数。 */
    args?: string[];
}

function writeExecutable(path: string, content: string): void {
    mkdirSync(dirname(path), { recursive: true });
    writeFileSync(path, content, "utf-8");
    chmodSync(path, 0o755);
}

/**
 * sandbox に PATH スタブ (`pnpm` / `uname` / `id` / `sudo` / `flock`) を置き、
 * 実スクリプトを走らせて外部コマンド呼び出しを記録する。
 */
function runProvisionSandbox(source: string, options: ProvisionOptions = {}): ProvisionRun {
    const sandbox = mkdtempSync(join(tmpdir(), "setup-browser-testing-contract-"));
    try {
        writeExecutable(join(sandbox, "scripts/setup-browser-testing.sh"), source);

        const callsPath = join(sandbox, "calls.log");
        const record = `printf '%s %s\\n' "$(basename "$0")" "$*" >> "${callsPath}"`;

        const depsOutput = options.depsOutput ?? "All system dependencies are installed.";
        const depsExit = options.depsExit ?? 0;

        writeExecutable(
            join(sandbox, "bin/pnpm"),
            [
                "#!/usr/bin/env bash",
                "set -u",
                record,
                'case "$*" in',
                '  *"install-deps --dry-run"*)',
                `      printf '%s\\n' ${JSON.stringify(depsOutput)}`,
                `      exit ${depsExit} ;;`,
                '  *"--version"*)',
                "      printf 'Version 1.61.1\\n'",
                "      exit 0 ;;",
                "esac",
                "exit 0",
            ].join("\n"),
        );
        writeExecutable(
            join(sandbox, "bin/uname"),
            ["#!/usr/bin/env bash", "set -u", record, `printf '%s\\n' ${JSON.stringify(options.os ?? "Linux")}`].join(
                "\n",
            ),
        );
        writeExecutable(
            join(sandbox, "bin/id"),
            ["#!/usr/bin/env bash", "set -u", `printf '%s\\n' ${JSON.stringify(options.uid ?? "1000")}`].join("\n"),
        );
        writeExecutable(
            join(sandbox, "bin/sudo"),
            ["#!/usr/bin/env bash", "set -u", record, `exit ${options.sudoExit ?? 0}`].join("\n"),
        );
        writeExecutable(join(sandbox, "bin/flock"), ["#!/usr/bin/env bash", "set -u", record, "exit 0"].join("\n"));

        const result = spawnSync("bash", [join(sandbox, "scripts/setup-browser-testing.sh"), ...(options.args ?? [])], {
            encoding: "utf-8",
            env: {
                ...process.env,
                PATH: `${join(sandbox, "bin")}:${process.env.PATH ?? ""}`,
                // 実 /tmp の導入ロックを取らない (既存 GLOBAL_TEST_LOCK_DIR の扱いと同じ)
                BROWSER_PROVISION_LOCK_DIR: join(sandbox, "lockdir"),
            },
        });

        const calls = existsSync(callsPath)
            ? readFileSync(callsPath, "utf-8")
                  .split("\n")
                  .map((l) => l.trim())
                  .filter((l) => l !== "")
            : [];

        return { status: result.status ?? -1, stdout: result.stdout ?? "", stderr: result.stderr ?? "", calls };
    } finally {
        rmSync(sandbox, { recursive: true, force: true });
    }
}

/** ブラウザ実体の導入呼び出しだけを抜き出す (`install-deps` は含めない)。 */
function browserInstallCalls(calls: string[]): string[] {
    return calls.filter((c) => /playwright install(?!-)/.test(c));
}

const MISSING_DEPS_OUTPUT = "Missing system dependencies to run browsers: libwoff2dec.so.1.0.2";

// --------------------------------------------------------------------------

describe("setup-browser-testing.sh 層 1: --self-test", () => {
    it("決定表の自己検査が緑で、ケース数が下限を満たすこと", { timeout: 30_000 }, () => {
        const run = spawnSync("bash", [SCRIPT_PATH, "--self-test"], { encoding: "utf-8", cwd: REPO_ROOT });

        expect(run.status, run.stderr).toBe(0);
        const matched = /self-test: (\d+) cases, (\d+) failures/.exec(run.stdout);
        expect(matched, `自己検査のサマリー行が無い: ${run.stdout}`).not.toBeNull();
        expect(Number(matched?.[2])).toBe(0);
        // 空の自己検査で緑にする逃げを塞ぐ
        expect(Number(matched?.[1])).toBeGreaterThanOrEqual(SELF_TEST_MIN_CASES);
    });
});

describe("setup-browser-testing.sh 層 2: 静的契約 (P1〜P6)", () => {
    it("現行の実ソースは違反 0 件 (正のコントロール)", () => {
        expect(provisioningStaticViolations(realSource())).toEqual([]);
    });

    it("P1: 対象ブラウザを chromium だけに狭めると違反を返す", () => {
        const broken = mutate(realSource(), "BROWSER_TARGETS=(chromium webkit)", "BROWSER_TARGETS=(chromium)");
        expect(provisioningStaticViolations(broken)).toContain("P1: 対象ブラウザ集合が (chromium webkit) でない");
    });

    it("P2: レーン変数への代入を足すと違反を返す", () => {
        const broken = mutate(
            realSource(),
            "BROWSER_TARGETS=(chromium webkit)",
            'BROWSER_TEST_LANES="chromium"\nBROWSER_TARGETS=(chromium webkit)',
        );
        expect(provisioningStaticViolations(broken)).toContain(
            "P2: BROWSER_TEST_LANES へ代入している (レーン契約を導入スクリプトから壊せる)",
        );
    });

    it("P3: 導入ロックの置き場をスクリプト自身が代入すると違反を返す", () => {
        const broken = mutate(
            realSource(),
            'PROVISION_LOCK_DIR="${BROWSER_PROVISION_LOCK_DIR:-/tmp}"',
            'BROWSER_PROVISION_LOCK_DIR=/tmp\nPROVISION_LOCK_DIR="${BROWSER_PROVISION_LOCK_DIR}"',
        );
        const violations = provisioningStaticViolations(broken);
        expect(violations).toContain("P3: BROWSER_PROVISION_LOCK_DIR へ代入している");
        expect(violations).toContain("P3: 導入ロックの置き場が既定値つき展開 1 箇所でない");
    });

    it("P4: --with-deps を無条件付与へ広げると違反を返す", () => {
        const broken = mutate(
            realSource(),
            'plain)     pnpm exec playwright install "${BROWSER_TARGETS[@]}" ;;',
            'plain)     pnpm exec playwright install --with-deps "${BROWSER_TARGETS[@]}" ;;',
        );
        expect(provisioningStaticViolations(broken)).toContain("P4: --with-deps の出現が 1 回でない (2 回)");
    });

    it("P5: 対話 sudo へ戻すと違反を返す", () => {
        const broken = mutate(realSource(), "sudo -n true 2>/dev/null", "sudo true 2>/dev/null");
        expect(provisioningStaticViolations(broken)).toContain("P5: 非対話でない sudo 実行がある (sudo true)");
    });

    it("P6: flock 不在時の分岐を削ると違反を返す", () => {
        const broken = mutate(realSource(), 'echo "WARNING: flock(1) が無いため導入の排他なしで実行します" >&2', "true");
        expect(provisioningStaticViolations(broken)).toContain("P6: flock 不在時の警告つき続行の分岐が消えている");
    });
});

describe("setup-browser-testing.sh 層 3: sandbox 実走 (S1〜S7)", () => {
    it("S1: linux / 依存充足 → dry-run と素の install だけ。sudo を起こさない", { timeout: 30_000 }, () => {
        const run = runProvisionSandbox(realSource());

        expect(run.status, run.stderr).toBe(0);
        expect(run.calls.filter((c) => c.startsWith("pnpm"))).toEqual([
            "pnpm exec playwright install-deps --dry-run chromium webkit",
            "pnpm exec playwright install chromium webkit",
        ]);
        expect(run.calls.filter((c) => c.startsWith("sudo"))).toEqual([]);
    });

    it("S2: linux / 依存不足 / sudo 可 → --with-deps つきで導入する", { timeout: 30_000 }, () => {
        const run = runProvisionSandbox(realSource(), {
            depsOutput: MISSING_DEPS_OUTPUT,
            depsExit: 1,
            sudoExit: 0,
        });

        expect(run.status, run.stderr).toBe(0);
        expect(browserInstallCalls(run.calls)).toEqual([
            "pnpm exec playwright install --with-deps chromium webkit",
        ]);
        expect(run.calls.filter((c) => c.startsWith("sudo"))).not.toEqual([]);
    });

    it("S3: linux / 依存不足 / 権限なし → 導入せずに落ちる", { timeout: 30_000 }, () => {
        const run = runProvisionSandbox(realSource(), {
            depsOutput: MISSING_DEPS_OUTPUT,
            depsExit: 1,
            sudoExit: 1,
        });

        expect(run.status).toBe(1);
        expect(browserInstallCalls(run.calls)).toEqual([]);
        expect(run.stderr).toContain("no-privilege");
        expect(run.stderr).toContain("bash scripts/setup-browser-testing.sh");
    });

    it("S4: linux / 出力が想定外 → 判定不能として落ち、版と確認コマンドを出す", { timeout: 30_000 }, () => {
        const run = runProvisionSandbox(realSource(), { depsOutput: "Unexpected output format", depsExit: 0 });

        expect(run.status).toBe(1);
        expect(browserInstallCalls(run.calls)).toEqual([]);
        expect(run.stderr).toContain("undeterminable-deps");
        expect(run.stderr).toContain("Version 1.61.1");
        expect(run.stderr).toContain("pnpm exec playwright install-deps --dry-run chromium webkit");
    });

    it("S4b: 不足文言つき exit 2 (異常終了 + marker 残留) は判定不能に倒れる", { timeout: 30_000 }, () => {
        const run = runProvisionSandbox(realSource(), { depsOutput: MISSING_DEPS_OUTPUT, depsExit: 2 });

        expect(run.status).toBe(1);
        expect(browserInstallCalls(run.calls)).toEqual([]);
        // 特権経路の手前で止まる = sudo を一度も起こしていない
        expect(run.calls.filter((c) => c.startsWith("sudo"))).toEqual([]);
        expect(run.stderr).toContain("undeterminable-deps");
    });

    it("S4c: 不足文言つき exit 137 (SIGKILL 相当) も同じ", { timeout: 30_000 }, () => {
        const run = runProvisionSandbox(realSource(), { depsOutput: MISSING_DEPS_OUTPUT, depsExit: 137 });

        expect(run.status).toBe(1);
        expect(browserInstallCalls(run.calls)).toEqual([]);
        expect(run.calls.filter((c) => c.startsWith("sudo"))).toEqual([]);
        expect(run.stderr).toContain("undeterminable-deps");
    });

    it("S5: darwin → install-deps を一度も呼ばない", { timeout: 30_000 }, () => {
        const run = runProvisionSandbox(realSource(), { os: "Darwin" });

        expect(run.status, run.stderr).toBe(0);
        expect(run.calls.filter((c) => c.includes("install-deps"))).toEqual([]);
        expect(browserInstallCalls(run.calls)).toEqual(["pnpm exec playwright install chromium webkit"]);
    });

    it("S6: 対象外 OS → pnpm を一度も起こさずに落ちる", { timeout: 30_000 }, () => {
        const run = runProvisionSandbox(realSource(), { os: "MINGW64_NT-10.0" });

        expect(run.status).toBe(1);
        expect(run.calls.filter((c) => c.startsWith("pnpm"))).toEqual([]);
        expect(run.stderr).toContain("unsupported-os");
    });

    it("S7: 未知オプションは exit 2", { timeout: 30_000 }, () => {
        const run = runProvisionSandbox(realSource(), { args: ["--check"] });

        expect(run.status).toBe(2);
        expect(run.calls.filter((c) => c.startsWith("pnpm"))).toEqual([]);
    });
});

describe("setup-browser-testing.sh 層 3: 実 Playwright との突合", () => {
    it("実 CLI: install-deps --dry-run の出力が分類器の前提と一致すること", { timeout: 120_000 }, (context) => {
        // 対象は **Linux かつ apt-get が PATH にある環境** に限る。
        // それ以外 (macOS / 非 Debian 系 Linux) は **理由を出して skip** する (silent skip にしない)。
        if (process.platform !== "linux" || spawnSync("which", ["apt-get"]).status !== 0) {
            context.skip(
                `install-deps は Debian 系 Linux 以外では別の出力を出すため skip (platform=${process.platform})`,
            );
            return;
        }

        // 分類器を再実装せず、スクリプト本体から marker 文言を抽出して使う
        // (スクリプト側の文言を書き換えたら、この smoke が実 CLI と突き合わせて落ちる)。
        const source = realSource();
        const satisfied = extractShellConst(source, "DEPS_SATISFIED_MARKER");
        const missing = extractShellConst(source, "DEPS_MISSING_MARKER");

        const run = spawnSync("pnpm", ["exec", "playwright", "install-deps", "--dry-run", "chromium", "webkit"], {
            encoding: "utf-8",
            cwd: REPO_ROOT,
        });

        // status === null = シグナルで死んだ / 起動できなかった。marker 照合へ進めず、
        // 理由を明示して失敗させる (0 と同一視しない)。
        expect(run.status, `install-deps を実行できなかった: ${run.error ?? run.signal}`).not.toBeNull();
        const out = `${run.stdout}${run.stderr}`;

        // runner では WebKit の共有ライブラリが未導入で missing になりうるので、
        // 「satisfied であること」は要求しない (環境依存の偽赤を作らない)。
        // 要求するのは **どちらかに確定すること** = 分類器が undeterminable に落ちないこと。
        if (run.status === 0) {
            expect(out).toContain(satisfied);
        } else {
            expect(out).toContain(missing);
        }
    });
});
