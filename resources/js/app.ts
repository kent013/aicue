import { createInertiaApp, page } from "@inertiajs/svelte";
import { hydrate, mount } from "svelte";
import { resolvePage } from "./inertia";
import { registerBfcacheGuard } from "./lib/bfcache-guard";
import { registerDocumentTitleSync } from "./lib/document-title";
import { hasAuthenticatedUser } from "./lib/shared-props";

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
    const disposeBfcacheGuard = registerBfcacheGuard({
        isAuthenticated: () => hasAuthenticatedUser(page.props),
    });
    import.meta.hot?.dispose(disposeBfcacheGuard);
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
