## アプリの使命（North Star / AGENTS.md より）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`（招待送信等は `back()->with(...)`）
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する）

## セキュリティ不変条件（抜粋）

1. tenant キー不信: ownership/actor/tenant キーを payload から受け取らない
2. 子は親に属する: nested route の不整合は認可より前に 404
3. cross-org 不可: 組織を跨ぐ read/write をしない（relation / org-scoped 解決経由のみ）
5. 権限判定は `laratrust_team_id` を明示
- シナリオ整合の共有ロック規約: `cuts` / `scenario_version` / `status` を書く全経路は対象 VideoManual 行を lockForUpdate した同一 tx 内で反映（新経路は inventory 登録必須）

【思考原則 — 全議論に適用】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵（Laravel/Svelte エコシステム）を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: あなたの役割

あなたは Web アプリケーション（Laravel 12 + Svelte 5 + Inertia.js）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか（特にシナリオ整合の共有ロック規約・cross-org/IDOR・SOP 複製しない判断の妥当性）
6. スコープの適切さ: 過大または過小になっていないか（v1 スコープ: 字幕のみ/TTS後回し/PWA/ffmpeg合成/単一Default Project を尊重）
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下、レビュー対象の概念設計本文）

（注: 現行コードの要点）
- `VideoManual` モデル: `$fillable=['title']`。`project_id/created_by/category_id` は保護キー（forceFill/relation 経由）。`status` cast=VideoManualStatus、`scenario_version` int。
- `VideoManualService::create()` は `Project::whereKey()->lockForUpdate()` で project 行をロック → `manuals()->make(['title'=>..])` + `forceFill(['created_by'=>..])->save()`、category は locked project から再解決して associate。
- `VideoManualService::delete()` は配下 takes/source_documents の file_path を集め tx 成功後に `DeleteTakeObjectsJob` へ渡し S3 実体削除。
- `Cut` モデル: `$fillable` に本文 8 フィールド。`video_manual_id/parent_cut_id/adopted_take_id` は保護キー。step/point 二層（type=Step/Point、point の parent_cut_id が step を指す）。
- `ScenarioService::save()` が共有ロック規約の準拠実装（project->manuals()->lockForUpdate()）。
- `VideoManualPolicy`: view=projectPolicy->view（撮影者可）、update/create/delete=projectPolicy->update（編集者のみ）。
- 既存 route はすべて `POST /projects/{project}/manuals`（store）等、`{manual}` は scopeBindings で `$project->manuals()` 解決。`NestedRouteIdorDefenseTest` に inventory。
- `title` column は string(200)。status default 'draft'、scenario_version default 0。
- `StorageUsageService` の Quota 占有量集計は takes + reservations のみ（source_documents は Quota 非対象）。

---

（概念設計本文をそのまま貼付）

# 概念設計: manual-duplicate（マニュアル(シナリオ)の別名保存/複製）

## 背景・課題

ユースケース・カバレッジ監査ギャップ #4（Medium）。

`doc/04_PCサイト機能仕様.md` L43 は編集画面の保存/破棄コントロール群として **「別名保存」で新タイトル・カテゴリの別動画として複製** する導線を規定している。しかし現状のコードベースには複製の route / Controller / Service / UI が一切存在しない（`duplicate` / `copy` / `clone` route ゼロ。`grep` で確認済み）。

VideoManual の CRUD は `create / show / edit / update / destroy` のみで、既存シナリオ（cuts）を雛形に別動画を起こす手段がユーザーに提供されていない。これは「思考ゼロ・編集ゼロ」で標準化マニュアルを量産する使命（同種作業のバリエーション動画を作る）に対する明確な機能欠落である。

## 改善アイデア

既存 VideoManual + その cuts（シナリオ）を **雛形** に、新規 VideoManual を複製する機能を追加する。

- **複製する**: タイトル（「〜のコピー」）、category、cuts（scenario）ツリー全体（step/point 二層・sort_order・本文フィールド）
- **複製しない**: takes / adopted_take_id / render 成果物（RenderJob）/ AnalysisJob（= 新規撮影・再合成前提）
- **リセットする**: status=draft（DB default）、scenario_version=0（DB default）、cut_length_ms=null（レンダ由来のため）
- **source document（SOP）**: **複製しない**（下記「実装方針」で設計判断の根拠を明示）

複製後は新マニュアルの詳細画面へ遷移する。

## 期待効果

- **使命への貢献**: 同一/類似作業の別バリエーション動画を、シナリオ設計コストゼロで量産できる。「標準作業を起点に AI が教材設計する」成果物（シナリオ）を再利用資産にする。
- **仕様欠落の解消**: doc/04 が要求する「別名保存」導線を実装し、ユースケース・カバレッジのギャップ #4 を閉じる。
- **既存フローとの整合**: 複製後の新マニュアルは通常の draft マニュアルと同一状態のため、既存の撮影/レンダフローにそのまま乗る（新しい状態機械を増やさない）。

## 実装方針（概要）

### backend

1. **route**: `POST /projects/{project}/manuals/{manual}/duplicate`（name `projects.manuals.duplicate`）を既存 `Route::scopeBindings()` グループ内に追加。`{manual}` は `$project->manuals()` 経由解決（子→親不整合は認可より前に 404）。`NestedRouteIdorDefenseTest` の inventory に登録必須。
2. **Controller**: `VideoManualController::duplicate()` を追加。既存 store/destroy と同じく `resolveOrganizationProject`（cross-org 404）→ `Gate::authorize('duplicate', $manual)` → Service 委譲 → **新マニュアル show への `redirect()->route(...)->with('success', ...)`**（Inertia 操作 POST の慣行。`response()->json()` 直書き・array 返却はしない）。
3. **Policy**: `VideoManualPolicy::duplicate(User, VideoManual): bool` を追加。複製 = 「元を閲覧でき」かつ「同一プロジェクトに新規作成できる」= プロジェクト編集者のみ（`projectPolicy->update`）。撮影者（project_member）は不可。
4. **Service**: `VideoManualService::duplicate(Project $project, VideoManual $source, int $userId): VideoManual`。1 トランザクション内で:
   - `$locked = Project::whereKey(...)->lockForUpdate()->firstOrFail()`（既存 create/updateMeta と同じ category 整合ロック順）
   - `$lockedSource = $locked->manuals()->whereKey($source->id)->lockForUpdate()->firstOrFail()`（子→親再解決 = cross-project 404 + シナリオの一貫読み取りロック。**シナリオ整合の共有ロック規約** に沿い、元 manual 行をロックした同一 tx 内で cuts を読む）
   - 新 manual を `$locked->manuals()->make(['title' => コピー名])` + `forceFill(['created_by' => $userId])->save()`（status/scenario_version は DB default = draft/0）
   - category は元の `category_id` を **ロック済み project から再解決** して associate（元と同じ分類。cross-project は 404）
   - cuts 複製: 元の step を sort_order 順に複製 → 各 step 配下 point を複製。`type`/`sort_order` は元値を forceFill、`parent_cut_id` は **旧 step id→新 step id のマップで張り替え**、本文（scene/shot_type/shooting_point/narration/subtitle_primary/subtitle_secondary/material_type/static_display_seconds）は fill。`adopted_take_id`=null（default）、`cut_length_ms`=null（レンダ由来はリセット）
   - タイトルは column 長（`title` string(200)）を超えないよう「〜のコピー」付与後に必要なら切り詰める
5. **書き込み経路の inventory**: 本メソッドは cuts を書くが、`scenario_version` / `status` の**リテラル書き込みは行わない**（新規行は DB default 依存）ため `ScenarioWritePathScanner` の検出 1/2 は素通りする（既存 `create()` と同じ）。共有ロック規約の観点で新経路を `docs/architecture.md` の書き込み経路表へ追記する。

### frontend

- **配置**: `Manuals/Show.svelte`（詳細画面）の編集/削除アクション群に「複製」ボタン（`canManage` のみ表示）を追加。押下で `ConfirmDialog`（既存 organism）→ 確認で `router.post('/projects/{id}/manuals/{id}/duplicate')`。成功時は Inertia redirect が新マニュアル show へ自動遷移。
  - doc/04 は 別名保存 を編集画面（Manuals/Edit）の保存/破棄群に位置づけるが、v1 は詳細画面（Show）配置を採る（編集モードに入らず一覧→詳細から複製でき、既存の ConfirmDialog/canManage 基盤を再利用でき最小差分）。
- **型波及**: 新 Props は増えない（`canManage` は既存）。TypeScript 型変更なし。

## 制約・前提

- **セキュリティ不変条件**: cross-org read/write 不可（`resolveOrganizationProject`）、子は親に属する（`$project->manuals()` 経由 = 認可より前に 404）、tenant/所有権キー不信（project_id/created_by/category_id は payload から受けない = forceFill/relation 経由）。IDOR inventory 登録必須。
- **シナリオ整合の共有ロック規約**（AGENTS.md ドメイン規約 1）: cuts を書く経路のため、元 manual 行を lockForUpdate した同一 tx 内で複製する。新経路として architecture.md に登録。
- **DTO/JsonResource/直 array 禁止**: 応答は既存 store と同じ Inertia redirect のため新規 DTO/Resource 不要（array/json 直返しなし）。
- **PHPStan L10 / Pest / RefreshDatabase 並列 / Factory**: 既存規約に従う。
- **過剰実装禁止**: 状態機械・新テーブル・新 DTO を増やさない。cuts 件数は有界（step≤100×point≤20）のため chunk 化しない（既存 ScenarioService と同方針）。

## source document（SOP）を複製しない設計判断

「参照」「複製」「複製しない」の 3 案を比較し **複製しない** を採る:

- **参照（file_path 共有）は不可**: `VideoManualService::delete()` は削除時に配下 source_documents の `file_path` を集め `DeleteTakeObjectsJob` に渡してストレージ実体を消す。file_path を 2 マニュアルで共有すると、片方の削除がもう片方の SOP 実体を消す（orphan/データ破壊）。SOP は追記型 immutable の監査証跡でもあり参照共有は設計と矛盾。
- **ファイル複製（Storage::copy + 新行）は過剰**: トランザクション内での S3 ファイル複製・失敗時の孤児処理を新たに抱える。複製の目的は **シナリオ（cuts）を雛形に別動画を作る** ことであり、cuts は既に materialize 済みのため再解析用 SOP は不要。
- **複製しないで十分**: 「cuts はあるが source document は無い」状態は、自作シナリオ経路（`ScenarioService` が SOP 無しで draft→ready を許可）で既にサポート済みの正当な状態。新マニュアルは `hasDocument=false` で始まり、必要なら通常の SOP アップロード導線で後から SOP を足せる。

→ v1 は **cuts のみ複製、source document は複製しない**。SOP を引き継ぐ file-copy は将来の拡張（out-of-scope）として明記する。

## スコープ外（v1 で扱わない）

- source document（SOP）ファイルの引き継ぎ（上記の理由。将来拡張）
- takes / adopted_take_id / RenderJob / AnalysisJob の複製（新規撮影・再合成前提）
- 別プロジェクトへの複製（v1 は単一 Default Project。route は同一 {project} 内複製のみ）
- 複製時のタイトル/カテゴリ編集ダイアログ（doc/04 の「新タイトル・カテゴリ」は複製後の通常編集で対応。複製アクション自体は 1-click + confirm に留める）
- TTS/音声（v1 スコープ外・字幕のみ）

