# アプリの使命・禁止事項・思考原則（レビュー基準・絶対遵守）

## 使命 (North Star)

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、
そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化された
マニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。標準作業を起点に AI が教材設計し撮影を指示する。

## 禁止事項

1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作（`migrate:fresh` 等）のエージェント判断実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`（ログイン直後フロー専用）
8. 必須条件未充足を理由にボタンを disabled にする UI

## セキュリティ不変条件

1. tenant キー不信（保護キーは payload から受け取らない。forceFill / relation で明示代入）
2. 子は親に属する（nested route 不整合は認可より前に 404）
3. cross-org 不可（relation / org-scoped 解決のみ）
5. 権限判定は常に `laratrust_team_id` 明示（strict_check=true）
6. PII(email/name)は CipherSweet、検索は `whereBlind()`
7. 課金の冪等性（idempotency_key + 部分 UNIQUE index）

## 思考原則

まず仮説を立てろ。フレームワークのレンジ内でやる。今必要なものだけ作る（オーバーエンジニアリング禁止）。
後方互換の並走を残さない。タコツボ実装を避ける（結合観点を確認）。テストファースト。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供テキストの分析に集中すること（ファイル読み込みは許可）。

---

# レビュー役割

あなたは Web アプリケーション（Laravel 12 + Svelte 5 + Inertia.js + TypeScript, PHPStan L10, Pest）の
改善に関する**概念設計レビュアー**です。

## レビュー観点

1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 抵触していないか
3. 実現可能性: 技術的に実現可能か
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか
8. **根本原因の妥当性**: 提示された単一根本原因（招待参加時に `current_organization_id` を設定しない）が
   観測症状（残高 10 / ヘッダー未所属 / 再ログインで見える）を過不足なく説明できているか。誤診断がないか。

## 出力形式

- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

# 参考: 関連コードの要約（現行実装）

## `app/Actions/Fortify/CreateNewUser.php`（登録アクション）
- session の `invitation_token` を fail-secure 解決。
- 登録 `DB::transaction` 内で User を save 後、
  `$joined = $invitationToken !== null ? $membership->acceptInvitationIfValid($invitationToken, $user) : null;`
- `if ($joined === null)`（招待失敗 or token なし）: `provisionPersonalOrganization($user)` で個人組織を作り、
  `grantSignupGrant($organization)` で signup grant を付与する。
- `$joined !== null`（招待成立）: **何もしない**（個人組織なし・grant なし）。→ ここで `current_organization_id` を設定しない。

## `app/Services/Organization/OrganizationProvisioningService.php`
- `provision()`: 組織生成後 `if ($creator->current_organization_id === null) { $creator->forceFill(['current_organization_id' => $organization->id])->save(); }`。
- 個人組織パスはこれで現在組織が確定する。

## `app/Services/Organization/OrganizationMembershipService.php`
- `acceptInvitationIfValid($token, $user): ?Organization`: register 経路専用。招待 active + email 一致 + 未メンバーなら
  `joinOrganization()` で attach + role 付与し、参加組織を返す。失敗時は null（呼び出し側が個人組織へ fallback）。
- `joinOrganization()`: organization_user へ insertOrIgnore + `addRole` + accepted_at。**`current_organization_id` は触らない**。
- `acceptInvitation()`（POST 受諾経路）も同じ `joinOrganization()` を使う。POST は dashboard へ redirect。

## `app/Http/Middleware/HandleInertiaRequests.php`
- 全ページ共有プロップ `currentOrganization` は `$user->currentOrganization`（= `current_organization_id` の生読み・
  isMemberOf 再確認あり）で決める。**`CurrentOrganizationResolver` の自己修復は通さない**。
- `organizations` プロップは `$user->organizations()->get()`（所属一覧）。

## `app/Services/Organization/CurrentOrganizationResolver.php`
- `resolve()`: current の所属再確認 → null/dangling なら所属先頭組織へ `current_organization_id` を自己修復（UPDATE）。
- 呼び出し元は **DashboardController のみ**。ヘッダー共有プロップは通らない。

## テスト現状（`tests/Feature/Organization/InvitationTest.php`）
- register 経路テストは「membership / 招待ロール / 個人組織なし / signup grant なし / 招待 accepted」を検証。
- **`current_organization_id` の確定・共有プロップ `currentOrganization` の反映は未検証**（回帰素通しの原因）。

---

# 概念設計（レビュー対象）

（以下、conceptual-design.md 全文）

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
**`$user->currentOrganization`（`current_organization_id` の生読み）だけ**で現在組織を決める
（`CurrentOrganizationResolver` の自己修復を通さない）。よって:

- 登録直後は `current_organization_id = null` → ヘッダーの現在組織 = null → 「組織を作成/選択」表示（症状 2）。
- `DashboardController` だけは `CurrentOrganizationResolver::resolve()` を通し、所属先頭組織へ
  `current_organization_id` を**自己修復**する。ここで招待先組織（owner が受け取った signup grant 10 枚を
  共有）が解決され、残高 10 が見える（症状 1 の「残高 10」= **招待先組織の共有残高**であり、新規ユーザーへの
  誤付与ではない。コード上、招待参加パスは grant を呼ばない）。
- ログイン後の着地(dashboard) or 再訪 dashboard で自己修復が走って初めてヘッダーにも組織が出る
  （「再ログイン後にようやく見える」）。

つまり **単一根本原因（招待参加時に `current_organization_id` を設定しない）** が、
「ヘッダー未所属表示」と「dashboard だけ残高 10」という二つの症状を同時に生む。

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
- 2 分岐（招待成功 = 参加のみ・grant無し・個人組織なし・現在組織=招待組織 / 招待失敗 = 個人組織作成・
  grant付与・現在組織=個人組織）が **排他かつ網羅**であることを Feature テストで固定する。
- 回帰観測欠落を塞ぐため、テストに **`current_organization_id` の確定**と **共有 Inertia プロップ
  `currentOrganization` の反映**（= ヘッダーに組織が出る）を追加する。

## 期待効果

- 使命への貢献: 招待メンバーが登録直後から所属組織で作業へ入れる（North Star「まず触れる／組織横断運用の
  到達導線」の入口を回復）。中間不整合による「詰み」体験を解消する。
- 具体的改善:
  - 招待経由登録 → 登録直後にヘッダー・dashboard の双方で招待組織が現在組織として表示される。
  - grant の増幅（招待 N 人 = N×10）を作らないという既存設計意図を、テストで恒久固定する。
  - 「再ログインするまで所属が見えない」窓が消える。

## 実装方針（概要）

- 変更の中心は `app/Actions/Fortify/CreateNewUser.php` の招待参加成立分岐（1 箇所）。
  `$joined !== null` のとき、登録 `DB::transaction` 内で
  `$user->current_organization_id === null` を満たす場合に限り `forceFill(['current_organization_id' => $joined->id])->save()`。
- 共有プロップ側（`HandleInertiaRequests`）や `joinOrganization()` 共通コアは変更しない
  （POST 受諾経路の「現在組織を勝手に切り替えない」既存挙動を保つため、修正は register 経路に限定する）。
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

