<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\DataTransferObjects\Billing\RemoteSubscriptionState;
use App\Enums\Billing\SubscriptionSwapOutcome;
use App\Exceptions\Billing\PlanChangeFailedException;
use App\Exceptions\Billing\SubscriptionLookupFailedException;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Cashier;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;
use Stripe\StripeObject;
use Stripe\Subscription as StripeSubscription;
use Webmozart\Assert\Assert;

/**
 * StripeGatewayInterface の Cashier (Stripe SDK) 実装。
 * PortalConfigurationSpec は同一名前空間 (App\Services\Billing) のため use 不要。
 *
 * **final にしない**のは `stripe()` を test seam として持つため
 * (`tests/Feature/Billing/SubscriptionSwapGatewayTest.php` が subclass で差し替える)。
 * seam をリネームしたら同テストも同時に更新すること。
 */
class CashierStripeGateway implements StripeGatewayInterface
{
    public function __construct(
        private readonly SubscriptionSnapshotMapper $mapper,
    ) {}

    /**
     * Stripe クライアント取得の seam (テストで差し替えるためだけに切り出す)。
     * 実装は Cashier の既定クライアントをそのまま返す。
     */
    protected function stripe(): StripeClient
    {
        return Cashier::stripe();
    }

    public function swapSubscriptionPrices(
        Organization $organization,
        string $basePriceId,
        string $idempotencyKey,
    ): SubscriptionSwapOutcome {
        $subscription = $organization->subscription('default');
        Assert::isInstanceOf($subscription, Subscription::class, '契約が見つかりません');
        $stripeId = $subscription->stripe_id;
        Assert::stringNotEmpty($stripeId, 'Stripe subscription id がありません');

        $stripe = $this->stripe();

        // SDK 例外を境界の外へ出さない (API 障害は PlanChangeFailedException へ変換する)。
        try {
            // item id の解決と remote 現在 Price の照合を **同じ 1 回の read** で行う。
            $remote = $stripe->subscriptions->retrieve($stripeId, ['expand' => ['items.data']]);

            // AI-CUE の subscription は **base 1 item・quantity=1 固定** (席課金なし)。
            // 想定外の構成は触らずに fail-closed (多 item / 数量付き / 解決不能 item を
            // 無言で潰さない)。normalizeItems は解決できない item があれば throw する
            // (**skip しない** = 「正常 1 件 + 不正 1 件」を 1 件として通さない)。
            $items = $this->normalizeItems($stripeId, $remote);

            if (count($items) !== 1 || $items[0]['quantity'] !== 1) {
                throw PlanChangeFailedException::unexpectedShape(
                    $stripeId,
                    count($items),
                    $items[0]['quantity'] ?? null,
                );
            }

            $item = $items[0];
            if ($item['priceId'] === $basePriceId) {
                return SubscriptionSwapOutcome::AlreadyOnTargetPrice; // update を送らない
            }

            $stripe->subscriptions->update(
                $stripeId,
                $this->buildSwapPayload($item['id'], $basePriceId),
                ['idempotency_key' => $idempotencyKey],
            );

            return SubscriptionSwapOutcome::Applied;
        } catch (ApiErrorException $e) {
            // 想定された外部障害のみ変換する (実装バグは素通しして 500 = 調査対象)。
            throw PlanChangeFailedException::stripeApiError($stripeId, $e);
        }
    }

    /**
     * subscription update payload (pure)。
     *
     * invariant (gateway 単体テストで固定):
     * - **既存 item id を指定**して price を差し替える (id 無指定は item の二重化を招く)
     * - `proration_behavior = create_prorations` — 日割り明細を作り、**次回請求に反映**する
     *   (`always_invoice` にしない = 即時請求 → 与信失敗の状態遷移を呼び込まない)。
     *   **この方針は確定済み**。切り替えに必要な作業一式 (state 機械 / webhook / UI /
     *   ロールバック意味論) は `docs/architecture.md` の「契約中プランの変更」節を参照
     *   (ここに複製しない = 二重管理を作らない)
     * - `billing_cycle_anchor` / `trial_end` / `payment_behavior` は **送らない**
     *   (即時請求・trial 再開の誘発を構造的に避ける)
     *
     * @return array{
     *   items: array{array{id: string, price: string, quantity: int}},
     *   proration_behavior: 'create_prorations'
     * }
     */
    public function buildSwapPayload(string $itemId, string $basePriceId): array
    {
        Assert::stringNotEmpty($itemId);
        Assert::stringNotEmpty($basePriceId);

        return [
            'items' => [
                ['id' => $itemId, 'price' => $basePriceId, 'quantity' => 1],
            ],
            'proration_behavior' => 'create_prorations',
        ];
    }

    /**
     * remote subscription の items を {id, priceId, quantity} へ正規化する。
     * price は string id / expanded object のどちらも取り得るため両対応する
     * (`StripeWebhookProcessor::resolveStripeIdField` と同型の防御)。
     *
     * **解決できない item が 1 つでもあれば throw する** (skip しない)。skip すると
     * 「正常 1 件 + 解決不能 1 件」が正規化後 1 件になり、多 item 契約を更新してしまうため。
     * quantity 欠落も同様に想定外として扱う。
     *
     * @return list<array{id: string, priceId: string, quantity: int}>
     */
    private function normalizeItems(string $stripeSubscriptionId, StripeSubscription $remote): array
    {
        $normalized = [];
        $rawCount = 0;
        foreach ($remote->items->data as $item) {
            $rawCount++;
            // id / price は同じ helper で正規化する (空文字・未設定を null に落とす)。
            $itemId = $this->resolveStripeIdField($item->id);
            $priceId = $this->resolveStripeIdField($item->price);
            $quantity = $item->quantity;
            if ($itemId === null || $priceId === null || ! is_int($quantity)) {
                // 解決不能 item は「無い」ものにせず、その場で fail-closed に倒す。
                throw PlanChangeFailedException::unexpectedShape($stripeSubscriptionId, $rawCount, null);
            }
            $normalized[] = ['id' => $itemId, 'priceId' => $priceId, 'quantity' => $quantity];
        }

        return $normalized;
    }

    /** Stripe の id フィールド (string id または expanded object) から id を取り出す。 */
    private function resolveStripeIdField(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }
        if ($value instanceof StripeObject) {
            // `?? null` は __isset を先に通すため、未設定キーで SDK の logger を鳴らさない。
            $id = $value->id ?? null;

            return is_string($id) && $id !== '' ? $id : null;
        }

        return null;
    }

    public function createSubscriptionCheckout(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
    ): CreatedCheckoutSession {
        // Cashier の `newSubscription()->checkout()` は最終的に request options 無しで
        // `checkout->sessions->create()` を呼ぶため per-request idempotency key を伝播できない。
        // 冪等キーを Stripe Checkout 作成 API へ確実に渡すため SDK を直叩きする
        // (CashierTicketCheckoutGateway と同型)。
        $organization->createOrGetStripeCustomer();

        $session = $organization->stripe()->checkout->sessions->create(
            $this->buildSubscriptionSessionPayload($organization, $stripePriceId, $successUrl, $cancelUrl, $metadata),
            ['idempotency_key' => $idempotencyKey],
        );

        // hosted mode では url / expires_at が常に返る (欠落は SDK/設定異常として fail-fast)
        Assert::string($session->url, 'Checkout Session に URL がありません (ui_mode: hosted のみ対応)');
        Assert::integer($session->expires_at, 'Checkout Session に expires_at がありません');

        return new CreatedCheckoutSession(
            sessionId: $session->id,
            url: $session->url,
            expiresAt: CarbonImmutable::createFromTimestamp($session->expires_at),
        );
    }

    public function retrieveSubscriptionState(string $stripeSubscriptionId): ?RemoteSubscriptionState
    {
        Assert::stringNotEmpty($stripeSubscriptionId);

        try {
            $remote = $this->stripe()->subscriptions->retrieve(
                $stripeSubscriptionId,
                ['expand' => ['items.data']],
            );
        } catch (InvalidRequestException $e) {
            // resource_missing = Stripe 側に無い。API キーの環境取り違えでも同じ形になるため、
            // ここでは「無い」とだけ返し、状態変更するかどうかは呼び出し側が決める。
            if ($e->getStripeCode() === 'resource_missing') {
                return null;
            }

            throw new SubscriptionLookupFailedException('Stripe 契約の照会に失敗しました', previous: $e);
        } catch (ApiErrorException $e) {
            throw new SubscriptionLookupFailedException('Stripe 契約の照会に失敗しました', previous: $e);
        }

        // SDK 型はここで配列へ落とす (mapper へ SDK 型を漏らさない)。
        $object = $remote->toArray();
        $snapshot = $this->mapper->fromStripeSubscription($object);
        if ($snapshot === null) {
            // id が取れない応答は「確認できなかった」として扱う (状態を変える材料にしない)。
            throw new SubscriptionLookupFailedException('Stripe 契約の応答から契約 id を取得できません');
        }

        return new RemoteSubscriptionState(
            snapshot: $snapshot,
            hasPaymentMethod: $this->mapper->observePaymentMethod($object),
        );
    }

    public function expireCheckoutSession(string $stripeSessionId): string
    {
        // 決済主体は organization だが expire は session id 単独で完結する
        // (呼び出し側が自 org 行の session id のみ渡す契約)
        $session = Cashier::stripe()->checkout->sessions->expire($stripeSessionId);

        return is_string($session->status) ? $session->status : 'expired';
    }

    /**
     * subscription Checkout Session payload (pure)。
     *
     * invariant (gateway ユニットテストで固定):
     * - `subscription_data.metadata.{name,type} = 'default'` — Cashier の WebhookController が
     *   `subscriptions` 行を作る際に読むラベル。**落とすと課金成立なのに subscription 行が
     *   作られず** `BillingAccess::state()` が NoSubscription に落ちて締め出しが起きる。
     * - `subscription_data.payment_settings.save_default_payment_method = 'on_subscription'` —
     *   T1004 の PM 流用の第一候補 (`subscription.default_payment_method`) が埋まる前提。
     *
     * @param  array<string, string>  $metadata
     * @return array{
     *   mode: 'subscription',
     *   customer: string,
     *   line_items: array{array{price: string, quantity: int}},
     *   success_url: string,
     *   cancel_url: string,
     *   metadata: array<string, string>,
     *   subscription_data: array{
     *     metadata: array<string, string>,
     *     payment_settings: array{save_default_payment_method: 'on_subscription'}
     *   }
     * }
     */
    public function buildSubscriptionSessionPayload(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
    ): array {
        // createOrGetStripeCustomer() 後は必ず存在する (欠落は設定異常として fail-fast)
        $customerId = $organization->stripe_id;
        Assert::stringNotEmpty($customerId, 'Stripe customer 未作成の組織では Checkout を作れません');

        return [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [
                [
                    'price' => $stripePriceId,
                    'quantity' => 1,
                ],
            ],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
            'subscription_data' => [
                'metadata' => [
                    'name' => 'default',
                    'type' => 'default',
                ],
                'payment_settings' => [
                    'save_default_payment_method' => 'on_subscription',
                ],
            ],
        ];
    }

    public function createPortalSession(Organization $organization, string $returnUrl): ExternalBillingRedirect
    {
        // configuration id (billing:ensure-portal-configuration で生成) が設定されていれば
        // subscription_update 無効の spec 準拠 configuration で portal session を作る
        // (未設定なら Dashboard 既定 configuration。PortalConfigurationSpec 参照)
        return new ExternalBillingRedirect($organization->billingPortalUrl(
            $returnUrl,
            PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id')),
        ));
    }

    public function syncCustomerDetails(Organization $organization): void
    {
        // 実 Stripe では Cashier の Billable 同期をそのまま使う。stripe_id 未設定は no-op
        // (Cashier 側も customer 不在では更新しないが、呼び出し前提を実装側でも明示)。
        if ($organization->stripe_id === null) {
            return;
        }

        $organization->syncStripeCustomerDetails();
    }
}
