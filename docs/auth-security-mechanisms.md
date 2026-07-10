# 認証・セキュリティ横断機構

**実装**: `app/Security/`, `app/Http/Middleware/` (認証・ハードニング系), `app/Support/{ProductionEnvGuard,TrustedHostsConfigValidator}.php`, `app/Console/Commands/{ProductionPreflightCommand,ResetAdminMfaCommand}.php`, `config/{security,trusted_hosts}.php`

## 概要

テンプレート共通のセキュリティ横断機構を 3 つ束ねて記述する。いずれも特定のドメインに属さず、
リクエスト / デプロイの横断層で動く。

1. **機微操作の再認証 (recent-auth / step-up)** — Critical Action の直前に「直近の再認証」を要求する。
2. **組織 2FA 強制 (enforced two-factor)** — 組織が 2FA を必須化した場合の未準拠ユーザーゲート + self-disable 禁止。
3. **セキュリティヘッダ / 本番ハードニング層** — baseline セキュリティヘッダと production 起動時 / デプロイ前の fail-fast。

MCP / CLI の OAuth 認可については [docs/mcp-oauth.md](mcp-oauth.md)、公開面の全体像は
[docs/architecture.md](architecture.md) を参照。

---

## 1. 機微操作の再認証 (recent-auth / step-up)

**実装**: `app/Security/{RecentAuthState,RecentAuthWindow}.php`, `app/Http/Middleware/RequireRecentAuth.php`, `app/Http/Controllers/Auth/ConfirmRecentAuthController.php`, `app/Listeners/Auth/StampRecentAuthOnLogin.php`

アカウント削除・オーナー移譲・API キー発行 / 失効などの機微操作の前に、**汎用的な** step-up 再認証を強制する。
Fortify 生の `password.confirm` (password 専用・3h 窓) を置き換え、SSO-only ユーザーも fail-closed で
詰まずに再SSO へ誘導される。

### 時間窓の判定契約

- 鮮度判定の**単一ソース**は `RecentAuthWindow::isFresh()`。session の `recent_auth_at` (unix timestamp / int) が
  `config('auth.recent_auth_timeout')` (既定 900 秒) 以内かを `(now - confirmedAt) <= timeout` で判定する。
- 解釈不能 (非 int) / `timeout <= 0` / 未来 timestamp (clock skew・改竄) はすべて `false` (= 未確認扱い、fail-closed)。
- 強制点 (`RequireRecentAuth`) と UI 補助 (status endpoint) の**双方がこの単一述語を参照**し、判定ドリフトを防ぐ。

### 成立 (satisfier) と session state

- session state の**唯一の writer** は `RecentAuthState`。satisfier 成立時に `recent_auth_at` /
  `recent_auth_method` (`password` | `sso` | `login`) / `recent_auth_provider` を dedicated key に書く。
  Fortify の `auth.password_confirmed_at` には**書かない** (意味汚染回避、横断標準は `recent_auth_at` が正本)。
- 成立時は `session()->migrate(true)` で session ID を rotate する (OWASP: 権限上昇時の session fixation 対策)。
  `regenerate()` と違い **CSRF token は維持**するため、XHR モーダルや別タブの進行中フォームを壊さない。
- satisfier は 2 経路: password 再入力 (`ConfirmRecentAuthController::confirmPassword`) と
  再SSO (`SocialAuthController` の `intent=step-up`)。password 未設定 (SSO-only) は password 経路を **fail-closed** で拒否し、
  再SSO へ誘導する。step-up 可能な provider は `config('template.social_providers.*.capability')` から解決 (未宣言は satisfier 不可)。
- fresh login (`Login` event、web guard・非 recaller) は `StampRecentAuthOnLogin` が `method='login'` で自動 stamp する。
  ログイン直後の機微操作で「もう 1 回」の二重壁を消す。remember-me による自動復元 (`viaRemember()`) は fresh 扱いしない (fail-closed)。
- 認証要素変更 (password / email / 2FA / social link·unlink) 後は `RecentAuthState::clear()` で鮮度を失効させる。

### XHR / 画面応答の差 (`RequireRecentAuth`、alias `recent-auth`)

鮮度切れ時、リクエスト種別で応答を出し分ける。

| リクエスト種別 | 応答 | クライアント挙動 |
|--------------|------|----------------|
| 鮮度ウィンドウ内 | 通過 | — |
| XHR (`expectsJson`) / Inertia の非 GET mutation | `409` + `{ code, message, redirect }` (`no-store`) | 再認証後に元操作を再送 |
| 通常遷移 (GET 等) | `302` で `recent-auth.confirm` へ | 元 URL を `url.intended` に保持し、confirm 成功後に replay |

- `409` に `x-inertia-location` / `x-inertia-redirect` ヘッダは付けない (Inertia core の external redirect 信号と衝突するため)。
- 非 GET の 302 fallback は mutation body を保持できないため、`recent_auth.dropped_mutation` one-shot flag を立て、
  confirm 成功後に「もう一度操作してください」を案内する (サイレント喪失の防止)。
- `intended` は GET は `fullUrl()`、非 GET は referer を採用するが、**same-origin のみ** (`origin` 完全一致 or `origin + '/'`
  前置一致) を許可し、それ以外は dashboard へ倒す (open redirect 防止)。

### レスポンス契約 (`ConfirmRecentAuthController::confirmPassword`)

- Inertia リクエスト (standalone confirm 画面の form.post、`X-Inertia` あり) → `redirect()->intended(dashboard)`。
- 非 Inertia XHR (インラインモーダルの fetch、`X-Inertia` なし) → `204 No Content` (クライアントが pending action を再実行)。
- `status` endpoint (`recent-auth.status`) はクライアント主導モーダルの precheck (`no-store`)。最終 gate は必ず `RequireRecentAuth`。

フロントは `resources/js/lib/recent-auth.ts` / `resources/js/components/organisms/RecentAuthModal.svelte` /
`resources/js/pages/Auth/ConfirmRecentAuth.svelte`。

---

## 2. 組織 2FA 強制 (enforced two-factor)

**実装**: `app/Http/Middleware/{RequireTwoFactorForEnforcedOrganizations,BlockTwoFactorDisableForEnforcedOrganizations}.php`, `app/Enums/TwoFactorStatus.php`, `app/Notifications/User/TwoFactorResetSecurityNotification.php`, `app/Console/Commands/ResetAdminMfaCommand.php`

組織が `two_factor_required` 属性を立てると、その組織に所属するユーザーの 2FA が必須化される。
両 middleware は web group に append 配線される (`SecurityHeaders` の後、`RequireTwoFactorForEnforcedOrganizations`
→ `BlockTwoFactorDisableForEnforcedOrganizations` の順)。判定は状態非依存の単一述語
`User::firstTwoFactorRequiringOrganization()` を共有する。

### 2 段防御の契約

**(1) 未準拠ユーザーの全画面ゲート** (`RequireTwoFactorForEnforcedOrganizations`):
1 つでも `two_factor_required` な組織に所属する **2FA 未完了 (disabled / pending)** ユーザーは、
`ALLOWED_ROUTE_NAMES` (2FA 設定達成に必要な route + logout / メール検証 / step-up 等) 以外の全 web 経路から
`settings.security` へ `302` (XHR は `409` + `{ code, message, redirect }`) される。組織スコープの部分制限は採らない
(2FA はアカウント全体の属性のため)。準拠 (enabled) ユーザーは attribute 判定のみで追加クエリゼロ。

**(2) 準拠ユーザーの self-disable 禁止** (`BlockTwoFactorDisableForEnforcedOrganizations`):
準拠ユーザーが `DELETE /user/two-factor-authentication` (`two-factor.disable`) を打つと action 自体は通ってしまい、
直後のリクエストで初めてゲートされて詰む (一時的に第二要素を失う)。本 middleware は disable の**到達自体を弾く**
(XHR は `422` + `{ code, message }`、HTML は flash error + back)。判定は現在の 2FA 状態に依存しない
`firstTwoFactorRequiringOrganization()` (enabled でも縛りは残るため)。`disable` route は (1) の allowlist から意図的に
除外され、未準拠者の disable は (1) が先に弾く。

### 状態機械と復旧

- `TwoFactorStatus` は Fortify の 2 カラム (`two_factor_secret` / `two_factor_confirmed_at`) から導出する 3 値
  (`Disabled` / `Pending` / `Enabled`)。bool 化すると「未設定」と「設定途中」が潰れ、設定ページ再訪で secret を
  再生成して認証アプリ側エントリを無効化してしまうため、`pending` を独立状態として保つ。
- やむを得ず外す場合は組織管理者の resetTwoFactor (`organizations.members.two-factor.reset`) 経由のみ。
  解除時は対象ユーザー本人へ `TwoFactorResetSecurityNotification` を送り、乗っ取り・誤操作を検知できるようにする。
- 管理者 (AdminUser) の MFA break-glass は `php artisan admin:reset-mfa {id} --reason=...` (reason 必須・10 文字以上、監査証跡)。

---

## 3. セキュリティヘッダ / 本番ハードニング層

**実装**: `app/Http/Middleware/{SecurityHeaders,RedirectToHttps,NoIndex,LocalOnly}.php`, `app/Support/{ProductionEnvGuard,TrustedHostsConfigValidator}.php`, `app/Console/Commands/ProductionPreflightCommand.php`, `config/{security,trusted_hosts}.php`

### baseline セキュリティヘッダ (`SecurityHeaders`)

web group に append され、`config/security.php` 駆動で以下を送出する。

- 常時: `X-Frame-Options: DENY` / `X-Content-Type-Options: nosniff` / `Referrer-Policy: strict-origin-when-cross-origin`。
- `Permissions-Policy` (config、空 / null は opt-out で env 一時 rollback 可)。
- `Strict-Transport-Security` (`security.hsts.enabled` のとき。`max_age` / `includeSubDomains` / `preload` を config 組み立て)。
- `Content-Security-Policy` (`security.csp.enabled` のとき。既定は strict。GTM を実際に描画する条件下
  = production + container_id のときのみ `gtm_directives` を該当 directive にマージして緩める)。
- **`.well-known/oauth-*` の例外**: OAuth / MCP discovery metadata は client が programmatic fetch する JSON のため、
  フル baseline を当てず `security.metadata_headers` の最小 subset (nosniff + no-referrer) のみ適用して early return する
  (HSTS は `security.metadata_hsts_enabled` が true のときだけ)。この最小 subset の後付け配線は `routes/ai.php` 側。

### その他のハードニング middleware

| middleware | 役割 |
|-----------|------|
| `RedirectToHttps` | HTTP → HTTPS の 308 リダイレクト。`FORCE_HTTPS_REDIRECT=true` で有効化 (LB 終端構成では off)。最外周に `prepend` |
| `NoIndex` | `X-Robots-Tag: noindex` 付与。`<meta robots>` に加えた二重防御 (文面未確定の法的スタブ等) |
| `LocalOnly` | local 専用経路 (debug login 等) のゲート。local 以外 / env 未設定は 404 (fail-secure)、資格情報は Basic 認証 + `hash_equals` (timing-safe) |

`RedirectToHttps` は `trustProxies`(`bootstrap/app.php`)設定済みなら `X-Forwarded-Proto` を見て `isSecure()` を判定する。

### Host header injection 防御 (TrustHosts)

`bootstrap/app.php` の `trustHosts()` が `config/trusted_hosts.php` の許可 host を regex pattern (`^host$`) に変換する
(`preg_quote` で `.` の誤ヒットを防ぐ)。production で allowlist が空だと全 request が 400 (SUSPICIOUS_OPERATION) になる
事故を防ぐため、`TrustedHostsConfigValidator` が起動時 fail-fast する (allowlist 空 / wildcard suffix の書式違反 /
`PRIMARY_HOST` の host 形式違反)。

### production 起動時 / デプロイ前の fail-fast

- **単一ソース (SSOT)** は `ProductionEnvGuard`。`AppServiceProvider::boot()` (production 起動時) と
  `production:preflight` コマンドの双方がこの guard を参照する。検査項目: `APP_KEY` / `CIPHERSWEET_KEY` /
  `STRIPE_WEBHOOK_SECRET` 非空、`SESSION_SECURE_COOKIE=true`、`APP_DEBUG=false`、
  `SECURITY_HSTS_ENABLED` / `SECURITY_CSP_ENABLED=true`、`DEBUG_LOGIN_*` が空、TrustHosts allowlist 非空 / 書式。
- `php artisan production:preflight --strict` を deploy パイプラインの pre / post-deploy で実行する。
  1 件でも違反があれば exit 1 (デプロイを止める)。`--strict` は `APP_ENV` が production でない場合も fail させる
  (CI/CD での APP_ENV 設定ミス検出)。mail 設定検査 (`operations:check-mail-config`) も委譲する。

---

## 関連ファイル

| ファイル | 役割 |
|---------|------|
| `app/Security/RecentAuthWindow.php` | recent-auth 鮮度判定の単一ソース (`isFresh`) |
| `app/Security/RecentAuthState.php` | recent-auth session state の唯一の writer (`confirm` / `clear`、session migrate) |
| `app/Http/Middleware/RequireRecentAuth.php` | `recent-auth` alias。機微操作 route の step-up ゲート (409 / 302 出し分け) |
| `app/Http/Controllers/Auth/ConfirmRecentAuthController.php` | confirm 画面 (`show`) / precheck (`status`) / password satisfier (`confirmPassword`) |
| `app/Listeners/Auth/StampRecentAuthOnLogin.php` | fresh login を recent-auth 成立として stamp (recaller 除外) |
| `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` | 2FA 未準拠ユーザーの全画面ゲート (allowlist 外を 302 / 409) |
| `app/Http/Middleware/BlockTwoFactorDisableForEnforcedOrganizations.php` | 準拠ユーザーの self-disable 到達を弾く (422 / back) |
| `app/Enums/TwoFactorStatus.php` | Fortify 2 カラムから導出する 3 値状態機械 (Disabled / Pending / Enabled) |
| `app/Notifications/User/TwoFactorResetSecurityNotification.php` | 組織管理者による 2FA 解除の本人通知 |
| `app/Console/Commands/ResetAdminMfaCommand.php` | AdminUser の MFA break-glass リセット (`admin:reset-mfa`、reason 必須) |
| `app/Http/Middleware/SecurityHeaders.php` | baseline セキュリティヘッダ (CSP / HSTS / frame 等)。`.well-known/oauth-*` は最小 subset |
| `app/Http/Middleware/RedirectToHttps.php` | HTTP→HTTPS 308 リダイレクト (`FORCE_HTTPS_REDIRECT`) |
| `app/Http/Middleware/NoIndex.php` | `X-Robots-Tag: noindex` 付与 |
| `app/Http/Middleware/LocalOnly.php` | local 専用経路ゲート (fail-secure 404 + Basic 認証) |
| `app/Support/ProductionEnvGuard.php` | production env baseline 検査の SSOT (起動時 fail-fast + preflight 委譲) |
| `app/Support/TrustedHostsConfigValidator.php` | TrustHosts allowlist の production 起動時検証 |
| `app/Console/Commands/ProductionPreflightCommand.php` | `production:preflight`。デプロイ前の設定検査 (違反で exit 1) |
| `config/security.php` | CSP / HSTS / Permissions-Policy / metadata subset / HTTPS リダイレクトの設定 |
| `config/trusted_hosts.php` | Host header allowlist (exact / wildcard suffix) |
| `bootstrap/app.php` | 上記 middleware の配線 (prepend / web append / alias / trustHosts / trustProxies) |
