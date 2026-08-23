Round 2 の全指摘（Critical 1 + Warning 4 + 不一致 1）に対応しました。対応内容と改訂後の
概念設計全文を示します。再レビューをお願いします。

## 対応マトリクス（Round 2 指摘 → 対応）

1. [Critical] org preview 上限の TOCTOU（manual 間を直列化できない）→ **対応**。
   triggerPreview は manual 行ロック後に **Organization 行を lockForUpdate()**（reserve の
   残高判定と同じ直列化手法）し、ロック下で in-flight preview 数を検査してから job 作成。
   取得順 `video_manuals → organizations` はグローバルロック順
   （render_jobs → video_manuals → ticket_reservations → organizations）の部分列で
   循環待ちなしと設計に明記。「異なる manual への並行 trigger でも上限 3 を超えない」
   Feature テストを追加
2. [Warning] IDOR inventory「4 route」表記 → **対応**。route 名 5 本を列挙して固定
   （projects.manuals.render / .preview / .render-jobs.show / .render-jobs.playback / .download）
3. [Warning] 世代 1 保持と playback の不整合 → **対応**。playback の 302 条件を
   「同 manual・preview の最新 succeeded job かつ output_path 非 NULL」に強化し、
   削除 job が S3 削除後に旧 job の output_path を NULL 化（削除済み実体を指し続けない。
   冪等: NULL なら no-op）
4. [Warning] DeleteRenderOutputsJob の過大な削除権限 → **対応**。payload を render job id に
   変更。handle は relation 経由で再解決し「最新 succeeded ではない（世代交代済み）」+
   「output_path が manual 配下の期待 prefix」を検証してから削除 + NULL 化
5. [Warning] version 不一致の自由文判定 → **対応**。`render_jobs.error_code`（nullable）+
   backed enum `RenderErrorCode`（scenario_version_changed / timeout / internal）を追加。
   DTO/Resource/TS に error_code を含め、フロント CTA は literal union で分岐
6. テスト欄の stale 閾値不一致 → **対応**。「queued=10 分 / running=30 分」に修正

## 改訂後の概念設計（全文）

# 概念設計: render（レンダ: 採用テイク合成 → 完成 mp4。ffmpeg + チケット 2 フェーズ）

作成: 2026-07-11 / 対象アプリ: AI-CUE (/workspace)
ステータス: ドラフト（Codex 概念レビュー Round 2 反映済み）
改訂: Round 1 反映（ポーリングと成果物アクセスの権限分離・preview 再生の専用 route・
queued 短 SLA 回復・throttle/同時実行上限の契約化・出力保持ポリシー・専用削除 job）、
Round 2 反映（org preview 上限の Organization 行ロック直列化・playback の最新世代条件・
削除 job の payload を job id + relation 再解決に変更・error_code enum・IDOR route 名一覧）

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
| output_path | string NULL | S3 キー（succeeded 時に設定。**世代交代で削除後は NULL 化**） |
| error | text NULL | 失敗理由（ユーザー向け要約・表示用） |
| error_code | string enum NULL | `RenderErrorCode`（失敗種別の型付き判別子。Round 2 反映） |
| timestamps | | |

- `result_json` は analysis 専用のため持たない（§10.1 の共通表は「等」表記。レンダに中間成果なし）
- **`kind` / `scenario_version` は §10.1 の列表に無い追加列**。`kind` は §10.8-8
  「preview と render は別操作種別（in-flight 判定・課金有無が異なる）」を 1 テーブルで
  表現する最小手段（preview 専用テーブルはカラムがほぼ同一で過剰）。`scenario_version` は
  §10.8-6 のスナップショット要件の実体。**本設計の承認と同時に doc/10 §10.1 を更新**する
- 新 enum: `App\Enums\Manual\RenderStep`（compose/concat）、`App\Enums\Manual\RenderKind`
  （render/preview）、`App\Enums\Manual\RenderErrorCode`
  （`scenario_version_changed` / `timeout` / `internal` など失敗種別。フロントの CTA 分岐は
  自由文 error でなく **error_code の literal union** で行う = 文言変更で壊れない）。
  `JobStatus` は既存を共用（doc/10 §10.2 どおり）
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
| GET | `.../manuals/{manual}/render-jobs/{renderJob}` | job 状態ポーリング（**成果物 URL は含めない**） | 200 + RenderJobResource |
| GET | `.../manuals/{manual}/render-jobs/{renderJob}/playback` | **preview の再生**（kind=preview の succeeded のみ。編集者専用） | 302（S3 署名 URL） |
| GET | `.../manuals/{manual}/download?lang=` | 完成 mp4 の署名 URL へ redirect | 302（S3 署名 URL） |

**ポーリングと成果物アクセスの権限分離（Round 1 Critical 反映）**: ポーリング（`view` =
撮影者も可）は status/step/progress の進捗情報のみを返し、**署名 URL を一切含めない**。
成果物へのアクセスは専用 route + 専用 ability に分離する:
- preview 再生 = `playback` route（`render` ability。preview は編集者専用機能）。
  302 を返す条件は「**当該 job が同 manual の preview の最新 succeeded job であり
  output_path が非 NULL**」（Round 2 反映: 世代 1 保持と整合。旧世代 job は実体削除済みの
  可能性があるため 404）。kind=render の job / 未完了 job も 404（download route が正）
- 完成 mp4 = `download` route（`download` ability）。**v1 の完成動画取得は download route のみ**
  （published のインライン再生は権限モデル簡潔化のため v1 スコープ外。Round 1 Warning 反映）

- 全て既存 `.../manuals/{manual}` 系と同じ `Route::scopeBindings()` グループ +
  `NestedRouteIdorDefenseTest` inventory 登録。**登録する route 名（5 本全て）**:
  `projects.manuals.render` / `projects.manuals.preview` /
  `projects.manuals.render-jobs.show` / `projects.manuals.render-jobs.playback` /
  `projects.manuals.download`（Round 2 反映: 件数でなく名前で固定し登録漏れを防ぐ）。
  cross-org 404 は既存 `EnsureProjectBelongsToRouteOrganization`
  （`project.in-route-org`）+ inline guard で担保
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
- **abuse 耐性の契約（Round 1 Warning 反映）**: render/preview の POST 2 route に named
  rate limiter `render-trigger`（`RateLimiter::for`。**user id + org id 単位で 6 回/分**）を
  middleware として固定する。加えて **org 単位の同時 preview 上限**（config
  `render_max_inflight_previews_per_org => 3`）をトリガー tx 内で検査し、超過は 409
  （`RenderConflictType::OrgPreviewLimit`）。preview はチケット非消費のため、
  この 2 段（レート + 同時実行数）が無料 ffmpeg 実行の負荷上限を構造的に決める

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
- **org 同時 preview 上限は Organization 行ロックで直列化（Round 2 Critical 反映）**:
  manual 行ロックは manual 間の競合を防がないため、上限検査 + job 作成の前に
  **Organization 行を `lockForUpdate()`** し（`TicketLedgerService::reserve` の残高判定と
  同じ直列化手法）、ロック下で org 全体の in-flight preview 数を数えて超過なら 409
  （`RenderConflictType::OrgPreviewLimit`）。取得順は `video_manuals → organizations` =
  既存グローバルロック順（… → video_manuals → ticket_reservations → organizations）の
  部分列で循環待ちを作らない。異なる manual への並行 trigger でも上限を超えないことを
  Feature テストで固定
- 採用テイク欠落は**許容**（欠落カットはプレースホルダ映像（黒背景）+ 字幕で合成 =
  doc/02「テイク未登録でもプレビュー再生できる」）
- チケット関連の検査・予約は一切行わない（COST=0。乱用防止は in-flight 1 本 +
  org 同時実行上限 + `render-trigger` rate limiter で担保 = §2 の契約）
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
            「トリガー後〜開始前」に編集された preview は古い版を黙って出さず fail する。
            失敗種別は **error_code=`scenario_version_changed`**（型付き判別子。Round 2 反映）
            + 表示用 error 文言「編集中にシナリオが変更されたため、プレビューを作り直して
            ください」。フロントは error_code で「作り直す」CTA を出す =
            単なる失敗扱いにしない（Round 1 Warning 反映））
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
          共通（render/preview）: 旧世代（同 manual・同 kind の直前 succeeded job）が
            あれば commit 後に **`DeleteRenderOutputsJob::dispatch(旧 job id)`** を積む
          job: status=succeeded, progress=100, output_path 保存 }
     **`DeleteRenderOutputsJob`（Round 1/2 Warning 反映）**: `DeleteTakeObjectsJob` は
     Take 概念の job のため流用しない（「似ているからで統合しない」）。さらに
     **payload は S3 キーでなく render job id**（任意キー削除の過大権限を排除）:
     handle は job 行を再ロードし、(a) 当該 job が「同 manual・同 kind の最新 succeeded」で
     **ない**こと（= 世代交代済み）を relation 経由で再検証、(b) output_path が manual 配下の
     期待 prefix（`projects/{p}/manuals/{m}/…`）であることを検証してから S3 削除 +
     **job の output_path を NULL 化**（削除済み実体を指し続けない = playback 404 と整合。
     media queue・tries=3・冪等: output_path NULL なら no-op）
     **出力保持ポリシー（Round 1 Warning 反映）**: render / preview とも
     「**最新 succeeded 1 世代のみ保持**」を契約とする。旧世代は finalize 時の上記掃除で
     消え、失敗時は X. の後始末で S3 に成果物を残さない（Quota 計上外でも肥大しない）
  X. catch (Throwable): report + RenderJobService::failJob(job, ユーザー向け要約)
     + 一時ディレクトリ掃除（finally）+ **S3 アップロード後の失敗なら当該出力キーを
     ベストエフォート削除**（孤児オブジェクトを残さない）
```

**`RenderJobService::failJob()`**（`AnalysisJobService::failJob()` と同型・冪等）:
- job 行ロック + terminal guard（succeeded/failed は no-op）→ status=failed +
  error（表示文言）+ error_code（`RenderErrorCode`。timeout=`timeout`、
  version 不一致=`scenario_version_changed`、それ以外=`internal`）
- kind=render のみ: manual 行ロック → `status === rendering` のときのみ **ready へ復帰**
  （render は ready からしか始まらないため cuts は必ず存在する。preview は status を
  触っていないので復帰なし）
- 予約が Reserved なら release（LogicException は握って冪等 = 例外時 release の保証）

**stale 回復**: `render:recover-stale-jobs` console command（5 分毎 schedule。
`analysis:recover-stale-jobs` と同型・§10.8 方針どおり個別実装）。**queued と running で
閾値を分ける（Round 1 Warning 反映）**:
- `queued` が config `render_queued_stale_after_minutes`（**10 分**）超過 → failJob
  （dispatch 喪失 / キュー詰まり。render は enqueue 時点で manual を rendering に倒し
  編集を止めるため、queued 滞留は短い SLA で fail させ「何もできない時間」を最小化する。
  遅延配送が後から届いても pipeline 冒頭の queued guard で二重実行にならない）
- `running` が config `render_stale_after_minutes`（30 分）超過 → failJob
  （worker 異常終了。timeout 1500s + マージン）
安全性の本体は閾値ではなく finalize の job 行ロック + running guard
（誤回収された生存 pipeline は commit しない）。

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

### 7. ポーリング: `GET .../render-jobs/{renderJob}`（進捗のみ）

- `RenderJobData` DTO（readonly・kind を判別子に持つ）+ `RenderJobResource`
  （`AnalysisJobData`/`AnalysisJobResource` と同型）:
  `id / kind / status / step / progress / error / error_code / manual_status`
- **署名 URL・output_path はポーリング応答に一切含めない**（Round 1 Critical 反映:
  `view` 権限の応答に成果物アクセスを混ぜない。preview 再生は playback route =
  `render` ability、完成 mp4 は download route = `download` ability に分離）
- TS 側は `kind` を literal union（"render" | "preview"）で持ち、パネルの分岐漏れを型で検出
- 認可は `view`（撮影者も進捗は read 可）。cross-manual job id は scopeBindings +
  inline 再検査で 404

### 8. フロントエンド（Inertia + Svelte 5 runes、DS token、Lucide、disabled 禁止）

- `Manuals/Show.svelte` + 新 feature component `features/manual/RenderPanel.svelte`
  （`AnalysisPanel.svelte` の polling パターンを踏襲）:
  - ready + 編集権限: 「完成動画を生成」ボタン（**disabled にしない**。採用テイク欠落 /
    残高不足 / 尺超過は押下時にサーバの 422/402 メッセージを表示）+
    「プレビュー生成」ボタン
  - rendering: 進捗表示（step ラベル: 合成中/連結中 + progress bar）。
    `GET .../render-jobs/{id}` を 2.5 秒間隔でポーリング、succeeded → `router.reload()`、
    failed → エラー表示 + 再実行導線
  - published: 「ダウンロード」リンク（download route への通常遷移のみ。インライン再生は
    v1 スコープ外 = Round 1 Warning 反映）。編集済みで ready に戻った場合は
    「再生成が必要」の案内
  - preview 完了: `<video src={playback route URL}>` で再生（302 → S3 署名 URL を
    ブラウザが追従。編集者専用画面でのみ表示）。failed の CTA 分岐は
    **error_code の literal union**（`scenario_version_changed` → 「作り直す」CTA）で行う
- Show props に `render: { job: RenderJobProps | null }` を追加。
  `resources/js/types/manual.ts` に `RenderJobProps` / `RenderStep` / `RENDER_STEP_LABELS` 追加

### 9. 設定・運用

- `config/manual.php` 追記: `render_ticket_cost => 3`（COST_RENDER。§10.5 初期固定値。
  尺/解像度係数化は後続）、`render_stale_after_minutes => 30`・
  `render_queued_stale_after_minutes => 10`（queued 短 SLA）、
  `render_max_total_source_ms => 1_200_000`、`render_max_inflight_previews_per_org => 3`、
  `preview_placeholder_seconds => 3`、`render_resolution => '1920x1080'`・`render_fps => 30`、
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
| Routes | render POST / preview POST / render-jobs GET / playback GET / download GET（scopeBindings + IDOR inventory + `render-trigger` rate limiter） |
| Controller | `ManualRenderController`（store/preview/show/playback）、`ManualDownloadController`（show） |
| Policy | `VideoManualPolicy::render` / `::download`（親委譲） |
| Service | `RenderJobService`（trigger/triggerPreview/failJob/recoverStale）、`RenderPipeline`、`VideoComposer` interface + `FfmpegVideoComposer`、`RenderManifest`/`ComposedVideo` DTO |
| Job | `RunManualRender`（tries=1, timeout=1500, 専用 connection database-render）、`DeleteRenderOutputsJob`（media queue・payload=job id・relation 再解決 + prefix 検証 + output_path NULL 化） |
| Console | `render:recover-stale-jobs`（5 分毎。queued=10 分 / running=30 分の 2 閾値） |
| Exception/Enum | `RenderConflictException` + `RenderConflictType` + Resource、`RenderErrorCode` |
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
- 残高不足 402（トリガー時）/ 採用テイク欠落 422 / 尺超過 422 / org preview 上限 409
  （**異なる manual への並行 trigger でも上限を超えない** = Organization 行ロック直列化）
- download: published + output_path で署名 URL redirect、未完成 404、lang≠ja 422、撮影者 403
- playback: **最新 succeeded preview** で署名 URL redirect、旧世代（output_path NULL 化済み /
  最新でない）404、kind=render 404、未完了 404、撮影者 403
- **ポーリング応答に output_path / 署名 URL が含まれない**（権限分離の固定）
- 認可: 撮影者は render/preview/download/playback 不可（403）、ポーリングは可
- 保持ポリシー: 再レンダ成功で旧世代 job id の削除 job が積まれ、実行で S3 削除 +
  旧 job の output_path NULL 化（世代 1 固定）。最新 succeeded を指す job id では no-op、
  prefix 不一致キーは削除しない。失敗時にアップロード済み出力が残らない
- cross-org / cross-project / cross-manual 404（IDOR inventory）
- 保護キー直送 422、stale 回復（**queued=10 分 / running=30 分** の 2 閾値）、
  failJob の status 戻し + error / error_code 記録

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
- published 完成動画のインライン再生（v1 の完成動画取得は download route のみ）
- レンダのユーザー操作キャンセル（queued 短 SLA 回復 + failed 再トリガーで代替）


【出力形式】（Round 1 と同じ）
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には必ず修正提案を添える
- 日本語で出力
