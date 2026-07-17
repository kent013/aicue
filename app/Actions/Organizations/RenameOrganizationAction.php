<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Services\Billing\BillingCustomerSynchronizer;
use Illuminate\Support\Facades\DB;

/**
 * 組織名のリネームを単一 transaction で行い、name 変更時に Stripe customer 同期を発火する Action。
 *
 * 旧 `OrganizationController::update` の内部処理を抽出したもの。外部挙動 (route / redirect /
 * DB 結果) は不変で、controller 側は transaction を張らず本 Action に委譲する (二重 transaction 回避、IV-3)。
 */
final class RenameOrganizationAction
{
    public function __construct(
        private readonly BillingCustomerSynchronizer $synchronizer,
    ) {}

    public function execute(Organization $organization, string $name): void
    {
        DB::transaction(function () use ($organization, $name): void {
            $organization->fill(['name' => $name]);
            // IV-5: name が実際に変化したときのみ同期 (stripeName は org name を返すため)。
            $nameChanged = $organization->isDirty('name');
            $organization->save();

            if ($nameChanged) {
                $this->synchronizer->dispatchFor($organization);
            }
        });
    }
}
