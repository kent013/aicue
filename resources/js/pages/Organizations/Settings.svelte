<script lang="ts">
    import { page, router, useForm } from "@inertiajs/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import DangerZone from "@/components/molecules/DangerZone.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
    import type { SharedProps } from "@/lib/shared-props";

    interface Member {
        id: number;
        name: string;
        email: string;
        role: string | null;
        twoFactorStatus: "disabled" | "pending" | "enabled";
    }

    interface Invitation {
        id: number;
        email: string;
        role: string;
        expiresAt: string | null;
    }

    interface Props {
        organization: {
            id: number;
            name: string;
            slug: string;
            isPersonal: boolean;
            twoFactorRequired: boolean;
        };
        members: Member[];
        invitations: Invitation[];
        currentUserRole: string | null;
        /** API キー / 接続セッション管理画面への導線を出すか (境界は manageApiKeys と同一) */
        canManageApiKeys: boolean;
    }

    let {
        organization,
        members,
        invitations,
        currentUserRole,
        canManageApiKeys,
    }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");
    const myId = $derived(shared.auth?.user?.id ?? null);

    const OWNER = "organization_owner";
    const ADMIN = "organization_admin";
    const MEMBER = "organization_member";

    const ROLE_LABELS: Record<string, string> = {
        [OWNER]: "オーナー",
        [ADMIN]: "管理者",
        [MEMBER]: "メンバー",
    };

    const canManage = $derived(currentUserRole === OWNER || currentUserRole === ADMIN);
    const isOwner = $derived(currentUserRole === OWNER);

    /* ---- 組織名 ---- */
    // 初期値として現在の組織名を取り込む (以後はフォーム側が真実)
    // svelte-ignore state_referenced_locally
    const nameForm = useForm({ name: organization.name });

    function submitName(event: SubmitEvent): void {
        event.preventDefault();
        nameForm.patch(`/organizations/${organization.slug}`, { preserveScroll: true });
    }

    /* ---- 招待 ---- */
    const inviteForm = useForm({ email: "", role: MEMBER });

    function submitInvite(event: SubmitEvent): void {
        event.preventDefault();
        inviteForm.post(`/organizations/${organization.slug}/invitations`, {
            preserveScroll: true,
            onSuccess: () => {
                inviteForm.reset();
            },
        });
    }

    /* ---- ロール変更 ----
       Owner への昇格・Owner の降格はこの UI からは行えない (オーナー移譲のみが正規経路)。 */
    function changeRole(member: Member, role: string): void {
        router.patch(
            `/organizations/${organization.slug}/members/${member.id}`,
            { role },
            { preserveScroll: true },
        );
    }

    /* ---- メンバー削除 ---- */
    let removeTarget = $state<Member | null>(null);
    let removeDialogOpen = $state(false);
    let removing = $state(false);

    function openRemoveDialog(member: Member): void {
        removeTarget = member;
        removeDialogOpen = true;
    }

    function removeMember(): void {
        if (removeTarget === null) return;
        router.delete(`/organizations/${organization.slug}/members/${removeTarget.id}`, {
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

    /* ---- オーナー移譲 (recent-auth 必須。precheck で鮮度を確認してから送る) ---- */
    const transferForm = useForm({ user_id: "" });
    let transferDialogOpen = $state(false);

    const transferCandidates = $derived(members.filter((member) => member.id !== myId));
    const transferTargetName = $derived(
        transferCandidates.find((member) => String(member.id) === transferForm.user_id)?.name ??
            "",
    );

    function openTransferDialog(event: SubmitEvent): void {
        event.preventDefault();
        if (transferForm.user_id === "") {
            transferForm.setError("user_id", "移譲先のメンバーを選択してください。");
            return;
        }
        transferDialogOpen = true;
    }

    function transferOwnership(): void {
        guardWithRecentAuth(() => {
            transferForm.post(`/organizations/${organization.slug}/transfer-ownership`, {
                preserveScroll: true,
                onFinish: () => {
                    transferDialogOpen = false;
                },
            });
        });
    }

    function roleLabel(role: string | null): string {
        return role === null ? "—" : (ROLE_LABELS[role] ?? role);
    }

    /* ---- 組織の 2FA 必須方針 (Owner 専権。recent-auth 必須) ----
       押下前の precheck はしない (未準拠 Owner の有効化はサーバが validation error で拒否し、
       ここに表示する)。 */
    const twoFactorRequirementForm = useForm({ enabled: false });

    function toggleTwoFactorRequirement(): void {
        twoFactorRequirementForm.enabled = !organization.twoFactorRequired;
        guardWithRecentAuth(() => {
            twoFactorRequirementForm.patch(
                `/organizations/${organization.slug}/two-factor-requirement`,
                { preserveScroll: true },
            );
        });
    }

    /* ---- メンバー 2FA リセット (Owner/Admin。recent-auth + 理由必須) ---- */
    let resetTwoFactorTarget = $state<Member | null>(null);
    let resetTwoFactorModalOpen = $state(false);
    const resetTwoFactorForm = useForm({ reason: "" });

    function openResetTwoFactorModal(member: Member): void {
        resetTwoFactorTarget = member;
        resetTwoFactorForm.reset();
        resetTwoFactorForm.clearErrors();
        resetTwoFactorModalOpen = true;
    }

    function submitResetTwoFactor(event: SubmitEvent): void {
        event.preventDefault();
        const target = resetTwoFactorTarget;
        if (target === null) return;
        guardWithRecentAuth(() => {
            resetTwoFactorForm.delete(
                `/organizations/${organization.slug}/members/${target.id}/two-factor`,
                {
                    preserveScroll: true,
                    onSuccess: () => {
                        resetTwoFactorModalOpen = false;
                    },
                },
            );
        });
    }

    /** 2FA リセットを提示できる対象か (自分以外 + 設定済み + Admin は Member のみ対象にできる) */
    function canResetTwoFactor(member: Member): boolean {
        if (!canManage || member.id === myId || member.twoFactorStatus === "disabled") {
            return false;
        }
        return isOwner || member.role === MEMBER;
    }
</script>

<AppLayout {appName}>
    <h1 class="text-h2">組織設定</h1>
    <p class="mt-1 text-caption text-text-secondary">
        {organization.name} のメンバーと設定を管理します。
    </p>

    <div class="mt-6 flex max-w-2xl flex-col gap-10">
        <Card padding="lg">
            <h2 class="text-h3">組織名</h2>
            {#if canManage}
                <form onsubmit={submitName} class="mt-4 flex flex-col gap-4">
                    <FormField label="組織名" id="organization-name" error={nameForm.errors.name}>
                        {#snippet children({ id, describedBy, invalid })}
                            <Input
                                {id}
                                type="text"
                                bind:value={nameForm.name}
                                error={invalid}
                                aria-describedby={describedBy}
                            />
                        {/snippet}
                    </FormField>
                    <div>
                        <Button type="submit" loading={nameForm.processing}>保存</Button>
                    </div>
                </form>
            {:else}
                <p class="mt-2 text-body">{organization.name}</p>
            {/if}
        </Card>

        {#if isOwner}
            <Card padding="lg">
                <h2 class="text-h3">セキュリティ</h2>
                <p class="mt-1 text-caption text-text-secondary">
                    2 段階認証を必須にすると、未設定のメンバーは設定が完了するまで他のページを利用できなくなります。
                </p>
                <div class="mt-4 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-2">
                        <span class="text-body">2 段階認証の必須化</span>
                        <Badge tone={organization.twoFactorRequired ? "success" : "neutral"}>
                            {organization.twoFactorRequired ? "有効" : "無効"}
                        </Badge>
                    </div>
                    <Button
                        variant={organization.twoFactorRequired ? "danger-outline" : "primary"}
                        size="sm"
                        loading={twoFactorRequirementForm.processing}
                        onclick={toggleTwoFactorRequirement}
                        testId="toggle-two-factor-requirement"
                    >
                        {organization.twoFactorRequired ? "必須化を解除" : "必須にする"}
                    </Button>
                </div>
                {#if twoFactorRequirementForm.errors.enabled}
                    <p class="mt-2 text-caption text-danger" role="alert">
                        {twoFactorRequirementForm.errors.enabled}
                    </p>
                {/if}
            </Card>
        {/if}

        <Card padding="lg">
            <h2 class="text-h3">メンバー</h2>
            <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="member-list">
                {#each members as member (member.id)}
                    <li class="flex items-center justify-between gap-4 py-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="truncate text-body">{member.name}</p>
                                {#if member.twoFactorStatus === "enabled"}
                                    <Badge tone="success">2FA</Badge>
                                {/if}
                            </div>
                            <p class="truncate text-caption text-text-secondary">
                                {member.email}
                            </p>
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
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
                            {#if canManage && member.role !== OWNER && member.id !== myId}
                                <Select
                                    value={member.role ?? MEMBER}
                                    aria-label={`${member.name} のロール`}
                                    onchange={(event) =>
                                        changeRole(member, event.currentTarget.value)}
                                    testId={`member-role-${member.id}`}
                                >
                                    <option value={ADMIN}>管理者</option>
                                    <option value={MEMBER}>メンバー</option>
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
                                    {roleLabel(member.role)}
                                </span>
                            {/if}
                        </div>
                    </li>
                {/each}
            </ul>
        </Card>

        {#if canManage}
            <Card padding="lg">
                <h2 class="text-h3">メンバーを招待</h2>
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
                                <option value={ADMIN}>管理者</option>
                                <option value={MEMBER}>メンバー</option>
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

                <h3 class="mt-8 text-caption font-medium text-text">送信済みの招待</h3>
                {#if invitations.length === 0}
                    <EmptyState
                        title="有効な招待はありません"
                        description="送信した招待は受諾されるか期限が切れるまでここに表示されます。"
                    />
                {:else}
                    <ul class="mt-2 flex flex-col divide-y divide-border">
                        {#each invitations as invitation (invitation.id)}
                            <li class="flex items-center justify-between gap-4 py-3">
                                <p class="truncate text-body">{invitation.email}</p>
                                <p class="shrink-0 text-caption text-text-secondary">
                                    {roleLabel(invitation.role)} ・ 期限 {invitation.expiresAt ??
                                        "—"}
                                </p>
                            </li>
                        {/each}
                    </ul>
                {/if}
            </Card>
        {/if}

        {#if canManageApiKeys}
            <Card padding="lg">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-h3">API キー / 接続セッション</h2>
                        <p class="mt-1 text-caption text-text-secondary">
                            REST API / MCP アクセスのキー発行・失効と、CLI / MCP の接続セッション管理は専用画面で行います。
                        </p>
                    </div>
                    <TextLink
                        href={`/organizations/${organization.slug}/api-keys`}
                        testId="link-api-keys"
                    >
                        管理画面を開く
                    </TextLink>
                </div>
            </Card>
        {/if}

        {#if isOwner && transferCandidates.length > 0}
            <DangerZone
                title="オーナー移譲"
                description="組織のオーナー権限を別のメンバーへ移譲します。移譲後、あなたは管理者になります。この操作にはパスワードの再確認が必要です。"
            >
                <form onsubmit={openTransferDialog} class="flex flex-col gap-4">
                    <FormField
                        label="移譲先のメンバー"
                        id="transfer-target"
                        error={transferForm.errors.user_id}
                    >
                        {#snippet children({ id, describedBy, invalid })}
                            <Select
                                {id}
                                bind:value={transferForm.user_id}
                                error={invalid}
                                aria-describedby={describedBy}
                            >
                                <option value="">選択してください</option>
                                {#each transferCandidates as candidate (candidate.id)}
                                    <option value={String(candidate.id)}>
                                        {candidate.name}
                                    </option>
                                {/each}
                            </Select>
                        {/snippet}
                    </FormField>
                    <div>
                        <Button
                            type="submit"
                            variant="danger-outline"
                            testId="transfer-ownership-button"
                        >
                            オーナーを移譲
                        </Button>
                    </div>
                </form>
            </DangerZone>
        {/if}
    </div>

    <ConfirmDialog
        bind:open={removeDialogOpen}
        title="メンバー削除"
        message={`${removeTarget?.name ?? ""} さんをこの組織から削除しますか？ この操作は取り消せません。`}
        confirmLabel="削除する"
        confirmVariant="danger"
        processing={removing}
        onConfirm={removeMember}
        testId="remove-member-dialog"
    />

    <ConfirmDialog
        bind:open={transferDialogOpen}
        title="オーナー移譲"
        message={`オーナー権限を ${transferTargetName} さんへ移譲しますか？ あなたは管理者に降格します。`}
        confirmLabel="移譲する"
        confirmVariant="danger"
        processing={transferForm.processing}
        onConfirm={transferOwnership}
        testId="transfer-ownership-dialog"
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
