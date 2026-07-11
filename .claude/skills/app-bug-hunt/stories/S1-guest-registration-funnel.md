# S1: 登録/ログインファネル

- 前提状態: 未ログイン(ゲスト)。reseed 済み。
- 目的: ゲストがトップ/公開ページから新規登録 → メール認証 → 初回ログインまで詰まらず到達できるか。公開導線(料金・問い合わせ・法務)が破綻しないか。

## 手順
1. `home`(トップページ)を開く → プロダクト価値と CTA(登録/ログイン/料金)が見える。`pricing`/`contact`/`legal.privacy`/`legal.terms`/`legal.commerce-disclosure` へ遷移できる。
2. `contact` → `contact.store` → `contact.thanks`(問い合わせ完了)。
3. `register` → `register.store` → `verification.notice` → `verification.send` 再送 → `verification.verify` でメール認証完了。
4. `login` → `login.store` → 2FA 有効なら `two-factor.login` → `two-factor.login.store` → `dashboard` へ。
5. パスワード忘れ: `password.request` → `password.email` → `password.reset` → `password.update` → 再ログイン。
6. `logout` でログアウト。

## このストーリーで消化する screens / operations
- screens: home, register, login, dashboard, verification.notice, verification.verify, password.request, password.reset, two-factor.login, contact, contact.thanks, legal.commerce-disclosure, legal.privacy, legal.terms
- operations: register.store, login.store, logout, password.email, password.update, verification.send, two-factor.login.store, contact.store, debug.login-as

## 逸脱アイデア (--deviate 時)
- 認証前ページ(dashboard 等)へ直アクセス → login へ誘導されるか。認証後に login/register を開くと dashboard へ戻るか。
- register/contact を二重送信 → 二重作成/二重送信されないか。
- 認証メールリンクの id/hash を改竄 → 他ユーザーを認証できないか。
- reset トークンを使い回し/期限切れ → 弾かれるか。
