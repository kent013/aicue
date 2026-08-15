<?php

declare(strict_types=1);

namespace App\Services\Billing\Contracts;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\DataTransferObjects\Billing\RemoteSubscriptionState;
use App\Enums\Billing\SubscriptionSwapOutcome;
use App\Exceptions\Billing\PlanChangeFailedException;
use App\Exceptions\Billing\SubscriptionLookupFailedException;
use App\Models\Organization;

/**
 * サブスクリプション系 Stripe 呼び出しの抽象
 * (実装: CashierStripeGateway。fake_externals 時は FakeStripeGateway を bind)。
 *
 * Stripe 呼び出しを本 interface に閉じ、Controller / Service は戻り値 DTO の URL へ
 * Inertia::location するのみ。チケット系は TicketCheckoutGateway が担う (境界を分ける)。
 */
interface StripeGatewayInterface
{
    /**
     * subscription (type=default) の hosted Checkout Session を作り snapshot を返す。
     *
     * 戻り値に session id を含むのは **webhook 照合の pin** に必須のため
     * (billing_checkout_sessions.stripe_session_id が真実源になる)。
     * $idempotencyKey は Stripe へそのまま渡す (`sub_start:{attemptToken}`)。
     *
     * @param  array<string, string>  $metadata  照合専用 (認可・org 解決には使わない)
     */
    public function createSubscriptionCheckout(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
        string $idempotencyKey,
    ): CreatedCheckoutSession;

    /**
     * 契約中 subscription の base Price を差し替える (プラン変更 = Stripe Subscription Update)。
     *
     * 実装は **remote の現在 Price と照合し、既に対象 Price なら update を送らない**
     * (`AlreadyOnTargetPrice`)。ローカル列 (`subscriptions.stripe_price` /
     * `organizations.plan_code`) は webhook 同期のためラグがあり判定に使えない。
     *
     * $idempotencyKey は Stripe へそのまま渡す (`change-plan:{token}:{planCode}`)。
     *
     * **Stripe SDK の object も例外も本 interface の外へ出さない**。API 障害
     * (`\Stripe\Exception\ApiErrorException`) と想定外の subscription 構成は、実装側で
     * `PlanChangeFailedException` (利用者向け文言 + 診断用 reason) に変換して throw する。
     *
     * ただし **前提違反 (呼び出し規約の破り) と実装バグは変換しない**:
     * 契約行の不在 (`Assert::isInstanceOf` → `InvalidArgumentException`) や `TypeError` は
     * fail-fast でそのまま外へ出す (呼び出し側 = Service が段 1 で契約の存在を保証済みのため、
     * ここに到達するのは実装不備)。
     *
     * @throws PlanChangeFailedException Stripe API 障害 / 想定外の subscription 構成
     */
    public function swapSubscriptionPrices(
        Organization $organization,
        string $basePriceId,
        string $idempotencyKey,
    ): SubscriptionSwapOutcome;

    /**
     * Stripe の契約 1 件を読み、突き合わせ用の観測結果を返す (日次リコンサイル専用の読み取り)。
     *
     * - 見つからない (404 / resource_missing) → **null** (状態を変えない材料として扱う)
     * - API 障害 → SubscriptionLookupFailedException (SDK 例外は外へ出さない)
     *
     * @throws SubscriptionLookupFailedException 照会に失敗したとき
     */
    public function retrieveSubscriptionState(string $stripeSubscriptionId): ?RemoteSubscriptionState;

    /**
     * Stripe 側 Checkout Session を expire する (別 plan の live pending 整理)。
     *
     * @return string expire 後の session status ('expired'|'complete'|...)
     */
    public function expireCheckoutSession(string $stripeSessionId): string;

    /**
     * Customer Portal セッションを作り遷移先を返す
     * (configuration は PortalConfigurationSpec 準拠。実装側で解決する)。
     */
    public function createPortalSession(Organization $organization, string $returnUrl): ExternalBillingRedirect;

    /**
     * 請求先連絡先 (name 等) を Stripe Customer に同期する。
     *
     * Cashier の Billable 同期メソッドを job から直接呼ぶと fake 環境 (bug-hunt / Browser) を
     * 素通りして実 Stripe API を叩く。同期も interface 境界を通すことで fake 可能にする。
     * `stripe_id` 未設定の組織は呼び出し側で skip 済の前提 (実装側でも no-op を許容)。
     */
    public function syncCustomerDetails(Organization $organization): void;
}
