【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**。実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、帰属 (organization / subject) を付ける。欠けると PHPStan level 10 が落ちる)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足: 本リポジトリの既存実装の事実 (レビュー時の前提。設計者がコードを実読して確認済み)】
- AI 解析は 3 段 (extract → decompose → generate) の `AnalysisPipeline`。各段は
  `withBoundedRetry` (config `manual.analysis_llm_max_retries` = 2) で JSON 検証失敗と
  transient 例外を再試行する。
- 3 つの prompt YAML はすべて `max_tokens: 16000` / `client_options.timeout: 360` で、
  `AnalysisTokenBudgetInvariantTest` / `AnalysisTimeBudgetInvariantTest` が値を pin している。
- 時間 budget: D = STAGE_COUNT(3) × C(360) = 1080s、job timeout T = D + C + 30 + 90 = 1560s <
  queue retry_after 1680s < チケット予約 TTL 1800s <= stale 閾値 1800s。
- `analysis_jobs.result_json` は decompose 段で書かれる write-only 監査スナップショット (読み出し実装は 0 件)。
- `analysis_jobs.scenario_version_at_terminal` は**失敗時のみ**書かれる (成功は NULL)。
- `ScenarioBookendBuilder` が導入/総括カットを普通の top-level step として前後に付与する
  (DB 上に識別子は無い)。
- 詳細画面 (`Manuals/Show`) の analysis props は `{job, hasDocument}` のみ。
  ポーリング応答 `AnalysisJobData` は `{id, status, step, progress, error, manual_status}`。
- チケット消費は `config('manual.analysis_ticket_cost') = 1` (COST_ANALYSIS)。

---

## 概念設計

（以下、devnotes/20260817-0005-scenario-validation-report/conceptual-design.md の全文）

# 概念設計: scenario-validation-report (シナリオ生成のバリデーション結果表示)

## 背景・課題

doc/03 §3.4 は、改良版 (v2) プロンプトの構成として、シナリオ本体の手前に**バリデーション結果**
(マニュアルとして有効か / 文末解析・語彙解析・構造解析 / 作業数 / 仮タイトル一覧 /
ノード数 (手順数・急所数) / 分割要否) を出す、と述べている。
また §3.4 の示唆は「手順書は画像/PDF 由来のため OCR 誤読が避けられない。構造化とシナリオ生成は
AI に任せつつ、**PC 側の編集機能で人が最終確認・修正する**運用が前提」と述べている。

現行実装 (2026-08-17 時点、コードを実読して確認):

- 3 段パイプライン (extract → decompose → generate) は `AnalysisPipeline` で稼働している。
- 生成物は `Manuals/Edit` のシナリオ編集画面で人が見られる。
- しかし利用者に届く情報は `AnalysisJobData` の 5 項目
  (`id` / `status` / `step` / `progress` / `error` / `manual_status`) **だけ**である。
  = **「解析が終わったか失敗したか」しか分からず、生成物が妥当かを人が判断する材料が無い。**

「思考ゼロ・編集ゼロ」を掲げる本アプリで「編集ゼロ」を最後に担保するのは人の最終確認である。
確認すべき箇所が示されないと、利用者は 20〜40 カットのシナリオを頭から読み直すしかない。

## ブリーフ前提の検証結果 (現行コードとの突き合わせ)

**依頼ブリーフの前提を鵜呑みにせず、対象コードを実読して 5 点を検証した。うち 3 点は訂正が要る。**

| # | ブリーフ / doc の前提 | 実読結果 | 判定 |
|---|---|---|---|
| 1 | 生成物の妥当性を人に伝える指標が何も無い | `AnalysisJobData` は status/step/progress/error のみ。`Manuals/Show` の `analysis` props も job + hasDocument のみ | **正** |
| 2 | `analysis_jobs.result_json` は中間成果 (作業分解表) を持つ | decompose 段で `writeProgress(['result_json' => $decomposition->toArray()])`。読み出す実装は 0 件 (write-only 監査スナップショット) | **正** |
| 3 | ノード数 (手順数/急所数) は cuts から数えられる | 数えられるが **doc の言う「ノード数」とは一致しない**。`ScenarioBookendBuilder::wrap()` が導入/総括カットを**通常の top-level step として**前後に足しており、DB 上に識別子が無い (CutType は step/point の 2 値のみ) | **要訂正** |
| 4 | (staleness 判定に使えそうな) `scenario_version_at_terminal` | **失敗時にしか書かれない** (`AnalysisJobService::failLockedJob` のみ)。成功ジョブは NULL | **要訂正** |
| 5 | 新しい LLM 段を増やすか、既存出力に足すかを比較せよ | 段を増やすと `Tests\Support\AnalysisBudget` の時間 budget 連鎖が破綻する (下記 §段を増やさない根拠) | **要訂正 (段追加は不可に近い)** |

### 訂正 3: 「ノード数」は再現できない

導入/総括カットは `CutType::Step` / `ShotType::Hiki` の普通の step として materialize される。
識別子を持たないので、cuts から「LLM が作った手順数」を復元することはできない。
→ 本設計では doc の「ノード数」を**そのまま再現しない**。代わりに
**「現在のシナリオのカット構成 (手順カット数 / 急所カット数 / 合計)」**を出す。
これは編集後も常に真であり、撮影ナビが実際に案内するカット数と一致する
(識別用のカラムを新設して doc の語に合わせにいくのは、今必要のない機構である = 思考原則 2)。

### 訂正 4: 「解析時の手順書」との対応付け

成功ジョブには scenario_version のスナップショットが無い。
一方 **`analysis_jobs.source_document_id`** は解析対象 SOP を指しており、
`VideoManual::sourceDocuments()->latest('id')` (= 次回解析の対象) と比較すれば
「この所見は、いまアップロードされている手順書に対するものか」を決定的に判定できる。
LLM 側の所見 (有効性 / 作業数 / 仮タイトル / 分割要否) は**手順書に対する判断**であって
カットに対する判断ではないため、**scenario_version ではなく source_document_id で鮮度を見るのが正しい**。
→ `scenario_version_at_terminal` を成功経路へ広げる変更は**不要** (既存カラムの意味を広げない)。

### 訂正 5 / 段を増やさない根拠 (時間 budget の連鎖)

`Tests\Support\AnalysisBudget` が固定している連鎖:

```
C (client timeout)   = 360s
STAGE_COUNT          = 3
D (deadline)         = STAGE_COUNT * C = 1,080s   (config manual.analysis_deadline_seconds)
T (job timeout)      = D + C + M1(30) + S(90) = 1,560s
queue retry_after    = 1,680s          T < retry_after
予約 TTL             = 1,800s          retry_after < TTL   (「変更しない」と表に明記)
stale 閾値           = 1,800s          TTL <= stale
```

4 段目を足すと D=1,440s → T>=1,920s となり **retry_after (1,680s) と予約 TTL (1,800s) を
両方追い越す**。すなわち「LLM を 1 段足す」は課金台帳の予約 TTL 変更まで波及する。
加えて LLM 呼び出しが 1 回増える (解析 1 回あたりの LLM 費用 +33%)。
**判定表示のために課金基盤の時間定数を動かすのは費用対効果が成立しない → 段は増やさない。**

## 改善アイデア

**「LLM にしか判断できないもの」と「PHP で決定的に算出できるもの」を分離し、
前者は既存 LLM 段の出力へ小さく相乗りさせ、後者は表示のたびに現在の cuts から算出する。**

### (A) LLM に出させるもの — 手順書に対する判断だけ

**既存の work-decomposition (2 段目) の出力へ `validation` オブジェクトを 1 つ足す。**

| 項目 | 型 | 根拠 |
|---|---|---|
| `verdict` | `"valid" \| "needs_review" \| "invalid"` | 「マニュアルとして有効か」。LLM にしか判断できない |
| `reason` | string (200 字以内) | 判定の理由 1 文。人が読む |
| `works` | list of `{title: string(60字以内)}` (1〜10 件) | 「仮タイトル一覧」。**作業数は PHP が count() で出す** |
| `split_recommended` | bool | 「分割要否」。作業数から機械的には決まらない (3 作業でも 1 マニュアルが妥当なことがある) |

**なぜ 3 段目 (scenario-generation) ではなく 2 段目 (work-decomposition) か**:

1. **意味的にそこが正しい**。「有効か / 作業数 / 仮タイトル / 分割要否」は**手順書そのものへの
   判断**であって、生成済みカットへの判断ではない。doc §3.4 の v2 プロンプトは
   2 プロンプト構成だったが、本実装は 3 段に分かれており、判断材料 (抽出済み SOP) を
   持っているのは 2 段目である。
2. **出力 token に余裕があるのは 2 段目**。両段とも `max_tokens: 16000` で固定
   (`AnalysisTokenBudgetInvariantTest` が 3 YAML すべてを 16,000 に pin)。
   3 段目は 100 手順 × 8 フィールドを出すため 16,000 に張り付きうるが、
   2 段目は `{no, action, points[]}` だけで実測的に数千 token に収まる。
   判定ブロックを 3 段目に足すと**カット出力を圧迫して truncate → JSON 不正 → リトライ**の
   リスクを増やす。

**doc §3.4 との差異は意図的**であり、詳細設計とコードコメントに明記する。

### (B) PHP で決定的に算出するもの — 現在のシナリオへの検査

表示のたびに `cuts` から算出する (保存しない = 編集後も常に真)。

**構成 (構造解析に相当)**: 手順カット数 / 急所カット数 / 合計。

**規約検査 (文末解析・語彙解析に相当)** — プロンプト規約 (doc/03 §3.3) の機械化可能な部分だけ:

| code | 検査 | 規約の出所 |
|---|---|---|
| `narration_missing` | ナレーションが空 | ナレーションは全カットに要る |
| `narration_not_polite` | 末尾 (句点を除く) が「ます」で終わらない | 「語尾は〜します に統一」 |
| `narration_directive` | 「ください」を含む | 「指示的な〜してくださいは禁止」 |
| `subtitle_primary_sentence` | 字幕①が「。」または「ます」を含む | 「字幕①は固有名詞・数値のみ」= 文にしない |
| `subtitle_secondary_missing` | 字幕②が空 | 「音声なしで 100% 伝わる情報量」 |

各 code につき **件数**と**該当カットの位置** (手順 N / 急所 N-M。編集画面の表記と同じ) を返す。

**「急所が 0 件の手順」は入れない**: 導入/総括カットは構造上必ず急所 0 件であり、
識別子が無い以上**全マニュアルで恒常的に 2 件の偽陽性**になる (訂正 3 の帰結)。
偽陽性を出す検査は「読み飛ばす習慣」を作るので、入れない方が価値が高い。

**閾値は置かない**: 「字幕①は 40 字以内」等の数値閾値は根拠となる実データが無い。
仕組みが機能しているか分からない段階で閾値を作らない (思考原則)。

### (C) 出力の置き場所

`analysis_jobs` に **`validation_json` (nullable json) を新設**する。

- `result_json` に相乗りさせない理由: `result_json` は「write-only 監査スナップショット」として
  設計・文書化されており、そこを表示の入力にすると `WorkDecompositionData::toArray()` の
  すべての変更が UI 契約になる。表示用の契約は独立したカラムで持ち、独立に nullable にする。
- 書き込みは decompose 段の**同じ条件付き UPDATE** (`writeProgress`、`where status=running`) で行う。
  書き込み経路は 1 つだけ増える。
- 読み出しは**必ず DTO の `fromStorage()` で組み立て直す**。壊れていれば `null` を返して
  「所見なし」として描画する (JSON カラムの中身を信用しない。詳細画面が 500 にならないことを優先)。
  LLM 応答の検証 (厳格・不正ならリトライ) とは別レイヤであることを明記する。

### (D) 画面表示

**`Manuals/Show`** に `features/manual/ScenarioReportPanel.svelte` を新設し、AnalysisPanel の直下に置く。
`AnalysisPanel` (起動・ポーリング) には手を入れない (責務が違う)。

- **LLM の所見** (最新の **succeeded** な解析ジョブから。無ければ非表示)
  判定バッジ + 理由 + 仮タイトル一覧 (作業数) + 分割推奨。
  解析時の手順書が最新でない場合は「この所見は解析時の手順書に対するものです」と添える。
- **PHP の検査結果** (cuts があれば常に表示)
  カット構成 + 指摘の一覧 (code ごとに件数と該当位置)。

**「有効でない」と出たときの行き先** (詰みを作らない):

| 判定 | 添える導線 |
|---|---|
| `valid` | 「シナリオを編集して確認する」(編集画面) / 撮影導線は既存 |
| `needs_review` | 同上 + 指摘位置の一覧 (どのカットを見ればよいか) |
| `invalid` | 「手順書を差し替える」(同一画面の SOP アップロード) + 「AI 解析をやり直す」(既存ボタン) + 編集画面 |
| `split_recommended` | 「作業ごとにマニュアルを分ける」= 既存の**複製**導線 + 仮タイトル一覧を提示 |

いずれの導線も**既存の画面要素**であり、新しい route も新しい操作も作らない。

**判定を制御フローに使わない**: `invalid` でも保存・撮影・レンダを一切止めない。
ボタンを disabled にもしない (禁止事項 8)。表示だけである。

## 期待効果

- **使命への貢献**: 「編集ゼロ」の最後の砦である人の最終確認 (doc §3.4 の運用前提) に、
  初めて**どこを見ればよいか**という材料が付く。OCR 誤読が語尾・字幕の規約違反として
  表面化するケースを機械的に拾える。
- 「作業数 3 / 分割推奨」を提示することで、1 マニュアル 1 作業という設計意図
  (撮影ナビの粒度) を利用者が自分で選べるようになる。
- **費用増はゼロ**: LLM 呼び出し回数は 3 回のまま。増えるのは 2 段目の出力
  数百 token (最悪ケースで 10 タイトル × 60 字 + 理由 200 字 ≒ 700 token、出力予約 16,000 の 5% 未満) と、
  プロンプト本文 +250 token 程度 (固定プロンプト余裕 4,000 token の内側)。
- **チケット消費は据え置き** (`COST_ANALYSIS = 1`)。新しい LLM 呼び出しが無く、
  増分は 1 段の出力予約の 5% 未満であるため、価格を動かす根拠が無い。

## 実装方針（概要）

1. `resources/prompts/work-decomposition.yaml` — 出力スキーマに `validation` を追加 (max_tokens / timeout は不変)。
2. `App\DataTransferObjects\Manual\Analysis\SopValidationData` — LLM 出力の `validation` を厳格検証する DTO
   (`fromLlmText()`)。不正は `LlmJson::schemaViolation()` = `LlmOutputInvalidException` → 既存の有界リトライ。
   保存済み JSON からの復元は `fromStorage(?array): ?self` (壊れていたら null)。
3. `App\Enums\Manual\ScenarioVerdict` (valid / needs_review / invalid)。TS union と値集合同期テストに登録。
4. `AnalysisPipeline::runDecomposeStep` — 同じ応答テキストから `SopValidationData` も組み立て、
   `writeProgress` に `validation_json` を足す (LLM 呼び出しは増えない)。
   **`WorkDecompositionData::toJsonString()` (3 段目への入力) には validation を混ぜない**
   (次段の入力 token を無駄にしない / 生成器を惑わせない)。
5. `App\Services\Manual\ScenarioRuleCheck` + `ScenarioRuleCheckData` / `ScenarioRuleFindingData` /
   `App\Enums\Manual\ScenarioRuleCode` — cuts から決定的に算出。DB 書き込みなし。
6. `VideoManualController::show` — `analysis.report` props を追加 (最新 succeeded job の所見 + 規約検査)。
7. `resources/js/components/features/manual/ScenarioReportPanel.svelte` + `types/manual.ts` の型追加。
8. マイグレーション `analysis_jobs.validation_json` (nullable json) + Factory 既定 null。
9. `CannedPromptResponses::workDecompositionCanned()` に validation を追加 (fake が DTO を通る)。
10. テスト: DTO 検証 (正常/不正/リトライ)、パイプライン保存、規約検査の各 code、props、
    UI (Show に出る / 判定なしで落ちない)、enum⇔TS 同期。

## 制約・前提

- LLM 呼び出しは `app/Prompts/` factory → `PromptDefense` → `GuardedPrompt` の 1 本道のみ。
  本設計は**既存 factory (`WorkDecompositionPrompt`) の出力スキーマを変えるだけ**で、
  新しい呼び出し経路も untrusted 入力も増やさない (`LlmCallContextData` の帰属は既存のまま)。
- prompt 文字列は `resources/prompts/work-decomposition.yaml` のみ。
- `AnalysisTokenBudgetInvariantTest` / `AnalysisTimeBudgetInvariantTest` の値は**一切変えない**
  (max_tokens 16,000 / timeout 360 / D=3C / STAGE_COUNT=3 のまま)。
- `response()->json()` は使わない。表示は Inertia props (DTO の `toArray()`)。
- ポーリング応答 (`AnalysisJobData` / `AnalysisJobResource`) は**変更しない**。
  succeeded で既存の `router.reload()` が走り props が更新されるため、所見は再描画で届く。
- 判定は制御フローに使わない (保存・撮影・レンダ・ボタン活性を一切変えない)。
- Show の追加クエリは 2 本 (最新 succeeded job / cuts)。cut 件数に依存しない定数本。

## スコープ外

- **シナリオ編集画面へのインライン表示** (行ごとの指摘バッジ)。保存応答 (`ScenarioResource`) の
  shape 変更と、保存のたびの再計算が必要になり波及が大きい。まず Show で「どこを見るか」を
  示し、編集画面は行き先として既存のまま使う。効果が確認できてから次段で扱う。
- 判定結果の通知メール本文への反映 (`ManualAnalyzedNotification`)。
- 導入/総括カットの識別 (カラム新設) と doc の「ノード数」完全再現。
- 文体検査の閾値化・語彙辞書化 (実データが出るまで固定規則のみ)。
- 過去ジョブの所見の履歴表示 (最新 succeeded の 1 件のみ)。
- `sop-extract` (1 段目) の OCR 信頼度の可視化。
