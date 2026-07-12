<script lang="ts">
    import { page, router, useForm } from "@inertiajs/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import FormError from "@/components/atoms/FormError.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import AdminMenuNav from "@/components/features/admin/AdminMenuNav.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
    import type { SharedProps } from "@/lib/shared-props";
    import type { ConsoleRole, InvitationRow, MemberRow } from "@/types/admin";

    /**
     * 管理メニュー > ユーザー管理 (doc/04 §4.2。モック PC_管理メニュー 02〜09)。
     * ロールは 3 値遷移コマンド (管理者/編集者/撮影者) の 1 セレクト。表示状態は 5 値
     * (owner/admin/editor/shooter/unassigned) をサーバが導出して配る。
     * 書き込みは既存 organizations.* endpoint (招待 / ロール変更 / 削除 / 2FA リセット)。
     * ボタンは必須未充足でも disabled にしない (押下時にサーバの error bag を表示 = 禁止事項 8)。
     */
    interface Props {
        organizationSlug: string;
        members: MemberRow[];
        invitations: InvitationRow[];
        hasDefaultProject: boolean;
        categoriesUrl: string | null;
    }

    let { organizationSlug, members, invitations, hasDefaultProject, categoriesUrl }: Props =
        $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    // 閲覧者は manageMembers 権限者 (owner/admin) のみ。Owner 判定は自分の行から導出する
    const viewerIsOwner = $derived(
        members.find((member) => member.isSelf)?.roleState === "owner",
    );

    /** ロール select の選択肢 (遷移コマンド 3 値。owner は enum 外 = 構造的に指定不可) */
    const ROLE_OPTIONS: { value: ConsoleRole; label: string }[] = [
        { value: "admin", label: "管理者" },
        { value: "editor", label: "編集者" },
        { value: "shooter", label: "撮影者" },
    ];

    /** ロール select を出す行か (owner 行・自分の行はテキスト表示 = 現行 Settings と同じ流儀) */
    function canChangeRole(member: MemberRow): boolean {
        return member.roleState !== "owner" && !member.isSelf;
    }

    /* ---- ロール変更 (3 値遷移コマンド) ---- */
    let roleErrorMemberId = $state<number | null>(null);
    let changingRole = $state(false);

    function changeRole(member: MemberRow, role: string): void {
        if (role === "" || changingRole) return; // 未割当の空 option / 二重送信の冪等ガード
        changingRole = true;
        router.patch(
            `/organizations/${organizationSlug}/members/${member.id}`,
            { role },
            {
                preserveScroll: true,
                onError: () => {
                    roleErrorMemberId = member.id;
                },
                onSuccess: () => {
                    roleErrorMemberId = null;
                },
                onFinish: () => {
                    changingRole = false;
                },
            },
        );
    }

    const pageErrors = $derived((page.props.errors ?? {}) as Record<string, string>);

    /* ---- メンバー削除 (モック 08 削除アラート) ---- */
    let removeTarget = $state<MemberRow | null>(null);
    let removeDialogOpen = $state(false);
    let removing = $state(false);

    function openRemoveDialog(member: MemberRow): void {
        removeTarget = member;
        removeDialogOpen = true;
    }

    function removeMember(): void {
        if (removeTarget === null || removing) return;
        router.delete(`/organizations/${organizationSlug}/members/${removeTarget.id}`, {
            preserveScroll: true,
            onStart: () => {
                removing = true;
            },
            onFinish: () => {
                removing = false;
                removeDialogOpen = false;
            },
        });
    }

    /* ---- recent-auth (step-up) precheck。stale なら再認証モーダルを挟んで再開する ---- */
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

    /* ---- メンバー 2FA リセット (Owner/Admin。recent-auth + 理由必須。Settings から移設) ---- */
    let resetTwoFactorTarget = $state<MemberRow | null>(null);
    let resetTwoFactorModalOpen = $state(false);
    const resetTwoFactorForm = useForm({ reason: "" });

    function openResetTwoFactorModal(member: MemberRow): void {
        resetTwoFactorTarget = member;
        resetTwoFactorForm.reset();
        resetTwoFactorForm.clearErrors();
        resetTwoFactorModalOpen = true;
    }

    function submitResetTwoFactor(event: SubmitEvent): void {
        event.preventDefault();
        if (resetTwoFactorForm.processing) return; // 二重送信の冪等ガード
        const target = resetTwoFactorTarget;
        if (target === null) return;
        guardWithRecentAuth(() => {
            resetTwoFactorForm.delete(
                `/organizations/${organizationSlug}/members/${target.id}/two-factor`,
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        resetTwoFactorModalOpen = false;
                    },
                },
            );
        });
    }

    /** 2FA リセットを提示できる対象か (自分以外 + 設定済み + Admin は org Member 系のみ対象) */
    function canResetTwoFactor(member: MemberRow): boolean {
        if (member.isSelf || member.twoFactorStatus === "disabled") {
            return false;
        }
        // Owner は誰でも。Admin は org Member (editor/shooter/unassigned) のみ (同格以上は不可)
        return (
            viewerIsOwner ||
            member.roleState === "editor" ||
            member.roleState === "shooter" ||
            member.roleState === "unassigned"
        );
    }

    /* ---- ユーザー追加 (招待。モック 03/04/06) ---- */
    const inviteForm = useForm({ email: "", role: "shooter" });

    function submitInvite(event: SubmitEvent): void {
        event.preventDefault();
        if (inviteForm.processing) return; // 二重送信の冪等ガード
        inviteForm.post(`/organizations/${organizationSlug}/invitations`, {
            preserveScroll: true,
            onSuccess: () => {
                inviteForm.reset();
            },
        });
    }

    /* ---- 招待取り消し ---- */
    let revokeTarget = $state<InvitationRow | null>(null);
    let revokeDialogOpen = $state(false);
    let revoking = $state(false);

    function openRevokeDialog(invitation: InvitationRow): void {
        revokeTarget = invitation;
        revokeDialogOpen = true;
    }

    function revokeInvitation(): void {
        if (revokeTarget === null || revoking) return;
        router.delete(`/organizations/${organizationSlug}/invitations/${revokeTarget.id}`, {
            preserveScroll: true,
            onStart: () => {
                revoking = true;
            },
            onFinish: () => {
                revoking = false;
                revokeDialogOpen = false;
            },
        });
    }
</script>

<AppLayout {appName}>
    <h1 class="text-h2">ユーザー管理</h1>
    <p class="mt-1 text-caption text-text-secondary">
        組織のメンバーと招待を管理します。ロールは「管理者・編集者・撮影者」から選択します。
    </p>

    <div class="mt-6 flex flex-col gap-6 md:flex-row md:items-start">
        <aside class="w-full shrink-0 md:w-56">
            <AdminMenuNav active="users" usersUrl="/manage/users" {categoriesUrl} />
        </aside>

        <div class="flex min-w-0 grow flex-col gap-10">
            <Card padding="lg">
                <h2 class="text-h3">メンバー一覧</h2>
                {#if !hasDefaultProject}
                    <p class="mt-2 text-caption text-text-secondary" data-testid="no-project-note">
                        プロジェクトがまだありません。編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。
                    </p>
                {/if}
                <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="member-list">
                    {#each members as member (member.id)}
                        <!-- 375px 方針: モバイルは縦積み、sm 以上は現行の横並び (F-14)。操作ブロックは要素単位で折り返し可 -->
                        <li
                            class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                        >
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <p class="truncate text-body">{member.name}</p>
                                    {#if member.twoFactorStatus === "enabled"}
                                        <Badge tone="success">2FA</Badge>
                                    {/if}
                                    {#if member.roleState === "unassigned"}
                                        <Badge tone="warning" testId={`unassigned-${member.id}`}>
                                            未割当
                                        </Badge>
                                    {/if}
                                </div>
                                <p class="truncate text-caption text-text-secondary">
                                    {member.email}
                                </p>
                                {#if roleErrorMemberId === member.id && pageErrors.role}
                                    <FormError
                                        message={pageErrors.role}
                                        testId={`role-error-${member.id}`}
                                    />
                                {/if}
                            </div>
                            <div class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">
                                {#if canResetTwoFactor(member)}
                                    <Button
                                        variant="danger-ghost"
                                        size="sm"
                                        onclick={() => openResetTwoFactorModal(member)}
                                        testId={`reset-two-factor-${member.id}`}
                                    >
                                        2FA 解除
                                    </Button>
                                {/if}
                                {#if canChangeRole(member)}
                                    <Select
                                        value={member.roleState === "unassigned"
                                            ? ""
                                            : member.roleState}
                                        aria-label={`${member.name} のロール`}
                                        onchange={(event) =>
                                            changeRole(member, event.currentTarget.value)}
                                        testId={`member-role-${member.id}`}
                                    >
                                        {#if member.roleState === "unassigned"}
                                            <option value="">未割当（選択してください）</option>
                                        {/if}
                                        {#each ROLE_OPTIONS as option (option.value)}
                                            <option value={option.value}>{option.label}</option>
                                        {/each}
                                    </Select>
                                    <Button
                                        variant="danger-ghost"
                                        size="sm"
                                        onclick={() => openRemoveDialog(member)}
                                        testId={`remove-member-${member.id}`}
                                    >
                                        削除
                                    </Button>
                                {:else}
                                    <span class="text-caption text-text-secondary">
                                        {member.roleLabel}
                                    </span>
                                {/if}
                            </div>
                        </li>
                    {/each}
                </ul>
            </Card>

            <Card padding="lg">
                <h2 class="text-h3">ユーザーを追加</h2>
                <p class="mt-1 text-caption text-text-secondary">
                    招待メールを送信します。招待の有効期限は 7 日間です。
                </p>
                <form onsubmit={submitInvite} class="mt-4 flex flex-col gap-4">
                    <FormField
                        label="メールアドレス"
                        id="invite-email"
                        error={inviteForm.errors.email}
                    >
                        {#snippet children({ id, describedBy, invalid })}
                            <Input
                                {id}
                                type="email"
                                bind:value={inviteForm.email}
                                error={invalid}
                                aria-describedby={describedBy}
                                autocomplete="off"
                            />
                        {/snippet}
                    </FormField>
                    <FormField label="ロール" id="invite-role" error={inviteForm.errors.role}>
                        {#snippet children({ id, describedBy, invalid })}
                            <Select
                                {id}
                                bind:value={inviteForm.role}
                                error={invalid}
                                aria-describedby={describedBy}
                            >
                                {#each ROLE_OPTIONS as option (option.value)}
                                    <option value={option.value}>{option.label}</option>
                                {/each}
                            </Select>
                        {/snippet}
                    </FormField>
                    <div>
                        <Button
                            type="submit"
                            loading={inviteForm.processing}
                            testId="invite-submit"
                        >
                            招待を送信
                        </Button>
                    </div>
                </form>
            </Card>

            <Card padding="lg">
                <h2 class="text-h3">招待中</h2>
                {#if invitations.length === 0}
                    <EmptyState
                        title="有効な招待はありません"
                        description="送信した招待は受諾されるか期限が切れるまでここに表示されます。"
                        testId="invitations-empty"
                    />
                {:else}
                    <ul
                        class="mt-4 flex flex-col divide-y divide-border"
                        data-testid="invitation-list"
                    >
                        {#each invitations as invitation (invitation.id)}
                            <!-- 375px 方針: モバイルは縦積み、sm 以上は現行の横並び (F-14) -->
                            <li
                                class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
                            >
                                <p class="min-w-0 truncate text-body">{invitation.email}</p>
                                <div class="flex flex-wrap items-center gap-3 sm:shrink-0 sm:justify-end">
                                    <p class="text-caption text-text-secondary">
                                        {invitation.roleLabel} ・ 期限 {invitation.expiresAt}
                                    </p>
                                    <Button
                                        variant="danger-ghost"
                                        size="sm"
                                        onclick={() => openRevokeDialog(invitation)}
                                        testId={`revoke-invitation-${invitation.id}`}
                                    >
                                        取消
                                    </Button>
                                </div>
                            </li>
                        {/each}
                    </ul>
                {/if}
            </Card>
        </div>
    </div>

    <ConfirmDialog
        bind:open={removeDialogOpen}
        title="ユーザー削除"
        message={`${removeTarget?.name ?? ""} さんをこの組織から削除しますか？ この操作は取り消せません。`}
        confirmLabel="削除する"
        confirmVariant="danger"
        processing={removing}
        onConfirm={removeMember}
        testId="remove-member-dialog"
    />

    <ConfirmDialog
        bind:open={revokeDialogOpen}
        title="招待の取り消し"
        message={`${revokeTarget?.email ?? ""} への招待を取り消しますか？ 取り消した招待は受諾できなくなります。`}
        confirmLabel="取り消す"
        confirmVariant="danger"
        processing={revoking}
        onConfirm={revokeInvitation}
        testId="revoke-invitation-dialog"
    />

    <Modal
        bind:open={resetTwoFactorModalOpen}
        title="メンバーの 2FA を解除"
        testId="reset-two-factor-modal"
    >
        <form onsubmit={submitResetTwoFactor} class="flex flex-col gap-4">
            <p class="text-body">
                {resetTwoFactorTarget?.name ?? ""} さんの 2 段階認証を解除します。
                解除はこのアカウント全体に及び、本人へセキュリティ通知が送信されます。
            </p>
            <FormField
                label="理由 (10 文字以上。監査ログに記録されます)"
                id="reset-two-factor-reason"
                error={resetTwoFactorForm.errors.reason}
            >
                {#snippet children({ id, describedBy, invalid })}
                    <Input
                        {id}
                        type="text"
                        bind:value={resetTwoFactorForm.reason}
                        error={invalid}
                        aria-describedby={describedBy}
                        autocomplete="off"
                    />
                {/snippet}
            </FormField>
            <div class="flex justify-end">
                <Button
                    type="submit"
                    variant="danger"
                    loading={resetTwoFactorForm.processing}
                    testId="reset-two-factor-submit"
                >
                    解除する
                </Button>
            </div>
        </form>
    </Modal>

    <RecentAuthModal
        bind:open={recentAuthOpen}
        passwordSet={recentAuthStatus?.passwordSet ?? false}
        availableProviders={recentAuthStatus?.availableProviders ?? []}
        canSatisfy={recentAuthStatus?.canSatisfy ?? true}
        onConfirmed={resumePendingAction}
    />
</AppLayout>
