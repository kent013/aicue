Round 5 の残指摘（Warning 1 件・Suggestion 1 件）を、いずれも貴殿の修正案どおり設計へ反映しました。これは新規論点の追加ではなく「合意済み修正の反映確認」です。確認をお願いします。

## 反映内容

### [Warning] 予約 TTL のハードコード → 修正案どおり反映
`AnalysisTimeBudgetInvariantTest` を「固定時刻（travelTo）で台帳の公開 API `reserve()` を実行し、`expires_at − now` を実 TTL として連鎖検証する」形へ変更（private 定数の複製を排除。台帳側の TTL 変更をテストが実際に検出できる）:

```php
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
```

### [Suggestion] 「sync queue」表現 → 採用
テスト計画・共通セットアップの記述を「パイプライン実走 = `AnalysisPipeline::run()` の直接呼び出し（dispatch の同期実行に依存しない）」へ統一（施策 6 の運用ノートと整合）。

### 参考: 施策 6 の時間 budget 節（現行全文）

### 時間 budget（worst-case から導出。値の根拠を一本化）

| 項目 | 値 | 根拠 |
|---|---|---|
| LLM worst-case | 1,080 秒 | 3 段 × (1+リトライ2) 試行 × client timeout 120 秒 |
| 抽出 + 解析/DB 余裕 | 180 秒 | PDF/XLSX 抽出・レスポンス解析・ロック待ちのマージン |
| **job `$timeout`** | **1,380 秒 (23 分)** | 上記合計 1,260 秒 + マージン |
| **queue `retry_after`** | **1,560 秒 (26 分)** | `timeout < retry_after` (Laravel 要件: 二重処理防止)。既定の database 接続 (90 秒) では不足するため **専用 connection `database-analysis`** を追加し、job 側 `$connection` で指定 |
| **予約 TTL** | 1,800 秒 (30 分) | TicketLedgerService::RESERVATION_TTL_MINUTES（変更しない）。startJob で予約直後から worst-case 完走 (23 分) しても TTL 内 |
| stale 回復閾値 | 1,800 秒 (30 分) | `analysis_stale_after_minutes`。step 更新間隔の worst-case (1 段 = 360 秒) ≪ 閾値で誤回収なし |

連鎖 **`timeout (1380) < retry_after (1560) < TTL (1800) ≤ stale 閾値 (1800)`** を
`AnalysisTimeBudgetInvariantTest` で CI 固定する（config/定数を弄って連鎖を壊せない）:

```php
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
    $clientTimeout = 120; // 各 YAML client_options.timeout と一致 (YAML 走査で検証)
    expect(3 * $attempts * $clientTimeout + 180)->toBeLessThanOrEqual((new RunManualAnalysis(1))->timeout);
});
```

```php
// config/queue.php connections に追加 (driver は既定 database と同一。retry_after のみ専用値)
'database-analysis' => [
    'driver' => 'database',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => 'jobs',
    'queue' => 'analysis',
    'retry_after' => 1560,
    'after_commit' => false,
],
```

運用ノート（**運用契約**。施策 13 で docs/architecture.md にも記載する）:
- connection を明示した job は `QUEUE_CONNECTION=sync` でも **database-analysis へ投入される**
  （env の既定を上書きする）。専用 worker が居ないとジョブは滞留する
- **本番/ステージングの worker プロセス定義・デプロイ手順・監視対象に
  `php artisan queue:work database-analysis` を必須項目として登録する**
  （queued 滞留は stale 回復 cron が 30 分で failJob するため、滞留 = 監視で気づける）
- ローカル/テストの検証方法: パイプラインの同期実行は **`AnalysisPipeline::run()` の直接呼び出し**、
  dispatch の検証は **`Queue::fake()`**（sync ドライバでの自動実行には依存しない）



【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED（新規の Critical/Warning があれば分類と修正案を添える）
- 日本語で出力
