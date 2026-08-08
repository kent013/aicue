<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\Billing\TicketAutoRecharge;
use App\Models\Organization;
use App\Services\Billing\AutoRechargeService;
use Illuminate\Bus\Queueable;
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
 *
 * 【入口排他 (ShouldBeUnique) を持たない理由 — 契約の反転 (AG-114 確定 1 / T137)】
 * - 旧主張: `ShouldBeUnique` + `uniqueFor = 30` で同一 org の重複 dispatch を抑止する
 * - 旧目的: reserve のたびに trigger job が積まれるのを減らす
 * - 新主張: 入口排他を持たない。重複 dispatch は下流が no-op へ収束させる
 * - 新前提: 本 job は業務 tx の内側から dispatch される (AG-114 確定 1)
 * - 前提を守る機構: maybeCreateAttempt の organizations 行ロック + pending 存在検査 +
 *   `tar_attempts_org_pending_unique` (partial unique) + unique violation の no-op 化
 * - 反転根拠: `UniqueLock` は PendingDispatch の dispatch 呼び出し時に取得され、
 *   rollback 時の解放は afterCommit 経路でしか行われない。業務 tx の内側で dispatch すると
 *   rollback しても `uniqueFor` 秒の抑止が残り、**ネスト深さに依らず解消できない**。
 *   AGENTS.md ドメイン規約 6 のとおり入口排他は保証を担わないため、撤去して
 *   永続状態遷移へ責務を一本化する
 */
final class AutoRechargeTriggerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $organizationId) {}

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

        // 起票と ExecuteAutoRechargeAttemptJob の投入は maybeCreateAttempt の tx 内で完結する
        // (AG-114 確定 1。ここで dispatch すると二重投入になる)。
        $autoRecharge->maybeCreateAttempt($organization);
    }
}
