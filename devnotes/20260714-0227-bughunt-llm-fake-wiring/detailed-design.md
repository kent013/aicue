# 詳細設計: bughunt-llm-fake-wiring

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`。`--parallel` 並列実行）
- **RefreshDatabase** グローバル適用（`tests/Pest.php`）。個別 `DatabaseTransactions` 禁止
- **テストデータは Factory 生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨。`declare(strict_types=1)` + 日本語コメント
- `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

`devnotes/20260714-0227-bughunt-llm-fake-wiring/conceptual-design.md`（概念レビュー Round 3 で **APPROVED**）

## 背景（要約）

bughunt (`APP_ENV=bughunt.local`) の実行時プロセスで Prism の LLM 呼び出しが fake されず実 Anthropic API に飛び 401。原因は `FakeExternalsServiceProvider` が Stripe のみ fake bind し LLM を配線していないこと。既存 canned 応答機構 (`app/Services/AI/Testing/Browser*`) は `tests/Pest.php` の Browser lane でしか install されない。加えて全実プロンプトが `Prompt::load()` 経由で generic `TextPrompt` を返すため `PromptFake::record()` のキーが `TextPrompt::class` に潰れ、S3 各段 (`sop-extract`/`work-decomposition`/`scenario-generation`) の DTO スキーマ (`sections`/`steps`/`cuts`) を返し分けられない。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | canned 応答機構の改名 + signature ベース解決化 | `app/Services/AI/Testing/CannedPromptResponses.php`（旧 `BrowserCannedResponses.php`）, `app/Services/AI/Testing/CannedPromptFake.php`（旧 `BrowserPromptFake.php`）, `app/Services/AI/Testing/CannedPromptFakeRegistrar.php`（旧 `BrowserPromptFakeRegistrar.php`） | High |
| 2 | S3 中核 3 プロンプトの決定論 canned 応答追加 | `app/Services/AI/Testing/CannedPromptResponses.php` | High |
| 3 | FakeExternalsServiceProvider に LLM fake 配線 (`boot()`) | `app/Providers/FakeExternalsServiceProvider.php` | High |
| 4 | Browser lane の改名追随 | `tests/Pest.php` | High |
| 5 | テスト一式（Feature/統合/衝突防止/fail-fast） | `tests/Feature/**`（新規） | High |

> **改名の限定**: rename 対象は LLM fake 配線に直接関わる上記 3 クラス + `tests/Pest.php` の import 追随のみ。互換 alias は残さず旧名を同 PR で消す（後方互換の並走を残さない原則）。namespace `App\Services\AI\Testing` は維持し、クラス名/ファイル名のみ変更（PSR-4）。

---

## 施策 1: canned 応答機構の改名 + signature ベース解決化

### 変更箇所
- `app/Services/AI/Testing/BrowserCannedResponses.php` → `CannedPromptResponses.php`（解決キーを class → **SystemMessage signature** へ）
- `app/Services/AI/Testing/BrowserPromptFake.php` → `CannedPromptFake.php`（`nextResponse()` を最新 record の **messages** ベース解決へ）
- `app/Services/AI/Testing/BrowserPromptFakeRegistrar.php` → `CannedPromptFakeRegistrar.php`（中身は依存クラス名追随のみ）

### 波及変更
- TypeScript 型定義: なし（バックエンドのみ）
- API Resource/DTO: なし
- テストファイル: `tests/Pest.php`（施策 4）。新規テスト（施策 5）

### 設計理由（signature 解決）
全実プロンプトが `TextPrompt::class` に潰れるため、クラス名では返し分けられない。user message は UserInput（前段の出力 JSON を含む）が混入し段間で誤判定するため使わない。**SystemMessage の役割文**（各 YAML の `system_prompt` 本文に含まれる、DefensiveInstructions preamble を除いたアプリ固有の一意句）を判別子とする。vendor の `PromptFake::record()` は `messages` を保持するため fake 側で参照可能。

### 変更後コード（`CannedPromptResponses.php`）
```php
<?php

declare(strict_types=1);

namespace App\Services\AI\Testing;

use Kent013\PrismPrompt\Testing\TextResponseFake;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * canned response の定義と SystemMessage signature による解決。
 *
 * 全実プロンプトは Prompt::load() 経由で generic TextPrompt を返すため、
 * PromptFake::record() のキー (static::class) は TextPrompt::class に潰れる。
 * よってクラス名ではなく system_prompt の役割文 (signature) で canned を返し分ける。
 * signature は各 YAML 固有の一意句 (DefensiveInstructions preamble は全 YAML 共通なので使わない)。
 *
 * 未一致 (0 件) / 曖昧 (2 件以上) はいずれも fail-fast で例外を投げ、silent false-positive を防ぐ。
 * Browser lane (tests/Pest.php) と bughunt 実行時 (FakeExternalsServiceProvider) の双方で共有される。
 */
final class CannedPromptResponses
{
    /**
     * SystemMessage の内容から一意な signature を引いて canned を返す。
     *
     * @param  array<int, Message>  $messages
     */
    public function forMessages(array $messages): TextResponseFake
    {
        $systemText = $this->systemMessageText($messages);

        $matched = [];
        foreach ($this->map() as $signature => $canned) {
            if ($systemText !== '' && str_contains($systemText, $signature)) {
                $matched[$signature] = $canned;
            }
        }

        if (count($matched) !== 1) {
            $registered = implode(', ', array_keys($this->map()));
            $collided = implode(', ', array_keys($matched));
            $snippet = mb_substr($systemText, 0, 200);
            throw new RuntimeException(sprintf(
                "CannedPromptResponses could not uniquely resolve a canned response "
                ."(matched %d signatures: [%s]).\nRegistered signatures: [%s]\n"
                ."System text (first 200 chars): %s\n"
                .'Register/adjust one in app/Services/AI/Testing/CannedPromptResponses.php '
                .'to avoid silent false-positives.',
                count($matched),
                $collided === '' ? '(none)' : $collided,
                $registered,
                $snippet,
            ));
        }

        $canned = array_values($matched)[0];
        Assert::string($canned);

        return TextResponseFake::make()->withText($canned);
    }

    /**
     * @return list<string> 登録済み signature 一覧 (テスト用)
     */
    public function supportedSignatures(): array
    {
        return array_keys($this->map());
    }

    /**
     * @param  array<int, Message>  $messages
     */
    private function systemMessageText(array $messages): string
    {
        $parts = [];
        foreach ($messages as $message) {
            if ($message instanceof SystemMessage) {
                $parts[] = $message->content;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * signature (system_prompt 固有の一意句) => canned response (決定論)。
     *
     * @return array<string, string>
     */
    private function map(): array
    {
        return [
            '作業手順書 (SOP) を構造化するエキスパート' => self::sopExtractCanned(),
            '作業標準化エキスパート' => self::workDecompositionCanned(),
            'マニュアル動画の演出家' => self::scenarioGenerationCanned(),
            'テキストを 1 文に要約するアシスタント' => self::exampleSummaryCanned(),
        ];
    }

    // ---- canned 応答 (各 DTO の fromLlmText を通過する最小妥当 JSON) ----
    // ※ 実体は施策 2 で定義
}
```

### 変更後コード（`CannedPromptFake.php` の要点）
```php
public function nextResponse(?Prompt $currentPrompt = null): TextResponseFake
{
    $messages = $this->latestRecordedMessages();
    if ($messages === null) {
        throw new RuntimeException(
            'CannedPromptFake::nextResponse() could not resolve recorded messages. '
            .'Ensure the fake is installed and Prompt::executePrism() recorded the prompt.'
        );
    }

    return $this->cannedResponses->forMessages($messages);
}

/** @return array<int, Message>|null */
private function latestRecordedMessages(): ?array
{
    $last = end($this->recorded);

    return is_array($last) ? $last['messages'] : null;
}
```
- コンストラクタは `CannedPromptResponses` を受ける（旧 `BrowserCannedResponses`）。`parent::__construct([])` は維持。
- `$this->recorded` は親 `PromptFake` の protected プロパティ（`array<int, array{prompt_class, messages, provider, model}>`）。

### 変更後コード（`CannedPromptFakeRegistrar.php`）
```php
/**
 * canned PromptFake の install/uninstall を単一箇所に封じ込める。
 * Browser lane (tests/Pest.php) と bughunt 実行時 (FakeExternalsServiceProvider::boot) の
 * 両方から共有される (Browser 専用ではない)。
 */
final class CannedPromptFakeRegistrar
{
    public function __construct(private readonly CannedPromptResponses $responses) {}

    public function install(): void
    {
        Prompt::installFake(new CannedPromptFake($this->responses));
    }

    public function uninstall(): void
    {
        Prompt::stopFaking();
    }
}
```

### signature 方式の将来移行（今回は不採用・根拠を残す）
自然文 signature は prompt tuning で drift しうる。将来 signature が増えて保守が重くなった場合は、各 YAML の `system_prompt` に machine-friendly な固定トークン（例: `[PROMPT_SIGNATURE:sop-extract]`）を埋めてトークン一致に移行する候補がある。**今回は不採用**: 全 YAML の prompt 本文改変は本 item（fake 配線）のスコープを超え、prompt 改変は使命上の慎重さを要する。drift は施策 5-2 の衝突防止/DTO 通過テストが fail-fast で検出するため、現時点は自然文 signature で十分。

### PHPStan 適合チェック
- [x] 戻り値の型明示（`TextResponseFake` / `list<string>` / `?array` 等）
- [x] `$message instanceof SystemMessage` で `->content`(readonly string) に安全アクセス
- [x] `array_values($matched)[0]` は `count===1` 保証後だが PHPStan 向けに `Assert::string()` で確定
- [x] 配列返却は canned 定義 (string) のみ。DTO/JsonResource 対象外の内部 fake ユーティリティ
- [x] `end()` の戻り (`mixed|false`) を `is_array()` で絞る

### テスト計画
施策 5 に集約。

### リスク
- signature が YAML から drift すると解決不能 → **施策 5 の衝突防止/DTO 通過テストが fail-fast で検出**（silent green にならない）。
- 既存 Browser lane は ExampleSummaryPrompt 系のみ使用。signature 化後も `example-summary` の system_prompt 役割文で解決されるため挙動は不変。

---

## 施策 2: S3 中核 3 プロンプトの決定論 canned 応答追加

### 変更箇所
- `app/Services/AI/Testing/CannedPromptResponses.php` の canned 定義メソッド群

### 設計理由
canned は各 DTO の `fromLlmText()` を**通過する最小妥当 JSON** でなければならない（通過しないと `AnalysisPipeline::withBoundedRetry` がリトライ→ジョブ失敗）。`LlmJson::decode` はコードフェンス除去のみなので**素の JSON 文字列**で返す。文字数・有界性は `ScenarioLimits`（MAX_STEPS=100 / MAX_POINTS_PER_STEP=20 / SCENE=1000 / NARRATION=2000 / SUBTITLE_PRIMARY=100 / SUBTITLE_SECONDARY=2000）に収める。

### 変更後コード（canned 定義）
```php
/** sop-extract: ExtractedSopData::fromLlmText を通過 (header + 1 section + 1 step) */
private static function sopExtractCanned(): string
{
    return json_encode([
        'header' => ['title' => 'bughunt サンプル手順書', 'department' => null, 'revision' => null],
        'sections' => [[
            'title' => null,
            'steps' => [[
                'no' => 1,
                'work_process' => 'バルブを閉じる',
                'work_points' => ['ハンドルを時計回りに回す'],
                'safety_points' => ['保護手袋を着用する'],
                'quality_points' => [],
                'pm_points' => [],
            ]],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/** work-decomposition: WorkDecompositionData::fromLlmText を通過 (1 step / points 1) */
private static function workDecompositionCanned(): string
{
    return json_encode([
        'steps' => [[
            'no' => 1,
            'action' => 'バルブを閉じる',
            'points' => ['ハンドルが止まるまで回す'],
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/** scenario-generation: GeneratedScenarioData::fromLlmText を通過 (step→それを参照する point) */
private static function scenarioGenerationCanned(): string
{
    return json_encode([
        'cuts' => [
            [
                'no' => 1, 'type' => 'step', 'parent_no' => null,
                'scene' => '作業台全体を引きで写す', 'shot_type' => 'hiki',
                'shooting_point' => null, 'narration' => 'バルブを閉じます。',
                'subtitle_primary' => 'バルブ閉', 'subtitle_secondary' => '',
            ],
            [
                'no' => 2, 'type' => 'point', 'parent_no' => 1,
                'scene' => 'ハンドル操作を寄りで写す', 'shot_type' => 'yori',
                'shooting_point' => null, 'narration' => 'ハンドルが止まるまで回します。',
                'subtitle_primary' => null, 'subtitle_secondary' => '',
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

/** example-summary: 1 文の要約 (非空 string) */
private static function exampleSummaryCanned(): string
{
    return 'テスト/bughunt 共通の固定要約文です。';
}
```

### 妥当性確認（各 DTO の検証条件との対応）
- **ExtractedSopData**: `header` object / `sections` list / step の `no:int` `work_process:非空` / 各 points list<string>。totalSteps=1（1..2100 の範囲内）。✅
- **WorkDecompositionData**: `steps` list（1..100）/ `no:int` / `action:非空` / `points` list<string>（0..20）。✅
- **GeneratedScenarioData**: `cuts` list（≥1）/ step は `parent_no=null` / point は既出 step を参照 / `shot_type∈{hiki,yori}` / `scene:非空≤1000` / `narration≤2000` / `subtitle_primary≤100` / `subtitle_secondary≤2000`。cut1(step)→cut2(point,parent_no=1)。✅

### PHPStan 適合チェック
- [x] `json_encode(..., JSON_THROW_ON_ERROR)` は `string` を返す（`false` 分岐なし）→ 戻り値 `string` 型に適合

### テスト計画
施策 5（DTO 通過テストが主保証）。

### リスク
- DTO の不変条件が将来変わると canned が古くなる → **DTO 通過テストが fail** して検出（追随を強制）。

---

## 施策 3: FakeExternalsServiceProvider に LLM fake 配線（`boot()`）

### 変更箇所
- `app/Providers/FakeExternalsServiceProvider.php`（`boot()` 追加。既存 `register()` は不変）

### 波及変更
- TypeScript/DTO/Resource: なし
- テスト: `tests/Feature/Providers/FakeExternalsServiceProviderTest.php`（既存に provider 発火条件テストを追加）

### 設計理由（環境 allowlist）
- Stripe fake は container bind で per-test 隔離が効くため既存 allowlist（`['local','testing','bughunt.local']`）のまま。
- LLM fake は**プロセスグローバル static `Prompt::$fake`** を書き換えるため、`testing`（phpunit harness が per-test で占有）と `local`（手元の実 API 検証を潰す）を**除外**し、**`['bughunt.local']` のみ**の LLM 専用 allowlist を新設。
- `boot()` は HTTP serve / queue worker / artisan の全 bootstrap で走るため、`RunManualAnalysis`（`ShouldQueue`）が sync（同一 HTTP プロセス）でも async（専用 worker）でも fake が有効。
- **fake 分岐は `PromptExecutionCompleted` を発火しない**（`executePrism` の fake branch は event 発火前に return）ため、`llm_call_logs` は書かれず、FX 解決 HTTP も走らない（bughunt から外部 HTTP に漏れない）。ログ依存導線が未検証領域になる点は概念設計に明記済み。

### 変更後コード
```php
// 既存 register() の allowlist は Stripe 用と明示するため PAYMENT_FAKE_ENVIRONMENTS に改名
// (private const・外部参照なし)。新設 LLM_FAKE_ENVIRONMENTS と対比させ誤読を防ぐ。
/** Stripe 課金 gateway fake を許可する環境 allowlist (container bind。per-test 隔離が効くため testing 可) */
private const array PAYMENT_FAKE_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

/** LLM (Prism) fake の install を許可する環境 allowlist (Prompt::$fake は static。testing/local を除外) */
private const array LLM_FAKE_ENVIRONMENTS = ['bughunt.local'];

public function boot(): void
{
    if (config('testing.fake_externals') !== true) {
        return;
    }

    // LLM fake は Prompt::$fake (プロセスグローバル static) を書き換えるため、
    // per-test で static を占有する testing、実 API 検証を潰す local は除外する。
    if (! in_array($this->app->environment(), self::LLM_FAKE_ENVIRONMENTS, true)) {
        return;
    }

    // Browser lane (tests/Pest.php) と同一の install API を使う (Prompt::installFake の封じ込め)。
    $this->app->make(CannedPromptFakeRegistrar::class)->install();
}
```
- import 追加: `use App\Services\AI\Testing\CannedPromptFakeRegistrar;`
- 既存 `register()` 内の `self::ALLOWED_ENVIRONMENTS` 参照を `self::PAYMENT_FAKE_ENVIRONMENTS` に置換（挙動不変。可読性のみ）。

### PHPStan 適合チェック
- [x] `config('testing.fake_externals')` は mixed → `=== true` 厳密比較
- [x] `$this->app->environment()` は `string` を返す（引数なし呼び出し）
- [x] `$this->app->make(CannedPromptFakeRegistrar::class)` の戻りは第 1 引数の class-string からジェネリクスで `CannedPromptFakeRegistrar` に解決
- [x] `const array` 宣言（PHP 8.4 typed class constant。既存 `ALLOWED_ENVIRONMENTS` と同形）

### テスト計画
施策 5（provider 発火条件テスト）。

### リスク
- `boot()` が全プロセスで走るため、万一 `bughunt.local` で fake_externals=true のまま**実 API 検証をしたい**ケースを潰す。→ bughunt は隔離検証専用環境であり実 API 検証用途がない前提（`ProductionEnvGuard` + 三重ガードで本番から遮断済み）。許容。
- register() の Stripe warning 経路とは独立（boot は allowlist 外で silent return。`testing`/`local` は誤設定ではなく設計上の除外なので warning は出さない）。

---

## 施策 4: Browser lane の改名追随（`tests/Pest.php`）

### 変更箇所
- `tests/Pest.php` L11 import と L90 呼び出し

### 変更後コード
```php
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
// ...
// canned PromptFake は Browser lane と bughunt 実行時の両方で共有 (registrar 参照)。
app(CannedPromptFakeRegistrar::class)->install();
```
- Browser lane の beforeEach/afterEach の**挙動は不変**（install/uninstall API 名は維持）。

### 波及変更
- テスト: Browser lane 全体（挙動不変を施策 5 の非破壊確認でカバー）

### PHPStan 適合チェック
- [x] クラス名変更のみ。型影響なし

### テスト計画
既存 Browser テスト green（施策 5-6）。

### リスク
- 旧クラス名の残参照があるとオートロード失敗 → 全参照を rename（`rg 'Browser(CannedResponses|PromptFake|PromptFakeRegistrar)'` で 0 件を確認）。

---

## 施策 5: テスト一式

配置: `tests/Feature/Llm/`（既存 `ExampleSummaryPromptTest.php` と同ディレクトリ）および `tests/Feature/Providers/`。全て Factory 生成・`RefreshDatabase` グローバル・`--parallel` 前提（個別 `DatabaseTransactions` 不使用）。

**共通テストヘルパ（vendor 公開契約 `recorded()` による capture 方式）**: `captureMessages(callable $runOnce): array<int, Message>` — `CannedPromptFakeRegistrar::install()` した状態で、渡された「対象 prompt を **1 回だけ** `executeSync()` する」クロージャを実行し、`Prompt::getFake()->recorded()`（vendor の**公開 API**）の唯一 entry から `messages` を取得する。1 ケース 1 実行のため record 順序・他メッセージ混入は発生しない。**reflection / protected `buildMessages()` には依存しない**（Round 2 対応: vendor 実装詳細への結合を排除）。取得した `messages` を `CannedPromptResponses::forMessages()` / signature 一致検証に渡す。

### 5-1. canned DTO 通過テスト（主保証）— `tests/Feature/Llm/CannedPromptResponsesTest.php`（新規）
各実 factory について「build → `CannedPromptFakeRegistrar::install()` → `executeSync()` → 該当 DTO の `fromLlmText()` が成功」を検証。テスト名は「{prompt} の canned が {DTO}::fromLlmText を通過する」形式で、満たす制約を可読化する。
- `SopExtractPrompt::make('サンプル SOP')->executeSync()` → `ExtractedSopData::fromLlmText($text)` が例外なし
- `WorkDecompositionPrompt::make('{"header":{},"sections":[]}')->executeSync()` → `WorkDecompositionData::fromLlmText($text)` が例外なし
- `ScenarioGenerationPrompt::make('{"steps":[]}')->executeSync()` → `GeneratedScenarioData::fromLlmText($text)` が例外なし
- `ExampleSummaryPrompt::make('本文')->executeSync()` → 非空 string
- afterEach で `Prompt::stopFaking()`。stray call 0（StrayLlmCallGuard 経由で担保）。
- 防御的に `Http::fake(['*' => ...])`（既存 AnalysisPipelineTest 準拠）。

### 5-2. signature 衝突防止テスト（登録 prompt allowlist に対する 1:1）— 同ファイル
- **登録対象 prompt の明示 allowlist（4 factory: SopExtract / WorkDecomposition / ScenarioGeneration / ExampleSummary）** を dataset とし、各ケースで対象 factory を `captureMessages()` 経由で **1 回だけ** `executeSync()` → capture した system message に対し `CannedPromptResponses::supportedSignatures()` の一致数が**ちょうど 1**、かつ一致 signature が**期待どおり**であることを検証（1:1 対応の固定。vendor 公開 `recorded()` のみ使用）。
- signature の**ペアワイズ非部分包含**（どの signature も他 signature の部分文字列でない）を assert（将来 signature 追加時の衝突を静的に防止）。
- **「全 YAML 総数 = signature 件数」の等値検証はしない**（fake 対象外の prompt を将来追加した際に誤 fail するため。Round 1 Critical 対応）。未登録判定は 5-3 の fail-fast で担保する。
- afterEach で `Prompt::stopFaking()`。

### 5-3. 未登録 / 曖昧 prompt fail-fast テスト — 同ファイル
- どの signature も含まない `SystemMessage('未知の役割')` のみの messages を `CannedPromptResponses::forMessages()` に渡すと `RuntimeException`（未登録＝0 件一致 → fail-fast。「未登録対象は 0 件に一致」を担保）。
- 2 signature を同時に含む messages（曖昧＝2 件一致）でも `RuntimeException`（`count !== 1` の両側を固定）。
- 例外メッセージに system text 先頭 200 字と一致 signature が含まれることを assert（調査性）。

### 5-4. provider 発火条件テスト — `tests/Feature/Providers/FakeExternalsServiceProviderTest.php`（既存に追加）
既存の env 差し替えパターン（`$this->app['env']` を try/finally で復元）を踏襲。**config も try/finally で原値復元**（`$original = config('testing.fake_externals'); config(['testing.fake_externals' => true]); ... finally { config(['testing.fake_externals' => $original]); $this->app['env'] = $originalEnv; }`）。
- `env=bughunt.local` ∧ flag=true で `boot()` → `Prompt::isFaking()===true`、代表 prompt が canned を返す（stray call 0）
- `env=testing` ∧ flag=true で `boot()` → `Prompt::isFaking()===false`（**provider が Prompt::$fake に触れない**ことを固定）
- `env=local` ∧ flag=true で `boot()` → `Prompt::isFaking()===false`
- flag=false で `boot()` → `Prompt::isFaking()===false`
- **リーク検知は `afterEach` に置く**（Round 2 対応: テスト本体が例外で落ちても必ず実行されるよう、`afterEach` で `Prompt::stopFaking()` を実行し、この describe/ファイルの各テスト境界で static がリークしないことを保証する。finally 後 assertion は例外時に到達しないため本体には置かない）。

### 5-5. AI 解析 end-to-end 統合テスト（queue + materialize）— `tests/Feature/Llm/CannedAnalysisPipelineTest.php`（新規）
既存 `AnalysisPipelineTest` の `pipelineContext()` パターンで context 構築（Factory: Organization/Project/VideoManual/SourceDocument/AnalysisJob + チケット）。
- `CannedPromptFakeRegistrar::install()`（bughunt 実行時の配線を模す）下で `app(AnalysisPipeline::class)->run($job->id)`
- 検証: ジョブ `succeeded` / `cuts` が materialize（step 1 + point 1）/ `VideoManualStatus` 遷移 / **stray call 0**（実 API 未到達）/ `Http` stray なし
- 防御的 `Http::fake`。afterEach `Prompt::stopFaking()`。

### 5-6. 既存経路非破壊 + stray guard 健全性
- 既存 Browser lane（`tests/Pest.php`）、`StrayLlmCallGuard` 系、`ExampleSummaryPromptTest`、`AnalysisPipelineTest` が green のまま（改名追随のみ・挙動不変）。
- **stray guard の健全性**: 新規に実 stray を発生させるケースは追加しない（実通信前遮断のタイミングを保証しづらいため。Round 2 対応）。既存 `StrayLlmCallGuard` 単体テスト群が green のままであることの維持確認に留め、本変更が guard 経路を壊していないことを担保する。
- `composer phpstan` / `vendor/bin/pint --test` green。

### PHPStan 適合チェック（テスト）
- [x] Factory 経由生成（手組み `create()` 配列は Factory の状態メソッド経由）
- [x] `fromLlmText` の戻り DTO を型で受ける
- [x] 個別 `DatabaseTransactions` 不使用
- [x] `captureMessages()` は `recorded()` が**厳密に 1 件**であることを `Assert::count`（相当）で確認し、`messages` の各要素が `Message` であることを `Assert::allIsInstanceOf(Message::class, ...)`（相当）で絞ってから返す（PHPStan L10 で `mixed` を排除）。`Prompt::getFake()` の null も `Assert::notNull` で絞る。

### テスト実装上の注記（Round 3 Suggestion 反映）
- 「stray 0」を明示検証する場合は、そのテスト自身で `StrayLlmCallGuard::install($this->app)` / `flushAndFailIfStray()` / `reset()` を管理する。明示検証しないケースは「fake の `recorded()` 件数」と「`Http` 未送信（`Http::assertNothingSent()` 相当）」で表現し、担保対象を混同しない。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 単一の関心事（bughunt への LLM fake 配線）で、変更は `app/Services/AI/Testing/` 3 クラス + provider 1 + `tests/Pest.php` + 新規テストに閉じる。他 item との共有面が小さく、改名を含むため他ブランチと同時並行すると import 競合しやすい。1 ブランチで一括実施が安全。 |
| 競合リスク | 改名（3 クラス）が `tests/Pest.php` と交差するため、同ファイルを触る他 item と同時進行しないこと。 |

## スコープ外
- **ffmpeg 不在**（Q1 残り）: 別 item。
- **S3 互換ストレージ region 未設定**（Q1 残り）: 別 item。
- レンダー（ffmpeg）段。本 item は AI 解析 3 段の完走まで。
- `llm_call_logs` 依存の UI/監査/運用導線（fake 分岐で非発火＝未検証領域として明示）。
- vendor（`kent013/laravel-prism-prompt`）本体の改修（record への prompt name 付与等）。
