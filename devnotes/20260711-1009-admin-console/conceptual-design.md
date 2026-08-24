# 概念設計: admin-console（管理メニュー: ユーザー管理 + カテゴリ管理画面）

## 背景・課題

doc/04 §4.2 は管理者専用の「管理メニュー（ユーザー管理画面・カテゴリ管理画面）」を定義する
（モックアップ: `doc/reference/mockups/動画アプリ/PC_管理メニュー/01〜18`）。
中核パイプライン（T001〜T005: カテゴリ/動画マニュアル/AI解析/シナリオ編集/撮影 PWA）は実装済みだが、
管理者向け運用画面が欠落している。

現状の実態:

- **ユーザー管理**: org メンバー管理（招待・ロール変更・削除・2FA リセット）は
  `Organizations/Settings.svelte` + `OrganizationInvitationController` /
  `OrganizationMemberController` + `OrganizationMembershipService` としてテンプレ由来で実装済み。
  ただし (a) 組織設定（名称・2FA 方針・オーナー移譲）と混在した画面で doc/04 の
  「管理メニュー > ユーザー管理」導線がない、(b) ロールが org ロール（Admin/Member）のみで、
  AI-CUE のロールモデル（doc/10 §10.5: project_admin=編集者 / project_member=撮影者）を
  管理者が一画面で付与できない。
- **カテゴリ管理**: バックエンド（`CategoryController` store/update/destroy/reorder +
  `CategoryService` の Project 行ロック直列化）は T001 で完成。UI は `Projects/Show.svelte` に
  内包されており、doc/04 の「管理メニュー > カテゴリ管理（専用画面）」が存在しない。

## 改善アイデア（設計判断 D1〜D6）

### D1: ユーザー管理は「既存 org メンバー/招待基盤の管理者向け専用画面」として実装する（二重の user 生成機構を新設しない）

doc/04 のモックアップ（レガシー VM Director）は「管理者がユーザー ID/表示名/パスワード/メールを
直接発行し、一覧にパスワードを平文表示する」方式だが、これは採用しない。

| doc/04 の要素 | AI-CUE へのマッピング（reconcile） |
|---|---|
| ユーザー ID（英数字20字以内・重複不可） | **既存 User のログイン識別子 = email に一本化**。形式チェック + blind index（`whereBlind`）重複検査は既存基盤（`UniqueEncryptedEmail` / `OrganizationMembershipService::inviteMember` の中立 422）をそのまま使う |
| 新規登録 | **メール招待**（既存 `OrganizationInvitation` フロー再利用）。受諾 = 既存 `CreateNewUser` / `acceptInvitation` の範囲内 |
| 表示名（全角20字以内） | `User.name`（CipherSweet PII）。**招待受諾時に本人が設定**する（既存 register フロー）。管理者による他人の表示名編集は提供しない（PII は本人管理 + `UpdateUserProfileInformation` が正規経路） |
| パスワード（半角英数8〜16字） | **`PasswordPolicy`（min12 + mixedCase + numbers）が SSOT**。doc/04 の 8〜16 字は現行ポリシーより弱く、セキュリティ不変条件を緩めないため不採用。パスワードは本人設定のみ（管理者知得・平文一覧表示は非許容）。忘失時は既存パスワード再設定フロー |
| 一覧のパスワード列 | 出さない（上記により構造的に不可能 = hash 化 + 本人設定） |
| 編集 | ロール変更のみ（D2） |
| 削除（確認ダイアログ） | 既存 `removeMember`（org メンバー削除 = Owner 削除不可・最終 Owner 保護は既存 Service が担保） |

根拠: AGENTS.md 思考原則 3（後方互換の並走を残さない）・4（別物概念を統合しない）。
管理者直接発行を作ると「招待受諾ユーザー」と「発行ユーザー」の二重生成機構になり、
PasswordPolicy / CipherSweet / 招待監査の不変条件が二重管理になる。
doc/04 とのマッピング（ユーザー ID → email 等）は本設計書と `docs/template-divergence.md` に記録する。

### D2: ロールは「正規状態への遷移コマンド（管理者/編集者/撮影者）」+「導出表示状態（5 値）」の 2 層で統合する

doc/02 §2.5（管理者・一般利用者の分離）+ doc/10 §10.5（project_admin=編集者 /
project_member=撮影者）を一画面で運用できるよう、org ロールと Default Project pivot の
組み合わせを 2 層で扱う。**ロールは保存概念ではない**（DB に新カラムを持たず、
既存プリミティブから毎リクエスト導出する = backfill/migration 不要）。

**(a) 表示状態（導出。5 値・全既存データを漏れなく分類する canonical mapping）**

| org ロール | Default Project pivot | 表示状態 | 変更可否 |
|---|---|---|---|
| Owner | （不問。あれば表示上無視） | 管理者（オーナー） | 変更不可（transferOwnership のみ） |
| Admin | （不問。あれば stale とみなし表示上無視） | 管理者 | 可 |
| Member | `project_admin` | 編集者 | 可 |
| Member | `project_member` | 撮影者 | 可 |
| Member | なし | **未割当** | 可（割当を促す表示） |

- 「未割当」を第一級の表示状態にする（旧招待経由・project 削除後などの既存データも
  silent degrade せず可視化され、この画面から正規化できる）。
- org Admin/Owner に stale pivot が残っていても実効権限は org ロール側が優先
  （`ProjectPolicy` の継承規則どおり）のため表示は「管理者」。stale pivot は次の
  ロール変更コマンド実行時に掃除される（下記 (b)）。

**(b) 遷移コマンド（新 enum `AdminConsoleRole`: admin / editor / shooter）と最終状態**

| コマンド | 最終状態（1 トランザクションで保証） |
|---|---|
| → 管理者 | org ロール Admin + Default Project pivot **detach（stale pivot 掃除）** |
| → 編集者 | org ロール Member + pivot `role=project_admin`（sync） |
| → 撮影者 | org ロール Member + pivot `role=project_member`（sync） |

- **ロール変更**: 既存 `organizations.members.update`（PATCH）の契約を 3 値コマンドへ
  **書き換える**（並走 endpoint を作らない。既存 caller の棚卸し = Settings のロール select は
  D5 で本画面へ移設して消滅、既存 Feature テストは詳細設計の波及変更で列挙）。Service は
  1 トランザクションで org ロール変更（`OrganizationMembershipService::changeRole` 再利用 =
  最終 Owner 保護継承）+ pivot sync/detach を行い、中間状態を残さない。
  権限判定は既存どおり `laratrust_team_id` 明示（strict_check）。
- **編集者/撮影者は Default Project 存在が必須条件**（招待・ロール変更とも）。管理者コマンドは
  project 不要。エラー契約: FormRequest は静的入力検証（enum 等）に限定し、Default Project の
  最終存在確認 + resolver 呼び出しは **Service トランザクション内**で行う（TOCTOU 封じ）。
  不在は `ValidationException` として **Inertia error bag** へ返し（redirect + errors =
  押下時エラー表示、禁止事項 8 と整合）、Feature テストは `assertSessionHasErrors()` を基準に
  する（422 ステータス断定は JSON 要求時のみ）。
- **メンバー削除の掃除規則**: `removeMember` を拡張し、org detach と同一トランザクションで
  project pivot も detach する（現状は pivot が残り、members 一覧に stale 表示される穴がある）。
  detach 対象は **`$organization->projects()` から解決した project id 集合に限定**する
  （cross-org 不変条件。別 org の pivot が維持されることを Feature テストで固定）。
- **招待**: `organization_invitations` に nullable `project_role`（`ProjectRole` enum cast）を
  追加し、`organizations.invitations.store` の role を 3 値コマンド化（`project_role` は
  クライアントから受けず、role コマンドからサーバが導出）。受諾時（`joinOrganization` =
  register 経路 / ログイン後経路の共通コア）に Default Project へ pivot attach する。
  受諾時に project が消えていた場合（招待後の project 削除 race）は org 参加 + **未割当**に
  落ちる（明示状態のため degrade が可視・再割当可能）。既存 pending invitation
  （project_role なし）は従来どおり org role のみで受諾され、Member は未割当として入る。
- **Default Project の解決は専用 resolver に一本化**: `DefaultProjectResolver`（org の先頭
  project = `orderBy('projects.id')->first()`）を新設し、本フィーチャと
  `CaptureManualController::home`（同じ規約の重複実装）の両方をこれに寄せる。
  `organizations.default_project_id` カラムは v1 では追加しない（単一 Default Project 前提の
  うちは過剰。resolver 一本化により複数 project 化時の変更点を 1 箇所に閉じる）。
  **read / write の 2 メソッドに分離**する: 表示・redirect 用の `resolve()`（ロックなし）と、
  書き込み用の `resolveForUpdate()`（**`lockForUpdate()` 付き解決**。呼び出し側トランザクション
  内で取得から pivot 更新完了まで Project 行ロックを保持し、解決直後の project 削除競合を
  排除する。CategoryService が Project 行ロックを直列化点とする既存規約とも整合）。
  ロール変更・招待受諾の pivot 書き込みは必ず `resolveForUpdate()` 経由。

### D3: カテゴリ管理は既存バックエンドを完全再利用し、専用画面（GET index）だけを新設。Projects/Show のカテゴリ CRUD UI は専用画面へ移設する

- `CategoryController` に `index`（GET `/projects/{project}/categories`）を追加し、
  `Admin/Categories.svelte`（一覧・追加・名称編集・削除・▲▼並べ替え）を描画する。
  store/update/destroy/reorder は**既存 route/Service をそのまま使う**（バックエンド重複実装なし。
  Project 行ロック直列化・同名 422・削除で未分類化・reorder 集合一致検証は既存のまま）。
- `Projects/Show.svelte` に内包されているカテゴリ追加/編集/削除/並べ替え UI は専用画面へ**移設**し、
  Show にはカテゴリ絞り込み（フィルタ select）だけを残す（doc/04 のホーム画面仕様と一致。
  並走 UI を残さない）。
- カテゴリ名の上限は doc/10 §10.1（確定仕様）の string(50) / 既存実装 `max:50` を維持する
  （doc/04 の「20字以内」はレガシーモック値。doc/10 が優先）。同名不可（project スコープ）・
  空値不可・重複 422 は既存実装済み。

### D4: ルーティングと認可（認可前 404 / can 連動）

| route | 画面/操作 | 認可 |
|---|---|---|
| GET `/admin/users`（新設） | ユーザー管理画面（一覧 = メンバー + 招待中） | current org を `ResolvesCurrentOrganization` で解決し `manageMembers`（= org Owner/Admin）。撮影者・一般（org Member）は **403** |
| POST `/organizations/{organization:slug}/invitations`（既存） | ユーザー追加（招待、3 値ロール） | 既存 `manageMembers` |
| PATCH `/organizations/{organization:slug}/members/{user}`（既存） | ロール変更（3 値合成） | 既存: `{user}` ∈ org の URL 整合 guard →**認可前 404**、`manageMembers`。IDOR inventory 登録済み（UrlIntegrityGuard） |
| DELETE `/organizations/{organization:slug}/members/{user}`（既存） | ユーザー削除（確認ダイアログ） | 同上 |
| GET `/projects/{project}/categories`（新設） | カテゴリ管理画面 | 既存業務 group 内（`require-active-subscription` + `project.in-route-org` = cross-org は**認可前 404**）+ `CategoryPolicy`（`ProjectPolicy::update` 委譲 = org Owner/Admin または project_admin）。撮影者は **403** |
| POST/PATCH/DELETE categories（既存） | 追加/編集/削除/reorder | 既存のまま（scopeBindings + IDOR inventory 登録済み） |

- 新設 GET 2 本はどちらも route param 1 個以下のため `NestedRouteIdorDefenseTest` の
  inventory 追加対象外（2+ param なし）。既存 write 系の登録は現状維持。
- 操作系 POST/PATCH/DELETE の応答はすべて既存どおり `back()->with(...)`（`redirect()->intended()` 不使用）。
- `/admin/users` は org 管理系（テンプレの organizations.* と同格）として課金ゲート外に置く
  （未契約でもメンバー整理は可能 = 既存 organizations.members.* と整合）。
  カテゴリ管理は project 配下の業務 route のためゲート内（既存 group）に置く。

### D5: フロント（サイドバー導線 + 2 画面。DS token のみ / disabled 禁止 / Lucide）

- **管理メニュー nav**: `components/features/admin/AdminMenuNav.svelte`（新設）。
  「ユーザー管理」「カテゴリ管理」リンクを持ち、両管理画面の左カラムに置く
  （モック 02/10 の 管理メニュー サイドバー）。表示は can 連動
  （`canManageMembers` / `canManageCategories` + カテゴリ管理は Default Project の id を props で受ける。
  project 不在・権限なしの項目は**非表示**）。
- **ホーム導線**: `Projects/Show.svelte` のサイドバー相当領域に管理メニュー導線を追加
  （`canManage` は既存 props、`canManageMembers` を新規 props で追加。権限がなければ非表示 =
  doc/04「管理者ログイン時のみサイドバーに表示」）。
- **`Admin/Users.svelte`**（新設）: メンバー一覧（表示名 / メール / ロール select / 2FA リセット /
  削除）+ 招待中一覧（取消）+ ユーザー追加（email + ロール 3 値）。削除は `ConfirmDialog`
  （モック 08 削除アラート）。エラーは押下時表示（disabled にしない = 禁止事項 8）。
  Inertia props は PHP 側 DTO（`DataTransferObjects\Admin\*`）+ TS interface の両面で契約を固定
  （PHPStan level 10）。ロール select は表示状態 5 値を表示し、変更可能な選択肢は
  遷移コマンド 3 値（D2）。
- **`Admin/Categories.svelte`**（新設）: カテゴリ一覧（▲▼ / 名称編集 / 削除）+ カテゴリ追加。
  Projects/Show から移設するフォーム/ダイアログ実装を流用（モック 10〜17 の導線:
  追加/エラー表記/追加表示/更新表示/削除表示）。
- **`Organizations/Settings.svelte` のスリム化**: メンバー一覧・招待・2FA リセット UI を
  Admin/Users へ移し、Settings には組織設定（名称・2FA 必須方針・オーナー移譲）のみ残す
  （オーナー移譲の移譲先 select 用に members 最小 props は残す）。
- **旧 UI の完全撤去は同一 PR の完了条件**（AGENTS.md 思考原則 3: 並走を残さない）:
  Projects/Show のカテゴリ CRUD UI・Settings のメンバー管理 UI は移設と同時に削除し、
  「Projects/Show にカテゴリ CRUD が無い」「Settings にメンバー管理 UI が無い」ことを
  Vitest の回帰テストで固定する。backend route は書き換え（members.update の 3 値化）以外
  変更しない。

### D6: PII 可視性・セキュリティ不変条件の維持

- `Admin/Users` の email/name（CipherSweet PII）は **manageMembers 権限者にしか画面自体を返さない**
  （403）ため、`ProjectShowEmailVisibility` 契約（can に連動した可視性の単一根拠）と同じ思想を
  「画面到達 = can('manageMembers')」で満たす。検索を追加する場合は `whereBlind` のみ（平文 where 禁止）。
- tenant/所有権キーは payload から受けない（既存 `ProhibitsProtectedKeys` 維持。
  `project_role` はクライアントから直接受けず、`role`（AdminConsoleRole 3 値）からサーバが導出する）。
- cross-org: `/admin/users` は current org 解決のみで org param を持たない（越境不能）。
  カテゴリ画面は既存 2 層 guard（middleware + inline）で cross-org 404（存在オラクル封じ）。

## 期待効果

- doc/04 §4.2 の管理メニュー（管理者専用のユーザー/カテゴリ管理）が完成し、
  「現場ユーザーのオンボーディング（招待→撮影者/編集者付与）」と「カテゴリ体系の運用」を
  管理者がセルフサービスで回せる（= 使命の「専門知識ゼロの現場でも回る」運用面の欠落を埋める）。
- ロール付与が「遷移コマンド 1 セレクト」になり、org ロールと project pivot の二段操作
  （現状は Settings と Projects/Show に分散し、pivot 付与導線は管理者向けに存在しない）が
  1 画面 1 操作に集約される。未割当・stale pivot などの非正規状態も可視化・正規化できる。
- doc/04 レガシーモックの「パスワード直接発行・平文一覧表示」というセキュリティ非許容方式を
  構造的に排除し（招待一本化）、PasswordPolicy / CipherSweet の不変条件を単一経路に保つ。
- カテゴリ管理バックエンドは再利用のみ（回帰リスク最小）で doc/04 の専用画面要件を満たす。

## 実装方針（概要）

実装は 3 スライスに分割する。**A + B は不可分（同一 PR・同一リリース単位）**:
`members.update` / `invitations.store` の 3 値コマンド契約への書き換え（A）と、その唯一の
caller UI（B の Admin/Users + Settings スリム化）を分離すると、旧 Settings UI が旧契約値を
送信し続ける並走/破壊状態が生じるため。**C のみ独立に実装・レビュー可能**。

**スライス A: ロール正規化 + 招待/受諾 + resolver（バックエンド）**

| 層 | 変更 |
|---|---|
| Enum | `App\Enums\AdminConsoleRole`（admin/editor/shooter = 遷移コマンド）新設 |
| Migration | `organization_invitations.project_role`（string, nullable, `ProjectRole` enum cast）追加 |
| Service | `DefaultProjectResolver` 新設（capture.home の重複規約も寄せる）。`OrganizationMembershipService`: inviteMember に projectRole 追加・joinOrganization で Default Project attach（不在は未割当）・遷移コマンド適用メソッド（org ロール + pivot を 1 tx、stale pivot 掃除）・removeMember に project pivot detach 追加 |
| Controller/Request | `OrganizationInvitationController@store` / `OrganizationMemberController@update` を 3 値コマンド対応へ書き換え。FormRequest は `Rule::enum(AdminConsoleRole)` + 型付きアクセサ `role(): AdminConsoleRole`（`$this->enum()` の結果を Assert で narrow）で Service へ enum を渡す。Default Project 存在確認は Service tx 内（不在は error bag） |

**スライス B: ユーザー管理画面 + Settings スリム化**

| 層 | 変更 |
|---|---|
| Controller/DTO | `Admin\UserManagementController@index`（GET のみ）。props は行 DTO（`App\DataTransferObjects\Admin\MemberRowData` / `InvitationRowData`。Capture ドメインの DTO パターン踏襲）+ トップレベルの明示 array shape（docblock）で PHP 側契約を固定 |
| Routes | GET `/admin/users`（auth+verified。課金ゲート外 = organizations.* と同格） |
| Svelte | `Admin/Users.svelte`・`features/admin/AdminMenuNav.svelte` 新設。`Organizations/Settings.svelte` からメンバー管理 UI を撤去（同一 PR 完了条件） |

**スライス C: カテゴリ管理画面 + Show 移設**

| 層 | 変更 |
|---|---|
| Controller | `Projects\CategoryController@index` 追加（既存 write は無変更） |
| Routes | GET `/projects/{project}/categories`（既存業務 group 内） |
| Policy | `CategoryPolicy::viewAny(User, Project)` 追加（`ProjectPolicy::update` 委譲 = 管理画面到達境界） |
| Svelte | `Admin/Categories.svelte` 新設。`Projects/Show.svelte` からカテゴリ CRUD UI を撤去（フィルタ select は残す。同一 PR 完了条件）+ 管理メニュー導線追加 |

**テスト / Docs（各スライスに内包）**

- 権限（両画面: admin 200 / 撮影者・一般 403 / サイドバー・導線の can 連動非表示 /
  カテゴリ画面 cross-org 404）
- 招待入力制約（email 形式・重複の中立エラー・role 不正・editor/shooter × project 不在。
  いずれも `assertSessionHasErrors()` 基準の Inertia error bag 契約）
- ロール遷移（editor⇄shooter⇄admin の最終状態 = org ロール + pivot、admin 昇格時の stale pivot
  掃除、未割当→割当、最終 Owner 保護継承、非メンバー 404、removeMember の pivot 掃除）
- 受諾（project_role 付き受諾で pivot attach、受諾時 project 不在は未割当、旧 invitation
  互換受諾）、`DefaultProjectResolver` の挙動
- カテゴリ画面（reorder 反映・同名 422・削除未分類化 = 既存バックエンド経由の画面テスト）、
  保護キー 422、既存テスト資産の role 値棚卸し更新
- Vitest（描画・disabled 不使用・押下時エラー・旧 UI が Projects/Show / Settings に無いことの回帰）
- `docs/template-divergence.md` に「ユーザー管理 = 招待一本化 + 遷移コマンドロール + Settings
  からの UI 移設」を記録。`docs/architecture.md` へ管理メニュー節を追記 |

## 制約・前提

- v1 は単一 Default Project 前提（AGENTS.md）。Default Project 解決は org 先頭 project
  （capture.home と同一規約）。複数 project 化の際は本画面にプロジェクト選択が必要（スコープ外）。
- Laratrust strict_check（`laratrust_team_id` 明示）・CipherSweet whereBlind・
  `back()->with(...)` 応答・DS token/disabled 禁止・Lucide のみ、は全て既存規約を踏襲。
- テンプレの Filament 管理画面（super-admin: AdminUser/Organization 等）は横断運用者向けの別物であり
  本フィーチャと無関係（統合しない）。

## スコープ外

- テンプレ Filament の拡張、外国語シナリオ編集（doc/04 §4.3 ユースケース未定義）、動画トリム
- 管理者によるパスワード直接発行・他人の表示名編集（D1 の理由により提供しない）
- ユーザー一覧の検索・複数プロジェクト対応・監査ログ画面
