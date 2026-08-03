# S1: 登録/ログインファネル

- 前提状態: 未ログイン(ゲスト)。reseed 済み。
- 目的: ゲストがトップ/公開ページから新規登録 → メール認証 → 初回ログインまで詰まらず到達できるか。公開導線(料金・問い合わせ・法務)が破綻しないか。

## 手順
1. `home`(トップページ)を開く → プロダクト価値と CTA(登録/ログイン/料金)が見える。`pricing`/`contact`/`legal.privacy`/`legal.terms`/`legal.commerce-disclosure` へ遷移できる。
2. `contact` → `contact.store` → `contact.thanks`(問い合わせ完了)。
3. `register` → `register.store` → `verification.notice` → `verification.send` 再送 → `verification.verify` でメール認証完了。
4. `login` → `login.store` → 2FA 有効なら `two-factor.login` → `two-factor.login.store` → `dashboard` へ。
5. **登録直後の課金オンボーディング着地 (P4 ゲート反転 / P7 `?plan=` handoff)**:
   新規登録で作られた個人組織は**未契約**なので、業務画面 (`dashboard` 配下の
   プロジェクト等) へ行こうとすると `onboarding.checkout`(`/onboarding/checkout`)へ
   遮断着地する。screens.md「課金ゲート着地」節が契約。
   - プラン一覧 (`plan-grid`) と Personal(無料) の自己申告ステップ (`personal-free-step`)、
     有償プランのステップ (`paid-plan-step`) が出る。**遮断理由が画面上で説明されているか**
     (middleware は error flash を積まないので、説明は着地ページの責務。H4)。
   - `onboarding.activate-personal` で Personal(無料)を開始 → 成功トーストに**付与された
     無料チケット枚数**が出て、`dashboard`(または遮断前に行きたかった画面)へ復帰するか。
     資金選択で「オートリチャージを設定する」を選ぶとカード登録 Checkout へ直行し、
     **cancel しても請求ページに着地してカード登録 CTA が残る**(詰まない)か。
   - `/pricing` から `?plan=starter` 等を付けて入ると、canonical URL (`/onboarding/checkout`)
     へ 303 され **query が URL に残らず**、リロードしても選択プランが保持される (peek)か。
   - 契約済みで `/onboarding/checkout` を直叩き → `billing.index` へ逃がされるか
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

## このストーリーで消化する screens / operations
- screens: home, register, login, dashboard, onboarding.checkout, verification.notice, verification.verify, password.request, password.reset, two-factor.login, contact, contact.thanks, legal.commerce-disclosure, legal.privacy, legal.terms
- operations: register.store, login.store, logout, password.email, password.update, verification.send, two-factor.login.store, contact.store, debug.login-as, onboarding.activate-personal

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
