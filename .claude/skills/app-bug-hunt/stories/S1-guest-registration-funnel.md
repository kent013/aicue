---
id: S1
title: 登録/ログインファネル
surface: signup_funnel
lane: parallel_browser
priority: P1
applicability: applicable
depends_on: []
reseed_before: true
accounts: [guest]
setup: []
covers_screens: [app.entry, contact, contact.thanks, dashboard, enterprise-sso.login, home, legal.commerce-disclosure, legal.privacy, legal.terms, login, onboarding.checkout, passkey.login-options, password.request, password.reset, register, two-factor.login, verification.notice, verification.verify]
covers_operations: [contact.store, debug.login-as, login.store, logout, onboarding.activate-personal, passkey.login, password.email, password.update, register.store, two-factor.login.store, verification.send]
covers_capabilities: [AUTH-01, AUTH-02, AUTH-03, AUTH-04, PLAT-02, PUB-01, PUB-02, QUO-01]
---

# S1: 登録/ログインファネル

## 目的
未ログインのゲストがトップ/公開ページから新規登録 → メール認証 → 初回ログインまで詰まらず到達できるか。公開導線(料金・問い合わせ・法務)が破綻しないか。

## 手順
1. `home`(トップページ)を開く → プロダクト価値と CTA(登録/ログイン/料金)が見える。`pricing`/`contact`/`legal.privacy`/`legal.terms`/`legal.commerce-disclosure` へ遷移できる。
2. `contact` → `contact.store` → `contact.thanks`(問い合わせ完了)。
3. `register` → `register.store` → `verification.notice` → `verification.send` 再送 → `verification.verify` でメール認証完了。
4. `login` → `login.store` → 2FA 有効なら `two-factor.login` → `two-factor.login.store` → `dashboard` へ。
4-b. **パスキーでのログイン (T106)**:
   S6 でパスキーを登録したユーザーでログアウト → `login` 画面に
   **「パスキーでログイン」導線が出ている**こと → `passkey.login-options` で challenge 取得 →
   `passkey.login` で `dashboard` へ到達できること。
   - **存在オラクル検証**: 存在しないメールアドレスで `passkey.login-options` を叩いたときの
     応答が、存在するユーザーのときと**区別できない**こと(区別できたら finding = High)。
   - **詰み検証**: パスキー非対応ブラウザ / WebAuthn が利用不可の環境で
     「パスキーでログイン」を押したとき、**説明が出て通常ログインに戻れる**こと
     (無反応・白画面なら finding = H4)。
5. **登録直後の課金オンボーディング着地 (P4 ゲート反転 / P7 `?plan=` handoff)**:
   新規登録で作られた個人組織は**未契約**なので、業務画面 (`dashboard` 配下の
   プロジェクト等) へ行こうとすると `onboarding.checkout`(`/organizations/{slug}/onboarding/checkout`)へ
   遮断着地する。screens.md「課金ゲート着地」節が契約。
   - プラン一覧 (`plan-grid`) と Personal(無料) の自己申告ステップ (`personal-free-step`)、
     有償プランのステップ (`paid-plan-step`) が出る。**遮断理由が画面上で説明されているか**
     (middleware は error flash を積まないので、説明は着地ページの責務。H4)。
   - `onboarding.activate-personal` で Personal(無料)を開始 → 成功トーストに**付与された
     無料チケット枚数**が出て、`dashboard`(または遮断前に行きたかった画面)へ復帰するか。
     資金選択で「オートリチャージを設定する」を選ぶとカード登録 Checkout へ直行し、
     **cancel しても請求ページに着地してカード登録 CTA が残る**(詰まない)か。
   - `/pricing` から `?plan=starter` 等を付けて入ると、canonical URL (`/organizations/{slug}/onboarding/checkout`)
     へ 303 され **query が URL に残らず**、リロードしても選択プランが保持される (peek)か。
   - 契約済みで `/organizations/{slug}/onboarding/checkout` を直叩き → `billing.index` へ逃がされるか
     (ループ・空画面にならないか)。
6. **ログイン後シェル (T069 左サイドバー) の構造検証** (screens.md「ナビゲーション/レイアウト規約」参照):
   - `dashboard` で左サイドバーが表示され、nav 項目が規約どおりか snapshot で確認する。
   - **設定の位置 (要検出)**: 「個人設定 `/settings`」が**下部ユーザーポップアップ (SidebarUserMenu) 内のみ**に
     あり、**左サイドバー nav 項目としては出ていない**ことを確認する。左 nav に「設定」が重複掲載されていれば
     finding (直前設計との矛盾 / 二重掲載、H10)。
   - **ページ幅準拠 (要検出、H11/H13)**: `dashboard` / `projects`(組織あれば) を desktop(1280) と
     mobile(375) で `resize` し、本文がサイドバーオフセット配下の `<main>` に収まり横スクロール・はみ出し・
     幅非準拠が無いことを確認する。サイドバー折りたたみ時も本文幅が追従するか確認。確認後 desktop に戻す。
   - 通知ベルが単一導線として出ており、通知が左 nav 項目に重複していないこと。
7. パスワード忘れ: `password.request` → `password.email` → `password.reset` → `password.update` → 再ログイン。
8. `logout` でログアウト。
9. 企業アカウントログインの入口画面(`enterprise-sso.login`)が表示され、案内が成立しているか。IdP への遷移 `enterprise-sso.redirect` と戻り口 `enterprise-sso.callback` は実際の識別提供者が要るため探索環境の外(区分 外)であり、たどらない。

## 逸脱アイデア (--deviate 時)
- 認証前ページ(dashboard 等)へ直アクセス → login へ誘導されるか。認証後に login/register を開くと dashboard へ戻るか。
- register/contact を二重送信 → 二重作成/二重送信されないか。
- 認証メールリンクの id/hash を改竄 → 他ユーザーを認証できないか。
- reset トークンを使い回し/期限切れ → 弾かれるか。
- `onboarding.activate-personal` を二重送信/連打 → 二重付与にならないか、`throttle:10,1` の 429 が
  無反応でなく説明付きで出るか。適格性を満たさない状態(既に契約済み等)で直 POST → 422 で
  詰まずに戻れるか。
- `?plan=` に未知値 / `enterprise` / 巨大文字列を入れる → 正規化されて既定に倒れるか
  (500・存在オラクルにならないか)。
- `passkey.login-options` を存在しないメール / 巨大文字列 / 非文字列で叩く → 応答差から
  **ユーザーの存在が判別できないか**(判別できたら finding = High)。`throttle:passkeys` の
  429 が無反応でなく説明付きで出るか。
- TOTP を confirmed 済みのユーザーで `passkey.login` を試す → **拒否される**か
  (`PasskeyLoginPolicy` の assurance 後退防止。通ったら finding = Critical)。
