<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\Enums\Billing\ScheduleSetupStatus;
use App\Models\Billing\Subscription;
use App\Services\Billing\StripeScheduleGateway;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Subscription Schedule の取りこぼし復旧 (2 工程の reconcile。daily)。
 *
 * schedule 作成は「from_subscription で create → 別 update で phases 設定」の 2 段 API call
 * のため、途中クラッシュで local と Stripe が食い違う。本コマンドが差分を回収する:
 *
 * - retryMissing: local に schedule_id が無い active 契約 → remote に schedule が残っていれば
 *   schedule_id を復元する (create 成功直後のクラッシュで local 記録が消えたケース)
 * - retryPartial: schedule_setup_status=Created (phases 未設定の部分完了) → remote の phases を
 *   確認し、設定済みなら Configured へ昇格。remote から schedule が消えていれば None へ reset
 *
 * phases の再構築 (どの price でどの期日に移行するか) はアプリのプラン移行ドメイン知識が
 * 必要なためテンプレートでは行わない。派生アプリは本コマンドを拡張し、自前の
 * ScheduleService で create 再試行 / phases 再構築を追加する (spirux/aigenba 参照)。
 *
 * 1 件失敗で全体停止しない (warning + continue)。
 */
class ReconcileSubscriptionSchedules extends Command
{
    protected $signature = 'billing:reconcile-schedules';

    protected $description = 'Subscription Schedule の部分完了 / local-remote 差分を復旧する (daily)';

    /** remote snapshot から schedule_id を復元した件数。 */
    private int $restored = 0;

    /** Created → Configured へ昇格させた件数。 */
    private int $promoted = 0;

    /** remote 消失で None へ reset した件数。 */
    private int $reset = 0;

    public function handle(StripeScheduleGateway $gateway): int
    {
        // 同一プロセス内での再呼び出し (scheduler / テスト) で前回値が累積しないようリセットする。
        $this->restored = 0;
        $this->promoted = 0;
        $this->reset = 0;

        $this->retryMissing($gateway);
        $this->retryPartial($gateway);

        // 無言成功ではなく処理件数を出力する (scheduler ログでの観測用)。
        $this->info("reconcile-schedules: restored={$this->restored} promoted={$this->promoted} reset={$this->reset}");

        return self::SUCCESS;
    }

    /**
     * 工程 1: local に schedule_id が無い契約の remote 復元。
     *
     * create API 成功直後のクラッシュでは local に痕跡が残らないため、schedule_id null の
     * 有効な契約を総当たりで remote 照会する (作成直後 1h は in-flight の可能性があるため除外)。
     */
    private function retryMissing(StripeScheduleGateway $gateway): void
    {
        $threshold = CarbonImmutable::now()->subHour();

        Subscription::query()
            ->where('type', 'default')
            ->whereIn('stripe_status', ['active', 'trialing', 'past_due'])
            ->whereNull('stripe_schedule_id')
            ->where('created_at', '<=', $threshold)
            ->get()
            ->each(function (Subscription $sub) use ($gateway): void {
                try {
                    $snapshot = $gateway->retrieve($sub->stripe_id);
                    if ($snapshot === null) {
                        return; // remote にも schedule なし = 差分なし
                    }

                    // phases 設定済み (2 phase 以上) なら Configured、不足なら Created として
                    // retryPartial / アプリ拡張の再構築対象に倒す。
                    $sub->markScheduleCreated($snapshot->id);
                    if (count($snapshot->phases) >= 2) {
                        $sub->markScheduleConfigured();
                    }
                    $this->restored++;
                } catch (Throwable $e) {
                    $this->warn("reconcile missing skip sub={$sub->stripe_id}: {$e->getMessage()}");
                }
            });
    }

    /**
     * 工程 2: schedule_setup_status=Created (phases 未設定の部分完了) の昇格 / reset。
     */
    private function retryPartial(StripeScheduleGateway $gateway): void
    {
        Subscription::query()
            ->where('schedule_setup_status', ScheduleSetupStatus::Created->value)
            ->whereNotNull('stripe_schedule_id')
            ->get()
            ->each(function (Subscription $sub) use ($gateway): void {
                try {
                    $snapshot = $gateway->retrieve($sub->stripe_id);
                    if ($snapshot === null) {
                        // remote から schedule が消えている (release / 手動削除) → local も解除。
                        $sub->clearSchedule();
                        $this->reset++;

                        return;
                    }

                    if (count($snapshot->phases) >= 2) {
                        $sub->markScheduleConfigured();
                        $this->promoted++;

                        return;
                    }

                    // phases 不足 → 再構築にはアプリのプラン移行知識が必要 (テンプレートでは観測のみ)。
                    $this->warn("reconcile partial pending sub={$sub->stripe_id}: phases 不足 (アプリ側の再構築が必要)");
                } catch (Throwable $e) {
                    $this->warn("reconcile partial skip sub={$sub->stripe_id}: {$e->getMessage()}");
                }
            });
    }
}
