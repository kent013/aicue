# アプリの使命 (North Star) — AGENTS.md より

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項 — AGENTS.md より

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

# セキュリティ不変条件 (抜粋) — AGENTS.md より

7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ

# 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

さらに本リポジトリ固有の思考原則:
1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件、とくに #7 課金の冪等性）
10. DESIGN.md準拠 / 11. Atomic Design準拠 (本設計に UI 変更は無いので該当なしのはず。もし見落としがあれば指摘してください)

【本設計でとくに厳しく見てほしい点】
- `withBoundedRetry` の catch を Throwable へ広げたことによる副作用 (握ってはいけない例外を握らないか)
- deadline guard の位置 (ループ先頭) が意図どおりか。無限ループ・off-by-one が無いか
- `isTransient()` の deny-by-default が正しいか。順序依存の罠が無いか
- `userMessageFor()` の分岐順序 (match(true) は上から評価される) に漏れ・取りこぼしが無いか
- テスト計画がチケット 2 フェーズ (reserve→commit/release) の不変条件を本当に固定できるか
- Architecture テストの新しい算術が壊れやすくないか (Pest のグローバル const 衝突を含む)
- PHPStan level 10 を実際に通せるか (とくに Yaml::parseFile の mixed 扱い、getPrevious の narrowing)
- オーバーエンジニアリングになっていないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
     */
    private function isTransient(Throwable $exception): bool
    {
        // 決定論的 (再試行しても同じ結果) → 明示的に deny
        if ($exception instanceof PrismRateLimitedException
            || $exception instanceof PrismRequestTooLargeException) {
            return false;
        }
        if ($exception instanceof LlmOutputInvalidException
            || $exception instanceof ConnectionException
            || $exception instanceof PrismProviderOverloadedException) {
            return true;
        }
        if (! $exception instanceof PrismException) {
            return false;
        }

        $previous = $exception->getPrevious();
        if (! $previous instanceof RequestException) {
            return false; // status を型安全に取得できない → fail-fast
        }

        return in_array($previous->response->status(), self::TRANSIENT_HTTP_STATUSES, true);
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

- `tests/Architecture/AnalysisTimeBudgetInvariantTest.php` (全面更新)
- `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` (timeout の pin 値 + 一致検証)

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
const ANALYSIS_STAGE_COUNT = 3;          // extract / decompose / generate
const ANALYSIS_FINALIZE_BUDGET_SECONDS = 30;  // M₁
const ANALYSIS_SAFETY_MARGIN_SECONDS = 90;    // S

/** 解析 3 プロンプトの client_options.timeout (全て同値であることも検証する) */
function analysisClientTimeoutSeconds(): int
{
    $timeouts = [];
    foreach (['sop-extract', 'work-decomposition', 'scenario-generation'] as $name) {
        $yaml = Yaml::parseFile(resource_path("prompts/{$name}.yaml"));
        expect($yaml)->toBeArray();
        $timeouts[] = $yaml['client_options']['timeout'] ?? null;
    }
    expect(array_unique($timeouts))->toHaveCount(1, '解析 3 プロンプトの client timeout が不一致');
    $timeout = $timeouts[0];
    expect($timeout)->toBeInt();

    return $timeout;
}

test('deadline は client timeout の 3 段分 (全段にフル ceiling の 1 回を許す)', function (): void {
    expect(config()->integer('manual.analysis_deadline_seconds'))
        ->toBe(ANALYSIS_STAGE_COUNT * analysisClientTimeoutSeconds());
});

test('job timeout は worst-case (deadline + client timeout 1 回 + finalize + 余白) を満たす', function (): void {
    // deadline 判定は「過ぎたか」のみなので、deadline 通過後に走りうるのは高々 1 回分の C
    $worstCase = config()->integer('manual.analysis_deadline_seconds')
        + analysisClientTimeoutSeconds()
        + ANALYSIS_FINALIZE_BUDGET_SECONDS
        + ANALYSIS_SAFETY_MARGIN_SECONDS;

    expect((new RunManualAnalysis(1))->timeout)->toBeGreaterThanOrEqual($worstCase);
});

test('モデル上限 (deadline + C + finalize) に対して明示的な安全余白がある', function (): void {
    $modelBound = config()->integer('manual.analysis_deadline_seconds')
        + analysisClientTimeoutSeconds()
        + ANALYSIS_FINALIZE_BUDGET_SECONDS;

    expect((new RunManualAnalysis(1))->timeout - $modelBound)
        ->toBeGreaterThanOrEqual(ANALYSIS_SAFETY_MARGIN_SECONDS);
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

`AnalysisTokenBudgetInvariantTest` 側:

```php
const ANALYSIS_CLIENT_TIMEOUT_SECONDS = 360; // 時間 budget の C (AnalysisTimeBudgetInvariantTest と対)

test('解析プロンプト YAML の max_tokens は出力予約と一致する', function (): void {
    // (現行テストを維持)
});

test('解析プロンプト YAML の client timeout は時間 budget の C と一致する', function (): void {
    foreach (analysisPromptNames() as $name) {
        $yaml = Yaml::parseFile(resource_path("prompts/{$name}.yaml"));
        expect($yaml)->toBeArray();
        expect($yaml['client_options']['timeout'] ?? null)
            ->toBe(ANALYSIS_CLIENT_TIMEOUT_SECONDS,
                "{$name}.yaml の client_options.timeout が時間 budget の C と不一致");
    }
});
```

> **Pest のグローバル const 衝突に注意**: `AnalysisTokenBudgetInvariantTest` は
> 既にファイルスコープで `const` を定義している。新規 const 名は
> 他テストファイルと重複しない名前にする (`ANALYSIS_` prefix で統一)。

### PHPStan 適合チェック

- [x] `Yaml::parseFile()` は `mixed` を返すため `expect()->toBeArray()` + 配列アクセス前に検証
- [x] `analysisClientTimeoutSeconds(): int` の戻り型を明示

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
 * 例外を投げられる PromptFake。AnalysisPipeline の transient 例外リトライを検証するために使う。
 *
 * `Prompt::installFake()` (パッケージの公開注入点) 経由で差し込む。
 * responses に Throwable を混ぜると、その順番の呼び出しで throw する。
 */
final class ThrowingPromptFake extends PromptFake
{
    /** @var list<TextResponseFake|Throwable> */
    private array $script;

    private int $index = 0;

    /** @param list<TextResponseFake|Throwable> $script */
    public function __construct(array $script)
    {
        parent::__construct([]);
        $this->script = $script;
    }

    public function nextResponse(): TextResponseFake
    {
        $item = $this->script[$this->index] ?? throw new \RuntimeException('script を使い切りました');
        $this->index++;

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

### 追加する Feature テスト

すべて `tests/Feature/Projects/AnalysisPipelineTest.php` に追加する
(既存の `pipelineContext()` ヘルパを再利用)。

#### (A) transient 例外のリトライ

- [ ] `ConnectionException ×1 → 2 回目成功で succeeded` — 予約は 1 件・`Committed` 1 回のみ
- [ ] `PrismProviderOverloadedException (529) ×1 → 2 回目成功で succeeded`
- [ ] `PrismException(previous = RequestException status 503) ×1 → 2 回目成功で succeeded`
- [ ] `ConnectionException ×3 (試行上限)` → failed。`error` が timeout 文言。予約は `Released`

#### (B) 非 retryable 例外は即 failJob (リトライしない)

`ThrowingPromptFake::attemptCount()` が **1** であることを併せて検証する。

- [ ] `PrismRateLimitedException (429)` → failed / `error` = 「AI が混み合っています…」/ 試行 1 回
- [ ] `PrismRequestTooLargeException (413)` → failed / `error` = 「手順書が大きすぎます…」/ 試行 1 回
- [ ] `PrismException(previous = RequestException status 400)` → failed / 汎用文言 / 試行 1 回
- [ ] `PrismException(previous なし)` → failed / 汎用文言 / 試行 1 回

#### (C) deadline

- [ ] `analysis_deadline_seconds = 0` → **LLM 呼び出しが 1 回も起きず** failed。
      `error` が timeout 文言。予約は `Released`
- [ ] **deadline 直前でもフル試行が許される**: `analysis_deadline_seconds = 1` かつ
      LLM が正常応答 → succeeded になる (残り時間が C 未満でも試行を開始する = `D + C` モデル)
- [ ] リトライ中に deadline を超えたら打ち切る: `ConnectionException` を投げる fake が
      `travel()` で時計を進める → 試行回数が `1 + maxRetries` **未満**で打ち切られ、
      `error` が timeout 文言

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


---

# 参考: 承認済み概念設計 (全文)

# 概念設計: AI 解析の時間 budget 是正と provider 例外の有界リトライ (F-1-01)

- 対象 finding: `devnotes/20260803-203721-bug-hunt/report.md` §High F-1-01
  (shard 詳細: `devnotes/20260803-203721-bug-hunt/shard-1/shard-report.md#F-1`)
- task_key: C
- 改訂: Codex 概念設計レビュー Round 1〜4 反映 (時間 budget の再定義と導出向きの修正 /
  生成レートの実測 (max_tokens 16000 飽和を含む) / 例外写像表と 500-504 の型安全な判定 /
  SIGALRM 時の会計を eventual guarantee へ / 360s を「運用上限」へ格下げ / pre-pipeline 予算 P の明示)

## 背景・課題

bug-hunt が「リポジトリ同梱のサンプル SOP (`doc/reference/sample-sop/AS_作業手順書.pdf`) で
AI 解析の generate 段が 120,002ms でタイムアウトし 2/2 で失敗する」を観測した。
`cURL error 28: Operation timed out after 120002 milliseconds with 0 bytes received`。
North Star の起点 (「SOP から AI がカット設計する」) が実運用サイズで機能しない。

### 事実確認 (すべてソース or 実測で裏取り済み。推測なし)

**(1) 実効タイムアウト 120s の出所 = 確定した。ssrf-pin は無関係。**

bug-hunt は「`config/prism.php` の `request_timeout` は 30s なのに観測は 120s。
`laravel-ssrf-pin` の transport 側 deadline が絡む可能性」としていたが、**ssrf-pin は経路にいない**。
実際の経路 (全て vendor / repo のコードを読んで確認):

| # | 位置 | 内容 |
|---|---|---|
| 1 | `resources/prompts/{sop-extract,work-decomposition,scenario-generation}.yaml` | `client_options: { timeout: 120 }` |
| 2 | `vendor/kent013/laravel-prism-prompt/src/Prompt.php:1076-1089` `resolveClientOptions()` | YAML の `client_options` をそのまま返す |
| 3 | 同 `Prompt.php:742-745` `executePrism()` | `$builder->withClientOptions($resolvedClientOptions)` |
| 4 | `vendor/echolabsdev/prism/src/Concerns/InitializesClient.php:16-21` | `Http::...->timeout(config('prism.request_timeout'))->connectTimeout(...)` = 30s |
| 5 | `vendor/echolabsdev/prism/src/Providers/Anthropic/Anthropic.php:112-121` | `$this->baseClient()->...->withOptions($options)` |

Laravel HTTP client の `timeout()` は Guzzle option `timeout` を書くだけなので、
**手順 5 の `withOptions(['timeout' => 120])` が手順 4 の 30 を上書きする**。
よって実効タイムアウトは **YAML の 120 秒**。観測値 120,002ms と完全に一致する。
`config/prism.php` の 30s は解析経路では一切効いていない。

なお 120 という値は `AnalysisTokenBudgetInvariantTest` (YAML 側) と
`AnalysisTimeBudgetInvariantTest` (worst-case 算術側) が二重に pin しており、
片方だけ動かすと CI が落ちる (= 設計として連鎖している値)。

**(2) 120s では原理的に足りない。生成レートを実測して定量確定させた。**

- 解析 3 プロンプトはいずれも `max_tokens: 16000`。
- 呼び出しは **非ストリーミング**の単発 HTTP (`Prism::text()->asText()`)。
  Anthropic は非ストリーミングでは生成完了までレスポンス本体を返さないため、
  1 回の呼び出しの実時間 ≒ **出力 token を生成し切るまでの時間**。
- 観測ログの `with 0 bytes received` は「生成中でまだ 1 バイトも返っていない」状態であり、
  ネットワーク瞬断ではなく **deadline 不足の署名**である。

**実測 (2026-08-04 JST = 2026-08-03 UTC, 本番 Anthropic API,
`claude-sonnet-4-5-20250929` = prompt YAML の設定モデル、非ストリーミング)**:
日本語 JSON を `max_tokens` まで飽和生成させて wall-clock と `usage.output_tokens` を計測。

| run | max_tokens | 実時間 | output_tokens | stop_reason | レート |
|---|---|---|---|---|---|
| 1 | 4,000 | 68.6s | 4,000 | max_tokens | 58.3 token/s |
| 2 | 4,000 | 67.7s | 4,000 | max_tokens | 59.1 token/s |
| 3 | 4,000 | 74.9s | 4,000 | max_tokens | 53.4 token/s |
| 4 | **16,000** | **273.9s** | **16,000** | max_tokens | **58.4 token/s** |

→ 実測レンジ **53.4〜59.1 token/s**。run 4 が示すとおり
**4,000 → 16,000 token の範囲でレートはほぼ一定**であり、
**`max_tokens: 16000` を飽和させる 1 回の呼び出しは実測 約 274 秒**かかる。ここから:

- **120s がカバーできるのは約 6,400〜7,100 output token** に過ぎない。
  `max_tokens: 16000` の 45% 未満であり、**上限まで使う段は落ちる**。
- generate 段の出力は `GeneratedScenarioData` のスキーマ上、cut 1 件あたり
  scene(≤1000 字) / narration(≤2000 字) / subtitle_secondary(≤2000 字) 等を持つ。
  現実的な SOP (数十手順) では容易に 7,000 token を超える。
- 194 バイトの極小 SOP が 50 秒で成功したのは出力が数百 token で済んだためであり、
  **入力サイズ依存ではなく「出力 token 量依存」**である (bug-hunt の「サイズ依存」の正体)。

つまり根本原因は **「120s は max_tokens 16000 の生成時間 (実測 274 秒) をカバーしていない」**。
リトライの有無以前に、成功しうる時間予算が与えられていない。

> 計測スクリプトは使い捨てのため保存していない。再現手順:
> `POST https://api.anthropic.com/v1/messages` に
> `{model: claude-sonnet-4-5-20250929, max_tokens: 16000,
> messages:[日本語 JSON カット一覧を 100 件以上生成させる指示]}` を非ストリーミングで送り、
> wall-clock と `usage.output_tokens` の比を取る。
>
> **この実測で未検証の点 (受容するリスク)**: 実 3 プロンプト (システムプロンプト込み) での
> レート、混雑時間帯の分散、TTFT の分布。後述の ceiling は実測 274 秒に対し
> **約 1.31 倍のマージン**を持たせてこれを吸収する。超過した場合は timeout 文言で
> ユーザーに次アクションを出す (無言の失敗にしない)。

**(3) provider/connection 例外はリトライ対象外 = 事実。ただしコメント矛盾の指摘は半分不正確。**

`AnalysisPipeline::withBoundedRetry` は `LlmOutputInvalidException` のみ catch する
(`app/Services/Manual/AnalysisPipeline.php:243-255`)。`ConnectionException` は素通りして
`run()` の catch → `failJob` へ落ちる。ここは bug-hunt の指摘どおり。

一方 `RunManualAnalysis` の「LLM 3 段 × 3 試行 × client timeout 120s = 1,080s」コメントは
**JSON 検証失敗リトライ (`analysis_llm_max_retries=2` = 計 3 試行) の worst-case 予算**として
書かれており、実装と矛盾はしていない (`AnalysisTimeBudgetInvariantTest` が同じ式を CI 固定)。
誤解を招く書き方ではあるので文言は直すが、「実装とコメントが矛盾している」という前提で
設計判断を組み立てるのは誤り。

**(4) 290KB PDF は token budget 内に収まっている (= 事前拒否は正解ではない)。**

`SopTextExtractor` は PDF → テキスト抽出 → 正規化後の `strlen` を
`manual.analysis_max_text_bytes` (150,000) と比較する。
実測 (`smalot/pdfparser` で同一処理を再現) の結果、
`AS_作業手順書.pdf` (290,498 bytes) の抽出テキストは **6,451 bytes / 3,292 文字**
(抽出所要時間 0.4 秒未満)。上限の 5% 未満であり、**入力側の budget 超過ではない**。
よって「事前の拒否」は本 finding の解ではない。

**(5) [scope 外・blocking follow-up] 同 PDF の抽出テキストは文字化けしている。**

同じ再現で、抽出テキストが CP932 バイト列を Latin-1 として解釈した典型的な mojibake
(`ì‹ÆŽè‡‘` … CP1252 → CP932 で復元すると `作業手順書`) であることを確認した。
これは **valid な UTF-8 になる**ため `SopTextExtractor::ensureUtf8()` の
`mb_check_encoding($text, 'UTF-8')` を通過し、そのまま LLM に渡っている。

本設計の scope 外 (別 defect) だが、**本設計だけでは同 PDF の解析結果は
「時間内に完走するが内容は無意味」になる**。したがって本施策の成功条件は
**「timeout 起因の失敗の解消」に限定**し、文字化けは **blocking follow-up として
必ず別 TODO を起票する** (§スコープ外 / open questions)。

## 改善アイデア

**「1 回の LLM 呼び出しに、出力 token 上限を生成し切れるだけの時間を与える」** ことを
主軸に据え、増えた時間予算が既存の連鎖 (job timeout < retry_after < 予約 TTL ≤ stale) を
壊さないように、**リトライの打ち切りを『試行回数』から『試行回数 ∧ 実時間 deadline』へ**
変える。そのうえで provider/connection 例外を有界リトライ対象に含め、
失敗時はユーザーが次に取れる行動を提示する。

1. **client timeout を実測に基づく運用上限へ引き上げる** (120 → **360 秒**)。
   **360s は「保証 ceiling」ではなく「観測に基づく運用上限」**である:
   `max_tokens: 16000` 飽和の実測 **274 秒**に対し約 **1.31 倍**のマージンを取った値。
   これを超える呼び出しは「今回の観測レンジを外れた provider 遅延」であり、
   打ち切って timeout 文言を出す (= 予算を無限に伸ばさない) という方針の表明である。
   *(「360 秒を超える = どのみち JSON が途中で切れる」という因果は成立しないので主張しない。
   16,000 token 未満の正常な JSON が provider 遅延で 360 秒を超えることはありうる。)*
2. **有界リトライを deadline 制にする**。パイプライン開始時刻 T0 から
   `manual.analysis_deadline_seconds` の実時間予算を持ち、
   **各試行の開始前**に残予算を検査する。残っていなければ即 timeout 扱いで failJob。
   → worst-case が「3 段 × 試行数 × timeout」の**積**ではなく
   **`deadline + client timeout 1 回分`** に変わり、時間予算の爆発を防げる。
   deadline は **`3 × client timeout` (= 1,080 秒)** と定義する。これにより
   **「LLM 以外の処理の合計が deadline に対して無視できる限り、3 段すべてが最低 1 回は
   フル ceiling で試行できる」**が成り立つ (deadline を ceiling の 3 倍未満にすると、
   飽和ジョブで最終段が starve する)。
   非 LLM 処理の内訳と有界性 — SOP テキスト抽出 (実測 0.4 秒未満。入力は
   `source_document_max_bytes` 20MB で有界) / DTO 検証 (純メモリ。入力は `max_tokens` で有界) /
   `updateProgress`・`extracted_json` 保存 (単一行 UPDATE) — はいずれも秒オーダーであり、
   `finalize` は deadline の外側 (後述 M で見る)。
3. **provider/connection 例外を有界リトライ対象に含める** (transient と型で断定できるものだけ)。
4. **失敗文言を理由で 3 系統に分岐する (H4)**。
   表示側 (`AnalysisPanel.svelte:294-297` は `failedJob.error` をそのまま `Alert` に出す) は
   **フロント変更不要**。
5. **時間 budget の連鎖を引き直し、CI 固定を新しい算術に合わせる**。
   予約 TTL (1,800s) と stale 閾値 (30 分) は **据え置ける値**を選ぶ (下表)。

### retryable 例外集合 (vendor のソースから写像を確定させた)

| provider 側の事象 | 到達する例外型 | 写像の根拠 | 扱い |
|---|---|---|---|
| cURL 28 (operation timed out) / 6 (resolve) / 7 (connect) / 35 (SSL connect) / 52 (got nothing) | `Illuminate\Http\Client\ConnectionException` | `guzzlehttp/guzzle/src/Handler/CurlFactory.php:711-717,765` が上記 errno を `ConnectException` にし、`laravel/framework/.../Http/Client/PendingRequest.php:1091-1092` が `ConnectionException` へ marshal | **retryable** |
| HTTP 529 (overloaded) | `Prism\Prism\Exceptions\PrismProviderOverloadedException` | `Providers/Anthropic/Anthropic.php:79-95` | **retryable** |
| LLM 出力 JSON の検証失敗 | `App\Exceptions\Manual\LlmOutputInvalidException` | 既存 | **retryable** (現行維持) |
| HTTP 408 / 500 / 502 / 503 / 504 | `PrismException` (generic) だが **`previous` に `Illuminate\Http\Client\RequestException` を保持** | `Providers/Anthropic/Anthropic.php:95-106` が `previous: $e` を渡す。`$e->getPrevious()->response->status()` で status を型安全に取得できる | **retryable** |
| HTTP 429 (rate limited) | `PrismRateLimitedException` | 同上 | 非 retryable (専用文言) |
| HTTP 413 (request too large) | `PrismRequestTooLargeException` | 同上 | 非 retryable (専用文言) |
| その他の 4xx / status 取得不能 | `PrismException` (generic) | 同上 | **非 retryable** |

**500/502/503/504 の status 取得方法 (型安全性)**: Anthropic provider は 429/529/413 以外を
status に依らず generic `PrismException` に潰すが、
`PrismException::providerRequestErrorWithDetails()` は
`new self($message, $statusCode, $previous)` で **HTTP status を例外 code に載せている**
(`vendor/echolabsdev/prism/src/Exceptions/PrismException.php:71-87`)。
ただし `getCode()` は他の factory (`toolNotFound` 等) で 0 になるため多義的。
そこで **`$e->getPrevious() instanceof Illuminate\Http\Client\RequestException` を判定してから
`->response->status()` を読む**。これなら PHPStan level 10 でも narrowing が効き、
「一時的な 502」と「決定論的な 400」を確実に区別できる。
status を取得できない generic `PrismException` は fail-fast に倒す。

## 期待効果

- **成功条件 (本施策のスコープ)**: **timeout / provider 例外起因の解析失敗が解消される**こと。
  具体的には (a) **観測レンジ (実測 274 秒) と設定した運用上限 (360 秒) の範囲では**
  1 回の呼び出しが打ち切られない、(b) 単発の接続断・500/502/503/504・529 で即 failJob しない、
  (c) 失敗時に理由別の次アクションが提示される。
- **使命への貢献**: North Star の起点である「SOP → カット設計」が
  timeout を理由に落ちなくなる。
  ただし **「サンプル PDF で有意味なシナリオが得られる」ことは本施策では保証しない**
  (文字化け defect の解消が前提。§スコープ外)。
- 単発の外部 API 瞬断でチケット消費フローが止まらない。
- `config/prism.php` の 30s が解析経路で効いていないという**現行実装の誤解の余地**を、
  docs とテストのコメントで解消する。

## 実装方針（概要）

| 変更対象 | 内容 |
|---|---|
| `resources/prompts/*.yaml` (解析 3 本) | `client_options.timeout: 120 → 360` |
| `config/manual.php` | `analysis_deadline_seconds` (1080) を追加 |
| `config/queue.php` | `database-analysis.retry_after: 1560 → 1680` |
| `app/Jobs/Manual/RunManualAnalysis.php` | `$timeout: 1380 → 1560`、budget コメントを新算術へ |
| `app/Services/Manual/AnalysisPipeline.php` | deadline の生成と伝播 / `withBoundedRetry` の retryable 判定 + deadline guard / `userMessageFor` の分岐 |
| `app/Exceptions/Manual/AnalysisFailedException.php` | `timedOut()` / `providerBusy()` を追加 |
| `tests/Architecture/AnalysisTimeBudgetInvariantTest.php` | 新しい連鎖 (`D = 3C` / `T ≥ D + C + M₁ + S` / `T < retry_after < TTL ≤ stale`) を固定。**生成レートは CI で pin しない** |
| `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` | YAML timeout の pin 値を新値へ / 解析 3 本の `max_tokens` と `timeout` の一致 |
| `tests/Feature/Projects/AnalysisPipelineTest.php` | 例外型ごとの retryable/非 retryable / deadline 打ち切り / deadline 直前開始 / SIGALRM 相当の会計不変条件 |
| `tests/Support/` | 例外を投げられる `PromptFake` 派生 (テスト用 double) |
| `docs/architecture.md` | 解析の時間 budget 連鎖 (L191/L195) と「YAML が prism.request_timeout を上書きする」旨を更新 |

**フロントエンド (Svelte/TS)・Controller・DTO / JsonResource・route の変更は無し**
(表示側はサーバ文言をそのまま出すため)。`response()->json()` の新規使用も無し。

## 制約・前提

### 時間 budget の連鎖 (新旧)

deadline の時計の定義を先に固定する:

- **T0** = `AnalysisPipeline::run()` の入口。
- deadline = `T0 + analysis_deadline_seconds`。
- **判定点は「各 LLM 試行の開始直前」のみ**。判定は **「deadline を過ぎたか」の真偽だけ**で行い、
  **残り時間を HTTP timeout に反映しない**。deadline を過ぎていれば試行を開始せず
  `AnalysisFailedException::timedOut()` を投げる。
  → 「deadline の 1 秒前に開始した試行にも client timeout の全体 (C) が許容される」。
  この意図は Feature テストで固定する (実装者が「残時間を timeout に設定する」方式に
  変えると `D + C` モデルが壊れるため)。
- 走行中の呼び出しを中断はしない (中断は Guzzle の client timeout が担う)。
  したがって **deadline 通過後に走りうるのは高々 1 回分の client timeout**。
- SOP テキスト抽出 (実測 0.4 秒未満) は deadline の**内側**に含む。
- **`startJob()` (予約作成・行ロック) は T0 の後**なので deadline の内側。
  一方 **job の `$timeout` は `handle()` 入口起点**なので、
  `handle()` 入口 → `run()` 入口の時間 (P: payload deserialize / DI 解決 / `findOrFail`) は
  D にも C にも含まれない。P は下表の **安全余白 S (90 秒) の内側**として扱う
  (受容条件: `P + その他モデル外要因 ≤ 90 秒`)。P はいずれも単一行 SELECT と
  コンテナ解決であり、通常はミリ秒オーダー。
- 時計は `CarbonImmutable::now()` (wall clock。テスト容易性優先。理由は「却下した代案」参照)。
  deadline は**ハード上限ではない** — 総実時間の上限は worker の `$timeout` (SIGALRM) である。

| 項目 | 現行 | 新 | 根拠 |
|---|---|---|---|
| 1 呼び出しの client timeout (C) | 120s | **360s** | `max_tokens: 16000` 飽和の実測 274 秒に対し約 1.31 倍の運用上限 |
| パイプライン deadline (D) | (なし) | **1,080s** = 3C | 3 段すべてにフル ceiling の 1 回を許す最小値 |
| finalize モデル予算 (M₁) | — | **30s** | deadline 通過後の terminal tx (2 行ロック) + チケット commit/release + 通知 + `report()`。実処理は秒未満 |
| pre-pipeline 予算 (P) | — | **S に含める** | `RunManualAnalysis::handle()` 入口から `AnalysisPipeline::run()` 入口まで (job payload の deserialize / DI 解決 / `AnalysisJob::findOrFail`)。**deadline の T0 より手前**なので D には入らない |
| 安全余白 (S) | — | **90s** | P + タイマー精度・シグナル配送・ログ処理などモデル外要因。**受容条件: `P + その他モデル外要因 ≤ 90s`** |
| job `$timeout` | 1,380s | **1,560s** = P + D + C + M₁ + S′ (P ⊆ S) | モデル上限 `D + C + M₁ = 1,470s` に対し **90 秒の明示的余白**。job の実時間は `handle()` 入口起点なので P を余白側で見る |
| queue `retry_after` | 1,560s | **1,680s** | job timeout < retry_after (レンダレーンと同値・運用実績あり) |
| 予約 TTL | 1,800s | **1,800s (据置)** | `TicketLedgerService::RESERVATION_TTL_MINUTES` を触らない |
| stale 閾値 | 30 分 | **30 分 (据置)** | `manual.analysis_stale_after_minutes` を触らない |

**C = 360 の決め方 (導出の向き)**:

1. **まず実測から C を決める**。`max_tokens: 16000` 飽和の実測は 274 秒。
   provider 遅延の分散を吸収するマージンとして約 1.31 倍を取り、**C = 360 秒**とする。
   (これは保証ではなく運用上限。§改善アイデア 1)
2. **その結果として** `D = 3C = 1,080`、`T = 4C + M₁ + S = 1,560` になる。
3. `T = 1,560 < retry_after 1,680 < TTL 1,800 ≤ stale 1,800` が成立し、
   **TTL と stale 閾値を据え置ける**。

参考までに、TTL 据え置き (`T = 4C + 120 < retry_after < 1,800`) から来る C の上界は
**`C < 390`** であり、実測から決めた 360 はその内側に収まっている
(= 実測起点で決めた値が、たまたま制約にも適合した)。

(TTL を伸ばして C をさらに大きく取る案は、`RenderTimeBudgetInvariantTest` が
`TTL ≤ render stale 閾値` を固定しているため **レンダレーンの stale 閾値まで巻き込む**。
レーン横断の影響を避けるため採らない。)

**retry_after の余白 120s で十分な理由**: `retry_after` の役割は
「worker の SIGALRM kill (`$timeout`) が queue の再可視化より先に起きる」ことの保証のみ。
`$tries = 1` なので再配送されても即 `failed()` → `failJob` (冪等) で終わり二重処理にならない。
既存のレンダレーン (`1500 < 1680 < 1800` = 余白 180/120) と同型で、
Laravel 既定 (`timeout 60 / retry_after 90` = 余白 30s) より厚い。

**この値選びの要点**: 予約 TTL と stale 閾値を据え置けたことで、
チケット台帳 (`TicketLedgerService`) と stale 回復 cron の運用契約に**一切手を入れない**。
影響範囲は解析レーンの中に閉じる。

**受容するトレードオフ**: deadline は 3 段で共有するため、前段が retry を使うと
最終段が starve して `timedOut()` になりうる。これは「合計 18 分を超えた」ことの
正しい打ち切りであり、段ごとの個別予算 (機構の増殖) は今回作らない。

### チケット 2 フェーズとの関係 (セキュリティ不変条件 #7)

リトライがチケット会計を壊さないことは、現行の構造から**構造的に保証される**:

- 予約 (`reserve`) は `startJob()` の中で 1 回だけ行われ、冪等キーは
  `analysis_jobs.ticket_reservation_id` (`ensureReservation`)。
- 本設計で増えるリトライは **`runExtractStep` / `runDecomposeStep` / `runGenerateStep` の内側**、
  すなわち `startJob()` の後・`finalize()` の前に閉じている。
  リトライ経路は予約行を読みも書きもしない。
- `commit` は `finalize()` の terminal tx 内で 1 回、`release` は `failJob()` の中で 1 回。
  どちらも `analysis_jobs` 行ロック + terminal guard を通るため、
  何回リトライしても commit/release は高々 1 回。
- したがって「無課金 succeeded」「課金済み failed」「二重課金」はいずれも発生しない。
  これを Feature テストで明示的に固定する。

**逆に、ジョブ再配送 (`tries`) を増やす案は採らない**。再配送はチケット会計と
stale 回復の直列化点を跨ぐため、リトライ予算を増やす軸としては最も危険である
(`$tries = 1` は §10.8-1 の意図的な設計。据え置く)。

#### SIGALRM (job `$timeout`) 強制終了時の予約の行方 — Laravel のソースで確定させた

内部リトライが予約行に触れないことは上で示したが、**`$timeout` によるプロセス強制終了は別経路**
なので個別に証明する:

1. `vendor/laravel/framework/src/Illuminate/Queue/Worker.php:292-321` `registerTimeoutHandler()` が
   `pcntl_alarm($timeout)` を張り、SIGALRM ハンドラで **`kill()` より前に**
   `markJobAsFailedIfWillExceedMaxAttempts()` を呼ぶ。
2. 同 `:665-676` — `$maxTries = $job->maxTries()` は **1** (`RunManualAnalysis::$tries = 1`)、
   `$job->attempts()` は初回配送で 1。よって `1 >= 1` が成立し `failJob($job, $e)` に入る。
3. `failJob` → `$job->fail($e)` → `CallQueuedHandler::failed()` →
   **`RunManualAnalysis::failed()`** → `AnalysisJobService::failJob()`
   (行ロック + terminal guard + `Reserved` のみ release)。

→ `$failOnTimeout` の設定に依存せず、`$tries = 1` の帰結として **`failed()` の呼び出しが試みられる**。

**ただし「即時 release」は保証しない (best-effort)**。SIGALRM ハンドラは同一プロセス内で走り、
進行中のトランザクションを明示 rollback してから `fail()` を呼ぶわけではないため、
(a) release が既存 tx に巻き込まれて kill と一緒に rollback される、
(b) 接続状態により `failed()` 自体が失敗する、
(c) 行ロック待ちのままプロセスが終了する、が起こりうる。

**したがって保証するのは eventual guarantee** — 「最終的に会計状態が正しく収束する」こと:

| 中断のタイミング | 最終的な予約状態 | 収束させる主体 |
|---|---|---|
| terminal tx の commit **前** | `Released` | `failed()` が成功すればその場で。失敗しても `analysis:recover-stale-jobs` cron (30 分) → `failJob` が release。さらに漏れても `TicketLedgerService::releaseStale` が TTL 超過分を回収 |
| terminal tx の commit **後** | `Committed` のまま | `failJob` の terminal guard が `false` を返して no-op (release しない) |
| `failed()` が失敗 / 未実行 | `Released` | `recoverStale` + `releaseStale` |

いずれの分岐でも「無課金 succeeded」「課金済み failed」は最終的に生じない。
**cron 2 種 (`analysis:recover-stale-jobs` / `releaseStale`) は補助ではなく保証の一部**である。
この 3 分岐の**最終**会計状態を Feature テストで固定する (即時性は要求しない)。

### フレームワークのレンジ内で収める (思考原則 1)

- タイムアウト値は `resources/prompts/*.yaml` の `client_options` = **prism-prompt 公式の作法**
  (`Prompt::resolveClientOptions()` が読む場所) で指定する。自前の HTTP 層は作らない。
- 設定値の読み出しは既存イディオムの `config()->integer(...)` (typed accessor) を使う。
  PHPStan level 10 で `mixed` にならない。
- リトライは `AnalysisPipeline` 内の既存 `withBoundedRetry` を拡張するだけで、
  Laravel の queue retry / Prism の `clientRetry` には手を出さない
  (どちらもチケット 2 フェーズや deadline 制御と噛み合わない)。
- **ストリーミング化は今回採らない** (下記「却下した代案」)。

### 却下した代案

| 代案 | 却下理由 |
|---|---|
| **ストリーミング化** (`Prism::text()->asStream()`) | 本命の正攻法だが**現在のレンジ外**。`kent013/laravel-prism-prompt` の `Prompt` はストリーム実行 API を公開していない (`executeSync` / `execute` / `executePrism` のみ。`grep` で確認)。使うには Prism 直呼びが必要で **AGENTS.md 禁止事項 5 (`PromptGuardrailTest` が検出)** に抵触する。パッケージ側に stream 実行が入ったら再検討 (§スコープ外)。 |
| **`max_tokens` を下げて生成時間を縮める** | 出力が途中で切れる → JSON 不正 → リトライしても同じ位置で切れる、という決定論的失敗に変わるだけ。悪化。 |
| **`analysis_max_text_bytes` を下げて事前拒否する** | 今回の PDF は上限の 5% 未満で、拒否しても救われない。「入力バイト → 出力 token」の実測換算係数を持たない以上、根拠のない上限は**正当な SOP を誤って拒否する**。 |
| **すべての `PrismException` を retryable にする** | 400 系 (リクエスト不正) を無駄に投げ直す。`previous` の `RequestException` から status を型安全に読める場合のみ 408/500/502/503/504 を retryable にし、読めないものは fail-fast にする (写像表)。 |
| **deadline に単調増加時計 (`hrtime`) を使う** | (1) `CarbonImmutable::now()` は `travelTo()` でテストできるが、`hrtime()` にすると deadline 打ち切りの Feature テストが書けなくなり禁止事項 1 (テストなし) に近づく。**テスト容易性を優先する**。(2) 時計補正による soft deadline の揺れは、**worker の `$timeout` (SIGALRM) が総実時間を上限する**ため受容できる (deadline はハード上限ではない)。 |
| **予約 TTL / render stale 閾値を伸ばして C をさらに大きく取る** | `RenderTimeBudgetInvariantTest` が `TTL ≤ render stale 閾値` を固定しているため、TTL を伸ばすとレンダレーンの stale 閾値まで巻き込む。レーン横断の影響を避ける。 |
| **429 を `retry-after` 秒スリープして再試行** | worker を占有したまま眠るのは deadline 予算と相性が悪く、今必要でもない。専用文言で「時間をおいて再実行」に倒す。 |
| **予約 TTL / stale 閾値を伸ばす** | 上表の値選びで不要になった。伸ばすと worker 異常終了時に manual が `analyzing` で長時間ロックされ UX が悪化する。 |
| **ジョブ再配送 (`tries` > 1) でリトライ** | チケット 2 フェーズと stale 回復の直列化点を跨ぐ。§10.8-1 の意図に反する。 |
| **失敗理由の enum / reason code を新設** | 表示側は `failedJob.error` の文字列をそのまま出すだけで種別分岐を持たず、消費者がいない (思考原則 2)。サーバ側の分岐は既存 `userMessageFor()` の `match(true)` = **例外型ディスパッチ**で行うため文字列比較は発生しない。 |
| **段ごとに個別の時間予算を持つ** | 値と機構が増える割に、合計上限が守られていれば UX 上の差は無い。今は作らない。 |

## スコープ外

- **PDF テキスト抽出の文字化け (上記 (5))**。本 finding とは独立した defect。
  `SopTextExtractor::fromPdf()` / `ensureUtf8()` と `smalot/pdfparser` の CMap 処理が対象。
  **blocking follow-up として別 TODO を必ず起票する**。本設計の施策はこれを直さない。
- LLM 呼び出しのストリーミング化 (パッケージ側の対応待ち)。
- レンダ (`RenderPipeline`) 側の時間 budget。今回は触らない。
- `analysis_max_text_bytes` / `max_tokens` の見直し。
- フロントエンド (`AnalysisPanel.svelte`) の表示ロジック。サーバ文言をそのまま出すため不要。


---

# 関連する現行コード (抜粋)

### `app/Services/Manual/AnalysisPipeline.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Analysis\ExtractedSopData;
use App\DataTransferObjects\Manual\Analysis\ExtractedText;
use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
use App\DataTransferObjects\Manual\Analysis\WorkDecompositionData;
use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Manual\AnalysisStep;
use App\Enums\Manual\JobStatus;
use App\Exceptions\Billing\InsufficientTicketsException;
use App\Exceptions\Manual\AnalysisFailedException;
use App\Exceptions\Manual\LlmOutputInvalidException;
use App\Models\AnalysisJob;
use App\Models\Organization;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\VideoManual;
use App\Prompts\ScenarioGenerationPrompt;
use App\Prompts\SopExtractPrompt;
use App\Prompts\WorkDecompositionPrompt;
use App\Services\Billing\TicketLedgerService;
use App\Services\Notification\NotificationCenterService;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * AI 解析パイプライン本体 (extract → decompose → generate → materialize)。概念設計 §4。
 *
 * - チケット 2 フェーズ: startJob で reserve (冪等キー = analysis_jobs.ticket_reservation_id)、
 *   terminal tx (finalize) で materialize + commit + succeeded を原子化
 *   (無課金 succeeded / 課金済み failed を構造的に排除)
 * - LLM 出力の有界リトライ: JSON 検証失敗 (LlmOutputInvalidException) のみ最大
 *   config manual.analysis_llm_max_retries 回再試行
 * - 失敗は catch → AnalysisJobService::failJob (行ロック + terminal guard で冪等)
 */
class AnalysisPipeline
{
    public function __construct(
        private readonly AnalysisJobService $jobs,
        private readonly ScenarioService $scenarios,
        private readonly SopTextExtractor $extractor,
        private readonly TicketLedgerService $tickets,
        private readonly NotificationCenterService $notifications,
        private readonly ScenarioBookendBuilder $bookend,
    ) {}

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
                // succeeded 到達時のみ・terminal tx の commit 後に通知 (stale 先勝ち false は通知しない)
                $this->notifications->notifyAnalysisFinished($job->refresh());
            }
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

            $organization = $this->resolveOrganization($locked);
            $this->ensureReservation($locked, $organization); // 残高不足はここで throw → catch → failJob

            $locked->status = JobStatus::Running;
            $locked->step = AnalysisStep::Extract;
            $locked->progress = 10;
            $locked->save();
            $job->refresh();

            return true;
        });
    }

    /**
     * 予約の冪等確保: 有効な Reserved があれば再利用 (再試行で二重予約しない)。
     * 失効済み Reserved は明示 release して付け替え、Released/Committed/なしは新規 reserve。
     */
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

    /** extract 段: 統一 JSON 化 + extracted_json 保存 (write-only 監査スナップショット) */
    private function runExtractStep(AnalysisJob $job, SourceDocument $document, ExtractedText $text): ExtractedSopData
    {
        $extracted = $this->withBoundedRetry(
            fn (): ExtractedSopData => ExtractedSopData::fromLlmText(
                SopExtractPrompt::make($text->text)->executeSync(),
            ),
        );

        $document->extracted_json = $extracted->toArray();
        $document->save();
        $this->updateProgress($job, AnalysisStep::Decompose, 35);

        return $extracted;
    }

    /** decompose 段: 作業分解表 + result_json 保存 (write-only 監査スナップショット) */
    private function runDecomposeStep(AnalysisJob $job, ExtractedSopData $extracted): WorkDecompositionData
    {
        $decomposition = $this->withBoundedRetry(
            fn (): WorkDecompositionData => WorkDecompositionData::fromLlmText(
                WorkDecompositionPrompt::make($extracted->toJsonString())->executeSync(),
            ),
        );

        $job->result_json = $decomposition->toArray();
        $job->step = AnalysisStep::Generate;
        $job->progress = 65;
        $job->save();

        return $decomposition;
    }

    /** generate 段: カット群生成 */
    private function runGenerateStep(AnalysisJob $job, WorkDecompositionData $decomposition): GeneratedScenarioData
    {
        $generated = $this->withBoundedRetry(
            fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(
                ScenarioGenerationPrompt::make($decomposition->toJsonString())->executeSync(),
            ),
        );

        $this->updateProgress($job, AnalysisStep::Generate, 90);

        return $generated;
    }

    /**
     * terminal tx: materialize + commit + succeeded を原子化 (概念設計 §4-5)。
     * transaction / 行ロックは本メソッド (最外層) だけが張る。
     *
     * グローバルロック順 (全経路がこの順でのみ取得する。逆順取得ゼロ = デッドロックなし):
     *   analysis_jobs → video_manuals → ticket_reservations → organizations
     *
     * TicketLedgerService 内部の実取得順 (実装から転記。内部変更はしない):
     *   - reserve / grant:   organizations のみ (lockOrganizationRow)
     *   - commit / release:  ticket_reservations (lockReservationRow) → organizations
     * 各経路の取得列:
     *   - trigger:      video_manuals のみ (balance() はロックなしの集計)
     *   - startJob:     analysis_jobs → (reserve: organizations)
     *   - finalize:     analysis_jobs → video_manuals → (commit: ticket_reservations → organizations)
     *   - failJob:      analysis_jobs → video_manuals → (release: ticket_reservations → organizations)
     *   - releaseStale (billing cron): ticket_reservations → organizations (前方リソースを保持しない)
     *   - ScenarioService::save: video_manuals のみ
     * いずれもグローバル順の部分列であり循環待ちは構成できない。
     *
     * @return bool succeeded に到達したか (stale 回復先勝ちなら false = 通知しない。
     *              RenderPipeline::finalize と同型の bool 返却)
     */
    private function finalize(AnalysisJob $job, GeneratedScenarioData $generated): bool
    {
        return DB::transaction(function () use ($job, $generated): bool {
            // ロック 1: job 行 (stale 回復 cron との直列化点)
            /** @var AnalysisJob $locked */
            $locked = AnalysisJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status !== JobStatus::Running) {
                return false; // stale 回復 cron が先勝ち → materialize も commit もしない (無課金 succeeded 排除)
            }

            // ロック 2: manual 行 (共有ロック規約。親 relation 経由再解決 = 子∈親も担保)
            $project = $this->resolveProject($locked);
            /** @var VideoManual $lockedManual */
            $lockedManual = $project->manuals()
                ->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();

            // 導入/総括カットを terminal tx 内 (locked manual 参照) で決定的に前後付与する。
            // 再掲元は今回生成の steps のみ (DB 既存 cuts 不参照)。
            $steps = $this->bookend->wrap($lockedManual, $generated->toScenarioSteps());

            // cuts + version + status(analyzing→ready) はロック済み manual 前提メソッドで反映
            // (内側 transaction を張らない。analyzing guard 違反は LogicException → 全体 rollback)
            $this->scenarios->materializeIntoLockedManual($lockedManual, $steps);

            // ロック 3: reservation/org 行 (TicketLedgerService::commit 内部。savepoint)
            $reservation = $locked->ticketReservation;
            Assert::notNull($reservation, 'startJob が必ず予約を付けている');
            // commit-wins: TTL 超過や stale releaser 先着 (Released) でも生存 hold は課金する
            // (二重課金は consume:{id} の UNIQUE が防ぐ)。失効 monthly hold のみ no-charge。
            // 戻り値 (TicketCommitResult) は可観測性のためのもので分岐には使わない
            $this->tickets->commit($reservation);

            $locked->status = JobStatus::Succeeded;
            $locked->progress = 100;
            $locked->save();

            return true;
        });
    }

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

    /**
     * step/progress の表示用更新 (tx 不要の単発 update。状態機械は status のみが真実源。
     * updated_at の更新が stale 判定の「最終 step 更新時刻」を兼ねる)。
     */
    private function updateProgress(AnalysisJob $job, AnalysisStep $step, int $progress): void
    {
        $job->step = $step;
        $job->progress = $progress;
        $job->save();
    }

    /** job → manual → project の導出 (payload 不信任。DB から relation 経由で再解決) */
    private function resolveProject(AnalysisJob $job): Project
    {
        $project = $job->videoManual?->project;
        Assert::isInstanceOf($project, Project::class, 'analysis job は必ず project 配下の manual に属する');

        return $project;
    }

    /** job → manual → project → organization の導出 */
    private function resolveOrganization(AnalysisJob $job): Organization
    {
        $organization = $this->resolveProject($job)->organization;
        Assert::isInstanceOf($organization, Organization::class, 'project は必ず組織に属する');

        return $organization;
    }

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
}

```

### `app/Jobs/Manual/RunManualAnalysis.php`

```php
<?php

declare(strict_types=1);

namespace App\Jobs\Manual;

use App\Models\AnalysisJob;
use App\Services\Manual\AnalysisJobService;
use App\Services\Manual\AnalysisPipeline;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * AI 解析の queue job (薄い殻。本体は AnalysisPipeline)。
 *
 * - payload は analysisJobId のみ (モデル/チケット/org 値を payload に持たない = payload 不信任)
 * - 専用 connection database-analysis (retry_after=1560) で流す。運用契約:
 *   本番/ステージングは `php artisan queue:work database-analysis` を worker 定義に必須登録
 *   (docs/architecture.md。滞留は recoverStale cron が 30 分で failJob する)
 */
class RunManualAnalysis implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** 自動再試行しない (§10.8-1。再実行は analyze 再トリガーのみ) */
    public int $tries = 1;

    /**
     * worst-case (LLM 3 段 × 3 試行 × client timeout 120s = 1,080s) + 抽出/解析余裕 180s + マージン。
     * timeout (1,380) < retry_after (1,560) < 予約 TTL (1,800) の連鎖は
     * AnalysisTimeBudgetInvariantTest が CI 固定する。
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

### `app/Exceptions/Manual/AnalysisFailedException.php`

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Manual;

use RuntimeException;

/**
 * AI 解析の失敗 (ユーザー向けメッセージ付き)。AnalysisPipeline が投げ、
 * catch 経路の failJob がメッセージをそのまま error 列に保存する。
 */
final class AnalysisFailedException extends RuntimeException
{
    /** テキスト抽出不能 (画像/スキャン手順書・破損・バイナリ) */
    public static function unextractable(): self
    {
        return new self('テキストを抽出できません。画像・スキャンの手順書は現在未対応です。');
    }

    /** 抽出できたが本文が実質空 (min_text_bytes 未満)。画像扱いと混同しない明示文言 */
    public static function tooShort(): self
    {
        return new self('手順書の本文が短すぎます。もう少し詳しい手順書をアップロードしてください。');
    }

    /** LLM 入力上限 (UTF-8 バイト) 超過 */
    public static function tooLarge(): self
    {
        return new self('手順書が大きすぎます。分割してアップロードしてください。');
    }
}

```

### `tests/Architecture/AnalysisTimeBudgetInvariantTest.php`

```php
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

```

### `tests/Architecture/AnalysisTokenBudgetInvariantTest.php`

```php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * AI 解析の LLM 入力上限 (config manual.analysis_max_text_bytes) の token budget 算術を
 * CI で固定する (値を弄って budget を壊せない)。
 *
 * 上界の根拠 (数学的・言語非依存): tokenizer は入力バイト列を「空でない区間」に分割する
 * (partition) ため、いかなる入力でも token 数 <= バイト数。従って
 * 「入力バイト数 <= 入力 token budget」なら context 超過は起きない。
 * budget = context - 出力予約 - 固定プロンプト余裕 = 200,000 - 16,000 - 4,000 = 180,000。
 * config 既定値 150,000 bytes は budget 180,000 に対するマージン込みの値。
 *
 * 運用条件: 「token 数 <= UTF-8 バイト数」は byte-fallback BPE 系 tokenizer の前提。
 * 対象モデル・tokenizer 系を変更する際は本上限設計 (config 値 + 本テストの定数) を必ず再確認する。
 */
const MODEL_CONTEXT_TOKENS = 200_000;   // claude-sonnet-4-5 (prompts YAML の model と対)

const OUTPUT_RESERVE_TOKENS = 16_000;   // 解析 3 YAML の max_tokens と一致させる

const PROMPT_OVERHEAD_TOKENS = 4_000;   // 固定 system/prompt + UserInput タグの余裕

const INPUT_BUDGET_TOKENS = MODEL_CONTEXT_TOKENS - OUTPUT_RESERVE_TOKENS - PROMPT_OVERHEAD_TOKENS; // 180,000

/**
 * 解析パイプラインの 3 プロンプト (施策 8)。
 *
 * @return list<string>
 */
function analysisPromptNames(): array
{
    return ['sop-extract', 'work-decomposition', 'scenario-generation'];
}

test('LLM 入力バイト上限が入力 token budget を超えない (分割上界: token数<=バイト数)', function (): void {
    expect(config()->integer('manual.analysis_max_text_bytes'))
        ->toBeLessThanOrEqual(INPUT_BUDGET_TOKENS);
});

test('解析プロンプト YAML の max_tokens は出力予約と一致する', function (): void {
    foreach (analysisPromptNames() as $name) {
        $path = resource_path("prompts/{$name}.yaml");
        expect(file_exists($path))->toBeTrue("解析プロンプト {$name}.yaml が存在しません");
        $yaml = Yaml::parseFile($path);
        expect($yaml)->toBeArray();
        expect($yaml['max_tokens'] ?? null)
            ->toBe(OUTPUT_RESERVE_TOKENS, "{$name}.yaml の max_tokens が出力予約 (OUTPUT_RESERVE_TOKENS) と不一致");
    }
});

test('解析プロンプト YAML の client timeout は時間 budget の前提値 (120 秒) と一致する', function (): void {
    // AnalysisTimeBudgetInvariantTest の worst-case 計算 (3 段 × 試行 × 120s) と対
    foreach (analysisPromptNames() as $name) {
        $yaml = Yaml::parseFile(resource_path("prompts/{$name}.yaml"));
        expect($yaml)->toBeArray();
        expect($yaml['client_options']['timeout'] ?? null)
            ->toBe(120, "{$name}.yaml の client_options.timeout が 120 と不一致");
    }
});

test('最小テキスト閾値 < 最大バイト上限 (validation の縮退防止)', function (): void {
    expect(config()->integer('manual.analysis_min_text_bytes'))
        ->toBeLessThan(config()->integer('manual.analysis_max_text_bytes'));
});

```

### `config/manual.php`

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 動画マニュアル / AI 解析の設定 (doc/10 §10.5 / §10.7 / §10.8)
|--------------------------------------------------------------------------
*/

return [
    // AI 解析 1 回のチケット消費 (doc/10 §10.5 COST_ANALYSIS)
    'analysis_ticket_cost' => 1,

    // LLM 出力 JSON の検証失敗時の有界リトライ回数 (§10.7-2。計 1+N 試行)
    'analysis_llm_max_retries' => 2,

    // LLM 入力上限 (UTF-8 bytes)。token budget 導出: context 200,000 - 出力予約 16,000
    // - 固定プロンプト 4,000 = 180,000 token。byte-fallback BPE では token 数 <= バイト数が
    // 安全側上界のため strlen で保証する (AnalysisTokenBudgetInvariantTest が算術を固定)
    'analysis_max_text_bytes' => 150_000,

    // 抽出テキストの実質空判定 (これ未満は「テキストを抽出できません」)
    'analysis_min_text_bytes' => 100,

    // stale ジョブ回復閾値 (分)。queued: dispatch 喪失、running: worker 異常終了
    'analysis_stale_after_minutes' => 30,

    // ── シナリオ導入/総括カット (概念設計 §改善アイデア) ──────────────
    // 総括カットの要点再掲に載せる最大件数 (先頭から)。0 以下は builder が 1 件扱いに補正。
    'summary_recap_max_points' => 3,
    // 導入/総括の作業名補間で用いるタイトルの truncate 上限 (subtitle_primary=100 に収める)。
    'scenario_bookend_title_max_chars' => 60,

    // SOP アップロード上限 (bytes) と許可拡張子 (mime rule 用)
    'source_document_max_bytes' => 20 * 1024 * 1024,
    'source_document_mimes' => ['pdf', 'xlsx', 'xls', 'txt'],

    // ── レンダ (doc/10 §10.5 / §10.8-1 / 概念設計 §9) ──────────────────
    'render_ticket_cost' => 3,                    // COST_RENDER (v1 固定。係数化は後続)
    'render_stale_after_minutes' => 30,           // running の stale 閾値
    'render_queued_stale_after_minutes' => 10,    // queued の短 SLA (編集ブロック最小化)
    'render_max_total_source_ms' => 1_200_000,    // 尺上限ソフトゲート (20 分)
    'render_default_take_duration_ms' => 60_000,  // duration_ms NULL テイクの保守的代用値
    'render_max_inflight_previews_per_org' => 3,  // org 同時 preview 上限
    'preview_placeholder_seconds' => 3,           // 採用テイク欠落 cut のプレースホルダ尺
    'render_resolution' => '1920x1080',
    'render_fps' => 30,
    'render_ffmpeg_binary' => env('RENDER_FFMPEG_BINARY', 'ffmpeg'),
    'render_ffprobe_binary' => env('RENDER_FFPROBE_BINARY', 'ffprobe'),
    'render_subtitle_font' => env('RENDER_SUBTITLE_FONT', 'Noto Sans CJK JP'),
    'render_playback_url_ttl_minutes' => 10,      // preview 再生 / DL 署名 URL の TTL
];

```
