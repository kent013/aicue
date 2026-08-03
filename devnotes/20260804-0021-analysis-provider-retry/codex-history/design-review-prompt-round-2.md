# Round 2: Round 1 指摘への対応と修正版詳細設計

Round 1 の [Warning] 5 件・[Suggestion] 2 件をすべて捌きました ([Critical] はありませんでした)。

- **[Warning] `isTransient()` の順序**: 判定順を「retryable を先・deny を後」へ入れ替え、
  deny 側 (429/413) を `::class` の厳密比較にしました (派生型を巻き込まない)。
- **[Warning] `userMessageFor()` の 408/5xx 未分岐**: `extractHttpStatus(Throwable): ?int` を追加し、
  **`isTransient()` と `userMessageFor()` の両方から使う**ようにしました
  (status の解釈を二重管理しない)。408 → timedOut / 500・502・503・504 → providerBusy。
- **[Warning] Yaml の mixed 絞り込み**: `expect()` をやめ `Webmozart\Assert\Assert` で
  静的に潰す形にしました。
- **[Warning] Pest グローバル const/関数の衝突**: 新規定数とヘルパを
  **`final class Tests\Support\AnalysisBudget`** に集約しました
  (`Tests\Support\PromptYaml` と同じ方針。360 の値も 1 箇所に集約)。
- **[Warning] deadline テストの CI 揺れ**: `travelTo()` で時刻固定 +
  `ThrowingPromptFake($script, advanceSeconds:)` が**明示的に時計を進める**設計にしました。
- **[Suggestion] 503 連続失敗の最終文言**: テストケースを追加しました。

以下、対応マトリクスと修正版の詳細設計全文です。再レビューをお願いします。

---

# 対応マトリクス: design-review Round 1

## [Warning] `isTransient()` の順序が継承変更に脆い (施策 3)
- 判断: **対応する**
- 根拠: 指摘は正しい。`PrismProviderOverloadedException` が将来 `PrismRateLimitedException` の
  派生になると、先に置いた deny 判定に食われて 529 が非 retry になる。
- 対応内容: 判定順を **「retryable 型を先に、deny を後に」** へ入れ替え、
  さらに deny 側 (429 / 413) を **`$exception::class === X::class` の厳密比較**にした。
  これで「派生型が deny に巻き込まれる」経路が構造的に消える。

## [Warning] `userMessageFor()` が generic `PrismException` の 408/500/502/503/504 を分岐していない (施策 4)
- 判断: **対応する**
- 根拠: 指摘は正しい。`isTransient()` では 408/5xx を区別しているのに、
  文言側は default の汎用文言に落ちており H4 (理由別の次アクション) が一貫しない。
- 対応内容: `extractHttpStatus(Throwable): ?int` を private ヘルパとして追加し、
  **`isTransient()` と `userMessageFor()` の両方から使う** (判定の二重管理を避ける)。
  分岐: 408 → `timedOut()` / 500・502・503・504 → `providerBusy()`。

## [Warning] Architecture テストの `Yaml::parseFile()` 後の型絞り込みが `expect()` 依存 (施策 5)
- 判断: **対応する**
- 対応内容: `Webmozart\Assert\Assert::isArray()` / `Assert::integer()` で静的に `mixed` を潰す
  (`expect()` は PHPStan の narrowing に効かない)。既存の `Assert` 利用イディオムに揃う。

## [Warning] Pest のファイルスコープ `const` / 関数の衝突 (施策 5)
- 判断: **対応する**
- 対応内容: 新規に導入する予定だったグローバル `const` / 関数をやめ、
  **`tests/Support/AnalysisBudget.php` (`final class`)** に `public const` + static メソッドとして集約する。
  2 つの Architecture テストが同じ 1 箇所から C を読むため、
  **360 という値が 2 ファイルに重複しない**という副次的な利点もある。
  既存の `AnalysisTokenBudgetInvariantTest` のトークン系 const
  (`MODEL_CONTEXT_TOKENS` 等) は衝突実績が無いため**そのまま維持**する
  (既存テストの不要な書き換えを避ける)。

## [Warning] deadline 系テストが時計進行に依存して CI で揺れる (施策 6)
- 判断: **対応する**
- 対応内容: deadline 系テストは **`travelTo()` で時刻を固定**し、
  `ThrowingPromptFake` 側で**明示的に `travel()` して時計を進める**設計にする
  (実時間の経過に依存しない)。
  → `ThrowingPromptFake` に「呼び出しごとに時計を進める秒数」を渡せるようにする。

## [Suggestion] 503 連続失敗時の「最終文言」ケースを追加
- 判断: **対応する**
- 対応内容: テスト計画 (A) に
  「`PrismException(previous=503)` ×3 (試行上限) → failed / `error` = providerBusy 文言 / 予約 Released」
  を追加。

## [Suggestion] `withBoundedRetry` の設計 (ループ先頭 deadline guard / deny-by-default / off-by-one)
- 判断: **維持** (「概ね妥当」との評価)。

## [Suggestion] DESIGN.md / Atomic Design は該当なし
- 判断: **維持**。


---

# 修正版 詳細設計 (全文)

# 詳細設計: AI 解析の時間 budget 是正と provider 例外の有界リトライ (F-1-01)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  （撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### セキュリティ不変条件 (本設計に直結するもの)

7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `declare(strict_types=1)` + 日本語コメント
- config の読み出しは `config()->integer(...)` などの typed accessor (level 10 で `mixed` にしない)

## 概念設計リファレンス

- `devnotes/20260804-0021-analysis-provider-retry/conceptual-design.md` (Codex Round 5 で APPROVED)
- レビュー履歴: `conceptual-review-round-{1..5}.md` / `codex-history/conceptual-review-*`

### 確定した時間 budget (概念設計より転記)

| 記号 | 項目 | 値 | 位置づけ |
|---|---|---|---|
| C | 1 呼び出しの client timeout | **360s** | 実測 274s (max_tokens 16000 飽和) の約 1.31 倍の**運用上限** |
| D | パイプライン deadline | **1,080s** = 3C | 各 LLM 試行の**開始可否**のみを決めるソフト予算 |
| M₁ | finalize モデル予算 | 30s | deadline 通過後の terminal tx + commit/release + 通知 |
| S | 安全余白 | 90s | P (worker が alarm を張ってから `run()` 入口まで) + タイマー精度 + シグナル配送 + ログ |
| T | job `$timeout` | **1,560s** | モデル上限 `D + C + M₁ = 1,470s` に対し 90 秒の明示的余白 |
| — | queue `retry_after` | **1,680s** | T < retry_after |
| — | 予約 TTL | 1,800s (据置) | retry_after < TTL |
| — | stale 閾値 | 30 分 (据置) | TTL ≤ stale |

> **P の起点 (Round 5 の指摘反映)**: `Worker::registerTimeoutHandler()` は
> `runJob()` **より前**に `pcntl_alarm()` を張る (`Queue/Worker.php:245-257`)。
> したがって P は「worker が alarm を設定した時点 → `AnalysisPipeline::run()` 入口」であり、
> payload 復元 / handler 解決 / DI / `findOrFail` を含む。いずれも単一行 SELECT と
> コンテナ解決でミリ秒オーダー。受容条件は `P + その他モデル外要因 ≤ 90 秒`。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | client timeout を 360 秒へ (解析 3 プロンプト) | `resources/prompts/{sop-extract,work-decomposition,scenario-generation}.yaml` | 高 |
| 2 | 時間 budget 連鎖の更新 | `config/manual.php`, `config/queue.php`, `app/Jobs/Manual/RunManualAnalysis.php` | 高 |
| 3 | deadline 対応 + transient 例外の有界リトライ | `app/Services/Manual/AnalysisPipeline.php` | 高 |
| 4 | 失敗文言の理由別分岐 (H4) | `app/Exceptions/Manual/AnalysisFailedException.php`, `AnalysisPipeline.php` | 高 |
| 5 | Architecture 不変条件の更新 | `tests/Architecture/AnalysisTimeBudgetInvariantTest.php`, `AnalysisTokenBudgetInvariantTest.php` | 高 |
| 6 | Feature テスト (リトライ / deadline / 会計) + テスト double | `tests/Feature/Projects/AnalysisPipelineTest.php`, `tests/Support/ThrowingPromptFake.php` | 高 |
| 7 | ドキュメント更新 | `docs/architecture.md` | 中 |

---

## 施策 1: client timeout を 360 秒へ (解析 3 プロンプト)

### 変更箇所

- `resources/prompts/sop-extract.yaml` (L3-10)
- `resources/prompts/work-decomposition.yaml` (L3-10)
- `resources/prompts/scenario-generation.yaml` (L3-10)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `AnalysisTokenBudgetInvariantTest` (施策 5 で更新)
- **`example-summary.yaml` は解析パイプライン外なので変更しない** (timeout 60 のまま)

### 現行コード (3 本共通。ヘッダコメントのみ差分)

```yaml
# max_tokens: 16000 は token budget の出力予約 (AnalysisTokenBudgetInvariantTest が固定)。
# client_options.timeout: 120 は時間 budget の前提値 (AnalysisTimeBudgetInvariantTest が固定)。
name: scenario-generation
provider: anthropic
model: claude-sonnet-4-5-20250929
max_tokens: 16000
client_options:
  timeout: 120
```

### 変更後コード

```yaml
# max_tokens: 16000 は token budget の出力予約 (AnalysisTokenBudgetInvariantTest が固定)。
# client_options.timeout: 360 は時間 budget の 1 呼び出し上限 C
# (AnalysisTimeBudgetInvariantTest / AnalysisTokenBudgetInvariantTest が固定)。
#
# 360 秒の根拠 (保証値ではなく運用上限):
#   claude-sonnet-4-5-20250929 に max_tokens=16000 を飽和生成させた実測が 273.9 秒
#   (2026-08-04 JST, 非ストリーミング, 58.4 token/s)。その約 1.31 倍を上限とした。
# 注意: この値は config/prism.php の request_timeout (30s) を **上書きする**。
#   prism-prompt の Prompt::resolveClientOptions() → Prism の
#   Anthropic::client() の withOptions() が Guzzle の timeout を後勝ちで書き換えるため。
name: scenario-generation
provider: anthropic
model: claude-sonnet-4-5-20250929
max_tokens: 16000
client_options:
  timeout: 360
```

### PHPStan 適合チェック

- [x] YAML のみ (PHP 変更なし)

### テスト計画

- [x] `AnalysisTokenBudgetInvariantTest`: 解析 3 本の `client_options.timeout` が 360 で一致
- [x] `AnalysisTokenBudgetInvariantTest`: 解析 3 本の `max_tokens` が 16000 で一致 (新規)
- [x] `PromptClientTimeoutInvariantTest`: 既存 (全 YAML が正の int の timeout を宣言) — 変更不要

### リスク

- 1 呼び出しが最大 360 秒 worker を占有する。解析専用レーン (`database-analysis`) のため
  他ジョブ (media / render / default) には影響しない。

---

## 施策 2: 時間 budget 連鎖の更新

### 変更箇所

- `config/manual.php` (L15-16 付近に追加)
- `config/queue.php` (L51-58 `database-analysis`)
- `app/Jobs/Manual/RunManualAnalysis.php` (L30-38)

### 波及変更

- TypeScript 型定義: なし / API Resource・DTO: なし
- テストファイル: `AnalysisTimeBudgetInvariantTest` (施策 5)

### 現行コード

```php
// config/manual.php
    // LLM 出力 JSON の検証失敗時の有界リトライ回数 (§10.7-2。計 1+N 試行)
    'analysis_llm_max_retries' => 2,
```

```php
// config/queue.php
        'database-analysis' => [
            ...
            'retry_after' => 1560,
```

```php
// app/Jobs/Manual/RunManualAnalysis.php
    /**
     * worst-case (LLM 3 段 × 3 試行 × client timeout 120s = 1,080s) + 抽出/解析余裕 180s + マージン。
     * timeout (1,380) < retry_after (1,560) < 予約 TTL (1,800) の連鎖は
     * AnalysisTimeBudgetInvariantTest が CI 固定する。
     */
    public int $timeout = 1380;
```

### 変更後コード

```php
// config/manual.php
    // LLM 呼び出しの有界リトライ回数 (計 1+N 試行)。JSON 検証失敗と transient な
    // provider/connection 例外の両方に適用する (AnalysisPipeline::withBoundedRetry)
    'analysis_llm_max_retries' => 2,

    // AI 解析パイプライン全体の実時間 deadline (秒)。AnalysisPipeline::run() 入口を T0 とし、
    // 各 LLM 試行の「開始可否」だけを決めるソフト予算 (走行中の呼び出しは中断しない)。
    // 値 = 3 段 × prompt YAML の client_options.timeout (360s) = 全段にフル ceiling の
    // 1 回を許す最小値。ハード上限は RunManualAnalysis::$timeout (SIGALRM)。
    'analysis_deadline_seconds' => 1080,
```

```php
// config/queue.php
        'database-analysis' => [
            ...
            // job timeout (1,560s) < retry_after (1,680s) < 予約 TTL (1,800s)
            // (AnalysisTimeBudgetInvariantTest が連鎖を固定)
            'retry_after' => 1680,
```

```php
// app/Jobs/Manual/RunManualAnalysis.php
    /**
     * 時間 budget の worst-case (概念設計 §時間 budget の連鎖):
     *   deadline D (1,080s = 3 × client timeout) — AnalysisPipeline が各試行の開始前に検査
     *   + client timeout C (360s)                — deadline 直前に開始した 1 呼び出し分
     *   + finalize モデル予算 M₁ (30s)           — terminal tx + commit/release + 通知
     *   + 安全余白 S (90s)                       — worker が alarm を張ってから run() 入口まで
     *                                              (P) + タイマー精度 + シグナル配送 + ログ
     *   = 1,560s
     * モデル上限 D + C + M₁ = 1,470s に対し 90 秒の明示的余白がある。
     * timeout (1,560) < retry_after (1,680) < 予約 TTL (1,800) ≤ stale 閾値 (1,800) の連鎖は
     * AnalysisTimeBudgetInvariantTest が CI 固定する。
     *
     * NOTE: 「3 段 × 3 試行 × timeout」という積のモデルは廃止した (リトライは deadline で
     *       打ち切るため、worst-case は積ではなく D + C になる)。
     */
    public int $timeout = 1560;
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (プロパティ型は `int`)
- [x] null 安全 (該当なし)
- [x] DTO を返している (該当なし)
- [x] Generics の型パラメータ (該当なし)

### テスト計画

- [x] `AnalysisTimeBudgetInvariantTest` を新算術へ更新 (施策 5)

### リスク

- `retry_after` 変更は **既に queue テーブルに入っている in-flight ジョブには適用されない**
  (値は worker が読む)。デプロイ時の一時的な混在は、`$tries = 1` と
  `failJob` の冪等性により会計上の問題を生まない。

---

## 施策 3: deadline 対応 + transient 例外の有界リトライ

### 変更箇所

- `app/Services/Manual/AnalysisPipeline.php`
  - `run()` (L53-75): deadline の生成と各段への伝播
  - `runExtractStep()` / `runDecomposeStep()` / `runGenerateStep()` (L126-171): 引数追加
  - `withBoundedRetry()` (L235-255): deadline guard + transient 判定
  - 新規 `isTransient()` private メソッド
  - クラス docblock の更新

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Projects/AnalysisPipelineTest.php` (施策 6)、
  `tests/Support/ThrowingPromptFake.php` (新規・施策 6)
- **`AnalysisPipeline` の public API (`run(int): void`) は不変** → 呼び出し元
  (`RunManualAnalysis::handle`, 既存テスト) への波及なし

### 現行コード

```php
    public function run(int $analysisJobId): void
    {
        $job = AnalysisJob::query()->findOrFail($analysisJobId);
        try {
            if (! $this->startJob($job)) {
                return; // 重複配送 / stale 回復後の遅延配送 → no-op
            }
            $document = $job->sourceDocument;
            Assert::notNull($document, 'trigger が必ず associate している');

            $text = $this->extractor->extract($document);
            $extracted = $this->runExtractStep($job, $document, $text);
            $decomposition = $this->runDecomposeStep($job, $extracted);
            $generated = $this->runGenerateStep($job, $decomposition);
            if ($this->finalize($job, $generated)) {
                $this->notifications->notifyAnalysisFinished($job->refresh());
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->jobs->failJob($job, $this->userMessageFor($exception));
        }
    }
```

```php
    /**
     * LLM 段の共通有界リトライ (JSON 検証失敗のみ。長さ・provider 例外はリトライしない)。
     *
     * @template T
     *
     * @param  callable(): T  $attempt
     * @return T
     */
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

### 変更後コード

```php
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Exceptions\PrismRequestTooLargeException;
```

```php
    /**
     * transient と断定できる provider 側 HTTP status (generic PrismException 経由で来る)。
     * 429/413/529 は専用例外型で来るため、ここには含めない。
     *
     * @var list<int>
     */
    private const TRANSIENT_HTTP_STATUSES = [408, 500, 502, 503, 504];

    public function run(int $analysisJobId): void
    {
        $job = AnalysisJob::query()->findOrFail($analysisJobId);

        // 実時間 deadline (ソフト予算)。各 LLM 試行の「開始可否」だけを決め、
        // 走行中の呼び出しは中断しない (中断は prompt YAML の client_options.timeout)。
        // ハード上限は RunManualAnalysis::$timeout (worker の SIGALRM)。
        $deadline = CarbonImmutable::now()
            ->addSeconds(config()->integer('manual.analysis_deadline_seconds'));

        try {
            if (! $this->startJob($job)) {
                return; // 重複配送 / stale 回復後の遅延配送 → no-op
            }
            $document = $job->sourceDocument;
            Assert::notNull($document, 'trigger が必ず associate している');

            $text = $this->extractor->extract($document);
            $extracted = $this->runExtractStep($job, $document, $text, $deadline);
            $decomposition = $this->runDecomposeStep($job, $extracted, $deadline);
            $generated = $this->runGenerateStep($job, $decomposition, $deadline);
            if ($this->finalize($job, $generated)) {
                // succeeded 到達時のみ・terminal tx の commit 後に通知 (stale 先勝ち false は通知しない)
                $this->notifications->notifyAnalysisFinished($job->refresh());
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->jobs->failJob($job, $this->userMessageFor($exception));
        }
    }
```

各段は `$deadline` を受け取り `withBoundedRetry` へ渡すだけ (例: generate 段):

```php
    /** generate 段: カット群生成 */
    private function runGenerateStep(
        AnalysisJob $job,
        WorkDecompositionData $decomposition,
        CarbonImmutable $deadline,
    ): GeneratedScenarioData {
        $generated = $this->withBoundedRetry(
            $deadline,
            AnalysisStep::Generate,
            fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(
                ScenarioGenerationPrompt::make($decomposition->toJsonString())->executeSync(),
            ),
        );

        $this->updateProgress($job, AnalysisStep::Generate, 90);

        return $generated;
    }
```

```php
    /**
     * LLM 段の共通有界リトライ。
     *
     * 打ち切り条件は 2 つ:
     *  (a) 試行回数 (config manual.analysis_llm_max_retries。計 1+N 試行)
     *  (b) 実時間 deadline (config manual.analysis_deadline_seconds)
     *
     * deadline の判定は **「deadline を過ぎたか」の真偽のみ**で行い、残り時間を
     * client timeout へ反映しない。これは意図的である: deadline の 1 秒前に開始した
     * 試行にも client timeout の全体 (C) を許すことで、job の worst-case を
     * 「D + C」という単純な形に閉じている (概念設計 §時間 budget)。
     * 残り時間を timeout に渡す実装へ変えるとこのモデルが壊れる。
     *
     * @template T
     *
     * @param  callable(): T  $attempt
     * @return T
     */
    private function withBoundedRetry(CarbonImmutable $deadline, AnalysisStep $step, callable $attempt): mixed
    {
        $maxRetries = config()->integer('manual.analysis_llm_max_retries');
        for ($tryCount = 0; ; $tryCount++) {
            if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                throw AnalysisFailedException::timedOut();
            }
            try {
                return $attempt();
            } catch (Throwable $exception) {
                if ($tryCount >= $maxRetries || ! $this->isTransient($exception)) {
                    throw $exception; // 打ち切り → run() の catch → failJob
                }
                Log::warning('AI 解析の LLM 呼び出しを再試行します', [
                    'step' => $step->value,
                    'attempt' => $tryCount + 1,
                    'max_attempts' => $maxRetries + 1,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    /**
     * 再試行してよい例外か (deny-by-default)。
     *
     * 写像の根拠 (vendor 実装より):
     * - cURL 28/6/7/35/52 → Guzzle ConnectException → Illuminate ConnectionException
     * - HTTP 429/529/413 は Prism の専用例外型
     * - それ以外の HTTP エラーは generic PrismException だが、previous に
     *   Illuminate\Http\Client\RequestException を保持するので status を型安全に読める
     *
     * 判定順は **retryable を先・deny を後**にする。deny 側を先に置くと、将来
     * 「retryable な型が deny 型の派生になる」変更が入ったときに黙って非 retry 化するため。
     * deny 側は同じ理由で `::class` の厳密比較にしている (派生型を巻き込まない)。
     */
    private function isTransient(Throwable $exception): bool
    {
        // (1) retryable と断定できる型を先に許可する
        if ($exception instanceof LlmOutputInvalidException
            || $exception instanceof ConnectionException
            || $exception instanceof PrismProviderOverloadedException) {
            return true;
        }

        // (2) 決定論的 (再試行しても同じ結果) を厳密比較で deny する
        if ($exception::class === PrismRateLimitedException::class
            || $exception::class === PrismRequestTooLargeException::class) {
            return false;
        }

        // (3) generic PrismException は previous の HTTP status で判定する
        $status = $this->extractHttpStatus($exception);

        return $status !== null && in_array($status, self::TRANSIENT_HTTP_STATUSES, true);
    }

    /**
     * generic PrismException が保持する provider 側 HTTP status を型安全に取り出す。
     * 取得できない場合は null (= 判定不能 → fail-fast)。
     *
     * `PrismException::providerRequestErrorWithDetails()` は previous に
     * Illuminate\Http\Client\RequestException を渡すため、そこから status を読む
     * (`getCode()` は他 factory で 0 になるため多義的で使わない)。
     */
    private function extractHttpStatus(Throwable $exception): ?int
    {
        if (! $exception instanceof PrismException) {
            return null;
        }

        $previous = $exception->getPrevious();
        if (! $previous instanceof RequestException) {
            return null;
        }

        return $previous->response->status();
    }
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`bool` / `mixed` + `@return T`)
- [x] null 安全: `getPrevious()` は `?Throwable` → **ローカル変数に格納してから `instanceof`**
      で narrowing する (`$exception->getPrevious()->response` の直書きはしない)
- [x] DTO を返している (各段は既存 DTO を返す。配列返却なし)
- [x] Generics の型パラメータ: `@template T` + `@param callable(): T` は現行踏襲
- [x] `config()->integer()` で `mixed` を作らない
- [x] `in_array` は `list<int>` 定数 + `strict: true`

### テスト計画

施策 6 を参照 (Feature テストで例外型ごとの挙動を固定)。

### リスク

- **`withBoundedRetry` の catch を `Throwable` に広げる**ため、`isTransient()` の
  deny-by-default が壊れると想定外の例外までリトライされる。
  → 例外型ごとの Feature テストで retryable / 非 retryable の両側を固定する。
- リトライが増えることで LLM 課金 (チケットではなく API コスト) が増えうる。
  上限は「3 段 × 3 試行」かつ deadline で有界。
- `Log::warning` が増える。解析専用レーンのため量は限定的。

---

## 施策 4: 失敗文言の理由別分岐 (H4)

### 変更箇所

- `app/Exceptions/Manual/AnalysisFailedException.php` (ファクトリ 2 つ追加)
- `app/Services/Manual/AnalysisPipeline.php` `userMessageFor()` (L286-295)

### 波及変更

- TypeScript 型定義: **なし** (`AnalysisJobProps.error` は `string | null` のまま)
- API Resource/DTO: **なし** (`AnalysisJobData::toArray()` の shape 不変)
- Svelte: **なし** (`AnalysisPanel.svelte:294-297` が `failedJob.error` をそのまま `Alert` に出す)
- テストファイル: `tests/Feature/Projects/AnalysisPipelineTest.php` (施策 6)

### 現行コード

```php
    /** ユーザー向けエラー文言 (内部詳細を error 列に漏らさない) */
    private function userMessageFor(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof AnalysisFailedException,
            $exception instanceof InsufficientTicketsException => $exception->getMessage(),
            $exception instanceof LlmOutputInvalidException => $exception->userMessage(),
            default => '解析に失敗しました。時間をおいて再実行してください。',
        };
    }
```

### 変更後コード

```php
// app/Exceptions/Manual/AnalysisFailedException.php
    /** パイプラインの実時間 deadline 超過 / provider の応答が client timeout を超えた */
    public static function timedOut(): self
    {
        return new self(
            '解析が時間内に終わりませんでした。手順書を分割して短くするか、'
            .'しばらく時間をおいて再実行してください。'
        );
    }

    /** provider の混雑 (429 / 529)。入力を変えても解決しないため待つ以外の行動がない */
    public static function providerBusy(): self
    {
        return new self('AI が混み合っています。しばらく時間をおいて再実行してください。');
    }
```

```php
// app/Services/Manual/AnalysisPipeline.php
    /**
     * ユーザー向けエラー文言 (内部詳細を error 列に漏らさない)。
     * 理由ごとに「次に取れる行動」が変わるため分岐する (H4)。
     *
     * HTTP status の取り出しは isTransient() と同じ extractHttpStatus() を使う
     * (retryable 判定と文言分岐で status の解釈を二重管理しない)。
     */
    private function userMessageFor(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof AnalysisFailedException,
            $exception instanceof InsufficientTicketsException => $exception->getMessage(),
            $exception instanceof LlmOutputInvalidException => $exception->userMessage(),
            // provider 応答が client timeout を超えた (cURL 28 等)
            $exception instanceof ConnectionException => AnalysisFailedException::timedOut()->getMessage(),
            // provider 混雑 (429 / 529)
            $exception instanceof PrismRateLimitedException,
            $exception instanceof PrismProviderOverloadedException => AnalysisFailedException::providerBusy()->getMessage(),
            // 入力過大 (413) は既存の「分割してアップロード」文言を再利用する
            $exception instanceof PrismRequestTooLargeException => AnalysisFailedException::tooLarge()->getMessage(),
            // generic PrismException: previous の HTTP status で理由を分ける
            $this->extractHttpStatus($exception) === 408 => AnalysisFailedException::timedOut()->getMessage(),
            in_array($this->extractHttpStatus($exception), [500, 502, 503, 504], true)
                => AnalysisFailedException::providerBusy()->getMessage(),
            default => '解析に失敗しました。時間をおいて再実行してください。',
        };
    }
```

> `AnalysisFailedException::timedOut()` は施策 3 の deadline guard でも投げられ、
> その場合は 1 つ目の分岐 (`instanceof AnalysisFailedException`) で同じ文言になる。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`string` / `self`)
- [x] `match(true)` による**例外型ディスパッチ** (文字列比較なし)
- [x] null 安全 (該当なし)

### テスト計画

施策 6 を参照。

### リスク

- 文言が長くなると `analysis_jobs.error` 列に収まるかが問題になる。
  → **実装前に migration の列型を確認**する (`text` なら問題なし)。
  文言は最長でも 60 文字程度。

---

## 施策 5: Architecture 不変条件の更新

### 変更箇所

- `tests/Support/AnalysisBudget.php` (**新規**。定数と YAML 読み出しの単一の窓口)
- `tests/Architecture/AnalysisTimeBudgetInvariantTest.php` (全面更新)
- `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` (timeout の pin を `AnalysisBudget` 経由へ)

> **Pest のグローバル `const` / 関数の衝突を避けるため、新規の定数・ヘルパは
> グローバルではなく `final class Tests\Support\AnalysisBudget` に集約する**
> (`Tests\Support\PromptYaml` と同じ方針)。360 という値が 2 ファイルに重複しない利点もある。
> 既存の `AnalysisTokenBudgetInvariantTest` のトークン系 const
> (`MODEL_CONTEXT_TOKENS` 等) は衝突実績が無いため**そのまま維持**する。

### 現行コード (時間 budget 側の要点)

```php
test('LLM worst-case (3段×3試行×client timeout) が job timeout に収まる', function (): void {
    $attempts = 1 + config()->integer('manual.analysis_llm_max_retries'); // 3
    $clientTimeout = 120; // 各 YAML client_options.timeout と一致
    expect(3 * $attempts * $clientTimeout + 180)->toBeLessThanOrEqual((new RunManualAnalysis(1))->timeout);
});
```

### 変更後コード

```php
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
use Tests\Support\AnalysisBudget;

test('解析 3 プロンプトの client timeout は同値である', function (): void {
    expect(AnalysisBudget::clientTimeoutSecondsPerPrompt())
        ->toHaveCount(1, '解析 3 プロンプトの client_options.timeout が不一致');
});

test('deadline は client timeout の 3 段分 (全段にフル ceiling の 1 回を許す)', function (): void {
    expect(config()->integer('manual.analysis_deadline_seconds'))
        ->toBe(AnalysisBudget::STAGE_COUNT * AnalysisBudget::clientTimeoutSeconds());
});

test('job timeout は worst-case (deadline + client timeout 1 回 + finalize + 余白) を満たす', function (): void {
    // deadline 判定は「過ぎたか」のみなので、deadline 通過後に走りうるのは高々 1 回分の C
    $worstCase = config()->integer('manual.analysis_deadline_seconds')
        + AnalysisBudget::clientTimeoutSeconds()
        + AnalysisBudget::FINALIZE_BUDGET_SECONDS
        + AnalysisBudget::SAFETY_MARGIN_SECONDS;

    expect((new RunManualAnalysis(1))->timeout)->toBeGreaterThanOrEqual($worstCase);
});

test('モデル上限 (deadline + C + finalize) に対して明示的な安全余白がある', function (): void {
    $modelBound = config()->integer('manual.analysis_deadline_seconds')
        + AnalysisBudget::clientTimeoutSeconds()
        + AnalysisBudget::FINALIZE_BUDGET_SECONDS;

    expect((new RunManualAnalysis(1))->timeout - $modelBound)
        ->toBeGreaterThanOrEqual(AnalysisBudget::SAFETY_MARGIN_SECONDS);
});

test('解析ジョブの時間 budget 連鎖 (timeout < retry_after < 予約TTL <= stale閾値)', function (): void {
    // (現行テストをそのまま維持: 予約 TTL は台帳の公開 API reserve() で実測する)
});

test('解析ジョブの connection/queue 名が設定と drift しない', function (): void {
    // (現行テストをそのまま維持)
});

test('解析ジョブは自動再試行しない (tries=1。再実行は analyze 再トリガーのみ)', function (): void {
    // (現行テストをそのまま維持)
});
```

新規 `tests/Support/AnalysisBudget.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Yaml\Yaml;
use Webmozart\Assert\Assert;

/**
 * AI 解析の時間 budget 不変条件で使う定数と、prompt YAML からの読み出し。
 *
 * Pest のファイルスコープ const / 関数はテスト間で衝突しうるため、
 * Tests\Support\PromptYaml と同じく autoload されるクラスに集約する。
 * client timeout (C) の実値は prompt YAML が唯一の出所であり、
 * ここには「値」を複製しない (drift を作らない)。
 */
final class AnalysisBudget
{
    /** extract / decompose / generate */
    public const STAGE_COUNT = 3;

    /** M₁: deadline 通過後の terminal tx + commit/release + 通知 */
    public const FINALIZE_BUDGET_SECONDS = 30;

    /** S: P (worker alarm → run() 入口) + タイマー精度 + シグナル配送 + ログ */
    public const SAFETY_MARGIN_SECONDS = 90;

    /** 解析パイプラインの 3 プロンプト */
    public const PROMPT_NAMES = ['sop-extract', 'work-decomposition', 'scenario-generation'];

    /**
     * 解析 3 プロンプトの client_options.timeout を重複排除して返す
     * (要素 1 個 = 3 本が同値)。
     *
     * @return list<int>
     */
    public static function clientTimeoutSecondsPerPrompt(): array
    {
        $timeouts = [];
        foreach (self::PROMPT_NAMES as $name) {
            $yaml = Yaml::parseFile(resource_path("prompts/{$name}.yaml"));
            Assert::isArray($yaml, "{$name}.yaml が map ではありません");
            Assert::keyExists($yaml, 'client_options', "{$name}.yaml に client_options がありません");
            Assert::isArray($yaml['client_options'], "{$name}.yaml の client_options が map ではありません");
            Assert::keyExists($yaml['client_options'], 'timeout', "{$name}.yaml に client_options.timeout がありません");
            $timeout = $yaml['client_options']['timeout'];
            Assert::integer($timeout, "{$name}.yaml の client_options.timeout が int ではありません");
            $timeouts[] = $timeout;
        }

        return array_values(array_unique($timeouts));
    }

    /** 解析 3 プロンプト共通の client timeout (C)。不一致なら例外 */
    public static function clientTimeoutSeconds(): int
    {
        $timeouts = self::clientTimeoutSecondsPerPrompt();
        Assert::count($timeouts, 1, '解析 3 プロンプトの client_options.timeout が不一致です');

        return $timeouts[0];
    }
}
```

`AnalysisTokenBudgetInvariantTest` 側 (既存 const はそのまま維持):

```php
use Tests\Support\AnalysisBudget;

test('解析プロンプト YAML の max_tokens は出力予約と一致する', function (): void {
    // (現行テストを維持)
});

test('解析プロンプト YAML の client timeout は 3 本で一致し、時間 budget の C になる', function (): void {
    // 値そのものは prompt YAML が唯一の出所。ここでは「3 本一致」と
    // 「時間 budget の連鎖と整合する (deadline = 3C)」だけを固定する
    expect(AnalysisBudget::clientTimeoutSecondsPerPrompt())->toHaveCount(1);
    expect(config()->integer('manual.analysis_deadline_seconds'))
        ->toBe(AnalysisBudget::STAGE_COUNT * AnalysisBudget::clientTimeoutSeconds());
});
```

> **注意**: `AnalysisBudget::PROMPT_NAMES` と既存の `analysisPromptNames()` が
> 二重管理になるため、実装時に既存関数を `AnalysisBudget::PROMPT_NAMES` へ寄せる
> (テストケースは削らない = 禁止事項 3 に抵触しない)。

### PHPStan 適合チェック

- [x] `Yaml::parseFile()` の `mixed` は **`Webmozart\Assert\Assert` で静的に潰す**
      (`expect()` は PHPStan の narrowing に効かないため使わない)
- [x] `clientTimeoutSeconds(): int` / `clientTimeoutSecondsPerPrompt(): list<int>` の戻り型を明示
- [x] `array_values(array_unique(...))` で `list<int>` を保つ

### テスト計画

このテスト群そのものが不変条件の登録である。

### リスク

- Architecture レーンで `resource_path()` を使うため、`AnalysisTokenBudgetInvariantTest` と
  同様に app bootstrap が必要 (既存と同じなので問題なし)。

---

## 施策 6: Feature テスト + テスト double

### 変更箇所

- `tests/Support/ThrowingPromptFake.php` (新規)
- `tests/Feature/Projects/AnalysisPipelineTest.php` (テスト追加)

### 波及変更

- 既存テストの削除・上書きは行わない (禁止事項 3)。
  既存の「有界リトライ: 不正 JSON ×2 → 3 回目成功で succeeded」等はそのまま通る
  (`Prompt::fake()` の経路は変更しないため)。

### 新規テスト double

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use Kent013\PrismPrompt\Testing\PromptFake;
use Kent013\PrismPrompt\Testing\TextResponseFake;
use Throwable;

/**
 * 例外を投げられる PromptFake。AnalysisPipeline の transient 例外リトライと
 * deadline 打ち切りを決定論的に検証するために使う。
 *
 * `Prompt::installFake()` (パッケージの公開注入点。CannedPromptFake と同じ経路) で差し込む。
 * script に Throwable を混ぜると、その順番の呼び出しで throw する。
 *
 * $advanceSeconds > 0 のときは **1 呼び出しごとに `travel()` で時計を進める**。
 * deadline 系テストは実時間の経過に依存させない (CI で揺れないようにする)。
 */
final class ThrowingPromptFake extends PromptFake
{
    private int $index = 0;

    /**
     * @param  list<TextResponseFake|Throwable>  $script
     * @param  int  $advanceSeconds  1 呼び出しごとに進める仮想時間 (秒)。0 なら進めない
     */
    public function __construct(
        private readonly array $script,
        private readonly int $advanceSeconds = 0,
    ) {
        parent::__construct([]);
    }

    public function nextResponse(): TextResponseFake
    {
        $item = $this->script[$this->index] ?? throw new RuntimeException(
            'ThrowingPromptFake: script を使い切りました (想定より多く LLM が呼ばれています)'
        );
        $this->index++;

        if ($this->advanceSeconds > 0) {
            // Laravel の時間旅行ヘルパ。CarbonImmutable::now() が進む
            travel($this->advanceSeconds)->seconds();
        }

        if ($item instanceof Throwable) {
            throw $item;
        }

        return $item;
    }

    /** 実際に LLM 呼び出しが試行された回数 */
    public function attemptCount(): int
    {
        return $this->index;
    }
}
```

> `travel()` は Laravel の global helper。テストクラス外 (Support クラス) から呼ぶため、
> 実装時に `Illuminate\Foundation\Testing\Wormhole` 経由で呼ぶか、
> ヘルパが解決できることを確認する。解決できない場合は
> `CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($this->advanceSeconds))`
> を直接使う (どちらでも決定論性は同じ)。

生成用ヘルパ (テストファイル内):

```php
/** previous に指定 status の RequestException を持つ generic PrismException */
function prismHttpException(int $status): PrismException
{
    $response = new Response(new Psr7Response($status, [], '{"error":{"type":"x","message":"y"}}'));

    return PrismException::providerRequestErrorWithDetails(
        provider: 'Anthropic',
        statusCode: $status,
        errorType: 'x',
        errorMessage: 'y',
        previous: new RequestException($response),
    );
}
```

### 追加する Feature テスト

すべて `tests/Feature/Projects/AnalysisPipelineTest.php` に追加する
(既存の `pipelineContext()` ヘルパを再利用)。

#### (A) transient 例外のリトライ

- [ ] `ConnectionException ×1 → 2 回目成功で succeeded` — 予約は 1 件・`Committed` 1 回のみ
- [ ] `PrismProviderOverloadedException (529) ×1 → 2 回目成功で succeeded`
- [ ] `PrismException(previous = RequestException status 503) ×1 → 2 回目成功で succeeded`
- [ ] `ConnectionException ×3 (試行上限)` → failed。`error` が timeout 文言。予約は `Released`
- [ ] `PrismException(previous = 503) ×3 (試行上限)` → failed。`error` が providerBusy 文言。
      予約は `Released` (最終文言の回帰固定)

#### (B) 非 retryable 例外は即 failJob (リトライしない)

`ThrowingPromptFake::attemptCount()` が **1** であることを併せて検証する。

- [ ] `PrismRateLimitedException (429)` → failed / `error` = 「AI が混み合っています…」/ 試行 1 回
- [ ] `PrismRequestTooLargeException (413)` → failed / `error` = 「手順書が大きすぎます…」/ 試行 1 回
- [ ] `PrismException(previous = RequestException status 400)` → failed / 汎用文言 / 試行 1 回
- [ ] `PrismException(previous なし)` → failed / 汎用文言 / 試行 1 回

#### (C) deadline (すべて `travelTo()` で時刻を固定し、fake 側が明示的に時計を進める)

- [ ] `analysis_deadline_seconds = 0` → **LLM 呼び出しが 1 回も起きず** (`attemptCount() === 0`)
      failed。`error` が timeout 文言。予約は `Released`
- [ ] **deadline 直前でもフル試行が許される**: `travelTo()` で時刻固定 +
      `analysis_deadline_seconds = 1` かつ fake は時計を進めず正常応答 → **succeeded**。
      残り時間が C 未満でも試行を開始する (= `D + C` モデル) ことの固定
- [ ] リトライ中に deadline を超えたら打ち切る: `analysis_deadline_seconds = 100`、
      `ThrowingPromptFake(script: [ConnectionException, ConnectionException, ConnectionException],
      advanceSeconds: 60)` → 2 回目の試行後に仮想時計が deadline を超えるため
      `attemptCount()` が `1 + maxRetries` (=3) **未満** (=2) で打ち切られ、
      `error` が timeout 文言になる

#### (D) チケット会計の不変条件 (セキュリティ不変条件 #7)

- [ ] リトライして最終的に succeeded → `TicketReservation` は 1 件のみ・`Committed`・
      `ticket_ledger_entries` の consume が 1 件のみ (二重課金なし)
- [ ] リトライして最終的に failed → 予約は `Released`・`Committed` は 0 件 (課金済み failed なし)
- [ ] **強制終了 (SIGALRM 相当) の最終収束** — 即時 release は要求しない:
  - commit 前に中断 (= `failed()` 相当を呼ばずに `recoverStale` を走らせる) → 予約が `Released`
  - commit 済みで中断 → `failJob()` を呼んでも terminal guard で no-op、予約は `Committed` のまま
  - `failed()` 相当 → `recoverStale` → `releaseStale` の**どの順で走らせても**最終会計状態が同じ
    (逆順 `releaseStale` → `recoverStale` も検証する)

### PHPStan 適合チェック

- [x] `ThrowingPromptFake::$script` に `list<TextResponseFake|Throwable>` の phpdoc
- [x] `nextResponse(): TextResponseFake` は親のシグネチャと互換
- [x] テスト内の `RequestException` 生成は `new RequestException(new Response(...))` で
      型を明示 (`Illuminate\Http\Client\Response` を `Http::response()` から作る)
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 (`tests/Pest.php` のグローバル適用のみ)

### リスク

- `Prompt::installFake()` を使うため、`tests/Pest.php` の `StrayLlmCallGuard` と
  干渉しないことを確認する (guard は `Prompt` 層の fake で short-circuit される設計。
  `beforeEach` の `Prompt::stopFaking()` によるリークもない)。
- 時計操作 (`travel()` / `travelTo()`) は Pest の並列実行でも各プロセス内に閉じる。

---

## 施策 7: ドキュメント更新

### 変更箇所

- `docs/architecture.md` §AI 解析ジョブの運用契約 (L189-198)

### 現行コード

```markdown
- 解析ジョブ (`RunManualAnalysis`) は専用 queue connection **`database-analysis`**
  (queue=analysis、retry_after=1560) で流れる。…
- 時間 budget の連鎖 `job timeout (1,380s) < retry_after (1,560s) < 予約 TTL (1,800s) ≤ stale 閾値 (1,800s)`
  は `AnalysisTimeBudgetInvariantTest` が CI 固定する
```

### 変更後コード

```markdown
- 解析ジョブ (`RunManualAnalysis`) は専用 queue connection **`database-analysis`**
  (queue=analysis、retry_after=1680) で流れる。…
- 時間 budget の連鎖 `job timeout (1,560s) < retry_after (1,680s) < 予約 TTL (1,800s) ≤ stale 閾値 (1,800s)`
  は `AnalysisTimeBudgetInvariantTest` が CI 固定する。内訳は
  `deadline D (1,080s = 3 × client timeout) + client timeout C (360s) + finalize 予算 (30s) + 安全余白 (90s)`。
  **D は `AnalysisPipeline` が各 LLM 試行の開始前に検査するソフト予算**であり、走行中の呼び出しは
  中断しない (中断は C が担う)。ハード上限は worker の `$timeout` (SIGALRM)
- **LLM 呼び出しの実効タイムアウトは `resources/prompts/*.yaml` の `client_options.timeout`** である。
  この値は `config/prism.php` の `request_timeout` (30s) を **上書きする**
  (prism-prompt の `Prompt::resolveClientOptions()` → Prism の `Anthropic::client()` の
  `withOptions()` が Guzzle option を後勝ちで書き換えるため)。解析の timeout を調整するときは
  `config/prism.php` ではなく prompt YAML を見ること
- LLM 呼び出しの有界リトライ対象は **JSON 検証失敗 + transient な provider/connection 例外**
  (`ConnectionException` / 529 / 408・500・502・503・504)。429・413・その他は fail-fast で
  理由別のユーザー文言を `analysis_jobs.error` に残す
```

### テスト計画

- [x] ドキュメントのみ (テスト不要)。ただし記載値は施策 5 のテストが CI 固定する

---

## 実装順序 (テストファースト。思考原則 5)

1. 施策 5 の Architecture テストを**先に**新しい値へ書き換える → **fail を確認**
2. 施策 6 の Feature テスト (A)〜(D) を**先に**書く → **fail を確認**
3. 施策 1・2 (config / YAML) を適用 → Architecture テスト green
4. 施策 3・4 (`AnalysisPipeline` / 例外) を適用 → Feature テスト green
5. 施策 7 (docs)
6. 全検証コマンド green: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`
   (フロント変更は無いが、規約どおり全部回す)

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 変更は解析レーン (prompt YAML / config / `AnalysisPipeline` / 解析テスト) に閉じており、他 TODO と共有するファイルが無い。一方で **Architecture テストの値を書き換えるため、同じ値に触る他の作業と同時進行すると CI が壊れる**。単独の worktree で完結させ、テスト green を確認してから main へマージするのが安全。 |
| 競合リスク | `config/queue.php` は他レーン (render / media) も定義するファイルなので、同ファイルを触る TODO と並走すると conflict しうる。変更は `database-analysis` ブロックの 1 行のみなので解決は容易。`docs/architecture.md` も同様。 |

## 前提の事前確認 (設計時に検証済み)

- [x] **`analysis_jobs.error` 列は `text`**
      (`database/migrations/2026_07_11_000000_create_analysis_jobs_table.php:31`)。
      施策 4 の文言 (最長 60 文字程度) は問題なく収まる。
- [x] **`Prism\Prism\Exceptions\*` を app 層で参照しても `PromptGuardrailTest` に抵触しない**。
      同テストの `PrismDirectDispatchScanner` が検出するのは
      `Prism::text|structured|stream|embeddings|image|audio(` の **facade メソッド呼び出しのみ**
      (`TARGET_METHODS` / `containsPrismDirectCall`)。例外クラスの `use` / `instanceof` は対象外。
- [x] **`StrayLlmCallGuard` と `Prompt::installFake()` は干渉しない**。
      guard は `PrismManager::resolve()` を差し替えて stray を検出する仕組みで、
      `Prompt` 層で fake が入っていれば `executePrism()` が short-circuit して
      `PrismManager` に到達しない (`tests/Support/StrayLlmCallGuard.php:28-47`)。
      `tests/Pest.php:43-60` の `beforeEach`/`afterEach` が `Prompt::stopFaking()` で
      リークも防いでいる。`CannedPromptFake` が同じ `installFake()` 経路で既に運用されている。

## 未解決事項 / 申し送り

1. **[blocking follow-up] PDF テキスト抽出の文字化け**。
   `doc/reference/sample-sop/AS_作業手順書.pdf` の抽出結果が CP932→Latin-1 の mojibake になり、
   `ensureUtf8()` の `mb_check_encoding($text, 'UTF-8')` を通過して LLM に渡っている。
   **本設計では直さない**。別 finding / 別 TODO として起票が必要
   (対象: `SopTextExtractor::fromPdf()` / `ensureUtf8()` と `smalot/pdfparser` の CMap 処理)。
   これを直さない限り、同 PDF の解析は「完走するが内容は無意味」のままである。
2. **実 3 プロンプトでの生成レートは未実測**。計測したのは同一モデルの一般的な日本語 JSON 生成
   (system prompt なし)。ceiling 360s は実測 274s に対し 1.31 倍のマージンで吸収する設計だが、
   本番投入後に generate 段の実所要時間をログで観測し、必要なら C を見直す。
3. **ストリーミング化**は `kent013/laravel-prism-prompt` がストリーム実行 API を公開したら再検討する
   (本命の正攻法。現状は禁止事項 5 に抵触するため採れない)。
