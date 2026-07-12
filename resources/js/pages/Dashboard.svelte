<script lang="ts">
    import { page } from "@inertiajs/svelte";
    import { Bell, Building, Camera, FolderPlus, HardDrive, Loader, Ticket } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import StatCard from "@/components/molecules/StatCard.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { DashboardProps } from "@/types/dashboard";
    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";

    /**
     * ダッシュボード (ログイン直後の着地点)。PHP: DashboardController / DashboardPageData と対。
     * state (no_organization / no_project / ready) とロール (editor / shooter / viewer) で
     * 表示を分岐する。権限がない導線は非描画 (disabled ボタンは一切作らない)。
     */
    let { dashboard }: DashboardProps = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const user = $derived(shared.auth?.user ?? null);
    const appName = $derived(shared.appName ?? "");
    // 未読数は shared props (T008 ベルと同源。サーバ二重集計なし)
    const unreadCount = $derived(shared.notifications?.unreadCount ?? 0);

    const billing = $derived(dashboard.billing);
    const project = $derived(dashboard.project);
    const isEditor = $derived(dashboard.role === "editor");
    const isShooter = $derived(dashboard.role === "shooter");

    /** バイト数の可読表記 (残容量タイルの subtext 用) */
    function formatBytes(bytes: number): string {
        if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
        if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
        if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KB`;
        return `${bytes} B`;
    }
</script>

{#snippet shootingCard()}
    <Card class="mt-6" testId="shooting-card">
        <h2 class="text-h3 text-text">撮影対象</h2>
        {#if dashboard.shooting_targets.length === 0}
            <p class="mt-3 text-body text-text-secondary" data-testid="shooting-empty">
                撮影対象はまだありません。
            </p>
        {:else}
            <ul class="mt-3 divide-y divide-border">
                {#each dashboard.shooting_targets as target (target.manual_id)}
                    <li
                        class="flex flex-wrap items-center justify-between gap-3 py-3"
                        data-testid="shooting-item"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-body text-text">{target.title}</p>
                            <p class="mt-1 text-caption text-text-secondary">
                                残り {target.pending_cuts_count}/{target.cuts_count} カット
                            </p>
                        </div>
                        {#if project}
                            <Button
                                size="sm"
                                href={`/app/projects/${project.id}/manuals/${target.manual_id}`}
                                inertia
                                testId="shoot-button"
                            >
                                撮影する
                            </Button>
                        {/if}
                    </li>
                {/each}
            </ul>
        {/if}
    </Card>
{/snippet}

{#snippet recentCard()}
    <Card class="mt-6" testId="recent-card">
        <h2 class="text-h3 text-text">最近のマニュアル</h2>
        {#if dashboard.recent_manuals.length === 0}
            {#if isEditor && project}
                <EmptyState
                    description="最初のマニュアルを作成して、AI にシナリオを設計させましょう。"
                    cta={{
                        kind: "link",
                        label: "最初のマニュアルを作成",
                        href: `/projects/${project.id}/manuals/create`,
                    }}
                    testId="recent-empty"
                />
            {:else}
                <p class="mt-3 text-body text-text-secondary" data-testid="recent-empty">
                    マニュアルはまだありません。編集者が作成すると、ここに表示されます。
                </p>
            {/if}
        {:else}
            <ul class="mt-3 divide-y divide-border">
                {#each dashboard.recent_manuals as manual (manual.id)}
                    <li
                        class="flex flex-wrap items-center justify-between gap-3 py-3"
                        data-testid="recent-item"
                    >
                        <div class="min-w-0">
                            {#if project}
                                <TextLink href={`/projects/${project.id}/manuals/${manual.id}`}>
                                    {manual.title}
                                </TextLink>
                            {:else}
                                <p class="truncate text-body text-text">{manual.title}</p>
                            {/if}
                            <p class="mt-1 text-caption text-text-secondary">
                                {manual.category_name ?? "未分類"} ・ {manual.updated_at}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-3">
                            <Badge tone={STATUS_TONES[manual.status]}>
                                {VIDEO_MANUAL_STATUS_LABELS[manual.status]}
                            </Badge>
                            {#if isEditor && project}
                                <TextLink
                                    href={`/projects/${project.id}/manuals/${manual.id}/edit`}
                                    testId="recent-edit-link"
                                >
                                    編集
                                </TextLink>
                            {/if}
                        </div>
                    </li>
                {/each}
            </ul>
        {/if}
    </Card>
{/snippet}

<!-- 設定/ログアウトのヘッダーナビは AppLayout が常設する (F-08。page-local に持たない) -->
<AppLayout {appName}>
    <h1 class="text-h2">{user?.name ?? ""} さん、ようこそ</h1>
    <p class="mt-1 text-caption text-text-secondary">今日のアクティビティを確認しましょう。</p>

    {#if dashboard.state === "no_organization"}
        <Card padding="none" class="mt-6">
            <EmptyState
                title="まずは組織を作成しましょう"
                description="組織を作成すると、プロジェクトとマニュアルの管理を始められます。"
                icon={Building}
                cta={{ kind: "link", label: "組織を作成", href: "/organizations/create" }}
                testId="dashboard-setup-org"
            />
        </Card>
    {:else}
        <!-- スタットタイル (org があれば billing は非 null) -->
        {#if billing}
            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <StatCard
                        label="チケット残高"
                        value={billing.ticket_balance}
                        subtext={billing.is_low_balance ? "残高が少なくなっています" : undefined}
                        icon={Ticket}
                        testId="stat-tickets"
                    />
                    {#if billing.is_low_balance}
                        <p class="mt-2 text-caption">
                            <TextLink href="/purchase-tickets" testId="purchase-link">
                                チケットを購入
                            </TextLink>
                        </p>
                    {/if}
                </div>
                <StatCard
                    label="容量使用率"
                    value={billing.storage_usage_percent === null
                        ? "無制限"
                        : `${billing.storage_usage_percent}%`}
                    subtext={billing.storage_limit_bytes === null
                        ? `${formatBytes(billing.storage_used_bytes)} 使用中`
                        : `${formatBytes(billing.storage_used_bytes)} / ${formatBytes(billing.storage_limit_bytes)}`}
                    icon={HardDrive}
                    testId="stat-storage"
                />
                <div>
                    <StatCard label="未読通知" value={unreadCount} icon={Bell} testId="stat-unread" />
                    <p class="mt-2 text-caption">
                        <TextLink href="/notifications">通知を確認</TextLink>
                    </p>
                </div>
                <StatCard
                    label="進行中ジョブ"
                    value={dashboard.in_progress.length}
                    icon={Loader}
                    testId="stat-inprogress"
                />
            </div>

            {#if !billing.has_active_subscription}
                <Card class="mt-6" testId="billing-callout">
                    <p class="text-body text-text">
                        有効なサブスクリプションがありません。プランを契約すると、マニュアルの作成・撮影を再開できます。
                    </p>
                    <div class="mt-4">
                        <Button href="/billing" inertia>プランを見る</Button>
                    </div>
                </Card>
            {/if}
        {/if}

        {#if dashboard.state === "no_project"}
            <Card padding="none" class="mt-6">
                {#if dashboard.can_create_project}
                    <EmptyState
                        title="プロジェクトを作成しましょう"
                        description="プロジェクトを作成すると、マニュアルの管理を始められます。"
                        icon={FolderPlus}
                        cta={{ kind: "link", label: "プロジェクトを作成", href: "/projects/create" }}
                        testId="dashboard-setup-project"
                    />
                {:else}
                    <EmptyState
                        title="プロジェクトがまだありません"
                        description={`「${dashboard.organization_name ?? ""}」の管理者にプロジェクト作成を依頼してください。`}
                        icon={FolderPlus}
                        testId="no-project-guidance"
                    />
                {/if}
            </Card>
        {:else if dashboard.state === "ready"}
            {#if dashboard.in_progress.length > 0}
                <Card class="mt-6" testId="inprogress-card">
                    <h2 class="text-h3 text-text">進行中ジョブ</h2>
                    <ul class="mt-3 divide-y divide-border">
                        {#each dashboard.in_progress as row (row.manual_id)}
                            <li class="py-3" data-testid="inprogress-item">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <p class="min-w-0 truncate text-body text-text">{row.title}</p>
                                    <Badge tone={STATUS_TONES[row.manual_status]}>
                                        {VIDEO_MANUAL_STATUS_LABELS[row.manual_status]}
                                    </Badge>
                                </div>
                                {#if row.job_status !== null && row.progress !== null}
                                    <div
                                        class="mt-2 h-2 w-full overflow-hidden rounded-sm bg-neutral"
                                        role="progressbar"
                                        aria-valuenow={row.progress}
                                        aria-valuemin={0}
                                        aria-valuemax={100}
                                        data-testid="inprogress-bar"
                                    >
                                        <div
                                            class="h-full bg-primary"
                                            style="width: {row.progress}%"
                                        ></div>
                                    </div>
                                {:else}
                                    <p class="mt-2 text-caption text-text-secondary">準備中</p>
                                {/if}
                                <div
                                    class="mt-2 flex flex-wrap items-center justify-between gap-2 text-caption text-text-secondary"
                                >
                                    <span>
                                        {row.job_updated_at !== null
                                            ? `最終更新 ${row.job_updated_at}`
                                            : ""}
                                    </span>
                                    {#if project}
                                        <TextLink
                                            href={`/projects/${project.id}/manuals/${row.manual_id}`}
                                            testId="inprogress-detail-link"
                                        >
                                            詳細で最新の進捗を確認
                                        </TextLink>
                                    {/if}
                                </div>
                            </li>
                        {/each}
                    </ul>
                </Card>
            {/if}

            {#if isShooter}
                <!-- 撮影者は撮影対象を先頭に -->
                {@render shootingCard()}
                {@render recentCard()}
            {:else}
                {@render recentCard()}
                {@render shootingCard()}
            {/if}

            {#if project && (isEditor || isShooter)}
                <Card class="mt-6" testId="quick-actions">
                    <h2 class="text-h3 text-text">クイックアクション</h2>
                    <div class="mt-3 flex flex-wrap gap-3">
                        {#if isEditor}
                            <Button
                                href={`/projects/${project.id}/manuals/create`}
                                inertia
                                testId="qa-create-manual"
                            >
                                新規マニュアル作成
                            </Button>
                            <Button
                                variant="neutral"
                                href={`/projects/${project.id}/categories`}
                                inertia
                                testId="qa-categories"
                            >
                                カテゴリ管理
                            </Button>
                        {/if}
                        <Button variant="neutral" href="/app" inertia testId="qa-capture">
                            <Camera class="size-4" aria-hidden="true" />
                            撮影アプリを開く
                        </Button>
                    </div>
                </Card>
            {/if}
        {/if}
    {/if}
</AppLayout>
