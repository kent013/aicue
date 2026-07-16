<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import { Bell } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import Pagination from "@/components/molecules/Pagination.svelte";
    import NotificationListItem from "@/components/features/notifications/NotificationListItem.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { PaginationMeta } from "@/types/manual";
    import type { NotificationItem } from "@/types/notification";

    /**
     * 通知一覧 (全 org 横断 = 自分宛のみ)。行クリックはサーバ解決の open (POST + 303)。
     * 「すべて既読にする」は未読 0 件のとき非表示にする (bug-hunt F-4-01 のプロダクト判断:
     * 既読化する対象が無い操作は導線から見せない)。これは禁止事項 #8「必須条件未充足を理由に
     * disabled にしない」とも整合する (disabled で無反応にするのではなく非表示にしている) が、
     * #8 が常時非表示を要求するわけではない点に注意 (今回は F-4-01 の仕様判断)。
     * 未読が 1 件以上のときは表示し、連打ノイズは in-flight 送信ガード (markingAll) で抑止する
     * (disabled 属性ではない)。未読数は専用 prop `unreadCount` を使う (0・null・負値・NaN は
     * すべて `> 0` が false となり安全に非表示に倒れる)。shared prop notifications.unreadCount は
     * ページ固有 prop `notifications` (配列) とキー衝突し参照できないため参照しない。
     */
    interface Props {
        notifications: NotificationItem[];
        meta: PaginationMeta;
        unreadCount: number;
    }

    let { notifications, meta, unreadCount }: Props = $props();

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
    <PageContainer>
        <PageHeaderSection
            title="通知"
            description="すべての組織の通知が表示されます。"
            icon={Bell}
            testId="notifications-heading"
        >
            {#if unreadCount > 0}
                <Button variant="ghost" size="sm" onclick={markAllRead} testId="read-all-button">
                    すべて既読にする
                </Button>
            {/if}
        </PageHeaderSection>
        <PageContent>
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
        </PageContent>
    </PageContainer>
</AppLayout>
