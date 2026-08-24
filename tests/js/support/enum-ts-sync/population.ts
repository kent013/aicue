/**
 * 逆走査の母集団 (家系の機能台帳 `enum-ts-sync-gate` 正典 v3 の i8)。
 *
 * **母集団**: `git ls-files -z` が返す**版管理下の `*.ts` と `*.svelte` の全数**。
 * 走査根の手書きの列挙は持たない (足し忘れが静かな穴になる)。
 * `-z` を使うのは、改行を含む合法なパスでも全数を列挙するためである。
 *
 * **2 つの一覧を区別する**:
 * - `listProgramTsFiles()` … 型世界に載せる起点。**`.d.ts` を含む**
 *   (周囲宣言が落ちると本番と違う型世界になる)
 * - `listCandidateTsFiles()` … 候補を探す対象。**`.d.ts` を除く**
 *
 * どちらかが 0 件なら「母集団が不明」として例外にする (空振りを緑にしない)。
 *
 * **唯一の除外**: `EXCLUDED_ROOTS`。**わざと構文を壊した見本**だけを外す。
 * i14 が「構文が壊れたファイルを無言で読み飛ばさない」ので、これを母集団に入れると
 * 本番の gate が恒久的に赤くなる。申告では逃がせない (申告は候補を逃がす仕組みで、
 * 読めないファイルの受け皿ではない)。除外は `tests/js/support/enum-ts-sync/` の
 * **配下**に限る (構造で縛る。任意のパスを書けない)。
 *
 * **除外根の自己点検は利用側の gate が持つ** — 「除外根の配下の全ファイルが実際に
 * 本番と同じ入口で落ちること」を `enum-ts-sync-discovery.test.ts` が見る。
 * ここが「除外根へ正常なファイルを置いて母集団から静かに消す」経路を塞ぐ。
 * 現時点の除外根は `.ts` だけを含む。**将来 `.svelte` を除外根へ入れるなら、
 * 自己点検は拡張子ごとに本番と同じ入口を使う必要がある** (`.ts` は TypeScript の
 * 構文診断、`.svelte` は `toVirtualUnit()` の失敗)。
 *
 * **保証しないもの**: 版管理外のファイル (無視されたもの・未追跡のもの) は見ない。
 * `.js` / `.mjs` / `.cjs` は母集団に入れない (本リポジトリの TS 以外の出口は
 * 本 gate の対象外である)。`git` の作業ツリーと索引が使えることが前提である。
 */
import { execFileSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { EnumTsSyncError } from "./errors";
import { REPO_ROOT } from "./repo-root";

export interface ExcludedRoot {
    /** リポジトリ相対のディレクトリ。`tests/js/support/enum-ts-sync/` の配下だけ。 */
    readonly root: string;
    /** 外す理由 (30 文字以上)。 */
    readonly reason: string;
}

/** 除外根を書ける唯一の場所 (構造で縛る)。 */
export const EXCLUDED_ROOT_PREFIX = "tests/js/support/enum-ts-sync/";

export const EXCLUDED_ROOTS = [
    {
        root: "tests/js/support/enum-ts-sync/fixtures/candidates-broken",
        reason: "候補走査が構文の壊れたファイルを無言で読み飛ばさないことの負の対照。中身は意図的に壊してあるので母集団に入れると本番の gate が恒久的に赤くなる",
    },
] as const satisfies readonly ExcludedRoot[];

/** 除外根の件数の pin。増えても減っても赤くする。 */
export const EXPECTED_EXCLUDED_ROOT_COUNT = 1;

/**
 * `git ls-files -z` の生出力から一覧を作る**純関数**
 * (0 件の分岐を単体で試験できるように分けてある)。
 */
export const parseTrackedOutput = (raw: string): readonly string[] =>
    [...new Set(raw.split("\0").filter((line) => line !== ""))].sort();

const trackedFiles = (root: string, pattern: string): readonly string[] =>
    parseTrackedOutput(
        execFileSync("git", ["-C", root, "ls-files", "-z", "--", pattern], {
            encoding: "utf-8",
            maxBuffer: 64 * 1024 * 1024,
        }),
    );

/**
 * 除外根の配下か。**パスの区間一致**で見る (素の `startsWith` にすると
 * 兄弟ディレクトリ `candidates-broken-2/` まで巻き込む)。
 */
export const isUnderExcludedRoot = (
    relative: string,
    roots: readonly ExcludedRoot[] = EXCLUDED_ROOTS,
): boolean => roots.some((entry) => relative === entry.root || relative.startsWith(`${entry.root}/`));

const requireNonEmpty = (files: readonly string[], label: string): readonly string[] => {
    if (files.length === 0) {
        throw new EnumTsSyncError("population", `${label} が 0 件です (母集団の走査が空振りしています)`);
    }
    return files;
};

/** 型世界に載せる起点 (`.d.ts` を含む)。 */
export const listProgramTsFiles = (root: string = REPO_ROOT): readonly string[] =>
    requireNonEmpty(
        trackedFiles(root, "*.ts").filter((file) => !isUnderExcludedRoot(file)),
        "版管理下の *.ts",
    );

/** 候補を探す対象 (`.d.ts` を除く)。 */
export const listCandidateTsFiles = (root: string = REPO_ROOT): readonly string[] =>
    requireNonEmpty(
        listProgramTsFiles(root).filter((file) => !file.endsWith(".d.ts")),
        "候補走査の対象になる *.ts",
    );

/** 候補を探す対象の `.svelte`。 */
export const listCandidateSvelteFiles = (root: string = REPO_ROOT): readonly string[] =>
    requireNonEmpty(
        trackedFiles(root, "*.svelte").filter((file) => !isUnderExcludedRoot(file)),
        "版管理下の *.svelte",
    );

/**
 * 除外根の配下にある版管理下ファイル (除外の自己点検に使う)。
 * 0 件は「除外根が空である = 除外の意味が失われた」ので例外にする。
 */
export const listExcludedFiles = (
    root: string = REPO_ROOT,
    roots: readonly ExcludedRoot[] = EXCLUDED_ROOTS,
): readonly string[] => {
    const files = new Set<string>();
    for (const entry of roots) {
        for (const file of trackedFiles(root, entry.root)) files.add(file);
    }
    return requireNonEmpty([...files].sort(), "除外根の配下の版管理下ファイル");
};

/** 除外根の体裁 (配下・実在・重複無し・理由 30 文字以上)。 */
export const validateExcludedRoots = (
    roots: readonly ExcludedRoot[] = EXCLUDED_ROOTS,
    root: string = REPO_ROOT,
): void => {
    if (roots.length === 0) {
        throw new EnumTsSyncError("population", "除外根の一覧が空です (除外の仕組みが黙って消えています)");
    }

    const seen = new Set<string>();
    for (const entry of roots) {
        const where = `除外根 ${entry.root}`;
        if (path.isAbsolute(entry.root)) throw new EnumTsSyncError(where, "絶対パスは登録できません");
        if (entry.root.includes("\\")) throw new EnumTsSyncError(where, "逆斜線を含むパスは登録できません");
        if (entry.root.split("/").some((s) => s === "" || s === "." || s === "..")) {
            throw new EnumTsSyncError(where, ". / .. / 空の区間を含むパスは登録できません");
        }
        if (!entry.root.startsWith(EXCLUDED_ROOT_PREFIX) || entry.root === EXCLUDED_ROOT_PREFIX) {
            throw new EnumTsSyncError(where, `除外根は ${EXCLUDED_ROOT_PREFIX} の配下だけです`);
        }
        const absolute = path.join(root, entry.root);
        if (!fs.existsSync(absolute) || !fs.statSync(absolute).isDirectory()) {
            throw new EnumTsSyncError(where, "除外根が実在するディレクトリではありません");
        }
        if (seen.has(entry.root)) throw new EnumTsSyncError(where, "同じ除外根が 2 回登録されています");
        seen.add(entry.root);
        if (entry.reason.trim().length < 30) {
            throw new EnumTsSyncError(where, "理由は 30 文字以上で書くこと");
        }
    }
};
