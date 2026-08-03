<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\Billing\TicketAutoRecharge;
use App\Models\Organization;
use App\Services\Billing\AutoRechargeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * P8a: チケット消費 (reserve) 後の残高閾値判定 → attempt 起票の薄い箱。
 *
 * 判定は Job 側に完全委譲 (reserve hot path で閾値を見ない)。**enabled 設定の存在確認で
 * 早期 return する** = opt-in 未設定の組織では何も起きない (既定 off の回帰点)。
 * 重複 dispatch は maybeCreateAttempt の pending 検査 / DB partial unique が吸収する。
 *
 * $tries = 1: 自動リトライしない (取りこぼしはリコンサイル (v) の管轄 — 二重課金面の安全側)。
 */
final class AutoRechargeTriggerJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 30;

    public function __construct(public readonly int $organizationId) {}

    public function uniqueId(): string
    {
        return (string) $this->organizationId;
    }

    public function handle(AutoRechargeService $autoRecharge): void
    {
        // enabled 設定がない org は即 return (opt-in / 既定 off のガード)。
        $configured = TicketAutoRecharge::query()
            ->where('organization_id', $this->organizationId)
            ->where('enabled', true)
            ->exists();
        if (! $configured) {
            return;
        }

        $organization = Organization::query()->find($this->organizationId);
        if (! $organization instanceof Organization) {
            return;
        }

        $attempt = $autoRecharge->maybeCreateAttempt($organization);
        if ($attempt !== null) {
            ExecuteAutoRechargeAttemptJob::dispatch($attempt->id);
        }
    }
}
