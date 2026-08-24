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
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【セキュリティ不変条件（アプリ都合で緩めない）— AGENTS.md より】

1. tenant キー不信: ownership/actor/tenant キーを payload から受け取らない
2. 子は親に属する: nested route の不整合は認可より前に 404（NestedRouteIdorDefenseTest inventory 登録必須）
3. cross-org 不可: 組織を跨ぐ read/write をしない
4. untrusted 文字列は UserInput 型経由でのみ prompt に入れる
5. 権限判定は常に laratrust_team_id を明示
6. PII は CipherSweet。検索は whereBlind()
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

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【前提コンテキスト（レビュー時に既知としてよい既存実装）】
- 確定仕様は /workspace/doc/10_実装仕様.md（特に §10.1 render_jobs / §10.2 JobStatus /
  §10.3 route / §10.5 COST_RENDER / §10.8-1 チケット2フェーズ / §10.8-6 レンダ中編集競合 /
  §10.8-8 render 冪等。§10.8 が §10.1〜§10.7 に優先）と /workspace/doc/09_詳細実装設計.md §9.7
- 見本実装（T003 AI 解析。読み込み可）: /workspace/app/Services/Manual/AnalysisJobService.php、
  /workspace/app/Services/Manual/AnalysisPipeline.php、/workspace/app/Jobs/Manual/RunManualAnalysis.php、
  /workspace/app/Services/Billing/TicketLedgerService.php（内部変更禁止のテンプレ課金プリミティブ）
- 共有ロック規約: cuts / video_manuals.scenario_version / video_manuals.status を書く全経路は
  VideoManual 行を lockForUpdate() した同一 tx 内で反映（ScenarioWritePathInventoryTest が
  deny-by-default で強制）。ScenarioService::save() / CaptureTakeService::adopt()・delete() は
  rendering/analyzing 中 409 の guard 実装済み
- v1 の Take はアップロード時 ffmpeg 正規化なし（登録時即 ready、duration_ms はクライアント申告）

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下、devnotes/20260711-0549-render/conceptual-design.md の全文）

# 概念設計: render（レンダ: 採用テイク合成 → 完成 mp4。ffmpeg + チケット 2 フェーズ）

作成: 2026-07-11 / 対象アプリ: AI-CUE (/workspace)
ステータス: ドラフト（Codex 概念レビュー前）

## 背景・課題

AI-CUE の使命（North Star）は「専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を
作れるようにする」こと。本フィーチャはその**最終成果物**を生成する:

```
採用済みテイク群（T004 実装済み）
  → RenderJob（compose: カットごとにクリップ正規化 + 字幕焼き込み）
  → RenderJob（concat: cut 順に連結 → 完成 mp4）
  → S3 出力（output_path）、manual status: rendering → published
  → プレビュー / ダウンロード（署名 URL）
```

現状:
- VideoManual/Cut/Take、シナリオ編集（T002）、AI 解析（T003）、撮影/採用（T004）実装済み
- `render_jobs` テーブル・route・ffmpeg 合成・レンダ UI は未実装（本設計の対象）
- **見本にする既存基盤**: T003 の `AnalysisJob` + `AnalysisJobService` + `AnalysisPipeline`
  （チケット reserve/commit/release・予約冪等キー・tries=1・TTL 付け替え・terminal tx・
  stale 回復 cron）。§10.8 の方針どおり **AnalysisJob/RenderJob は共通抽象化せず個別実装**
- v1 スコープ: **字幕のみ（TTS なし）**、自前 ffmpeg ワーカー、単一言語（多言語は後続）
- 動画正規化の前提: v1 の Take はアップロード後の ffmpeg 正規化を**行っていない**
  （`TakeRegistrationService` は登録時に即 ready。`duration_ms` はクライアント申告 = 表示用）。
  したがってレンダ側 compose 段が**クリップごとの正規化（再エンコード）を兼ねる**必要がある

## 改善アイデア（何をどう変えるか）

### 1. データモデル（doc/10 §10.1 準拠 + §10.8-6 の version スナップショット）

**新テーブル `render_jobs`** + `RenderJob` Model + `RenderJobFactory`:

| カラム | 型 | 備考 |
|---|---|---|
| id | bigint PK | |
| video_manual_id | FK→video_manuals, NOT NULL | **protected**, cascade |
| kind | string enum | `RenderKind`: render / preview（§10.8-8「別操作種別」の実体） |
| status | string enum | `JobStatus`（既存 T003 enum を共用）: queued/running/succeeded/failed |
| step | string enum NULL | `RenderStep`: compose / concat（§10.1） |
| progress | int NULL | 0-100（ポーリング表示用の粗い値） |
| scenario_version | int NOT NULL | **開始時スナップショット**（§10.8-6。トリガー tx で確定） |
| ticket_reservation_id | FK→ticket_reservations, NULL | **protected**。予約の冪等キー（§10.8-1）。preview は常に NULL |
| output_path | string NULL | S3 キー（成功時のみ） |
| error | text NULL | 失敗理由（ユーザー向け要約） |
| timestamps | | |

- `result_json` は analysis 専用のため持たない（§10.1 の共通表は「等」表記。レンダに中間成果なし）
- **`kind` / `scenario_version` は §10.1 の列表に無い追加列**。`kind` は §10.8-8
  「preview と render は別操作種別（in-flight 判定・課金有無が異なる）」を 1 テーブルで
  表現する最小手段（preview 専用テーブルはカラムがほぼ同一で過剰）。`scenario_version` は
  §10.8-6 のスナップショット要件の実体。**本設計の承認と同時に doc/10 §10.1 を更新**する
- 新 enum: `App\Enums\Manual\RenderStep`（compose/concat）、`App\Enums\Manual\RenderKind`
  （render/preview）。`JobStatus` は既存を共用（doc/10 §10.2 どおり）
- 保護キー: `video_manual_id` / `ticket_reservation_id` は `MassAssignmentProtectedKeys`
  登録済み（追記不要）。RenderJob の status/step/progress/kind/scenario_version/output_path/error
  は Service 管理状態のため **$fillable を持たない**（AnalysisJob と同じ明示代入のみの規約）
- `VideoManual::renderJobs(): HasMany<RenderJob>`（route param `{renderJob}` の
  scopeBindings 推論と一致する relation 名）

### 2. ルート（§10.3。PC 編集画面、web guard・org-scoped・認可前 404）

| メソッド | パス | 用途 | 応答 |
|---|---|---|---|
| POST | `.../manuals/{manual}/render` | 完成動画レンダ（`video_render` チケット消費） | 201 + RenderJobResource |
| POST | `.../manuals/{manual}/preview` | プレビュー生成（チケット非消費） | 201 + RenderJobResource |
| GET | `.../manuals/{manual}/render-jobs/{renderJob}` | job 状態ポーリング | 200 + RenderJobResource |
| GET | `.../manuals/{manual}/download?lang=` | 完成 mp4 の署名 URL へ redirect | 302（S3 署名 URL） |

- 全て既存 `.../manuals/{manual}` 系と同じ `Route::scopeBindings()` グループ +
  `NestedRouteIdorDefenseTest` inventory 登録（4 route）。cross-org 404 は既存
  `EnsureProjectBelongsToRouteOrganization`（`project.in-route-org`）+ inline guard で担保
- **ポーリング URI は §10.3 の `GET .../jobs/{job}` から `.../render-jobs/{renderJob}` へ変更**:
  既存 `jobs/{analysisJob}` は `VideoManual::analysisJobs()` に bind 済みで、render_jobs は
  別テーブルのため同一 param では scopeBindings の relation 推論（= IDOR 防御の第一層）が
  成立しない。ポーリング応答 shape・フロントの polling 実装は AnalysisJob 系を踏襲
  （「流用/共通化」はコード規約レベルで達成し、URL は型ごとに分ける）。
  **doc/10 §10.3 を本設計承認と同時に更新**
- `download` の `lang` は v1 では**任意 + `ja` のみ許可**（それ以外は 422。feature_multilang
  は後続。クエリ形状だけ §10.3 と互換に保つ）。ダウンロード可能条件:
  `status=published` かつ最新 succeeded render job の `output_path` あり（無ければ 409 系の
  conflict 応答ではなく **404**: 完成物が存在しない）。応答は
  `redirect()->away(署名URL)`（`response-content-disposition: attachment` を署名に含める。
  DTO/JsonResource 規約の対象外 = JSON を返さない）
- render/preview トリガーは同一オリジン XHR（JSON 応答）。409/402/422 の精緻な HTTP 契約が
  必要なため `ManualAnalysisController` と同型の JsonResource 構成

### 3. 認可（§10.5: 編集者 = render/download。撮影者は不可）

`VideoManualPolicy` に親委譲メソッドを追加（直 fetch 禁止・`ProjectPolicy::update` 委譲）:
- `render(User, VideoManual)`: render / preview 両トリガーの ability（preview も編集者専用。
  §10.5 の権限表は編集者=render/download、撮影者に preview は無い）
- `download(User, VideoManual)`: download + ポーリング…ではなく、**ポーリングは `view`**
  （AnalysisJob ポーリングと同じ read 権限。撮影者もレンダ進行を見られて害がない）

### 4. トリガー: `RenderJobService::trigger()`（render）/ `triggerPreview()`（preview）

`AnalysisJobService::trigger()` と同型。tx + **VideoManual 行ロック**（共有ロック規約:
status を書く / version を読むため。`ScenarioWritePathInventoryTest` inventory 登録）:

**render（チケット消費・status 遷移あり）**:
1. 行ロック（`$project->manuals()->whereKey(...)->lockForUpdate()` = 子∈親も担保）
2. 実行可能状態 guard: `status === ready` のみ（それ以外は **409** `RenderConflictException`）。
   - published からの直接再レンダは**不可**（編集で published→ready に戻してから =
     §10.8-6 の「再レンダは明示トリガー」。無変更の published 再レンダは同一出力に
     チケットを重複消費するだけなので状態機械から排除）
   - 失敗後の再トリガー: failJob が rendering→ready へ戻すため「failed のみ再トリガー可」
     （§10.8-8）は ready guard + in-flight 判定で構造的に満たされる
3. **render 冪等（§10.8-8）**: 同一 manual の in-flight（queued/running）な `kind=render`
   job が存在 → **409**（preview の in-flight は別種別なので妨げない）
4. **入力検証（採用テイク欠落 = エラー方針）**: 全カット（step/point）を走査し、
   `adopted_take_id` が NULL または採用テイクが `status !== ready` のカットが 1 つでもあれば
   **422**（欠落カットの表示ラベル一覧を message に含める）。
   **スキップではなくエラーを採用**: 使命は「標準化されたマニュアル動画」であり、
   歯抜けの完成動画を黙って出すのは成果物の標準性を壊す。撮影漏れはトリガー時に
   明示して撮影へ差し戻すのが正しい導線（チケット消費前に fail-fast できる利点も）
5. **尺上限 guard（§10.8-1: レンダ 30 分未満 = TTL 内 commit）**: 採用テイク
   `duration_ms`（NULL は保守的に config の既定尺で代用）+ 静止画 `static_display_seconds`
   の合計が config `render_max_total_source_ms`（初期 20 分）超過 → **422**
   （「動画を分割してください」）。クライアント申告値ベースのソフトゲートで、
   ハード保証はジョブ timeout（§6）が担う
6. **残高事前チェック**: `balance(org) < COST_RENDER(=3)` → **402**（reserve はジョブ開始時 = §10.5）
7. `RenderJob` を relation 経由で作成（kind=render, status=queued,
   **scenario_version = 行ロック下の `$locked->scenario_version` をスナップショット**）
8. manual status を `ready → rendering` に forceFill（enqueue 時点で遷移させ、
   シナリオ保存 / テイク採用・削除を既存の rendering guard（409）で排他。
   `ScenarioService::save()` / `CaptureTakeService::adopt()`・`delete()` に実装済みであることを
   確認済み = 補強不要。Feature テストで整合を固定）
9. commit 後に `RunManualRender::dispatch($job->id)`

**preview（チケット非消費・status 遷移なし）**:
- 行ロック + guard: `status ∈ {ready, published}`（analyzing/rendering は 409、draft は
  cuts 不在のため 422「シナリオがありません」）。**manual status は変更しない**
  （プレビューは編集中確認の中核 UX。編集を妨げない = doc/09 §9.7）
- preview 冪等: 同一 manual の in-flight な `kind=preview` job が存在 → 409
- 採用テイク欠落は**許容**（欠落カットはプレースホルダ映像（黒背景）+ 字幕で合成 =
  doc/02「テイク未登録でもプレビュー再生できる」）
- チケット関連の検査・予約は一切行わない（COST=0。乱用防止は in-flight 1 本 +
  route rate limit（throttle）で担保）
- scenario_version はスナップショットする（進行中に編集されたら「その時点の版」の
  プレビューであることを応答に示せる。preview は status を持たないため編集と並走する）

org 導出は `$project->organization`（HasOneThrough）。payload のチケット/org 値は受けない。
`RenderConflictException`（新設。`AnalysisConflictException` と同型: `RenderConflictType`
enum + 専用 Resource で 409/422 を返す）。402 は既存 `InsufficientTicketsException::render()`
（T003 で expectsJson → 402 対応済み）をそのまま使う。

### 5. レンダジョブ: `RunManualRender` + `RenderPipeline`

**`App\Jobs\Manual\RunManualRender`**（ShouldQueue。`RunManualAnalysis` と同型）:
- payload は **`renderJobId: int` のみ**（payload 不信任）
- `$tries = 1`（§10.8-1）、専用 queue connection **`database-render`**（queue=render）を新設
- **時間 budget（§10.8-1: TTL=30 分内に commit）**:
  `job timeout (1500s = 25 分) < queue retry_after (1680s) < 予約 TTL (1800s)`。
  連鎖は `RenderTimeBudgetInvariantTest`（Architecture テスト。
  `AnalysisTimeBudgetInvariantTest` と同型）で CI 固定
- `failed(Throwable)` は最終防衛線として `RenderJobService::failJob()` を冪等に呼ぶ

**`App\Services\Render\RenderPipeline`**（本体。`AnalysisPipeline` と同型の状態機械）:

```
run(int $jobId):
  1. startJob tx { job 行ロック。status !== queued なら no-op return（重複配送 guard）
       kind=render のみ: ensureReservation(job, org)   ← §10.8-1（AnalysisPipeline と同一手順:
         有効な Reserved は再利用 / 失効 Reserved は明示 release して新規 reserve 付け替え /
         なしは新規 reserve。残高不足 InsufficientTicketsException → catch → failJob）
       status=running, step=compose, progress=5 }
  2. マニフェスト構築 tx（読み取り一貫性の確定点）:
     tx { manual 行ロック（$project->manuals() 経由再解決）
          guard: manual.scenario_version === job.scenario_version（不一致 → 例外 → failJob。
            render では rendering guard により起き得ないが preview では起きうる =
            「トリガー後〜開始前」に編集された preview は古い版を黙って出さず fail する）
          cuts を表示順（step の sort_order → 直後にその points を sort_order 順）でロード、
          各 cut の採用テイク（video_path / duration_ms）・material_type・
          static_display_seconds・subtitle_primary / subtitle_secondary を
          RenderManifest DTO（in-memory・readonly）に確定 }
     以後 ffmpeg 実行中に cuts / takes が変わっても参照しない（version 固定の実体）
  3. compose 段（cut ごと。DB 外・ロック外）:
     - S3 から採用テイクを一時ディレクトリへダウンロード
     - クリップ正規化 + 字幕焼き込み: H.264/AAC・解像度/fps は config 固定値へ再エンコード
       （v1 Take は正規化未実施のため必須）。字幕は **焼き込み（burn-in）を採用**:
       成果物の使命は「どこでも同じに再生される標準化マニュアル動画」であり、
       サイドカー字幕はプレーヤー依存で表示が保証されない。subtitle_secondary（完全情報）を
       画面下部に常時表示、subtitle_primary（名称・数値）があれば上部に強調表示
     - material_type=still: 採用テイク動画の先頭フレームを静止画化し
       static_display_seconds 尺で保持（+ 無音声トラック）
     - preview のプレースホルダ cut（採用テイクなし）: 黒背景 + 字幕を
       config `preview_placeholder_seconds`（初期 3 秒）尺で生成
     - クリップごとに ffprobe で実尺を取得（cut_length_ms の派生元）
     - progress を compose 済みクリップ数比で 5→80 に更新（表示用の単発 update）
  4. concat 段: 正規化済みクリップを concat → 最終 mp4。step=concat, progress=90
  5. S3 アップロード: output キーは version 付きで再実行安全（doc/09 §9.7）
     render:  projects/{p}/manuals/{m}/renders/v{scenario_version}-{jobId}.mp4
     preview: projects/{p}/manuals/{m}/previews/v{scenario_version}-{jobId}.mp4
  6. finalize（terminal tx。AnalysisPipeline::finalize と同型の原子化）:
     tx { job 行ロック → guard: status === running（stale 回復 cron 先勝ちなら何もしない =
            無課金 succeeded / 課金済み failed を構造的に排除）
          manual 行ロック（$project->manuals() 経由）
          kind=render のみ:
            - guard: manual.status === rendering かつ
              manual.scenario_version === job.scenario_version（防御的再検証）
            - cuts.cut_length_ms（manifest の実測値）+ manual.total_length_ms を反映
            - TicketLedgerService::commit(reservation)（非 Reserved は LogicException →
              terminal tx 全体 rollback → failJob。§10.8-1「commit は Reserved のみ」）
            - manual status: rendering → published
            - 旧 render 出力（直前の succeeded render job の output_path）があれば
              commit 後に DeleteTakeObjectsJob::dispatch([旧キー])（media queue・冪等・
              ベストエフォート掃除。preview 出力も同様に旧 preview を掃除）
          job: status=succeeded, progress=100, output_path 保存 }
  X. catch (Throwable): report + RenderJobService::failJob(job, ユーザー向け要約)
     + 一時ディレクトリ掃除（finally）
```

**`RenderJobService::failJob()`**（`AnalysisJobService::failJob()` と同型・冪等）:
- job 行ロック + terminal guard（succeeded/failed は no-op）→ status=failed + error
- kind=render のみ: manual 行ロック → `status === rendering` のときのみ **ready へ復帰**
  （render は ready からしか始まらないため cuts は必ず存在する。preview は status を
  触っていないので復帰なし）
- 予約が Reserved なら release（LogicException は握って冪等 = 例外時 release の保証）

**stale 回復**: `render:recover-stale-jobs` console command（5 分毎 schedule。
`analysis:recover-stale-jobs` と同型・§10.8 方針どおり個別実装）。queued/running が
config `render_stale_after_minutes`（30 分）超過で failJob。安全性の本体は閾値ではなく
finalize の job 行ロック + running guard（誤回収された生存 pipeline は commit しない）。

**ロック順**（`AnalysisPipeline` のグローバル順に render_jobs を追加。全経路で逆順取得なし）:
`render_jobs → video_manuals → ticket_reservations → organizations`
（analysis_jobs と render_jobs は同一 tx 内で両方ロックする経路が存在しないため同順位で共存可）。

### 6. ffmpeg の隔離: `VideoComposer` 抽象 + Process ラッパ

doc/09 §9.7 の方針どおり **`VideoComposer` インターフェース**の背後に実装を隠す:

- `App\Services\Render\VideoComposer`（interface）:
  `compose(RenderManifest $manifest, TemporaryDirectory $work): ComposedVideo`
  （ComposedVideo = ローカル最終 mp4 パス + クリップ実測尺 list の DTO）。
  将来 AWS MediaConvert 等への差し替え点
- `App\Services\Render\FfmpegVideoComposer`（v1 実装）: ffmpeg/ffprobe コマンド組み立て。
  実行は **Laravel の `Process` facade** 経由（`Process::fake()` でテスト可能 =
  「専用 Service/Process ラッパに隔離」の要件を框架の公式作法で満たす。自前ラッパ不要）。
  binary パスは config（`render_ffmpeg_binary` / `render_ffprobe_binary`）
- S3 入出力は `RenderPipeline` 側（`Storage::disk('s3')` + テスト `Storage::fake()`）。
  composer はローカルファイルのみ扱う（責務分離: composer は「合成」だけ）
- 字幕テキストは ffmpeg フィルタへ**引数エスケープ経由でのみ**渡す（drawtext/subtitles
  フィルタのメタ文字注入対策。シェル文字列連結はしない = Process の配列引数）。
  フォントは日本語対応フォント（Noto Sans CJK）を config で明示

### 7. ポーリング: `GET .../render-jobs/{renderJob}`

- `RenderJobData` DTO + `RenderJobResource`（`AnalysisJobData`/`AnalysisJobResource` と同型）:
  `id / kind / status / step / progress / error / manual_status / output_url`
- `output_url` は **status=succeeded のときのみ** `temporaryUrl`（短 TTL 署名 URL）を含める
  （preview の再生導線。ポーリングは succeeded で停止するため署名 URL 生成は終端 1 回）。
  render の完成動画 DL はダウンロード route を正とし、output_url はプレビュー再生用
- 認可は `view`（撮影者も read 可）。cross-manual job id は scopeBindings + inline 再検査で 404

### 8. フロントエンド（Inertia + Svelte 5 runes、DS token、Lucide、disabled 禁止）

- `Manuals/Show.svelte` + 新 feature component `features/manual/RenderPanel.svelte`
  （`AnalysisPanel.svelte` の polling パターンを踏襲）:
  - ready + 編集権限: 「完成動画を生成」ボタン（**disabled にしない**。採用テイク欠落 /
    残高不足 / 尺超過は押下時にサーバの 422/402 メッセージを表示）+
    「プレビュー生成」ボタン
  - rendering: 進捗表示（step ラベル: 合成中/連結中 + progress bar）。
    `GET .../render-jobs/{id}` を 2.5 秒間隔でポーリング、succeeded → `router.reload()`、
    failed → エラー表示 + 再実行導線
  - published: 「ダウンロード」リンク（download route への通常遷移）+ 完成動画のインライン
    再生（output_url）。編集済みで ready に戻った場合は「再生成が必要」の案内
  - preview 完了: モーダル or インラインで `<video src={output_url}>` 再生
- Show props に `render: { job: RenderJobProps | null }` を追加。
  `resources/js/types/manual.ts` に `RenderJobProps` / `RenderStep` / `RENDER_STEP_LABELS` 追加

### 9. 設定・運用

- `config/manual.php` 追記: `render_ticket_cost => 3`（COST_RENDER。§10.5 初期固定値。
  尺/解像度係数化は後続）、`render_stale_after_minutes => 30`、
  `render_max_total_source_ms => 1_200_000`、`preview_placeholder_seconds => 3`、
  `render_resolution => '1920x1080'`・`render_fps => 30`、
  `render_ffmpeg_binary` / `render_ffprobe_binary` / `render_subtitle_font`
- `config/queue.php` に `database-render` connection（queue=render, retry_after=1680）。
  運用契約: worker に `php artisan queue:work database-render` を必須登録
  （docs/architecture.md へ追記。ffmpeg バイナリは worker ホスト要件）
- schedule: `render:recover-stale-jobs` 5 分毎

## 期待効果

- **使命の完結**: SOP → シナリオ → ナビ撮影 → **完成マニュアル動画（mp4）** の
  エンドツーエンドが成立する（AI-CUE の最終成果物。「編集ゼロ」の実体 =
  合成・字幕・連結を全自動化）
- プレビュー（チケット非消費）で「撮影前にシナリオ段階の確認」ができ、
  撮り直しコストを下げる（doc/02 のプレビュー UX）
- チケット 2 フェーズ + 予約冪等キー + version スナップショット + rendering 排他により、
  再試行・並行トリガー・TTL 切れ・編集競合のそれぞれで「二重課金しない / 完成物が
  編集中シナリオと食い違わない / rendering で詰まない」方向へ収束（セキュリティ不変条件 7）

## 実装方針（概要）

| レイヤ | 追加/変更 |
|---|---|
| DB | `render_jobs` migration、`RenderStep`/`RenderKind` enum、`RenderJobFactory` |
| Model | `RenderJob`（$fillable なし・FK 保護）、`VideoManual::renderJobs()` |
| Routes | render POST / preview POST / render-jobs GET / download GET（scopeBindings + IDOR inventory + throttle） |
| Controller | `ManualRenderController`（store/preview/show）、`ManualDownloadController`（show） |
| Policy | `VideoManualPolicy::render` / `::download`（親委譲） |
| Service | `RenderJobService`（trigger/triggerPreview/failJob/recoverStale）、`RenderPipeline`、`VideoComposer` interface + `FfmpegVideoComposer`、`RenderManifest`/`ComposedVideo` DTO |
| Job | `RunManualRender`（tries=1, timeout=1500, 専用 connection database-render） |
| Console | `render:recover-stale-jobs`（5 分毎） |
| Exception | `RenderConflictException` + `RenderConflictType` + Resource |
| Front | Show の RenderPanel（生成/プレビュー/進捗/DL）、TS 型 |
| Config | manual.php（COST_RENDER=3 ほか）、queue.php（database-render） |
| Test | Feature（下記）+ Architecture（IDOR inventory・ScenarioWritePathInventoryTest 追記・RenderTimeBudgetInvariantTest）+ Vitest |
| Docs | doc/10 §10.1（kind/scenario_version）・§10.3（render-jobs URI）、docs/architecture.md、docs/factories.md |

**テストの重点（実 ffmpeg・実 S3・実キューに触れない）**:
`Process::fake()`（ffmpeg/ffprobe）+ `Storage::fake()` + `Queue::fake()`/sync。
- render 成功: status ready→rendering→published、output_path 記録、cut_length_ms/total_length_ms 反映
- **version 固定**: preview トリガー後に scenario 保存 → マニフェスト構築で version 不一致 fail。
  render 中の scenario 保存 / adopt / take 削除は 409（既存 guard との整合）
- published→ready 戻し後の再レンダは明示トリガーのみ（published 直接 render は 409）
- チケット 2 フェーズ: 再試行で二重予約しない（予約再利用）、TTL 失効 Reserved の
  release + 新規付け替え、失敗時 release、commit は Reserved のみ（非 Reserved は
  rollback + failJob）、**preview は予約ゼロ**（台帳・予約テーブル無変化）
- render 冪等: 同時 in-flight 1（render/preview は互いに独立）、failed のみ再実行可
- 残高不足 402（トリガー時）/ 採用テイク欠落 422 / 尺超過 422
- download: published + output_path で署名 URL redirect、未完成 404、lang≠ja 422、撮影者 403
- 認可: 撮影者は render/preview/download 不可（403）、ポーリングは可
- cross-org / cross-project / cross-manual 404（IDOR inventory）
- 保護キー直送 422、stale 回復（queued/running 30 分）、failJob の status 戻し + error 記録

## 制約・前提

- **doc/10 §10.8 が §10.1〜§10.7 に優先**（予約冪等キー・tries=1・version 固定・render 冪等）
- `TicketLedgerService` は**内部変更しない**（TTL 延長 API は追加しない = §10.8 保留項。
  30 分内 commit は timeout 1500s + 尺上限ソフトゲートで担保）
- 共有ロック規約: status / scenario_version / cuts（cut_length_ms 含む）の全書き込みは
  VideoManual 行ロック下 + `ScenarioWritePathInventoryTest` inventory 登録
  （`RenderJobService::trigger` / `::failJob` / `RenderPipeline::finalize` を追加）
- AnalysisJob/RenderJob は共通抽象化しない（§10.8。同型コードの重複は意図的）
- ffmpeg は worker ホストに前提として存在（Docker image 要件。テストでは Process::fake）

## スコープ外（後続フェーズ）

- 多言語（feature_multilang: 字幕言語別出力・lang 切替）、TTS 音声（TtsProvider 差し込み）
- COST_RENDER の尺/解像度係数化（v1 は固定 3）
- サイドカー字幕・画質バリアント・レンダ出力の容量 Quota 計上（v1 は takes のみ計上）
- 分割 job / TTL 延長による 30 分超レンダ（v1 は尺上限で回避）
- レンダ進捗の WebSocket/SSE push（v1 はポーリング）
- Take アップロード時の事前正規化パイプライン（compose 段の再エンコードで代替）

