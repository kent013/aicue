Round 4 の指摘（Critical 1 件・Warning 1 件・Suggestion 1 件、いずれも施策 6）に全て対応しました。改訂後の施策 6 全文をインラインで添付します。再レビューをお願いします。

## 対応マトリクス

### [Critical] Queueable trait と `$connection` プロパティ再宣言の衝突 → 対応（修正案どおり）
- `public string $connection = 'database-analysis';` のプロパティ宣言を削除し、コンストラクタで `$this->onConnection('database-analysis');` を呼ぶ形に変更。typed 再宣言が trait composition エラーになる旨をコメントで明記。

### [Warning] 「sync では影響なし」の運用ノートの誤り・worker 未起動時の滞留 → 対応（修正案どおり）
- 運用ノートを訂正: connection 明示 job は `QUEUE_CONNECTION=sync` でも database-analysis へ投入される（専用 worker 不在なら滞留。滞留は stale 回復 cron が 30 分で failJob するため監視で検知可能）。
- ローカル/テストの検証方法を明記: パイプラインの同期実行は **`AnalysisPipeline::run()` の直接呼び出し**、dispatch の検証は **`Queue::fake()`**（sync ドライバの自動実行に依存しない）。
- **運用契約**として「本番/ステージングの worker プロセス定義・デプロイ手順・監視対象に `php artisan queue:work database-analysis` を必須登録」を施策 6 の運用ノートに明記し、施策 13 で docs/architecture.md へ転記することを追加。

### [Suggestion] connection/queue 名の drift 検出 → 採用
- `AnalysisTimeBudgetInvariantTest` に「`$job->connection === 'database-analysis'`（onConnection が設定）/ `queue.connections.database-analysis.queue === 'analysis'` / `driver === 'database'`」のテストを追加。

---

# 改訂後の施策 6（全文）

## 施策 6: 解析ジョブ本体（RunManualAnalysis + AnalysisPipeline）

### 変更箇所
- `app/Jobs/Manual/RunManualAnalysis.php`（新規）
- `app/Services/Manual/AnalysisPipeline.php`（新規）
- `app/Exceptions/Manual/AnalysisFailedException.php`（新規: ユーザー向けメッセージ付き失敗）
- `app/Exceptions/Manual/LlmOutputInvalidException.php`（新規: 有界リトライのトリガー）
- `config/queue.php`（`database-analysis` connection 追加 = retry_after を解析ジョブ専用に設定）
- `tests/Architecture/AnalysisTimeBudgetInvariantTest.php`（新規: 時間 budget の連鎖を CI 固定）

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
    $ttlSeconds = 30 * 60; // TicketLedgerService::RESERVATION_TTL_MINUTES (private のため値で固定。
                           // 台帳側を変えたらこのテストが検出する運用契約)
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

### 変更後コード（骨子）

```php
// RunManualAnalysis (queue job は薄い殻。本体は Pipeline)
class RunManualAnalysis implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** 自動再試行しない (§10.8-1。再実行は analyze 再トリガーのみ) */
    public int $tries = 1;

    /**
     * worst-case (3 段 × 3 試行 × 120s = 1,080s) + 抽出/解析余裕 180s + マージン。
     * timeout < retry_after (1,560s) < 予約 TTL (1,800s) の連鎖は
     * AnalysisTimeBudgetInvariantTest が CI 固定する
     */
    public int $timeout = 1380;

    public function __construct(public readonly int $analysisJobId)
    {
        // retry_after を解析専用値にした connection (config/queue.php)。既定 database は 90s のため。
        // Queueable trait が $connection プロパティを既に定義しているため、プロパティ再宣言でなく
        // onConnection() で指定する (typed 再宣言は trait composition エラーになる)
        $this->onConnection('database-analysis');
    }

    public function handle(AnalysisPipeline $pipeline): void
    {
        $pipeline->run($this->analysisJobId);
    }

    /** catch を通らない失敗 (timeout kill 等) の最終防衛線。failJob は冪等 */
    public function failed(?Throwable $exception): void
    {
        $job = AnalysisJob::query()->find($this->analysisJobId);
        if ($job !== null) {
            app(AnalysisJobService::class)->failJob($job, '解析が中断されました。再実行してください。');
        }
    }
}
```

```php
// AnalysisPipeline::run の骨格 (概念設計 §4 の忠実な実装)
public function run(int $analysisJobId): void
{
    $job = AnalysisJob::query()->findOrFail($analysisJobId);
    try {
        if (! $this->startJob($job)) {
            return; // 重複配送 / stale 回復後の遅延配送 → no-op
        }
        $document = $job->sourceDocument;
        Assert::notNull($document, 'trigger が必ず associate している');

        $text = $this->extractor->extract($document);                       // 抽出 + バイト上限
        $extracted = $this->runExtractStep($job, $document, $text);         // LLM 1 段目
        $decomposition = $this->runDecomposeStep($job, $extracted);         // LLM 2 段目
        $generated = $this->runGenerateStep($job, $decomposition);          // LLM 3 段目
        $this->finalize($job, $generated);                                  // terminal tx
    } catch (Throwable $exception) {
        report($exception);
        $this->jobs->failJob($job, $this->userMessageFor($exception));
    }
}

/** 開始 tx: queued guard + 予約の冪等確保 (§10.8-1) + running へ */
private function startJob(AnalysisJob $job): bool
{
    return DB::transaction(function () use ($job): bool {
        /** @var AnalysisJob $locked */
        $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== JobStatus::Queued) {
            return false; // 重複配送 guard
        }

        $organization = $this->resolveOrganization($locked); // manual→project→organization
        $this->ensureReservation($locked, $organization);    // 残高不足はここで throw → catch → failJob

        $locked->status = JobStatus::Running;
        $locked->step = AnalysisStep::Extract;
        $locked->progress = 10;
        $locked->save();
        $job->refresh();

        return true;
    });
}

/** 予約の冪等確保: 有効な Reserved があれば再利用。Released/失効/Committed は新規 reserve→付け替え */
private function ensureReservation(AnalysisJob $locked, Organization $organization): void
{
    $reservation = $locked->ticketReservation;
    if ($reservation !== null
        && $reservation->status === TicketReservationStatus::Reserved
        && $reservation->expires_at->isFuture()) {
        return; // 再利用 (再試行で二重予約しない)
    }
    if ($reservation !== null && $reservation->status === TicketReservationStatus::Reserved) {
        // 失効済みだが cron 未回収の Reserved → 明示 release して付け替え (§10.8-1)
        try {
            $this->tickets->release($reservation);
        } catch (LogicException) {
            // 並行 release 済み
        }
    }
    $cost = config()->integer('manual.analysis_ticket_cost');
    $new = $this->tickets->reserve($organization, $cost); // 不足は InsufficientTicketsException
    $locked->ticketReservation()->associate($new);
    $locked->save();
}

/**
 * terminal tx: materialize + commit + succeeded を原子化 (概念設計 §4-5)。
 * transaction / 行ロックは本メソッド (最外層) だけが張る。
 *
 * グローバルロック順 (全経路がこの順でのみ取得する。逆順取得ゼロ = デッドロックなし):
 *   analysis_jobs → video_manuals → ticket_reservations → organizations
 *
 * TicketLedgerService 内部の実取得順 (実装から転記。内部変更はしない):
 *   - reserve / grant:   organizations のみ (L243/L42 lockOrganizationRow)
 *   - commit / release:  ticket_reservations (lockReservationRow) → organizations (lockOrganizationRow)
 * 各経路の取得列:
 *   - trigger:      video_manuals のみ (balance() はロックなしの集計)
 *   - startJob:     analysis_jobs → (reserve: organizations)
 *   - finalize:     analysis_jobs → video_manuals → (commit: ticket_reservations → organizations)
 *   - failJob:      analysis_jobs → video_manuals → (release: ticket_reservations → organizations)
 *   - releaseStale (billing cron): ticket_reservations → organizations (前方リソースを保持しない)
 *   - ScenarioService::save: video_manuals のみ
 * いずれもグローバル順の部分列であり循環待ちは構成できない。
 */
private function finalize(AnalysisJob $job, GeneratedScenarioData $generated): void
{
    DB::transaction(function () use ($job, $generated): void {
        // ロック 1: job 行 (stale 回復 cron との直列化点)
        /** @var AnalysisJob $locked */
        $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
        if ($locked->status !== JobStatus::Running) {
            return; // stale 回復 cron が先勝ち → materialize も commit もしない (無課金 succeeded 排除)
        }

        // ロック 2: manual 行 (共有ロック規約。親 relation 経由再解決 = 子∈親も担保)
        $project = $this->resolveProject($locked);
        /** @var VideoManual $lockedManual */
        $lockedManual = $project->manuals()
            ->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();

        // cuts + version + status(analyzing→ready) はロック済み manual 前提メソッドで反映
        // (内側 transaction を張らない。analyzing guard 違反は LogicException → 全体 rollback)
        $this->scenarios->materializeIntoLockedManual($lockedManual, $generated->toScenarioSteps());

        // ロック 3: reservation/org 行 (TicketLedgerService::commit 内部。savepoint)
        $reservation = $locked->ticketReservation;
        Assert::notNull($reservation, 'startJob が必ず予約を付けている');
        // 非 Reserved は LogicException → terminal tx 全体 rollback (materialize も巻き戻る) → failJob
        $this->tickets->commit($reservation);

        $locked->status = JobStatus::Succeeded;
        $locked->progress = 100;
        $locked->save();
    });
}

/** LLM 段の共通有界リトライ (JSON 検証失敗のみ。長さ・provider 例外はリトライしない) */
private function withBoundedRetry(callable $attempt): mixed
{
    $maxRetries = config()->integer('manual.analysis_llm_max_retries');
    for ($tryCount = 0; ; $tryCount++) {
        try {
            return $attempt();
        } catch (LlmOutputInvalidException $exception) {
            if ($tryCount >= $maxRetries) {
                throw $exception; // 計 (1 + maxRetries) 試行で打ち切り → failJob
            }
        }
    }
}
```

- 各 step メソッドは `withBoundedRetry` 内で「Prompt factory → executeSync → DTO::fromLlmText」を
  実行し、成功後に job の step/progress を更新（extract 完了 35 / decompose 完了 65 /
  generate 完了 90。tx 不要の単発 update だが `whereKey(...)->lockForUpdate()` は不要 =
  progress は表示用の粗い値で、状態機械は status のみが真実源）
- `runExtractStep` は成功時に `source_documents.extracted_json` へ DTO->toArray() を保存
  （write-only 監査スナップショット）、`runDecomposeStep` は `analysis_jobs.result_json` へ保存
- `userMessageFor(Throwable)`: AnalysisFailedException / LlmOutputInvalidException /
  InsufficientTicketsException はそのままユーザー向け文言、その他は汎用文言
  「解析に失敗しました。時間をおいて再実行してください」（内部詳細を error 列に漏らさない）

### PHPStan適合チェック
- [x] withBoundedRetry は @template で型を貫通（`@param callable(): T` → `@return T`）
- [x] HasOneThrough の organization は Assert::isInstanceOf で narrow
- [x] DTO 戻り値の generics 明示

### テスト計画（AnalysisPipelineTest。Prompt::fake + Storage::fake + sync queue）
- [ ] 成功パス: fake 3 応答 → cuts materialize / manual=ready / scenario_version+1 /
      job=succeeded / 予約 committed / extracted_json・result_json 保存
- [ ] 再試行で二重予約しない: 予約付き job を再度 run → reserve が増えない（queued guard も検証）
- [ ] TTL 切れ付け替え: Released 予約付きの queued job → 新予約で完走、旧予約は Released のまま
- [ ] 失敗時 release: LLM 3 回不正 JSON → job=failed + error / manual 復帰 / 予約 released
- [ ] commit は Reserved のみ: finalize 前に予約を Released に細工 → terminal tx rollback /
      cuts 不変 / failed（「failed ∧ committed」「succeeded ∧ released」の非共存アサーション）
- [ ] インターリーブ: (a) cron 先勝ち（job を failed に）→ run 完走しても cuts/commit 無し
      (b) pipeline 先勝ち → 後追い failJob が no-op
- [ ] 有界リトライ: 不正 JSON ×2 → 3 回目成功で succeeded（Prompt::fake の呼び出し回数検証）
- [ ] manual 復帰の分岐: cuts 有り（再解析失敗）→ ready / cuts 無し → draft

### リスク
- LLM 応答が仕様的に正しいが空 steps の場合 → DTO 検証（steps ≥ 1）で LlmOutputInvalidException
  → リトライ → 失敗（materialize が空シナリオで ready を作ることはない）


---

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類し、Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力
