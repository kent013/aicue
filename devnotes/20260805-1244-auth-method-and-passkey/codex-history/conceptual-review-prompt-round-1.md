【アプリの使命 (North Star) — AGENTS.md より】
## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


【禁止事項 — AGENTS.md より】
## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


【セキュリティ不変条件 — AGENTS.md より】
## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項・セキュリティ不変条件に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 13 + Fortify 1.37 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか
8. TODO 分割判断の妥当性: 分割単位・依存順序・ロールバック単位の主張は成立しているか

【特に厳しく見てほしい論点】
- 「passkey route の auto-discovery 露出は無い」という結論の導出が正しいか。見落としている経路
  (config cache / route cache / package discovery manifest / テスト環境と本番でのロード順差) は無いか
- 「template t0 の PasskeyServiceProvider を移植」という当初指示に対し、
  「Fortify ネイティブの Features::passkeys() を有効化し、app 側 Provider はアダプタに留める」へ
  読み替えた判断は妥当か。台帳 boundary の記述と矛盾しないか
- SSO 登録ユーザーの password を null にする変更 (Str::password(32) の撤去) の後方互換リスク。
  既存レコードへの移行が必要か。必要ならそれは設計に書かれているか
- EnsureLoginMethodRemains と既存 canSatisfy を「別概念」として分離する判断の妥当性
- 2 TODO 分割 (T-α 独立 / T-β 統合) の判断。特に案 X/Y/Z を却下した論拠が成立しているか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 参考: 現行コードの実測抜粋 (レビューの前提事実)

### composer.json (抜粋)
```json
"require": { "laravel/fortify": "^1.37", "laravel/framework": "^13.8", ... },
"extra": { "laravel": { "dont-discover": ["laravel/passport"] } }
```
`laravel/passkeys` は composer.json に無く、composer.lock 内で fortify v1.37.2 の
require として v0.2.1 が入っている。

### vendor/laravel/fortify/src/FortifyServiceProvider.php
```php
protected function configurePasskeys()
{
    LaravelPasskeys::ignoreRoutes();          // ← 無条件
    if ($model = $this->passkeyUserModel()) { LaravelPasskeys::useUserModel($model); }
    config([
        'passkeys.relying_party_id' => config('fortify.passkeys.relying_party_id', parse_url(config('app.url'), PHP_URL_HOST)),
        'passkeys.management_middleware' => config('fortify-options.passkeys.confirmPassword', true) ? ['password.confirm'] : [],
        'passkeys.throttle' => $this->passkeyThrottleMiddleware(),
        ...
    ]);
}
protected function passkeyThrottleMiddleware()
{
    $limiter = config('fortify.limiters.passkeys');
    return $limiter ? 'throttle:'.$limiter : null;
}
```

### vendor/laravel/fortify/routes/routes.php (抜粋)
```php
if (Features::enabled(Features::passkeys())) {
    ... Route::get('/passkeys/login/options')->middleware(['guest:web', ...$throttle])->name('passkey.login-options');
        Route::post('/passkeys/login')  ->middleware($passkeyGuestMiddleware)->name('passkey.login');
        Route::get('/passkeys/confirm/options') / Route::post('/passkeys/confirm')
        Route::get('/user/passkeys/options') / Route::post('/user/passkeys')
        Route::delete('/user/passkeys/{passkey}')->middleware($passkeyMiddleware)->name('passkey.destroy');
}
```

### vendor/laravel/passkeys/src/Http/Controllers/PasskeyRegistrationController.php::destroy
```php
public function destroy(Passkey $passkey, DeletePasskey $deletePasskey): PasskeyDeletedResponse
{
    $user = Auth::guard(Config::string('passkeys.guard'))->user() ?? throw new AuthenticationException;
    if (! $user instanceof PasskeyUser) { throw new RuntimeException(...); }
    abort_unless($passkey->user_id === $user->getKey(), 403);   // ← 403 で存在を漏らす
    $deletePasskey($user, $passkey);
    return app(PasskeyDeletedResponse::class);
}
```
route binding は `PasskeysServiceProvider::registerRouteBindings()` が
`app($model)->resolveRouteBinding($value)` でグローバル解決している (親スコープ無し)。

### vendor/laravel/passkeys/src/Http/Controllers/PasskeyLoginController.php::store
```php
$passkey = $verify($request->credential(), $request->verificationOptions());
$guard = Auth::guard(Config::string('passkeys.guard'));
if (! Passkeys::allowsLogin($request, $passkey)) { throw InvalidPasskeyException::make(...); }
$guard->login($passkey->user, $request->remember());   // ← Fortify の TOTP チャレンジを通らない
$request->session()->regenerate();
```

### app/Services/Auth/SocialAccountService.php (L45-72)
```php
public function register(string $provider, SocialiteUser $socialiteUser): User
{
    return DB::transaction(function () use ($provider, $socialiteUser): User {
        $email = $socialiteUser->getEmail();
        if (! is_string($email) || $email === '') { throw new \RuntimeException('SSO プロバイダから email が取得できませんでした'); }
        $user = (new User([
            'name' => $socialiteUser->getName() ?? $email,
            'email' => $email,
            'password' => Str::password(32),          // ← L57
        ]))->forceFill([
            'terms_accepted_at' => now(),
            'consent_version' => config()->string('legal.consent_version'),
            'email_verified_at' => now(),             // ← L62 無条件付与
        ]);
        $user->save();
        $this->link($provider, $socialiteUser, $user);
        $this->provisioning->provisionPersonalOrganization($user);
        return $user;
    });
}
```

### database/migrations/0001_01_01_000000_create_users_table.php
```php
// SSO-only ユーザー (UserFactory::ssoOnly() / password 未設定) を許容するため
// nullable。password 経路の可否判定は User::hasPassword() が fail-closed で行う
$table->string('password')->nullable();
```

### app/Models/User.php
```php
class User extends Authenticatable implements CipherSweetEncrypted, LaratrustUser, MustVerifyEmail, OAuthenticatable
// traits: HasApiTokens, HasFactory, HasRolesAndPermissions, Notifiable, TwoFactorAuthenticatable, UsesCipherSweet
// $fillable = ['name','email','password']; casts: password => 'hashed'
// CipherSweet: email (blind index email_index) / name (blind index name_index) を暗号化

/**
 * password が設定されているか (recent-auth の password satisfier 可否)。
 * テンプレート標準では SSO 登録ユーザーにもランダム password が設定されるため常に true
 * だが、password 未設定 (null / 空) を許すアプリはこの判定で SSO-only ユーザーを
 * password 経路から fail-closed で除外できる。
 */
public function hasPassword(): bool
{
    $password = $this->getAttribute('password');
    return is_string($password) && $password !== '';
}
```

### app/Http/Controllers/Auth/ConfirmRecentAuthController.php::buildStatus
```php
$passwordSet = $user->hasPassword();
$providers = [];
foreach ($user->socialAccounts()->pluck('provider') as $provider) {
    $capability = $this->capabilityFor($provider);        // config template.social_providers.{p}.capability
    if (! $capability->isStepUpSatisfier()) { continue; } // IdentityOnly は除外 (fail-closed)
    $providers[] = new RecentAuthProviderDto(...);
}
$canSatisfy = $passwordSet || $providers !== [];
```

### app/Security/RecentAuthState.php
```php
final class RecentAuthState
{
    public function confirm(string $method, ?string $provider = null, ?int $verifiedAt = null): void { /* session put + migrate(true) */ }
    /** 認証要素変更 (password/email/2FA/social link·unlink 等) 後に鮮度を失効させる。 */
    public function clear(): void { session()->forget([...]); }   // ← production 呼び出し元 0 件
}
```
`confirm()` の呼び出し元は 3 箇所のみ:
ConfirmRecentAuthController(password) / SocialAuthController::completeStepUp(sso) /
Listeners/Auth/StampRecentAuthOnLogin(login)。

### app/Providers/FortifyServiceProvider.php
```php
private const RECENT_AUTH_ROUTE_NAMES = ['two-factor.recovery-codes','two-factor.regenerate-recovery-codes','two-factor.disable'];
private const CONDITIONAL_RECENT_AUTH_ROUTES = ['user-profile-information.update' => 'recent-auth.on-email-change'];
private function attachRecentAuthToSensitiveRoutes(): void
{
    $this->app->booted(static function (Application $app): void {
        $routes = $app->make(Router::class)->getRoutes();
        $routes->refreshNameLookups();
        foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) { self::appendMiddlewareIfMissing($routes, $name, 'recent-auth'); }
        foreach (self::CONDITIONAL_RECENT_AUTH_ROUTES as $name => $alias) { self::appendMiddlewareIfMissing($routes, $name, $alias); }
    });
}
// RateLimiter::for('login', ...) と RateLimiter::for('two-factor', ...) のみ定義。'passkeys' は無い
```

### config/fortify.php (抜粋)
```php
'limiters' => ['login' => 'login', 'two-factor' => 'two-factor'],   // passkeys 無し
'features' => [
    Features::registration(), Features::resetPasswords(), Features::emailVerification(),
    Features::updateProfileInformation(), Features::updatePasswords(),
    Features::twoFactorAuthentication([
        'confirm' => true,
        // Fortify 標準の password.confirm (3h・パスワード限定) は無効化し、step-up を
        // generic recent-auth (15 分窓・パスワード or 再SSO) へ統一する。SSO-only ユーザーを
        // password 固定の確認画面で詰ませないため。
        'confirmPassword' => false,
    ]),
],
```

### config/template.php
```php
'social_providers' => [
    'google' => ['label' => 'Google', 'capability' => 'fresh_auth_prompt_only'],
],
```

### app/Providers/AppServiceProvider.php
```php
// register(): EventServiceProvider::disableEventDiscovery();   ← 自動 discovery は無効
// boot():     Event::listen(Login::class, StampRecentAuthOnLogin::class);  等 明示配線のみ
//             Model::preventSilentlyDiscardingAttributes((bool) config('app.mass_assignment_strict'));
```

### 2FA 強制
`RequireTwoFactorForEnforcedOrganizations` は bootstrap/app.php:92 の **global web middleware**
(ログイン後のゲート)。`TwoFactorEnforcementAllowlistTest` が
`ALLOWED_ROUTE_NAMES` の鮮度 (実在する named route であること + 理由文字列が非空) を固定している。

### 既存 Architecture gate の作法 (模範例)
```php
// tests/Architecture/ManageRouteAuthGuardTest.php — 構造的 deny-by-default
foreach (Route::getRoutes() as $route) {
    if (! str_starts_with($route->uri(), 'manage/')) { continue; }
    foreach (['auth','verified'] as $required) {
        if (! in_array($required, $route->gatherMiddleware(), true)) { $violations[] = ...; }
    }
    $checked++;
}
expect($violations)->toBe([]);
expect($checked)->toBeGreaterThan(0);   // 空振り drift も fail させる
```
```php
// tests/Architecture/NestedRouteIdorDefenseTest.php — inventory + exempt allowlist
// 「2 個以上の route パラメータを取る named route を全て候補とし、
//   inventory (防御方式付き) か prefixExemptAllowlist (理由付き) のどちらかに必ず分類させる」
```

### c2c 台帳 (ledger_revision f7175a1d… のスナップショット)

**auth-login-method-retention** (status: active / aicue: pending)
- boundary: `含む: EnsureLoginMethodRemains middleware とその適用 (パスワード削除・SSO 連携解除・passkey 削除経路)。含まない: passkey destroy への適用詳細 (auth-passkey-hardening 施策 3)`
- aicue note: `実査でファイル不在。password+Google の 2 手段があるため追従対象`

**auth-sso-social** (status: active / aicue: update_pending, version pre-t0)
- boundary: `含む: SocialAuthController, SocialAccountService/Linker, MicrosoftEmailTrustPolicy (Confirmed/Unconfirmed キルスイッチ), ProviderCapability (fresh_auth_prompt_only/identity_only, 未宣言 fail-closed), Socialite fake。含まない: エンタープライズ OIDC, admin SSO, recent-auth 再SSO satisfier 本体`
- agenda (**未解決**): `aigenba の id_token 検証 (nOAuth 対策の署名検証 + auth_time) をテンプレの trust policy 形へ還流するかの裁定`

**auth-passkey** (status: active / aicue: pending / canonical v1=aigenba)
- boundary: `含む: PasskeyServiceProvider (vendor route 加工・binder 差し替え・Response contract 上書き), resources/js/lib/passkeys.ts, ClearRecentAuthOnPasskeyChange, PASSKEYS_* env。含まない: aigenba:T1108 hardening 4 施策 (auth-passkey-hardening), recent-auth satisfier 本体 (auth-recent-auth)`
- gates: `tests/Architecture/PasskeyPackageContractTest.php`, `tests/Architecture/PasskeyRouteProtectionTest.php`, `tests/js/architecture/passkeys-import-isolation.test.ts`, `tests/js/architecture/recent-auth-passkey-wiring-gate.test.ts`
- aicue note: `PasskeyServiceProvider 不在 (実査)、composer に laravel/passkeys なし (実査)` ← **この実査は fortify 1.37 移行前のもので、現在は推移依存で v0.2.1 が入っている**
- **agenda_resolved (2026-08-04 裁定 A)**:
  `R6: A に統一 (パスキー登録・削除時に recent-auth を失効させる)。ClearRecentAuthOnPasskeyChange 相当を導入`
  directive: `強化オプション (新規登録直後のパスキーを即 re-step-up の satisfier に使えなくする) はキュレーター判断で見送り。理由: 攻撃者は操作順序を選べるため限界効用が低い一方、satisfier 集合に時間依存の除外条件を持ち込み、初回パスキー登録直後の正規ユーザー導線とロックアウト隅ケースを増やす。再検討条件: パスキーが 2FA 準拠判定に算入される時、または放置端末起点の実被害が観測された時`

---

## 概念設計

# 概念設計: auth-method-and-passkey

対象 c2c 台帳 (ledger_revision `f7175a1d…` 時点のスナップショット):

| id | aicue status | canonical |
|----|--------------|-----------|
| `auth-login-method-retention` | `pending` | t0 |
| `auth-sso-social` | `update_pending` (version `pre-t0`) | t0 |
| `auth-passkey` | `pending` | v1 (aigenba) |

---

## 0. 着手前の露出リスク確認 (結論: **露出なし**)

指示された事前確認を実行した。結果は「**passkey route は 1 本も生えていない**」。

### 実測

```
$ grep -n "laravel/passkeys" composer.lock   → v0.2.1 が存在 (composer.json の require には無い)
$ php artisan route:list --json | (passkey フィルタ)  → 198 routes 中 0 件
```

### なぜ生えていないか (経路を最後まで追った)

1. `laravel/passkeys` は **`laravel/fortify` v1.37.2 の推移依存** (`composer.lock` の fortify
   require に `"laravel/passkeys": "^0.2.0"`)。`composer.json` の直接 require ではない。
2. `Laravel\Passkeys\PasskeysServiceProvider` は `extra.laravel.providers` 宣言を持ち、
   `composer.json` の `extra.laravel.dont-discover` には **入っていない** ため
   **auto-discovery されている**。
3. しかし `Laravel\Fortify\FortifyServiceProvider::configurePasskeys()` が
   **無条件で `LaravelPasskeys::ignoreRoutes()` を呼ぶ** (vendor/laravel/fortify/src/FortifyServiceProvider.php:123)。
   `Passkeys::shouldRegisterRoutes()` が false になるため、パッケージ側 routes は登録されない。
4. Fortify 1.37 は **passkey route を自前の routes ファイルで提供する**
   (vendor/laravel/fortify/routes/routes.php:180)。ただし
   `if (Features::enabled(Features::passkeys()))` でゲートされており、
   本アプリの `config/fortify.php` の `features` に `Features::passkeys()` は **無い**。

⇒ 「passkeys テーブル不在のまま 500」の露出は**現時点で存在しない**。

### したがって `dont-discover` 追加は行わない (積極的に避ける)

`laravel/passkeys` を `dont-discover` に入れるのは**有害**である:

- Fortify の passkey route は `Laravel\Passkeys\Http\Controllers\*` を直接参照する
- `PasskeysServiceProvider::register()` が 4 つの Response contract の binding を張る
- `registerRouteBindings()` が `passkey` route model binding を張る

discovery を切ると Fortify ネイティブの passkey 機能ごと壊れる。
台帳 `auth-passkey` が求めているのは「passkey を導入する」ことであり、封じることではない。

### ただし「露出なし」は**脆い不変条件**なので gate は張る

現状の安全は「Fortify が boot して `ignoreRoutes()` を呼ぶ」という**ロード順序依存の副作用**に
乗っているだけである。Fortify の将来版がこの呼び出しをやめる / features 配列を触った PR で
意図せず有効化される、のどちらでも黙って露出面が変わる。
台帳 `auth-passkey` の `gates` が指名する canonical gate 2 本を新設して機械固定する:

- `tests/Architecture/PasskeyPackageContractTest.php`
  — パッケージ側 route 非登録・Response contract binding・vendor 契約の pin
- `tests/Architecture/PasskeyRouteProtectionTest.php`
  — passkey route 各本の middleware スタックを列挙固定 (deny-by-default)

指示にあった `tests/Feature/Auth/PasskeyRouteExposureTest.php` は、
**露出が無かったため「露出を止める応急 pin」としては不要**であり、
上記 canonical gate 2 本に役割を統合する (家系で名前が揃うほうが台帳追従として正しい)。

---

## 1. 背景・課題

### 1-1. 台帳が指す 3 件は「1 つの安全不変条件」の構成部品

3 件はいずれも **「ユーザーが自分のアカウントから締め出されない / 他人に成り代わられない」** という
同じ不変条件の別側面である。

- `auth-login-method-retention`: ログイン手段を**全部消して自分で締め出す**事故の防止
- `auth-sso-social`: IdP が主張する email を**無条件に信頼して他人に成り代わられる**事故の防止
- `auth-passkey`: パスワードに依存しないログイン手段の**追加**

### 1-2. 発見した実害 (設計の起点になる 4 つ)

調査で以下を確認した。いずれも「台帳追従」以前に aicue 側に既に存在する欠陥である。

**(A) `hasPassword()` が SSO 登録ユーザーに対して嘘をつく**

`app/Services/Auth/SocialAccountService.php:57` が SSO 登録時に `Str::password(32)` の
ハッシュを書き込む。一方 `database/migrations/0001_01_01_000000_create_users_table.php:22-24`
は **`password` を nullable にしており**、コメントは明示的に
「SSO-only ユーザー (`UserFactory::ssoOnly()` / password 未設定) を許容するため nullable。
password 経路の可否判定は `User::hasPassword()` が fail-closed で行う」と書いている。
`User::hasPassword()` の docblock も
「テンプレート標準では SSO 登録ユーザーにもランダム password が設定されるため常に true だが、
password 未設定 (null / 空) を許すアプリはこの判定で SSO-only ユーザーを password 経路から
fail-closed で除外できる」と、**逸脱を選ぶ余地を明示的に残している**。

つまり **スキーマ・Factory・判定ヘルパはすべて「password は無くてよい」前提で作られており、
`SocialAccountService::register()` だけがその前提を裏切っている**。

現在の実害: SSO で登録したユーザーは `/recent-auth/status` が `passwordSet: true` を返すため、
`RecentAuthModal` / `ConfirmRecentAuth.svelte` が**入力しても絶対に通らないパスワード欄**を提示する。
`RecentAuthPasswordRecoveryTest` が守っている「手段が無いユーザーの回復導線」も、
このユーザーには `canSatisfy: true` に見えるので出ない。

そして本題として、**`EnsureLoginMethodRemains` が `hasPassword()` で数えると常に真になり
guard が形骸化する** (指示書の指摘どおり)。

**(B) vendor の passkey 削除は「他人の passkey の存在」を 403 で漏らす**

`vendor/laravel/passkeys/src/Http/Controllers/PasskeyRegistrationController.php::destroy()`:

```php
abort_unless($passkey->user_id === $user->getKey(), 403);
```

route model binding (`PasskeysServiceProvider::registerRouteBindings()`) は
**グローバルに id で解決する**ため、他人の passkey id を投げると
「存在する→403」「存在しない→404」で**識別できてしまう**。
AGENTS.md セキュリティ不変条件 2「不整合は**認可より前に 404**」(403 で存在を漏らさない) に反する。

台帳 `auth-passkey` の boundary が canonical 資産として挙げる
「PasskeyServiceProvider (vendor route 加工・**binder 差し替え**・Response contract 上書き)」の
**binder 差し替えはまさにこれの是正**である。

**(C) passkey endpoint は現状のまま有効化すると無制限になる**

Fortify の passkey route の throttle は `config('fortify.limiters.passkeys')` から取る。
本アプリの `config/fortify.php` の `limiters` は `login` / `two-factor` のみで
`passkeys` が無く、`FortifyServiceProvider::passkeyThrottleMiddleware()` は `null` を返す。
⇒ **`GET /passkeys/login/options` (guest, 未認証) が無制限**になる。
毎回 `random_bytes(32)` + session 書き込みが走る未認証 endpoint を絞りなしで開けてはいけない。

**(D) `RecentAuthState::clear()` は本番呼び出し元ゼロの死んだ API**

docblock は「認証要素変更 (password/email/2FA/social link·unlink 等) 後に鮮度を失効させる」と
宣言しているが、**production コードからの呼び出しは 1 件も無い**。
2026-08-04 裁定 A の `ClearRecentAuthOnPasskeyChange` が**この API の最初の実利用者**になる。

### 1-3. 台帳スナップショットの陳腐化 1 件 (報告事項)

台帳 `auth-passkey` の aicue note は
「composer に laravel/passkeys なし (実査)」と記録しているが、
これは **fortify 1.37 系への更新前の実査**である。現在は推移依存として
`laravel/passkeys v0.2.1` が `composer.lock` に入っている。
本設計はこの実測を前提にする (= パッケージ導入コストは既に払い済み)。

---

## 2. 改善アイデア

### 施策 1. ログイン手段の「実在」を単一の源で定義し、除去経路に関門を張る

**まず「実際にログインに使える手段」を型で定義する。**
`App\Services\Auth\LoginMethodInventory` (仮) が `User` から
「今この瞬間、ログイン画面から本人がアカウントに入れる手段」の集合を返す:

- `password`: `User::hasPassword()` (= raw attribute が非空文字列)
- `social:{provider}`: `social_accounts` の行 (**capability に依らず全件**)
- `passkey`: `passkeys` の行が 1 件以上 (施策 3 で追加)

**既存の `canSatisfy` と統合しない。** `ConfirmRecentAuthController::buildStatus()` の
`canSatisfy = $passwordSet || $providers !== []` は
「**step-up 再認証を成立させられるか**」であり、`ProviderCapability::isStepUpSatisfier()` で
provider を絞り込む。一方ログイン手段は capability に関係なく数える
(`identity_only` の provider でもログインはできる)。
AGENTS.md 思考原則 4「別物の概念を『似ているから』で統合しない」に従い、両者は別クラスに保つ。

**(A) の是正を前提条件として同梱する。** `SocialAccountService::register()` から
`Str::password(32)` を外し、SSO 登録ユーザーの `password` を **null のまま**にする。
これによって `hasPassword()` が初めて意味を持ち、guard が形骸化しない。
これは `docs/template-divergence.md` に登録する**意図的なテンプレート逸脱**とする
(`User::hasPassword()` の docblock が明示的に許容している逸脱)。

**関門**: `app/Http/Middleware/EnsureLoginMethodRemains.php`。
「この操作が成功したら手段が 0 になる」ならブロックする。
route パラメータから除去対象を特定し、残存数を数える。

**構造 gate**: `tests/Architecture/LoginMethodRemovalRouteTest.php` を
`NestedRouteIdorDefenseTest` と同じ **inventory + exempt allowlist の deny-by-default** で新設。
候補 route を構造的に列挙し、
「guard 必須 (`ensure-login-method` middleware を持つ)」か
「免除 (理由文字列必須)」のどちらかに**必ず分類させる**。分類漏れは fail。

免除の代表例と理由を最初から登録しておく:

- `settings.account.destroy` — アカウント自体を消す操作。手段が 0 になるのは**目的**であり関門を通さない
- `two-factor.disable` — 第二要素であってログイン手段ではない
- `user-password.update` — 変更であって除去ではない (`current_password` 必須で null 化不能)

### 施策 2. SSO の email 信頼を差し替え可能な policy にする

`SocialAccountService::register()` の `email_verified_at => now()` 無条件付与
(SocialAccountService.php:62) を policy 経由に通す。

- `App\Services\Auth\EmailTrustPolicy` interface (`trustsEmail(SocialiteUser): bool`)
- `ConfirmedEmailTrustPolicy` (true) / `UnconfirmedEmailTrustPolicy` (false)
- provider ごとの宣言は `config/template.php` の `social_providers.{provider}.email_trust` に置く
  (既存の `capability` と同じ場所・同じ fail-closed 作法)
- **google は `confirmed` を宣言し、挙動は完全に不変** (`email_verified_at` は今までどおり付く)

**未宣言は fail-closed** (= `unconfirmed` 扱い) とし、
`tests/Architecture/SocialProviderTrustPolicyTest.php` が
`config/template.php` の全 provider に宣言があることを機械検証する
(`SsrfPinBoundaryTest` / `RecentAuthRouteTest` と同型)。

**踏み込まないこと** (台帳で未裁定・指示でも明示):

- Microsoft provider の追加 (台帳 `auth-sso-social` の `agenda` は未解決)
- aigenba の id_token 署名検証 + `auth_time` 検証 (同 `agenda` 未解決)
- `capability` は `fresh_auth_prompt_only` のまま据え置き

### 施策 3. passkey を Fortify ネイティブ機能として有効化し、アプリ側の不変条件を被せる

**指示は「template t0 の `PasskeyServiceProvider` を移植」だが、
移植元 t0 のソースは本環境に存在しない** (リポジトリにも近傍にも `laravel-claude-template` は無い)。
一方で台帳 `auth-passkey` の boundary は canonical 資産の中身を
「**vendor route 加工・binder 差し替え・Response contract 上書き**」と明記している。
これは vendor の**置き換え**ではなく**アダプタ**である。

さらに AGENTS.md 思考原則 1「フレームワークのレンジ内でやる。自前機構の前に
Laravel / 同梱モジュールの公式作法を確認する」に照らすと、
**route 定義・controller・action・migration を自前で書き直すのは明確な違反**である。
Fortify 1.37 は passkey を第一級機能として同梱しており、公式作法は
`Features::passkeys()` を有効にすることである。

したがって:

**3-a. Fortify の passkey feature を有効化する**

`config/fortify.php` の `features` に `Features::passkeys(['confirmPassword' => false])` を追加。
`confirmPassword => false` は 2FA と**同じ理由で必須**である —
本アプリは Fortify 標準の `password.confirm` (3h・パスワード限定) を撤去し
generic recent-auth (15 分窓・パスワード or 再SSO) に統一済みで、
`password.confirm` を残すと SSO-only ユーザーが確認画面で詰む
(`config/fortify.php` の既存コメントがこの判断を明文化している)。

`limiters` に `'passkeys' => 'passkeys'` を追加し、
`FortifyServiceProvider` に `RateLimiter::for('passkeys', ...)` を定義する ((C) の是正)。

migration は `Passkeys::migrationPath()` を Fortify が publish するものを取り込む
(自前で書かない)。

**3-b. `App\Providers\PasskeyServiceProvider` を「アダプタ」として新設**

台帳 boundary の 3 役割をそのまま担わせる:

| 役割 | 中身 | 解決する問題 |
|------|------|-------------|
| binder 差し替え | `Route::bind('passkey', ...)` を**認証ユーザーの `passkeys()` relation 経由**に張り替える | (B) の 403 情報漏れ → 他人の passkey は **404** |
| Response contract 上書き | 4 つの `PasskeyXxxResponse` を Inertia / DTO+JsonResource を返すアプリ実装に差し替え | AGENTS.md 禁止事項 4 (`response()->json()` 直書き) の回避、Inertia 契約への適合 |
| vendor route 加工 | `recent-auth` / `ensure-login-method` の後付け配線 | 既存 `attachRecentAuthToSensitiveRoutes()` と同じ作法 |

**3-c. `App\Models\Passkey` (vendor モデルの app サブクラス) + Factory**

`Passkeys::usePasskeyModel(App\Models\Passkey::class)` で差し替える。理由:

- AGENTS.md 実装規約「新規モデル追加時は Factory の追加と
  `docs/architecture.md` / `docs/factories.md` への追記が必須」
  — テストは Factory 生成が必須 (`Model::create()` 手組み禁止)
- self-scoped な route binding と `HasFactory` の置き場所になる

**3-d. recent-auth との配線 (2026-08-04 裁定 A)**

- `app/Listeners/Auth/ClearRecentAuthOnPasskeyChange.php` を新設し、
  `PasskeyRegistered` / `PasskeyDeleted` の両方で `RecentAuthState::clear()` を呼ぶ
  (= credential 集合の変化 = 失効)。**(D) の死んだ API の最初の実利用者**になる
- 配線は `AppServiceProvider::boot()` の `Event::listen()` で明示的に行う
  (同 `register()` で `EventServiceProvider::disableEventDiscovery()` 済みのため
  auto-discovery には乗らない)
- `PasskeyVerified` → `RecentAuthState::confirm(method: 'passkey')` を satisfier に追加する
- **登録直後 passkey の satisfier 除外は実装しない** (裁定で見送り済み)

**3-e. 2FA との関係を「変えない」ことを明示的に固定する**

vendor の `PasskeyLoginController::store()` は `$guard->login()` を直接呼ぶため、
Fortify の TOTP チャレンジを通らない。ただし本アプリの組織 2FA 強制は
**ログイン後の global middleware** (`RequireTwoFactorForEnforcedOrganizations`,
`bootstrap/app.php:92`) なので、passkey ログインでも強制ゲートは効く。

裁定 A の再検討条件が「**パスキーが 2FA 準拠判定に算入される時**」である以上、
**現時点で passkey を 2FA 準拠に算入しない**のが台帳準拠の解である。
これを「たまたまそうなっている」で終わらせず、
「passkey でログインしても 2FA 強制組織のゲートは通過できない」ことを Feature テストで固定する。

**3-f. フロント**

- `resources/js/lib/passkeys.ts` (TypeScript 必須 / AGENTS.md 禁止事項 7)
  — WebAuthn ceremony ラッパ。`navigator.credentials` 非対応環境の feature detection を含む
- `Settings/Security.svelte` に passkey カードを追加。
  既存の `guardWithRecentAuth` / `RecentAuthModal` 契約にそのまま乗せる
- T102 の `noInlineConfig: true` (eslint.config:58) により
  **inline `eslint-disable` が使えない**。WebAuthn の `ArrayBuffer`/`base64url` 変換で
  lint 違反を出さない書き方にするか、必要なら `eslint.config` 側で
  ファイル単位の override を宣言する (inline に逃げない)
- DESIGN.md の token / Atomic Design に従い、既存 atom (`Card`/`Button`/`Badge`/`Alert`/
  `FormField`/`Input`/`ConfirmDialog`) の組合せで構成する。新規 SVG は作らない
  (`@lucide/svelte` のみ。`svg-inline-allowlist.test.ts` が強制)

---

## 3. 期待効果

### 使命 (North Star) への貢献

AI-CUE の使命は「専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする」。
**v1 スコープで撮影は PWA (同一オリジン・セッション認証)** と決まっている。

- **現場でパスワードを打たせない**: 手袋・保護具・明るい工場現場でのスマホ入力は
  マニュアル動画作成のワークフロー上、最も摩擦の大きい所作の一つ。
  passkey (生体/端末ロック解除) は「思考ゼロ」の思想と直結する
- **同一オリジン PWA なので RP ID が素直**: WebAuthn の relying party は
  アプリのホストそのままでよく、cross-origin の複雑さを持ち込まない
- **締め出しは現場を止める**: 現場作業者が自分で手段を消して入れなくなると、
  復旧には管理者の介在が要る。`EnsureLoginMethodRemains` はその停止時間を構造的に消す

### 具体的な改善見込み

- SSO 登録ユーザーに**入力しても通らないパスワード欄を出さなくなる** ((A) の是正)
- 他人の passkey の存在が **403 で漏れなくなる** ((B) の是正、404 に統一)
- 未認証の passkey challenge endpoint が**絞られる** ((C) の是正)
- 「ログイン手段を減らす route」が今後追加されたとき、
  guard 無しなら **CI が構造的に fail する** (分類漏れの deny-by-default)
- `RecentAuthState::clear()` が**死んだ API でなくなる** ((D) の是正)

---

## 4. 実装方針 (概要)

### 変更コンポーネント

| 層 | ファイル | 施策 |
|----|---------|------|
| config | `config/fortify.php` (features / limiters) | 3 |
| config | `config/template.php` (`social_providers.*.email_trust`) | 2 |
| config | `.env.example` (`PASSKEYS_*`) | 3 |
| Service | `app/Services/Auth/LoginMethodInventory.php` (新) | 1 |
| Service | `app/Services/Auth/SocialAccountService.php` (改) | 1, 2 |
| Policy | `app/Services/Auth/EmailTrust/{EmailTrustPolicy,Confirmed…,Unconfirmed…}.php` (新) | 2 |
| Middleware | `app/Http/Middleware/EnsureLoginMethodRemains.php` (新) | 1 |
| Provider | `app/Providers/PasskeyServiceProvider.php` (新) | 3 |
| Provider | `app/Providers/FortifyServiceProvider.php` (改: limiter + route 配線) | 1, 3 |
| Provider | `app/Providers/AppServiceProvider.php` (改: `Event::listen`) | 3 |
| Model | `app/Models/User.php` (改: `PasskeyUser` 実装 + trait) | 3 |
| Model | `app/Models/Passkey.php` + `database/factories/PasskeyFactory.php` (新) | 3 |
| Listener | `app/Listeners/Auth/ClearRecentAuthOnPasskeyChange.php` (新) | 3 |
| Migration | passkeys テーブル (vendor publish) | 3 |
| Front | `resources/js/lib/passkeys.ts` (新) | 3 |
| Front | `resources/js/pages/Settings/Security.svelte` (改) | 3 |
| Docs | `docs/template-divergence.md` / `docs/architecture.md` / `docs/factories.md` | 1, 3 |

### 新設する Architecture gate

| gate | 型 | 施策 |
|------|----|----|
| `tests/Architecture/LoginMethodRemovalRouteTest.php` | inventory + exempt (deny-by-default) | 1 |
| `tests/Architecture/SocialProviderTrustPolicyTest.php` | config 宣言網羅 | 2 |
| `tests/Architecture/PasskeyPackageContractTest.php` | vendor 契約 pin | 3 |
| `tests/Architecture/PasskeyRouteProtectionTest.php` | route middleware 列挙固定 | 3 |
| `tests/Architecture/RecentAuthRouteTest.php` (改) | allowlist 追加 + **satisfier 集合 inventory を新設** | 3 |

`RecentAuthRouteTest` は現在 allowlist しか見ていない。指示にある
「satisfier 集合 (`RecentAuthState` / `SocialAuthController::completeStepUp` /
`ConfirmRecentAuthController`) を更新」を機械化するため、
**`RecentAuthState::confirm()` の呼び出し元集合を inventory 固定**するテストを同ファイルに追加する
(未登録の satisfier が生えたら fail)。

---

## 5. TODO 分割の判断

### 結論: **2 TODO** (依存順序あり)

| TODO | 台帳 | 内容 | 独立性 |
|------|------|------|--------|
| **T-α** | `auth-sso-social` | 施策 2 (EmailTrustPolicy seam) | **完全独立**。先行でも後行でもよい |
| **T-β** | `auth-login-method-retention` + `auth-passkey` | 施策 1 + 施策 3 | T-α と独立。内部で 1↔3 が相互依存 |

### なぜ施策 1 と 3 を分けないか

**`EnsureLoginMethodRemains` は、単独で出すと保護対象 route が 1 本も無い死んだコードになる。**

現在の aicue に「ログイン手段を減らす route」は**存在しない**:

- SSO 連携解除 route は無い (`Settings/Security.svelte` は「連携済み」バッジを出すだけで解除導線が無い)
- passkey 削除 route は無い (施策 3 で初めて生える)
- `settings.account.destroy` はアカウント除去であり関門の対象外 (免除)
- `user-password.update` は `current_password` 必須の変更であり除去ではない

AGENTS.md 思考原則 2「今必要なものだけ作る (オーバーエンジニアリング禁止)」および
禁止事項 1「テストなしの実装完了報告」に照らすと、
**関門とその最初の被保護 route は同じ TODO でしか green にできない**。
指示書自身も「passkey 削除経路が `EnsureLoginMethodRemains` を通ることを Feature テストで固定」と
要求しており、これは 1 TODO 内でのみ満たせる。

台帳 `auth-login-method-retention` の boundary も
「EnsureLoginMethodRemains middleware **とその適用** (パスワード削除・SSO 連携解除・passkey 削除経路)」と
**適用まで含めて 1 単位**と定義している。

### 却下した分割案

**案 X: 台帳 3 件 = 3 TODO (指示のデフォルト)**
→ 施策 1 が保護対象ゼロで着地する。`LoginMethodRemovalRouteTest` の inventory も空になり
guard としての意味を持たない。却下。

**案 Y: passkey を server / UI で 2 分割**
→ server だけ先行させると、UI から到達できない認証 endpoint が main に一定期間残る。
未認証 route (`/passkeys/login/*`) を含むため、到達導線が無いまま開けるのは筋が悪い。
機能としても UI が本体 (現場作業者が使えて初めて使命に効く)。却下。

**案 Z: 施策 1 で SSO 連携解除 route も新設して独立させる**
→ 連携解除は**プロダクト判断を伴う新機能**であり、
台帳 `auth-sso-social` の boundary にも `auth-login-method-retention` の
「今回実装せよ」にも入っていない。スコープ膨張。却下
(ただし `LoginMethodRemovalRouteTest` の候補判定には**将来 route が生えたら捕まる**形で組み込む)。

### ロールバック単位

- **T-α**: 単独 revert 可。config 追加 + 新規クラス + テストのみで、
  google の挙動は不変 (`email_verified_at` は従来どおり付く) なのでデータ影響ゼロ
- **T-β**: `config/fortify.php` の `Features::passkeys()` **1 行が実質的なキルスイッチ**。
  外せば passkey route が消え、UI 側も `Features::canManagePasskeys()` 相当のフラグで隠せる。
  `passkeys` テーブルは残るが未参照になるだけで害はない
  (AGENTS.md 禁止事項 3 により migration の巻き戻しはエージェント判断で行わない)。
  施策 1 部分 (SSO password null 化) は passkey とは独立に revert 可能だが、
  revert すると `hasPassword()` の嘘が戻る点に注意

### 実装順序 (T-β 内部)

1. (A) の是正 + `LoginMethodInventory` + `EnsureLoginMethodRemains` + Architecture gate
   (この時点で gate は「候補は全部 exempt」で green)
2. passkey feature 有効化 + migration + `App\Models\Passkey` + Factory
   (この時点で `LoginMethodRemovalRouteTest` が `passkey.destroy` 未分類で **fail する** = 期待どおり)
3. `PasskeyServiceProvider` (binder / Response / route 加工) で guard と recent-auth を配線し gate を green に戻す
4. `ClearRecentAuthOnPasskeyChange` + satisfier 追加 + `RecentAuthRouteTest` 更新
5. フロント (`passkeys.ts` + `Settings/Security.svelte`)

AGENTS.md 思考原則 5「テストファースト。fail を確認してから実装に入る」に沿った順序である。

---

## 6. 制約・前提

- **DB**: PostgreSQL 18.4 (192.168.117.3) 利用可。`composer test` 実走可能
  (直近実測 2704 passed / 0 failed / 2 skipped)。テスト DB は worktree ごとに一意
  (`TestDatabaseEnv::pgsqlBaseDatabase`)
- **dev DB への破壊操作 (`migrate:fresh` 等) をエージェント判断で実行しない** (AGENTS.md 禁止事項 3)
- **PHPStan level 10** / **Pest + `RefreshDatabase` グローバル + `--parallel`** /
  個別 `DatabaseTransactions` 禁止 / テストデータは Factory 経由
- `resources/js` は **TypeScript 必須** (AGENTS.md 禁止事項 7)
- eslint `noInlineConfig: true` (T102) — inline `eslint-disable` 不可
- **PII**: `User` の `email` / `name` は CipherSweet 暗号化。
  vendor の `getPasskeyUsername()` / `getPasskeyDisplayName()` はこれらを平文で
  WebAuthn options に載せる (認証器 UI に表示されるため仕様上不可避)。
  challenge は session に入るが `SESSION_ENCRYPT=true` が
  `EnvExampleInvariantTest` で固定されているため保護される
- `EnvExampleInvariantTest` があるため `PASSKEYS_*` 系 env は `.env.example` への追記が必要
- **同一オリジン PWA** 前提なので RP ID は `APP_URL` のホストで足りる (cross-origin 考慮不要)

---

## 7. スコープ外

- **Microsoft provider の追加** — 台帳 `auth-sso-social` の `agenda` 未解決 (プロダクト判断)
- **aigenba 形の id_token 署名検証 / `auth_time` 検証** — 同 `agenda` 未解決。
  `capability` は `fresh_auth_prompt_only` 据え置き
- **登録直後 passkey の satisfier 除外 (強化オプション)** — 2026-08-04 裁定で**明示的に見送り済み**
- **passkey を組織 2FA 準拠に算入すること** — 裁定 A の「再検討条件」であり、現時点では算入しない
- **SSO 連携解除 route の新設** — 台帳の boundary 外。スコープ膨張を避ける
- **`auth-passkey-hardening` (aigenba:T1108 の 4 施策)** — 別 feature として台帳が分離済み
- **admin (Filament) 面への passkey 適用** — `admin` guard は本設計の対象外
