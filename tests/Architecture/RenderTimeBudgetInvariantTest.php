<?php

declare(strict_types=1);

use App\Jobs\Manual\DeleteRenderOutputsJob;
use App\Jobs\Manual\RunManualRender;
use App\Services\Billing\TicketLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Architecture lane は既定で DB を使わないが、本テストは予約 TTL を台帳の公開 API で
// 実測するため RefreshDatabase を明示適用する (AnalysisTimeBudgetInvariantTest と同型)

uses(RefreshDatabase::class);

/*
 * レンダジョブの時間 budget 連鎖を CI で固定する (config/定数を弄って連鎖を壊せない)。
 *
 * | 項目 | 値 | 根拠 |
 * |---|---|---|
 * | job $timeout | 1,500 秒 (25 分) | 尺上限ソフトゲート (20 分ソース) の実レンダ + マージン |
 * | queue retry_after | 1,680 秒 | timeout < retry_after (Laravel 要件: 二重処理防止) |
 * | 予約 TTL | 1,800 秒 | TicketLedgerService::RESERVATION_TTL_MINUTES (変更しない) |
 * | stale running 閾値 | 1,800 秒 | manual.render_stale_after_minutes |
 * | stale queued 閾値 | 600 秒 | manual.render_queued_stale_after_minutes (queued 短 SLA) |
 *
 * **`manual.render_max_total_source_ms` を引き上げる際は timeout 1,500s に実レンダが
 * 収まるかを実測で再確認せよ** (尺上限と時間 budget は連動する運用値。
 * compose 段は全カットの再エンコードを含むためソース尺に概ね比例する)。
 */
test('レンダジョブの時間 budget 連鎖 (timeout < retry_after < 予約TTL <= stale running 閾値)', function (): void {
    $timeout = (new RunManualRender(1))->timeout;
    $retryAfter = config()->integer('queue.connections.database-render.retry_after');

    // 予約 TTL は台帳の公開 API (reserve) で実測する (private 定数をハードコード複製しない)
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [$organization] = createOrganizationWithOwner();
    $tickets = app(TicketLedgerService::class);
    $tickets->grant($organization, 3, '時間 budget テスト用');
    $reservation = $tickets->reserve($organization, 3);
    $ttlSeconds = (int) CarbonImmutable::now()->diffInSeconds($reservation->expires_at);

    $staleRunningSeconds = config()->integer('manual.render_stale_after_minutes') * 60;
    expect($timeout)->toBeLessThan($retryAfter);
    expect($retryAfter)->toBeLessThan($ttlSeconds);
    expect($ttlSeconds)->toBeLessThanOrEqual($staleRunningSeconds);
});

test('queued 短 SLA 閾値 < running 閾値 (queued 滞留は編集ブロックを最小化するため先に回収する)', function (): void {
    $queuedMinutes = config()->integer('manual.render_queued_stale_after_minutes');
    $runningMinutes = config()->integer('manual.render_stale_after_minutes');

    expect($queuedMinutes)->toBeLessThan($runningMinutes);
    expect($queuedMinutes)->toBeGreaterThan(0);
});

test('レンダジョブの connection/queue 名が設定と drift しない', function (): void {
    $job = new RunManualRender(1);
    expect($job->connection)->toBe('database-render'); // onConnection() が設定
    expect(config()->string('queue.connections.database-render.queue'))->toBe('render');
    expect(config()->string('queue.connections.database-render.driver'))->toBe('database');
});

test('レンダジョブは自動再試行しない (tries=1。再実行は render/preview 再トリガーのみ)', function (): void {
    expect((new RunManualRender(1))->tries)->toBe(1);
});

test('旧世代削除ジョブは media queue で流れる (DeleteRenderOutputsJob)', function (): void {
    $job = new DeleteRenderOutputsJob(1);
    expect($job->connection)->toBe('database-media');
    expect($job->tries)->toBe(3);
});
