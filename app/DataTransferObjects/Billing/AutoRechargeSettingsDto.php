<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * P8a: オートリチャージ設定カード (AutoRechargeCard) 用の Inertia props DTO。
 *
 * subscription 有無に依存せず常に非 null で渡す (無料パーソナル含む全プランが対象)。
 * TS 側 `AutoRechargeProps` (resources/js/types/billing.ts) と厳密一致契約。
 *
 * @phpstan-import-type PurchaseTierShape from PurchaseTierDto
 *
 * @phpstan-type AutoRechargeShape array{
 *   enabled: bool,
 *   thresholdCount: int,
 *   maxCount: int,
 *   minCount: int,
 *   maxCountLimit: int,
 *   canManage: bool,
 *   hasPaymentMethod: bool,
 *   paymentMethodBrand: string|null,
 *   paymentMethodLast4: string|null,
 *   setupPending: bool,
 *   requiresReconsent: bool,
 *   pendingAutoEnable: bool,
 *   disabledReason: string|null,
 *   failureCount: int,
 *   consentVersion: string,
 *   baseUnitAmountJpy: int,
 *   tiers: list<PurchaseTierShape>
 * }
 */
final readonly class AutoRechargeSettingsDto
{
    /** @param list<PurchaseTierDto> $tiers */
    public function __construct(
        public bool $enabled,
        public int $thresholdCount,
        public int $maxCount,
        public int $minCount,
        public int $maxCountLimit,
        public bool $canManage,
        public bool $hasPaymentMethod,
        public ?string $paymentMethodBrand,
        public ?string $paymentMethodLast4,
        /** setup 完了 (30 分以内) だが PM snapshot 未反映 = 「カード登録処理中」表示。 */
        public bool $setupPending,
        /** 価格改定等で現行最大請求額が同意額を超過 = 再同意まで自動購入停止中。 */
        public bool $requiresReconsent,
        /** 有効な事前同意が待機中 (PM 未登録) = カード登録完了で自動有効化される。 */
        public bool $pendingAutoEnable,
        public ?string $disabledReason,
        public int $failureCount,
        /** 現行の同意文言バージョン (有効化 submit の hidden に載せる)。 */
        public string $consentVersion,
        public int $baseUnitAmountJpy,
        public array $tiers,
    ) {}

    /** @return AutoRechargeShape */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'thresholdCount' => $this->thresholdCount,
            'maxCount' => $this->maxCount,
            'minCount' => $this->minCount,
            'maxCountLimit' => $this->maxCountLimit,
            'canManage' => $this->canManage,
            'hasPaymentMethod' => $this->hasPaymentMethod,
            'paymentMethodBrand' => $this->paymentMethodBrand,
            'paymentMethodLast4' => $this->paymentMethodLast4,
            'setupPending' => $this->setupPending,
            'requiresReconsent' => $this->requiresReconsent,
            'pendingAutoEnable' => $this->pendingAutoEnable,
            'disabledReason' => $this->disabledReason,
            'failureCount' => $this->failureCount,
            'consentVersion' => $this->consentVersion,
            'baseUnitAmountJpy' => $this->baseUnitAmountJpy,
            'tiers' => array_map(
                static fn (PurchaseTierDto $t): array => $t->toArray(),
                $this->tiers,
            ),
        ];
    }
}
