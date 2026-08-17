/**
 * scripts/claude の回帰テスト。
 *
 * このラッパは開発者が Claude Code を起動する唯一の入口であり、壊すと開発が止まる。
 * にもかかわらず引数の再構築 (`eval "set -- $new_args"` とクォートのエスケープ) という
 * 壊れやすい箇所を誰も検査していなかったので、探索・既定フラグ・引数転送を実プロセスで固定する。
 *
 * 作り: 一時ディレクトリに偽の `HOME` と偽の拡張ディレクトリを組み、`scripts/claude` を
 * 複製して起動する。拡張のネイティブバイナリは「自分のパスと受け取った引数を NUL 区切りで
 * `$ARGV_OUT` へ書いて exit 0 する」偽物なので、どこまで到達したかと何が渡ったかが分かる。
 * 偽 `HOME` は毎回 `afterEach` で消す (残骸を作らない)。
 *
 * 期待する platform は `uname` を起動して求める。Node の `process.platform` /
 * `process.arch` から作るとラッパ本体の情報源 (`uname`) とズレた環境
 * (Rosetta やコンテナ) で正常なラッパが赤くなる。
 *
 * **あえて固定しないこと (誇張しない)**:
 * - 同じ版が `~/.vscode` と `~/.vscode-server` の両方にあるときにどちらを優先するか。
 *   これは探索ループの順序から生じる副次的性質で、追従元も固定していない。
 *   下流だけで固定すると追従元が探索順を変えたとき本リポジトリだけ落ちる。
 * - 代替経路が掴んだバイナリが実際にこの機械で動くこと。代替経路は arch を検査しないので
 *   異機種のバイナリを起動しうる (`[ -x ]` を通っても `exec` が実行形式の不一致で落ちうる)。
 *   これは追従元が持つ既知の穴であり、ここだけ塞ぐと新しい乖離になるので塞がない。
 *   W2 の終了コードが 0 になるのは**テスト用の偽バイナリが 0 で終わるから**であって、
 *   実機の別 platform のバイナリが動くという意味ではない。
 * - 版の比較は `sort -t- -k1 -V` (GNU 拡張) に依存する。これは現行ラッパが既に持つ前提で、
 *   本テストが持ち込むものではないため、macOS 実機での可用性はここでは扱わない。
 *
 * 実行: pnpm test (vitest の include に scripts/**\/*.test.ts が含まれる)
 */
import { afterEach, describe, expect, it } from "vitest";
import { spawnSync, type SpawnSyncReturns } from "node:child_process";
import { chmodSync, copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join, resolve } from "node:path";

const REPO_ROOT = process.cwd();
const WRAPPER_PATH = resolve(REPO_ROOT, "scripts/claude");

/** 偽のネイティブバイナリ。自分のパスと引数を NUL 区切りで書き出して正常終了する。 */
const RECORDING_BINARY = `#!/bin/sh
printf '%s\\0' "$0" "$@" > "$ARGV_OUT"
exit 0
`;

/** 偽の状態表示行スクリプト (中身は使われない。実行可能かどうかだけが効く)。 */
const STATUSLINE_STUB = `#!/bin/sh
exit 0
`;

const scratchRoots: string[] = [];

afterEach(() => {
    for (const root of scratchRoots.splice(0)) {
        rmSync(root, { recursive: true, force: true });
    }
});

function uname(flag: string): string {
    const result = spawnSync("uname", [flag], { encoding: "utf-8" });
    if (result.status !== 0) throw new Error(`uname ${flag} が失敗した`);
    return result.stdout.trim();
}

/** ラッパ本体と同じ写像で platform 文字列を作る (情報源は uname に揃える)。 */
function expectedPlatform(): string {
    const os = uname("-s") === "Darwin" ? "darwin" : "linux";
    const machine = uname("-m");
    const arch = machine === "x86_64" || machine === "amd64" ? "x64" : "arm64";
    return `${os}-${arch}`;
}

/** 期待する platform とは必ず異なる platform 文字列 (代替経路の入力に使う)。 */
function foreignPlatform(): string {
    return expectedPlatform() === "linux-arm64" ? "darwin-x64" : "linux-arm64";
}

interface Scratch {
    /** 一時ディレクトリの根。afterEach で消す。 */
    readonly root: string;
    /** 偽 HOME (絶対パス)。ラッパは $HOME 配下から拡張を探す。 */
    readonly home: string;
    /** 複製した scripts/claude の絶対パス。 */
    readonly wrapper: string;
    /** 偽バイナリが引数を書き出す先。 */
    readonly argvOut: string;
}

function createScratch(): Scratch {
    const root = mkdtempSync(join(tmpdir(), "claude-wrapper-"));
    scratchRoots.push(root);

    const home = join(root, "home");
    mkdirSync(home, { recursive: true });

    const binDir = join(root, "bin");
    mkdirSync(binDir, { recursive: true });
    const wrapper = join(binDir, "claude");
    copyFileSync(WRAPPER_PATH, wrapper);
    chmodSync(wrapper, 0o755);

    return { root, home, wrapper, argvOut: join(root, "argv") };
}

/** 偽の状態表示行をラッパと同じディレクトリへ置く (置かなければ注入は起きない)。 */
function installStatusline(scratch: Scratch): string {
    const path = join(dirname(scratch.wrapper), "claude-statusline");
    writeFileSync(path, STATUSLINE_STUB, "utf-8");
    chmodSync(path, 0o755);

    return path;
}

interface ExtensionOptions {
    /** 置き場 (`.vscode` = 手元 / `.vscode-server` = リモート開発機)。 */
    readonly root: ".vscode" | ".vscode-server";
    readonly version: string;
    readonly platform: string;
    /** ネイティブバイナリの状態。既定は記録する実行可能ファイル。 */
    readonly binary?: "recording" | "not-executable";
}

/** 偽の拡張を組み、ネイティブバイナリの絶対パスを返す。 */
function installExtension(scratch: Scratch, options: ExtensionOptions): string {
    const extensionDir = join(
        scratch.home,
        options.root,
        "extensions",
        `anthropic.claude-code-${options.version}-${options.platform}`,
    );
    const binary = join(extensionDir, "resources", "native-binary", "claude");
    mkdirSync(dirname(binary), { recursive: true });
    writeFileSync(binary, RECORDING_BINARY, "utf-8");
    chmodSync(binary, options.binary === "not-executable" ? 0o644 : 0o755);

    return binary;
}

function runWrapper(scratch: Scratch, args: readonly string[] = []): SpawnSyncReturns<string> {
    return spawnSync(scratch.wrapper, [...args], {
        env: { ...process.env, HOME: scratch.home, ARGV_OUT: scratch.argvOut },
        encoding: "utf-8",
    });
}

/** 偽バイナリが記録した [起動されたパス, ...引数] を読む。起動されていなければ throw する。 */
function recordedInvocation(scratch: Scratch): { readonly binary: string; readonly args: string[] } {
    const raw = readFileSync(scratch.argvOut, "utf-8");
    const parts = raw.split("\0");
    parts.pop(); // 末尾の NUL による空要素
    const binary = parts.shift();
    if (binary === undefined) throw new Error("記録が空 (偽バイナリが起動されていない)");

    return { binary, args: parts };
}

describe("scripts/claude の拡張探索", () => {
    it("W1: 2 つの置き場に別々の版があるとき、版が大きい方のバイナリを起動する", () => {
        const scratch = createScratch();
        const platform = expectedPlatform();
        installExtension(scratch, { root: ".vscode", version: "1.2.3", platform });
        const newer = installExtension(scratch, { root: ".vscode-server", version: "1.10.0", platform });

        const result = runWrapper(scratch);

        expect(result.status).toBe(0);
        expect(recordedInvocation(scratch).binary).toBe(newer);
    });

    it("W2: 完全一致が無ければ別 platform の拡張を拾い直し、期待 platform と採用パスを警告する", () => {
        const scratch = createScratch();
        const fallback = installExtension(scratch, {
            root: ".vscode-server",
            version: "1.0.0",
            platform: foreignPlatform(),
        });

        const result = runWrapper(scratch);

        // 拾い直した拡張のバイナリまで到達している (現行の即 exit 1 との違いはここ)。
        // status が 0 なのは偽バイナリが 0 で終わるからであって、
        // 実機で別 platform のバイナリが動くという意味ではない (冒頭の但し書き参照)。
        expect(result.status).toBe(0);
        expect(recordedInvocation(scratch).binary).toBe(fallback);
        expect(result.stderr).toContain(expectedPlatform());
        expect(result.stderr).toContain(fallback.replace("/resources/native-binary/claude", ""));
    });

    it("W3: 拡張が 1 つも無ければ platform 名つきのエラーで終了する", () => {
        const scratch = createScratch();

        const result = runWrapper(scratch);

        expect(result.status).toBe(1);
        expect(result.stderr).toContain(expectedPlatform());
    });

    it("W4: ネイティブバイナリが実行可能でなければそのパスを示して終了する", () => {
        const scratch = createScratch();
        const binary = installExtension(scratch, {
            root: ".vscode-server",
            version: "1.0.0",
            platform: expectedPlatform(),
            binary: "not-executable",
        });

        const result = runWrapper(scratch);

        expect(result.status).toBe(1);
        expect(result.stderr).toContain(binary);
    });

    it("W8 負のコントロール: 完全一致で見つかったときは警告を 1 文字も出さない", () => {
        const scratch = createScratch();
        installExtension(scratch, { root: ".vscode-server", version: "1.0.0", platform: expectedPlatform() });

        const result = runWrapper(scratch);

        expect(result.status).toBe(0);
        expect(result.stderr).toBe("");
    });
});

describe("scripts/claude の引数の組み立て", () => {
    function scratchWithExtension(): Scratch {
        const scratch = createScratch();
        installExtension(scratch, { root: ".vscode-server", version: "1.0.0", platform: expectedPlatform() });

        return scratch;
    }

    it("W5: 既定で権限確認の回避を前置し、--no-bypass では前置も転送もしない", () => {
        const withDefault = scratchWithExtension();
        expect(runWrapper(withDefault, ["--print"]).status).toBe(0);
        expect(recordedInvocation(withDefault).args).toEqual(["--dangerously-skip-permissions", "--print"]);

        const optedOut = scratchWithExtension();
        expect(runWrapper(optedOut, ["--no-bypass", "--print"]).status).toBe(0);
        expect(recordedInvocation(optedOut).args).toEqual(["--print"]);
    });

    it("W6: 状態表示行があれば --settings と JSON を前置し、--no-ctx で前置しない", () => {
        const scratch = scratchWithExtension();
        const statusline = installStatusline(scratch);

        expect(runWrapper(scratch, ["--print"]).status).toBe(0);
        expect(recordedInvocation(scratch).args).toEqual([
            "--dangerously-skip-permissions",
            "--settings",
            `{"statusLine":{"type":"command","command":"${statusline}","padding":0}}`,
            "--print",
        ]);

        const optedOut = scratchWithExtension();
        installStatusline(optedOut);
        expect(runWrapper(optedOut, ["--no-ctx", "--print"]).status).toBe(0);
        expect(recordedInvocation(optedOut).args).toEqual(["--dangerously-skip-permissions", "--print"]);
    });

    it("W6: 状態表示行が無ければ --settings は付かない (不在の環境の負のコントロール)", () => {
        const scratch = scratchWithExtension();

        expect(runWrapper(scratch, ["--print"]).status).toBe(0);
        expect(recordedInvocation(scratch).args).not.toContain("--settings");
    });

    it("W7: 壊れやすい引数を順序も内容も変えずに転送する", () => {
        const scratch = scratchWithExtension();
        const args = ["", "a b", "it's", '{"a":1}', "--", "日本語 の 引数", "1 行目\n2 行目"];

        expect(runWrapper(scratch, args).status).toBe(0);
        expect(recordedInvocation(scratch).args).toEqual(["--dangerously-skip-permissions", ...args]);
    });
});
