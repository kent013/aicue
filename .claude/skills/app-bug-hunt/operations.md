# 操作インベントリ (operations.md) — AI-CUE

> bug-hunt カバレッジの分母となる「書き込み操作」(非GET × web セッション面) の一覧。`php artisan route:list`
> から生成しストーリー (S1..S7) を割り当てた。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
> 列フォーマット: markdown leading-pipe 5 列 `| method | route | name | story | 区分 |` (correlate.py 依存)。

## 操作一覧 (web セッション面)

| method | route | name | story | 区分 |
|---|---|---|---|---|
| POST | billing/checkout | billing.checkout | S5 | 通常 |
| POST | billing/portal | billing.portal | S5 | 通常 |
| POST | purchase-tickets/checkout | billing.tickets.checkout | S5 | 通常 |
| POST | notifications/read-all | notifications.read-all | S6 | 通常 |
| POST | notifications/{notification}/open | notifications.open | S6 | 通常 |
| POST | notifications/{notification}/read | notifications.read | S6 | 通常 |
| POST | app/projects/{project}/manuals/{manual}/sync | capture.manuals.sync | S3 | 通常 |
| POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/adopt | capture.takes.adopt | S3 | 通常 |
| DELETE | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take} | capture.takes.destroy | S3 | 通常 |
| POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/downloaded | capture.takes.downloaded | S3 | 通常 |
| POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes | capture.takes.store | S3 | 通常 |
| PATCH | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take} | capture.takes.update | S3 | 通常 |
| POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/upload-url | capture.takes.upload-url | S3 | 通常 |
| POST | contact | contact.store | S1 | 通常 |
| POST | debug/login/{userId} | debug.login-as | S1 | 通常 |
| POST | invitations/accept | invitations.accept.store | S2 | 通常 |
| POST | login | login.store | S1 | 通常 |
| POST | logout | logout | S1 | 通常 |
| DELETE | organizations/{organization:slug}/api-keys/{apiKey} | organizations.api-keys.revoke | S4 | 通常 |
| DELETE | organizations/{organization:slug}/api-keys/sessions/{oauthSession} | organizations.api-keys.sessions.revoke | S4 | 通常 |
| POST | organizations/{organization:slug}/api-keys | organizations.api-keys.store | S4 | 通常 |
| DELETE | organizations/{organization:slug}/invitations/{invitation} | organizations.invitations.revoke | S2 | 通常 |
| POST | organizations/{organization:slug}/invitations | organizations.invitations.store | S2 | 通常 |
| DELETE | organizations/{organization:slug}/members/{user} | organizations.members.destroy | S2 | 通常 |
| DELETE | organizations/{organization:slug}/members/{user}/two-factor | organizations.members.two-factor.reset | S2 | 通常 |
| PATCH | organizations/{organization:slug}/members/{user} | organizations.members.update | S2 | 通常 |
| POST | organizations | organizations.store | S4 | 通常 |
| POST | organizations/{organization}/switch | organizations.switch | S4 | 通常 |
| POST | organizations/{organization:slug}/transfer-ownership | organizations.transfer-ownership | S4 | 通常 |
| PATCH | organizations/{organization:slug}/two-factor-requirement | organizations.two-factor-requirement.update | S4 | 通常 |
| PATCH | organizations/{organization:slug} | organizations.update | S4 | 通常 |
| POST | user/confirm-password | password.confirm.store | S6 | 通常 |
| POST | forgot-password | password.email | S1 | 通常 |
| POST | reset-password | password.update | S1 | 通常 |
| DELETE | projects/{project}/categories/{category} | projects.categories.destroy | S4 | 通常 |
| PATCH | projects/{project}/categories/reorder | projects.categories.reorder | S4 | 通常 |
| POST | projects/{project}/categories | projects.categories.store | S4 | 通常 |
| PATCH | projects/{project}/categories/{category} | projects.categories.update | S4 | 通常 |
| DELETE | projects/{project} | projects.destroy | S4 | 通常 |
| DELETE | projects/{project}/items/{item} | projects.items.destroy | S4 | 通常 |
| POST | projects/{project}/items | projects.items.store | S4 | 通常 |
| PATCH | projects/{project}/items/{item} | projects.items.update | S4 | 通常 |
| POST | projects/{project}/manuals/{manual}/analyze | projects.manuals.analyze | S3 | 通常 |
| DELETE | projects/{project}/manuals/{manual} | projects.manuals.destroy | S3 | 通常 |
| POST | projects/{project}/manuals/{manual}/preview | projects.manuals.preview | S3 | 通常 |
| POST | projects/{project}/manuals/{manual}/render | projects.manuals.render | S3 | 通常 |
| PUT | projects/{project}/manuals/{manual}/scenario | projects.manuals.scenario.update | S3 | 通常 |
| POST | projects/{project}/manuals/{manual}/source-documents | projects.manuals.source-documents.store | S3 | 通常 |
| POST | projects/{project}/manuals | projects.manuals.store | S3 | 通常 |
| PATCH | projects/{project}/manuals/{manual} | projects.manuals.update | S3 | 通常 |
| DELETE | projects/{project}/members/{user} | projects.members.destroy | S4 | 通常 |
| POST | projects/{project}/members | projects.members.store | S4 | 通常 |
| POST | projects | projects.store | S4 | 通常 |
| PATCH | projects/{project} | projects.update | S4 | 通常 |
| POST | recent-auth/password | recent-auth.password | S6 | 通常 |
| POST | register | register.store | S1 | 通常 |
| DELETE | settings/account | settings.account.destroy | S6 | 通常 |
| POST | user/confirmed-two-factor-authentication | two-factor.confirm | S6 | 通常 |
| DELETE | user/two-factor-authentication | two-factor.disable | S6 | 通常 |
| POST | user/two-factor-authentication | two-factor.enable | S6 | 通常 |
| POST | two-factor-challenge | two-factor.login.store | S1 | 通常 |
| POST | user/two-factor-recovery-codes | two-factor.regenerate-recovery-codes | S6 | 通常 |
| PUT | user/password | user-password.update | S6 | 通常 |
| PUT | user/profile-information | user-profile-information.update | S6 | 通常 |
| POST | email/verification-notification | verification.send | S1 | 通常 |
