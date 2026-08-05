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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 13 + Fortify 1.37 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pest テストフレームワーク (RefreshDatabase をグローバル適用、--parallel 実行)
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- CipherSweet による PII 暗号化 (User.email / User.name)

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか
11. Atomic Design準拠: `resources/js/components/` の `atoms/molecules/organisms/features/templates` の責務分離に沿った配置か。アイコンは Lucide 前提で、SVG 直書きを新設していないか

【特に厳しく見てほしい論点】
- `EnsureLoginMethodRemains` が middleware 内で `DB::transaction()` を開き、その中で
  `$next($request)` を実行する設計の妥当性と落とし穴 (Laravel の middleware / session /
  Inertia / event の相互作用)
- `Route::bind('passkey', ...)` を後勝ちで差し替える設計が、vendor の
  `PasskeysServiceProvider::boot()` との boot 順序で確実に勝てるか
- `PasskeyConfirmationResponse::toResponse()` で `auth.password_confirmed_at` を
  `forget()` する設計が実際に効くか (session 保存タイミング)
- Fortify の `Features::passkeys(['confirmPassword' => false])` の副作用
  (`config(['fortify-options.passkeys' => ...])`) と config cache の相互作用
- `RecentAuthRouteTest` の satisfier inventory (静的走査) の実現可能性と false negative
- テスト計画が RefreshDatabase + --parallel の制約下で実際に書けるか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 関連する現行コード (実測抜粋)

### vendor/laravel/passkeys/src/PasskeysServiceProvider.php
```php
public function register(): void
{
    $this->mergeConfigFrom(__DIR__.'/../config/passkeys.php', 'passkeys');
    $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
    $this->app->singleton(PasskeyConfirmationResponseContract::class, PasskeyConfirmationResponse::class);
    $this->app->singleton(PasskeyRegistrationResponseContract::class, PasskeyRegistrationResponse::class);
    $this->app->singleton(PasskeyDeletedResponseContract::class, PasskeyDeletedResponse::class);
}

public function boot(): void
{
    $this->registerPublishing();
    $this->registerRoutes();          // Passkeys::shouldRegisterRoutes() が false なので no-op
    $this->registerRouteBindings();   // Route::bind('passkey', ...) をグローバル解決で登録
}

protected function registerRouteBindings(): void
{
    Route::bind('passkey', function (string $value): Passkey {
        $model = Passkeys::passkeyModel();
        $passkey = app($model)->resolveRouteBinding($value);
        if (! $passkey instanceof Passkey) {
            throw (new ModelNotFoundException)->setModel($model, [$value]);
        }
        return $passkey;
    });
}
```

### vendor/laravel/passkeys/src/Http/Controllers/PasskeyRegistrationController.php
```php
public function destroy(Passkey $passkey, DeletePasskey $deletePasskey): PasskeyDeletedResponse
{
    $user = Auth::guard(Config::string('passkeys.guard'))->user() ?? throw new AuthenticationException;
    if (! $user instanceof PasskeyUser) { throw new RuntimeException(...); }
    abort_unless($passkey->user_id === $user->getKey(), 403);
    $deletePasskey($user, $passkey);
    return app(PasskeyDeletedResponse::class);
}
```
`DeletePasskey`:
```php
public function __invoke(PasskeyUser $user, Passkey $passkey): void
{
    PasskeyDeleted::dispatch($user, $passkey);   // ← delete の前後関係は実装参照
    ...
}
```

### vendor/laravel/passkeys/src/Http/Controllers/PasskeyConfirmationController.php
```php
public function store(PasskeyVerificationRequest $request, VerifyPasskey $verify): PasskeyConfirmationResponse
{
    $user = Auth::guard(...)->user() ?? throw new AuthenticationException;
    if (! $user instanceof PasskeyUser) { throw new RuntimeException(...); }
    $verify($request->credential(), $request->verificationOptions(), $user);
    /** @var SessionStore $session */
    $session = $request->session();
    $session->passwordConfirmed();          // ← auth.password_confirmed_at に書く
    return app(PasskeyConfirmationResponse::class);
}
```

### vendor/laravel/passkeys/src/Actions/VerifyPasskey.php (抜粋)
```php
public function __invoke(PublicKeyCredential $credential, PublicKeyCredentialRequestOptions $options, ?PasskeyUser $user = null): Passkey
{
    $response = $this->getResponse($credential);
    $passkey = DB::transaction(function () use (...) {
        $passkey = $this->getPasskey($credential, lock: true);
        $this->ensurePasskeyBelongsToUser($passkey, $user);   // (string) 正規化済み比較
        $source = $this->validate($response, $passkey, $options);
        $this->updatePasskey($passkey, $source);
        PasskeyVerified::dispatch($passkey->user, $passkey);
        return $passkey;
    });
    return $passkey;
}
```

### vendor/laravel/fortify/src/FortifyServiceProvider.php
```php
protected function configurePasskeys()
{
    LaravelPasskeys::ignoreRoutes();                       // 無条件
    if ($model = $this->passkeyUserModel()) { LaravelPasskeys::useUserModel($model); }
    config([
        'passkeys.relying_party_id' => config('fortify.passkeys.relying_party_id', parse_url(config('app.url'), PHP_URL_HOST)),
        'passkeys.allowed_origins'  => config('fortify.passkeys.allowed_origins', [config('app.url')]),
        'passkeys.guard'            => config('fortify.guard', 'web'),
        'passkeys.middleware'       => config('fortify.middleware', ['web']),
        'passkeys.management_middleware' => config('fortify-options.passkeys.confirmPassword', true) ? ['password.confirm'] : [],
        'passkeys.redirect'         => Fortify::redirects('login'),
        'passkeys.throttle'         => $this->passkeyThrottleMiddleware(),
    ]);
}
```
`Features::passkeys(array $options = [])`:
```php
public static function passkeys(array $options = [])
{
    if ($options) { config(['fortify-options.passkeys' => $options]); }
    return 'passkeys';
}
```

### app/Providers/FortifyServiceProvider.php (既存の後付け配線 — 模範)
```php
/**
 * ... route:cache 下でも CompiledRouteCollection::getByName() が nameCache に memoize した
 * 同一 instance を match() が返すため、この変更は dispatch にも有効。
 */
private function attachRecentAuthToSensitiveRoutes(): void
{
    $this->app->booted(static function (Application $app): void {
        $routes = $app->make(Router::class)->getRoutes();
        $routes->refreshNameLookups();
        foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) { self::appendMiddlewareIfMissing($routes, $name, 'recent-auth'); }
        foreach (self::CONDITIONAL_RECENT_AUTH_ROUTES as $name => $alias) { self::appendMiddlewareIfMissing($routes, $name, $alias); }
    });
}
```

### bootstrap/providers.php (現行)
```php
return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FortifyServiceProvider::class,
    McpPassportServiceProvider::class,
    SeoServiceProvider::class,
    // 外部 fake の条件付き rebind。AppServiceProvider の実装 bind を後勝ちで上書きするため必ず末尾側
    FakeExternalsServiceProvider::class,
];
```

### app/Http/Middleware/RequireRecentAuth.php (既存の同型 middleware)
```php
public function handle(Request $request, Closure $next): Response
{
    $session = $request->session();
    if (RecentAuthWindow::isFresh($session->get('recent_auth_at'))) {
        $response = $next($request);
        if (! $response instanceof Response) { throw new LogicException(...); }
        return $response;
    }
    $confirmUrl = route('recent-auth.confirm');
    if ($request->expectsJson() || $this->isInertiaMutation($request)) {
        return RecentAuthRequiredResource::make(new RecentAuthRequiredDto(
            message: 'この操作には直近の再認証が必要です。', redirect: $confirmUrl,
        ))->response()->setStatusCode(409)->withHeaders(['Cache-Control' => 'no-store']);
    }
    $intended = $request->isMethod('GET') ? $request->fullUrl() : $this->sameOriginRefererOrDashboard($request);
    $session->put('url.intended', $intended);
    if (! $request->isMethod('GET')) { $session->put('recent_auth.dropped_mutation', true); }
    return redirect()->route('recent-auth.confirm');
}
```

### app/Security/RecentAuthState.php
```php
final class RecentAuthState
{
    public function confirm(string $method, ?string $provider = null, ?int $verifiedAt = null): void
    {
        $at = $verifiedAt ?? time();
        session()->put('recent_auth_at', $at);
        session()->put('recent_auth_method', $method);
        session()->put('recent_auth_provider', $provider);
        session()->migrate(true);      // 権限上昇に伴う session fixation 対策 (CSRF token は維持)
    }
    public function clear(): void { session()->forget(['recent_auth_at','recent_auth_method','recent_auth_provider']); }
}
```

### app/Models/User.php (抜粋)
```php
class User extends Authenticatable implements CipherSweetEncrypted, LaratrustUser, MustVerifyEmail, OAuthenticatable
{
    use HasApiTokens;
    use HasFactory, HasRolesAndPermissions, Notifiable, TwoFactorAuthenticatable, UsesCipherSweet;
    protected $fillable = ['name','email','password'];
    protected $hidden = ['password','remember_token','two_factor_secret','two_factor_recovery_codes'];
    // casts: password => 'hashed', email_verified_at/terms_accepted_at/two_factor_confirmed_at => datetime
    public function hasPassword(): bool { $p = $this->getAttribute('password'); return is_string($p) && $p !== ''; }
    public function twoFactorStatus(): TwoFactorStatus { /* Disabled/Pending/Enabled */ }
    public function socialAccounts(): HasMany { return $this->hasMany(SocialAccount::class); }
}
```

### app/Http/Controllers/Auth/ConfirmRecentAuthController.php::buildStatus (canSatisfy の定義)
```php
$passwordSet = $user->hasPassword();
$providers = [];
foreach ($user->socialAccounts()->pluck('provider') as $provider) {
    $capability = $this->capabilityFor($provider);
    if (! $capability->isStepUpSatisfier()) { continue; }
    $providers[] = new RecentAuthProviderDto(...);
}
$canSatisfy = $passwordSet || $providers !== [];
```

### tests/Pest.php
```php
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->beforeEach(...);
// Architecture はファイル走査中心のため DB を使わない (TestCase のみ)
```

### bootstrap/app.php (global web middleware / alias)
```php
$middleware->web(append: [
    HandleInertiaRequests::class,
    SecurityHeaders::class,
    RequireTwoFactorForEnforcedOrganizations::class,
    BlockTwoFactorDisableForEnforcedOrganizations::class,
    NoStoreCacheHeadersForAuthenticatedPages::class,
    EncryptHistory::class,
]);
$middleware->authenticateSessions();     // alias 'auth.session'
$middleware->alias([
    'recent-auth' => RequireRecentAuth::class,
    'recent-auth.on-email-change' => RequireRecentAuthOnEmailChange::class,
    'require-active-subscription' => RequireActiveSubscription::class,
    'verified.or-back' => EnsureEmailIsVerifiedOrBack::class,
]);
// shouldRenderJsonWhen(api/* || expectsJson)
```

### AGENTS.md ドメイン固有規約 1 (共有ロック規約 — 模範)
> `cuts` / `video_manuals.scenario_version` / `video_manuals.status` を書き込む全経路は、
> 対象 VideoManual 行を `lockForUpdate()` で取得した同一トランザクション内で反映する。
> 経路 inventory は `ScenarioWritePathInventoryTest` (Architecture テスト) へ昇格済み
> = 新しい書き込み経路は inventory 登録が必須。

---

## 詳細設計書

# 詳細設計: auth-method-and-passkey

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用

### セキュリティ不変条件（本設計に直接効くもの）

2. **子は親に属する**: nested route の不整合は**認可より前に 404**
3. **cross-org 不可**
5. **権限判定は常に `laratrust_team_id` を明示**
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）／ **RefreshDatabase** はグローバル適用（`tests/Pest.php`）、`--parallel` 実行、
  個別 `DatabaseTransactions` 使用禁止
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）。新モデルは Factory 必須
- **DTO + JsonResource** パターン
- アーリーリターン推奨 / `declare(strict_types=1)` + 日本語コメント
- Controller は薄く（Service 委譲）、transaction は Service 内
- `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 13 + Fortify 1.37 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

[devnotes/20260805-1244-auth-method-and-passkey/conceptual-design.md](./conceptual-design.md) （APPROVED / Round 5）

---

## 施策一覧

| # | 施策名 | TODO | 主な変更ファイル | 優先度 |
|---|--------|------|-----------------|--------|
| 1 | SSO email trust policy seam | **T-α** | `SocialAccountService`, `config/template.php`, `EmailTrust/*` | 高 |
| 2 | ログイン手段 inventory と phantom password 是正 | T-β / S1 | `SocialAccountService`, `LoginMethodInventory`, `LoginMethodRemoval`, `LoginMethodSet`, `PasskeyLoginPolicy` | 高 |
| 3 | `EnsureLoginMethodRemains`（直列化規約つき） | T-β / S1+S3 | `EnsureLoginMethodRemains`, `bootstrap/app.php` | 高 |
| 4 | passkey feature 有効化と vendor アダプタ | T-β / S2+S3 | `config/fortify.php`, `PasskeyServiceProvider`, `App\Models\Passkey` | 高 |
| 5 | recent-auth 配線（裁定 A） | T-β / S3 | `ClearRecentAuthOnPasskeyChange`, `StampRecentAuthOnPasskeyVerified` | 高 |
| 6 | フロント（passkeys.ts + UI） | T-β / S4 | `resources/js/lib/passkeys.ts`, `Settings/Security.svelte`, `Auth/Login.svelte` | 中 |

---

# 施策 1: SSO email trust policy seam（T-α）

## 変更箇所

- `config/template.php` L38-47（`social_providers`）
- `app/Services/Auth/SocialAccountService.php` L62（`email_verified_at => now()`）
- 新規: `app/Services/Auth/EmailTrust/{EmailTrustPolicy,ConfirmedEmailTrustPolicy,UnconfirmedEmailTrustPolicy,EmailTrustPolicyResolver}.php`

## 波及変更

- TypeScript 型定義: **なし**（`socialProviders` の Inertia prop は `array_keys()` のままで形が変わらない）
- API Resource/DTO: **なし**
- テストファイル: `tests/Feature/Auth/SocialAuthTest.php`（回帰）、新規 `tests/Architecture/SocialProviderTrustPolicyTest.php`
- ドキュメント: `docs/architecture.md`（SSO 節に Confirmed の判定基準）

## 現行コード

```php
// app/Services/Auth/SocialAccountService.php L53-64
$user = (new User([
    'name' => $socialiteUser->getName() ?? $email,
    'email' => $email,
    'password' => Str::password(32),
]))->forceFill([
    'terms_accepted_at' => now(),
    'consent_version' => config()->string('legal.consent_version'),
    // IdP 側で検証済みの email として扱う
    'email_verified_at' => now(),
]);
```

```php
// config/template.php
'social_providers' => [
    'google' => ['label' => 'Google', 'capability' => 'fresh_auth_prompt_only'],
],
```

## 変更後コード

```php
// app/Services/Auth/EmailTrust/EmailTrustPolicy.php
<?php

declare(strict_types=1);

namespace App\Services\Auth\EmailTrust;

use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * SSO provider が主張する email を「IdP 側で検証済み」として信頼してよいかの方針。
 *
 * **Confirmed の判定基準 (契約)**:
 *   provider が当該 email の **所有を検証済み** であり、かつ
 *   **テナント管理者が任意の email を claim できない** こと。
 *   この 2 条件を満たす provider のみ、IdP の主張だけで email_verified_at を立ててよい。
 *
 * 差し替え可能にしてある理由 = nOAuth 対策のキルスイッチ。
 * 例: Microsoft Entra ID のテナント管理者は未検証の email claim を任意に設定でき、
 * 他社ドメインの email を主張できる。そのため Microsoft は Unconfirmed 側に置く。
 *
 * 宣言は config('template.social_providers.{provider}.email_trust')。
 * 未宣言・解釈不能は Unconfirmed に倒す (fail-closed)。
 */
interface EmailTrustPolicy
{
    /** IdP の主張する email を検証済みとして扱ってよいか */
    public function trustsEmail(SocialiteUser $socialiteUser): bool;
}
```

```php
// app/Services/Auth/EmailTrust/ConfirmedEmailTrustPolicy.php
final class ConfirmedEmailTrustPolicy implements EmailTrustPolicy
{
    public function trustsEmail(SocialiteUser $socialiteUser): bool
    {
        return true;
    }
}

// app/Services/Auth/EmailTrust/UnconfirmedEmailTrustPolicy.php
final class UnconfirmedEmailTrustPolicy implements EmailTrustPolicy
{
    public function trustsEmail(SocialiteUser $socialiteUser): bool
    {
        return false;
    }
}
```

```php
// app/Enums/EmailTrustLevel.php
enum EmailTrustLevel: string
{
    case Confirmed = 'confirmed';
    case Unconfirmed = 'unconfirmed';

    /** 未宣言・解釈不能は Unconfirmed (fail-closed) */
    public static function fromRaw(mixed $raw): self
    {
        return is_string($raw) ? (self::tryFrom($raw) ?? self::Unconfirmed) : self::Unconfirmed;
    }
}
```

```php
// app/Services/Auth/EmailTrust/EmailTrustPolicyResolver.php
final class EmailTrustPolicyResolver
{
    public function for(string $provider): EmailTrustPolicy
    {
        $level = EmailTrustLevel::fromRaw(
            config('template.social_providers.'.$provider.'.email_trust'),
        );

        return match ($level) {
            EmailTrustLevel::Confirmed => new ConfirmedEmailTrustPolicy,
            EmailTrustLevel::Unconfirmed => new UnconfirmedEmailTrustPolicy,
        };
    }
}
```

```php
// app/Services/Auth/SocialAccountService.php (register の該当部)
public function __construct(
    private readonly SecurityEventRecorder $recorder,
    private readonly OrganizationProvisioningService $provisioning,
    private readonly EmailTrustPolicyResolver $emailTrust,   // ← 追加
) {}

// ...
// IdP が email 所有を検証している provider のみ検証済みとして扱う (nOAuth 対策の継ぎ目)。
// google は confirmed 宣言のため従来どおり email_verified_at が立つ (挙動不変)。
$verifiedAt = $this->emailTrust->for($provider)->trustsEmail($socialiteUser) ? now() : null;

$user = (new User([
    'name' => $socialiteUser->getName() ?? $email,
    'email' => $email,
    // 施策 2 で password は null になる (本施策では触らない)
    'password' => Str::password(32),
]))->forceFill([
    'terms_accepted_at' => now(),
    'consent_version' => config()->string('legal.consent_version'),
    'email_verified_at' => $verifiedAt,
]);
```

```php
// config/template.php
'social_providers' => [
    'google' => [
        'label' => 'Google',
        'capability' => 'fresh_auth_prompt_only',
        // email_trust: IdP の主張する email を検証済みとして扱ってよいか
        // (App\Enums\EmailTrustLevel の value)。未宣言は unconfirmed 扱い (fail-closed)。
        // Google は Gmail / Workspace とも email 所有を検証しており、管理者は
        // 所有権を証明したドメイン外を claim できないため confirmed。
        // Microsoft (Entra ID) は管理者が未検証 email claim を設定できる (nOAuth) ため
        // 追加する場合は必ず unconfirmed から始めること。
        'email_trust' => 'confirmed',
    ],
],
```

## PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`trustsEmail(): bool` / `for(): EmailTrustPolicy`）
- [x] null 安全（`config()` の戻りは `mixed` → `EmailTrustLevel::fromRaw()` が `is_string` で narrowing）
- [x] DTO を返している（enum + interface。配列返却なし）
- [x] Generics の型パラメータ: 該当なし

## テスト計画

- [ ] 新規 `tests/Architecture/SocialProviderTrustPolicyTest.php`
  - 全 provider が `email_trust` 宣言を持つ（deny-by-default。宣言漏れで fail）
  - 全 provider が `capability` 宣言を持つ（既存 fail-closed の明示化）
  - **google の `email_trust` が `confirmed`**（現行挙動の pin。緩めるならテストごと変える）
  - `EmailTrustLevel::fromRaw(null)` / `fromRaw('nonsense')` / `fromRaw(['x'])` が `Unconfirmed`
- [ ] 既存 `tests/Feature/Auth/SocialAuthTest.php` に回帰を追加
  - 「SSO register で User + SocialAccount が作成されログインされる」の `email_verified_at`
    非 null アサートは**そのまま**（挙動不変の証明）
  - 追加: provider の `email_trust` を `unconfirmed` に差し替えた場合、
    `email_verified_at` が **null** になり `/email/verify` ゲートに落ちる
    （`config()->set()` で差し替え。Socialite は既存 `fakeSocialiteCallback()` を再利用）
- [ ] 個別 `DatabaseTransactions` を使っていないことを確認（グローバル `RefreshDatabase` のみ）

## リスク

- `email_trust` 宣言漏れの provider を後から足すと**その provider だけ検証済みにならない**。
  これは fail-closed 方向の誤差であり、`SocialProviderTrustPolicyTest` が CI で先に落とす
- `SocialAccountService` のコンストラクタ引数が増えるため、DI 解決に依存する箇所の確認が必要
  （現状は `SocialAuthController::callback` のメソッドインジェクションのみ = コンテナ解決で自動追随）

---

# 施策 2: ログイン手段 inventory と phantom password 是正（T-β / S1）

## 変更箇所

- `app/Services/Auth/SocialAccountService.php` L57（`'password' => Str::password(32)` の撤去）
- `app/Listeners/RecordSecurityEvent.php`（`PasswordChanged` の購読を追加）
- 新規: `app/Services/Auth/LoginMethodInventory.php`, `app/Services/Auth/PasskeyLoginPolicy.php`
- 新規: `app/DataTransferObjects/Auth/{LoginMethodRemoval,LoginMethodSet}.php`
- 新規: `app/Enums/Auth/LoginMethodKind.php`

## 波及変更

- TypeScript 型定義: **なし**（S1 時点では Inertia prop を増やさない。UI 露出は S4）
- API Resource/DTO: `LoginMethodSet` は内部 DTO。HTTP 応答には出さない
- テストファイル: 新規 `tests/Feature/Auth/LoginMethodInventoryTest.php`、
  既存 `tests/Feature/Auth/SocialAuthTest.php`（password null の回帰）、
  既存 `tests/Feature/Auth/RecentAuthTest.php`（SSO 登録ユーザーの `passwordSet` が false になる回帰）
- ドキュメント: `docs/template-divergence.md`（新規 D エントリ）

## 現行コード

```php
// app/Services/Auth/SocialAccountService.php L53-58
$user = (new User([
    'name' => $socialiteUser->getName() ?? $email,
    'email' => $email,
    // SSO 登録はパスワードを持たない (ランダム値をハッシュ化して保存)
    'password' => Str::password(32),
]))->forceFill([...]);
```

```php
// app/Models/User.php L88-101（変更しない。意味が初めて成立する）
public function hasPassword(): bool
{
    $password = $this->getAttribute('password');

    return is_string($password) && $password !== '';
}
```

## 変更後コード

### 2-a. phantom password の撤去（前方修正のみ）

```php
// app/Services/Auth/SocialAccountService.php
$user = (new User([
    'name' => $socialiteUser->getName() ?? $email,
    'email' => $email,
    // SSO 登録は password を持たない (null のまま)。
    // users.password は nullable であり、password 経路の可否は User::hasPassword() が
    // fail-closed で判定する契約 (0001_01_01_000000_create_users_table.php のコメント)。
    // ランダム値を入れると hasPassword() が常に true になり、
    // recent-auth の passwordSet と EnsureLoginMethodRemains の双方が形骸化する。
    // → docs/template-divergence.md の逸脱エントリを参照。
]))->forceFill([...]);
```

`$fillable` は `['name','email','password']` のままでよい（渡さないだけ）。
`password` の cast は `hashed` だが、attribute を渡さなければ cast は走らない。

### 2-b. `PasswordChanged` の記録（将来の判別子）

```php
// app/Listeners/RecordSecurityEvent.php
public function subscribe(Dispatcher $events): void
{
    // ...既存...
    $events->listen(PasswordUpdated::class, [self::class, 'handlePasswordChanged']);   // ← 追加
}

/**
 * アプリ内でのパスワード変更 (UpdateUserPassword)。
 * SecurityEventType::PasswordChanged は enum に存在しながら記録経路が無く、
 * 「そのユーザーが自分でパスワードを設定したか」を後から判別できなかった。
 */
public function handlePasswordChanged(PasswordUpdated $event): void
{
    $this->recorder->record(SecurityEventType::PasswordChanged, $this->asUser($event->user));
}
```

> **実装時の確認事項**: Fortify 1.37 が発火するパスワード更新イベントの正確なクラス名を
> `vendor/laravel/fortify/src/Events/` で確認すること。該当イベントが無い場合は
> `App\Actions\Fortify\UpdateUserPassword` から `SecurityEventRecorder` を直接呼ぶ
> （`OrganizationMemberController::resetTwoFactor` と同じ「Action から直接記録」の作法）。

### 2-c. `PasskeyLoginPolicy`（passkey ログイン「資格」の唯一の源）

```php
<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\TwoFactorStatus;
use App\Models\User;
use Laravel\Fortify\Features;

/**
 * その User が passkey で **ログイン** することを許されるか（credential の有無とは無関係）。
 *
 * 判定を 1 箇所に集約する理由: 同じ条件が
 *   (1) Passkeys::authorizeLoginUsing() の closure（vendor のログイン直前ゲート）
 *   (2) LoginMethodInventory の passkey 判定
 * の 2 箇所で必要になり、別々に書けば必ず乖離するため。
 *
 * **TOTP confirmed のユーザーを拒否する理由**:
 * vendor の PasskeyLoginController::store() は $guard->login() を直接呼び、
 * Fortify の two-factor challenge を通らない。2026-08-04 裁定 A の再検討条件が
 * 「パスキーが 2FA 準拠判定に算入される時」である以上、現時点で passkey は 2FA 相当ではなく、
 * passkey login で TOTP を置き換えるのは assurance の後退にあたる。
 * これは c2c 未裁定の論点であり、裁定が出たら**このクラス 1 箇所**を書き換えれば
 * 上記 2 経路が同時に反転する。
 */
final class PasskeyLoginPolicy
{
    public function allowsPasskeyLogin(User $user): bool
    {
        if (! Features::enabled(Features::passkeys())) {
            return false;   // feature off なら route ごと存在しない
        }

        return $user->twoFactorStatus() !== TwoFactorStatus::Enabled;
    }
}
```

### 2-d. 除去対象 DTO（閉じた variant / 不正状態を作れない）

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Auth;

use App\Models\Passkey;
use App\Models\User;
use Webmozart\Assert\Assert;

/**
 * 「今から何を除去しようとしているか」を表す閉じた variant。
 *
 * private constructor + 名前付き static factory で **不正状態を生成できない**ようにする
 * (provider 空文字、他人の passkey、種別と payload の不整合をコンストラクタで排除)。
 */
final class LoginMethodRemoval
{
    private function __construct(
        public readonly LoginMethodRemovalKind $kind,
        public readonly ?Passkey $passkey = null,
        public readonly ?string $provider = null,
    ) {}

    /** 除去しない (現在状態の照会) */
    public static function none(): self
    {
        return new self(LoginMethodRemovalKind::None);
    }

    public static function password(): self
    {
        return new self(LoginMethodRemovalKind::Password);
    }

    public static function social(string $provider): self
    {
        Assert::stringNotEmpty($provider);

        return new self(LoginMethodRemovalKind::Social, provider: $provider);
    }

    /**
     * passkey 1 件の削除。
     * $passkey は **binder が対象 User に属することを 404 で確定させた後**に渡すこと。
     * 二重防御として所有を assert する (fail-closed)。
     */
    public static function passkey(Passkey $passkey, User $owner): self
    {
        Assert::true(
            (string) $passkey->user_id === (string) $owner->getKey(),
            'LoginMethodRemoval::passkey は対象 User 所有の passkey のみ受け付ける',
        );

        return new self(LoginMethodRemovalKind::Passkey, passkey: $passkey);
    }

    /** 全 passkey を除外した集合の評価用 (不変条件検査) */
    public static function allPasskeys(): self
    {
        return new self(LoginMethodRemovalKind::AllPasskeys);
    }
}
```

```php
// app/Enums/Auth/LoginMethodRemovalKind.php
enum LoginMethodRemovalKind
{
    case None;
    case Password;
    case Social;
    case Passkey;
    case AllPasskeys;
}

// app/Enums/Auth/LoginMethodKind.php
enum LoginMethodKind: string
{
    case Password = 'password';
    case Social = 'social';
    case Passkey = 'passkey';
}
```

```php
// app/DataTransferObjects/Auth/LoginMethodSet.php
/**
 * ログインに使える手段の集合。
 *
 * @phpstan-type MethodLabel string
 */
final class LoginMethodSet
{
    /** @param list<string> $methods 'password' / 'social:google' / 'passkey' */
    public function __construct(public readonly array $methods) {}

    public function isEmpty(): bool
    {
        return $this->methods === [];
    }

    public function count(): int
    {
        return count($this->methods);
    }
}
```

### 2-e. `LoginMethodInventory`（投影後の集合を返す）

```php
<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\DataTransferObjects\Auth\LoginMethodRemoval;
use App\DataTransferObjects\Auth\LoginMethodSet;
use App\Enums\Auth\LoginMethodRemovalKind;
use App\Models\User;

/**
 * 「ログイン画面から本人がアカウントに入れる手段」の集合。
 *
 * 基準は「データが存在する」ではなく「**使える**」。
 * feature を落とした後も使えない手段を数えると guard が形骸化するため。
 *
 * 唯一の公開 API は remainingAfter()。「現在の手段」も
 * LoginMethodRemoval::none() で表現する (API を 2 本にすると片方だけ使う実装が生える)。
 *
 * ⚠ 呼び出し側の契約: 除去の可否判定に使う場合、本メソッドは
 * **対象 User 行を lockForUpdate() で取得した同一トランザクション内**で呼ぶこと
 * (EnsureLoginMethodRemains が唯一の呼び出し点)。ロック外の評価は TOCTOU で破れる。
 *
 * ⚠ canSatisfy (ConfirmRecentAuthController) とは**別概念**。あちらは
 * 「step-up 再認証を成立させられるか」で ProviderCapability による絞り込みが入る。
 * こちらは「ログインできるか」なので capability では絞らない。統合しないこと。
 */
final class LoginMethodInventory
{
    public function __construct(
        private readonly PasskeyLoginPolicy $passkeyLoginPolicy,
    ) {}

    /** $removal が成功した後に残るログイン手段の集合 */
    public function remainingAfter(User $user, LoginMethodRemoval $removal): LoginMethodSet
    {
        $methods = [];

        // --- password ---
        if ($removal->kind !== LoginMethodRemovalKind::Password && $user->hasPassword()) {
            $methods[] = 'password';
        }

        // --- social ---
        // capability では絞らない (identity_only でもログインはできる)。
        // ただし config に無い provider は SocialAuthController::ensureProviderEnabled() が
        // 404 にするため、連携行があってもログインには使えない → 数えない。
        $enabled = array_keys(config()->array('template.social_providers'));
        foreach ($user->socialAccounts()->pluck('provider') as $provider) {
            if (! is_string($provider) || ! in_array($provider, $enabled, true)) {
                continue;   // fail-closed
            }
            if ($removal->kind === LoginMethodRemovalKind::Social && $removal->provider === $provider) {
                continue;   // 今から外す
            }
            $methods[] = 'social:'.$provider;
        }

        // --- passkey ---
        if ($this->passkeyLoginPolicy->allowsPasskeyLogin($user) && $this->hasRemainingPasskey($user, $removal)) {
            $methods[] = 'passkey';
        }

        return new LoginMethodSet(array_values(array_unique($methods)));
    }

    private function hasRemainingPasskey(User $user, LoginMethodRemoval $removal): bool
    {
        if ($removal->kind === LoginMethodRemovalKind::AllPasskeys) {
            return false;
        }

        $query = $user->passkeys();

        if ($removal->kind === LoginMethodRemovalKind::Passkey && $removal->passkey !== null) {
            // 削除対象自身を残存手段として数えない (投影)
            $query->whereKeyNot($removal->passkey->getKey());
        }

        return $query->exists();
    }
}
```

## PHPStan 適合チェック

- [x] 戻り値の型が明示（`LoginMethodSet` / `bool`）
- [x] null 安全（`pluck()` の要素は `mixed` → `is_string` で narrowing、`$removal->passkey` は
      `?Passkey` を `!== null` で narrowing、`Assert` で不変条件を宣言）
- [x] DTO を返している（`LoginMethodSet`。配列返却なし）
- [x] Generics: `HasMany<Passkey, User>` は `PasskeyAuthenticatable` trait 由来
      （`@phpstan-require-implements PasskeyUser` により User 側で解決される）
- [x] `config()->array()` を使い `mixed` を型付きで受ける（既存の作法）

## テスト計画

- [ ] 新規 `tests/Feature/Auth/LoginMethodInventoryTest.php`
  - SSO 登録ユーザー（`ssoOnly()`）: `remainingAfter(none)` に `password` を含まない
  - password ユーザー: `password` を含む
  - `config('template.social_providers')` から google を外すと `social:google` が消える
  - `PasskeyLoginPolicy` が false（feature off / TOTP confirmed）なら passkey を含まない
  - `LoginMethodRemoval::passkey($p, $user)` で対象 passkey が集合から消える（投影）
  - `LoginMethodRemoval::allPasskeys()` で passkey が全て消える
  - **不正状態が作れない**: 他人の passkey を `LoginMethodRemoval::passkey()` に渡すと
    `InvalidArgumentException`、`social('')` も同様
  - **inventory と login authorization の一致**（意味レベル。構造 gate だけでは保証できない）:
    同一ユーザー状態で `LoginMethodInventory` の passkey 判定と
    `Passkeys::allowsLogin()` の結果が一致する（TOTP on/off × feature on/off の 4 組合せ）
- [ ] 既存 `tests/Feature/Auth/SocialAuthTest.php` に追加
  - SSO register 後の `$user->password` が **null**、`hasPassword()` が **false**
  - `email_verified_at` は従来どおり非 null（施策 1 との相互作用の回帰）
- [ ] 既存 `tests/Feature/Auth/RecentAuthTest.php` に追加
  - SSO 登録直後のユーザーの `/recent-auth/status` が `passwordSet: false`、
    `canSatisfy: true`（google が satisfier のため）
- [ ] 既存 `tests/Feature/Auth/RecentAuthPasswordRecoveryTest.php` は**変更不要**
      （`ssoOnly()` factory を使っており、本変更で実挙動と一致する方向）
- [ ] 個別 `DatabaseTransactions` を使っていないことを確認

## リスク

- **既存 SSO ユーザーには効かない**（前方修正のみ）。誤 UI / `canSatisfy` 誤判定 /
  inventory の誤カウントは既知制約として残る。`docs/template-divergence.md` に記録する。
  遡及移行は「password 登録後に SSO 連携したユーザーの実パスワードを消す」危険があるため行わない
- `Str::password(32)` 撤去により、SSO ユーザーの `password` が null になる。
  `password` を非 null 前提にしているコードが無いことは `hasPassword()` の存在と
  `UserFactory::ssoOnly()` の既存テスト（`UserFactoryStatesTest`）が示している
- `LoginMethodInventory` を `canSatisfy` と混同する実装が将来生えるリスク →
  docblock で明示的に警告（AGENTS.md 思考原則 4）

---

# 施策 3: `EnsureLoginMethodRemains`（直列化規約つき）（T-β / S1+S3）

## 変更箇所

- 新規: `app/Http/Middleware/EnsureLoginMethodRemains.php`
- `bootstrap/app.php`（middleware alias `ensure-login-method` の登録）
- 新規: `tests/Architecture/LoginMethodRemovalRouteTest.php`

## 波及変更

- TypeScript 型定義: **なし**（S1 時点。S4 で UI が 422 を扱う）
- API Resource/DTO: 新規 `LoginMethodRequiredDto` + `LoginMethodRequiredResource`
  （`RecentAuthRequiredDto` / `RecentAuthRequiredResource` と同型。禁止事項 4 の遵守）
- テストファイル: 新規 Architecture 1 本 + Feature（施策 4 と併せて）

## 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DataTransferObjects\Auth\LoginMethodRemoval;
use App\Http\Resources\Auth\LoginMethodRequiredResource;
use App\Models\Passkey;
use App\Models\User;
use App\Services\Auth\LoginMethodInventory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログイン手段を減らす操作の前に「実行後も最低 1 つ手段が残る」ことを保証する関門。
 *
 * **評価するのは現在状態ではなく「操作が成功した後の投影状態」**。
 * 素朴に現在を数えると削除対象自身が残存手段として数えられ、
 * 「唯一の passkey を削除できてしまう」= 意図と正反対の挙動になる。
 *
 * **直列化規約 (TOCTOU 対策)**:
 *   投影が正しくても、確認と削除が別トランザクションなら破れる
 *   (passkey 2 件のユーザーが別々の passkey を同時削除 → 両方が「もう片方が残る」と判定 → 0 件)。
 *   そこで本 middleware が
 *     (1) DB::transaction() を開き
 *     (2) 対象 User 行を lockForUpdate() で取得し
 *     (3) **ロック取得後に** 投影を評価し
 *     (4) **同一トランザクション内で $next() を実行**して vendor の削除まで完了させる。
 *   ロック取得順序は User → credential に固定する。
 *   本アプリのドメイン固有規約 1「シナリオ整合の共有ロック規約」と同型の作法。
 *
 * **単一の直列化点であること**が不変条件であり、
 * tests/Architecture/LoginMethodRemovalRouteTest.php が deny-by-default で強制する。
 */
final class EnsureLoginMethodRemains
{
    public function __construct(
        private readonly LoginMethodInventory $inventory,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->pass($next, $request);   // 未認証は auth middleware の責務
        }

        return DB::transaction(function () use ($request, $next, $user): Response {
            // (2) 対象 User 行をロック (以降の投影評価はこのロック下でのみ有効)
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            // (3) ロック取得後に投影を評価する
            $remaining = $this->inventory->remainingAfter($locked, $this->removalFor($request, $locked));

            if ($remaining->isEmpty()) {
                return $this->reject($request);
            }

            // (4) 同一トランザクション内で削除まで完了させる
            return $this->pass($next, $request);
        });
    }

    /**
     * route から「今から何を除去しようとしているか」を決める。
     *
     * 対象 passkey が当該 User に属することは **binder が 404 で確定済み**
     * (PasskeyServiceProvider の binder 差し替え)。DTO 側でも二重に assert する。
     */
    private function removalFor(Request $request, User $user): LoginMethodRemoval
    {
        $passkey = $request->route('passkey');
        if ($passkey instanceof Passkey) {
            return LoginMethodRemoval::passkey($passkey, $user);
        }

        // 将来の除去 route (password 削除 / SSO 解除) はここに分岐を足す。
        // 未知の除去 route を素通しさせないため fail-closed で落とす
        // (LoginMethodRemovalRouteTest が「middleware を付けたのに分岐が無い」を先に検出する)。
        throw new LogicException(
            'EnsureLoginMethodRemains: 除去対象を決定できない route です。removalFor() に分岐を追加してください。',
        );
    }

    private function reject(Request $request): Response
    {
        $dto = new LoginMethodRequiredDto(
            message: 'この操作を行うと、ログインする手段がなくなります。先に別のログイン手段（パスワードの設定、ソーシャル連携、他のパスキー）を追加してください。',
            settingsUrl: route('settings.security'),
        );

        if ($request->expectsJson() || $request->hasHeader('X-Inertia')) {
            return LoginMethodRequiredResource::make($dto)
                ->response()
                ->setStatusCode(422)
                ->withHeaders(['Cache-Control' => 'no-store']);
        }

        return back()->withErrors(['login_method' => $dto->message]);
    }

    private function pass(Closure $next, Request $request): Response
    {
        $response = $next($request);
        if (! $response instanceof Response) {
            throw new LogicException('Expected Symfony Response from middleware $next, got '.get_debug_type($response));
        }

        return $response;
    }
}
```

```php
// bootstrap/app.php の alias 追加
$middleware->alias([
    'recent-auth' => RequireRecentAuth::class,
    'recent-auth.on-email-change' => RequireRecentAuthOnEmailChange::class,
    // ログイン手段を減らす操作の関門 (投影後評価 + User 行ロックによる直列化)。
    // 付与対象は LoginMethodRemovalRouteTest が deny-by-default で強制する。
    'ensure-login-method' => EnsureLoginMethodRemains::class,
    // ...
]);
```

### Architecture gate

```php
<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureLoginMethodRemains;
use Illuminate\Support\Facades\Route;

/*
 * 「ログイン手段を減らす route」の分類 invariant (deny-by-default)。
 *
 * ログイン手段を全部消して自分で締め出す事故は復旧コストが高く、現場を止める。
 * 候補となる route を構造的に列挙し、
 *   (a) guard 必須 (ensure-login-method middleware を持つ) か
 *   (b) 免除 (理由文字列つき)
 * のどちらかに **必ず分類させる**。分類漏れは fail = 将来 SSO 解除 / passkey 削除 /
 * パスワード削除 route を足したとき、guard の要否を必ず考えさせる。
 *
 * 本テストは分類漏れ・drift を落とす役割に限定する。実挙動 (投影評価・ロック・422) は
 * tests/Feature/Auth/LoginMethodRetentionTest.php が担保する。
 *
 * 候補の構造的定義: 認証系 URI 空間 ('user/passkeys', 'settings/social', 'user/password',
 * 'settings/account') に属する破壊的メソッド (DELETE / PUT / PATCH) の named route。
 */

/** @return list<string> guard 必須の route 名 */
function loginMethodRemovalGuardedRoutes(): array
{
    return [
        // passkey 削除 (credential 集合を減らす。最初の被保護 route)
        'passkey.destroy',
    ];
}

/** @return array<string, string> route 名 => 免除理由 (非空必須) */
function loginMethodRemovalExemptRoutes(): array
{
    return [
        // アカウント自体を消す操作。手段が 0 になるのは目的であって事故ではない。
        // 別途 recent-auth (step-up) で保護済み。
        'settings.account.destroy' => 'アカウント除去そのものであり、手段が残らないことが意図',
        // 第二要素の除去であってログイン手段の除去ではない
        // (TOTP を外してもパスワード / SSO / passkey は残る)。
        'two-factor.disable' => '第二要素の除去でありログイン手段ではない',
        // 変更であって除去ではない。current_password 必須で null 化できない。
        'user-password.update' => 'パスワードの変更であり除去経路ではない (current_password 必須)',
    ];
}

test('ログイン手段を減らしうる route は guard 必須か免除のどちらかに分類されている', function (): void {
    $prefixes = ['user/passkeys', 'settings/social', 'user/password', 'settings/account'];
    $destructive = ['DELETE', 'PUT', 'PATCH'];

    $guarded = loginMethodRemovalGuardedRoutes();
    $exempt = loginMethodRemovalExemptRoutes();

    $checked = 0;
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        $matchesPrefix = false;
        foreach ($prefixes as $prefix) {
            if (str_starts_with($uri, $prefix)) {
                $matchesPrefix = true;
                break;
            }
        }
        if (! $matchesPrefix || array_intersect($destructive, $route->methods()) === []) {
            continue;
        }

        $name = $route->getName();
        if ($name === null) {
            $violations[] = "route {$uri} に名前が無く分類できない";

            continue;
        }

        $checked++;

        if (array_key_exists($name, $exempt)) {
            expect(trim($exempt[$name]))->not->toBe('', "route '{$name}' の免除理由が空 (運用劣化)");

            continue;
        }

        if (! in_array($name, $guarded, true)) {
            $violations[] = "route '{$name}' が未分類 (guard 必須 or 免除のどちらかに登録すること)";

            continue;
        }

        $middleware = $route->gatherMiddleware();
        $hasGuard = in_array('ensure-login-method', $middleware, true)
            || in_array(EnsureLoginMethodRemains::class, $middleware, true);
        if (! $hasGuard) {
            $violations[] = "route '{$name}' に ensure-login-method middleware が付与されていない";
        }
    }

    expect($violations)->toBe([]);
    // 1 本も検査されない = 候補判定が壊れた (空振り drift) ので fail させる
    expect($checked)->toBeGreaterThan(0);
});

test('guard 必須リストの route は全て実在する', function (): void {
    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();

    foreach (loginMethodRemovalGuardedRoutes() as $name) {
        expect($routes->getByName($name))->not->toBeNull("route '{$name}' が存在しない (リネーム/削除に追従していない)");
    }
});
```

> **S1 時点の扱い**: `Features::passkeys()` が off のため `passkey.destroy` は存在しない。
> S1 では `loginMethodRemovalGuardedRoutes()` を空配列にし、候補は全て免除に分類する
> （`$checked > 0` は `settings.account.destroy` / `user-password.update` で満たされる）。
> S3 で `passkey.destroy` を guard 必須へ追加する。

## PHPStan 適合チェック

- [x] 戻り値の型が明示（`handle(): Response`）
- [x] null 安全（`$request->user()` は `User|AdminUser|null` の union → `instanceof User` で narrowing。
      `$request->route('passkey')` は `mixed` → `instanceof Passkey` で narrowing）
- [x] DTO を返している（`LoginMethodRequiredDto` + `LoginMethodRequiredResource`。`response()->json()` 直書きなし）
- [x] `DB::transaction()` の closure 戻り値型を `Response` で明示

## テスト計画

- [ ] 新規 `tests/Architecture/LoginMethodRemovalRouteTest.php`（上記）
- [ ] 新規 `tests/Feature/Auth/LoginMethodRetentionTest.php`（施策 4 の route 実在が前提 = S3）

  | 前提 | 期待 |
  |------|------|
  | password/social なし・passkey **1 件** | `DELETE /user/passkeys/{id}` が **422** + `settingsUrl` を含む |
  | password/social なし・passkey **2 件** | 1 件削除**できる**（204/redirect）、残 1 件 |
  | password あり・passkey 1 件 | 削除**できる** |
  | google 連携あり・passkey 1 件 | 削除**できる** |
  | TOTP confirmed・passkey 2 件 | passkey は手段に数えないので、password/social が無ければ **422** |
  | 削除対象が**他人の** passkey | inventory 評価より前に **404**（403 ではない） |
  | 非 Inertia / 非 JSON | `back()` + `withErrors('login_method')` |

- [ ] **直列化規約の検証**（`RefreshDatabase` はテストをトランザクションで包むため、
      2 接続での実レースは再現できない）。代わりに **SQL レベルで機構を固定**する:
  - `DB::listen()` で発行 SQL を収集し、`DELETE /user/passkeys/{id}` の処理中に
    - `users` に対する `for update` を含む `select` が発行されている
    - その `select` が `passkeys` の `delete` **より前**である
    - 両者が同一トランザクション内である（`DB::transactionLevel()` を listener で観測）
  - 拒否時（422）には `passkeys` の `delete` が **発行されていない**
  - この方針の限界（実レースは再現できない）をテストの docblock に明記する
- [ ] 個別 `DatabaseTransactions` を使っていないことを確認

## リスク

- **middleware 内でトランザクションを張る**ため、`$next()` が返す Responsable の変換や
  event listener がトランザクション内で走る。ロールバック時に session 側の副作用
  （`RecentAuthState::clear()`）だけが残りうるが、これは「再認証を余計に 1 回要求する」
  方向の誤差であり **fail-safe**。設計として受け入れ、テストで向きを固定する
- `removalFor()` が未知 route で `LogicException` を投げる（fail-closed）。
  middleware を付けたのに分岐を足し忘れると 500 になるが、
  `LoginMethodRemovalRouteTest` + Feature テストが CI で先に落とす
- 長いトランザクション保持による lock 競合。対象は自分自身の User 行 1 行のみで、
  passkey 削除は数 ms のため実運用上の影響は無視できる

---

# 施策 4: passkey feature 有効化と vendor アダプタ（T-β / S2+S3）

## 変更箇所

- `config/fortify.php` L118-121（`limiters`）、L140-171（`features`）
- `app/Providers/FortifyServiceProvider.php`（`RateLimiter::for('passkeys', ...)`）
- `bootstrap/providers.php`（`PasskeyServiceProvider` を **`FortifyServiceProvider` より後**に登録）
- `app/Models/User.php`（`PasskeyUser` 実装 + `PasskeyAuthenticatable` trait）
- `.env.example`（`PASSKEYS_*`）
- 新規: `app/Providers/PasskeyServiceProvider.php`
- 新規: `app/Models/Passkey.php`, `database/factories/PasskeyFactory.php`
- 新規: `app/Http/Responses/Passkey/{PasskeyLoginResponse,PasskeyConfirmationResponse,PasskeyRegistrationResponse,PasskeyDeletedResponse}.php`
- 新規 migration: `database/migrations/2026_08_05_000000_create_passkeys_table.php`（vendor から publish）

## 波及変更

- TypeScript 型定義: `resources/js/lib/shared-props.ts`（`auth.user` に `passkeyLoginAvailable` 相当を足すなら）
  → **S4 で扱う**。S3 では Inertia prop を増やさない
- API Resource/DTO: 新規 `app/DataTransferObjects/Auth/PasskeyOptionsDto` + `PasskeyOptionsResource`
- テストファイル: 新規 Architecture 3 本 + Feature 複数
- ドキュメント: `docs/architecture.md`（passkey 節）、`docs/factories.md`（`PasskeyFactory`）、
  `docs/supported-browsers.md`（passkey の保証範囲）、`docs/template-divergence.md`

## 現行コード

```php
// config/fortify.php
'limiters' => ['login' => 'login', 'two-factor' => 'two-factor'],   // passkeys 無し
'features' => [
    Features::registration(),
    // ...
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => false]),
],
```

## 変更後コード

### 4-a. config

```php
// config/fortify.php
'limiters' => [
    'login' => 'login',
    'two-factor' => 'two-factor',
    // passkey endpoint の絞り。未設定だと FortifyServiceProvider::passkeyThrottleMiddleware()
    // が null を返し、**未認証の GET /passkeys/login/options が無制限**になる
    // (毎回 random_bytes(32) + session 書き込みが走る)。
    'passkeys' => 'passkeys',
],

'features' => [
    // ...既存...
    Features::twoFactorAuthentication([...]),

    // パスキー (WebAuthn)。現場 PWA でパスワード入力を不要にする。
    // confirmPassword=false の理由は 2FA と同一 — 本アプリは Fortify 標準の
    // password.confirm (3h・パスワード限定) を撤去し generic recent-auth
    // (15 分窓・パスワード or 再SSO) へ統一済みで、残すと SSO-only ユーザーが詰む。
    // step-up は PasskeyServiceProvider が recent-auth を後付け配線する
    // (PasskeyRouteProtectionTest が CI 固定)。
    Features::passkeys(['confirmPassword' => false]),
],
```

```php
// app/Providers/FortifyServiceProvider.php::configureRateLimiters()
RateLimiter::for('passkeys', function (Request $request) {
    // 未認証の challenge 発行 (GET /passkeys/login/options) を含むため、
    // 認証済みは user 単位、未認証は IP 単位で絞る。
    $user = $request->user();

    return Limit::perMinute(10)->by(
        $user !== null ? 'passkey|'.$user->getAuthIdentifier() : 'passkey|'.$request->ip(),
    );
});
```

```dotenv
# .env.example (SSO ブロックの近くに追加)
# パスキー (WebAuthn)。未設定時は APP_URL から導出される。
# PASSKEYS_RELYING_PARTY_ID=example.com
# PASSKEYS_USER_HANDLE_SECRET=
```

> `passkeys.relying_party_id` / `allowed_origins` は Fortify が
> `fortify.passkeys.*` から上書きする。同一オリジン PWA 前提のため既定
> （`APP_URL` のホスト / `[APP_URL]`）で足りる。明示したい環境向けに
> `config/fortify.php` に `'passkeys' => ['relying_party_id' => env('PASSKEYS_RELYING_PARTY_ID')]`
> を置く場合は `.env.example` の該当行をコメント解除する。

### 4-b. モデル

```php
// app/Models/User.php （差分）
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;

class User extends Authenticatable implements
    CipherSweetEncrypted, LaratrustUser, MustVerifyEmail, OAuthenticatable, PasskeyUser
{
    use HasApiTokens;
    use HasFactory, HasRolesAndPermissions, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable, UsesCipherSweet;
```

> `PasskeyAuthenticatable` が `passkeys(): HasMany` / `hasPasskeysEnabled(): bool` /
> `getPasskeyUserHandle(): string` / `getPasskeyDisplayName(): string` /
> `getPasskeyUsername(): string` を供給する。`@phpstan-require-implements PasskeyUser` により
> interface 実装と対で PHPStan が検証する。
> `getPasskeyDisplayName()` / `getPasskeyUsername()` は CipherSweet 暗号化属性
> （`name` / `email`）を返すが、モデル経由の読み出しは透過的に復号されるため動作する。
> **これらは WebAuthn options に平文で載り認証器 UI に表示される**（仕様上不可避）。
> challenge を格納する session は `SESSION_ENCRYPT=true`（`EnvExampleInvariantTest` が固定）で保護される。

```php
// app/Models/Passkey.php
<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PasskeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Passkeys\Passkey as BasePasskey;

/**
 * vendor モデルの app サブクラス。
 *
 * 差し替える理由:
 *   1. Factory の置き場所 (AGENTS.md: テストデータは必ず Factory で生成 / 新規モデルは Factory 必須)
 *   2. アプリ側の型として route binding / DTO で扱えるようにする
 *
 * 差し替えは PasskeyServiceProvider::register() の Passkeys::usePasskeyModel() で行う。
 *
 * @use HasFactory<PasskeyFactory>
 */
final class Passkey extends BasePasskey
{
    /** @use HasFactory<PasskeyFactory> */
    use HasFactory;

    protected $table = 'passkeys';

    protected static function newFactory(): PasskeyFactory
    {
        return PasskeyFactory::new();
    }
}
```

```php
// database/factories/PasskeyFactory.php
/**
 * @extends Factory<Passkey>
 */
final class PasskeyFactory extends Factory
{
    protected $model = Passkey::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            // credential_id は base64url unpadded (VerifyPasskey が
            // Base64UrlSafe::encodeUnpadded で照合する)
            'credential_id' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
            // WebAuthn ceremony を伴わないテスト (削除 / 一覧 / 手段カウント) 用の最小形。
            // 実 ceremony を検証するテストは vendor の WebAuthn helper で生成すること。
            'credential' => ['type' => 'public-key'],
            'last_used_at' => null,
        ];
    }
}
```

migration は vendor から publish する（自前で書かない）:

```bash
php artisan vendor:publish --tag=passkeys-migrations
```

> `Laravel\Passkeys\Passkeys::migrationPath()` を Fortify も publish 対象にしている
> （`FortifyServiceProvider.php:210`）。生成される migration は
> `foreignIdFor(Passkeys::userModel(), 'user_id')->constrained()->cascadeOnDelete()` で
> `users` を参照する（アカウント削除時に passkey も消える = 望ましい）。
> **禁止事項 3 により `migrate:fresh` は実行しない**。`php artisan migrate` のみ。

### 4-c. `PasskeyServiceProvider`（vendor アダプタ）

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Responses\Passkey\PasskeyConfirmationResponse;
use App\Http\Responses\Passkey\PasskeyDeletedResponse;
use App\Http\Responses\Passkey\PasskeyLoginResponse;
use App\Http\Responses\Passkey\PasskeyRegistrationResponse;
use App\Models\Passkey;
use App\Models\User;
use App\Services\Auth\PasskeyLoginPolicy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
use Laravel\Passkeys\Contracts\PasskeyDeletedResponse as PasskeyDeletedResponseContract;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Laravel\Passkeys\Contracts\PasskeyRegistrationResponse as PasskeyRegistrationResponseContract;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkey as VendorPasskey;
use Laravel\Passkeys\Passkeys;

/**
 * laravel/passkeys (Fortify 1.37 が推移依存で持つ) のアプリ側アダプタ。
 *
 * route / controller / action / migration は Fortify + laravel/passkeys が提供する
 * (AGENTS.md 思考原則 1: フレームワークのレンジ内でやる)。本 Provider は
 * 「アプリ固有の不変条件を vendor に被せる」ことだけを担う:
 *
 *   1. **binder 差し替え**: vendor の binder はグローバルに id 解決し、controller が
 *      `abort_unless($passkey->user_id === $user->getKey(), 403)` で弾く。403 は
 *      **他人の passkey の存在を漏らす**。認証ユーザーの passkeys() 経由に張り替えて
 *      不整合を **認可より前に 404** にする (AGENTS.md セキュリティ不変条件 2)。
 *   2. **Response contract 上書き**: vendor 既定は `new JsonResponse(...)` を直に返し
 *      禁止事項 4 に触れる。Inertia / DTO+JsonResource へ差し替える。
 *      あわせて confirm 経路が書く `auth.password_confirmed_at` をここで除去する
 *      (RecentAuthState の契約「Fortify の鍵には書かない」を守る)。
 *   3. **vendor route 加工**: recent-auth / ensure-login-method の後付け配線。
 *   4. **login 認可**: TOTP 有効ユーザーの passkey login を拒否 (PasskeyLoginPolicy に委譲)。
 *
 * ⚠ **boot 順序**: Route::bind() は後勝ちのため、本 Provider は auto-discovery された
 * Laravel\Passkeys\PasskeysServiceProvider より **後** に boot する必要がある。
 * bootstrap/providers.php の末尾側 (FakeExternalsServiceProvider の直前) に置く。
 * この順序依存は PasskeyPackageContractTest が「binder の最終解決系がアプリ実装」で固定する。
 */
final class PasskeyServiceProvider extends ServiceProvider
{
    /** recent-auth を後付けする passkey route (credential 集合を触る管理経路) */
    private const RECENT_AUTH_ROUTE_NAMES = [
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    ];

    /** ログイン手段を減らす passkey route */
    private const LOGIN_METHOD_GUARD_ROUTE_NAMES = [
        'passkey.destroy',
    ];

    public function register(): void
    {
        Passkeys::usePasskeyModel(Passkey::class);

        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
        $this->app->singleton(PasskeyConfirmationResponseContract::class, PasskeyConfirmationResponse::class);
        $this->app->singleton(PasskeyRegistrationResponseContract::class, PasskeyRegistrationResponse::class);
        $this->app->singleton(PasskeyDeletedResponseContract::class, PasskeyDeletedResponse::class);
    }

    public function boot(): void
    {
        $this->rebindPasskeyRouteModel();
        $this->configureLoginAuthorization();
        $this->attachMiddlewareToPasskeyRoutes();
    }

    /**
     * {passkey} を「認証ユーザー所有の passkey」にスコープして解決する。
     * 他人の passkey / 不在 id はともに 404 (403 で存在を漏らさない)。
     */
    private function rebindPasskeyRouteModel(): void
    {
        Route::bind('passkey', static function (string $value): VendorPasskey {
            $user = request()->user();
            if (! $user instanceof User) {
                throw (new ModelNotFoundException)->setModel(Passkey::class, [$value]);
            }

            $passkey = $user->passkeys()->whereKey($value)->first();
            if (! $passkey instanceof VendorPasskey) {
                throw (new ModelNotFoundException)->setModel(Passkey::class, [$value]);
            }

            return $passkey;
        });
    }

    /**
     * passkey login の最終ゲート。判定は PasskeyLoginPolicy に集約する
     * (LoginMethodInventory と条件を二重定義しないため。closure にロジックを書かない)。
     */
    private function configureLoginAuthorization(): void
    {
        Passkeys::authorizeLoginUsing(static function (Request $request, PasskeyUser $user, VendorPasskey $passkey): bool {
            if (! $user instanceof User) {
                return false;   // fail-closed
            }

            return app(PasskeyLoginPolicy::class)->allowsPasskeyLogin($user);
        });
    }

    /**
     * Fortify が登録した passkey route へアプリ側 middleware を後付けする。
     * FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() と同じ作法
     * (route:cache 下でも CompiledRouteCollection の nameCache が同一 instance を返すため有効)。
     */
    private function attachMiddlewareToPasskeyRoutes(): void
    {
        $this->app->booted(static function (Application $app): void {
            $routes = $app->make(Router::class)->getRoutes();
            $routes->refreshNameLookups();

            foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
                self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
            }

            foreach (self::LOGIN_METHOD_GUARD_ROUTE_NAMES as $name) {
                self::appendMiddlewareIfMissing($routes, $name, 'ensure-login-method');
            }
        });
    }

    private static function appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void
    {
        $route = $routes->getByName($name);
        if ($route !== null && ! in_array($alias, $route->middleware(), true)) {
            $route->middleware($alias);
        }
    }
}
```

> **middleware の実行順**: `->middleware()` の append 順は `recent-auth` → `ensure-login-method`。
> 先に step-up を通し、その後に手段保持を検査する。
> `ensure-login-method` がトランザクションを開くため、recent-auth の redirect/409 は
> トランザクションの**外**で決まる（余計なロックを取らない）。

### 4-d. Response 上書き（4 契約）

```php
// app/Http/Responses/Passkey/PasskeyConfirmationResponse.php
/**
 * passkey による step-up 確認の応答。
 *
 * vendor の PasskeyConfirmationController::store() は
 * `$session->passwordConfirmed()` を呼び **Fortify の auth.password_confirmed_at を書く**。
 * 本アプリは RecentAuthState の契約で「Fortify の鍵には書かない (意味汚染・権限漏れ回避)」
 * としており、将来 password.confirm を使う route が生えると passkey confirm が
 * それを黙って満たす潜在的な権限漏れになる。
 *
 * controller 実行後・session 保存前である本メソッドで確実に除去する
 * (Response 差し替えがアプリ責務である理由がこの継ぎ目)。
 * 鮮度そのものは StampRecentAuthOnPasskeyVerified が recent_auth_at へ既に打っている。
 *
 * 応答契約は recent-auth.password (ConfirmRecentAuthController::confirmPassword) と揃える。
 */
final class PasskeyConfirmationResponse implements PasskeyConfirmationResponseContract
{
    public function toResponse($request): SymfonyResponse
    {
        $request->session()->forget('auth.password_confirmed_at');

        if ($request->hasHeader('X-Inertia')) {
            $redirect = redirect()->intended(route('dashboard'));
            if ($request->session()->pull('recent_auth.dropped_mutation') === true) {
                $redirect->with('info', '再認証が完了しました。先ほどの操作はまだ実行されていません。お手数ですがもう一度操作してください。');
            }

            return $redirect;
        }

        return response()->noContent()->withHeaders(['Cache-Control' => 'no-store, private']);
    }
}
```

```php
// app/Http/Responses/Passkey/PasskeyRegistrationResponse.php
/**
 * passkey 登録完了。操作系 POST のため redirect()->intended() は使わない (禁止事項 7)。
 */
final class PasskeyRegistrationResponse implements PasskeyRegistrationResponseContract
{
    private ?Passkey $passkey = null;

    public function withPasskey($passkey): static { $this->passkey = $passkey; return $this; }

    public function toResponse($request): SymfonyResponse
    {
        return back()->with('success', 'パスキーを登録しました。');
    }
}

// app/Http/Responses/Passkey/PasskeyDeletedResponse.php
final class PasskeyDeletedResponse implements PasskeyDeletedResponseContract
{
    public function toResponse($request): SymfonyResponse
    {
        return back()->with('success', 'パスキーを削除しました。');
    }
}

// app/Http/Responses/Passkey/PasskeyLoginResponse.php
/**
 * passkey ログイン完了。**ログイン直後フロー**のため intended() が許される数少ない経路
 * (禁止事項 7 の例外条件)。Fortify 標準ログイン (App\Http\Responses\Fortify\LoginResponse)
 * と着地を揃えること。
 */
final class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    public function toResponse($request): SymfonyResponse
    {
        return redirect()->intended(config()->string('fortify.home'));
    }
}
```

> `options` 系 3 endpoint（`passkey.login-options` / `passkey.confirm-options` /
> `passkey.registration-options`）は vendor controller が `response()->json()` で返す
> **vendor コード**であり、アプリの禁止事項 4 の対象外（差し替え contract が無い）。
> ただし challenge + PII（email）を載せるため `no-store` を付ける必要がある。
> `NoStoreCacheHeadersForAuthenticatedPages` が認証済み応答に baseline を張るが、
> **`passkey.login-options` は guest route** なので対象外。
> → `PasskeyServiceProvider` から `passkey.login-options` に軽量 middleware
> （`no-store` 付与）を後付けし、`PasskeyRouteProtectionTest` で固定する。

## PHPStan 適合チェック

- [x] 戻り値の型が明示（binder closure は `VendorPasskey`、Response は `SymfonyResponse`）
- [x] null 安全（`request()->user()` は union → `instanceof User`、
      `$user->passkeys()->whereKey()->first()` は `?Model` → `instanceof VendorPasskey`）
- [x] DTO を返している（Response は redirect / noContent。`response()->json()` 直書きなし）
- [x] Generics（`HasFactory<PasskeyFactory>` / `Factory<Passkey>` / `HasMany<Passkey, User>`）
- [x] `Passkeys::$passkeyModel` は `class-string<Laravel\Passkeys\Passkey>` なので
      `App\Models\Passkey`（サブクラス）は共変で通る

## テスト計画

- [ ] 新規 `tests/Architecture/PasskeyPackageContractTest.php`
  - `Passkeys::shouldRegisterRoutes()` が **false**（パッケージ側 routes は登録されない）
  - passkey named route 7 本が全て実在し、`action` が `Laravel\Passkeys\Http\Controllers\*`
  - `config('fortify-options.passkeys.confirmPassword')` が **false**
  - **`config()->all()` に `fortify-options.passkeys` キーが存在**
    （`Features::passkeys([...])` の副作用が `config:cache` の serialize に取り込まれる証明）
  - `Passkeys::passkeyModel()` === `App\Models\Passkey`、`Passkeys::userModel()` === `App\Models\User`
  - `App\Models\User` が `PasskeyUser` を実装している
  - `Passkeys::authorizeLoginUsing` に closure が登録済み（`Passkeys::allowsLogin()` が
    TOTP 有無で結果を変えることで間接検証）
- [ ] 新規 `tests/Architecture/PasskeyRouteProtectionTest.php`（route × middleware の列挙固定）

  | route | 必須 middleware |
  |-------|----------------|
  | `passkey.login-options` | `web`, `guest:web`, `throttle:passkeys`, `no-store` |
  | `passkey.login` | `web`, `guest:web`, `throttle:passkeys` |
  | `passkey.confirm-options` | `web`, `auth:web`, `throttle:passkeys` |
  | `passkey.confirm` | `web`, `auth:web`, `throttle:passkeys` |
  | `passkey.registration-options` | `web`, `auth:web`, `throttle:passkeys`, `recent-auth` |
  | `passkey.store` | `web`, `auth:web`, `throttle:passkeys`, `recent-auth` |
  | `passkey.destroy` | `web`, `auth:web`, `recent-auth`, `ensure-login-method` |

  - **`password.confirm` がどの passkey route にも付いていない**こと
- [ ] 新規 `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php`
  - 全 route を走査し `password.confirm` middleware を持つものが **0 本**（deny-by-default）
  - `$checked > 0`（route 走査自体が空振りしていないこと）
- [ ] 新規 `tests/Feature/Auth/PasskeyRouteAccessTest.php`
  - 未認証で `/user/passkeys/options` → login へ redirect
  - 認証済みだが recent-auth stale で `POST /user/passkeys` → 409（Inertia mutation）
  - **他人の passkey を DELETE → 404**（403 ではない。`PasskeyFactory` で別 User の passkey を作る）
  - **不在 id を DELETE → 404**（他人と同一応答であること = 存在を漏らさない）
  - `GET /passkeys/login/options` の応答が `Cache-Control: no-store` を持つ
  - throttle: `passkeys` limiter 超過で 429
- [ ] 新規 `tests/Feature/Auth/PasskeyTwoFactorInteractionTest.php`
  - TOTP confirmed ユーザーは `Passkeys::allowsLogin()` が false
  - TOTP 無効ユーザーは true
  - 不変条件: **TOTP confirmed ユーザーは `remainingAfter(allPasskeys())` が空でない**
  - passkey ログインしたユーザーが 2FA 強制組織に属する場合、
    `RequireTwoFactorForEnforcedOrganizations` のゲートに掛かる（= 2FA 準拠に算入しない）
- [ ] `docs/factories.md` に `PasskeyFactory` を追記（AGENTS.md 実装規約）
- [ ] 個別 `DatabaseTransactions` を使っていないことを確認

## リスク

- **boot 順序**が壊れると binder が vendor 実装のまま残り 403 情報漏れが復活する
  → `PasskeyPackageContractTest` の「他人の passkey → 404」で検出
- `Features::passkeys()` の追加で `Features::hasProfileFeatures()` /
  `hasSecurityFeatures()` の戻り値が変わる。本アプリはこれらを使っていない
  （`grep` で確認すること）が、Fortify の view 側で参照されうるため実装時に再確認
- vendor の `PasskeyRegistrationController::destroy()` は
  `abort_unless($passkey->user_id === $user->getKey(), 403)` を**strict `===`** で行う。
  binder を自 User スコープにしたので到達しないが、`users.id` が `bigint`（両辺 `int`）で
  一致することを前提とする。`VerifyPasskey::ensurePasskeyBelongsToUser()` は既に
  `(string)` 正規化済み（spirux:T1108 施策 3 が upstream 済み）
- WebAuthn ceremony を伴う Feature テストは vendor の helper に依存する。
  ceremony 不要な経路（削除 / 一覧 / 手段カウント / 認可）を優先して固定する

---

# 施策 5: recent-auth 配線（裁定 A）（T-β / S3）

## 変更箇所

- 新規: `app/Listeners/Auth/ClearRecentAuthOnPasskeyChange.php`
- 新規: `app/Listeners/Auth/StampRecentAuthOnPasskeyVerified.php`
- `app/Providers/AppServiceProvider.php`（`Event::listen` 2 本）
- `app/Listeners/Auth/StampRecentAuthOnLogin.php`（docblock の前提リストを更新）
- `tests/Architecture/RecentAuthRouteTest.php`（allowlist + satisfier inventory）

## 波及変更

- TypeScript 型定義: **なし**
- API Resource/DTO: **なし**
- テストファイル: 上記 Architecture + 新規 Feature

## 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Security\RecentAuthState;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/**
 * credential 集合の変化 = recent-auth 失効（2026-08-04 裁定 A）。
 *
 * パスキーは単独でログインできる強い資格であり、集合が変わったら
 * 直前に済ませた本人確認は失効させる、という家系統一原則
 * (統一原則のほうが複数年の保守で分類漏れ事故を生みにくく、UX の実害は
 *  登録直後のタップ 1 回程度、という Codex 判定 A に基づくオーナー裁定)。
 *
 * **強化オプション（新規登録直後のパスキーを即 re-step-up の satisfier に使えなくする）は
 * 裁定で明示的に見送られている。実装しないこと。**
 * 再検討条件: パスキーが 2FA 準拠判定に算入される時、または放置端末起点の実被害が観測された時。
 *
 * 本 listener は RecentAuthState::clear() の初の production 利用者である
 * (docblock は「認証要素変更後に失効させる」と宣言していたが呼び出し元が無かった)。
 *
 * ⚠ EnsureLoginMethodRemains がトランザクション内で $next() を実行するため、
 * PasskeyDeleted はそのトランザクション内で発火する。ロールバック時には
 * session 側の clear() だけが残りうるが、これは「再認証を余計に 1 回要求する」
 * 方向の誤差であり fail-safe。
 */
final class ClearRecentAuthOnPasskeyChange
{
    public function __construct(
        private readonly RecentAuthState $recentAuthState,
    ) {}

    public function handleRegistered(PasskeyRegistered $event): void
    {
        $this->recentAuthState->clear();
    }

    public function handleDeleted(PasskeyDeleted $event): void
    {
        $this->recentAuthState->clear();
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Listeners\Auth;

use App\Security\RecentAuthState;
use Laravel\Passkeys\Events\PasskeyVerified;

/**
 * passkey 検証成立を recent-auth の satisfier として stamp する。
 *
 * ⚠ PasskeyVerified は VerifyPasskey::__invoke() の**中**で dispatch されるため、
 * **login 経路と confirm 経路の両方**で発火する。経路ごとの最終 session state:
 *
 *   | 経路                  | 発火順                        | 最終 recent_auth_method |
 *   |-----------------------|-------------------------------|-------------------------|
 *   | passkey login         | PasskeyVerified → Login        | 'login' (後勝ち)        |
 *   | passkey confirm       | PasskeyVerified のみ           | 'passkey'               |
 *   | passkey 登録 / 削除   | PasskeyRegistered / Deleted    | 未設定 (clear 済み)     |
 *
 * login 経路では StampRecentAuthOnLogin が後勝ちで 'login' を書く。最終状態は決定的だが、
 * 順序に依存するため RecentAuthMethodStampingTest が経路別に固定する。
 */
final class StampRecentAuthOnPasskeyVerified
{
    public function __construct(
        private readonly RecentAuthState $recentAuthState,
    ) {}

    public function handle(PasskeyVerified $event): void
    {
        $this->recentAuthState->confirm(method: 'passkey');
    }
}
```

```php
// app/Providers/AppServiceProvider.php::boot() （既存 Event::listen 群の隣に追加）
Event::listen(Login::class, StampRecentAuthOnLogin::class);
// passkey 検証成立 = recent-auth satisfier (confirm 経路。login 経路では Login が後勝ち)
Event::listen(PasskeyVerified::class, StampRecentAuthOnPasskeyVerified::class);
// credential 集合の変化 = recent-auth 失効 (2026-08-04 裁定 A)
Event::listen(PasskeyRegistered::class, [ClearRecentAuthOnPasskeyChange::class, 'handleRegistered']);
Event::listen(PasskeyDeleted::class, [ClearRecentAuthOnPasskeyChange::class, 'handleDeleted']);
```

> `AppServiceProvider::register()` で `EventServiceProvider::disableEventDiscovery()` 済みのため、
> **明示配線が必須**（auto-discovery には乗らない）。

```php
// app/Listeners/Auth/StampRecentAuthOnLogin.php の docblock 更新（差分）
 * ⚠ 重要: 本 listener は「web guard の Login が全て credential-presentation である」前提に立つ。
-*   現行コードの web guard login は (1) Fortify password (2) Fortify TOTP (3) SSO
-*   Auth::login() の 3 種のみ。
+*   現行コードの web guard login は (1) Fortify password (2) Fortify TOTP (3) SSO
+*   Auth::login() (4) passkey (PasskeyLoginController::store の $guard->login()) の 4 種のみ。
+*   (4) は WebAuthn の user verification (生体 / PIN) を伴うため credential-presentation
+*   として fresh 扱いしてよい。
```

### `RecentAuthRouteTest` の更新（allowlist + satisfier inventory）

```php
// tests/Architecture/RecentAuthRouteTest.php （追記部）

/**
 * @return list<string>
 */
function recentAuthRequiredRouteNames(): array
{
    return [
        // ...既存...
        'user-profile-information.update',
        // passkey 管理 (credential 集合を増減させる経路。配線は
        // PasskeyServiceProvider::attachMiddlewareToPasskeyRoutes())
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    ];
}

/*
 * satisfier 集合の inventory (deny-by-default)。
 *
 * RecentAuthState::confirm() は「鮮度が成立した」と宣言する唯一の writer であり、
 * 呼び出し元が増えることは step-up の成立条件が増えることそのものである。
 * 未登録の呼び出し元が生えたら fail させ、PR review で必ず判断させる。
 *
 * @return list<string> RecentAuthState::confirm() を呼んでよいクラスの FQCN
 */
function recentAuthSatisfierClasses(): array
{
    return [
        // password 再入力
        App\Http\Controllers\Auth\ConfirmRecentAuthController::class,
        // 再SSO (step-up intent。本人性バインド済み)
        App\Http\Controllers\Auth\SocialAuthController::class,
        // fresh credential login (web guard・非 recaller)
        App\Listeners\Auth\StampRecentAuthOnLogin::class,
        // passkey 検証成立 (confirm 経路 / login 経路の両方で発火)
        App\Listeners\Auth\StampRecentAuthOnPasskeyVerified::class,
    ];
}

test('RecentAuthState::confirm の呼び出し元は inventory に登録されたクラスのみ', function (): void {
    $allowed = recentAuthSatisfierClasses();
    $violations = [];
    $checked = 0;

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if (! is_string($contents) || ! str_contains($contents, '->confirm(')) {
            continue;
        }
        // RecentAuthState を参照していない ->confirm( は対象外 (別ドメインの confirm)
        if (! str_contains($contents, 'RecentAuthState')) {
            continue;
        }
        $checked++;

        $fqcn = fqcnFromFile($file->getPathname());   // namespace + class 名から復元
        if ($fqcn === null || ! in_array($fqcn, $allowed, true)) {
            $violations[] = "{$file->getPathname()} が RecentAuthState::confirm() を呼んでいるが satisfier inventory に未登録";
        }
    }

    expect($violations)->toBe([]);
    // RecentAuthState 自身を除いた呼び出し元が 0 = 走査が壊れている
    expect($checked)->toBeGreaterThan(0);
});
```

## PHPStan 適合チェック

- [x] 戻り値の型が明示（`handle*(): void`）
- [x] null 安全（listener は event オブジェクトのみ扱い、null 参照なし）
- [x] DTO: 該当なし（副作用のみ）
- [x] Generics: 該当なし

## テスト計画

- [ ] 新規 `tests/Feature/Auth/RecentAuthMethodStampingTest.php`（経路別の最終 session state）
  - password 再入力 → `recent_auth_method === 'password'`
  - 再SSO → `'sso'` + `recent_auth_provider === 'google'`
  - 通常ログイン → `'login'`
  - **passkey confirm** → `'passkey'`
  - **passkey login** → `'login'`（`PasskeyVerified` の後に `Login` が後勝ち）
  - **passkey confirm 後に `auth.password_confirmed_at` が存在しない**（施策 4 の Response 除去）
- [ ] 新規 `tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php`（裁定 A）
  - recent-auth 成立済み → passkey **登録** → `recent_auth_at` が消える
  - recent-auth 成立済み → passkey **削除** → `recent_auth_at` が消える
  - 直後に機微操作（`settings.account.destroy`）を試みると step-up を要求される
  - **登録直後 passkey の satisfier 除外は実装されていない**ことの明示
    （登録直後の passkey で confirm すると鮮度が成立する = 裁定どおり）
- [ ] `tests/Architecture/RecentAuthRouteTest.php` の 2 テスト（allowlist / satisfier inventory）
- [ ] 個別 `DatabaseTransactions` を使っていないことを確認

## リスク

- **`PasskeyRegistered` が recent-auth を失効させるため、passkey を登録した直後に
  もう 1 つ登録しようとすると再度 step-up を求められる**。これは裁定 A が
  「UX の実害は登録直後のタップ 1 回程度」として受け入れた仕様。
  UI 側で「セキュリティのため再認証が必要です」を明示し無言のリダイレクトにしない
- satisfier inventory テストは静的走査であり、動的呼び出し（`app(RecentAuthState::class)->confirm()`）を
  文字列一致で拾う。false negative（別名変数経由）は残るが、
  「新しい satisfier を足すときに必ず考えさせる」という目的には十分

---

# 施策 6: フロント（passkeys.ts + UI）（T-β / S4）

## 変更箇所

- 新規: `resources/js/lib/passkeys.ts`
- `resources/js/pages/Settings/Security.svelte`（passkey カード追加）
- `resources/js/pages/Auth/Login.svelte`（passkey ログインボタン追加）
- `routes/web.php` L188-200（`settings.security` の Inertia prop に passkey 一覧を追加）
- 新規: `tests/js/architecture/passkeys-import-isolation.test.ts`

## 波及変更

- **TypeScript 型定義**: `Settings/Security.svelte` の `Props` に
  `passkeys: PasskeyListItem[]` / `passkeyLoginAvailable: boolean` を追加
- **API Resource/DTO**: 新規 `app/DataTransferObjects/Auth/PasskeyListItemDto` +
  `app/Http/Resources/Auth/PasskeyListItemResource`
  （Inertia prop 用。`response()->json()` 直書きを避ける = 禁止事項 4）
- **テストファイル**: `tests/js/pages/Settings/Security.test.ts`（既存があれば更新）、
  新規 `tests/js/lib/passkeys.test.ts`、新規 `tests/js/architecture/passkeys-import-isolation.test.ts`

## 変更後コード

### 6-a. `resources/js/lib/passkeys.ts`

```ts
/**
 * WebAuthn (passkey) ceremony の薄いラッパ。
 *
 * サーバとの JSON 契約は laravel/passkeys が定義する
 * ({ options } を受け取り、credential を JSON で返す)。
 *
 * **feature detection を必ず経由すること**。現場 PWA が主戦場であり、
 * 非対応端末 / 生体未設定端末は常態である (docs/supported-browsers.md)。
 *
 * eslint の noInlineConfig: true (T102) により inline eslint-disable は使えない。
 * base64url 変換は型安全に書き、any / ts-ignore を持ち込まないこと。
 */

/** この端末で passkey ceremony を開始できるか (API の存在確認) */
export function isPasskeySupported(): boolean {
    return typeof window !== "undefined" && typeof window.PublicKeyCredential === "function";
}

/** この端末で passkey を **作成** できるか (プラットフォーム認証器 + user verification) */
export async function canCreatePasskey(): Promise<boolean> {
    if (!isPasskeySupported()) return false;
    try {
        return await window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
    } catch {
        return false;
    }
}

/** ceremony の結果種別。キャンセル/タイムアウトはエラーとして騒がない */
export type PasskeyOutcome<T> =
    | { status: "ok"; value: T }
    | { status: "cancelled" }
    | { status: "unsupported" }
    | { status: "failed"; message: string };

function base64UrlToBuffer(value: string): ArrayBuffer { /* ... */ }
function bufferToBase64Url(value: ArrayBuffer): string { /* ... */ }

/** 登録 ceremony (GET options → navigator.credentials.create → POST) */
export async function registerPasskey(name: string): Promise<PasskeyOutcome<void>> { /* ... */ }

/** ログイン ceremony (GET options → navigator.credentials.get → POST) */
export async function loginWithPasskey(): Promise<PasskeyOutcome<{ redirect: string }>> { /* ... */ }

/** step-up 確認 ceremony (GET confirm-options → navigator.credentials.get → POST confirm) */
export async function confirmWithPasskey(): Promise<PasskeyOutcome<void>> { /* ... */ }
```

`NotAllowedError`（ユーザーキャンセル / タイムアウト）は `{ status: "cancelled" }` に畳み、
呼び出し側は toast を出さず再試行導線を残す。

### 6-b. Inertia prop

```php
// routes/web.php の settings.security
Route::get('/settings/security', function (LoginMethodInventory $inventory, PasskeyLoginPolicy $passkeyPolicy) {
    $user = request()->user();
    // ...既存の linkedProviders...

    return Inertia::render('Settings/Security', [
        'socialProviders' => array_keys(config()->array('template.social_providers')),
        'linkedProviders' => $linkedProviders,
        // passkey 一覧 (DTO + JsonResource 経由。禁止事項 4)
        'passkeys' => $user instanceof User
            ? PasskeyListItemResource::collection(
                $user->passkeys()->orderByDesc('created_at')->get()
                    ->map(fn (Passkey $p) => new PasskeyListItemDto(
                        id: (int) $p->getKey(),
                        name: $p->name,
                        authenticator: $p->authenticator,
                        lastUsedAt: $p->last_used_at?->toIso8601String(),
                        createdAt: $p->created_at?->toIso8601String(),
                    )),
            )
            : [],
        // TOTP 有効ユーザーには「ログインには使えないが再認証には使える」旨を出す
        'passkeyLoginAvailable' => $user instanceof User && $passkeyPolicy->allowsPasskeyLogin($user),
    ]);
})->name('settings.security');
```

> **注意**: `settings.security` の closure は既に肥大している。実装時に
> `App\Http\Controllers\Settings\SecurityController` へ抽出することを推奨する
> （AGENTS.md「Controller は薄く(Service 委譲)」。route closure に DI を積み増さない）。

### 6-c. `Settings/Security.svelte`（passkey カード）

既存の 2FA カード / ソーシャル連携カードと同じ構成で 3 枚目を追加する。
**新規 atom は作らない**（`Card` / `Button` / `Badge` / `Alert` / `FormField` / `Input` /
`ConfirmDialog` の組合せ）。アイコンは `@lucide/svelte` のみ（`KeyRound` 等）。
SVG 直書きは `svg-inline-allowlist.test.ts` が禁止する。

```svelte
<Card padding="lg">
    <div class="flex items-center justify-between gap-4">
        <h2 class="text-h3">パスキー</h2>
        {#if passkeys.length > 0}
            <Badge tone="success">{passkeys.length} 件登録済み</Badge>
        {:else}
            <Badge tone="neutral">未登録</Badge>
        {/if}
    </div>
    <p class="mt-1 text-caption text-text-secondary">
        指紋・顔認証・端末のロック解除でログインできます。
    </p>

    {#if !passkeyLoginAvailable && twoFactorEnabled}
        <!-- 誤認させない: 2FA 有効時はログインには使えないが再認証には使える -->
        <Alert type="info" testId="passkey-2fa-notice">
            2要素認証を有効にしているため、パスキーでのログインはできません。
            この画面での再認証にはご利用いただけます。
        </Alert>
    {/if}

    {#if !passkeySupported}
        <Alert type="warning" testId="passkey-unsupported">
            このブラウザはパスキーに対応していません。パスワードまたはソーシャルログインをご利用ください。
        </Alert>
    {:else if !passkeyCreatable}
        <Alert type="warning" testId="passkey-not-creatable">
            この端末ではパスキーを作成できません。画面ロック（生体認証・PIN）を設定すると利用できます。
        </Alert>
    {/if}

    <!-- 一覧 + 削除。ボタンは条件未充足でも disabled にしない (禁止事項 8) -->
    <!-- 登録・削除は recent-auth 必須 → 既存 guardWithRecentAuth に載せる -->
</Card>
```

- **登録 / 削除は `guardWithRecentAuth()` を通す**（既存 2FA と完全に同じ契約）
- 削除は `ConfirmDialog` を挟む
- **422（`ensure-login-method` の拒否）を専用に扱う**: 「これが最後のログイン手段です」の
  文言と `settingsUrl` への導線を出す（無言失敗にしない）
- passkey 登録直後は recent-auth が失効する（裁定 A）ため、
  「セキュリティのため再認証が必要です」を明示してから再認証モーダルを開く

### 6-d. `Auth/Login.svelte`

- `isPasskeySupported()` が true のときのみ「パスキーでログイン」ボタンを出す
- ボタンの下に「2要素認証を有効にしているアカウントではパスキーでログインできません」を添える
- 拒否時（`PasskeyLoginPolicy` による deny）は同画面にエラーを出し、
  パスワード欄と SSO ボタンをそのまま残す（回復導線を消さない）

## PHPStan / TypeScript 適合チェック

- [x] `PasskeyListItemDto` は readonly promoted properties、`PasskeyListItemResource` は `$wrap = null`
- [x] `Passkey::$authenticator` は `?string`（vendor の `$appends`）→ TS 側も `string | null`
- [x] `resources/js` は TypeScript のみ（AGENTS.md 禁止事項 7）
- [x] `any` / `@ts-ignore` を使わない（`noInlineConfig` により抑制コメントも使えない）

## テスト計画

- [ ] 新規 `tests/js/lib/passkeys.test.ts`
  - `isPasskeySupported()` が `window.PublicKeyCredential` 不在で false
  - `canCreatePasskey()` が `isUserVerifyingPlatformAuthenticatorAvailable` の
    reject / false で false（例外を投げない）
  - `NotAllowedError` が `{ status: "cancelled" }` に畳まれる
  - base64url 変換の往復（`bufferToBase64Url(base64UrlToBuffer(x)) === x`）
- [ ] 新規 `tests/js/architecture/passkeys-import-isolation.test.ts`
  - `@/lib/passkeys` を import してよいのは
    `pages/Settings/Security.svelte` / `pages/Auth/Login.svelte` /
    `components/organisms/RecentAuthModal.svelte` のみ（deny-by-default）
  - 既存 `tests/js/architecture/atomic-import-graph.test.ts` と同じ作法
- [ ] `tests/js/pages/Settings/Security.test.ts`
  - 非対応環境で `passkey-unsupported` Alert が出る
  - 2FA 有効時に `passkey-2fa-notice` が出る
  - **ボタンが disabled になっていない**（禁止事項 8）
- [ ] `pnpm typecheck` / `pnpm lint` / `pnpm test` / `pnpm build` が green
- [ ] `docs/supported-browsers.md` に passkey の保証範囲と非対応時のフォールバックを追記

## リスク

- WebAuthn は jsdom でエミュレートできない。`tests/js` では**ラッパの分岐だけ**を検証し、
  実 ceremony は browser テスト（Chromium + WebKit の 2 レーン契約）でも
  仮想認証器が必要なため **v1 では自動化しない**。手動確認項目として
  `docs/supported-browsers.md` の実機受入確認に追加する
- `Settings/Security.svelte` が肥大する。passkey セクションを
  `components/features/auth/PasskeySection.svelte` へ切り出すことを検討
  （`atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import に従う）

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone**（T-α / T-β とも） |
| 判断根拠 | 認証の中核（`SocialAccountService` / `User` / recent-auth 契約 / Fortify config）に触れ、`RecentAuthRouteTest` という既存の CI 契約を書き換える。並走中の他 TODO と `config/fortify.php`・`app/Models/User.php`・`bootstrap/app.php`・`AppServiceProvider` で衝突しやすい。worktree で独立させ、テスト全 green を確認してから main へ入れる |
| 競合リスク | `AppServiceProvider::boot()` の `Event::listen` 群、`bootstrap/app.php` の alias 配列、`bootstrap/providers.php` は他施策も触りやすい。T-α → T-β の順に直列実行し、T-β 着手前に main を rebase すること |

### TODO 分割の最終形

| TODO | 台帳 | 内容 | 独立性 / ロールバック |
|------|------|------|---------------------|
| **T-α** | `auth-sso-social` | 施策 1 | 完全独立。google の挙動不変（`email_verified_at` は従来どおり付く）のためデータ影響ゼロ。単独 revert 可 |
| **T-β** | `auth-login-method-retention` + `auth-passkey` | 施策 2〜6 | T-α と独立。内部は S1→S2→S3→S4 の順（S3 で feature 有効化と guard 群を **1 コミット**に束ね、「guard 無しで feature が on のコミット」を歴史上作らない）。ロールバックは `config/fortify.php` の `Features::passkeys()` 1 行が実質的なキルスイッチ（`LoginMethodInventory` も `PasskeyLoginPolicy` 経由で feature flag に連動する） |

**なぜ台帳 3 件を 2 TODO にしたか**: `EnsureLoginMethodRemains` は
「保護対象となる除去 route」が存在しないと死んだコードになる。現在の aicue には
SSO 解除 route も passkey 削除 route も無く、`passkey.destroy` が**最初の被保護 route** である。
台帳 `auth-login-method-retention` の boundary も
「EnsureLoginMethodRemains middleware **とその適用**（…passkey 削除経路）」と
適用まで含めて 1 単位と定義している。分けると AGENTS.md 思考原則 2
（今必要なものだけ作る）と禁止事項 1（テストなしの実装完了）の両方に触れる。

---

## 詳細設計フェーズでの持ち越し確認事項（概念設計レビューからの申し送り）

1. `$next()` が返す Responsable の変換時点 / `PasskeyDeleted` / `RecentAuthState::clear()` /
   session 保存の**実測順序** → 施策 3・5 のリスク節に方針を記載。実装時に
   `DB::listen` + session assertion で確認する
2. **独立 connection による並行削除テスト** → `RefreshDatabase` がテストを
   トランザクションで包むため実レースは再現不可。SQL レベル（`for update` の発行と順序）で
   機構を固定する方針に置き換え、限界をテスト docblock に明記する（施策 3 テスト計画）
3. **DTO の不正状態排除** → `LoginMethodRemoval` を private constructor +
   名前付き static factory + `Assert` で構成（施策 2）
4. **config / route cache 状態での最終契約確認** → `config()->all()` に
   `fortify-options.passkeys` が含まれることを `PasskeyPackageContractTest` で検査。
   route cache については `Route::bind()` の closure が serialize されないこと、
   `$app->booted()` がキャッシュ済み collection に対して走ることを設計根拠として記載
   （既存 `attachRecentAuthToSensitiveRoutes()` の docblock が同一機構の稼働実績）

---

## c2c 台帳へ差し戻す論点

概念設計 §8 のとおり。要点のみ再掲:

1. **AG-新: passkey login と「ユーザー自身が有効化した TOTP」の関係** — vendor の
   `PasskeyLoginController::store()` が Fortify の 2FA challenge を通らない。
   aicue は `PasskeyLoginPolicy` で fail-closed 既定（TOTP 有効なら passkey login 拒否）を置くが、
   家系横断の裁定が要る
2. **事実訂正**: `auth-passkey` の aicue note「composer に laravel/passkeys なし」は
   fortify 1.37 移行前の実査。現在は推移依存で `v0.2.1` が入っている
3. **t0 再定義の可能性**: Fortify 1.37 が passkey を第一級機能として同梱したため、
   「`PasskeyServiceProvider` を自前で持つ」形は 1.37 以降では「vendor アダプタ」に縮退する
