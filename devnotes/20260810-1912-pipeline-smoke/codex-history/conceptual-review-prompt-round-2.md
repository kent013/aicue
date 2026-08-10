Round 1 の指摘への対応を報告します。対応マトリクスと、概念設計の変更点を示します。
再レビューし、全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。

## 対応マトリクス

### [Warning] 2. SOP ダミーテキストのコード直書き → **対応する**
禁止事項 6 は prompt template の直書き禁止であり fixture 入力は対象外だが、指摘の趣旨
(LLM へ渡る文字列がコードに埋まる形は誤読を生む) は妥当。加えてファイル化すると
受入条件 (100 バイト以上・日本語比率 0.10 以上) を単体テストで直接固定できる実利がある。
→ `resources/fixtures/pipeline-smoke-sop.txt` へ外出し。prompt は `resources/prompts/*.yaml` のまま。
   fixture が受入条件を満たすことを検査する単体テストを施策に追加。

### [Warning] 3-a この実行分の LLM ログの切り出し → **対応する**
「実行分」の定義を明文化: `llm_call_logs.id > (開始前の MAX(id)) ∧ created_at >= 開始時刻`。
`--run-id` を metadata に載せる案は **別件へ分離** した (AnalysisPipeline に `withMetadata()` を
入れる変更であり、本件の目的 (回るかの確認) には不要。思考原則 2「今必要なものだけ作る」)。

### [Warning] 3-b worker 待ちの成功条件が抽象的 → **対応する**
段ごとに (polling 対象 / 成功 / 失敗 / 上限 / 診断出力) を表で確定させ、
失敗分類 (failure_class) の写像表を追加した。分類は観測のためであり制御フローを変えない
(既存のドメイン規約 7「決済 gateway 失敗の観測語彙」と同じ流儀)。

### [Warning] 5. bug-hunt 外での誤実行防壁 → **対応する**
コマンド本体に fail-secure 4 条件を置き、`--force` でも迂回できないものとした:
environment('bughunt.local') / bug-hunt DB 名 regex / FakeStorageGate::enabled() /
config('testing.fake_llm') === false。
DB 名 regex の二重管理を避けるため SSOT を `App\Support\BughuntDatabaseGuard` へ昇格し、
既存 `Database\Seeders\Concerns\DetectsBughuntDatabase` はそこへ委譲する
(依存の向きが app ← seeders になる。3 seeder の呼び出し側は不変)。

### [Warning] 7. コストレポート DTO の型 → **対応する**
`--group-by` は enum 化 (`App\Enums\LlmCostReportGroupBy`)。金額は `numeric-string|null` で持ち
独自 Money 抽象は作らない。JPY は `totalCostJpy` (非 null 行の合計) と `jpyUnresolvedCalls`
(null 行数) を別フィールドに分け、USD 側も `usdUnresolvedCalls` を持つ。

## 変更後の概念設計 (全文)

# 概念設計: pipeline-smoke (パイプライン通し確認の自動化 + LLM コストレポート)

> 一次入力: `devnotes/20260810-1912-pipeline-smoke/recon-brief.md`
> オーナー指示 (逸脱不可): 品質評価ではなく**プロセスが回ること**の確認 / 入力はダミーで良い /
> 置き場所は **bug-hunt レーン** / LLM は **3 段すべて実呼び出し** / **コストレポートを作る**
> (spirux・aigenba と同じ形に揃える。独自形式を発明しない)。

## 背景・課題

AI-CUE の中核バリューチェーン (SOP 投入 → AI 解析 → シナリオ → 撮影テイク → ffmpeg 合成 → mp4)
は、段ごとの自動テストは持つが **端から端まで実際に回ることを機械で確認する手段が 1 本も無い**
(`app/Console/Commands/` に該当コマンド無し。実査で確認済み)。

回帰テストが緑でも、以下は検出できない:

- 実 LLM の応答が現行 DTO のスキーマ検証 (`ExtractedSopData::fromLlmText` 等) を通らなくなった
- ジョブ投入 → worker → パイプラインの配線が切れている (queue connection・worker 定義の取り違え)
- 実 ffmpeg / ffprobe と `FfmpegVideoComposer` の引数組み立ての齟齬
- チケット 2 フェーズ (reserve→commit) が実経路で成立しない

また **LLM の費用が把握できない**。`llm_call_logs` に呼び出し単位の記録はあるが
(`provider` / `model` / トークン / `input_cost_usd` / `output_cost_usd` / `total_cost_usd` /
`pricing_snapshot` / `fx_snapshot` / `total_cost_jpy`)、**集計してレポートする側が無い**
(Filament の一覧はあるが「1 回の通し確認にいくらかかったか」「今月いくら使ったか」は出ない)。

### なぜ通常のテストレーンに置けないか

aicue:T130 でテストレーンは HTTP 出口を既定拒否にした (`StrayHttpRequestGuard`)。加えて
`StrayLlmCallGuard` が未 fake の LLM 呼び出しを fail-fast させる。実 LLM を 3 段呼ぶ通し確認を
`composer test` に入れると、この 2 つの既定と正面から衝突する。したがって**別レーンに置く**。

## 改善アイデア

### A. 通し確認 (smoke)

**artisan コマンド 1 本**を実体とし、**bug-hunt レーンの隔離環境から起動する**。

- 実体: `dev:pipeline-smoke` (`app/Console/Commands/Development/PipelineSmokeCommand.php`)
- 起動導線: `scripts/bug-hunt-shard.sh pipeline-smoke --shard N --run-id R`
  (provision 済み shard の DB / APP_URL / モード env / 実 API キーを、serve・worker と
  **同一の env 隔離**で注入して artisan を呼ぶ薄い導線)

ダミー素材:

- SOP: `resources/fixtures/pipeline-smoke-sop.txt` (**テキスト fixture をファイルで持つ**)。
  `SopTextExtractor` の受入条件 — `manual.analysis_min_text_bytes` (100) 以上・
  日本語比率 `manual.analysis_min_japanese_ratio` (0.10) 以上 — を満たす最小の日本語手順書。
  ファイルにする理由は 2 つ: (a) LLM へ渡る文字列をコードに埋めない (prompt template は
  従来どおり `resources/prompts/*.yaml` の既存経路のまま。本 fixture は prompt ではなく入力データ)、
  (b) **受入条件を単体テストで直接固定できる** (fixture 起因の smoke 失敗を未然に潰す)
- テイク動画: **ffmpeg でその場に生成**した 2 秒の mp4 (`testsrc2` + `sine`)。
  **リポジトリに動画バイナリを置かない**

各段は**実在の業務経路**を通す (専用の抜け道を作らない):

| # | 段 | 通す経路 | 期待遷移 |
|---|----|---------|---------|
| 1 | fixture | `VideoManualService::create` (SOP 同時添付) | manual = draft |
| 2 | analysis | `AnalysisJobService::trigger` → queue → `RunManualAnalysis` → `AnalysisPipeline` | job queued→running→succeeded / manual analyzing→ready / cuts ≥ 1 |
| 3 | capture | `TakeUploadService::issue` → オブジェクト書き込み → `TakeRegistrationService::register` → `CaptureTakeService::adopt` (全 cut 分) | take = ready / `cuts.adopted_take_id` 全件非 NULL |
| 4 | render | `RenderJobService::trigger` → queue → `RunManualRender` → `RenderPipeline` | job succeeded / manual rendering→published / `output_path` 非 NULL |
| 5 | artifact | 出力オブジェクトを `ffprobe` で検査 | 動画ストリーム ≥ 1 かつ duration > 0 |

### C. 失敗の切り分け (段ごとの待機と診断)

worker を待つ段は、**待機対象・成功状態・失敗状態・上限・timeout 時の診断**を先に確定させる。

| 段 | polling 対象 | 成功 | 失敗 (即時終了) | 上限 |
|---|---|---|---|---|
| analysis | `analysis_jobs.status` | `succeeded` | `failed` | 1,560s (job `$timeout`) + 120s |
| render | `render_jobs.status` | `succeeded` | `failed` | 1,500s (job `$timeout`) + 120s |

- polling 間隔 2 秒。**失敗は待たずに即座に落とす** (`failed` を観測したらその場で診断へ)
- timeout / 失敗時に必ず出す診断: `status` / `step` / `progress` / `error` (ジョブ行の列) /
  `video_manuals.status` / `cuts` 件数 / **`jobs` 表の当該 connection 残件数** /
  **この実行分の `llm_call_logs` 件数と `failure_reason`**
- 失敗分類 (`failure_class`。**観測のためであり制御フローを変えない**。
  ドメイン規約 7「決済 gateway 失敗の観測語彙」と同じ流儀):

| class | 判定 |
|---|---|
| `preflight` | 前提不成立で LLM を 1 回も呼んでいない |
| `wiring` | ジョブが `queued` のまま上限到達 (= worker 不在 / connection 取り違え) |
| `llm` | 当該実行分の `llm_call_logs` に `failure_reason` 行がある、または記録行が 0 のまま `failed` |
| `render` | `render_jobs.error_code` が非 null、または ffprobe が非 0 終了 |
| `storage` | 出力オブジェクトが不在 / 読めない |
| `unknown` | 写像表に一致が無かった (**写像表の値としては使わない**) |

### D. LLM コストレポート

**集計は 1 実装**にし、**2 つの入口**から使う:

- smoke の末尾に「**この実行分**の費用」を必ず出す (段 = `prompt_template` 単位 + 合計)。
  「この実行分」の定義は **`llm_call_logs.id > (開始前の MAX(id)) ∧ `created_at` >= 開始時刻**
  (`organization_id` / `subject_*` は付いていないため id 差分で切り出す。下記「未解決」を参照)
- 運用向けに `operations:llm-cost-report` (期間集計。`--since` / `--until` / `--group-by`)

出す語彙は **`llm_call_logs` の列名そのまま** (独自の指標を発明しない)。
通貨は **USD を主・JPY を副**。理由は「制約・前提」に書く。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」の中核はパイプラインが**実際に最後まで回る**ことに
  依存する。回らなくなったことを人の目でなく機械で検出できるようにする
- 実 LLM 起因の破損 (スキーマ変更・モデル更新・レート制限) と**配線の破損**を切り分けられる
- LLM 費用が「1 回いくら」「期間でいくら」の 2 粒度で見えるようになり、
  モデル選定・リトライ設計・プロンプト長の意思決定にデータで臨める

## 実装方針 (概要)

1. `app/Support/BughuntDatabaseGuard.php` (新規) — bug-hunt DB 名 regex の SSOT を app 側へ昇格。
   既存 `Database\Seeders\Concerns\DetectsBughuntDatabase` は**ここへ委譲**する
   (依存の向きが app ← seeders になる。3 seeder の呼び出し側は不変 = 二重管理を作らない)
2. `app/Console/Commands/Development/PipelineSmokeCommand.php` (新規)
   - **fail-secure 4 条件** (すべて満たさなければ実行しない。**`--force` でも迂回できない**):
     `environment('bughunt.local')` ∧ bug-hunt DB 名 ∧ `FakeStorageGate::enabled()` ∧
     `config('testing.fake_llm') === false`
   - `ConfirmableTrait` (Laravel 公式) で**毎回確認** (費用見積りを提示)。`--force` で skip
   - `--check`: **preflight だけ実行して終了** (LLM を 1 回も呼ばない = 費用ゼロの下見)
   - `--json`: 機械可読出力
3. `app/Services/LlmCostReportService.php` + `app/DataTransferObjects/LlmCostReport*.php` +
   `app/Enums/LlmCostReportGroupBy.php` (新規)
   - `llm_call_logs` を group by して DTO を返すだけ。**再計算も再換算もしない**
   - 金額は `numeric-string|null`。独自 Money 抽象は作らない
   - JPY は `totalCostJpy` (非 null 行の合計) と `jpyUnresolvedCalls` (null 行数) を別フィールドに
     分けて null 混在を隠さない。USD 側も `usdUnresolvedCalls` を持つ
4. `app/Console/Commands/Operations/LlmCostReportCommand.php` (新規) — 期間集計の入口
5. `resources/fixtures/pipeline-smoke-sop.txt` (新規) — ダミー SOP
6. `scripts/bug-hunt-shard.sh` に `pipeline-smoke` サブコマンド追加 (薄い導線)
7. テスト: smoke コマンドの fail-secure / preflight / 判定 / 出力、fixture の受入条件、
   コスト集計 — いずれも**実 LLM を呼ばない形で**

## 制約・前提

- **bug-hunt レーンの既定に乗る**: 既定 real-llm (親 `.env` の `ANTHROPIC_API_KEY` を
  serve/worker にだけ注入) / 既定 fake-storage / DB `bug_hunt(_N)` / 用途別 wrapper (`env -i`)
- **worker を待つ** (パイプラインを直接呼ばない): `RunManualAnalysis` / `RunManualRender` は
  `ShouldQueue` で、dispatch は aicue:T137 により**業務 tx の内側**にある。
  provision は `database-analysis` / `database-render` の worker を既に起動している。
  コマンド内で `AnalysisPipeline::run()` を直接呼ぶと**動いている worker と競合**し、
  どちらが実行したか分からなくなる (= 切り分け不能)
- **実 LLM が呼ばれた証拠は `llm_call_logs` で取る**: `Prompt::$fake` が入っていると
  vendor は実行経路を短絡し `PromptExecutionCompleted` を発火しない = **記録行が 1 行も出ない**
  (`vendor/kent013/laravel-prism-prompt/src/Prompt.php` の `executePrism()` 実読)。
  したがって「3 段それぞれに記録行がある」ことが real-llm の実証になる
  (worker 側プロセスの config を直接読めない問題も同時に解ける)
- **チケット**: 1 回の通し確認で解析 1 枚 + レンダ 3 枚 = **4 枚**消費する。
  `BughuntBillingSeeder` が有料プラン組織に 100 枚付与済み
- **時間**: 解析 job の `$timeout` = 1,560s、レンダ = 1,500s。待ちの上限はこれに余白を足す
- **fake storage への書き込み**: 実 S3 は使わない (`--real-storage` は inert トグル)。
  fake の `s3_fake` disk に実バイトを置くため、`FakeObjectStore` を参照する必要がある。
  これは `FakeClassReferenceInvariantTest` の allowlist 追加を伴う
  (既存の `PutFakeStorageObjectController` / `GetFakeStorageObjectController` と**同species**:
  `FakeStorageGate` 成立時のみ意味を持つ利用点)
- **通貨**: `total_cost_usd` は `pricing_snapshot` から決定的に決まるが、`total_cost_jpy` は
  `FxRateService` (Frankfurter API) 依存で、取得失敗時は **null で graceful degradation** する。
  よって USD を主とし、JPY は「解決できた行だけの合計」と明示する。
  **期間集計で単一レートに再換算しない** (行ごとの `fx_snapshot` が記録時レートの正本)

## スコープ外 (やらないこと)

- **品質評価の自動化はしない** (オーナー指示)。字幕の文言・語尾・捏造の有無・カット数の妥当性は
  一切判定しない。判定するのは「期待した状態遷移が起きたか」だけ
- **実 S3 配線はしない**。したがって**実 S3 の presigned PUT 契約は検証されない**
- **ブラウザ操作は含めない** (撮影 UI の実機確認は別件)
- **課金機能ではない**。利用者への請求 (llm-batch-billing 本体) は範囲外。
  本件は開発者・運営者が費用を把握するための可視化
- **価格表の自動追随はしない** (`pricing_snapshot` が記録時価格を保持済み)
- **スケジュール実行しない** (`routes/console.php` に登録しない)。実 provider へ出て費用が出る
- **Filament に画面を足さない / Excel・PDF 出力を作らない** (`report-generation-infra` は
  Excel/PDF のレポート出力基盤であり本件とは別層)

## 台帳 (lctl) の確認結果: コストレポートの先例は無い

`mcp__lctl__get_feature` で確認した結果、**LLM コストの集計レポートに相当する feature は
台帳に存在しない**。近いものは 3 件あり、いずれも別物である:

| feature id | 実体 | 本件との関係 |
|---|---|---|
| `llm-batch-billing` | spirux `app/Support/BatchBilling.php`。Batches API の 50% 割引を**課金台帳へ写す丸め規約** (成分別 ×0.5 → 小数 6 桁 HALF_UP、`batch` / `batch_abandoned` 語彙) | **レポートではない**。aicue は Batches API 未採用 (reviewing) |
| `report-generation-infra` | aigenba の Excel Reporter / spirux の dompdf PDF + `ReportTypeRegistry` | **出力ファイル基盤**であり LLM コストとは別層。aicue セルは reviewing (「レポート要件は現状薄い」) |
| `llm-model-check-cli` | aigenba `app/Console/Commands/Development/Ai/`。疎通確認・モデル一覧・golden 採点の**運用 CLI** | **CLI の作法**の先例。laravel-clau…@e04c5e2 の実装報告が「実 provider へ出て課金が発生するため自動テストからは実行しない」「手動実行のみとして分類」と明記 |

`llm-model-check-cli` の経緯には「**AI 呼び出しのコスト記録層はテンプレートにも既にあるのに、
その動作確認・採点のための運用 CLI だけが aigenba に閉じている**」と書かれている。
つまり家系で共有されているのは**記録層 (`llm_call_logs`) の語彙**までで、
集計レポートは**どのリポジトリも持っていない**。

**したがって「先例に揃える」の実行可能な解釈は次の 2 点**とし、独自形式を発明しない:

1. **語彙は記録層に揃える** — レポートの列は `llm_call_logs` の列名をそのまま使う
   (`input_tokens` / `output_tokens` / `cache_read_input_tokens` / `cache_write_input_tokens` /
   `total_cost_usd` / `total_cost_jpy`)。新しい指標を作らない
2. **CLI の作法は `llm-model-check-cli` に揃える** — `app/Console/Commands/Development/` 配置 /
   手動実行のみ (スケジュール登録しない) / 実 provider へ出るものは自動テストから実行しない

先例が無いことは設計に明記し、実装後に `append_event` で台帳へ還流できる形にしておく
(本設計フェーズでは台帳へ書き込まない)。

## 未解決 / 判断を仰ぐ点

- `AnalysisPipeline` の 3 プロンプトは `withMetadata()` を呼んでいない。したがって
  `llm_call_logs.organization_id` / `subject_*` は **NULL・`metadata_missing = true`** になる。
  帰結として**組織単位・マニュアル単位の費用集計は現状できない**。
  本件では**直さない** (思考原則 2)。代わりにレポートへ `metadata_missing` の件数を必ず出し、
  「帰属不能な行がこれだけある」ことを可視化して嘘をつかない
