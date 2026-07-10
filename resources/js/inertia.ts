import type { ResolvedComponent } from "@inertiajs/svelte";

const pages = import.meta.glob<ResolvedComponent>("./pages/**/*.svelte");

/**
 * Inertia のページ名を ./pages 配下の Svelte component に解決する。
 * 未解決時は throw して「真っ白画面で原因不明」を防ぐ。
 */
export async function resolvePage(name: string): Promise<ResolvedComponent> {
    const loader = pages[`./pages/${name}.svelte`];
    if (!loader) {
        throw new Error(`Inertia page not found: ${name}`);
    }
    return loader();
}
