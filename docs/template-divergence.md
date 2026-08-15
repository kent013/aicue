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
| 招待 | org ロールのみ | **org ロールのみ (テンプレートと同じ)**。一度 `organization_invitations.project_role` を追加して受諾時に Default Project へ pivot attach する差分を持っていたが、裁定 AG-079 (Default Project という概念自体が不要) で**列ごと撤去**し逸脱を戻した。編集者 / 撮影者は参加後にロール割当コマンドで付与する |
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

- Owner はメンバーのロール変更では `AdminConsoleRole` の enum 外 = 型で構造的に指定不可。
  招待では `Rule::enum(OrganizationRole)->except([Owner])` が構造的に拒否する
  (**ロール語彙の非対称**: 招待は org ロール 2 値 / メンバーのロール変更は 3 値コマンド。
  招待は「組織に入れる」だけを意味し、編集者 / 撮影者の割当は参加後の別操作である)
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
- 設計: `devnotes/20260711-1009-admin-console/` (概念設計 D1/D2/D6・詳細設計 施策 1〜7) /
  `devnotes/20260807-2032-invitation-in-app-acceptance/` (役割付き招待の撤去 = 裁定 AG-079)

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
| ダッシュボード callout | `has_active_subscription` (subscription 有無) | `billing_state` (`OnboardingBillingState` の 5 値) による状態別 callout。未契約はプラン選択 CTA / 支払い不健全は支払い方法確認 CTA (真偽値に潰さない。T150) |

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

---

## D11 ✅ svelte-no-undef-gate を config 静的検査型で別実装 (同一不変条件・別実装)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| gate 実装 | `tests/js/architecture/svelte-no-undef-gate.test.ts` (実装未確認 = mirror 未取得) | 同名ファイルを **ESLint `calculateConfigForFile()` による実効設定の静的検査**として独自実装 |

### なぜ正当な差分か(logic-driven)

c2c 台帳 `atomic-design-gates` の AG-023 裁定 (2026-08-05) で
「aicue に svelte-no-undef-gate を補完する」ことは確定しているが、
laravel-claude-template の mirror が本環境に無く、テンプレ実装を読めない。
実装を待って不変条件を無防備のまま放置するより、
**同じ不変条件を別実装で先に固定する**方が実害を早く閉じられる。

### 揃えている不変条件(これは保証し続ける)

> A. 「`resources/js` 配下の**全** `.svelte` で ESLint `no-undef` が error である」
> B. 「その `languageOptions.globals` は**実行時グローバル**
>    (`globals.browser` + `eslint.config.js` の `APP_RUNTIME_GLOBALS` 明示登録) と
>    **完全一致**する — 型専用名を混ぜて no-undef を骨抜きにしない」
> C. 「**lint 対象の全ファイル**
>    (= `pnpm lint` = `eslint resources/js` の範囲 × `eslint.config.js` が `files` で
>    対象にしている全拡張子: `.svelte` / `.js` / `.mjs` / `.cjs` / `.ts` / `.jsx` / `.tsx`) で
>    `linterOptions.noInlineConfig` が true であり、inline の eslint-disable が効かない」

`tests/js/architecture/svelte-no-undef-gate.test.ts` が
ESLint 公開 API `calculateConfigForFile()` で実効設定を解決し、
A/B を全 `.svelte` に、C を lint 対象全ファイルに適用して検査する。
走査 0 件でも fail する (空振り防止)。
検査ロジックは純関数 (`assertSvelteNoUndefConfig` / `assertNoInlineConfig`) に切り出し、
正負のコントロールで検出器の実効性を固定している
(ESLint の flat config マージ規則そのものは試験対象にしない)。

**運用契約 1 (noInlineConfig 体制)**: ルールを黙らせる唯一の手段は
`eslint.config.js` の file-scoped override。override を認めるのは
(a) 抑制対象が具体的な 1 ファイル (または明示列挙) に閉じている
(b) なぜ安全かがコード側コメントで説明されている
(c) config 側に理由と再検討条件が書かれている — の 3 条件をすべて満たすときだけ。

**運用契約 2 (宣言と検査範囲の一致)**: `pnpm lint` の対象を広げる
(引数ディレクトリを増やす / 新しい拡張子を扱う) ときは、本 gate の
`LINT_TARGET_EXTENSIONS` と走査ルートも**同一 PR で**広げること。
宣言 (config コメント) と検査範囲が乖離すると「守っているつもりの穴」ができる。

### 収束条件

laravel-claude-template の mirror が取得できた時点でテンプレ実装と突き合わせ、
実装を寄せられるなら本エントリを解消する。

### 関連

- 実装: `tests/js/architecture/svelte-no-undef-gate.test.ts`, `eslint.config.js`
- 設計: `devnotes/20260805-0101-frontend-baseline-gates/detailed-design.md` 施策 4
- 台帳: c2c `atomic-design-gates` AG-023 (2026-08-05 裁定), `eslint-svelte-ts-baseline`

---

## D12 ✅ ページタイトル / description はサーバ単一 SoT (helper 経由必須の JS 契約は不採用)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| `<title>` の供給 | ページ側が JS helper 経由で宣言する契約 (helper 経由必須を frontend gate で強制) | サーバが単一 SoT。`SeoManager::resolveDocumentTitle()` が Blade `<title>` と Inertia 共有 prop `title` の両方へ同じ文字列を流す |
| `<meta name="description">` | ページ側 helper の守備範囲 | サーバのみ (`config('seo.default_description')` → `SeoMeta::$description` → `SeoRenderer::render()`)。認証配下 (`renderPrivate`) は**意図的に出さない** |
| frontend gate の役割 | 「helper を経由していること」を強制 | 「クライアントに第二 SoT を作らせない」を強制 (`<svelte:head>` の `<title>` / `<meta name="description">` を禁止) |

### なぜ正当な差分か(logic-driven)

本アプリは **SEO ヘッドをサーバ描画に一本化している** (`app/Support/Seo/`)。
クローラが読む正本はサーバが描画した `<head>` であり、title / canonical / og / JSON-LD は
すべて `config/seo.php` を起点に組み立てる。この構造では、ページ固有名の供給点は
`config('seo.app_titles')` (route 既定) と `SeoManager::setPrivateTitle()` (controller の
動的上書き) の 2 つで完結し、**JS helper を挟む層が存在しない**。

テンプレートの「helper 経由必須」契約は「ページ側が title の一次情報を持つ」前提の設計だが、
本アプリでは title の一次情報は **controller / config** が持つ。同じ契約を移植すると
一次情報が 2 箇所に分かれ、**フルロードと SPA 遷移でタイトルが食い違う**という
テンプレートが防ごうとしたまさにその破綻を招く。よって helper 契約は不採用とし、
同じ不変条件を「第二 SoT の禁止」という別の機構で保証する。

### 揃えている不変条件(これは保証し続ける)

> `<title>` の SoT はサーバ (`SeoManager::resolveDocumentTitle`) ただ 1 つであり、
> **フルロードと SPA 遷移で一致する** (共有 prop `title` + `resources/js/lib/document-title.ts`)。
> `<meta name="description">` は **サーバが生成する初回 HTML のみを SoT とし**、
> クライアントから第二 SoT や重複タグを作らない。

**title と description で射程が違う点に注意**: `HandleInertiaRequests::share()` が
共有するのは `title` のみで、description に SPA 同期経路は無い。description の読み手は
クローラであり、クローラが読むのは初回 HTML なので、SPA 遷移後の追従は保証しない
(必要になったら共有 prop + クライアント反映機構 + テストを別途設計する)。

どの機構でカバーするか:

- **`DocumentTitleCoverageTest`** (Architecture): Inertia を render する GET named route が
  必ずページ固有名を持つことを deny-by-default で強制する (未網羅は fail。
  action を静的解決できない route は理由付き allowlist が必須)
- **`tests/js/architecture/svelte-head-no-title.test.ts`**: `resources/js/pages/**/*.svelte` の
  `<svelte:head>` に `<title>` / `<meta name="description">` を書かせない
  (`<svelte:head>` 自体は preload hint 等のため許可)
- **`tests/Feature/Seo/SeoManagerTest.php`**: 解決優先順位と各 route の固有 title を仕様固定

### 関連

- 実装: `app/Support/Seo/SeoManager.php` / `app/Support/Seo/SeoRenderer.php` /
  `app/View/Composers/SeoComposer.php` / `app/Http/Middleware/HandleInertiaRequests.php` /
  `resources/js/lib/document-title.ts` / `config/seo.php`
- 設計: `devnotes/20260805-0101-architecture-gate-followup/`
- c2c 台帳: `gate-document-title-coverage` / `page-title-frontend-contract`

---

## D13 ✅ SSO 登録ユーザーの password を保存しない (phantom password の撤去。前方修正のみ)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| SSO 登録時の `users.password` | `Str::password(32)` をハッシュ化して保存 | **保存しない (null のまま)** |
| `User::hasPassword()` の意味 | SSO-only ユーザーでも常に true (docblock 自身が「テンプレート標準では常に true」と明記) | 「password 経路が実際に使えるか」を返す |
| 既存データの扱い | — | **遡及是正しない (前方修正のみ)** |

### なぜ正当な差分か(logic-driven)

本アプリは password 以外のログイン手段 (SSO / パスキー) を第一級に扱い、
「**ログイン手段が 0 になる操作を止める**」ことを不変条件にしている
(`EnsureLoginMethodRemains` / `LoginMethodInventory`。docs/auth-security-mechanisms.md §6)。
この不変条件は `hasPassword()` が真実を返すことに依存する。

phantom password (ランダム値) が入っていると:

1. `LoginMethodInventory` が SSO-only ユーザーにも `password` を数え、
   **唯一のパスキーを削除できてしまう** (guard が形骸化する)。
2. recent-auth の `passwordSet` が true になり、SSO-only ユーザーの再認証モーダルが
   **入力しても必ず失敗するパスワード欄**を出す (詰み画面)。

`users.password` は migration が nullable で作られており
(`0001_01_01_000000_create_users_table.php`)、`UserFactory::ssoOnly()` も
`password => null` を前提にしている。つまりスキーマとテスト補助は既に「null を許す」側で、
`Str::password(32)` だけが取り残されていた。

### 射程 (既知の制約として残すもの)

**前方修正のみ**。本変更**以前**に SSO 登録されたユーザーの phantom password はそのまま残り、
そのユーザーに限り上記 1 / 2 の誤差が続く。遡及移行 (既存 SSO ユーザーの password を null 化) は
**「password を先に登録してから SSO を連携したユーザーの実パスワードを消す」**危険があり、
`password_changed` の監査証跡が無い時代のユーザーを機械的に判別できないため行わない。

判別材料を今後蓄積するため、`UpdateUserPassword` から
`SecurityEventType::PasswordChanged` を記録するようにした
(enum に存在しながら記録経路が無かった。`/reset-password` 経路は
`Illuminate\Auth\Events\PasswordReset` 経由で既に記録済み)。

### 揃えている不変条件(これは保証し続ける)

> 「`User::hasPassword()` は password 経路の可否を **fail-closed** で判定する」

- `tests/Feature/Auth/SocialAuthTest.php`: SSO register 後の `password` が null /
  `hasPassword()` が false / `email_verified_at` は従来どおり非 null (T105 との相互作用の回帰)
- `tests/Feature/Auth/RecentAuthTest.php`: SSO 登録直後の `/recent-auth/status` が
  `passwordSet: false` / `canSatisfy: true` (再SSO が satisfier)
- `tests/Feature/Auth/LoginMethodInventoryTest.php`: `ssoOnly()` ユーザーの手段集合に
  `password` が含まれない

### 関連

- 実装: `app/Services/Auth/SocialAccountService.php` / `app/Actions/Fortify/UpdateUserPassword.php` /
  `app/Services/Auth/LoginMethodInventory.php`
- 設計: `devnotes/20260805-1244-auth-method-and-passkey/` (施策 2)

---

## D14 ✅ 実行済み route の記録をアプリ側の観測器で採る (退避 → 正規化 → route 名解決の 3 段を置かない)

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 「どの操作を叩けたか」の採取 | ブラウザの通信履歴を退避 → 正規化器 → artisan コマンドで route 名解決、の 3 段 | **serve のプロセス内で middleware が 1 要求 1 行を追記**する (`BughuntExecutedRouteMiddleware`) |
| 採取の起動 | 走行中の LLM (探索エージェント) が退避コマンドを呼ぶ | 起動時に `provision` が env で仕込み、以後は無条件 |
| 遮断された要求の扱い | 通信履歴なので 302/403 も「叩いた」側に残り、後段で除外しきれない | 遮断 middleware より**内側**に置いてあるため、そもそも記録に現れない |
| 主入力が欠けたとき | 照合器が「全 in_scope を未実行 candidate」として出力し 0 で終わる | **終了コード 3 で落ちる** (worklist を出さない) |

### なぜ正当な差分か(logic-driven)

操作到達カバレッジの出力は「次に何を叩くべきか」という作業指示であり、
**記録が採れていないこと**と**本当に叩けていないこと**を取り違えると、
一覧そのものが嘘になる (全機構が未実行に見える)。3 段方式はこの取り違えを
2 か所で作っていた:

1. **採取の起動が LLM に依存する**。退避コマンドを呼び忘れた走行は、
   記録が空のまま「全部未実行」として成功終了する。
2. **通信履歴は遮断された要求も含む**。認証・課金ゲート・step-up 再認証で
   跳ねた 302 は「叩いた」ように見えるが、controller には到達していない。
   route 名の再解決は URL からの逆引きなので、この差を後段では復元できない。

アプリ側の観測器は、web グループの**末尾** (priority list の鎖の最後) に置くことで
「ここに到達した = 遮断 middleware をすべて通過した」という機械的事実を得る。
route 名は `$request->route()->getName()` でその場で確定するので逆引きも要らない。
起動は `scripts/bug-hunt-shard.sh provision` が env で仕込むため LLM の手順に依存しない。

### 揃えている不変条件(これは保証し続ける)

> 「**主入力が揃わない走行は成功にしない**」

- `scripts/bug-hunt-shard.sh provision` は疎通確認の要求が実際に記録されたことを
  同期点として確認し、記録されなければ**走行前に**落ちる (`assert_executed_capture_wired`)
- `coverage/build_executed.py` は失敗マーカー / ファイル欠落 / 壊れた行 / 別 run の混入 /
  **名前付き route の観測行が 0** のいずれでも**終了コード 3** で落ち、`executed.json` を
  書き出さない (`route_name: null` の行しか無い shard もここで落ちる)
- `coverage/correlate.py` は `--executed` 未指定 / 形が契約外 / run_id 不一致 /
  shard 宣言と実測の食い違い / 観測行 0 のいずれでも**終了コード 3** で落ち、
  未実行 worklist を出力しない
- 記録器が遮断 middleware より内側に居ることは
  `tests/Architecture/BughuntExecutedRouteOrderingTest.php` が deny-by-default で固定する
  (短絡しうる middleware の分類は `tests/Support/Routing/MiddlewareShortCircuitInventory.php`)
- 記録器が既定 no-op であること (env 既定 false + production 除外) と ok/blocked の写像は
  `tests/Feature/Bughunt/ExecutedRouteCaptureTest.php` が実 HTTP 要求で固定する

### 保証しないもの (誇張しない)

- **web グループ外は観測しない** (`api/*` / Filament `/admin` / MCP)。分母に載っていれば
  未実行側へ倒れる (過小申告の方向)
- **部分欠測は検出しない**。分かるのは「名前付き route の行が 1 件も無い」「別 run が混ざった」
  「失敗マーカーが残せた」まで
- **偽造耐性は無い**。記録ファイルは worktree 内にあり、書き換えを検出する仕組みは持たない

### 関連

- 実装: `app/Http/Middleware/BughuntExecutedRouteMiddleware.php` / `config/bughunt.php` /
  `bootstrap/app.php` / `scripts/bug-hunt-shard.sh` /
  `.claude/skills/app-bug-hunt/coverage/build_executed.py` /
  `.claude/skills/app-bug-hunt/coverage/correlate.py`
- 設計: `devnotes/20260815-1113-bughunt-route-capture-failclosed/`

---

## D15 ✅ strict_types gate の走査域を追跡下 PHP 全数にし、未宣言一覧を持たない

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| テスト名 | `StrictTypesBaselineInvariantTest` | `StrictTypesDeclarationGateTest` |
| 走査域 | `app/` のファイル走査 | **git 追跡下の `*.php` − `*.blade.php`** (実測 1543 本) |
| 未宣言一覧 (baseline) | 常に空を契約として保持 | **持たない** (免除機構そのものが無い) |
| 判定器 | 構造判定 (値は数値リテラル 1 の完全一致) | 構造判定 + **実測照合器との突き合わせ** |

### なぜ正当な差分か(logic-driven)

走査域を `app/` に限ると、`config/` `database/` `bootstrap/` `public/` の未宣言 28 本が
規約の外に残り続ける。本アプリは容量予約 (bytes) やチケット枚数のように、数値と文字列の
取り違えがそのまま容量・金額の誤りになる領域を持つため、「どこか 1 枚だけ緩い」状態を
残さないことに意味がある。実測ではこの 28 本は 1 行の追加だけで解消でき、うち 25 本は
PHPStan level 10 の解析対象でもあるので、**追加した宣言の副作用は静的解析で機械的に確認できる**。

未宣言一覧 (baseline) を持たないのは、導入時点の未宣言 32 本を同一変更で是正して 0 件から
始めるためである。空の登録簿とそれを双方向比較する仕組みは 1 件も違反を守らないまま
複雑さだけを足すことになる (「今必要なものだけ作る」)。既存の
`QueueDispatchAtomicityInventoryTest` が採っているのと同じ形である。

判定器が実測照合器 (`StrictTypesRuntimeProbe`) と突き合わせるのは、「判定器は宣言済みと
言うのに実際は厳密化されない」という**逆向きの乖離 = fail-open** を機械的に 0 件へ固定する
ためである。判定器は実効性の下界であり、安全側の乖離 (実効だが受理しない形) だけを許す。

### 揃えている不変条件(これは保証し続ける)

> 「宣言を欠く PHP ファイルが新しく増えない」

- 走査域が広いので、テンプレートが保証する `app/` の範囲は**包含している**
- 空の baseline と本アプリの「登録簿なし」は、守っている集合が同じ (未宣言 0 件)
- テンプレート取り込みで `StrictTypesBaselineInvariantTest` が入ってきた場合は、
  **2 本立てにせず本 gate へ統合する** (同じ事実を 2 箇所で宣言しない)
- どうしても宣言できないファイルが将来出た場合も、なし崩しに allow-list を足さない。
  設計レビューを通してから機構を新設する

### 保証しないもの (誇張しない)

- `artisan` など拡張子が `.php` でない PHP ファイル / 未追跡 (git add 前) のファイルは見ない
  (gate が守る境界は commit / CI である)
- `*.blade.php` は見ない。テンプレートであり PHP ソースファイルではないため、免除ではなく対象外
- 宣言の有無だけを見る。型の緩さそのもの (level 10 で検出される型不一致) は PHPStan の担当
- 実効ではあるが正準形でない書き方 (`01` / `0x1` / `declare(ticks=1, strict_types=1)` 等) は
  **受理しない** (安全側の乖離)。**冒頭の正準形より後ろに `strict_types` を含む declare が
  ある形も受理しない** (現行 PHP の実効は strict のままだが、表記を揃える規約であり、
  「後に書いた方が勝つ」へ仕様が変わったときの fail-open も同時に塞ぐ)
- `php artisan vendor:publish` 直後に宣言が失われることは防げない (検出はする)

### 関連

- 実装: `tests/Architecture/StrictTypesDeclarationGateTest.php` /
  `tests/Support/StrictTypesDeclarationScanner.php` /
  `tests/Support/StrictTypesRuntimeProbe.php` / `tests/Support/TrackedPhpSourceFiles.php`
- 自己検査: `tests/Unit/Architecture/StrictTypesDeclarationScannerTest.php` /
  `tests/Unit/Architecture/TrackedPhpSourceFilesTest.php`
- 設計: `devnotes/20260815-1534-strict-types-baseline-gate/`
- テンプレート側の根拠: `tests/Architecture/StrictTypesBaselineInvariantTest.php`
  (家系の裁定 AG-010 (2026-08-05)「テンプレートへ還流し家系の標準装備とする」)
