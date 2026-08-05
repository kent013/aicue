import { describe, it, expect } from "vitest";
import fs from "node:fs/promises";
import path from "node:path";
import { fileURLToPath } from "node:url";

/*
 * svelte-head-no-title — ページ側に title / description の第二 SoT を作らせない。
 *
 * SoT = サーバの SEO 基盤:
 *   - <title>  … SeoManager::resolveDocumentTitle() が唯一の解決経路。
 *                Blade (SeoComposer/SeoRenderer) と Inertia 共有 prop `title`
 *                (HandleInertiaRequests) の両方が同じ文字列を読み、
 *                resources/js/lib/document-title.ts が SPA 遷移で document.title に反映する。
 *   - <meta name="description"> … config('seo.default_description') →
 *                SeoMeta::$description (withDescription) → SeoRenderer::render()。
 *                認証配下 (renderPrivate) では **意図的に出さない** (noindex ページに
 *                メタを残さない)。
 *
 * ここで <svelte:head> に title / description を書くと:
 *   - title: フルロード (サーバ描画) と SPA 遷移 (クライアント上書き) で食い違う。
 *            再現条件が遷移経路依存になりデバッグが極めて難しい
 *   - description: 公開ページ (Welcome.svelte = home / Guest/Pricing.svelte = pricing。
 *            後者は controller が withDescription を実際に供給している) で
 *            同一 <head> に description が 2 個並ぶ = クローラから見た明確な defect。
 *            さらにサーバ側にしかない og:description / twitter:description と食い違い、
 *            SNS カードと検索結果の説明文が別物になる。
 *            認証配下では「noindex なのに description だけ生えている」不整合が復活する
 *
 * **<svelte:head> 自体は禁止しない** (preload hint 等の正当な用途がある)。
 * 禁止するのは SoT が競合する title / description の 2 要素のみ。
 * og:description / twitter:description は現状クライアントから書かれる懸念が薄いため対象外
 * (必要になったら検出集合を広げる)。
 *
 * 現時点で違反 0 件 = 純粋な予防 gate。よって「検出器が実際に点灯すること」を
 * 負のコントロールで固定し、空振り green を防ぐ
 * (tests/js/architecture/pages-path-case-invariant.test.ts と同じ作法)。
 */

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(HERE, "../../../");
const PAGES_DIR = path.join(REPO_ROOT, "resources/js/pages");

/** <svelte:head> ブロックの中身を列挙する (複数ブロック・複数行に対応)。 */
const HEAD_BLOCK = /<svelte:head\s*>([\s\S]*?)<\/svelte:head\s*>/g;

/** <title ...> 開始タグ (属性の有無を問わない)。 */
const TITLE_TAG = /<title[\s/>]/i;

/**
 * <meta ... name=description ...> (属性順・クォート有無を問わない)。
 * HTML は無引用の属性値を許すため `name=description` も有効な書き方であり、
 * クォート必須の regex だと抜け道になる。
 *
 * 無引用形は `\b` ではなく **属性値の終端**まで確認する:
 * `\b` だと `name=description-like` (別属性値) にも一致してしまうため
 * (`-` が非単語文字なので境界が成立する)。
 */
const META_DESCRIPTION_TAG =
    /<meta\b[^>]*\bname\s*=\s*(?:"description"|'description'|description(?=[\s/>]|$))/i;

/**
 * `name` 属性が式の <meta> (`<meta name={metaName}>`)。
 * 「description ではないと静的に証明できない」ので fail させる (deny-by-default)。
 */
const META_DYNAMIC_NAME = /<meta\b[^>]*\bname\s*=\s*\{/i;

/**
 * スプレッド属性の <meta> (`<meta {...attrs}>`)。同上の理由で fail させる。
 *
 * **`content={color}` のような他属性の式は禁止しない** — 宣言した契約は
 * 「title / description のみ禁止」であり、`<meta name="theme-color" content={color}>` は
 * 正当な使い方。禁止対象を `name` の不確定性とスプレッドに限定する。
 */
const META_SPREAD_ATTR = /<meta\b[^>]*\{\s*\.\.\./i;

/**
 * ソース中の <svelte:head> ブロックから、禁止要素の種類を列挙する純関数。
 * 戻り値は "title" / "meta[name=description]" の列。
 */
export function findForbiddenHeadElements(source: string): string[] {
    const found: string[] = [];
    for (const match of source.matchAll(HEAD_BLOCK)) {
        const inner = match[1];
        if (TITLE_TAG.test(inner)) found.push("title");
        if (META_DESCRIPTION_TAG.test(inner)) found.push("meta[name=description]");
        else if (META_DYNAMIC_NAME.test(inner) || META_SPREAD_ATTR.test(inner))
            found.push("meta[dynamic-name]");
    }
    return found;
}

async function pageFiles(dir: string): Promise<string[]> {
    const out: string[] = [];
    for (const e of await fs.readdir(dir, { recursive: true, withFileTypes: true })) {
        if (e.isFile() && e.name.endsWith(".svelte")) out.push(path.join(e.parentPath, e.name));
    }
    return out;
}

describe("architecture/svelte-head-no-title", () => {
    it("resources/js/pages の <svelte:head> に title / meta description が存在しない", async () => {
        const files = await pageFiles(PAGES_DIR);
        const offenders: string[] = [];
        for (const file of files) {
            const hits = findForbiddenHeadElements(await fs.readFile(file, "utf8"));
            for (const hit of hits) offenders.push(`${path.relative(REPO_ROOT, file)}: <${hit}>`);
        }
        expect(
            offenders.sort(), // 失敗メッセージを走査順の環境差で揺らさない
            `<svelte:head> 内の title / meta[name=description] を検出。これらのサーバ単一 SoT ` +
                `(SeoManager::resolveDocumentTitle / SeoRenderer) を壊します。` +
                `title は共有 prop 経由で自動反映されるので何も書かないでください。` +
                `description が必要なら controller から SeoMeta::withDescription() で供給してください: ` +
                `${offenders.join(", ")}`,
        ).toEqual([]);
    });

    it("走査が空振りしていない (ページファイルを実際に列挙できている)", async () => {
        // ディレクトリ移動やビルド構成変更で「0 件検査して green」になる退行を落とす。
        expect((await pageFiles(PAGES_DIR)).length).toBeGreaterThan(0);
    });

    /*
     * 負のコントロール: 検出器が実際に点灯することを fixture 文字列で確認する
     * (実ファイルは書き換えない)。違反 0 件の予防 gate を green として扱わないため。
     */
    it("負のコントロール: <svelte:head> 内の title / meta description を検出する", () => {
        const violations: Array<[string, string[]]> = [
            ["<svelte:head><title>ダッシュボード</title></svelte:head>", ["title"]],
            [
                `<svelte:head>\n  <meta name="description" content="説明" />\n</svelte:head>`,
                ["meta[name=description]"],
            ],
            // 属性順が逆でも検出する
            [
                `<svelte:head><meta content="説明" name="description"></svelte:head>`,
                ["meta[name=description]"],
            ],
            // シングルクォートでも検出する
            [
                `<svelte:head><meta name='description' content='説明'></svelte:head>`,
                ["meta[name=description]"],
            ],
            // **無引用の属性値** も HTML として有効なので検出する
            [
                `<svelte:head><meta name=description content="説明"></svelte:head>`,
                ["meta[name=description]"],
            ],
            // name が式 / スプレッドの <meta> は「description でないと証明できない」ので fail
            [`<svelte:head><meta name={metaName} content={desc}></svelte:head>`, ["meta[dynamic-name]"]],
            [`<svelte:head><meta {...metaAttrs}></svelte:head>`, ["meta[dynamic-name]"]],
            // 同一ブロックに両方あれば 2 件
            [
                `<svelte:head><title>A</title><meta name="description" content="B"></svelte:head>`,
                ["title", "meta[name=description]"],
            ],
        ];
        for (const [source, expected] of violations) {
            expect(findForbiddenHeadElements(source), source).toEqual(expected);
        }
    });

    it("正のコントロール: 許可される <svelte:head> の中身と、head 外の title は検出しない", () => {
        const allowed = [
            // <svelte:head> 自体は禁止しない (preload hint 等は正当な用途)
            `<svelte:head><link rel="preload" href="/fonts/x.woff2" as="font" /></svelte:head>`,
            `<svelte:head><meta name="theme-color" content="#1f2937"></svelte:head>`,
            // SVG の <title> は a11y の正当な用途。<svelte:head> の外なので対象外
            `<svg role="img"><title>再生</title><path d="" /></svg>`,
            // description という語が別文脈にあっても誤検出しない
            `<svelte:head><meta name="og:description-like" content="x"></svelte:head>`,
            // 無引用でも別の属性値なら誤検出しない (属性値の終端まで確認している)
            `<svelte:head><meta name=descriptionfoo content="x"></svelte:head>`,
            `<svelte:head><meta name=description-like content="x"></svelte:head>`,
            // name が静的なら content が式でも許可する (契約は title/description のみ禁止)
            `<svelte:head><meta name="theme-color" content={themeColor}></svelte:head>`,
            `<p>name="description" という文字列を本文に書いても対象外</p>`,
            // <svelte:head> が無ければ何も検出しない
            `<script lang="ts">const title = "ダッシュボード";</script><h1>{title}</h1>`,
        ];
        for (const source of allowed) {
            expect(findForbiddenHeadElements(source), source).toEqual([]);
        }
    });
});
