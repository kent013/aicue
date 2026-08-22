# 概念設計: ai-analysis（AI 解析: SOP→作業分解→シナリオ生成→Cut materialize）

作成: 2026-07-11 / 対象アプリ: AI-CUE (/workspace)
ステータス: **APPROVED**（Codex gpt-5.4/medium 概念レビュー Round 4。履歴は codex-history/）
改訂: Round 1〜3 レビュー反映（ready からの再解析・stale job 回復・terminal tx・
LLM 入力の UTF-8 バイト上限・SourceDocument 追記型 immutable 化・commit/succeeded の原子性・
経路 inventory のメソッド粒度）

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
  Show 画面から「手順書なし」の manual に追加、または差し替え。**SourceDocument は追記型
  immutable**（差し替え = 新しい行を追加。既存行・ファイル・extracted_json は削除も上書きも
  しない = 過去の analysis_jobs の参照と監査性を保つ）。解析は常に**最新の 1 件**を使う
  （v1 は「実質 1 document」= latest 勝ち。旧ファイルの物理掃除はストレージ Quota フェーズ）。
  許可状態は `status ∈ {draft, ready}`（analyzing/rendering/published は 409）。
  **状態確認と SourceDocument 作成は VideoManual 行を `lockForUpdate()` した同一 tx 内**で行う
  （analyze の trigger と直列化。trigger 側の「最新 document 選択」も同じ行ロック下で行い、
  選択順は `latest('id')` で決定的にする = 差し替えと解析開始の競合を排除）。
  scopeBindings + `NestedRouteIdorDefenseTest` inventory 登録
- file_path はサーバ生成（`projects/{projectId}/manuals/{manualId}/source-documents/{ulid}.{ext}`）。
  original_name / mime / size_bytes はアップロードファイルから導出

### 3. 解析トリガー: `POST .../manuals/{manual}/analyze`

同一オリジン XHR（JSON 応答、`ScenarioService` 保存と同じ精緻な HTTP 契約が必要なため
Inertia redirect でなく JsonResource）。処理は `AnalysisJobService::trigger()`:

1. URL 整合 guard（`project.in-route-org` middleware + inline guard）+ scopeBindings
   （cross-org / cross-project は認可より前に 404）
2. 認可: `VideoManualPolicy::analyze`（新設。親委譲 = `ProjectPolicy::update`。
   編集者 project_admin のみ。撮影者 project_member は 403）
3. tx + **VideoManual 行ロック**（共有ロック規約: status を書くため）:
   - **実行可能状態**: `status ∈ {draft, ready}` のみ（analyzing/rendering/published は **409**）。
     **ready からの再解析を正式な遷移として定義**する（SOP 差し替え後に再解析できないと
     「SOP 起点」の使命に反する。既存 cuts は materialize で全置換されるため、フロントは
     ready からの実行時に確認ダイアログを出す）。§10.2 は draft→analyzing のみ記載のため、
     **本設計の承認と同時に `doc/10_実装仕様.md` §10.2 を更新**する（ready→analyzing 追加と
     失敗復帰規則。許可遷移は状態遷移テストへ登録し仕様と実装の不一致を残さない）
   - **analyze 冪等（§10.8-8）**: in-flight job（queued/running）が存在 → **409**
     （同一 manual の同時 in-flight は 1 つ。直近 job が failed/succeeded のときのみ
     再トリガー可 = in-flight 不在判定で構造的に満たされる）
   - source document 不在 → **422**（ValidationException。「手順書をアップロードしてください」）
   - **残高事前チェック**: `TicketLedgerService::balance(org) < COST_ANALYSIS` → **402**
     （`InsufficientTicketsException`。ここでは reserve しない。予約はジョブ開始時 = §10.5）
   - `AnalysisJob` を relation 経由で作成（status=queued、最新 SourceDocument を associate）
   - manual status を `draft|ready → analyzing` に forceFill（enqueue 時点で遷移させ、
     ジョブ開始までの間の手動シナリオ編集を `ScenarioService` の analyzing guard で排他）
4. commit 後に `RunManualAnalysis::dispatch($job->id)`（`afterCommit`）。
   dispatch 失敗・queue 投入喪失は下記の **stale job 回復**（§4）が拾う
5. 応答: `AnalysisJobResource`（job id / status / step / progress）→ フロントがポーリング開始

org は web セッションでなく `$manual->project->organization`（HasOneThrough）から導出
（ジョブ側と同一の導出経路。payload のチケット/org 値は一切受けない）。

`InsufficientTicketsException` の render を拡張: 既存の web 向け back+flash は維持しつつ、
`expectsJson` の場合は **402** を JsonResource で返す（`ScenarioConflictException::render()` と
同型の「専用 Resource + `->response()->setStatusCode(402)`」パターン。
`response()->json()` 直書きは書かないことをここで固定する）。

### 4. 解析ジョブ: `RunManualAnalysis` + `AnalysisPipeline`

**`App\Jobs\Manual\RunManualAnalysis`**（ShouldQueue）:
- payload は **`analysisJobId: int` のみ**（モデル/チケット/org 値を payload に持たない =
  payload 不信任。job / manual / source_document / org は DB から relation 経由で再解決）
- `$tries = 1`（§10.8-1: 自動再試行しない。再実行は analyze 再トリガーの明示操作のみ）、
  `$timeout = 1380`（worst-case: LLM 3 段 × 3 試行 × client timeout 120 秒 = 1,080 秒 + 抽出/余裕。
  `timeout (1380) < queue retry_after (1560、専用 connection) < 予約 TTL (1800)` の連鎖を
  Architecture テストで固定。実行中の TTL 切れ・二重処理を構造的に起こさない）
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
     - SopTextExtractor::extract(sourceDocument) → ExtractedText 値オブジェクト
       （text + charCount + sourceKind の診断情報。PDF: smalot/pdfparser、
         Excel: phpoffice/phpspreadsheet、txt: そのまま）
       - 抽出不能/実質空（最小文字数未満）→ AnalysisFailedException
         「テキストを抽出できません。画像・スキャン手順書は現在未対応です」= v1 スコープ §10.7
       - **入力長上限**（LLM 文脈長・コスト暴走 guard）: 抽出テキストが
         config の max_text_bytes 超過 → AnalysisFailedException
         「手順書が大きすぎます。分割してアップロードしてください」
       - 上限は **UTF-8 バイト数基準**で token budget から導出する: モデル context
         200,000 token − 出力予約 16,000 − 固定プロンプト余裕 4,000 = 入力 budget
         180,000 token。byte-fallback BPE 系 tokenizer では **token 数 ≤ UTF-8 バイト数**が
         安全側の上界（1 byte が 1 token より細かく割れることはない）ため、
         `strlen($text) ≤ max_text_bytes` で budget 内を保証する。
         既定値 **150,000 bytes**（budget 180,000 に対しマージン込み。日本語 3 bytes/字で
         実質 5 万字 ≈ 数十ページの SOP をカバー）。
         算術（`max_text_bytes + 出力予約 + 固定分 ≤ context`）は config 不変条件テストで
         CI 固定する（値を弄って budget を壊せない）
       - 防御第二層: それでも provider が入力長で拒否した場合は当該例外を握って
         failJob（ユーザー向けエラー）。長さ起因の失敗は有界リトライの対象にしない
         （リトライは JSON 検証失敗のみ）
       - **運用条件**: 「token 数 ≤ UTF-8 バイト数」は byte-fallback BPE 系 tokenizer の
         前提。対象モデル・tokenizer 系を変更する際は本上限設計
         （config 値 + AnalysisTokenBudgetInvariantTest の定数）を必ず再確認すること
     - SopExtractPrompt::make(UserInput $text) → 統一 JSON → ExtractedSopData DTO 検証
     - source_documents.extracted_json に保存。step=decompose, progress=35
  3. decompose:
     - WorkDecompositionPrompt::make(UserInput $extractedJson) → WorkDecompositionData DTO
     - analysis_jobs.result_json に作業分解表を保存。step=generate, progress=65
  4. generate:
     - ScenarioGenerationPrompt::make(UserInput $decompositionJson) → GeneratedScenarioData DTO
       （cuts: no/type/parent_no/scene/shot_type/…。parent_no 整合・階層・文字数上限・
         steps≤100/points≤20 の有界性を DTO で検証）
  5. terminal tx（**materialize + 課金確定 + succeeded を単一トランザクションで原子的に**。
     stale 回復 cron との競合を job 行ロックで直列化する）:
     DB::transaction {
       a. job 行を lockForUpdate → **guard: status === running**（cron の failJob が先勝ちして
          failed になっていたら何もせず終了 = materialize も commit も succeeded も行わない。
          遅れて完走した pipeline が「無課金 succeeded」を作る競合を構造的に排除）
       b. ScenarioService::materializeIntoLockedManual(lockedManual, steps)
          （詳細レビューで「ロック済み前提メソッド」へ改名・再構成。tx/行ロックは terminal tx が最外層で張る）
          （内側の DB::transaction は同一接続のネスト = savepoint。manual 行ロック +
            analyzing guard は本メソッド内。共有ロック規約準拠）
       c. TicketLedgerService::commit(reservation)
          （Service 内部の行ロック + Reserved guard に委ねる。**非 Reserved は
            LogicException → terminal tx 全体が rollback**（materialize も巻き戻る）→
            catch 経路の failJob へ。「report して続行」はしない = 無課金成功を作らない）
       d. job: status=succeeded, progress=100
     }
     → 「cuts 反映」「課金確定」「succeeded」は全て同時に成立するか全て成立しない
        （課金済み failed / 未課金 succeeded を作らない。テスト観点に含める）
  X. catch (Throwable): failJob(job, error):
     - tx { job 行ロック → **guard: status ∈ {queued, running} のときのみ**（succeeded /
           failed は no-op = 冪等。terminal tx 勝ち後の cron・failed() フック競合も安全）
           status=failed + error（ユーザー向け要約。詳細は report()）
           manual 行ロック: status が analyzing のときのみ復帰（cuts が 1 件以上あれば
           ready、無ければ draft。ready からの再解析失敗で ready に戻す一般化。
           §10.2 の「失敗は draft へ」は cuts 無しの初回解析ケースとして包含される）
           予約が Reserved なら release（LogicException は握って冪等）}
```

**stale job 回復（dispatch 喪失・worker 異常終了の後始末）**:
`analysis:recover-stale-jobs` console command を新設し 5 分毎に schedule
（`billing:release-stale-reservations` と同型の運用プリミティブ）:
- `status=queued` かつ `created_at` が閾値（30 分）超過 → failJob（dispatch 喪失 /
  キュー詰まり。遅延配送が後から届いても `run()` 冒頭の「status !== queued なら no-op」
  guard で二重実行にならない）
- `status=running` かつ `updated_at` が閾値（30 分）超過 → failJob（worker クラッシュ /
  timeout kill。pipeline は各 step 遷移で progress を更新するため **updated_at を
  「最終 step 更新時刻」として stale 判定に利用**する。厳密なハートビートではないため、
  安全性の本体は閾値ではなく **terminal tx の job 行ロック + status guard**（上記 5-a）:
  仮に生存中の pipeline を誤回収しても、その pipeline は terminal tx で status !== running を
  検知して materialize / commit を行わず終了する。閾値 30 分は step 更新間隔の worst-case（1 段 360 秒）より十分大きく誤回収自体も実運用では起きない）
- failJob は冪等（行ロック + status ∈ {queued, running} guard）なので cron と
  `failed()` フックの競合は安全

- **LLM 出力の有界リトライ**: 各段の「JSON 指示 + PHP 側 DTO 検証」で検証失敗
  （`LlmOutputInvalidException`）時のみ同一プロンプトを再実行、**最大 2 回**（計 3 試行）。
  それでも不正なら failJob（§10.4 / §10.7-2。YAML schema 機構には依存しない）
- チケットは COST_ANALYSIS=1（config 値）を **1 ジョブ 1 予約**。リトライ・多段呼び出しでも
  追加消費しない
- **DTO は永続化境界を跨いで in-memory で受け渡す**（extract → decompose → generate は
  DTO をそのまま次段へ渡す。`extracted_json` / `result_json` は監査・デバッグ用の
  write-only スナップショットで、v1 のアプリコードは DB から再読込しない =
  `array<mixed>` 汚染を作らない。再読込が必要になった時点で custom cast で DTO 復元を固定）
- LLM 出力 JSON の切り詰め防止: 3 YAML に `max_tokens` を明示（生成シナリオ JSON は
  既定 4096 では不足しうるため 16,000 程度。詳細設計で確定）

### 5. materialize（`ScenarioService::materializeIntoLockedManual()`）

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

書き込み経路が 2 つ以上になるため、AGENTS.md の規約どおり **経路 inventory を持つ
Architecture テストへ昇格**する。inventory は**メソッド粒度**で「何を書いてよいか」まで固定:

| 経路 | 書いてよいもの |
|---|---|
| `ScenarioService::save()` | cuts / scenario_version / status（rendering·analyzing guard 付き） |
| `ScenarioService::materializeIntoLockedManual()` | cuts / scenario_version / status（analyzing→ready のみ） |
| `AnalysisJobService::trigger()` | status（draft·ready→analyzing のみ） |
| `AnalysisJobService::failJob()` | status（analyzing→ready·draft のみ。cuts 有無で決定） |

（`AnalysisPipeline` / job / cron は status を直接書かず、必ず上記メソッド経由。
deny-by-default: 上記以外のファイルが `scenario_version` / `VideoManualStatus` 書き込みに
触れたらテストが fail する静的走査）

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
  - draft/ready + 編集権限: 「AI 解析」ボタン（**disabled にしない**。手順書なし/残高不足は
    押下時にサーバの 422/402 メッセージを表示）。手順書未添付ならアップロード導線。
    ready からの再解析は「既存シナリオが置き換えられます」確認ダイアログを挟む
  - analyzing: 進捗表示（step ラベル: 抽出中/作業分解中/シナリオ生成中 + progress bar）。
    `GET .../jobs/{id}` を 2〜3 秒間隔でポーリング、succeeded → `router.reload()`、
    failed → エラー表示 + 「再実行」ボタン（analyze 再 POST）
  - Show の props に `analysis: { job: AnalysisJobProps | null, hasDocument: boolean }` を追加
- `resources/js/types/manual.ts` に AnalysisJob 型を追加

### 9. 設定・依存

- `config/manual.php`（新規）: `analysis_ticket_cost => 1`（COST_ANALYSIS。§10.5）、
  `source_document_max_bytes`、許可 mime、`analysis_llm_max_retries => 2`、
  `analysis_max_text_bytes => 150_000`（LLM 入力上限。UTF-8 バイト基準の token budget
  導出値 = §4）、`analysis_stale_after_minutes => 30`（stale 回復閾値）
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
- チケット 2 フェーズ + 予約冪等キー + stale job 回復により、再試行・並行トリガー・
  TTL 切れ・worker 異常終了のそれぞれで「二重課金しない / analyzing で詰まない」方向へ
  収束する設計（セキュリティ不変条件 7。各失敗モードはテストで固定する）

## 実装方針（概要）

| レイヤ | 追加/変更 |
|---|---|
| DB | `analysis_jobs` migration、`JobStatus`/`AnalysisStep` enum、`AnalysisJobFactory` |
| Model | `AnalysisJob`（FK は fillable 外）、`VideoManual::analysisJobs()` |
| Security | `MassAssignmentProtectedKeys` に `ticket_reservation_id` |
| Routes | analyze POST / jobs GET / source-documents POST（scopeBindings + IDOR inventory） |
| Controller | `ManualAnalysisController`（store/show）、`SourceDocumentController`（store） |
| Service | `AnalysisJobService`（trigger/failJob）、`AnalysisPipeline`、`SopTextExtractor`、`SourceDocumentService`、`ScenarioService::materializeIntoLockedManual()` |
| Job | `RunManualAnalysis`（tries=1, timeout=1380, 専用 queue connection, payload=job id のみ） |
| Console | `analysis:recover-stale-jobs`（5 分毎 schedule。stale queued/running の回復） |
| LLM | prompts 3 YAML + factory 3 種 + DTO 群 + `LlmOutputInvalidException` |
| Front | Create のファイル入力、Show の AnalysisPanel（ポーリング）、TS 型 |
| Test | Feature（成功/失敗/2 フェーズ/冪等/402/404/403/422/リトライ/stale 回復/terminal tx 競合/commit 原子性/状態遷移）、Architecture（IDOR・書き込み経路 inventory・token budget config 不変条件）、Vitest |

terminal tx と failJob の競合は**インターリーブ別に Feature テストで固定**する:
(a) cron 先勝ち → pipeline は materialize/commit/succeeded を行わない、
(b) pipeline 先勝ち → 後追い cron/failed() は no-op、
(c) materialize 例外 → rollback + failed + released、
(d) commit 例外（非 Reserved）→ terminal tx 全体 rollback + failed。
不変条件「failed ∧ committed が共存しない」「succeeded ∧ released が共存しない」を
アサーションに含める。
| Docs | `doc/10_実装仕様.md` §10.2（ready→analyzing / 失敗復帰の追記）、`docs/architecture.md`、`docs/factories.md` |

実装は 1 チケット内で 3 層に段階化する（失敗切り分けのため）:
(1) 状態機械 + 課金の閉塞（analysis_jobs / trigger / failJob / 回復 cron / チケット 2 フェーズ）
→ (2) 抽出 + LLM 3 段 + DTO + materialize → (3) UI（アップロード / AnalysisPanel / ポーリング）。

## 制約・前提

- **doc/10 §10.8 が §10.1〜§10.7 に優先**（チケット冪等キー・tries=1・analyze 冪等）
- `TicketLedgerService` は**内部変更しない**（テンプレ課金プリミティブ。reserve→commit/release の
  公開 API のみ使用。TTL 延長 API は追加しない = §10.8 採用しなかった項)
- LLM はテスト時 `Prompt::fake()`、ストレージは `Storage::fake()`、キューは sync /
  `Queue::fake()`（実 API・実 S3 に触れない）
- 共有ロック規約: status / scenario_version / cuts の全書き込みは VideoManual 行ロック下
- v1 はテキスト抽出可能な手順書のみ（画像/スキャン PDF のマルチモーダルは後続 = §10.7-1）
- キュー: database driver。解析ジョブは専用 connection `database-analysis`（queue=analysis、
  retry_after=1560 = timeout より長く TTL より短い）で流す（詳細設計 施策 6 の時間 budget）

## スコープ外（後続フェーズ）

- 撮影 PWA・テイク管理、レンダ（RenderJob / COST_RENDER）、多言語、TTS
- 画像/スキャン手順書のマルチモーダル解析（OCR）
- `max_manuals` / `max_storage_bytes` Quota の実計上（レンダ/撮影フェーズ）
- ジョブ進捗の WebSocket/SSE push（v1 はポーリング）
- 複数 SourceDocument の統合解析（v1 は manual あたり実質 1 document = latest 勝ち）
- 旧 SourceDocument ファイルの物理掃除・容量計上（ストレージ Quota フェーズで一括設計）
- 長大 SOP の分割・要約前処理（v1 は上限超過を明示エラーで返す）
