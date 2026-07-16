# S1: 登録/ログインファネル

- 前提状態: 未ログイン(ゲスト)。reseed 済み。
- 目的: ゲストがトップ/公開ページから新規登録 → メール認証 → 初回ログインまで詰まらず到達できるか。公開導線(料金・問い合わせ・法務)が破綻しないか。

## 手順
1. `home`(トップページ)を開く → プロダクト価値と CTA(登録/ログイン/料金)が見える。`pricing`/`contact`/`legal.privacy`/`legal.terms`/`legal.commerce-disclosure` へ遷移できる。
2. `contact` → `contact.store` → `contact.thanks`(問い合わせ完了)。
3. `register` → `register.store` → `verification.notice` → `verification.send` 再送 → `verification.verify` でメール認証完了。
4. `login` → `login.store` → 2FA 有効なら `two-factor.login` → `two-factor.login.store` → `dashboard` へ。
5. **ログイン後シェル (T069 左サイドバー) の構造検証** (screens.md「ナビゲーション/レイアウト規約」参照):
   - `dashboard` で左サイドバーが表示され、nav 項目が規約どおりか snapshot で確認する。
   - **設定の位置 (要検出)**: 「個人設定 `/settings`」が**下部ユーザーポップアップ (SidebarUserMenu) 内のみ**に
     あり、**左サイドバー nav 項目としては出ていない**ことを確認する。左 nav に「設定」が重複掲載されていれば
     finding (直前設計との矛盾 / 二重掲載、H10)。
   - **ページ幅準拠 (要検出、H11/H13)**: `dashboard` / `projects`(組織あれば) を desktop(1280) と
     mobile(375) で `resize` し、本文がサイドバーオフセット配下の `<main>` に収まり横スクロール・はみ出し・
     幅非準拠が無いことを確認する。サイドバー折りたたみ時も本文幅が追従するか確認。確認後 desktop に戻す。
   - 通知ベルが単一導線として出ており、通知が左 nav 項目に重複していないこと。
6. パスワード忘れ: `password.request` → `password.email` → `password.reset` → `password.update` → 再ログイン。
7. `logout` でログアウト。

## このストーリーで消化する screens / operations
- screens: home, register, login, dashboard, verification.notice, verification.verify, password.request, password.reset, two-factor.login, contact, contact.thanks, legal.commerce-disclosure, legal.privacy, legal.terms
- operations: register.store, login.store, logout, password.email, password.update, verification.send, two-factor.login.store, contact.store, debug.login-as

## 逸脱アイデア (--deviate 時)
- 認証前ページ(dashboard 等)へ直アクセス → login へ誘導されるか。認証後に login/register を開くと dashboard へ戻るか。
- register/contact を二重送信 → 二重作成/二重送信されないか。
- 認証メールリンクの id/hash を改竄 → 他ユーザーを認証できないか。
- reset トークンを使い回し/期限切れ → 弾かれるか。
