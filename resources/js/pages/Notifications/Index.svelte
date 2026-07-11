<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import { Bell } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import Pagination from "@/components/molecules/Pagination.svelte";
    import NotificationListItem from "@/components/features/notifications/NotificationListItem.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { PaginationMeta } from "@/types/manual";
    import type { NotificationItem } from "@/types/notification";

    /**
     * 通知一覧 (全 org 横断 = 自分宛のみ)。行クリックはサーバ解決の open (POST + 303)。
     * 「すべて既読にする」は未読 0 でも disabled にしない (押下時は成功 flash のみ。
     * 連打ノイズは in-flight 送信ガードで抑止する = disabled 属性ではなくハンドラ内 guard)。
     */
    interface Props {
        notifications: NotificationItem[];
        meta: PaginationMeta;
    }

    let { notifications, meta }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    let markingAll = $state(false);

    function markAllRead(): void {
        if (markingAll) return; // 連打ガード (disabled 属性ではなく送信ガード)
        router.post(
            "/notifications/read-all",
            {},
            {
                onStart: () => {
                    markingAll = true;
                },
                onFinish: () => {
                    markingAll = false;
                },
            },
        );
    }

    function goToPage(pageNumber: number): void {
        router.get("/notifications", { page: pageNumber });
    }
</script>

<AppLayout {appName}>
    <div class="flex items-start justify-between gap-4">
        <div>
            <h1 class="text-h2">通知</h1>
            <p class="mt-1 text-caption text-text-secondary">
                すべての組織の通知が表示されます。
            </p>
        </div>
        <Button variant="ghost" size="sm" onclick={markAllRead} testId="read-all-button">
            すべて既読にする
        </Button>
    </div>

    {#if notifications.length === 0}
        <div class="mt-6">
            <EmptyState
                title="通知はありません"
                description="ジョブの完了・招待・チケット残高の通知がここに表示されます。"
                icon={Bell}
                bordered
                testId="notifications-empty"
            />
        </div>
    {:else}
        <Card padding="none" class="mt-6 overflow-hidden">
            <ul data-testid="notification-list">
                {#each notifications as notification (notification.id)}
                    <li>
                        <NotificationListItem {notification} />
                    </li>
                {/each}
            </ul>
        </Card>
        {#if meta.last_page > 1}
            <div class="mt-6">
                <Pagination
                    currentPage={meta.current_page}
                    totalPages={meta.last_page}
                    onChange={goToPage}
                    testId="notifications-pagination"
                />
            </div>
        {/if}
    {/if}
</AppLayout>
