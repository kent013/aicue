<script lang="ts">
    import type { Snippet } from "svelte";

    /**
     * `@inertiajs/svelte` の `Link` を差し替えるテスト用スタブ。
     *
     * Inertia の `Link` は素の `<a href>` として描画され、判別できる属性を持たない
     * (dist を確認: 付くのは読み込み中の `data-loading` だけ)。そのため
     * 「Inertia Link ではなく通常の `<a>` である」ことを描画結果だけで検証すると**空振り**する。
     * 本スタブを `vi.mock` で注入し、**描画されたら判別できる印**を残すことで
     * SPA 遷移化への退行を確実に赤くする (Codex impl-review R1 [Critical])。
     */
    let { href, children }: { href?: string; children?: Snippet } = $props();
</script>

<a {href} data-testid="inertia-link-stub">{@render children?.()}</a>
