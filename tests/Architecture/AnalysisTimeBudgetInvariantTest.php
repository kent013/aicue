<?php

declare(strict_types=1);

use App\Jobs\Manual\RunManualAnalysis;
use App\Services\Billing\TicketLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AnalysisBudget;

// Architecture lane は既定で DB を使わないが、本テストは予約 TTL を台帳の公開 API で
// 実測するため RefreshDatabase を明示適用する
uses(RefreshDatabase::class);

/*
 * 解析ジョブの時間 budget 連鎖を CI で固定する (config/定数を弄って連鎖を壊せない)。
 *
 * | 記号 | 項目 | 値 | 根拠 |
 * |---|---|---|---|
 * | C | client timeout (prompt YAML) | 360s | max_tokens 16000 飽和の実測 274s の約 1.31 倍 (運用上限) |
 * | D | パイプライン deadline | 1,080s = 3C | 全 3 段にフル ceiling の 1 回を許す最小値 |
 * | M₁ | finalize モデル予算 | 30s | terminal tx + commit/release + 通知 |
 * | S | 安全余白 | 90s | P (worker alarm → run() 入口) + タイマー精度 + シグナル配送 |
 * | T | job $timeout | 1,560s | D + C + M₁ + S |
 * | — | queue retry_after | 1,680s | T < retry_after |
 * | — | 予約 TTL | 1,800s | TicketLedgerService (変更しない) |
 * | — | stale 閾値 | 1,800s | manual.analysis_stale_after_minutes |
 *
 * **生成レート (token/s) は CI で pin しない**。実測に基づく運用上限であって
 * 保証値ではないため (概念設計 §実測)。CI が固定するのは順序関係と一貫性のみ。
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

test('解析 3 プロンプトの client timeout が仕様値 (C) と一致する', function (): void {
    foreach (AnalysisBudget::clientTimeoutSecondsFromYaml() as $name => $timeout) {
        expect($timeout)->toBe(
            AnalysisBudget::CLIENT_TIMEOUT_SECONDS,
            "{$name}.yaml の client_options.timeout が時間 budget の C と不一致",
        );
    }
});

test('config の deadline が仕様値 (D = 3C) と一致する', function (): void {
    expect(config()->integer('manual.analysis_deadline_seconds'))
        ->toBe(AnalysisBudget::DEADLINE_SECONDS);
});

test('job timeout は worst-case (deadline + client timeout 1 回 + finalize + 余白) を満たす', function (): void {
    // deadline 判定は「過ぎたか」のみなので、deadline 通過後に走りうるのは高々 1 回分の C
    $worstCase = AnalysisBudget::DEADLINE_SECONDS
        + AnalysisBudget::CLIENT_TIMEOUT_SECONDS
        + AnalysisBudget::FINALIZE_BUDGET_SECONDS
        + AnalysisBudget::SAFETY_MARGIN_SECONDS;

    expect((new RunManualAnalysis(1))->timeout)->toBeGreaterThanOrEqual($worstCase);
});

test('モデル上限 (deadline + C + finalize) に対して明示的な安全余白がある', function (): void {
    $modelBound = AnalysisBudget::DEADLINE_SECONDS
        + AnalysisBudget::CLIENT_TIMEOUT_SECONDS
        + AnalysisBudget::FINALIZE_BUDGET_SECONDS;

    expect((new RunManualAnalysis(1))->timeout - $modelBound)
        ->toBeGreaterThanOrEqual(AnalysisBudget::SAFETY_MARGIN_SECONDS);
});

test('解析ジョブは自動再試行しない (tries=1。再実行は analyze 再トリガーのみ)', function (): void {
    expect((new RunManualAnalysis(1))->tries)->toBe(1);
});
