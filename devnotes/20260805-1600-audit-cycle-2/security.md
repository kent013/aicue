# 多角監査 サイクル 2 — セキュリティ観点

対象: `git diff 4cbdff8..HEAD` (T103 認可 gate / T105 EmailTrustPolicy / T106 passkey)
監査日: 2026-08-05 / 対象ブランチ: main (HEAD = 9da2ef5)
参照した正本: `AGENTS.md` §セキュリティ不変条件、`docs/app-integration-guide.md` §7

### セキュリティ: RISK_FOUND

**Critical は 0 件。High 2 件 / Medium 2 件 / Low 5 件。**
High-1 (API 存在オラクル) は「存在オラクル封じ」という**今サイクルの主目的そのものに残った穴**で、
かつ Architecture テストが現在の (穴のある) 順序を機械固定しているため自然治癒しない。
**即座の TODO 登録を推奨する。**

---

## 1. 敵対的検証の結果

実装エージェントの自己申告は採用せず、以下の方法で自分で確認した。
テストは T099 のグローバルロック経由で実走した (結果は §1.9)。

### 1.1 存在オラクル封じ (cross-org の実在 project vs 不在 project)

**確認した方法**

1. `php artisan tinker` から `Router::gatherRouteMiddleware()` + `SortedMiddleware`
   (priority 適用後) で **実行時の最終 middleware 順序**をダンプした
   (宣言順ではなく実行順を見る。docblock を信用しない)。
2. `app/Exceptions/ApiExceptionRenderer.php` を読み、
   `ModelNotFoundException` (binding 失敗) と `NotFoundHttpException` (`abort(404)`) が
   同一 envelope に collapse されるかを確認した。
3. `extraHeaders()` が両者で空配列になることを確認した (ヘッダ差分の有無)。
4. `tests/Feature/Api/V1/ItemAuthorizationTest.php` を実走した (65 tests green)。

**結果 (body / ヘッダ)**: 問題なし。

- `ApiExceptionRenderer::toApiError()` L72-74 が `ModelNotFoundException | NotFoundHttpException`
  を **メッセージごと** `ApiError::fromCode(ApiErrorCode::NotFound)` に潰す。
  Laravel の `prepareException` が `ModelNotFoundException` を
  `NotFoundHttpException("No query results for model [App\Models\Project] 999")` に変換しても、
  その message は envelope に載らない。
- `extraHeaders()` L148-162 は `HttpExceptionInterface::getHeaders()` を写すだけで、
  404 の両経路とも空。ヘッダ差分なし。
- 実行時 middleware 順序 (実測):
  `Authenticate → Throttle → SubstituteBindings → ResolveApiActor → RequireApiKeyAbility → EnsureProjectBelongsToApiOrganization → IdempotentRequest`
  = `api.project-in-org` は **FormRequest より前** (controller 解決より前) に走る。
  `ItemAuthorizationTest` の「cross-org の実在 project と存在しない project id は完全に同一応答」
  (`$crossOrg->json() === $missing->json()`) も green。

**結果 (status)**: **穴あり → 下記 High-1**。
ability を持たないトークンでは 403 と 404 が実在/不在で分岐する。

**結果 (タイミング)**: 差分は存在する (下記 Low-4)。実害は限定的と判断。

### 1.2 passkey 削除 (他人 / 不在 / 非数値 / bigint 範囲外)

**確認した方法**: `app/Http/Routing/SelfScopedPasskeyBinder.php` を読み、
4 ケースがすべて同一の `ModelNotFoundException(setModel(Passkey::class))` に落ちることを確認。
vendor 側 (`vendor/laravel/passkeys/src/Http/Controllers/PasskeyRegistrationController.php:79`
の `abort_unless(..., 403)`) が **到達不能**であることを、
binder が `Route::bind('passkey', SelfScopedPasskeyBinder::class)` で後勝ちすること
(`PasskeyServiceProvider::boot()` L86-89 の `$app->booted()`) と、実行時 middleware ダンプで
`SubstituteBindings` が controller より前にあることの両方から確認。
`tests/Architecture/PasskeyPackageContractTest.php` / `tests/Feature/Auth/PasskeyRouteAccessTest.php`
を実走 (green)。

**結果**: 問題なし。4 ケースすべて同一 404。

- 他人の passkey → `where('user_id', $user->getKey())` を**解決クエリ自体**に含めるため
  取得段階で null → 404 (取得後に弾く実装ではない = 403/404 差分が出ない)。
- 不在 id → 同上 404。
- 非数値 (`ctype_digit` 不成立) → `normalizeIntegerId()` が null → 404 (22P02 も回避)。
- bigint 範囲外 (`ltrim($value,'0')` が 19 桁以上) → null → 404 (22003 も回避)。
- `setModel()` に id を渡していないため例外メッセージも 4 ケースで同一。
- 未認証時も `Auth::guard('web')->user()` が User でなければ fail-closed で 404。

### 1.3 RateLimiter 'passkeys' (未認証 challenge endpoint)

**確認した方法**:
`vendor/laravel/fortify/routes/routes.php:180-219` を読み、
`$passkeyGuestMiddleware = ['guest:web', 'throttle:passkeys']` が
`passkey.login-options` / `passkey.login` に付くことを確認。
`config/fortify.php` の `limiters.passkeys => 'passkeys'` と
`FortifyServiceProvider::configureRateLimiters()` L188 の limiter 本体が一致することを確認。
実行時 middleware ダンプで `ThrottleRequests:passkeys` が両 route に載っていることを実測。

**結果**: 配線は正しい (10/min、未認証は IP 単位、認証済みは user id 単位)。
**ただし IP キーそのものが偽装可能 → 下記 High-2。**

なお `passkey.destroy` だけ throttle が付かない (vendor の `$passkeyMiddleware` は
`$throttle` を含まない)。実測でも `passkey.destroy` に `ThrottleRequests` は無い → Medium-2。

### 1.4 PasskeyLoginPolicy の fail-closed (TOTP confirmed の passkey login 拒否)

**確認した方法**:
`vendor/laravel/passkeys/src/Http/Controllers/PasskeyLoginController.php:58-62` で
`Passkeys::allowsLogin()` が `$guard->login()` **より前**に呼ばれ、false なら
`InvalidPasskeyException` になることを確認。
`PasskeyServiceProvider::configureLoginAuthorization()` L98-104 が
`boot()` から無条件に登録され、`$user instanceof User` でなければ false (fail-closed) を確認。
`User::twoFactorStatus()` (app/Models/User.php:149-158) を読み、
`Enabled` = `two_factor_confirmed_at !== null` = **confirmed のみ**であることを確認。
`tests/Feature/Auth/PasskeyTwoFactorInteractionTest.php` 実走 (green)。

**結果**: 問題なし。TOTP **confirmed** ユーザーは passkey login を拒否される。
`Pending` (secret はあるが未 confirm) は許可されるが、Fortify 側も未 confirm では
two-factor challenge を出さないため assurance の後退にならない (一貫している)。
feature flag (`Features::passkeys()`) を外すと同時に false になるキルスイッチも成立。

**副作用の確認**: `VerifyPasskey::__invoke()` は `allowsLogin()` **より前**に
`PasskeyVerified` を dispatch する。deny 経路で session が汚れないことを
`StampRecentAuthOnPasskeyVerified` L45-48 (`request()->user()` が User でなければ return =
login 経路は guest なので stamp しない) で確認。本人性バインド (L52-59) も
`(string)` 比較 + 非 scalar は不一致に倒す fail-closed。問題なし。

### 1.5 PasskeyConfirmationResponse の forget() (auth.password_confirmed_at 非汚染)

**確認した方法**:
`vendor/.../PasskeyConfirmationController.php:70` が `$session->passwordConfirmed()` を
呼ぶことを確認 → `app/Http/Responses/Passkey/PasskeyConfirmationResponse.php:34` が
`forget('auth.password_confirmed_at')` することを確認。
`toResponse()` は Router の `prepareResponse` (Responsable 変換) で呼ばれ、
`StartSession` の session 保存 (middleware 復路) **より前**に走る = 除去が確実に効く。
`register()` L75 で contract が singleton bind されており vendor 既定に落ちないことも確認。
`tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php` 実走 (green)。

**結果**: 問題なし。`auth.password_confirmed_at` は汚染されない。
`recent_auth.dropped_mutation` の one-shot flag も `pull()` で両経路消費されている。

### 1.6 EnsureLoginMethodRemains の抜け道

**確認した方法**:
(a) `tests/Architecture/LoginMethodRemovalRouteTest.php` の候補定義
    (`user/passkeys` / `settings/social` / `user/password` / `settings/account` prefix ×
    DELETE|PUT|PATCH) を読み、deny-by-default であることを確認。
(b) `routes/web.php` / vendor routes を grep して **SSO 連携解除 route と password 削除 route が
    実在しないこと**を確認 (= 現状 passkey.destroy が唯一の除去経路)。
(c) 実行時 middleware ダンプで `passkey.destroy` の順序が
    `RequireRecentAuth (14) → EnsureLoginMethodRemains (15)` であることを実測。
(d) TOCTOU: `handle()` L65-78 が `DB::transaction` → `lockForUpdate()` →
    **ロック後に投影評価** → 同一 transaction 内で `$next()` の順であることを確認。
    pgsql は READ COMMITTED のため、ロック取得後の再読み取りで先行 commit が見える =
    「passkey 2 件を同時に別々に削除」は 2 本目が reject される。
(e) `hasPassword()` (app/Models/User.php:110-117) と
    `SocialAccountService::register()` の phantom password 撤去 (前方修正のみ) を突き合わせ。
`tests/Feature/Auth/LoginMethodRetentionTest.php` / `LoginMethodInventoryTest.php` 実走 (green)。

**結果**: 現行 route 集合の範囲では抜け道なし。ただし 2 点の残存リスク:

- **Low-1 (phantom password の fail-open)**: legacy SSO ユーザーは
  `users.password` にランダムハッシュが残っており `hasPassword()` が true を返す。
  `LoginMethodInventory.php:47` はこれを `'password'` としてカウントするため、
  「本人が使えない手段」で guard が満たされうる。方向は fail-open。
  ただし SSO ユーザーは必ず `social_accounts` 行を持つため実害は
  「provider を config から外した後」に限られ、その場合も
  `/forgot-password` で復旧可能 (password が null でもリセットは可能)。**Low**。
- **Low-2 (TOTP 有効化が分類対象外)**: `PasskeyLoginPolicy` (L39) は TOTP confirmed で
  passkey を**ログイン手段から外す**。したがって将来 password 削除 / SSO 解除 route を
  足すと「passkey しか無いユーザーが `two-factor.confirm` を叩いた瞬間に手段 0」になる。
  `LoginMethodRemovalRouteTest` は `two-factor.disable` を免除登録しているが
  **`two-factor.confirm` (有効化) は候補 prefix (`user/confirmed-two-factor-authentication`) に
  入らず走査対象外**。現時点では passkey-only 状態が到達不能なため実害なし。**Low**。

### 1.7 認可 gate 自体の抜け道 (Codex Critical 3 件の後追い + 追加探索)

**確認した方法**: `devnotes/20260805-1319-todo-T103/impl-review-round-1.md` の Critical 3 件を
現行コードで再確認 → `tests/Support/AuthorizationMarkerScanner.php` L101-108 に
`->authorize` の直後 `(` チェックが入り、L114-117 で inline guard の **全** offset を返す
実装になっていることを確認 (3 件とも修正済み)。
その上で自分で以下を追加探索した:
- `controllerAuthorizationExemptions()` の 12 件を 1 件ずつ根拠と実装で突き合わせ → 妥当。
  特に `notifications.*` は `findOwnOrFail` による自 user 限定解決、
  `invitations.accept.store` は token bearer、`webhooks.ses` は SNS 署名 fail-closed を確認。
- `ItemController` の層順序 (`resolveOrganizationProject` → `resolveProjectItem` → `Gate::forUser`)
  を L59-110 で確認 → 全 3 メソッドで層 2 → 層 3 の順。
- `Gate::forUser($this->apiActor($request)->user)` を使い `Gate::authorize` を避けている点
  (dual guard で ApiKey が Policy に渡り 500 になる問題の回避) を確認。

**結果**: Codex の 3 件は解消済み。**ただし gate 自体に別の穴を 1 つ発見 → Low-3**
(vendor 所有 route の無条件スキップ)。

### 1.8 その他 (今サイクルの残り差分)

- **T105 EmailTrustPolicy**: `EmailTrustLevel::fromRaw()` が非文字列・未知文字列を
  `Unconfirmed` に倒す fail-closed を確認。`config/template.php` で `google` のみ
  `confirmed` 宣言、Microsoft (nOAuth) への注意書きあり。
  `SocialAccountService::register()` が `email_verified_at` を policy 経由に変えたことで
  「未検証 email での SSO 乗っ取り」経路が閉じている。
  `tests/Architecture/SocialProviderTrustPolicyTest.php` 実走 (green)。**問題なし**。
- **CSRF**: `bootstrap/app.php:175-178` の除外は `stripe/*` / `ses/*` のみ
  (いずれも署名検証あり)。passkey route はすべて web group 配下で
  実測 middleware に `PreventRequestForgery` が載っている (login / confirm / store / destroy)。
  フロントも `resources/js/lib/passkeys.ts:125` で `X-XSRF-TOKEN` を送っている。**問題なし**。
- **XSS**: 新規 Svelte (`PasskeySection.svelte` / `Login.svelte` / `ConfirmRecentAuth.svelte` /
  `RecentAuthModal.svelte`) に `{@html}` / `innerHTML` / `eval` は 0 件 (grep で確認)。
  passkey の `name` は user 入力だが Svelte の既定エスケープ経由。**問題なし** (関連 Low-5 あり)。
- **SQLi**: 新規コードの DB アクセスはすべて Eloquent の
  `whereKey` / `where('user_id', ...)` / `whereKeyNot` / relation 経由。
  raw SQL・文字列連結クエリは 0 件 (grep で確認)。**問題なし**。
- **機密情報**: passkey の credential 本体 (公開鍵 / signature counter) は
  `PasskeyListItemDto` / `PasskeyListItemResource` に載っていない (id / name / authenticator /
  lastUsedAt / createdAt のみ)。`PasskeyRegistrationResponse::withPasskey()` は
  意図的に何も保持せず応答本文に載せない。CLI (`packages/cli`) の差分も
  secret を log/stdout に出す箇所なし (grep で確認)。**問題なし**。
- **.env.example / 設定の整合**: 今サイクルの .env.example 追記は
  「passkey は専用 env を持たない」旨のコメントのみ。
  `vendor/laravel/fortify/src/FortifyServiceProvider.php:130-134` を読み、
  `relying_party_id` = APP_URL の host / `allowed_origins` = [APP_URL] /
  `user_handle_secret` = APP_KEY が既定であること、`config/fortify.php` に
  `passkeys` 上書きキーが無いことを確認 → **記述と実挙動は一致**。
  「APP_KEY ローテートで既存パスキーが全件無効」も正しい
  (`APP_PREVIOUS_KEYS` は encrypter 用で user handle には効かない)。**問題なし**。
- **Inertia 秘匿契約**: `InvitationAcceptanceController` の差分は SEO title のみで、
  無効招待の理由・組織名を開示しない既存契約は維持されている。**問題なし**。

### 1.9 テスト実走結果 (グローバルロック経由)

```
tests/Feature/Auth    : 226 passed / 931 assertions
tests/Feature/Api     :  65 passed / 265 assertions
tests/Architecture    : 253 passed / 1555 assertions
```
`ControllerAuthorizationGateTest` / `NestedRouteIdorDefenseTest` /
`ProjectRouteCurrentOrgGuardTest` / `PasskeyRouteProtectionTest` /
`LoginMethodRemovalRouteTest` / `PasskeyPackageContractTest` /
`SocialProviderTrustPolicyTest` を含め全 green。
**ただし下記 High-1 / High-2 はいずれも既存テストの検査範囲外**であり、
green であることは安全性の証明にならない。

---

## 2. 発見事項

### High-1 [High] REST API v1: ability 403 と binding 404 の差分が **cross-org の project 存在オラクル**になる

- `routes/api.php:69-70` (read group) / `routes/api.php:86-87` (write group)
- `app/Http/Middleware/RequireApiKeyAbility.php:67-72, 89-96`
- `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php:70, 126` (この順序を機械固定している)

**内容**

実行時 middleware 順序 (実測) は

```
2. SubstituteBindings           ← 不在 project id はここで 404 (ModelNotFound)
3. ResolveApiActor
4. RequireApiKeyAbility:write   ← ability 不足はここで 403 insufficient_ability
5. EnsureProjectBelongsToApiOrganization  ← cross-org はここで 404
```

`read` だけを持つ API キー (`StoreApiKeyRequest` は `abilities` を自由選択させるため
read-only キーは正規の構成) で `POST /api/v1/projects/{id}/items` を叩くと:

| {id} | 応答 |
|---|---|
| **他組織に実在**する project | **403** `insufficient_ability` |
| **どこにも存在しない** id | **404** `not_found` |

= 「その project id がシステム全体で実在するか」が 1 bit 漏れる。
write-only キーで GET を叩けば同じ差分が read 側にも出る。

これは `docs/app-integration-guide.md` §7 不変条件 2/3/9 と
`AGENTS.md` セキュリティ不変条件 2/3 が明示的に閉じようとしている
「cross-org の実在と不在を区別させない」に**正面から抵触**する。
今サイクルは FormRequest 由来の 422/404 差分だけを塞ぎ、
**ability 由来の 403/404 差分を見落としている**。

さらに悪いことに、`ProjectRouteCurrentOrgGuardTest` は
`api-key.ability:* < api.project-in-org` を **意図的に機械固定**しており
(理由: 「エラー契約 insufficient_ability が route ごとにぶれる」)、
順序を直そうとすると Architecture テストが落ちる = 自然治癒しない構造になっている。

**影響**: 有効なトークンを 1 つ持つ actor による全テナント横断の project ID 列挙。
データ内容の漏えいは無いため Critical ではないが、リポジトリ自身が
非交渉と宣言している不変条件の違反であるため High。

**推奨対処 (TODO 登録を推奨)**
`api.project-in-org` を `api-key.ability:*` **より前**へ移し、
`ProjectRouteCurrentOrgGuardTest` の順序契約とその根拠コメントを反転させる。
`resolve.api-actor < api.project-in-org < api-key.ability < idempotent` とすれば
- 不在 → 404 (binding)
- cross-org 実在 → 404 (guard)
- 自組織 + ability 不足 → 403 insufficient_ability
となり、エラー契約の一貫性 (自組織リソースに対する insufficient_ability) も保たれる。
Feature テストに「read-only キーで write route を叩いたとき、cross-org 実在 project と
不在 id が同一応答であること」を追加すること。

### High-2 [High / 既存] `trustProxies(at: '*')` により `$request->ip()` が攻撃者制御 → IP ベース throttle が全面的に無効

- `bootstrap/app.php:53-60`
- `app/Providers/FortifyServiceProvider.php:169-175 (login)`, `:179-182 (two-factor)`, `:188-194 (passkeys)`
- `app/Providers/AppServiceProvider.php:270, 293, 310, 324`

**確認した方法 (実測)**: `TrustProxies` middleware を実際に通してから `ip()` を評価した。

```
XFF = "1.2.3.4, 10.0.0.5" → ip() = '1.2.3.4'
XFF = "9.9.9.9"           → ip() = '9.9.9.9'
XFF なし                   → ip() = '10.0.0.5'
```

`at: '*'` は全アドレスを trusted proxy 扱いにするため、Symfony の `getClientIps()` は
XFF の **最左** (= クライアントが自由に書ける値) を返す。ALB / CloudFront は XFF を
上書きせず append するため、本番構成でも最左は攻撃者制御下にある。

**今サイクルとの関係**: T106 が新設した `passkeys` limiter は
**未認証経路 (`GET /passkeys/login/options`, `POST /passkeys/login`) を IP 単位でしか
絞れない**。したがって「10/min で保護されている」という自己申告は成立せず、
`X-Forwarded-For` を毎回変えるだけで無制限に
(a) `random_bytes(32)` + session 書き込みを誘発でき (未認証リソース枯渇)、
(b) passkey assertion を無制限に試行できる。
同じ理由で既存の `login` (5/min) も総当り防御として機能していない。

**推奨対処 (TODO 登録を推奨)**
`trustProxies` を実際の LB CIDR (env 由来の allowlist) に絞る。
即時に絞れないなら、少なくとも未認証 limiter のキーを
`$request->server('REMOTE_ADDR')` ベース (または XFF の右から N 番目) に切り替える。
`config/trusted_hosts.php` と同じ「env allowlist + production fail-fast」の作法で揃えられる。

### Medium-1 [Medium] passkey の登録 / 削除が security audit trail に一切残らない

- `app/Enums/SecurityEventType.php` (case が存在しない)
- `app/Listeners/Auth/ClearRecentAuthOnPasskeyChange.php:41-49` (session clear のみ)

パスワード変更 (今サイクルで `UpdateUserPassword.php:58` に追加)、2FA 有効/無効、
SSO 連携、API キー発行/失効はすべて `SecurityEventRecorder` で記録されるのに、
**単独でログインできる強い資格である passkey の増減だけが記録されない**。
セッション乗っ取り後に攻撃者が passkey を登録して永続化した場合、
パスワード変更 (`logoutOtherDevices`) では追い出せず、かつ痕跡も残らない。

**推奨**: `SecurityEventType` に `PasskeyRegistered` / `PasskeyDeleted` を追加し、
`ClearRecentAuthOnPasskeyChange` (または専用 listener) から記録する。
可能なら本人へのメール通知も (2FA 変更と同格の扱い)。

### Medium-2 [Medium] `passkey.destroy` に throttle が無く、User 行ロックを無制限に取れる

- `vendor/laravel/fortify/routes/routes.php:217-219` (`$passkeyMiddleware` に `$throttle` を含めない)
- `app/Http/Middleware/EnsureLoginMethodRemains.php:65-78`
- `app/Providers/PasskeyServiceProvider.php:112-131` (throttle の後付けをしていない)

実測 middleware ダンプで `passkey.destroy` にのみ `ThrottleRequests` が無いことを確認した。
`EnsureLoginMethodRemains` は毎リクエスト `DB::transaction` + `User` 行の
`lockForUpdate()` を取るため、認証済みユーザーが自分の User 行に対して
無制限にロック競合を起こせる (自分自身への軽度の DoS / DB コネクション圧迫)。
存在オラクルにはならない (binder が先に 404) が、
他の passkey route が 10/min で絞られているのに削除だけ素通しなのは非対称。

**推奨**: `PasskeyServiceProvider::attachMiddlewareToPasskeyRoutes()` で
`passkey.destroy` にも `throttle:passkeys` を後付けし、
`PasskeyRouteProtectionTest` に固定する。

### Low-1 [Low] phantom password が `LoginMethodInventory` で「使える手段」として数えられる

`app/Services/Auth/LoginMethodInventory.php:47` / `app/Models/User.php:110-117`。
詳細は §1.6。T105 が前方修正のみ (D13) なので設計どおりだが、
guard の判定が fail-open 方向である点は記録しておく価値がある。

### Low-2 [Low] `two-factor.confirm` (TOTP 有効化) が `LoginMethodRemovalRouteTest` の走査対象外

`tests/Architecture/LoginMethodRemovalRouteTest.php:60` の prefix 集合に
`user/confirmed-two-factor-authentication` が無い。詳細は §1.6。
将来 password 削除 / SSO 解除 route を足す前に、
「TOTP 有効化も passkey をログイン手段から外す操作である」ことを
分類対象に含めておくべき。

### Low-3 [Low] 認可 gate が vendor 所有の変更系 route を無条件スキップする

`tests/Architecture/ControllerAuthorizationGateTest.php:198-202` は
ハンドラが `vendor/` 配下なら `status: 'vendor'` で候補から外す。
その結果、今サイクルで増えた 6 本の passkey 変更系 route
(`passkey.login` / `passkey.confirm` / `passkey.store` / `passkey.destroy` ほか) は
**exemption inventory に 1 行も登録されないまま**コードベースに入った。
app route には deny-by-default、vendor route には allow-by-default という非対称であり、
「vendor パッケージを新規導入する」という最もレビューが必要な変更で gate が沈黙する。

**推奨**: vendor route も列挙し、パッケージ単位で
「なぜパッケージ側の防御で足りるか」を inventory 登録させる
(今回なら「binder を自前に差し替えて自己スコープ化した」等が根拠になる)。

### Low-4 [Low / 受容可] 404 の 2 経路にタイミング差分がある

不在 project は `SubstituteBindings` (index 2) で即 404 になるのに対し、
cross-org 実在 project は `ResolveApiActor` (membership 再検証 / OAuth session 検証) と
ability 判定を通過してから `api.project-in-org` の 1 クエリを経て 404 になる。
body / ヘッダは完全一致 (§1.1) だが、処理量には数クエリ分の差がある。
統計的測定が必要で throttle も効くため実効性は低い。**受容可**と判断するが、
High-1 の修正時に順序を変えると差分の位置も変わるので併せて確認すること。

### Low-5 [Low / 既存・サイクル外] `svelte/no-at-html-tags` が有効化されていない

`eslint.config.js` に `svelte/no-at-html-tags` の宣言が無く (grep で確認)、
今サイクルの `noInlineConfig: true` 導入で
`resources/js/pages/Settings/Security.svelte:484` の
`<!-- eslint-disable-next-line svelte/no-at-html-tags -->` が削除された。
これは「元々効いていなかった抑制コメントの除去」であって新規の劣化ではない
(当該 `{@html qrSvg}` は Fortify がサーバ生成する QR SVG で untrusted ではない)。
ただし結果として `{@html}` に対する機械的な gate は現在 1 つも無い。
将来 untrusted 文字列が `{@html}` に入る変更を止める仕組みが無い点だけ記録する。

---

## 3. 「問題なし」と結論した項目の根拠一覧

| 項目 | 確認方法 | 結論 |
|---|---|---|
| 404 body / ヘッダの同一性 | `ApiExceptionRenderer` L72-74, L148-162 の読解 + Feature テスト実走 | 差分なし |
| passkey 4 ケース同一 404 | binder の読解 (解決クエリに所有者スコープ内包) + vendor 403 の到達不能性を実測順序で確認 | 差分なし |
| PasskeyLoginPolicy fail-closed | vendor の呼出位置 (login 前) + `twoFactorStatus()` の定義 + 非 User の false | 拒否される |
| password_confirmed_at 非汚染 | vendor の `passwordConfirmed()` → app の `forget()` の実行順 (session 保存前) | 汚染なし |
| TOCTOU (同時削除) | `lockForUpdate` 後に投影評価 → 同一 tx で `$next()`、pgsql READ COMMITTED | 破れない |
| CSRF | 除外は `stripe/*` `ses/*` のみ、passkey route の実測 middleware に `PreventRequestForgery` | 保護あり |
| SQLi | 新規コードの全 DB アクセスが Eloquent (raw / 連結 0 件) | なし |
| XSS | 新規 Svelte 4 本に `{@html}` / `innerHTML` / `eval` 0 件 | なし |
| credential 非露出 | DTO / Resource / 応答 contract の読解 (公開鍵・counter を載せない) | 漏えいなし |
| tenant キー不信 | `StoreItemRequest` / `UpdateItemRequest` 再利用 + cross-org + `project_id` payload の 404 テスト実走 | 維持 |
| UserInput 型 (prompt) | 今サイクルの差分に LLM 呼び出し / prompt 生成の追加なし (差分全走査で確認) | 対象外 |
| .env.example と実設定 | Fortify の passkeys 既定値 (vendor L130-134) と `config/fortify.php` の突き合わせ | 一致 |
| EmailTrustPolicy | `fromRaw()` の fail-closed + config 宣言 + Architecture テスト実走 | 問題なし |
| 招待の理由非開示 | `InvitationAcceptanceController` 差分は SEO title のみ | 維持 |
| CLI の secret 取り扱い | `packages/cli` 差分の grep (log / stdout に secret なし) | 問題なし |

---

## 4. 推奨アクション (優先度順)

1. **[即座に TODO 登録] High-1**: `api.project-in-org` を `api-key.ability` より前へ。
   `ProjectRouteCurrentOrgGuardTest` の順序契約反転 + Feature テスト追加。
2. **[即座に TODO 登録] High-2**: `trustProxies` の CIDR allowlist 化
   (または未認証 limiter のキーを REMOTE_ADDR ベースへ)。
3. **[TODO 登録] Medium-1**: passkey 増減の `SecurityAuditEvent` 記録 (+ 本人通知)。
4. **[TODO 登録] Medium-2**: `passkey.destroy` への `throttle:passkeys` 後付け。
5. **[検討]** Low-2 / Low-3 は次に認証系 route または vendor パッケージを足すときに
   同時対応すれば足りる。Low-1 / Low-4 / Low-5 は記録のみで可。
