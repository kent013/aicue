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

#### `validation` の必須度 — 厳格必須 (不正なら有界リトライ、最終的に解析失敗)

「補助情報だから不正でも null にして通す」案を検討したうえで、**厳格必須**を選ぶ:

1. **品質上の選択**: 所見が欠けた解析を「成功」として扱わない。この機能の名前
   (シナリオ生成のバリデーション結果表示) が示す成果物は所見そのものであり、
   欠けたまま succeeded にするのは名前に反する。
2. 既存 3 段すべてが「DTO 検証 → 不正なら `LlmOutputInvalidException` → 有界リトライ」の
   1 本道であり、ここだけ別扱いにすると「一部だけ不正な応答」という概念が増える。
3. スキーマが小さい (4 フィールド / enum 3 値 / タイトル 10 件上限)。同じモデルは 3 段目で
   これよりはるかに大きいスキーマを満たしている。リトライは計 3 試行ある。
4. 寛容にすると「所見が出ないまま誰も気づかない」= 機能が静かに死ぬ。

**この判断のコストとリスク** (「無料だから安全」とは書かない):
report のスキーマ違反だけを理由に解析全体が失敗しうる。そのとき失われるのは
**利用者の待ち時間** と **運営側の provider 実費 (最大 3 試行分)** であり、
**利用者のチケット予約は release される** (`failLockedJob`) ので利用者のチケットは減らない。

**観測条件 (この判断を継続してよいかを後から評価するため)**:
`validation` のスキーマ違反は、`steps` 側の違反と**識別できる形で記録する**。
メッセージ文字列に頼らず、リトライログに**固定キーの構造化 context**を載せる:
`stage` (= `work_decomposition`) / `failure_category` (= `schema_violation`) /
`failure_path` (= `validation.works.2.title` のような違反パス) / `attempt`。
**可変の LLM 応答本文は含めない**。
評価指標は「validation 起因の再試行数 / 最終失敗数 / 2 段目の出力 token 分布」の 3 つ。
**閾値は今は置かない** (分布を見てから判断する)。

### (B) PHP で決定的に算出するもの — 現在のシナリオへの検査

表示のたびに `cuts` から算出する (保存しない = 編集後も常に真)。

**構成 (構造解析に相当)**: 手順カット数 / 急所カット数 / 合計。

**規約検査 (文末解析・語彙解析に相当)** — プロンプト規約 (doc/03 §3.3) の機械化可能な部分だけ:

| code | 検査 (規則は下記で明文化) | 規約の出所 |
|---|---|---|
| `narration_missing` | ナレーションが空 | ナレーションは全カットに要る |
| `narration_not_polite` | 丁寧体で終わっていない | 「語尾は〜します に統一」 |
| `narration_directive` | 「ください」を含む | 「指示的な〜してくださいは禁止」 |
| `subtitle_primary_sentence` | 字幕①が文になっている | 「字幕①は固有名詞・数値のみ」= 文にしない |
| `subtitle_secondary_missing` | 字幕②が空 | 「音声なしで 100% 伝わる情報量」 |

**規則の明文化** (偽陽性を出す検査は読み飛ばす習慣を作るので、境界を先に決める):

- `narration_not_polite`: 末尾の空白と句点 (`。` `.` `!` `！`) を除いた文字列が
  **{ます, ません, ました, ましょう, です, でした}** のいずれでも終わらないとき。
  「〜してはいけません」「〜が必要です」を偽陽性にしないための集合であり、
  体言止め・「〜する」「〜せよ」を拾うのが目的。
- `narration_directive`: 「ください」を含むとき。**`narration_not_polite` とは独立に数える**
  (「〜してください」は両方に載りうる。パネルは code ごとの件数を出すので二重計上にはならない)。
- `subtitle_primary_sentence`: 字幕①が `。` を含む、または「ます」「です」を含むとき。
- 閾値 (文字数上限等) は 1 つも置かない (根拠となる実データが無い段階で値を作らない)。

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
- 読み出しは**必ず DTO の `fromStorage(?array): ?self` で組み立て直す**。壊れていれば `null` を返して
  「所見なし」として描画する (JSON カラムの中身を信用しない。詳細画面が 500 にならないことを優先)。
  LLM 応答の検証 (厳格・不正ならリトライ) とは別レイヤであることを明記する。
- **null に畳むだけにせず必ず記録する**: 復元失敗時は固定文言の `Log::warning`
  (「解析ジョブの妥当性所見の復元に失敗しました」) を、**job id + 失敗分類 (どのキーが不正か)** の
  構造化 context 付きで出す。**保存 JSON 本文はログに載せない** (LLM 由来の可変文字列)。
  無音の縮退は保存契約の破損を長期間隠すため、テストで「壊れた保存値でも Show が 200」と
  「警告が記録される」の両方を固定する。

### (D) 画面表示

**`Manuals/Show`** に `features/manual/ScenarioReportPanel.svelte` を新設し、AnalysisPanel の直下に置く。
`AnalysisPanel` (起動・ポーリング) には手を入れない (責務が違う)。

- **LLM の所見** (最新の **succeeded** な解析ジョブから。無ければ非表示)
  判定バッジ + 理由 + 仮タイトル一覧 (作業数) + 分割推奨。
  取得は **`$manual->analysisJobs()` relation 起点**に固定する
  (クラス起点の主キー取得を作らない = cross-org 不可の不変条件と `DirectFetchInventory` に触れない)。
  **鮮度の表示仕様**: `is_current_document = (job.source_document_id !== null &&
  job.source_document_id === 最新 SOP の id)`。SOP が差し替え済み / 削除済み (FK は nullOnDelete で
  NULL になる) / 1 件も無い、のいずれでも false になり、false のときだけ
  「この所見は解析時の手順書に対するものです」の注記と再解析導線を添える (所見自体は隠さない)。
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
- **費用**: 3 つを混同せずに書く (利用者のチケット / provider 実費 / 時間)。
  1. **新しい必須段・新しい実行経路は追加しない**。必須段数は 3 段のまま、
     有界リトライの上限 (`analysis_llm_max_retries = 2`) も時間 budget も変えない。
     通常成功時の呼び出し回数は 3 回のまま。
     **ただし `validation` のスキーマ違反により、従来なら起きなかった 2 段目の再試行が
     最大 2 回発生しうる**。その分の provider 実費は増える (下記 3 と同じ話)。
  2. **利用者のチケット消費は `COST_ANALYSIS = 1` のまま**とする。必須段数とリトライ上限を
     変更しておらず、validation 起因の実費増分は実装後に観測するため、
     現時点でチケット価格を変更する根拠が無い。
  3. **provider 実費は微増する** (運営側の負担)。増分は
     **出力側が「日本語で最大およそ 800 字 (仮タイトル 10 件 × 60 字 + 理由 200 字) と
     その JSON 構造」**、入力側が**プロンプト本文の追記 (日本語で約 400 字)**。
     **token 数には換算しない** — 日本語の文字数と token 数は一致せず、現時点で
     実測 (tokenizer / `llm_call_logs` の計測) を持っていないため、比率 (「1% 未満」等) は主張しない。
     言えるのは「2 段目の既存出力の余裕を大きく損なわない見込み」までである。
     実装後に `llm_call_logs` で 2 段目の出力 token 分布を観測して評価する。

## 実装方針（概要）

1. `resources/prompts/work-decomposition.yaml` — 出力スキーマに `validation` を追加 (max_tokens / timeout は不変)。
2. **応答 DTO の一本化** (Codex R1 指摘): `WorkDecompositionResponseData::fromLlmText(string)` が
   **decode を 1 回だけ**行い、`WorkDecompositionData::fromPayload(array)` と
   `SopValidationData::fromPayload(array)` を組み立てる。
   `WorkDecompositionData::fromLlmText()` は**削除**する (後方互換の並走を残さない)。
   不正は `LlmJson::schemaViolation()` = `LlmOutputInvalidException` → 既存の有界リトライ。
3. `App\Enums\Manual\ScenarioVerdict` (valid / needs_review / invalid)。TS union と値集合同期テストに登録。
4. `AnalysisPipeline::runDecomposeStep` — 応答 DTO から `validation_json` も `writeProgress` に足す
   (LLM 呼び出しは増えない)。**`WorkDecompositionData::toJsonString()` (3 段目への入力) には
   validation を混ぜない** (次段の入力 token を無駄にしない / 生成器を惑わせない)。
5. `App\Services\Manual\ScenarioRuleCheck` — cuts から決定的に算出。DB 書き込みなし。
   cuts は `orderBy('sort_order')` の **1 クエリで全件取得**し、`parent_cut_id` で 1 パス groupBy して
   位置 (手順 N / 急所 N-M) を組む (`ScenarioDocumentData::fromManual` と同じ手口。N+1 を作らない)。
6. **DTO 階層** (props は必ずこれ経由。Controller で生 array を組まない):
   - `ScenarioReportData { verdict: ScenarioVerdictData|null, counts: ScenarioCountsData,
     findings: list<ScenarioRuleFindingData> }`
   - `ScenarioVerdictData { verdict: ScenarioVerdict, reason: string, works: list<string>,
     workCount: int, splitRecommended: bool, isCurrentDocument: bool }` (workCount は PHP が count で導出)
   - `ScenarioCountsData { steps: int, points: int, total: int }`
   - `ScenarioRuleFindingData { code: ScenarioRuleCode, count: int,
     positions: list<ScenarioCutPositionData{step:int, point:?int}> }` (先頭 5 件まで)
7. `VideoManualController::show` — `analysis.report` props (`ScenarioReportData::toArray()` または null)。
8. `resources/js/components/features/manual/ScenarioReportPanel.svelte` + `types/manual.ts` の型・ラベル追加。
9. マイグレーション `analysis_jobs.validation_json` (nullable json) + Factory 既定 null。
10. `CannedPromptResponses::workDecompositionCanned()` に validation を追加 (fake が DTO を通る)。
11. テスト: DTO 検証 (正常/不正/リトライ)、パイプライン保存、壊れた保存値での復元 (null + 警告)、
    規約検査の各 code、**クエリ数がカット件数に依存しないこと**、props、
    UI (Show に出る / 所見なしで落ちない)、enum⇔TS 同期。

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
- Show の追加クエリは 3 本 (最新 succeeded job / 最新 SOP の id / cuts 全件)。
  **cut 件数に依存しない**ことを DB::listen でクエリ数を数えるテストで固定する。

## リスク

| リスク | 影響 | 緩和 |
|---|---|---|
| `validation` のスキーマ違反だけで解析が失敗しうる | **利用者の待ち時間 + 運営側の再試行費用 (最大 3 試行分)**。利用者のチケット予約は release される | スキーマを 4 フィールドに絞る / 有界リトライ 3 試行 / 失敗は画面に出る / 違反パスを識別可能に記録して観測する |
| 2 段目の出力が増える (日本語で最大 800 字程度 + JSON 構造) | 2 段目は `{no, action, points[]}` だけで出力予約 16,000 に対して余裕がある段。**比率は実測前なので主張しない** | 3 段目 (予約に張り付きうる段) には足さない / 実装後に `llm_call_logs` で分布を観測 |
| 規約検査の偽陽性 | 「読み飛ばす習慣」を作り検査の価値を失う | 許容終端集合を広めに定義 / 導入・総括カットが必ず該当する検査 (急所 0 件) を入れない / 閾値を置かない |
| 所見が古い手順書に対するものになる | 誤った判断材料 | `is_current_document` で注記 + 再解析導線 |
| 保存 JSON の破損が無音化する | 機能が静かに死ぬ | 復元失敗を `Log::warning` で記録し、テストで固定 |

## スコープ外

- **シナリオ編集画面へのインライン表示** (行ごとの指摘バッジ)。保存応答 (`ScenarioResource`) の
  shape 変更と、保存のたびの再計算が必要になり波及が大きい。まず Show で「どこを見るか」を
  示し、編集画面は行き先として既存のまま使う。効果が確認できてから次段で扱う。
- 判定結果の通知メール本文への反映 (`ManualAnalyzedNotification`)。
- 導入/総括カットの識別 (カラム新設) と doc の「ノード数」完全再現。
- 文体検査の閾値化・語彙辞書化 (実データが出るまで固定規則のみ)。
- 過去ジョブの所見の履歴表示 (最新 succeeded の 1 件のみ)。
- `sop-extract` (1 段目) の OCR 信頼度の可視化。
