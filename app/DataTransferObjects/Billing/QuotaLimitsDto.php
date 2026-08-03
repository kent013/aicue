<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * 課金ダッシュボードに出す現行 quota 上限 (override 反映済み)。
 *
 * 値の出典は QuotaService::limits() (プラン既定 + organization override のマージ結果)。
 * limits に key が無い = 無制限 = null。maxStorageGb は GiB 換算の表示値で、換算規則は
 * PricingService::storageGb と同一 (intdiv(bytes, 1024**3) 切り捨て)。
 *
 * 使用量 (current) は AI-CUE に横断集計経路が無いため持たない (上限の提示のみ)。
 *
 * @phpstan-type QuotaLimitsShape array{
 *   maxProjects: int|null,
 *   maxMembers: int|null,
 *   maxStorageGb: int|null
 * }
 */
final readonly class QuotaLimitsDto
{
    public function __construct(
        public ?int $maxProjects,
        public ?int $maxMembers,
        public ?int $maxStorageGb,
    ) {}

    /**
     * QuotaService::limits() の結果から組み立てる。
     *
     * @param  array<string, int>  $limits
     */
    public static function fromLimits(array $limits): self
    {
        $bytes = $limits['max_storage_bytes'] ?? null;

        return new self(
            maxProjects: $limits['max_projects'] ?? null,
            maxMembers: $limits['max_members'] ?? null,
            maxStorageGb: $bytes === null ? null : intdiv($bytes, 1024 ** 3),
        );
    }

    /**
     * @return QuotaLimitsShape
     */
    public function toArray(): array
    {
        return [
            'maxProjects' => $this->maxProjects,
            'maxMembers' => $this->maxMembers,
            'maxStorageGb' => $this->maxStorageGb,
        ];
    }
}
