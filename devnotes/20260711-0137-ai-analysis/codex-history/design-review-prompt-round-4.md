Round 3 の指摘（Warning 3 件・Suggestion 1 件）に全て対応しました。改訂後の施策 6・7・9 の全文をインラインで添付します。再レビューをお願いします。

## 対応マトリクス

### [Warning] 施策 6: timeout と有界リトライの不整合・retry_after 未考慮 → 対応
- **時間 budget 表**を新設し worst-case から再算定: LLM worst-case = 3 段 × 3 試行 × client timeout 120 秒 = 1,080 秒、抽出/解析/DB ロック待ち余裕 180 秒（Suggestion を織り込み）→ **job `$timeout = 1380` (23 分)**。
- 既定 database 接続の retry_after (90 秒) では timeout < retry_after を満たせないため、**専用 connection `database-analysis`（retry_after = 1560 秒）** を config/queue.php に追加し、job の `$connection` プロパティで指定。
- 連鎖 **`timeout (1380) < retry_after (1560) < 予約 TTL (1800) ≤ stale 閾値 (1800)`** と「LLM worst-case + 余裕 ≤ timeout」の算術を、新設 `AnalysisTimeBudgetInvariantTest`（Architecture）で CI 固定。

### [Warning] 施策 7: 推測変換によるバイナリの誤変換 → 対応（修正案どおり）
- ensureUtf8 を strict 手順へ: (1) mb_check_encoding OK → そのまま、(2) NG → `mb_detect_encoding($text, ['UTF-8','SJIS-win','EUC-JP'], strict: true)` で判定不能なら **unextractable（変換しない）**、(3) 変換後に再度 mb_check_encoding 検証・不合格は unextractable、(4) mb_scrub は検証合格後の残存破損の限定補修のみ。
- テストに「判定不能バイナリ（乱数バイト列）fixture → unextractable」「変換後再検証 NG → unextractable」を追加。

### [Warning] 施策 9: ファイル単位 allowlist の抜け穴・自己検証欠落 → 対応（修正案どおり）
- token_get_all ベース走査（既存 PrismDirectDispatchScanner と同流儀。コメント/文字列リテラルは無視）で「`->materializeIntoLockedManual(` = 呼び出し」「`function materializeIntoLockedManual` = 宣言」を token 列で区別。
- **宣言は ScenarioService.php のみ / 呼び出しは AnalysisPipeline.php のみ**許可。ScenarioService 自身の中に新規呼び出しを書いた場合も fail（ファイル単位 allowlist の抜け穴を封鎖）。
- scanner 自己検証をテスト計画に追加: コメント内出現は無視 / 呼び出しを検出 / 宣言を検出 / 走査対象ファイル数 > 0（degenerate PASS 防止）。

### [Suggestion] timeout に抽出・DB ロック待ち・解析余裕を含める → 採用
- budget 表の「抽出 + 解析/DB 余裕 180 秒」として織り込み済み。

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

運用ノート: worker は `php artisan queue:work database-analysis` を既存 worker に追加する
（`QUEUE_CONNECTION=sync` のローカル/テストでは job の `$connection` 指定に関わらず
`Queue::fake()` / pipeline 直接呼び出しで検証するため影響なし）。

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

    /** retry_after を解析専用値にした connection (config/queue.php)。既定 database は 90s のため */
    public string $connection = 'database-analysis';

    public function __construct(public readonly int $analysisJobId) {}

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

# 改訂後の施策 7（全文）

## 施策 7: SOP テキスト抽出

### 変更箇所
- `app/Services/Manual/SopTextExtractor.php` + `app/DataTransferObjects/Manual/Analysis/ExtractedText.php`（新規）
- `composer.json`: `smalot/pdfparser`（PDF テキスト抽出）、`phpoffice/phpspreadsheet`（Excel）

### 変更後コード（骨子）

```php
final readonly class ExtractedText
{
    public function __construct(
        public string $text,
        public int $byteLength,     // strlen (UTF-8 bytes) = token budget 判定値
        public string $sourceKind,  // pdf | spreadsheet | plain (診断用)
    ) {}
}

class SopTextExtractor
{
    /** mime で分岐して抽出。空/超過/破損は AnalysisFailedException (ユーザー向け文言) */
    public function extract(SourceDocument $document): ExtractedText
    {
        $contents = Storage::get($document->file_path); // 不在は例外 → failJob (汎用文言)
        // 分岐はアップロード時に内容 sniff 済みの $document->mime を使う
        // (クライアント拡張子は信頼しない。施策 4 の再判定と対)
        $kind = $this->kindFor($document->mime); // 'pdf' | 'spreadsheet' | 'plain' の literal union
        try {
            $text = match ($kind) {
                'pdf' => $this->fromPdf($contents),          // Smalot\PdfParser\Parser::parseContent
                'spreadsheet' => $this->fromSpreadsheet($contents), // 一時ファイル経由で IOFactory::load → 全シートのセルをタブ/改行結合
                'plain' => $contents,
            };
        } catch (Throwable $exception) {
            // parser の内部例外はユーザー向け文言へ正規化 (詳細は report で内部ログのみ)
            report($exception);
            throw AnalysisFailedException::unextractable();
        }
        // UTF-8 妥当性の担保 (旧 XLS の SJIS 系・PDF の壊れた埋め込み対策)。
        // 推測変換で未知バイナリを「日本語らしき無意味文字列」へ化けさせない strict 手順:
        //   1. mb_check_encoding($text, 'UTF-8') OK → そのまま
        //   2. NG → mb_detect_encoding($text, ['UTF-8', 'SJIS-win', 'EUC-JP'], strict: true)。
        //      判定不能 (false) → AnalysisFailedException::unextractable() (バイナリ扱い。変換しない)
        //   3. 判定 encoding から mb_convert_encoding → 再度 mb_check_encoding で検証。不合格 → unextractable()
        //   4. mb_scrub は「検証合格後の残存破損の限定補修」としてのみ使用 (救済変換には使わない)
        $text = $this->ensureUtf8($text); // JSON 化・UserInput 生成を不正バイトで壊さない
        $text = $this->normalize($text); // 連続空白圧縮 + trim

        $bytes = strlen($text);
        if ($bytes < config()->integer('manual.analysis_min_text_bytes')) {
            throw AnalysisFailedException::unextractable(); // 画像/スキャン → v1 未対応の明示文言
        }
        if ($bytes > config()->integer('manual.analysis_max_text_bytes')) {
            throw AnalysisFailedException::tooLarge();
        }

        return new ExtractedText($text, $bytes, $kind);
    }
}
```

- PhpSpreadsheet はファイルパス入力のため `tmpfile()`/`tempnam` に書き出して読み込み、finally で削除
- 依存追加は `pnpm run audit:gate` を通し、`docs/supply-chain/review-checklist.md` の観点
  （メンテ状況・popularity・脆弱性履歴）を PR に記録

### PHPStan適合チェック
- [x] Storage::get の string|null → Assert::string
- [x] match は kindFor() の戻り値 enum/literal union で網羅

### テスト計画
- [ ] plain テキスト抽出（fixtures/sop.txt）
- [ ] PDF テキスト抽出（テスト用の最小 PDF fixture）
- [ ] xlsx 抽出（PhpSpreadsheet で生成した fixture）
- [ ] 空ファイル → unextractable / max_text_bytes 超過 → tooLarge（config を一時的に下げて検証）
- [ ] SJIS-win テキスト fixture → strict 検出で UTF-8 へ変換され UserInput 生成が壊れない
- [ ] 判定不能バイナリ fixture（乱数バイト列）→ unextractable（推測変換で LLM に渡らない）
- [ ] 変換後の再検証 NG ケース → unextractable

### リスク
- parser 品質（表崩れ・結合セル）は v1 の既知限界。抽出結果は LLM の統一 JSON 化で吸収し、
  最終品質は編集画面での人手修正が担保（doc/03 §3.4 の運用前提）


---

# 改訂後の施策 9（全文）

## 施策 9: materialize + 書き込み経路 inventory

### 変更箇所
- `app/Services/Manual/ScenarioService.php`（`materializeIntoLockedManual()` 追加 + upsertCut の共用化）
- `tests/Architecture/ScenarioWritePathInventoryTest.php`（新規）
- `docs/architecture.md`（経路 inventory 表の更新。施策 13）

### 変更後コード（骨子）

```php
/**
 * AI 解析結果の materialize (共有ロック規約の第 2 の書き込み経路。概念設計 §5)。
 *
 * **ロック済み前提メソッド**: transaction / lockForUpdate は呼び出し側 (AnalysisPipeline::
 * finalize の terminal tx) が最外層で張る。本メソッドは内側 transaction を張らない
 * (transaction/lock の層を 1 箇所に統一しロック順逆転を構造的に防ぐ)。
 * 前提の担保は 2 層 (PHPDoc 前提だけに依存しない):
 *  1. **呼び出し経路の構造的限定**: 本メソッドの呼び出し元は AnalysisPipeline のみ。
 *     ScenarioWritePathInventoryTest が「app/ 内で `materializeIntoLockedManual(` を
 *     呼ぶファイルは AnalysisPipeline だけ」を deny-by-default で機械検証する (施策 9)
 *  2. runtime 検査 (defensive):
 *     - DB::transactionLevel() === 0 → LogicException (tx 外呼び出しの検出)
 *     - $lockedManual->status !== analyzing → LogicException (terminal tx ごと rollback → failJob)
 *
 * - 既存 cuts 全削除 → 生成ツリー挿入 (再解析は全置換)。sort_order/parent/type はサーバ導出
 * - version+1 と status(analyzing→ready) を cuts と同一 tx で反映 (共有ロック規約)
 *
 * @param list<ScenarioStepInput> $steps
 */
public function materializeIntoLockedManual(VideoManual $lockedManual, array $steps): void
{
    if (DB::transactionLevel() === 0) {
        throw new LogicException('materialize はロック済みトランザクション内からのみ呼び出せます');
    }
    if ($lockedManual->status !== VideoManualStatus::Analyzing) {
        throw new LogicException('materialize は analyzing 中のみ実行できます');
    }

    // 全置換 (each->delete: save() と同じ理由で bulk delete を避ける。配下 Take は FK cascade)
    $lockedManual->cuts()->get()->each->delete();

    $changed = true; // 生成は常に実変更 (upsertCut の isDirty 追跡は新規行で必ず true)
    foreach ($steps as $stepIndex => $stepInput) {
        /** @var Collection<int, Cut> $noExisting */
        $noExisting = new Collection;
        $step = $this->upsertCut($lockedManual, $noExisting, $stepInput, CutType::Step, null, $stepIndex, $changed);
        foreach ($stepInput->points as $pointIndex => $pointInput) {
            $this->upsertCut($lockedManual, $noExisting, $pointInput, CutType::Point, $step->id, $pointIndex, $changed);
        }
    }

    $lockedManual->forceFill([
        'scenario_version' => $lockedManual->scenario_version + 1,
        'status' => VideoManualStatus::Ready,
    ])->save();
}
```

（`upsertCut` は既存実装のまま流用可能: `$input->id === null` の新規経路 + 空の existing
Collection で「全行 insert」として機能する。`GeneratedScenarioData::toScenarioSteps()` が
id=null を保証）

```php
// ScenarioWritePathInventoryTest — deny-by-default の token ベース静的走査
// (PrismDirectDispatchScanner と同じ token_get_all 流儀。コメント/docblock/文字列リテラル中の
//  出現は無視し誤検出しない)。走査対象: app/ 配下の .php
//
// 検出 1: 識別子/配列キー 'scenario_version' の出現 → allowlist 外のファイルなら fail
//   allowlist: ScenarioService.php (save/materializeIntoLockedManual)、
//              VideoManual.php (@property 宣言は docblock のため token 走査では元々対象外)
// 検出 2: 書き込み形 `'status' => VideoManualStatus::` / `->status = VideoManualStatus::`
//   → allowlist (ScenarioService.php / AnalysisJobService.php) 外なら fail
// 検出 3: materializeIntoLockedManual の「呼び出し」の経路限定。token 列で
//   `T_OBJECT_OPERATOR (->) + T_STRING(materializeIntoLockedManual) + '('` = 呼び出し、
//   `T_FUNCTION + T_STRING(materializeIntoLockedManual)` = 宣言、を区別する。
//   - 宣言は ScenarioService.php にのみ存在してよい
//   - 呼び出しは AnalysisPipeline.php にのみ存在してよい (それ以外のファイルは fail。
//     **ScenarioService 自身の中に新たな呼び出しを書いても fail** = ファイル単位 allowlist の
//     抜け穴を塞ぐ)
//
// scanner の自己検証 (PromptGuardrailTest の scanner self-test と同型):
//   - 合成ソース「コメント内の materializeIntoLockedManual(」→ 検出しない
//   - 合成ソース「$this->materializeIntoLockedManual($m, $s);」→ 呼び出しとして検出する
//   - 合成ソース「public function materializeIntoLockedManual(」→ 宣言として検出する
//   - 走査対象 (app/) が解決でき、対象ファイル数 > 0 (degenerate PASS 防止)
```

### PHPStan適合チェック
- [x] `new Collection` は `Collection<int, Cut>` として @var 注記
- [x] list<ScenarioStepInput> の型貫通

### テスト計画
- [ ] materialize 成功: steps+points ツリーが sort_order 0..N-1 / parent_cut_id / type
      サーバ導出で保存され、version+1 / status=ready
- [ ] 再解析: 既存 cuts が全置換される（旧 cut id が消える）
- [ ] analyzing 以外で呼ぶと LogicException（terminal tx rollback 経路は施策 6 のテスト）
- [ ] ScenarioWritePathInventoryTest 本体: 現行コードベースで green（allowlist 網羅）
- [ ] scanner 自己検証: 合成ソースで「コメント内出現は無視 / `->materializeIntoLockedManual(`
      呼び出しは検出 / `function materializeIntoLockedManual(` 宣言は検出」を unit で固定
      （許可外呼び出しを検出できることの証明）
- [ ] 走査対象ディレクトリ解決 + 対象ファイル数 > 0 の degenerate PASS 防止

### リスク
- token 走査でもエイリアス経由の間接呼び出し（callable 化等）は検出できないが、
  そのような迂回はレビューで弾く前提（fail-closed の第一防衛 + runtime 検査の第二防衛）


---

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類し、Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力
