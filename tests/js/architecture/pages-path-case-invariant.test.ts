import { describe, it, expect } from "vitest";
import fs from "node:fs/promises";
import path from "node:path";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";

/*
 * pages-path-case-invariant — ページ参照 path の大文字/小文字を固定する。
 *
 * SoT = resources/js/inertia.ts の resolver 規約: import.meta.glob で "./pages" 配下の
 * .svelte を集め、`./pages/${name}.svelte` というキーで引く。
 * つまり pages ディレクトリは **小文字 `pages/` 固定**。大文字 `Pages/` を参照する
 * import / glob / dynamic import は、case-insensitive な開発 FS では偶然動いても
 * case-sensitive な CI / 本番コンテナで解決不能になり白画面/ビルド失敗になる。
 *
 * 実効性: 他アプリからのコード移植で `resources/js/Pages/` 参照が実際に混入したことがある。
 *
 * 検査対象:
 *   A. resources/js 配下 (.ts / .svelte) の **path 文字列リテラル**中の大文字 `Pages/` セグメント。
 *      静的 import・`import.meta.glob`・**dynamic import の文字列リテラル**を等しく拾う
 *      (どれも「引用符で囲まれた path リテラル」として現れるため、単一の検出器で足りる)。
 *   B. git tracked path に `resources/js/Pages/` で始まるものが無いこと
 *      (case-insensitive FS の case-fold エイリアスを誤って git add した事故の検出)。
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(HERE, "../../../");
const RESOURCES_JS = path.join(REPO_ROOT, "resources/js");

/**
 * 引用符で囲まれた path リテラル中の大文字 `Pages/` セグメントを検出する。
 * `./Pages/` `../Pages/` `/Pages/` `@/Pages/` `resources/js/Pages/` などを等しく拾う。
 * 単語の一部 (例: `SubPages/`) は前方境界で除外する。
 */
const UPPERCASE_PAGES_REF = /(["'`])(?:Pages\/|[^"'`]*[./@]Pages\/)/;

function findUppercasePagesRefs(source: string): string[] {
    return source
        .split(/\r?\n/)
        .filter((line) => UPPERCASE_PAGES_REF.test(line))
        .map((line) => line.trim());
}

async function sourceFiles(dir: string): Promise<string[]> {
    const out: string[] = [];
    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
        if (e.isFile() && /\.(svelte|ts)$/.test(e.name)) out.push(path.join(e.parentPath, e.name));
    }
    return out;
}

describe("architecture/pages-path-case-invariant", () => {
    it("resources/js に大文字 Pages/ を参照する import / glob / dynamic import が存在しない", async () => {
        const files = await sourceFiles(RESOURCES_JS);
        const offenders: string[] = [];
        for (const file of files) {
            const hits = findUppercasePagesRefs(await fs.readFile(file, "utf8"));
            for (const hit of hits) offenders.push(`${path.relative(REPO_ROOT, file)}: ${hit}`);
        }
        expect(
            offenders.sort(), // 失敗メッセージを走査順の環境差で揺らさない
            `大文字 'Pages/' path 参照を検出。resolver 規約は小文字 './pages/' 固定 ` +
                `(resources/js/inertia.ts の import.meta.glob と一致させること): ${offenders.join(", ")}`,
        ).toEqual([]);
    });

    it("git tracked path に大文字 resources/js/Pages/ で始まるものが存在しない", () => {
        // architecture invariant: git 不在は環境不備。silent skip せず明瞭に fail させる。
        let tracked: string;
        try {
            tracked = execFileSync("git", ["ls-files", "resources/js/"], {
                cwd: REPO_ROOT,
                encoding: "utf8",
            });
        } catch (e) {
            throw new Error(
                `git ls-files の実行に失敗 (git worktree 前提の architecture invariant): ${String(e)}`,
            );
        }
        const offenders = tracked.split("\n").filter((p) => p.startsWith("resources/js/Pages/"));
        expect(
            offenders,
            `大文字 'resources/js/Pages/' で始まる tracked file を検出。case-insensitive FS の ` +
                `case-fold エイリアスを誤って git add したもの。小文字 'resources/js/pages/' に統一すること: ` +
                `${offenders.join(", ")}`,
        ).toEqual([]);
    });

    /*
     * 負のコントロール: 検出器が実際に点灯することを fixture 文字列で確認する
     * (実ファイルは書き換えない)。空振り gate を green として扱わないため。
     */
    it("負のコントロール: 静的 import / glob / dynamic import の大文字 Pages を検出する", () => {
        const violations = [
            `import Dashboard from "./Pages/Dashboard.svelte";`,
            `import Foo from '@/Pages/Foo.svelte';`,
            "const pages = import.meta.glob('./Pages/**/*.svelte');",
            `const mod = await import("./Pages/Lazy.svelte");`,
            "const mod = await import(`../Pages/Lazy.svelte`);",
            `const entry = "resources/js/Pages/Dashboard.svelte";`,
        ];
        for (const line of violations) {
            expect(findUppercasePagesRefs(line), line).toHaveLength(1);
        }
    });

    it("正のコントロール: 小文字 pages / 無関係な Pages 語は検出しない", () => {
        const allowed = [
            `import Dashboard from "./pages/Dashboard.svelte";`,
            "const pages = import.meta.glob('./pages/**/*.svelte');",
            `const mod = await import("@/pages/Lazy.svelte");`,
            "// Pages/ という語をコメントに書いても引用符外なら対象外",
            `const label = "SubPages/foo";`, // 単語境界の無い Pages は誤検出しない
        ];
        for (const line of allowed) {
            expect(findUppercasePagesRefs(line), line).toHaveLength(0);
        }
    });
});
