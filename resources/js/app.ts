import { createInertiaApp } from "@inertiajs/svelte";
import { hydrate, mount } from "svelte";
import { resolvePage } from "./inertia";
import { registerDocumentTitleSync } from "./lib/document-title";

// SPA 遷移後の document.title 陳腐化を解消する。Svelte adapter には createInertiaApp の
// title callback が無いため、router.on('navigate') を購読してサーバ共有 prop `title` を
// document.title へ同期する (= title callback の等価機構)。document 不在 (SSR) では no-op。
if (typeof document !== "undefined") {
    const disposeTitleSync = registerDocumentTitleSync();
    // HMR 二重登録防止: dev の hot reload で app.ts が再評価される際に前回の
    // router.on('navigate') 購読を解除する。本番ビルドでは import.meta.hot は undefined。
    import.meta.hot?.dispose(disposeTitleSync);
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
