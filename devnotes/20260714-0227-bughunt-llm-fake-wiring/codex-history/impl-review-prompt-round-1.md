## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## 役割・タスク

あなたは Laravel + Svelte アプリのコードレビュアーである。TODO T035「bughunt 実行時環境への LLM(Prism) 応答 fake 配線」の実装差分をレビューする。

背景: bughunt 隔離環境 (`APP_ENV=bughunt.local`) の実行時プロセスで Prism の LLM 呼び出しが fake されず実 Anthropic API に飛び 401 になっていた。本実装は、既存の Browser lane 専用 canned 機構を「SystemMessage signature ベース解決」に一般化し、`FakeExternalsServiceProvider::boot()` から bughunt.local 限定で install することで、S3 解析 3 段 (sop-extract / work-decomposition / scenario-generation) を決定論 canned 応答で完走させる。

### レビュー観点（この順で厳しく見る）

1. **設計との一致性**: 下記「詳細設計書」の施策 1〜5 と差分が一致しているか。逸脱があれば指摘。
2. **正確性**: signature 解決ロジック (0件/2件以上の fail-fast)、`latestRecordedMessages()` の record 契約依存、boot() の環境 allowlist (bughunt.local のみ / testing・local 除外の static リーク回避) にバグや抜けがないか。
3. **PHPStan 適合性** (level 10): `mixed` の残留、型 narrowing の漏れ。
4. **DTO/JsonResource パターン**: 本変更は内部 fake ユーティリティで対象外だが、`response()->json()` 直書き等の禁止事項違反がないか。
5. **テスト網羅性**: canned が各 DTO の `fromLlmText` を通過する主保証、signature 1:1 対応、未登録/曖昧の fail-fast、provider 発火条件 (4 環境)、end-to-end 統合、が十分か。Factory 生成・RefreshDatabase グローバル・個別 DatabaseTransactions 不使用を守っているか。
6. **セキュリティ**: bughunt から実外部 API/HTTP に漏れないこと、static (`Prompt::$fake`) が testing/local を汚染しないこと。
7. **後方互換の並走を残さない**: 旧 `Browser*` クラス名を同 PR で完全に消しているか (alias 残置なし)。

### 出力形式

- ファイルごとに判定を述べる。
- 指摘は **[Critical] / [Warning] / [Suggestion]** で分類する。
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明記する。

品質ゲート結果（参考。すべて green）:
- composer test: 1626 passed, 2 skipped (0 failed)
- composer phpstan: No errors (level 10)
- vendor/bin/pint --test: passed
- pnpm lint / typecheck: clean
- pnpm test: 525 passed
- pnpm build: OK

---

## 詳細設計書

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


---

## 実装差分（git diff HEAD）

```diff
diff --git a/app/Providers/FakeExternalsServiceProvider.php b/app/Providers/FakeExternalsServiceProvider.php
index 86a5077..a6bf15a 100644
--- a/app/Providers/FakeExternalsServiceProvider.php
+++ b/app/Providers/FakeExternalsServiceProvider.php
@@ -4,6 +4,7 @@
 
 namespace App\Providers;
 
+use App\Services\AI\Testing\CannedPromptFakeRegistrar;
 use App\Services\Billing\Fakes\FakeSubscriptionCheckoutGateway;
 use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
 use App\Services\Billing\SubscriptionCheckoutGateway;
@@ -17,15 +18,22 @@
  * bootstrap/providers.php で AppServiceProvider より後に登録する (後勝ち rebind)。
  * fail-secure 二軸:
  * 1. flag === true (既定 false = 完全 no-op)
- * 2. 環境 allowlist (local / testing / bughunt.local)。denylist (非 production) ではなく
- *    allowlist で倒す = staging 等の未知環境で flag が誤設定されても fake しない
- *    (warning ログで検出可能にする)。production は加えて ProductionEnvGuard が
- *    flag=true を deploy 時 fail-fast で拒否する (二重防御)。
+ * 2. 環境 allowlist。denylist (非 production) ではなく allowlist で倒す = staging 等の
+ *    未知環境で flag が誤設定されても fake しない (warning ログで検出可能にする)。
+ *    production は加えて ProductionEnvGuard が flag=true を deploy 時 fail-fast で拒否する。
+ *
+ * fake 対象は 2 系統で allowlist が異なる:
+ * - Stripe 課金 gateway: container bind (per-test 隔離が効くため testing 可)。register() で配線。
+ * - LLM (Prism): Prompt::$fake は static (プロセスグローバル) のため testing/local を除外。
+ *   boot() で bughunt.local のみ配線 (HTTP serve / queue worker / artisan 全 bootstrap で発火)。
  */
 class FakeExternalsServiceProvider extends ServiceProvider
 {
-    /** fake bind を許可する環境 allowlist */
-    private const array ALLOWED_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];
+    /** Stripe 課金 gateway fake を許可する環境 allowlist (container bind。per-test 隔離が効くため testing 可) */
+    private const array PAYMENT_FAKE_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];
+
+    /** LLM (Prism) fake の install を許可する環境 allowlist (Prompt::$fake は static。testing/local を除外) */
+    private const array LLM_FAKE_ENVIRONMENTS = ['bughunt.local'];
 
     public function register(): void
     {
@@ -34,7 +42,7 @@ public function register(): void
         }
 
         $environment = $this->app->environment();
-        if (! in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
+        if (! in_array($environment, self::PAYMENT_FAKE_ENVIRONMENTS, true)) {
             Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
                 'environment' => $environment,
             ]);
@@ -46,4 +54,21 @@ public function register(): void
         $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
         $this->app->bind(SubscriptionCheckoutGateway::class, FakeSubscriptionCheckoutGateway::class);
     }
+
+    public function boot(): void
+    {
+        if (config('testing.fake_externals') !== true) {
+            return;
+        }
+
+        // LLM fake は Prompt::$fake (プロセスグローバル static) を書き換えるため、
+        // per-test で static を占有する testing、実 API 検証を潰す local は除外する。
+        // (Stripe と違い warning は出さない: testing/local の除外は誤設定ではなく設計上の除外)
+        if (! in_array($this->app->environment(), self::LLM_FAKE_ENVIRONMENTS, true)) {
+            return;
+        }
+
+        // Browser lane (tests/Pest.php) と同一の install API を使う (Prompt::installFake の封じ込め)。
+        $this->app->make(CannedPromptFakeRegistrar::class)->install();
+    }
 }
diff --git a/app/Services/AI/Testing/BrowserCannedResponses.php b/app/Services/AI/Testing/BrowserCannedResponses.php
deleted file mode 100644
index 6c3b09f..0000000
--- a/app/Services/AI/Testing/BrowserCannedResponses.php
+++ /dev/null
@@ -1,89 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\Services\AI\Testing;
-
-use Kent013\PrismPrompt\Testing\TextResponseFake;
-use Kent013\PrismPrompt\TextPrompt;
-use RuntimeException;
-
-/**
- * Prompt クラスごとの決定論的 canned response 定義 (Browser テスト用)。
- *
- * 新しい Prompt サブクラスを Browser テストから呼ぶ場合は、ここに必ず応答を
- * 登録すること。未登録のまま呼ばれた場合は fail-fast で例外を投げ、
- * 偽陽性 (silent green) を防ぐ。
- *
- * Feature/Unit テストは Prism::fake() / Prompt::fake() を使い個別にモックするため、
- * ここには影響しない。本クラスは Browser (pest-plugin-browser) テストプロセス内で
- * のみ使われる (tests/Pest.php の Browser lane が BrowserPromptFakeRegistrar 経由で
- * インストールする)。
- *
- * キーの決まり方 (重要):
- *   record() には Prompt インスタンスの `static::class` が入る。テンプレートの
- *   `ExampleSummaryPrompt` のような「YAML を Prompt::load で読む factory」は
- *   generic な `TextPrompt` インスタンスを返すため、記録されるクラスは
- *   `TextPrompt::class` になる (factory クラス名ではない)。
- *   専用サブクラス (`class FooPrompt extends TextPrompt` 等) を定義した場合は
- *   そのサブクラス名がキーになるので、map() に 1 行追加する。
- */
-final class BrowserCannedResponses
-{
-    public function forPromptClass(string $promptClass): TextResponseFake
-    {
-        $canned = $this->lookup($promptClass);
-
-        if ($canned === null) {
-            $registered = implode(', ', $this->supportedPromptClasses());
-            throw new RuntimeException(sprintf(
-                "BrowserCannedResponses has no canned response for prompt class '%s'.\n"
-                ."Registered classes: [%s]\n"
-                .'Register one in app/Services/AI/Testing/BrowserCannedResponses.php '
-                .'to avoid silent false-positives in Browser tests.',
-                $promptClass,
-                $registered === '' ? '(none)' : $registered,
-            ));
-        }
-
-        return TextResponseFake::make()->withText($canned);
-    }
-
-    /**
-     * @return list<class-string>
-     */
-    public function supportedPromptClasses(): array
-    {
-        /** @var list<class-string> $keys */
-        $keys = array_keys($this->map());
-
-        return $keys;
-    }
-
-    private function lookup(string $promptClass): ?string
-    {
-        return $this->map()[$promptClass] ?? null;
-    }
-
-    /**
-     * @return array<class-string, string>
-     */
-    private function map(): array
-    {
-        return [
-            // App\Prompts\ExampleSummaryPrompt (factory) 用の最小エントリ。
-            // Prompt::load が返す generic TextPrompt を実行するため、記録される
-            // prompt class は TextPrompt::class になる (クラス docblock 参照)。
-            TextPrompt::class => self::exampleSummaryCanned(),
-        ];
-    }
-
-    /**
-     * ExampleSummaryPrompt (example-summary.yaml) 用の canned response。
-     * 「テキストを日本語 1 文で要約する」プロンプトなので固定 1 文を返す。
-     */
-    private static function exampleSummaryCanned(): string
-    {
-        return 'Browser テスト用の固定要約文です。';
-    }
-}
diff --git a/app/Services/AI/Testing/BrowserPromptFake.php b/app/Services/AI/Testing/BrowserPromptFake.php
deleted file mode 100644
index 75cf164..0000000
--- a/app/Services/AI/Testing/BrowserPromptFake.php
+++ /dev/null
@@ -1,70 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\Services\AI\Testing;
-
-use Kent013\PrismPrompt\Prompt;
-use Kent013\PrismPrompt\Testing\PromptFake;
-use Kent013\PrismPrompt\Testing\TextResponseFake;
-use RuntimeException;
-
-/**
- * Browser テスト用の決定論的 PromptFake。
- *
- * Prompt サブクラスのクラス名から canned response を引き、
- * sequence 枯渇しない無限供給を提供する (parent::nextResponse() の
- * sequence ベース挙動をオーバーライド)。
- *
- * prompt class の解決経路 (優先順):
- *   1. nextResponse($currentPrompt) に明示渡しされた Prompt インスタンス
- *      (将来 prism-prompt が executePrism() で $this を渡すようになった場合の forward-compat)
- *   2. 直前の record() で記録された prompt class (= 現行 kent013/laravel-prism-prompt
- *      v0.17 の実契約。executePrism()/executePrismStructured() は nextResponse() 直前に
- *      record(static::class, ...) を呼ぶため、record の最新 entry が「今実行中の Prompt」を指す)
- *
- * いずれの経路でも prompt class が得られない場合のみ fail-fast で例外を投げる
- * (= fake 未インストール / record されていない真の misconfiguration)。
- */
-final class BrowserPromptFake extends PromptFake
-{
-    public function __construct(private readonly BrowserCannedResponses $cannedResponses)
-    {
-        parent::__construct([]);
-    }
-
-    /**
-     * @param  ?Prompt<mixed>  $currentPrompt
-     */
-    public function nextResponse(?Prompt $currentPrompt = null): TextResponseFake
-    {
-        $promptClass = $currentPrompt !== null
-            ? $currentPrompt::class
-            : $this->latestRecordedPromptClass();
-
-        if ($promptClass === null) {
-            throw new RuntimeException(
-                'BrowserPromptFake::nextResponse() could not resolve the current Prompt class. '
-                .'Neither an explicit $currentPrompt was passed nor a prior record() call exists. '
-                .'This indicates a misconfiguration: ensure BrowserPromptFakeRegistrar has installed '
-                .'BrowserPromptFake and that Prompt::executePrism() recorded the prompt before requesting '
-                .'a fake response.'
-            );
-        }
-
-        return $this->cannedResponses->forPromptClass($promptClass);
-    }
-
-    /**
-     * 直前に record() された prompt class を返す (無ければ null)。
-     *
-     * record() は executePrism()/executePrismStructured() の fake 分岐で
-     * nextResponse() の直前に必ず呼ばれるため、最新 entry が現在実行中の Prompt を指す。
-     */
-    private function latestRecordedPromptClass(): ?string
-    {
-        $last = end($this->recorded);
-
-        return is_array($last) ? $last['prompt_class'] : null;
-    }
-}
diff --git a/app/Services/AI/Testing/BrowserPromptFakeRegistrar.php b/app/Services/AI/Testing/BrowserPromptFakeRegistrar.php
deleted file mode 100644
index b3cd0da..0000000
--- a/app/Services/AI/Testing/BrowserPromptFakeRegistrar.php
+++ /dev/null
@@ -1,28 +0,0 @@
-<?php
-
-declare(strict_types=1);
-
-namespace App\Services\AI\Testing;
-
-use Kent013\PrismPrompt\Prompt;
-
-/**
- * `Prompt::$fake` への BrowserPromptFake 差替を封じ込める単一箇所。
- *
- * `laravel-prism-prompt` が提供する `Prompt::installFake(PromptFake)` 公開 API
- * を使う。将来この API が変わった場合も影響範囲はここだけ。
- */
-final class BrowserPromptFakeRegistrar
-{
-    public function __construct(private readonly BrowserCannedResponses $responses) {}
-
-    public function install(): void
-    {
-        Prompt::installFake(new BrowserPromptFake($this->responses));
-    }
-
-    public function uninstall(): void
-    {
-        Prompt::stopFaking();
-    }
-}
diff --git a/app/Services/AI/Testing/CannedPromptFake.php b/app/Services/AI/Testing/CannedPromptFake.php
new file mode 100644
index 0000000..c6bbf33
--- /dev/null
+++ b/app/Services/AI/Testing/CannedPromptFake.php
@@ -0,0 +1,62 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\AI\Testing;
+
+use Kent013\PrismPrompt\Prompt;
+use Kent013\PrismPrompt\Testing\PromptFake;
+use Kent013\PrismPrompt\Testing\TextResponseFake;
+use Prism\Prism\Contracts\Message;
+use RuntimeException;
+
+/**
+ * 決定論的 canned PromptFake (SystemMessage signature 解決)。
+ *
+ * 全実プロンプトは Prompt::load 経由で generic TextPrompt を実行するため、
+ * record() のキー (static::class) は TextPrompt::class に潰れる。よってクラス名では
+ * S3 各段 (sop-extract / work-decomposition / scenario-generation) を返し分けられない。
+ * 代わりに record() が保持する messages の SystemMessage 役割文 (signature) で解決する
+ * (解決ロジックは CannedPromptResponses に集約)。
+ *
+ * record() は executePrism()/executePrismStructured() の fake 分岐で nextResponse() の
+ * 直前に必ず呼ばれるため、$this->recorded の最新 entry が「今実行中の Prompt」を指す。
+ *
+ * Browser lane (tests/Pest.php) と bughunt 実行時 (FakeExternalsServiceProvider::boot) の
+ * 両方で共有される (Browser 専用ではない)。
+ */
+final class CannedPromptFake extends PromptFake
+{
+    public function __construct(private readonly CannedPromptResponses $cannedResponses)
+    {
+        parent::__construct([]);
+    }
+
+    /**
+     * @param  ?Prompt<mixed>  $currentPrompt
+     */
+    public function nextResponse(?Prompt $currentPrompt = null): TextResponseFake
+    {
+        $messages = $this->latestRecordedMessages();
+        if ($messages === null) {
+            throw new RuntimeException(
+                'CannedPromptFake::nextResponse() could not resolve recorded messages. '
+                .'Ensure the fake is installed and Prompt::executePrism() recorded the prompt.'
+            );
+        }
+
+        return $this->cannedResponses->forMessages($messages);
+    }
+
+    /**
+     * 直前に record() された messages を返す (無ければ null)。
+     *
+     * @return array<int, Message>|null
+     */
+    private function latestRecordedMessages(): ?array
+    {
+        $last = end($this->recorded);
+
+        return is_array($last) ? $last['messages'] : null;
+    }
+}
diff --git a/app/Services/AI/Testing/CannedPromptFakeRegistrar.php b/app/Services/AI/Testing/CannedPromptFakeRegistrar.php
new file mode 100644
index 0000000..58fea60
--- /dev/null
+++ b/app/Services/AI/Testing/CannedPromptFakeRegistrar.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\AI\Testing;
+
+use Kent013\PrismPrompt\Prompt;
+
+/**
+ * canned PromptFake の install/uninstall を単一箇所に封じ込める。
+ *
+ * `laravel-prism-prompt` が提供する `Prompt::installFake(PromptFake)` 公開 API を使う。
+ * 将来この API が変わった場合も影響範囲はここだけ。
+ *
+ * Browser lane (tests/Pest.php) と bughunt 実行時 (FakeExternalsServiceProvider::boot) の
+ * 両方から共有される (Browser 専用ではない)。
+ */
+final class CannedPromptFakeRegistrar
+{
+    public function __construct(private readonly CannedPromptResponses $responses) {}
+
+    public function install(): void
+    {
+        Prompt::installFake(new CannedPromptFake($this->responses));
+    }
+
+    public function uninstall(): void
+    {
+        Prompt::stopFaking();
+    }
+}
diff --git a/app/Services/AI/Testing/CannedPromptResponses.php b/app/Services/AI/Testing/CannedPromptResponses.php
new file mode 100644
index 0000000..cc952a8
--- /dev/null
+++ b/app/Services/AI/Testing/CannedPromptResponses.php
@@ -0,0 +1,162 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\AI\Testing;
+
+use Kent013\PrismPrompt\Testing\TextResponseFake;
+use Prism\Prism\Contracts\Message;
+use Prism\Prism\ValueObjects\Messages\SystemMessage;
+use RuntimeException;
+use Webmozart\Assert\Assert;
+
+/**
+ * canned response の定義と SystemMessage signature による解決。
+ *
+ * 全実プロンプトは Prompt::load 経由で generic TextPrompt を返すため、
+ * PromptFake::record() のキー (static::class) は TextPrompt::class に潰れる。
+ * よってクラス名ではなく system_prompt の役割文 (signature) で canned を返し分ける。
+ * signature は各 YAML 固有の一意句 (DefensiveInstructions preamble は全 YAML 共通なので使わない)。
+ *
+ * 未一致 (0 件) / 曖昧 (2 件以上) はいずれも fail-fast で例外を投げ、silent false-positive を防ぐ。
+ * Browser lane (tests/Pest.php) と bughunt 実行時 (FakeExternalsServiceProvider) の双方で共有される。
+ */
+final class CannedPromptResponses
+{
+    /**
+     * SystemMessage の内容から一意な signature を引いて canned を返す。
+     *
+     * @param  array<int, Message>  $messages
+     */
+    public function forMessages(array $messages): TextResponseFake
+    {
+        $systemText = $this->systemMessageText($messages);
+
+        $matched = [];
+        foreach ($this->map() as $signature => $canned) {
+            if ($systemText !== '' && str_contains($systemText, $signature)) {
+                $matched[$signature] = $canned;
+            }
+        }
+
+        if (count($matched) !== 1) {
+            $registered = implode(', ', array_keys($this->map()));
+            $collided = implode(', ', array_keys($matched));
+            $snippet = mb_substr($systemText, 0, 200);
+            throw new RuntimeException(sprintf(
+                'CannedPromptResponses could not uniquely resolve a canned response '
+                ."(matched %d signatures: [%s]).\nRegistered signatures: [%s]\n"
+                ."System text (first 200 chars): %s\n"
+                .'Register/adjust one in app/Services/AI/Testing/CannedPromptResponses.php '
+                .'to avoid silent false-positives.',
+                count($matched),
+                $collided === '' ? '(none)' : $collided,
+                $registered,
+                $snippet,
+            ));
+        }
+
+        $canned = array_values($matched)[0];
+        Assert::string($canned);
+
+        return TextResponseFake::make()->withText($canned);
+    }
+
+    /**
+     * @return list<string> 登録済み signature 一覧 (テスト用)
+     */
+    public function supportedSignatures(): array
+    {
+        return array_keys($this->map());
+    }
+
+    /**
+     * @param  array<int, Message>  $messages
+     */
+    private function systemMessageText(array $messages): string
+    {
+        $parts = [];
+        foreach ($messages as $message) {
+            if ($message instanceof SystemMessage) {
+                $parts[] = $message->content;
+            }
+        }
+
+        return implode("\n", $parts);
+    }
+
+    /**
+     * signature (system_prompt 固有の一意句) => canned response (決定論)。
+     *
+     * @return array<string, string>
+     */
+    private function map(): array
+    {
+        return [
+            '作業手順書 (SOP) を構造化するエキスパート' => self::sopExtractCanned(),
+            '作業標準化エキスパート' => self::workDecompositionCanned(),
+            'マニュアル動画の演出家' => self::scenarioGenerationCanned(),
+            'テキストを 1 文に要約するアシスタント' => self::exampleSummaryCanned(),
+        ];
+    }
+
+    // ---- canned 応答 (各 DTO の fromLlmText を通過する最小妥当 JSON) ----
+
+    /** sop-extract: ExtractedSopData::fromLlmText を通過 (header + 1 section + 1 step) */
+    private static function sopExtractCanned(): string
+    {
+        return json_encode([
+            'header' => ['title' => 'bughunt サンプル手順書', 'department' => null, 'revision' => null],
+            'sections' => [[
+                'title' => null,
+                'steps' => [[
+                    'no' => 1,
+                    'work_process' => 'バルブを閉じる',
+                    'work_points' => ['ハンドルを時計回りに回す'],
+                    'safety_points' => ['保護手袋を着用する'],
+                    'quality_points' => [],
+                    'pm_points' => [],
+                ]],
+            ]],
+        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    }
+
+    /** work-decomposition: WorkDecompositionData::fromLlmText を通過 (1 step / points 1) */
+    private static function workDecompositionCanned(): string
+    {
+        return json_encode([
+            'steps' => [[
+                'no' => 1,
+                'action' => 'バルブを閉じる',
+                'points' => ['ハンドルが止まるまで回す'],
+            ]],
+        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    }
+
+    /** scenario-generation: GeneratedScenarioData::fromLlmText を通過 (step→それを参照する point) */
+    private static function scenarioGenerationCanned(): string
+    {
+        return json_encode([
+            'cuts' => [
+                [
+                    'no' => 1, 'type' => 'step', 'parent_no' => null,
+                    'scene' => '作業台全体を引きで写す', 'shot_type' => 'hiki',
+                    'shooting_point' => null, 'narration' => 'バルブを閉じます。',
+                    'subtitle_primary' => 'バルブ閉', 'subtitle_secondary' => '',
+                ],
+                [
+                    'no' => 2, 'type' => 'point', 'parent_no' => 1,
+                    'scene' => 'ハンドル操作を寄りで写す', 'shot_type' => 'yori',
+                    'shooting_point' => null, 'narration' => 'ハンドルが止まるまで回します。',
+                    'subtitle_primary' => null, 'subtitle_secondary' => '',
+                ],
+            ],
+        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+    }
+
+    /** example-summary: 1 文の要約 (非空 string) */
+    private static function exampleSummaryCanned(): string
+    {
+        return 'テスト/bughunt 共通の固定要約文です。';
+    }
+}
diff --git a/docs/testing-browser.md b/docs/testing-browser.md
index 574d45e..e37815e 100644
--- a/docs/testing-browser.md
+++ b/docs/testing-browser.md
@@ -46,7 +46,7 @@ ### 前提
 ## テストの書き方
 
 `tests/Browser/` 配下に置く。suite の配線は `tests/Pest.php` の Browser lane
-(TestCase + RefreshDatabase + StrayLlmCallGuard + BrowserPromptFake) と
+(TestCase + RefreshDatabase + StrayLlmCallGuard + CannedPromptFake) と
 `phpunit.browser.xml` が担う。既定 `phpunit.xml` の testsuite には含まれないため、
 `composer test` からは実行されない。
 
@@ -83,9 +83,10 @@ ## LLM fake (in-process)
 
 1. **StrayLlmCallGuard** (Feature/Unit と共通): 未 fake の LLM 呼び出しは accumulator に
    記録され afterEach で fail する。
-2. **BrowserPromptFake** (`app/Services/AI/Testing/`): `Prompt` 実行を prompt class 単位の
-   決定論 canned response に差し替える (sequence 枯渇しない無限供給)。
-   `BrowserPromptFakeRegistrar` が `Prompt::installFake()` で beforeEach ごとにインストールする。
+2. **CannedPromptFake** (`app/Services/AI/Testing/`): `Prompt` 実行を SystemMessage の役割文
+   (signature) 単位の決定論 canned response に差し替える (sequence 枯渇しない無限供給)。
+   `CannedPromptFakeRegistrar` が `Prompt::installFake()` で beforeEach ごとにインストールする。
+   この canned 機構は bughunt 実行時 (`FakeExternalsServiceProvider::boot`) とも共有される。
 
 さらに `phpunit.browser.xml` が LLM provider API キーをダミー値で `<server force>` する
 (guard が万一無効化された場合の最終防壁。phpunit.xml と同じ 3 プロバイダ)。
@@ -93,8 +94,10 @@ ## LLM fake (in-process)
 ### canned response の追加
 
 新しい Prompt を Browser テストから呼ぶ場合、
-`app/Services/AI/Testing/BrowserCannedResponses.php` の `map()` に 1 行追加する。
-未登録の Prompt から呼ばれると即 `RuntimeException` で fail-fast する (silent green 防止)。
+`app/Services/AI/Testing/CannedPromptResponses.php` の `map()` に
+「system_prompt 固有の一意句 (signature) => canned response」を 1 行追加する。
+どの signature にも一致しない (0 件) / 複数一致 (2 件以上) の Prompt から呼ばれると即
+`RuntimeException` で fail-fast する (silent green 防止)。
 
 キーの注意: `Prompt::load()` を使う factory (例: `App\Prompts\ExampleSummaryPrompt`) は
 generic な `TextPrompt` を実行するため、記録される prompt class は `TextPrompt::class` になる。
diff --git a/phpunit.browser.xml b/phpunit.browser.xml
index bf5b719..7f980cc 100644
--- a/phpunit.browser.xml
+++ b/phpunit.browser.xml
@@ -46,7 +46,7 @@
         <server name="QUEUE_CONNECTION" value="sync" force="true"/>
         <server name="SESSION_DRIVER" value="array" force="true"/>
         <!--
-          LLM 実通信遮断 (二層防御)。主防御は StrayLlmCallGuard + BrowserPromptFake
+          LLM 実通信遮断 (二層防御)。主防御は StrayLlmCallGuard + CannedPromptFake
           (tests/Pest.php の Browser lane)。新 LLM provider 導入時は phpunit.xml と
           あわせてここにもダミー値を追加すること。
         -->
diff --git a/tests/Feature/Llm/CannedAnalysisPipelineTest.php b/tests/Feature/Llm/CannedAnalysisPipelineTest.php
new file mode 100644
index 0000000..3f1dd21
--- /dev/null
+++ b/tests/Feature/Llm/CannedAnalysisPipelineTest.php
@@ -0,0 +1,73 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\CutType;
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\AnalysisJob;
+use App\Models\Project;
+use App\Models\SourceDocument;
+use App\Models\VideoManual;
+use App\Services\AI\Testing\CannedPromptFakeRegistrar;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Manual\AnalysisPipeline;
+use Illuminate\Support\Facades\Http;
+use Illuminate\Support\Facades\Storage;
+use Kent013\PrismPrompt\Prompt;
+
+/*
+ * AI 解析 end-to-end 統合テスト (bughunt-llm-fake-wiring 施策 5-5):
+ * bughunt 実行時の配線 (CannedPromptFakeRegistrar::install) 下で AnalysisPipeline を
+ * 完走させ、3 段 (sop-extract → work-decomposition → scenario-generation) がすべて
+ * canned で解決されて cuts が materialize されることを検証する (実 API 未到達 = stray 0)。
+ */
+
+beforeEach(function (): void {
+    // 万一の FX 解決 HTTP を stray にしない防御 (fake 分岐は event 非発火の想定)。
+    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
+});
+
+afterEach(function (): void {
+    Prompt::stopFaking();
+});
+
+test('canned fake 配線下で AnalysisPipeline が succeeded し cuts (step+point) が materialize される', function (): void {
+    Storage::fake();
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'analyzing']);
+    $path = "projects/{$project->id}/manuals/{$manual->id}/source-documents/sop.txt";
+    Storage::put($path, str_repeat("手順: バルブを閉じる。急所: ハンドルが止まるまで回す。\n", 5));
+    $document = SourceDocument::factory()->forManual($manual)->create([
+        'file_path' => $path,
+        'mime' => 'text/plain',
+    ]);
+    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
+    app(TicketLedgerService::class)->grant($organization, 1, 'テスト残高');
+
+    // bughunt 実行時 (FakeExternalsServiceProvider::boot) と同一の install 経路。
+    app(CannedPromptFakeRegistrar::class)->install();
+    app(AnalysisPipeline::class)->run($job->id);
+
+    // ジョブ succeeded (3 段すべて canned で解決 = 実 API 未到達)。
+    $job->refresh();
+    expect($job->status)->toBe(JobStatus::Succeeded);
+    expect($job->error)->toBeNull();
+
+    // manual: cuts ツリー (step 1 + point 1) / ready。
+    $manual->refresh();
+    expect($manual->status)->toBe(VideoManualStatus::Ready);
+    $cuts = $manual->cuts()->get();
+    expect($cuts)->toHaveCount(2);
+    $step = $cuts->firstWhere('type', CutType::Step);
+    $point = $cuts->firstWhere('type', CutType::Point);
+    expect($step)->not->toBeNull();
+    expect($point)->not->toBeNull();
+    expect($point->parent_cut_id)->toBe($step->id);
+
+    // 実 LLM provider へは 1 度も到達していない (fake の recorded に 3 段が記録されている)。
+    $fake = Prompt::getFake();
+    expect($fake)->not->toBeNull();
+    expect($fake?->recorded())->toHaveCount(3);
+});
diff --git a/tests/Feature/Llm/CannedPromptResponsesTest.php b/tests/Feature/Llm/CannedPromptResponsesTest.php
new file mode 100644
index 0000000..6c4dcd5
--- /dev/null
+++ b/tests/Feature/Llm/CannedPromptResponsesTest.php
@@ -0,0 +1,191 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Manual\Analysis\ExtractedSopData;
+use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
+use App\DataTransferObjects\Manual\Analysis\WorkDecompositionData;
+use App\Prompts\ExampleSummaryPrompt;
+use App\Prompts\ScenarioGenerationPrompt;
+use App\Prompts\SopExtractPrompt;
+use App\Prompts\WorkDecompositionPrompt;
+use App\Services\AI\Testing\CannedPromptFakeRegistrar;
+use App\Services\AI\Testing\CannedPromptResponses;
+use Illuminate\Support\Facades\Http;
+use Kent013\PrismPrompt\Prompt;
+use Kent013\PrismPrompt\TextPrompt;
+use Prism\Prism\Contracts\Message;
+use Prism\Prism\ValueObjects\Messages\SystemMessage;
+use Webmozart\Assert\Assert;
+
+/*
+ * CannedPromptResponses (bughunt-llm-fake-wiring 施策 1/2/5):
+ * - 各実 factory の canned が該当 DTO の fromLlmText を通過する (主保証)
+ * - 登録 prompt allowlist に対し signature がちょうど 1 件一致する (1:1 対応の固定)
+ * - 未登録 (0 件) / 曖昧 (2 件以上) は fail-fast で例外 (silent false-positive 防止)
+ */
+
+beforeEach(function (): void {
+    // executeSync の fake 分岐は PromptExecutionCompleted を発火しない想定だが、
+    // 万一 listener が FX 解決 (HTTP) を試みても stray request にしないよう防御的に fake する。
+    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
+    app(CannedPromptFakeRegistrar::class)->install();
+});
+
+afterEach(function (): void {
+    Prompt::stopFaking();
+});
+
+/**
+ * 登録済み prompt を 1 回だけ実行し、record された messages を capture する。
+ * vendor 公開 API recorded() のみに依存する (reflection / protected には触れない)。
+ *
+ * @param  Closure(): mixed  $runOnce
+ * @return array<int, Message>
+ */
+function captureMessages(Closure $runOnce): array
+{
+    // install() で fresh な CannedPromptFake に差し替え、recorded を空から始める。
+    app(CannedPromptFakeRegistrar::class)->install();
+    $runOnce();
+
+    $fake = Prompt::getFake();
+    Assert::notNull($fake);
+    $recorded = $fake->recorded();
+    // 1 ケース 1 実行のため record は厳密に 1 件 (順序・混入の余地なし)。
+    Assert::count($recorded, 1);
+    $messages = $recorded[0]['messages'];
+    Assert::isArray($messages);
+    Assert::allIsInstanceOf($messages, Message::class);
+
+    return $messages;
+}
+
+/**
+ * messages の SystemMessage 本文を連結する (signature 判定対象)。
+ *
+ * @param  array<int, Message>  $messages
+ */
+function systemTextOf(array $messages): string
+{
+    $parts = [];
+    foreach ($messages as $message) {
+        if ($message instanceof SystemMessage) {
+            $parts[] = $message->content;
+        }
+    }
+
+    return implode("\n", $parts);
+}
+
+/** 登録済み prompt allowlist (key => [factory 実体, 期待 signature]) */
+function makeRegisteredPrompt(string $key): TextPrompt
+{
+    return match ($key) {
+        'sop-extract' => SopExtractPrompt::make('サンプル SOP'),
+        'work-decomposition' => WorkDecompositionPrompt::make('{"header":{},"sections":[]}'),
+        'scenario-generation' => ScenarioGenerationPrompt::make('{"steps":[]}'),
+        'example-summary' => ExampleSummaryPrompt::make('本文'),
+        default => throw new InvalidArgumentException("unknown prompt key: {$key}"),
+    };
+}
+
+// ---- 5-1: canned DTO 通過テスト (主保証) ----
+
+test('sop-extract の canned が ExtractedSopData::fromLlmText を通過する', function (): void {
+    $text = SopExtractPrompt::make('サンプル SOP')->executeSync();
+    Assert::string($text);
+
+    $dto = ExtractedSopData::fromLlmText($text);
+    expect($dto->sections)->not->toBeEmpty();
+    expect($dto->sections[0]['steps'])->toHaveCount(1);
+});
+
+test('work-decomposition の canned が WorkDecompositionData::fromLlmText を通過する', function (): void {
+    $text = WorkDecompositionPrompt::make('{"header":{},"sections":[]}')->executeSync();
+    Assert::string($text);
+
+    $dto = WorkDecompositionData::fromLlmText($text);
+    expect($dto->steps)->toHaveCount(1);
+});
+
+test('scenario-generation の canned が GeneratedScenarioData::fromLlmText を通過する', function (): void {
+    $text = ScenarioGenerationPrompt::make('{"steps":[]}')->executeSync();
+    Assert::string($text);
+
+    $dto = GeneratedScenarioData::fromLlmText($text);
+    // step 1 + それを参照する point 1 (materialize で step→points ツリーになる)
+    expect($dto->steps)->toHaveCount(1);
+    expect($dto->steps[0]->points)->toHaveCount(1);
+});
+
+test('example-summary の canned は非空 string を返す', function (): void {
+    $text = ExampleSummaryPrompt::make('本文')->executeSync();
+    expect($text)->toBeString();
+    expect(trim((string) $text))->not->toBe('');
+});
+
+// ---- 5-2: signature 衝突防止テスト (登録 prompt allowlist に対する 1:1) ----
+
+test('登録 prompt はちょうど 1 signature に一致し、それが期待どおり', function (string $key, string $expected): void {
+    $messages = captureMessages(fn () => makeRegisteredPrompt($key)->executeSync());
+    $systemText = systemTextOf($messages);
+
+    $signatures = app(CannedPromptResponses::class)->supportedSignatures();
+    $matched = array_values(array_filter(
+        $signatures,
+        static fn (string $signature): bool => str_contains($systemText, $signature),
+    ));
+
+    expect($matched)->toBe([$expected]);
+})->with([
+    'sop-extract' => ['sop-extract', '作業手順書 (SOP) を構造化するエキスパート'],
+    'work-decomposition' => ['work-decomposition', '作業標準化エキスパート'],
+    'scenario-generation' => ['scenario-generation', 'マニュアル動画の演出家'],
+    'example-summary' => ['example-summary', 'テキストを 1 文に要約するアシスタント'],
+]);
+
+test('signature はペアワイズで非部分包含 (将来 signature 追加時の衝突を静的に防止)', function (): void {
+    $signatures = app(CannedPromptResponses::class)->supportedSignatures();
+
+    foreach ($signatures as $a) {
+        foreach ($signatures as $b) {
+            if ($a === $b) {
+                continue;
+            }
+            expect(str_contains($a, $b))->toBeFalse(
+                "signature '{$b}' が signature '{$a}' の部分文字列になっています",
+            );
+        }
+    }
+});
+
+// ---- 5-3: 未登録 / 曖昧 prompt fail-fast テスト ----
+
+test('未登録 (0 件一致) の SystemMessage は fail-fast する', function (): void {
+    $messages = [new SystemMessage('未知の役割')];
+
+    expect(fn () => app(CannedPromptResponses::class)->forMessages($messages))
+        ->toThrow(RuntimeException::class);
+});
+
+test('曖昧 (2 件一致) の SystemMessage は fail-fast する', function (): void {
+    $messages = [new SystemMessage('作業標準化エキスパート かつ マニュアル動画の演出家')];
+
+    expect(fn () => app(CannedPromptResponses::class)->forMessages($messages))
+        ->toThrow(RuntimeException::class);
+});
+
+test('fail-fast の例外メッセージに system text 先頭と一致 signature が含まれる (調査性)', function (): void {
+    $systemText = '作業標準化エキスパート と マニュアル動画の演出家';
+
+    try {
+        app(CannedPromptResponses::class)->forMessages([new SystemMessage($systemText)]);
+        // 到達しない (上で必ず throw する)
+        expect(false)->toBeTrue();
+    } catch (RuntimeException $exception) {
+        expect($exception->getMessage())->toContain(mb_substr($systemText, 0, 200));
+        expect($exception->getMessage())->toContain('作業標準化エキスパート');
+        expect($exception->getMessage())->toContain('マニュアル動画の演出家');
+    }
+});
diff --git a/tests/Feature/Providers/FakeExternalsServiceProviderTest.php b/tests/Feature/Providers/FakeExternalsServiceProviderTest.php
index 20600a3..0c17fd7 100644
--- a/tests/Feature/Providers/FakeExternalsServiceProviderTest.php
+++ b/tests/Feature/Providers/FakeExternalsServiceProviderTest.php
@@ -2,6 +2,7 @@
 
 declare(strict_types=1);
 
+use App\Prompts\ExampleSummaryPrompt;
 use App\Providers\FakeExternalsServiceProvider;
 use App\Services\Billing\CashierSubscriptionCheckoutGateway;
 use App\Services\Billing\CashierTicketCheckoutGateway;
@@ -9,14 +10,25 @@
 use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
 use App\Services\Billing\SubscriptionCheckoutGateway;
 use App\Services\Billing\TicketCheckoutGateway;
+use Illuminate\Support\Facades\Http;
 use Illuminate\Support\Facades\Log;
+use Kent013\PrismPrompt\Prompt;
 
 /*
  * FakeExternalsServiceProvider: config('testing.fake_externals') が capability flag。
  * fail-secure 二軸 (flag 既定 false = 完全 no-op / 環境 allowlist) を固定する。
  * Pest はテスト毎に app を再構築するため register() 再実行の container 汚染は漏れない。
+ *
+ * boot() は LLM (Prism) fake を配線する。Prompt::$fake は static (プロセスグローバル) のため
+ * allowlist は bughunt.local のみ (testing/local は除外)。static リークを避けるため
+ * afterEach で必ず stopFaking する (テスト本体が例外で落ちても到達させる)。
  */
 
+afterEach(function (): void {
+    // boot() が install した Prompt::$fake (static) を各テスト境界でリークさせない。
+    Prompt::stopFaking();
+});
+
 test('既定 (flag=false) では両 gateway とも Cashier 実装に解決される', function (): void {
     expect(config('testing.fake_externals'))->toBeFalse();
     expect(app(TicketCheckoutGateway::class))->toBeInstanceOf(CashierTicketCheckoutGateway::class);
@@ -47,3 +59,75 @@
     expect(app(SubscriptionCheckoutGateway::class))->toBeInstanceOf(CashierSubscriptionCheckoutGateway::class);
     Log::shouldHaveReceived('warning')->once();
 });
+
+/*
+ * boot(): LLM (Prism) fake の環境 allowlist (bughunt.local のみ)。
+ * 各テストは env と config を try/finally で原値復元する (static/config 汚染を漏らさない)。
+ */
+
+test('boot: env=bughunt.local ∧ flag=true で Prompt fake が有効になり canned を返す', function (): void {
+    // 万一の FX 解決 HTTP を stray にしない防御。
+    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
+
+    $originalEnv = $this->app['env'];
+    $originalFlag = config('testing.fake_externals');
+    try {
+        config(['testing.fake_externals' => true]);
+        $this->app['env'] = 'bughunt.local';
+        (new FakeExternalsServiceProvider($this->app))->boot();
+
+        expect(Prompt::isFaking())->toBeTrue();
+
+        // 代表 prompt が canned を返す (stray call 0 = 実 API 未到達)。
+        $summary = ExampleSummaryPrompt::make('本文')->executeSync();
+        expect($summary)->toBeString();
+        expect(trim((string) $summary))->not->toBe('');
+    } finally {
+        Prompt::stopFaking();
+        config(['testing.fake_externals' => $originalFlag]);
+        $this->app['env'] = $originalEnv;
+    }
+});
+
+test('boot: env=testing ∧ flag=true では Prompt::$fake に触れない (static 占有を避ける)', function (): void {
+    $originalFlag = config('testing.fake_externals');
+    try {
+        // env は既定の testing のまま。
+        config(['testing.fake_externals' => true]);
+        (new FakeExternalsServiceProvider($this->app))->boot();
+
+        expect(Prompt::isFaking())->toBeFalse();
+    } finally {
+        config(['testing.fake_externals' => $originalFlag]);
+    }
+});
+
+test('boot: env=local ∧ flag=true では Prompt::$fake に触れない (実 API 検証を潰さない)', function (): void {
+    $originalEnv = $this->app['env'];
+    $originalFlag = config('testing.fake_externals');
+    try {
+        config(['testing.fake_externals' => true]);
+        $this->app['env'] = 'local';
+        (new FakeExternalsServiceProvider($this->app))->boot();
+
+        expect(Prompt::isFaking())->toBeFalse();
+    } finally {
+        config(['testing.fake_externals' => $originalFlag]);
+        $this->app['env'] = $originalEnv;
+    }
+});
+
+test('boot: flag=false では bughunt.local でも Prompt fake を配線しない (完全 no-op)', function (): void {
+    $originalEnv = $this->app['env'];
+    $originalFlag = config('testing.fake_externals');
+    try {
+        config(['testing.fake_externals' => false]);
+        $this->app['env'] = 'bughunt.local';
+        (new FakeExternalsServiceProvider($this->app))->boot();
+
+        expect(Prompt::isFaking())->toBeFalse();
+    } finally {
+        config(['testing.fake_externals' => $originalFlag]);
+        $this->app['env'] = $originalEnv;
+    }
+});
diff --git a/tests/Pest.php b/tests/Pest.php
index 2255566..935026f 100644
--- a/tests/Pest.php
+++ b/tests/Pest.php
@@ -8,7 +8,7 @@
 use App\Models\Organization;
 use App\Models\Project;
 use App\Models\User;
-use App\Services\AI\Testing\BrowserPromptFakeRegistrar;
+use App\Services\AI\Testing\CannedPromptFakeRegistrar;
 use App\Services\Organization\OrganizationProvisioningService;
 use Illuminate\Foundation\Testing\RefreshDatabase;
 use Illuminate\Support\Facades\Vite;
@@ -84,10 +84,11 @@
         // accumulator に記録され afterEach で fail する)。
         StrayLlmCallGuard::install($this->app);
 
-        // Browser lane は Prompt を常時 canned fake 化する (クラス別の決定論応答。
-        // 未登録の Prompt から呼ばれると fail-fast)。install() 内の stopFaking の
-        // 後に上書きインストールするのが load-bearing。
-        app(BrowserPromptFakeRegistrar::class)->install();
+        // Browser lane は Prompt を常時 canned fake 化する (SystemMessage signature 別の
+        // 決定論応答。未登録の Prompt から呼ばれると fail-fast)。canned PromptFake は
+        // Browser lane と bughunt 実行時の両方で共有 (registrar 参照)。install() 内の
+        // stopFaking の後に上書きインストールするのが load-bearing。
+        app(CannedPromptFakeRegistrar::class)->install();
     })
     ->afterEach(function (): void {
         try {

```
