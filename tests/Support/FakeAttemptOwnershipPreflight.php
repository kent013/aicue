<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Enums\Security\ExternalCallKind;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Support\JobExecution\AttemptOwnershipPreflight;
use Carbon\CarbonImmutable;

/**
 * `AttemptOwnershipPreflight` の **競合注入シーム** (verdict の差し替えではない)。
 *
 * Billing の実行経路は「冒頭の Pending guard → invoice 作成 → attach → pay」という直列で、
 * guard と各 preflight の**間に注入点が 1 つも無い**。そのため
 * 「checkpoint の**直前**に停止側 / 他ワーカーが terminal 化した」窓を作れるのは
 * preflight 自身だけである。本クラスはその窓を開けることだけを責務にし、
 * **判定は本番実装 (`parent::stillPending()`) に委譲する**。
 *
 * ★この性質 (verdict を差し替えない) を壊さないこと。fake で判定そのものを差し替えると、
 *   refresh / status 判定 / 所有権喪失ログがテストで一度も実行されなくなり、
 *   テストが実装から乖離する。
 */
final class FakeAttemptOwnershipPreflight extends AttemptOwnershipPreflight
{
    /**
     * この checkpoint に到達したら attempt 行を terminal 化する。
     *
     * @var list<ExternalCallKind>
     */
    public array $terminalizeAt = [];

    /** terminal 化させる先 (canceled / failed / paid を切り替えて後始末の分岐を固定する) */
    public AutoRechargeAttemptStatus $terminalStatus = AutoRechargeAttemptStatus::Canceled;

    /**
     * 到達した checkpoint の記録 (= 配置の観測)。
     *
     * @var list<string>
     */
    public array $calls = [];

    public function stillPending(TicketAutoRechargeAttempt $attempt, ExternalCallKind $call): bool
    {
        $this->calls[] = $call->value;

        if (in_array($call, $this->terminalizeAt, true)) {
            // ★ 「checkpoint の**直前**に停止側 / 他ワーカーが terminal 化した」窓を作る。
            TicketAutoRechargeAttempt::query()
                ->whereKey($attempt->id)
                ->where('status', AutoRechargeAttemptStatus::Pending->value)
                ->update([
                    'status' => $this->terminalStatus->value,
                    'updated_at' => CarbonImmutable::now(),
                ]);
        }

        // ★ **判定は fake しない**。refresh / status 判定 / 所有権喪失ログは本番実装が実行する。
        //   fake の責務は「窓を開けること」だけである。
        return parent::stillPending($attempt, $call);
    }
}
