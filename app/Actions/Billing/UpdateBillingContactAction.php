<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\DataTransferObjects\Billing\UpdateBillingContactData;
use App\Models\Organization;
use App\Services\Billing\BillingCustomerSynchronizer;
use Illuminate\Support\Facades\DB;

/**
 * P9: 請求先メール / 宛名を更新し、**email 変更時のみ** Stripe 同期を発火する Action。
 *
 * 不変条件: 同期は BillingCustomerSynchronizer 経由 (BillingSyncDispatchInvariantTest) /
 * transaction 内 afterCommit / email dirty 時のみ同期 /
 * billing_contact_name は DB 保存のみで Stripe へは送らない。
 */
final class UpdateBillingContactAction
{
    public function __construct(
        private readonly BillingCustomerSynchronizer $synchronizer,
    ) {}

    public function execute(Organization $organization, UpdateBillingContactData $data): void
    {
        DB::transaction(function () use ($organization, $data): void {
            // 両列とも $fillable 外 (PII / 状態キー) のため明示代入する。
            $organization->billing_contact_email = $data->email;
            $organization->billing_contact_name = $data->name;

            // dirty 判定は save() 前に評価する (save 後は false になる)。
            // billing_contact_name は Stripe へ送らないため同期トリガに含めない。
            $emailChanged = $organization->isDirty('billing_contact_email');

            $organization->save();

            if ($emailChanged) {
                $this->synchronizer->dispatchFor($organization);
            }
        });
    }
}
