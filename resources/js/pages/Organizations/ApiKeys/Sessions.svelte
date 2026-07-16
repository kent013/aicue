<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import ApiKeyTabNav from "@/components/molecules/ApiKeyTabNav.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import { Plug } from "@lucide/svelte";
    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
    import type { SharedProps } from "@/lib/shared-props";
    import type { OAuthSession } from "@/types/OAuthSession";

    interface Props {
        organization: { id: number; name: string; slug: string };
        sessions: OAuthSession[];
        legacyTokens: OAuthSession[];
    }

    let { organization, sessions, legacyTokens }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    const base = $derived(`/organizations/${organization.slug}`);
    const tabs = $derived([
        { label: "API キー", href: `${base}/api-keys`, active: false, testId: "tab-api-keys" },
        {
            label: "接続セッション",
            href: `${base}/api-keys/sessions`,
            active: true,
            testId: "tab-sessions",
        },
    ]);

    const CLIENT_KIND_LABELS: Record<string, string> = { cli: "CLI", mcp: "MCP" };
    function clientKindLabel(kind: string): string {
        return CLIENT_KIND_LABELS[kind] ?? kind;
    }

    /* ---- recent-auth (step-up) precheck ---- */
    let recentAuthOpen = $state(false);
    let recentAuthStatus = $state<RecentAuthStatus | null>(null);
    let pendingAction: (() => void) | null = null;

    function guardWithRecentAuth(action: () => void): void {
        void withRecentAuth({
            onFresh: action,
            onStale: (status) => {
                recentAuthStatus = status;
                pendingAction = action;
                recentAuthOpen = true;
            },
        });
    }

    function resumePendingAction(): void {
        const action = pendingAction;
        pendingAction = null;
        action?.();
    }

    /* ---- セッション失効 (ConfirmDialog。recent-auth 必須) ---- */
    let revokeTarget = $state<OAuthSession | null>(null);
    let revokeDialogOpen = $state(false);
    let revoking = $state(false);

    function openRevokeDialog(session: OAuthSession): void {
        revokeTarget = session;
        revokeDialogOpen = true;
    }

    function revokeSession(): void {
        if (revokeTarget === null) return;
        const id = revokeTarget.id;
        guardWithRecentAuth(() => {
            router.delete(`${base}/api-keys/sessions/${id}`, {
                preserveScroll: true,
                onStart: () => {
                    revoking = true;
                },
                onFinish: () => {
                    revoking = false;
                    revokeDialogOpen = false;
                },
            });
        });
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="接続セッション"
            description={`${organization.name} の CLI / MCP 接続 (OAuth) を管理します。失効するとそのセッション配下のトークンは以降拒否されます。`}
            icon={Plug}
            testId="sessions-heading"
        />
        <PageContent>
            <ApiKeyTabNav {tabs} />

            <div class="mt-6 flex flex-col gap-6">
                <Card padding="lg">
                    <h2 class="text-h3">OAuth セッション</h2>
                    <p class="mt-1 text-caption text-text-secondary">
                        ブラウザログイン (OAuth) で確立した接続です。失効済みも棚卸しのため残します。
                    </p>

                    {#if sessions.length === 0}
                        <EmptyState
                            title="接続セッションはありません"
                            description="CLI / MCP からログインすると、その接続がここに表示されます。"
                        />
                    {:else}
                        <ul
                            class="mt-4 flex flex-col divide-y divide-border"
                            data-testid="oauth-session-list"
                        >
                            {#each sessions as session (session.id)}
                                <li class="flex items-center justify-between gap-4 py-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <p class="truncate text-body">
                                                {session.userName ?? "(不明なユーザー)"}
                                            </p>
                                            <Badge tone="neutral">
                                                {clientKindLabel(session.clientKind)}
                                            </Badge>
                                            {#if session.revokedAt}
                                                <Badge tone="danger">失効済み</Badge>
                                            {/if}
                                        </div>
                                        <p class="truncate text-caption text-text-secondary">
                                            {session.scopes.length > 0
                                                ? session.scopes.join(", ")
                                                : "スコープなし"} ・ 最終利用 {session.lastUsedAt ??
                                                "未使用"}
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        {#if !session.revokedAt}
                                            <Button
                                                variant="danger-ghost"
                                                size="sm"
                                                onclick={() => openRevokeDialog(session)}
                                                testId={`revoke-session-${session.id}`}
                                            >
                                                失効
                                            </Button>
                                        {/if}
                                    </div>
                                </li>
                            {/each}
                        </ul>
                    {/if}
                </Card>

                {#if legacyTokens.length > 0}
                    <Card padding="lg">
                        <h2 class="text-h3">レガシー接続 (棚卸し)</h2>
                        <p class="mt-1 text-caption text-text-secondary">
                            セッション管理の対象外 (session_id なし) の古い MCP トークンです。表示のみで、失効はトークン発行元での取り消しが必要です。
                        </p>
                        <ul
                            class="mt-4 flex flex-col divide-y divide-border"
                            data-testid="legacy-token-list"
                        >
                            {#each legacyTokens as token (token.id)}
                                <li class="flex items-center justify-between gap-4 py-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <p class="truncate text-body">
                                                {token.userName ?? "(不明なユーザー)"}
                                            </p>
                                            <Badge tone="neutral">
                                                {clientKindLabel(token.clientKind)}
                                            </Badge>
                                            <Badge tone="warning">レガシー</Badge>
                                        </div>
                                        <p class="truncate text-caption text-text-secondary">
                                            {token.scopes.length > 0
                                                ? token.scopes.join(", ")
                                                : "スコープなし"}
                                        </p>
                                    </div>
                                </li>
                            {/each}
                        </ul>
                    </Card>
                {/if}
            </div>

            <ConfirmDialog
            bind:open={revokeDialogOpen}
            title="セッション失効"
            message={`${revokeTarget?.userName ?? "この"} さんの接続を失効しますか？ 配下のトークンは以降拒否されます。この操作は取り消せません。`}
            confirmLabel="失効する"
            confirmVariant="danger"
            processing={revoking}
            onConfirm={revokeSession}
            testId="revoke-session-dialog"
        />

        <RecentAuthModal
            bind:open={recentAuthOpen}
            passwordSet={recentAuthStatus?.passwordSet ?? false}
            availableProviders={recentAuthStatus?.availableProviders ?? []}
            canSatisfy={recentAuthStatus?.canSatisfy ?? true}
            onConfirmed={resumePendingAction}
        />
        </PageContent>
    </PageContainer>
</AppLayout>
