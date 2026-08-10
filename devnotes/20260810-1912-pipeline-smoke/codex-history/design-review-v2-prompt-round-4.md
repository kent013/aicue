# Round 4: Round 2/3 指摘への対応 (残件は文言統一のみ)

Round 3 で挙がった 2 つの文言修正と 1 つの実装注意 (whereIn での母集団限定) に対応した。
production の機構・テスト件数ともに増減なし。

Round 3 の最後で「この 2 箇所を直せば全体判定は APPROVED」と言われた箇所である。
修正の反映漏れが無いかを確認してほしい (特に「SQL 関数」に言及している 4 箇所の統一)。

判定は施策ごとに APPROVE / REQUEST_CHANGES を付け、最後に全体判定
(APPROVED / CHANGES_REQUESTED) を書くこと。

---

# 添付 1: 対応マトリクス

# 対応マトリクス: design-review-v2 Round 3

## [Warning] 施策 2: 「改訂の記録」に「素の列 GROUP BY (= index に乗る)」の断定が残っている
- 判断: **対応する**
- 根拠: Round 2 で enum 側だけ直し、改訂の記録の修正が漏れていた。素の列であることから
  index が効くとは断定できない (指摘どおり)。
- 対応内容: 「**GROUP BY キーへの SQL 関数適用**・driver 差・UTC 日境界の注記とそのテストが
  まるごと消え、全軸が素の列 GROUP BY になる」へ差し替えた (index への言及を削除)。

## [Warning] 施策 2: 「SQL 関数をゼロにした」は COALESCE 導入後は文字どおり成立しない
- 判断: **対応する**
- 根拠: 指摘どおり。集計値側では `COUNT` / `SUM` / `COALESCE` を使う。
  意図は「GROUP BY キーへ適用する SQL 関数がゼロ」であり、表現が意図を超えていた。
- 対応内容: 該当 4 箇所 (改訂の記録 / enum docblock / 施策 2 リスク欄 / 最終確認表) を
  **「GROUP BY キーへ適用する SQL 関数がゼロ (集計値側では COUNT / SUM / COALESCE を使う)」**
  へ統一した。

## [Suggestion] 施策 6: `llmRecordingIncomplete()` の入力を required template に限定すべき
- 判断: **対応する** (指摘どおり。新しい引数も検査も足さない)
- 根拠: 対象外の template が混ざると `array_diff($succeeded, $attributed)` が
  本 smoke と無関係な行まで「不完全」と判定する。実害がある。
- 対応内容:
  1. `llm-evidence` 段の母集団定義に
     **`whereIn('prompt_template', [3 template])`** を明記した
     (「他の prompt が同 shard で走っても混ざらない」)
  2. 純関数の docblock に「呼び出し側の責務: required に限定した集合を渡すこと。
     クエリ側で母集団を絞るのが最小の対処であり、**追加の引数も検査も足さない**」と明記
  3. 導出表の前提にも「呼び出し側は同じ集合で `whereIn` している前提」と追記

## 全体
Round 3 の指摘はすべて文言の統一と 1 つの実装注意であり、機構の増減は無い。すべて受け入れた。


---

# 添付 2: 修正後の詳細設計 全文

# 詳細設計: pipeline-smoke (パイプライン通し確認 + LLM コストレポート)

## 改訂の記録 (v2 / 2026-08-10)

**v1 (Codex 詳細レビュー Round 4 で APPROVED) をオーナー指示により差し戻し、作り直した版である。**
v1 の判断は「削った」ものも含めて本節に残す (消さない)。

> オーナー指示 (recon-brief 末尾「その 2」):
> 「集計システムはそんなにデカくなくていい。DB に入るから、ちゃんと設定して集計するだけ。
>  オーバーエンジニアリングにならないように。統一的なレポートの仕組みを作って還流させて。」

### 何を足したか

| 追加 | 理由 |
|---|---|
| **施策 1: LLM 呼び出しの帰属メタデータ (`withMetadata()`) 配線** | v1 は「`withMetadata()` 未呼び出し」を**スコープ外**とし、`metadata_missing` 件数を出して可視化するに留めた。**順序が逆だった。** 記録側が組織・対象を落としている状態では集計軸が「段」と「モデル」しか出ず、運用で本当に知りたい「どの組織が / どの対象が いくら使ったか」が永久に出ない。集計を薄くできるのは**記録が正しく入っているから**であって、集計層で取り繕う話ではない |
| **集計軸 `subject` (多態の対象)** | 上の帰属が入って初めて意味を持つ軸。かつ他リポジトリでも意味が通る (morph なので aicue の「マニュアル」に縛られない) |
| **smoke の `llm-evidence` 段に帰属の検査を追加** | 施策 1 の配線が**実 LLM で end-to-end に効いているか**を確かめる唯一の機械的な場所 (理由は施策 1 の「テストの限界」参照) |
| **移植ファイル一覧 (§還流)** | 家系初の実装になるため、渡す物を設計に明記する |

### 何を削ったか (v1 → v2)

| 削ったもの | 削ってよいと判断した根拠 |
|---|---|
| 集計軸 `day` (`date(created_at)` GROUP BY) | **保証が減らない。** 期間の絞り込みは `--since` / `--until` が既に担う。日次推移は「あったら便利」(思考原則 2)。削ることで **GROUP BY キーへの SQL 関数適用・driver 差・UTC 日境界の注記とそのテスト**がまるごと消え、全軸が素の列 GROUP BY になる |
| 行 DTO の `cacheReadInputTokens` / `cacheWriteInputTokens` | **保証が減らない。** キャッシュ分の金額は `total_cost_usd` に既に入っている。トークン内訳は費用把握に不要 |
| 行 DTO の `avgDurationMs` | **保証が減らない。** 所要時間はコストではない。かつ唯一の非加算指標であり、これを消したことで TOTAL 行の意味が「各列の単純和」に揃った |
| 集計サービスの `--group-by=day` 用 driver 分岐の議論 | 上記に伴い消滅 |
| v1 の「レポートは `metadata_missing` を出して組織別集計ができないことを説明する」という**言い訳の設計** | 施策 1 で記録側を直したため不要。`metadata_missing` は**言い訳**ではなく**配線が生きているかの健全性シグナル**として残す (意味が変わった) |

### 何を維持したか (前回の合議で反論して守った判断・オーナーが「変わらない」と言った方針)

- pipeline smoke 本体の方針 (**実 LLM 3 段** / **bug-hunt レーン** / **品質は判定しない** /
  費用の見積り / 連打防止) — v1 のまま
- **1 実装・複数入口** (集計は 1 本。smoke 末尾と期間集計コマンドの 2 入口) — v1 からの方針を維持
- `SmokeFailureClassifier` の判定境界 (Round 3 の 2 つの Critical への対応。`Llm` を
  LLM 起因になり得る段に閉じる / `artifact` の `Storage` と `Render` の 2 分岐 /
  成功段は `null`) — **v1 のまま。触らない**
- fake storage への直接書き込み (案 A) と allowlist 登録の判断 — v1 のまま
- `--run-id` / `--shard` を artisan へ転送しない option 対応表 — v1 のまま
- 予約行の tenant-safe な再解決 — v1 のまま
- **記録層 (`llm_call_logs`) の列を増やさない** — v1 で列追加を提案していないため、そのまま維持

---

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
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
| P4 | **`withMetadata()` の呼び出しはリポジトリ全体で 0 件**。`AnalysisPipeline` の 3 プロンプトも呼ばない → `llm_call_logs.organization_id` / `subject_*` は NULL、`metadata_missing = true` | `grep -rn withMetadata app/ tests/ database/` (`LlmMetadataExtractor` の docblock のみ) |
| P5 | `total_cost_jpy` は `FxRateService`(Frankfurter API) 依存で失敗時 null。`total_cost_usd` は `pricing_snapshot` から決定的 | `app/Services/LlmCallLogWriter.php` / `app/Services/FxRateService.php` |
| P6 | 単価は `claude-sonnet-4-5-20250929` = input $3.00 / output $15.00 per MTok | `config/prism-prompt-pricing.php` |
| P7 | SOP は 100 バイト以上 (`manual.analysis_min_text_bytes`) かつ日本語比率 0.10 以上 (`manual.analysis_min_japanese_ratio`) でないと LLM に渡らず失敗する | `config/manual.php` / `SopTextExtractor` |
| P8 | 解析 1 枚 + レンダ 3 枚 = 4 枚のチケットを消費する。`BughuntBillingSeeder` は 100 枚付与する | `config/manual.php` / `database/seeders/BughuntBillingSeeder.php` |
| P9 | `OrganizationProvisioningService::provision()` は **Project を作らない**。Default Project の定義は「org の先頭 project」 | `app/Services/Project/DefaultProjectResolver.php` |
| P10 | 本番コード (app/ routes/ config/ bootstrap/) は fake クラスを参照できない。例外は allowlist 4 件 | `tests/Architecture/FakeClassReferenceInvariantTest.php` |
| P11 | app/ の `Illuminate\Support\Facades\Http` 参照は `ExternalSeamInventory` の母集団に入り、**閉じた語彙**の `ExternalSeamKind` を 1 つ選んで登録する必要がある | `tests/Support/ExternalSeam/ExternalSeamScanner.php` |
| P12 | `queue.default` は bughunt で `sync` だが、2 つの Job は `onConnection('database-analysis' / 'database-render')` を明示するため DB キュー経由で worker が拾う | `RunManualAnalysis::__construct()` / `RunManualRender::__construct()` |
| **P13** | `Prompt::withMetadata(array $metadata): static` は `metadata_context` に **array_merge するだけ**で、パッケージは中身を解釈しない。値は `PromptExecutionCompleted::$metadata` / `PromptExecutionFailed::$metadata` に**そのまま**流れる | `vendor/.../src/Prompt.php` L216-224 / L768 / L791 / `docs/events-and-cost.md` |
| **P14** | 両 listener が metadata から取り出す**汎用キーは 4 つだけ**: `organization_id` / `user_id` / `subject_type` / `subject_id`。取り出しは `LlmMetadataExtractor` の厳格変換を通る | `app/Listeners/RecordLlmCallCost.php` L72-79 / `RecordLlmCallFailure.php` L44-47 |
| **P15** | `LlmCallLogWriter` の `metadata_missing` 判定は **(organization_id, subject_type, subject_id) の三点セット欠落**。`user_id` は console 実行を考慮して判定に含めない | `app/Services/LlmCallLogWriter.php` |
| **P16** | `llm_call_logs` は既に `subject_type` / `subject_id` (string(64)) と index `(subject_type, subject_id)` / `(organization_id, created_at)` / `(model, created_at)` / `prompt_template` を持つ。**帰属のための列追加は不要** | `database/migrations/2026_06_11_090000_create_llm_call_logs_table.php` |
| **P17** | `Prompt::load()` は docblock `@return TextPrompt`。`withMetadata()` は `static` を返すので `make(): TextPrompt` の戻り型は**変えずに済む** | 同 vendor L113-130 |
| **P18** | `Prompt::$fake` 有効時は `record($promptClass, $messages, $provider, $model)` しか記録されず、**metadata は fake 経路から観測できない** | `vendor/.../src/Testing/PromptFake.php` |
| **P19** | `tests/Architecture/PromptUntrustedInputContractTest.php` が既に **`ReflectionProperty(Prompt::class, 'templateVariables')` で組み立て済み Prompt の内部を検査する**先例を持ち、`app/Prompts/` 全クラスを deny-by-default で inventory 登録させている | 同ファイル |
| **P20** | `app/` 内の LLM 呼び出し点は **`AnalysisPipeline` の 3 箇所のみ** (`ExampleSummaryPrompt` は見本で呼び出し元なし) | `grep -rn "executeSync" app/` |
| **P21** | 家系の他リポジトリに LLM コスト集計レポートの先例は無い (共有されているのは記録層 `llm_call_logs` の語彙まで) | lctl 台帳 (概念設計フェーズで調査済み) |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | **LLM 呼び出しの帰属メタデータ配線 (記録側)** | `app/DataTransferObjects/LlmCallContextData.php` (新) / `app/Prompts/*.php` (3 本) / `app/Services/Manual/AnalysisPipeline.php` / `tests/Architecture/PromptUntrustedInputContractTest.php` | **High** |
| 2 | LLM コスト集計 (薄型) | `app/Enums/LlmCostGroupBy.php` (新) / `app/DataTransferObjects/LlmCost{Row,Report}Data.php` (新) / `app/Services/LlmCostReportService.php` (新) | High |
| 3 | 期間集計コマンド | `app/Console/Commands/Operations/LlmCostReportCommand.php` (新) | Medium |
| 4 | bug-hunt DB 名判定の SSOT を app 側へ昇格 | `app/Support/BughuntDatabaseGuard.php` (新) / `database/seeders/Concerns/DetectsBughuntDatabase.php` | High |
| 5 | ダミー SOP fixture | `resources/fixtures/pipeline-smoke-sop.txt` (新) | High |
| 6 | pipeline smoke コマンド本体 | `app/Console/Commands/Development/PipelineSmokeCommand.php` (新) / `app/DataTransferObjects/Smoke/*.php` (新) / `app/Enums/Smoke/*.php` (新) / `app/Support/Smoke/SmokeFailureClassifier.php` (新) | High |
| 7 | fake 参照 allowlist への登録 | `tests/Architecture/FakeClassReferenceInvariantTest.php` | High |
| 8 | bug-hunt レーンからの起動導線 | `scripts/bug-hunt-shard.sh` | High |
| 9 | ドキュメント追記 | `AGENTS.md` / `docs/architecture.md` / `.claude/skills/app-bug-hunt/SKILL.md` | Medium |
| 10 | テスト | 各施策の欄を参照 | High |

---

## 施策 1: LLM 呼び出しの帰属メタデータ配線 (記録側)

> **これが「ちゃんと設定して集計するだけ」の「ちゃんと設定して」の実体である。**
> 記録層の列は 1 本も増やさない (P16)。増やすと他リポジトリが migration ごと持っていく必要が出る。

### 変更箇所

- 新規: `app/DataTransferObjects/LlmCallContextData.php`
- 変更: `app/Prompts/SopExtractPrompt.php` / `WorkDecompositionPrompt.php` / `ScenarioGenerationPrompt.php`
- 変更: `app/Services/Manual/AnalysisPipeline.php` (3 つの呼び出し点 + context の解決 1 箇所)
- 変更: `tests/Architecture/PromptUntrustedInputContractTest.php` (inventory に帰属層を追加)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし (HTTP 面を持たない)
- migration: **なし** (P16)
- `app/Prompts/ExampleSummaryPrompt.php`: **変更しない** (見本であり呼び出し元が無い。
  inventory 側で「帰属 exempt」として明示登録する。既存の「untrusted 変数が無い prompt は
  空配列で登録する」という exempt 機構と**同じ形**にする)

### なぜ「呼び出し点」ではなく「factory 側」に置くか (設計の中心判断)

`withMetadata()` を `AnalysisPipeline` の呼び出し点で 3 回書く形も可能だが、**factory 側に置く**。

1. **禁止事項 5 が「LLM 呼び出しは `app/Prompts/` の factory 経由のみ」を既に強制している。**
   したがって factory が metadata を付ければ、**帰属を迂回する経路が構造的に存在しない**。
   将来 prompt が増えても、呼び出し側の書き忘れで帰属が落ちることがない
2. **必須引数にすれば PHPStan level 10 が「付け忘れ」をコンパイル時に落とす。**
   ソーススキャナ (「`::make(` の直後に `->withMetadata(` があるか」を token 走査する
   Architecture テスト) を新設する案は**採らない** — 必須引数で型が保証する事実を
   スキャナで再検査するのは機構の二重化であり、チェーン記述の揺れに弱い
3. 既存の inventory テスト (P19) が `app/Prompts/` を deny-by-default で走査しているので、
   **新しい機構を作らずに** 帰属の検査層をそこへ足せる

### 変更後コード

```php
// app/DataTransferObjects/LlmCallContextData.php (新規)
namespace App\DataTransferObjects;

use Illuminate\Database\Eloquent\Model;

/**
 * LLM 呼び出しの**帰属コンテキスト**。`Prompt::withMetadata()` へ渡す 4 つの汎用キー
 * (organization_id / user_id / subject_type / subject_id) の値オブジェクト。
 *
 * ★ ここにアプリ固有の語彙を持ち込まない。subject は多態 (Model なら何でもよい) で持つ。
 *   これは記録層 (llm_call_logs) と listener (P14) が既に持っている契約そのものであり、
 *   本 DTO はその契約を**呼び出し側から型で守る**ためだけに存在する。
 * ★ organization / subject が null でも構築できる (console 実行など帰属が無い呼び出しがある)。
 *   欠落は LlmCallLogWriter が metadata_missing = true として記録し (P15)、
 *   コストレポートが件数として可視化する (施策 2)。
 */
final readonly class LlmCallContextData
{
    private function __construct(
        public ?int $organizationId,
        public ?int $userId,
        public ?string $subjectType,
        public ?string $subjectId,
    ) {}

    /**
     * subject は Eloquent Model から解決する。型名は **getMorphClass()** を使う
     * (morph map を設定しているリポジトリでもそのまま移植できる)。
     */
    public static function for(?int $organizationId, ?Model $subject, ?int $userId = null): self
    {
        return new self(
            organizationId: $organizationId,
            userId: $userId,
            subjectType: $subject?->getMorphClass(),
            subjectId: $subject === null ? null : (string) $subject->getKey(),
        );
    }

    /** 帰属が無い呼び出し (見本 / 運用スクリプト等) を**明示**するための名前付き構築子。 */
    public static function none(): self
    {
        return new self(null, null, null, null);
    }

    /**
     * withMetadata() へ渡す配列。**null のキーは落とす**
     * (LlmMetadataExtractor は isset() で判定するため、null を入れても入れなくても
     *  結果は同じだが、イベント payload に意味のない null を載せない)。
     *
     * @return array<string, int|string>
     */
    public function toMetadata(): array
    {
        return array_filter([
            'organization_id' => $this->organizationId,
            'user_id' => $this->userId,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
        ], static fn (int|string|null $v): bool => $v !== null);
    }
}
```

```php
// app/Prompts/SopExtractPrompt.php (他 2 本も同型)
public static function make(string $untrustedSopText, LlmCallContextData $context): TextPrompt
{
    return Prompt::load('sop-extract', [
        'text' => UserInput::from($untrustedSopText), // 不変条件 4: untrusted は UserInput
    ])->withMetadata($context->toMetadata());          // 帰属: llm_call_logs の organization/subject
}
```

- `Prompt::load()` は `@return TextPrompt` (P17)、`withMetadata()` は `static` を返すので
  **戻り型 `TextPrompt` は変わらない**。PHPStan の追加注釈は不要

```php
// app/Services/Manual/AnalysisPipeline.php
// run() 内、startJob() が true を返した直後 (= 実際に走る担当だと確定した後) に 1 度だけ解決する。
$context = LlmCallContextData::for(
    $this->resolveOrganization($job)->id,   // 既存の private メソッドをそのまま使う
    $job->videoManual,                      // subject = 対象マニュアル (多態で持つ)
    $job->triggered_by,                     // actor (null 可)
);
```

- `$context` は `runExtractStep` / `runDecomposeStep` / `runGenerateStep` へ**引数で**渡す
  (パイプラインを stateful にしない)。3 つの `withBoundedRetry` closure が capture する
- **リトライしても同じ context が使われる** = 再試行で発生した失敗行 (`RecordLlmCallFailure`) にも
  同じ帰属が付く。これは望ましい (「この対象に何回失敗したか」が組織・対象で引けるようになる)
- `resolveOrganization()` は `project->organization` (HasOneThrough) を 1 回引くだけ。
  解析 1 本につき 1 クエリ増える (LLM 3 回に対して無視できる)

### なぜ subject を「マニュアル」にするか

`AnalysisJob` ではなく `VideoManual` を subject にする。理由: 費用を知りたい単位は
**成果物 (マニュアル)** であって job ではない。再解析すれば job は増えるが、
「このマニュアルに合計いくらかけたか」が引けるのが運用の要求である。
**なお集計層はこの判断を一切知らない** — 集計層が見るのは `subject_type` / `subject_id` の 2 列だけ。

### テストの限界 (誇張しない。範囲を正確に書く)

テストレーンで**検証できる**のは「factory が組み立てた Prompt の `metadata_context` に
帰属キーが入っていること」までである (reflection。P19 の先例と同型)。

テストレーンで**検証できない**のは「その `metadata_context` がイベントへ流れ、
listener 経由で `llm_call_logs` の `organization_id` / `subject_*` として記録されること」である。
`Prompt::$fake` は `executePrism()` の先頭で短絡してイベントを発火せず (P3)、
`PromptFake::record()` は metadata を記録しない (P18)。したがって
「fake で回して `llm_call_logs` の `organization_id` を assert する」は**原理的に書けない**。

そこで 3 層で固定する:

| 層 | 何を固定するか | 場所 |
|---|---|---|
| 型 | factory は context 無しでは呼べない (付け忘れが PHPStan で落ちる) | PHP の必須引数 + `composer phpstan` |
| 構造 | **factory が組み立てた Prompt の `metadata_context` に帰属キーが入っている** | `PromptUntrustedInputContractTest` (reflection。P19 の先例と同型) — **テストレーンで検証できる** |
| 実地 | イベント → listener → `llm_call_logs` まで流れ、組織 / 対象が入り `metadata_missing = false` になる | **施策 6 の `llm-evidence` 段** (bug-hunt レーン) — **ここだけ**はテストレーンで代替できない |

3 層目が本 devnote の smoke そのものである点が重要で、
**「記録を直す」と「通し確認する」が同じ 1 回の実行で閉じる**。

### `PromptUntrustedInputContractTest` への追加 (既存機構の拡張。新規機構を作らない)

inventory の各エントリに「期待する帰属キー集合」を足す。exempt は空配列で明示する
(既存の「untrusted 変数が無い prompt は空配列」と同じ流儀)。

```php
/** @return array<class-string, array{list<string>, list<string>, Closure(): Prompt}> */
function promptUntrustedInputInventory(): array
{
    $context = LlmCallContextData::for(7, VideoManual::factory()->makeOne(['id' => 42]), 3);

    return [
        // 見本 prompt。呼び出し元が無く帰属の対象も無いので exempt (空配列で明示)
        ExampleSummaryPrompt::class => [
            ['text'], [],
            fn (): Prompt => ExampleSummaryPrompt::make('untrusted end-user text'),
        ],
        SopExtractPrompt::class => [
            ['text'], ['organization_id', 'subject_type', 'subject_id'],
            fn (): Prompt => SopExtractPrompt::make('untrusted sop text', $context),
        ],
        // work-decomposition / scenario-generation も同型
    ];
}

test('帰属が必要な prompt は metadata_context に organization / subject を持つ', function (
    string $class, array $_untrusted, array $expectedKeys, Closure $factory,
): void {
    $prompt = $factory();
    /** @var array<string, mixed> $metadata */
    $metadata = (new ReflectionProperty(Prompt::class, 'metadata_context'))->getValue($prompt);

    foreach ($expectedKeys as $key) {
        expect($metadata)->toHaveKey($key,
            "{$class}: withMetadata() で '{$key}' を渡してください"
            .' (欠けると llm_call_logs が metadata_missing になり組織・対象別の費用が出せません)');
    }
})->with('untrusted_prompt_inputs');
```

- `VideoManual::factory()->makeOne(...)` は **DB へ書かない** (`make`)。既存テストと同じく
  factory 経由でモデルを作る規約を守る
- deny-by-default は既存の走査 (`discoverPromptFactoryClasses()`) がそのまま担う。
  **新しい prompt を足したら帰属キーの登録を強制される**

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`for(): self` / `toMetadata(): array<string, int|string>`)
- [x] null 安全 (すべて nullable を明示。`$subject?->getMorphClass()`)
- [x] DTO を返している (`LlmCallContextData`。配列は `toMetadata()` の 1 箇所だけで、
      戻り型に array shape 相当の value 型を明示する)
- [x] Generics の型パラメータ (該当なし)

### テスト計画

- [x] 新規 `tests/Unit/DataTransferObjects/LlmCallContextDataTest.php`
  - `for()` が `getMorphClass()` と `(string) getKey()` を使うこと (int 主キーが string 化される)
  - `subject = null` / `organizationId = null` のとき該当キーが `toMetadata()` から**落ちる**こと
  - `none()` が空配列を返すこと
  - `toMetadata()` の結果が `LlmMetadataExtractor` の 4 抽出器を通ったとき**元の値へ戻る**こと
    (往復テスト。`LlmMetadataExtractor::extractInt` が `ctype_digit` 判定である事実と
    `subject_id` を string 化する判断が噛み合っていることを固定する)
- [x] 変更 `tests/Architecture/PromptUntrustedInputContractTest.php` (上記の帰属層)
- [x] 既存 `tests/Feature/Projects/AnalysisPipelineTest.php` / `tests/Feature/Llm/CannedAnalysisPipelineTest.php`:
      **呼び出しシグネチャ変更の追随のみ**。canned fake 経路なので期待値の変更は無い
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

| リスク | 対処 |
|---|---|
| `resolveOrganization()` が startJob の外で呼ばれる (ロック外) | **参照のみ**で、書き込みも判定もしない (metadata の値を作るだけ)。startJob 内の予約処理は従来どおりロック内 |
| 見本 prompt を exempt にしたことで「exempt にすれば通る」抜け道になる | exempt は inventory への明示登録が必要 (deny-by-default)。抜け道を作る操作がレビューで見える形になっている |
| 帰属が入ることで既存の `metadata_missing` 前提のテストが壊れる | 既存テストは listener の単体テスト (`RecordLlmCallCostTest` 等) で event を直接組むため影響しない (grep 済み) |

---

## 施策 2: LLM コスト集計 (薄型)

> **DB に入っているものを GROUP BY するだけ**にする。再計算も再換算もしない。
> 4 ファイル (enum 1 / DTO 2 / service 1) が下限であり、これ以上は削らない理由を各項に書く。

### 変更箇所

- 新規: `app/Enums/LlmCostGroupBy.php`
- 新規: `app/DataTransferObjects/LlmCostRowData.php`
- 新規: `app/DataTransferObjects/LlmCostReportData.php`
- 新規: `app/Services/LlmCostReportService.php`

### 何をこれ以上削らないか (削除候補を検討した結果)

| 削除候補 | 判定 | 理由 |
|---|---|---|
| enum を消して string の group-by を service へ渡す | **消さない** | SQL の GROUP BY 列名を型のない文字列で受けることになる。閉じた語彙を enum で持つのはこのリポジトリの一貫した作法 (`app/Enums/` 配下多数) であり、**規約が要求しているもの**。かつ enum は「列名を集計層の外へ出さない」唯一の境界 |
| DTO を消して配列を返す | **消さない** | AGENTS.md の DTO 規約 (禁止事項 4 の精神 + コーディングルール「DTO + JsonResource パターン」) に真っ向から反する。**規約に反してまで削らない** |
| 行 DTO と全体 DTO を 1 本にする | **消せない** | 表形式の結果は「行の型」と「クエリ条件を含む全体の型」の 2 つを必要とする。1 本にすると rows が `list<array>` になり PHPStan level 10 で型が死ぬ |
| service を消して command に直書き | **消さない** | 「1 実装・複数入口」(オーナー指示 C) が成立しなくなる。smoke と期間コマンドの 2 入口が同じ集計を使うための唯一の置き場所。かつ AGENTS.md「Controller は薄く(Service 委譲)」の CLI 版 |
| service を Model の scope にする | **消さない** | 戻すのは DTO であって Builder ではないため scope の形に合わない。集計クエリを Model に置くと `LlmCallLog` が記録と集計の 2 責務を持つ |

### 変更後コード

```php
// app/Enums/LlmCostGroupBy.php
namespace App\Enums;

/**
 * コストレポートの集計軸 (閉じた語彙)。
 *
 * ★ ここが「集計層が知ってよい llm_call_logs の列」の**唯一の宣言点**である。
 *   列名リテラルを本 enum の外へ出さない (SQL へ素通しさせない型境界)。
 * ★ すべて素の列 GROUP BY とし、GROUP BY キーへ SQL 関数を適用しない (driver 差を持ち込まない)。
 *   既存 index を使えるかどうかは期間条件と実行計画に依存する (index 前提の設計にしない)。
 */
enum LlmCostGroupBy: string
{
    case PromptTemplate = 'prompt_template';   // どの段が
    case Model = 'model';                      // どのモデルが
    case Organization = 'organization';        // どの組織が
    case Subject = 'subject';                  // どの対象が (多態)

    /** @return non-empty-list<string> 集計キーを構成する列 */
    public function columns(): array
    {
        return match ($this) {
            self::PromptTemplate => ['prompt_template'],
            self::Model => ['model'],
            self::Organization => ['organization_id'],
            self::Subject => ['subject_type', 'subject_id'],
        };
    }
}
```

```php
// app/DataTransferObjects/LlmCostRowData.php
namespace App\DataTransferObjects;

/**
 * 集計 1 行 (TOTAL 行も同じ型)。
 *
 * 金額は DECIMAL の SUM を **numeric-string** のまま持つ (float 化も丸め直しもしない)。
 * null は「upstream の pricing / FX 解決失敗」であって 0 (unknown モデルの zero-cost
 * snapshot = 正常系) とは違う。潰さず、件数として別に返す (「安く見える」嘘をつかない)。
 */
final readonly class LlmCostRowData
{
    /**
     * @param  numeric-string|null  $totalCostUsd  usdUnresolvedCalls を除いた合計
     * @param  numeric-string|null  $totalCostJpy  jpyUnresolvedCalls を除いた合計
     * @param  int<0, max>  $calls
     */
    public function __construct(
        public string $key,                 // 集計キー (null 成分は '(none)'、複合は '#' 連結)
        public int $calls,
        public int $inputTokens,
        public int $outputTokens,
        public ?string $totalCostUsd,
        public ?string $totalCostJpy,
        public int $usdUnresolvedCalls,     // total_cost_usd IS NULL の件数
        public int $jpyUnresolvedCalls,     // total_cost_jpy IS NULL の件数
        public int $failedCalls,            // failure_reason IS NOT NULL の件数
        public int $metadataMissingCalls,   // metadata_missing = true の件数 (帰属配線の健全性)
    ) {}

    /** @return array{key: string, calls: int, ...} */
    public function toArray(): array { /* 全 public property を素直に写す */ }
}
```

```php
// app/DataTransferObjects/LlmCostReportData.php
namespace App\DataTransferObjects;

use App\Enums\LlmCostGroupBy;
use Carbon\CarbonImmutable;

final readonly class LlmCostReportData
{
    /** @param  list<LlmCostRowData>  $rows */
    public function __construct(
        public LlmCostGroupBy $groupBy,
        public ?CarbonImmutable $since,
        public ?CarbonImmutable $until,
        public ?int $afterId,               // 「この実行分」を切り出した id 境界 (smoke 用)
        public array $rows,
        public LlmCostRowData $total,       // key = 'TOTAL'
    ) {}

    /** @return array{group_by: string, since: ?string, until: ?string, after_id: ?int, rows: list<array<string, mixed>>, total: array<string, mixed>} */
    public function toArray(): array { /* enum は ->value、Carbon は toIso8601String()、子は再帰 */ }
}
```

```php
// app/Services/LlmCostReportService.php
namespace App\Services;

/**
 * llm_call_logs の集計 (読み取り専用)。**再計算も再換算もしない**。
 *
 * - USD が主: total_cost_usd は pricing_snapshot から決定的に決まる
 * - JPY は副: total_cost_jpy は行ごとの fx_snapshot (記録時レート) 由来。期間合計の JPY は
 *   「各行の記録時レートでの合計」であり、単一レートで USD を換算した値ではない
 * - 未解決 (null) は 0 に潰さず件数で返す
 *
 * ★ この層は llm_call_logs の列しか知らない。アプリのドメイン語彙を持ち込まない
 *   (他リポジトリへそのまま移植できる状態を保つ)。
 */
final readonly class LlmCostReportService
{
    public function report(
        LlmCostGroupBy $groupBy,
        ?CarbonImmutable $since = null,
        ?CarbonImmutable $until = null,
        ?int $afterId = null,
    ): LlmCostReportData;
}
```

### 実装方針 (クエリは 2 本だけ)

1. **行**: `LlmCallLog::query()` に where を積み、`groupBy->columns()` で GROUP BY + SELECT。
   集計列は `COUNT(*)` / `SUM(input_tokens)` / `SUM(output_tokens)` /
   `SUM(total_cost_usd)` / `SUM(total_cost_jpy)` /
   `SUM(CASE WHEN total_cost_usd IS NULL THEN 1 ELSE 0 END)` (JPY も同型) /
   `SUM(CASE WHEN failure_reason IS NOT NULL THEN 1 ELSE 0 END)` /
   `SUM(CASE WHEN metadata_missing THEN 1 ELSE 0 END)`
   - ★ **加算整数列 (トークン数・各件数) は SQL 側で `COALESCE(SUM(...), 0)` にする。**
     `SUM()` は対象 0 件のとき `NULL` を返すため、そのままだと `int` 引数の DTO が
     TypeError / `Assert::natural()` 失敗になる。**0 件と「集計不能」を混同させない**ために
     COALESCE は整数列だけに掛ける
   - ★ **金額列 (`total_cost_usd` / `total_cost_jpy`) には COALESCE を掛けない。**
     `null` は「未解決」を表す情報であり、0 に潰すと「タダだった」という嘘になる
     (これが `usdUnresolvedCalls` / `jpyUnresolvedCalls` と対になる仕様)
2. **TOTAL**: **同じ where 条件で GROUP BY 無しの同じ集計を 1 本**引く。
   GROUP BY 無しの集計は**対象 0 件でも 1 行返る** (`calls = 0`、整数列は COALESCE で 0、
   金額列は `null`)。これが「0 件時の TOTAL の形」の正本である。
   行を PHP で足し合わせない。理由: DECIMAL を PHP で加算すると float 化するか
   bcmath 依存を新たに持ち込むことになり、**移植先の PHP 拡張前提を増やす**。
   DB に足させれば精度も型もそのまま
3. **キー生成**: `columns()` の各値を取り出し、null は `'(none)'` に正規化して `'#'` で連結。
   例: Organization → `'7'`、Subject → `'App\Models\VideoManual#42'`
4. **型の境界**: `SUM()` の戻りは driver 依存で `string|int|float|null` になりうるため
   **DTO 生成の直前で検査する**。`is_numeric()` を満たさない値は `LogicException` (fail-loud)。
   件数系は `(int)` 化のうえ `Assert::natural()`
5. **where**: `since <= created_at < until` (半開区間)、`afterId !== null` なら `id > afterId`

#### 期間の境界仕様 (確定)

- **半開区間 `since <= created_at < until`**
- 日付のみ (`Y-m-d`) の入力の解釈:
  - `--since=2026-08-01` → `2026-08-01 00:00:00`
  - `--until=2026-08-10` → **`2026-08-11 00:00:00` (排他)** = 「2026-08-10 を含む」
  - 日時 (`Y-m-d H:i:s`) 入力はそのまま使う (排他境界のまま)
- 省略時: `since` = 30 日前、`until` = 現在 (排他)
- `since >= until` は入力エラー
- `config('app.timezone')` は **UTC 固定** (実読) であり `created_at` は UTC の `timestamp` 列。
  **期間境界は UTC で解釈する**とレポートに 1 行注記する (JST と 9 時間ずれる)。
  v1 にあった「日次集計の UTC 日境界」の議論は `day` 軸を削ったため消滅した

#### 表示スケール (確定)

DTO は `numeric-string` のまま保持し**丸めない**。表示側でのみ
USD = 小数 6 桁 / JPY = 小数 2 桁に `number_format` で揃える (列がガタつかないようにするだけ)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全 (`Webmozart\Assert\Assert` を DTO 生成境界で使用)
- [x] DTO を返している (配列返却なし)
- [x] Generics の型パラメータが正しい (`list<LlmCostRowData>` / `non-empty-list<string>`)

### テスト計画

- [x] 新規 `tests/Unit/Services/LlmCostReportServiceTest.php`
      (`LlmCallLog::factory()` でデータを作る。**実 LLM を呼ばない**)
  - 集計軸ごとの行分割 (prompt_template / model / organization / **subject**)
  - `subject` 軸のキーが `subject_type` と `subject_id` の複合で分かれること
  - null 成分が `'(none)'` に正規化されること (組織なしの行)
  - 期間境界: `since` ちょうどの行は**含む** / `until` ちょうどの行は**含まない**
  - `total_cost_usd` が null の行を 0 に潰さず `usdUnresolvedCalls` に数え、USD 合計に含めないこと
  - `total_cost_jpy` が null の行を `jpyUnresolvedCalls` に数えること (`withFxSnapshot()` state を使う)
  - `failedCalls` が `failed()` state の行を数えること
  - `metadataMissingCalls` が `metadataMissing()` state の行を数えること
  - `afterId` 指定で id 境界より**大きい**行だけが対象になること
  - **TOTAL が行の単純合計と一致すること** (別クエリで取っているので回帰の価値がある)
  - **対象 0 件のとき rows = [] かつ TOTAL が `calls = 0` / 整数列 0 / 金額列 null になること**
    (`SUM()` の NULL が整数列へ漏れないことの回帰。COALESCE を外すと落ちるテストにする)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 4 軸すべてが**既存の列だけ**で成立し、**GROUP BY キーへ SQL 関数を適用する軸がひとつも無い**
  (v1 の `date(created_at)` 軸を削ったため。集計値側では `COUNT` / `SUM` / `COALESCE` を使う)。
  既存 index (`(organization_id, created_at)` /
  `(model, created_at)` / `prompt_template` / `(subject_type, subject_id)`) と
  相性はよいが、「期間条件 + 軸」の組み合わせで常に index が効くとまでは主張しない。
  **本件の規模 (開発者・運営者向けの可視化) では index を追加しない**

---

## 施策 3: 期間集計コマンド (`operations:llm-cost-report`)

### 変更箇所

- 新規: `app/Console/Commands/Operations/LlmCostReportCommand.php`

### signature

```php
protected $signature = 'operations:llm-cost-report
    {--since= : 集計開始日時 (Y-m-d または Y-m-d H:i:s。既定 = 30 日前。UTC 解釈)}
    {--until= : 集計終了日時 (既定 = 現在。UTC 解釈)}
    {--group-by=prompt_template : 集計軸 (prompt_template|model|organization|subject)}
    {--json : 機械可読出力}';

protected $description = 'llm_call_logs を集計して LLM 利用コストを表示する (読み取り専用)。';
```

- 終了コード `self::INVALID` (2) にする入力エラー (**すべてテストで固定する**):
  - `--group-by` が `LlmCostGroupBy::tryFrom()` で null
  - `--since` / `--until` が `Y-m-d` でも `Y-m-d H:i:s` でも parse できない
  - `since >= until`
- 既定表示は `$this->table()`。列は
  `key / calls / in_tok / out_tok / usd / jpy / usd_null / jpy_null / failed / meta_missing`
- 末尾の注記 (4 行。**これ以上増やさない**):
  1. 期間境界は UTC 解釈
  2. JPY は各行の記録時レート (`fx_snapshot`) の合計であり単一レート換算ではない
  3. `usd_null` / `jpy_null` の行は合計に含まれない
  4. `meta_missing` = 組織・対象が特定できない行 (**0 でないなら呼び出し側の
     `withMetadata()` 配線が欠けている**。施策 1 参照)
- `--json` は `LlmCostReportData::toArray()` を
  `json_encode($dto->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)` するだけ
  (shape は DTO 側が正本)
- **スケジュール登録しない** (`routes/console.php` を触らない)

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`handle(): int`)
- [x] null 安全 (option は `string|null` を明示検査してから parse)
- [x] DTO を返している (Service が DTO を返す。command は表示のみ)

### テスト計画

- [x] 新規 `tests/Feature/Console/LlmCostReportCommandTest.php`
  - 既定オプションで表が出ること / `--json` の shape (キー集合・型) が固定されること
  - 終了コード 2: 不正な `--group-by` / parse 不能な `--since`・`--until` / `since >= until`
  - 日付のみ入力の解釈 (`--until=YYYY-MM-DD` がその日を**含む**こと)
  - `--group-by=subject` が動くこと (帰属が入った行を factory で作る)
- [x] 個別の `DatabaseTransactions` を使っていない

---

## 施策 4: bug-hunt DB 名判定の SSOT を app 側へ昇格

*(v1 の施策 1。判断は変えていない)*

### 変更箇所

- 新規: `app/Support/BughuntDatabaseGuard.php`
- 変更: `database/seeders/Concerns/DetectsBughuntDatabase.php` (委譲に置換。**public API は不変**)

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
 * ★ 依存の向きは app ← seeders。seeder 側 trait は本クラスへ委譲するだけの薄い殻にする。
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
namespace Database\Seeders\Concerns;

use App\Support\BughuntDatabaseGuard;   // ★ import を省略しない

trait DetectsBughuntDatabase
{
    private function isBughuntDatabase(): bool
    {
        return app(BughuntDatabaseGuard::class)->isBughuntDatabase();
    }
}
```

### テスト計画

- [x] 新規 `tests/Unit/Support/BughuntDatabaseGuardTest.php` — `matches()` の判定表
      (`bug_hunt` / `bug_hunt_1` / `bug_hunt_8` = true、`bug_hunt_9` / `bug_hunt_` / `aicue` /
      `bug_hunt_1x` / `xbug_hunt` = false)
- [x] 既存 seeder テストは呼び出し側不変のため更新不要

---

## 施策 5: ダミー SOP fixture

*(v1 の施策 4。変更なし)*

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
  - **判定は `SopTextExtractor` と同じ基準で行う** (比率計算を再実装しない。
    `SourceDocument` を作って `extract()` を通し「fixture がゲートを通る」ことを
    behavioral に固定する)
- 意義: 「smoke が fixture の不備で落ちる」という**紛らわしい失敗**を構造的に潰す

---

## 施策 6: pipeline smoke コマンド本体

*(v1 の施策 5。**`llm-evidence` 段の成功条件に帰属の検査を追加**した以外は v1 のまま)*

### 変更箇所

- 新規: `app/Console/Commands/Development/PipelineSmokeCommand.php`
- 新規: `app/Enums/Smoke/SmokeStage.php` / `app/Enums/Smoke/SmokeFailureClass.php`
- 新規: `app/DataTransferObjects/Smoke/SmokeStageResultData.php` / `SmokeRunResultData.php`
- 新規: `app/Support/Smoke/SmokeFailureClassifier.php`
  (**純関数の分類器**。`app/Support/Billing/GatewayFailureClassifier.php` と同じ配置・同じ流儀)

### signature

```php
protected $signature = 'dev:pipeline-smoke
    {--check : preflight だけ実行して終了する (LLM を 1 回も呼ばない = 費用ゼロ)}
    {--org= : 対象組織 id (省略時は条件を満たす先頭の組織)}
    {--json : 機械可読出力}
    {--force : 実行確認を省略する (fail-secure 条件は迂回できない)}';

protected $description = 'SOP 投入→AI 解析→撮影テイク→ffmpeg 合成→mp4 の全段が通ることを実 LLM で確認する (bug-hunt 専用・課金あり)。';
```

`Illuminate\Console\ConfirmableTrait` を use する。ただし
**`confirmToProceed()` を引数なしで呼んではならない** — 既定の callback は
`environment() === 'production'` のときしか確認せず (vendor 実読)、本コマンドの実行環境は
`bughunt.local` なので**確認が一度も出ないまま課金が走る**。

```php
if (! $this->confirmToProceed($costWarning, true)) {   // ★第 2 引数 true = 常に確認する
    return self::INVALID;
}
```

- `--force` 指定時は `ConfirmableTrait` 側が `$this->option('force')` を見て skip する
  (fail-secure 4 条件は `--force` でも迂回できない。**確認の skip と fail-secure は別物**)
- 拒否されたら `self::INVALID` (2) を返し、**何も実行しない**
- `$costWarning` には見積り費用を出す (下記「費用見積り」)

### fail-secure 条件 (`--force` でも迂回できない)

`handle()` の**最初の実効文**で検査し、1 つでも欠ければ `self::FAILURE` で即終了する:

| # | 条件 | 根拠 |
|---|---|---|
| 1 | `app()->environment('bughunt.local')` | 実 LLM + 実 ffmpeg + チケット消費を dev / production で走らせない |
| 2 | `BughuntDatabaseGuard::isBughuntDatabase()` | dev DB へ fixture をばら撒かない |
| 3 | `FakeStorageGate::enabled()` | 実 S3 へ書かない |
| 4 | `config('testing.fake_llm') === false` | fake LLM のまま「通った」と報告しない |

> 4 は**自プロセスの config** であり worker の設定は見ていない。worker が fake なら
> 「`llm_call_logs` の記録行が 0」として段 `llm-evidence` で落ちる (P3。**2 層で守る**)。

### 依存の解決タイミング (fail-secure より前に何も解決しない)

**コマンドの constructor は引数を持たない。** すべての依存 (`FakeObjectStore` /
`VideoManualService` / `AnalysisJobService` / `TakeUploadService` / `TakeRegistrationService` /
`CaptureTakeService` / `RenderJobService` / `ProjectService` / `TicketLedgerService` /
`LlmCostReportService`) は **fail-secure 4 条件を通過した後**に `handle()` 内で `app(...)` から
遅延解決する。理由: constructor injection にすると `artisan list` / `artisan help` を含む
あらゆる artisan 起動でコマンドが構築され、`FakeObjectStore` が `FakeStorageGate` の判定より
前に解決されうる。

### preflight (`--check` はここまでで終了)

**`--check` は DB を 1 行も変更しない** (読み取りと外部プロセスの `-version` のみ)。

| # | 検査 | 失敗時 |
|---|---|---|
| 1 | fail-secure 4 条件 | 即終了 |
| 2 | `manual.render_ffmpeg_binary` / `render_ffprobe_binary` が実行可能 (`-version` の終了コード 0) | `preflight` |
| 3 | 対象組織の解決 (`--org` 指定 or 条件を満たす先頭)。条件 = チケット残高十分 ∧ 所属 user が 1 人以上 | `preflight` |
| 4 | **actor の解決**: 対象 org 所属 user の先頭 (`$organization->users()->orderBy('users.id')->first()`)。不在なら失敗 | `preflight` |
| 5 | **Project の状態表示**: `DefaultProjectResolver::resolve()` が非 null なら `existing #id`、null なら **`will-create`** (作成はしない) | 失敗にしない |
| 6 | チケット残高 `availableTrueBalance() >= analysis_ticket_cost + render_ticket_cost` (= 4) | `preflight` |
| 7 | SOP fixture が読め、`analysis_min_text_bytes` 以上 | `preflight` |
| 8 | `config('queue.connections.database-analysis')` / `database-render` が存在すること | `preflight` |

- **Project が無いことは preflight の失敗にしない** (P9)。`--check` の出力には
  `project=will-create` と出す
- **actor に Laratrust の team context は設定しない** (呼ぶ Service はいずれも権限判定を持たず、
  認可は Controller 層の責務。actor は `created_by` / `triggered_by` に使うだけ)
- worker プロセスの**生存**は preflight では判定しない (**できない**)。代わりに段 `analysis` /
  `render` の「`queued` のまま上限到達」を `Wiring` 失敗として明示する
- 実行対象 (org / actor / project / 残高 / ffmpeg 版) を必ず表示してから確認を求める

### 実行の段 (すべて実在の業務経路)

| 段 | 実行 | 成功条件 (**これだけを見る**) |
|---|---|---|
| `fixture` | `ProjectService::createProject`(Default Project 不在時のみ) → `VideoManualService::create($project, 'pipeline-smoke YYYY-MM-DD HH:MM', null, $userId, $sopUploadedFile)` | manual が `draft` / `source_documents` 1 件 |
| `analysis` | `AnalysisJobService::trigger($project, $manual, $actor)` → **worker を待つ** | `analysis_jobs.status = succeeded` ∧ `video_manuals.status = ready` ∧ `cuts` ≥ 1 ∧ `scenario_version` ≥ 1 |
| `llm-evidence` | (DB 読み取りのみ) | **下記 2 条件の両方** |
| `capture` | 全 cut について `TakeUploadService::issue` → オブジェクト書き込み → `TakeRegistrationService::register` → `CaptureTakeService::adopt` | 全 cut の `adopted_take_id` が非 NULL ∧ 対応 take が `ready` |
| `render` | `RenderJobService::trigger($project, $manual, $actor)` → **worker を待つ** | `render_jobs.status = succeeded` ∧ `video_manuals.status = published` ∧ `output_path` 非 NULL |
| `artifact` | 出力オブジェクトをローカルへ取り出し `ffprobe` | 動画ストリーム ≥ 1 ∧ `format=duration` > 0 |

#### 「この実行分」の境界 `$baselineId` (取得タイミングを確定する)

`$baselineId = LlmCallLog::query()->max('id') ?? 0` を、
**fail-secure 4 条件と preflight を通過した直後・`fixture` 段を始める前**に 1 回だけ取る。

- `fixture` 段より前に取る必要がある: `VideoManualService::create()` は LLM を呼ばないが、
  境界を「本コマンドが何かを作り始める前」に置いておけば、将来どの段で LLM が増えても
  取りこぼさない
- `--check` では取らない (LLM を 1 回も呼ばないため)
- 対象が 0 件のとき `max('id')` は null になるので `?? 0` で潰す
  (`id > 0` = 全行対象。bug-hunt DB は使い捨てなので実害がない)

#### `llm-evidence` 段の成功条件 (v2 で拡張)

この実行分の行 = `id > $baselineId` **かつ**
`whereIn('prompt_template', ['sop-extract', 'work-decomposition', 'scenario-generation'])`
(**母集団をクエリで 3 template に絞る**。他の prompt が同 shard で走っても混ざらない) について:

1. **実呼び出しの証拠** (v1 から): `failure_reason IS NULL` ∧ `input_tokens > 0` の成功行が、
   3 つの `prompt_template` それぞれについて**各 1 行以上**ある
2. **帰属の証拠** (v2 で追加): 上の成功行がすべて
   `metadata_missing = false` ∧ `organization_id = 対象 org の id` ∧
   `subject_type = VideoManual の morph class` ∧ `subject_id = 対象 manual の id (文字列比較)`

条件 2 が **施策 1 の配線が実 LLM 経路で本当に効いていることの唯一の機械的な確認**である
(テストレーンでは P3 / P18 により観測できない)。

**「記録の不備」は `Llm` ではなく `Wiring` に分類する。** 対象は次の 2 つで、
分類器へは**1 つの bool `$llmRecordingIncomplete`** にまとめて渡す
(判定の入力を増やさない。detail 文字列で内訳を出す):

| 記録の不備 | 例 |
|---|---|
| 帰属欠落 | 成功行はあるが `metadata_missing = true` / `organization_id` や `subject_*` が期待と違う |
| **必要 template の欠落** | `analysis` 段は成功しているのに 3 template のうち一部の成功行が無い |

いずれも **LLM は成功しているのにアプリ側の記録経路が欠けている**状態であり、
provider の問題 (`Llm`) と混ぜると「レート制限で落ちた」と「`withMetadata()` を書き忘れた」が
同じ札になる。**成功行が 1 行も無い**場合は記録の不備ではなく LLM が呼ばれていない疑いなので、
従来どおり `Llm` に落ちる (下の判定順 #8 が `$hasLlmSuccessRow` を条件に含むのはこのため)。

> **診断の出力**: 条件 2 が落ちたときは、**欠けている template 名**と、
> 欠けている列 (`organization_id` / `subject_type` / `subject_id`) の実際の値を段の detail に出す。
> 「どこの `withMetadata()` が抜けたか」「どの段の記録が落ちたか」が一目で分かるようにする。

- **品質は一切見ない**: 字幕の文言・語尾・捏造の有無・カット数の妥当性・尺の妥当性は判定しない
- `cuts` は LLM 出力に依存して件数が変わる。`capture` 段は**全 cut を総なめ**する

### worker 待ち (段 `analysis` / `render`)

```
2 秒間隔で job 行を再読込する:
  - status = succeeded → 成功
  - status = failed    → **待たずに即座に**失敗へ (error / step / progress を診断へ)
  - 上限到達           → timeout。status = queued なら Wiring、running なら StageTimeout
上限: analysis = RunManualAnalysis::$timeout + 120s
      render   = RunManualRender::$timeout   + 120s
```

上限値は**ジョブ側の定数から導出**し、コマンドに独立した数値リテラルを置かない
(`(new RunManualAnalysis(0))->timeout` を読む)。

### テイク動画の作り方 (段 `capture`)

**1 本だけ生成して全 cut で使い回す** (cut ごとに新しい S3 キーへ同じバイト列を置く)。

```bash
ffmpeg -y \
  -f lavfi -i testsrc2=size=640x360:rate=30:duration=2 \
  -f lavfi -i sine=frequency=440:duration=2 \
  -c:v libx264 -preset veryfast -pix_fmt yuv420p \
  -c:a aac -ar 48000 -ac 2 -shortest \
  {workDir}/take.mp4
```

- `Process::path($workDir)->timeout(...)->run([...])` (配列引数。シェル連結しない)
- `sizeBytes` = `filesize()`、`contentType` = `'video/mp4'`
- `checksum` = `Sha256Checksum::fromBase64(base64_encode(hash_file('sha256', $path, binary: true)))`
- cut ごとに `clientTakeId` = `(string) Str::ulid()` を新規発番する
- 書き込みは `FakeObjectStore::storeStreamed($reservation->video_path, $stream, 'video/mp4', $checksum->base64)`

#### 予約行 (`TakeUploadReservation`) の再解決 (tenant-safe)

```php
// $cut は $manual->cuts() 経由で取得済み ($manual は $project->manuals() 経由)
$reservation = $cut->uploadReservations()
    ->where('client_take_id', $clientTakeId)
    ->latest('id')
    ->firstOrFail();
```

- **必ず `organization → project → manual → cut` の確認済み relation から辿る**
- **クラス起点の主キー同一性クエリを書かない** (`ModelDirectFetchInvariantTest` の
  deny-by-default に触れる形を作らない)
- **presigned URL を parse して key を復元しない / payload から tenant キーを復元しない**

### 失敗分類 (`SmokeFailureClass`)

| case | 判定 |
|---|---|
| `Preflight` | preflight で落ちた (LLM を 1 回も呼んでいない) |
| `Wiring` | ジョブが **`queued` のまま**上限到達 / **`llm-evidence` で記録が不完全** (帰属欠落 or 必要 template 欠落) |
| `StageTimeout` | ジョブが **`running` のまま**上限到達 |
| `Llm` | **`analysis` / `llm-evidence` 段が失敗している**うえで、この実行分の `llm_call_logs` に `failure_reason` 行がある、または成功行が 1 行も無い (**他の段には適用しない**) |
| `Render` | `render` 段で `render_jobs.error_code` が非 null、または `artifact` 段で出力は読めたが ffprobe が非 0 終了 |
| `Storage` | `artifact` 段で出力オブジェクトが不在 / 読み出し不能 |
| `Unknown` | 写像表に一致が無かった |

- 分類は**観測のためであり制御フローを変えない**
- ★ **`failure_reason` 行の存在だけで `Llm` にしない**。`withBoundedRetry` は transient 失敗を
  最大 3 試行まで再試行するため、**最終的に成功した実行にも `failure_reason` 行は残る**。
  分類は「段が失敗したとき」にだけ行い、成功した段は分類しない
  (成功時のリトライは診断行に `llm_retry_rows=N` として**情報として**出す)

### 分類器 (`App\Support\Smoke\SmokeFailureClassifier`)

`SmokeStage` の case は `Preflight` / `Fixture` / `Analysis` / `LlmEvidence` / `Capture` /
`Render` / `Artifact` の 7 つ。

```php
final readonly class SmokeFailureClassifier
{
    /** LLM が原因になり得る段 (Llm 分類の適用範囲を**この集合に閉じる**) */
    private const array LLM_ATTRIBUTABLE_STAGES = [SmokeStage::Analysis, SmokeStage::LlmEvidence];

    /**
     * 失敗の観測分類。**成功した段では null を返す**。
     *
     * @param  bool            $stageSucceeded        段が成功したか
     * @param  JobStatus|null  $jobStatus             観測したジョブ状態 (段によっては null)
     * @param  bool            $timedOut              待機上限に到達したか
     * @param  bool            $hasLlmFailureRow       この実行分に failure_reason 行があるか
     * @param  bool            $hasLlmSuccessRow       この実行分に成功行があるか
     * @param  bool            $llmRecordingIncomplete ★v2: 成功行はあるが記録が不完全か
     *                                                 (帰属欠落 **または** 必要 template の成功行欠落)
     * @param  bool            $hasRenderErrorCode     render_jobs.error_code が非 null か
     * @param  bool            $outputReadable         出力オブジェクトを読み出せたか
     * @param  bool            $ffprobeFailed          ffprobe が非 0 終了したか
     */
    public static function classify(
        SmokeStage $stage,
        bool $stageSucceeded,
        ?JobStatus $jobStatus,
        bool $timedOut,
        bool $hasLlmFailureRow,
        bool $hasLlmSuccessRow,
        bool $llmRecordingIncomplete,
        bool $hasRenderErrorCode,
        bool $outputReadable,
        bool $ffprobeFailed,
    ): ?SmokeFailureClass;

    /**
     * `$llmRecordingIncomplete` を**導出する**純関数 (同じクラスに置く。新しいファイルを作らない)。
     *
     * 「LLM は成功しているのに記録が欠けている」を 2 原因まとめて判定する:
     *   - 必要 template の成功行が足りない (analysis は成功したのに記録が落ちた)
     *   - 成功行はあるが帰属 (organization / subject) が期待と違う
     *
     * DB 読み出しは呼び出し側 (コマンド) が行い、本関数は **template 名の集合演算だけ**を行う
     * = DB なしの Unit テストで導出規則を直接固定できる。
     *
     * ★ 呼び出し側の責務: `$succeededTemplates` / `$attributedTemplates` は
     *   **`$requiredTemplates` に限定した集合**であること。DB クエリに
     *   `->whereIn('prompt_template', $requiredTemplates)` を付ければ足りる
     *   (対象外の template が混ざると `array_diff($succeeded, $attributed)` が
     *    本 smoke と無関係な行まで「不完全」と判定してしまう)。
     *   **追加の引数も検査も足さない** — クエリ側で母集団を絞るのが最小の対処である。
     *
     * @param  list<string>  $requiredTemplates    期待する prompt_template (3 段)
     * @param  list<string>  $succeededTemplates   この実行分の成功行が存在した template (required に限定)
     * @param  list<string>  $attributedTemplates  うち帰属が期待どおりだった template (required に限定)
     */
    public static function llmRecordingIncomplete(
        array $requiredTemplates,
        array $succeededTemplates,
        array $attributedTemplates,
    ): bool {
        if ($succeededTemplates === []) {
            return false;   // 成功行が 1 行も無いのは「記録の不備」ではなく Llm 側の疑い (#9 へ)
        }

        return array_diff($requiredTemplates, $succeededTemplates) !== []
            || array_diff($succeededTemplates, $attributedTemplates) !== [];
    }
}
```

判定順 (先に一致したものを返す):

| # | 条件 | 返り値 |
|---|---|---|
| 1 | `$stageSucceeded` | **`null`** (分類しない) |
| 2 | `$stage === Preflight` | `Preflight` |
| 3 | `$timedOut && $jobStatus === Queued` | `Wiring` |
| 4 | `$timedOut && $jobStatus === Running` | `StageTimeout` |
| 5 | `$stage === Render && $hasRenderErrorCode` | `Render` |
| 6 | `$stage === Artifact && ! $outputReadable` | `Storage` |
| 7 | `$stage === Artifact && $ffprobeFailed` | `Render` |
| 8 | **`$stage === LlmEvidence && $hasLlmSuccessRow && $llmRecordingIncomplete`** | **`Wiring`** |
| 9 | `in_array($stage, LLM_ATTRIBUTABLE_STAGES, true) && ($hasLlmFailureRow \|\| ! $hasLlmSuccessRow)` | `Llm` |
| 10 | それ以外 | `Unknown` |

**境界の意図**:

- **`Llm` は LLM が原因になり得る段に閉じる** (Round 3 の Critical への対応。維持)
- **`artifact` の 2 分岐**: 読み出せない = `Storage`、読めたが ffprobe 失敗 = `Render`
- **★v2: 記録の不備は `Wiring`**。#8 を #9 より**前**に置く。LLM 成功行があるのに
  帰属が無い / 必要 template の行が足りない状態は provider の問題ではなくアプリの配線の問題であり、
  `Llm` に混ぜると「レート制限で落ちた」と「`withMetadata()` を書き忘れた」が同じ札になってしまう。
  #8 は `$hasLlmSuccessRow` を条件に含むので、そもそも成功行が無いときは #9 の `Llm` に落ちる
- **`llm-evidence` 段が失敗したのに `Unknown` になる経路を残さない**: この段の失敗理由は
  「成功行が 1 行も無い」(#9 → `Llm`) か「記録が不完全」(#8 → `Wiring`) の 2 通りしかなく、
  `$llmRecordingIncomplete` が必要 template 欠落まで含むので**両者で網羅されている**

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
llm-evidence  ok         0.0s    sop-extract=1 work-decomposition=1 scenario-generation=1 attributed=3/3
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
注: meta_missing = 0 (帰属は organization=#3 subject=VideoManual#12)

RESULT: PASS (total 214.4s, cost $0.084330)
```

`--json` は次の**1 経路だけ**を通る:

```php
json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
```

`SmokeRunResultData` / `SmokeStageResultData` にも array shape 付き `toArray()` を実装し、
public property の並びが外部契約になることを避ける。コスト部は
`LlmCostReportData::toArray()` をそのまま埋め込む (二重定義しない)。
**`response()->json()` は使わない**。

### 終了コード

| code | 意味 |
|---|---|
| 0 (`SUCCESS`) | 全段 ok |
| 1 (`FAILURE`) | いずれかの段が失敗 / preflight 失敗 / fail-secure 不成立 |
| 2 (`INVALID`) | オプション不正 / 確認で拒否 |

### 後始末

- 一時ディレクトリ (`storage/app/smoke/{ulid}/`) は `finally` で必ず削除する
- **DB 上の fixture は削除しない**。失敗時の調査に必要であり、bug-hunt DB は provision で
  `migrate:fresh` される使い捨てだから

### 費用見積り (確認プロンプトに出す値)

- 3 段合計の入力 ≒ 6〜8k token、出力 ≒ 4〜6k token
- → **1 回あたりおよそ $0.07〜0.12 (約 10〜20 円)**。
  LLM リトライ (最大 3 試行/段) が発生すると最大 3 倍程度
- 確認文には「**実測値は実行後のコストレポートに出る**」と併記し、見積りを断定しない

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`handle(): int`、各 private メソッドも DTO / void)
- [x] null 安全 (`Webmozart\Assert\Assert` で option / 解決結果を検査)
- [x] DTO を返している (`SmokeStageResultData` / `SmokeRunResultData`)
- [x] Generics の型パラメータが正しい (`list<SmokeStageResultData>`)

### リスク

| リスク | 対処 |
|---|---|
| `capture` 段が cut 数に比例して遅い | テイク動画は**1 本だけ生成して使い回す** |
| 実 LLM が `cuts` を 0 件にした | 段 `analysis` の成功条件 `cuts ≥ 1` で落ちる (品質ではなく**構造**の判定) |
| 実行中に同じ shard で別の LLM 呼び出しが走ると「この実行分」に混入する | **運用前提として明記**する。`--run-id` を metadata に載せる恒久対策は本件スコープ外 (帰属キーは 4 つの汎用キーに閉じる方針を崩さない) |
| `render` の尺上限ソフトゲート (20 分) | 2 秒 × cut 数なので到達しない |

---

## 施策 7: fake 参照 allowlist への登録

*(v1 の施策 6。判断は変えていない)*

### なぜ必要か / なぜこの形にするか

`capture` 段は fake storage (`s3_fake` disk) に**実バイト**を置く必要がある。書き込み口は 3 通り:

| 案 | 内容 | 判定 |
|---|---|---|
| A | `FakeObjectStore::storeStreamed()` を直接呼ぶ | **採用**。allowlist 1 行の追加で済む。既存の `Put/GetFakeStorageObjectController` と**同 species** |
| B | presigned URL へ `Http::put()` (loopback) | 却下。`ExternalSeamInventory` の母集団に入り、閉じた語彙の `ExternalSeamKind` に該当 case が無い |
| C | `Storage::disk('s3_fake')` を直接叩き sidecar を手書き | 却下。sidecar 形式の二重管理になる |

**案 A で失われるもの (誇張しない)**: presigned PUT の署名検証・ヘッダ契約は通らない。
ただしこれは fake 固有の emulation であり、**本番の実 S3 presigned PUT は本 smoke では
そもそも検証できない**。`FakeObjectStore` が担保する **checksum 三者一致の 3/3** は通る。

```php
const FAKE_REFERENCE_ALLOWED = [
    'app/Providers/FakeExternalsServiceProvider.php',
    'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
    'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
    // bug-hunt 専用の通し確認コマンド。fake storage へ実バイトを置く必要があり、
    // FakeStorageGate 成立時のみ動く (上 2 件の controller と同 species)。
    // 本番経路からは到達しない (artisan 手動実行のみ・スケジュール登録なし)。
    // ★実装条件: constructor 引数を持たず、fake は handle() の fail-secure 4 条件を
    //   通過した**後**にのみ app() で遅延解決する。
    'app/Console/Commands/Development/PipelineSmokeCommand.php',
    'bootstrap/providers.php',
];
```

### テスト計画

- [x] 既存 gate が緑のまま (allowlist の 1 行追加のみ)
- [x] `4-2 配置例外は 2 件から増えていない` は**変更しない**

---

## 施策 8: bug-hunt レーンからの起動導線

*(v1 の施策 7。判断は変えていない)*

### 追加するもの

1. `artisan_with_mode_for_shard()` — 既存 `artisan_for_shard()` と同型だが
   **`MODE_ENV` + `LLM_KEY_ENV` を載せる** (`secret_xtrace_off` / `restore` で挟む)
2. `cmd_pipeline_smoke()`:
   - **最初の実効文で `require_orchestrator "pipeline-smoke"`** (費用の防壁)
   - `require_manifest` で provision 済みを確認し、db / url を manifest から取る
   - `prepare_mode_and_preflight` (= `build_mode_env` → `assert_llm_key_present`)
   - `artisan_with_mode_for_shard "${db}" "${url}" dev:pipeline-smoke --force <転送する option のみ>`
3. `main()` の `case` に `pipeline-smoke)` を追加
4. usage ヘッダに 1 行追記

#### モードフラグの扱い (確定)

`pipeline-smoke` は **`--real-llm` を要求しない**。既存の「モードフラグは provision 系専用」
という検査は**変更しない** (`pipeline-smoke --real-llm` は従来どおり `die 2`)。

#### option の対応表

| option | script が消費 | artisan へ転送 |
|---|---|---|
| `--shard N` | ✔ | ✘ |
| `--run-id R` | ✔ | ✘ |
| `--check` | ✘ | ✔ |
| `--json` | ✘ | ✔ |
| `--org=ID` | ✘ | ✔ |
| `--force` | — | ✔ (script が常に付ける) |

script 側は**転送する option を allowlist で明示列挙**し、未知 option は `die 2` する。

### 追加しないもの

- **`generate_wrapper()` の許可サブコマンドには追加しない**。子 (探索エージェント)
  セッションから叩けるのは `db-check` / `db-exists` / `mail-urls` / `reseed` のまま

### テスト計画

- [x] `scripts/bug-hunt-shard.sh self-test` に dryrun ケースを 2 つ追加:
      (a) `BUGHUNT_ORCHESTRATOR` 無しで `pipeline-smoke` が **副作用の前に die** すること
      (b) **`--shard` / `--run-id` が artisan へ転送されない**こと
- [x] `tests/Architecture/BughuntOrchestratorGateInvariantTest.php` の期待表に
      `cmd_pipeline_smoke` を追加
- [x] `BughuntShardCapInvariantTest` / `BughuntRawDbCommandInventoryTest` が緑のまま

---

## 施策 9: ドキュメント追記

| ファイル | 追記内容 |
|---|---|
| `AGENTS.md` §bug-hunt | `pipeline-smoke` サブコマンドの存在、**実 LLM で課金が発生する**こと、`BUGHUNT_ORCHESTRATOR=1` 必須、子 wrapper には露出しないこと |
| `AGENTS.md` §LLM | **`app/Prompts/` の factory は `LlmCallContextData` を必須引数で受け、`withMetadata()` で帰属を付ける**こと。新しい prompt を足したら `PromptUntrustedInputContractTest` の inventory に帰属キーを登録すること |
| `docs/architecture.md` | 「パイプライン通し確認 (pipeline smoke)」節: 段の定義・合否条件・失敗分類の語彙・**保証しないもの**・LLM コストレポートの軸と通貨の扱い (USD 主 / JPY は記録時レート合計 / 期間は UTC) |
| `.claude/skills/app-bug-hunt/SKILL.md` | 探索エージェントは pipeline-smoke を**実行しない**こと (親が実行する) |

### 「保証しないもの」(誇張しない。docs へそのまま書く)

1. **生成物の品質は一切保証しない**。判定しているのは「期待した状態遷移が起きたか」だけ
2. **実 S3 は検証していない**。通るのは `FakeObjectStore` の checksum 3/3 だけ
3. **ブラウザ (撮影 PWA) の実機経路は検証していない**。CLI から Service を呼んでいる
4. **worker プロセスの LLM モードを直接は見ていない**。`llm_call_logs` の記録行の存在で
   間接的に実呼び出しを実証している
5. **費用は「この実行で記録された行の合計」**であり provider 側の請求額とは一致しない
6. **帰属メタデータが「イベント経由で `llm_call_logs` に記録されること」はテストレーンでは
   検証できない** (P3 / P18)。テストレーンで検証できるのは
   「factory が組み立てた Prompt が `metadata_context` に帰属キーを持つこと」(reflection) までで、
   **listener を経て DB へ入ったことを確かめられるのは本 smoke の `llm-evidence` 段だけ**である
7. **並行実行に対する保証は無い**。「この実行分」は `llm_call_logs.id` の差分で切り出しており、
   同一 shard で別の LLM 呼び出しが並行すると混入する
8. **1 回通ったことは、次も通ることを意味しない**。実 LLM の出力は非決定的である

---

## 施策 10: テスト

**実 LLM を 1 回も呼ばない。** テストレーンの `StrayLlmCallGuard` / `StrayHttpRequestGuard` は
既定のまま (opt-out しない)。

| ファイル | 検証内容 |
|---|---|
| `tests/Unit/DataTransferObjects/LlmCallContextDataTest.php` | 帰属 DTO の構築と `toMetadata()` (施策 1) |
| `tests/Architecture/PromptUntrustedInputContractTest.php` (変更) | 全 prompt factory が帰属キーを持つ (deny-by-default) |
| `tests/Unit/Support/BughuntDatabaseGuardTest.php` | DB 名判定表 (正例 3 / 負例 5)。DB 不要 |
| `tests/Unit/Fixtures/PipelineSmokeSopFixtureTest.php` | SOP fixture が `SopTextExtractor` のゲートを通ること |
| `tests/Unit/Services/LlmCostReportServiceTest.php` | 集計軸 4 / null 未解決の分離 / `afterId` 境界 / 期間境界 / TOTAL 一致 / 0 件時の形 |
| `tests/Unit/Support/Smoke/SmokeFailureClassifierTest.php` | 失敗分類の判定表 + **`llmRecordingIncomplete()` の導出表** (下表 2 つ) |
| `tests/Feature/Console/LlmCostReportCommandTest.php` | 既定表示 / `--json` shape / 終了コード 2 / 日付のみ入力の解釈 |
| `tests/Feature/Console/PipelineSmokeCommandTest.php` | 下表 |

### `PipelineSmokeCommandTest` の観点

| # | ケース | 期待 |
|---|---|---|
| 1 | `testing` 環境 (= bughunt.local でない) で実行 | 終了コード 1。**LLM も ffmpeg も呼ばれない** |
| 2 | env は満たすが DB 名が bug-hunt でない | 終了コード 1 |
| 3 | `config('testing.fake_llm') = true` | 終了コード 1 |
| 4 | `--force` を付けても 1〜3 は迂回できない | 終了コード 1 |
| 5 | 4 条件を満たし `--check` | preflight の結果が出て終了。**`Prompt` の fake すら install せず `StrayLlmCallGuard` が赤くならない** |
| 6 | `--check` で ffmpeg バイナリが不在 | 終了コード 1 / `failure_class = preflight` |
| 7 | `--check` でチケット残高不足 | 終了コード 1 / `failure_class = preflight` |
| 8 | `--check --json` | `SmokeRunResultData` の shape が固定される |
| 9 | 確認プロンプトで拒否 (`--force` なし・`expectsConfirmation(false)`) | 終了コード 2 / 何も実行しない。**`bughunt.local` でも確認が出ること**を固定する (`confirmToProceed()` の第 2 引数 `true` を外すと落ちるテストにする) |
| 9b | `--force` あり | 確認が出ずに進むこと (fail-secure 4 条件は依然として効く = ケース 4 と対) |
| 10 | `--check` で Default Project 不在 | **成功** し `project=will-create` が出る。**`projects` の件数が変わらない** |
| 11 | `--check` で対象 org に所属 user が 0 人 | 終了コード 1 / `failure_class = preflight` |

> **ffmpeg 依存を持ち込まない**: ケース 5・8・10・11 は
> `config()->set('manual.render_ffmpeg_binary', PHP_BINARY)` (と ffprobe 側) で
> **preflight の分岐だけ**を固定する。ケース 6 は存在しないパスで逆側を固定する。
> ケース 1〜4 は `BughuntDatabaseGuard` を container で差し替えて成立させる
> (`FakeStorageGate` が既に container 解決される先例と同型)。

### なぜ「全段を fake で通すテスト」を書かないか

`Prompt::fake` + `Process::fake` + `Storage::fake` で全段を回すテストは書けるが**書かない**:

1. 各段の配線は既に段ごとの Feature テストが持っている (重複。思考原則 2 に反する)
2. `Process::fake()` で ffmpeg を fake すると、**このコマンドの唯一の固有価値
   (実 ffmpeg が本当に回るか) が消える** (偽グリーン)
3. smoke の**固有ロジック**は「fail-secure 条件 / preflight / 待ちと分類 / 集計と出力」であり、
   これらは上表で実 LLM なしに固定できる

> **`llm-evidence` 段の判定をコマンドの Feature テストから駆動することはできない。**
> この段へ到達するには fail-secure 4 条件 (`bughunt.local` / bug-hunt DB) を満たしたうえで
> `analysis` 段を成功させる必要があり、それは実 LLM と worker を要求する。
> したがって判定の中身は**純関数 `llmRecordingIncomplete()` として Unit テストで固定する**
> (v1 で `SmokeFailureClassifier` を切り出したのと同じ理由・同じ場所。**新しいクラスは作らない**)。
> DB 読み出し (どの template の成功行があり、どれが帰属を満たすか) は
> コマンド側に残し、`llm-evidence` 段の end-to-end 確認は bug-hunt レーンの実行が担う。

### `SmokeFailureClassifierTest` の判定表

| # | 入力 | 期待 |
|---|---|---|
| 1 | stage = preflight | `Preflight` |
| 2 | timedOut ∧ jobStatus = queued | `Wiring` |
| 3 | timedOut ∧ jobStatus = running | `StageTimeout` |
| 4 | stage = render ∧ hasRenderErrorCode | `Render` |
| 5 | stage = artifact ∧ ¬outputReadable | `Storage` |
| 6 | stage = artifact ∧ outputReadable ∧ ffprobeFailed | `Render` |
| 7 | stage = analysis ∧ failed ∧ hasLlmFailureRow | `Llm` |
| 8 | stage = llm-evidence ∧ ¬hasLlmSuccessRow | `Llm` |
| 9 | stage = fixture / capture の失敗 ∧ ¬hasLlmSuccessRow | **`Unknown`** (`Llm` に漏らさない) |
| 10 | stage = capture の失敗 ∧ hasLlmFailureRow (リトライ痕) | **`Unknown`** (同上) |
| 11 | **stage = llm-evidence ∧ hasLlmSuccessRow ∧ llmRecordingIncomplete** | **`Wiring`** (v2 追加) |
| 12 | **stage = analysis ∧ failed ∧ llmRecordingIncomplete ∧ ¬hasLlmSuccessRow** | **`Llm`** (v2 追加。`Wiring` 分岐を llm-evidence 以外へ漏らさない負のコントロール) |
| 13 | 上記いずれにも一致しない失敗 | `Unknown` |
| 14 | `$stageSucceeded = true` (リトライの failure 行があっても最終成功) | **`null`** |

ケース 9・10 は Round 3 で指摘された誤分類の**負のコントロール** (v1 から維持)。
ケース 11 は「LLM は動いているのに記録が落ちた」を `Wiring` に確定させる。
ケース 12 は v2 で足した `Wiring` 分岐が他段へ漏れないことの負のコントロール。

> **`Wiring` になる 2 原因 (帰属欠落 / template 欠落) を classifier のケースに分けない。**
> classify() への入力はどちらも `llmRecordingIncomplete = true` で**同一**であり、
> 分けても導出側を検証したことにならない (v2 レビュー Round 2 の指摘どおり)。
> **原因の切り分けは導出関数 `llmRecordingIncomplete()` の責務**であり、
> 下表で独立に固定する。

### `SmokeFailureClassifier::llmRecordingIncomplete()` の判定表 (DB 不要)

`$requiredTemplates = ['sop-extract', 'work-decomposition', 'scenario-generation']` 固定で
(呼び出し側は同じ集合で `whereIn` して母集団を絞っている前提):

| # | succeededTemplates | attributedTemplates | 期待 | 意図 |
|---|---|---|---|---|
| 1 | 3 件すべて | 同じ 3 件 | `false` | 正常 (記録は完全) |
| 2 | `[]` | `[]` | **`false`** | 成功行が無い = `Llm` 側の疑い。ここで true にすると #8 が #9 を食う |
| 3 | `sop-extract`, `work-decomposition` の 2 件 | 同 2 件 | **`true`** | **必要 template の欠落** (帰属は正しいのに記録が足りない) |
| 4 | 3 件すべて | `sop-extract` の 1 件のみ | **`true`** | **帰属欠落** |
| 5 | 3 件すべて | `[]` | `true` | 全行の帰属が落ちた (`withMetadata()` 未配線そのもの) |

ケース 3 が「帰属だけを見て template 欠落を見落とす実装」を落とす回帰である。

---

## 還流 (他リポジトリへの移植)

**LLM コスト集計レポートは家系のどのリポジトリにも無い (P21)。この実装が家系初になる。**

### 移植に必要なファイル

#### A. 前提 (移植先に既にあるはず。テンプレート由来の記録層)

| ファイル | 役割 |
|---|---|
| `database/migrations/*_create_llm_call_logs_table.php` | 記録層。**本件で列を増やしていない** |
| `app/Models/LlmCallLog.php` | 記録モデル |
| `app/Services/LlmCallLogWriter.php` | 書き込み単一窓口 (`metadata_missing` 判定を含む) |
| `app/Listeners/RecordLlmCallCost.php` / `RecordLlmCallFailure.php` | イベント → 記録 |
| `app/Support/LlmMetadataExtractor.php` | metadata の厳格抽出 (汎用 4 キー) |
| `app/Services/FxRateService.php` / `app/DataTransferObjects/FxSnapshotDto.php` | JPY 換算 (JPY 列を使うなら必要) |
| `database/factories/LlmCallLogFactory.php` | 集計テストのデータ生成 |

#### B. 本件で新設する移植対象 (**そのまま持っていける**)

| ファイル | 備考 |
|---|---|
| `app/DataTransferObjects/LlmCallContextData.php` | 帰属の値オブジェクト。ドメイン語彙なし |
| `app/Enums/LlmCostGroupBy.php` | 集計軸。`llm_call_logs` の列しか知らない |
| `app/DataTransferObjects/LlmCostRowData.php` | 集計 1 行 |
| `app/DataTransferObjects/LlmCostReportData.php` | レポート全体 |
| `app/Services/LlmCostReportService.php` | 集計本体 (クエリ 2 本) |
| `app/Console/Commands/Operations/LlmCostReportCommand.php` | 期間集計の入口 |
| `tests/Unit/DataTransferObjects/LlmCallContextDataTest.php` | 〃 |
| `tests/Unit/Services/LlmCostReportServiceTest.php` | 〃 |
| `tests/Feature/Console/LlmCostReportCommandTest.php` | 〃 |

#### C. 移植先が**自分で書く**部分 (aicue 固有部分の切り離し方)

1. **`app/Prompts/*` の factory に `LlmCallContextData` を必須引数で足し、
   `->withMetadata($context->toMetadata())` を付ける。**
   これがドメインとの唯一の接点であり、リポジトリごとに subject が違う
   (aicue = `VideoManual` / 他 = 各々の対象)。`LlmCallContextData::for()` は
   `Model` を受けて `getMorphClass()` するだけなので**書き換え不要**
2. 呼び出し元 (aicue では `AnalysisPipeline`) で context を組み立てて渡す
3. `PromptUntrustedInputContractTest` を持っているなら inventory へ帰属キーを登録する
   (持っていないなら、この 2 層目の検査は省いてよい。1 層目 = 必須引数は言語が担保する)

#### D. 移植しないもの (aicue 固有)

- `PipelineSmokeCommand` と `app/{Enums,DataTransferObjects,Support}/Smoke/*`
  (aicue のパイプライン形状に強く依存する)
- `BughuntDatabaseGuard` / `scripts/bug-hunt-shard.sh` の導線
  (bug-hunt レーンを持つリポジトリのみ)
- `resources/fixtures/pipeline-smoke-sop.txt`

### 台帳 (lctl) 手順 (実装後)

1. コスト集計レポートに相当する feature は台帳に無いので **`/lctl-curate --add` で起票を依頼する**
   (新規 feature の起票はキュレーター専権であり MCP からはできない)
2. 起票されたら `status_reported` を出す。本文に上記 A〜D をそのまま含める
   (他リポジトリが移植に必要な情報 = 移植対象ファイル / 前提となる記録層の列 /
   aicue 固有部分の切り離し方)
3. **本設計フェーズでは台帳へ書き込まない**

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 施策 1 が `app/Prompts/` 全体と `AnalysisPipeline` と Architecture テストに触れ、施策 4 が既存 seeder の共有 trait に触れ、施策 7 が Architecture gate の定数に触れ、施策 8 が `scripts/bug-hunt-shard.sh` (2,528 行) に触れる。いずれも他タスクと衝突しやすい共有面であり、1 本の worktree で通して整合を取ってからマージするのが安全。また施策 2〜3・6 は互いに依存する |
| 競合リスク | `app/Prompts/*` / `scripts/bug-hunt-shard.sh` / `tests/Architecture/*` / `AGENTS.md` を同時に触る他タスクがあれば衝突する |

### 実装順序 (依存順)

1. 施策 1 (帰属配線) — **最初**。これが入って初めて施策 2 の `subject` / `organization` 軸に意味が出る
2. 施策 2 → 施策 3
3. 施策 4 → 施策 5 → 施策 6 → 施策 7 → 施策 8
4. 施策 9 / 10 は各施策と同時 (テストファースト)

## 実装後の検証コマンド

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`scripts/bug-hunt-shard.sh self-test` (実資源に触れない)。
**`dev:pipeline-smoke` 自体は CI で実行しない** (実 provider へ出て課金が発生するため)。

## 最終確認 (使命・禁止事項チェック)

| 観点 | 結果 |
|---|---|
| 使命への寄与 | バリューチェーン (SOP → シナリオ → ナビ撮影 → 合成) が**実際に最後まで回ること**を機械で確認する。加えて、その 1 回にかかった費用が**どの組織のどのマニュアルの分か**まで記録されるようになる |
| オーナー指示 A (記録側を先に直す) | 施策 1。記録層の列は増やさない (P16) |
| オーナー指示 B (集計層は薄く) | 施策 2。v1 から軸 1 本・行 DTO 3 フィールドを削り、**GROUP BY キーへ適用する SQL 関数をゼロ**にした (集計値の `COUNT` / `SUM` / `COALESCE` は使う)。残した 4 ファイルは各々「削れない理由」を表で明示 |
| オーナー指示 C (1 実装・複数入口) | 集計は `LlmCostReportService` 1 本。入口は smoke 末尾と `operations:llm-cost-report` の 2 つ |
| オーナー指示 D (還流前提) | 集計層は `llm_call_logs` の列しか知らない。subject は多態。移植ファイル一覧を §還流 に記載 |
| 禁止事項 1 (テストなし完了) | 施策 10 に全施策のテストを列挙。帰属配線は型 + reflection + smoke の 3 層で固定し、**テストレーンで検証できない範囲を明示** |
| 禁止事項 2 (PHPStan widen) | 各施策に PHPStan 適合チェックあり。`SUM()` の driver 差は DTO 生成境界で `Assert` により検査 |
| 禁止事項 3 (dev DB 破壊) | fail-secure 4 条件が bug-hunt DB 以外での実行を拒否する (`--force` でも迂回不可) |
| 禁止事項 4 (`response()->json()`) | HTTP 面を持たない。出力は DTO の `toArray()` → `json_encode` の 1 経路 |
| 禁止事項 5 (Prism 直呼び) | LLM は既存の `app/Prompts/` factory 経由。**帰属をその factory に置いたので、迂回経路が構造的に存在しない** |
| 禁止事項 6 (prompt 直書き) | prompt は `resources/prompts/*.yaml` のまま。fixture は入力データとして `resources/fixtures/` |
| 禁止事項 7・8 (UI 系) | UI 変更なし |
| 禁止事項 9 (Artifact) | 成果物はリポジトリ内ファイルのみ |
| セキュリティ不変条件 1 (tenant キー不信) | presigned URL を parse しない / payload から tenant キーを復元しない。**帰属メタデータはサーバ側で解決した値のみ** (`resolveOrganization()` / relation 経由) |
| セキュリティ不変条件 3 (クラス起点の主キー同一性クエリ) | 予約行は `$cut->uploadReservations()` 経由で解決 |
| セキュリティ不変条件 4 (untrusted は UserInput 経由) | 変更なし。`PromptUntrustedInputContractTest` の 1 層目がそのまま守る |
| 外部到達点の目録 | `Http` facade を新たに参照しない。`ExternalSeamInventory` に変更なし |
| テストレーンの HTTP / LLM 既定拒否 | opt-out しない。実 LLM は bug-hunt レーンでのみ呼ぶ |
| 思考原則 2 (今必要なものだけ) | 集計は 1 実装 2 入口。Filament 画面・Excel/PDF・スケジュール実行・日次軸・所要時間統計・帰属用の新列はすべて作らない |
