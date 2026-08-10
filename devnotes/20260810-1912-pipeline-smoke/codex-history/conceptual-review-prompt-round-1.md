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
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【この設計に固有の前提 — レビュー時に踏まえること】
- オーナーが明示的に決めた事項があり、逸脱は不可である:
  (a) 目的は品質評価ではなく「プロセスが回ること」の確認。入力はダミーで良い
  (b) 置き場所は bug-hunt レーン
  (c) LLM は 3 段すべて実呼び出し
  (d) コストレポートを作る (spirux / aigenba と同じ形に揃える。独自形式を発明しない)
- 「生成物の品質 (字幕の内容・語尾・捏造の有無) を自動判定する仕組み」は
  **オーナーが明示的にスコープ外とした**。これを追加せよという指摘は受け付けられない
- 過剰に作らないこと (AGENTS.md 思考原則 2「今必要なものだけ作る」) が強く求められている

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

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

ダミー素材は**その場で作る**:

- SOP: コマンドに埋め込んだ**日本語の短い手順書テキスト**を一時 `.txt` に書き出す
  (`SopTextExtractor` の受入条件 — 100 バイト以上・日本語比率 0.10 以上 — を満たす最小の実文)
- テイク動画: **ffmpeg で生成**した 2 秒の mp4 (`testsrc2` + `sine`)。
  **リポジトリに動画バイナリを置かない**

各段は**実在の業務経路**を通す (専用の抜け道を作らない):

| # | 段 | 通す経路 | 期待遷移 |
|---|----|---------|---------|
| 1 | fixture | `VideoManualService::create` (SOP 同時添付) | manual = draft |
| 2 | analysis | `AnalysisJobService::trigger` → queue → `RunManualAnalysis` → `AnalysisPipeline` | job queued→running→succeeded / manual analyzing→ready / cuts ≥ 1 |
| 3 | capture | `TakeUploadService::issue` → オブジェクト書き込み → `TakeRegistrationService::register` → `CaptureTakeService::adopt` (全 cut 分) | take = ready / `cuts.adopted_take_id` 全件非 NULL |
| 4 | render | `RenderJobService::trigger` → queue → `RunManualRender` → `RenderPipeline` | job succeeded / manual rendering→published / `output_path` 非 NULL |
| 5 | artifact | 出力オブジェクトを `ffprobe` で検査 | 動画ストリーム ≥ 1 かつ duration > 0 |

### B. LLM コストレポート

**集計は 1 実装**にし、**2 つの入口**から使う:

- smoke の末尾に「**この実行分**の費用」を必ず出す (段 = `prompt_template` 単位 + 合計)
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

1. `app/Console/Commands/Development/PipelineSmokeCommand.php` (新規)
   - `ConfirmableTrait` (Laravel 公式) で**毎回確認**。`--force` で skip
   - `--check`: **preflight だけ実行して終了** (LLM を 1 回も呼ばない = 費用ゼロの下見)
   - `--json`: 機械可読出力
2. `app/Services/LlmCostReportService.php` + `app/DataTransferObjects/LlmCostReport*.php` (新規)
   - `llm_call_logs` を group by して DTO を返すだけ。**再計算も再換算もしない**
3. `app/Console/Commands/Operations/LlmCostReportCommand.php` (新規) — 期間集計の入口
4. `scripts/bug-hunt-shard.sh` に `pipeline-smoke` サブコマンド追加 (薄い導線)
5. テスト: smoke コマンドの preflight / 判定 / 出力と、コスト集計の単体テスト
   (**実 LLM を呼ばない形で**)

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
