# 詳細設計: scenario-validation-report (シナリオ生成のバリデーション結果表示)

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**。実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、帰属 (organization / subject) を付ける)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン（AGENTS.md 参照）/ アーリーリターン推奨
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く (Service 委譲)
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

`devnotes/20260817-0005-scenario-validation-report/conceptual-design.md` (conceptual-review Round 4 で APPROVED)

概念設計からの**細部の精緻化 2 点** (方針は不変):

1. `works` の要素を `{title: string}` の object ではなく**文字列そのもの** (`list<string>`) にする。
   出力 token と DTO 検証が減り、意味は変わらない。
2. 位置 (`positions`) は専用 DTO を作らず `list<array{step: int, point: int|null}>` の
   array shape で持つ (PHPStan level 10 は array shape で十分に型付けできる。クラスを 1 つ減らす)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| M1 | プロンプトへの `validation` 追加 | `resources/prompts/work-decomposition.yaml` | 高 |
| M2 | LLM 出力 DTO の再構成 (単一 decode + 妥当性 DTO) | `app/DataTransferObjects/Manual/Analysis/{WorkDecompositionResponseData,SopValidationData,WorkDecompositionData}.php`, `app/Enums/Manual/ScenarioVerdict.php`, `app/Support/Manual/LlmJson.php`, `app/Exceptions/Manual/LlmOutputInvalidException.php` | 高 |
| M3 | 保存先カラム `analysis_jobs.validation_json` | `database/migrations/{実装日}_000100_add_validation_json_to_analysis_jobs_table.php`, `app/Models/AnalysisJob.php`, `database/factories/AnalysisJobFactory.php` | 高 |
| M4 | パイプラインの保存とリトライログの構造化 | `app/Services/Manual/AnalysisPipeline.php` | 高 |
| M5 | 規約検査 (決定的算出) | `app/Support/Manual/ScenarioRuleCheck.php`, `app/Enums/Manual/ScenarioRuleCode.php`, `app/DataTransferObjects/Manual/{ScenarioRuleFindingData,ScenarioReportData}.php` | 高 |
| M6 | props 組み立てと Controller 配線 | `app/Services/Manual/ScenarioReportBuilder.php`, `app/DataTransferObjects/Manual/ScenarioVerdictViewData.php`, `app/Http/Controllers/Projects/VideoManualController.php` | 高 |
| M7 | 画面 (型・ラベル・パネル・配置) | `resources/js/types/manual.ts`, `resources/js/components/features/manual/ScenarioReportPanel.svelte`, `resources/js/pages/Manuals/Show.svelte` | 高 |
| M8 | fake / 既存テストの追随 | `app/Services/AI/Testing/CannedPromptResponses.php`, `tests/Feature/Llm/CannedPromptResponsesTest.php`, `tests/Feature/Projects/AnalysisPipelineTest.php`, `tests/js/pages/ManualsShow.test.ts` | 高 |
| M9 | ドキュメント更新 | `docs/architecture.md`, `doc/03_AI解析とシナリオ生成.md` | 中 |

---

## M1: プロンプトへの `validation` 追加

### 変更箇所

- ファイル: `resources/prompts/work-decomposition.yaml` (prompt 節の「出力スキーマ」まわり)

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: M2 の `SopValidationData` と 1:1 (スキーマの正本は YAML、検証の正本は DTO)
- テストファイル: `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` / `AnalysisTimeBudgetInvariantTest.php` は
  **変更しない** (`max_tokens: 16000` / `client_options.timeout: 360` を触らないため。触っていないことがこの 2 本で守られる)
- `DefensiveInstructionsPresenceTest` / `PromptDefenseWindowGateTest`: system_prompt と untrusted 変数 (`$extracted`) を
  変えないため変更なし

### 現行コード

```yaml
prompt: |
  次の抽出済み手順書データから「作業分解表」を作成し、JSON で出力してください。

  ルール:
  - 一動作・一 No (1 文に複数動詞があれば行を分ける)
  - 手順 (action) は物理的な動詞のみ (「〇〇の清掃」等の括りは禁止)
  - 急所 (points) は判断基準・数値・良否境界・資料の注釈のみ。1 急所 1 要素
  - 資料にない語を足さない (指差呼称含め忠実に)
  - steps は 100 行以内、points は 1 行あたり 20 要素以内

  出力スキーマ:
  { "steps": [ { "no": int, "action": string, "points": [string] } ] }

  抽出済み手順書データ:
  {{ $extracted }}
```

### 変更後コード

```yaml
prompt: |
  次の抽出済み手順書データから「作業分解表」と「妥当性の所見」を作成し、JSON で出力してください。

  ルール (作業分解表):
  - 一動作・一 No (1 文に複数動詞があれば行を分ける)
  - 手順 (action) は物理的な動詞のみ (「〇〇の清掃」等の括りは禁止)
  - 急所 (points) は判断基準・数値・良否境界・資料の注釈のみ。1 急所 1 要素
  - 資料にない語を足さない (指差呼称含め忠実に)
  - steps は 100 行以内、points は 1 行あたり 20 要素以内

  ルール (妥当性の所見。人が最終確認するための材料であり、作業分解表の内容は変えない):
  - verdict は 3 値。"valid" = 動画マニュアルの元資料として成立している /
    "needs_review" = 成立しているが確認すべき欠落・曖昧さがある /
    "invalid" = 手順書として読み取れず動画マニュアルの元にできない
  - reason は判定の理由を 1 文 (200 文字以内) で書く。資料にない事実を足さない
  - works はこの資料に含まれる「作業」の仮タイトル一覧 (1〜10 件、各 60 文字以内)。
    1 資料に 1 作業しか無ければ 1 件だけ返す
  - split_recommended は「1 マニュアル 1 作業に分けた方がよいか」の真偽。
    works が 2 件以上でも 1 本の動画が妥当なら false でよい

  出力スキーマ:
  {
    "steps": [ { "no": int, "action": string, "points": [string] } ],
    "validation": {
      "verdict": "valid"|"needs_review"|"invalid",
      "reason": string,
      "works": [string],
      "split_recommended": bool
    }
  }

  抽出済み手順書データ:
  {{ $extracted }}
```

先頭コメントに「`validation` は doc/03 §3.4 のバリデーション結果のうち **LLM にしか判断できない項目**
だけを載せる。件数・文体検査は PHP 側 (`ScenarioRuleCheck`) が決定的に算出する」と、
**「この判定は表示専用で制御フローには使わない」**を追記する
(同じ表現を M9 の `docs/architecture.md` にも置き、YAML と docs で言い方を揃える)。

### PHPStan適合チェック

- [x] 対象外 (YAML)

### テスト計画

- [ ] `tests/Architecture/AnalysisTokenBudgetInvariantTest.php` が緑のまま (max_tokens 不変の確認)
- [ ] `tests/Feature/Llm/CannedPromptResponsesTest.php` の signature 判定 (`作業標準化エキスパート`) が緑のまま
      (system_prompt を変えないため)

### リスク

- プロンプト本文が伸びる (日本語で約 400 字)。固定プロンプト余裕 (4,000 token) の内側で、
  入力上限 `analysis_max_text_bytes` には影響しない。

---

## M2: LLM 出力 DTO の再構成 (単一 decode + 妥当性 DTO)

### 変更箇所

- 新規: `app/Enums/Manual/ScenarioVerdict.php`
- 新規: `app/DataTransferObjects/Manual/Analysis/SopValidationData.php`
- 新規: `app/DataTransferObjects/Manual/Analysis/WorkDecompositionResponseData.php`
- 変更: `app/DataTransferObjects/Manual/Analysis/WorkDecompositionData.php` (L26 `fromLlmText` → `fromPayload`)
- 変更: `app/Support/Manual/LlmJson.php` (`schemaViolation` に違反パスを持たせる)
- 変更: `app/Exceptions/Manual/LlmOutputInvalidException.php` (`$path` プロパティ追加)

### 波及変更

- TypeScript型定義: `ScenarioVerdict` の literal union を `resources/js/types/manual.ts` に追加 (M7)
- API Resource/DTO: `SopValidationData::toArray()` が `validation_json` の保存 shape 兼 props の入力
- テストファイル: `tests/Feature/Llm/CannedPromptResponsesTest.php` (`WorkDecompositionData::fromLlmText` の
  呼び出しを `WorkDecompositionResponseData::fromLlmText` へ)、`tests/Feature/Projects/AnalysisPipelineTest.php`

### 現行コード

```php
// WorkDecompositionData.php
public static function fromLlmText(string $text): self
{
    $decoded = LlmJson::decode($text);

    $rawSteps = $decoded['steps'] ?? null;
    // ... (検証本体)
}

// LlmJson.php
public static function schemaViolation(string $detail): LlmOutputInvalidException
{
    return new LlmOutputInvalidException(LlmOutputInvalidReason::SchemaViolation, $detail);
}

// LlmOutputInvalidException.php
public function __construct(
    public readonly LlmOutputInvalidReason $reason,
    string $detail,
) {
    parent::__construct("AI の応答を解釈できませんでした。再実行してください。({$reason->value}: {$detail})");
}
```

### 変更後コード

```php
// app/Enums/Manual/ScenarioVerdict.php (新規)
/**
 * 手順書が動画マニュアルの元資料として成立しているかの所見 (LLM 判断)。
 * **制御フローには使わない** (表示のみ。保存・撮影・レンダを止めない)。
 * TS 側 types/manual.ts の ScenarioVerdict union と値集合を一致させる。
 */
enum ScenarioVerdict: string
{
    case Valid = 'valid';
    case NeedsReview = 'needs_review';
    case Invalid = 'invalid';
}
```

```php
// app/DataTransferObjects/Manual/Analysis/SopValidationData.php (新規)
/**
 * work-decomposition プロンプト出力の `validation` (手順書に対する所見) の検証済み DTO。
 *
 * 2 つの入口を持ち、**厳しさが違う**ことがこの DTO の要点である:
 * - fromPayload(): LLM 応答用。不正は LlmOutputInvalidException (= 有界リトライ)。
 * - fromStorage(): 保存済み JSON 用。不正は null + Log::warning (詳細画面を落とさない)。
 * どちらも同一の parse() を通るため、保存 shape と応答 shape は構造的に一致する。
 */
final readonly class SopValidationData
{
    public const int MAX_REASON_CHARS = 200;

    public const int MAX_WORKS = 10;

    public const int MAX_WORK_TITLE_CHARS = 60;

    /** @param list<string> $works */
    public function __construct(
        public ScenarioVerdict $verdict,
        public string $reason,
        public array $works,
        public bool $splitRecommended,
    ) {}

    /**
     * LLM 応答 (decode 済み全体) から `validation` を厳格に取り出す。
     *
     * @param  array<array-key, mixed>  $decoded
     */
    public static function fromPayload(array $decoded): self
    {
        $raw = $decoded['validation'] ?? null;
        if (! is_array($raw)) {
            throw LlmJson::schemaViolation('validation は object でなければなりません', 'validation');
        }

        return self::parse($raw);
    }

    /**
     * 保存済み JSON からの復元 (壊れていたら null + 警告)。
     * **保存値の本文はログに載せない** (LLM 由来の可変文字列)。
     *
     * ★ 引数は `mixed` である。JSON カラムは cast の結果が array とは限らず
     *   (scalar / string が入っていれば `?array` 型宣言は **TypeError で詳細画面を落とす**)、
     *   「壊れていても画面を落とさない」という本メソッドの目的と矛盾するため。
     *   null は正常 (未生成)、array 以外は復元失敗として扱う。
     */
    public static function fromStorage(mixed $stored, int $analysisJobId): ?self
    {
        if ($stored === null) {
            return null; // 未生成 (旧ジョブ) は正常系
        }
        try {
            if (! is_array($stored)) {
                throw LlmJson::schemaViolation('validation_json が object ではありません', 'validation');
            }

            return self::parse($stored);
        } catch (LlmOutputInvalidException $exception) {
            Log::warning('解析ジョブの妥当性所見の復元に失敗しました', [
                'analysis_job_id' => $analysisJobId,
                'failure_category' => $exception->reason->value,
                'failure_path' => $exception->path,
            ]);

            return null;
        }
    }

    /** @param array<array-key, mixed> $raw */
    private static function parse(array $raw): self
    {
        $rawVerdict = $raw['verdict'] ?? null;
        // tryFrom の結果を変数で保持する (from() で二度引かない)
        $verdict = is_string($rawVerdict) ? ScenarioVerdict::tryFrom($rawVerdict) : null;
        if ($verdict === null) {
            throw LlmJson::schemaViolation(
                'validation.verdict は valid / needs_review / invalid のいずれかでなければなりません',
                'validation.verdict',
            );
        }

        $reason = $raw['reason'] ?? null;
        if (! is_string($reason) || trim($reason) === '') {
            throw LlmJson::schemaViolation('validation.reason は非空文字列でなければなりません', 'validation.reason');
        }
        if (mb_strlen($reason) > self::MAX_REASON_CHARS) {
            throw LlmJson::schemaViolation('validation.reason が文字数上限を超えています', 'validation.reason');
        }

        $rawWorks = $raw['works'] ?? null;
        if (! is_array($rawWorks) || ! array_is_list($rawWorks)) {
            throw LlmJson::schemaViolation('validation.works は配列でなければなりません', 'validation.works');
        }
        if (count($rawWorks) < 1 || count($rawWorks) > self::MAX_WORKS) {
            throw LlmJson::schemaViolation(
                'validation.works は 1 件以上 '.self::MAX_WORKS.' 件以内でなければなりません',
                'validation.works',
            );
        }
        $works = [];
        foreach ($rawWorks as $index => $work) {
            if (! is_string($work) || trim($work) === '') {
                throw LlmJson::schemaViolation("validation.works.{$index} は非空文字列でなければなりません", "validation.works.{$index}");
            }
            if (mb_strlen($work) > self::MAX_WORK_TITLE_CHARS) {
                throw LlmJson::schemaViolation("validation.works.{$index} が文字数上限を超えています", "validation.works.{$index}");
            }
            $works[] = $work;
        }

        $split = $raw['split_recommended'] ?? null;
        if (! is_bool($split)) {
            throw LlmJson::schemaViolation(
                'validation.split_recommended は真偽値でなければなりません',
                'validation.split_recommended',
            );
        }

        return new self($verdict, $reason, $works, $split);
    }

    /** 作業数は保存も出力もせず count() で導出する (LLM に数えさせない) */
    public function workCount(): int
    {
        return count($this->works);
    }

    /**
     * @return array{verdict: string, reason: string, works: list<string>, split_recommended: bool}
     *         validation_json の保存 shape (fromStorage が受理する shape と同一)
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict->value,
            'reason' => $this->reason,
            'works' => $this->works,
            'split_recommended' => $this->splitRecommended,
        ];
    }
}
```

```php
// app/DataTransferObjects/Manual/Analysis/WorkDecompositionResponseData.php (新規)
/**
 * work-decomposition プロンプトの応答全体 (`{ steps, validation }`)。
 * **decode は本クラスの fromLlmText() だけが行う** (同じ応答を 2 回パースしない)。
 */
final readonly class WorkDecompositionResponseData
{
    public function __construct(
        public WorkDecompositionData $decomposition,
        public SopValidationData $validation,
    ) {}

    public static function fromLlmText(string $text): self
    {
        $decoded = LlmJson::decode($text);

        return new self(
            WorkDecompositionData::fromPayload($decoded),
            SopValidationData::fromPayload($decoded),
        );
    }
}
```

```php
// WorkDecompositionData.php (変更: 入口を fromPayload に付け替える。fromLlmText は削除)
/**
 * @param  array<array-key, mixed>  $decoded  応答全体 (decode は呼び出し側が 1 回だけ行う)
 */
public static function fromPayload(array $decoded): self
{
    $rawSteps = $decoded['steps'] ?? null;
    // ... 以下の検証本体は現行のまま。schemaViolation に第 2 引数 (パス) を渡す形へ揃える
}
```

```php
// LlmJson.php (変更)
/** スキーマ違反の例外を生成する (DTO 検証用の短縮形)。$path は観測用の違反位置 */
public static function schemaViolation(string $detail, ?string $path = null): LlmOutputInvalidException
{
    return new LlmOutputInvalidException(LlmOutputInvalidReason::SchemaViolation, $detail, $path);
}

// LlmOutputInvalidException.php (変更)
public function __construct(
    public readonly LlmOutputInvalidReason $reason,
    string $detail,
    /** 違反位置 (例: validation.works.2)。観測専用で制御フローには使わない */
    public readonly ?string $path = null,
) {
    parent::__construct("AI の応答を解釈できませんでした。再実行してください。({$reason->value}: {$detail})");
}
```

> `$path` は**追加の任意引数**であり、既存の全呼び出し (extract / generate の DTO) は無変更で
> `null` のまま動く。パスを埋めるのは 2 段目の 2 DTO
> (`WorkDecompositionData` / `SopValidationData`) だけである = 概念設計の観測条件
> 「validation 側の違反を steps 側と識別できる」を最小の差分で満たす。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`self` / `?self` / array shape)
- [x] null 安全 (`is_string` / `is_array` / `array_is_list` / `is_bool` の narrowing で `mixed` を潰す)
- [x] DTO を返している (配列返却は `toArray()` のみで array shape 付き)
- [x] Generics の型パラメータ (`list<string>` を PHPDoc で明示)
- [x] `readonly` クラス + `public const int` (既存 `ScenarioLimits` と同じ書式)

### テスト計画

- [ ] 新規 `tests/Unit/Manual/SopValidationDataTest.php`:
      正常 (3 verdict 値) / verdict 不正 / reason 空 / reason 超過 / works 0 件 / works 11 件 /
      works 要素が非文字列 / タイトル超過 / split_recommended が bool でない → いずれも
      `LlmOutputInvalidException` かつ `path` が `validation.*` であること
- [ ] `fromStorage`: 正常復元 / null 入力は null / 壊れた配列は null + `Log::warning` が記録される
      (`Log::shouldReceive('warning')` ではなく `Log::spy()` で 1 回呼ばれることを検証)
- [ ] 新規 `tests/Unit/Manual/WorkDecompositionResponseDataTest.php`:
      steps と validation の両方が揃った応答を 1 回の decode で組み立てる /
      `validation` 欠落は `LlmOutputInvalidException` (path=`validation`)
- [ ] 既存 `tests/Feature/Llm/CannedPromptResponsesTest.php` を `WorkDecompositionResponseData` 経由へ更新

### リスク

- `WorkDecompositionData::fromLlmText()` を消すため、参照が残っていると fatal になる
  → 参照は canned テスト 1 箇所とパイプライン 1 箇所のみ (`rg` で確認済み)。同じ変更で両方直す。

---

## M3: 保存先カラム `analysis_jobs.validation_json`

### 変更箇所

- 新規: `database/migrations/{実装日}_000100_add_validation_json_to_analysis_jobs_table.php`
  (**ファイル名の日付は実装日に合わせる**。現在の最終 migration は
  `2026_08_16_220000_add_material_type_to_takes_table.php` なので、それより後で
  未来日にならない値を採番する = 設計書に日付を焼き付けない)
- 変更: `app/Models/AnalysisJob.php` (`@property` / `casts()`)
- 変更: `database/factories/AnalysisJobFactory.php` (`definition()` に `validation_json => null`、
  所見付きの state `withValidation()` を追加)

### 波及変更

- TypeScript型定義: なし (props は DTO 経由)
- API Resource/DTO: `SopValidationData::toArray()` が保存 shape
- テストファイル: `tests/Support/Retention/RetentionTableRegistry.php` は**変更不要**
  (区分は表単位で、`analysis_jobs` は登録済み。列は見ない)
- `MassAssignmentSafetyTest`: `AnalysisJob` は `$fillable` を持たない (全列が明示代入のみ) ため変更なし

### 変更後コード

```php
// migration
/**
 * analysis_jobs.validation_json: 手順書に対する LLM の所見 (SopValidationData の保存 shape)。
 *
 * result_json (作業分解表の write-only 監査スナップショット) とは**別カラム**にする:
 * こちらは詳細画面が読む表示契約であり、write-only の監査値と寿命・契約が違う。
 * NULL = 所見なし (本機能より前のジョブ / decompose 段に到達しなかったジョブ)。
 */
public function up(): void
{
    Schema::table('analysis_jobs', function (Blueprint $table): void {
        $table->json('validation_json')->nullable()->after('result_json');
    });
}

public function down(): void
{
    Schema::table('analysis_jobs', function (Blueprint $table): void {
        $table->dropColumn('validation_json');
    });
}
```

```php
// AnalysisJob.php
 * @property array<array-key, mixed>|null $validation_json

protected function casts(): array
{
    return [
        'status' => JobStatus::class,
        'step' => AnalysisStep::class,
        'progress' => 'integer',
        'result_json' => 'array',
        'validation_json' => 'array',
    ];
}
```

```php
// AnalysisJobFactory.php
'validation_json' => null,

/** 妥当性所見つき (表示テスト用)。既定は valid / 作業 1 件 */
public function withValidation(
    ScenarioVerdict $verdict = ScenarioVerdict::Valid,
    bool $splitRecommended = false,
): static {
    return $this->state(fn (): array => [
        'validation_json' => (new SopValidationData(
            verdict: $verdict,
            reason: 'テスト用の所見です。',
            works: ['バルブ閉止作業'],
            splitRecommended: $splitRecommended,
        ))->toArray(),
    ]);
}
```

### PHPStan適合チェック

- [x] `@property` の型が `array<array-key, mixed>|null` (既存 `result_json` と同形)
- [x] Factory の state は `array<string, mixed>` を返す closure

### テスト計画

- [ ] 既存 `tests/Architecture/RetentionTableClassificationTest.php` が緑のまま (表は追加していない)
- [ ] Factory の `withValidation()` が `SopValidationData::fromStorage()` を通ること (M6 のテストで担保)

### リスク

- 既存行は NULL のまま = 表示は「所見なし」。backfill はしない (過去ジョブの応答は保存していない)。

---

## M4: パイプラインの保存とリトライログの構造化

### 変更箇所

- ファイル: `app/Services/Manual/AnalysisPipeline.php` (L216-239 `runDecomposeStep` / L367-372 リトライログ /
  L481 `writeProgress` の array shape)

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: `WorkDecompositionResponseData` を利用
- テストファイル: `tests/Feature/Projects/AnalysisPipelineTest.php` (canned 応答と保存内容の検証を追加)

### 現行コード

```php
/** decompose 段: 作業分解表 + result_json 保存 (write-only 監査スナップショット) */
private function runDecomposeStep(
    AnalysisJob $job,
    ExtractedSopData $extracted,
    CarbonImmutable $deadline,
    LlmCallContextData $context,
): WorkDecompositionData {
    $decomposition = $this->withBoundedRetry(
        $job,
        $deadline,
        AnalysisStep::Decompose,
        fn (): WorkDecompositionData => WorkDecompositionData::fromLlmText(
            WorkDecompositionPrompt::make($extracted->toJsonString(), $context)->executeSync(),
        ),
    );

    // 終端後の自前書き込みを塞ぐ: 進捗と result_json は running のときだけ書く
    $this->writeProgress($job, [
        'result_json' => $decomposition->toArray(),
        'step' => AnalysisStep::Generate->value,
        'progress' => 65,
    ]);

    return $decomposition;
}
```

```php
Log::warning('AI 解析の LLM 呼び出しを再試行します', [
    'step' => $step->value,
    'attempt' => $tryCount + 1,
    'max_attempts' => $maxRetries + 1,
    'exception' => $exception::class,
]);
```

```php
 * @param  array{step: string, progress: int, result_json?: array<string, mixed>}  $attributes
```

### 変更後コード

```php
/**
 * decompose 段: 作業分解表 (result_json) + 手順書への所見 (validation_json) を 1 回の
 * LLM 呼び出しで受け取り、**同じ条件付き UPDATE で**保存する。
 *
 * ★ 次段 (generate) へ渡すのは `decomposition` **だけ**である。
 *   所見を次段の入力 JSON に混ぜない (入力 token を無駄にせず、生成器の指示も汚さない)。
 */
private function runDecomposeStep(
    AnalysisJob $job,
    ExtractedSopData $extracted,
    CarbonImmutable $deadline,
    LlmCallContextData $context,
): WorkDecompositionData {
    $response = $this->withBoundedRetry(
        $job,
        $deadline,
        AnalysisStep::Decompose,
        fn (): WorkDecompositionResponseData => WorkDecompositionResponseData::fromLlmText(
            WorkDecompositionPrompt::make($extracted->toJsonString(), $context)->executeSync(),
        ),
    );

    // 終端後の自前書き込みを塞ぐ: 進捗と 2 つの JSON は running のときだけ書く
    $this->writeProgress($job, [
        'result_json' => $response->decomposition->toArray(),
        'validation_json' => $response->validation->toArray(),
        'step' => AnalysisStep::Generate->value,
        'progress' => 65,
    ]);

    return $response->decomposition;
}
```

```php
// withBoundedRetry のリトライログ (観測条件。固定キーの構造化 context)
Log::warning('AI 解析の LLM 呼び出しを再試行します', [
    'step' => $step->value,                 // = stage (extract/decompose/generate)
    'attempt' => $tryCount + 1,
    'max_attempts' => $maxRetries + 1,
    'exception' => $exception::class,
    // スキーマ違反のときだけ分類と違反位置が入る (validation 起因かを集計で分けるため)。
    // **応答本文は載せない** (LLM 由来の可変文字列)
    'failure_category' => $exception instanceof LlmOutputInvalidException
        ? $exception->reason->value
        : null,
    'failure_path' => $exception instanceof LlmOutputInvalidException
        ? $exception->path
        : null,
]);
```

```php
 * @param  array{step: string, progress: int, result_json?: array<string, mixed>,
 *   validation_json?: array{verdict: string, reason: string, works: list<string>,
 *   split_recommended: bool}}  $attributes
```

**最終失敗にも同じ観測キーを残す** (概念設計 Round 4 の指摘)。リトライログだけだと
「再試行はしたが結局どこで落ちたか」が集計できないため、`run()` の catch にも同じ
固定キーを持つ 1 行を足す (`report()` は例外の送信で、集計用の構造化ログとは別責務):

```php
} catch (Throwable $exception) {
    report($exception);
    // 観測: スキーマ違反で最終失敗したときも再試行ログと同じキーを残す (集計で突き合わせるため)。
    // 応答本文は載せない。分岐には使わない (failJob の文言は userMessageFor が決める)
    if ($exception instanceof LlmOutputInvalidException) {
        Log::warning('AI 解析が LLM 応答のスキーマ違反で失敗しました', [
            'analysis_job_id' => $job->id,
            'failure_category' => $exception->reason->value,
            'failure_path' => $exception->path,
        ]);
    }
    $this->jobs->failJob($job, $this->userMessageFor($exception));
}
```

> `stage` は失敗時点の `analysis_jobs.step` 列 (= 進捗表示と同じ値) から分かるため、
> ここでは job id を出して重複を避ける (段の情報を 2 系統で持たない)。

### PHPStan適合チェック

- [x] `writeProgress` の array shape に `validation_json` を追記 (保護列を渡せない性質は維持)
- [x] `forceFill()->getAttributes()` 経路のため cast (`array`) が適用される (既存 `result_json` と同じ)
- [x] `withBoundedRetry` の `@template T` は `WorkDecompositionResponseData` で解決される
- [x] `$exception->path` は `?string` (三項の両枝が `string|null`)

### テスト計画

- [ ] `tests/Feature/Projects/AnalysisPipelineTest.php` に追加:
      - 成功時に `analysis_jobs.validation_json` が `{verdict, reason, works, split_recommended}` で保存される
      - `result_json` は従来どおり `{steps: [...]}` のまま (所見が混ざらない)
      - **3 段目へ渡る入力 JSON に `validation` が含まれない** (`WorkDecompositionData::toJsonString()` の内容を検証)
- [ ] `validation` 欠落応答 → 有界リトライののち failed、`validation_json` は NULL のまま
- [ ] リトライログに `failure_path` が `validation.` で始まる値で載る (`Log::spy()`)
- [ ] **`steps` 側の違反と識別できる**: `steps.0.action` を壊した応答では `failure_path` が
      `steps.` で始まり、`validation` を壊した応答では `validation.` で始まる (観測条件の固定)
- [ ] **最終失敗にも同じ観測キーが残る**: 3 試行すべて validation 違反のとき、
      `failure_category` / `failure_path` を持つ失敗ログが 1 行出る (再試行ログとは別に)
- [ ] 既存の重複配送 / 所有権喪失 / terminal guard のテストが緑のまま

### リスク

- `validation` 違反だけで解析が失敗しうる (概念設計で受容済み。上限 3 試行、チケット予約は release)。

---

## M5: 規約検査 (決定的算出)

### 変更箇所

- 新規: `app/Enums/Manual/ScenarioRuleCode.php`
- 新規: `app/Support/Manual/ScenarioRuleCheck.php` (純関数。DB に触らない)
- 新規: `app/DataTransferObjects/Manual/ScenarioRuleFindingData.php`
- 新規: `app/DataTransferObjects/Manual/ScenarioReportData.php`

### 波及変更

- TypeScript型定義: `ScenarioRuleCode` union + ラベル表 (M7)
- API Resource/DTO: `ScenarioReportData::toArray()` が props shape
- テストファイル: `tests/Architecture/ManualEnumTsSyncInvariantTest.php` に 2 enum を登録

### 変更後コード

```php
// app/Enums/Manual/ScenarioRuleCode.php (新規)
/**
 * シナリオ規約検査の指摘コード (doc/03 §3.3 のプロンプト規約のうち機械検査できるもの)。
 *
 * **意図的に入れていない検査**: 「急所が 0 件の手順」は ScenarioBookendBuilder が付ける
 * 導入/総括カットが構造上必ず該当し (DB 上に識別子が無い)、全マニュアルで恒常的な
 * 偽陽性 2 件になるため入れない。
 * **閾値 (文字数上限等) を持つ検査も入れない** (根拠となる実データが無いため)。
 *
 * TS 側 types/manual.ts の ScenarioRuleCode union と値集合を一致させる。
 */
enum ScenarioRuleCode: string
{
    case NarrationMissing = 'narration_missing';
    case NarrationNotPolite = 'narration_not_polite';
    case NarrationDirective = 'narration_directive';
    case SubtitlePrimarySentence = 'subtitle_primary_sentence';
    case SubtitleSecondaryMissing = 'subtitle_secondary_missing';
}
```

```php
// app/Support/Manual/ScenarioRuleCheck.php (新規)
/**
 * シナリオ規約検査 (決定的・純関数)。**DB に触らない** (呼び出し側が取得済み cuts を渡す)。
 *
 * 判定は表示のための材料であり、**制御フローには使わない** (保存・撮影・レンダを止めない)。
 * 規則は doc/03 §3.3 のプロンプト規約に対応し、偽陽性を出さない範囲でのみ機械化する。
 */
final class ScenarioRuleCheck
{
    /** 指摘 1 件あたりに載せる位置の上限 (画面が長くならないための表示上の都合) */
    public const int MAX_POSITIONS_PER_CODE = 5;

    /** ナレーションの許容終端 (丁寧体)。「〜してはいけません」「〜が必要です」を偽陽性にしない */
    private const array POLITE_ENDINGS = ['ます', 'ません', 'ました', 'ましょう', 'です', 'でした'];

    /**
     * 終端判定の前に落とす末尾の空白・句点 (Unicode 対応の正規表現)。
     *
     * ★ `rtrim($s, "。.!！")` は使えない。`rtrim` の charlist は**バイト単位**で解釈されるため、
     *   マルチバイト文字を渡すとその構成バイトが個別に剥がされ、UTF-8 文字列を壊しうる。
     */
    private const string TRAILING_MARKS_PATTERN = '/[\s。．.!！]+$/u';

    /**
     * @param  Collection<int, Cut>  $orderedCuts  sort_order 昇順で取得済みの全 cut
     */
    public static function run(Collection $orderedCuts): ScenarioReportData
    {
        // parent_cut_id で 1 パス groupBy (トップレベルは key 0。cut id は 1 始まりで衝突しない)
        // = ScenarioDocumentData::fromManual と同じ手口。cut 件数に比例するのはメモリだけで
        //   クエリは 0 本 (取得は呼び出し側の 1 本)
        ...
        // step は 1 始まり、point はその step 内で 1 始まり (編集画面の「手順 N」「急所 N-M」表記と一致)
    }

    /** ナレーションが丁寧体で終わっているか (末尾の空白・句点を落として判定) */
    private static function endsPolitely(string $narration): bool
    {
        $trimmed = preg_replace(self::TRAILING_MARKS_PATTERN, '', $narration) ?? $narration;
        foreach (self::POLITE_ENDINGS as $ending) {
            if (str_ends_with($trimmed, $ending)) {
                return true;
            }
        }

        return false;
    }
}
```

#### 数え方と異常データの扱い (明文化)

- **`stepCount`** = `parent_cut_id === null` の cut 数 (= 画面の「手順」。
  `ScenarioBookendBuilder` が付ける導入/総括カットも識別子が無いのでここに含まれる)。
- **`pointCount`** = 親を**この cut 集合の中で解決できた**子 cut の数。
- **数えない cut は 2 種類**あり、どちらも `pointCount` にも規約検査にも入れない
  (位置を「手順 N-M」として表記できない = 表示できない指摘を出さないため)。
  DB 制約上どちらも発生しない (`parent_cut_id` は同一 manual 内の cut への FK で cascade 削除、
  保存経路は `ScenarioService` がトップレベル step → point の二層しか作らない) が、
  **防御的に明示して Unit テストで 1 ケースずつ固定する**:
  1. **孤児 cut**: `parent_cut_id` が非 null だが同じ cut 集合に親が見つからない
  2. **三層目の cut**: 親は見つかるが、その親自身も子である (= 二層構造から外れる)
- 走査順は取得側が **`orderBy('sort_order')->orderBy('id')`** で決める (`CutSequencer` と同じ並び)。
  同値 `sort_order` でも位置表記が揺れないようにするため、`ScenarioDocumentData::fromManual` の
  `orderBy('sort_order')` のみとは**あえて揃えない** (あちらは編集用 document、こちらは位置の表示)。

検査規則 (1 cut ごと。**同一 cut が複数 code に載りうる**):

| code | 条件 |
|---|---|
| `narration_missing` | `trim($cut->narration) === ''` |
| `narration_not_polite` | ナレーションが非空 かつ `! endsPolitely()` |
| `narration_directive` | `str_contains($cut->narration, 'ください')` |
| `subtitle_primary_sentence` | 字幕①が非 null かつ (`。` を含む または `ます` を含む または `です` を含む) |
| `subtitle_secondary_missing` | `trim($cut->subtitle_secondary) === ''` |

```php
// app/DataTransferObjects/Manual/ScenarioRuleFindingData.php (新規)
/**
 * 規約検査 1 件の指摘 (code ごとに 1 つ)。件数は全件、位置は先頭 N 件のみ載せる。
 */
final readonly class ScenarioRuleFindingData
{
    /** @param list<array{step: int, point: int|null}> $positions 1 始まり。point=null は手順カット */
    public function __construct(
        public ScenarioRuleCode $code,
        public int $count,
        public array $positions,
    ) {}

    /** @return array{code: string, count: int, positions: list<array{step: int, point: int|null}>} */
    public function toArray(): array
    {
        return ['code' => $this->code->value, 'count' => $this->count, 'positions' => $this->positions];
    }
}
```

```php
// app/DataTransferObjects/Manual/ScenarioReportData.php (新規)
/**
 * 詳細画面の「生成結果の確認」パネルの props。
 *
 * 2 つの出所を **1 つの型に束ねるが混ぜない**:
 * - verdict: LLM が手順書に下した所見 (解析時点のスナップショット。null = 所見なし)
 * - stepCount / pointCount / findings: 現在の cuts から算出した決定的な値 (常に最新)
 */
final readonly class ScenarioReportData
{
    /** @param list<ScenarioRuleFindingData> $findings */
    public function __construct(
        public ?ScenarioVerdictViewData $verdict,
        public int $stepCount,
        public int $pointCount,
        public array $findings,
    ) {}

    /**
     * @return array{verdict: array{verdict: string, reason: string, works: list<string>,
     *   work_count: int, split_recommended: bool, is_current_document: bool}|null,
     *   counts: array{steps: int, points: int, total: int},
     *   findings: list<array{code: string, count: int,
     *     positions: list<array{step: int, point: int|null}>}>}
     */
    public function toArray(): array
    {
        return [
            'verdict' => $this->verdict?->toArray(),
            'counts' => [
                'steps' => $this->stepCount,
                'points' => $this->pointCount,
                'total' => $this->stepCount + $this->pointCount,
            ],
            'findings' => array_map(
                static fn (ScenarioRuleFindingData $finding): array => $finding->toArray(),
                $this->findings,
            ),
        ];
    }

    /** 所見も指摘も無く cut も無い = 出す価値が何も無い (Controller が null props にする判定) */
    public function isEmpty(): bool
    {
        return $this->stepCount === 0 && $this->pointCount === 0 && $this->verdict === null;
    }
}
```

### PHPStan適合チェック

- [x] `Collection<int, Cut>` の generics を PHPDoc で明示
- [x] `list<...>` と array shape をすべての `toArray()` に宣言
- [x] enum 経由の値のみを返す (文字列直書きなし)
- [x] `array` 定数は `private const array` (PHP 8.3+ の型付き定数。既存 `ScenarioLimits` と同じ書式)

### テスト計画

- [ ] 新規 `tests/Unit/Manual/ScenarioRuleCheckTest.php`: 5 code それぞれの陽性/陰性、
      境界 (「〜してはいけません」「〜が必要です」は `narration_not_polite` にならない /
      「〜してください」は directive と not_polite の**両方**に載る) 、位置表記 (手順 2 / 急所 2-3)、
      位置は `MAX_POSITIONS_PER_CODE` 件で打ち切るが `count` は全件
- [ ] 指摘 0 件のシナリオで `findings` が空配列になる (「指摘なし」を出せる)
- [ ] 数え方: 導入/総括カットが `stepCount` に含まれる (識別子が無い以上そうなる、を明示的に固定) /
      親を解決できない子 cut は `pointCount` にも指摘にも入らない
- [ ] `tests/Architecture/ManualEnumTsSyncInvariantTest.php` に `ScenarioVerdict` / `ScenarioRuleCode` を登録

### リスク

- 規約検査の偽陽性は「読み飛ばし」を生む → 許容終端集合を広めに取り、閾値を持たせない。
  導入/総括カットは規約に適合する文面 (`lang/ja/manual.php` を実読して確認: ナレーションは
  「〜示します。」「〜振り返ります。」、字幕②は非空) なので恒常的な指摘は出ない。
  唯一 `subtitle_primary` が manual の title そのものになる導入カットは、
  title が「〜します」を含む場合に `subtitle_primary_sentence` に載る (利用者の入力次第の例外であり、
  指摘としては正しい = 字幕①に文が入っている)。

---

## M6: props 組み立てと Controller 配線

### 変更箇所

- 新規: `app/Services/Manual/ScenarioReportBuilder.php`
- 新規: `app/DataTransferObjects/Manual/ScenarioVerdictViewData.php`
- 変更: `app/Http/Controllers/Projects/VideoManualController.php` (show の `analysis` props)

### 波及変更

- TypeScript型定義: `AnalysisProps` に `report` を追加 (M7)
- API Resource/DTO: **ポーリング応答 (`AnalysisJobData` / `AnalysisJobResource`) は変更しない**
  (succeeded で既存の `router.reload()` が走り props が更新されるため)
- テストファイル: `tests/js/pages/ManualsShow.test.ts` の `baseProps.analysis` に `report: null` を追加

### 変更後コード

```php
// app/DataTransferObjects/Manual/ScenarioVerdictViewData.php (新規)
/**
 * 画面に出す「手順書への所見」。保存値 (SopValidationData) に**鮮度**を足したもの。
 *
 * is_current_document = 所見の対象がいまアップロードされている手順書と同一か。
 * false のとき画面は「解析時の手順書に対する所見です」と添えて再解析へ誘導する
 * (所見自体は隠さない)。
 */
final readonly class ScenarioVerdictViewData
{
    public function __construct(
        public SopValidationData $validation,
        public bool $isCurrentDocument,
    ) {}

    /**
     * @return array{verdict: string, reason: string, works: list<string>, work_count: int,
     *   split_recommended: bool, is_current_document: bool}
     */
    public function toArray(): array
    {
        return [
            ...$this->validation->toArray(),
            'work_count' => $this->validation->workCount(),
            'is_current_document' => $this->isCurrentDocument,
        ];
    }
}
```

```php
// app/Services/Manual/ScenarioReportBuilder.php (新規)
/**
 * 詳細画面の「生成結果の確認」props の組み立て。
 *
 * クエリは **cut 件数に依存しない 3 本**:
 *  1. cuts 全件 (sort_order 昇順) — 規約検査とカット構成
 *  2. 最新の succeeded な解析ジョブ (relation 起点) — 所見
 *  3. 最新の手順書 id (relation 起点、id のみ) — 所見の鮮度
 *
 * ★ 取得はすべて **$manual の relation 経由**である (クラス起点の主キー取得を作らない =
 *   cross-org 不可の不変条件を構造的に満たし、DirectFetchInventory への登録も要らない)。
 * ★ 所見の出所は「最新の succeeded ジョブ」であって「最新のジョブ」ではない。
 *   いま画面にある cuts を作ったのは最後に成功した解析だからである
 *   (再解析が失敗しても、前回の所見と現在のシナリオの対応は保たれる)。
 * ★ 鮮度を **id の一致**で見てよい前提: source_document は **追記型 (append-only)** である。
 *   差し替えは新しい行の INSERT であり (SourceDocumentService::appendDocument。
 *   `file_path` を上書き更新する経路は無い)、解析対象は常に「最新 id の 1 件」
 *   (AnalysisJobService::trigger が行ロック下で `latest('id')` を選ぶ)。
 *   将来ファイルを in-place 更新する経路を作るなら、id ではなく内容の版で比較する必要がある
 *   (docs にこの前提を明記する)。
 */
final class ScenarioReportBuilder
{
    public function build(VideoManual $manual): ?ScenarioReportData
    {
        // 位置表記が同値 sort_order で揺れないよう id を第 2 キーにする (CutSequencer と同じ並び)
        /** @var Collection<int, Cut> $cuts */
        $cuts = $manual->cuts()->orderBy('sort_order')->orderBy('id')->get();
        $report = ScenarioRuleCheck::run($cuts);

        $verdict = $this->resolveVerdict($manual);
        $merged = new ScenarioReportData(
            verdict: $verdict,
            stepCount: $report->stepCount,
            pointCount: $report->pointCount,
            findings: $report->findings,
        );

        return $merged->isEmpty() ? null : $merged; // 出す材料が何も無ければ props を出さない
    }

    private function resolveVerdict(VideoManual $manual): ?ScenarioVerdictViewData
    {
        $job = $manual->analysisJobs()
            ->where('status', JobStatus::Succeeded->value)
            ->latest('id')
            ->first();
        if ($job === null) {
            return null;
        }

        $validation = SopValidationData::fromStorage($job->validation_json, $job->id);
        if ($validation === null) {
            return null; // 未生成 (旧ジョブ) / 復元失敗 (fromStorage が警告を残している)
        }

        $latestDocumentId = $manual->sourceDocuments()->max('id');

        return new ScenarioVerdictViewData(
            validation: $validation,
            isCurrentDocument: $job->source_document_id !== null
                && $latestDocumentId !== null
                && (int) $latestDocumentId === $job->source_document_id,
        );
    }
}
```

```php
// VideoManualController::show (変更点のみ)
public function show(
    Request $request,
    Project $project,
    VideoManual $manual,
    SeoManager $seo,
    VideoManualService $manuals,
    ScenarioReportBuilder $reports,   // ← 追加 (method injection。既存 2 サービスと同じ作法)
): Response {
    // ...
    'analysis' => [
        'job' => $analysisJob === null ? null : AnalysisJobData::fromJob($analysisJob, $manual)->toArray(),
        'hasDocument' => $manual->sourceDocuments()->exists(),
        // 生成結果の確認 (LLM の所見 + 現在の cuts への決定的検査)。null = 出す材料が無い。
        // 描画時点のスナップショットであり常に最新ではない (render.coverage と同じ性質)。
        'report' => $reports->build($manual)?->toArray(),
    ],
```

### PHPStan適合チェック

- [x] `?ScenarioReportData` / `?ScenarioVerdictViewData` の nullable を明示
- [x] `$manual->sourceDocuments()->max('id')` は `mixed` を返すため `(int)` 変換前に null 検査
- [x] `Collection<int, Cut>` の generics を PHPDoc で固定
- [x] Controller は生 array を組まず DTO の `toArray()` だけを渡す

### テスト計画

- [ ] 新規 `tests/Feature/Projects/ManualScenarioReportPropsTest.php`:
      - succeeded ジョブ + `validation_json` → props に verdict が出る
      - 最新ジョブが failed でも**前回 succeeded**の所見が出る
      - `validation_json` が NULL (旧ジョブ) → `verdict: null` だが counts/findings は出る
      - 壊れた `validation_json` → 200 かつ `verdict: null` かつ `Log::warning` が 1 回
      - SOP 差し替え後 (未再解析) → `is_current_document: false`
      - SOP 削除後 (`source_document_id` が NULL) → `is_current_document: false`
      - 複製直後の manual (解析ジョブなし・cuts あり) → verdict null / counts あり
      - cuts 0 件 かつ 所見なし → `report: null`
      - **撮影者 (canManage=false) でも report は props に載る** (表示は情報提供であり操作ではない)
- [ ] クエリ本数テスト: cut 3 件と 60 件で `ScenarioReportBuilder::build()` の発行クエリ数が同数
      (`DB::listen` で計数。N+1 を作っていないことの固定)
- [ ] 認可: 他組織の manual へは既存どおり 404 (props 追加で経路は変わらないことの回帰)

### リスク

- Show のクエリが 3 本増える (すべて cut 件数非依存)。詳細画面は既に 10 本規模なので影響は小さい。

---

## M7: 画面 (型・ラベル・パネル・配置)

### 変更箇所

- 変更: `resources/js/types/manual.ts` (**union 2 つと props 型だけ**。ラベル・tone は置かない)
- 新規: `resources/js/components/features/manual/scenario-report.ts`
  (表示ラベル / tone / 位置整形。**features 層の presentation helper**)
- 新規: `resources/js/components/features/manual/ScenarioReportPanel.svelte`
- 変更: `resources/js/pages/Manuals/Show.svelte` (AnalysisPanel の直下に配置)

> **なぜラベルと tone を `types/manual.ts` に置かないか** (Codex R1 指摘):
> `BadgeTone` は atom の型であり、ドメイン型定義ファイルが UI atom に依存すると責務が混ざる。
> 既存の `STATUS_TONES` が types 側にあるのは先行実装の事情で、**今回それを増やさない**。
> 新しい表示語彙は features 層 (`components/features/manual/`) の helper に置く
> (同階層の `insufficient-tickets.ts` が先例)。

### 波及変更

- TypeScript型定義: 上記 (props 型は PHP の array shape と 1:1)
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/ManualsShow.test.ts` (props 追加) + 新規コンポーネントテスト

### 変更後コード

```ts
// resources/js/types/manual.ts (追加)
/** PHP: App\Enums\Manual\ScenarioVerdict と対 (値集合同期テストあり) */
export type ScenarioVerdict = "valid" | "needs_review" | "invalid";

/** PHP: App\Enums\Manual\ScenarioRuleCode と対 (値集合同期テストあり) */
export type ScenarioRuleCode =
    | "narration_missing"
    | "narration_not_polite"
    | "narration_directive"
    | "subtitle_primary_sentence"
    | "subtitle_secondary_missing";

/** PHP: ScenarioReportData::toArray() と対 */
export interface ScenarioReportProps {
    verdict: {
        verdict: ScenarioVerdict;
        reason: string;
        works: string[];
        work_count: number;
        split_recommended: boolean;
        is_current_document: boolean;
    } | null;
    counts: { steps: number; points: number; total: number };
    findings: {
        code: ScenarioRuleCode;
        count: number;
        positions: { step: number; point: number | null }[];
    }[];
}

export interface AnalysisProps {
    job: AnalysisJobProps | null;
    hasDocument: boolean;
    /** null = 出す材料が無い (cuts も所見も無い) */
    report: ScenarioReportProps | null;
}
```

```ts
// resources/js/components/features/manual/scenario-report.ts (新規)
// 表示語彙 (ラベル / tone) と整形。ドメイン型 (types/manual.ts) から UI atom 型への依存を分離する。
import type { BadgeTone } from "@/components/atoms/Badge.types";
import type { ScenarioRuleCode, ScenarioVerdict } from "@/types/manual";

/** satisfies で verdict / code 追加時のキー漏れをコンパイル時に検出する */
export const SCENARIO_VERDICT_LABELS = {
    valid: "マニュアルとして有効",
    needs_review: "確認が必要な箇所があります",
    invalid: "このままでは元資料として不十分",
} as const satisfies Record<ScenarioVerdict, string>;

export const SCENARIO_VERDICT_TONES = {
    valid: "success",
    needs_review: "warning",
    invalid: "danger",
} as const satisfies Record<ScenarioVerdict, BadgeTone>;

/** 指摘ラベル (規則そのものを言い切る。原因を断定しない文言にする) */
export const SCENARIO_RULE_LABELS = {
    narration_missing: "ナレーションが空のカット",
    narration_not_polite: "ナレーションが「です・ます」調で終わっていないカット",
    narration_directive: "ナレーションに「ください」が入っているカット",
    subtitle_primary_sentence: "字幕①が名称・数値でなく文になっている可能性のあるカット",
    subtitle_secondary_missing: "字幕②が空のカット",
} as const satisfies Record<ScenarioRuleCode, string>;

/**
 * 位置の整形。「手順 2」/「急所 2-3」(編集画面の読み上げ表記と同じ)。
 * **count は positions.length と別に受け取る** — positions は先頭 5 件で打ち切られており、
 * 「ほか」を出すかは総件数でしか判定できないため。
 */
export function formatPositions(
    positions: { step: number; point: number | null }[],
    count: number,
): string {
    const labels = positions.map((p) => (p.point === null ? `手順 ${p.step}` : `急所 ${p.step}-${p.point}`));

    return count > positions.length ? `${labels.join(" / ")} ほか` : labels.join(" / ");
}
```

```svelte
<!-- ScenarioReportPanel.svelte (骨子) -->
<script lang="ts">
    /**
     * 生成結果の確認パネル (doc/03 §3.4 のバリデーション結果)。
     * - 所見 (LLM・解析時点のスナップショット) と 検査 (現在の cuts から決定的に算出) を
     *   **見出しで分けて**出す (鮮度が違うため)
     * - 判定は表示のみ。ボタンを disabled にしない / 保存・撮影を止めない
     * - 「有効でない」ときの行き先を必ず添える (編集する / 手順書を差し替えて再解析する)
     */
    interface Props {
        projectId: number;
        manualId: number;
        report: ScenarioReportProps;
        canManage: boolean;
    }
</script>

<Card padding="lg" testId="scenario-report">
    <h2 class="text-h3">生成結果の確認</h2>

    {#if report.verdict}
        <Badge tone={SCENARIO_VERDICT_TONES[report.verdict.verdict]} testId="scenario-verdict">
            {SCENARIO_VERDICT_LABELS[report.verdict.verdict]}
        </Badge>
        <p class="text-body">{report.verdict.reason}</p>
        {#if !report.verdict.is_current_document}
            <p class="text-caption text-text-secondary" data-testid="scenario-verdict-stale">
                この所見は解析時の手順書に対するものです。手順書を差し替えた場合は AI 解析をやり直してください。
            </p>
        {/if}
        <p>作業: {report.verdict.work_count} 件</p>
        <ul>{#each report.verdict.works as work}<li>{work}</li>{/each}</ul>
        {#if report.verdict.split_recommended}
            <Alert type="info" testId="scenario-split-recommended">
                この手順書には複数の作業が含まれています。作業ごとにマニュアルを分けると
                撮影とナビゲーションが短くなります (「複製」から作業ごとに分けられます)。
            </Alert>
        {/if}
    {/if}

    <p data-testid="scenario-counts">
        カット構成: 手順 {report.counts.steps} / 急所 {report.counts.points} (合計 {report.counts.total})
    </p>

    {#if report.findings.length > 0}
        <ul data-testid="scenario-findings">
            {#each report.findings as finding}
                <li>
                    {SCENARIO_RULE_LABELS[finding.code]}: {finding.count} 件
                    <span class="text-caption text-text-secondary">
                        {formatPositions(finding.positions, finding.count)}
                    </span>
                </li>
            {/each}
        </ul>
    {:else}
        <p data-testid="scenario-findings-empty">シナリオの書式に関する指摘はありません。</p>
    {/if}

    {#if canManage}
        <Button variant="ghost" href={`/projects/${projectId}/manuals/${manualId}/edit`} inertia
                testId="scenario-report-edit-link">
            シナリオを編集して確認する
        </Button>
    {/if}
</Card>
```

- `formatPositions(positions, count)`: `{step: 2, point: null}` → 「手順 2」、
  `{step: 2, point: 3}` → 「急所 2-3」。`count > positions.length` のとき末尾に「ほか」。
- 色・角丸・タイポは DS token のみ (`text-h3` / `text-body` / `text-caption` / `text-text-secondary`)。
  hex 直書きなし。アイコンを足す場合は `@lucide/svelte` のみ。
- component 階層: `features/manual` が `atoms` (Badge/Button/Card/Alert) を参照する = 単方向のまま。

```svelte
<!-- Show.svelte (AnalysisPanel の直後に追加) -->
{#if analysis.report}
    <ScenarioReportPanel
        projectId={project.id}
        manualId={manual.id}
        report={analysis.report}
        {canManage}
    />
{/if}
```

### PHPStan適合チェック

- [x] 対象外 (TS)。`pnpm typecheck` と `satisfies` によるキー漏れ検出で担保

### テスト計画

- [ ] 新規 `tests/js/components/features/manual/ScenarioReportPanel.test.ts`:
      3 verdict のラベル/tone / `is_current_document=false` の注記 / `split_recommended` の案内 /
      指摘 0 件の文言 / 位置表記 (手順 2・急所 2-3・「ほか」) / canManage=false で編集導線が出ない
- [ ] `tests/js/pages/ManualsShow.test.ts`: `report: null` で従来どおり描画 (パネルが出ない) /
      report ありでパネルが出る
- [ ] `tests/js/architecture/atomic-import-graph.test.ts` が緑 (features → atoms の単方向)
- [ ] ds-purity テストが緑 (token 以外の色を使っていない)

### リスク

- 情報量が増えて詳細画面が縦に伸びる → 指摘は code ごとに 1 行、位置は上限 5 件までに抑える。

---

## M8: fake / 既存テストの追随

### 変更箇所

- 変更: `app/Services/AI/Testing/CannedPromptResponses.php` (`workDecompositionCanned()`)
- 変更: `tests/Feature/Llm/CannedPromptResponsesTest.php`
- 変更: `tests/Feature/Projects/AnalysisPipelineTest.php` (canned/fake 応答の JSON)
- 変更: `tests/js/pages/ManualsShow.test.ts`

### 変更後コード

```php
/** work-decomposition: WorkDecompositionResponseData::fromLlmText を通過 (1 step / points 1 / 所見つき) */
private static function workDecompositionCanned(): string
{
    return json_encode([
        'steps' => [[
            'no' => 1,
            'action' => 'バルブを閉じる',
            'points' => ['ハンドルが止まるまで回す'],
        ]],
        'validation' => [
            'verdict' => 'valid',
            'reason' => '手順と急所が読み取れており、動画マニュアルの元資料として成立しています。',
            'works' => ['バルブ閉止作業'],
            'split_recommended' => false,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}
```

### 波及変更

- bug-hunt の `dev:pipeline-smoke` / shard 実行は同じ canned を使うため、この 1 箇所の更新で追随する
  (fake の配線は `CannedPromptFakeRegistrar` のまま変更なし)

### テスト計画

- [ ] `tests/Feature/Llm/CannedPromptResponsesTest.php` の「canned が DTO を通過する」が
      `WorkDecompositionResponseData` で緑
- [ ] canned の signature 判定 (system_prompt 由来) が緑のまま

### リスク

- canned を更新し忘れると解析系 Feature テストが広範に落ちる (= 検出は容易)。

---

## M9: ドキュメント更新

### 変更箇所

- `docs/architecture.md`: 「AI 解析パイプライン」節に **§シナリオ生成の妥当性所見と規約検査** を追加
  (所見の出所 = 最新 succeeded ジョブ / 鮮度 = source_document_id 一致 /
  **制御フローに使わない** / 保証しないもの)
- `doc/03_AI解析とシナリオ生成.md` §3.4: v2 の「バリデーション結果」を本実装がどう分担したか
  (LLM = 有効性・仮タイトル・分割要否 / PHP = 件数・文体検査 / 「ノード数」は導入・総括カットを
  含むカット構成として出す) を追記

### 保証しないもの (誇張しない。docs に明記する)

- 規約検査は**書式の検査**であって内容の正しさ (OCR 誤読・手順の欠落) は検出しない。
- 所見は**解析時点の手順書に対する LLM の判断**であり、その後の手動編集は反映されない
  (だから件数・文体は別建てで常に再計算している)。
- 検査は導入/総括カットを普通の手順カットとして数える (識別子を持たないため)。
- 所見の鮮度判定は **source_document が追記型である前提**に立つ (id の一致で見る)。
  in-place 更新の経路を作るときは比較方法を見直す。
- 「所見は手順書への判断であり、手動編集後のシナリオの品質は保証しない」を
  **docs と UI 文言の両方で同じ言い方に揃える** (パネルの見出しは「生成結果の確認」、
  所見側の注記は「解析時の手順書に対するものです」)。

### テスト計画

- [ ] `tests/Architecture/` のドキュメント同期系 (`verification-commands-doc-sync` 等) が緑のまま

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `analysis_jobs` への migration を含む (他タスクと同じ worktree で走らせない)。(2) `AnalysisPipeline` / `WorkDecompositionData` / prompt YAML / canned 応答という**解析パイプラインの中核**を触るため、他の解析系変更と並走すると衝突が読みにくい。(3) M1→M9 が一連の依存 (DTO → 保存 → props → 画面) で、部分マージすると canned 不整合で解析系テストが落ちる |
| 競合リスク | `resources/js/types/manual.ts` (他タスクも触りやすい共有ファイル) / `VideoManualController::show` の props / `tests/js/pages/ManualsShow.test.ts`。いずれも追記のみで、衝突しても解決は機械的 |

## 実装順序 (テストファースト)

1. M3 (migration + model + factory) — 保存先が無いと後段が書けない
2. M2 (DTO / enum / 例外) — **先に Unit テストを赤で作る**
3. M1 (prompt YAML) + M8 (canned) — DTO が通ることを canned テストで確認
4. M4 (pipeline) — Feature テストで保存とリトライログを固定
5. M5 (規約検査) — Unit テストで 5 code の境界を固定
6. M6 (builder + controller) — Feature テストで props とクエリ本数を固定
7. M7 (画面) — vitest でパネルを固定
8. M9 (docs)

## 検証コマンド

AGENTS.md の検証コマンド一覧 (VERIFICATION_COMMANDS) の**全量**を実行してからコミットする:

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

末尾 3 本 (`*:packages`) は本変更が触らない `packages/` 配下が対象だが、
**規約上は実行対象**なので省略しない (回帰の見落としを防ぐ)。
