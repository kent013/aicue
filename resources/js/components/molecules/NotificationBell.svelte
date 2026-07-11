<script lang="ts">
    import { Bell } from "@lucide/svelte";
    import { Link } from "@inertiajs/svelte";

    /**
     * 通知ベル (未読数バッジ付き)。/notifications への Inertia link。
     * 未読数は shared props notifications.unreadCount (親が渡す)。
     * v1 はドロップダウンなし = フォーカス管理/状態を持たない最小構成。
     */
    interface Props {
        unreadCount: number;
        testId?: string;
    }

    let { unreadCount, testId = "notification-bell" }: Props = $props();

    const badge = $derived(unreadCount > 99 ? "99+" : String(unreadCount));
</script>

<Link
    href="/notifications"
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
