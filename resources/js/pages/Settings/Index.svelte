<script lang="ts">
    import { page, router, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import DangerZone from "@/components/molecules/DangerZone.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
    import type { SharedProps } from "@/lib/shared-props";

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    const initialUser = (page.props as unknown as SharedProps).auth?.user ?? null;

    /**
     * Fortify の PUT /user/profile-information は errorBag (updateProfileInformation)
     * を使う。submit 時に errorBag を指定すると Inertia がバッグを解決するため、
     * form.errors はバッグ名でネストされずフィールド名で参照できる。
     */
    const profileForm = useForm({
        name: initialUser?.name ?? "",
        email: initialUser?.email ?? "",
    });

    function submitProfile(event: SubmitEvent): void {
        event.preventDefault();
        profileForm.put("/user/profile-information", {
            errorBag: "updateProfileInformation",
            preserveScroll: true,
        });
    }

    const passwordForm = useForm({
        current_password: "",
        password: "",
    });

    function submitPassword(event: SubmitEvent): void {
        event.preventDefault();
        passwordForm.put("/user/password", {
            errorBag: "updatePassword",
            preserveScroll: true,
            onSuccess: () => {
                passwordForm.reset();
            },
        });
    }

    let deleteDialogOpen = $state(false);
    let deleting = $state(false);

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

    // アカウント削除は recent-auth (step-up) 必須。precheck で鮮度を確認してから送る
    function deleteAccount(): void {
        guardWithRecentAuth(() => {
            router.delete("/settings/account", {
                onStart: () => {
                    deleting = true;
                },
                onFinish: () => {
                    deleting = false;
                },
            });
        });
    }
</script>

<AppLayout {appName}>
    <h1 class="text-h2">設定</h1>

    <nav aria-label="設定メニュー" class="mt-4 flex gap-4 border-b border-border pb-2">
        <TextLink href="/settings">プロフィール</TextLink>
        <TextLink href="/settings/security">セキュリティ</TextLink>
    </nav>

    <div class="mt-6 flex max-w-2xl flex-col gap-10">
        <Card padding="lg">
            <h2 class="text-h3">プロフィール</h2>
            <p class="mt-1 text-caption text-text-secondary">名前とメールアドレスを更新します。</p>
            <form onsubmit={submitProfile} class="mt-4 flex flex-col gap-4">
                <FormField label="名前" id="profile-name" error={profileForm.errors.name}>
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="text"
                            bind:value={profileForm.name}
                            error={invalid}
                            aria-describedby={describedBy}
                            autocomplete="name"
                        />
                    {/snippet}
                </FormField>
                <FormField
                    label="メールアドレス"
                    id="profile-email"
                    error={profileForm.errors.email}
                >
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="email"
                            bind:value={profileForm.email}
                            error={invalid}
                            aria-describedby={describedBy}
                            autocomplete="email"
                        />
                    {/snippet}
                </FormField>
                <div>
                    <Button type="submit" loading={profileForm.processing}>保存</Button>
                </div>
            </form>
        </Card>

        <Card padding="lg">
            <h2 class="text-h3">パスワード変更</h2>
            <p class="mt-1 text-caption text-text-secondary">
                現在のパスワードを確認のうえ、新しいパスワードに変更します。
            </p>
            <form onsubmit={submitPassword} class="mt-4 flex flex-col gap-4">
                <FormField
                    label="現在のパスワード"
                    id="current-password"
                    error={passwordForm.errors.current_password}
                >
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="password"
                            bind:value={passwordForm.current_password}
                            error={invalid}
                            aria-describedby={describedBy}
                            autocomplete="current-password"
                        />
                    {/snippet}
                </FormField>
                <FormField
                    label="新しいパスワード"
                    id="new-password"
                    error={passwordForm.errors.password}
                >
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="password"
                            bind:value={passwordForm.password}
                            error={invalid}
                            aria-describedby={describedBy}
                            autocomplete="new-password"
                        />
                    {/snippet}
                </FormField>
                <div>
                    <Button type="submit" loading={passwordForm.processing}>
                        パスワードを変更
                    </Button>
                </div>
            </form>
        </Card>

        <DangerZone
            title="アカウント削除"
            description="アカウントを削除すると、すべてのデータが完全に失われます。この操作は取り消せません。"
        >
            <Button
                variant="danger-outline"
                onclick={() => {
                    deleteDialogOpen = true;
                }}
                testId="delete-account-button"
            >
                アカウントを削除
            </Button>
        </DangerZone>
    </div>

    <ConfirmDialog
        bind:open={deleteDialogOpen}
        title="アカウント削除"
        message="本当にアカウントを削除しますか？ すべてのデータが完全に失われ、この操作は取り消せません。"
        confirmLabel="削除する"
        confirmVariant="danger"
        processing={deleting}
        onConfirm={deleteAccount}
        testId="delete-account-dialog"
    />

    <RecentAuthModal
        bind:open={recentAuthOpen}
        passwordSet={recentAuthStatus?.passwordSet ?? false}
        availableProviders={recentAuthStatus?.availableProviders ?? []}
        canSatisfy={recentAuthStatus?.canSatisfy ?? true}
        onConfirmed={resumePendingAction}
    />
</AppLayout>
