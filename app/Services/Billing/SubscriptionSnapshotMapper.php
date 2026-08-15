<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Carbon\CarbonImmutable;

/**
 * Stripe の subscription オブジェクト (連想配列) → SubscriptionSnapshot の **唯一の写像**。
 *
 * webhook (payload の data.object) と日次突き合わせ (SDK オブジェクトの toArray()) が
 * 同じ規則で読むことを構造で保証する (写像が 2 つあると突き合わせ経路だけ別挙動になる)。
 * **Stripe SDK の型は受け取らない** (配列だけを知る)。
 */
final class SubscriptionSnapshotMapper
{
    /**
     * subscription オブジェクトから snapshot を組む。
     *
     * `id` が取れない応答は写像失敗として **null** を返す (呼び出し側が fail-closed に倒す)。
     *
     * @param  array<mixed>  $object  subscription オブジェクト (data.object 相当)
     */
    public function fromStripeSubscription(array $object): ?SubscriptionSnapshot
    {
        $stripeId = $this->stringAt($object, 'id');
        if ($stripeId === null || $stripeId === '') {
            return null;
        }

        return new SubscriptionSnapshot(
            stripeId: $stripeId,
            status: $this->stringAt($object, 'status') ?? 'incomplete',
            basePriceId: $this->stringAt($object, 'items.data.0.price.id'),
            baseQuantity: $this->intAt($object, 'items.data.0.quantity'),
            // 次回更新日時: 新 API (basil) は item 配下、旧 API は top-level に持つため両系を拾う。
            currentPeriodEnd: $this->timestampToCarbon(
                data_get($object, 'items.data.0.current_period_end')
                    ?? data_get($object, 'current_period_end'),
            ),
            trialEndsAt: $this->timestampToCarbon(data_get($object, 'trial_end')),
            endsAt: $this->timestampToCarbon(
                data_get($object, 'ended_at') ?? data_get($object, 'cancel_at'),
            ),
        );
    }

    /**
     * 決済手段の観測 (三値)。**true と「観測できなかった」を潰さない**。
     *  - default_payment_method / default_source のどちらかから id が取れた → true
     *  - どちらも空 → null (顧客既定を使う契約もあるため false と断定しない)
     *
     * @param  array<mixed>  $object
     */
    public function observePaymentMethod(array $object): ?bool
    {
        $observed = $this->resolveStripeIdField(data_get($object, 'default_payment_method')) !== null
            || $this->resolveStripeIdField(data_get($object, 'default_source')) !== null;

        return $observed ? true : null;
    }

    /**
     * Stripe の id フィールド (string id または expanded object) から id を取り出す。
     */
    private function resolveStripeIdField(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value !== '' ? $value : null;
        }
        if (is_array($value)) {
            $id = $value['id'] ?? null;

            return is_string($id) && $id !== '' ? $id : null;
        }

        return null;
    }

    /** Stripe の epoch 秒を CarbonImmutable にする (非 int / 非正数は null)。 */
    private function timestampToCarbon(mixed $value): ?CarbonImmutable
    {
        return is_int($value) && $value > 0 ? CarbonImmutable::createFromTimestamp($value) : null;
    }

    /** @param array<mixed> $object */
    private function stringAt(array $object, string $path): ?string
    {
        $value = data_get($object, $path);

        return is_string($value) ? $value : null;
    }

    /** @param array<mixed> $object */
    private function intAt(array $object, string $path): ?int
    {
        $value = data_get($object, $path);

        return is_int($value) ? $value : null;
    }
}
