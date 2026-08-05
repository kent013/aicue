# 画面インベントリ (screens.md) — AI-CUE

> bug-hunt カバレッジの分母となる「画面」(GET × inertia × web) の一覧。`php artisan route:list` から生成し
> ストーリー (S1..S7) を割り当てた。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
> 対象外 (seo/social/sso/2fa下位/legal confirmation 等) は OUT_OF_SCOPE_PREFIXES で除外済み。

## GET × web 一覧 (画面 + 画面に付随する JSON GET)

> 本表は「GET × web セッション面」の一覧であり、**Inertia 画面だけではない**。
> 以下は画面ではなく**画面に付随する JSON GET** として載せている
> (bug-hunt は単独で開かず、対応する画面操作の副作用として通過させる):
> `capture.csrf-cookie` / `session.status` / `passkey.registration-options` /
> `passkey.login-options` / `passkey.confirm-options`

| route (URL) | name | 割当ストーリー |
|---|---|---|
| / | home | S1 |
| app | capture.home | S3 |
| app/csrf-cookie | capture.csrf-cookie | S3 |
| app/projects/{project}/manuals | capture.manuals.index | S3 |
| app/projects/{project}/manuals/{manual} | capture.manuals.show | S3 |
| app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback | capture.takes.playback | S3 |
| billing | billing.index | S5 |
| billing-required | onboarding.billing-required | S2 |
| billing/plans | billing.plans | S5 |
| commerce-disclosure | legal.commerce-disclosure | S1 |
| contact | contact | S1 |
| contact/thanks | contact.thanks | S1 |
| dashboard | dashboard | S1 |
| email/verify | verification.notice | S1 |
| email/verify/{id}/{hash} | verification.verify | S1 |
| forgot-password | password.request | S1 |
| invitations/accept | invitations.accept | S2 |
| login | login | S1 |
| manage/users | manage.users.index | S4 |
| notifications | notifications.index | S6 |
| onboarding/checkout | onboarding.checkout | S1 |
| organizations/create | organizations.create | S4 |
| organizations/{organization:slug}/api-keys | organizations.api-keys.index | S4 |
| organizations/{organization:slug}/api-keys/sessions | organizations.api-keys.sessions.index | S4 |
| organizations/{organization:slug}/onboarding/cli | organizations.onboarding.cli | S4 |
| organizations/{organization:slug}/onboarding/mcp | organizations.onboarding.mcp | S4 |
| organizations/{organization:slug}/settings | organizations.settings | S4 |
| passkeys/confirm/options | passkey.confirm-options | S6 |
| passkeys/login/options | passkey.login-options | S1 |
| pricing | pricing | S5 |
| privacy | legal.privacy | S1 |
| purchase-tickets | billing.tickets.show | S5 |
| projects | projects.index | S4 |
| projects/create | projects.create | S4 |
| projects/{project} | projects.show | S3 |
| projects/{project}/categories | projects.categories.index | S4 |
| projects/{project}/edit | projects.edit | S4 |
| projects/{project}/manuals/create | projects.manuals.create | S3 |
| projects/{project}/manuals/{manual} | projects.manuals.show | S3 |
| projects/{project}/manuals/{manual}/download | projects.manuals.download | S3 |
| projects/{project}/manuals/{manual}/edit | projects.manuals.edit | S3 |
| projects/{project}/manuals/{manual}/jobs/{analysisJob} | projects.manuals.jobs.show | S3 |
| projects/{project}/manuals/{manual}/render-jobs/{renderJob} | projects.manuals.render-jobs.show | S3 |
| projects/{project}/manuals/{manual}/render-jobs/{renderJob}/playback | projects.manuals.render-jobs.playback | S3 |
| recent-auth/confirm | recent-auth.confirm | S6 |
| recent-auth/status | recent-auth.status | S6 |
| register | register | S1 |
| reset-password/{token} | password.reset | S1 |
| session/status | session.status | S6 |
| settings | settings | S6 |
| settings/security | settings.security | S6 |
| terms | legal.terms | S1 |
| two-factor-challenge | two-factor.login | S1 |
| user/confirm-password | password.confirm | S6 |
| user/passkeys/options | passkey.registration-options | S6 |

**非 Inertia の GET (画面ではないが分母に載せているもの)**:
`capture.csrf-cookie` (撮影 PWA の CSRF cookie 発行) と `session.status`
(bfcache guard `resources/js/lib/bfcache-guard.ts` が pageshow 直後に叩く
セッション有効性プローブ。auth グループの**外**にあり guest でも 200 +
`authenticated: false`) は Inertia ページを返さないが、ブラウザ挙動の契約に
直結するためインベントリに残す (S3 / S6 で観測する)。
パスキーの `passkey.*-options` 3 本も同じ扱い (次節)。

## パスキー options endpoint の扱い (要検出)

`passkey.*-options` の 3 本は**画面ではなく WebAuthn の challenge を返す JSON GET**
(`capture.csrf-cookie` / `session.status` と同じ扱いで表に載せている)。
bug-hunt はこれらを**単独で開くのではなく**、S1/S6 のパスキー操作を UI から実走した
副作用として通過させる。加えて逸脱アイデアとして直叩きを行う:

- `passkey.registration-options` / `passkey.confirm-options` は `RequireRecentAuth` /
  auth の配下。**未ログイン・再認証切れで直叩きしたときに 401/302 で止まり、
  challenge が漏れない**こと。
- `passkey.login-options` は guest 配下。**メールアドレスを列挙できる応答差
  (存在するユーザーと存在しないユーザーで応答が変わる)** が出ないこと (存在オラクル)。
- 3 本とも `throttle:passkeys` 配下。連打時の 429 が**画面上で説明される**こと
  (無反応で詰まないこと。H4)。

## 課金ゲート着地 (P4 ゲート反転) の画面遷移

> 未契約組織は業務 route group に入れない (`require-active-subscription`)。遮断時の着地は
> **`manageBilling` 保持者 → `onboarding.checkout` / 非保持者 → `onboarding.billing-required`**
> (正本: `docs/billing-gate-inversion-runbook.md`、運用契約: `docs/architecture.md`
> §サブスク契約 Checkout とオンボーディング着地)。

- `onboarding.checkout` は**離脱ガード付き**: 契約済み (有効 sub / free personal) は
  `billing.index` へ、`manageBilling` 非保持者は `onboarding.billing-required` へ逃がす。
- `onboarding.billing-required` も同様に、利用可なら `dashboard`、`manageBilling` 保持者なら
  `onboarding.checkout` へ逃がす。**どちらの画面も「行き先のない詰み」を作らないこと**が契約で、
  ここでループ・403・空画面が出たら finding (H4/H10)。
- `?plan=` は org スコープ session へ積んで canonical URL へ 303 する (query が残らない)。
  リロードしても選択が消えない (peek) こと。

## ナビゲーション/レイアウト規約 (T069 左サイドバー、参照アプリ aigenba 準拠)

> ログイン後の全画面は `templates/AppLayout.svelte` の**左サイドバー型シェル**を共有する
> (設計正本: `devnotes/20260716-1757-login-sidebar-nav/`)。bug-hunt はこの構造規約への
> 準拠を横断ヒューリスティクス H11/H13 とあわせて全認証画面で検査する。

**左サイドバー nav 項目 (desktop 固定 / mobile ドロワー) — ここに出てよいもの:**
- ダッシュボード `/dashboard`(常時)、プロジェクト `/projects`(組織あり)、
  メンバー `/manage/users`(`canManageMembers`)、API キー `/organizations/{slug}/api-keys`(`canManageApiKeys`)、
  請求 `/billing`(組織あり)

**下部ユーザー/組織ポップアップ (SidebarUserMenu) — ここに出るべきもの (左 nav に出してはいけない):**
- **個人設定 `/settings`**、組織設定 `/organizations/{slug}/settings`、CLI/MCP セットアップ、
  法務(利用規約/プライバシー/特商法)、ログアウト、組織切替
- **規約 (要検出)**: 「個人設定 `/settings`」は**下部ポップアップ専用**。左サイドバー nav 項目としては
  出さない(T069 で設定はポップアップへ移動した)。左 nav に「設定」が重複掲載されていれば finding
  (H10 相当: 直前設計との矛盾 / 二重掲載)。
- 通知はベル(`notification-bell` / mobile `notification-bell-mobile`)単一導線。左 nav 項目にしない。

**ページ幅/レイアウト準拠 (要検出、H11/H13):**
- 各ページ本文はサイドバーのオフセット(desktop 256/64px、mobile 0)配下の `<main>` コンテナ内に収まり、
  **横スクロール・要素はみ出し・レイアウト幅非準拠が無い**こと。旧レイアウトの `max-w-6xl` 中央寄せを外したため、
  独自に幅を仮定していたページ(テーブル/ワイド要素)が新シェル幅に非準拠になっていないかを desktop/mobile で確認する。
- desktop(≥1024)/tablet(768)/mobile(375) で本文が破綻せず、サイドバー折りたたみ(64px)時も本文幅が追従すること。
