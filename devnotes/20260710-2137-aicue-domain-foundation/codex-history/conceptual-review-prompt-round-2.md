## Round 2: Round 1 指摘への対応と再レビュー依頼

Round 1（CHANGES_REQUESTED）の全指摘に対し、以下の通り概念設計を改訂しました。対応マトリクスと改訂後の該当箇所を示します。**全 Critical と主要 Warning を解消できているか、残課題がないかを再判定してください。**

### 対応マトリクス（要約）

- [Critical] category_id 矛盾 → protected のまま維持。FormRequest で `Rule::exists('categories','id')->where('project_id',$project->id)`（null 許容）で project 配下限定検証し、保存は project スコープで解決した Category を `->associate()` 代入（payload を直接 fill しない）。tenant キー不信を維持しつつカテゴリ選択を成立。
- [Critical] VideoManual が CRD → `edit`/`update`（title・category_id メタデータ更新）を追加し CRUD に。成功判定も修正。
- [Critical/Warning] スコープ過大 → **2 Tier に分割**。Tier A（Category/VideoManual）= 15 点フルセット、Tier B（SourceDocument/Cut/Take）= schema+model+factory のみ（route/専用 Controller/IDOR 登録/UI なし。後続で振る舞いと同時に張る）。
- [Warning] レスポンス型契約未明示 → 一覧 props shape（`manuals:{data,meta}`+`categories`+`filters`）等を Resource/Data 経由で型付け、TS interface 追加を波及変更に明示。
- [Warning] IDOR 未確定ルート先行登録 → 「張ったルートだけ登録」。Tier B は後続で張る時に登録。
- [Warning] reorder 契約不足 → `PATCH .../categories/reorder` + `ReorderCategoriesRequest`（project 配下 exists）+ Service 1 transaction 一括再採番。後勝ち・全件再採番で単純化。
- [Warning] 先取りカラム意味づけ → scenario_version/size_bytes/client_take_id/adopted_take_id の「誰が更新/整合先/フェーズ1状態」表を追加。
- [Warning] 権限がアクション別でない → アクション×role 許可表を追加（show 系=両者、write 系=編集者のみ）。
- [Warning] 未分類の扱い → 表示名/フィルタ選択肢/フォーム初期値/並び順（末尾）を明記。
- [Warning] PHPStan 粒度 → casts() 登録・nullable は `?Type`・props shape を具体化。
- [Suggestion] 一覧は GET クエリ + paginate、Resource/Data 統一 → 採用。

### 改訂後の概念設計（全文）

# 概念設計: aicue-domain-foundation（AI-CUE ドメイン実装フェーズ1・データ基盤 + Category/VideoManual リソース）

## 背景・課題

テンプレート抽出 Phase 0〜10 は完了し、認証 / 組織・Team・Project / セキュリティ層 / 課金 / API・MCP / LLM コア / 管理画面などの**汎用基盤は green**（`doc`・`devnotes/20260611-template-extraction`）。一方、**AI-CUE のドメイン本体（`categories` / `video_manuals` / `source_documents` / `cuts` / `takes` / ジョブ群、撮影 PWA、SOP→シナリオ生成）は未実装**で、存在するドメインらしきモデルは汎用サンプルの `Item` / `Project` のみ。

`doc/10 実装仕様` §10.6 は、フェーズ1を「初期化後、**Category → VideoManual → Cut → Take** の順で各リソースを `Item` 見本と同じ 15 点セットでトレースする」と定義している。§10.8（2026-07-10 の着手前レビュー反映）は §10.1〜§10.7 に優先する確定事項。まずこのフェーズ1の**データ基盤とユーザーが直接 CRUD する最初のリソース**を立ち上げ、以降のフェーズ（AI 解析・シナリオ編集・レンダ・撮影 PWA・課金統合）が乗る土台を green で確定させる。

**仮説**: `Item` 見本のトレースで確立済みの「org-scoped 解決 → 認可前 404 → 親委譲 Policy → protected FK → NestedRouteIdorDefenseTest 登録」パターンを、AI-CUE の中核集約（Project ─< Category / VideoManual ─< Cut ─< Take）へ機械的に横展開できる。ドメイン固有の難所（チケット2フェーズ消費・楽観ロック・PWA・ffmpeg）を**振る舞いとして持ち込まず、スキーマとして先取り**すれば、フェーズ1は既存テンプレのレンジ内（新規機構ゼロ）で完了できる。

**成功判定**: `composer test` / `phpstan`(lv10) / `pint --test` / `pnpm lint,typecheck,test,build` が全 green。Category の管理 CRUD と VideoManual の CRUD（一覧/作成/表示/メタデータ更新/削除）が動作し、保護キー 422・cross-org/cross-project 404・権限（編集者/撮影者）が Feature テストで固定される。

## 改善アイデア

`doc/10` §10.1〜§10.8 のうち**フェーズ1の土台に必要な部分だけ**を、テンプレの `Item` 規約に沿って実装する。Round 1 レビューを受け、スコープを**2段階（Tier）**に明確化する。

- **Tier A（15 点フルセット＝ユーザー価値に直結）**: `Category` と `VideoManual`。CRUD・ルート・IDOR 登録・Policy・FormRequest・Svelte 画面・Feature/Vitest まで。
- **Tier B（データ基盤の先取り＝schema + model + factory のみ）**: `SourceDocument` / `Cut` / `Take`。マイグレーション・Model（親子 relation）・Factory・`MassAssignmentProtectedKeys` 追記まで。**ルート・専用 Controller・IDOR inventory 登録・UI は張らない**（それらは振る舞いを持つ後続フェーズ＝解析/シナリオ編集/撮影 と同時に張る。実ルート未確定のまま inventory だけ先行させない）。

これにより「フェーズ1 = データ基盤 + 最初の CRUD」の粒度に収め、`Item` 見本のレンジ内で完結させる。

### スコープ（フェーズ1）

1. **Enum 定義（string backed）**: `VideoManualStatus`(draft/analyzing/ready/rendering/published) / `CutType`(step/point) / `ShotType`(hiki/yori) / `TakeStatus`(uploading/processing/ready/failed) / `JobStatus`(queued/running/succeeded/failed) / `MaterialType`(video/still)。Model の `casts()` に登録。状態遷移の**振る舞い（遷移メソッド）は後続**、定義と cast のみ。フェーズ1で実際に値が動くのは `VideoManualStatus`（作成時 `draft`）のみ。他 enum は Tier B カラムの cast 用。
2. **マイグレーション（§10.1 の確定スキーマ、§10.8 のカラム追加を織り込む）**:
   - `categories`【Tier A】（project_id protected・cascade、name project 内ユニーク〔複合 unique (project_id,name)〕、sort_order）
   - `video_manuals`【Tier A】（project_id/created_by protected、category_id NULL・**FK onDelete set null**、status、`scenario_version` int default 0、total_length_ms NULL）
   - `source_documents`【Tier B】（video_manual_id protected cascade、file_path/original_name/mime/size_bytes、extracted_json NULL）
   - `cuts`【Tier B】（video_manual_id/parent_cut_id/adopted_take_id protected、type/shot_type/material_type enum、sort_order、本文フィールド群）
   - `takes`【Tier B】（cut_id protected cascade、`client_take_id` で `(cut_id, client_take_id)` UNIQUE〔同期冪等キー〕、size_bytes、status、sort_order）
   - **先取りカラムの意味づけ**（後続で意味がブレないよう明記）:
     | カラム | 誰が更新するか | 何と整合するか | フェーズ1での状態 |
     |---|---|---|---|
     | `video_manuals.scenario_version` | シナリオ保存 Service（後続）| `PUT .../scenario` の `expected_version` と楽観ロック照合 | default 0 固定・更新経路なし |
     | `takes.size_bytes` | テイク登録 Service（後続）| org 単位 `bytes_used`（Quota 実計上）| 記録のみ・集計経路なし |
     | `takes.client_take_id` | 撮影端末（後続）| `(cut_id, client_take_id)` UNIQUE で同期冪等 | Factory は ULID 生成・API 未提供 |
     | `cuts.adopted_take_id` | 採用 API（後続）| 採用テイク。指す take 削除時 null 化 | 常に null |
3. **Model**: 親 BelongsTo / 子 hasMany、FK は `$fillable` 外、relation 経由 create。`Item` と同じ型注釈規約（`@use HasFactory<...>`、generics）。enum は `casts()` で backed enum にキャスト、nullable カラムは `?Type` を明示。
4. **`MassAssignmentProtectedKeys` 追記**（§10.1）: `video_manual_id` / `cut_id` / `parent_cut_id` / `category_id` / `adopted_take_id` / `created_by` / `source_document_id`（`ticket_reservation_id` はジョブテーブル導入時=後続フェーズ）。
5. **Factory**（親 Factory 連鎖）: 5 モデル分。`docs/architecture.md` / `docs/factories.md` に追記。
6. **ルート + IDOR 防御【Tier A のみ】**:
   - `/projects/{project}/categories`（resource：store/update/destroy + `PATCH .../categories/reorder`。編集者のみ）
   - `/projects/{project}/manuals`（index は `Projects/Show` に内包・GET クエリ絞り込み + paginate、`create`/`store`/`show`/`edit`/`update`〔メタデータ = title・category_id〕/`destroy`）
   - いずれも `scopeBindings()`。**張ったルートだけ** `NestedRouteIdorDefenseTest` inventory に登録（cut/take は後続で張る時に登録）。
   - **`category_id` の扱い（Critical 対応）**: `category_id` は protected のまま。FormRequest で `category_id` を受けるが `Rule::exists('categories','id')->where('project_id', $project->id)`（＋ null 許容）で**当該 project 配下に限定**して検証し、保存は Controller/Service が **project スコープで解決した Category を `->associate()`** して代入（payload の値を直接 fill しない = tenant キー不信を維持）。
7. **FormRequest**（`ProhibitsProtectedKeys` + rules）: Category（name/sort_order）・VideoManual（title/category_id）の Store・Update、Category reorder（順序配列）。
8. **Policy（親委譲）**: `CategoryPolicy` / `VideoManualPolicy`（`ProjectPolicy` に委譲、直 fetch 禁止）。**アクション別 role 許可表（§10.5：project_admin=編集者 / project_member=撮影者）**:
   | アクション | 編集者(admin) | 撮影者(member) |
   |---|---|---|
   | projects.show（一覧閲覧）| ✓ | ✓ |
   | manuals.show | ✓ | ✓ |
   | manuals.store / update / destroy | ✓ | ✗ |
   | categories.store / update / destroy / reorder | ✓ | ✗ |
   （撮影者の capture/upload/adopt は後続フェーズ。フェーズ1の撮影者は read のみ。）
9. **Svelte 画面（DS token のみ）**: `Projects/Show.svelte` を見本に**動画一覧をカテゴリ/状態/検索で GET クエリ絞り込み + paginate 内包**、VideoManual の create/show/edit（メタデータ）画面、Category 管理（追加/更新/並べ替え/削除）。エラーは押下時表示（§禁止事項8、disabled 禁止）。**未分類（null category）の扱い**: 一覧フィルタに「未分類」選択肢、作成/編集フォームのカテゴリ初期値は「未分類」、一覧のカテゴリ列表示名は「未分類」、並び順は末尾。
10. **レスポンス型契約（型安全性）**: PC 画面は Inertia props。一覧 props は `manuals: {data: VideoManualListItem[], meta: PaginationMeta}` + `categories: CategoryOption[]` + `filters: {category_id?, status?, q?}` の shape を Resource/Data 経由で返す（`response()->json()` 直書きなし）。詳細/フォーム初期値も同様に Resource/Data で型付け。TS 側は `resources/js/types` に対応 interface を追加（波及変更として明示）。
11. **Feature テスト + Vitest**: 保護キー 422（category_id 含む protected を payload 送出）/ cross-org・cross-project 404 / 権限（編集者は CRUD 可・撮影者は 403）/ Category 削除で manual が set null（未分類化）/ Category reorder の順序反映 / manuals 一覧フィルタ。

## 期待効果

- **使命への貢献**: AI-CUE の中核データモデル（SOP=SourceDocument → VideoManual → Cut → Take）を確定し、「SOP 起点で AI がカット設計した動画マニュアル」を格納する器を green で用意する。以降のフェーズ（AI 解析・シナリオ編集・撮影・レンダ）が全てこの土台に乗る。
- **具体的改善**: `Item` 見本で実証済みのセキュリティ不変条件（tenant キー不信・子は親に属する・cross-org 不可）を、ドメイン全リソースへ機械的に横展開。難所（楽観ロック・容量 Quota・冪等同期）を**スキーマとして先取り**することで、後続フェーズはカラム追加なしに振る舞いだけを足せる。

## 実装方針（概要）

- 新規機構は作らない。既存テンプレの `Item`（Model/Controller/Policy/Request/Factory/routes/IDOR test）を型紙に、Tier A（Category/VideoManual）は 15 点フルセット、Tier B（SourceDocument/Cut/Take）は schema+model+factory で横展開する（AGENTS.md 思考原則1「フレームワークのレンジ内」）。
- `doc/10` の**フェーズ1チェックリスト（§10.6）15 点セット**を Tier A で満たす。難所（§10.8-1〜7）は**カラム/UNIQUE 制約としてのみ**取り込み、振る舞い（reserve/commit・409 楽観ロック・presigned・ffmpeg）は後続フェーズの TODO とする。
- **Category reorder は専用操作**として実装: `PATCH .../categories/reorder`（payload = 当該 project の category id 順序配列）→ 専用 `ReorderCategoriesRequest`（各 id が project 配下である exists 検証）→ Service が 1 transaction で `sort_order` を一括再採番。競合は「後勝ち・全件再採番」で単純化（sort_order は表示順のみで不変条件に絡まない）。
- transaction は Service 内（reorder の複数行更新等）。Controller は薄く（Item Controller と同じ委譲パターン）。
- `docs/template-divergence.md` へ、後続で発生する意図的逸脱（シナリオ document 一括保存・メディア・合成）は**発生フェーズで**記録（フェーズ1では逸脱を持ち込まない方針）。

## 制約・前提

- **§10.8 が §10.1〜§10.7 に優先**する確定仕様。フェーズ1ではカラム（`scenario_version` / `size_bytes` / `client_take_id` UNIQUE / `category_id` set null）として反映。
- セキュリティ不変条件（AGENTS.md §セキュリティ不変条件）を全リソースで満たす: protected FK は payload 拒否、nested route 不整合は認可前 404、cross-org read/write 不可、権限は `laratrust_team_id` 明示。
- PII 該当カラムは現状なし（name/title は業務データ、email/氏名ではない）→ CipherSweet 対象外。
- 課金・Quota・LLM・PWA・ffmpeg は**このフェーズでは配線しない**（後続）。`analysis_jobs` / `render_jobs` テーブルも AI/レンダフェーズで導入（`ticket_reservation_id` 依存のため）。

## スコープ外（後続フェーズ）

- AI 解析（`analysis_jobs`、work-decomposition / scenario-generation / sop-extract プロンプト、DTO 検証・有界リトライ）— §10.4 / §10.8-1
- シナリオ document 一括保存（`PUT .../scenario`、楽観ロック 409、protected キーのネスト構造決定）— §10.8-2 / §10.8-5
- レンダ（`render_jobs`、ffmpeg ワーカー、バージョン固定、ダウンロード署名 URL）— §10.8-6
- 撮影 PWA（presigned upload、署名チケット検証 + HeadObject、takes sync/adopt、CSRF 419 リトライ、カメラフォールバック）— §10.3 / §10.8-3 / §10.8-7
- 課金統合（チケット reserve→commit/release の 2 フェーズ、容量 Quota 実計上・孤児掃除 cron）— §10.5 / §10.8-1 / §10.8-4
- 多言語（`feature_multilang`）

