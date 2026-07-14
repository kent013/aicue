# 概念設計: invitation-signup-membership

## 背景・課題

bug-hunt(回帰run) **F-01 (Critical / data_integrity)**。前回修正 T021(登録チケット付与)/
T019 が新たに壊した回帰の疑い。

### 症状（再現手順と観測）

1. 未ログインで署名付き招待リンク `/invitations/accept?token=...` を開く
   → `InvitationAcceptanceController::show` が有効招待を検出し、session に `invitation_token` を保存して
     `/register` へ誘導する。
2. 招待と**同一 email** で新規登録する。
   → `CreateNewUser::create()` が session の `invitation_token` を解決し、`acceptInvitationIfValid()` が
     招待組織へ参加させ、招待を「受諾済み(accepted_at)」にする。個人組織は作らず、signup grant も付与しない。
3. メール認証を完了する。
4. **結果(不整合)**:
   - (1) ダッシュボードでチケット残高が 10 に見える。
   - (2) にもかかわらず**ヘッダーの組織メニューは組織が選択されていない状態**（「組織を作成/選択」のみ）で、
     招待先組織にも個人組織にも属さない **中間不整合状態** に見える。
   - 招待リンク再訪は「使用済み」だが `invitations.accept.store`(POST) が呼ばれた形跡はない
     （= 参加は register 経路の `acceptInvitationIfValid` で成立していた）。
   - **再ログイン後にようやく組織所属が見える**。

### 根本原因（コード確定）

参加経路ごとに `users.current_organization_id`（ヘッダー等の全ページ共有プロップの拠り所）の
セット有無が食い違っている。

- **個人組織パス**: `CreateNewUser` → `OrganizationProvisioningService::provision()` が
  `current_organization_id === null` のとき生成した組織を `current_organization_id` に**セットする**。
- **招待参加パス**: `CreateNewUser` → `acceptInvitationIfValid()` → `joinOrganization()` は
  organization_user への attach と role 付与を行うが、**`current_organization_id` を一切セットしない**。
  結果、招待経由で登録したユーザーは登録直後 `current_organization_id = null` のまま。

一方、全ページ共有の `HandleInertiaRequests::currentOrganizationProp()` は
**`$user->currentOrganization`（`current_organization_id` の生読み・`isMemberOf` 再確認あり）だけ**で
現在組織を決め、**`CurrentOrganizationResolver` の自己修復を通さない**。ここが観測差を生む二次条件である。

観測症状は「同一リクエスト内の矛盾」ではなく、**ページ（リクエスト）ごとに現在組織の解決経路が違う**ことで
生じる:

- **dashboard を開いたとき**: `DashboardController` が `CurrentOrganizationResolver::resolve()` を通し、
  `current_organization_id` が null なので所属先頭組織（= 招待先組織）へ**自己修復**し（`heal` の UPDATE →
  `$user->refresh()`）、その組織で残高を描画する。招待先組織は owner の signup grant 10 枚を保持するため
  **残高 10 が見える**（症状 1）。この 10 は**招待先組織の共有残高**であり、新規ユーザーへの誤付与ではない
  （コード上、招待参加パスは `grantSignupGrant` を一切呼ばない。既存テストも招待先組織残高 0 を固定済み）。
- **dashboard 以外のページ / 自己修復が走る前のヘッダー描画**: 自己修復を通らない共有プロップは
  `current_organization_id = null` を生読みするため現在組織 = null → ヘッダーは「組織を作成/選択」のみ（症状 2）。
- ただし、いったん dashboard の自己修復で `current_organization_id` が確定すれば、以後は共有プロップも
  同じ値を読むため矛盾は消える。よって「dashboard を経由 or 再ログイン（着地が dashboard）した後で
  ようやくヘッダーにも所属が出る」という観測になる（症状の時系列）。

つまり **一次原因＝招待参加時に `current_organization_id` を確定しないこと**、**二次条件＝共有プロップが
dashboard-only の自己修復に依存し、他ページ・ヘッダーには反映が保証されないこと** の組合せが、
「ヘッダー未所属」と「dashboard で残高 10」という別ページ観測を生む。一次原因を登録経路の入口で塞げば、
自己修復に依存せず登録直後の**全ページ**で招待先組織が現在組織として一貫表示される（二次条件を無害化する）。

### 既存テストがすり抜けた理由

`tests/Feature/Organization/InvitationTest.php` の register 経路テストは
「membership 存在 / 招待ロール / 個人組織なし / signup grant なし / 招待 accepted」を検証するが、
**`current_organization_id` がセットされること・共有プロップに現在組織が反映されること**を検証していない。
この観測欠落が回帰の素通しを許した。

## 改善アイデア

**招待参加による登録も、個人組織登録と同じく「登録完了時点で現在組織が確定している」ことを保証する。**

- `CreateNewUser::create()` の招待参加成立分岐（`$joined !== null`）で、登録トランザクション内において
  参加した招待組織を `current_organization_id` にセットする（`current_organization_id === null` のときのみ、
  `provision()` と同じ冪等ガードで）。保護キーのため `forceFill` で書き込む。
- これにより「登録直後から所属が見える（ヘッダー = dashboard）」を満たし、中間不整合の窓を消す。
- 回帰観測欠落を塞ぐため、テストに **`current_organization_id` の確定**（DB 値）と、**登録直後の
  認証済みリクエストの共有 Inertia プロップ `currentOrganization` に招待先組織が反映されること**（=
  dashboard の自己修復に依存せずヘッダーに組織が出る）の**両方**を追加する。DB 値だけでなく共有プロップの
  観測点まで固定することで、二次条件（dashboard-only 自己修復）に依存しないことを保証する。

### 登録経路の分岐タクソノミー（排他・網羅）

`current_organization_id` を含め、登録が**成功**するケースは以下 2 分岐に閉じる（Codex Round1 W3 対応）。

| 分岐 | 発火条件 | 個人組織 | signup grant | current_organization_id |
|------|----------|---------|--------------|------------------------|
| **A: 招待成立** | token あり + DB 実在 + active（未失効/未受諾/未取消） + 招待 email 一致 + 未メンバー | 作らない | 付与しない | 招待先組織 |
| **B: 通常/フォールバック** | token なし / 空 / 不正型、**または** token あり but（DB 不在・失効・受諾済・取消・既メンバー race） | 作る | 付与する（config 枚数） | 個人組織 |

**分岐に含まれない拒否ケース**: token あり + active + **招待 email 不一致** → `MatchesInvitationEmail` rule が
**登録自体を 422 で拒否**する（fallback しない）。`acceptInvitationIfValid` 内の email 不一致 null 返却は
二重防御であり、rule 通過後には発火しない（validation 段で既に弾かれるため）。この責務分離は現行実装のまま維持する。

分岐 A/B は `$joined = acceptInvitationIfValid(...)` の戻りが `Organization`（A）か `null`（B）かで
一意に決まり、**排他かつ網羅**（成功ケースを二重に、あるいは取りこぼしなく被覆する）。テストは A の代表と
B の代表（token なし・token 無効の 2 系）を固定する。

## 期待効果

- 使命への貢献（限定的だが的確）: 招待メンバーの**初期オンボーディング整合性を回復**し、登録直後に組織作業へ
  入れないという**導線の詰みを除去**する。これは教材設計・撮影ナビの本質改善ではなく、組織横断運用の入口
  （招待で組織へ参加して使い始める）を機能させる整合修正である（North Star への寄与は入口整備の範囲）。
- 具体的改善:
  - 招待経由登録 → 登録直後の**全ページ**（ヘッダー共有プロップ・dashboard）で招待組織が現在組織として表示される。
  - grant の増幅（招待 N 人 = N×10）を作らないという既存設計意図を、テストで恒久固定する。
  - 「dashboard を経由・再ログインするまで所属が見えない」窓が消える。

## 実装方針（概要）

- 変更の中心は `app/Actions/Fortify/CreateNewUser.php` の招待参加成立分岐（1 箇所）。
  `$joined !== null` のとき、登録 `DB::transaction` 内で
  `$user->current_organization_id === null` を満たす場合に限り `forceFill(['current_organization_id' => $joined->id])->save()`。
- 共有プロップ側（`HandleInertiaRequests`）や `joinOrganization()` 共通コアは変更しない。

  **修正を register 経路に限定し、`joinOrganization` の共通契約へ昇格させない理由（Codex Round1 W1 対応）**:
  `joinOrganization` はログイン後の POST 受諾経路（`InvitationAcceptanceController::store` → `acceptInvitation`）と
  共有される。この経路では**既にログイン中で現在組織を確定済み**のユーザーが 2 つ目以降の組織へ参加する。
  ここで現在組織を招待先へ強制切替すると「操作の副作用で現在組織が勝手に変わる」挙動になり、POST 受諾が
  dashboard へ戻る既存契約（現在組織は維持）を壊す。したがって「初回参加で現在組織を確定する」補正は
  **`current_organization_id` がまだ未確定（null）である登録経路に固有**であり、`provision()` が個人組織登録で
  `current_organization_id === null` ガード付きで行っているのと同じ位置づけ（初回確定）に揃える。共通契約への
  昇格は POST 経路の意図しない切替を招くため行わない。
- テスト（`InvitationTest` / `RegistrationTest`）に現在組織確定と共有プロップ反映の検証を追加し、
  2 分岐の排他・網羅を固定する。

## 制約・前提

- `current_organization_id` は mass-assignment 保護キー（`MassAssignmentProtectedKeys`）のため
  `forceFill` 経由でのみ書く（payload 由来値は使わない）。既存 `provision()` と同一作法。
- 修正は register 経路（`CreateNewUser` / `acceptInvitationIfValid`）に限定する。ログイン後 POST 受諾
  （`InvitationAcceptanceController::store` → `acceptInvitation`）は現在組織を切り替えない既存契約を維持する。
- signup grant の付与ロジック・冪等制御（`TicketLedgerService` / 部分 UNIQUE index）は変更しない。
  本 finding は「参加パスで grant を呼ばない」既存挙動が正しいことを前提に、可視性のみを直す。
- テストは `RefreshDatabase` グローバル + `--parallel`、Factory 生成、個別 `DatabaseTransactions` 禁止。

## スコープ外

- `HandleInertiaRequests::currentOrganizationProp()` を毎リクエスト自己修復（GET で書き込み）させる
  広域変更。今回は「登録時に現在組織を確定する」局所修正で十分（既存の dangling 自己修復は dashboard が担う）。
- SSO 登録（`SocialAccountService::provisionPersonalOrganization` は個人組織を作るが signup grant を
  呼ばない）の付与整合。別 finding として切り出す（本 finding は招待経由の可視性が主題）。
- 招待受諾の POST 経路の現在組織切替仕様の変更。
- LP 文言・課金冪等・部分 UNIQUE index など registration-ticket-grant(T021) 側の成果物。
