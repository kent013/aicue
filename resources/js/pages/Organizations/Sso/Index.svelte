<script lang="ts">
    /**
     * 企業 IdP との OIDC SSO 接続の管理 (一覧 + 登録・更新フォーム)。
     *
     * ★画面は **1 枚**である。**接続の秘密を扱う前面を 2 枚に割らない** (正典 v1 / I4)。
     * ★サーバから渡ってくるのは `hasClientSecret` の真偽だけで、
     *   **平文も伏字も渡らない** (一覧の経路はサーバ側でも復号しない)。
     * ★**必須条件が未充足でもボタンを disabled にしない**。押した時にエラーを表示する
     *   (禁止事項 8)。身元がある接続の削除・発行者 URL の変更も「押せるが拒否される」形にし、
     *   拒否の理由はサーバの応答としてエラー表示に出す。
     */
    import { page, router, useForm } from "@inertiajs/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import {
        OIDC_CONNECTION_STATUS_HINTS,
        OIDC_CONNECTION_STATUS_LABELS,
        OIDC_CONNECTION_STATUS_TONES,
        type SsoConnectionSummary,
    } from "@/components/features/sso/oidc-connection";
    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
    import type { SharedProps } from "@/lib/shared-props";
    import { orgUrl } from "@/lib/org-url";
    import { ShieldCheck } from "@lucide/svelte";

    interface Props {
        organization: { id: number; name: string; slug: string };
        connections: SsoConnectionSummary[];
        callbackUrl: string;
    }

    let { organization, connections, callbackUrl }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

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

    /* ---- 登録 ---- */
    let registerModalOpen = $state(false);
    const registerForm = useForm({
        login_slug: "",
        display_name: "",
        issuer: "",
        client_id: "",
        client_secret: "",
    });

    function submitRegister(event: SubmitEvent): void {
        event.preventDefault();
        guardWithRecentAuth(() => {
            registerForm.post(orgUrl(organization.slug, "/sso"), {
                preserveScroll: true,
                onSuccess: () => {
                    registerForm.reset();
                    registerModalOpen = false;
                },
            });
        });
    }

    /* ---- 更新 ---- */
    let editTarget = $state<SsoConnectionSummary | null>(null);
    let editModalOpen = $state(false);
    const editForm = useForm({
        display_name: "",
        issuer: "",
        client_id: "",
        client_secret: "",
    });

    function openEdit(connection: SsoConnectionSummary): void {
        editTarget = connection;
        editForm.display_name = connection.displayName;
        editForm.issuer = connection.issuer;
        editForm.client_id = connection.clientId;
        // ★秘密は**空**で開く。空のまま送れば据え置きである (伏字を送らない)。
        editForm.client_secret = "";
        editModalOpen = true;
    }

    function submitEdit(event: SubmitEvent): void {
        event.preventDefault();
        const target = editTarget;
        if (target === null) return;
        guardWithRecentAuth(() => {
            editForm.patch(orgUrl(organization.slug, `/sso/${target.id}`), {
                preserveScroll: true,
                onSuccess: () => {
                    editModalOpen = false;
                },
            });
        });
    }

    /* ---- 状態を変える操作 ---- */
    let busyConnectionId = $state<number | null>(null);

    function postAction(connection: SsoConnectionSummary, action: string): void {
        guardWithRecentAuth(() => {
            router.post(
                orgUrl(organization.slug, `/sso/${connection.id}/${action}`),
                {},
                {
                    preserveScroll: true,
                    onStart: () => {
                        busyConnectionId = connection.id;
                    },
                    onFinish: () => {
                        busyConnectionId = null;
                    },
                },
            );
        });
    }

    /* ---- 削除 ---- */
    let deleteTarget = $state<SsoConnectionSummary | null>(null);
    let deleteDialogOpen = $state(false);
    let deleting = $state(false);

    function openDelete(connection: SsoConnectionSummary): void {
        deleteTarget = connection;
        deleteDialogOpen = true;
    }

    function deleteConnection(): void {
        const target = deleteTarget;
        if (target === null) return;
        guardWithRecentAuth(() => {
            router.delete(orgUrl(organization.slug, `/sso/${target.id}`), {
                preserveScroll: true,
                onStart: () => {
                    deleting = true;
                },
                onFinish: () => {
                    deleting = false;
                    deleteDialogOpen = false;
                },
            });
        });
    }

    const errors = $derived(shared.errors ?? {});
    const connectionError = $derived(
        typeof errors.sso_connection === "string" ? errors.sso_connection : null,
    );
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="SSO 接続"
            description={`${organization.name} のメンバーが勤務先の ID プロバイダ (IdP) でログインできるようにします。`}
            icon={ShieldCheck}
            testId="sso-connections-heading"
        />
        <PageContent>
            <div class="flex flex-col gap-6">
                {#if connectionError}
                    <div
                        class="rounded-md border border-danger bg-danger/10 p-4 text-body text-text"
                        role="alert"
                        data-testid="sso-connection-error"
                    >
                        {connectionError}
                    </div>
                {/if}

                <Card padding="lg">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h2 class="text-h3">登録済みの接続</h2>
                            <p class="mt-1 text-caption text-text-secondary">
                                IdP 側には戻り先として <code class="font-mono">{callbackUrl}</code> を登録してください。
                            </p>
                        </div>
                        <Button size="sm" onclick={() => (registerModalOpen = true)} testId="register-sso-button">
                            接続を登録
                        </Button>
                    </div>

                    {#if connections.length === 0}
                        <EmptyState
                            title="SSO 接続はありません"
                            description="登録した接続はここに表示されます。"
                        />
                    {:else}
                        <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="sso-connection-list">
                            {#each connections as connection (connection.id)}
                                <li class="flex flex-col gap-3 py-4">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <p class="truncate text-body">{connection.displayName}</p>
                                        <Badge tone={OIDC_CONNECTION_STATUS_TONES[connection.status]}>
                                            {OIDC_CONNECTION_STATUS_LABELS[connection.status]}
                                        </Badge>
                                        {#if connection.hasIdentities}
                                            <Badge tone="neutral">利用者あり</Badge>
                                        {/if}
                                    </div>

                                    <p class="text-caption text-text-secondary">
                                        {OIDC_CONNECTION_STATUS_HINTS[connection.status]}
                                    </p>

                                    <dl class="grid grid-cols-1 gap-1 text-caption text-text-secondary sm:grid-cols-2">
                                        <div class="flex min-w-0 gap-2">
                                            <dt class="shrink-0">ログイン用の識別名</dt>
                                            <dd class="truncate font-mono">{connection.loginSlug}</dd>
                                        </div>
                                        <div class="flex min-w-0 gap-2">
                                            <dt class="shrink-0">発行者 URL</dt>
                                            <dd class="truncate font-mono">{connection.issuer}</dd>
                                        </div>
                                        <div class="flex min-w-0 gap-2">
                                            <dt class="shrink-0">クライアント ID</dt>
                                            <dd class="truncate font-mono">{connection.clientId}</dd>
                                        </div>
                                        <div class="flex min-w-0 gap-2">
                                            <dt class="shrink-0">シークレット</dt>
                                            <dd>{connection.hasClientSecret ? "登録済み" : "未登録"}</dd>
                                        </div>
                                    </dl>

                                    <div class="flex flex-wrap items-center gap-2">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onclick={() => openEdit(connection)}
                                            testId={`edit-sso-${connection.id}`}
                                        >
                                            編集
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            loading={busyConnectionId === connection.id}
                                            onclick={() => postAction(connection, "verify")}
                                            testId={`verify-sso-${connection.id}`}
                                        >
                                            確認
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            loading={busyConnectionId === connection.id}
                                            onclick={() => postAction(connection, "activate")}
                                            testId={`activate-sso-${connection.id}`}
                                        >
                                            有効化
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            loading={busyConnectionId === connection.id}
                                            onclick={() => postAction(connection, "disable")}
                                            testId={`disable-sso-${connection.id}`}
                                        >
                                            無効化
                                        </Button>
                                        <Button
                                            variant="danger-ghost"
                                            size="sm"
                                            onclick={() => openDelete(connection)}
                                            testId={`delete-sso-${connection.id}`}
                                        >
                                            削除
                                        </Button>
                                    </div>
                                </li>
                            {/each}
                        </ul>
                    {/if}
                </Card>
            </div>

            <Modal bind:open={registerModalOpen} title="SSO 接続を登録" testId="register-sso-modal">
                <form novalidate onsubmit={submitRegister} class="flex flex-col gap-4">
                    <FormField label="識別名" id="sso-slug" error={registerForm.errors.login_slug}
                        help="ログイン導線の URL に使う名前です。英小文字・数字・ハイフンで入力してください。">
                        {#snippet children({ id, describedBy, invalid })}
                            <Input {id} type="text" bind:value={registerForm.login_slug} error={invalid}
                                aria-describedby={describedBy} autocomplete="off" testId="sso-slug" />
                        {/snippet}
                    </FormField>
                    <FormField label="表示名" id="sso-display-name" error={registerForm.errors.display_name}>
                        {#snippet children({ id, describedBy, invalid })}
                            <Input {id} type="text" bind:value={registerForm.display_name} error={invalid}
                                aria-describedby={describedBy} autocomplete="off" testId="sso-display-name" />
                        {/snippet}
                    </FormField>
                    <FormField label="発行者 URL" id="sso-issuer" error={registerForm.errors.issuer}
                        help="IdP が示す issuer をそのまま入力してください (末尾のスラッシュも区別されます)。">
                        {#snippet children({ id, describedBy, invalid })}
                            <Input {id} type="text" bind:value={registerForm.issuer} error={invalid}
                                aria-describedby={describedBy} autocomplete="off" testId="sso-issuer" />
                        {/snippet}
                    </FormField>
                    <FormField label="クライアント ID" id="sso-client-id" error={registerForm.errors.client_id}>
                        {#snippet children({ id, describedBy, invalid })}
                            <Input {id} type="text" bind:value={registerForm.client_id} error={invalid}
                                aria-describedby={describedBy} autocomplete="off" testId="sso-client-id" />
                        {/snippet}
                    </FormField>
                    <FormField label="クライアントシークレット" id="sso-client-secret"
                        error={registerForm.errors.client_secret}>
                        {#snippet children({ id, describedBy, invalid })}
                            <Input {id} type="password" bind:value={registerForm.client_secret} error={invalid}
                                aria-describedby={describedBy} autocomplete="off" testId="sso-client-secret" />
                        {/snippet}
                    </FormField>
                    <div class="flex justify-end">
                        <Button type="submit" loading={registerForm.processing} testId="register-sso-submit">
                            登録する
                        </Button>
                    </div>
                </form>
            </Modal>

            <Modal bind:open={editModalOpen} title="SSO 接続を編集" testId="edit-sso-modal">
                <form novalidate onsubmit={submitEdit} class="flex flex-col gap-4">
                    <FormField label="表示名" id="sso-edit-display-name" error={editForm.errors.display_name}>
                        {#snippet children({ id, describedBy, invalid })}
                            <Input {id} type="text" bind:value={editForm.display_name} error={invalid}
                                aria-describedby={describedBy} autocomplete="off" testId="sso-edit-display-name" />
                        {/snippet}
                    </FormField>
                    <FormField label="発行者 URL" id="sso-edit-issuer" error={editForm.errors.issuer}
                        help="利用者が 1 人でもログインした接続では変更できません (新しい接続を作成してください)。">
                        {#snippet children({ id, describedBy, invalid })}
                            <Input {id} type="text" bind:value={editForm.issuer} error={invalid}
                                aria-describedby={describedBy} autocomplete="off" testId="sso-edit-issuer" />
                        {/snippet}
                    </FormField>
                    <FormField label="クライアント ID" id="sso-edit-client-id" error={editForm.errors.client_id}>
                        {#snippet children({ id, describedBy, invalid })}
                            <Input {id} type="text" bind:value={editForm.client_id} error={invalid}
                                aria-describedby={describedBy} autocomplete="off" testId="sso-edit-client-id" />
                        {/snippet}
                    </FormField>
                    <FormField label="クライアントシークレット" id="sso-edit-client-secret"
                        error={editForm.errors.client_secret}
                        help="変更するときだけ入力してください。空のままなら現在の値を保ちます。">
                        {#snippet children({ id, describedBy, invalid })}
                            <Input {id} type="password" bind:value={editForm.client_secret} error={invalid}
                                aria-describedby={describedBy} autocomplete="off" testId="sso-edit-client-secret" />
                        {/snippet}
                    </FormField>
                    <p class="text-caption text-text-secondary">
                        発行者 URL・クライアント ID・シークレットのいずれかを変えると、接続は「未確認」に戻ります。
                        もう一度「確認」と「有効化」を行ってください。
                    </p>
                    <div class="flex justify-end">
                        <Button type="submit" loading={editForm.processing} testId="edit-sso-submit">
                            更新する
                        </Button>
                    </div>
                </form>
            </Modal>

            <ConfirmDialog
                bind:open={deleteDialogOpen}
                title="SSO 接続の削除"
                message={`${deleteTarget?.displayName ?? ""} を削除しますか？ この IdP でログインした利用者がいる場合は削除できません (「無効化」を使ってください)。`}
                confirmLabel="削除する"
                confirmVariant="danger"
                processing={deleting}
                onConfirm={deleteConnection}
                testId="delete-sso-dialog"
            />

            <RecentAuthModal
                bind:open={recentAuthOpen}
                status={recentAuthStatus}
                onConfirmed={resumePendingAction}
            />
        </PageContent>
    </PageContainer>
</AppLayout>
