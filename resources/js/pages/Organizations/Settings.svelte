<script lang="ts">
    import { page, useForm } from "@inertiajs/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import DangerZone from "@/components/molecules/DangerZone.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
    import { Settings } from "@lucide/svelte";
    import type { SharedProps } from "@/lib/shared-props";

    /**
     * 組織設定 (名称 / 2FA 必須方針 / API キー導線 / オーナー移譲)。
     * メンバー管理 (一覧・招待・ロール・削除・2FA リセット) は管理メニュー > ユーザー管理
     * (/manage/users) へ移設済み。members はオーナー移譲 select 用の最小 shape (id/name)。
     */
    interface Member {
        id: number;
        name: string;
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
        currentUserRole: string | null;
        /** API キー / 接続セッション管理画面への導線を出すか (境界は manageApiKeys と同一) */
        canManageApiKeys: boolean;
        /** ユーザー管理画面 (管理メニュー) への導線 URL (manageMembers 権限なしは null = 非表示) */
        usersUrl: string | null;
    }

    let { organization, members, currentUserRole, canManageApiKeys, usersUrl }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");
    const myId = $derived(shared.auth?.user?.id ?? null);

    const OWNER = "organization_owner";
    const ADMIN = "organization_admin";

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
    // client precheck 専用の transient error。serverErrors (transferForm.errors) とは分離し、
    // 有効値復帰で自動解消する (「押下時にエラー表示」契約は維持: 無効のままなら残す)。
    let transferClientError = $state<string | null>(null);

    const transferCandidates = $derived(members.filter((member) => member.id !== myId));
    const transferTargetName = $derived(
        transferCandidates.find((member) => String(member.id) === transferForm.user_id)?.name ??
            "",
    );

    // precheck 合格条件 = 選択値が実在候補に一致すること。エラー条件はこの否定。
    const isValidTransferTarget = $derived(
        transferCandidates.some((member) => String(member.id) === transferForm.user_id),
    );

    // 有効候補へ復帰した時点で client error を連動クリア (過剰クリア防止: clientError!=null かつ有効時のみ)。
    // 候補 0 人ケースのエラーは isValidTransferTarget が常に false のため残留する = 選択では直せないので正しい。
    // serverErrors (transferForm.errors) はこの effect の対象外 = 非退行。
    $effect(() => {
        if (transferClientError !== null && isValidTransferTarget) {
            transferClientError = null;
        }
    });

    /** 候補 0 人時の共通文言 (案内文と押下時エラーで揺れないよう単一定義。テストも本文言を検証) */
    const NO_TRANSFER_CANDIDATES = "移譲先にできるメンバーがいません。";

    /**
     * 移譲確認ダイアログを開く。成立し得ない操作は ConfirmDialog まで進めず、
     * 押下時にエラー表示する (disabled 禁止 = AGENTS.md 8)。
     * 選択値の実在検証は DOM 改変・stale 値の早期排除で、最終ゲートはサーバ
     * (Policy + exists:users,id + Service のメンバーシップ検証)。
     * select の value は string のため、Member.id (number) は String() に揃えて比較する。
     */
    function openTransferDialog(event: SubmitEvent): void {
        event.preventDefault();
        if (transferCandidates.length === 0) {
            transferClientError = `${NO_TRANSFER_CANDIDATES}先にメンバーを招待してください。`;
            return;
        }
        if (!isValidTransferTarget) {
            transferClientError = "移譲先のメンバーを選択してください。";
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
                    // 再 mount しないライフサイクル (再認証キャンセル等) でも stale を残さない
                    transferClientError = null;
                },
            });
        });
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
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="組織設定"
            description={`${organization.name} の設定を管理します。`}
            icon={Settings}
            testId="organization-settings-heading"
        />
        <PageContent>
            <div class="flex flex-col gap-10">
            <Card padding="lg">
                <h2 class="text-h3">組織名</h2>
                {#if canManage}
                    <form novalidate onsubmit={submitName} class="mt-4 flex flex-col gap-4">
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

            {#if usersUrl !== null}
                <Card padding="lg">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h2 class="text-h3">ユーザー管理</h2>
                            <p class="mt-1 text-caption text-text-secondary">
                                メンバーの一覧・招待・ロール変更・削除は管理メニューのユーザー管理画面で行います。
                            </p>
                        </div>
                        <TextLink href={usersUrl} testId="link-manage-users">
                            管理画面を開く
                        </TextLink>
                    </div>
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

            {#if isOwner}
                <DangerZone
                    title="オーナー移譲"
                    description="組織のオーナー権限を別のメンバーへ移譲します。移譲後、あなたは管理者になります。この操作にはパスワードの再確認が必要です。"
                >
                    {#if transferCandidates.length === 0}
                        <p
                            class="text-caption text-text-secondary"
                            data-testid="transfer-no-candidates"
                        >
                            {NO_TRANSFER_CANDIDATES}先に
                            {#if usersUrl !== null}
                                <TextLink href={usersUrl}>管理メニュー &gt; ユーザー管理</TextLink>
                                からメンバーを招待してください。
                            {:else}
                                メンバーを招待できる管理者に依頼してください。
                            {/if}
                        </p>
                    {/if}
                    <form novalidate onsubmit={openTransferDialog} class="flex flex-col gap-4">
                        <FormField
                            label="移譲先のメンバー"
                            id="transfer-target"
                            error={transferClientError ?? transferForm.errors.user_id}
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
            bind:open={transferDialogOpen}
            title="オーナー移譲"
            message={`オーナー権限を ${transferTargetName} さんへ移譲しますか？ あなたは管理者に降格します。`}
            confirmLabel="移譲する"
            confirmVariant="danger"
            processing={transferForm.processing}
            onConfirm={transferOwnership}
            testId="transfer-ownership-dialog"
        />

        <RecentAuthModal
            bind:open={recentAuthOpen}
            status={recentAuthStatus}
            onConfirmed={resumePendingAction}
        />
        </PageContent>
    </PageContainer>
</AppLayout>
