【アプリの使命（North Star）— AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【セキュリティ不変条件（アプリ都合で緩めない）— AGENTS.md より】

1. tenant キー不信: ownership/actor/tenant キーを payload から受け取らない
2. 子は親に属する: nested route の不整合は認可より前に 404（NestedRouteIdorDefenseTest inventory 登録必須）
3. cross-org 不可: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. untrusted 文字列は UserInput 型経由でのみ prompt に入れる
5. 権限判定は常に laratrust_team_id を明示(strict_check=true)
6. PII(email/name)は CipherSweet。検索は whereBlind()
7. 課金の冪等性: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. 外部 URL 取得は SSRF 検査経由

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

【あなたの役割】
あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【前提資料（必要ならファイル読み込み可）】
- 確定仕様: /workspace/doc/10_実装仕様.md（特に §10.1 データモデル、§10.2 JobStatus、§10.4 プロンプト 3 種、§10.5 COST_ANALYSIS=1、§10.7 v1 スコープ、§10.8-1 チケット 2 フェーズ、§10.8-8 analyze 冪等。**§10.8 は §10.1〜§10.7 に優先**）
- パイプライン仕様: /workspace/doc/03_AI解析とシナリオ生成.md
- 既存見本: /workspace/app/Services/Manual/ScenarioService.php（共有ロック規約の準拠実装）、/workspace/app/Services/Billing/TicketLedgerService.php（reserve→commit/release。内部変更禁止）、/workspace/app/Prompts/ExampleSummaryPrompt.php + /workspace/resources/prompts/example-summary.yaml（LLM 呼び出し規約）

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

以下は AI-CUE の新フィーチャ「AI 解析（SOP→作業分解→シナリオ生成→Cut materialize）」の概念設計です。レビューしてください。

（/workspace/devnotes/20260711-0137-ai-analysis/conceptual-design.md と同一内容）

# 概念設計: ai-analysis（AI 解析: SOP→作業分解→シナリオ生成→Cut materialize）

作成: 2026-07-11 / 対象アプリ: AI-CUE (/workspace)

## 背景・課題

AI-CUE の使命（North Star）は「現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを
設計した動画シナリオを生成する」こと。本フィーチャはその**中核パイプライン**を実装する:

```
SourceDocument(SOP: PDF/Excel テキスト)
  → extract   (テキスト抽出 + sop-extract プロンプトで統一 JSON 化)
  → decompose (work-decomposition プロンプトで作業分解表)
  → generate  (scenario-generation プロンプトでカット群)
  → materialize (Cut 群を VideoManual に保存、status: analyzing→ready)
```

現状:
- `source_documents` / `cuts` / `video_manuals` テーブル・Model はフェーズ1で先取り済み
  （SourceDocument は Tier B: schema のみ、アップロード経路なし）
- シナリオ手動編集（`ScenarioService::save()`・T002）実装済み。AI 生成結果は同じ
  Cut データ構造に materialize し、以後は手動編集フローに合流する
- LLM 基盤（kent013/laravel-prism-prompt: `app/Prompts` factory + `resources/prompts/*.yaml` +
  PromptGuardrailTest ほか Architecture テスト群）、チケット台帳（`TicketLedgerService` の
  reserve→commit/release 2 フェーズ）、Quota 基盤は実装済み
- `analysis_jobs` テーブル・`JobStatus` enum・アップロード/解析の route/UI は未実装（本設計の対象）

## 改善アイデア（何をどう変えるか）

### 1. データモデル（doc/10 §10.1 準拠）

**新テーブル `analysis_jobs`** + `AnalysisJob` Model + `AnalysisJobFactory`:

| カラム | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| video_manual_id | FK→video_manuals, NOT NULL | **protected**, cascade |
| source_document_id | FK→source_documents, NULL | **protected**, nullOnDelete |
| status | string enum | `JobStatus`: queued/running/succeeded/failed |
| step | string enum NULL | `AnalysisStep`: extract/decompose/generate |
| progress | int NULL | 0-100（ポーリング表示用の粗い値） |
| ticket_reservation_id | FK→ticket_reservations, NULL | **protected**。予約の冪等キー（§10.8-1） |
| result_json | json NULL | 中間成果（作業分解表）。デバッグ・再現用 |
| error | text NULL | 失敗理由（ユーザー向け要約） |
| timestamps | | |

- `output_path` は render 専用のため analysis_jobs には持たない（render_jobs は後続フェーズ）
- 新 enum: `App\Enums\Manual\JobStatus`（queued/running/succeeded/failed。フェーズ1の後続送り分）、
  `App\Enums\Manual\AnalysisStep`（extract/decompose/generate）
- `MassAssignmentProtectedKeys` に **`ticket_reservation_id` を追記**
  （`video_manual_id` / `source_document_id` は登録済み）
- `VideoManual::analysisJobs(): HasMany<AnalysisJob>` を追加（route param `{analysisJob}` の
  scopeBindings 推論と一致させる）
- `source_documents` テーブル/Model/Factory は既存（§10.1 の列は揃っている）。本フィーチャで
  **アップロードの振る舞い**（route/FormRequest/Service/UI）を導入する

### 2. 手順書アップロード経路

- **作成時アップロード**: `POST /projects/{project}/manuals`（既存）の multipart 拡張。
  `StoreVideoManualRequest` に任意の `document` ファイルフィールドを追加
  （mime: pdf / xlsx / xls / txt、サイズ上限は config。保護キーは従来どおり 422）。
  `VideoManualService::create()` が作成 tx 内で `SourceDocumentService::store()` を呼び、
  Storage（default disk。本番 S3 / テスト `Storage::fake()`）へ保存 + SourceDocument 行を
  relation 経由で作成する
- **後付け/差し替え**: `POST /projects/{project}/manuals/{manual}/source-documents`（新設）。
  Show 画面から「手順書なし」の manual に追加、または差し替え（既存行 + ファイルを削除して
  新規作成 = v1 は manual あたり実質 1 document）。`status=analyzing` 中の差し替えは 409。
  scopeBindings + `NestedRouteIdorDefenseTest` inventory 登録
- file_path はサーバ生成（`projects/{projectId}/manuals/{manualId}/source-documents/{ulid}.{ext}`）。
  original_name / mime / size_bytes はアップロードファイルから導出

### 3. 解析トリガー: `POST .../manuals/{manual}/analyze`

同一オリジン XHR（JSON 応答、`ScenarioService` 保存と同じ精緻な HTTP 契約が必要なため
Inertia redirect でなく JsonResource）。処理は `AnalysisJobService::trigger()`:

1. URL 整合 guard（`project.in-current-org` middleware + inline guard）+ scopeBindings
   （cross-org / cross-project は認可より前に 404）
2. 認可: `VideoManualPolicy::analyze`（新設。親委譲 = `ProjectPolicy::update`。
   編集者 project_admin のみ。撮影者 project_member は 403）
3. tx + **VideoManual 行ロック**（共有ロック規約: status を書くため）:
   - **analyze 冪等（§10.8-8）**: `status !== draft`（analyzing/ready/rendering/published）または
     in-flight job（queued/running）が存在 → **409**（同一 manual の同時 in-flight は 1 つ。
     失敗時は manual が draft に戻るため「failed のときのみ再トリガー可」は
     draft 判定 + in-flight 不在判定で構造的に満たされる）
   - source document 不在 → **422**（ValidationException。「手順書をアップロードしてください」）
   - **残高事前チェック**: `TicketLedgerService::balance(org) < COST_ANALYSIS` → **402**
     （`InsufficientTicketsException`。ここでは reserve しない。予約はジョブ開始時 = §10.5）
   - `AnalysisJob` を relation 経由で作成（status=queued、最新 SourceDocument を associate）
   - manual status を `draft → analyzing` に forceFill（enqueue 時点で遷移させ、
     ジョブ開始までの間の手動シナリオ編集を `ScenarioService` の analyzing guard で排他）
4. commit 後に `RunManualAnalysis::dispatch($job->id)`（`afterCommit`）
5. 応答: `AnalysisJobResource`（job id / status / step / progress）→ フロントがポーリング開始

org は web セッションでなく `$manual->project->organization`（HasOneThrough）から導出
（ジョブ側と同一の導出経路。payload のチケット/org 値は一切受けない）。

`InsufficientTicketsException` の render を拡張: 既存の web 向け back+flash は維持しつつ、
`expectsJson` の場合は **402** で JSON レンダリング（フレームワーク標準レンダリング。
`response()->json()` 直書きはしない）。

### 4. 解析ジョブ: `RunManualAnalysis` + `AnalysisPipeline`

**`App\Jobs\Manual\RunManualAnalysis`**（ShouldQueue）:
- payload は **`analysisJobId: int` のみ**（モデル/チケット/org 値を payload に持たない =
  payload 不信任。job / manual / source_document / org は DB から relation 経由で再解決）
- `$tries = 1`（§10.8-1: 自動再試行しない。再実行は analyze 再トリガーの明示操作のみ）、
  `$timeout = 600`（LLM 3 段 × timeout 120s + リトライ余裕。**予約 TTL 30 分より十分短く**、
  実行中の TTL 切れを構造的に起こさない）
- `handle()` は `AnalysisPipeline::run($analysisJobId)` へ委譲。`failed(Throwable)` は
  最終防衛線として failJob（下記）を冪等に呼ぶ（timeout 等 catch を通らない経路の後始末）

**`App\Services\Manual\AnalysisPipeline`**（本体）:

```
run(int $jobId):
  1. tx { job 行ロック。status !== queued なら no-op return（重複配送 guard）
        予約の確保 ensureReservation(job, org):                    ← §10.8-1
          - job->ticket_reservation_id が有効（status=Reserved かつ未失効）→ 再利用
          - 予約が Released（TTL stale cron が解放済み）/ 失効 → 新規 reserve → job に付け替え
          - 予約なし → reserve → job に associate（明示代入）
          - 残高不足 InsufficientTicketsException → failJob（予約なしのため release 不要）
        status=running, step=extract, progress=10 }
  2. extract:
     - SopTextExtractor::extract(sourceDocument) → テキスト
       （PDF: smalot/pdfparser、Excel: phpoffice/phpspreadsheet、txt: そのまま。
         抽出不能/空 → AnalysisFailedException「テキストを抽出できません。
         画像・スキャン手順書は現在未対応です」= v1 スコープ §10.7）
     - SopExtractPrompt::make(UserInput $text) → 統一 JSON → ExtractedSopData DTO 検証
     - source_documents.extracted_json に保存。step=decompose, progress=35
  3. decompose:
     - WorkDecompositionPrompt::make(UserInput $extractedJson) → WorkDecompositionData DTO
     - analysis_jobs.result_json に作業分解表を保存。step=generate, progress=65
  4. generate:
     - ScenarioGenerationPrompt::make(UserInput $decompositionJson) → GeneratedScenarioData DTO
       （cuts: no/type/parent_no/scene/shot_type/…。parent_no 整合・階層・文字数上限・
         steps≤100/points≤20 の有界性を DTO で検証）
  5. materialize: ScenarioService::materializeFromAnalysis(project, manual, steps)
  6. finalize: tx { commit は予約 status=Reserved のときのみ（TicketLedgerService::commit の
        行ロック + Reserved guard に委ねる。非 Reserved は report + 続行 = 防御的 guard。
        $timeout=600 ≪ TTL30分 のため実運用では到達しない）
        job: status=succeeded, progress=100 }
  X. catch (Throwable): failJob(job, error):
     - tx { job 行ロック: status=failed + error（ユーザー向け要約。詳細は report()）
           manual 行ロック: status が analyzing のときのみ draft へ戻す
           予約が Reserved なら release（LogicException は握って冪等）}
```

- **LLM 出力の有界リトライ**: 各段の「JSON 指示 + PHP 側 DTO 検証」で検証失敗
  （`LlmOutputInvalidException`）時のみ同一プロンプトを再実行、**最大 2 回**（計 3 試行）。
  それでも不正なら failJob（§10.4 / §10.7-2。YAML schema 機構には依存しない）
- チケットは COST_ANALYSIS=1（config 値）を **1 ジョブ 1 予約**。リトライ・多段呼び出しでも
  追加消費しない

### 5. materialize（`ScenarioService::materializeFromAnalysis()`）

シナリオ整合の**共有ロック規約**（AGENTS.md ドメイン固有規約 / `ScenarioService::save()` 準拠）
に従う第 2 の書き込み経路として、`ScenarioService` に新メソッドを追加する:

- 自前 tx で `$project->manuals()->whereKey($manual->id)->lockForUpdate()` → 行ロック
- guard: `status === analyzing` のときのみ実行（それ以外 = 想定外の状態遷移 → 例外で failJob へ）
- 既存 cuts を全削除 → 生成 cuts ツリーを挿入。**§10.8-5 準拠**:
  `sort_order` はサーバ採番（配列順）、`parent_cut_id` はネスト構造からサーバ決定、
  `type` は階層位置から導出（step/point）。本文フィールドは fill、導出キーは forceFill
  （`save()` の `upsertCut()` を新規作成モードで再利用）
- `scenario_version += 1`、`status: analyzing → ready`
- 入力型は手動保存と同じ `ScenarioStepInput` / `ScenarioPointInput`（id=null の新規のみ）に
  変換して渡す（生成物と手動編集のデータ構造を 1 つの変換点で合流させる）

書き込み経路が 2 つになるため、AGENTS.md の規約どおり **経路 inventory を持つ
Architecture テストへ昇格**する（`cuts` / `scenario_version` / `video_manuals.status` を書く
クラスの allowlist: `ScenarioService` / `AnalysisJobService`。deny-by-default）。

### 6. ポーリング: `GET .../manuals/{manual}/jobs/{analysisJob}`

- URI は doc/10 §10.3 どおり `/jobs/{analysisJob}`（param 名 `{analysisJob}` で scopeBindings が
  `VideoManual::analysisJobs()` から解決。cross-manual の job id は認可より前に 404）
- 認可: `view`（撮影者も read 可 = doc §10.5 の read 権限）
- 応答: `AnalysisJobResource`（id / status / step / progress / error / manual_status）。
  `manual_status` を含めることでフロントは succeeded 時に ready を確認してリロードできる
- 任意最適化（§10.8-8）: `updated_at` 由来の `Last-Modified` + `If-Modified-Since` で 304
  （実装は低優先。詳細設計でコスト次第では見送り可）

### 7. プロンプト 3 種（§10.4 準拠）

`resources/prompts/` に追加（全 YAML で name 一意 = PromptYamlContractTest、
`client_options.timeout: 120` = PromptClientTimeoutInvariantTest、
`DefensiveInstructions::forUserInputJa()` preamble = DefensiveInstructionsPresenceTest、
untrusted 変数は `UserInput` 経由 = PromptUntrustedInputContractTest / セキュリティ不変条件 4）:

| YAML | 変数 | 出力 → DTO |
|---|---|---|
| `sop-extract.yaml` | `{{ $text }}`（抽出テキスト、UserInput） | 統一 JSON（header + sections[].steps[]）→ `ExtractedSopData` |
| `work-decomposition.yaml` | `{{ $extracted }}`（統一 JSON 文字列、UserInput） | `{ steps: [{no, action, points[]}] }` → `WorkDecompositionData` |
| `scenario-generation.yaml` | `{{ $decomposition }}`（作業分解表 JSON 文字列、UserInput） | `{ cuts: [{no, type, parent_no, scene, shot_type, shooting_point, narration, subtitle_primary, subtitle_secondary}] }` → `GeneratedScenarioData` |

- factory は `app/Prompts/{SopExtract,WorkDecomposition,ScenarioGeneration}Prompt::make()`
  （`Prompt::load` は app/Prompts のみ = PromptGuardrailTest。Prism 直呼び禁止）
- JSON パース（コードフェンス除去 + json_decode）と検証は DTO 側 `fromLlmText()` に置き、
  不正は `LlmOutputInvalidException`（有界リトライのトリガー）
- プロンプト本文は doc/10 §10.4 草案 + doc/03 のルール（1 動作 1 No、急所分離、捏造禁止、
  ナレーション語尾統一、字幕①/②の出し分け、shot_type 原則）を織り込む

### 8. フロントエンド（Inertia + Svelte 5 runes、DS token、Lucide、disabled 禁止）

- `Manuals/Create.svelte`: 手順書ファイル入力（任意）を追加（multipart POST）
- `Manuals/Show.svelte` + 新 feature component `features/manual/AnalysisPanel.svelte`:
  - draft + 編集権限: 「AI 解析」ボタン（**disabled にしない**。手順書なし/残高不足は
    押下時にサーバの 422/402 メッセージを表示）。手順書未添付ならアップロード導線
  - analyzing: 進捗表示（step ラベル: 抽出中/作業分解中/シナリオ生成中 + progress bar）。
    `GET .../jobs/{id}` を 2〜3 秒間隔でポーリング、succeeded → `router.reload()`、
    failed → エラー表示 + 「再実行」ボタン（analyze 再 POST）
  - Show の props に `analysis: { job: AnalysisJobProps | null, hasDocument: boolean }` を追加
- `resources/js/types/manual.ts` に AnalysisJob 型を追加

### 9. 設定・依存

- `config/manual.php`（新規）: `analysis_ticket_cost => 1`（COST_ANALYSIS。§10.5）、
  `source_document_max_bytes`、許可 mime、`analysis_llm_max_retries => 2`
- composer 依存追加: `smalot/pdfparser`（PDF テキスト抽出）、`phpoffice/phpspreadsheet`
  （Excel テキスト抽出）。`pnpm run audit:gate` を通し、supply-chain review-checklist の
  観点で採用理由を記録
- シナリオ有界値（steps≤100 / points≤20）は `UpdateScenarioRequest` の private 定数から
  共有クラス（例 `App\Support\Manual\ScenarioLimits`）へ昇格し、DTO 検証と共用

## 期待効果

- **使命への直接貢献**: 「SOP を起点に AI がカット設計」という North Star の中核機能が
  エンドツーエンドで動く（アップロード → 1 クリック解析 → 撮影可能なシナリオ）
- 生成結果は既存のシナリオ編集（T002）にそのまま合流し「現場が最終確認・修正する」
  運用（doc/03 §3.4 の示唆）が成立する
- チケット 2 フェーズ・冪等設計により、再試行・並行トリガー・TTL 切れでも
  二重課金/二重実行が起きない（セキュリティ不変条件 7）

## 実装方針（概要）

| レイヤ | 追加/変更 |
|---|---|
| DB | `analysis_jobs` migration、`JobStatus`/`AnalysisStep` enum、`AnalysisJobFactory` |
| Model | `AnalysisJob`（FK は fillable 外）、`VideoManual::analysisJobs()` |
| Security | `MassAssignmentProtectedKeys` に `ticket_reservation_id` |
| Routes | analyze POST / jobs GET / source-documents POST（scopeBindings + IDOR inventory） |
| Controller | `ManualAnalysisController`（store/show）、`SourceDocumentController`（store） |
| Service | `AnalysisJobService`（trigger/failJob）、`AnalysisPipeline`、`SopTextExtractor`、`SourceDocumentService`、`ScenarioService::materializeFromAnalysis()` |
| Job | `RunManualAnalysis`（tries=1, timeout=600, payload=job id のみ） |
| LLM | prompts 3 YAML + factory 3 種 + DTO 群 + `LlmOutputInvalidException` |
| Front | Create のファイル入力、Show の AnalysisPanel（ポーリング）、TS 型 |
| Test | Feature（成功/失敗/2 フェーズ/冪等/402/404/403/422/リトライ）、Architecture（IDOR・書き込み経路 inventory）、Vitest |

## 制約・前提

- **doc/10 §10.8 が §10.1〜§10.7 に優先**（チケット冪等キー・tries=1・analyze 冪等）
- `TicketLedgerService` は**内部変更しない**（テンプレ課金プリミティブ。reserve→commit/release の
  公開 API のみ使用。TTL 延長 API は追加しない = §10.8 採用しなかった項)
- LLM はテスト時 `Prompt::fake()`、ストレージは `Storage::fake()`、キューは sync /
  `Queue::fake()`（実 API・実 S3 に触れない）
- 共有ロック規約: status / scenario_version / cuts の全書き込みは VideoManual 行ロック下
- v1 はテキスト抽出可能な手順書のみ（画像/スキャン PDF のマルチモーダルは後続 = §10.7-1）
- キュー: 既定の database driver / default queue（専用 worker 分離はレンダフェーズで検討）

## スコープ外（後続フェーズ）

- 撮影 PWA・テイク管理、レンダ（RenderJob / COST_RENDER）、多言語、TTS
- 画像/スキャン手順書のマルチモーダル解析（OCR）
- `max_manuals` / `max_storage_bytes` Quota の実計上（レンダ/撮影フェーズ）
- ジョブ進捗の WebSocket/SSE push（v1 はポーリング）
- 複数 SourceDocument の統合解析（v1 は manual あたり実質 1 document）
