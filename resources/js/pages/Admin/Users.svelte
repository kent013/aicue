<script lang="ts">
    import { tick } from "svelte";
    import { page, router, useForm } from "@inertiajs/svelte";
    import { Users } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import FormError from "@/components/atoms/FormError.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import { formatDateTime } from "@/lib/date-format";
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
    }

    let { organizationSlug, members, invitations, hasDefaultProject }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    // 閲覧者は manageMembers 権限者 (owner/admin) のみ。Owner 判定は自分の行から導出する
    const viewerIsOwner = $derived(
        members.find((member) => member.isSelf)?.roleState === "owner",
    );

    /**
     * 招待のロール選択肢 = org ロール 2 値 (編集者 / 撮影者は参加後に割り当てる)。
     * メンバーのロール変更コマンド (ROLE_OPTIONS) とは別集合 (統合しない)。
     */
    const INVITE_ROLE_OPTIONS: { value: string; label: string }[] = [
        { value: "organization_admin", label: "管理者" },
        { value: "organization_member", label: "メンバー" },
    ];

    /**
     * ロール select の選択肢 (遷移コマンド 3 値。owner は enum 外 = 構造的に指定不可)。
     * hasDefaultProject が false のとき、編集者・撮影者は選べても割り当てはできない
     * (サーバが validation で拒否)。禁止事項 8 に従い disabled にはせず、選択地点で
     * 制約を可視化する注記サフィックスを付ける。管理者は無条件に選べるため付けない。
     */
    const roleOptions = $derived<{ value: ConsoleRole; label: string }[]>([
        { value: "admin", label: "管理者" },
        { value: "editor", label: hasDefaultProject ? "編集者" : "編集者（要プロジェクト）" },
        { value: "shooter", label: hasDefaultProject ? "撮影者" : "撮影者（要プロジェクト）" },
    ]);

    /** ロール select を出す行か (owner 行・自分の行はテキスト表示 = 現行 Settings と同じ流儀) */
    function canChangeRole(member: MemberRow): boolean {
        return member.roleState !== "owner" && !member.isSelf;
    }

    /* ---- ロール変更 (3 値遷移コマンド) ---- */
    let roleErrorMemberId = $state<number | null>(null);
    // 拒否時に該当行 Select を remount して権威値 (member.roleState) を読み直させるためのキー。
    // 一方向 value 伝播では admin→admin の同値へ再同期されない問題を remount で断つ。
    let roleResetTokens = $state<Record<number, number>>({});
    // フォーカス復帰対象 (onError で保存し、onFinish で disabled 解除後に復帰)。
    let roleRefocusMemberId = $state<number | null>(null);
    let changingRole = $state(false);

    function changeRole(member: MemberRow, role: string): void {
        if (role === "" || changingRole) return; // 未割当の空 option / 二重送信の冪等ガード
        roleErrorMemberId = null; // 送信開始時に前回エラーをクリア (次通信中まで残さない)
        changingRole = true;
        router.patch(
            `/organizations/${organizationSlug}/members/${member.id}`,
            { role },
            {
                preserveScroll: true,
                onError: () => {
                    // 拒否: 権威値へ戻すため該当行を remount。実フォーカス復帰は onFinish (disabled 解除後)。
                    roleErrorMemberId = member.id;
                    roleResetTokens[member.id] = (roleResetTokens[member.id] ?? 0) + 1;
                    roleRefocusMemberId = member.id;
                },
                onSuccess: () => {
                    // 成功時はエラー行・フォーカス復帰対象を残さない (拒否残渣の持ち越し防止)
                    roleErrorMemberId = null;
                    roleRefocusMemberId = null;
                },
                onFinish: () => {
                    changingRole = false;
                    void refocusRole();
                },
            },
        );
    }

    // remount + disabled 解除の反映を待ってから、失敗行 Select へフォーカスを戻す。
    // Select atom は id / data-testid を native <select> にそのまま渡すため atom 改造は不要。
    async function refocusRole(): Promise<void> {
        const id = roleRefocusMemberId;
        if (id === null) return;
        roleRefocusMemberId = null;
        await tick();
        const el = document.querySelector<HTMLSelectElement>(
            `[data-testid="member-role-${id}"]`,
        );
        el?.focus();
    }

    const pageErrors = $derived(
        (page.props.errors ?? {}) as Record<string, string | string[]>,
    );
    // error bag の配列化 (Laravel の複数メッセージ経路) に堅牢化: 先頭要素へ正規化。
    // 表示・aria-describedby・invalid 判定をこの一本の派生に集約する。
    const roleMessage = $derived.by(() => {
        const raw = pageErrors.role;
        return Array.isArray(raw) ? raw[0] : raw;
    });

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

    /**
     * 2FA リセットを提示できる対象か (自分以外 + 2FA 確定済み + Admin は org Member 系のみ対象)。
     * pending (secret 生成済・TOTP 未確認) は 2FA 無効として扱い、解除ボタンを出さない
     * (本人の設定画面・2FA バッジと表示意味論を揃える。F-2-03)。
     */
    function canResetTwoFactor(member: MemberRow): boolean {
        if (member.isSelf || member.twoFactorStatus !== "enabled") {
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
    const inviteForm = useForm({ email: "", role: "organization_member" });

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
    <PageContainer>
        <PageHeader
            title="ユーザー管理"
            description="組織のメンバーと招待を管理します。メンバーのロールは「管理者・編集者・撮影者」から選択します (招待は「管理者・メンバー」の 2 値で、編集者・撮影者は参加後に割り当てます)。"
            icon={Users}
            testId="users-heading"
        />
        <PageContent>
            <div class="flex min-w-0 grow flex-col gap-10">
                <Card padding="lg">
                    <h2 class="text-h3">メンバー一覧</h2>
                    {#if !hasDefaultProject}
                        <p class="mt-2 text-caption text-text-secondary" data-testid="no-project-note">
                            プロジェクトがまだありません。編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。
                        </p>
                        <!-- 詰まりの文脈から 1 ホップで作成画面へ (既存 CTA 流儀 = Button href+inertia) -->
                        <Button
                            href="/projects/create"
                            inertia
                            variant="ghost"
                            size="sm"
                            class="mt-3"
                            testId="create-project-link"
                        >
                            プロジェクトを作成
                        </Button>
                    {/if}
                    <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="member-list">
                        {#each members as member (member.id)}
                            <!-- 375px 方針: モバイルは縦積み、sm 以上は現行の横並び (F-14)。操作ブロックは要素単位で折り返し可 -->
                            <li
                                class="flex flex-col gap-2 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-4"
                            >
                                <div class="min-w-0 sm:min-w-40">
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
                                    <!-- 最終ログイン。値の無い行は「記録なし」(「未ログイン」と断定しない —
                                         導出元の security_audit_events は保持期間が未確定で、将来 purge されうるため)。
                                         表示は閲覧者の端末タイムゾーンで行う (date-format.ts の Intl 経由) -->
                                    <p
                                        class="truncate text-caption text-text-secondary"
                                        data-testid={`member-last-login-${member.id}`}
                                    >
                                        最終ログイン {formatDateTime(member.lastLoginAt, "記録なし")}
                                    </p>
                                </div>
                                <div class="flex flex-wrap items-center gap-2 sm:ml-auto sm:shrink-0 sm:justify-end">
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
                                        <!-- 拒否時は該当行のみ remount して権威値 (member.roleState) を読み直す。
                                             {#key} は論理ブロックで DOM 親を追加しない = actions 列の flex-wrap を保つ (F-14) -->
                                        {#key roleResetTokens[member.id] ?? 0}
                                            <Select
                                                value={member.roleState === "unassigned"
                                                    ? ""
                                                    : member.roleState}
                                                aria-label={`${member.name} のロール`}
                                                error={roleErrorMemberId === member.id &&
                                                    !!roleMessage}
                                                aria-describedby={roleErrorMemberId === member.id &&
                                                roleMessage
                                                    ? `role-error-${member.id}`
                                                    : undefined}
                                                disabled={changingRole}
                                                onchange={(event) =>
                                                    changeRole(member, event.currentTarget.value)}
                                                testId={`member-role-${member.id}`}
                                            >
                                                {#if member.roleState === "unassigned"}
                                                    <option value="">未割当（選択してください）</option>
                                                {/if}
                                                {#each roleOptions as option (option.value)}
                                                    <option value={option.value}>{option.label}</option>
                                                {/each}
                                            </Select>
                                        {/key}
                                        <!-- combobox 直下エラー: flex-wrap 内の full-width 要素として
                                             select の次行に落とす。Select の parentElement は actions 列のまま (F-14) -->
                                        {#if roleErrorMemberId === member.id && roleMessage}
                                            <div class="w-full sm:text-right">
                                                <FormError
                                                    id={`role-error-${member.id}`}
                                                    message={roleMessage}
                                                    testId={`role-error-${member.id}`}
                                                />
                                            </div>
                                        {/if}
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
                        招待メールを送信します。招待の有効期限は 7 日間です。編集者・撮影者は参加後に割り当てます。
                    </p>
                    <form novalidate onsubmit={submitInvite} class="mt-4 flex flex-col gap-4">
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
                                    {#each INVITE_ROLE_OPTIONS as option (option.value)}
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
                                    class="flex flex-col gap-2 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:gap-4"
                                >
                                    <p class="min-w-0 truncate text-body sm:min-w-40">{invitation.email}</p>
                                    <div class="flex flex-wrap items-center gap-3 sm:ml-auto sm:shrink-0 sm:justify-end">
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
            <form novalidate onsubmit={submitResetTwoFactor} class="flex flex-col gap-4">
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
            status={recentAuthStatus}
            onConfirmed={resumePendingAction}
        />
        </PageContent>
    </PageContainer>
</AppLayout>
