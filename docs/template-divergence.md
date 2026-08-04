# テンプレート差分レジストリ

テンプレート(laravel-claude-template)の構造から**意図的に逸脱**した箇所の正本記録。
逸脱が正当なのは **logic-driven(ドメイン要件起因)のときだけ**。互換・UX・作業量を理由にした
逸脱は記録せず是正する(`docs/app-integration-guide.md` §0)。

## 記録の原則

- 判定軸は「ライブラリ/実装が同じか」でなく「**同じ不変条件を同じタイミング/抽象度で保証するか**」。
  不変条件が揃っていれば構文差は許容
- 各エントリには (a) なぜ logic-driven か (b) テンプレートの不変条件をどの機構で保証し続けるか
  を必ず書く

## エントリ形式

```
## D1 ✅ <逸脱の要約>

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| ... | ... | ... |

### なぜ正当な差分か(logic-driven)
...

### 揃えている不変条件(これは保証し続ける)
> 「...」
どの機構でカバーするか。drift を防ぐテスト。

### 関連
- 実装: ...
- テンプレート側の根拠: ...
```

---

## D1 ✅ Tier B スキーマの先取り (Cut / Take を振る舞い無しで先行作成)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| リソース追加 | Item 見本の 15 点フルセット (route/Controller/UI まで) を同時に張る | Cut / Take は migration + Model + Factory + 保護キーのみ (route/Controller/UI なし)。SourceDocument は T003 (AI 解析) で route/Controller/UI を追加済みで本差分の対象外 |

### なぜ正当な差分か(logic-driven)
AI-CUE の中核集約 (VideoManual ─< SourceDocument / Cut ─< Take) はフェーズ1で
スキーマを確定させ、後続フェーズ (AI 解析・シナリオ編集・撮影 PWA) がカラム追加なしに
振る舞いだけを足せるようにする (doc/10 §10.6 フェーズ1定義)。実 route 未確定のまま
IDOR inventory だけ先行させないため、route/UI は張らない。

なお SourceDocument は T003 (AI 解析) で振る舞いが確定し、
`SourceDocumentController` + `projects.manuals.source-documents.store` route +
`SourceDocumentUpload.svelte` を追加して本差分から**卒業**した。残る先取りは Cut / Take のみ
(Cut は書き込み経路のみ ScenarioService 経由で存在し、per-row route は張っていない。D5 参照)。

### 揃えている不変条件(これは保証し続ける)
> 「route/UI を張るまで外部到達不可。張るときに NestedRouteIdorDefenseTest inventory 登録と
> relation 経由解決 + 404 テストを同時に行う」
NestedRouteIdorDefenseTest の deny-by-default (2+param route の未分類 fail) が、後続フェーズで
route を張った瞬間に分類登録を強制する。保護キー (video_manual_id/cut_id/parent_cut_id/
adopted_take_id) は MassAssignmentSafetyTest が $fillable 不含を自動走査する。
SourceDocument の卒業時にはこの不変条件どおり、`projects.manuals.source-documents.store` の
NestedRouteIdorDefenseTest inventory 登録と relation 経由解決 + 404 テスト
(`tests/Feature/Projects/SourceDocumentUploadTest.php`) を route 追加と同時に行った。

### 関連
- 実装: `database/migrations/2026_07_10_*`, `app/Models/{SourceDocument,Cut,Take}.php`,
  卒業分: `app/Http/Controllers/Projects/SourceDocumentController.php`,
  `resources/js/components/features/manual/SourceDocumentUpload.svelte`
- 設計: `devnotes/20260710-2137-aicue-domain-foundation/detailed-design.md` 施策2/4/5

## D2 ✅ 循環 FK の 3 段階マイグレーション (cuts の parent_cut_id / adopted_take_id を後付け)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| migration | 1 テーブル = 1 migration (CREATE 内で FK 完結) | cuts は CREATE 時に FK なし nullable カラムのみ置き、takes 作成後に FK を後付け |

### なぜ正当な差分か(logic-driven)
`cuts.adopted_take_id` ↔ `takes.cut_id` の循環 FK と `cuts.parent_cut_id` の自己参照 FK は
単一 CREATE では構築不能/DB 依存で不安定なため、cuts → takes →
`2026_07_10_000500_add_foreign_keys_to_cuts_table` の 3 段に分離する。

### 揃えている不変条件(これは保証し続ける)
> 「親削除時の参照整合 (nullOnDelete) と、down() の逆順 drop (dropForeign → 各テーブル drop)」
RefreshDatabase が全 Feature テストで migration の up を暗黙検証する。

### 関連
- 実装: `database/migrations/2026_07_10_000300_create_cuts_table.php` / `..._000500_add_foreign_keys_to_cuts_table.php`

## D3 ✅ Category `sort_order` の Service 専有 (fillable 外・Store/Update で受けない)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| カラム入力 | Item は全業務カラムを FormRequest 経由で受ける | Category の sort_order は payload から一切受けず、CategoryService (作成時末尾採番 + reorder) のみが更新 |

### なぜ正当な差分か(logic-driven)
並べ替えは「送信 id 集合 = project の Category 集合 (distinct・過不足なし)」という
集合契約で成立する。Store/Update から任意 sort_order を設定できると reorder 契約を
迂回して順序が破綻するため、専用 reorder 操作に閉じる。

### 揃えている不変条件(これは保証し続ける)
> 「create/update/reorder/delete は Project 行ロック (lockForUpdate) 下で直列化され、
> sort_order は project 内で一意な並びを保つ」
`tests/Feature/Projects/CategoryReorderTest.php` が末尾採番・集合不一致 422・並び反映を固定する。

### 関連
- 実装: `app/Services/Manual/CategoryService.php`, `app/Models/Category.php`
- 設計: `devnotes/20260710-2137-aicue-domain-foundation/detailed-design.md` 施策7

## D4 ✅ web `{project}` route の org スコープ guard を middleware 層に追加 (project.in-current-org)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| {project} ∈ current org の guard | controller の inline guard (`resolveOrganizationProject`) のみ | `project.in-current-org` middleware (`EnsureProjectBelongsToCurrentOrganization`) を web の {project} route group に一括付与 + inline guard を二重防御として維持 |

### なぜ正当な差分か(logic-driven)
FormRequest のバリデーションは controller メソッド解決時 = inline guard より**前**に走る。
テンプレの Item 見本は DB ルールを持たないため無害だが、T001 で追加した project スコープの
DB ルール (categories.name の unique / category の exists) は、cross-org プロジェクトに対して
422 (検証エラー) と 404 の応答差分を作り、他組織のカテゴリ名・所属関係を辞書探索できる
存在オラクルになる (T001 セキュリティレビュー指摘)。middleware は FormRequest 解決より前
(SubstituteBindings の後) に走るため、順序ハザードを route group 単位で構造的に閉じる。
`Route::bind('project', ...)` の binder 化は不採用: `{project}` param は API v1
(`routes/api.php`) でも使われ、API は org を API キーから確定する (`ResolvesApiOrganization`)
ため、web セッション前提の binder はコンテキスト分岐を持ち込む。middleware なら web group に
閉じて付与でき、API 側の解決モデルに触れない。

### 揃えている不変条件(これは保証し続ける)
> 「cross-org の {project} は、FormRequest の DB ルールを含むあらゆるアプリコードより前に 404
> (403 や 422 で存在を漏らさない)」
`tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` が deny-by-default で
「web の {project} route は必ず本 middleware を持つ / API は持たない」を機械検証する。
実挙動は `CategoryCrudTest` (unique 探索 404) / `VideoManualCrudTest` (exists 探索 404) が固定する。

### 関連
- 実装: `app/Http/Middleware/EnsureProjectBelongsToCurrentOrganization.php`, `routes/web.php`, `bootstrap/app.php`
- テンプレート側の根拠: `docs/app-integration-guide.md` §2 (URL 整合 guard 行を 2 層構成に更新済み)

## D5 ✅ Cut のシナリオ編集は per-row CRUD でなく document 単位保存 (PUT .../scenario)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 子リソースの書き込み | Item 見本の per-row CRUD (store/update/destroy を行単位で張る) | シナリオ (Cut 群) は `PUT /projects/{project}/manuals/{manual}/scenario` で document (steps→points ツリー) を一括保存し、サーバが 1 トランザクションで reconcile |

### なぜ正当な差分か(logic-driven)
シナリオ編集は「行追加/削除/並べ替え/手順削除で配下急所も削除」を伴う。per-row CRUD では
(a) 親子カスケード + 並べ替えの原子性が壊れる、(b) 編集途中の中間状態がサーバに漏れる。
document 保存 + 楽観ロック (`expected_version` / 409) が原子性と後勝ち破壊防止を両立する
(doc/09 §9.4 / doc/10 §10.8-2)。

### 揃えている不変条件(これは保証し続ける)
> 「保護キー不信 / 認可前 404 / relation 経由 create を document 保存でも同じ機構で維持する」
- 保護キー + サーバ導出キー (`parent_cut_id` / `adopted_take_id` / `sort_order` / `type`) は
  ネスト行にも `missing` ルールを張り送出で 422 (`UpdateScenarioRequest::nestedProtectedKeyRules` は
  `MassAssignmentProtectedKeys::all()` 由来で drift しない)
- payload の cut id は照合専用。他 manual の id 混入は 404 (存在を漏らさない)、
  階層/型変更 (step↔point) と id 重複は 422
- `{manual}` ∈ `{project}` は scopeBindings、`{project}` ∈ current org は middleware + inline guard
drift 防止テスト: `ScenarioUpdateTest` (保護キー 422 / 異物 id 404 / 409 系) と
`NestedRouteIdorDefenseTest` (`projects.manuals.scenario.update` 登録)。

### 関連
- 実装: `app/Services/Manual/ScenarioService.php`, `app/Http/Requests/Projects/UpdateScenarioRequest.php`, `app/Http/Controllers/Projects/ManualScenarioController.php`
- 設計: `devnotes/20260711-0007-scenario-editing/detailed-design.md`

## D6 ✅ presigned PUT の署名対象は ChecksumSHA256 のみ (Content-Type/Length は HeadObject 照合が担う)

| 観点 | 設計書 (T004 detailed-design) | 実装 |
|---|---|---|
| presign 署名対象 | ContentType / ContentLength / ChecksumSHA256 を署名対象に含める | **ChecksumSHA256 のみ** (query パラメータ + SignedHeaders)。ContentType / ContentLength はコマンドに渡すが署名には含まれない |

### なぜ正当な差分か(logic-driven)
AWS PHP SDK の `SignatureV4::presign` は presigned URL 生成時に Content-Type / Content-Length を
署名対象ヘッダから除外する (SDK の仕様。ブラウザ・中継系がこれらを書き換えるため)。
セキュリティ上の要点 (概念設計 D2b「この URL で置ける内容は申告ハッシュの 1 通りに固定」) は
ChecksumSHA256 の署名だけで完全に成立する: 本文がハッシュと一致しない PUT は S3 が拒否し、
本文が固定されればサイズも一意に固定される。Content-Type は課金・検証に影響しない表示属性で、
登録時の HeadObject 三点照合 (size / content_type / checksum) が不一致を削除・拒否する。

### 揃えている不変条件(これは保証し続ける)
> 「presigned URL で登録済みオブジェクトを別内容に差し替えることはできない」
`TakeObjectStorageTest` が実 SDK で `X-Amz-SignedHeaders=host;x-amz-checksum-sha256` と
checksum query の存在を固定し、`TakeRegistrationTest` が三点照合の削除・拒否を固定する。

### 関連
- 実装: `app/Services/Capture/TakeObjectStorage.php`
- 設計: `devnotes/20260711-0345-capture-pwa/detailed-design.md` 施策3

## D7 ✅ org 同時 preview 上限の「直列化実証テスト」は subprocess 方式を保留 (逐次境界テストで代替)

| 観点 | 設計 (T005 詳細設計 施策 4/15) | 本アプリ |
|---|---|---|
| 実証方法 | 親が Organization 行ロック保持 → 子プロセス (Symfony Process 起動の artisan) で triggerPreview → ロック待ち順序を同期ポイント付きで検証 | 逐次境界の Feature テスト (上限-1 通過 / 上限 409 / terminal 化で枠が空く / kind=render は数えない) + skip 済みプレースホルダテスト |

### なぜ正当な差分か (logic-driven)

テストスイートは `RefreshDatabase` をグローバル適用しており (AGENTS.md 実装規約)、テストの
フィクスチャは**未コミットのトランザクション内**にしか存在しない。別プロセス (subprocess) や
別 DB connection からは親テストの Organization / VideoManual 行が見えないため、子プロセスで
`triggerPreview` を実行する実証は**専用の非トランザクション pgsql 環境** (fixtures を実
コミットする別 lane) が前提になる。この lane 導入はテスト基盤の変更 (RefreshDatabase 規約の
例外化) を伴うため、本フィーチャの worktree では行わない。

### 揃えている不変条件 (これは保証し続ける)

> 「org 同時 preview 上限の検査 + job 作成は Organization 行ロック下で行い、異なる manual への
> 並行トリガーでも上限を超えない」

- 直列化の実装は `RenderJobService::triggerPreview` の `Organization ... lockForUpdate()`
  (`TicketLedgerService::reserve` の残高判定と同一手法 = 実証済みパターンの転用)
- 逐次境界は `tests/Feature/Manual/RenderPreviewConcurrencyTest.php` が固定
- ロック順 `video_manuals → organizations` はグローバル順の部分列 (docs/architecture.md
  §レンダジョブの運用契約が正本)
- subprocess 実証は同テストの skip プレースホルダとして残置 (専用 lane 導入時に実装する)

### 関連

- 実装: `app/Services/Manual/RenderJobService.php` (triggerPreview)
- 設計: `devnotes/20260711-0549-render/detailed-design.md` 施策 4 テスト計画

---

## D8 ✅ 管理メニューのユーザー管理 = 招待一本化 + 遷移コマンドロール + Settings からの UI 移設

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| メンバー管理 UI | `Organizations/Settings.svelte` に組織設定と同居 | 管理メニュー専用画面 `Admin/Users` (GET `/manage/users`) へ移設。Settings は組織設定 (名称 / 2FA 方針 / API キー導線 / オーナー移譲) のみ |
| ロールの語彙 | org ロール直接指定 (`organization_admin` / `organization_member`) | **3 値遷移コマンド** (`AdminConsoleRole`: admin/editor/shooter)。org ロール + Default Project pivot への「正規状態への遷移」を 1 tx で適用 (`applyConsoleRole`)。表示は導出 5 値 (`MemberRoleState`: owner/admin/editor/shooter/unassigned) |
| 招待 | org ロールのみ | `organization_invitations.project_role` (nullable・forceFill 専有) を追加し、受諾時に Default Project へ pivot attach。旧行 (null) は従来どおり org 参加のみ |
| settings() props | members に email / role / twoFactorStatus | members は `{id, name}` に縮小 (オーナー移譲 select 用途のみ = PII 最小化)。invitations prop は撤去 |
| ユーザー作成 | (doc/04 レガシーモック: 管理者がパスワード直接発行・平文一覧表示) | **招待一本化** (ユーザー ID → email へマッピング)。パスワードは本人設定のみ |

### なぜ正当な差分か (logic-driven)

doc/04 §4.2 (管理メニュー) + doc/02 §2.5 (管理者/一般の分離) + doc/10 §10.5
(project_admin=編集者 / project_member=撮影者) を、テンプレの org メンバー基盤の上に
「org ロール + Default Project pivot の合成」で実現するため。doc/04 レガシーモックの
直接発行・平文パスワード表示はセキュリティ不変条件 (PasswordPolicy / CipherSweet) と
衝突するため招待一本化に reconcile した。ロールを保存概念にしない (毎リクエスト導出) ことで
backfill 不要・非正規状態 (未割当 / stale pivot) の可視化と修復が可能になる。

### 揃えている不変条件 (これは保証し続ける)

> 「招待 token は hash-only 保存 / 重複は中立メッセージ / 権限判定は laratrust_team_id 明示 /
> Owner 昇格は transferOwnership のみ / PII 可視性は manageMembers 到達境界 (403)」

- Owner は `AdminConsoleRole` の enum 外 = 型で構造的に指定不可
- project_role はクライアント payload から受けない (role コマンドからサーバ導出 + forceFill。
  `ProhibitsProtectedKeys` は入口で保護キーを missing 強制)
- pivot 書き込み経路は `OrganizationMembershipService` / `ProjectMemberController` に閉じる
  (**`ProjectMemberPivotWritePathTest`** が deny-by-default で強制)
- `/manage/` 配下 route の auth+verified は **`ManageRouteAuthGuardTest`** が deny-by-default で強制
- drift 防止テスト: `ConsoleRoleTransitionTest` / `UserManagementPageTest` /
  `OrganizationsSettings.test.ts` (メンバー管理 UI 不在の回帰封じ)

### 関連

- 実装: `app/Enums/AdminConsoleRole.php` / `app/Enums/MemberRoleState.php` /
  `app/Services/Organization/OrganizationMembershipService.php` /
  `app/Services/Project/DefaultProjectResolver.php` /
  `app/Http/Controllers/Admin/UserManagementController.php`
- 設計: `devnotes/20260711-1009-admin-console/` (概念設計 D1/D2/D6・詳細設計 施策 1〜7)

## D9 ✅→解消 BillingAccess の entitlement 判定への書き換え (free tier は課金ゲートを通す)

> **【解消 / 2026-08-03 (T075 = 決済 parity P4)】** 本乖離は**ゲート反転で解消した**。
> 「free tier (= `plan_code` null) は課金ゲートを通す」という扱いをやめ、無料枠は
> `organizations.free_plan_code = 'personal'` の**明示申告** (`ActiveFreePlan`) で表現するようになった。
> `plan_code` は entitlement 判定に一切使わない (quota の解決キーのみ)。
> 既存組織は grandfathering backfill が `free_plan_code` を書くため締め出しは発生しない。
> 設計: `devnotes/20260717-0035-aigenba-billing-parity/` §P4。**記録は削除せず経緯として残す**。

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| BillingAccess::hasActiveAccess | `subscription('default')` が active/trialing のときのみ許可 (未契約 = fail-closed) | plan_code null (未契約 = 支払い不要 free tier) は許可 / plan_code 非 null (有償プラン契約状態) のみ active/trialing を要求 |
| 遮断時の UX | billing へ redirect (理由提示なし) / JSON 402 「有効なサブスクリプションがありません」 | billing へ redirect + 理由 flash / JSON 402 (両経路とも「サブスクリプションのお支払いが確認できないため…」で統一) |
| ダッシュボード callout | `has_active_subscription` (subscription 有無) | `has_billing_access` (billing entitlement) + 支払い方法確認 CTA |

### なぜ正当な差分か (logic-driven)

AI-CUE は「Free プランで今すぐ試せます」を掲げる freemium 設計 (pricing / home)。テンプレート
既定の「active subscription 必須」では、未契約の新規組織が business route (/projects, /app) に
一切到達できず、North Star フロー (SOP→シナリオ→撮影→動画) が入口で詰む
(bug-hunt F-07: devnotes/20260712-075854-bug-hunt)。有償価値は別レイヤで gate 済み
(チケット残高 = analyze/render、Quota = max_projects / max_storage_bytes) のため、
本ゲートの責務は「有償プラン契約中の支払い健全性の担保」のみで足りる。
なお BillingAccess docblock 自身が「アプリは本クラスの書き換えで gate 方針を変更する」と
宣言する公式拡張ポイントのため、これは構造逸脱ではなくサンクション済み拡張の記録。

### 揃えている不変条件 (これは保証し続ける)

> 「課金による利用可否の判定は BillingAccess 経由のみ / 有償契約の支払い不健全
> (past_due / canceled / incomplete / 行不在) は fail-closed で遮断 / billing・checkout は
> 構造的 allowlist で遮断中も到達可能 / plan_code は Stripe Price を持つ有償プラン契約時のみ
> webhook が set する状態キー (null = 未契約 = free tier)」

- 挙動固定: `RequireActiveSubscriptionMiddlewareTest` (F-07 再現 3 本 + 有償契約マトリクス +
  free プランが Stripe Price を持たない前提の固定 + BillingAccess 単体マトリクス)
- 遮断 UX: 同テストが flash / 402 message の文言を両経路で固定。
  ダッシュボード callout は `DashboardTest` + `Dashboard.test.ts` が固定

### 関連

- 実装: `app/Services/Billing/BillingAccess.php` /
  `app/Http/Middleware/RequireActiveSubscription.php` /
  `app/DataTransferObjects/Dashboard/BillingSummaryData.php`
- 設計: `devnotes/20260712-0927-bugfix-billing-free-access/` (概念設計 + 詳細設計 施策 1〜5)

## D10 ✅ テストレーンのグローバルロック (worktree-local flock を残さず削除)

| 観点 | テンプレート(正典 = spirux 形) | 本アプリ |
|---|---|---|
| worktree-local flock | 残す (グローバルロックとの二重ロック) | **削除する** (グローバルロックが厳密に包含するため。思考原則 3) |
| lock file 名 | `/tmp/spirux-global-test.lock` (repo 名固定) | `/tmp/global-test-lane-<uid>.d/lock` (slug 非依存 + UID 分離 + 0700 / 所有者 / symlink 検証) |
| heartbeat | 常時 30 秒 | **待機中のみ** (保持中はテストランナー自身が喋る。CI は無競合なので 1 行も出ない) |
| 再入ガード | owner-pid 一致 | **nonce 一致** (sidecar の 1 行目と env の突き合わせ。PID 再利用の穴を持たない) |
| 検証スイートの置き場 | devnotes 常駐 | **`scripts/verify-global-test-lock.sh` へ昇格 + Architecture テスト + CI ゲート** (禁止事項 1) |
| bug-hunt | (正典に記述なし) | 対象外。非干渉は保証せず best-effort の pre-flight guard のみ (残余リスクとして受容) |

### なぜ正当な差分か (logic-driven)

aicue の実装は必ず worktree で行う (AGENTS.md §worktree 運用ルール) ため、
**同一マシン上で複数のテストレーンが同時に走るのが常態**である。実在するハザードは
(H1) Browser lane の playwright 掃除が `pgrep -f` で**マシン全体**を走査して他レーンの
run-server を kill する、(H2) PostgreSQL サーバという単一共有資源の奪い合い、
(H3) devcontainer の CPU/メモリ枯渇によるタイムアウト由来の偽赤、
(H4) `flock -n` が待たずに死ぬためエージェントがリトライループを回す、の 4 つで、
**いずれも作用域はマシン (コンテナ) 全体**である。

ロックの作用域は守るべき資源の作用域と一致していなければならない。したがって
worktree-local ロックは 1 つも新しい事象を防がない (グローバルロックのスコープが
厳密に包含する)。残せば有害な `flock -n` (H4) をそのまま温存することになるため、
**同じ変更で削除する**。lock 名に slug を入れないのは `AppNameHardcodeTest` が
`scripts/` へのアプリ slug 直書きを禁じていること、および**このロックは repo を
またいで共有されて正しい** (同一マシンの PostgreSQL と CPU は repo をまたいで 1 つ) ため。

### 揃えている不変条件 (これは保証し続ける)

> 「ブロッキング取得 / 待機中の heartbeat / 再入ガード / ロック fd の非継承」の 4 要件は
> 正典と同一。差分はいずれも**同じ不変条件をより強い機構で保証する**方向である。
> 加えて aicue は「ロック保持期間 = 取得 〜 **専用プロセスグループが空になった後**」
> (親の生存期間でも直接の子の終了時点でもない) を非交渉の契約として追加している。

- 並行挙動 (層 1): `scripts/verify-global-test-lock.sh` (C01〜C24)。CI の `php` job で毎回走る
- 構造的不変条件 (層 2): `tests/Architecture/GlobalTestLockInventoryTest.php`。
  composer.json / package.json の `test*` script は明示 exemption (`test:ui` / `test:watch`) 以外
  **deny-by-default でロック経由必須**。旧 `test.lock` / `app-vitest-*` / `flock -n` / `exec` /
  lane 自前の `trap ... EXIT` / `GLOBAL_TEST_LOCK_DIR` 設定の残存を検出する
- **層 2 から層 1 を実行してはならない** (非交渉): 層 2 は `composer test` の内側 =
  ロック保持中に走るため、自己競合する

### 保証しないこと (明示)

- SIGKILL / 親のクラッシュ / コンテナ強制停止 (trap が走らない)。この場合も flock は OS が
  解放し、残留 sidecar は次の取得者がアトミックに上書きするため「ロックリーク」と
  「stale nonce による誤再入」は防ぐが、**残存子孫と次レーンの併走は防げない**
- 自ら `setsid()` / `setpgid()` で専用プロセスグループを離脱した子孫
- 規約に参加しないプロセス (bug-hunt / 未移植リポジトリ / 手打ちの `vendor/bin/pest` / 他ツール)
- 別 UID のプロセスとの H2 / H3 競合

### スコープ外の観測 (次に触る人への申し送り)

bug-hunt 自身の `.claude/bug-hunt.lock` は **worktree-local** なので、別 worktree からの
bug-hunt 同時起動は `playwright-cli kill-all` で相互破壊しうる。本設計では触っていない
(bug-hunt 側 = orchestrator と N 体の subagent worker にまたがる security-sensitive な
スクリプトの改造になり、スコープに対して過大)。同種の課題として記録に残す。

### 関連

- 実装: `scripts/global-test-lock.sh` / `scripts/with-global-test-lock.sh` /
  `scripts/verify-global-test-lock.sh` / `scripts/run-test.sh` /
  `scripts/run-browser-test.sh` / `scripts/run-vitest.sh` / `package.json`
- 設計: `devnotes/20260804-2319-global-test-lock/` (概念設計 Round 6 / 詳細設計 Round 5)
- c2c 台帳: `global-test-lock` (origin: spirux:T1109/T1110、テンプレ昇格承認済み)
