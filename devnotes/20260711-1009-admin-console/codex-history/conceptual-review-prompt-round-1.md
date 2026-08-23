# レビュー依頼: 管理メニュー（ユーザー管理 + カテゴリ管理画面）概念設計

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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【セキュリティ不変条件（アプリ都合で緩めない）— AGENTS.md より】

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない(`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**(`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【補足コンテキスト】
- 対象リポジトリは /workspace（読み込み可）。確定仕様は doc/10_実装仕様.md、機能仕様は doc/04_PCサイト機能仕様.md §管理メニュー。
- 既存基盤: 認証 = Fortify + メール招待（OrganizationInvitation, token_hash 保存）、User の email/name は CipherSweet 暗号化 + blind index、パスワード強度 SSOT = App\Support\PasswordPolicy（min12+mixedCase+numbers）。
- org メンバー管理は app/Http/Controllers/Organizations/{OrganizationInvitationController,OrganizationMemberController}.php + app/Services/Organization/OrganizationMembershipService.php が既存（UI は resources/js/pages/Organizations/Settings.svelte に内包）。
- カテゴリは app/Http/Controllers/Projects/CategoryController.php + app/Services/Manual/CategoryService.php（Project 行ロック直列化、store/update/destroy/reorder 完備、UI は resources/js/pages/Projects/Show.svelte に内包）。
- ロール: org = Owner/Admin/Member（Laratrust, laratrust_team_id 明示）、project = project_members pivot（admin=編集者 / member=撮影者, doc/10 §10.5）。ProjectPolicy::update = org Owner/Admin または project_admin。
- doc/04 のレガシーモックアップは「管理者がユーザー ID/PW を直接発行し一覧にパスワードを平文表示」だが、設計はこれを email 招待一本化へ reconcile している（設計 D1）。この reconcile の妥当性も重要な論点。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下、devnotes/20260711-1009-admin-console/conceptual-design.md の全文）

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

### D2: ロールは AI-CUE 3 值（管理者/編集者/撮影者）の単一セレクトに統合し、既存プリミティブへ合成する

doc/02 §2.5（管理者・一般利用者の分離）+ doc/10 §10.5（project_admin=編集者 /
project_member=撮影者）を一画面で運用できるよう、管理 UI のロールを 3 値に統一する:

| UI 表示 | 実体（既存プリミティブへの合成） |
|---|---|
| 管理者 | org ロール Admin（Owner は「管理者（オーナー）」表示・変更不可 = 既存 transferOwnership のみ） |
| 編集者 | org ロール Member + **Default Project** の project_members pivot `role=admin`（project_admin） |
| 撮影者 | org ロール Member + Default Project の pivot `role=member`（project_member） |

- 新 enum `AdminConsoleRole`（admin / editor / shooter）を導入し、表示とバリデーションの単一根拠にする。
- **ロール変更**: 既存 `organizations.members.update`（PATCH）を 3 値対応へ**書き換える**
  （並走 endpoint を作らない）。Service は 1 トランザクションで org ロール変更
  （`OrganizationMembershipService::changeRole` 再利用 = 最終 Owner 保護継承）+
  Default Project pivot の `syncWithoutDetaching` / `detach` を整合的に行う。
  権限判定は既存どおり `laratrust_team_id` 明示（strict_check）。
- **招待**: `organization_invitations` に nullable `project_role`（`ProjectRole` 値）を追加し、
  `organizations.invitations.store` の role を 3 値化。受諾時（`joinOrganization` = 既存の
  register 経路 / ログイン後経路の共通コア）に Default Project へ pivot attach する
  （project 不在なら org 参加のみ = fail-soft）。
- **Default Project の解決**: v1 は単一 Default Project（org の先頭 project。
  `CaptureManualController::home` と同じ `orderBy('projects.id')->first()` 規約）。

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
  Inertia props は typed array + TS interface。
- **`Admin/Categories.svelte`**（新設）: カテゴリ一覧（▲▼ / 名称編集 / 削除）+ カテゴリ追加。
  Projects/Show から移設するフォーム/ダイアログ実装を流用（モック 10〜17 の導線:
  追加/エラー表記/追加表示/更新表示/削除表示）。
- **`Organizations/Settings.svelte` のスリム化**: メンバー一覧・招待 UI を Admin/Users へ移し、
  Settings には組織設定（名称・2FA 必須方針・オーナー移譲・ownership 系）のみ残す
  （オーナー移譲の移譲先 select 用に members 最小 props は残す）。
  並走 UI を残さないための移設であり、backend route は変更しない。

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
- ロール付与が 3 値 1 セレクトになり、org ロールと project pivot の二段操作（現状は
  Settings と Projects/Show に分散）が 1 画面 1 操作に集約される。
- カテゴリ管理バックエンドは再利用のみ（回帰リスク最小）で doc/04 の専用画面要件を満たす。

## 実装方針（概要）

| 層 | 変更 |
|---|---|
| Enum | `App\Enums\AdminConsoleRole`（admin/editor/shooter）新設 |
| Migration | `organization_invitations.project_role`（string, nullable）追加 |
| Service | `OrganizationMembershipService`: inviteMember に projectRole 引数追加・joinOrganization で Default Project attach・changeRole 合成用メソッド（org ロール + pivot を 1 tx）追加 |
| Controller | `Admin\UserManagementController@index`（新設・GET のみ）、`OrganizationInvitationController@store` / `OrganizationMemberController@update` の 3 値ロール対応、`Projects\CategoryController@index` 追加 |
| Routes | GET `/admin/users`、GET `/projects/{project}/categories`（既存 group 内） |
| Policy | 既存再利用（`OrganizationPolicy::manageMembers` / `CategoryPolicy`←`ProjectPolicy::update` 親委譲）。カテゴリ index 用に `CategoryPolicy::viewAny(User, Project)` を追加（update 委譲） |
| Svelte | `Admin/Users.svelte`・`Admin/Categories.svelte`・`features/admin/AdminMenuNav.svelte` 新設、`Projects/Show.svelte` からカテゴリ CRUD UI 移設、`Organizations/Settings.svelte` スリム化 |
| テスト | 権限（両画面: admin 200 / 撮影者・一般 403 / cross-org 404）、招待入力制約（email 形式/重複中立 422・role 不正 422）、ロール合成（editor⇄shooter⇄admin の org ロール + pivot 遷移・最終 Owner 保護・非メンバー 404）、受諾時の Default Project attach（project 不在 fail-soft 含む）、カテゴリ画面（reorder 反映・同名 422・削除未分類化 = 既存バックエンドテストの画面経由確認）、保護キー 422、Vitest（描画・disabled 不使用・押下時エラー・can 連動のサイドバー非表示） |
| Docs | `docs/template-divergence.md` に「ユーザー管理 = 招待一本化 + 3 値ロール合成 + Settings からの UI 移設」を記録。`docs/architecture.md` へ管理メニュー節を追記 |

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
