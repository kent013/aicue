/**
 * SPA 遷移後の document.title 追従。
 *
 * title/meta はサーバ SEO 基盤 (SeoComposer/SeoRenderer) に一本化している。
 * フルロード時は <title> が正しいが、Inertia SPA でページ間を遷移すると app.blade.php は
 * 再描画されず document.title が陳腐化する。
 *
 * 方針: サーバが <title> に描画するのと同一の文字列を Inertia 共有 prop `title` として供給し
 * (HandleInertiaRequests / SeoManager::resolveDocumentTitle)、各 visit ごとに document.title を
 * 同期する。クライアント側に第二の title SoT (<svelte:head> 等) を作らない。
 *
 * Inertia v3 では `page` が store ではなく runes-reactive オブジェクトになり store 購読が
 * 使えないため、`router.on('navigate')` (成功 visit + history navigation で発火) を購読する。
 */
import { page as defaultPage, router as defaultRouter } from "@inertiajs/svelte";

/**
 * 共有 prop の title を document.title へ反映する純関数。
 *
 * - 非 string / trim 後空 → no-op (サーバ未供給時に既存の正しい <title> を壊さない)
 * - 同値 → 代入しない (無駄な DOM 書き込みを避ける)
 */
export function applyDocumentTitle(title: unknown): void {
    if (typeof title !== "string") return;

    const next = title.trim();
    if (next === "") return;

    if (document.title !== next) {
        document.title = next;
    }
}

/** navigate イベントが最小限満たす形 (adapter 実装非依存)。 */
interface NavigateEvent {
    detail: { page?: { props?: Record<string, unknown> } };
}

/** `registerDocumentTitleSync` がテスト差替可能にする最小 router 契約。 */
export interface DocumentTitleRouter {
    on(event: "navigate", callback: (event: NavigateEvent) => void): () => void;
}

/** `registerDocumentTitleSync` がテスト差替可能にする最小 page 契約。 */
export interface DocumentTitlePage {
    props?: Record<string, unknown>;
}

/**
 * navigate イベントを購読し、visit ごとに props.title を document.title へ反映する。
 *
 * - 初回 (マウント時点) の `page.props.title` を一度反映 (navigate 前のフルロード分)。
 * - 以降は `router.on('navigate')` (成功 visit + history navigation で発火) で追従。
 * - 戻り値は購読解除 disposer。HMR / テストの二重登録を防ぐため呼び出し側が保持する。
 * - `router` / `page` は注入可能 (既定で `@inertiajs/svelte` のもの) でテスト差替を容易にする。
 */
export function registerDocumentTitleSync(
    router: DocumentTitleRouter = defaultRouter,
    page: DocumentTitlePage = defaultPage,
): () => void {
    applyDocumentTitle(page.props?.title);

    return router.on("navigate", (event) => {
        applyDocumentTitle(event.detail.page?.props?.title);
    });
}
