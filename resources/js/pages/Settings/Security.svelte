<script lang="ts">
    import { tick } from "svelte";
    import { page, router } from "@inertiajs/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import { useForm } from "@inertiajs/svelte";
    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
    import type { SharedProps } from "@/lib/shared-props";
    import { providerLabel } from "@/lib/social";
    import { addToast } from "@/lib/stores/toast";

    interface Props {
        socialProviders?: string[];
        linkedProviders?: string[];
    }

    let { socialProviders = [], linkedProviders = [] }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");
    const twoFactorEnabled = $derived(shared.auth?.user?.twoFactorEnabled ?? false);

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
    let qrSvg = $state<string | null>(null);
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

    async function loadQrCode(): Promise<void> {
        try {
            const data = await fetchJson<{ svg: string }>("/user/two-factor-qr-code");
            qrSvg = data.svg;
        } catch {
            addToast("error", "QR コードの取得に失敗しました。再読み込みしてください。");
        }
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
                    void loadQrCode();
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
                qrSvg = null;
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
                    qrSvg = null;
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
    <h1 class="text-h2">設定</h1>

    <nav aria-label="設定メニュー" class="mt-4 flex gap-4 border-b border-border pb-2">
        <TextLink href="/settings">プロフィール</TextLink>
        <TextLink href="/settings/security">セキュリティ</TextLink>
    </nav>

    <div class="mt-6 flex max-w-2xl flex-col gap-10">
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
                        認証アプリで以下の QR コードを読み取り、表示されたコードを入力して設定を完了してください。
                    </p>
                    {#if qrSvg}
                        <!-- QR はサーバ提供の SVG をそのまま描画する -->
                        <div class="self-start rounded-md border border-border bg-surface p-4">
                            <!-- eslint-disable-next-line svelte/no-at-html-tags -->
                            {@html qrSvg}
                        </div>
                    {/if}
                    <form onsubmit={confirmTwoFactor} class="flex flex-col gap-4">
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
        onConfirmed={resumePendingAction}
    />
</AppLayout>
