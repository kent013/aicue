【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。factory は `LlmCallContextData` を必須引数で受け `withMetadata()` で帰属を付ける
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(DESIGN.md)
9. Artifact の使用

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: あなたの役割

Laravel 12 + PHP 8.4 + Svelte 5 のアプリ (aicue) の**実装レビュアー**として、詳細設計と実装差分を突き合わせてレビューせよ。

### レビュー観点

1. **設計との一致性**: 詳細設計 (v2, Codex 合議 APPROVED 済み) の施策 1〜10 が実装されているか。意図的な逸脱があるなら妥当か
2. **正確性**: ロジックの誤り・境界条件・並行性・失敗時の挙動
3. **PHPStan level 10 適合性**: 型の widen / 不要な cast / mixed の漏れ
4. **DTO パターン**: `response()->json()` 直書き禁止、DTO + `toArray()` の 1 経路
5. **テスト網羅性**: 各施策にテストがあるか。**テストで検証できないことを「検証した」と書いていないか**
6. **セキュリティ**: tenant 境界、payload 不信任、主キー同一性クエリの目録、秘密のログ出力
7. **費用の防壁**: 実 LLM を 3 段呼ぶコマンドであり、誤起動が課金に直結する。fail-secure 条件・確認プロンプト・orchestrator gate が構造的に迂回できないか
8. **還流性**: 集計層 (`LlmCostGroupBy` / `LlmCost*Data` / `LlmCostReportService` / `LlmCostReportCommand` / `LlmCallContextData`) に aicue のドメイン語彙 (マニュアル / カット / 撮影) が漏れていないか。他リポジトリがそのまま持っていける形か

### 出力形式

- ファイルごとに判定
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類。Critical は「このままマージすると壊れる / 危険」に限る
- 最後に**全体判定: APPROVED または CHANGES_REQUESTED** を明記

### 前提 (レビュー時に踏まえること)

- UI (resources/js) の変更は無い。DESIGN.md / Atomic Design 観点は対象外
- **実 LLM を呼ぶ本実行はまだしていない** (費用が出るためオーナー判断)。`--check` (費用ゼロ preflight) のみ実行済み
- テストレーンでは帰属の DB 記録は検証できない (`Prompt::$fake` が event を発火せず `PromptFake::record()` は metadata を記録しない)。この限界は設計に明記されており、実装もその範囲でしか主張していないかを見てほしい

---

## user: 詳細設計書

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

## 実装差分 (git diff HEAD -- app/ tests/ database/ scripts/ resources/fixtures/)

```diff
diff --git a/app/Console/Commands/Development/PipelineSmokeCommand.php b/app/Console/Commands/Development/PipelineSmokeCommand.php
new file mode 100644
index 0000000..8d30ebc
--- /dev/null
+++ b/app/Console/Commands/Development/PipelineSmokeCommand.php
@@ -0,0 +1,925 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\Development;
+
+use App\DataTransferObjects\Capture\Sha256Checksum;
+use App\DataTransferObjects\Capture\TakeRegistrationInput;
+use App\DataTransferObjects\Capture\TakeUploadInput;
+use App\DataTransferObjects\LlmCostReportData;
+use App\DataTransferObjects\LlmCostRowData;
+use App\DataTransferObjects\Smoke\SmokeRunResultData;
+use App\DataTransferObjects\Smoke\SmokeStageResultData;
+use App\Enums\LlmCostGroupBy;
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\TakeStatus;
+use App\Enums\Manual\VideoManualStatus;
+use App\Enums\Smoke\SmokeFailureClass;
+use App\Enums\Smoke\SmokeStage;
+use App\Jobs\Manual\RunManualAnalysis;
+use App\Jobs\Manual\RunManualRender;
+use App\Models\AnalysisJob;
+use App\Models\Cut;
+use App\Models\LlmCallLog;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Capture\CaptureTakeService;
+use App\Services\Capture\TakeRegistrationService;
+use App\Services\Capture\TakeUploadService;
+use App\Services\LlmCostReportService;
+use App\Services\Manual\AnalysisJobService;
+use App\Services\Manual\RenderJobService;
+use App\Services\Manual\VideoManualService;
+use App\Services\Project\DefaultProjectResolver;
+use App\Services\Project\ProjectService;
+use App\Services\Storage\Fakes\FakeObjectStore;
+use App\Support\BughuntDatabaseGuard;
+use App\Support\FakeStorageGate;
+use App\Support\Smoke\SmokeFailureClassifier;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Command;
+use Illuminate\Console\ConfirmableTrait;
+use Illuminate\Database\Eloquent\Builder;
+use Illuminate\Database\QueryException;
+use Illuminate\Http\UploadedFile;
+use Illuminate\Support\Facades\File;
+use Illuminate\Support\Facades\Process;
+use Illuminate\Support\Str;
+use RuntimeException;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * SOP 投入 → AI 解析 → 撮影テイク → ffmpeg 合成 → mp4 の全段が通ることを
+ * **実 LLM** で確認する通し確認コマンド (bug-hunt 専用・課金あり)。
+ *
+ * ★ **品質は一切判定しない**。見るのは「期待した状態遷移が起きたか」だけである
+ *   (字幕の文言・カット数の妥当性・尺の妥当性は判定しない)。
+ * ★ **保証しないもの**の一覧は `docs/architecture.md` §パイプライン通し確認 が正本。
+ * ★ constructor は引数を持たない。すべての依存は fail-secure 4 条件を通過した**後**に
+ *   `handle()` 内で遅延解決する (`artisan list` / `help` を含むあらゆる artisan 起動で
+ *   コマンドが構築されるため、fake が gate 判定より前に解決されるのを防ぐ)。
+ */
+class PipelineSmokeCommand extends Command
+{
+    use ConfirmableTrait;
+
+    /** worker 待ちのポーリング間隔 (秒)。 */
+    private const int POLL_INTERVAL_SECONDS = 2;
+
+    /** ジョブ側 timeout に足す余裕 (秒)。上限値はジョブ定数から導出し独立したリテラルを置かない。 */
+    private const int WAIT_MARGIN_SECONDS = 120;
+
+    /** テイク動画の尺 (秒)。1 本だけ生成して全 cut で使い回す。 */
+    private const int TAKE_SECONDS = 2;
+
+    /** 外部プロセスの実行上限 (秒)。 */
+    private const int PROCESS_TIMEOUT_SECONDS = 120;
+
+    /**
+     * `llm-evidence` 段で成功行を要求する prompt_template (母集団もこの集合で絞る)。
+     *
+     * @var list<string>
+     */
+    private const array REQUIRED_TEMPLATES = ['sop-extract', 'work-decomposition', 'scenario-generation'];
+
+    /** @var string */
+    protected $signature = 'dev:pipeline-smoke
+        {--check : preflight だけ実行して終了する (LLM を 1 回も呼ばない = 費用ゼロ)}
+        {--org= : 対象組織 id (省略時は条件を満たす先頭の組織)}
+        {--json : 機械可読出力}
+        {--force : 実行確認を省略する (fail-secure 条件は迂回できない)}';
+
+    /** @var string */
+    protected $description = 'SOP 投入→AI 解析→撮影テイク→ffmpeg 合成→mp4 の全段が通ることを実 LLM で確認する (bug-hunt 専用・課金あり)。';
+
+    /** @var list<SmokeStageResultData> 実行済みの段 */
+    private array $stages = [];
+
+    /** @var array<string, string> 実行対象の表示 (env / db / org / ffmpeg 版など) */
+    private array $context = [];
+
+    /** この実行分の境界 (llm_call_logs.id)。`--check` では取らない。 */
+    private ?int $baselineId = null;
+
+    public function handle(): int
+    {
+        $startedAt = CarbonImmutable::now();
+
+        // ── fail-secure 4 条件 (--force でも迂回できない) ──────────────
+        $blocker = $this->failSecureBlocker();
+        if ($blocker !== null) {
+            $this->error("fail-secure 条件を満たしていないため実行しません: {$blocker}");
+
+            return self::FAILURE;
+        }
+
+        // ── preflight (--check はここまでで終了。DB を 1 行も変更しない) ──
+        $preflight = $this->runPreflight();
+        if ($preflight === null) {
+            return $this->finish($startedAt, checkOnly: (bool) $this->option('check'), cost: null);
+        }
+        [$organization, $actor] = $preflight;
+
+        if ($this->option('check') === true) {
+            return $this->finish($startedAt, checkOnly: true, cost: null);
+        }
+
+        if (! $this->confirmToProceed($this->costWarning(), true)) {
+            // ★第 2 引数 true = **常に**確認する。既定 callback は production でしか確認しないため、
+            //   bughunt.local では確認が一度も出ないまま課金が走ってしまう。
+            $this->warn('中止しました (何も実行していません)。');
+
+            return self::INVALID;
+        }
+
+        // 「この実行分」の境界。preflight 通過直後・fixture 段より前に 1 回だけ取る
+        // (将来どの段で LLM が増えても取りこぼさない)。0 件時は 0 = 全行対象。
+        $maxId = LlmCallLog::query()->max('id');
+        $this->baselineId = is_numeric($maxId) ? (int) $maxId : 0;
+
+        $workDir = storage_path('app/smoke/'.Str::ulid()->toString());
+        File::ensureDirectoryExists($workDir);
+
+        try {
+            $this->runStages($organization, $actor, $workDir);
+        } finally {
+            File::deleteDirectory($workDir);
+        }
+
+        return $this->finish($startedAt, checkOnly: false, cost: $this->costReport());
+    }
+
+    // ─────────────────────────────────────────────────────────────────
+    // fail-secure / preflight
+    // ─────────────────────────────────────────────────────────────────
+
+    /**
+     * fail-secure 4 条件。満たさない最初の条件の説明を返す (満たしていれば null)。
+     *
+     * 4 は**自プロセスの config** であり worker の設定は見ていない。worker が fake なら
+     * 「llm_call_logs の記録行が 0」として段 llm-evidence で落ちる (2 層で守る)。
+     */
+    private function failSecureBlocker(): ?string
+    {
+        if (! $this->laravel->environment('bughunt.local')) {
+            return 'env が bughunt.local ではありません (実 LLM / 実 ffmpeg / チケット消費を dev / production で走らせない)';
+        }
+        if (! app(BughuntDatabaseGuard::class)->isBughuntDatabase()) {
+            return '接続先が bug-hunt DB ではありません (dev DB へ fixture をばら撒かない)';
+        }
+        if (! app(FakeStorageGate::class)->enabled()) {
+            return 'fake storage が無効です (実 S3 へ書かない)';
+        }
+        if (config('testing.fake_llm') !== false) {
+            return 'fake LLM が有効です (fake のまま「通った」と報告しない)';
+        }
+
+        return null;
+    }
+
+    /**
+     * preflight。成功したら [対象組織, actor] を返し、失敗したら null を返す
+     * (`--check` の成功時も [組織, actor] を返し、呼び出し側がそこで打ち切る)。
+     *
+     * @return array{Organization, User}|null
+     */
+    private function runPreflight(): ?array
+    {
+        $startedAt = CarbonImmutable::now();
+        $this->context['env'] = (string) $this->laravel->environment();
+        $this->context['fake_storage'] = 'on';
+        $this->context['fake_llm'] = 'off';
+
+        $ffmpegVersion = $this->probeBinary(config()->string('manual.render_ffmpeg_binary'));
+        $ffprobeVersion = $this->probeBinary(config()->string('manual.render_ffprobe_binary'));
+        $this->context['ffmpeg'] = $ffmpegVersion ?? 'MISSING';
+        $this->context['ffprobe'] = $ffprobeVersion ?? 'MISSING';
+        if ($ffmpegVersion === null || $ffprobeVersion === null) {
+            return $this->failPreflight($startedAt, 'ffmpeg / ffprobe を実行できません (manual.render_ffmpeg_binary / render_ffprobe_binary)');
+        }
+
+        foreach (['database-analysis', 'database-render'] as $connection) {
+            if (config("queue.connections.{$connection}") === null) {
+                return $this->failPreflight($startedAt, "queue connection {$connection} が未定義です");
+            }
+        }
+
+        $fixture = $this->fixturePath();
+        $contents = is_file($fixture) ? file_get_contents($fixture) : false;
+        if (! is_string($contents) || strlen($contents) < config()->integer('manual.analysis_min_text_bytes')) {
+            return $this->failPreflight($startedAt, "SOP fixture が読めないか短すぎます: {$fixture}");
+        }
+
+        // DB へ触る検査はここから。未 provision / 未 migrate の bug-hunt DB では例外になるが、
+        // それも preflight の失敗として扱う (--json の契約を壊さず、原因を段の detail に残す)。
+        try {
+            $organization = $this->resolveOrganization();
+        } catch (QueryException $exception) {
+            return $this->failPreflight(
+                $startedAt,
+                'DB を読めません (bug-hunt DB が未 provision / 未 migrate の可能性): '.self::describe($exception),
+            );
+        }
+        if ($organization === null) {
+            return $this->failPreflight($startedAt, '条件を満たす組織が見つかりません (チケット残高と所属 user を確認してください)');
+        }
+        $this->context['org'] = '#'.$organization->id;
+
+        /** @var User|null $actor */
+        $actor = $organization->users()->orderBy('users.id')->first();
+        if ($actor === null) {
+            return $this->failPreflight($startedAt, "組織 #{$organization->id} に所属 user がいません");
+        }
+        $this->context['actor'] = '#'.$actor->id;
+
+        $balance = app(TicketLedgerService::class)->availableTrueBalance($organization);
+        $required = $this->requiredTickets();
+        $this->context['tickets'] = "{$balance} (required {$required})";
+        if ($balance < $required) {
+            return $this->failPreflight($startedAt, "チケット残高が不足しています (残高 {$balance} / 必要 {$required})");
+        }
+
+        // Project 不在は preflight の失敗にしない (fixture 段で作る)
+        $project = app(DefaultProjectResolver::class)->resolve($organization);
+        $this->context['project'] = $project === null ? 'will-create' : 'existing #'.$project->id;
+
+        $this->recordStage(SmokeStage::Preflight, true, $startedAt, 'ok', null);
+
+        return [$organization, $actor];
+    }
+
+    /** preflight 失敗の記録 (段の detail に理由をそのまま出す)。 */
+    private function failPreflight(CarbonImmutable $startedAt, string $reason): null
+    {
+        $this->recordStage(
+            SmokeStage::Preflight,
+            false,
+            $startedAt,
+            $reason,
+            SmokeFailureClassifier::classify(
+                SmokeStage::Preflight, false, null, false, false, false, false, false, true, false,
+            ),
+        );
+
+        return null;
+    }
+
+    /** `{binary} -version` の 1 行目 (実行できなければ null)。 */
+    private function probeBinary(string $binary): ?string
+    {
+        try {
+            $result = Process::timeout(self::PROCESS_TIMEOUT_SECONDS)->run([$binary, '-version']);
+        } catch (Throwable) {
+            return null;
+        }
+        if (! $result->successful()) {
+            return null;
+        }
+
+        $firstLine = strtok($result->output(), "\n");
+
+        return $firstLine === false ? 'unknown' : trim($firstLine);
+    }
+
+    /**
+     * 対象組織の解決。`--org` 指定があればその組織、無ければ条件を満たす先頭の組織。
+     *
+     * ★ `--org` の主キー指定は「運用者が CLI で組織を名指しする」形であり
+     *   `DirectFetchInventory` へ `OperatorInvokedConsoleCommand` として登録済み。
+     */
+    private function resolveOrganization(): ?Organization
+    {
+        $option = $this->option('org');
+        if (is_string($option) && $option !== '') {
+            if (! ctype_digit($option)) {
+                return null;
+            }
+
+            /** @var Organization|null */
+            return Organization::query()->whereKey((int) $option)->first();
+        }
+
+        $required = $this->requiredTickets();
+        $tickets = app(TicketLedgerService::class);
+        foreach (Organization::query()->orderBy('id')->cursor() as $organization) {
+            if (! $organization->users()->exists()) {
+                continue;
+            }
+            if ($tickets->availableTrueBalance($organization) >= $required) {
+                return $organization;
+            }
+        }
+
+        return null;
+    }
+
+    /** 1 回の通し確認が消費するチケット枚数 (解析 + レンダ)。 */
+    private function requiredTickets(): int
+    {
+        return config()->integer('manual.analysis_ticket_cost')
+            + config()->integer('manual.render_ticket_cost');
+    }
+
+    /** 確認プロンプトに出す警告文 (見積りは断定しない)。 */
+    private function costWarning(): string
+    {
+        return '実 LLM を 3 段呼び出し、チケットを '.$this->requiredTickets().' 枚消費します。'
+            .' 1 回あたりおよそ $0.07〜0.12 (リトライが起きると最大 3 倍程度)。'
+            .' 実測値は実行後のコストレポートに出ます。';
+    }
+
+    // ─────────────────────────────────────────────────────────────────
+    // 段の実行
+    // ─────────────────────────────────────────────────────────────────
+
+    private function runStages(Organization $organization, User $actor, string $workDir): void
+    {
+        $fixture = $this->runFixtureStage($organization, $actor, $workDir);
+        if ($fixture === null) {
+            return;
+        }
+        [$project, $manual] = $fixture;
+
+        if (! $this->runAnalysisStage($project, $manual, $actor)) {
+            return;
+        }
+        if (! $this->runLlmEvidenceStage($organization, $manual)) {
+            return;
+        }
+        if (! $this->runCaptureStage($organization, $project, $manual, $workDir)) {
+            return;
+        }
+        $renderJob = $this->runRenderStage($project, $manual, $actor);
+        if ($renderJob === null) {
+            return;
+        }
+        $this->runArtifactStage($renderJob, $workDir);
+    }
+
+    /**
+     * fixture 段: Default Project (不在時のみ作成) + SOP つき manual の作成。
+     *
+     * @return array{Project, VideoManual}|null
+     */
+    private function runFixtureStage(Organization $organization, User $actor, string $workDir): ?array
+    {
+        $startedAt = CarbonImmutable::now();
+        try {
+            $project = app(DefaultProjectResolver::class)->resolve($organization)
+                ?? app(ProjectService::class)->createProject($organization, 'pipeline-smoke', null);
+
+            // UploadedFile は保存時に元ファイルを触りうるため、fixture 本体ではなく複製を渡す
+            $localCopy = $workDir.'/pipeline-smoke-sop.txt';
+            File::copy($this->fixturePath(), $localCopy);
+
+            $manual = app(VideoManualService::class)->create(
+                $project,
+                'pipeline-smoke '.CarbonImmutable::now()->format('Y-m-d H:i'),
+                null,
+                $actor->id,
+                new UploadedFile($localCopy, 'pipeline-smoke-sop.txt', 'text/plain', null, test: true),
+            );
+
+            $documents = $manual->sourceDocuments()->count();
+            $ok = $manual->status === VideoManualStatus::Draft && $documents === 1;
+            $detail = "manual=#{$manual->id} documents={$documents} status={$manual->status->value}";
+
+            return $this->gate(SmokeStage::Fixture, $ok, $startedAt, $detail) ? [$project, $manual] : null;
+        } catch (Throwable $exception) {
+            $this->gate(SmokeStage::Fixture, false, $startedAt, self::describe($exception));
+
+            return null;
+        }
+    }
+
+    /** analysis 段: 解析ジョブを起票し worker の完了を待つ。 */
+    private function runAnalysisStage(Project $project, VideoManual $manual, User $actor): bool
+    {
+        $startedAt = CarbonImmutable::now();
+        try {
+            $job = app(AnalysisJobService::class)->trigger($project, $manual, $actor);
+        } catch (Throwable $exception) {
+            return $this->gate(SmokeStage::Analysis, false, $startedAt, self::describe($exception));
+        }
+
+        $timeout = (new RunManualAnalysis(0))->timeout + self::WAIT_MARGIN_SECONDS;
+        [$status, $timedOut] = $this->waitForJob($job, $timeout);
+
+        $manual->refresh();
+        $cuts = $manual->cuts()->count();
+        $ok = $status === JobStatus::Succeeded
+            && $manual->status === VideoManualStatus::Ready
+            && $cuts >= 1
+            && $manual->scenario_version >= 1;
+        $detail = "job=#{$job->id} status={$status->value} cuts={$cuts}"
+            ." scenario_version={$manual->scenario_version}"
+            .($job->error === null ? '' : " error={$job->error}")
+            .($job->step === null ? '' : " step={$job->step->value}");
+
+        return $this->gate(SmokeStage::Analysis, $ok, $startedAt, $detail, $status, $timedOut);
+    }
+
+    /**
+     * llm-evidence 段 (DB 読み取りのみ): 実呼び出しの証拠と帰属の証拠。
+     *
+     * これが「施策 1 の配線が実 LLM 経路で本当に効いていること」の唯一の機械的な確認である
+     * (テストレーンでは Prompt::$fake がイベントを発火しないため観測できない)。
+     */
+    private function runLlmEvidenceStage(Organization $organization, VideoManual $manual): bool
+    {
+        $startedAt = CarbonImmutable::now();
+
+        $succeeded = [];
+        $attributed = [];
+        $mismatches = [];
+        foreach ($this->runScopedLogs()->whereNull('failure_reason')->where('input_tokens', '>', 0)->get() as $log) {
+            $template = $log->prompt_template;
+            if ($template === null) {
+                continue;
+            }
+            $succeeded[$template] = true;
+
+            $expectedType = $manual->getMorphClass();
+            $expectedId = (string) $manual->id;
+            if (! $log->metadata_missing
+                && $log->organization_id === $organization->id
+                && $log->subject_type === $expectedType
+                && $log->subject_id === $expectedId) {
+                $attributed[$template] = true;
+
+                continue;
+            }
+            $mismatches[] = sprintf(
+                '%s: organization_id=%s subject_type=%s subject_id=%s metadata_missing=%s'
+                .' (期待 organization_id=%d subject_type=%s subject_id=%s)',
+                $template,
+                $log->organization_id === null ? 'null' : (string) $log->organization_id,
+                $log->subject_type ?? 'null',
+                $log->subject_id ?? 'null',
+                $log->metadata_missing ? 'true' : 'false',
+                $organization->id,
+                $expectedType,
+                $expectedId,
+            );
+        }
+
+        $succeededTemplates = array_keys($succeeded);
+        $attributedTemplates = array_keys($attributed);
+        $missingTemplates = array_values(array_diff(self::REQUIRED_TEMPLATES, $succeededTemplates));
+        $incomplete = SmokeFailureClassifier::llmRecordingIncomplete(
+            self::REQUIRED_TEMPLATES,
+            $succeededTemplates,
+            $attributedTemplates,
+        );
+
+        $ok = $missingTemplates === [] && ! $incomplete;
+        $detail = sprintf(
+            'succeeded=%d/%d attributed=%d/%d retry_rows=%d',
+            count($succeededTemplates),
+            count(self::REQUIRED_TEMPLATES),
+            count($attributedTemplates),
+            count(self::REQUIRED_TEMPLATES),
+            $this->runScopedLogs()->whereNotNull('failure_reason')->count(),
+        );
+        if ($missingTemplates !== []) {
+            $detail .= ' 成功行が無い template: '.implode(', ', $missingTemplates);
+        }
+        if ($mismatches !== []) {
+            $detail .= ' 帰属が期待と違う行: '.implode(' / ', $mismatches);
+        }
+
+        return $this->gate(
+            SmokeStage::LlmEvidence,
+            $ok,
+            $startedAt,
+            $detail,
+            llmRecordingIncomplete: $incomplete,
+        );
+    }
+
+    /** capture 段: 全 cut にテイクを 1 本ずつ置いて採用する (動画は 1 本生成して使い回す)。 */
+    private function runCaptureStage(
+        Organization $organization,
+        Project $project,
+        VideoManual $manual,
+        string $workDir,
+    ): bool {
+        $startedAt = CarbonImmutable::now();
+        try {
+            $takePath = $this->generateTakeVideo($workDir);
+            $sizeBytes = filesize($takePath);
+            Assert::integer($sizeBytes, 'テイク動画のサイズを取得できません');
+            $digest = hash_file('sha256', $takePath, binary: true);
+            Assert::string($digest, 'テイク動画の sha256 を計算できません');
+            $checksum = Sha256Checksum::fromBase64(base64_encode($digest));
+
+            $adopted = 0;
+            /** @var Cut $cut */
+            foreach ($manual->cuts()->orderBy('id')->get() as $cut) {
+                $this->uploadAndAdoptTake($organization, $project, $manual, $cut, $takePath, $sizeBytes, $checksum);
+                $adopted++;
+            }
+
+            // 採用テイクの有無は relation 経由で数える (採用キーの列名を持ち出さない)
+            $unadopted = $manual->cuts()->doesntHave('adoptedTake')->count();
+            $ok = $adopted >= 1 && $unadopted === 0;
+
+            return $this->gate(SmokeStage::Capture, $ok, $startedAt, "takes={$adopted} unadopted={$unadopted}");
+        } catch (Throwable $exception) {
+            return $this->gate(SmokeStage::Capture, false, $startedAt, self::describe($exception));
+        }
+    }
+
+    /** 1 cut 分の presign → オブジェクト書き込み → 登録 → 採用。 */
+    private function uploadAndAdoptTake(
+        Organization $organization,
+        Project $project,
+        VideoManual $manual,
+        Cut $cut,
+        string $takePath,
+        int $sizeBytes,
+        Sha256Checksum $checksum,
+    ): void {
+        $clientTakeId = Str::ulid()->toString();
+        $ticket = app(TakeUploadService::class)->issue(
+            $organization,
+            $project,
+            $manual,
+            $cut,
+            new TakeUploadInput($clientTakeId, $sizeBytes, 'video/mp4', $checksum),
+        );
+
+        // 予約行は必ず organization → project → manual → cut の確認済み relation から辿る
+        // (presigned URL を parse して key を復元しない / payload から tenant キーを復元しない)
+        $reservation = $cut->uploadReservations()
+            ->where('client_take_id', $clientTakeId)
+            ->latest('id')
+            ->firstOrFail();
+
+        $stream = fopen($takePath, 'rb');
+        Assert::resource($stream, null, 'テイク動画を開けません');
+        try {
+            app(FakeObjectStore::class)->storeStreamed(
+                $reservation->video_path,
+                $stream,
+                'video/mp4',
+                $checksum->base64,
+            );
+        } finally {
+            fclose($stream);
+        }
+
+        $result = app(TakeRegistrationService::class)->register(
+            $project,
+            $manual,
+            $cut,
+            new TakeRegistrationInput($ticket->ticket, $clientTakeId, self::TAKE_SECONDS * 1000, null),
+        );
+        if ($result->take->status !== TakeStatus::Ready) {
+            throw new RuntimeException("テイクが ready になりません: take=#{$result->take->id} status={$result->take->status->value}");
+        }
+
+        app(CaptureTakeService::class)->adopt($project, $manual, $cut, $result->take);
+    }
+
+    /** ダミーのテイク動画を 1 本だけ生成する (全 cut で使い回す)。 */
+    private function generateTakeVideo(string $workDir): string
+    {
+        $path = $workDir.'/take.mp4';
+        $result = Process::path($workDir)->timeout(self::PROCESS_TIMEOUT_SECONDS)->run([
+            config()->string('manual.render_ffmpeg_binary'), '-y',
+            '-f', 'lavfi', '-i', 'testsrc2=size=640x360:rate=30:duration='.self::TAKE_SECONDS,
+            '-f', 'lavfi', '-i', 'sine=frequency=440:duration='.self::TAKE_SECONDS,
+            '-c:v', 'libx264', '-preset', 'veryfast', '-pix_fmt', 'yuv420p',
+            '-c:a', 'aac', '-ar', '48000', '-ac', '2', '-shortest',
+            $path,
+        ]);
+        if (! $result->successful() || ! is_file($path)) {
+            throw new RuntimeException('テイク動画を生成できません: '.trim($result->errorOutput()));
+        }
+
+        return $path;
+    }
+
+    /** render 段: レンダジョブを起票し worker の完了を待つ。 */
+    private function runRenderStage(Project $project, VideoManual $manual, User $actor): ?RenderJob
+    {
+        $startedAt = CarbonImmutable::now();
+        try {
+            $job = app(RenderJobService::class)->trigger($project, $manual, $actor);
+        } catch (Throwable $exception) {
+            $this->gate(SmokeStage::Render, false, $startedAt, self::describe($exception));
+
+            return null;
+        }
+
+        $timeout = (new RunManualRender(0))->timeout + self::WAIT_MARGIN_SECONDS;
+        [$status, $timedOut] = $this->waitForJob($job, $timeout);
+
+        $manual->refresh();
+        $ok = $status === JobStatus::Succeeded
+            && $manual->status === VideoManualStatus::Published
+            && $job->output_path !== null;
+        $detail = "job=#{$job->id} status={$status->value} manual_status={$manual->status->value}"
+            .' output='.($job->output_path ?? 'null')
+            .($job->error_code === null ? '' : " error_code={$job->error_code->value}");
+
+        $passed = $this->gate(
+            SmokeStage::Render,
+            $ok,
+            $startedAt,
+            $detail,
+            $status,
+            $timedOut,
+            hasRenderErrorCode: $job->error_code !== null,
+        );
+
+        return $passed ? $job : null;
+    }
+
+    /** artifact 段: 出力オブジェクトを ffprobe で読む (品質は見ない。尺 > 0 と映像ストリームのみ)。 */
+    private function runArtifactStage(RenderJob $job, string $workDir): bool
+    {
+        $startedAt = CarbonImmutable::now();
+        $outputPath = $job->output_path;
+        Assert::stringNotEmpty($outputPath, 'render 段の成功条件が output_path 非 null を保証している');
+
+        $store = app(FakeObjectStore::class);
+        if ($store->head($outputPath) === null) {
+            return $this->gate(
+                SmokeStage::Artifact, false, $startedAt,
+                "出力オブジェクトを読み出せません: {$outputPath}",
+                outputReadable: false,
+            );
+        }
+
+        $local = $workDir.'/output.mp4';
+        File::copy($store->absolutePath($outputPath), $local);
+
+        $probe = Process::timeout(self::PROCESS_TIMEOUT_SECONDS)->run([
+            config()->string('manual.render_ffprobe_binary'),
+            '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $local,
+        ]);
+        if (! $probe->successful()) {
+            return $this->gate(
+                SmokeStage::Artifact, false, $startedAt,
+                'ffprobe が失敗しました: '.trim($probe->errorOutput()),
+                ffprobeFailed: true,
+            );
+        }
+
+        [$duration, $videoStreams] = self::readProbe($probe->output());
+        $ok = $videoStreams >= 1 && $duration > 0.0;
+
+        return $this->gate(
+            SmokeStage::Artifact,
+            $ok,
+            $startedAt,
+            sprintf('duration=%.2fs video_streams=%d', $duration, $videoStreams),
+            ffprobeFailed: false,
+        );
+    }
+
+    /**
+     * ffprobe の JSON から [尺 (秒), 映像ストリーム数] を取り出す。
+     *
+     * @return array{float, int}
+     */
+    private static function readProbe(string $json): array
+    {
+        /** @var mixed $decoded */
+        $decoded = json_decode($json, true);
+        if (! is_array($decoded)) {
+            return [0.0, 0];
+        }
+
+        $format = $decoded['format'] ?? null;
+        $duration = 0.0;
+        if (is_array($format) && isset($format['duration']) && is_numeric($format['duration'])) {
+            $duration = (float) $format['duration'];
+        }
+
+        $streams = $decoded['streams'] ?? null;
+        $videoStreams = 0;
+        if (is_array($streams)) {
+            foreach ($streams as $stream) {
+                if (is_array($stream) && ($stream['codec_type'] ?? null) === 'video') {
+                    $videoStreams++;
+                }
+            }
+        }
+
+        return [$duration, $videoStreams];
+    }
+
+    // ─────────────────────────────────────────────────────────────────
+    // 待機・記録・出力
+    // ─────────────────────────────────────────────────────────────────
+
+    /**
+     * worker の完了待ち。失敗は待たずに即座に打ち切る。
+     *
+     * @return array{JobStatus, bool} [観測した状態, 上限に到達したか]
+     */
+    private function waitForJob(AnalysisJob|RenderJob $job, int $timeoutSeconds): array
+    {
+        $deadline = CarbonImmutable::now()->addSeconds($timeoutSeconds);
+        while (true) {
+            $job->refresh(); // 主キー同一性クエリを書かずに再読込する (インスタンス起点)
+            if ($job->status === JobStatus::Succeeded || $job->status === JobStatus::Failed) {
+                return [$job->status, false];
+            }
+            if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
+                return [$job->status, true];
+            }
+            sleep(self::POLL_INTERVAL_SECONDS);
+        }
+    }
+
+    /** 段の結果を記録し、成功なら true を返す (呼び出し側はこれで打ち切りを判断する)。 */
+    private function gate(
+        SmokeStage $stage,
+        bool $ok,
+        CarbonImmutable $startedAt,
+        string $detail,
+        ?JobStatus $jobStatus = null,
+        bool $timedOut = false,
+        bool $hasRenderErrorCode = false,
+        bool $outputReadable = true,
+        bool $ffprobeFailed = false,
+        bool $llmRecordingIncomplete = false,
+    ): bool {
+        $failureClass = SmokeFailureClassifier::classify(
+            $stage,
+            $ok,
+            $jobStatus,
+            $timedOut,
+            $this->hasLlmFailureRow(),
+            $this->hasLlmSuccessRow(),
+            $llmRecordingIncomplete,
+            $hasRenderErrorCode,
+            $outputReadable,
+            $ffprobeFailed,
+        );
+        $this->recordStage($stage, $ok, $startedAt, $detail, $failureClass);
+
+        return $ok;
+    }
+
+    private function recordStage(
+        SmokeStage $stage,
+        bool $ok,
+        CarbonImmutable $startedAt,
+        string $detail,
+        ?SmokeFailureClass $failureClass,
+    ): void {
+        $this->stages[] = new SmokeStageResultData(
+            stage: $stage,
+            ok: $ok,
+            elapsedMs: self::elapsedMs($startedAt),
+            detail: $detail,
+            failureClass: $failureClass,
+        );
+    }
+
+    /**
+     * この実行分の llm_call_logs (母集団は必ず 3 template に絞る)。
+     *
+     * @return Builder<LlmCallLog>
+     */
+    private function runScopedLogs(): Builder
+    {
+        return LlmCallLog::query()
+            ->where('id', '>', $this->baselineId ?? 0)   // 順序比較 = 主キー同一性クエリではない
+            ->whereIn('prompt_template', self::REQUIRED_TEMPLATES);
+    }
+
+    private function hasLlmFailureRow(): bool
+    {
+        return $this->baselineId !== null && $this->runScopedLogs()->whereNotNull('failure_reason')->exists();
+    }
+
+    private function hasLlmSuccessRow(): bool
+    {
+        return $this->baselineId !== null
+            && $this->runScopedLogs()->whereNull('failure_reason')->where('input_tokens', '>', 0)->exists();
+    }
+
+    /** この実行分のコストレポート (集計は LlmCostReportService 1 本。二重実装しない)。 */
+    private function costReport(): ?LlmCostReportData
+    {
+        if ($this->baselineId === null) {
+            return null;
+        }
+
+        return app(LlmCostReportService::class)->report(
+            LlmCostGroupBy::PromptTemplate,
+            afterId: $this->baselineId,
+        );
+    }
+
+    /** 結果の組み立てと出力。 */
+    private function finish(CarbonImmutable $startedAt, bool $checkOnly, ?LlmCostReportData $cost): int
+    {
+        $failureClass = null;
+        foreach ($this->stages as $stage) {
+            if (! $stage->ok) {
+                $failureClass = $stage->failureClass;
+                break;
+            }
+        }
+        $passed = $failureClass === null;
+
+        $result = new SmokeRunResultData(
+            passed: $passed,
+            checkOnly: $checkOnly,
+            context: $this->context,
+            stages: $this->stages,
+            failureClass: $failureClass,
+            cost: $cost,
+            totalElapsedMs: self::elapsedMs($startedAt),
+        );
+
+        if ($this->option('json') === true) {
+            $this->line(json_encode($result->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
+        } else {
+            $this->renderHuman($result);
+        }
+
+        return $passed ? self::SUCCESS : self::FAILURE;
+    }
+
+    private function renderHuman(SmokeRunResultData $result): void
+    {
+        $this->line('== preflight ==');
+        foreach ($result->context as $key => $value) {
+            $this->line("{$key}={$value}");
+        }
+
+        $this->line('');
+        $this->line('== stages ==');
+        $this->table(['stage', 'status', 'elapsed', 'failure_class', 'detail'], array_map(
+            static fn (SmokeStageResultData $stage): array => [
+                $stage->stage->value,
+                $stage->ok ? 'ok' : 'NG',
+                sprintf('%.1fs', $stage->elapsedMs / 1000),
+                $stage->failureClass->value ?? '-',
+                $stage->detail,
+            ],
+            $result->stages,
+        ));
+
+        $cost = $result->cost;
+        if ($cost !== null) {
+            $this->line('');
+            $this->line('== llm cost (this run) ==');
+            $this->table(['prompt_template', 'calls', 'in_tok', 'out_tok', 'usd', 'jpy', 'meta_missing'], array_map(
+                static fn (LlmCostRowData $row): array => [
+                    $row->key,
+                    (string) $row->calls,
+                    (string) $row->inputTokens,
+                    (string) $row->outputTokens,
+                    $row->totalCostUsd ?? '-',
+                    $row->totalCostJpy ?? '-',
+                    (string) $row->metadataMissingCalls,
+                ],
+                [...$cost->rows, $cost->total],
+            ));
+            $this->line('注: JPY は各行の記録時レート (fx_snapshot) の合計であり単一レート換算ではない');
+        }
+
+        $this->line('');
+        $this->line(sprintf(
+            'RESULT: %s (total %.1fs%s)',
+            $result->passed ? 'PASS' : 'FAIL',
+            $result->totalElapsedMs / 1000,
+            $result->failureClass === null ? '' : ', failure_class='.$result->failureClass->value,
+        ));
+    }
+
+    /** @return int<0, max> */
+    private static function elapsedMs(CarbonImmutable $startedAt): int
+    {
+        $elapsed = (int) round(abs(CarbonImmutable::now()->getPreciseTimestamp(3) - $startedAt->getPreciseTimestamp(3)));
+        Assert::natural($elapsed);
+
+        return $elapsed;
+    }
+
+    /** 例外の要約 (内部詳細を出しすぎず、どこで落ちたかが分かる程度)。 */
+    private static function describe(Throwable $exception): string
+    {
+        return $exception::class.': '.Str::limit($exception->getMessage(), 300);
+    }
+
+    private function fixturePath(): string
+    {
+        return base_path('resources/fixtures/pipeline-smoke-sop.txt');
+    }
+}
diff --git a/app/Console/Commands/Operations/LlmCostReportCommand.php b/app/Console/Commands/Operations/LlmCostReportCommand.php
new file mode 100644
index 0000000..8fd695a
--- /dev/null
+++ b/app/Console/Commands/Operations/LlmCostReportCommand.php
@@ -0,0 +1,179 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\Operations;
+
+use App\DataTransferObjects\LlmCostReportData;
+use App\DataTransferObjects\LlmCostRowData;
+use App\Enums\LlmCostGroupBy;
+use App\Services\LlmCostReportService;
+use Carbon\CarbonImmutable;
+use Carbon\Exceptions\InvalidFormatException;
+use Illuminate\Console\Command;
+
+/**
+ * llm_call_logs を期間集計して LLM 利用コストを表示する (読み取り専用)。
+ *
+ * 集計本体は LlmCostReportService が持つ (1 実装・複数入口。もう 1 つの入口は
+ * dev:pipeline-smoke の末尾に出る「この実行分」のレポート)。
+ * 本コマンドは入力の検証と表示だけを担う。**スケジュール登録はしない**。
+ */
+class LlmCostReportCommand extends Command
+{
+    /** 日付のみ入力 (`Y-m-d`) の解釈で使う。 */
+    private const string DATE_FORMAT = 'Y-m-d';
+
+    /** 日時入力 (`Y-m-d H:i:s`) の解釈で使う。 */
+    private const string DATE_TIME_FORMAT = 'Y-m-d H:i:s';
+
+    /** 既定の集計期間 (日)。 */
+    private const int DEFAULT_WINDOW_DAYS = 30;
+
+    /** @var string */
+    protected $signature = 'operations:llm-cost-report
+        {--since= : 集計開始日時 (Y-m-d または Y-m-d H:i:s。既定 = 30 日前。UTC 解釈)}
+        {--until= : 集計終了日時 (既定 = 現在。UTC 解釈)}
+        {--group-by=prompt_template : 集計軸 (prompt_template|model|organization|subject)}
+        {--json : 機械可読出力}';
+
+    /** @var string */
+    protected $description = 'llm_call_logs を集計して LLM 利用コストを表示する (読み取り専用)。';
+
+    public function handle(LlmCostReportService $reports): int
+    {
+        $groupByOption = $this->stringOption('group-by') ?? LlmCostGroupBy::PromptTemplate->value;
+        $groupBy = LlmCostGroupBy::tryFrom($groupByOption);
+        if ($groupBy === null) {
+            $this->error("--group-by が不正です: {$groupByOption} (指定できるのは ".LlmCostGroupBy::optionList().')');
+
+            return self::INVALID;
+        }
+
+        $sinceOption = $this->stringOption('since');
+        $since = $sinceOption === null
+            ? CarbonImmutable::now()->subDays(self::DEFAULT_WINDOW_DAYS)
+            : self::parseBoundary($sinceOption, exclusiveEndOfDay: false);
+        if ($since === null) {
+            $this->error('--since を解釈できません (Y-m-d または Y-m-d H:i:s で指定してください)');
+
+            return self::INVALID;
+        }
+
+        $untilOption = $this->stringOption('until');
+        $until = $untilOption === null
+            ? CarbonImmutable::now()
+            : self::parseBoundary($untilOption, exclusiveEndOfDay: true);
+        if ($until === null) {
+            $this->error('--until を解釈できません (Y-m-d または Y-m-d H:i:s で指定してください)');
+
+            return self::INVALID;
+        }
+
+        if ($since->greaterThanOrEqualTo($until)) {
+            $this->error('--since は --until より前でなければなりません (期間は半開区間 since <= created_at < until)');
+
+            return self::INVALID;
+        }
+
+        $report = $reports->report($groupBy, $since, $until);
+
+        if ($this->option('json') === true) {
+            $this->line(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
+
+            return self::SUCCESS;
+        }
+
+        $this->renderTable($report);
+
+        return self::SUCCESS;
+    }
+
+    /** 表 + 注記 (注記は 4 行から増やさない)。 */
+    private function renderTable(LlmCostReportData $report): void
+    {
+        $this->line(sprintf(
+            'group_by=%s since=%s until=%s (UTC)',
+            $report->groupBy->value,
+            $report->since?->toDateTimeString() ?? '-',
+            $report->until?->toDateTimeString() ?? '-',
+        ));
+
+        $rows = array_map(self::displayRow(...), $report->rows);
+        $rows[] = self::displayRow($report->total);
+
+        $this->table(
+            ['key', 'calls', 'in_tok', 'out_tok', 'usd', 'jpy', 'usd_null', 'jpy_null', 'failed', 'meta_missing'],
+            $rows,
+        );
+
+        $this->line('注: 期間境界は UTC で解釈する (JST とは 9 時間ずれる)');
+        $this->line('注: JPY は各行の記録時レート (fx_snapshot) の合計であり単一レート換算ではない');
+        $this->line('注: usd_null / jpy_null の行は金額合計に含まれない (0 に潰していない)');
+        $this->line('注: meta_missing = 組織・対象が特定できない行。0 でないなら呼び出し側の withMetadata() 配線が欠けている');
+    }
+
+    /**
+     * 表示行 (列がガタつかないよう桁を揃えるだけ。DTO 側は丸めない)。
+     *
+     * @return list<string>
+     */
+    private static function displayRow(LlmCostRowData $row): array
+    {
+        return [
+            $row->key,
+            (string) $row->calls,
+            (string) $row->inputTokens,
+            (string) $row->outputTokens,
+            $row->totalCostUsd === null ? '-' : number_format((float) $row->totalCostUsd, 6, '.', ''),
+            $row->totalCostJpy === null ? '-' : number_format((float) $row->totalCostJpy, 2, '.', ''),
+            (string) $row->usdUnresolvedCalls,
+            (string) $row->jpyUnresolvedCalls,
+            (string) $row->failedCalls,
+            (string) $row->metadataMissingCalls,
+        ];
+    }
+
+    /**
+     * 期間境界の解釈。解釈できなければ null (呼び出し側が INVALID を返す)。
+     *
+     * - `Y-m-d` の `--until` は**翌日 0 時 (排他)** にする = 「その日を含む」
+     * - `Y-m-d H:i:s` はそのまま使う (排他境界のまま)
+     */
+    private static function parseBoundary(string $raw, bool $exclusiveEndOfDay): ?CarbonImmutable
+    {
+        $dateOnly = self::parseWithFormat($raw, self::DATE_FORMAT);
+        if ($dateOnly !== null) {
+            return $exclusiveEndOfDay ? $dateOnly->addDay() : $dateOnly;
+        }
+
+        return self::parseWithFormat($raw, self::DATE_TIME_FORMAT);
+    }
+
+    /**
+     * 厳格な parse。再フォーマットが入力と一致しない値 (`2026-13-45` の桁溢れ等) は null。
+     */
+    private static function parseWithFormat(string $raw, string $format): ?CarbonImmutable
+    {
+        try {
+            // '!' で未指定フィールドを epoch へリセットする (時刻の混入を防ぐ)
+            $parsed = CarbonImmutable::createFromFormat('!'.$format, $raw);
+        } catch (InvalidFormatException) {
+            return null;
+        }
+
+        if (! $parsed instanceof CarbonImmutable || $parsed->format($format) !== $raw) {
+            return null;
+        }
+
+        return $parsed;
+    }
+
+    /** option を string|null へ narrow する (bool option と取り違えない)。 */
+    private function stringOption(string $name): ?string
+    {
+        $value = $this->option($name);
+
+        return is_string($value) && $value !== '' ? $value : null;
+    }
+}
diff --git a/app/DataTransferObjects/LlmCallContextData.php b/app/DataTransferObjects/LlmCallContextData.php
new file mode 100644
index 0000000..4a5242c
--- /dev/null
+++ b/app/DataTransferObjects/LlmCallContextData.php
@@ -0,0 +1,74 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects;
+
+use Illuminate\Database\Eloquent\Model;
+use Webmozart\Assert\Assert;
+
+/**
+ * LLM 呼び出しの**帰属コンテキスト**。`Prompt::withMetadata()` へ渡す 4 つの汎用キー
+ * (organization_id / user_id / subject_type / subject_id) の値オブジェクト。
+ *
+ * ★ ここにアプリ固有の語彙を持ち込まない。subject は多態 (Model なら何でもよい) で持つ。
+ *   これは記録層 (llm_call_logs) と listener (RecordLlmCallCost / RecordLlmCallFailure) が
+ *   既に持っている契約そのものであり、本 DTO はその契約を**呼び出し側から型で守る**ためだけに存在する。
+ * ★ organization / subject が null でも構築できる (console 実行など帰属が無い呼び出しがある)。
+ *   欠落は LlmCallLogWriter が metadata_missing = true として記録し、
+ *   コストレポート (LlmCostReportService) が件数として可視化する。
+ */
+final readonly class LlmCallContextData
+{
+    private function __construct(
+        public ?int $organizationId,
+        public ?int $userId,
+        public ?string $subjectType,
+        public ?string $subjectId,
+    ) {}
+
+    /**
+     * subject は Eloquent Model から解決する。型名は **getMorphClass()** を使う
+     * (morph map を設定しているリポジトリでもそのまま移植できる)。
+     */
+    public static function for(?int $organizationId, ?Model $subject, ?int $userId = null): self
+    {
+        $subjectId = null;
+        if ($subject !== null) {
+            // int 主キーでも ULID でも subject_id (string(64)) に収まる形へ寄せる
+            $key = $subject->getKey();
+            Assert::scalar($key, 'subject の主キーが scalar ではありません');
+            $subjectId = (string) $key;
+        }
+
+        return new self(
+            organizationId: $organizationId,
+            userId: $userId,
+            subjectType: $subject?->getMorphClass(),
+            subjectId: $subjectId,
+        );
+    }
+
+    /** 帰属が無い呼び出し (見本 / 運用スクリプト等) を**明示**するための名前付き構築子。 */
+    public static function none(): self
+    {
+        return new self(null, null, null, null);
+    }
+
+    /**
+     * withMetadata() へ渡す配列。**null のキーは落とす**
+     * (LlmMetadataExtractor は isset() で判定するため null を入れても結果は同じだが、
+     *  イベント payload に意味のない null を載せない)。
+     *
+     * @return array<string, int|string>
+     */
+    public function toMetadata(): array
+    {
+        return array_filter([
+            'organization_id' => $this->organizationId,
+            'user_id' => $this->userId,
+            'subject_type' => $this->subjectType,
+            'subject_id' => $this->subjectId,
+        ], static fn (int|string|null $value): bool => $value !== null);
+    }
+}
diff --git a/app/DataTransferObjects/LlmCostReportData.php b/app/DataTransferObjects/LlmCostReportData.php
new file mode 100644
index 0000000..6fdec2f
--- /dev/null
+++ b/app/DataTransferObjects/LlmCostReportData.php
@@ -0,0 +1,52 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects;
+
+use App\Enums\LlmCostGroupBy;
+use Carbon\CarbonImmutable;
+
+/**
+ * コストレポート全体 (集計軸 + クエリ条件 + 行 + TOTAL)。
+ *
+ * `toArray()` が機械可読出力の正本であり、public property の並びを外部契約にしない。
+ */
+final readonly class LlmCostReportData
+{
+    /**
+     * @param  ?int  $afterId  「この実行分」を切り出した id 境界 (smoke 用)
+     * @param  list<LlmCostRowData>  $rows
+     * @param  LlmCostRowData  $total  key = 'TOTAL'
+     */
+    public function __construct(
+        public LlmCostGroupBy $groupBy,
+        public ?CarbonImmutable $since,
+        public ?CarbonImmutable $until,
+        public ?int $afterId,
+        public array $rows,
+        public LlmCostRowData $total,
+    ) {}
+
+    /**
+     * @return array{
+     *     group_by: string,
+     *     since: string|null,
+     *     until: string|null,
+     *     after_id: int|null,
+     *     rows: list<array<string, mixed>>,
+     *     total: array<string, mixed>,
+     * }
+     */
+    public function toArray(): array
+    {
+        return [
+            'group_by' => $this->groupBy->value,
+            'since' => $this->since?->toIso8601String(),
+            'until' => $this->until?->toIso8601String(),
+            'after_id' => $this->afterId,
+            'rows' => array_map(static fn (LlmCostRowData $row): array => $row->toArray(), $this->rows),
+            'total' => $this->total->toArray(),
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/LlmCostRowData.php b/app/DataTransferObjects/LlmCostRowData.php
new file mode 100644
index 0000000..c8fdda0
--- /dev/null
+++ b/app/DataTransferObjects/LlmCostRowData.php
@@ -0,0 +1,70 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects;
+
+/**
+ * コストレポートの集計 1 行 (TOTAL 行も同じ型)。
+ *
+ * 金額は DECIMAL の SUM を **numeric-string** のまま持つ (float 化も丸め直しもしない)。
+ * null は「upstream の pricing / FX 解決失敗」であって 0 (unknown モデルの zero-cost
+ * snapshot = 正常系) とは違う。潰さず、件数として別に返す (「安く見える」嘘をつかない)。
+ */
+final readonly class LlmCostRowData
+{
+    /**
+     * @param  string  $key  集計キー (null 成分は '(none)'、複合は '#' 連結)
+     * @param  int<0, max>  $calls
+     * @param  int<0, max>  $inputTokens
+     * @param  int<0, max>  $outputTokens
+     * @param  numeric-string|null  $totalCostUsd  usdUnresolvedCalls を除いた合計
+     * @param  numeric-string|null  $totalCostJpy  jpyUnresolvedCalls を除いた合計
+     * @param  int<0, max>  $usdUnresolvedCalls  total_cost_usd IS NULL の件数
+     * @param  int<0, max>  $jpyUnresolvedCalls  total_cost_jpy IS NULL の件数
+     * @param  int<0, max>  $failedCalls  failure_reason IS NOT NULL の件数
+     * @param  int<0, max>  $metadataMissingCalls  metadata_missing = true の件数 (帰属配線の健全性)
+     */
+    public function __construct(
+        public string $key,
+        public int $calls,
+        public int $inputTokens,
+        public int $outputTokens,
+        public ?string $totalCostUsd,
+        public ?string $totalCostJpy,
+        public int $usdUnresolvedCalls,
+        public int $jpyUnresolvedCalls,
+        public int $failedCalls,
+        public int $metadataMissingCalls,
+    ) {}
+
+    /**
+     * @return array{
+     *     key: string,
+     *     calls: int,
+     *     input_tokens: int,
+     *     output_tokens: int,
+     *     total_cost_usd: string|null,
+     *     total_cost_jpy: string|null,
+     *     usd_unresolved_calls: int,
+     *     jpy_unresolved_calls: int,
+     *     failed_calls: int,
+     *     metadata_missing_calls: int,
+     * }
+     */
+    public function toArray(): array
+    {
+        return [
+            'key' => $this->key,
+            'calls' => $this->calls,
+            'input_tokens' => $this->inputTokens,
+            'output_tokens' => $this->outputTokens,
+            'total_cost_usd' => $this->totalCostUsd,
+            'total_cost_jpy' => $this->totalCostJpy,
+            'usd_unresolved_calls' => $this->usdUnresolvedCalls,
+            'jpy_unresolved_calls' => $this->jpyUnresolvedCalls,
+            'failed_calls' => $this->failedCalls,
+            'metadata_missing_calls' => $this->metadataMissingCalls,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Smoke/SmokeRunResultData.php b/app/DataTransferObjects/Smoke/SmokeRunResultData.php
new file mode 100644
index 0000000..8f4bcc5
--- /dev/null
+++ b/app/DataTransferObjects/Smoke/SmokeRunResultData.php
@@ -0,0 +1,60 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Smoke;
+
+use App\DataTransferObjects\LlmCostReportData;
+use App\Enums\Smoke\SmokeFailureClass;
+
+/**
+ * pipeline smoke 1 回分の結果。`--json` は本 DTO の `toArray()` を 1 経路で出す
+ * (public property の並びを外部契約にしない。`response()->json()` は使わない)。
+ */
+final readonly class SmokeRunResultData
+{
+    /**
+     * @param  bool  $checkOnly  preflight だけ実行した (`--check`) か
+     * @param  array<string, string>  $context  実行対象の表示 (env / db / org / ffmpeg 版など)
+     * @param  list<SmokeStageResultData>  $stages
+     * @param  ?LlmCostReportData  $cost  この実行分のコスト (`--check` では null)
+     * @param  int<0, max>  $totalElapsedMs
+     */
+    public function __construct(
+        public bool $passed,
+        public bool $checkOnly,
+        public array $context,
+        public array $stages,
+        public ?SmokeFailureClass $failureClass,
+        public ?LlmCostReportData $cost,
+        public int $totalElapsedMs,
+    ) {}
+
+    /**
+     * @return array{
+     *     passed: bool,
+     *     check_only: bool,
+     *     failure_class: string|null,
+     *     total_elapsed_ms: int,
+     *     context: array<string, string>,
+     *     stages: list<array<string, mixed>>,
+     *     cost: array<string, mixed>|null,
+     * }
+     */
+    public function toArray(): array
+    {
+        return [
+            'passed' => $this->passed,
+            'check_only' => $this->checkOnly,
+            'failure_class' => $this->failureClass?->value,
+            'total_elapsed_ms' => $this->totalElapsedMs,
+            'context' => $this->context,
+            'stages' => array_map(
+                static fn (SmokeStageResultData $stage): array => $stage->toArray(),
+                $this->stages,
+            ),
+            // コスト部は LlmCostReportData::toArray() をそのまま埋め込む (二重定義しない)
+            'cost' => $this->cost?->toArray(),
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Smoke/SmokeStageResultData.php b/app/DataTransferObjects/Smoke/SmokeStageResultData.php
new file mode 100644
index 0000000..259ff5d
--- /dev/null
+++ b/app/DataTransferObjects/Smoke/SmokeStageResultData.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Smoke;
+
+use App\Enums\Smoke\SmokeFailureClass;
+use App\Enums\Smoke\SmokeStage;
+
+/**
+ * pipeline smoke の段 1 つの結果。
+ *
+ * `detail` は**診断のための自由文**であり、機械判定には使わない (判定は ok / failureClass)。
+ */
+final readonly class SmokeStageResultData
+{
+    /**
+     * @param  int<0, max>  $elapsedMs
+     * @param  ?SmokeFailureClass  $failureClass  成功段では null (分類しない)
+     */
+    public function __construct(
+        public SmokeStage $stage,
+        public bool $ok,
+        public int $elapsedMs,
+        public string $detail,
+        public ?SmokeFailureClass $failureClass,
+    ) {}
+
+    /**
+     * @return array{stage: string, ok: bool, elapsed_ms: int, detail: string, failure_class: string|null}
+     */
+    public function toArray(): array
+    {
+        return [
+            'stage' => $this->stage->value,
+            'ok' => $this->ok,
+            'elapsed_ms' => $this->elapsedMs,
+            'detail' => $this->detail,
+            'failure_class' => $this->failureClass?->value,
+        ];
+    }
+}
diff --git a/app/Enums/LlmCostGroupBy.php b/app/Enums/LlmCostGroupBy.php
new file mode 100644
index 0000000..34afcb2
--- /dev/null
+++ b/app/Enums/LlmCostGroupBy.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums;
+
+/**
+ * コストレポートの集計軸 (閉じた語彙)。
+ *
+ * ★ ここが「集計層が知ってよい llm_call_logs の列」の**唯一の宣言点**である。
+ *   列名リテラルを本 enum の外へ出さない (SQL へ素通しさせない型境界)。
+ * ★ すべて素の列 GROUP BY とし、GROUP BY キーへ SQL 関数を適用しない (driver 差を持ち込まない)。
+ *   既存 index を使えるかどうかは期間条件と実行計画に依存する (index 前提の設計にしない)。
+ */
+enum LlmCostGroupBy: string
+{
+    case PromptTemplate = 'prompt_template';   // どの段が
+    case Model = 'model';                      // どのモデルが
+    case Organization = 'organization';        // どの組織が
+    case Subject = 'subject';                  // どの対象が (多態)
+
+    /**
+     * 集計キーを構成する列。
+     *
+     * @return non-empty-list<string>
+     */
+    public function columns(): array
+    {
+        return match ($this) {
+            self::PromptTemplate => ['prompt_template'],
+            self::Model => ['model'],
+            self::Organization => ['organization_id'],
+            self::Subject => ['subject_type', 'subject_id'],
+        };
+    }
+
+    /** `--group-by` オプションのヘルプ用 (語彙の列挙を文字列で二重管理しない)。 */
+    public static function optionList(): string
+    {
+        return implode('|', array_map(static fn (self $case): string => $case->value, self::cases()));
+    }
+}
diff --git a/app/Enums/Smoke/SmokeFailureClass.php b/app/Enums/Smoke/SmokeFailureClass.php
new file mode 100644
index 0000000..1d76308
--- /dev/null
+++ b/app/Enums/Smoke/SmokeFailureClass.php
@@ -0,0 +1,35 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Smoke;
+
+/**
+ * pipeline smoke の失敗分類 (観測語彙)。
+ *
+ * ★ 分類は**観測のためであり制御フローを変えない**。
+ * ★ `Unknown` は「写像表に一致が無かった」ことを意味し、写像表の値としては使わない。
+ */
+enum SmokeFailureClass: string
+{
+    /** preflight で落ちた (LLM を 1 回も呼んでいない) */
+    case Preflight = 'preflight';
+
+    /** ジョブが queued のまま上限到達 / LLM は動いているのに記録が不完全 */
+    case Wiring = 'wiring';
+
+    /** ジョブが running のまま上限到達 */
+    case StageTimeout = 'stage_timeout';
+
+    /** provider 側の疑い (LLM が原因になり得る段でのみ使う) */
+    case Llm = 'llm';
+
+    /** レンダ (error_code あり / 出力は読めたが ffprobe が非 0) */
+    case Render = 'render';
+
+    /** 出力オブジェクトが不在 / 読み出し不能 */
+    case Storage = 'storage';
+
+    /** 写像表に一致が無かった */
+    case Unknown = 'unknown';
+}
diff --git a/app/Enums/Smoke/SmokeStage.php b/app/Enums/Smoke/SmokeStage.php
new file mode 100644
index 0000000..522e918
--- /dev/null
+++ b/app/Enums/Smoke/SmokeStage.php
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Smoke;
+
+/**
+ * pipeline smoke の段 (実行順)。**すべて実在の業務経路**に対応する。
+ */
+enum SmokeStage: string
+{
+    case Preflight = 'preflight';       // 事前検査 (LLM を 1 回も呼ばない)
+    case Fixture = 'fixture';           // SOP 投入 (manual + source_document)
+    case Analysis = 'analysis';         // AI 解析 (worker 待ち)
+    case LlmEvidence = 'llm-evidence';  // 実呼び出しと帰属の記録検査 (DB 読み取りのみ)
+    case Capture = 'capture';           // 撮影テイクの登録と採用
+    case Render = 'render';             // ffmpeg 合成 (worker 待ち)
+    case Artifact = 'artifact';         // 出力 mp4 の読み出しと ffprobe
+}
diff --git a/app/Prompts/ScenarioGenerationPrompt.php b/app/Prompts/ScenarioGenerationPrompt.php
index 5810d34..ebbefe8 100644
--- a/app/Prompts/ScenarioGenerationPrompt.php
+++ b/app/Prompts/ScenarioGenerationPrompt.php
@@ -4,6 +4,7 @@
 
 namespace App\Prompts;
 
+use App\DataTransferObjects\LlmCallContextData;
 use Kent013\PrismPrompt\Prompt;
 use Kent013\PrismPrompt\TextPrompt;
 use Kent013\PrismPrompt\Values\UserInput;
@@ -15,10 +16,10 @@
  */
 final class ScenarioGenerationPrompt
 {
-    public static function make(string $untrustedDecompositionJson): TextPrompt
+    public static function make(string $untrustedDecompositionJson, LlmCallContextData $context): TextPrompt
     {
         return Prompt::load('scenario-generation', [
             'decomposition' => UserInput::from($untrustedDecompositionJson), // 不変条件 4: untrusted は UserInput
-        ]);
+        ])->withMetadata($context->toMetadata()); // 帰属: llm_call_logs の organization / subject
     }
 }
diff --git a/app/Prompts/SopExtractPrompt.php b/app/Prompts/SopExtractPrompt.php
index 540901d..27c8603 100644
--- a/app/Prompts/SopExtractPrompt.php
+++ b/app/Prompts/SopExtractPrompt.php
@@ -4,6 +4,7 @@
 
 namespace App\Prompts;
 
+use App\DataTransferObjects\LlmCallContextData;
 use Kent013\PrismPrompt\Prompt;
 use Kent013\PrismPrompt\TextPrompt;
 use Kent013\PrismPrompt\Values\UserInput;
@@ -14,10 +15,10 @@
  */
 final class SopExtractPrompt
 {
-    public static function make(string $untrustedSopText): TextPrompt
+    public static function make(string $untrustedSopText, LlmCallContextData $context): TextPrompt
     {
         return Prompt::load('sop-extract', [
             'text' => UserInput::from($untrustedSopText), // 不変条件 4: untrusted は UserInput
-        ]);
+        ])->withMetadata($context->toMetadata()); // 帰属: llm_call_logs の organization / subject
     }
 }
diff --git a/app/Prompts/WorkDecompositionPrompt.php b/app/Prompts/WorkDecompositionPrompt.php
index cff6e64..1b941dd 100644
--- a/app/Prompts/WorkDecompositionPrompt.php
+++ b/app/Prompts/WorkDecompositionPrompt.php
@@ -4,6 +4,7 @@
 
 namespace App\Prompts;
 
+use App\DataTransferObjects\LlmCallContextData;
 use Kent013\PrismPrompt\Prompt;
 use Kent013\PrismPrompt\TextPrompt;
 use Kent013\PrismPrompt\Values\UserInput;
@@ -15,10 +16,10 @@
  */
 final class WorkDecompositionPrompt
 {
-    public static function make(string $untrustedExtractedJson): TextPrompt
+    public static function make(string $untrustedExtractedJson, LlmCallContextData $context): TextPrompt
     {
         return Prompt::load('work-decomposition', [
             'extracted' => UserInput::from($untrustedExtractedJson), // 不変条件 4: untrusted は UserInput
-        ]);
+        ])->withMetadata($context->toMetadata()); // 帰属: llm_call_logs の organization / subject
     }
 }
diff --git a/app/Services/LlmCostReportService.php b/app/Services/LlmCostReportService.php
new file mode 100644
index 0000000..1cc794d
--- /dev/null
+++ b/app/Services/LlmCostReportService.php
@@ -0,0 +1,216 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services;
+
+use App\DataTransferObjects\LlmCostReportData;
+use App\DataTransferObjects\LlmCostRowData;
+use App\Enums\LlmCostGroupBy;
+use App\Models\LlmCallLog;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Query\Builder;
+use stdClass;
+use Webmozart\Assert\Assert;
+
+/**
+ * llm_call_logs の集計 (読み取り専用)。**再計算も再換算もしない**。
+ *
+ * - USD が主: total_cost_usd は pricing_snapshot から決定的に決まる
+ * - JPY は副: total_cost_jpy は行ごとの fx_snapshot (記録時レート) 由来。期間合計の JPY は
+ *   「各行の記録時レートでの合計」であり、単一レートで USD を換算した値ではない
+ * - 未解決 (null) は 0 に潰さず件数で返す
+ *
+ * ★ この層は llm_call_logs の列しか知らない。アプリのドメイン語彙を持ち込まない
+ *   (他リポジトリへそのまま移植できる状態を保つ)。
+ */
+final readonly class LlmCostReportService
+{
+    /** TOTAL 行のキー (行のキーと衝突しうるが、TOTAL は rows と別フィールドで返すため問題にならない)。 */
+    private const string TOTAL_KEY = 'TOTAL';
+
+    /** 集計キーの null 成分の表記。 */
+    private const string NONE_KEY = '(none)';
+
+    /**
+     * 集計値の SELECT 句 (行 / TOTAL で**同じもの**を使う = 定義の二重管理をしない)。
+     *
+     * ★ 整数列は `COALESCE(SUM(...), 0)`。`SUM()` は対象 0 件で NULL を返すため、
+     *   そのままだと int 引数の DTO が TypeError になる。
+     * ★ 金額列 (`total_cost_usd` / `total_cost_jpy`) には COALESCE を**掛けない**。
+     *   null は「未解決」を表す情報であり、0 に潰すと「タダだった」という嘘になる
+     *   (`usd_unresolved_calls` / `jpy_unresolved_calls` と対になる仕様)。
+     */
+    private const string AGGREGATE_SELECT = 'COUNT(*) AS calls'
+        .', COALESCE(SUM(input_tokens), 0) AS input_tokens'
+        .', COALESCE(SUM(output_tokens), 0) AS output_tokens'
+        .', SUM(total_cost_usd) AS total_cost_usd'
+        .', SUM(total_cost_jpy) AS total_cost_jpy'
+        .', COALESCE(SUM(CASE WHEN total_cost_usd IS NULL THEN 1 ELSE 0 END), 0) AS usd_unresolved_calls'
+        .', COALESCE(SUM(CASE WHEN total_cost_jpy IS NULL THEN 1 ELSE 0 END), 0) AS jpy_unresolved_calls'
+        .', COALESCE(SUM(CASE WHEN failure_reason IS NOT NULL THEN 1 ELSE 0 END), 0) AS failed_calls'
+        .', COALESCE(SUM(CASE WHEN metadata_missing THEN 1 ELSE 0 END), 0) AS metadata_missing_calls';
+
+    /**
+     * 集計本体。クエリは 2 本だけ (行 / TOTAL)。
+     *
+     * TOTAL を行の PHP 加算で作らないのは、DECIMAL を PHP で足すと float 化するか
+     * bcmath 依存を新たに持ち込むことになり、移植先の PHP 拡張前提を増やすためである。
+     * GROUP BY 無しの集計は**対象 0 件でも 1 行返る**ので、0 件時の TOTAL の形もここが正本。
+     *
+     * @param  ?CarbonImmutable  $since  半開区間の開始 (含む)
+     * @param  ?CarbonImmutable  $until  半開区間の終了 (含まない)
+     * @param  ?int  $afterId  id がこれより**大きい**行だけを対象にする (smoke の「この実行分」)
+     */
+    public function report(
+        LlmCostGroupBy $groupBy,
+        ?CarbonImmutable $since = null,
+        ?CarbonImmutable $until = null,
+        ?int $afterId = null,
+    ): LlmCostReportData {
+        $columns = $groupBy->columns();
+
+        // 集計キー列は select() (列名の配列) で、集計値は selectRaw() で積む
+        // = SQL 文字列へ列名を連結しない (literal-string 境界を崩さない)
+        $query = $this->baseQuery($since, $until, $afterId)
+            ->select($columns)
+            ->selectRaw(self::AGGREGATE_SELECT)
+            ->groupBy($columns);
+        foreach ($columns as $column) {
+            $query->orderBy($column);
+        }
+
+        $rows = [];
+        foreach ($query->get() as $record) {
+            $data = self::recordToArray($record);
+            $rows[] = self::toRow(self::keyOf($data, $columns), $data);
+        }
+
+        $totalRecord = $this->baseQuery($since, $until, $afterId)
+            ->selectRaw(self::AGGREGATE_SELECT)
+            ->first();
+        Assert::notNull($totalRecord, 'GROUP BY 無しの集計は対象 0 件でも 1 行返る');
+
+        return new LlmCostReportData(
+            groupBy: $groupBy,
+            since: $since,
+            until: $until,
+            afterId: $afterId,
+            rows: $rows,
+            total: self::toRow(self::TOTAL_KEY, self::recordToArray($totalRecord)),
+        );
+    }
+
+    /** where 条件だけを積んだ素のクエリ (行用 / TOTAL 用で同じ母集団を使う)。 */
+    private function baseQuery(?CarbonImmutable $since, ?CarbonImmutable $until, ?int $afterId): Builder
+    {
+        $query = LlmCallLog::query()->toBase();
+
+        if ($since !== null) {
+            $query->where('created_at', '>=', $since);
+        }
+        if ($until !== null) {
+            $query->where('created_at', '<', $until);   // 半開区間 (until ちょうどは含まない)
+        }
+        if ($afterId !== null) {
+            $query->where('id', '>', $afterId);         // 順序比較 = 主キー同一性クエリではない
+        }
+
+        return $query;
+    }
+
+    /**
+     * SELECT 結果 1 行を配列へ落とす (driver ごとの stdClass / array の差を 1 箇所に閉じる)。
+     *
+     * @return array<string, mixed>
+     */
+    private static function recordToArray(mixed $record): array
+    {
+        Assert::isInstanceOf($record, stdClass::class, '集計クエリの戻りが想定の形ではありません');
+
+        /** @var array<string, mixed> $data */
+        $data = (array) $record;
+
+        return $data;
+    }
+
+    /**
+     * 集計キーの生成。null 成分は '(none)'、複合キーは '#' 連結。
+     *
+     * @param  array<string, mixed>  $data
+     * @param  non-empty-list<string>  $columns
+     */
+    private static function keyOf(array $data, array $columns): string
+    {
+        $parts = [];
+        foreach ($columns as $column) {
+            $value = $data[$column] ?? null;
+            if ($value === null) {
+                $parts[] = self::NONE_KEY;
+
+                continue;
+            }
+            Assert::scalar($value, "集計キー列 {$column} が scalar ではありません");
+            $parts[] = (string) $value;
+        }
+
+        return implode('#', $parts);
+    }
+
+    /**
+     * 集計結果 1 行 → DTO。**型の境界はここ 1 箇所**
+     * (`SUM()` の戻りは driver 依存で string|int|float|null になりうるため fail-loud に検査する)。
+     *
+     * @param  array<string, mixed>  $data
+     */
+    private static function toRow(string $key, array $data): LlmCostRowData
+    {
+        return new LlmCostRowData(
+            key: $key,
+            calls: self::countOf($data, 'calls'),
+            inputTokens: self::countOf($data, 'input_tokens'),
+            outputTokens: self::countOf($data, 'output_tokens'),
+            totalCostUsd: self::moneyOf($data, 'total_cost_usd'),
+            totalCostJpy: self::moneyOf($data, 'total_cost_jpy'),
+            usdUnresolvedCalls: self::countOf($data, 'usd_unresolved_calls'),
+            jpyUnresolvedCalls: self::countOf($data, 'jpy_unresolved_calls'),
+            failedCalls: self::countOf($data, 'failed_calls'),
+            metadataMissingCalls: self::countOf($data, 'metadata_missing_calls'),
+        );
+    }
+
+    /**
+     * 件数系の narrow。COALESCE 済みなので null は来ない (来たら SELECT 句の退行)。
+     *
+     * @param  array<string, mixed>  $data
+     * @return int<0, max>
+     */
+    private static function countOf(array $data, string $column): int
+    {
+        $value = $data[$column] ?? null;
+        Assert::numeric($value, "集計列 {$column} が数値ではありません (COALESCE が外れていませんか)");
+        $count = (int) $value;
+        Assert::natural($count, "集計列 {$column} が負の値です");
+
+        return $count;
+    }
+
+    /**
+     * 金額系の narrow。**null は維持する** (未解決を 0 に潰さない)。
+     *
+     * @param  array<string, mixed>  $data
+     * @return numeric-string|null
+     */
+    private static function moneyOf(array $data, string $column): ?string
+    {
+        $value = $data[$column] ?? null;
+        if ($value === null) {
+            return null;
+        }
+        Assert::scalar($value, "集計列 {$column} が scalar ではありません");
+        $amount = (string) $value;
+        Assert::numeric($amount, "集計列 {$column} が数値文字列ではありません");
+
+        return $amount;
+    }
+}
diff --git a/app/Services/Manual/AnalysisPipeline.php b/app/Services/Manual/AnalysisPipeline.php
index 39b63f0..b78fbaa 100644
--- a/app/Services/Manual/AnalysisPipeline.php
+++ b/app/Services/Manual/AnalysisPipeline.php
@@ -4,6 +4,7 @@
 
 namespace App\Services\Manual;
 
+use App\DataTransferObjects\LlmCallContextData;
 use App\DataTransferObjects\Manual\Analysis\ExtractedSopData;
 use App\DataTransferObjects\Manual\Analysis\ExtractedText;
 use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
@@ -100,10 +101,16 @@ public function run(int $analysisJobId): void
             $document = $job->sourceDocument;
             Assert::notNull($document, 'trigger が必ず associate している');
 
+            // LLM コスト記録の帰属 (llm_call_logs.organization_id / subject_*)。
+            // startJob() が true を返した直後 = 実際に走る担当だと確定した後に 1 度だけ解決し、
+            // 3 段すべての prompt factory へ引数で渡す (パイプラインを stateful にしない)。
+            // リトライでも同じ context が使われるため、再試行で出た失敗行にも同じ帰属が付く。
+            $context = $this->resolveCallContext($job);
+
             $text = $this->extractor->extract($document);
-            $extracted = $this->runExtractStep($job, $document, $text, $deadline);
-            $decomposition = $this->runDecomposeStep($job, $extracted, $deadline);
-            $generated = $this->runGenerateStep($job, $decomposition, $deadline);
+            $extracted = $this->runExtractStep($job, $document, $text, $deadline, $context);
+            $decomposition = $this->runDecomposeStep($job, $extracted, $deadline, $context);
+            $generated = $this->runGenerateStep($job, $decomposition, $deadline, $context);
             if ($this->finalize($job, $generated)) {
                 // succeeded 到達時のみ・terminal tx の commit 後に通知 (stale 先勝ち false は通知しない)
                 $this->notifications->notifyAnalysisFinished($job->refresh());
@@ -184,13 +191,14 @@ private function runExtractStep(
         SourceDocument $document,
         ExtractedText $text,
         CarbonImmutable $deadline,
+        LlmCallContextData $context,
     ): ExtractedSopData {
         $extracted = $this->withBoundedRetry(
             $job,
             $deadline,
             AnalysisStep::Extract,
             fn (): ExtractedSopData => ExtractedSopData::fromLlmText(
-                SopExtractPrompt::make($text->text)->executeSync(),
+                SopExtractPrompt::make($text->text, $context)->executeSync(),
             ),
         );
 
@@ -206,13 +214,14 @@ private function runDecomposeStep(
         AnalysisJob $job,
         ExtractedSopData $extracted,
         CarbonImmutable $deadline,
+        LlmCallContextData $context,
     ): WorkDecompositionData {
         $decomposition = $this->withBoundedRetry(
             $job,
             $deadline,
             AnalysisStep::Decompose,
             fn (): WorkDecompositionData => WorkDecompositionData::fromLlmText(
-                WorkDecompositionPrompt::make($extracted->toJsonString())->executeSync(),
+                WorkDecompositionPrompt::make($extracted->toJsonString(), $context)->executeSync(),
             ),
         );
 
@@ -231,13 +240,14 @@ private function runGenerateStep(
         AnalysisJob $job,
         WorkDecompositionData $decomposition,
         CarbonImmutable $deadline,
+        LlmCallContextData $context,
     ): GeneratedScenarioData {
         $generated = $this->withBoundedRetry(
             $job,
             $deadline,
             AnalysisStep::Generate,
             fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(
-                ScenarioGenerationPrompt::make($decomposition->toJsonString())->executeSync(),
+                ScenarioGenerationPrompt::make($decomposition->toJsonString(), $context)->executeSync(),
             ),
         );
 
@@ -492,6 +502,27 @@ private function resolveProject(AnalysisJob $job): Project
         return $project;
     }
 
+    /**
+     * LLM 呼び出しの帰属コンテキストの導出 (payload 不信任。すべて DB から relation 経由で再解決)。
+     *
+     * subject は **VideoManual** にする。費用を知りたい単位は成果物 (マニュアル) であって
+     * job ではない (再解析で job は増えるが「このマニュアルに合計いくらかけたか」が運用の要求)。
+     * なお集計層はこの判断を一切知らない (見るのは subject_type / subject_id の 2 列だけ)。
+     *
+     * ★ 参照のみで書き込みも判定もしない (startJob の行ロック外で呼んでも状態を変えない)。
+     */
+    private function resolveCallContext(AnalysisJob $job): LlmCallContextData
+    {
+        $manual = $job->videoManual;
+        Assert::isInstanceOf($manual, VideoManual::class, 'analysis job は必ず manual に属する');
+
+        return LlmCallContextData::for(
+            $this->resolveOrganization($job)->id,
+            $manual,
+            $job->triggered_by,
+        );
+    }
+
     /** job → manual → project → organization の導出 */
     private function resolveOrganization(AnalysisJob $job): Organization
     {
diff --git a/app/Support/BughuntDatabaseGuard.php b/app/Support/BughuntDatabaseGuard.php
new file mode 100644
index 0000000..83fd523
--- /dev/null
+++ b/app/Support/BughuntDatabaseGuard.php
@@ -0,0 +1,35 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support;
+
+use Illuminate\Support\Facades\DB;
+
+/**
+ * bug-hunt DB 名判定の SSOT (fail-secure な**検出**側)。
+ *
+ * ★ 並列 cap は 4 (`scripts/bug-hunt-shard.sh` の `BUGHUNT_SHARD_CAP`) だが、本 regex は
+ *   cap と同期させない。狭めると残留 `bug_hunt_5` を bughunt DB と認識できず「dev DB 扱い」に
+ *   なってしまう (= 検出漏れ)。同スクリプトの `SHARD_DB_RE` は「触れてよい DB の allowlist」で
+ *   方向が逆である点に注意。
+ * ★ 依存の向きは app ← seeders。seeder 側 trait (DetectsBughuntDatabase) は本クラスへ
+ *   委譲するだけの薄い殻にする。
+ */
+final readonly class BughuntDatabaseGuard
+{
+    /** bug-hunt DB 名の許容 regex (残留も検出するため cap より広い。上記 docblock 参照)。 */
+    private const string BUGHUNT_DB_REGEX = '/^bug_hunt(_[1-8])?$/';
+
+    /** 現在の既定接続が bug-hunt DB を指しているか。 */
+    public function isBughuntDatabase(): bool
+    {
+        return self::matches(DB::connection()->getDatabaseName());
+    }
+
+    /** 名前だけを見る純関数 (テストで DB 接続なしに判定表を固定できる)。 */
+    public static function matches(string $databaseName): bool
+    {
+        return preg_match(self::BUGHUNT_DB_REGEX, $databaseName) === 1;
+    }
+}
diff --git a/app/Support/Smoke/SmokeFailureClassifier.php b/app/Support/Smoke/SmokeFailureClassifier.php
new file mode 100644
index 0000000..7c49d06
--- /dev/null
+++ b/app/Support/Smoke/SmokeFailureClassifier.php
@@ -0,0 +1,106 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Smoke;
+
+use App\Enums\Manual\JobStatus;
+use App\Enums\Smoke\SmokeFailureClass;
+use App\Enums\Smoke\SmokeStage;
+
+/**
+ * pipeline smoke の失敗分類器 (純関数)。
+ * 配置と流儀は `App\Support\Billing\GatewayFailureClassifier` に合わせている。
+ *
+ * 判定順 (先に一致したものを返す):
+ *  1. 段が成功 → null (分類しない)
+ *  2. preflight → Preflight
+ *  3. timeout ∧ queued → Wiring / 4. timeout ∧ running → StageTimeout
+ *  5. render ∧ error_code → Render
+ *  6. artifact ∧ 読めない → Storage / 7. artifact ∧ ffprobe 失敗 → Render
+ *  8. llm-evidence ∧ 成功行あり ∧ 記録不完全 → Wiring
+ *  9. LLM 起因になり得る段 ∧ (failure 行あり ∨ 成功行なし) → Llm
+ * 10. それ以外 → Unknown
+ */
+final readonly class SmokeFailureClassifier
+{
+    /**
+     * LLM が原因になり得る段 (`Llm` 分類の適用範囲を**この集合に閉じる**)。
+     *
+     * @var list<SmokeStage>
+     */
+    private const array LLM_ATTRIBUTABLE_STAGES = [SmokeStage::Analysis, SmokeStage::LlmEvidence];
+
+    /**
+     * 失敗の観測分類。**成功した段では null を返す**。
+     *
+     * @param  bool  $stageSucceeded  段が成功したか
+     * @param  ?JobStatus  $jobStatus  観測したジョブ状態 (段によっては null)
+     * @param  bool  $timedOut  待機上限に到達したか
+     * @param  bool  $hasLlmFailureRow  この実行分に failure_reason 行があるか
+     * @param  bool  $hasLlmSuccessRow  この実行分に成功行があるか
+     * @param  bool  $llmRecordingIncomplete  成功行はあるが記録が不完全か (帰属欠落 or template 欠落)
+     * @param  bool  $hasRenderErrorCode  render_jobs.error_code が非 null か
+     * @param  bool  $outputReadable  出力オブジェクトを読み出せたか
+     * @param  bool  $ffprobeFailed  ffprobe が非 0 終了したか
+     */
+    public static function classify(
+        SmokeStage $stage,
+        bool $stageSucceeded,
+        ?JobStatus $jobStatus,
+        bool $timedOut,
+        bool $hasLlmFailureRow,
+        bool $hasLlmSuccessRow,
+        bool $llmRecordingIncomplete,
+        bool $hasRenderErrorCode,
+        bool $outputReadable,
+        bool $ffprobeFailed,
+    ): ?SmokeFailureClass {
+        if ($stageSucceeded) {
+            return null; // 成功時のリトライ痕 (failure_reason 行) を失敗として分類しない
+        }
+
+        return match (true) {
+            $stage === SmokeStage::Preflight => SmokeFailureClass::Preflight,
+            $timedOut && $jobStatus === JobStatus::Queued => SmokeFailureClass::Wiring,
+            $timedOut && $jobStatus === JobStatus::Running => SmokeFailureClass::StageTimeout,
+            $stage === SmokeStage::Render && $hasRenderErrorCode => SmokeFailureClass::Render,
+            $stage === SmokeStage::Artifact && ! $outputReadable => SmokeFailureClass::Storage,
+            $stage === SmokeStage::Artifact && $ffprobeFailed => SmokeFailureClass::Render,
+            // LLM は動いているのにアプリ側の記録経路が欠けている = 配線の問題 (provider の問題ではない)
+            $stage === SmokeStage::LlmEvidence && $hasLlmSuccessRow && $llmRecordingIncomplete => SmokeFailureClass::Wiring,
+            in_array($stage, self::LLM_ATTRIBUTABLE_STAGES, true)
+                && ($hasLlmFailureRow || ! $hasLlmSuccessRow) => SmokeFailureClass::Llm,
+            default => SmokeFailureClass::Unknown,
+        };
+    }
+
+    /**
+     * 「LLM は成功しているのに記録が欠けている」の導出 (純関数。DB 読み出しは呼び出し側の責務)。
+     *
+     * 2 原因をまとめて 1 つの bool にする:
+     *   - 必要 template の成功行が足りない (analysis は成功したのに記録が落ちた)
+     *   - 成功行はあるが帰属 (organization / subject) が期待と違う
+     *
+     * ★ 呼び出し側の責務: `$succeededTemplates` / `$attributedTemplates` は
+     *   **`$requiredTemplates` に限定した集合**であること (クエリに
+     *   `->whereIn('prompt_template', $requiredTemplates)` を付ければ足りる)。
+     *   対象外の template が混ざると本 smoke と無関係な行まで「不完全」と判定してしまう。
+     *
+     * @param  list<string>  $requiredTemplates  期待する prompt_template (3 段)
+     * @param  list<string>  $succeededTemplates  この実行分の成功行が存在した template (required に限定)
+     * @param  list<string>  $attributedTemplates  うち帰属が期待どおりだった template (required に限定)
+     */
+    public static function llmRecordingIncomplete(
+        array $requiredTemplates,
+        array $succeededTemplates,
+        array $attributedTemplates,
+    ): bool {
+        if ($succeededTemplates === []) {
+            return false; // 成功行が 1 行も無いのは「記録の不備」ではなく Llm 側の疑い
+        }
+
+        return array_diff($requiredTemplates, $succeededTemplates) !== []
+            || array_diff($succeededTemplates, $attributedTemplates) !== [];
+    }
+}
diff --git a/database/seeders/Concerns/DetectsBughuntDatabase.php b/database/seeders/Concerns/DetectsBughuntDatabase.php
index d237d51..5779eb7 100644
--- a/database/seeders/Concerns/DetectsBughuntDatabase.php
+++ b/database/seeders/Concerns/DetectsBughuntDatabase.php
@@ -4,24 +4,19 @@
 
 namespace Database\Seeders\Concerns;
 
-use Illuminate\Support\Facades\DB;
+use App\Support\BughuntDatabaseGuard;
 
 /**
- * bug-hunt DB 名判定の SSOT (fail-secure な**検出**側)。
- * bughunt 系 seeder の fail-secure guard から参照する。
+ * bughunt 系 seeder の fail-secure guard から参照する薄い殻。
  *
- * ★ 並列 cap は 4 (`scripts/bug-hunt-shard.sh` の `BUGHUNT_SHARD_CAP`) だが、本 regex は
- *   **cap と同期させない**。狭めると残留 `bug_hunt_5` を bughunt DB と認識できず
- *   「dev DB 扱い」になってしまう (= 検出漏れ)。同スクリプトの `SHARD_DB_RE` は
- *   「触れてよい DB の allowlist」で方向が逆である点に注意。
+ * 判定の SSOT は `App\Support\BughuntDatabaseGuard`
+ * (同じ判定を smoke コマンドの fail-secure 条件でも使うため app 側へ昇格した)。
+ * ここには regex を持たない (二重管理をしない)。
  */
 trait DetectsBughuntDatabase
 {
-    /** bug-hunt DB 名の許容 regex (残留も検出するため cap より広い。上記 docblock 参照)。 */
-    private const string BUGHUNT_DB_REGEX = '/^bug_hunt(_[1-8])?$/';
-
     private function isBughuntDatabase(): bool
     {
-        return preg_match(self::BUGHUNT_DB_REGEX, DB::connection()->getDatabaseName()) === 1;
+        return app(BughuntDatabaseGuard::class)->isBughuntDatabase();
     }
 }
diff --git a/resources/fixtures/pipeline-smoke-sop.txt b/resources/fixtures/pipeline-smoke-sop.txt
new file mode 100644
index 0000000..183b46d
--- /dev/null
+++ b/resources/fixtures/pipeline-smoke-sop.txt
@@ -0,0 +1,25 @@
+作業手順書: 会議室プロジェクタの起動と片付け
+
+目的
+本手順書は、社内会議室に常設されたプロジェクタを安全に起動し、
+使用後に正しく片付けるための標準作業を定めるものである。
+
+対象者
+会議室を利用するすべての従業員。
+
+手順
+1. 会議室の入口にある主電源スイッチを入れる。天井の表示灯が緑に点灯することを確認する。
+2. プロジェクタ本体の電源ボタンを押し、投影が始まるまで約三十秒待つ。
+3. 卓上の切替器で使用する端末の入力を選び、映像が画面に映ることを確認する。
+4. 焦点リングを回して文字がはっきり読める位置に合わせる。
+5. 使用が終わったら電源ボタンを二回押して電源を切り、冷却ファンが止まるまで待つ。
+6. 主電源スイッチを切り、ケーブルを所定のフックに掛けて退室する。
+
+安全上の注意
+・投影中のレンズを直接のぞき込まないこと。強い光で目を痛める恐れがある。
+・電源を切った直後の本体は高温になっている。冷却ファンが止まるまで触れないこと。
+・ケーブルを床に垂らしたまま放置しないこと。つまずきによる転倒の原因になる。
+・異音や焦げた匂いがした場合は直ちに主電源を切り、総務担当へ連絡すること。
+
+記録
+使用後は会議室備え付けの利用簿に、利用日時と氏名を記入すること。
diff --git a/scripts/bug-hunt-shard.sh b/scripts/bug-hunt-shard.sh
index 17744c9..ab0db25 100755
--- a/scripts/bug-hunt-shard.sh
+++ b/scripts/bug-hunt-shard.sh
@@ -40,6 +40,10 @@
 #   db-check  --shard I --run-id TS    # DB 名 + User::count() 表示
 #   db-exists --shard I --run-id TS    # pg_database 存在確認 (owner role, read-only)
 #   mail-urls --shard I --run-id TS [--count K]   # 署名 URL 抽出 (offset+port 二重フィルタ)
+#   pipeline-smoke --shard I --run-id TS [--check] [--json] [--org=ID]
+#                                      # SOP→AI 解析→撮影→ffmpeg 合成→mp4 の通し確認 (dev:pipeline-smoke)。
+#                                      # ★実 LLM を 3 段呼ぶため課金が発生する (--check は preflight のみ = 費用ゼロ)。
+#                                      # BUGHUNT_ORCHESTRATOR=1 が必須 (子 wrapper には露出しない)。
 #   verify-run --run-id TS             # (fan-out 用) 全 shard の shard-report.md 完遂判定 (空/骨子のみは欠落扱い)。
 #   teardown  --run-id TS [--drop-db]  # serve 停止 (+DB 破棄, admin role)
 #   self-test                          # 実資源に触れない自己検証 (guard / 資源導出 / env 注入 / run)
@@ -322,6 +326,25 @@ artisan_for_shard() {
         php artisan "$@" --env=bughunt.local
 }
 
+# artisan (dev:pipeline-smoke) — runtime 経路 + モードフラグ + 実キー。
+# artisan_for_shard との違いは MODE_ENV / LLM_KEY_ENV を載せる点だけ (実 LLM を呼ぶため)。
+# 秘密 (LLM_KEY_ENV) を展開するプロセス起動を xtrace ガードで挟む (-x 有効時も値を trace に出さない)。
+artisan_with_mode_for_shard() {
+    local db=$1 url=$2; shift 2
+    guard_bughunt_runtime "${db}" bughunt
+    secret_xtrace_off
+    env -i PATH="${PATH}" HOME="${HOME}" \
+        DB_CONNECTION=pgsql \
+        DB_HOST="$(env_file_required DB_HOST)" DB_PORT="$(env_file_required DB_PORT)" \
+        DB_DATABASE="${db}" DB_USERNAME=bughunt DB_PASSWORD="$(env_file_get DB_PASSWORD)" \
+        APP_URL="${url}" \
+        ${MODE_ENV[@]+"${MODE_ENV[@]}"} ${LLM_KEY_ENV[@]+"${LLM_KEY_ENV[@]}"} \
+        php artisan "$@" --env=bughunt.local
+    local rc=$?
+    secret_xtrace_restore
+    return "${rc}"
+}
+
 # createdb / dropdb — admin 経路 (bughunt role は CREATEDB を持たない)。
 pg_admin_for_provision() {
     local op=$1 db=$2   # op ∈ {createdb, dropdb}
@@ -1245,6 +1268,23 @@ cmd_reseed() {
     echo "reseeded: ${db}"
 }
 
+# パイプライン通し確認 (dev:pipeline-smoke)。★実 LLM を 3 段呼ぶため課金が発生する。
+# 費用の防壁として orchestrator gate を最初の実効文に置く (子 wrapper にも露出させない)。
+# 転送する artisan option は allowlist で明示列挙する (--shard / --run-id は script が消費し転送しない)。
+cmd_pipeline_smoke() {
+    require_orchestrator "pipeline-smoke"
+    local shard=$1 run_id=$2 smoke_check=$3 smoke_json=$4 smoke_org=$5
+    require_manifest "${run_id}"
+    local db url
+    db="$(shard_db "${shard}")"; url="$(shard_url "${shard}")"
+    prepare_mode_and_preflight
+    local -a smoke_args=(dev:pipeline-smoke --force)
+    [[ -n "${smoke_check}" ]] && smoke_args+=(--check)
+    [[ -n "${smoke_json}" ]] && smoke_args+=(--json)
+    [[ -n "${smoke_org}" ]] && smoke_args+=("--org=${smoke_org}")
+    artisan_with_mode_for_shard "${db}" "${url}" "${smoke_args[@]}"
+}
+
 cmd_db_check() {
     local shard=$1 run_id=$2
     local db url
@@ -1489,6 +1529,36 @@ ENVEOF
     [[ "${rc}" == 0 ]] || t_fail "親 (token有り) で gate が通過しない (rc=${rc})"
     t_ok "orchestrator gate (provision/provision-all/teardown は親専用)"
 
+    echo "[e3] pipeline-smoke gate: 費用の防壁 (orchestrator token 必須) と option 転送 allowlist"
+    # (a) token 無しでは**副作用の前に** die する (manifest 確認 / mode 構築 / artisan 起動のいずれにも到達しない)。
+    local e3_marker="${TMP_BASE}/e3-pipeline-smoke-side-effects"
+    rm -f "${e3_marker}"
+    rc=0
+    ( unset BUGHUNT_SELFTEST_DRYRUN; unset BUGHUNT_ORCHESTRATOR
+      require_manifest() { echo "require_manifest" >> "${e3_marker}"; }
+      prepare_mode_and_preflight() { echo "prepare_mode_and_preflight" >> "${e3_marker}"; }
+      artisan_with_mode_for_shard() { echo "artisan" >> "${e3_marker}"; }
+      cmd_pipeline_smoke 0 20990301-000000 1 "" "" ) >/dev/null 2>&1 || rc=$?
+    [[ "${rc}" == 1 ]] || t_fail "[e3] token 無しの pipeline-smoke が die しない (rc=${rc})"
+    [[ ! -f "${e3_marker}" ]] \
+        || t_fail "[e3] gate より前に副作用が起きた (記録: $(tr '\n' ' ' < "${e3_marker}"))"
+
+    # (b) --shard / --run-id は script が消費し artisan へ転送しない (--force は常に付ける)。
+    local e3_args="${TMP_BASE}/e3-pipeline-smoke-args"
+    rm -f "${e3_args}"
+    ( unset BUGHUNT_SELFTEST_DRYRUN; export BUGHUNT_ORCHESTRATOR=1
+      require_manifest() { :; }
+      prepare_mode_and_preflight() { :; }
+      artisan_with_mode_for_shard() { shift 2; echo "$*" > "${e3_args}"; }
+      cmd_pipeline_smoke 1 20990301-000000 1 1 7 ) >/dev/null 2>&1
+    [[ -f "${e3_args}" ]] || t_fail "[e3] artisan wrapper が呼ばれていない"
+    grep -q -- 'dev:pipeline-smoke --force --check --json --org=7' "${e3_args}" 2>/dev/null \
+        || t_fail "[e3] 転送 option が allowlist どおりでない (実際: $(cat "${e3_args}" 2>/dev/null))"
+    grep -q -- '--shard' "${e3_args}" 2>/dev/null && t_fail "[e3] --shard が artisan へ転送された"
+    grep -q -- '--run-id' "${e3_args}" 2>/dev/null && t_fail "[e3] --run-id が artisan へ転送された"
+    rm -f "${e3_marker}" "${e3_args}"
+    t_ok "pipeline-smoke gate (orchestrator 専用 / option 転送 allowlist)"
+
     echo "[f] createdb 実行コマンドに OWNER bughunt が含まれる"
     local createdb_cmd
     createdb_cmd="$(declare -f pg_admin_for_provision)"
@@ -2448,6 +2518,8 @@ main() {
     local sub="${1:-}"
     shift || true
     local shard="" run_id="" count=5 drop_db="" parallel=4 hold_lock=""
+    # pipeline-smoke へ転送する option (allowlist)。他サブコマンドでの指定は下で die 2 する。
+    local smoke_check="" smoke_json="" smoke_org="" _smoke_flag=0
     COVERAGE=""    # --coverage: pcov 付きで serve 起動しコード到達カバレッジを収集 (既定 OFF)
     # モードは既定 real-llm + fake-storage。専用フラグ変数で「同時指定」「適用範囲」を判定する
     # (LLM_MODE/STORAGE_MODE の上書きだけだと「既定と同値の明示指定」を取りこぼすため)。
@@ -2465,6 +2537,9 @@ main() {
             --real-llm) LLM_MODE="real"; _llm_flag_real=1; shift ;;
             --fake-llm) LLM_MODE="fake"; _llm_flag_fake=1; shift ;;
             --real-storage) STORAGE_MODE="real"; _storage_flag_real=1; shift ;;
+            --check) smoke_check=1; _smoke_flag=1; shift ;;
+            --json) smoke_json=1; _smoke_flag=1; shift ;;
+            --org=*) smoke_org="${1#--org=}"; _smoke_flag=1; shift ;;
             --drop-db) drop_db="--drop-db"; shift ;;
             --hold-lock) hold_lock="--hold-lock"; shift ;;
             *) die 2 "unknown option: $1" ;;
@@ -2485,6 +2560,11 @@ main() {
             || die 2 "--real-llm / --fake-llm / --real-storage は provision または provision-all でのみ使える"
     fi
 
+    # smoke option は pipeline-smoke 専用 (--coverage / モードフラグと同じ流儀)。
+    if [[ "${_smoke_flag}" == 1 && "${sub}" != "pipeline-smoke" ]]; then
+        die 2 "--check / --json / --org は pipeline-smoke でのみ使える"
+    fi
+
     case "${sub}" in
         provision)
             validate_shard "${shard}"; validate_run_id "${run_id}"
@@ -2505,6 +2585,9 @@ main() {
             validate_shard "${shard}"; validate_run_id "${run_id}"
             [[ "${count}" =~ ^[0-9]+$ ]] || die 2 "--count は整数"
             cmd_mail_urls "${shard}" "${run_id}" "${count}" ;;
+        pipeline-smoke)
+            validate_shard "${shard}"; validate_run_id "${run_id}"
+            cmd_pipeline_smoke "${shard}" "${run_id}" "${smoke_check}" "${smoke_json}" "${smoke_org}" ;;
         verify-run)
             validate_run_id "${run_id}"
             cmd_verify_run "${run_id}" ;;
diff --git a/tests/Architecture/BughuntOrchestratorGateInvariantTest.php b/tests/Architecture/BughuntOrchestratorGateInvariantTest.php
index 00904e2..97b59dc 100644
--- a/tests/Architecture/BughuntOrchestratorGateInvariantTest.php
+++ b/tests/Architecture/BughuntOrchestratorGateInvariantTest.php
@@ -124,13 +124,16 @@ function bughuntGateFirstEffectiveStatement(string $window): string
     expect($window)->toMatch('/is_dryrun\s*&&\s*return\s*0/');
 });
 
-test('provision / provision-all / teardown が最初の実効文で require_orchestrator を呼ぶこと', function (): void {
+test('provision / provision-all / teardown / pipeline-smoke が最初の実効文で require_orchestrator を呼ぶこと', function (): void {
     $sh = bughuntGateReadSource('scripts/bug-hunt-shard.sh');
 
     $expectations = [
         'cmd_provision' => 'provision',
         'cmd_provision_all' => 'provision-all',
         'cmd_teardown' => 'teardown',
+        // pipeline-smoke は実 LLM を 3 段呼ぶ = 実行そのものが課金である。
+        // dev DB 防御と同じ理由ではなく**費用の防壁**として同じ gate に乗せる。
+        'cmd_pipeline_smoke' => 'pipeline-smoke',
     ];
     foreach ($expectations as $function => $label) {
         $window = bughuntGateFunctionWindow($sh, $function);
diff --git a/tests/Architecture/BughuntShardCapInvariantTest.php b/tests/Architecture/BughuntShardCapInvariantTest.php
index e776452..bd149f8 100644
--- a/tests/Architecture/BughuntShardCapInvariantTest.php
+++ b/tests/Architecture/BughuntShardCapInvariantTest.php
@@ -522,12 +522,21 @@ function bughuntCapProseViolations(string $relativePath, string $content, int $c
     }
 });
 
-test('DetectsBughuntDatabase の regex が cap を超える _[1-8] を保持していること', function (): void {
-    $source = bughuntCapReadSource('database/seeders/Concerns/DetectsBughuntDatabase.php');
+test('BughuntDatabaseGuard の regex が cap を超える _[1-8] を保持していること', function (): void {
+    // 判定の SSOT は app 側 (seeder trait は委譲するだけの薄い殻)。
+    // dev DB 防御は smoke コマンドの fail-secure 条件もここを読むため、正本を 1 つに保つ。
+    $source = bughuntCapReadSource('app/Support/BughuntDatabaseGuard.php');
 
     expect($source)->toContain('/^bug_hunt(_[1-8])?$/');
 });
 
+test('DetectsBughuntDatabase は regex を二重に持たず SSOT へ委譲していること', function (): void {
+    $source = bughuntCapReadSource('database/seeders/Concerns/DetectsBughuntDatabase.php');
+
+    expect($source)->toContain('BughuntDatabaseGuard')
+        ->and($source)->not->toContain('bug_hunt(');
+});
+
 test('run-browser-test.sh の pre-flight guard が cap を超える 8018 まで見ていること', function (): void {
     $source = bughuntCapReadSource('scripts/run-browser-test.sh');
 
diff --git a/tests/Architecture/FakeClassReferenceInvariantTest.php b/tests/Architecture/FakeClassReferenceInvariantTest.php
index c41a6da..d7aa2de 100644
--- a/tests/Architecture/FakeClassReferenceInvariantTest.php
+++ b/tests/Architecture/FakeClassReferenceInvariantTest.php
@@ -33,6 +33,12 @@
     // fake storage signed route の受け口 (FakeStorageGate 成立時のみ route 登録される)
     'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
     'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
+    // bug-hunt 専用の通し確認コマンド。fake storage へ実バイトを置く必要があり、
+    // FakeStorageGate 成立時のみ動く (上 2 件の controller と同 species)。
+    // 本番経路からは到達しない (artisan 手動実行のみ・スケジュール登録なし)。
+    // ★実装条件: constructor 引数を持たず、fake は handle() の fail-secure 4 条件を
+    //   通過した**後**にのみ app() で遅延解決する。
+    'app/Console/Commands/Development/PipelineSmokeCommand.php',
     // provider 登録点。FakeExternalsServiceProvider (配置例外クラス) を必ず参照する
     'bootstrap/providers.php',
 ];
@@ -93,13 +99,14 @@
     expect($violations)->toBe([]);
 });
 
-test('4-4 参照 allowlist は 4 件から増えていない', function (): void {
+test('4-4 参照 allowlist は 5 件から増えていない', function (): void {
     // 増やすときは理由コメントを添えて**ここも触る** (意図的な摩擦)。
-    expect(FAKE_REFERENCE_ALLOWED)->toHaveCount(4)
+    expect(FAKE_REFERENCE_ALLOWED)->toHaveCount(5)
         ->and(FAKE_REFERENCE_ALLOWED)->toBe([
             'app/Providers/FakeExternalsServiceProvider.php',
             'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
             'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
+            'app/Console/Commands/Development/PipelineSmokeCommand.php',
             'bootstrap/providers.php',
         ]);
 });
diff --git a/tests/Architecture/PromptUntrustedInputContractTest.php b/tests/Architecture/PromptUntrustedInputContractTest.php
index 83a7715..2f37a88 100644
--- a/tests/Architecture/PromptUntrustedInputContractTest.php
+++ b/tests/Architecture/PromptUntrustedInputContractTest.php
@@ -2,6 +2,8 @@
 
 declare(strict_types=1);
 
+use App\DataTransferObjects\LlmCallContextData;
+use App\Models\VideoManual;
 use App\Prompts\ExampleSummaryPrompt;
 use App\Prompts\ScenarioGenerationPrompt;
 use App\Prompts\SopExtractPrompt;
@@ -18,36 +20,65 @@
  *  2. deny-by-default: app/Prompts/ 配下の全 factory を inventory に分類する (未分類 fail)。
  *     新しい prompt を追加したら untrusted 変数名を inventory へ登録するか、
  *     end-user 入力なしなら空配列で登録する。
+ *  3. coverage(帰属): factory が組み立てた Prompt の metadata_context に
+ *     llm_call_logs の帰属キー (organization_id / subject_type / subject_id) が入ること。
+ *     欠けると llm_call_logs が metadata_missing になり、組織別・対象別の費用が出せない。
+ *     帰属の対象を持たない prompt (見本など) は期待キーを空配列で登録して exempt を明示する。
+ *
+ * ★ この 3 層目が固定できるのは **組み立て済み Prompt の内部**までである。
+ *   「metadata_context がイベント → listener → llm_call_logs へ流れること」は
+ *   テストレーンでは検証できない (Prompt::$fake は executePrism() の先頭で短絡して
+ *   PromptExecutionCompleted を発火せず、PromptFake::record() は metadata を記録しない)。
+ *   その end-to-end 確認は bug-hunt レーンの `dev:pipeline-smoke` の llm-evidence 段が担う。
  *
  * 検査対象クラスは dataset 化しており、prompt 追加時は inventory (= dataset の源) に
  * 1 エントリ足すだけで両層の検査に載る。
  */
 
 /**
- * prompt factory FQCN => [untrusted template 変数名の list, 組み立て closure]。
+ * 検査用の帰属 context。DB へ書かない (makeOne + 親キーの明示指定で親 factory を解決させない)。
+ * Architecture lane は DB を張らないため、ここで DB に触れてはならない。
+ */
+function promptAttributionContext(): LlmCallContextData
+{
+    $manual = VideoManual::factory()->makeOne(['id' => 42, 'project_id' => 1, 'created_by' => 1]);
+
+    return LlmCallContextData::for(7, $manual, 3);
+}
+
+/**
+ * prompt factory FQCN => [untrusted template 変数名の list, 期待する帰属キーの list, 組み立て closure]。
  * end-user 入力なしの prompt は変数 list を空配列で登録する (exempt を明示)。
+ * 帰属の対象を持たない prompt は帰属キー list を空配列で登録する (exempt を明示)。
  *
- * @return array<class-string, array{list<string>, Closure(): Prompt}>
+ * @return array<class-string, array{list<string>, list<string>, Closure(): Prompt}>
  */
 function promptUntrustedInputInventory(): array
 {
+    $context = promptAttributionContext();
+
     return [
+        // 見本 prompt。呼び出し元が無く帰属の対象も無いので帰属は exempt (空配列で明示)
         ExampleSummaryPrompt::class => [
             ['text'],
+            [],
             fn (): Prompt => ExampleSummaryPrompt::make('untrusted end-user text'),
         ],
         // AI 解析 3 段 (SOP 由来の untrusted テキスト/JSON は全段 UserInput 経由)
         SopExtractPrompt::class => [
             ['text'],
-            fn (): Prompt => SopExtractPrompt::make('untrusted sop text'),
+            ['organization_id', 'subject_type', 'subject_id'],
+            fn (): Prompt => SopExtractPrompt::make('untrusted sop text', $context),
         ],
         WorkDecompositionPrompt::class => [
             ['extracted'],
-            fn (): Prompt => WorkDecompositionPrompt::make('{"sections":[]}'),
+            ['organization_id', 'subject_type', 'subject_id'],
+            fn (): Prompt => WorkDecompositionPrompt::make('{"sections":[]}', $context),
         ],
         ScenarioGenerationPrompt::class => [
             ['decomposition'],
-            fn (): Prompt => ScenarioGenerationPrompt::make('{"steps":[]}'),
+            ['organization_id', 'subject_type', 'subject_id'],
+            fn (): Prompt => ScenarioGenerationPrompt::make('{"steps":[]}', $context),
         ],
     ];
 }
@@ -84,13 +115,13 @@ function discoverPromptFactoryClasses(): array
 }
 
 dataset('untrusted_prompt_inputs', function (): iterable {
-    foreach (promptUntrustedInputInventory() as $class => [$untrustedVars, $factory]) {
-        yield $class => [$class, $untrustedVars, $factory];
+    foreach (promptUntrustedInputInventory() as $class => [$untrustedVars, $attributionKeys, $factory]) {
+        yield $class => [$class, $untrustedVars, $attributionKeys, $factory];
     }
 });
 
 // ── 1. coverage(型) ──────────────────────────────────────────────────
-test('untrusted template 変数は UserInput 型で渡される', function (string $class, array $untrustedVars, Closure $factory): void {
+test('untrusted template 変数は UserInput 型で渡される', function (string $class, array $untrustedVars, array $_attributionKeys, Closure $factory): void {
     $prompt = $factory();
     expect($prompt)->toBeInstanceOf(Prompt::class);
 
@@ -125,3 +156,35 @@ function discoverPromptFactoryClasses(): array
     $stale = array_values(array_diff(array_keys(promptUntrustedInputInventory()), $discovered));
     expect($stale)->toBe([], 'inventory に現存しない prompt factory: '.implode(', ', $stale));
 });
+
+// ── 3. coverage(帰属) ────────────────────────────────────────────────
+test('帰属が必要な prompt は metadata_context に organization / subject を持つ', function (
+    string $class,
+    array $_untrustedVars,
+    array $attributionKeys,
+    Closure $factory,
+): void {
+    $prompt = $factory();
+
+    // Prompt::withMetadata() が array_merge するだけの内部バッグを reflection で取り出す
+    // (パッケージは中身を解釈せず PromptExecution* イベントへそのまま流す)。
+    $property = new ReflectionProperty(Prompt::class, 'metadata_context');
+    /** @var array<string, mixed> $metadata */
+    $metadata = $property->getValue($prompt);
+
+    if ($attributionKeys === []) {
+        expect($metadata)->toBe([], "{$class}: 帰属 exempt として登録されていますが metadata が付いています");
+
+        return;
+    }
+
+    foreach ($attributionKeys as $key) {
+        // toHaveKey() の第 2 引数は「期待する値」なので、説明付きで落とすには assertArrayHasKey を使う
+        $this->assertArrayHasKey(
+            $key,
+            $metadata,
+            "{$class}: withMetadata() で '{$key}' を渡してください"
+            .' (欠けると llm_call_logs が metadata_missing になり組織・対象別の費用が出せません)',
+        );
+    }
+})->with('untrusted_prompt_inputs');
diff --git a/tests/Architecture/ScenarioWritePathInventoryTest.php b/tests/Architecture/ScenarioWritePathInventoryTest.php
index 222da4a..9d82392 100644
--- a/tests/Architecture/ScenarioWritePathInventoryTest.php
+++ b/tests/Architecture/ScenarioWritePathInventoryTest.php
@@ -55,6 +55,9 @@ final class ScenarioWritePathScanner
         'Services/Manual/RenderJobService.php',
         'Services/Manual/RenderPipeline.php',
         'Models/RenderJob.php',
+        // bug-hunt 専用の通し確認コマンド。analysis 段の成功条件 (scenario_version >= 1) を
+        // **読み取るだけ**で、書き込みは 1 箇所も持たない (書き込みは検出 2 が別途 deny する)。
+        'Console/Commands/Development/PipelineSmokeCommand.php',
         // T032: failJob が失敗確定時の scenario_version を job にスナップショット読みする
         // (書き込むのは scenario_version_at_terminal であり scenario_version ではない)
         'Services/Manual/AnalysisJobService.php',
diff --git a/tests/Feature/Console/LlmCostReportCommandTest.php b/tests/Feature/Console/LlmCostReportCommandTest.php
new file mode 100644
index 0000000..4dfab00
--- /dev/null
+++ b/tests/Feature/Console/LlmCostReportCommandTest.php
@@ -0,0 +1,153 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\LlmCallLog;
+use App\Models\Organization;
+use App\Models\VideoManual;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Command;
+use Illuminate\Support\Facades\Artisan;
+
+/**
+ * 既定期間 (30 日前 <= created_at < 現在) に確実に入る記録時刻。
+ *
+ * `until` は排他かつ `created_at` は秒精度の timestamp 列なので、`now()` ちょうどに
+ * 記録された行は既定期間に入らないことがある。既定オプションの検査ではここを使う。
+ */
+function llmCostReportRecordedAt(): CarbonImmutable
+{
+    return CarbonImmutable::now()->subMinutes(5);
+}
+
+/*
+ * 期間集計コマンド (施策 3)。読み取り専用で、集計本体は LlmCostReportService が持つ
+ * (1 実装・複数入口)。ここでは入口としての契約 (入力検証・終了コード・出力形) を固定する。
+ *
+ * 出力を読むため Artisan::call() / Artisan::output() を使う
+ * (PendingCommand の mock 出力は table() 描画を素通しするため出力検査に使えない)。
+ */
+
+/**
+ * @param  array<string, mixed>  $parameters
+ * @return array{int, string} [終了コード, 標準出力]
+ */
+function runLlmCostReport(array $parameters = []): array
+{
+    $exitCode = Artisan::call('operations:llm-cost-report', $parameters);
+
+    return [$exitCode, Artisan::output()];
+}
+
+/** @return array<string, mixed> */
+function runLlmCostReportJson(array $parameters = []): array
+{
+    [$exitCode, $output] = runLlmCostReport($parameters + ['--json' => true]);
+    expect($exitCode)->toBe(Command::SUCCESS);
+
+    /** @var array<string, mixed> $decoded */
+    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
+
+    return $decoded;
+}
+
+it('既定オプションで表を出力し成功する', function (): void {
+    LlmCallLog::factory()->create([
+        'prompt_template' => 'sop-extract',
+        'created_at' => llmCostReportRecordedAt(),
+    ]);
+
+    [$exitCode, $output] = runLlmCostReport();
+
+    expect($exitCode)->toBe(Command::SUCCESS)
+        ->and($output)->toContain('sop-extract')
+        ->and($output)->toContain('TOTAL')
+        ->and($output)->toContain('meta_missing');
+});
+
+it('--json は LlmCostReportData の shape をそのまま出す', function (): void {
+    LlmCallLog::factory()->create([
+        'prompt_template' => 'sop-extract',
+        'created_at' => llmCostReportRecordedAt(),
+    ]);
+
+    $decoded = runLlmCostReportJson();
+
+    expect($decoded)->toHaveKeys(['group_by', 'since', 'until', 'after_id', 'rows', 'total'])
+        ->and($decoded['group_by'])->toBe('prompt_template')
+        ->and($decoded['rows'][0]['key'])->toBe('sop-extract')
+        ->and($decoded['rows'][0])->toHaveKeys([
+            'key', 'calls', 'input_tokens', 'output_tokens',
+            'total_cost_usd', 'total_cost_jpy',
+            'usd_unresolved_calls', 'jpy_unresolved_calls',
+            'failed_calls', 'metadata_missing_calls',
+        ])
+        ->and($decoded['total']['key'])->toBe('TOTAL');
+});
+
+it('--group-by=subject が動く', function (): void {
+    $manual = VideoManual::factory()->create();
+    LlmCallLog::factory()->create([
+        'subject_type' => $manual->getMorphClass(),
+        'subject_id' => (string) $manual->id,
+        'created_at' => llmCostReportRecordedAt(),
+    ]);
+
+    $decoded = runLlmCostReportJson(['--group-by' => 'subject']);
+
+    expect($decoded['rows'][0]['key'])->toBe($manual->getMorphClass().'#'.$manual->id);
+});
+
+it('--group-by=organization が動く', function (): void {
+    $organization = Organization::factory()->create();
+    LlmCallLog::factory()->create([
+        'organization_id' => $organization->id,
+        'created_at' => llmCostReportRecordedAt(),
+    ]);
+
+    $decoded = runLlmCostReportJson(['--group-by' => 'organization']);
+
+    expect($decoded['rows'][0]['key'])->toBe((string) $organization->id);
+});
+
+it('不正な --group-by は終了コード 2', function (): void {
+    [$exitCode] = runLlmCostReport(['--group-by' => 'manual']);
+
+    expect($exitCode)->toBe(Command::INVALID);
+});
+
+it('parse 不能な --since は終了コード 2', function (): void {
+    [$exitCode] = runLlmCostReport(['--since' => 'not-a-date']);
+
+    expect($exitCode)->toBe(Command::INVALID);
+});
+
+it('桁溢れした --until は終了コード 2 (再フォーマット一致で厳格に弾く)', function (): void {
+    [$exitCode] = runLlmCostReport(['--until' => '2026-13-45']);
+
+    expect($exitCode)->toBe(Command::INVALID);
+});
+
+it('since >= until は終了コード 2', function (): void {
+    [$exitCode] = runLlmCostReport(['--since' => '2026-08-10', '--until' => '2026-08-01']);
+
+    expect($exitCode)->toBe(Command::INVALID);
+});
+
+it('日付のみの --until はその日を含む (排他境界を翌日 0 時にする)', function (): void {
+    LlmCallLog::factory()->create([
+        'prompt_template' => 'end-of-day',
+        'created_at' => CarbonImmutable::parse('2026-08-10 23:59:59'),
+    ]);
+
+    $decoded = runLlmCostReportJson(['--since' => '2026-08-01', '--until' => '2026-08-10']);
+
+    expect($decoded['until'])->toBe(CarbonImmutable::parse('2026-08-11 00:00:00')->toIso8601String())
+        ->and($decoded['rows'][0]['key'])->toBe('end-of-day');
+});
+
+it('日時つきの --until はそのまま排他境界として使う', function (): void {
+    $decoded = runLlmCostReportJson(['--since' => '2026-08-01', '--until' => '2026-08-10 12:00:00']);
+
+    expect($decoded['until'])->toBe(CarbonImmutable::parse('2026-08-10 12:00:00')->toIso8601String());
+});
diff --git a/tests/Feature/Console/PipelineSmokeCommandTest.php b/tests/Feature/Console/PipelineSmokeCommandTest.php
new file mode 100644
index 0000000..64747aa
--- /dev/null
+++ b/tests/Feature/Console/PipelineSmokeCommandTest.php
@@ -0,0 +1,281 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Project\ProjectService;
+use Illuminate\Console\Command;
+use Illuminate\Support\Facades\Artisan;
+use Illuminate\Support\Facades\DB;
+use Kent013\PrismPrompt\Prompt;
+
+/*
+ * pipeline smoke コマンド (施策 6) の**固有ロジック**を実 LLM なしに固定する。
+ *
+ * 固定するのは「fail-secure 条件 / preflight / 確認 / 出力」まで。
+ * 各段の配線は段ごとの Feature テストが既に持っており、ffmpeg を Process::fake すると
+ * このコマンドの唯一の固有価値 (実 ffmpeg が本当に回るか) が消えて偽グリーンになるため、
+ * 全段を fake で通すテストは**書かない**。
+ * `llm-evidence` 段の判定は純関数として SmokeFailureClassifierTest が固定する。
+ */
+
+/**
+ * fail-secure 4 条件を満たす状態にする (bug-hunt レーン相当)。
+ *
+ * - env: bughunt.local
+ * - DB 名: bug_hunt (接続名だけを差し替える。実 DB はテスト DB のまま)
+ * - fake storage: on / fake LLM: off
+ * - ffmpeg / ffprobe: PHP バイナリで代用 (`-version` が 0 終了する = preflight の分岐だけを固定する)
+ */
+function enterSmokeLane(): void
+{
+    app()->detectEnvironment(fn (): string => 'bughunt.local');
+    DB::connection()->setDatabaseName('bug_hunt');
+    config()->set('testing.fake_storage', true);
+    config()->set('testing.fake_llm', false);
+    config()->set('manual.render_ffmpeg_binary', PHP_BINARY);
+    config()->set('manual.render_ffprobe_binary', PHP_BINARY);
+}
+
+/**
+ * preflight を通せる組織 (所属 user あり・チケット残高十分) を作る。
+ *
+ * @return array{Organization, User}
+ */
+function smokeReadyOrganization(int $tickets = 100): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    app(TicketLedgerService::class)->grant($organization, $tickets, 'pipeline-smoke test');
+
+    return [$organization, $owner];
+}
+
+/**
+ * @param  array<string, mixed>  $parameters
+ * @return array{int, string}
+ */
+function runPipelineSmoke(array $parameters = []): array
+{
+    $exitCode = Artisan::call('dev:pipeline-smoke', $parameters);
+
+    return [$exitCode, Artisan::output()];
+}
+
+// ── fail-secure 4 条件 (--force でも迂回できない) ───────────────────────
+
+it('bughunt.local 以外の env では実行しない', function (): void {
+    smokeReadyOrganization();
+    // enterSmokeLane() を呼ばない = env は testing のまま
+
+    [$exitCode, $output] = runPipelineSmoke(['--check' => true]);
+
+    expect($exitCode)->toBe(Command::FAILURE)
+        ->and($output)->toContain('env が bughunt.local ではありません')
+        ->and(Prompt::isFaking())->toBeFalse();
+});
+
+it('bug-hunt DB 以外では実行しない', function (): void {
+    smokeReadyOrganization();
+    enterSmokeLane();
+    DB::connection()->setDatabaseName('aicue_dev');
+
+    [$exitCode, $output] = runPipelineSmoke(['--check' => true]);
+
+    expect($exitCode)->toBe(Command::FAILURE)
+        ->and($output)->toContain('bug-hunt DB ではありません');
+});
+
+it('fake storage が無効なら実行しない', function (): void {
+    smokeReadyOrganization();
+    enterSmokeLane();
+    config()->set('testing.fake_storage', false);
+
+    [$exitCode, $output] = runPipelineSmoke(['--check' => true]);
+
+    expect($exitCode)->toBe(Command::FAILURE)
+        ->and($output)->toContain('fake storage が無効です');
+});
+
+it('fake LLM が有効なら実行しない', function (): void {
+    smokeReadyOrganization();
+    enterSmokeLane();
+    config()->set('testing.fake_llm', true);
+
+    [$exitCode, $output] = runPipelineSmoke(['--check' => true]);
+
+    expect($exitCode)->toBe(Command::FAILURE)
+        ->and($output)->toContain('fake LLM が有効です');
+});
+
+it('--force でも fail-secure 条件は迂回できない', function (): void {
+    smokeReadyOrganization();
+    enterSmokeLane();
+    config()->set('testing.fake_llm', true);
+
+    [$exitCode, $output] = runPipelineSmoke(['--force' => true]);
+
+    expect($exitCode)->toBe(Command::FAILURE)
+        ->and($output)->toContain('fake LLM が有効です')
+        ->and(VideoManual::query()->count())->toBe(0);
+});
+
+// ── preflight (--check) ────────────────────────────────────────────────
+
+it('--check は preflight の結果を出して終了する (LLM を 1 回も呼ばない)', function (): void {
+    [$organization] = smokeReadyOrganization();
+    enterSmokeLane();
+
+    [$exitCode, $output] = runPipelineSmoke(['--check' => true]);
+
+    expect($exitCode)->toBe(Command::SUCCESS)
+        ->and($output)->toContain('preflight')
+        ->and($output)->toContain('org=#'.$organization->id)
+        ->and($output)->toContain('PASS')
+        // Prompt の fake すら install しない (StrayLlmCallGuard が赤くならないことと対)
+        ->and(Prompt::isFaking())->toBeFalse()
+        ->and(VideoManual::query()->count())->toBe(0);
+});
+
+it('--check で ffmpeg が実行できなければ preflight 失敗', function (): void {
+    smokeReadyOrganization();
+    enterSmokeLane();
+    config()->set('manual.render_ffmpeg_binary', '/nonexistent/ffmpeg-for-smoke-test');
+
+    [$exitCode, $output] = runPipelineSmoke(['--check' => true, '--json' => true]);
+    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
+
+    expect($exitCode)->toBe(Command::FAILURE)
+        ->and($decoded['failure_class'])->toBe('preflight')
+        ->and($decoded['context']['ffmpeg'])->toBe('MISSING');
+});
+
+it('--check でチケット残高が足りなければ preflight 失敗', function (): void {
+    // 残高不足の組織しか無い状態 (--org で名指しして「先頭の組織」探索に落とさない)
+    [$organization] = smokeReadyOrganization(tickets: 1);
+    enterSmokeLane();
+
+    [$exitCode, $output] = runPipelineSmoke([
+        '--check' => true, '--json' => true, '--org' => (string) $organization->id,
+    ]);
+    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
+
+    expect($exitCode)->toBe(Command::FAILURE)
+        ->and($decoded['failure_class'])->toBe('preflight')
+        ->and($decoded['stages'][0]['detail'])->toContain('チケット残高が不足');
+});
+
+it('--check で対象組織に所属 user がいなければ preflight 失敗', function (): void {
+    $organization = Organization::factory()->create();
+    app(TicketLedgerService::class)->grant($organization, 100, 'pipeline-smoke test');
+    enterSmokeLane();
+
+    [$exitCode, $output] = runPipelineSmoke([
+        '--check' => true, '--json' => true, '--org' => (string) $organization->id,
+    ]);
+    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
+
+    expect($exitCode)->toBe(Command::FAILURE)
+        ->and($decoded['failure_class'])->toBe('preflight')
+        ->and($decoded['stages'][0]['detail'])->toContain('所属 user がいません');
+});
+
+it('--check --json の shape が固定される', function (): void {
+    smokeReadyOrganization();
+    enterSmokeLane();
+
+    [$exitCode, $output] = runPipelineSmoke(['--check' => true, '--json' => true]);
+    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
+
+    expect($exitCode)->toBe(Command::SUCCESS)
+        ->and($decoded)->toHaveKeys([
+            'passed', 'check_only', 'failure_class', 'total_elapsed_ms', 'context', 'stages', 'cost',
+        ])
+        ->and($decoded['passed'])->toBeTrue()
+        ->and($decoded['check_only'])->toBeTrue()
+        ->and($decoded['failure_class'])->toBeNull()
+        // --check は LLM を 1 回も呼ばないのでコストレポートは付かない
+        ->and($decoded['cost'])->toBeNull()
+        ->and($decoded['stages'][0])->toHaveKeys(['stage', 'ok', 'elapsed_ms', 'detail', 'failure_class'])
+        ->and($decoded['stages'][0]['stage'])->toBe('preflight');
+});
+
+it('--check は Default Project が無くても成功し will-create と出す (作成はしない)', function (): void {
+    smokeReadyOrganization();
+    enterSmokeLane();
+    expect(Project::query()->count())->toBe(0);
+
+    [$exitCode, $output] = runPipelineSmoke(['--check' => true, '--json' => true]);
+    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
+
+    expect($exitCode)->toBe(Command::SUCCESS)
+        ->and($decoded['context']['project'])->toBe('will-create')
+        // --check は DB を 1 行も変更しない
+        ->and(Project::query()->count())->toBe(0);
+});
+
+it('--check は既存 Default Project を existing として表示する', function (): void {
+    [$organization, $owner] = smokeReadyOrganization();
+    $project = app(ProjectService::class)->createProject($organization, '既存', null);
+    enterSmokeLane();
+
+    [, $output] = runPipelineSmoke(['--check' => true, '--json' => true]);
+    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
+
+    expect($decoded['context']['project'])->toBe('existing #'.$project->id)
+        ->and($decoded['context']['actor'])->toBe('#'.$owner->id);
+});
+
+it('--check は DB を読めない場合も preflight 失敗として JSON を返す', function (): void {
+    smokeReadyOrganization();
+    enterSmokeLane();
+    // bug-hunt DB が未 provision / 未 migrate の状況を再現する
+    // (DDL はトランザクショナルなので RefreshDatabase のロールバックで元に戻る)
+    DB::statement('ALTER TABLE organizations RENAME TO organizations_absent_for_test');
+
+    [$exitCode, $output] = runPipelineSmoke(['--check' => true, '--json' => true]);
+    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
+
+    expect($exitCode)->toBe(Command::FAILURE)
+        ->and($decoded['failure_class'])->toBe('preflight')
+        ->and($decoded['stages'][0]['detail'])->toContain('DB を読めません');
+});
+
+// ── 実行確認 (課金の防壁) ──────────────────────────────────────────────
+
+it('bughunt.local でも実行確認が出て、拒否したら何も実行しない', function (): void {
+    smokeReadyOrganization();
+    enterSmokeLane();
+
+    // confirmToProceed() の第 2 引数 (常に確認する) を外すと確認が出ず、この期待が落ちる
+    $this->artisan('dev:pipeline-smoke')
+        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
+        ->assertExitCode(Command::INVALID);
+
+    expect(VideoManual::query()->count())->toBe(0)
+        ->and(Project::query()->count())->toBe(0);
+});
+
+it('--force なら確認を出さずに進む (fail-secure 条件は依然として効く)', function (): void {
+    [$organization] = smokeReadyOrganization();
+    enterSmokeLane();
+    // fixture 段で必ず落ちるようにして、実 LLM / worker 待ちへ進ませない
+    // (Default Project 不在 + max_projects=0 → ProjectService::createProject が Quota で失敗)
+    config()->set('quota.plans.'.config()->string('quota.fallback_plan').'.max_projects', 0);
+    expect($organization->plan_code)->toBeNull();
+
+    [$exitCode, $output] = runPipelineSmoke(['--force' => true, '--json' => true]);
+    $decoded = json_decode(trim($output), true, 512, JSON_THROW_ON_ERROR);
+
+    // 確認は出ず (出ていれば PendingCommand ではなく Artisan::call が入力待ちで壊れる)、
+    // preflight を通過して fixture 段まで進んだうえで失敗している
+    expect($exitCode)->toBe(Command::FAILURE)
+        ->and($decoded['stages'][0]['stage'])->toBe('preflight')
+        ->and($decoded['stages'][0]['ok'])->toBeTrue()
+        ->and($decoded['stages'][1]['stage'])->toBe('fixture')
+        ->and($decoded['stages'][1]['ok'])->toBeFalse()
+        ->and(VideoManual::query()->count())->toBe(0);
+});
diff --git a/tests/Feature/Llm/CannedPromptResponsesTest.php b/tests/Feature/Llm/CannedPromptResponsesTest.php
index 6c4dcd5..cdae497 100644
--- a/tests/Feature/Llm/CannedPromptResponsesTest.php
+++ b/tests/Feature/Llm/CannedPromptResponsesTest.php
@@ -2,6 +2,7 @@
 
 declare(strict_types=1);
 
+use App\DataTransferObjects\LlmCallContextData;
 use App\DataTransferObjects\Manual\Analysis\ExtractedSopData;
 use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
 use App\DataTransferObjects\Manual\Analysis\WorkDecompositionData;
@@ -82,9 +83,9 @@ function systemTextOf(array $messages): string
 function makeRegisteredPrompt(string $key): TextPrompt
 {
     return match ($key) {
-        'sop-extract' => SopExtractPrompt::make('サンプル SOP'),
-        'work-decomposition' => WorkDecompositionPrompt::make('{"header":{},"sections":[]}'),
-        'scenario-generation' => ScenarioGenerationPrompt::make('{"steps":[]}'),
+        'sop-extract' => SopExtractPrompt::make('サンプル SOP', LlmCallContextData::none()),
+        'work-decomposition' => WorkDecompositionPrompt::make('{"header":{},"sections":[]}', LlmCallContextData::none()),
+        'scenario-generation' => ScenarioGenerationPrompt::make('{"steps":[]}', LlmCallContextData::none()),
         'example-summary' => ExampleSummaryPrompt::make('本文'),
         default => throw new InvalidArgumentException("unknown prompt key: {$key}"),
     };
@@ -93,7 +94,7 @@ function makeRegisteredPrompt(string $key): TextPrompt
 // ---- 5-1: canned DTO 通過テスト (主保証) ----
 
 test('sop-extract の canned が ExtractedSopData::fromLlmText を通過する', function (): void {
-    $text = SopExtractPrompt::make('サンプル SOP')->executeSync();
+    $text = SopExtractPrompt::make('サンプル SOP', LlmCallContextData::none())->executeSync();
     Assert::string($text);
 
     $dto = ExtractedSopData::fromLlmText($text);
@@ -102,7 +103,7 @@ function makeRegisteredPrompt(string $key): TextPrompt
 });
 
 test('work-decomposition の canned が WorkDecompositionData::fromLlmText を通過する', function (): void {
-    $text = WorkDecompositionPrompt::make('{"header":{},"sections":[]}')->executeSync();
+    $text = WorkDecompositionPrompt::make('{"header":{},"sections":[]}', LlmCallContextData::none())->executeSync();
     Assert::string($text);
 
     $dto = WorkDecompositionData::fromLlmText($text);
@@ -110,7 +111,7 @@ function makeRegisteredPrompt(string $key): TextPrompt
 });
 
 test('scenario-generation の canned が GeneratedScenarioData::fromLlmText を通過する', function (): void {
-    $text = ScenarioGenerationPrompt::make('{"steps":[]}')->executeSync();
+    $text = ScenarioGenerationPrompt::make('{"steps":[]}', LlmCallContextData::none())->executeSync();
     Assert::string($text);
 
     $dto = GeneratedScenarioData::fromLlmText($text);
diff --git a/tests/Support/Security/DirectFetchInventory.php b/tests/Support/Security/DirectFetchInventory.php
index df14633..ddd8651 100644
--- a/tests/Support/Security/DirectFetchInventory.php
+++ b/tests/Support/Security/DirectFetchInventory.php
@@ -157,6 +157,13 @@ public static function inventory(): array
                 .'HTTP から到達不能で scheduler / queue からも呼ばれず、--reason を監査ログへ残す',
                 commandSignature: 'admin:reset-mfa {id} {--reason=}',
             ),
+            'Console/Commands/Development/PipelineSmokeCommand.php#resolveOrganization#Organization.whereKey:$option#1' => DirectFetchJustificationEntry::operatorConsole(
+                '運用者が bug-hunt レーンで CLI から対象組織を --org=ID で名指しする通し確認コマンド。'
+                .'HTTP から到達不能で scheduler / queue からも呼ばれず、実行そのものが fail-secure 4 条件'
+                .'(env=bughunt.local / bug-hunt DB / fake storage / real LLM) を満たさないと開始しない。'
+                .'対象は常に 1 組織で cross-org の概念が無く、組織を跨ぐ read/write もしない',
+                commandSignature: 'dev:pipeline-smoke {--check} {--org=} {--json} {--force}',
+            ),
             'Console/Commands/Billing/MarkStripeCustomerRedactedCommand.php#handle#Organization.whereKey:$organizationId#1' => DirectFetchJustificationEntry::operatorConsole(
                 '運用者が CLI で組織を id で名指しし、決済事業者側 customer の redaction 実施を記録する保守コマンド。'
                 .'HTTP から到達不能で scheduler / queue からも呼ばれず、cross-org の概念が無い (対象は常に 1 組織)。'
diff --git a/tests/Unit/DataTransferObjects/LlmCallContextDataTest.php b/tests/Unit/DataTransferObjects/LlmCallContextDataTest.php
new file mode 100644
index 0000000..2415272
--- /dev/null
+++ b/tests/Unit/DataTransferObjects/LlmCallContextDataTest.php
@@ -0,0 +1,60 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\LlmCallContextData;
+use App\Models\VideoManual;
+use App\Support\LlmMetadataExtractor;
+
+/*
+ * LLM 呼び出しの帰属コンテキスト DTO (施策 1)。
+ *
+ * この DTO は `Prompt::withMetadata()` へ渡す 4 つの汎用キー
+ * (organization_id / user_id / subject_type / subject_id) の値オブジェクトであり、
+ * listener 側の LlmMetadataExtractor が読み戻せる形になっていることが契約である。
+ */
+
+it('for() は subject を getMorphClass() と主キーの文字列表現で持つ', function (): void {
+    $manual = VideoManual::factory()->makeOne(['id' => 42]);
+
+    $context = LlmCallContextData::for(7, $manual, 3);
+
+    expect($context->organizationId)->toBe(7)
+        ->and($context->userId)->toBe(3)
+        ->and($context->subjectType)->toBe($manual->getMorphClass())
+        ->and($context->subjectId)->toBe('42');
+});
+
+it('null の成分は toMetadata() から落ちる', function (): void {
+    $context = LlmCallContextData::for(null, null);
+
+    expect($context->toMetadata())->toBe([]);
+});
+
+it('organization だけを持つ context は organization_id のみを載せる', function (): void {
+    $context = LlmCallContextData::for(11, null);
+
+    expect($context->toMetadata())->toBe(['organization_id' => 11]);
+});
+
+it('none() は帰属なしを明示し空の metadata を返す', function (): void {
+    $context = LlmCallContextData::none();
+
+    expect($context->organizationId)->toBeNull()
+        ->and($context->userId)->toBeNull()
+        ->and($context->subjectType)->toBeNull()
+        ->and($context->subjectId)->toBeNull()
+        ->and($context->toMetadata())->toBe([]);
+});
+
+it('toMetadata() は LlmMetadataExtractor の 4 抽出器を往復して元の値へ戻る', function (): void {
+    $manual = VideoManual::factory()->makeOne(['id' => 42]);
+    $metadata = LlmCallContextData::for(7, $manual, 3)->toMetadata();
+
+    // listener (RecordLlmCallCost / RecordLlmCallFailure) が行う取り出しと同じ経路
+    expect(LlmMetadataExtractor::extractInt($metadata, 'organization_id'))->toBe(7)
+        ->and(LlmMetadataExtractor::extractInt($metadata, 'user_id'))->toBe(3)
+        ->and(LlmMetadataExtractor::extractString($metadata, 'subject_type'))->toBe($manual->getMorphClass())
+        // subject_id は string 化して渡す (extractIntOrString は ULID も int もこの形で吸収する)
+        ->and(LlmMetadataExtractor::extractIntOrString($metadata, 'subject_id'))->toBe('42');
+});
diff --git a/tests/Unit/Fixtures/PipelineSmokeSopFixtureTest.php b/tests/Unit/Fixtures/PipelineSmokeSopFixtureTest.php
new file mode 100644
index 0000000..2a97f0b
--- /dev/null
+++ b/tests/Unit/Fixtures/PipelineSmokeSopFixtureTest.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\SourceDocument;
+use App\Services\Manual\SopTextExtractor;
+use Illuminate\Support\Facades\Storage;
+
+/*
+ * pipeline smoke のダミー SOP fixture (施策 5)。
+ *
+ * 意義: 「smoke が fixture の不備で落ちる」という**紛らわしい失敗**を構造的に潰す。
+ * 判定は比率計算を再実装せず SopTextExtractor と同じ基準で behavioral に行う。
+ */
+
+it('fixture が存在し UTF-8 として妥当である', function (): void {
+    $path = base_path('resources/fixtures/pipeline-smoke-sop.txt');
+
+    expect(is_file($path))->toBeTrue();
+
+    $contents = file_get_contents($path);
+    expect($contents)->toBeString()
+        ->and(mb_check_encoding((string) $contents, 'UTF-8'))->toBeTrue()
+        ->and(strlen((string) $contents))
+        ->toBeGreaterThan(config()->integer('manual.analysis_min_text_bytes'))
+        ->toBeLessThan(config()->integer('manual.analysis_max_text_bytes'));
+});
+
+it('fixture が SopTextExtractor のゲートを通る', function (): void {
+    Storage::fake();
+    $contents = file_get_contents(base_path('resources/fixtures/pipeline-smoke-sop.txt'));
+    expect($contents)->toBeString();
+
+    $path = 'source-documents/pipeline-smoke-sop.txt';
+    Storage::put($path, (string) $contents);
+
+    $document = SourceDocument::factory()->create([
+        'file_path' => $path,
+        'original_name' => 'pipeline-smoke-sop.txt',
+        'mime' => 'text/plain',
+        'size_bytes' => strlen((string) $contents),
+    ]);
+
+    // 短すぎ / 日本語比率不足なら AnalysisFailedException が飛ぶ = ゲートを通らない
+    $extracted = app(SopTextExtractor::class)->extract($document);
+
+    expect($extracted->text)->not->toBe('')
+        ->and($extracted->byteLength)
+        ->toBeGreaterThanOrEqual(config()->integer('manual.analysis_min_text_bytes'));
+});
diff --git a/tests/Unit/Services/LlmCostReportServiceTest.php b/tests/Unit/Services/LlmCostReportServiceTest.php
new file mode 100644
index 0000000..0bc23da
--- /dev/null
+++ b/tests/Unit/Services/LlmCostReportServiceTest.php
@@ -0,0 +1,217 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\LlmCostReportData;
+use App\DataTransferObjects\LlmCostRowData;
+use App\Enums\LlmCostGroupBy;
+use App\Models\LlmCallLog;
+use App\Models\Organization;
+use App\Models\VideoManual;
+use App\Services\LlmCostReportService;
+use Carbon\CarbonImmutable;
+
+/*
+ * llm_call_logs の薄型集計 (施策 2)。**実 LLM を呼ばない** (factory でデータを作る)。
+ *
+ * 集計層が知ってよいのは llm_call_logs の列だけであり、アプリのドメイン語彙は持ち込まない
+ * (他リポジトリへそのまま移植できる状態を保つ)。
+ */
+
+function llmCostReportService(): LlmCostReportService
+{
+    return app(LlmCostReportService::class);
+}
+
+/** @return array<string, LlmCostRowData> key => 行 */
+function rowsByKey(LlmCostReportData $report): array
+{
+    $indexed = [];
+    foreach ($report->rows as $row) {
+        $indexed[$row->key] = $row;
+    }
+
+    return $indexed;
+}
+
+it('prompt_template 軸で行が分かれる', function (): void {
+    LlmCallLog::factory()->count(2)->create(['prompt_template' => 'sop-extract']);
+    LlmCallLog::factory()->create(['prompt_template' => 'scenario-generation']);
+
+    $report = llmCostReportService()->report(LlmCostGroupBy::PromptTemplate);
+    $rows = rowsByKey($report);
+
+    expect($rows)->toHaveKeys(['sop-extract', 'scenario-generation'])
+        ->and($rows['sop-extract']->calls)->toBe(2)
+        ->and($rows['scenario-generation']->calls)->toBe(1);
+});
+
+it('model 軸で行が分かれる', function (): void {
+    LlmCallLog::factory()->create(['model' => 'claude-sonnet-4-5-20250929']);
+    LlmCallLog::factory()->count(3)->create(['model' => 'claude-haiku-4-5']);
+
+    $rows = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::Model));
+
+    expect($rows['claude-sonnet-4-5-20250929']->calls)->toBe(1)
+        ->and($rows['claude-haiku-4-5']->calls)->toBe(3);
+});
+
+it('organization 軸で行が分かれ、組織なしは (none) に正規化される', function (): void {
+    $organization = Organization::factory()->create();
+    LlmCallLog::factory()->count(2)->create(['organization_id' => $organization->id]);
+    LlmCallLog::factory()->metadataMissing()->create();
+
+    $rows = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::Organization));
+
+    expect($rows[(string) $organization->id]->calls)->toBe(2)
+        ->and($rows['(none)']->calls)->toBe(1);
+});
+
+it('subject 軸のキーは subject_type と subject_id の複合になる', function (): void {
+    $manual = VideoManual::factory()->create();
+    $other = VideoManual::factory()->create();
+    LlmCallLog::factory()->count(2)->create([
+        'subject_type' => $manual->getMorphClass(),
+        'subject_id' => (string) $manual->id,
+    ]);
+    LlmCallLog::factory()->create([
+        'subject_type' => $other->getMorphClass(),
+        'subject_id' => (string) $other->id,
+    ]);
+
+    $rows = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::Subject));
+
+    expect($rows[$manual->getMorphClass().'#'.$manual->id]->calls)->toBe(2)
+        ->and($rows[$other->getMorphClass().'#'.$other->id]->calls)->toBe(1);
+});
+
+it('期間は半開区間で since ちょうどを含み until ちょうどを含まない', function (): void {
+    $since = CarbonImmutable::parse('2026-08-01 00:00:00');
+    $until = CarbonImmutable::parse('2026-08-10 00:00:00');
+
+    LlmCallLog::factory()->create(['created_at' => $since, 'prompt_template' => 'on-since']);
+    LlmCallLog::factory()->create(['created_at' => $until, 'prompt_template' => 'on-until']);
+    LlmCallLog::factory()->create([
+        'created_at' => $since->subSecond(),
+        'prompt_template' => 'before-since',
+    ]);
+
+    $rows = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::PromptTemplate, $since, $until));
+
+    expect($rows)->toHaveKey('on-since')
+        ->and($rows)->not->toHaveKey('on-until')
+        ->and($rows)->not->toHaveKey('before-since');
+});
+
+it('total_cost_usd が null の行は 0 に潰さず usdUnresolvedCalls に数える', function (): void {
+    LlmCallLog::factory()->create([
+        'prompt_template' => 'mix',
+        'total_cost_usd' => '1.000000',
+    ]);
+    LlmCallLog::factory()->create([
+        'prompt_template' => 'mix',
+        'total_cost_usd' => null,
+    ]);
+
+    $row = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::PromptTemplate))['mix'];
+
+    expect($row->calls)->toBe(2)
+        ->and($row->usdUnresolvedCalls)->toBe(1)
+        ->and((float) $row->totalCostUsd)->toBe(1.0);
+});
+
+it('total_cost_jpy が null の行は jpyUnresolvedCalls に数える', function (): void {
+    LlmCallLog::factory()->withFxSnapshot()->create(['prompt_template' => 'jpy']);
+    LlmCallLog::factory()->create(['prompt_template' => 'jpy']); // fx_snapshot なし = JPY 未解決
+
+    $row = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::PromptTemplate))['jpy'];
+
+    expect($row->jpyUnresolvedCalls)->toBe(1)
+        ->and($row->totalCostJpy)->not->toBeNull();
+});
+
+it('failure_reason を持つ行を failedCalls に数える', function (): void {
+    LlmCallLog::factory()->failed()->count(2)->create(['prompt_template' => 'fail']);
+    LlmCallLog::factory()->create(['prompt_template' => 'fail']);
+
+    $row = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::PromptTemplate))['fail'];
+
+    expect($row->calls)->toBe(3)->and($row->failedCalls)->toBe(2);
+});
+
+it('metadata_missing の行を metadataMissingCalls に数える', function (): void {
+    LlmCallLog::factory()->metadataMissing()->create(['prompt_template' => 'meta']);
+    LlmCallLog::factory()->create(['prompt_template' => 'meta']);
+
+    $row = rowsByKey(llmCostReportService()->report(LlmCostGroupBy::PromptTemplate))['meta'];
+
+    expect($row->metadataMissingCalls)->toBe(1);
+});
+
+it('afterId は境界より大きい id の行だけを対象にする', function (): void {
+    $older = LlmCallLog::factory()->create(['prompt_template' => 'older']);
+    LlmCallLog::factory()->create(['prompt_template' => 'newer']);
+
+    $report = llmCostReportService()->report(
+        LlmCostGroupBy::PromptTemplate,
+        afterId: $older->id,
+    );
+
+    expect(rowsByKey($report))->toHaveKey('newer')
+        ->and(rowsByKey($report))->not->toHaveKey('older')
+        ->and($report->afterId)->toBe($older->id);
+});
+
+it('TOTAL 行が各行の単純合計と一致する (別クエリで取っている)', function (): void {
+    LlmCallLog::factory()->count(2)->create(['prompt_template' => 'a']);
+    LlmCallLog::factory()->count(3)->create(['prompt_template' => 'b']);
+
+    $report = llmCostReportService()->report(LlmCostGroupBy::PromptTemplate);
+
+    $calls = array_sum(array_map(fn ($row): int => $row->calls, $report->rows));
+    $inputTokens = array_sum(array_map(fn ($row): int => $row->inputTokens, $report->rows));
+    $outputTokens = array_sum(array_map(fn ($row): int => $row->outputTokens, $report->rows));
+    $usd = array_sum(array_map(fn ($row): float => (float) $row->totalCostUsd, $report->rows));
+
+    expect($report->total->key)->toBe('TOTAL')
+        ->and($report->total->calls)->toBe($calls)
+        ->and($report->total->inputTokens)->toBe($inputTokens)
+        ->and($report->total->outputTokens)->toBe($outputTokens)
+        ->and(round((float) $report->total->totalCostUsd, 6))->toBe(round($usd, 6));
+});
+
+it('対象 0 件でも TOTAL は 1 行返り、整数列は 0 / 金額列は null になる', function (): void {
+    // COALESCE を整数列から外すと SUM() の NULL が int 引数へ流れて TypeError になる回帰
+    $report = llmCostReportService()->report(LlmCostGroupBy::PromptTemplate);
+
+    expect($report->rows)->toBe([])
+        ->and($report->total->calls)->toBe(0)
+        ->and($report->total->inputTokens)->toBe(0)
+        ->and($report->total->outputTokens)->toBe(0)
+        ->and($report->total->usdUnresolvedCalls)->toBe(0)
+        ->and($report->total->jpyUnresolvedCalls)->toBe(0)
+        ->and($report->total->failedCalls)->toBe(0)
+        ->and($report->total->metadataMissingCalls)->toBe(0)
+        ->and($report->total->totalCostUsd)->toBeNull()
+        ->and($report->total->totalCostJpy)->toBeNull();
+});
+
+it('toArray() が集計軸と期間と行を機械可読な形で返す', function (): void {
+    $since = CarbonImmutable::parse('2026-08-01 00:00:00');
+    $until = CarbonImmutable::parse('2026-08-11 00:00:00');
+    LlmCallLog::factory()->create(['prompt_template' => 'x', 'created_at' => $since]);
+
+    $array = llmCostReportService()->report(LlmCostGroupBy::PromptTemplate, $since, $until)->toArray();
+
+    expect($array['group_by'])->toBe('prompt_template')
+        ->and($array['since'])->toBe($since->toIso8601String())
+        ->and($array['until'])->toBe($until->toIso8601String())
+        ->and($array['after_id'])->toBeNull()
+        ->and($array['rows'][0])->toHaveKeys([
+            'key', 'calls', 'input_tokens', 'output_tokens',
+            'total_cost_usd', 'total_cost_jpy',
+            'usd_unresolved_calls', 'jpy_unresolved_calls',
+            'failed_calls', 'metadata_missing_calls',
+        ])
+        ->and($array['total']['key'])->toBe('TOTAL');
+});
diff --git a/tests/Unit/Support/BughuntDatabaseGuardTest.php b/tests/Unit/Support/BughuntDatabaseGuardTest.php
new file mode 100644
index 0000000..a8f9c21
--- /dev/null
+++ b/tests/Unit/Support/BughuntDatabaseGuardTest.php
@@ -0,0 +1,20 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\BughuntDatabaseGuard;
+
+/*
+ * bug-hunt DB 名判定の SSOT (施策 4)。判定表は DB 接続なしに固定できる純関数で持つ。
+ *
+ * regex は並列 cap (4) と**同期させない**。狭めると残留 bug_hunt_5 を bughunt DB と
+ * 認識できず「dev DB 扱い」になってしまう (= 検出漏れ)。
+ */
+
+it('bug-hunt の DB 名を検出する', function (string $name): void {
+    expect(BughuntDatabaseGuard::matches($name))->toBeTrue();
+})->with(['bug_hunt', 'bug_hunt_1', 'bug_hunt_4', 'bug_hunt_8']);
+
+it('bug-hunt でない DB 名を検出しない', function (string $name): void {
+    expect(BughuntDatabaseGuard::matches($name))->toBeFalse();
+})->with(['bug_hunt_9', 'bug_hunt_', 'bug_hunt_0', 'aicue', 'app', 'bug_hunt_1x', 'xbug_hunt', '']);
diff --git a/tests/Unit/Support/Smoke/SmokeFailureClassifierTest.php b/tests/Unit/Support/Smoke/SmokeFailureClassifierTest.php
new file mode 100644
index 0000000..e202fe2
--- /dev/null
+++ b/tests/Unit/Support/Smoke/SmokeFailureClassifierTest.php
@@ -0,0 +1,150 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\JobStatus;
+use App\Enums\Smoke\SmokeFailureClass;
+use App\Enums\Smoke\SmokeStage;
+use App\Support\Smoke\SmokeFailureClassifier;
+
+/*
+ * 失敗分類 (施策 6)。**観測のための分類であり制御フローを変えない**。
+ *
+ * ここが smoke の固有ロジックのうち DB も実 LLM も要らない部分であり、
+ * 判定表を Unit で直接固定する (コマンド本体は bughunt.local + bug-hunt DB を要求するため
+ * llm-evidence 段まで Feature テストから駆動できない)。
+ */
+
+/**
+ * 判定表を読みやすくするための名前付き引数ラッパ (既定はすべて「何も起きていない失敗」)。
+ */
+function classifySmoke(
+    SmokeStage $stage,
+    bool $stageSucceeded = false,
+    ?JobStatus $jobStatus = null,
+    bool $timedOut = false,
+    bool $hasLlmFailureRow = false,
+    bool $hasLlmSuccessRow = false,
+    bool $llmRecordingIncomplete = false,
+    bool $hasRenderErrorCode = false,
+    bool $outputReadable = true,
+    bool $ffprobeFailed = false,
+): ?SmokeFailureClass {
+    return SmokeFailureClassifier::classify(
+        $stage,
+        $stageSucceeded,
+        $jobStatus,
+        $timedOut,
+        $hasLlmFailureRow,
+        $hasLlmSuccessRow,
+        $llmRecordingIncomplete,
+        $hasRenderErrorCode,
+        $outputReadable,
+        $ffprobeFailed,
+    );
+}
+
+it('preflight の失敗は Preflight', function (): void {
+    expect(classifySmoke(SmokeStage::Preflight))->toBe(SmokeFailureClass::Preflight);
+});
+
+it('queued のまま上限到達は Wiring (worker が拾っていない)', function (): void {
+    expect(classifySmoke(SmokeStage::Analysis, jobStatus: JobStatus::Queued, timedOut: true))
+        ->toBe(SmokeFailureClass::Wiring);
+});
+
+it('running のまま上限到達は StageTimeout', function (): void {
+    expect(classifySmoke(SmokeStage::Render, jobStatus: JobStatus::Running, timedOut: true))
+        ->toBe(SmokeFailureClass::StageTimeout);
+});
+
+it('render 段の error_code は Render', function (): void {
+    expect(classifySmoke(SmokeStage::Render, hasRenderErrorCode: true))
+        ->toBe(SmokeFailureClass::Render);
+});
+
+it('artifact 段で出力を読み出せないのは Storage', function (): void {
+    expect(classifySmoke(SmokeStage::Artifact, outputReadable: false))
+        ->toBe(SmokeFailureClass::Storage);
+});
+
+it('artifact 段で読めたが ffprobe が落ちたのは Render', function (): void {
+    expect(classifySmoke(SmokeStage::Artifact, outputReadable: true, ffprobeFailed: true))
+        ->toBe(SmokeFailureClass::Render);
+});
+
+it('analysis 段の失敗で failure_reason 行があるのは Llm', function (): void {
+    expect(classifySmoke(SmokeStage::Analysis, hasLlmFailureRow: true, hasLlmSuccessRow: true))
+        ->toBe(SmokeFailureClass::Llm);
+});
+
+it('llm-evidence 段で成功行が 1 行も無いのは Llm', function (): void {
+    expect(classifySmoke(SmokeStage::LlmEvidence, hasLlmSuccessRow: false))
+        ->toBe(SmokeFailureClass::Llm);
+});
+
+it('fixture 段の失敗は Llm に漏らさず Unknown', function (): void {
+    expect(classifySmoke(SmokeStage::Fixture, hasLlmSuccessRow: false))
+        ->toBe(SmokeFailureClass::Unknown);
+});
+
+it('capture 段の失敗はリトライ痕 (failure 行) があっても Unknown', function (): void {
+    expect(classifySmoke(SmokeStage::Capture, hasLlmFailureRow: true, hasLlmSuccessRow: true))
+        ->toBe(SmokeFailureClass::Unknown);
+});
+
+it('llm-evidence 段で成功行はあるが記録が不完全なのは Wiring', function (): void {
+    expect(classifySmoke(
+        SmokeStage::LlmEvidence,
+        hasLlmSuccessRow: true,
+        llmRecordingIncomplete: true,
+    ))->toBe(SmokeFailureClass::Wiring);
+});
+
+it('記録不完全でも llm-evidence 以外の段へ Wiring を漏らさない (負のコントロール)', function (): void {
+    expect(classifySmoke(
+        SmokeStage::Analysis,
+        hasLlmSuccessRow: false,
+        llmRecordingIncomplete: true,
+    ))->toBe(SmokeFailureClass::Llm);
+});
+
+it('写像表に一致しない失敗は Unknown', function (): void {
+    expect(classifySmoke(SmokeStage::Capture))->toBe(SmokeFailureClass::Unknown);
+});
+
+it('成功した段は分類しない (リトライの failure 行があっても null)', function (): void {
+    expect(classifySmoke(
+        SmokeStage::Analysis,
+        stageSucceeded: true,
+        hasLlmFailureRow: true,
+        hasLlmSuccessRow: true,
+    ))->toBeNull();
+});
+
+// ── llmRecordingIncomplete() の導出表 (DB 不要) ────────────────────────
+
+/** @var list<string> */
+$required = ['sop-extract', 'work-decomposition', 'scenario-generation'];
+
+it('記録が完全なら false', function () use ($required): void {
+    expect(SmokeFailureClassifier::llmRecordingIncomplete($required, $required, $required))->toBeFalse();
+});
+
+it('成功行が 1 行も無いのは「記録の不備」ではない (Llm 側の疑いへ渡す)', function () use ($required): void {
+    expect(SmokeFailureClassifier::llmRecordingIncomplete($required, [], []))->toBeFalse();
+});
+
+it('必要 template の成功行が足りないのは true (帰属が正しくても記録が足りない)', function () use ($required): void {
+    $partial = ['sop-extract', 'work-decomposition'];
+
+    expect(SmokeFailureClassifier::llmRecordingIncomplete($required, $partial, $partial))->toBeTrue();
+});
+
+it('成功行はあるが帰属が一部欠けているのは true', function () use ($required): void {
+    expect(SmokeFailureClassifier::llmRecordingIncomplete($required, $required, ['sop-extract']))->toBeTrue();
+});
+
+it('全行の帰属が落ちている (withMetadata 未配線そのもの) のは true', function () use ($required): void {
+    expect(SmokeFailureClassifier::llmRecordingIncomplete($required, $required, []))->toBeTrue();
+});
```

## テスト結果 (実測)

- `composer test`: `tests=4394 passed=4392 skipped=2 failed=0` (施策 1〜10 の追加分を含む全レーン)
- `composer phpstan` (level 10): `[OK] No errors`
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed (UI 変更なしのため `pnpm test` / `pnpm build` / packages 系は省略)
- `bash scripts/bug-hunt-shard.sh self-test`: all passed ([e3] pipeline-smoke gate を追加)
- 変異検査 (実測。実装を壊してテストが赤くなることを確認後に復元):
  - 整数列の `COALESCE` を外す → `対象 0 件でも TOTAL は 1 行返り…` が赤
  - `confirmToProceed($w, true)` の第 2 引数を外す → `bughunt.local でも実行確認が出て…` が赤 (exit 2 が 1 になる)
  - `SopExtractPrompt` の `withMetadata()` を外す → 帰属 inventory が赤
  - `cmd_pipeline_smoke` の `require_orchestrator` を後ろへ移す → self-test [e3] が赤
- `php artisan dev:pipeline-smoke --check` (費用ゼロ preflight) を bug-hunt 相当の env (env -i 隔離) で実測:
  - fail-secure 4 条件を通過し、ffmpeg/ffprobe 7.1.5 を検出、SOP fixture と queue connection の検査を通過
  - bug_hunt DB が未 provision のため組織解決で QueryException → `failure_class=preflight` / exit 1 /
    detail に「DB を読めません (bug-hunt DB が未 provision / 未 migrate の可能性)」+ 元例外メッセージ
  - この QueryException の握りは**設計に無い追加**である (設計の preflight 表には DB 到達性の項目が無い)。
    理由: `--json` が例外で出力ゼロになると機械可読契約が壊れるため。妥当か判定してほしい

## 質問 (重点的に見てほしい点)

1. **費用の防壁**が構造的に迂回できないか (fail-secure 4 条件 / `confirmToProceed($w, true)` / orchestrator gate / 子 wrapper 非露出)
2. `llm-evidence` 段の**帰属照合ロジック**が設計どおりか (母集団の絞り込み / `llmRecordingIncomplete()` への入力が required に限定されているか)
3. `SmokeFailureClassifier::classify()` に渡す `$llmRecordingIncomplete` を **gate() の引数**にした判断 (段の記録前に自分自身の結果を参照できないため) が妥当か
4. 集計層に aicue のドメイン語彙が漏れていないか (還流性)
5. テストが**検証できないことを検証したふりをしていない**か
