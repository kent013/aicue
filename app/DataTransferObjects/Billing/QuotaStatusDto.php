<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Enums\QuotaKey;

/**
 * 課金ダッシュボードに出す現行 quota の状態 (上限 + 使用量 + 超過次元)。
 *
 * 上限の出典は QuotaService::limits() (プラン既定 + organization override のマージ結果)。
 * limits に key が無い = 無制限 = null。maxStorageGb は GiB 換算の表示値で、換算規則は
 * PricingService::storageGb と同一 (intdiv(bytes, 1024**3) 切り捨て)。
 *
 * **超過 (exceededLabels) は「使用量 > 上限」の厳密超過のみ**を指す。
 * 「上限ちょうど」(1/1 等) は plan の設計どおりの正常状態なので警告に含めない
 * (>= にすると max_projects=1 の starter / personal の全組織にプロジェクトを 1 つ作った
 *  時点から恒常警告が出て、本当の超過が埋もれる)。「上限に達した」ことへの気づきは
 * 警告ではなく **使用量 / 上限の併記表示**が担う。
 * 判定は**バイト等の生の単位**で行い、表示用の GiB 切り捨て値では判定しない。
 *
 * メンバー数は**上限のみ**を持つ (使用量も超過も出さない): max_members を
 * QuotaService::check する呼び出し元は存在せず実効的に未強制のため、
 * 「超過すると止まる」と読める表示をしない (App\Enums\QuotaKey の docblock 参照)。
 *
 * @phpstan-type QuotaStatusShape array{
 *   maxProjects: int|null,
 *   maxMembers: int|null,
 *   maxStorageGb: int|null,
 *   projectsUsed: int,
 *   storageUsedBytes: int,
 *   exceededLabels: list<string>
 * }
 */
final readonly class QuotaStatusDto
{
    /**
     * @param  list<string>  $exceededLabels  超過している次元の表示名 (QuotaKey::label())
     */
    public function __construct(
        public ?int $maxProjects,
        public ?int $maxMembers,
        public ?int $maxStorageGb,
        public int $projectsUsed,
        public int $storageUsedBytes,
        /** @var list<string> */
        public array $exceededLabels,
    ) {}

    /**
     * QuotaService::limits() の結果と実使用量から組み立てる。
     *
     * @param  array<string, int>  $limits
     */
    public static function build(array $limits, int $projectsUsed, int $storageUsedBytes): self
    {
        $projectLimit = $limits[QuotaKey::MaxProjects->value] ?? null;
        $storageLimit = $limits[QuotaKey::MaxStorageBytes->value] ?? null;

        // append のみで組み立てるため list<string> のまま (PHPStan が推論する)。
        // 将来 filter 等でキーが飛ぶ操作を挟むなら、その時点で array_values を足すこと。
        $exceeded = [];
        if ($projectLimit !== null && $projectsUsed > $projectLimit) {
            $exceeded[] = QuotaKey::MaxProjects->label();
        }
        if ($storageLimit !== null && $storageUsedBytes > $storageLimit) {
            $exceeded[] = QuotaKey::MaxStorageBytes->label();
        }

        return new self(
            maxProjects: $projectLimit,
            maxMembers: $limits[QuotaKey::MaxMembers->value] ?? null,
            maxStorageGb: $storageLimit === null ? null : intdiv($storageLimit, 1024 ** 3),
            projectsUsed: $projectsUsed,
            storageUsedBytes: $storageUsedBytes,
            exceededLabels: $exceeded,
        );
    }

    /**
     * @return QuotaStatusShape
     */
    public function toArray(): array
    {
        return [
            'maxProjects' => $this->maxProjects,
            'maxMembers' => $this->maxMembers,
            'maxStorageGb' => $this->maxStorageGb,
            'projectsUsed' => $this->projectsUsed,
            'storageUsedBytes' => $this->storageUsedBytes,
            'exceededLabels' => $this->exceededLabels,
        ];
    }
}
