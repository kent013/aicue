<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\DataTransferObjects\Marketing\PricingPlanDto;
use App\Enums\Billing\OnboardingBillingState;

/**
 * プラン比較ページ (/billing/plans) の Inertia page prop。
 *
 * プラン台帳 → DTO の mapper は公開料金表と共有する (PricingService::listPublicPlans)。
 * currentPlanCode は **表示専用** の解決結果であり gate 判定には使わない
 * (判定は BillingAccess::state() 一本)。
 *
 * TS 側は resources/js/types/billing.ts の BillingPlansPageProps と exact 対で保守する。
 *
 * @phpstan-import-type PricingPlanShape from PricingPlanDto
 *
 * @phpstan-type BillingPlansPageShape array{
 *   plans: list<PricingPlanShape>,
 *   currentPlanCode: string|null,
 *   billingState: string,
 *   canManage: bool,
 *   subscriptionAttemptToken: string,
 *   hasChangeableSubscription: bool,
 *   planChangeToken: string,
 *   planChangeExpectedPlanCode: string|null
 * }
 */
final readonly class BillingPlansPageDto
{
    /**
     * @param  list<PricingPlanDto>  $plans
     */
    public function __construct(
        public array $plans,
        public ?string $currentPlanCode,
        public OnboardingBillingState $billingState,
        public bool $canManage,
        /**
         * P9: 契約 checkout 開始 POST の冪等 token (画面 render ごとに固定される ULID)。
         * チケット購入の `ticketAttemptToken` / カード登録の `autoRechargeSetupToken` とは
         * **別 key 空間** (混ぜない)。**既定値を持たない** — 渡し忘れると空 token が front へ出て
         * POST が 422 になる silent failure を作らないため。
         */
        public string $subscriptionAttemptToken,
        /**
         * 有効な subscription を持つか (= `startCheckout` が拒否し `changePlan` が受ける側)。
         * 判定は `startCheckoutLocked` 段 1 と**同一の述語** (`Subscription::valid()`) を使う
         * ため、UI がどちらの経路を選んでも「押したら循環エラー」にならない。
         */
        public bool $hasChangeableSubscription,
        /**
         * プラン変更 POST の冪等 token (画面 render ごとに固定される ULID)。
         * `subscriptionAttemptToken` (契約 checkout) とは **別 key 空間**で混ぜない。
         */
        public string $planChangeToken,
        /**
         * 楽観的競合制御 (stale UI 検知) の期待値 = **`organizations.plan_code` そのもの**。
         *
         * 表示用の `currentPlanCode` とは**別物**なので混ぜない: 表示用は
         * `BillingController::resolveCurrentPlanCode()` の projection で、ActiveFreePlan の
         * org では `free_plan_code` を返す。`hasChangeableSubscription`
         * (= `Subscription::valid()`) と ActiveFreePlan は同時に成立しうる
         * (例: `canceled` かつ期末まで有効な grace period 契約) ため、表示値を競合制御に
         * 使うと恒常 422 (stale) の詰みになる。
         */
        public ?string $planChangeExpectedPlanCode,
    ) {}

    /**
     * @return BillingPlansPageShape
     */
    public function toArray(): array
    {
        return [
            'plans' => array_map(
                static fn (PricingPlanDto $plan): array => $plan->toArray(),
                $this->plans,
            ),
            'currentPlanCode' => $this->currentPlanCode,
            'billingState' => $this->billingState->value,
            'canManage' => $this->canManage,
            'subscriptionAttemptToken' => $this->subscriptionAttemptToken,
            'hasChangeableSubscription' => $this->hasChangeableSubscription,
            'planChangeToken' => $this->planChangeToken,
            'planChangeExpectedPlanCode' => $this->planChangeExpectedPlanCode,
        ];
    }
}
