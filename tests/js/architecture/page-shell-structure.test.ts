import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";
import { fileURLToPath } from "url";

/*
 * page-shell-structure — 認証ページ外枠の aigenba parity を構造保証する Architecture テスト。
 *
 * 契約: `AppLayout` を import するページ (ログイン後シェルを使う認証ページ) は、aigenba の統一外枠
 *   <AppLayout><PageContainer><PageHeader|PageHeaderSection><PageContent>…
 * に従い、layout primitive を import かつ使用する。これにより外枠(padding/見出し/中央寄せ max-w-7xl)が
 * primitive に一元化され、ページ独自の外枠ドリフトを構造的に防ぐ。
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
        reason: "2 カラム grid の撮影レコーダー面。全幅のため PageContent の max-w-7xl 中央寄せを課さない。",
    },
];
const PAGECONTENT_ALLOWLIST_PATHS = new Set(PAGECONTENT_ALLOWLIST.map((e) => e.path));

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
});
