<?php

declare(strict_types=1);

use App\Services\Billing\TicketLedgerService;
use App\Services\Manual\AnalysisJobService;
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
| 課金 daily バッチ
|--------------------------------------------------------------------------
| - send-billing-reminders: 更新予告 (renewal 3 日前)。冪等は通知台帳の dedup_key。
| - reconcile-schedules: Subscription Schedule の部分完了 / local-remote 差分の復旧。
*/
Schedule::command('billing:send-billing-reminders')->daily()->onOneServer()->withoutOverlapping();
Schedule::command('billing:reconcile-schedules')->daily();

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
