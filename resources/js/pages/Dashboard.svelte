<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import { Inbox } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import type { SharedProps } from "@/lib/shared-props";

    const shared = $derived(page.props as unknown as SharedProps);
    const user = $derived(shared.auth?.user ?? null);
    const appName = $derived(shared.appName ?? "");

    let loggingOut = $state(false);

    function logout(): void {
        router.post(
            "/logout",
            {},
            {
                onStart: () => {
                    loggingOut = true;
                },
                onFinish: () => {
                    loggingOut = false;
                },
            },
        );
    }
</script>

<AppLayout {appName}>
    {#snippet headerActions()}
        <TextLink href="/settings">設定</TextLink>
        <Button variant="ghost" size="sm" onclick={logout} loading={loggingOut}>
            ログアウト
        </Button>
    {/snippet}

    <h1 class="text-h2">{user?.name ?? ""} さん、ようこそ</h1>
    <p class="mt-1 text-caption text-text-secondary">今日のアクティビティを確認しましょう。</p>

    <Card padding="none" class="mt-6">
        <EmptyState
            title="まだコンテンツがありません"
            description="このテンプレートにアプリ固有の機能を追加すると、ここに表示されます。"
            icon={Inbox}
            testId="dashboard-empty"
        />
    </Card>
</AppLayout>
