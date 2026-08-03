# 画面インベントリ (screens.md) — AI-CUE

> bug-hunt カバレッジの分母となる「画面」(GET × inertia × web) の一覧。`php artisan route:list` から生成し
> ストーリー (S1..S7) を割り当てた。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
> 対象外 (seo/social/sso/2fa下位/legal confirmation 等) は OUT_OF_SCOPE_PREFIXES で除外済み。

## 画面一覧

| route (URL) | name | 割当ストーリー |
|---|---|---|
| / | home | S1 |
| app | capture.home | S3 |
| app/csrf-cookie | capture.csrf-cookie | S3 |
| app/projects/{project}/manuals | capture.manuals.index | S3 |
| app/projects/{project}/manuals/{manual} | capture.manuals.show | S3 |
| app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback | capture.takes.playback | S3 |
| billing | billing.index | S5 |
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
| organizations/create | organizations.create | S4 |
| organizations/{organization:slug}/api-keys | organizations.api-keys.index | S4 |
| organizations/{organization:slug}/api-keys/sessions | organizations.api-keys.sessions.index | S4 |
| organizations/{organization:slug}/onboarding/cli | organizations.onboarding.cli | S4 |
| organizations/{organization:slug}/onboarding/mcp | organizations.onboarding.mcp | S4 |
| organizations/{organization:slug}/settings | organizations.settings | S4 |
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
| settings | settings | S6 |
| settings/security | settings.security | S6 |
| terms | legal.terms | S1 |
| two-factor-challenge | two-factor.login | S1 |
| user/confirm-password | password.confirm | S6 |

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
