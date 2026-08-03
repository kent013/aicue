<script lang="ts">
    import type { Snippet } from "svelte";
    import { page } from "@inertiajs/svelte";
    import ToastContainer from "@/components/organisms/ToastContainer.svelte";
    import { consumeFlash, type FlashPayload } from "@/lib/stores/flash-to-toast";
    import { clearToasts } from "@/lib/stores/toast";

    /**
     * 認証画面 (login / register / reset 等) 用レイアウト。
     * 中央寄せの surface カード 1 枚構成。
     * Laravel flash は consumeFlash で toast に変換する (visitKey で de-dup)。
     */
    interface Props {
        /** カード上部の見出し */
        title: string;
        appName?: string;
        children: Snippet;
        /** カード下部の補助導線 (別画面へのリンク等) */
        footer?: Snippet;
    }

    let { title, appName, children, footer }: Props = $props();

    // 消去境界 (DESIGN.md §Toast): layout の初期化時に既存 toast を破棄してから
    // 当該 visit の flash を消費する。初期化時の 1 回のみ ($effect に載せない)。
    clearToasts();

    $effect(() => {
        consumeFlash(page.props.flash as FlashPayload | undefined);
    });
</script>

<ToastContainer />

<div class="flex min-h-screen flex-col items-center justify-center bg-neutral px-4 py-10 text-text">
    {#if appName}
        <p class="mb-6 text-h3 text-primary">{appName}</p>
    {/if}
    <main class="w-full max-w-md rounded-lg border border-border bg-surface p-6">
        <h1 class="mb-6 text-h2">{title}</h1>
        {@render children()}
    </main>
    {#if footer}
        <div class="mt-4 text-center text-caption text-text-secondary">
            {@render footer()}
        </div>
    {/if}
</div>
