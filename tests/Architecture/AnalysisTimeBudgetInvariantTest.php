<?php

declare(strict_types=1);

use App\Jobs\Manual\RunManualAnalysis;
use App\Services\Billing\TicketLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

// Architecture lane は既定で DB を使わないが、本テストは予約 TTL を台帳の公開 API で
// 実測するため RefreshDatabase を明示適用する
uses(RefreshDatabase::class);

/*
 * 解析ジョブの時間 budget 連鎖を CI で固定する (config/定数を弄って連鎖を壊せない)。
 *
 * | 項目 | 値 | 根拠 |
 * |---|---|---|
 * | LLM worst-case | 1,080 秒 | 3 段 × (1+リトライ2) 試行 × client timeout 120 秒 |
 * | job $timeout | 1,380 秒 | 上記 + 抽出/解析余裕 180 秒 + マージン |
 * | queue retry_after | 1,560 秒 | timeout < retry_after (Laravel 要件: 二重処理防止) |
 * | 予約 TTL | 1,800 秒 | TicketLedgerService::RESERVATION_TTL_MINUTES (変更しない) |
 * | stale 回復閾値 | 1,800 秒 | manual.analysis_stale_after_minutes |
 */
test('解析ジョブの時間 budget 連鎖 (timeout < retry_after < 予約TTL <= stale閾値)', function (): void {
    $timeout = (new RunManualAnalysis(1))->timeout;
    $retryAfter = config()->integer('queue.connections.database-analysis.retry_after');

    // 予約 TTL は台帳の公開 API (reserve) で実測する: 固定時刻で reserve し
    // expires_at − now を実 TTL とする (TicketLedgerService の private 定数を
    // ハードコード複製しない = 台帳側の TTL 変更をこのテストが実際に検出できる)
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [$organization] = createOrganizationWithOwner();
    $tickets = app(TicketLedgerService::class);
    $tickets->grant($organization, 1, '時間 budget テスト用');
    $reservation = $tickets->reserve($organization, 1);
    $ttlSeconds = (int) CarbonImmutable::now()->diffInSeconds($reservation->expires_at);

    $staleSeconds = config()->integer('manual.analysis_stale_after_minutes') * 60;
    expect($timeout)->toBeLessThan($retryAfter);
    expect($retryAfter)->toBeLessThan($ttlSeconds);
    expect($ttlSeconds)->toBeLessThanOrEqual($staleSeconds);
});

test('解析ジョブの connection/queue 名が設定と drift しない', function (): void {
    $job = new RunManualAnalysis(1);
    expect($job->connection)->toBe('database-analysis'); // onConnection() が設定
    expect(config()->string('queue.connections.database-analysis.queue'))->toBe('analysis');
    expect(config()->string('queue.connections.database-analysis.driver'))->toBe('database');
});

test('LLM worst-case (3段×3試行×client timeout) が job timeout に収まる', function (): void {
    $attempts = 1 + config()->integer('manual.analysis_llm_max_retries'); // 3
    $clientTimeout = 120; // 各 YAML client_options.timeout と一致 (AnalysisTokenBudgetInvariantTest が YAML 側を固定)
    expect(3 * $attempts * $clientTimeout + 180)->toBeLessThanOrEqual((new RunManualAnalysis(1))->timeout);
});

test('解析ジョブは自動再試行しない (tries=1。再実行は analyze 再トリガーのみ)', function (): void {
    expect((new RunManualAnalysis(1))->tries)->toBe(1);
});
