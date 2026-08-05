# 認証・セキュリティ横断機構

**実装**: `app/Security/`, `app/Http/Middleware/` (認証・ハードニング系), `app/Support/{ProductionEnvGuard,TrustedHostsConfigValidator}.php`, `app/Console/Commands/{ProductionPreflightCommand,ResetAdminMfaCommand}.php`, `config/{security,trusted_hosts}.php`

## 概要

テンプレート共通のセキュリティ横断機構を 6 つ束ねて記述する。いずれも特定のドメインに属さず、
リクエスト / デプロイの横断層で動く。

1. **機微操作の再認証 (recent-auth / step-up)** — Critical Action の直前に「直近の再認証」を要求する。
2. **組織 2FA 強制 (enforced two-factor)** — 組織が 2FA を必須化した場合の未準拠ユーザーゲート + self-disable 禁止。
3. **セキュリティヘッダ / 本番ハードニング層** — baseline セキュリティヘッダ、認証済み応答の
   `no-store` baseline、production 起動時 / デプロイ前の fail-fast。
4. **SSO email の信頼方針 (email trust policy)** — IdP が主張する email を検証済みとして
   扱ってよいかを provider ごとに宣言し、宣言のないものは fail-closed に倒す。
5. **パスキー (WebAuthn)** — Fortify + laravel/passkeys のログイン / 再認証 / 管理経路と、
   アプリ側が被せる不変条件 (所有者スコープ binder・応答契約・TOTP との関係)。
6. **ログイン手段保持 guard** — ログイン手段が 0 になる操作を、投影後評価と行ロックで止める。

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
  `recent_auth_method` (`password` | `sso` | `login` | `passkey`) / `recent_auth_provider` を dedicated key に書く。
  Fortify の `auth.password_confirmed_at` には**書かない** (意味汚染回避、横断標準は `recent_auth_at` が正本)。
- 成立時は `session()->migrate(true)` で session ID を rotate する (OWASP: 権限上昇時の session fixation 対策)。
  `regenerate()` と違い **CSRF token は維持**するため、XHR モーダルや別タブの進行中フォームを壊さない。
- satisfier は 3 経路: password 再入力 (`ConfirmRecentAuthController::confirmPassword`)、
  再SSO (`SocialAuthController` の `intent=step-up`)、パスキー検証
  (`StampRecentAuthOnPasskeyVerified`。`POST /passkeys/confirm`)。
  **どの手段が使えるかはサーバの `/recent-auth/status` が単一の源** (`passwordSet` /
  `availableProviders` / `passkeyAvailable`)。画面ごとに判定を持たせない
  (持たせると passkey しか持たないユーザーが特定画面でだけ詰む)。
  `canSatisfy` はこの 3 つの論理和であり、**パスキーは TOTP の有無に関係なく再認証に使える**
  (`PasskeyLoginPolicy` が縛るのは login のみ)。
- ⚠ **`canSatisfy` は「アカウントに手段があるか」であり「この端末で実行できるか」ではない**。
  WebAuthn の feature detection はクライアントにしか無いため、パスキーしか持たないユーザーが
  非対応ブラウザで開くと「手段はあるのに何も出ない」無言の行き止まりになりうる。
  両 UI (`RecentAuthModal` / `Auth/ConfirmRecentAuth`) は
  `passwordSet || availableProviders || (passkeyAvailable && passkeySupported)` を
  クライアント側で導出し、成立しない場合は**理由と回復導線を明示**する
  (`recent-auth-unsupported-here` / `confirm-unsupported-here`)。password 未設定 (SSO-only) は password 経路を **fail-closed** で拒否し、
  再SSO へ誘導する。step-up 可能な provider は `config('template.social_providers.*.capability')` から解決 (未宣言は satisfier 不可)。
- fresh login (`Login` event、web guard・非 recaller) は `StampRecentAuthOnLogin` が `method='login'` で自動 stamp する。
  ログイン直後の機微操作で「もう 1 回」の二重壁を消す。remember-me による自動復元 (`viaRemember()`) は fresh 扱いしない (fail-closed)。
- 認証要素変更 (password / email / 2FA / social link·unlink) 後は `RecentAuthState::clear()` で鮮度を失効させる。
  **パスキーの登録 / 削除**は `ClearRecentAuthOnPasskeyChange` が実際に `clear()` を呼ぶ (2026-08-04 裁定 A。§5 参照)。
- satisfier の集合 (= `RecentAuthState::confirm()` の呼び出し元) は
  `tests/Architecture/RecentAuthRouteTest.php` の inventory が deny-by-default で固定する。
  新しい satisfier を足すには inventory への登録が必須 (= step-up の成立条件が増えることを PR で必ず判断させる)。

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

**実装**: `app/Http/Middleware/{SecurityHeaders,NoStoreCacheHeadersForAuthenticatedPages,RedirectToHttps,NoIndex,LocalOnly}.php`, `app/Support/{ProductionEnvGuard,TrustedHostsConfigValidator}.php`, `app/Console/Commands/ProductionPreflightCommand.php`, `config/{security,trusted_hosts}.php`

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

### 認証済み応答の no-store baseline (`NoStoreCacheHeadersForAuthenticatedPages`)

ログアウト後のブラウザ「戻る」で認証済み画面 (PII を含む) が bfcache から再表示されるのを
防ぐ baseline。**適用判定は route 列挙ではなく「認証済みか」**で行う (path 列挙は一般認証画面を
取りこぼす)。guest / 公開ページ (login・LP・SEO) は対象外のままにして bfcache と共有キャッシュの
恩恵を維持する。

- リクエスト時点 **または** 応答時点のどちらかで認証済みなら `Cache-Control: no-store, private`
  を付与する (logout POST の redirect、login POST の応答をどちらも保護側に倒す)。
- 既に `no-store` を持つ応答 (recent-auth 409 / 2FA 409 / 署名 URL redirect 等、内側で明示された
  より厳格な値) は書き換えない = **directive が縮む方向の上書きをしない**。
- session を持たないリクエスト (`routes/web.php` の stateless block) は stateless 公開配信のため
  対象外。
- **Safari は `no-store` でも bfcache に格納しうる**ため本 middleware だけでは足りない。
  撮影 PWA の主戦場が iOS Safari である以上、クライアント側の bfcache 秘匿・再検証
  (`resources/js/lib/bfcache-guard.ts` + `session.status` プローブ) と**セット**で守る契約
  (正本は `docs/supported-browsers.md`、Browser テストのレーン契約は `docs/testing-browser.md`)。
- `session.status` プローブ (`app/Http/Controllers/Auth/SessionStatusController.php`) は
  **auth グループの外**に置く。auth 配下だと未認証時に 302/401 になり、guard 側で
  「セッション無効」と「endpoint 不在 / ネットワーク障害」を区別しにくくなるため、guest でも
  200 + `authenticated: false` を返して判定を boolean 一本にする。

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

### client IP の信頼境界 (TrustProxies)

`bootstrap/app.php` の `trustProxies()` は **`at:` を渡さない**。Laravel の `TrustProxies` は
`$this->proxies() ?: config('trustedproxy.proxies')` の順で解決するため、`at:` を渡さないことで
env 由来の allowlist (`TRUSTED_PROXIES` → `config/trustedproxy.php`) が正本になる。

かつて `at: '*'` だった。全アドレスを trusted proxy 扱いにすると `$request->ip()` は
**X-Forwarded-For 最左 = クライアントが自由に書ける値**になり、次がすべて攻撃者の制御下に落ちる:

| 影響先 | 壊れ方 |
|---|---|
| IP ベースの rate limiter (`inquiry` / `oauth-register` 等) | XFF を変えるだけでバケットを無限に増やせる = 実質無制限 |
| reCAPTCHA / bot 対策の IP 判定 | 同上 |
| `security_audit_events.ip_address` | 監査ログの IP が任意の値になる (追跡不能) |

逆に **hop を 1 つでも取りこぼす**と client IP がその hop の内部 IP に固定され、
全利用者が 1 つの rate limit バケットに落ちる (**自己 DoS**)。多段構成
(CDN → LB → app) では経路上のすべての hop を列挙する必要がある。

production で `TRUSTED_PROXIES` が未宣言 / `*` / `REMOTE_ADDR` / 書式不正のときは
`TrustedProxiesConfigValidator` (`ProductionEnvGuard` 経由) が**起動時 fail-fast** する。
プロキシが無い構成は `none` の**明示宣言**を要求する (未設定と区別する)。
運用契約・実 hop 一覧・変更手順は `docs/trusted-proxies-runbook.md` が正本。

なお `RedirectToHttps` は **`append`** で global middleware に載せる (`prepend` にしない)。
`prepend` すると `TrustProxies` より前に走り `$request->isSecure()` が `X-Forwarded-Proto` を
見られず、LB 終端 + `FORCE_HTTPS_REDIRECT=true` で 308 の無限ループになる。

> 既存の `security_audit_events.ip_address` は `at: '*'` 時代の値を含み**遡及訂正できない**。
> 過去分の IP は信頼できない値として扱うこと。

### production 起動時 / デプロイ前の fail-fast

- **単一ソース (SSOT)** は `ProductionEnvGuard`。`AppServiceProvider::boot()` (production 起動時) と
  `production:preflight` コマンドの双方がこの guard を参照する。検査項目: `APP_KEY` / `CIPHERSWEET_KEY` /
  `STRIPE_WEBHOOK_SECRET` 非空、`SESSION_SECURE_COOKIE=true`、`APP_DEBUG=false`、
  `SECURITY_HSTS_ENABLED` / `SECURITY_CSP_ENABLED=true`、`DEBUG_LOGIN_*` が空、TrustHosts allowlist 非空 / 書式、
  **TrustProxies allowlist (`TRUSTED_PROXIES`) の宣言と書式**。
- `php artisan production:preflight --strict` を deploy パイプラインの pre / post-deploy で実行する。
  1 件でも違反があれば exit 1 (デプロイを止める)。`--strict` は `APP_ENV` が production でない場合も fail させる
  (CI/CD での APP_ENV 設定ミス検出)。mail 設定検査 (`operations:check-mail-config`) も委譲する。

---

## 4. SSO email の信頼方針 (email trust policy)

**実装**: `app/Services/Auth/EmailTrust/`, `app/Enums/EmailTrustLevel.php`, `config/template.php` (`social_providers.*.email_trust`)

SSO 登録 (`Auth/SocialAccountService::register`) は、IdP が主張する email を無条件に
検証済み (`email_verified_at`) として扱わない。provider ごとの信頼段階の宣言を
`EmailTrustPolicyResolver` が `EmailTrustPolicy` へ解決し、`trustsEmail()` が true の
場合にのみ `email_verified_at` を立てる。

### Confirmed の判定基準 (契約)

`email_trust = confirmed` を名乗ってよいのは、次の **2 条件をともに満たす** provider だけ。

1. provider が当該 email の **所有を検証済み** である。
2. **テナント管理者が任意の email を claim できない** (所有権を証明していないドメインの
   email を主張できない)。

満たさない / 不明な場合は `unconfirmed` に置き、アプリ側のメール到達確認
(`/email/verify`) を経てから検証済みにする。

- **Google**: Gmail / Workspace とも email 所有を検証しており、管理者は所有権を証明した
  ドメイン外を claim できないため `confirmed`。
- **Microsoft (Entra ID)**: テナント管理者が未検証の email claim を任意に設定でき、
  他社ドメインの email を主張できる (nOAuth)。追加する場合は必ず `unconfirmed` から始める。

### fail-closed と機械強制

`email_trust` の未宣言・解釈不能 (非文字列 / 未知値) はすべて `Unconfirmed` に倒れる
(`EmailTrustLevel::fromRaw`)。宣言漏れは静かな機能劣化 (登録者がメール確認を求められる) に
なるため、`tests/Architecture/SocialProviderTrustPolicyTest.php` が
「全 provider が `capability` / `email_trust` を明示宣言していること」と
「google の `email_trust` が `confirmed` であること (現行挙動の pin)」を CI で強制する。

policy を interface にしてあるのは nOAuth 対策の**キルスイッチ**を残すため
(provider の運用変更が判明したら宣言 1 行を `unconfirmed` に倒せば新規登録が
メール確認経路に落ちる)。

---

## 5. パスキー (WebAuthn)

**実装**: `app/Providers/PasskeyServiceProvider.php`, `app/Models/Passkey.php`, `app/Http/Responses/Passkey/`, `app/Http/Routing/SelfScopedPasskeyBinder.php`, `app/Services/Auth/PasskeyLoginPolicy.php`, `app/Listeners/Auth/{ClearRecentAuthOnPasskeyChange,StampRecentAuthOnPasskeyVerified}.php`, `resources/js/lib/passkeys.ts`, `resources/js/components/features/auth/PasskeySection.svelte`

route / controller / action / migration は **Fortify + laravel/passkeys が提供する**
(`config/fortify.php` の `Features::passkeys(['confirmPassword' => false])` が唯一の有効化点 =
**実質的なキルスイッチ**)。アプリ側 (`PasskeyServiceProvider`) は「vendor にアプリ固有の
不変条件を被せる」ことだけを担う (監査記録だけは `RecordSecurityEvent` が vendor イベントを購読する)。

### アプリが被せる 5 つの不変条件

| # | 内容 | 理由 |
|---|------|------|
| 1 | **binder 差し替え** (`SelfScopedPasskeyBinder`) | vendor binder はグローバル id 解決 → controller の `abort_unless(..., 403)` に到達し **他人の passkey の存在が漏れる**。所有者スコープで解決し「他人」と「不在」を等しく 404 にする (セキュリティ不変条件 2)。非数値 / bigint 範囲外も 404 に倒す (pgsql 22P02 / 22003 の 500 化を防ぐ) |
| 2 | **Response contract 上書き** (`app/Http/Responses/Passkey/`) | vendor 既定は `new JsonResponse(...)` の直返しで禁止事項 4 に触れる。加えて confirm 経路が書く `auth.password_confirmed_at` をここで**除去**する (recent-auth の「Fortify の鍵には書かない」契約を守る) |
| 3 | **route middleware の後付け** | `throttle:passkeys` (削除。vendor は destroy にだけ throttle を付けない)、`recent-auth` (登録 / 削除)、`ensure-login-method` (削除)、`no-store` (guest の login-options)。順序は **throttle → recent-auth → ensure-login-method** (逆順だと stale なリクエストでも `EnsureLoginMethodRemains` が User 行 `lockForUpdate` を取りに行き、無制限のロック競合を作れる) |
| 4 | **login 認可** (`PasskeyLoginPolicy`) | TOTP confirmed ユーザーの passkey login を拒否する |
| 5 | **増減の監査記録** (`RecordSecurityEvent`) | パスキーは**単独でログインできる強い資格**のため、登録 / 削除を `security_audit_events` (`passkey_registered` / `passkey_deleted`) に残す (セッション乗っ取り後の永続化を事後追跡できるようにする)。credential 本体 (公開鍵 / signature counter) は metadata に載せない |

配線は `$app->booted()` 内で最終上書きする (auto-discovery された
`Laravel\Passkeys\PasskeysServiceProvider` との boot 順序が `bootstrap/providers.php` では
保証されないため)。構成は `tests/Architecture/{PasskeyPackageContractTest,PasskeyRouteProtectionTest}.php` が固定する。

### TOTP との関係 (c2c 未裁定に対する fail-closed 既定)

vendor の `PasskeyLoginController::store()` は `$guard->login()` を直接呼び、Fortify の
two-factor challenge を通らない。したがって **TOTP confirmed のユーザーは passkey login を
拒否する** (assurance の後退を作らない)。判定は `PasskeyLoginPolicy` **1 箇所**に集約してあり、
(a) vendor の login ゲート (b) `LoginMethodInventory` の passkey 判定 (c) Settings 画面の
`passkeyLoginAvailable` prop が同時に反転する。裁定が出たらこのクラスだけを書き換える。

**passkey は 2FA 準拠判定に算入しない**。2FA 必須組織の未準拠ユーザーは passkey を持っていても
`RequireTwoFactorForEnforcedOrganizations` のゲートに掛かる。

### credential 集合の変化 = recent-auth 失効 (2026-08-04 裁定 A)

パスキーは単独でログインできる強い資格であり、集合が変わったら直前に済ませた本人確認は失効させる
(家系統一原則)。`PasskeyRegistered` / `PasskeyDeleted` を `ClearRecentAuthOnPasskeyChange` が購読する。
UX の実害は「登録直後のタップ 1 回」に限られる。
**「登録直後の passkey を satisfier から除外する」強化オプションは裁定で見送り済み**
(再検討条件: パスキーが 2FA 準拠判定に算入される時、または放置端末起点の実被害が観測された時)。

### transport 契約 (client ↔ server)

| operation | options 取得 | 送信 | 成功応答 |
|-----------|-------------|------|---------|
| 登録 | `fetch GET /user/passkeys/options` | Inertia `router.post('/user/passkeys')` | `back()->with('success')` |
| 削除 | — | Inertia `router.delete('/user/passkeys/{id}')` | `back()->with('success')` |
| 再認証 (インラインモーダル) | `fetch GET /passkeys/confirm/options` | `fetch POST /passkeys/confirm` | `204` + `no-store` |
| 再認証 (全画面 confirm) | 同上 | Inertia `router.post('/passkeys/confirm')` | `redirect()->intended()` |
| ログイン | `fetch GET /passkeys/login/options` | `fetch POST /passkeys/login` | JSON `{redirect}` |

`@/lib/passkeys` の import 元は `tests/js/architecture/passkeys-import-isolation.test.ts` が
allowlist で固定する (transport 契約の食い違いは**無言失敗**として現れるため)。

### 運用上の注意

- 設定は `APP_URL` から導出される (relying party id = ホスト、allowed origins = `[APP_URL]`)。
  同一オリジン PWA 前提のため専用 env は持たない。
- **`APP_KEY` をローテートすると user handle (`hash_hmac` の鍵が `APP_KEY`) が変わり、
  登録済みパスキーが全件無効になる**。鍵ローテートを行う場合は
  `PASSKEYS_USER_HANDLE_SECRET` 相当の固定値を `config/passkeys.php` に持たせる設計変更が必要。
- 未認証の challenge 発行 (`GET /passkeys/login/options`) は `throttle:passkeys` (10/min) で絞る。
  `config('fortify.limiters.passkeys')` が未設定だと Fortify が throttle を外し **無制限**になる。

---

## 6. ログイン手段保持 guard (`EnsureLoginMethodRemains`)

**実装**: `app/Http/Middleware/EnsureLoginMethodRemains.php`, `app/Services/Auth/LoginMethodInventory.php`, `app/DataTransferObjects/Auth/{LoginMethodRemoval,LoginMethodSet}.php`

ログイン手段を全部消して自分で締め出す事故は復旧コストが高く、現場を止める。
手段を減らす操作の前に「実行後も最低 1 つ手段が残る」ことを保証する。

- **評価するのは現在状態ではなく「操作が成功した後の投影状態」**。素朴に現在を数えると
  削除対象自身が残存手段として数えられ、「唯一の passkey を削除できてしまう」= 意図と正反対になる。
- **直列化規約 (TOCTOU 対策)**: middleware が (1) transaction を開き (2) 対象 User 行を
  `lockForUpdate()` で取り (3) **ロック取得後に**投影を評価し (4) **同一 transaction 内で `$next()`**
  を実行する。ロック取得順序は User → credential。
- 手段の基準は「データが存在する」ではなく「**使える**」(`LoginMethodInventory`)。
  config から外された provider や feature off の passkey は数えない (数えると guard が形骸化する)。
- `canSatisfy` (recent-auth の step-up 成立可否) とは**別概念**。統合しないこと。

### 応答契約 (transport で分岐)

| リクエスト種別 | 応答 |
|--------------|------|
| Inertia | `302` (Inertia が DELETE では 303 に変換) + `errors.login_method` |
| 純 XHR (`Accept: application/json`) | `422` + `{ code: 'login_method_required', message, settingsUrl }` (`no-store`) |
| 通常フォーム | `back()->withErrors('login_method')` |

**Inertia に 422 JSON を返さない** (protocol 違反で router が応答を解釈できず無言失敗する)。

### 適用範囲の機械強制

`tests/Architecture/LoginMethodRemovalRouteTest.php` が **両方向**で固定する。

1. 認証系 URI 空間の破壊的 route は「guard 必須」か「理由付き免除」のどちらかに**必ず分類**される。
2. **allowlist 外の route に付与してはならない** — `$next()` を transaction 内で実行するため、
   streamed response / 外部 I/O / `afterCommit` でない queue dispatch を含む route に付けると
   副作用範囲が急拡大する。

将来 password 削除 / SSO 連携解除 route を追加するときも**必ずこの middleware を通す**
(単一の直列化点。別経路を作ると TOCTOU が戻る)。

---

## 関連ファイル

| ファイル | 役割 |
|---------|------|
| `app/Security/RecentAuthWindow.php` | recent-auth 鮮度判定の単一ソース (`isFresh`) |
| `app/Security/RecentAuthState.php` | recent-auth session state の唯一の writer (`confirm` / `clear`、session migrate) |
| `app/Http/Middleware/RequireRecentAuth.php` | `recent-auth` alias。機微操作 route の step-up ゲート (409 / 302 出し分け) |
| `app/Http/Controllers/Auth/ConfirmRecentAuthController.php` | confirm 画面 (`show`) / precheck (`status`) / password satisfier (`confirmPassword`) |
| `app/Listeners/Auth/StampRecentAuthOnLogin.php` | fresh login を recent-auth 成立として stamp (recaller 除外) |
| `app/Http/Middleware/NoStoreCacheHeadersForAuthenticatedPages.php` | 認証済み応答の `no-store` baseline (bfcache 由来の PII 再表示防止) |
| `app/Http/Controllers/Auth/SessionStatusController.php` | セッション有効性の軽量プローブ (`session.status`)。auth グループの外・guest でも 200 |
| `app/Providers/PasskeyServiceProvider.php` | laravel/passkeys の app アダプタ (binder / Response contract / middleware 後付け / login 認可) |
| `app/Http/Routing/SelfScopedPasskeyBinder.php` | `{passkey}` を所有者スコープで解決 (他人 / 不在 / 不正型をすべて 404) |
| `app/Services/Auth/PasskeyLoginPolicy.php` | passkey **ログイン**可否の単一判定点 (feature flag + TOTP) |
| `app/Services/Auth/LoginMethodInventory.php` | 投影後のログイン手段集合 (`remainingAfter`) |
| `app/Http/Middleware/EnsureLoginMethodRemains.php` | `ensure-login-method` alias。手段が 0 になる操作を投影後評価 + 行ロックで止める |
| `app/Http/Middleware/NoStoreResponse.php` | `no-store` alias。guest route (passkey の login-options) の challenge をキャッシュさせない |
| `resources/js/lib/bfcache-guard.ts` | bfcache 復元時のクライアント側秘匿・再検証 (Safari 対策。正本は `docs/supported-browsers.md`) |
| `app/Http/Middleware/RequireTwoFactorForEnforcedOrganizations.php` | 2FA 未準拠ユーザーの全画面ゲート (allowlist 外を 302 / 409) |
| `app/Http/Middleware/BlockTwoFactorDisableForEnforcedOrganizations.php` | 準拠ユーザーの self-disable 到達を弾く (422 / back) |
| `app/Enums/TwoFactorStatus.php` | Fortify 2 カラムから導出する 3 値状態機械 (Disabled / Pending / Enabled) |
| `app/Notifications/User/TwoFactorResetSecurityNotification.php` | 組織管理者による 2FA 解除の本人通知 |
| `app/Console/Commands/ResetAdminMfaCommand.php` | AdminUser の MFA break-glass リセット (`admin:reset-mfa`、reason 必須) |
| `app/Http/Middleware/SecurityHeaders.php` | baseline セキュリティヘッダ (CSP / HSTS / frame 等)。`.well-known/oauth-*` は最小 subset |
| `app/Http/Middleware/RedirectToHttps.php` | HTTP→HTTPS 308 リダイレクト (`FORCE_HTTPS_REDIRECT`)。**append で TrustProxies の後**に走らせる |
| `config/trustedproxy.php` | `TRUSTED_PROXIES` の解釈 (framework が読む固定名の config キー) |
| `app/Support/TrustedProxyToken.php` | `TRUSTED_PROXIES` の 1 token 判定 (config 段と validator 段で共有) |
| `app/Support/TrustedProxiesConfigValidator.php` | client IP 信頼境界の production 起動時検証 (未宣言 / `*` / `REMOTE_ADDR` / 書式不正を拒否) |
| `docs/trusted-proxies-runbook.md` | 実 proxy hop 一覧・CIDR 管理主体・変更手順・切り分け (運用者記入欄あり) |
| `app/Listeners/RecordSecurityEvent.php` | 認証系イベント → `security_audit_events` の記録 (passkey 増減を含む)。網羅性は `SecurityEventCoverageTest` |
| `app/Http/Middleware/NoIndex.php` | `X-Robots-Tag: noindex` 付与 |
| `app/Http/Middleware/LocalOnly.php` | local 専用経路ゲート (fail-secure 404 + Basic 認証) |
| `app/Support/ProductionEnvGuard.php` | production env baseline 検査の SSOT (起動時 fail-fast + preflight 委譲) |
| `app/Support/TrustedHostsConfigValidator.php` | TrustHosts allowlist の production 起動時検証 |
| `app/Console/Commands/ProductionPreflightCommand.php` | `production:preflight`。デプロイ前の設定検査 (違反で exit 1) |
| `config/security.php` | CSP / HSTS / Permissions-Policy / metadata subset / HTTPS リダイレクトの設定 |
| `config/trusted_hosts.php` | Host header allowlist (exact / wildcard suffix) |
| `app/Services/Auth/EmailTrust/EmailTrustPolicy.php` | SSO email を検証済みとして信頼してよいかの方針 (interface。nOAuth 対策のキルスイッチ) |
| `app/Services/Auth/EmailTrust/EmailTrustPolicyResolver.php` | provider の `email_trust` 宣言から policy を解決 (未宣言は Unconfirmed) |
| `app/Enums/EmailTrustLevel.php` | 信頼段階 (Confirmed / Unconfirmed)。`fromRaw()` が fail-closed 変換の単一ソース |
| `bootstrap/app.php` | 上記 middleware の配線 (prepend / web append / alias / trustHosts / trustProxies) |
