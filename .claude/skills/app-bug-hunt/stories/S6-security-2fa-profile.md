# S6: セキュリティ (2FA/プロフィール/機微操作の再認証)

- 前提状態: 代表ユーザーでログイン済み。
- 目的: 2FA 有効化/無効化・プロフィール編集・パスワード変更・機微操作前の再認証(recent-auth / confirm-password)・アカウント削除が正しく反映され、無防備に実行されないか。

## 手順
1. `settings` → プロフィール編集 `user-profile-information.update`(表示名/メール。PII は保護)、パスワード変更 `user-password.update`。
2. `settings.security` → 2FA 有効化 `two-factor.enable` → `two-factor.confirm`、リカバリコード再生成 `two-factor.regenerate-recovery-codes`、無効化 `two-factor.disable`。
3. 機微操作前の再認証: `password.confirm`(confirm-password 画面)→ `password.confirm.store`、`recent-auth.confirm`/`recent-auth.status` → `recent-auth.password`。
4. アカウント削除 `settings.account.destroy`(確認 → 実行)。

## このストーリーで消化する screens / operations
- screens: settings, settings.security, password.confirm, recent-auth.confirm, recent-auth.status
- operations: user-profile-information.update, user-password.update, two-factor.enable, two-factor.confirm, two-factor.disable, two-factor.regenerate-recovery-codes, password.confirm.store, recent-auth.password, settings.account.destroy

## 逸脱アイデア (--deviate 時)
- 再認証(recent-auth/confirm-password)を経ずに機微操作(2FA無効化・アカウント削除・オーナー移譲)を直 POST → ブロックされるか。
- パスワード変更後に旧セッションが無効化されるか。2FA 無効化直後に必須組織(two-factor-requirement)へアクセスできるか。
- アカウント削除を最後のオーナーが実行 → 組織が孤児化しないか、警告が出るか。
- メール変更時に再認証メールが飛ぶか、変更前アドレスへ通知されるか。
