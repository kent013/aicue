import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";
import { fileURLToPath } from "url";

/*
 * page-shell-structure — ページ外枠テンプレートの構造契約を集約する Architecture テスト。
 *
 * 契約 1 (AppLayout): `AppLayout` を import するページ (ログイン後シェルを使う認証ページ) は、
 * aigenba の統一外枠
 *   <AppLayout><PageContainer><PageHeader|PageHeaderSection><PageContent>…
 * に従い、layout primitive を import かつ使用する。これにより外枠(padding/見出し/中央寄せ max-w-7xl)が
 * primitive に一元化され、ページ独自の外枠ドリフトを構造的に防ぐ。
 *
 * 契約 2 (AuthLayout の離脱導線): `AuthLayout` を import するページは、**その手順を完了できない
 * ユーザーが別の入口へ抜けられる導線**を `{#snippet footer()}` に 1 つ以上持ち、`TextLink` atom で
 * 表現する (DESIGN.md §Do's and Don'ts)。認証ファネルはアプリ最初の関門であり、ここでの行き止まりは
 * 価値提供の入口をそのまま失う (bug-hunt F-2-02)。例外は AUTH_EXIT_ALLOWLIST に理由付きで登録する。
 *
 * 運用規約(機械強制でない・レビュー観点): 本文標準は上記外枠。ALLOWLIST 追加は理由必須。
 * (旧 page-content-usage.test.ts をリネーム。AdminMenuNav 等の廃止 import は deprecated-imports.test.ts。)
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PAGES_DIR = path.resolve(HERE, "../../../resources/js/pages");

/** PageContent 必須契約の除外 allowlist (PageContainer/PageHeader は必須)。追加は理由必須(reason 非空)。 */
const PAGECONTENT_ALLOWLIST: ReadonlyArray<{ path: string; reason: string }> = [
    {
        path: "Capture/Show.svelte",
        reason:
            "2 カラム grid の撮影レコーダー面。全幅のため PageContent の max-w-7xl 中央寄せを課さない。" +
            "横持ち時は撮影パネルが fixed の全画面へ切り替わるため、中央寄せの外枠を前提にできない。",
    },
];
const PAGECONTENT_ALLOWLIST_PATHS = new Set(PAGECONTENT_ALLOWLIST.map((e) => e.path));

/** AuthLayout ページの離脱導線契約の除外 allowlist。追加は理由必須(reason 非空)。 */
const AUTH_EXIT_ALLOWLIST: ReadonlyArray<{ path: string; reason: string }> = [
    {
        path: "Auth/VerifyEmail.svelte",
        reason:
            "離脱導線は本文の『ログアウト』(POST 遷移) が担う。footer の TextLink では表現できない。" +
            "未検証状態で到達できる別入口が無いため、代替リンクを置くと新たな行き止まりを作る。",
    },
];
const AUTH_EXIT_ALLOWLIST_PATHS = new Set(AUTH_EXIT_ALLOWLIST.map((e) => e.path));

const escapeRegExp = (s: string): string => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

function stripComments(src: string): string {
    return src
        .replace(/<!--[\s\S]*?-->/g, "")
        .replace(/\/\*[\s\S]*?\*\//g, "")
        // `//` 行コメントは**行頭コメント限定**で除去 (先頭空白のみ許容)。文字列内 (URL "https://" 等)
        // や行内の `//` を壊さないため、`//` が行の内容の先頭にある場合のみ落とす (Codex impl-review R1)。
        .replace(/^\s*\/\/[^\n]*$/gm, "");
}

async function sveltePages(dir: string): Promise<string[]> {
    const out: string[] = [];
    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
        if (e.isFile() && e.name.endsWith(".svelte")) out.push(path.join(e.parentPath, e.name));
    }
    return out;
}

const importsAppLayout = (src: string): boolean =>
    /import\s+\w+\s+from\s+["']@\/components\/templates\/AppLayout\.svelte["']/.test(src);

/** 指定 primitive path の default import 識別子を返す (alias 対応)。無ければ null。 */
function importIdentifier(src: string, importPath: string): string | null {
    const re = new RegExp(`import\\s+(\\w+)\\s+from\\s+["']${escapeRegExp(importPath)}["']`);
    const m = src.match(re);
    return m ? m[1] : null;
}

/** 識別子の通常開始タグが使われているか (タグ名境界まで)。 */
const usesTag = (src: string, ident: string): boolean =>
    new RegExp(`<${escapeRegExp(ident)}(?:\\s|/?>)`).test(src);

/**
 * footer snippet 本体を取り出す (先頭の {/snippet} まで)。
 * - 定義が 0 個 → null (= 契約違反として報告)
 * - 定義が 2 個以上 / 本体に snippet が入れ子 → "抽出器が現実に追いつけていない" 印として
 *   例外を投げる (fail-closed。黙って pass させない)
 */
function footerSnippetBody(src: string): string | null {
    const matches = [...src.matchAll(/\{#snippet\s+footer\s*\(\s*\)\s*\}([\s\S]*?)\{\/snippet\}/g)];
    if (matches.length === 0) return null;
    if (matches.length > 1) {
        throw new Error("footer snippet の定義が複数あります。抽出器の前提が崩れています。");
    }
    const body = matches[0][1];
    if (/\{#snippet\b/.test(body)) {
        throw new Error(
            "footer snippet に snippet が入れ子です。抽出器を AST 方式へ更新してください。",
        );
    }
    return body;
}

describe("architecture/page-shell-structure", () => {
    it("PAGECONTENT_ALLOWLIST の各エントリは理由(reason)必須", () => {
        for (const e of PAGECONTENT_ALLOWLIST) {
            expect(e.reason.trim(), `allowlist "${e.path}" は理由必須`).not.toBe("");
        }
    });

    it("AppLayout ページは PageContainer + PageHeader(Section) + PageContent を使い、padding={false} を使わない", async () => {
        const files = await sveltePages(PAGES_DIR);
        const missingContainer: string[] = [];
        const missingHeader: string[] = [];
        const missingContent: string[] = [];
        const paddingFalse: string[] = [];

        for (const file of files) {
            const rel = path.relative(PAGES_DIR, file).replace(/\\/g, "/");
            const src = stripComments(await fs.readFile(file, "utf8"));
            if (!importsAppLayout(src)) continue;

            // PageContainer 必須 + padding={false} 禁止
            const pc = importIdentifier(src, "@/components/templates/PageContainer.svelte");
            if (!pc || !usesTag(src, pc)) missingContainer.push(rel);
            else if (new RegExp(`<${escapeRegExp(pc)}\\b[^>]*\\bpadding=\\{false\\}`).test(src))
                paddingFalse.push(rel);

            // PageHeader または PageHeaderSection 必須
            const ph = importIdentifier(src, "@/components/molecules/PageHeader.svelte");
            const phs = importIdentifier(src, "@/components/molecules/PageHeaderSection.svelte");
            const hasHeader = (ph && usesTag(src, ph)) || (phs && usesTag(src, phs));
            if (!hasHeader) missingHeader.push(rel);

            // PageContent 必須 (allowlist 除く)
            if (!PAGECONTENT_ALLOWLIST_PATHS.has(rel)) {
                const pcnt = importIdentifier(src, "@/components/templates/PageContent.svelte");
                if (!pcnt || !usesTag(src, pcnt)) missingContent.push(rel);
            }
        }

        const msg = [
            missingContainer.length && `PageContainer 不足/未使用:\n  - ${missingContainer.join("\n  - ")}`,
            missingHeader.length && `PageHeader(Section) 不足/未使用:\n  - ${missingHeader.join("\n  - ")}`,
            missingContent.length && `PageContent 不足/未使用:\n  - ${missingContent.join("\n  - ")}`,
            paddingFalse.length && `PageContainer padding={false} は禁止:\n  - ${paddingFalse.join("\n  - ")}`,
        ].filter(Boolean).join("\n\n");
        expect(
            { missingContainer, missingHeader, missingContent, paddingFalse },
            msg,
        ).toEqual({ missingContainer: [], missingHeader: [], missingContent: [], paddingFalse: [] });
    });

    it("AUTH_EXIT_ALLOWLIST の各エントリは理由(reason)必須 / path 重複なし", () => {
        for (const e of AUTH_EXIT_ALLOWLIST) {
            expect(e.reason.trim(), `allowlist "${e.path}" は理由必須`).not.toBe("");
        }
        // path 重複は編集ミスの兆候
        expect(AUTH_EXIT_ALLOWLIST_PATHS.size).toBe(AUTH_EXIT_ALLOWLIST.length);
    });

    it("AUTH_EXIT_ALLOWLIST の各エントリは実在し AuthLayout を使うページである (死蔵 entry 検出)", async () => {
        for (const e of AUTH_EXIT_ALLOWLIST) {
            const abs = path.join(PAGES_DIR, e.path);
            const src = stripComments(await fs.readFile(abs, "utf8"));
            expect(
                importIdentifier(src, "@/components/templates/AuthLayout.svelte"),
                `allowlist "${e.path}" は AuthLayout ページではない (entry が死蔵または typo)`,
            ).not.toBeNull();
        }
    });

    it("AuthLayout ページは footer snippet に TextLink の離脱導線を持つ", async () => {
        const files = await sveltePages(PAGES_DIR);
        const missingFooter: string[] = [];
        const footerWithoutLink: string[] = [];

        for (const file of files) {
            const rel = path.relative(PAGES_DIR, file).replace(/\\/g, "/");
            const src = stripComments(await fs.readFile(file, "utf8"));
            if (!importIdentifier(src, "@/components/templates/AuthLayout.svelte")) continue;
            if (AUTH_EXIT_ALLOWLIST_PATHS.has(rel)) continue;

            const body = footerSnippetBody(src);
            if (body === null) {
                missingFooter.push(rel);
                continue;
            }
            const link = importIdentifier(src, "@/components/atoms/TextLink.svelte");
            if (!link || !usesTag(body, link)) footerWithoutLink.push(rel);
        }

        const msg = [
            missingFooter.length &&
                `AuthLayout ページに footer snippet が無い:\n  - ${missingFooter.join("\n  - ")}`,
            footerWithoutLink.length &&
                `footer に TextLink の離脱導線が無い:\n  - ${footerWithoutLink.join("\n  - ")}`,
        ]
            .filter(Boolean)
            .join("\n\n");
        expect({ missingFooter, footerWithoutLink }, msg).toEqual({
            missingFooter: [],
            footerWithoutLink: [],
        });
    });
});
