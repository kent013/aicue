## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。数値を見て即座に閾値を弄るな。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。
仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC

【この設計に固有の前提 — 逸脱不可のオーナー決定】
(a) 目的は品質評価ではなく「プロセスが回ること」の確認。入力はダミーで良い
(b) 置き場所は bug-hunt レーン
(c) LLM は 3 段すべて実呼び出し
(d) コストレポートを作る (spirux / aigenba と同じ形に揃える。独自形式を発明しない)
→ 「生成物の品質 (字幕の内容・語尾・捏造の有無) を自動判定せよ」という指摘は
   **オーナーが明示的にスコープ外とした**ため受け付けられない。
→ 「過剰に作らない」(AGENTS.md 思考原則 2) が強く求められている。機能追加を促す指摘は
   本当に今必要かを示してから出すこと。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性
9. セキュリティ（認可チェック、入力バリデーション、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md 準拠 (UI 変更を含む場合。本件は CLI のみで UI 変更なし)
11. Atomic Design 準拠 (同上)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: pipeline-smoke (パイプライン通し確認 + LLM コストレポート)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- 新モデルを追加する設計では **対応するFactoryの作成も施策に含める**（本設計は新モデルを追加しない）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

`devnotes/20260810-1912-pipeline-smoke/conceptual-design.md` （Codex 概念レビュー Round 2 で APPROVED）

## 前提の確認結果 (実読で確定した事実)

| # | 事実 | 出典 |
|---|---|---|
| P1 | bug-hunt provision は `database-analysis` / `database-render` / `database-media` の worker を `queue:listen` で起動する | `scripts/bug-hunt-shard.sh` `BUGHUNT_WORKER_CONNECTIONS` / `start_shard_workers()` |
| P2 | serve / worker にだけ実 API キーが載る (`LLM_KEY_ENV`)。`MODE_ENV` はフラグのみ | 同 `build_mode_env()` |
| P3 | `Prompt::$fake` install 時、vendor は `executePrism()` の**先頭で短絡**し `PromptExecutionCompleted` を発火しない = `llm_call_logs` の行が 1 行も出ない | `vendor/kent013/laravel-prism-prompt/src/Prompt.php` L699-703 |
| P4 | `AnalysisPipeline` の 3 プロンプトは `withMetadata()` を呼ばない → `llm_call_logs.organization_id` / `subject_*` は NULL、`metadata_missing = true` | `app/Prompts/*.php` (全 3 本) / `RecordLlmCallCost::buildData()` |
| P5 | `total_cost_jpy` は `FxRateService`(Frankfurter API) 依存で失敗時 null。`total_cost_usd` は `pricing_snapshot` から決定的 | `app/Services/LlmCallLogWriter.php` / `app/Services/FxRateService.php` |
| P6 | 単価は `claude-sonnet-4-5-20250929` = input $3.00 / output $15.00 per MTok | `config/prism-prompt-pricing.php` |
| P7 | SOP は 100 バイト以上 (`manual.analysis_min_text_bytes`) かつ日本語比率 0.10 以上 (`manual.analysis_min_japanese_ratio`) でないと LLM に渡らず失敗する | `config/manual.php` / `SopTextExtractor` |
| P8 | 解析 1 枚 + レンダ 3 枚 = 4 枚のチケットを消費する。`BughuntBillingSeeder` は 100 枚付与する | `config/manual.php` / `database/seeders/BughuntBillingSeeder.php` |
| P9 | `OrganizationProvisioningService::provision()` は **Project を作らない**。Default Project の定義は「org の先頭 project」 | `app/Services/Project/DefaultProjectResolver.php` |
| P10 | 本番コード (app/ routes/ config/ bootstrap/) は fake クラスを参照できない。例外は allowlist 4 件で、うち 2 件は `FakeStorageGate` 成立時のみ動く fake storage の受け口 controller | `tests/Architecture/FakeClassReferenceInvariantTest.php` |
| P11 | app/ の `Illuminate\Support\Facades\Http` 参照は `ExternalSeamInventory` の母集団に入り、**閉じた語彙**の `ExternalSeamKind` を 1 つ選んで登録する必要がある | `tests/Support/ExternalSeam/ExternalSeamScanner.php` / `app/Enums/Security/ExternalSeamKind.php` |
| P12 | `queue.default` は bughunt で `sync` だが、2 つの Job は `onConnection('database-analysis' / 'database-render')` を明示するため DB キュー経由で worker が拾う | `RunManualAnalysis::__construct()` / `RunManualRender::__construct()` |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | bug-hunt DB 名判定の SSOT を app 側へ昇格 | `app/Support/BughuntDatabaseGuard.php` (新) / `database/seeders/Concerns/DetectsBughuntDatabase.php` | High |
| 2 | LLM コスト集計サービス + DTO + enum | `app/Services/LlmCostReportService.php` (新) / `app/DataTransferObjects/LlmCostReport{Data,RowData}.php` (新) / `app/Enums/LlmCostReportGroupBy.php` (新) | High |
| 3 | 期間集計コマンド | `app/Console/Commands/Operations/LlmCostReportCommand.php` (新) | Medium |
| 4 | ダミー SOP fixture | `resources/fixtures/pipeline-smoke-sop.txt` (新) | High |
| 5 | pipeline smoke コマンド本体 | `app/Console/Commands/Development/PipelineSmokeCommand.php` (新) / `app/DataTransferObjects/Smoke/*.php` (新) / `app/Enums/Smoke/*.php` (新) | High |
| 6 | fake 参照 allowlist への登録 | `tests/Architecture/FakeClassReferenceInvariantTest.php` | High |
| 7 | bug-hunt レーンからの起動導線 | `scripts/bug-hunt-shard.sh` | High |
| 8 | ドキュメント追記 | `AGENTS.md` / `docs/architecture.md` / `.claude/skills/app-bug-hunt/SKILL.md` | Medium |
| 9 | テスト | `tests/Feature/Console/PipelineSmokeCommandTest.php` (新) / `tests/Unit/Services/LlmCostReportServiceTest.php` (新) / `tests/Unit/Fixtures/PipelineSmokeSopFixtureTest.php` (新) | High |

---

## 施策 1: bug-hunt DB 名判定の SSOT を app 側へ昇格

### 変更箇所

- 新規: `app/Support/BughuntDatabaseGuard.php`
- 変更: `database/seeders/Concerns/DetectsBughuntDatabase.php` (委譲に置換。**public API は不変**)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 既存 seeder テストは呼び出し側が不変のため変更不要。
  新規の behavioral テストを施策 9 に含める

### 現行コード

```php
// database/seeders/Concerns/DetectsBughuntDatabase.php
trait DetectsBughuntDatabase
{
    private const string BUGHUNT_DB_REGEX = '/^bug_hunt(_[1-8])?$/';

    private function isBughuntDatabase(): bool
    {
        return preg_match(self::BUGHUNT_DB_REGEX, DB::connection()->getDatabaseName()) === 1;
    }
}
```

### 変更後コード

```php
// app/Support/BughuntDatabaseGuard.php (新規)
namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * bug-hunt DB 名判定の SSOT (fail-secure な**検出**側)。
 *
 * ★ 並列 cap は 4 (`scripts/bug-hunt-shard.sh` の BUGHUNT_SHARD_CAP) だが、本 regex は
 *   cap と同期させない。狭めると残留 bug_hunt_5 を bughunt DB と認識できず「dev DB 扱い」に
 *   なってしまう (= 検出漏れ)。同スクリプトの SHARD_DB_RE は「触れてよい DB の allowlist」で
 *   方向が逆である点に注意。
 * ★ 依存の向きは app ← seeders。seeder 側 trait は本クラスへ委譲するだけの薄い殻にする
 *   (regex の二重管理を作らない)。
 */
final readonly class BughuntDatabaseGuard
{
    private const string BUGHUNT_DB_REGEX = '/^bug_hunt(_[1-8])?$/';

    public function isBughuntDatabase(): bool
    {
        return self::matches(DB::connection()->getDatabaseName());
    }

    /** 名前だけを見る純関数 (テストで DB 接続なしに判定表を固定できる) */
    public static function matches(string $databaseName): bool
    {
        return preg_match(self::BUGHUNT_DB_REGEX, $databaseName) === 1;
    }
}
```

```php
// database/seeders/Concerns/DetectsBughuntDatabase.php (委譲へ置換)
trait DetectsBughuntDatabase
{
    private function isBughuntDatabase(): bool
    {
        return app(BughuntDatabaseGuard::class)->isBughuntDatabase();
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`bool`)
- [x] null 安全 (`getDatabaseName()` は `string` を返す)
- [x] DTO を返している (該当なし。boolean 判定)
- [x] Generics の型パラメータ (該当なし)

### テスト計画

- [x] 新規 `tests/Unit/Support/BughuntDatabaseGuardTest.php` — `matches()` の判定表
      (`bug_hunt` / `bug_hunt_1` / `bug_hunt_8` = true、`bug_hunt_9` / `bug_hunt_` / `aicue` /
      `bug_hunt_1x` / `xbug_hunt` = false)
- [x] 既存 seeder テストは呼び出し側不変のため更新不要
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 3 つの bughunt seeder の fail-secure guard が本クラス経由になる。**振る舞いは同一**だが、
  container 解決が挟まる。`app()` 解決は seeder 実行時点で確実に可能 (Laravel bootstrap 済み)

---

## 施策 2: LLM コスト集計サービス + DTO + enum

### 変更箇所

- 新規: `app/Enums/LlmCostReportGroupBy.php`
- 新規: `app/DataTransferObjects/LlmCostReportRowData.php`
- 新規: `app/DataTransferObjects/LlmCostReportData.php`
- 新規: `app/Services/LlmCostReportService.php`

### 波及変更

- TypeScript 型定義: なし (CLI 専用。Inertia へは出さない)
- API Resource/DTO: なし (JsonResource は作らない = HTTP 面を持たない)
- テストファイル: `tests/Unit/Services/LlmCostReportServiceTest.php` (新規)

### 設計判断 (先例に揃える)

**台帳 (lctl) に LLM コスト集計レポートの先例は存在しない** (概念設計の調査結果を参照)。
よって「独自形式を発明しない」を次の 2 点として実行する:

1. **語彙は記録層 (`llm_call_logs`) の列名に揃える**。新しい派生指標 (単価・効率など) を作らない
2. **CLI の作法は `llm-model-check-cli` (aigenba 発 / template 実装済み) に揃える** —
   `app/Console/Commands/Development/` 配置、手動実行のみ、実 provider へ出るものは
   自動テストから実行しない

丸めは `llm-batch-billing` (spirux) の規約と**衝突しない**: 本サービスは
**記録済みの値を合算するだけで再計算も丸め直しもしない**。したがって成分別 ×0.5 →
小数 6 桁 HALF_UP という写像規約とは層が違い、将来 Batch API を採用しても
本サービスは「その規約で書かれた行を合算する」だけで済む。

### 変更後コード

```php
// app/Enums/LlmCostReportGroupBy.php
namespace App\Enums;

/** コストレポートの集計軸 (閉じた語彙。SQL へ列名を素通しさせないための型境界)。 */
enum LlmCostReportGroupBy: string
{
    case PromptTemplate = 'prompt_template';
    case Model = 'model';
    case Organization = 'organization';
    case Day = 'day';

    /** 集計キーに使う SQL 式 (列名リテラルは**本 enum の外へ出さない**)。 */
    public function selectExpression(): string
    {
        return match ($this) {
            self::PromptTemplate => 'prompt_template',
            self::Model => 'model',
            self::Organization => 'organization_id',
            self::Day => 'date(created_at)',
        };
    }
}
```

```php
// app/DataTransferObjects/LlmCostReportRowData.php
namespace App\DataTransferObjects;

/**
 * 集計 1 行。金額は DECIMAL の合計を **numeric-string** で持つ (float 化しない)。
 * null は「upstream の pricing / FX 解決失敗」を意味し、0 (unknown モデルの zero-cost
 * snapshot = 正常系) と区別する必要があるため潰さない。
 */
final readonly class LlmCostReportRowData
{
    /**
     * @param  numeric-string|null  $totalCostUsd  usdUnresolvedCalls を除いた行の合計
     * @param  numeric-string|null  $totalCostJpy  jpyUnresolvedCalls を除いた行の合計
     * @param  int<0, max>  $calls
     */
    public function __construct(
        public string $key,                 // 集計キー (null は '(none)' に正規化)
        public int $calls,
        public int $inputTokens,
        public int $outputTokens,
        public int $cacheReadInputTokens,
        public int $cacheWriteInputTokens,
        public ?string $totalCostUsd,
        public ?string $totalCostJpy,
        public int $usdUnresolvedCalls,     // total_cost_usd IS NULL の件数
        public int $jpyUnresolvedCalls,     // total_cost_jpy IS NULL の件数
        public int $failedCalls,            // failure_reason IS NOT NULL の件数
        public int $metadataMissingCalls,   // metadata_missing = true の件数
        public int $avgDurationMs,
    ) {}
}
```

```php
// app/DataTransferObjects/LlmCostReportData.php
namespace App\DataTransferObjects;

use App\Enums\LlmCostReportGroupBy;
use Carbon\CarbonImmutable;

final readonly class LlmCostReportData
{
    /** @param  list<LlmCostReportRowData>  $rows */
    public function __construct(
        public LlmCostReportGroupBy $groupBy,
        public ?CarbonImmutable $since,
        public ?CarbonImmutable $until,
        public ?int $afterId,               // smoke の「この実行分」切り出しに使った境界
        public array $rows,
        public LlmCostReportRowData $total, // 合計行 (key = 'TOTAL')
    ) {}
}
```

```php
// app/Services/LlmCostReportService.php
namespace App\Services;

/**
 * llm_call_logs の集計 (読み取り専用)。**再計算も再換算もしない**。
 *
 * - USD が主: total_cost_usd は pricing_snapshot から決定的に決まる
 * - JPY は副: total_cost_jpy は行ごとの fx_snapshot (記録時レート) 由来。
 *   したがって期間合計の JPY は「各行の記録時レートでの合計」であり、
 *   単一レートで USD を換算した値ではない (**再換算しない**)
 * - 未解決 (null) は 0 に潰さず件数として別に返す (「安く見える」嘘をつかない)
 */
final readonly class LlmCostReportService
{
    public function report(
        LlmCostReportGroupBy $groupBy,
        ?CarbonImmutable $since = null,
        ?CarbonImmutable $until = null,
        ?int $afterId = null,
    ): LlmCostReportData;
}
```

実装方針:

- 1 本の group by クエリで行を取り、`total` は**同じ where 条件の別クエリ**で取る
  (行合計の再集計にしない = null 件数の二重計上を避ける)
- `SUM()` の戻りは driver 依存で `string|int|float|null` になりうるため、
  **DTO 生成境界で検査する**: `is_numeric()` で確かめて `(string)` へ寄せ、
  数値でなければ `LogicException` (fail-loud)。件数系は `(int)` 化のうえ `Assert::natural()`
- `AVG(duration_ms)` は `(int) round()`
- `organization` 軸のキー null は `'(none)'` に正規化する

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全（`Webmozart\Assert\Assert` を DTO 生成境界で使用）
- [x] DTO を返している（配列返却なし）
- [x] Generics の型パラメータが正しい（`list<LlmCostReportRowData>`）

### テスト計画

- [x] 新規 `tests/Unit/Services/LlmCostReportServiceTest.php`
      (`LlmCallLog::factory()` でデータを作る。**実 LLM を呼ばない**)
  - 集計軸ごとの行分割 (prompt_template / model / organization / day)
  - `total_cost_usd` が null の行を 0 に潰さず `usdUnresolvedCalls` に数えること
  - `total_cost_jpy` が null の行を `jpyUnresolvedCalls` に数え、JPY 合計に含めないこと
  - `afterId` 指定で id 境界より大きい行だけが対象になること
  - `since` / `until` の境界 (含む/含まない) が仕様どおりであること
  - `metadata_missing` 件数が出ること
  - 対象 0 件のとき rows = [] かつ total が全部 0 / null になること (空振り時の形を固定)
- [x] 既存テストの更新: なし
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- `date(created_at)` は SQLite / pgsql で書式が異なる。テストは pgsql レーン
  (`php-test-pgsql-lane`) で走るため pgsql 表現に合わせる。
  driver 分岐を作らない (aicue のテスト DB は pgsql 固定)

---

## 施策 3: 期間集計コマンド (`operations:llm-cost-report`)

### 変更箇所

- 新規: `app/Console/Commands/Operations/LlmCostReportCommand.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Feature/Console/LlmCostReportCommandTest.php` (施策 9)

### 変更後コード (signature)

```php
protected $signature = 'operations:llm-cost-report
    {--since= : 集計開始日時 (Y-m-d または Y-m-d H:i:s。既定 = 30 日前)}
    {--until= : 集計終了日時 (既定 = 現在)}
    {--group-by=prompt_template : 集計軸 (prompt_template|model|organization|day)}
    {--json : 機械可読出力}';

protected $description = 'llm_call_logs を集計して LLM 利用コストを表示する (読み取り専用)。';
```

- 不正な `--group-by` は `LlmCostReportGroupBy::tryFrom()` が null → `self::INVALID`(=2) で終了
- 既定表示は `$this->table()`。列は
  `key / calls / in_tok / out_tok / cache_r / cache_w / usd / jpy / usd_unresolved / jpy_unresolved / failed / meta_missing / avg_ms`
- 末尾に注記を必ず出す:
  「JPY は各行の記録時レート (fx_snapshot) の合計であり単一レート換算ではない」
  「usd/jpy_unresolved の行は合計に含まれない」
  「meta_missing = 組織・対象が特定できない行 (現状 AI 解析 3 段はすべてこれに該当する)」
- **スケジュール登録しない** (`routes/console.php` を触らない)。読み取り専用だが自動実行の必要が無い

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`handle(): int`)
- [x] null 安全（option は `string|null` を明示検査してから parse）
- [x] DTO を返している（Service が DTO を返す。command は表示のみ）

### テスト計画

- [x] 新規: 既定オプションで表が出ること / `--json` の shape / 不正 `--group-by` の終了コード
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 大量行での `SUM` は index (`llm_call_logs` の `(organization_id, created_at)` /
  `(model, created_at)` / `prompt_template`) に乗る。`date(created_at)` 軸は index に乗らないが、
  運用規模 (開発者向け可視化) では許容する

---

## 施策 4: ダミー SOP fixture

### 変更箇所

- 新規: `resources/fixtures/pipeline-smoke-sop.txt`

### 内容 (要件)

- **日本語の短い作業手順書**。3〜5 手順 + 安全上の注意を数行
- `manual.analysis_min_text_bytes` (100) を**十分に**超える (目安 400〜800 バイト)
- 日本語比率が `manual.analysis_min_japanese_ratio` (0.10) を**十分に**超える (実測 0.6 以上)
- `manual.analysis_max_text_bytes` (150,000) を大きく下回る
- 内容は無害なダミー (実在の製品・人名を書かない)

### テスト計画

- [x] 新規 `tests/Unit/Fixtures/PipelineSmokeSopFixtureTest.php`
  - ファイルが存在し UTF-8 として妥当であること
  - `strlen()` が `config('manual.analysis_min_text_bytes')` より大きいこと
  - 日本語比率が `config('manual.analysis_min_japanese_ratio')` より大きいこと
    (**判定は `SopTextExtractor` と同じ基準で行う**。比率計算を再実装しない —
    公開 API が無い場合は `SourceDocument` を作って `extract()` を通す形にし、
    「fixture がゲートを通る」ことを behavioral に固定する)
- 意義: 「smoke が fixture の不備で落ちる」という**紛らわしい失敗**を構造的に潰す

### リスク

- なし (テキスト 1 ファイル)

---

## 施策 5: pipeline smoke コマンド本体

### 変更箇所

- 新規: `app/Console/Commands/Development/PipelineSmokeCommand.php`
- 新規: `app/Enums/Smoke/SmokeStage.php` / `app/Enums/Smoke/SmokeFailureClass.php`
- 新規: `app/DataTransferObjects/Smoke/SmokeStageResultData.php` / `SmokeRunResultData.php`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし (HTTP 面を持たない)
- テストファイル: `tests/Feature/Console/PipelineSmokeCommandTest.php` (施策 9)
- **`tests/Architecture/FakeClassReferenceInvariantTest.php` の allowlist** (施策 6)

### signature

```php
protected $signature = 'dev:pipeline-smoke
    {--check : preflight だけ実行して終了する (LLM を 1 回も呼ばない = 費用ゼロ)}
    {--org= : 対象組織 id (省略時は条件を満たす先頭の組織)}
    {--json : 機械可読出力}
    {--force : 実行確認を省略する (fail-secure 条件は迂回できない)}';

protected $description = 'SOP 投入→AI 解析→撮影テイク→ffmpeg 合成→mp4 の全段が通ることを実 LLM で確認する (bug-hunt 専用・課金あり)。';
```

`Illuminate\Console\ConfirmableTrait` を use し、`confirmToProceed()` で**毎回確認**する
(`--force` で skip)。確認文には見積り費用を出す (下記「費用見積り」)。

### fail-secure 条件 (`--force` でも迂回できない)

`handle()` の**最初の実効文**で検査し、1 つでも欠ければ `self::FAILURE` で即終了する:

| # | 条件 | 根拠 |
|---|---|---|
| 1 | `app()->environment('bughunt.local')` | 実 LLM + 実 ffmpeg + チケット消費を dev / production で走らせない |
| 2 | `BughuntDatabaseGuard::isBughuntDatabase()` | dev DB へ fixture をばら撒かない (AGENTS.md「dev DB 防御 (非交渉)」) |
| 3 | `FakeStorageGate::enabled()` | 実 S3 へ書かない。fake storage 前提の設計であることの明示 |
| 4 | `config('testing.fake_llm') === false` | fake LLM のまま「通った」と報告しない |

> 4 は**自プロセスの config** であり、worker プロセスの設定は見ていない。
> worker 側が fake であった場合は「`llm_call_logs` に記録行が 0」として段 2 の判定で落ちる
> (P3。**2 層で守る**)。

### preflight (`--check` はここまでで終了)

| # | 検査 | 失敗時 |
|---|---|---|
| 1 | fail-secure 4 条件 | 即終了 |
| 2 | `manual.render_ffmpeg_binary` / `render_ffprobe_binary` が実行可能 (`-version` の終了コード 0) | `preflight` |
| 3 | 対象組織の解決 (`--org` 指定 or 条件を満たす先頭) と Default Project の解決 | `preflight` |
| 4 | チケット残高 `availableTrueBalance() >= analysis_ticket_cost + render_ticket_cost` (= 4) | `preflight` |
| 5 | SOP fixture が読め、`analysis_min_text_bytes` 以上 | `preflight` |
| 6 | 対象 3 connection の worker 到達性: `jobs` 表への insert 権限 (= DB 接続) と、`config('queue.connections.database-analysis')` / `database-render` が存在すること | `preflight` |

- worker プロセスの**生存**は preflight では判定しない (**できない**)。
  代わりに段 2 / 段 4 の「`queued` のまま上限到達」を `wiring` 失敗として明示する
- 実行対象 (org / project / 残高 / ffmpeg 版) を必ず表示してから確認を求める

### 実行の段 (すべて実在の業務経路)

| 段 | 実行 | 成功条件 (**これだけを見る**) |
|---|---|---|
| `fixture` | `ProjectService::createProject`(Default Project 不在時のみ) → `VideoManualService::create($project, 'pipeline-smoke YYYY-MM-DD HH:MM', null, $userId, $sopUploadedFile)` | manual が `draft` / `source_documents` 1 件 |
| `analysis` | `AnalysisJobService::trigger($project, $manual, $actor)` → **worker を待つ** | `analysis_jobs.status = succeeded` ∧ `video_manuals.status = ready` ∧ `cuts` ≥ 1 ∧ `scenario_version` ≥ 1 |
| `llm-evidence` | (DB 読み取りのみ) | この実行分の `llm_call_logs` に `prompt_template` ∈ {`sop-extract`, `work-decomposition`, `scenario-generation`} が**各 1 行以上** ∧ 各行の `input_tokens` > 0 |
| `capture` | 全 cut について `TakeUploadService::issue` → オブジェクト書き込み → `TakeRegistrationService::register` → `CaptureTakeService::adopt` | 全 cut の `adopted_take_id` が非 NULL ∧ 対応 take が `ready` |
| `render` | `RenderJobService::trigger($project, $manual, $actor)` → **worker を待つ** | `render_jobs.status = succeeded` ∧ `video_manuals.status = published` ∧ `output_path` 非 NULL |
| `artifact` | 出力オブジェクトをローカルへ取り出し `ffprobe` | 動画ストリーム ≥ 1 ∧ `format=duration` > 0 |

- **品質は一切見ない**: 字幕の文言・語尾・捏造の有無・カット数の妥当性・尺の妥当性は判定しない
- `cuts` は LLM 出力に依存して件数が変わる。`capture` 段は**全 cut を総なめ**する
  (件数を固定値で期待しない)

### worker 待ち (段 `analysis` / `render`)

```
2 秒間隔で job 行を再読込する:
  - status = succeeded → 成功
  - status = failed    → **待たずに即座に**失敗へ (error / step / progress を診断へ)
  - 上限到達           → timeout。status = queued なら wiring、running なら stage-timeout
上限: analysis = 1,560s (RunManualAnalysis::$timeout) + 120s
      render   = 1,500s (RunManualRender::$timeout)  + 120s
```

上限値は**ジョブ側の定数から導出**し、コマンドに独立した数値リテラルを置かない
(`AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` が固定している連鎖と
二重管理にならないよう、`(new RunManualAnalysis(0))->timeout` を読む)。

### 失敗分類 (`SmokeFailureClass`)

| case | 判定 |
|---|---|
| `Preflight` | preflight で落ちた (LLM を 1 回も呼んでいない) |
| `Wiring` | ジョブが `queued` のまま上限到達 (worker 不在 / connection 取り違え / dispatch 喪失) |
| `Llm` | この実行分の `llm_call_logs` に `failure_reason` 行がある、または記録行 0 のまま `failed` |
| `Render` | `render_jobs.error_code` が非 null、または ffprobe が非 0 終了 |
| `Storage` | 出力オブジェクトが不在 / 読み出し不能 |
| `Unknown` | 写像表に一致が無かった (**写像表の値としては使わない**) |

分類は**観測のためであり制御フローを変えない** (ドメイン規約 7 と同じ流儀)。
`Unknown` は「写像表に一致が無かった」ことを意味する。

### 出力

既定 (人間向け):

```
== preflight ==
env=bughunt.local db=bug_hunt fake_storage=on fake_llm=off
ffmpeg=7.1.5 ffprobe=7.1.5
org=#3 "Business プラン組織" project=#1 tickets=100 (required 4)

== stages ==
stage         status   elapsed   detail
fixture       ok         0.4s    manual=#12 document=#12
analysis      ok        73.2s    job=#8 cuts=9 scenario_version=1
llm-evidence  ok         0.0s    sop-extract=1 work-decomposition=1 scenario-generation=1
capture       ok        18.7s    takes=9 adopted=9
render        ok       121.5s    job=#5 output=projects/1/manuals/12/renders/v1-5.mp4
artifact      ok         0.6s    duration=21.4s streams=v:1,a:1

== llm cost (this run) ==
prompt_template          calls  in_tok  out_tok  usd       jpy
sop-extract                  1    1832      612  0.014670  2.27
work-decomposition           1    1907     1204  0.023781  3.68
scenario-generation          1    2988     2461  0.045879  7.10
TOTAL                        3    6727     4277  0.084330  13.05
注: JPY は各行の記録時レート (fx_snapshot) の合計。単一レート換算ではない
注: metadata_missing = 3 件 (AI 解析 3 段は organization/subject を記録していない)

RESULT: PASS (total 214.4s, cost $0.084330)
```

`--json` は `SmokeRunResultData` を `json_encode` した機械可読出力。
**`response()->json()` は使わない** (禁止事項 4 は HTTP 応答の規約。CLI の stdout は対象外だが、
出力生成は DTO → `json_encode` の 1 経路に閉じる)。

### 終了コード

| code | 意味 |
|---|---|
| 0 (`SUCCESS`) | 全段 ok |
| 1 (`FAILURE`) | いずれかの段が失敗 / preflight 失敗 / fail-secure 不成立 |
| 2 (`INVALID`) | オプション不正 / 確認で拒否 |

### 後始末

- 一時ディレクトリ (`storage/app/smoke/{ulid}/`) は `finally` で必ず削除する
- **DB 上の fixture (project / manual / cuts / takes / render 出力) は削除しない**。
  失敗時の調査に必要であり、bug-hunt DB は provision で `migrate:fresh` される使い捨てだから
  (削除すると「落ちた直後に中身を見る」ができなくなる = 切り分けの目的に反する)

### 費用見積り (確認プロンプトに出す値)

`config/prism-prompt-pricing.php` の `claude-sonnet-4-5-20250929` = input **$3.00** /
output **$15.00** per MTok。実測ではなく**桁の目安**として出す:

- 3 段合計の入力 ≒ 6〜8k token (prompt YAML 本体 + 前段の JSON)、出力 ≒ 4〜6k token
- → **1 回あたりおよそ $0.07〜0.12 (約 10〜20 円)**。
  LLM リトライ (`manual.analysis_llm_max_retries` = 2 → 最大 3 試行/段) が発生すると最大 3 倍程度
- 確認文には「**実測値は実行後のコストレポートに出る**」と併記し、見積りを断定しない

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`handle(): int`、各 private メソッドも DTO / void)
- [x] null 安全（`Webmozart\Assert\Assert` で option / 解決結果を検査）
- [x] DTO を返している（段の結果は `SmokeStageResultData`、全体は `SmokeRunResultData`。配列返却なし）
- [x] Generics の型パラメータが正しい（`list<SmokeStageResultData>`）

### テスト計画

施策 9 を参照。

### リスク

| リスク | 対処 |
|---|---|
| `capture` 段が全 cut 分の ffmpeg エンコードを回すため cut 数に比例して遅い | テイク動画は**1 本だけ生成して使い回す** (checksum も 1 回計算)。cut ごとに新しい key へ同じバイト列を置く |
| 実 LLM が `cuts` を 0 件にした | 段 `analysis` の成功条件 `cuts ≥ 1` で落ちる (品質ではなく**構造**の判定) |
| 実行中に同じ shard で別の LLM 呼び出しが走ると「この実行分」に混入する | **運用前提として明記**する: pipeline-smoke 実行中は同一 shard で別操作をしない。`--run-id` を metadata に載せる恒久対策は本件スコープ外 |
| `render` の尺上限ソフトゲート (20 分) | 2 秒 × cut 数なので到達しない |

---

## 施策 6: fake 参照 allowlist への登録

### 変更箇所

- 変更: `tests/Architecture/FakeClassReferenceInvariantTest.php` の `FAKE_REFERENCE_ALLOWED`

### なぜ必要か / なぜこの形にするか

`capture` 段は fake storage (`s3_fake` disk) に**実バイト**を置く必要がある
(置かないと `RenderPipeline::downloadSources()` が失敗する)。書き込み口は 3 通りあり、
**いずれも「本番コードから fake を触る」か「外向き到達点を増やす」かのどちらかを伴う**:

| 案 | 内容 | 判定 |
|---|---|---|
| A | `FakeObjectStore::storeStreamed()` を直接呼ぶ | **採用**。allowlist 1 行の追加で済む。既存の `Put/GetFakeStorageObjectController` と**同 species** (= `FakeStorageGate` 成立時のみ意味を持つ利用点) という先例がある |
| B | presigned URL へ `Http::put()` (loopback) | 却下。`ExternalSeamInventory` の母集団に入り、閉じた語彙の `ExternalSeamKind` に該当 case が無い (**新 case の追加が必要** = 摩擦が大きい)。さらに `php artisan serve` の生存に依存する |
| C | `Storage::disk('s3_fake')` を直接叩き sidecar を手書き | 却下。sidecar (completion marker) の形式を二重管理することになり、`FakeObjectStore` の不変条件を壊しうる |

**案 A で失われるもの (誇張しない)**: presigned PUT の**署名検証・ヘッダ契約**
(`PutFakeStorageObjectController` の checksum 三者一致 1/2) は通らない。
ただしこれは fake 固有の emulation であり、**本番の実 S3 presigned PUT は本 smoke では
そもそも検証できない** (実 S3 配線はスコープ外)。したがって案 A で失うのは
「fake の受け口 controller の配線」であって「本番の配線」ではない。
`FakeObjectStore` が担保する **checksum 三者一致の 3/3 (実 body == 期待値)** は通る。

### 変更後コード

```php
const FAKE_REFERENCE_ALLOWED = [
    'app/Providers/FakeExternalsServiceProvider.php',
    'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
    'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
    // bug-hunt 専用の通し確認コマンド。fake storage へ実バイトを置く必要があり、
    // FakeStorageGate 成立時のみ動く (上 2 件の controller と同 species)。
    // 本番経路からは到達しない (artisan 手動実行のみ・スケジュール登録なし)。
    'app/Console/Commands/Development/PipelineSmokeCommand.php',
    'bootstrap/providers.php',
];
```

### テスト計画

- [x] 既存 gate が緑のまま (allowlist の 1 行追加のみ)
- [x] `4-2 配置例外は 2 件から増えていない` は**変更しない** (`placementExceptions()` は触らない)

### リスク

- allowlist を増やす方向はこの gate が戒めている行為である。
  **緩和ではなく「同 species の先例に載せる」判断**であることをコメントで残し、
  条件 (`FakeStorageGate` 成立時のみ動く / 本番経路から到達しない) を明記する

---

## 施策 7: bug-hunt レーンからの起動導線

### 変更箇所

- 変更: `scripts/bug-hunt-shard.sh`

### 追加するもの

1. `artisan_with_mode_for_shard()` — 既存 `artisan_for_shard()` と同型だが
   **`MODE_ENV` + `LLM_KEY_ENV` を載せる** (serve / worker と同一の env 隔離。
   `secret_xtrace_off` / `restore` で挟む)
2. `cmd_pipeline_smoke()`:
   - **最初の実効文で `require_orchestrator "pipeline-smoke"`**
     (DB 防御ではなく**費用**の防壁。子セッションに実行させない)
   - `require_manifest` で provision 済みを確認し、db / url を manifest から取る
   - `prepare_mode_and_preflight` (= `build_mode_env` → `assert_llm_key_present`)。
     実キーが無ければ fail-fast
   - `artisan_with_mode_for_shard "${db}" "${url}" dev:pipeline-smoke --force "$@"`
3. `main()` の `case` に `pipeline-smoke)` を追加 (`--shard` / `--run-id` / `--check` を受ける)
4. usage ヘッダ (ファイル冒頭コメント) に 1 行追記 (`usage()` が動的に切り出す)

### 追加しないもの

- **`generate_wrapper()` の許可サブコマンドには追加しない**。
  子 (探索エージェント) セッションから叩けるのは `db-check` / `db-exists` / `mail-urls` /
  `reseed` のみのままとする = **探索中に誤って費用を発生させられない**

### テスト計画

- [x] `scripts/bug-hunt-shard.sh self-test` に dryrun ケースを 1 つ追加:
      `BUGHUNT_ORCHESTRATOR` 無しで `pipeline-smoke` が **副作用の前に die** すること
- [x] `tests/Architecture/BughuntOrchestratorGateInvariantTest.php` の
      「最初の実効文で require_orchestrator」テストの期待表に `cmd_pipeline_smoke` を追加
- [x] `tests/Architecture/BughuntShardCapInvariantTest.php` / `BughuntRawDbCommandInventoryTest.php`
      が緑のまま (createdb / dropdb を増やさない)

### リスク

- `require_orchestrator` の呼び出し元が 3 → 4 に増える。AGENTS.md の記述は
  「`provision`/`teardown` は親のみ」であり、gate はその 3 つを**必須**として固定しているだけで
  追加を禁じてはいない。AGENTS.md 側にも 1 行追記する (施策 8)

---

## 施策 8: ドキュメント追記

### 変更箇所

| ファイル | 追記内容 |
|---|---|
| `AGENTS.md` §bug-hunt | `pipeline-smoke` サブコマンドの存在、**実 LLM で課金が発生する**こと、`BUGHUNT_ORCHESTRATOR=1` 必須、子 wrapper には露出しないこと |
| `docs/architecture.md` | 「パイプライン通し確認 (pipeline smoke)」節: 段の定義・合否条件・**保証しないもの**・LLM コストレポートの粒度と通貨の扱い |
| `.claude/skills/app-bug-hunt/SKILL.md` | 探索エージェントは pipeline-smoke を**実行しない**こと (親が実行する) |

### 「保証しないもの」(誇張しない。docs へそのまま書く)

1. **生成物の品質は一切保証しない**。字幕の文言・語尾・捏造の有無・カット数の妥当性・
   尺の妥当性は判定していない。判定しているのは「期待した状態遷移が起きたか」だけ
2. **実 S3 は検証していない**。presigned PUT の署名・ヘッダ契約 (fake 受け口 controller の
   1/2 と 2/2) も通っていない。通るのは `FakeObjectStore` の checksum 3/3 だけ
3. **ブラウザ (撮影 PWA) の実機経路は検証していない**。CLI から Service を呼んでいる
4. **worker プロセスの LLM モードを直接は見ていない**。`llm_call_logs` の記録行の存在で
   間接的に実呼び出しを実証している (fake は event を発火しないため)
5. **費用は「この実行で記録された行の合計」**であり、provider 側の請求額とは一致しない
   (`pricing_snapshot` は呼び出し時点の価格表。JPY は行ごとの記録時レート合計)
6. **組織単位・マニュアル単位の費用集計は現状できない**。AI 解析 3 段は
   `withMetadata()` を呼んでおらず `organization_id` / `subject_*` が NULL
   (`metadata_missing = true`)。レポートは件数として必ず表示する
7. **並行実行に対する保証は無い**。「この実行分」は `llm_call_logs.id` の差分で切り出しており、
   同一 shard で別の LLM 呼び出しが並行すると混入する
8. **1 回通ったことは、次も通ることを意味しない**。実 LLM の出力は非決定的である

---

## 施策 9: テスト

**実 LLM を 1 回も呼ばない。** テストレーンの `StrayLlmCallGuard` / `StrayHttpRequestGuard` は
既定のまま (opt-out しない)。

| ファイル | 検証内容 |
|---|---|
| `tests/Unit/Support/BughuntDatabaseGuardTest.php` | DB 名判定表 (正例 3 / 負例 5)。純関数のため DB 不要 |
| `tests/Unit/Fixtures/PipelineSmokeSopFixtureTest.php` | SOP fixture が `SopTextExtractor` のゲート (バイト数・日本語比率) を通ること |
| `tests/Unit/Services/LlmCostReportServiceTest.php` | 集計軸 / null 未解決の分離 / `afterId` 境界 / `since`・`until` 境界 / 0 件時の形 |
| `tests/Feature/Console/LlmCostReportCommandTest.php` | 既定表示 / `--json` shape / 不正 `--group-by` の終了コード |
| `tests/Feature/Console/PipelineSmokeCommandTest.php` | 下表 |

### `PipelineSmokeCommandTest` の観点

| # | ケース | 期待 |
|---|---|---|
| 1 | `testing` 環境 (= bughunt.local でない) で実行 | 終了コード 1。**LLM も ffmpeg も呼ばれない**。「fail-secure 条件」の不成立理由が出力に出る |
| 2 | env は満たすが DB 名が bug-hunt でない | 終了コード 1 |
| 3 | `config('testing.fake_llm') = true` | 終了コード 1 (fake のまま「通った」と言わせない) |
| 4 | `--force` を付けても 1〜3 は迂回できない | 終了コード 1 |
| 5 | 4 条件を満たし `--check` | preflight の結果が出て終了。**`Prompt` の fake すら install せず、`StrayLlmCallGuard` が赤くならない** = LLM を 1 回も呼んでいないことの機械的な保証 |
| 6 | `--check` で ffmpeg バイナリが不在 (config を存在しないパスへ差し替え) | 終了コード 1 / `failure_class = preflight` |
| 7 | `--check` でチケット残高不足 | 終了コード 1 / `failure_class = preflight` |
| 8 | `--check --json` | `SmokeRunResultData` の shape (キー集合・型) が固定される |
| 9 | 確認プロンプトで拒否 (`--force` なし・`expectsConfirmation(false)`) | 終了コード 2 / 何も実行しない |

> **ケース 1〜4 を Feature テストで成立させる方法**: 4 条件は `app()->environment()` /
> DB 名 / `FakeStorageGate` / config で決まる。テスト側は `config()->set()` と
> `App::detectEnvironment()` 相当ではなく、**判定を注入可能にする**のではなく
> **条件の各要素を config / 環境から読む純関数へ寄せ**、テストは
> `$this->app->detectEnvironment(fn () => 'bughunt.local')` と
> `DB::connection()->getDatabaseName()` のスタブではなく、
> **`BughuntDatabaseGuard` を container で差し替える**ことで成立させる
> (`FakeStorageGate` が既に container 解決される先例と同型)。

### なぜ「全段を fake で通すテスト」を書かないか

`Prompt::fake` + `Process::fake` + `Storage::fake` で全段を回すテストは書ける。
しかし **書かない**。理由:

1. 各段の配線は既に段ごとの Feature テストが持っている (`AnalysisPipeline` /
   `RenderPipeline` / `TakeRegistrationService` の既存テスト群)。同じものを再実装するのは
   重複であり、思考原則 2 に反する
2. `Process::fake()` で ffmpeg を fake すると、**このコマンドの唯一の固有価値
   (実 ffmpeg が本当に回るか) が消える**。fake で緑になる smoke テストは
   「smoke が動く」ことの証明にならない (偽グリーン)
3. smoke コマンドの**固有ロジック**は「fail-secure 条件 / preflight / 待ちと分類 / 集計と出力」で
   あり、これらは上表で実 LLM なしに固定できる

**代わりに固定するもの**: 待ちと分類のロジックは、`analysis_jobs` / `render_jobs` /
`llm_call_logs` を Factory で任意の状態に置いてから**分類関数を直接呼ぶ**単体テストで
判定表を固定する (`queued` のまま → `Wiring` / `failure_reason` あり → `Llm` / 等)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 1 が既存 seeder の共有 trait に触れ、施策 6 が Architecture gate の定数に触れ、施策 7 が `scripts/bug-hunt-shard.sh` (2,528 行) に触れる。いずれも他タスクと衝突しやすい共有面であり、1 本の worktree で通して整合を取ってからマージするのが安全。また施策 2〜5 は互いに依存する (5 が 2 と 4 を使う) ため分割しても直列化される |
| 競合リスク | `scripts/bug-hunt-shard.sh` と `tests/Architecture/FakeClassReferenceInvariantTest.php` を同時に触る他タスクがあれば衝突する。`AGENTS.md` / `docs/architecture.md` の追記も競合しやすい |

## 実装後の検証コマンド

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`scripts/bug-hunt-shard.sh self-test` (実資源に触れない)。
**`dev:pipeline-smoke` 自体は CI で実行しない** (実 provider へ出て課金が発生するため。
`llm-model-check-cli` の先例と同じ扱い)。

## 実装後の台帳 (lctl) 還流

コストレポートの先例が家系に無いことを確認済みなので、実装後に
`mcp__lctl__append_event` で `status_reported` を出せる形にしておく。
起票先の feature が無い (新規起票は MCP では不可) ため、**キュレーター巡回への起票依頼**が
必要である旨を実装タスクの申し送りに書く。本設計フェーズでは台帳へ書き込まない。

---

## 関連する現行コード (抜粋。実読済み)

### app/Jobs/Manual/RunManualAnalysis.php (抜粋)
```php
class RunManualAnalysis implements ShouldQueue
{
    public int $tries = 1;
    public int $timeout = 1560;
    public function __construct(public readonly int $analysisJobId)
    { $this->onConnection('database-analysis'); }
    public function handle(AnalysisPipeline $pipeline): void { $pipeline->run($this->analysisJobId); }
    public function failed(?Throwable $exception): void { /* failJob (冪等) */ }
}
```
RunManualRender も同型 (`$timeout = 1500` / `onConnection('database-render')`)。

### app/Services/Manual/AnalysisJobService.php::trigger (抜粋)
```php
public function trigger(Project $project, VideoManual $manual, ?User $actor = null): AnalysisJob
{
    return DB::transaction(function () { /* manual 行ロック / status guard(draft|ready) /
        in-flight 冪等 / 最新 SourceDocument 選択 (無ければ ValidationException) /
        残高事前チェック(availableTrueBalance) / job 起票 / manual を analyzing へ /
        RunManualAnalysis::dispatch($job->id)  ← 業務 tx の内側 */ });
}
```
RenderJobService::trigger は status=ready 必須・全 cut に adopted ready take 必須・尺上限・残高。

### app/Services/Capture/TakeUploadService.php::issue (抜粋)
```php
public function issue(Organization $o, Project $p, VideoManual $m, Cut $c, TakeUploadInput $in): TakeUploadTicketData
// org 行ロック → manual 状態 guard(ready|published) → Quota(max_storage_bytes) →
// S3 キーはサーバ生成 → 予約 insert → tx 外で presign + 署名チケット seal
```
`TakeUploadInput(string $clientTakeId, int $sizeBytes, string $contentType, Sha256Checksum $checksum)`
`Sha256Checksum::fromBase64()` は base64 正当性 + 32 バイトを保証。

### app/Services/Capture/TakeRegistrationService.php::register (抜粋)
チケット開封 → 予約再解決 → 冪等分岐 → 予約 claim(pending→verifying の CAS) →
`$this->storage->headObject($reservation->video_path)` の三点照合 (size / content_type /
ChecksumSHA256) → tx で Take 作成 + 予約 completed の CAS。
※ headObject が null (= オブジェクト未 PUT) なら 422。

### app/Services/Storage/Fakes/FakeObjectStore.php (抜粋)
```php
public const string DISK = 's3_fake';
public function storeStreamed(string $key, mixed $input, string $contentType, string $expectedChecksum): void
// stream で sha256 を計算しつつ tmp へ書き、期待値と hash_equals、
// key ロック下で sidecar 削除 → rename → sidecar 書き込み (completion marker)
public function head(string $key): ?ObjectMetadataData
```

### tests/Architecture/FakeClassReferenceInvariantTest.php (抜粋)
```php
/** 参照 allowlist: fake 系クラスを参照してよい本番ファイル (repo ルート相対) */
const FAKE_REFERENCE_ALLOWED = [
    'app/Providers/FakeExternalsServiceProvider.php',
    'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
    'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
    'bootstrap/providers.php',
];
// 走査根: app/ routes/ config/ bootstrap/
// 「誤検出が出たら allowlist を足す方向へ倒さない。まず本当に本番コードから fake を
//   参照しているのかを疑う (それが本 gate の目的)」とコメントに明記されている
```

### app/Support/FakeStorageGate.php (抜粋)
```php
public function enabled(): bool
{
    if (config('testing.fake_storage') !== true) { return false; }
    $env = $this->app->environment();
    if ($env === 'bughunt.local') { return true; }
    return $env === 'testing' && $this->app->runningUnitTests();
}
```

### app/Enums/Security/ExternalSeamKind.php (抜粋)
```php
// ★閉じた語彙にする。新しい `Http::` 直呼びが増えたとき、既存 case のどれにも当てはまらなければ
//   **case を足す判断**を通す
enum ExternalSeamKind: string {
    case Payment; case SocialLogin; case Captcha; case Mail; case MarketData;
    case ObjectStorage; // 委譲専用
    case Llm;           // 委譲専用
}
```

### vendor/kent013/laravel-prism-prompt/src/Prompt.php::executePrism (抜粋)
```php
if (static::isFaking() && static::$fake !== null) {
    static::$fake->record(...); return static::$fake->nextResponse()->getText();  // ← 短絡
}
$executionId = (string) Str::uuid();
... $result = $builder->asText();
event(new PromptExecutionCompleted(... cost: $cost, metadata: $this->metadata_context ...));
```
= fake 時は PromptExecutionCompleted が発火せず llm_call_logs に 1 行も残らない。

### database/migrations/2026_06_11_090000_create_llm_call_logs_table.php (列)
execution_id(unique) / organization_id / user_id / subject_type / subject_id /
prompt_class / prompt_template / provider / model / finish_reason / step_count /
input_tokens / output_tokens / cache_write_input_tokens / cache_read_input_tokens /
thought_tokens / input_cost_usd(decimal 12,6) / output_cost_usd / total_cost_usd /
pricing_snapshot(json) / fx_snapshot(json) / total_cost_jpy(decimal 12,2) /
duration_ms / request_id / metadata_missing(bool) / failure_reason(varchar 500) / created_at
index: (organization_id, created_at) / (subject_type, subject_id) / (model, created_at) /
prompt_template / metadata_missing
※「コストの null は upstream の pricing 解決失敗。0.0 は unknown モデルの zero-cost snapshot
   (正常系) なので区別して保持する」とコメントに明記されている

### scripts/bug-hunt-shard.sh (抜粋)
```bash
BUGHUNT_WORKER_CONNECTIONS=(database-analysis database-render database-media)
build_mode_env() {   # MODE_ENV はフラグのみ / LLM_KEY_ENV に実キー (real-llm 時のみ)
    MODE_ENV+=("TESTING_FAKE_LLM=false"); LLM_KEY_ENV+=("ANTHROPIC_API_KEY=${key}")
    MODE_ENV+=("TESTING_FAKE_STORAGE=true") }
artisan_for_shard() {   # migrate/seed 用。MODE_ENV も LLM_KEY_ENV も載せない
    guard_bughunt_runtime "${db}" bughunt
    env -i PATH HOME DB_* APP_URL php artisan "$@" --env=bughunt.local }
generate_wrapper() {  # 子セッションに許可されるのは db-check|db-exists|reseed|mail-urls のみ }
require_orchestrator() {  # BUGHUNT_ORCHESTRATOR=1 を持つ親のみ。無ければ die 1 }
```
main() のフラグ検査: `--real-llm / --fake-llm / --real-storage は provision または
provision-all でのみ使える` (それ以外の sub でこれらのフラグを付けると die 2)。

### config/manual.php (抜粋)
analysis_ticket_cost=1 / render_ticket_cost=3 / analysis_llm_max_retries=2 /
analysis_deadline_seconds=1080 / analysis_max_text_bytes=150000 /
analysis_min_text_bytes=100 / analysis_min_japanese_ratio=0.10 /
render_max_total_source_ms=1200000 / render_ffmpeg_binary=env(RENDER_FFMPEG_BINARY,'ffmpeg')

### app/Services/Project/DefaultProjectResolver.php
「org の先頭 project (projects.id 昇順の最初)」= Default Project。
OrganizationProvisioningService::provision() は Project を作らない。
ProjectService::createProject(Organization, string $name, ?string $description): Project
(Quota(max_projects) チェック内包)。
