# アプリの使命（North Star / AGENTS.md より）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告
2. PHPStan エラーの widen（型を緩めて黙らせる）・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び（factory 経由のみ）
6. prompt 文字列のコード直書き（`resources/prompts/*.yaml` に置く）
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する）

セキュリティ不変条件（抜粋）: tenant キー不信 / 子は親に属する（nested route は認可より前に 404）/ cross-org 不可 / 権限判定は laratrust_team_id 明示 / PII は CipherSweet。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ（Laravel/Svelte エコシステムの既存解を使う）。
機能の名前に立ち返れ。
仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Web アプリケーション（Laravel + Svelte）の改善に関する**概念設計レビュアー**です。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 補足コンテキスト（現行コードの要点）

- `AnalysisJobService::failJob` は job を Failed にした後、`$manual->status === Analyzing` のとき
  `cuts()->exists() ? Ready : Draft` に status を落とす（同一 tx / lockForUpdate 共有ロック規約）。
- `ScenarioService::save`（edit 画面の手動編集経路）は cuts を作成/更新し `scenario_version++`、
  status が Draft かつ cuts があれば Ready へ昇格する。
- `VideoManualController::show` は現在 `analysisJobs()->latest('id')->first()` / render/preview の
  最新 job を無条件で props 化する。`AnalysisJobData`/`RenderJobData` DTO は `job: null` を許容する
  nullable shape（TS 側 `AnalysisJobProps | null` と対）。
- `AnalysisPanel.svelte` / `RenderPanel.svelte` は失敗 job の `error` を manualStatus を見ずに表示する。
- 提案の staleness 信号は `cuts()->where('updated_at','>',$job->updated_at)->exists()`。

## 概念設計

（以下、conceptual-design.md 全文）

<!-- ここに conceptual-design.md の内容が続く -->

---
# 概念設計: manuals-stale-alert-followup

bug-hunt 回帰 run の finding **F-1-1 (High) + F-1-2 (Low) + F-1-3 (Low)** を修正する。
いずれも S3「SOP → シナリオ → 撮影」導線の manuals 画面の UX 破綻。F-1-1 は前回修正
T022（`20260713-1646-manuals-stale-alert`）の**未カバー変種**（claimed_success_no_change / H10）。

## 背景・課題

### F-1-1 (High): 失敗した job の error alert が状態と矛盾したまま残留する

- `AnalysisPanel.svelte` L293-296 の `{#if failedJob?.error}` は **manualStatus を一切考慮せず無条件表示**する。
- サーバ `VideoManualController::show` は L122-125 で **常に最新の analysis job**
  （`analysisJobs()->latest('id')->first()`）を返す。失敗 job が最新なら、その後シナリオが
  別経路で完成しても失敗 job が返り続ける。
- 具体シナリオ（既知 Q1: LLM 401 等で実解析が失敗）:
  1. 手順書アップロード → AI 解析起動 → LLM 401 で job=failed。
     `AnalysisJobService::failJob` L130-132 は **cuts が無ければ status=draft**、
     **cuts があれば status=ready** に落とす。
  2. cuts 無しで draft に落ちたケースで、ユーザーが **edit 画面で手動シナリオを完成**
     （`ScenarioService::save` が cuts を作成し scenario_version++、status=**ready**）。
  3. Show に戻ると status=「準備完了」(ready) なのに「解析に失敗しました」alert が残留し
     **状態と矛盾**。リロードしてもサーバが同じ失敗 job を返すため消えない。
- **RenderPanel.svelte でも同根**: `failedRenderJob?.error`（L260）・`failedPreviewJob`（L319-324）
  も最新失敗 job を無条件表示する。結果、Show 画面上に **解析失敗（AnalysisPanel）+ 書き出し失敗 +
  プレビュー失敗（RenderPanel）の複数 alert が時系列を無視して積み上がる**。

#### 素朴な client ガード（`status !== 'ready'`）が誤りである理由

- brief は「`failedJob?.error` 表示に `status!=='ready'` 相当のガード」も選択肢に挙げるが、
  これは**「ready から再解析して失敗した」正当なフィードバックまで隠す**。
  `failJob` は cuts 保持のまま status=ready を維持するため、`status!=='ready'` ガードでは
  「再解析したのに何も表示されない」退行になる。
- 正しい弁別軸は **staleness（陳腐化）= 失敗 job が確定した後にシナリオが変更されたか**である。
  - 「解析失敗 → 手動で cuts 完成」= cuts の更新時刻 > job 確定時刻 → **stale（隠す）**。
  - 「ready から再解析 → 失敗（既存 cuts は不変）」= cuts 更新時刻 < job 確定時刻 →
    **not stale（表示する）**。
  - render の `error_code=scenario_version_changed` 失敗も、シナリオ変更は失敗の**前**に
    起きているため not stale となり「作り直す」CTA が正しく残る。
- したがって staleness は **client が持たない情報（シナリオ最終更新時刻）** を要するので、
  **サーバ権威（VideoManualController / Service）で判定する**のが唯一正しい設計。

### F-1-2 (Low): SOP 抽出エラーが 2 ケースを同一文言に混同

- `SopTextExtractor.php` L48-51 は「抽出テキストが `analysis_min_text_bytes`(=100) 未満」でも
  `AnalysisFailedException::unextractable()`（文言「テキストを抽出できません。画像・スキャンの
  手順書は現在未対応です。」）を投げる。
- 結果、**短いが有効なテキスト SOP**（例: 50 byte の plain text 手順書）にも「画像・スキャンは
  未対応」という**誤った原因説明**が出る。ユーザーは画像でないのに画像扱いされ混乱する。

### F-1-3 (Low): タイトル必須エラーが入力後もその場で消えない

- `Manuals/Create.svelte` の submit で title 必須違反 → `form.errors.title` がセットされ
  `FormField` が invalid 枠 + エラー文言を表示。
- タイトルを入力し始めても `form.errors.title` は**次の submit までクリアされない**ため、
  入力済みなのに赤枠+エラーが残り、フォームが壊れて見える。

## 改善アイデア

| # | finding | 方針 |
|---|---------|------|
| 1 | F-1-1 | **サーバ権威の staleness 判定**。`VideoManualController::show` が返す analysis / render / preview の各 job を、「**terminal=failed かつ 確定後にシナリオが変更された（stale）**」なら **表示用に null 化**する。判定ロジックは `VideoManualService`（薄い Controller / Service 委譲）へ集約。DTO / props / TS 型の shape は不変（`job: null` は既存の nullable 契約内）。 |
| 2 | F-1-2 | `AnalysisFailedException` に **`tooShort()` ファクトリ**を追加し、`SopTextExtractor` の min-bytes 分岐で使う。「本文が短すぎます」系の文言に分離。`unextractable()` は真に抽出不能（画像/スキャン/バイナリ/破損）専用に戻す。 |
| 3 | F-1-3 | `Create.svelte` のタイトル `Input` に **`oninput` で `form.clearErrors("title")`** を配線。`FormField` の `invalid` は `error` prop 由来なので、error クリアで枠と文言が同時に消える。 |

### staleness の定義（施策1の中核）

失敗 job が **stale** = 「その job が terminal（failed）に確定した後に、対象 VideoManual の
シナリオが変更された」。判定信号は **`cuts` のうち `updated_at` が job の `updated_at` より
新しい行が存在するか**（`$manual->cuts()->where('updated_at', '>', $job->updated_at)->exists()`）。

- 「解析失敗 → 手動完成」: 完成時に cuts が作成/更新され `updated_at` が job 確定より新しい → **stale**。
- 「ready から再解析 → 失敗（cuts 不変）」: cuts の `updated_at` は job 確定より古い → **not stale**。
- render の `scenario_version_changed` 失敗: シナリオ変更は失敗の前 → **not stale**（CTA 維持）。
- take 採用/解除も cuts.adopted_take_id を触り `updated_at` を更新する（共有ロック規約）ため、
  採用状態の変更後の書き出し失敗も stale として抑制される。

**succeeded job は抑制しない**（`needsRegenerate` / playback / download が依存するため）。
抑制対象は **failed job の error alert 表示のみ**。

## 期待効果

- **使命（North Star）への貢献**: 「SOP を起点に AI がシナリオ設計 → PWA 撮影」という中核導線で、
  **完了/成功した後に残る矛盾したエラー表示を除去**し、「思考ゼロ・編集ゼロ」の体験から
  ノイズと詰まり感を取り除く。専門知識ゼロの現場作業者が状態を正しく理解できる。
- F-1-1: ready なのに「解析失敗」が出る矛盾を解消。複数パネルの stale alert 積み上がりを一掃。
  一方で「再解析失敗」「scenario_version_changed の作り直し CTA」など**現在も関係ある失敗は保持**。
- F-1-2: 短い有効テキストに「画像未対応」と誤案内せず、正しい是正行動（本文を充実させる）へ導く。
- F-1-3: フォームの自己修復（入力で赤枠が消える）で、作成フォームの信頼感を回復。

## 実装方針（概要）

### 施策1（F-1-1, サーバ）
- `VideoManualService`（既存）に **表示用 job 解決メソッド**を追加:
  - `displayAnalysisJob(VideoManual): ?AnalysisJob`
  - `displayRenderJob(VideoManual): ?RenderJob`
  - `displayPreviewJob(VideoManual): ?RenderJob`
  - 各々「最新 job を取得 → failed かつ stale なら null」を返す薄いメソッド。
  - 共通述語 `private isStaleFailure(VideoManual, Job): bool`（`status===failed &&
    cuts()->where('updated_at','>',$job->updated_at)->exists()`）。
- `VideoManualController::show` は上記メソッド経由に置換（inline クエリの latest 取得を委譲）。
  `playbackJobId`（succeeded preview のみ）は現状維持。DTO 変換（`AnalysisJobData::fromJob` /
  `RenderJobData::fromJob`）と props shape は不変。
- **client（AnalysisPanel / RenderPanel）はロジック変更不要**。stale 抑制はサーバが `job: null`
  で表現し、既存の nullable 契約で自然に「alert 非表示」になる。ライブ（同一セッションでの
  ポーリング）中に stale 状態へ遷移する経路は無い（シナリオ完成/take 採用は別画面遷移 → Inertia
  reload で新 props を取得）ため、client 側で追加ガードは不要。

### 施策2（F-1-2, サーバ）
- `AnalysisFailedException::tooShort()` を追加（文言例:「手順書の本文が短すぎます。もう少し
  詳しい手順書をアップロードしてください。」）。`SopTextExtractor` L49-51 を `tooShort()` に差し替え。

### 施策3（F-1-3, フロント）
- `Create.svelte` のタイトル `Input` に `oninput={() => form.clearErrors("title")}` を配線
  （Inertia `useForm` の `clearErrors`）。`Input` atom は `...rest` で `oninput` を透過する。

## 制約・前提

- **薄い Controller / Service 委譲**（AGENTS 実装規約）: staleness ロジックは Service に置く。
- **DTO + JsonResource / props shape 不変**（禁止事項#4 に非抵触。`response()->json()` 直書きなし。
  show は Inertia props、job DTO も既存 `toArray()` のまま）。
- **共有ロック規約（ドメイン規約1）**: 本施策は **read 経路のみ**（表示用 job 選別）で
  cuts / scenario_version / status を書かないため、ロック経路 inventory への新規登録は不要。
- **PHPStan level 10**: 追加メソッドは戻り値型明示（`?AnalysisJob` / `?RenderJob` / `bool`）、
  `Assert` で null 安全。widen / baseline 化しない。
- **テスト必須**: Feature（controller show の stale 抑制/非抑制）、Unit（SopTextExtractor の
  短文/画像で別文言）、vitest（Create タイトルクリア、AnalysisPanel/RenderPanel の job=null 非表示）。
- **DESIGN.md / Atomic**: 新規 hex / SVG / コンポーネントなし。既存 `Alert` / `Input` / `FormField`
  のみ。フロント変更は Svelte 5 runes + 既存 atom の props 透過に限る。

## スコープ外

- `cuts` / job への **新カラム追加（scenario_version スナップショット等）** は行わない
  （timestamp 比較で十分。オーバーエンジニアリング禁止）。
- ライブポーリング中の client staleness ガードは追加しない（到達経路が無い）。
- SopTextExtractor の抽出アルゴリズム自体（PDF/xlsx parser、UTF-8 正規化）は変更しない。
- 作成フォームのタイトル以外（category / document）のエラークリア挙動は変更しない
  （今回の finding 対象外。必要になれば別途）。
- `min_text_bytes` の閾値そのものの見直しはしない（文言の弁別のみ）。
