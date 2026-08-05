# S6: セキュリティ (2FA/プロフィール/機微操作の再認証)

- 前提状態: 代表ユーザーでログイン済み。
- 目的: 2FA 有効化/無効化・プロフィール編集・パスワード変更・機微操作前の再認証(recent-auth / confirm-password)・アカウント削除が正しく反映され、無防備に実行されないか。

## 手順
1. `settings` → プロフィール編集 `user-profile-information.update`(表示名/メール。PII は保護)、パスワード変更 `user-password.update`。パスワード入力に**「表示」トグル**があるか(T042)。保存成功のトーストが出るか(T026)。
2. `settings.security` → 2FA 有効化 `two-factor.enable` → `two-factor.confirm`、リカバリコード再生成 `two-factor.regenerate-recovery-codes`(トーストは 1 つのみ, T026)、無効化 `two-factor.disable`。
3. 機微操作前の再認証: `password.confirm`(confirm-password 画面)→ `password.confirm.store`、`recent-auth.confirm`/`recent-auth.status` → `recent-auth.password`。
3-b. **パスキーの登録 → 削除 (T106/T107)**:
   `settings.security` → 「パスキーを追加」→ 再認証(`RequireRecentAuth`)を求められる →
   `recent-auth.confirm` で通過 → `passkey.registration-options` で challenge 取得 →
   `passkey.store` で登録完了。一覧に登録済みパスキーが出るか。
   - **詰み検証**: パスキーだけが唯一のログイン手段になった状態で `passkey.destroy` を試す。
     `ensure-login-method` に弾かれたとき、**「先に別のログイン手段を登録してください」という
     行き先付きの説明**が出るか(403 の素っ気ないエラーで終わったら finding = H4)。
   - **IDOR 検証**: 他ユーザーの passkey id を `passkey.destroy` に流し込む →
     **必ず 404**(403 だと「その id は存在する」と漏れる)。
   - 削除成功後、一覧から消えてトーストが 1 つだけ出るか(T026)。
   - **再認証の詰み検証**: パスキーしか持たないユーザーが `passkey.confirm-options` →
     `passkey.confirm` で `recent-auth.confirm` を通過できるか(パスワード欄しか出ずに
     詰んだら finding = H4)。
3-c. **パスワード未設定ユーザーの初回パスワード設定(`settings.password.store`, T107)**:
   SSO / パスキーのみで登録したユーザーで `settings.security` を開く →
   「パスワードを設定」導線が**存在し、押下できる**こと(必須条件未充足で disabled に
   していないこと = 禁止事項 8)。現行パスワード欄が**要求されない**こと。
   設定後に `login` からパスワードでログインできること。
4. アカウント削除 `settings.account.destroy`(確認 → 実行)。
5. **bfcache 復元時の秘匿・再検証 (`session.status`)**: 認証済み画面 → 外部/別ページへ遷移 →
   **ブラウザバック**で戻す。`resources/js/lib/bfcache-guard.ts` が pageshow 直後に
   `GET /session/status` を叩き、`authenticated: false` なら中身を秘匿してログインへ倒す。
   - ログアウト後に戻るボタンで認証済み画面が**中身の見える状態で復元されない**こと
     (サーバ側 no-store とクライアント guard のセット。正本 `docs/supported-browsers.md`)。
   - セッションが有効な場合は秘匿が**解除されて通常操作に戻れる**こと (白画面のまま
     詰まないこと。H4)。**iOS Safari / WebKit レーンが主戦場**なので WebKit で必ず見る。
6. 通知センター `notifications.index`(`/notifications`): 通知一覧・空状態の説明、既読化 `notifications.read` / 一括既読 `notifications.read-all` / 開封遷移 `notifications.open`。ブラウザタブ title が固有(「通知 | AI-CUE」)か(T034)。

## このストーリーで消化する screens / operations
- screens: settings, settings.security, password.confirm, recent-auth.confirm, recent-auth.status, notifications.index, session.status, passkey.registration-options, passkey.confirm-options
- operations: user-profile-information.update, user-password.update, two-factor.enable, two-factor.confirm, two-factor.disable, two-factor.regenerate-recovery-codes, password.confirm.store, recent-auth.password, settings.account.destroy, notifications.read, notifications.read-all, notifications.open, passkey.store, passkey.destroy, passkey.confirm, settings.password.store

## 逸脱アイデア (--deviate 時)
- 再認証(recent-auth/confirm-password)を経ずに機微操作(2FA無効化・アカウント削除・オーナー移譲)を直 POST → ブロックされるか。
- パスワード変更後に旧セッションが無効化されるか。2FA 無効化直後に必須組織(two-factor-requirement)へアクセスできるか。
- アカウント削除を最後のオーナーが実行 → 組織が孤児化しないか、警告が出るか。
- **他人の passkey を消せるか**: 別ユーザーの passkey id を `passkey.destroy` へ直 DELETE →
  **404 以外(特に 403)が返れば存在オラクル = finding (Critical)**。数値でない id / bigint 範囲外も
  500 にならず 404 か。
- 再認証切れの状態で `passkey.registration-options` / `passkey.store` を直叩き → 通ったら finding。
- 既にパスワードを持つユーザーが `settings.password.store` を直 POST して**現行パスワード検証を
  迂回**できないか(できたら finding = Critical。正規経路は `user-password.update`)。
- メール変更(`user-profile-information.update`)が **stale セッション(remember 経由で recent_auth 未 stamp)では recent-auth の step-up を要求**して弾かれるか(T031。UI 回避の直接 fetch でも 409 か)。変更成功時に旧アドレスへ通知されるか。
