# 概念設計: aicue-domain-foundation（AI-CUE ドメイン実装フェーズ1・データ基盤 + Category/VideoManual リソース）

## 背景・課題

テンプレート抽出 Phase 0〜10 は完了し、認証 / 組織・Team・Project / セキュリティ層 / 課金 / API・MCP / LLM コア / 管理画面などの**汎用基盤は green**（`doc`・`devnotes/20260611-template-extraction`）。一方、**AI-CUE のドメイン本体（`categories` / `video_manuals` / `source_documents` / `cuts` / `takes` / ジョブ群、撮影 PWA、SOP→シナリオ生成）は未実装**で、存在するドメインらしきモデルは汎用サンプルの `Item` / `Project` のみ。

`doc/10 実装仕様` §10.6 は、フェーズ1を「初期化後、**Category → VideoManual → Cut → Take** の順で各リソースを `Item` 見本と同じ 15 点セットでトレースする」と定義している。§10.8（2026-07-10 の着手前レビュー反映）は §10.1〜§10.7 に優先する確定事項。まずこのフェーズ1の**データ基盤とユーザーが直接 CRUD する最初のリソース**を立ち上げ、以降のフェーズ（AI 解析・シナリオ編集・レンダ・撮影 PWA・課金統合）が乗る土台を green で確定させる。

**仮説**: `Item` 見本のトレースで確立済みの「org-scoped 解決 → 認可前 404 → 親委譲 Policy → protected FK → NestedRouteIdorDefenseTest 登録」パターンを、AI-CUE の中核集約（Project ─< Category / VideoManual ─< Cut ─< Take）へ機械的に横展開できる。ドメイン固有の難所（チケット2フェーズ消費・楽観ロック・PWA・ffmpeg）を**振る舞いとして持ち込まず、スキーマとして先取り**すれば、フェーズ1は既存テンプレのレンジ内（新規機構ゼロ）で完了できる。

**成功判定**: `composer test` / `phpstan`(lv10) / `pint --test` / `pnpm lint,typecheck,test,build` が全 green。Category の管理 CRUD と VideoManual の CRUD（一覧/作成/表示/メタデータ更新/削除）が動作し、保護キー 422・cross-org/cross-project 404・権限（編集者/撮影者）が Feature テストで固定される。

## 改善アイデア

`doc/10` §10.1〜§10.8 のうち**フェーズ1の土台に必要な部分だけ**を、テンプレの `Item` 規約に沿って実装する。Round 1 レビューを受け、スコープを**2段階（Tier）**に明確化する。

- **Tier A（15 点フルセット＝ユーザー価値に直結）**: `Category` と `VideoManual`。CRUD・ルート・IDOR 登録・Policy・FormRequest・Svelte 画面・Feature/Vitest まで。用語注記: 両者とも専用 `index`/`show` ページは持たず一覧は `Projects/Show` に内包する（`Item` 見本と同じ「一覧は親画面内包・書き込み系のみ Controller」パターン）。ここでの「CRUD」は store/show/update/destroy を指す。
- **Tier B（データ基盤の先取り＝schema + model + factory のみ）**: `SourceDocument` / `Cut` / `Take`。マイグレーション・Model（親子 relation）・Factory・`MassAssignmentProtectedKeys` 追記まで。**ルート・専用 Controller・IDOR inventory 登録・UI は張らない**（それらは振る舞いを持つ後続フェーズ＝解析/シナリオ編集/撮影 と同時に張る。実ルート未確定のまま inventory だけ先行させない）。

これにより「フェーズ1 = データ基盤 + 最初の CRUD」の粒度に収め、`Item` 見本のレンジ内で完結させる。

### スコープ（フェーズ1）

1. **Enum 定義（string backed）**: `VideoManualStatus`(draft/analyzing/ready/rendering/published) / `CutType`(step/point) / `ShotType`(hiki/yori) / `TakeStatus`(uploading/processing/ready/failed) / `MaterialType`(video/still)。**いずれもフェーズ1のテーブル（video_manuals / cuts / takes）の cast 対象カラムに対応する**。Model の `casts()` に登録。状態遷移の**振る舞い（遷移メソッド）は後続**、定義と cast のみ。フェーズ1で実際に値が動くのは `VideoManualStatus`（作成時 `draft`）のみ。`JobStatus` は対応する `analysis_jobs`/`render_jobs` テーブルも利用箇所もフェーズ1に無いため**ジョブ導入フェーズへ移す**（「今必要なものだけ作る」）。
2. **マイグレーション（§10.1 の確定スキーマ、§10.8 のカラム追加を織り込む）**:
   - `categories`【Tier A】（project_id protected・cascade、name project 内ユニーク〔複合 unique (project_id,name)〕、sort_order）
   - `video_manuals`【Tier A】（project_id/created_by protected、category_id NULL・**FK onDelete set null**、status、`scenario_version` int default 0、total_length_ms NULL）
   - `source_documents`【Tier B】（video_manual_id protected cascade、file_path/original_name/mime/size_bytes、extracted_json NULL）
   - `cuts`【Tier B】（video_manual_id/parent_cut_id/adopted_take_id protected、type/shot_type/material_type enum、sort_order、本文フィールド群）
   - `takes`【Tier B】（cut_id protected cascade、`client_take_id` で `(cut_id, client_take_id)` UNIQUE〔同期冪等キー〕、size_bytes、status、sort_order）
   - **循環 FK のマイグレーション順序**（`cuts.adopted_take_id` ↔ `takes.cut_id`）: 単一マイグレーションでは構築不能。`cuts`（adopted_take_id は FK なしの nullable カラムだけ先に置く）→ `takes`（cut_id FK cascade）→ 後段の `Schema::table('cuts', ...)` で `adopted_take_id` の FK（`takes` 参照・onDelete set null）を追加、の順で明記する。
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
   - **`category_id` の扱い（Critical 対応・入力名衝突の解消）**: `category_id` は protected（`ProhibitsProtectedKeys` が payload の `category_id` キーを 422 で拒否）。よってカテゴリ選択の**入力名は保護キーと別名 `category`**（値は category id）とする。FormRequest は `category` を `Rule::exists('categories','id')->where('project_id', $project->id)`（＋ null 許容）で**当該 project 配下に限定**して検証。**保存時は検証済み id をそのまま代入せず、必ず Controller/Service が project relation から Category を再解決して `category()->associate($resolvedCategory)`**（point-in-time 検証と保存時解決の二段構え。再解決を必須契約とし Feature テストで固定）。`category_id` を直接送れば 422 のまま。
7. **FormRequest**（`ProhibitsProtectedKeys` + rules）: Category（**name のみ**。`sort_order` は入力から除外＝専用 reorder 操作の契約を迂回させない）・VideoManual（title/`category`〔別名〕）の Store・Update、Category reorder（順序配列）。**`sort_order` は作成時に Service が末尾値を採番し、以後の変更は reorder Service のみが行う**（Store/Update から任意 sort_order を設定させない）。
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
11. **Feature テスト + Vitest**: 保護キー 422（`category_id` を直接 payload 送出で 422）/ `category`（別名）で他 project の category id を指定すると exists 検証で弾かれる / **保存時再解決の固定**（in-project の category を指定して associate される・改竄が反映されない）/ cross-org・cross-project 404 / 権限（編集者は CRUD 可・撮影者は 403）/ Category 削除で manual が set null（未分類化）/ Category reorder の順序反映と集合不一致 422 / manuals 一覧フィルタ。

## 期待効果

- **使命への貢献**: AI-CUE の中核データモデル（SOP=SourceDocument → VideoManual → Cut → Take）を確定し、「SOP 起点で AI がカット設計した動画マニュアル」を格納する器を green で用意する。以降のフェーズ（AI 解析・シナリオ編集・撮影・レンダ）が全てこの土台に乗る。
- **具体的改善**: `Item` 見本で実証済みのセキュリティ不変条件（tenant キー不信・子は親に属する・cross-org 不可）を、ドメイン全リソースへ機械的に横展開。難所（楽観ロック・容量 Quota・冪等同期）を**スキーマとして先取り**することで、後続フェーズはカラム追加なしに振る舞いだけを足せる。

## 実装方針（概要）

- 新規機構は作らない。既存テンプレの `Item`（Model/Controller/Policy/Request/Factory/routes/IDOR test）を型紙に、Tier A（Category/VideoManual）は 15 点フルセット、Tier B（SourceDocument/Cut/Take）は schema+model+factory で横展開する（AGENTS.md 思考原則1「フレームワークのレンジ内」）。
- `doc/10` の**フェーズ1チェックリスト（§10.6）15 点セット**を Tier A で満たす。難所（§10.8-1〜7）は**カラム/UNIQUE 制約としてのみ**取り込み、振る舞い（reserve/commit・409 楽観ロック・presigned・ffmpeg）は後続フェーズの TODO とする。
- **Category の `sort_order` は専用 Service のみが操作**する（Store/Update は触らない）:
  - 作成: Store Service が当該 project 内の `max(sort_order)+1`（末尾）を採番。
  - 並べ替え: `PATCH .../categories/reorder`（payload = 当該 project の category id 順序配列）→ 専用 `ReorderCategoriesRequest`。各 id の exists だけでは不十分（欠落・重複・空で順序破綻）なので、**「送信 id 集合が当該 project の Category 集合と完全一致（distinct かつ過不足なし）」を検証し、不一致は 422**。
  - **並行制御（Round 3/4 対応・Project 行ロックで直列化）**: 行ロックは未作成行を守らないため「Category 全行ロック」では新規 insert（0 件 project での同時作成含む）を直列化できず、同時作成が同じ `max(sort_order)+1` を採番しうる。したがって **create / delete / reorder の全処理で、transaction 冒頭に共通の `Project` 行を `lockForUpdate()`** し、そのロック取得後に Category 集合の取得・`max` 計算・集合再検証・更新を行う。これで project 単位に確実に直列化され、Category 全行ロックは不要。順序は「後勝ち」ではなく**「ロック取得順に直列化」**。reorder は集合一致検証（distinct・過不足なし）を Project ロック取得後に行い、不一致は 422（＝ロック中は増減しないため 409 は発生しない）。
- transaction は Service 内（reorder の複数行更新等）。Controller は薄く（Item Controller と同じ委譲パターン）。
- `docs/template-divergence.md` へ、後続で発生する意図的逸脱（シナリオ document 一括保存・メディア・合成）は**発生フェーズで**記録（フェーズ1では逸脱を持ち込まない方針）。

## 制約・前提

- **§10.8 が §10.1〜§10.7 に優先**する確定仕様。フェーズ1ではカラム（`scenario_version` / `size_bytes` / `client_take_id` UNIQUE / `category_id` set null）として反映。
- セキュリティ不変条件（AGENTS.md §セキュリティ不変条件）を全リソースで満たす: protected FK は payload 拒否、nested route 不整合は認可前 404、cross-org read/write 不可、権限は `laratrust_team_id` 明示。
- PII 該当カラムは現状なし（name/title は業務データ、email/氏名ではない）→ CipherSweet 対象外。
- 課金・Quota・LLM・PWA・ffmpeg は**このフェーズでは配線しない**（後続）。`analysis_jobs` / `render_jobs` テーブル・`JobStatus` enum も AI/レンダフェーズで導入（`ticket_reservation_id` 依存のため）。
- **型安全性（PHPStan lv10）は `Item` 規約をフルで踏襲**: nullable は `?Type` に加え Model プロパティの PHPDoc（`@property`）、`casts()` の返却型、relation の generics（`BelongsTo<Parent,$this>` / `HasMany<Child,$this>`）、Resource/Data の返却 shape まで固定（詳細は Phase 2 詳細設計で Item 見本にトレース）。
- **Tier B の将来必須条件（今は記載のみ）**: 通常 FK では「子が同一親所属」を保証できないため、後続フェーズで relation 経由解決＋404 テストを必須条件として引き継ぐ:
  - `cuts.adopted_take_id`: 採用 API は `cut->takes()` 経由でのみ解決、cross-cut 指定は 404。
  - `cuts.parent_cut_id`: 急所（point）の親手順は同一 `video_manual` 所属を relation 経由で解決、cross-manual 指定は 404。

## スコープ外（後続フェーズ）

- AI 解析（`analysis_jobs`、work-decomposition / scenario-generation / sop-extract プロンプト、DTO 検証・有界リトライ）— §10.4 / §10.8-1
- シナリオ document 一括保存（`PUT .../scenario`、楽観ロック 409、protected キーのネスト構造決定）— §10.8-2 / §10.8-5
- レンダ（`render_jobs`、ffmpeg ワーカー、バージョン固定、ダウンロード署名 URL）— §10.8-6
- 撮影 PWA（presigned upload、署名チケット検証 + HeadObject、takes sync/adopt、CSRF 419 リトライ、カメラフォールバック）— §10.3 / §10.8-3 / §10.8-7
- 課金統合（チケット reserve→commit/release の 2 フェーズ、容量 Quota 実計上・孤児掃除 cron）— §10.5 / §10.8-1 / §10.8-4
- 多言語（`feature_multilang`）
