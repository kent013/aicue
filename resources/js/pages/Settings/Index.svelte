<script lang="ts">
    import { page, router, useForm } from "@inertiajs/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import DangerZone from "@/components/molecules/DangerZone.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PasswordInput from "@/components/molecules/PasswordInput.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
    import { Settings } from "@lucide/svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { AccountDeletionBlocker } from "@/types/account";

    // ページ専用 props 型 (SharedProps を継承しページ固有 prop を足す。多重キャスト排除)。
    // SharedProps に errors フィールドは無く、Inertia が別途注入するため継承衝突しない。
    interface SettingsPageProps extends SharedProps {
        /** 退会をブロックしている組織と次の一手 (表示時点のスナップショット) */
        accountDeletionBlockers?: AccountDeletionBlocker[];
        /** password が設定済みか。欠落 = 状態不明 (既定値に倒さない。下記 passwordState 参照) */
        hasPassword?: boolean;
        errors?: Record<string, string | string[]>;
    }

    const props = $derived(page.props as unknown as SettingsPageProps);
    const appName = $derived(props.appName ?? "");
    const accountDeletionBlockers = $derived(props.accountDeletionBlockers ?? []);

    /** 別組織へ切り替える導線の失敗表示 (押したのに何も起きない = 詰みを作らない) */
    let switchError = $state<string | null>(null);

    /**
     * 別組織の課金導線。/billing は現在組織スコープ (route parameter を持たない) のため、
     * 先に組織を切り替える。**成功時のみ** /billing へ進む (失敗時はその場に留まる)。
     * 所属・存在の検査はサーバが権威 (非所属は 404 = 存在秘匿)。
     */
    function switchThenBilling(slug: string): void {
        switchError = null;
        router.post(
            `/organizations/${slug}/switch`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => router.visit("/billing"),
                onError: () => {
                    switchError =
                        "組織を切り替えられませんでした。時間をおいて再度お試しください。";
                },
            },
        );
    }

    /**
     * prop 欠落 (= 状態不明) を false に倒すと、password 設定済みユーザーに初回設定フォームを出す
     * = 本批で潰している「状態不明を誤った UI に倒す」の再演になる。3 値で扱う。
     */
    const passwordState = $derived.by((): "set" | "unset" | "unknown" =>
        typeof props.hasPassword === "boolean" ? (props.hasPassword ? "set" : "unset") : "unknown",
    );

    // ブロック時にサーバーが返す errors.account を表示文字列へ正規化 (string | string[] 両対応)
    const accountError = $derived.by((): string | null => {
        const err = props.errors?.account;
        if (err === undefined) return null;
        return Array.isArray(err) ? (err[0] ?? null) : err;
    });

    const initialUser = props.auth?.user ?? null;

    /**
     * Fortify の PUT /user/profile-information は errorBag (updateProfileInformation)
     * を使う。submit 時に errorBag を指定すると Inertia がバッグを解決するため、
     * form.errors はバッグ名でネストされずフィールド名で参照できる。
     */
    const profileForm = useForm({
        name: initialUser?.name ?? "",
        email: initialUser?.email ?? "",
    });

    // baseline email。更新成功のたびに最新値へ同期し、連続操作 (変更→再編集) 時の
    // precheck 判定ドリフトを抑える。
    let baselineEmail = $state(initialUser?.email ?? "");

    function putProfile(): void {
        // 送信時点の email をスナップショット。onSuccess で「サーバが受理した値」を
        // baseline にするため、送信後〜応答前に入力が変わっても現在入力値で baseline を汚さない。
        const submittedEmail = profileForm.email;
        profileForm.put("/user/profile-information", {
            errorBag: "updateProfileInformation",
            preserveScroll: true,
            onSuccess: () => {
                // 成功時、受理された送信値を baseline に (連続操作の判定ズレ防止)
                baselineEmail = submittedEmail;
            },
        });
    }

    function submitProfile(event: SubmitEvent): void {
        event.preventDefault();
        // email 変更時のみ step-up precheck (氏名のみ変更は従来通り即 put)。
        // サーバ側 recent-auth.on-email-change が最終ゲート、これは UX 補助。
        const emailChanged = profileForm.email !== baselineEmail;
        if (emailChanged) {
            guardWithRecentAuth(putProfile);
            return;
        }
        putProfile();
    }

    const passwordForm = useForm({
        current_password: "",
        password: "",
    });

    function submitPassword(event: SubmitEvent): void {
        event.preventDefault();
        // 送信中の誤認防止のため、前回エラーを送信開始時に明示クリアする
        // (Inertia useForm は送信ではクリアせず応答後にのみ errors を更新するため)。
        // 本フォームが所有するフィールドに限定してクリアし、過剰クリアの余地を残さない。
        passwordForm.clearErrors("current_password", "password");
        passwordForm.put("/user/password", {
            errorBag: "updatePassword",
            preserveScroll: true,
            onSuccess: () => {
                passwordForm.reset();
            },
        });
    }

    const passwordSetupForm = useForm({ password: "" });

    function submitPasswordSetup(event: SubmitEvent): void {
        event.preventDefault();
        passwordSetupForm.clearErrors("password");
        // 初回設定は recent-auth 必須 (サーバ側 middleware が最終ゲート)。
        // stale なら再認証モーダルを挟んで再開する (他の機微操作と同じ precheck)。
        guardWithRecentAuth(() => {
            passwordSetupForm.post("/settings/password", {
                preserveScroll: true,
                onSuccess: () => {
                    passwordSetupForm.reset();
                },
            });
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
                preserveScroll: true,
                onStart: () => {
                    deleting = true;
                },
                // ブロック時 (errors.account): ダイアログを閉じ DangerZone の Alert を露出させる
                onError: () => {
                    deleteDialogOpen = false;
                },
                onFinish: () => {
                    deleting = false;
                },
            });
        });
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader title="設定" icon={Settings} testId="settings-heading" />
        <PageContent>
            <nav aria-label="設定メニュー" class="mt-4 flex gap-4 border-b border-border pb-2">
            <TextLink href="/settings">プロフィール</TextLink>
            <TextLink href="/settings/security">セキュリティ</TextLink>
        </nav>

        <div class="mt-6 flex flex-col gap-10">
            <Card padding="lg">
                <h2 class="text-h3">プロフィール</h2>
                <p class="mt-1 text-caption text-text-secondary">名前とメールアドレスを更新します。</p>
                <form novalidate onsubmit={submitProfile} class="mt-4 flex flex-col gap-4">
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
                {#if passwordState === "unknown"}
                    <h2 class="text-h3">パスワード</h2>
                    <Alert type="warning" testId="password-state-unknown" class="mt-4">
                        パスワードの設定状態を取得できませんでした。ページを再読み込みしてお試しください。
                        {#snippet action()}
                            <Button variant="ghost" onclick={() => router.reload()}>再読み込み</Button>
                        {/snippet}
                    </Alert>
                {:else if passwordState === "set"}
                    <h2 class="text-h3">パスワード変更</h2>
                    <p class="mt-1 text-caption text-text-secondary">
                        現在のパスワードを確認のうえ、新しいパスワードに変更します。
                    </p>
                    <form novalidate onsubmit={submitPassword} class="mt-4 flex flex-col gap-4">
                        <FormField
                            label="現在のパスワード"
                            id="current-password"
                            error={passwordForm.errors.current_password}
                        >
                            {#snippet children({ id, describedBy, invalid })}
                                <PasswordInput
                                    {id}
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
                                <PasswordInput
                                    {id}
                                    bind:value={passwordForm.password}
                                    error={invalid}
                                    aria-describedby={describedBy}
                                    autocomplete="new-password"
                                />
                            {/snippet}
                        </FormField>
                        <div>
                            <Button type="submit" loading={passwordForm.processing}>
                                {passwordForm.processing ? "変更中…" : "パスワードを変更"}
                            </Button>
                        </div>
                    </form>
                {:else}
                    <h2 class="text-h3">パスワードを設定</h2>
                    <p class="mt-1 text-caption text-text-secondary">
                        現在はパスキーまたはソーシャルログインでご利用中です。パスワードを設定すると、
                        パスワードでもログインできるようになります (既存のログイン手段はそのまま使えます)。
                    </p>
                    <form novalidate onsubmit={submitPasswordSetup} class="mt-4 flex flex-col gap-4">
                        <FormField
                            label="新しいパスワード"
                            id="new-password"
                            error={passwordSetupForm.errors.password}
                        >
                            {#snippet children({ id, describedBy, invalid })}
                                <PasswordInput
                                    {id}
                                    bind:value={passwordSetupForm.password}
                                    error={invalid}
                                    aria-describedby={describedBy}
                                    autocomplete="new-password"
                                />
                            {/snippet}
                        </FormField>
                        <div>
                            <Button
                                type="submit"
                                loading={passwordSetupForm.processing}
                                testId="set-password-button"
                            >
                                {passwordSetupForm.processing ? "設定中…" : "パスワードを設定"}
                            </Button>
                        </div>
                    </form>
                {/if}
            </Card>

            <DangerZone
                title="アカウント削除"
                description="アカウントを削除すると、すべてのデータが完全に失われます。この操作は取り消せません。"
            >
                {#if accountDeletionBlockers.length > 0}
                    <Alert type="warning" title="退会するには先に対応が必要です" class="mb-3">
                        以下の組織で対応が必要です（削除時にサーバーが再判定します）。
                        <ul class="mt-2 list-disc pl-5">
                            {#each accountDeletionBlockers as blocker (blocker.slug)}
                                <li>
                                    {blocker.name}
                                    <!-- action は 1 件ずつ独立した操作に見えるよう縦積みにする
                                         (リンクとボタンが区切りなく連続すると押し間違える) -->
                                    <div class="mt-1 flex flex-col items-start gap-1">
                                        {#each blocker.actions as action (action)}
                                            {#if action === "transfer_ownership"}
                                                <TextLink
                                                    href={`/organizations/${blocker.slug}/settings`}
                                                >
                                                    オーナーを移譲する
                                                </TextLink>
                                            {:else if action === "open_billing"}
                                                <TextLink href="/billing">
                                                    サブスクリプションを解約する
                                                </TextLink>
                                            {:else if action === "switch_organization_then_open_billing"}
                                                <Button
                                                    variant="ghost"
                                                    onclick={() => switchThenBilling(blocker.slug)}
                                                    testId="switch-then-billing-button"
                                                >
                                                    この組織に切り替えて解約する
                                                </Button>
                                            {/if}
                                        {/each}
                                    </div>
                                </li>
                            {/each}
                        </ul>
                        {#if switchError}
                            <p class="mt-2 text-caption text-danger" data-testid="switch-organization-error">
                                {switchError}
                            </p>
                        {/if}
                    </Alert>
                {/if}
                {#if accountError}
                    <Alert type="danger" class="mb-3">{accountError}</Alert>
                {/if}
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
            status={recentAuthStatus}
            onConfirmed={resumePendingAction}
        />
        </PageContent>
    </PageContainer>
</AppLayout>
