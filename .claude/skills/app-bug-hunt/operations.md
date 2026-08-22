# 操作インベントリ (operations.md) — AI-CUE

> **このファイルは生成物である。手で編集しない。**
> 直し方: 割当ストーリー列は `.claude/skills/app-bug-hunt/stories/S*.md` の前付け
> (`covers_screens` / `covers_operations`) を、区分・理由・種別は
> `inventory/annotations.toml` を、散文は `inventory/notes-*.md` を直してから
> `python3 scripts/bug-hunt-inventory.py generate` を走らせる。
> 抽出条件: 開発環境 (local) またはテスト実行中に登録される route 集合。
> ドリフト検査: `scripts/bug-hunt-inventory-check.sh` (exit 3 = ドリフト)。

bug-hunt カバレッジの分母となる「書き込み操作」(非 GET × web セッション面) の一覧。全 79 件 (うち対象外 1 件)。列は method / route / name / story / 区分 の 5 列固定 (coverage/correlate.py の入力契約。ヘッダ名を変えない)。

## 操作一覧 (web セッション面)

| method | route | name | story | 区分 |
|---|---|---|---|---|
| POST | billing/auto-recharge/setup | billing.auto-recharge.setup | S5 | 通常 |
| POST | billing/auto-recharge | billing.auto-recharge.update | S5 | 通常 |
| POST | billing/checkout | billing.checkout | S5 | 通常 |
| PATCH | billing/contact | billing.contact.update | S5 | 通常 |
| POST | billing/plan | billing.plan.change | S5 | 通常 |
| POST | billing/portal | billing.portal | S5 | 通常 |
| POST | purchase-tickets/checkout | billing.tickets.checkout | S5 | 通常 |
| POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/adopt | capture.takes.adopt | S3 S7 | 通常 |
| DELETE | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take} | capture.takes.destroy | S3 S7 | 通常 |
| POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/downloaded | capture.takes.downloaded | S3 | 通常 |
| POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes | capture.takes.store | S3 | 通常 |
| PATCH | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take} | capture.takes.update | S3 | 通常 |
| POST | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/upload-url | capture.takes.upload-url | S3 | 通常 |
| POST | contact | contact.store | S1 | 通常 |
| POST | debug/login/{userId} | debug.login-as | S1 | 通常 |
| POST | invitations/{invitation}/accept-in-app | invitations.accept-in-app | S2 | 通常 |
| POST | invitations/accept | invitations.accept.store | S2 | 通常 |
| POST | login | login.store | S1 | 通常 |
| POST | logout | logout | S1 | 通常 |
| POST | notifications/{notification}/open | notifications.open | S6 | 通常 |
| POST | notifications/{notification}/read | notifications.read | S6 | 通常 |
| POST | notifications/read-all | notifications.read-all | S6 | 通常 |
| POST | onboarding/activate-personal | onboarding.activate-personal | S1 | 通常 |
| DELETE | organizations/{organization}/api-keys/{apiKey} | organizations.api-keys.revoke | S4 | 通常 |
| DELETE | organizations/{organization}/api-keys/sessions/{oauthSession} | organizations.api-keys.sessions.revoke | S4 | 通常 |
| POST | organizations/{organization}/api-keys | organizations.api-keys.store | S4 | 通常 |
| DELETE | organizations/{organization}/invitations/{invitation} | organizations.invitations.revoke | S2 | 通常 |
| POST | organizations/{organization}/invitations | organizations.invitations.store | S2 | 通常 |
| DELETE | organizations/{organization}/members/{user} | organizations.members.destroy | S2 | 通常 |
| DELETE | organizations/{organization}/members/{user}/two-factor | organizations.members.two-factor.reset | S2 | 通常 |
| PATCH | organizations/{organization}/members/{user} | organizations.members.update | S2 | 通常 |
| POST | organizations | organizations.store | S4 | 通常 |
| POST | organizations/{organization}/switch | organizations.switch | S4 | 通常 |
| POST | organizations/{organization}/transfer-ownership | organizations.transfer-ownership | S4 | 通常 |
| PATCH | organizations/{organization}/two-factor-requirement | organizations.two-factor-requirement.update | S4 | 通常 |
| PATCH | organizations/{organization} | organizations.update | S4 | 通常 |
| POST | passkeys/confirm | passkey.confirm | S6 | 通常 |
| DELETE | user/passkeys/{passkey} | passkey.destroy | S6 | 通常 |
| POST | passkeys/login | passkey.login | S1 | 通常 |
| POST | user/passkeys | passkey.store | S6 | 通常 |
| POST | user/confirm-password | password.confirm.store | S6 | 通常 |
| POST | forgot-password | password.email | S1 | 通常 |
| POST | reset-password | password.update | S1 | 通常 |
| DELETE | projects/{project}/categories/{category} | projects.categories.destroy | S4 S7 | 通常 |
| PATCH | projects/{project}/categories/reorder | projects.categories.reorder | S4 S7 | 通常 |
| POST | projects/{project}/categories | projects.categories.store | S4 | 通常 |
| PATCH | projects/{project}/categories/{category} | projects.categories.update | S4 S7 | 通常 |
| DELETE | projects/{project} | projects.destroy | S4 | 通常 |
| DELETE | projects/{project}/items/{item} | projects.items.destroy | S4 | 通常 |
| POST | projects/{project}/items | projects.items.store | S4 | 通常 |
| PATCH | projects/{project}/items/{item} | projects.items.update | S4 | 通常 |
| POST | projects/{project}/manuals/{manual}/analyze | projects.manuals.analyze | S3 | 通常 |
| DELETE | projects/{project}/manuals/{manual} | projects.manuals.destroy | S3 S7 | 通常 |
| POST | projects/{project}/manuals/{manual}/duplicate | projects.manuals.duplicate | S3 S7 | 通常 |
| POST | projects/{project}/manuals/{manual}/preview | projects.manuals.preview | S3 | 通常 |
| POST | projects/{project}/manuals/{manual}/render | projects.manuals.render | S3 | 通常 |
| PUT | projects/{project}/manuals/{manual}/scenario | projects.manuals.scenario.update | S3 S7 | 通常 |
| POST | projects/{project}/manuals/{manual}/source-documents | projects.manuals.source-documents.store | S3 | 通常 |
| POST | projects/{project}/manuals | projects.manuals.store | S3 | 通常 |
| PATCH | projects/{project}/manuals/{manual} | projects.manuals.update | S3 S7 | 通常 |
| DELETE | projects/{project}/members/{user} | projects.members.destroy | S4 | 通常 |
| POST | projects/{project}/members | projects.members.store | S4 | 通常 |
| POST | projects | projects.store | S4 | 通常 |
| PATCH | projects/{project} | projects.update | S4 | 通常 |
| POST | recent-auth/password | recent-auth.password | S6 | 通常 |
| POST | register | register.store | S1 | 通常 |
| DELETE | settings/account/deletion-request | settings.account.deletion-request.destroy | S6 | 通常 |
| POST | settings/account/deletion-request | settings.account.deletion-request.store | S6 | 通常 |
| DELETE | settings/account | settings.account.destroy | S6 | 通常 |
| POST | settings/password | settings.password.store | S6 | 通常 |
| POST | user/confirmed-two-factor-authentication | two-factor.confirm | S6 | 通常 |
| DELETE | user/two-factor-authentication | two-factor.disable | S6 | 通常 |
| POST | user/two-factor-authentication | two-factor.enable | S6 | 通常 |
| POST | two-factor-challenge | two-factor.login.store | S1 | 通常 |
| POST | user/two-factor-recovery-codes | two-factor.regenerate-recovery-codes | S6 | 通常 |
| PUT | user/password | user-password.update | S6 | 通常 |
| PUT | user/profile-information | user-profile-information.update | S6 | 通常 |
| POST | email/verification-notification | verification.send | S1 | 通常 |
| POST | ses/notification | webhooks.ses | - | 外 |

## 対象外の理由

- `webhooks.ses` — 外部の配信基盤からの通知を受ける機械向けの受け口でありブラウザ操作で叩く経路ではないため分母に載せない

<!--
  operations.md の末尾へそのまま連結される散文。人が書く (生成器は中身を読まない)。
  **表を書かないこと** — coverage/correlate.py は operations.md を頭から走査し、
  直近のヘッダの列割当で `|` 始まりの行を操作行として読むため、ここに表があると
  注釈に無い行が操作として数えられる。段 2 が表の混入を drift として拒否する。
-->

## 課金ゲート allowlist と認可 (P4 反転後、要検出)

`billing.*` / `billing.tickets.*` / `billing.auto-recharge.*` / `billing.contact.update` /
`onboarding.*` / `notifications.*` は **`require-active-subscription` group の外**にある構造的
allowlist で、未契約・支払い不健全な組織でも到達できなければならない (`routes/web.php` の
gate group コメントが正本)。ここが 402/リダイレクトで詰むと「契約するための画面が契約して
いないと開けない」= 詰み finding (H4)。

- `billing.auto-recharge.update` / `billing.auto-recharge.setup` / `billing.contact.update` /
  `billing.checkout` / `billing.plan.change` / `billing.tickets.checkout` の認可は Controller 冒頭の
  `Gate::authorize('manageBilling')` (owner / admin)。member は 403、他組織はそもそも
  current org スコープ (route parameter なし) で構造的に到達不能。
- `onboarding.activate-personal` は `throttle:10,1` 付き。連打時に 429 が UX として
  説明されるか (無反応にならないか) を見る。
- 二重課金の観点は S5 の逸脱アイデア参照 (`attempt_token` 冪等 / live pending dedup)。

## パスキー / ログイン手段の認可・guard 契約 (T106/T107 後、要検出)

正本は `docs/auth-security-mechanisms.md` §5・§6。**認証系は IDOR・詰みが最も出やすい面**
なので、以下の 4 つは必ず破壊を試みる。

- **他人の passkey は 404** (`{passkey}` は `SelfScopedPasskeyBinder` が
  「認証ユーザー所有 + 数値正規化」を担う explicit binder。403 で存在を漏らさない
  = セキュリティ不変条件 2 の実装点)。**他組織・他ユーザーの passkey id を
  `passkey.destroy` に流し込んで 404 以外が返れば finding (Critical)**。
- **唯一のログイン手段は消せない** (`ensure-login-method` middleware)。
  パスキーだけのユーザーが唯一の passkey を削除しようとしたとき、
  **403 で突き放さず「先に別の手段を登録してください」と行き先が示される**こと
  (行き先のない詰みを作らない = H4)。
- **登録・削除は再認証の後ろ** (`RequireRecentAuth`)。再認証が切れた状態で直 POST して
  通ったら finding。再認証を求められたとき、**パスキーしか持たないユーザーが
  `recent-auth.confirm` で詰まない**こと (T107 の `passkeyAvailable` 配線が効いているか)。
- **TOTP confirmed なら passkey login は拒否** (`PasskeyLoginPolicy`)。vendor の
  `PasskeyLoginController::store()` は Fortify の two-factor challenge を通らないため、
  TOTP を confirmed 済みのユーザーが `passkey.login` で入れたら **assurance の後退** =
  finding (Critical)。
- **`throttle:passkeys` / `settings.password.store` の `throttle:6,1`**。
  連打で 429 になったとき**画面上で説明される**こと (無反応にしない)。

`settings.password.store` は **SSO / パスキーのみで登録したユーザーがパスワードを
初めて設定する経路** (T107 で新設)。既存の `user-password.update` (現行パスワード必須) とは
別物なので、**現行パスワードを持たないユーザーが到達できること**、および
**既にパスワードを持つユーザーがこの経路で現行パスワード検証を迂回できないこと**の
両方を見る。
