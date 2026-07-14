# 概念設計: manual-duplicate（マニュアル(シナリオ)の別名保存/複製）

## 背景・課題

ユースケース・カバレッジ監査ギャップ #4（Medium）。

`doc/04_PCサイト機能仕様.md` L43 は編集画面の保存/破棄コントロール群として **「別名保存」で新タイトル・カテゴリの別動画として複製** する導線を規定している。しかし現状のコードベースには複製の route / Controller / Service / UI が一切存在しない（`duplicate` / `copy` / `clone` route ゼロ。`grep` で確認済み）。

VideoManual の CRUD は `create / show / edit / update / destroy` のみで、既存シナリオ（cuts）を雛形に別動画を起こす手段がユーザーに提供されていない。これは「思考ゼロ・編集ゼロ」で標準化マニュアルを量産する使命（同種作業のバリエーション動画を作る）に対する明確な機能欠落である。

## 改善アイデア

既存 VideoManual の**保存済み** cuts（シナリオ）を **雛形** に、**新タイトル・カテゴリ**を付けた新規 VideoManual を作る「複製（= doc/04 の別名保存の実体）」機能を追加する。

- **ユーザー入力**: 新タイトル（既定「〜のコピー」プリフィル）+ カテゴリ（既定 = 元の category プリフィル）。doc/04「新タイトル・カテゴリの別動画として複製」を満たす。
- **複製する**: cuts（scenario）ツリー全体（step/point 二層・sort_order・本文フィールド）
- **複製しない**: takes / adopted_take_id / render 成果物（RenderJob）/ AnalysisJob（= 新規撮影・再合成前提）
- **リセットする**: status=draft（DB default）、scenario_version=0（DB default）、cut_length_ms=null（レンダ由来のため）
- **source document（SOP）**: **複製しない**（下記「実装方針」で設計判断の根拠を明示。成功フラッシュで非引き継ぎを明示）

複製後は新マニュアルの詳細画面へ遷移する。

### 「別名保存」との関係（呼称と scope の整理）

doc/04 L43 は編集画面の保存/破棄群に「別名保存 = 新タイトル・カテゴリの別動画として複製」を置く。本設計は複製元を**保存済み**シナリオとし、複製時に新タイトル・カテゴリを受け取ることで doc/04 の実体要件を満たす。**未保存のエディタバッファ（更新する前の編集内容）ごと新 manual へ退避する Save As は out-of-scope**（`scenario.update` の全バリデーション経路を複製 endpoint に持ち込む大工事となり v1「今必要なものだけ作る」に反する。エディタは離脱時に破棄警告を出すため、「更新する→複製」で committed 内容は同結果に到達できる）。この理由から UI は保存済み manual の住処である**詳細画面（Manuals/Show）**に置く。

## 期待効果

- **使命への貢献**: 同一/類似作業の別バリエーション動画を、シナリオ設計コストゼロで量産できる。「標準作業を起点に AI が教材設計する」成果物（シナリオ）を再利用資産にする。
- **仕様欠落の解消**: 複製経路の**構造的欠落（route / Controller / Service / UI がゼロ）を解消**し、doc/04 の「新タイトル・カテゴリの別動画として複製」を満たす。未保存エディタバッファの Save As は out-of-scope（上記整理）。
- **既存フローとの整合**: 複製後の新マニュアルは通常の draft マニュアルと同一状態のため、既存の撮影/レンダフローにそのまま乗る（新しい状態機械を増やさない）。

## 実装方針（概要）

### backend

1. **route**: `POST /projects/{project}/manuals/{manual}/duplicate`（name `projects.manuals.duplicate`）を既存 `Route::scopeBindings()` グループ内に追加。`{manual}` は `$project->manuals()` 経由解決（子→親不整合は認可より前に 404）。`NestedRouteIdorDefenseTest` の inventory に登録必須。
2. **FormRequest**: `DuplicateVideoManualRequest`（StoreVideoManualRequest を厳密に踏襲）。`ProhibitsProtectedKeys` use、`authorize(): bool { return true; }`（認可は Controller の Gate に一元化）。rules: `title` = required|string|max:200、`category`（入力名。保護キー category_id は別名で受けない）= nullable|integer + `Rule::exists('categories','id')->where('project_id', $routeProjectId)`。**型付きアクセサ** `title(): string` / `categoryId(): ?int`（内部で `validated()` + `Assert` で narrow。`mixed` 直渡しを避け PHPStan L10 適合）を持つ。
   - **存在オラクル防御（検証順序）**: route は `project.in-current-org` middleware 配下。middleware は FormRequest 検証より前に走り cross-org `{project}` を 404 に落とす。`{manual}∈{project}` も route model binding（scopeBindings）で検証前に 404。よって FormRequest 実行時点で `{project}` は in-org 保証済み → category exists を route project id にスコープするだけで cross-project/cross-org category は 422 に落ち、存在差を漏らさない。
3. **Controller**: `VideoManualController::duplicate()` を追加。既存 store/destroy と同じく `resolveOrganizationProject`（cross-org 404）→ `Gate::authorize('duplicate', $manual)` → FormRequest の `title()` / `categoryId()` アクセサのみ参照 → Service 委譲 → **新マニュアル show への `redirect()->route(...)->with('success', '動画マニュアルを複製しました（手順書は引き継がれません）')`**（Inertia 操作 POST の慣行。`response()->json()` 直書き・array 返却はしない。`redirect()->intended()` も使わない）。
4. **Policy**: `VideoManualPolicy::duplicate(User, VideoManual): bool` を追加。複製 = 「元を閲覧でき」かつ「同一プロジェクトに新規作成できる」= プロジェクト編集者のみ（`projectPolicy->update`）。撮影者（project_member）は不可。
5. **Service**: `VideoManualService::duplicate(Project $project, VideoManual $source, string $title, ?int $categoryId, int $userId): VideoManual`。1 トランザクション内で:
   - `$locked = Project::whereKey(...)->lockForUpdate()->firstOrFail()`（既存 create/updateMeta と同じ category 整合ロック順 = project→manual）
   - `$lockedSource = $locked->manuals()->whereKey($source->id)->lockForUpdate()->firstOrFail()`（子→親再解決 = cross-project 404 + シナリオの一貫読み取りロック）
   - 新 manual を `$locked->manuals()->make(['title' => $title])` + `forceFill(['created_by' => $userId])->save()`（status/scenario_version は DB default = draft/0）
   - category は `$categoryId !== null` のとき `$locked->categories()->whereKey($categoryId)->firstOrFail()` で **ロック済み project から再解決**して associate（**検証後に category が削除/移動された競合時のみ firstOrFail で 404**。null は未分類）
   - **共有ロック規約の literal 準拠**: cuts を書く前に、新 manual を同一 tx 内で `$locked->manuals()->whereKey($new->id)->lockForUpdate()->firstOrFail()` として**再取得してロック**し、その locked インスタンスの `cuts()` relation 経由で cut を作成する（既存 ScenarioService の準拠形と一致。「対象 VideoManual 行を lockForUpdate で取得した同一 tx 内で反映」の文言を満たす）。
   - cuts 複製: 元の step を sort_order 順に複製 → 各 step 配下 point を複製。`type`/`sort_order` は元値を forceFill、`parent_cut_id` は **旧 step id→新 step id のマップで張り替え**、本文（scene/shot_type/shooting_point/narration/subtitle_primary/subtitle_secondary/material_type/static_display_seconds）は fill。`adopted_take_id`=null（default。リテラル書き込みしない）、`cut_length_ms`=null（レンダ由来はリセット）
6. **共有ロック規約の inventory 登録（新経路）**: 本メソッドは cuts を書く新経路のため、`ScenarioWritePathInventoryTest.php` の docblock 経路表に `VideoManualService::duplicate()`（書いてよいもの = cuts。lockForUpdate 済みの新 manual 経由）を明示追記し、`docs/architecture.md` の書き込み経路表にも追記する。scanner（検出 1=scenario_version / 2=status / 4=adopted_take_id）は duplicate がこれらのリテラルを書かない（新規行は DB default 依存）ため追加 allowlist 変更は不要 — この理由も docblock に記す。

### frontend

- **配置**: `Manuals/Show.svelte`（詳細画面）の編集/削除アクション群に「複製」ボタン（`canManage` のみ表示）を追加。押下で **`Modal`（既存 organism）ベースの複製ダイアログ**を開く。ダイアログは `FormField`（タイトル、既定「{元タイトル} のコピー」プリフィル）+ `Select`（カテゴリ、既定 = 元 category プリフィル。選択肢は props で供給）を持ち、`useForm` で `POST /projects/{id}/manuals/{id}/duplicate` する（Manuals/Create の title/category フォームと同型）。成功時は Inertia redirect が新マニュアル show へ自動遷移。
  - `Show` は編集画面（Edit）に category 選択肢 props（`categoryOptions`）を新たに供給する必要がある（Show controller が categories を props に追加）。
  - doc/04 は 別名保存 を編集画面の保存/破棄群に位置づけるが、複製対象は**保存済み** manual であり、詳細画面（Show）が保存済み manual の住処のため整合的。未保存バッファ Save As（Edit の live buffer 退避）が out-of-scope 部分。
- **型波及**:
  - Manuals/Show の Props に `categories: CategoryOption[]`（複製ダイアログのカテゴリ選択肢）を追加 → TypeScript Props interface 更新。`CategoryOption` 型は既存（`resources/js/types/manual.ts`）。
  - Show controller（`VideoManualController::show`）の Inertia props に `categories`（既存 `categoryOptions()` 再利用）を追加。

## 制約・前提

- **セキュリティ不変条件**: cross-org read/write 不可（`resolveOrganizationProject`）、子は親に属する（`$project->manuals()` 経由 = 認可より前に 404）、tenant/所有権キー不信（project_id/created_by/category_id は payload から受けない = forceFill/relation 経由）。IDOR inventory 登録必須。
- **シナリオ整合の共有ロック規約**（AGENTS.md ドメイン規約 1）: cuts を書く経路のため、元 manual 行を lockForUpdate した同一 tx 内で複製する。新経路として architecture.md に登録。
- **DTO/JsonResource/直 array 禁止**: 応答は既存 store と同じ Inertia redirect のため新規 DTO/Resource 不要（array/json 直返しなし）。入力境界は `DuplicateVideoManualRequest`（FormRequest）で固定。
- **PHPStan L10 / Pest / RefreshDatabase 並列 / Factory**: 既存規約に従う。
- **過剰実装禁止**: 状態機械・新テーブル・新 DTO を増やさない。cuts 件数は有界（step≤100×point≤20）のため chunk 化しない（既存 ScenarioService と同方針）。

## テスト計画（概要。詳細設計で施策化）

- **Feature（複製の正しさ）**: cuts が step/point 二層・sort_order・本文フィールドまで正しく複製され、parent_cut_id が新 step id に張り替わる。takes 空・adopted_take_id=null・cut_length_ms=null・status=draft・scenario_version=0。新タイトル/カテゴリが反映。元 manual は不変。
- **Feature（非複製）**: source_documents / takes / RenderJob / AnalysisJob は複製されない（新 manual の hasDocument=false）。
- **Feature（権限・組織スコープ）**: 撮影者（project_member）は複製不可（403）。他組織の manual 複製は 404（cross-org）。`{manual}` ∉ `{project}` は 404（scopeBindings）。他 project の category を渡すと **422**（FormRequest）。検証通過後に category が削除された競合は Service 再解決で **404**。
- **Feature（後続フロー接続）**: 複製直後に `CutSequencer::orderedWithLabels` が全 cuts を「手順N / 急所N-M」ラベル付きで返す（cut_length_ms=null 初期状態で order/label 導出が壊れない）。
- **Architecture**: `NestedRouteIdorDefenseTest` inventory に `projects.manuals.duplicate` を追加。`docs/architecture.md` の共有ロック書き込み経路表へ追記。
- **vitest（UI）**: canManage 時のみ複製ボタン表示。押下で Modal 展開、タイトル/カテゴリがプリフィル、送信で正しい URL に POST。

## source document（SOP）を複製しない設計判断

「参照」「複製」「複製しない」の 3 案を比較し **複製しない** を採る:

- **参照（file_path 共有）は不可**: `VideoManualService::delete()` は削除時に配下 source_documents の `file_path` を集め `DeleteTakeObjectsJob` に渡してストレージ実体を消す。file_path を 2 マニュアルで共有すると、片方の削除がもう片方の SOP 実体を消す（orphan/データ破壊）。SOP は追記型 immutable の監査証跡でもあり参照共有は設計と矛盾。
- **ファイル複製（Storage::copy + 新行）は過剰**: トランザクション内での S3 ファイル複製・失敗時の孤児処理を新たに抱える。複製の目的は **シナリオ（cuts）を雛形に別動画を作る** ことであり、cuts は既に materialize 済みのため再解析用 SOP は不要。
- **複製しないで十分**: 「cuts はあるが source document は無い」状態は、自作シナリオ経路（`ScenarioService` が SOP 無しで draft→ready を許可）で既にサポート済みの正当な状態。新マニュアルは `hasDocument=false` で始まり、必要なら通常の SOP アップロード導線で後から SOP を足せる。

→ v1 は **cuts のみ複製、source document は複製しない**。SOP を引き継ぐ file-copy は将来の拡張（out-of-scope）として明記する。

## スコープ外（v1 で扱わない）

- **未保存エディタバッファの Save As**: エディタで「更新する」前の未保存 scenario 編集内容ごと新 manual へ退避する導線（`scenario.update` の全バリデーション経路を複製 endpoint に持ち込む大工事。複製元は保存済み cuts とする）。
- source document（SOP）ファイルの引き継ぎ（上記の理由。将来拡張）
- takes / adopted_take_id / RenderJob / AnalysisJob の複製（新規撮影・再合成前提）
- 別プロジェクトへの複製（v1 は単一 Default Project。route は同一 {project} 内複製のみ）
- TTS/音声（v1 スコープ外・字幕のみ）
