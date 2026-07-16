import { describe, it, expect } from "vitest";
import fs from "fs/promises";
import path from "path";
import { fileURLToPath } from "url";

/*
 * page-content-usage — 認証ページのコンテンツ幅統一を構造保証する Architecture テスト。
 *
 * 契約: `AppLayout` を import するページ (= ログイン後シェルを使う認証ページ) は、本文を layout primitive
 * `PageContent` で包む (import かつ使用) こと。これにより本文幅の中央寄せ/max-width 制御が PageContent に
 * 一元化され、T069 で発生したような「各ページが独自 max-width を左寄せ」ドリフトを構造的に防ぐ。
 *
 * 運用規約 (機械強制ではない・レビュー観点):
 *  - 認証ページ本文の標準幅は maxWidth="2xl"。例外 (3xl/4xl/7xl 等) は各ページで理由をもって指定する。
 *  - ALLOWLIST への追加は理由コメント必須 (無理由追加禁止)。
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PAGES_DIR = path.resolve(HERE, "../../../resources/js/pages");

/**
 * max-width 非制約 allowlist (PageContent を課さないページ)。
 * 追加は `{ path, reason }` で行い、reason(理由)必須 = 空文字は機械的に fail する(無理由追加禁止)。
 */
const ALLOWLIST: ReadonlyArray<{ path: string; reason: string }> = [
    {
        path: "Capture/Show.svelte",
        reason: "2 カラム grid の撮影レコーダー面。カメラ/カット一覧をワイドに使うため max-width 非制約。",
    },
];
const ALLOWLIST_PATHS: ReadonlySet<string> = new Set(ALLOWLIST.map((e) => e.path));

const escapeRegExp = (s: string): string => s.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

/** HTML コメントと JS/TS コメントを除去 (コメント内の import / <PageContent> 誤認を防ぐ)。 */
function stripComments(src: string): string {
    return src
        .replace(/<!--[\s\S]*?-->/g, "")
        .replace(/\/\*[\s\S]*?\*\//g, "")
        .replace(/(^|[^:])\/\/[^\n]*/g, "$1");
}

async function sveltePages(dir: string): Promise<string[]> {
    const out: string[] = [];
    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
        if (e.isFile() && e.name.endsWith(".svelte")) {
            out.push(path.join(e.parentPath, e.name));
        }
    }
    return out;
}

const importsAppLayout = (src: string): boolean =>
    /import\s+\w+\s+from\s+["']@\/components\/templates\/AppLayout\.svelte["']/.test(src);

/** PageContent の default import 識別子を返す (別名 import 対応)。無ければ null。 */
function pageContentIdentifier(src: string): string | null {
    const m = src.match(/import\s+(\w+)\s+from\s+["']@\/components\/templates\/PageContent\.svelte["']/);
    return m ? m[1] : null;
}

describe("architecture/page-content-usage", () => {
    it("allowlist の各エントリは理由(reason)必須 (無理由追加禁止を機械強制)", () => {
        for (const entry of ALLOWLIST) {
            expect(entry.reason.trim(), `allowlist "${entry.path}" は理由(reason)必須`).not.toBe("");
        }
    });

    it("AppLayout を使う認証ページ (allowlist 除く) は PageContent を import かつ使用する", async () => {
        const files = await sveltePages(PAGES_DIR);
        const missingImport: string[] = [];
        const unused: string[] = [];

        for (const file of files) {
            const rel = path.relative(PAGES_DIR, file).replace(/\\/g, "/");
            if (ALLOWLIST_PATHS.has(rel)) continue;

            const raw = await fs.readFile(file, "utf8");
            const src = stripComments(raw);
            if (!importsAppLayout(src)) continue;

            const ident = pageContentIdentifier(src);
            if (!ident) {
                missingImport.push(rel);
                continue;
            }
            // 開始タグをタグ名境界まで検査 (接頭辞一致 <PageContentPreview> 等を排除)。
            const usage = new RegExp(`<${escapeRegExp(ident)}(?:\\s|/?>)`);
            if (!usage.test(src)) unused.push(rel);
        }

        expect(
            { missingImport, unused },
            [
                missingImport.length
                    ? `PageContent import 不足 (本文を <PageContent> で包むこと):\n  - ${missingImport.join("\n  - ")}`
                    : "",
                unused.length
                    ? `PageContent を import しているが未使用 (dead import。本文を <PageContent> で包むこと):\n  - ${unused.join("\n  - ")}`
                    : "",
            ]
                .filter(Boolean)
                .join("\n\n"),
        ).toEqual({ missingImport: [], unused: [] });
    });
});
