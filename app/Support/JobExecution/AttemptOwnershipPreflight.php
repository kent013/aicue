<?php

declare(strict_types=1);

namespace App\Support\JobExecution;

use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Enums\Security\ExternalCallKind;
use App\Models\Billing\TicketAutoRechargeAttempt;
use Illuminate\Support\Facades\Log;

/**
 * auto-recharge attempt の所有権再検証 (preflight suppression。裁定 AG-082 標準形 (2))。
 *
 * ★ **なぜ独立クラスなのか**:
 *   Manual の 2 パイプラインは「外部呼び出しが複数回連続する」構造なので、
 *   既存 fake のフック (`ThrowingPromptFake::$onAttempt` / `FakeRenderComposer::$duringCompose`)
 *   で **preflight の配置そのものを behavioral に赤化できる**。
 *   一方 Billing は「冒頭の Pending guard → create → attach → pay」という直列で、
 *   guard と各 preflight の**間に注入点が 1 つも無い**ため、
 *   preflight 呼び出しを削除しても既存 fake では赤化しない。
 *   そこで preflight だけを差し替え可能な collaborator として切り出す
 *   (本番コードにテスト専用 closure を足さないため)。
 *   Manual 側は同じ理由が無いので**この形にしない** (利益の無い churn を作らない。
 *   AGENTS.md 思考原則 2)。
 *
 * ★ **非 final** にしてあるのはテスト側の競合注入シームが override するためである
 *   (`App\Services\Render\RenderObjectStorage` と同じ作法。interface は新設しない)。
 *   ただしシームは**判定を差し替えない** — checkpoint 直前に attempt 行を terminal 化して
 *   `parent::stillPending()` へ委譲するだけである。
 *   したがって本メソッドの refresh / status 判定 / ログはテストでも常に本実装が走る。
 */
class AttemptOwnershipPreflight
{
    /**
     * Stripe 呼び出しの直前に attempt の所有権 (= pending) を再検証する。
     *
     * @return bool 送信してよいか (false = 所有権喪失 → 呼び出し側が中断する)
     */
    public function stillPending(TicketAutoRechargeAttempt $attempt, ExternalCallKind $call): bool
    {
        $attempt->refresh();
        if ($attempt->status === AutoRechargeAttemptStatus::Pending) {
            return true; // アーリーリターン (正常系)
        }

        // Manual ドメインと**同じ必須キー集合**で観測する (集計の語彙を 1 本に保つ。
        // JobOwnershipLostException::logContext() と必須 7 キーが一致する。
        // Billing 固有の追加キーは PII-free な attempt_ulid の 1 本だけ)。
        Log::warning('auto-recharge: 所有権を失ったため Stripe 呼び出しを中止しました', [
            'event' => ExternalCallKind::LOG_EVENT,
            'job_type' => TicketAutoRechargeAttempt::class,
            'job_id' => $attempt->id,
            'expected_status' => AutoRechargeAttemptStatus::Pending->value,
            'actual_status' => $attempt->status->value,
            'stage' => 'execute_attempt',
            'external_call' => $call->value,
            'attempt_ulid' => $attempt->attempt_ulid,
        ]);

        return false;
    }
}
