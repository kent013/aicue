<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import { BatteryCharging, CreditCard, TriangleAlert } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import type { AutoRechargeProps } from "@/types/billing";

    /**
     * AutoRechargeCard — 請求画面の「チケット オートリチャージ (自動補充)」セクション (P8a)。
     *
     * 残高が閾値を下回ったら、保存済みカードで上限 (Max) まで自動購入する。
     * subscription 非依存 — 無料パーソナルを含む全プランで利用できる。**既定 off の opt-in**。
     *
     * 表示状態:
     *  - カード未登録: 有効化 CTA を出さず「カードを登録する」CTA (Checkout mode=setup へ)
     *  - 登録処理中 (setupPending): 「カード登録処理中」表示 (webhook Job 反映待ち、30 分窓)
     *  - 失敗停止 (disabledReason='payment_failures'): danger バナー + 再有効化導線
     *  - 有効/無効: 閾値・Max の編集 + 有効化 (同意パネル経由) / 停止 (常に押せる)
     *
     * fail-closed の対称性: 有効化は同意を要求し、停止は一切妨げない (ワンクリック停止)。
     * **ボタンは条件未充足で disabled にしない** (AGENTS.md 禁止事項 #8) — 押下時に
     * 入力エラーを表示する。in-flight 中の多重送信抑止は Button の loading で表現する。
     */
    interface Props {
        autoRecharge: AutoRechargeProps;
        /** 設定更新 POST 先 (billing.auto-recharge.update) */
        updateUrl: string;
        /** カード登録開始 POST 先 (billing.auto-recharge.setup) */
        setupUrl: string;
        /** setup 開始 POST の attempt_token (props 由来・render 固定で二重 submit を冪等化) */
        setupAttemptToken: string;
    }

    let { autoRecharge, updateUrl, setupUrl, setupAttemptToken }: Props = $props();

    // 一方向 value + oninput (type=number への two-way bind 禁止規約)。props 更新で正準値へ再同期。
    let thresholdText = $derived(String(autoRecharge.thresholdCount));
    let maxText = $derived(String(autoRecharge.maxCount));
    let submitting = $state(false);
    let showConsent = $state(false);
    /** 押下時に初めて出す入力エラー (disabled でブロックしない代わりの提示点) */
    let inputError = $state<string | null>(null);
    /** サーバ 422 の可視化 (flash toast は errors bag を運ばないため silent failure を防ぐ) */
    let serverError = $state<string | null>(null);

    const pickServerError = (errors: Record<string, string>): string | null => {
        for (const key of [
            "enabled",
            "consent_version",
            "threshold_count",
            "max_count",
            "attempt_token",
        ]) {
            const message = errors[key];
            if (typeof message === "string" && message !== "") return message;
        }
        return Object.values(errors).find((v) => typeof v === "string" && v !== "") ?? null;
    };

    const formatYen = (v: number): string => new Intl.NumberFormat("ja-JP").format(v);

    const parseIntStrict = (raw: string): number | null => {
        const trimmed = raw.trim();
        if (trimmed === "" || !/^\d+$/.test(trimmed)) return null;
        const n = Number.parseInt(trimmed, 10);
        return Number.isNaN(n) ? null : n;
    };

    const parsedThreshold = $derived.by<number | null>(() => {
        const n = parseIntStrict(thresholdText);
        return n === null || n < 0 ? null : n;
    });

    const parsedMax = $derived.by<number | null>(() => {
        const n = parseIntStrict(maxText);
        if (n === null || n < autoRecharge.minCount || n > autoRecharge.maxCountLimit) return null;
        return n;
    });

    const rangeError = $derived.by<string | null>(() => {
        if (parsedThreshold === null) {
            return "リチャージ開始残高は 0 以上の整数で入力してください";
        }
        if (parsedMax === null) {
            return `リチャージ後の残高は ${autoRecharge.minCount} 〜 ${autoRecharge.maxCountLimit} の整数で入力してください`;
        }
        if (parsedMax <= parsedThreshold) {
            return "リチャージ後の残高は開始残高より大きい値を指定してください";
        }
        return null;
    });

    // 適用単価: Max 枚をまとめ買いした場合の tier 単価 (同意文言の上限額と同じ計算)。
    const appliedUnit = $derived.by<number>(() => {
        const c = parsedMax;
        if (c === null) return autoRecharge.baseUnitAmountJpy;
        let unit = autoRecharge.tiers[0]?.unitAmount ?? autoRecharge.baseUnitAmountJpy;
        for (const t of autoRecharge.tiers) {
            if (c >= t.minCount) unit = t.unitAmount;
        }
        return unit;
    });

    const maxChargeAmount = $derived(
        parsedMax !== null && rangeError === null ? parsedMax * appliedUnit : null,
    );

    const consentLines = $derived.by<string[]>(() => {
        const lines = [
            `残高が ${parsedThreshold ?? autoRecharge.thresholdCount} 枚を下回ると、登録済みのカードで不足分をまとめて購入し、${parsedMax ?? autoRecharge.maxCount} 枚まで補充します。`,
        ];
        if (maxChargeAmount !== null) {
            lines.push(`1 回の自動購入の上限額は ¥${formatYen(maxChargeAmount)} (税込) です。`);
        }
        lines.push("この設定はあとからいつでも変更・停止できます。");
        return lines;
    });

    const stateBadge = $derived.by<{ label: string; tone: "success" | "danger" | "neutral" }>(
        () => {
            if (autoRecharge.enabled) return { label: "有効", tone: "success" };
            if (autoRecharge.disabledReason === "payment_failures") {
                return { label: "自動停止中", tone: "danger" };
            }
            return { label: "無効", tone: "neutral" };
        },
    );

    interface UpdatePayload {
        enabled: boolean;
        threshold_count: number;
        max_count: number;
        consent_version?: string;
        [key: string]: boolean | number | string | undefined;
    }

    function post(payload: UpdatePayload): void {
        submitting = true;
        serverError = null;
        router.post(updateUrl, payload, {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                serverError = pickServerError(errors);
            },
            onSuccess: () => {
                serverError = null;
                inputError = null;
                showConsent = false;
            },
            onFinish: () => {
                submitting = false;
            },
        });
    }

    /** 入力値の妥当性を押下時に確定する (disabled でブロックしない = 禁止事項 #8)。 */
    function ensureValidRange(): boolean {
        inputError = rangeError;
        return rangeError === null;
    }

    function openConsent(): void {
        if (submitting) return;
        if (!ensureValidRange()) return;
        showConsent = true;
    }

    function confirmEnable(): void {
        if (submitting) return;
        if (!ensureValidRange() || parsedThreshold === null || parsedMax === null) return;
        post({
            enabled: true,
            threshold_count: parsedThreshold,
            max_count: parsedMax,
            // 同意文言バージョンのみ送る。金額はサーバが現行カタログで再計算する。
            consent_version: autoRecharge.consentVersion,
        });
    }

    /** 有効のまま閾値/Max を更新。上限引き上げ・再同意要求時は同意パネルを経由する。 */
    function handleUpdate(): void {
        if (submitting) return;
        if (!ensureValidRange() || parsedThreshold === null || parsedMax === null) return;
        if (autoRecharge.requiresReconsent || parsedMax > autoRecharge.maxCount) {
            showConsent = true;
            return;
        }
        post({
            enabled: true,
            threshold_count: parsedThreshold,
            max_count: parsedMax,
            consent_version: autoRecharge.consentVersion,
        });
    }

    /** カード未登録時の設定保存 (enabled=false の upsert)。有効化はしない。 */
    function handleSaveDraft(): void {
        if (submitting) return;
        if (!ensureValidRange() || parsedThreshold === null || parsedMax === null) return;
        post({ enabled: false, threshold_count: parsedThreshold, max_count: parsedMax });
    }

    /** 停止は常に成立させる (入力値が壊れていても現在値で送る = ワンクリック停止の保証)。 */
    function handleDisable(): void {
        if (submitting) return;
        inputError = null;
        const threshold = parsedThreshold ?? autoRecharge.thresholdCount;
        const max =
            parsedMax !== null && parsedMax > threshold ? parsedMax : autoRecharge.maxCount;
        post({ enabled: false, threshold_count: threshold, max_count: max });
    }

    function handleStartSetup(): void {
        if (submitting) return;
        submitting = true;
        serverError = null;
        router.post(
            setupUrl,
            { attempt_token: setupAttemptToken },
            {
                onError: (errors: Record<string, string>) => {
                    serverError = pickServerError(errors);
                },
                onSuccess: () => {
                    serverError = null;
                },
                onFinish: () => {
                    submitting = false;
                },
            },
        );
    }

    // setupPending (カード登録の webhook/Job 反映待ち) の間、autoRecharge props だけを
    // partial reload でポーリングし、反映され次第 UI を自動で切り替える (手動リロード不要)。
    // 30 分窓 (サーバ側 stale 判定) を超えると props 側が false になるため無限ポーリングはしない。
    $effect(() => {
        if (!autoRecharge.setupPending) return;

        const intervalId = window.setInterval(() => {
            router.reload({ only: ["autoRecharge"] });
        }, 4000);

        return () => window.clearInterval(intervalId);
    });
</script>

<Card padding="lg" testId="auto-recharge-card">
    <div class="flex flex-wrap items-center gap-2">
        <BatteryCharging class="h-5 w-5 text-text-secondary" aria-hidden="true" />
        <h2 class="text-h3">チケット オートリチャージ</h2>
        <Badge tone={stateBadge.tone} bordered testId="auto-recharge-state-badge">
            {stateBadge.label}
        </Badge>
    </div>
    <p class="mt-1 text-body text-text-secondary">
        残高が少なくなったら、不足分をまとめて自動購入し、設定した枚数まで補充します。
        設定しない限り自動購入は行われません。
    </p>

    {#if autoRecharge.enabled && autoRecharge.requiresReconsent}
        <div class="mt-4">
            <Alert type="warning" testId="auto-recharge-reconsent-banner">
                チケット単価の改定により、自動購入の上限額が変わりました。内容を確認して再度同意するまで、自動購入は行われません。
            </Alert>
        </div>
    {/if}

    {#if autoRecharge.disabledReason === "payment_failures"}
        <div class="mt-4">
            <Alert type="danger" testId="auto-recharge-failure-banner">
                決済が続けて失敗したため、オートリチャージを自動停止しました。カード情報を更新のうえ、再度有効にしてください。
            </Alert>
        </div>
    {/if}

    <!-- カード状態 (未登録 CTA / 処理中 / 登録済み表示)。設定入力はカード有無に関わらず常時
         表示する — 「開始残高・補充枚数が見えない」を防ぐ。有効化だけカード登録後 (fail-closed)。 -->
    {#if !autoRecharge.hasPaymentMethod}
        {#if autoRecharge.setupPending}
            <p class="mt-4 text-body text-text-secondary" data-testid="auto-recharge-setup-pending">
                {autoRecharge.pendingAutoEnable
                    ? "お支払い情報を処理しています。反映後、オートリチャージは自動で有効になります (同意済み)。"
                    : "カード登録を処理しています。完了すると自動で表示が切り替わります。"}
            </p>
        {:else}
            <p class="mt-4 text-body text-text-secondary" data-testid="auto-recharge-no-pm">
                {autoRecharge.pendingAutoEnable
                    ? "カード登録が完了すると、同意済みの内容でオートリチャージが自動で有効になります。"
                    : "オートリチャージには、自動購入に使うカードの登録が必要です。開始残高・補充枚数はカード登録前でも設定・保存できます。"}
            </p>
            {#if autoRecharge.canManage}
                <div class="mt-3">
                    <Button
                        variant="primary"
                        loading={submitting}
                        onclick={handleStartSetup}
                        testId="auto-recharge-setup"
                    >
                        <CreditCard class="h-4 w-4" aria-hidden="true" />
                        カードを登録する
                    </Button>
                </div>
            {/if}
        {/if}
    {:else}
        <div
            class="mt-4 flex items-center gap-2 text-body text-text-secondary"
            data-testid="auto-recharge-pm"
        >
            <CreditCard class="h-4 w-4 shrink-0" aria-hidden="true" />
            <span>
                お支払いカード: {autoRecharge.paymentMethodBrand ?? "カード"}
                {#if autoRecharge.paymentMethodLast4}
                    •••• {autoRecharge.paymentMethodLast4}
                {/if}
            </span>
        </div>
    {/if}

    <div class="mt-4 grid gap-4 md:grid-cols-2">
        <FormField label="リチャージ開始残高 (残りがこの枚数を下回ったら購入)" id="auto-recharge-threshold">
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="number"
                    min="0"
                    step="1"
                    value={thresholdText}
                    error={invalid}
                    aria-describedby={describedBy}
                    readonly={!autoRecharge.canManage}
                    testId="auto-recharge-threshold-input"
                    oninput={(e: Event) => {
                        const t = e.currentTarget;
                        if (t instanceof HTMLInputElement) thresholdText = t.value;
                    }}
                />
            {/snippet}
        </FormField>
        <FormField label="リチャージ後の残高 (この枚数まで補充)" id="auto-recharge-max">
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="number"
                    min={autoRecharge.minCount}
                    max={autoRecharge.maxCountLimit}
                    step="1"
                    value={maxText}
                    error={invalid}
                    aria-describedby={describedBy}
                    readonly={!autoRecharge.canManage}
                    testId="auto-recharge-max-input"
                    oninput={(e: Event) => {
                        const t = e.currentTarget;
                        if (t instanceof HTMLInputElement) maxText = t.value;
                    }}
                />
            {/snippet}
        </FormField>
    </div>

    {#if maxChargeAmount !== null}
        <p class="mt-2 text-body text-text-secondary" data-testid="auto-recharge-max-amount">
            1 回の自動購入の上限額: ¥{formatYen(maxChargeAmount)} (税込・1 枚あたり ¥{formatYen(
                appliedUnit,
            )})
        </p>
    {/if}

    {#if inputError !== null}
        <p
            class="mt-2 text-caption text-danger"
            aria-live="polite"
            data-testid="auto-recharge-range-error"
        >
            {inputError}
        </p>
    {/if}

    {#if showConsent}
        <div class="mt-4">
            <Alert type="info" title="自動購入への同意" testId="auto-recharge-consent">
                <ul class="flex flex-col gap-1">
                    {#each consentLines as line (line)}
                        <li>{line}</li>
                    {/each}
                </ul>
                {#snippet action()}
                    <div class="flex flex-wrap gap-3">
                        <Button
                            variant="primary"
                            loading={submitting}
                            onclick={confirmEnable}
                            testId="auto-recharge-consent-confirm"
                        >
                            同意して有効にする
                        </Button>
                        <Button
                            variant="ghost"
                            onclick={() => {
                                showConsent = false;
                            }}
                            testId="auto-recharge-consent-cancel"
                        >
                            今は有効にしない
                        </Button>
                    </div>
                {/snippet}
            </Alert>
        </div>
    {/if}

    {#if autoRecharge.canManage}
        <div class="mt-4 flex flex-wrap gap-3">
            {#if autoRecharge.enabled}
                <Button
                    variant="primary"
                    loading={submitting}
                    onclick={handleUpdate}
                    testId="auto-recharge-update"
                >
                    設定を更新する
                </Button>
                <Button
                    variant="ghost"
                    loading={submitting}
                    onclick={handleDisable}
                    testId="auto-recharge-disable"
                >
                    停止する
                </Button>
            {:else if autoRecharge.hasPaymentMethod}
                <Button
                    variant="primary"
                    loading={submitting}
                    onclick={openConsent}
                    testId="auto-recharge-enable"
                >
                    <BatteryCharging class="h-4 w-4" aria-hidden="true" />
                    有効にする
                </Button>
            {:else}
                <!-- カード未登録: 有効化は出さず (fail-closed)、設定値の保存だけ許可する -->
                <Button
                    variant="ghost"
                    loading={submitting}
                    onclick={handleSaveDraft}
                    testId="auto-recharge-save-draft"
                >
                    設定を保存する
                </Button>
            {/if}
        </div>
    {:else}
        <p class="mt-4 text-caption text-text-secondary" data-testid="auto-recharge-readonly">
            オートリチャージの設定には組織の管理者権限が必要です。
        </p>
    {/if}

    {#if serverError !== null}
        <p
            class="mt-2 text-caption text-danger"
            aria-live="polite"
            data-testid="auto-recharge-server-error"
        >
            <TriangleAlert class="inline h-4 w-4" aria-hidden="true" />
            {serverError}
        </p>
    {/if}

    {#if autoRecharge.enabled}
        <p class="mt-3 text-caption text-text-secondary" data-testid="auto-recharge-status">
            オートリチャージが有効です。残高が {autoRecharge.thresholdCount} 枚を下回ったら、不足分をまとめて自動購入し、{autoRecharge.maxCount}
            枚まで補充します。
        </p>
    {/if}
</Card>
