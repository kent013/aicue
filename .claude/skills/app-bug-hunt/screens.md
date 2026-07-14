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
