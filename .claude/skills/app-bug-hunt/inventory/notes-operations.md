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
