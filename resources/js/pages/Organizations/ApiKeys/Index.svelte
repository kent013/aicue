<script lang="ts">
    import { page, router, useForm } from "@inertiajs/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Checkbox from "@/components/atoms/Checkbox.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import ApiKeyTabNav from "@/components/molecules/ApiKeyTabNav.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
    import type { SharedProps } from "@/lib/shared-props";

    interface ApiKeyItem {
        id: number;
        name: string;
        keyPrefix: string;
        abilities: string[];
        lastUsedAt: string | null;
        revokedAt: string | null;
        createdAt: string | null;
    }

    interface NewApiKey {
        id: number;
        name: string;
        plain: string;
    }

    interface Props {
        organization: { id: number; name: string; slug: string };
        apiKeys: ApiKeyItem[];
        newApiKey?: NewApiKey | null;
    }

    let { organization, apiKeys, newApiKey = null }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    const base = $derived(`/organizations/${organization.slug}`);
    const tabs = $derived([
        { label: "API キー", href: `${base}/api-keys`, active: true, testId: "tab-api-keys" },
        {
            label: "接続セッション",
            href: `${base}/api-keys/sessions`,
            active: false,
            testId: "tab-sessions",
        },
    ]);

    const ABILITY_LABELS: Record<string, string> = { read: "読み取り", write: "書き込み" };
    function abilityLabel(ability: string): string {
        return ABILITY_LABELS[ability] ?? ability;
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

    /* ---- 発行 (Modal。recent-auth 必須) ---- */
    let issueModalOpen = $state(false);
    let abilityRead = $state(true);
    let abilityWrite = $state(false);
    const issueForm = useForm({ name: "", abilities: [] as string[] });

    function submitIssue(event: SubmitEvent): void {
        event.preventDefault();
        issueForm.abilities = [
            ...(abilityRead ? ["read"] : []),
            ...(abilityWrite ? ["write"] : []),
        ];
        guardWithRecentAuth(() => {
            issueForm.post(`${base}/api-keys`, {
                preserveScroll: true,
                onSuccess: () => {
                    issueForm.reset();
                    abilityRead = true;
                    abilityWrite = false;
                    issueModalOpen = false;
                },
            });
        });
    }

    /* ---- 失効 (ConfirmDialog。recent-auth 必須) ---- */
    let revokeTarget = $state<ApiKeyItem | null>(null);
    let revokeDialogOpen = $state(false);
    let revoking = $state(false);

    function openRevokeDialog(apiKey: ApiKeyItem): void {
        revokeTarget = apiKey;
        revokeDialogOpen = true;
    }

    function revokeApiKey(): void {
        if (revokeTarget === null) return;
        const id = revokeTarget.id;
        guardWithRecentAuth(() => {
            router.delete(`${base}/api-keys/${id}`, {
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
    <PageContent maxWidth="2xl">
        <h1 class="text-h2">API キー</h1>
        <p class="mt-1 text-caption text-text-secondary">
            {organization.name} の REST API / MCP アクセスに使う組織スコープのキーとセッションを管理します。
        </p>

        <div class="mt-6">
            <ApiKeyTabNav {tabs} />

            <div class="mt-6 flex flex-col gap-6">
                <Card padding="lg">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-h3">発行済みキー</h2>
                            <p class="mt-1 text-caption text-text-secondary">
                                平文キーは発行直後に 1 度だけ表示されます。失効済みキーは監査痕跡として残ります。
                            </p>
                        </div>
                        <Button
                            size="sm"
                            onclick={() => (issueModalOpen = true)}
                            testId="issue-api-key-button"
                        >
                            発行
                        </Button>
                    </div>

                    {#if newApiKey}
                        <div
                            class="mt-4 rounded-md border border-success bg-success/10 p-4"
                            data-testid="new-api-key"
                        >
                            <p class="text-body font-medium text-text">
                                {newApiKey.name} を発行しました
                            </p>
                            <p class="mt-1 text-caption text-text-secondary">
                                このキーが表示されるのは今回限りです。安全な場所に保存してください。
                            </p>
                            <code
                                class="mt-2 block rounded-sm bg-surface px-3 py-2 text-caption font-mono break-all"
                                data-testid="new-api-key-plain">{newApiKey.plain}</code
                            >
                        </div>
                    {/if}

                    {#if apiKeys.length === 0}
                        <EmptyState
                            title="API キーはありません"
                            description="発行したキーはここに表示されます。"
                        />
                    {:else}
                        <ul
                            class="mt-4 flex flex-col divide-y divide-border"
                            data-testid="api-key-list"
                        >
                            {#each apiKeys as apiKey (apiKey.id)}
                                <li class="flex items-center justify-between gap-4 py-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2">
                                            <p class="truncate text-body">{apiKey.name}</p>
                                            {#each apiKey.abilities as ability (ability)}
                                                <Badge tone="neutral">{abilityLabel(ability)}</Badge>
                                            {/each}
                                            {#if apiKey.revokedAt}
                                                <Badge tone="danger">失効済み</Badge>
                                            {/if}
                                        </div>
                                        <p class="truncate text-caption text-text-secondary">
                                            {apiKey.keyPrefix}… ・ 発行 {apiKey.createdAt ?? "—"} ・
                                            最終利用 {apiKey.lastUsedAt ?? "未使用"}
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        {#if !apiKey.revokedAt}
                                            <Button
                                                variant="danger-ghost"
                                                size="sm"
                                                onclick={() => openRevokeDialog(apiKey)}
                                                testId={`revoke-api-key-${apiKey.id}`}
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

                <Card padding="lg">
                    <h2 class="text-h3">導入ガイド</h2>
                    <p class="mt-1 text-caption text-text-secondary">
                        AI クライアント (MCP) やターミナル (CLI) からの接続手順を確認できます。
                    </p>
                    <div class="mt-3 flex gap-4">
                        <TextLink href={`${base}/onboarding/mcp`} testId="link-onboarding-mcp">
                            MCP 導入ガイド
                        </TextLink>
                        <TextLink href={`${base}/onboarding/cli`} testId="link-onboarding-cli">
                            CLI 導入ガイド
                        </TextLink>
                    </div>
                </Card>
            </div>
        </div>

        <Modal bind:open={issueModalOpen} title="API キーを発行" testId="issue-api-key-modal">
            <form onsubmit={submitIssue} class="flex flex-col gap-4">
                <FormField label="キー名" id="api-key-name" error={issueForm.errors.name}>
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="text"
                            bind:value={issueForm.name}
                            error={invalid}
                            aria-describedby={describedBy}
                            autocomplete="off"
                        />
                    {/snippet}
                </FormField>
                <fieldset class="flex flex-col gap-2">
                    <legend class="text-caption font-medium text-text">権限 (ability)</legend>
                    <Checkbox
                        id="api-key-ability-read"
                        bind:checked={abilityRead}
                        label="読み取り (read)"
                        testId="api-key-ability-read"
                    />
                    <Checkbox
                        id="api-key-ability-write"
                        bind:checked={abilityWrite}
                        label="書き込み (write)"
                        error={issueForm.errors.abilities}
                        testId="api-key-ability-write"
                    />
                </fieldset>
                <p class="text-caption text-text-secondary">
                    平文キーは発行直後に 1 度だけ表示されます。再表示はできません。
                </p>
                <div class="flex justify-end">
                    <Button type="submit" loading={issueForm.processing} testId="issue-api-key-submit">
                        発行する
                    </Button>
                </div>
            </form>
        </Modal>

        <ConfirmDialog
            bind:open={revokeDialogOpen}
            title="API キー失効"
            message={`${revokeTarget?.name ?? ""} を失効しますか？ このキーを使う連携は直ちに動かなくなります。この操作は取り消せません。`}
            confirmLabel="失効する"
            confirmVariant="danger"
            processing={revoking}
            onConfirm={revokeApiKey}
            testId="revoke-api-key-dialog"
        />

        <RecentAuthModal
            bind:open={recentAuthOpen}
            passwordSet={recentAuthStatus?.passwordSet ?? false}
            availableProviders={recentAuthStatus?.availableProviders ?? []}
            canSatisfy={recentAuthStatus?.canSatisfy ?? true}
            onConfirmed={resumePendingAction}
        />
    </PageContent>
</AppLayout>
