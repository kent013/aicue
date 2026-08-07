import type { ResolvedComponent } from "@inertiajs/svelte";

/**
 * **Error ページだけは初期 bundle に同梱する** (eager glob)。
 *
 * 他のページと違い、Error ページが必要になるのは「サーバが 4xx/5xx を返している」
 * = ネットワークやサーバが不調な瞬間である。そこで追加の chunk 取得に出ると、
 * 取得失敗 → resolvePage が throw → SPA が無反応、という**今日より悪い**状態になる。
 * 初期 bundle の増分は 1 ページ分にすぎない。
 *
 * テストが検査するため export する (tests/js/architecture/inertia-eager-error-page.test.ts)。
 * ここを増やすと全ページ eager 化に近づくため、同テストがキー集合を exact-fit で固定する。
 */
export const EAGER_PAGES = import.meta.glob<ResolvedComponent>("./pages/Error.svelte", {
    eager: true,
});

/**
 * 遅延解決されるページ (Error 以外はすべてこちら)。
 *
 * ★`!./pages/Error.svelte` で **Error を明示的に除外**する。除外しないと
 * 「Error 以外は lazy」というコメントと実体がズレ、eager/lazy の両方に Error が載って
 * 「eager が効いている」ことを gate で言い切れなくなる。
 */
export const LAZY_PAGES = import.meta.glob<ResolvedComponent>([
    "./pages/**/*.svelte",
    "!./pages/Error.svelte",
]);

/**
 * ページ名 → component の解決 (純関数)。eager map を先に引き、無ければ遅延 loader へ。
 * 未解決時は throw して「真っ白画面で原因不明」を防ぐ。
 *
 * map を引数で受けるのはテスト可能性のため
 * (「Error のとき遅延 loader を 1 度も呼ばない」を spy で固定する)。
 */
export async function resolvePageFrom(
    name: string,
    eager: Record<string, ResolvedComponent>,
    lazy: Record<string, () => Promise<ResolvedComponent>>,
): Promise<ResolvedComponent> {
    const key = `./pages/${name}.svelte`;

    const eagerComponent = eager[key];
    if (eagerComponent) {
        return eagerComponent;
    }

    const loader = lazy[key];
    if (!loader) {
        throw new Error(`Inertia page not found: ${name}`);
    }
    return loader();
}

/** Inertia のページ名を ./pages 配下の Svelte component に解決する。 */
export async function resolvePage(name: string): Promise<ResolvedComponent> {
    return resolvePageFrom(name, EAGER_PAGES, LAZY_PAGES);
}
