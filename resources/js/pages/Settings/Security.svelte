<script lang="ts">
    import { tick } from "svelte";
    import { page, router } from "@inertiajs/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import CodeSnippet from "@/components/molecules/CodeSnippet.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
    import PasskeySection from "@/components/features/auth/PasskeySection.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import { Settings } from "@lucide/svelte";
    import { useForm } from "@inertiajs/svelte";
    import type { PasskeyListItem } from "@/lib/passkeys";
    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
    import type { SharedProps } from "@/lib/shared-props";
    import { providerLabel } from "@/lib/social";
    import { addToast } from "@/lib/stores/toast";

    interface Props {
        socialProviders?: string[];
        linkedProviders?: string[];
        passkeys?: PasskeyListItem[];
        /** passkey での「ログイン」が許されるか (TOTP 有効時は false。再認証には使える) */
        passkeyLoginAvailable?: boolean;
    }

    let {
        socialProviders = [],
        linkedProviders = [],
        passkeys = [],
        passkeyLoginAvailable = false,
    }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");
    const twoFactorEnabled = $derived(shared.auth?.user?.twoFactorEnabled ?? false);

    /**
     * EnsureLoginMethodRemains はログイン手段が 0 になる削除を
     * **302 + errors.login_method** で拒否する (Inertia に 422 JSON を返すと無言失敗するため)。
     * ここで拾って PasskeySection に渡し、画面上で明示する。
     */
    const loginMethodError = $derived(
        (page.props as unknown as { errors?: Record<string, string> }).errors?.login_method,
    );

    /* ----------------------------------------------------------------
     * 2FA 管理
     * 未有効 → 有効化開始 (POST) → QR + コード確認 (confirming)
     * → リカバリコード表示 → 有効。無効化は ConfirmDialog 経由。
     * 注: Fortify の password.confirm は撤去済み (generic recent-auth へ統一)。
     * リカバリコード表示/再生成の endpoint は recent-auth 配線済み
     * (FortifyServiceProvider::attachRecentAuthToSensitiveRoutes())。フロントは
     * guardWithRecentAuth で precheck し、stale なら再認証モーダルを挟んで再開する。
     * 残る 2FA endpoint の配線は config/fortify.php の TODO(template) 参照。
     * ---------------------------------------------------------------- */

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

    $effect(() => {
        // 再認証モーダルが閉じたら pending の destructive closure を破棄 (キャンセル時の残置防止)。
        // onConfirmed 経由の resume は action をローカルへ退避してから pendingAction を null 化するため
        // (resumePendingAction: `const a = pendingAction; pendingAction = null; a?.();`)、
        // 本 effect と二重で走っても resume が先に action を握っており安全。
        if (!recentAuthOpen) {
            pendingAction = null;
        }
    });

    /** QR 確認待ち (有効化開始済みだが未確認) */
    let confirming = $state(false);
    let enabling = $state(false);
    /**
     * enrollment 素材。QR と手動セットアップキーは独立に失敗しうる
     * (片方でも enrollment は続行できる = カメラ不可端末 / QR 非対応アプリ / 支援技術利用者を詰ませない)。
     */
    let qrSvg = $state<string | null>(null);
    let setupKey = $state<string | null>(null);
    /** 両方の取得に失敗した = enrollment を続行できない (再試行導線を出す) */
    let enrollmentAssetsFailed = $state(false);
    let loadingEnrollmentAssets = $state(false);
    let recoveryCodes = $state<string[]>([]);
    let loadingRecoveryCodes = $state(false);
    /** 新コード一覧へのフォーカス移動用 (再生成成功時に再保管を促す) */
    let recoveryCodesPanel = $state<HTMLDivElement | null>(null);

    /**
     * Fortify の 2FA 確認アクション (ConfirmTwoFactorAuthentication) は検証失敗を
     * 名前付き error bag "confirmTwoFactorAuthentication" に投げる
     * (login チャレンジ側は default bag)。Inertia は default bag が無いと named bag を
     * ネストしたまま共有するため、client 側で同名の errorBag を指定しないと
     * confirmForm.errors.code が解決されず、誤コード時に無言失敗する (F-2-02)。
     */
    const CONFIRM_TWO_FACTOR_ERROR_BAG = "confirmTwoFactorAuthentication" as const;

    const confirmForm = useForm({
        code: "",
    });

    async function fetchJson<T>(url: string): Promise<T> {
        const response = await fetch(url, {
            headers: { Accept: "application/json" },
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        return (await response.json()) as T;
    }

    /**
     * JSON レスポンスから非空文字列の field を取り出す。
     * fetchJson の generic は型 assertion にすぎないため shape は信用せず narrowing する
     * (不正 shape は通信失敗と同じ「その手段が使えない」に畳む)。
     */
    function readStringField(payload: unknown, key: string): string | null {
        if (typeof payload !== "object" || payload === null) return null;
        const value = (payload as Record<string, unknown>)[key];
        return typeof value === "string" && value.trim() !== "" ? value : null;
    }

    /** 単一 endpoint から文字列 field を取得する (通信失敗 / HTTP 失敗 / 不正 shape はすべて null)。
        表示文言も再試行導線も同一のため種別は区別しない。秘密が絡む経路なので console にも出さない。 */
    async function fetchStringField(url: string, key: string): Promise<string | null> {
        try {
            return readStringField(await fetchJson<unknown>(url), key);
        } catch {
            return null;
        }
    }

    /**
     * 取得世代。**後着優先**の判定に使う。
     * 破棄 (reset) と取得開始で進み、解決時に世代が変わっていれば結果を捨てる。
     * これが無いと (a) confirm/disable 成功で消したはずの secret が、遅れて解決した
     * fetch で再格納される (= サーバの新しい secret とは違うキーを認証アプリに登録させてしまう)
     * (b) 古い run が loading を握り続けて再有効化が始まらない、の 2 つの競合が起きる。
     */
    let enrollmentGeneration = 0;

    /**
     * enrollment 素材 (QR + 手動セットアップキー) を取得する。
     * 2 つは独立に扱い、片方が取れれば enrollment を続行できる。
     * 両方失敗したときだけ「取得失敗 (再試行可)」として提示する。
     */
    async function loadEnrollmentAssets(): Promise<void> {
        const generation = ++enrollmentGeneration;
        loadingEnrollmentAssets = true;

        const [qr, secret] = await Promise.all([
            fetchStringField("/user/two-factor-qr-code", "svg"),
            fetchStringField("/user/two-factor-secret-key", "secretKey"),
        ]);

        // 世代が進んでいる = 破棄済み or 新しい取得が走っている。結果も loading も触らない
        // (finally で戻すと古い run が新しい run の loading を消してしまう)
        if (generation !== enrollmentGeneration) return;

        qrSvg = qr;
        setupKey = secret;
        enrollmentAssetsFailed = qr === null && secret === null;
        loadingEnrollmentAssets = false;
    }

    /**
     * enrollment 素材を画面から破棄する (開始時 / confirm 成功時 / 無効化成功時に呼ぶ)。
     * 世代を進めることで、進行中の取得結果が後から再格納されるのを防ぐ。
     * TOTP secret の残置時間を enrollment 中に限定する目的も兼ねる。
     */
    function resetEnrollmentAssets(): void {
        enrollmentGeneration += 1;
        qrSvg = null;
        setupKey = null;
        enrollmentAssetsFailed = false;
        loadingEnrollmentAssets = false;
    }

    /**
     * リカバリコードを取得する。成否を返し、失敗時の文言は呼び出し側が文脈に応じて出す
     * (通常表示: 単純な取得失敗 / 再生成直後: 旧コード失効済みの注意)。
     */
    async function loadRecoveryCodes(): Promise<boolean> {
        loadingRecoveryCodes = true;
        try {
            recoveryCodes = await fetchJson<string[]>("/user/two-factor-recovery-codes");
            return true;
        } catch {
            return false;
        } finally {
            loadingRecoveryCodes = false;
        }
    }

    /**
     * 「リカバリコードを表示」押下時 (失敗は取得失敗トースト)。
     * GET も recent-auth 配線済みのため precheck を通す (stale なら再認証モーダル→再開)。
     */
    function showRecoveryCodes(): void {
        guardWithRecentAuth(() => {
            void (async () => {
                if (!(await loadRecoveryCodes())) {
                    addToast("error", "リカバリコードの取得に失敗しました。");
                }
            })();
        });
    }

    /* ---- リカバリコード再生成 (F-10) ----
       POST 成功 = 旧コードは既に失効。表示中の旧コードを即クリアしてから GET で
       新コードを取得し、成功時は一覧へフォーカスする (再保管を促す)。成功 toast は
       サーバ flash (RecoveryCodesGeneratedResponse) を単一の源とし client では出さない
       (二重発火 F-L1 の解消)。GET 失敗時は「再生成は成功／表示取得が失敗」を明示し、
       既存の「リカバリコードを表示」ボタンが再試行導線になる (recoveryCodes が空に戻る)。 */
    let regenerateDialogOpen = $state(false);
    let regenerating = $state(false);

    /** POST 成功後の後処理 (旧コードは既に失効している前提)。 */
    async function handleRegenerateSuccess(): Promise<void> {
        regenerateDialogOpen = false;
        // 旧コードは失効済み。誤保管を防ぐため画面から即クリアする。
        // 成功 toast はサーバ flash (RecoveryCodesGeneratedResponse) が単一の源として出す
        // (二重発火 F-L1 の解消)。ここでは client 楽観 toast を出さない。
        recoveryCodes = [];
        if (await loadRecoveryCodes()) {
            await tick();
            recoveryCodesPanel?.focus();
            return;
        }
        // GET 失敗は「表示取得の失敗」= 再生成成功とは別事象。成功 toast と並んでも
        // 矛盾しないよう対象を明示する。
        addToast(
            "error",
            "リカバリコードは再生成されましたが、新しいコードの表示取得に失敗しました。旧コードは既に無効です。「リカバリコードを表示」から再取得してください。",
        );
    }

    /** 再生成は recent-auth 必須 (サーバが最終ゲート)。stale なら再認証モーダル→再開 */
    function regenerateRecoveryCodes(): void {
        guardWithRecentAuth(() => {
            router.post(
                "/user/two-factor-recovery-codes",
                {},
                {
                    preserveScroll: true,
                    onStart: () => {
                        regenerating = true;
                    },
                    onSuccess: () => {
                        void handleRegenerateSuccess();
                    },
                    onError: () => {
                        regenerateDialogOpen = false;
                        addToast("error", "リカバリコードの再生成に失敗しました。");
                    },
                    onFinish: () => {
                        regenerating = false;
                    },
                },
            );
        });
    }

    function enableTwoFactor(): void {
        // 再試行時に前回の素材・エラーを持ち越さない
        resetEnrollmentAssets();
        router.post(
            "/user/two-factor-authentication",
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    enabling = true;
                },
                onSuccess: () => {
                    confirming = true;
                    void loadEnrollmentAssets();
                },
                onFinish: () => {
                    enabling = false;
                },
            },
        );
    }

    function confirmTwoFactor(event: SubmitEvent): void {
        event.preventDefault();
        confirmForm.post("/user/confirmed-two-factor-authentication", {
            preserveScroll: true,
            // Fortify の named error bag からエラーをスコープする (未指定だと errors.code が解決されない)
            errorBag: CONFIRM_TWO_FACTOR_ERROR_BAG,
            onSuccess: () => {
                confirming = false;
                resetEnrollmentAssets();
                confirmForm.reset();
                showRecoveryCodes();
            },
        });
    }

    let disableDialogOpen = $state(false);
    let disabling = $state(false);

    function disableTwoFactor(): void {
        // recent-auth 必須 (サーバが最終ゲート)。regenerateRecoveryCodes と同一の resume 契約。
        const action = () => {
            router.delete("/user/two-factor-authentication", {
                preserveScroll: true,
                onStart: () => {
                    disabling = true;
                },
                onSuccess: () => {
                    disableDialogOpen = false;
                    confirming = false;
                    resetEnrollmentAssets();
                    recoveryCodes = [];
                },
                onFinish: () => {
                    disabling = false;
                },
            });
        };

        void withRecentAuth({
            onFresh: action,
            onStale: (status) => {
                // 二重モーダル回避: 確認ダイアログを閉じてから再認証ダイアログを開く。
                disableDialogOpen = false;
                recentAuthStatus = status;
                pendingAction = action;
                recentAuthOpen = true;
            },
            // delegated (status 取得失敗) は onFresh フォールバック = server middleware が最終ゲート。
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
                <div class="flex items-center justify-between gap-4">
                    <h2 class="text-h3">2要素認証</h2>
                    {#if twoFactorEnabled}
                        <Badge tone="success">有効</Badge>
                    {:else}
                        <Badge tone="neutral">無効</Badge>
                    {/if}
                </div>
                <p class="mt-1 text-caption text-text-secondary">
                    認証アプリのワンタイムコードでログインを保護します。
                </p>

                {#if twoFactorEnabled}
                    <div class="mt-4 flex flex-col gap-4">
                        {#if recoveryCodes.length > 0}
                            <!-- tabindex="-1" は再生成成功時の programmatic focus 用 -->
                            <div
                                class="rounded-md border border-border bg-neutral p-4"
                                tabindex="-1"
                                bind:this={recoveryCodesPanel}
                                data-testid="recovery-codes-panel"
                            >
                                <p class="text-caption text-text-secondary">
                                    リカバリコードは安全な場所に保管してください。各コードは一度だけ使えます。
                                </p>
                                <ul
                                    class="mt-2 grid grid-cols-2 gap-1 text-body font-mono"
                                    data-testid="recovery-codes"
                                >
                                    {#each recoveryCodes as code (code)}
                                        <li>{code}</li>
                                    {/each}
                                </ul>
                            </div>
                        {:else}
                            <div>
                                <Button
                                    variant="ghost"
                                    onclick={showRecoveryCodes}
                                    loading={loadingRecoveryCodes}
                                    testId="show-recovery-codes-button"
                                >
                                    リカバリコードを表示
                                </Button>
                            </div>
                        {/if}
                        <div class="flex flex-wrap gap-3">
                            <Button
                                variant="ghost"
                                onclick={() => {
                                    regenerateDialogOpen = true;
                                }}
                                testId="regenerate-recovery-codes-button"
                            >
                                リカバリコードを再生成
                            </Button>
                            <Button
                                variant="danger-outline"
                                onclick={() => {
                                    disableDialogOpen = true;
                                }}
                                testId="disable-two-factor-button"
                            >
                                2要素認証を無効化
                            </Button>
                        </div>
                    </div>
                {:else if confirming}
                    <div class="mt-4 flex flex-col gap-4">
                        <p class="text-body text-text-secondary">
                            認証アプリで QR コードを読み取るか、セットアップキーを手動入力し、表示されたコードを入力して設定を完了してください。
                        </p>
                        {#if loadingEnrollmentAssets}
                            <!-- 取得中に「表示できませんでした」を先出ししない (失敗前に失敗文言を出さない) -->
                            <p
                                class="text-caption text-text-secondary"
                                aria-busy="true"
                                data-testid="enrollment-assets-loading"
                            >
                                認証アプリ設定用の情報を読み込んでいます…
                            </p>
                        {:else if enrollmentAssetsFailed}
                            <Alert
                                type="danger"
                                title="設定情報を取得できませんでした"
                                testId="enrollment-assets-error"
                            >
                                QR コードとセットアップキーのどちらも取得できませんでした。
                                {#snippet action()}
                                    <Button
                                        variant="ghost"
                                        onclick={() => void loadEnrollmentAssets()}
                                        loading={loadingEnrollmentAssets}
                                        testId="retry-enrollment-assets-button"
                                    >
                                        再試行
                                    </Button>
                                {/snippet}
                            </Alert>
                        {:else}
                            {#if qrSvg}
                                <!-- QR はサーバ提供の SVG をそのまま描画する。svg 文字列に属性を注入せず、
                                     wrapper を role="img" にしてアクセシブルネームを与える (H14) -->
                                <div
                                    role="img"
                                    aria-label="2 要素認証の設定用 QR コード"
                                    class="self-start rounded-md border border-border bg-surface p-4"
                                    data-testid="two-factor-qr"
                                >
                                    {@html qrSvg}
                                </div>
                            {:else}
                                <Alert type="warning" testId="qr-unavailable">
                                    QR コードを表示できませんでした。下のセットアップキーを認証アプリに手動入力してください。
                                </Alert>
                            {/if}

                            {#if setupKey}
                                <div class="flex flex-col gap-2">
                                    <p class="text-caption text-text-secondary">
                                        QR コードを読み取れない場合は、次のセットアップキーを認証アプリに手動入力してください。
                                    </p>
                                    <CodeSnippet code={setupKey} testId="two-factor-setup-key" />
                                </div>
                            {:else}
                                <Alert type="warning" testId="setup-key-unavailable">
                                    セットアップキーを表示できませんでした。上の QR コードを認証アプリで読み取ってください。
                                </Alert>
                            {/if}
                        {/if}
                        <form novalidate onsubmit={confirmTwoFactor} class="flex flex-col gap-4">
                            <FormField
                                label="認証コード"
                                id="two-factor-code"
                                error={confirmForm.errors.code}
                            >
                                {#snippet children({ id, describedBy, invalid })}
                                    <Input
                                        {id}
                                        type="text"
                                        inputmode="numeric"
                                        bind:value={confirmForm.code}
                                        error={invalid}
                                        aria-describedby={describedBy}
                                        autocomplete="one-time-code"
                                    />
                                {/snippet}
                            </FormField>
                            <div>
                                <Button type="submit" loading={confirmForm.processing}>
                                    確認して有効化
                                </Button>
                            </div>
                        </form>
                    </div>
                {:else}
                    <div class="mt-4">
                        <Button
                            onclick={enableTwoFactor}
                            loading={enabling}
                            testId="enable-two-factor-button"
                        >
                            有効化
                        </Button>
                    </div>
                {/if}
            </Card>

            <PasskeySection
                {passkeys}
                {passkeyLoginAvailable}
                {twoFactorEnabled}
                {loginMethodError}
                guard={guardWithRecentAuth}
            />

            <Card padding="lg">
                <h2 class="text-h3">ソーシャルログイン連携</h2>
                <p class="mt-1 text-caption text-text-secondary">
                    外部アカウントを連携すると、そのアカウントでもログインできます。
                </p>
                <ul class="mt-4 flex flex-col gap-3">
                    {#each socialProviders as provider (provider)}
                        <li
                            class="flex items-center justify-between gap-4 rounded-md border border-border p-3"
                        >
                            <span class="text-body">{providerLabel(provider)}</span>
                            {#if linkedProviders.includes(provider)}
                                <Badge tone="success" testId={`linked-${provider}`}>連携済み</Badge>
                            {:else}
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    href={`/auth/${provider}/redirect/link`}
                                    testId={`link-${provider}`}
                                >
                                    連携する
                                </Button>
                            {/if}
                        </li>
                    {/each}
                </ul>
            </Card>
        </div>

        <ConfirmDialog
            bind:open={disableDialogOpen}
            title="2要素認証の無効化"
            message="2要素認証を無効化しますか？ リカバリコードも無効になります。"
            confirmLabel="無効化する"
            confirmVariant="danger"
            processing={disabling}
            onConfirm={disableTwoFactor}
            testId="disable-two-factor-dialog"
        />

        <ConfirmDialog
            bind:open={regenerateDialogOpen}
            title="リカバリコードの再生成"
            message="リカバリコードを再生成しますか？ 既存のリカバリコードは直ちにすべて失効します。新しいコードを必ず保管し直してください。"
            confirmLabel="再生成する"
            confirmVariant="danger"
            processing={regenerating}
            onConfirm={regenerateRecoveryCodes}
            testId="regenerate-recovery-codes-dialog"
        />

        <RecentAuthModal
            bind:open={recentAuthOpen}
            passwordSet={recentAuthStatus?.passwordSet ?? false}
            availableProviders={recentAuthStatus?.availableProviders ?? []}
            canSatisfy={recentAuthStatus?.canSatisfy ?? true}
            passkeyAvailable={recentAuthStatus?.passkeyAvailable ?? false}
            onConfirmed={resumePendingAction}
        />
        </PageContent>
    </PageContainer>
</AppLayout>
