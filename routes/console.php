<?php

declare(strict_types=1);

use App\Services\Billing\AccountDeletionBillingGuard;
use App\Services\Billing\StripeWebhookProcessor;
use App\Services\Billing\TicketLedgerService;
use App\Services\Capture\StaleUploadReservationSweeper;
use App\Services\Manual\AnalysisJobService;
use App\Services\Manual\RenderJobService;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| 課金 cron
|--------------------------------------------------------------------------
| reserve TTL 超過のチケット予約を解放する (2 フェーズ消費の前提となる stale 解放)。
*/
Artisan::command('billing:release-stale-reservations', function (TicketLedgerService $tickets) {
    $released = $tickets->releaseStale();
    $this->info("released {$released} stale reservation(s)");
})->purpose('期限切れ (expires_at 超過) のチケット予約を解放する');

Schedule::command('billing:release-stale-reservations')->everyFiveMinutes();

/*
|--------------------------------------------------------------------------
| Stripe webhook の滞留回収
|--------------------------------------------------------------------------
| 本処理中にプロセスが落ちて status='received' のまま残った記録を再処理へ戻す。
| 放置すると Stripe の再送は claim() に弾かれて 200 で終わり、Stripe 側も配信成功と
| 判断して再送を打ち切るため、決済済みチケットの付与が**無音で失われる**。
|
| **監視対象 (必須)**: 本コマンドの report() と、次の 3 つの件数。
|   1. status='received' かつ updated_at が滞留の閾値より古い行の件数
|      (増え続ける = scheduler か本コマンドが動いていない)
|   2. 本コマンド出力の retry-scheduled 件数 (再実行が失敗し続けている)
|   3. status='recovery_pending' の件数 (自動再実行の対象外として置かれた行。
|      理由は recovery_reason 列)
| 詳細は docs/architecture.md の「Stripe webhook の滞留回収」が正本。
*/
Artisan::command('billing:recover-stale-webhook-events', function (StripeWebhookProcessor $webhooks) {
    $result = $webhooks->recoverStale();
    $this->info(sprintf(
        'replayed %d / retry-scheduled %d / moved-to-recovery-pending %d / skipped %d',
        $result->replayed,
        $result->retryScheduled,
        $result->movedToRecoveryPending,
        $result->skipped,
    ));
})->purpose('処理中に滞留した Stripe webhook 記録を再処理へ戻す');

Schedule::command('billing:recover-stale-webhook-events')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(static fn () => report(new RuntimeException(
        'billing:recover-stale-webhook-events 失敗 — 決済済み・チケット未付与が滞留する可能性',
    )));

/*
|--------------------------------------------------------------------------
| 課金 daily バッチ
|--------------------------------------------------------------------------
| - send-billing-reminders: 更新予告 (renewal 3 日前)。冪等は通知台帳の dedup_key。
| - reconcile-schedules: Subscription Schedule の部分完了 / local-remote 差分の復旧。
*/
Schedule::command('billing:send-billing-reminders')->daily()->onOneServer()->withoutOverlapping();
Schedule::command('billing:reconcile-schedules')->daily();

/*
|--------------------------------------------------------------------------
| Stripe 契約状態の突き合わせ (AG-035 (6))
|--------------------------------------------------------------------------
| webhook 欠落でローカルの契約状態が固まると、支払い失敗の遮断も復旧も起きない。
| 日次で Stripe を真実として収束させる。**チケット (金銭) には触れない**。
|
| 既存の 2 本とは書く列が重ならない (相乗りさせない):
|   - billing:reconcile-auto-recharge (15 分) = チケット自動購入の未決金
|   - billing:reconcile-schedules (日次)      = 予約 (Schedule) の作りかけ
|
| **監視対象**: 終了コードと report() (未確認・失敗はここにしか出ない)。
*/
Schedule::command('billing:reconcile-subscription-status')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(static fn () => report(new RuntimeException(
        'billing:reconcile-subscription-status 失敗 — Stripe と契約状態が突き合わせられていない',
    )));

/*
|--------------------------------------------------------------------------
| 課金孤児の検知 (退会ガードの second layer)
|--------------------------------------------------------------------------
| 退会ガード (AccountDeletionBillingGuard) は通常経路を止めるが、webhook トランザクションと
| 同時刻に退会が commit される競合までは排他しない (subscription 行を作るのは Cashier の
| WebhookController = vendor 側で、自前 listener の排他では覆えないため)。
| 予防で漏れた分と、本機能より前から存在する孤児組織を daily で検知する。
|
| 報告契約 (通知洪水を作らない):
|   - 1 実行につき **集約して 1 回だけ** report() する
|   - 内容は **件数と organization id のみ** (組織名・メール等の PII を載せない)
|   - 未解消なら翌日も同じ内容で再報告する (抑制状態を持たない = 冪等な観測)
|
| **監視対象**: 本コマンドの report()。
*/
Artisan::command('billing:detect-orphan-billing-organizations', function (
    OrganizationMembershipService $membership,
    AccountDeletionBillingGuard $guard,
) {
    $ids = $guard->orphanBillingOrganizationIds($membership->organizationsWithoutOwner());
    if ($ids === []) {
        $this->info('課金孤児なし');

        return;
    }

    $this->warn(count($ids).' 件の課金孤児組織を検出しました');
    // RuntimeException は import しない (本ファイルは namespace 宣言が無く global 解決される。
    // 非複合 use は NoNonCompoundGlobalUseTest が禁止する)。
    report(new RuntimeException(
        'Owner 不在かつ課金中の組織を検出: count='.count($ids).' ids='.implode(',', $ids),
    ));
})->purpose('Owner 不在かつ生きた課金責務がある組織 (課金孤児) を検知して報告する');

Schedule::command('billing:detect-orphan-billing-organizations')->daily()->onOneServer();

/*
|--------------------------------------------------------------------------
| 課金 cron (オートリチャージ / P8a)
|--------------------------------------------------------------------------
| reconcile-auto-recharge: pending attempt の回収 (課金済み回収 / 再実行 / SCA リマインド /
| 期限切れ終端 / 取りこぼし起票)。
|
| **監視対象 (必須)**: webhook が MAX_PROCESSING_ATTEMPTS=8 で恒久 drop した
| 「課金済み・付与なし」を回収する**唯一の**経路であり、停止すると資金回収済み・チケット
| 未付与が滞留する。AI-CUE の運用アラート経路は report() のみのため、onFailure をそこへ繋ぐ。
| 滞留の観測点は ticket_auto_recharge_attempts.status='pending' の件数
| (docs/architecture.md の監視対象リストを参照)。
*/
Schedule::command('billing:reconcile-auto-recharge')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(static fn () => report(new RuntimeException(
        'billing:reconcile-auto-recharge 失敗 — 資金回収済み・チケット未付与が滞留する可能性',
    )));

/*
|--------------------------------------------------------------------------
| 問い合わせ (Inquiry) retention purge
|--------------------------------------------------------------------------
| 保持期限 (config legal.inquiry_retention_days) を超過した spam / closed を日次で削除する。
| 手動運用 (dry-run / 本人削除要請) は docs/inquiry-deletion-runbook.md を参照。
*/
Schedule::command('inquiry:purge --apply')->daily();

/*
|--------------------------------------------------------------------------
| 退会予約の執行 (猶予期間つき削除)
|--------------------------------------------------------------------------
| deletion_purge_after を過ぎた退会予約を執行する。判定は既存の
| OrganizationMembershipService::deleteAccount() が行う (課金ガードのロック下再評価を
| そのまま継承する)。退会ブロッカーは**業務上の保留**として次へ進み、想定外例外があれば
| FAILURE で終わる (全件 DB 障害を SUCCESS で隠さない)。
|
| **監視対象**: 本コマンドの終了コードと report()。
| 取消は利用者が /settings からいつでも行える (誤操作救済の本体)。
*/
Schedule::command('account:purge-deletion-requests --apply')->daily()->onOneServer();

/*
|--------------------------------------------------------------------------
| AI 解析 cron
|--------------------------------------------------------------------------
| dispatch 喪失 (queued 滞留) と worker 異常終了 (running 滞留) の回復。
| failJob は行ロック + terminal guard で冪等 (billing:release-stale-reservations と同型)。
*/
Artisan::command('analysis:recover-stale-jobs', function (AnalysisJobService $jobs) {
    $recovered = $jobs->recoverStale();
    $this->info("recovered {$recovered} stale analysis job(s)");
})->purpose('滞留した解析ジョブ (queued/running が閾値超過) を失敗確定し予約を解放する');

Schedule::command('analysis:recover-stale-jobs')->everyFiveMinutes();

/*
|--------------------------------------------------------------------------
| レンダ cron
|--------------------------------------------------------------------------
| recover-stale-jobs: dispatch 喪失 (queued=10 分) と worker 異常終了 (running=30 分) の回復。
| reconcile-outputs: 出力世代の収束 (世代交代済みの output_path を削除 job へ再投入。
| stale 回復とは別責務のため command を分離する)。
*/
Artisan::command('render:recover-stale-jobs', function (RenderJobService $jobs) {
    $recovered = $jobs->recoverStale();
    $this->info("recovered {$recovered} stale render job(s)");
})->purpose('滞留したレンダジョブ (queued/running が閾値超過) を失敗確定し予約を解放する');

Schedule::command('render:recover-stale-jobs')->everyFiveMinutes();

Artisan::command('render:reconcile-outputs', function (RenderJobService $jobs) {
    $result = $jobs->reconcileOutputs();
    $this->info("dispatched {$result['dispatched']} delete job(s), skipped {$result['skipped']}");
})->purpose('世代交代済みのレンダ出力を走査し S3 削除ジョブを再投入する (最新 1 世代へ収束)');

Schedule::command('render:reconcile-outputs')->everyFiveMinutes()->onOneServer()->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| 撮影 PWA cron (doc/10 §10.8-4 / 概念設計 D7)
|--------------------------------------------------------------------------
| 期限切れ pending / stale verifying のアップロード予約を released 化して
| bytes_pending を解放し、PUT 済みだが未登録の S3 孤児オブジェクトを削除する。
| fresh verifying (検証中) には触れない (登録処理の claim 契約と競合しない)。冪等。
*/
Artisan::command('capture:release-stale-upload-reservations', function (StaleUploadReservationSweeper $sweeper) {
    $released = $sweeper->sweep();
    $this->info("released {$released} stale upload reservation(s)");
})->purpose('期限切れのテイクアップロード予約を解放し S3 孤児オブジェクトを削除する');

Schedule::command('capture:release-stale-upload-reservations')->everyTenMinutes()->onOneServer()->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| 冪等キーの保持期間 purge (T139)
|--------------------------------------------------------------------------
| 保持期間 (config idempotency.retention_hours) を超えた冪等キーを
| REST / MCP 両テーブルから物理削除する。claim 時の lazy delete だけでは
| 「二度と再送されなかったキー」が残り続け単調増加するため。
|
| **監視対象**: 本コマンドの report() (processing のまま期限切れ = 確定できなかった claim。
| プロセス強制終了か finalize 失敗の痕跡)。
|
| ⚠ onOneServer() は **scheduler が動いていること + ロックを提供する cache driver** を
|   前提にする (既存の billing:send-billing-reminders / render:reconcile-outputs と同じ前提)。
*/
Schedule::command('idempotency:prune')->daily()->onOneServer();

/*
|--------------------------------------------------------------------------
| 課金記録の保持期間 (7 年) の決着 (T144 / PR-C2)
|--------------------------------------------------------------------------
| 保持期限 (config legal.billing_retention_years) を超えた課金記録を日次で決着させる。
| 削除で決着する 6 target と、**畳み込み**で決着する台帳 (ticket_ledger_entries) がある
| (台帳は残高の真実源なので消すと残高が変わる。古い行は残高スナップショットへ置換する)。
|
| **監視対象**: 本コマンドの終了コードと出力の `horizon:` 行。
|   - `horizon: NG` が続く = 規約 (/privacy が宣言する最長 7 年) を満たせていない状態である。
|     **`fail_closed` は「安全に残した」であって「規約を満たした」ではない**ため残存に数える。
|   - `fail_closed` の**継続・増加**を正常成功として扱わないこと (解消手順は
|     docs/billing-retention-runbook.md)。
|
| 本コマンドは PR-C2 のデプロイ時点から --apply で有効である (runbook の初回 apply は
| 「初回を能動的に完走させて結果を確認する」ためのもので、schedule の抑止ではない)。
*/
Schedule::command('billing:purge-retention-expired --apply')->daily()->onOneServer();
