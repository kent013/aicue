# S2: 招待フロー(メンバー招待 → 受諾)

- 前提状態: 組織オーナー/管理者でログイン済み。招待先は別 cookie セッション(別ブラウザコンテキスト)。
- 目的: オーナーがメンバー(編集者/撮影者ロール)を招待し、被招待者が受諾して組織に参加し、AI-CUE の役割(編集者=マニュアル編集・撮影者=撮影)に応じた権限で入れるか。

## 手順
1. `organizations.invitations.store` でメールとロール(編集者/撮影者)を指定して招待 → 招待一覧に載る。
2. 被招待者(別 cookie)が招待メールのリンクから `invitations.accept` を開く → 内容確認 → `invitations.accept.store` で受諾 → 組織に参加。未ログインなら登録/ログインへ誘導し、完了後に参加。**未ログイン→register 誘導時に招待メールが登録フォームに自動入力される**か(T055)。登録直後から**招待先組織に正しく所属**し(個人組織を作らず・登録特典を二重付与しない)、ヘッダー組織メニューに招待先が出るか(T030)。
3. オーナーが `organizations.members.update` でロール変更、`organizations.members.destroy` で除名、`organizations.members.two-factor.reset` で 2FA リセット。
4. `organizations.invitations.revoke` で未受諾の招待を取り消し → リンクが無効化。

## このストーリーで消化する screens / operations
- screens: invitations.accept
- operations: invitations.accept.store, organizations.invitations.store, organizations.invitations.revoke, organizations.members.update, organizations.members.destroy, organizations.members.two-factor.reset

## 逸脱アイデア (--deviate 時)
- 取り消し済み/期限切れ/受諾済みの招待リンクを再利用 → 弾かれるか。
- 別組織の招待トークンを自分のセッションで受諾 → 想定組織にのみ参加するか(トークン改竄)。
- 招待受諾を二重送信 → 二重参加/重複メンバーにならないか。
- 撮影者ロールで受諾後、編集者専用操作(manuals.store 等)を試す → 403 か(S7 と連動)。
