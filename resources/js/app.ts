import { createInertiaApp, page } from "@inertiajs/svelte";
import { hydrate, mount } from "svelte";
import { resolvePage } from "./inertia";
import { readSessionEpochCookie, registerBfcacheGuard } from "./lib/bfcache-guard";
import { registerDocumentTitleSync } from "./lib/document-title";
import { registerRecentAuthRedirectHandler } from "./lib/recent-auth";
import { hasAuthenticatedUser, readSessionEpoch } from "./lib/shared-props";

// SPA 遷移後の document.title 陳腐化を解消する。Svelte adapter には createInertiaApp の
// title callback が無いため、router.on('navigate') を購読してサーバ共有 prop `title` を
// document.title へ同期する (= title callback の等価機構)。document 不在 (SSR) では no-op。
if (typeof document !== "undefined") {
    const disposeTitleSync = registerDocumentTitleSync();
    // HMR 二重登録防止: dev の hot reload で app.ts が再評価される際に前回の
    // router.on('navigate') 購読を解除する。本番ビルドでは import.meta.hot は undefined。
    import.meta.hot?.dispose(disposeTitleSync);

    // bfcache 復元時の PII 再表示を塞ぐ (詳細設計 施策 6)。作動条件は Inertia 共有 props の
    // auth.user (= 認証済みページのみ)。判定は登録時に固定せず pagehide のたびに評価する:
    // login は Inertia の client-side 遷移で完了するため、「起動時 guest だった document が
    // そのまま認証済み画面になる」経路があり、起動時 1 回の判定では取りこぼす。
    // 公開ページ (LP / login / SEO) では秘匿もプローブも起こらない点は同じ。
    // 2 つの世代の出所は呼び出し側で名前付きで明示する (既定任せにすると、読み手が
    // 「描画世代と現世代がどこから来るか」を追えない)。
    const disposeBfcacheGuard = registerBfcacheGuard({
        isAuthenticated: () => hasAuthenticatedUser(page.props),
        // 描画世代は **いま画面に出ている内容と同じ応答で来た値**を使う
        // (cookie から読むと「内容は A・印は B」の取り違えが起きる)
        readRenderedEpoch: () => readSessionEpoch(page.props),
        // 現世代は cookie の写し。同期判定でしか使わない (開示の根拠にはしない)
        readCurrentEpoch: () => readSessionEpochCookie(document.cookie),
    });
    import.meta.hot?.dispose(disposeBfcacheGuard);

    // recent-auth 鮮度切れの 409 (recent_auth_required) を confirm 画面へ着地させる単一ハンドラ。
    // precheck (withRecentAuth) を通れない delegated 経路の受け皿であり、これが無いと
    // 409 が Inertia の既定エラーモーダルに落ちて無言の行き止まりになる (詳細設計 施策 4)。
    const disposeRecentAuthRedirect = registerRecentAuthRedirectHandler();
    import.meta.hot?.dispose(disposeRecentAuthRedirect);
}

createInertiaApp({
    resolve: resolvePage,
    setup({ el, App, props }) {
        if (!el) {
            throw new Error("Inertia root element not found");
        }
        if (el.dataset.serverRendered === "true") {
            hydrate(App, { target: el, props });
        } else {
            mount(App, { target: el, props });
        }
    },
});
