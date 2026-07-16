<script lang="ts">
    import { page as inertiaPage, router } from "@inertiajs/svelte";
    import { CircleCheck, ShoppingCart, Ticket } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { PurchaseTicketsPageProps } from "@/types/billing";

    /**
     * チケット購入画面 (current org スコープ)。
     * - 枚数入力 → 傾斜表 (props 単一真実源) から適用単価・総額を即時表示
     * - 購入 POST は count + attempt_token のみ (金額はサーバが権威)。成功時はサーバの
     *   Inertia::location が Stripe Checkout へ full page redirect する
     * - 送信ボタンは disabled にしない (不正値は押下時にエラー表示 + サーバ validation の二重防御)
     * - canManage=false のメンバーには購入依頼の案内を表示 (残高・料金表は表示 = 透明性維持)
     */
    interface Props {
        page: PurchaseTicketsPageProps;
    }

    let { page }: Props = $props();

    const shared = $derived(inertiaPage.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");
    // flash (error/info) は AppLayout の flash-to-toast が表示する (インライン二重表示しない)
    const serverErrors = $derived((inertiaPage.props.errors ?? {}) as Record<string, string>);

    // props から一度だけ seed する (以後はユーザー入力が真実)
    // svelte-ignore state_referenced_locally
    let countText = $state<string | number>(String(page.defaultCount));
    let submitting = $state(false);
    let clientError = $state<string | null>(null);

    // 生入力を整数として厳格に解釈する (clamp / floor の暗黙補正をしない)。
    // input[type=number] の bind:value は number を返すことがあるため String 経由で正規化する
    const parsedCount = $derived.by<number | null>(() => {
        const trimmed = String(countText).trim();
        if (!/^\d+$/.test(trimmed)) return null;
        const n = Number(trimmed);
        return Number.isSafeInteger(n) ? n : null;
    });

    const isValidCount = $derived(
        parsedCount !== null && parsedCount >= page.minCount && parsedCount <= page.maxCount,
    );

    // clientError は「購入枚数の範囲バリデーション」専用の transient state。押下時にのみ設定され、
    // 値が有効へ復帰した時点で自動解消する (「押下時にエラー表示」契約は維持: 無効のままなら残す)。
    // serverErrors (full POST 往復由来) は本 effect の対象外で別経路。
    // ※不変条件: 将来 clientError に別種のメッセージを載せる場合はこのクリア条件の再検討が必要。
    // clientError の有無も条件に含めることで不要な代入を避け、意図を明確化する。
    $effect(() => {
        if (clientError !== null && isValidCount) {
            clientError = null;
        }
    });

    // 適用単価: tiers (minCount 昇順) から minCount <= count の最大段を選ぶ
    const appliedUnit = $derived.by<number | null>(() => {
        if (parsedCount === null) return null;
        let unit: number | null = null;
        for (const tier of page.tiers) {
            if (parsedCount >= tier.minCount) unit = tier.unitAmount;
        }
        return unit;
    });

    // 合計は妥当時のみ金額表示 (範囲外は — 表示で誤認を防ぐ)
    const totalAmount = $derived(
        isValidCount && parsedCount !== null && appliedUnit !== null
            ? parsedCount * appliedUnit
            : null,
    );

    const formatYen = (v: number): string => new Intl.NumberFormat("ja-JP").format(v);

    // 傾斜表の帯表示 (Pricing と同じ変換規則。表示都合が異なるため molecule 共有はしない)
    const tierRows = $derived(
        page.tiers.map((tier, i) => {
            const next = page.tiers[i + 1];
            return {
                label: next ? `${tier.minCount}〜${next.minCount - 1} 枚` : `${tier.minCount} 枚以上`,
                unitAmount: tier.unitAmount,
            };
        }),
    );

    function submit(): void {
        if (submitting) return; // 多重送信ガード (disabled にはしない)
        clientError = null;
        if (!isValidCount || parsedCount === null) {
            clientError = `購入枚数は ${page.minCount}〜${page.maxCount} の整数で入力してください`;
            return;
        }
        router.post(
            "/purchase-tickets/checkout",
            { count: parsedCount, attempt_token: page.attemptToken },
            {
                onStart: () => {
                    submitting = true;
                },
                onFinish: () => {
                    submitting = false;
                },
            },
        );
    }
</script>

<AppLayout {appName}>
    <PageContent maxWidth="3xl">
        <h1 class="text-h2">チケットを購入</h1>
        <p class="mt-1 text-caption text-text-secondary">
            チケットは AI 解析・動画レンダに使います。まとめ買いで 1 枚あたりの料金が下がります。
        </p>

        <div class="mt-6 flex flex-col gap-6">
            {#if page.purchased}
                <Alert type="success" title="ご購入ありがとうございます" testId="purchase-success-banner">
                    決済の確認後、残高に反映されます (通常数秒〜数分)。反映はページの再読み込みでご確認いただけます。
                </Alert>
            {/if}
            <Card padding="lg" testId="purchase-balance">
                <div class="flex items-center gap-3">
                    <Ticket class="size-5 text-primary" aria-hidden="true" />
                    <div>
                        <h2 class="text-h3">現在の残高</h2>
                        <p class="mt-1 text-body" data-testid="purchase-balance-count">
                            {page.balance.toLocaleString("ja-JP")} 枚
                        </p>
                    </div>
                </div>
            </Card>

            {#if page.canManage}
                <Card padding="lg" testId="purchase-form">
                    <h2 class="text-h3">購入枚数</h2>
                    <div class="mt-4 flex max-w-xs flex-col gap-2">
                        <FormField
                            label={`枚数 (${page.minCount}〜${page.maxCount})`}
                            id="ticket-count"
                            error={clientError ?? serverErrors.count ?? serverErrors.attempt_token ?? null}
                        >
                            {#snippet children({ id, describedBy, invalid })}
                                <Input
                                    {id}
                                    type="number"
                                    bind:value={countText}
                                    error={invalid}
                                    aria-describedby={describedBy}
                                    min={page.minCount}
                                    max={page.maxCount}
                                    testId="ticket-count-input"
                                />
                            {/snippet}
                        </FormField>
                    </div>

                    <p class="mt-4 text-body" data-testid="purchase-total">
                        {#if totalAmount !== null && appliedUnit !== null}
                            単価 ¥{formatYen(appliedUnit)} × {parsedCount} 枚 = 合計 ¥{formatYen(
                                totalAmount,
                            )}
                        {:else}
                            合計 —
                        {/if}
                    </p>

                    <div class="mt-6">
                        <Button onclick={submit} loading={submitting} testId="purchase-submit">
                            <ShoppingCart class="size-4" aria-hidden="true" />
                            購入手続きへ (Stripe)
                        </Button>
                    </div>
                    <p class="mt-2 text-caption text-text-secondary">
                        Stripe の決済画面に移動します。決済確認後にチケットが付与されます。
                    </p>
                </Card>
            {:else}
                <Alert type="info" testId="purchase-role-note">
                    チケットの購入は組織のオーナーまたは管理者が行えます。管理者に購入を依頼してください。
                </Alert>
            {/if}

            <section>
                <h2 class="text-h3">チケット料金</h2>
                {#if tierRows.length > 0}
                    <ul
                        class="mt-3 rounded-lg border border-border bg-surface px-6 py-2 text-body"
                        data-testid="purchase-tier-table"
                    >
                        {#each tierRows as row (row.label)}
                            <li class="flex justify-between border-b border-border py-2 last:border-b-0">
                                <span class="text-text-secondary">{row.label}</span>
                                <span class="text-text">¥{formatYen(row.unitAmount)} ／ 枚</span>
                            </li>
                        {/each}
                    </ul>
                {/if}
                <p class="mt-2 inline-flex items-center gap-1 text-caption text-text-secondary">
                    <CircleCheck class="size-3.5" aria-hidden="true" />
                    購入したチケットに有効期限はありません。
                </p>
            </section>
        </div>
    </PageContent>
</AppLayout>
