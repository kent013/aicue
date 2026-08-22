<script lang="ts">
    import { Bell } from "@lucide/svelte";
    import { Link } from "@inertiajs/svelte";

    /**
     * 通知ベル (未読数バッジ付き)。遷移先 URL は**親が渡す**。
     *
     * ★通知一覧は組織 URL 配下にある (家系裁定 AG-037) ので、atom/molecule が
     *   組織文脈を自分で解決しない (組織を持たない面に置かれたときに壊れる)。
     * 未読数は shared props notifications.unreadCount (親が渡す)。
     * v1 はドロップダウンなし = フォーカス管理/状態を持たない最小構成。
     */
    interface Props {
        unreadCount: number;
        href: string;
        testId?: string;
    }

    let { unreadCount, href, testId = "notification-bell" }: Props = $props();

    const badge = $derived(unreadCount > 99 ? "99+" : String(unreadCount));
</script>

<Link
    {href}
    class="relative inline-flex size-9 items-center justify-center rounded-md text-text-secondary
        hover:bg-neutral hover:text-text"
    aria-label="通知"
    data-testid={testId}
>
    <Bell class="size-5" aria-hidden="true" />
    {#if unreadCount > 0}
        <span
            class="absolute -top-1 -right-1 inline-flex min-w-4 items-center justify-center
                rounded-sm bg-danger px-1 text-caption text-neutral"
            data-testid="unread-badge"
        >
            {badge}
        </span>
    {/if}
</Link>
