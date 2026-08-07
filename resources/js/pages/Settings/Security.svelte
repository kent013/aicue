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
    import {
        withRecentAuth,
        isRecentAuthRequiredPayload,
        RECENT_AUTH_CONFIRM_PATH,
        type RecentAuthStatus,
    } from "@/lib/recent-auth";
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

    /**
     * precheck の結果 (fresh / stale / delegated) を **返す**。
     * PasskeySection は precheck 区間 (`/recent-auth/status` の待ち時間) を自前の loading で
     * 覆う必要があるため戻り値を待つ。結果に関心が無い呼び出し側は `void` で明示的に捨てる。
     *
     * ★`onDelegated` を **optional 第 2 引数**として受ける (T124)。
     *   `withRecentAuth` は status 取得失敗時に `onDelegated ?? onFresh` を呼ぶため、
     *   未指定だと「action をそのまま実行してサーバの最終ゲートに委ねる」挙動になる。
     *   これは「1 回きりの mutation」なら正しいが、**409 を受けて自分を再実行する
     *   呼び出し側では無限ループになる** (409 → status 失敗 → 再取得 → 409 …)。
     *   そういう呼び出し側は必ず onDelegated を渡すこと。
     *   既存 4 呼び出し側 (recovery codes 表示 / 再生成 / passkey guard / disable) は
     *   無指定のままで挙動不変。
     */
    function guardWithRecentAuth(
        action: () => void,
        onDelegated?: () => void,
    ): Promise<"fresh" | "stale" | "delegated"> {
        return withRecentAuth({
            onFresh: action,
            onStale: (status) => {
                recentAuthStatus = status;
                pendingAction = action;
                recentAuthOpen = true;
            },
            onDelegated,
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

    /**
     * enrollment 素材 1 本の取得結果。
     * `recentAuthRequired` は「取得失敗」とは**別事象**として上位へ返す
     * (409 を「取得失敗」に畳むと、原因と対処が一致しない表示になり再試行が無限に失敗する)。
     */
    interface EnrollmentField {
        value: string | null;
        recentAuthRequired: boolean;
    }

    /**
     * enrollment 素材の単一 endpoint を取得する (通信失敗 / HTTP 失敗 / 不正 shape はすべて null)。
     * 表示文言も再試行導線も同一のため種別は区別しない。秘密が絡む経路なので console にも出さない。
     *
     * ★`Accept: application/json` は**必須**。これが無いと RequireRecentAuth の
     *   expectsJson() が偽になり 302 が返って fetch がリダイレクトを追従するため、
     *   409 判定が一度も成立しない (サーバ側 Feature テストが同じヘッダ条件で固定している)。
     */
    async function fetchEnrollmentField(url: string, key: string): Promise<EnrollmentField> {
        try {
            const response = await fetch(url, { headers: { Accept: "application/json" } });
            if (!response.ok) {
                const body: unknown = await response.json().catch(() => null);
                return {
                    value: null,
                    recentAuthRequired: isRecentAuthRequiredPayload(response.status, body),
                };
            }
            return {
                value: readStringField(await response.json(), key),
                recentAuthRequired: false,
            };
        } catch {
            return { value: null, recentAuthRequired: false };
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
     * step-up を要求されたが状態を確認できず、モーダルを出せなかった状態。
     * 「取得失敗」とは別事象なので別の状態・別の文言・別の導線で出す。
     */
    let enrollmentStepUpBlocked = $state(false);
    /**
     * 自動再開を 1 enrollment につき 1 回に制限するフラグ。
     * サーバの鮮度判定が status と 409 で食い違う異常時でも必ず停止させるための上限であり、
     * **ループを切るのは常に人間の操作**にする (再試行ボタンがこのフラグを戻す)。
     */
    let enrollmentStepUpRetried = false;

    /**
     * enrollment 素材 (QR + 手動セットアップキー) を取得する。
     * 2 つは独立に扱い、片方が取れれば enrollment を続行できる。
     * 両方失敗したときだけ「取得失敗 (再試行可)」として提示する。
     *
     * ★409 (step-up 要求) の集約はここ 1 箇所。個別 fetch から guardWithRecentAuth を呼ばない
     *   (QR と secret-key は同一 session の同一鮮度判定なので**両方 409 になるのが通常**であり、
     *    個別に呼ぶとモーダル 2 重起動と pendingAction 上書きが常時発生する)。
     */
    async function loadEnrollmentAssets(): Promise<void> {
        const generation = ++enrollmentGeneration;
        loadingEnrollmentAssets = true;
        /*
         * 前回の**結果表示**をここで一度に捨てる (取得結果に依らない単一の初期化点)。
         * これが無いと 500 で取得失敗 → 再試行 → 409 の順に遷移したとき、409 分岐は
         * enrollmentAssetsFailed を触らないため「再認証が必要です」と
         * 「設定情報を取得できませんでした」が同時に出る (原因と対処が食い違う表示になる)。
         * ★enrollmentStepUpRetried (自動再開の上限) はここでは戻さない。
         *   戻すと 409 → 自動再開 → 409 → 自動再開 … が無限に回る。
         *   上限を戻せるのは人間の操作 (retryEnrollmentAssets) と enrollment の破棄だけ。
         */
        enrollmentAssetsFailed = false;
        enrollmentStepUpBlocked = false;

        const [qr, secret] = await Promise.all([
            fetchEnrollmentField("/user/two-factor-qr-code", "svg"),
            fetchEnrollmentField("/user/two-factor-secret-key", "secretKey"),
        ]);

        // 世代が進んでいる = 破棄済み or 新しい取得が走っている。結果も loading も触らない
        // (finally で戻すと古い run が新しい run の loading を消してしまう)
        if (generation !== enrollmentGeneration) return;

        // 鮮度切れは「取得失敗」ではない。再認証モーダルを 1 回だけ開き、成立後に同じ取得を再開する
        if (qr.recentAuthRequired || secret.recentAuthRequired) {
            loadingEnrollmentAssets = false;

            // 自動再開の上限。ここを超えたら人間の操作 (再試行ボタン) を待つ
            if (enrollmentStepUpRetried) {
                enrollmentStepUpBlocked = true;
                return;
            }
            enrollmentStepUpRetried = true;

            void guardWithRecentAuth(
                () => void loadEnrollmentAssets(),
                // status 取得失敗 (delegated)。**再取得しない** (ここで再取得すると
                // 409 → status 失敗 → 再取得 の無限ループになる)。
                () => {
                    enrollmentStepUpBlocked = true;
                },
            );

            return;
        }

        qrSvg = qr.value;
        setupKey = secret.value;
        enrollmentAssetsFailed = qr.value === null && secret.value === null;
        loadingEnrollmentAssets = false;
    }

    /**
     * 手動再試行 (取得失敗 Alert / step-up 不能 Alert の両方から呼ぶ)。
     * **自動再開の上限を戻すのはここだけ** (ループを切るのは常に人間の操作)。
     * 結果表示のリセットは loadEnrollmentAssets() 側の単一初期化点が行う。
     */
    function retryEnrollmentAssets(): void {
        enrollmentStepUpRetried = false;
        void loadEnrollmentAssets();
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
        enrollmentStepUpBlocked = false;
        enrollmentStepUpRetried = false;
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
        void guardWithRecentAuth(() => {
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
        void guardWithRecentAuth(() => {
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

    /**
     * 有効化開始。POST /user/two-factor-authentication は recent-auth 必須になった (T124) ため
     * precheck を前段に置く。
     * ★順序が重要: step-up は enrollment の**最初**の操作にする。precheck 無しで POST すると
     *   Inertia mutation が 409 (`recent_auth_required`) を受け、単一ハンドラ
     *   (registerRecentAuthRedirectHandler) が confirm 画面へ**全画面遷移**する。
     *   precheck ならモーダルで完結するので**設定画面から離脱せず**、成立後に開始操作を
     *   その場で再開できる (既存 3 呼び出し側と同じ流儀)。
     *   ※ここで守るのは離脱の回避であって QR / 入力中コードの保持ではない
     *     (この時点では素材はまだ存在しない。素材取得後の鮮度切れは
     *      loadEnrollmentAssets() の 409 分岐が担当する)。
     * ★throttle の**巻き添え**はもう論点ではない (T125 でレーン分離済み)。
     *   two-factor.enable/confirm は `two-factor-manage` (10/min)、
     *   recent-auth.password は `password-verify` (6/min) で別 bucket なので、
     *   2FA 操作の連打で再認証が 429 になる旧構造 (inline の 1 bucket 共有) は無い。
     *   ただし ThrottleRequests は middleware priority により RequireRecentAuth より
     *   **先**に走る (実測: 鮮度切れの GET でも X-RateLimit-Remaining が減る)。
     *   つまり precheck 無しの POST は「成立し得ない試行」で two-factor-manage の枠を
     *   削る。precheck はそれも避けるが、固定したい本命は**画面状態を失わないこと**である。
     */
    function enableTwoFactor(): void {
        void guardWithRecentAuth(() => {
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
        });
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
                        {:else if enrollmentStepUpBlocked}
                            <!-- step-up を要求されたが状態を確認できずモーダルを出せなかった。
                                 「取得失敗」とは別事象なので別文言・別導線で受ける (行き先のない詰みを作らない) -->
                            <Alert
                                type="warning"
                                title="再認証が必要です"
                                testId="enrollment-step-up-blocked"
                            >
                                2 要素認証の設定情報を表示するには再認証が必要です。
                                <TextLink href={RECENT_AUTH_CONFIRM_PATH}>再認証ページ</TextLink>
                                で本人確認を済ませてから、もう一度お試しください。
                                {#snippet action()}
                                    <Button
                                        variant="ghost"
                                        onclick={retryEnrollmentAssets}
                                        loading={loadingEnrollmentAssets}
                                        testId="retry-enrollment-step-up-button"
                                    >
                                        再試行
                                    </Button>
                                {/snippet}
                            </Alert>
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
                                        onclick={retryEnrollmentAssets}
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
            status={recentAuthStatus}
            onConfirmed={resumePendingAction}
        />
        </PageContent>
    </PageContainer>
</AppLayout>
