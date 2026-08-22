---
id: S2
title: 招待フロー(メンバー招待 → 受諾)
surface: invitation
lane: parallel_browser
priority: P1
applicability: applicable
depends_on: []
reseed_before: false
accounts: [owner, member]
setup: [招待先は別 cookie セッション (別ブラウザコンテキスト) で開く]
covers_screens: [invitations.accept, onboarding.billing-required]
covers_operations: [invitations.accept-in-app, invitations.accept.store, organizations.invitations.revoke, organizations.invitations.store, organizations.members.destroy, organizations.members.two-factor.reset, organizations.members.update]
covers_capabilities: [MEM-01, MEM-02, MEM-03, MEM-04, MEM-05, MEM-06]
---

# S2: 招待フロー(メンバー招待 → 受諾)

## 目的
組織オーナー/管理者がメンバー(編集者/撮影者ロール)を招待し、被招待者が受諾して組織に参加し、AI-CUE の役割(編集者=マニュアル編集・撮影者=撮影)に応じた権限で入れるか。

## 手順
1. `organizations.invitations.store` でメールとロール(編集者/撮影者)を指定して招待 → 招待一覧に載る。
2. 被招待者(別 cookie)が招待メールのリンクから `invitations.accept` を開く → 内容確認 → `invitations.accept.store` で受諾 → 組織に参加。未ログインなら登録/ログインへ誘導し、完了後に参加。**未ログイン→register 誘導時に招待メールが登録フォームに自動入力される**か(T055)。登録直後から**招待先組織に正しく所属**し(個人組織を作らず・登録特典を二重付与しない)、ヘッダー組織メニューに招待先が出るか(T030)。
3. オーナーが `organizations.members.update` でロール変更、`organizations.members.destroy` で除名、`organizations.members.two-factor.reset` で 2FA リセット。
4. `organizations.invitations.revoke` で未受諾の招待を取り消し → リンクが無効化。
5. **未契約組織の非管理 member の着地 (`onboarding.billing-required`)**: 招待先組織が未契約
   (`BillingAccess` が遮断) の状態で、`manageBilling` を持たない member (編集者/撮影者) が
   業務画面へ行こうとすると `/billing-required` に着地する。
   - 「組織管理者が課金手続きを完了するのをお待ちください」と**オーナーの連絡先**
     (`billing-required-owner-email`) / お問い合わせ導線が出て、403 や空画面にならないか。
   - この画面から**戻れる先がある**か (行き先のない詰みが無いか。H4)。
   - 逆方向の離脱ガード: 利用可の状態で `/billing-required` を直叩き → `dashboard` へ、
     `manageBilling` 保持者で直叩き → `onboarding.checkout` へ逃がされるか
     (2 画面を往復する無限リダイレクトにならないか。H10)。

## 逸脱アイデア (--deviate 時)
- 取り消し済み/期限切れ/受諾済みの招待リンクを再利用 → 弾かれるか。
- 別組織の招待トークンを自分のセッションで受諾 → 想定組織にのみ参加するか(トークン改竄)。
- 招待受諾を二重送信 → 二重参加/重複メンバーにならないか。
- 撮影者ロールで受諾後、編集者専用操作(manuals.store 等)を試す → 403 か(S7 と連動)。
