# 詳細設計レビュー Round 2

Round 1 の Critical 3 件・Warning 8 件すべてに対応しました。最重要修正 5 点はすべて反映済みです。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

## [Critical] 施策 4: `Route::bind('passkey')` の boot 順序が保証されていない

- 判断: **対応する**
- 根拠: 正しい。`bootstrap/providers.php` の順序は app provider 間の順序であり、
  auto-discovery された package provider (`PasskeysServiceProvider`) との最終 boot 順序を
  設計根拠にするのは危うい。
- 対応内容: binder 差し替えも `$this->app->booted(...)` の中で実行する
  (route middleware 後付けと同じ「全 provider boot 後に最終上書き」の形へ統一)。
  `bootstrap/providers.php` の配置は残すが、**正しさの根拠を `booted()` に移す**。
  Feature テスト「他人の passkey DELETE が 404」に加え、
  router の binder を直接叩く小テスト (`Route::getBindingCallback('passkey')` 経由) を追加。

## [Critical] 施策 4/6: Response contract とフロント transport の契約が未確定

- 判断: **対応する (最重要)**
- 根拠: 正しい。`back()` を返す Response と fetch wrapper が噛み合っていない。
  成功判定・Inertia props 更新・409/422 の扱いが崩れる。
- 対応内容: **operation 単位の transport 契約表**を新設し、Response 実装をそれに合わせる。
  既存アプリの作法に合わせて決定する:

  | operation | options 取得 | 送信 | 応答 |
  |-----------|-------------|------|------|
  | 登録 | `fetch GET /user/passkeys/options` (JSON) | **Inertia `router.post`** | `back()->with('success')` |
  | 削除 | — | **Inertia `router.delete`** | `back()->with('success')` |
  | step-up confirm | `fetch GET /passkeys/confirm/options` | **`fetch POST`** | **204 + no-store** (`recent-auth.password` と同契約) |
  | ログイン (guest) | `fetch GET /passkeys/login/options` | **`fetch POST`** | JSON `{redirect}` (DTO+Resource) |

  根拠: 登録/削除は passkey 一覧 (Inertia prop) を更新する必要があり、
  既存 `Settings/Security.svelte` の 2FA が `router.post` / `router.delete` +
  `back()` flash で統一されている。confirm は既存 `RecentAuthModal` が
  `fetch` + 204 契約なので合わせる。options 取得は既存
  `/user/two-factor-qr-code` の fetch パターンと同一。

- **あわせて `EnsureLoginMethodRemains::reject()` の応答も修正する**:
  Inertia mutation に 422 JSON を返すと Inertia protocol 違反になる。
  `expectsJson()` (非 Inertia XHR) のみ 422 JSON、
  それ以外 (Inertia 含む) は `back()->withErrors(['login_method' => ...])` にする。
  Inertia は 302 + errors を native に処理し、Svelte 側は `$page.props.errors` で読める。
  禁止事項 7 (操作系 POST は `back()->with(...)` で完結) とも整合する。
  判別子として `expectsJson()` が使えるのは、Inertia が
  `Accept: text/html, application/xhtml+xml` を送るため。

## [Critical] 施策 3: middleware 内 transaction の適用範囲が広がるリスク

- 判断: **対応する**
- 根拠: 正しい。`$next()` を transaction に入れると controller / 同期 listener /
  Responsable 変換 / flash まで含まれる。現状 `passkey.destroy` 1 本なら成立するが、
  将来この middleware が別 route に付くと副作用範囲が急拡大する。
- 対応内容:
  1. `LoginMethodRemovalRouteTest` に
     「**`ensure-login-method` を付けてよいのは guarded allowlist の route のみ**」を追加
     (未知 route への付与を deny-by-default で fail させる。現状は付与の**有無**しか見ていない)
  2. middleware の docblock に適用条件を明記:
     「streamed response / 外部 I/O (HTTP・S3) / `afterCommit` でない queue dispatch を
     含む route には付けない。付ける場合は本 middleware の transaction 方式を再設計すること」

## [Warning] 施策 3: middleware の実行順 (`recent-auth` → `ensure-login-method`)

- 判断: **対応する**
- 根拠: 正しい。順序が逆だと stale recent-auth のリクエストでも User 行ロックを取りに行く。
- 対応内容: `PasskeyRouteProtectionTest` で `passkey.destroy` の
  `gatherMiddleware()` 上の**インデックス比較**により
  `recent-auth` が `ensure-login-method` より前であることを pin する。

## [Warning] 施策 3: `RefreshDatabase` 下では `transactionLevel()` が 1 以上から始まる

- 判断: **対応する**
- 根拠: 正しい。`level === 1` を期待すると壊れる。
- 対応内容: 「middleware 突入前の level を基準に **+1 されていること**」および
  「`for update` の select と `delete` が**同一 level** で観測されること」を見る設計に変更。

## [Warning] 施策 4: config cache 下の保証が `config()->all()` だけでは不十分

- 判断: **対応する (より忠実な検査に置き換える)**
- 根拠: 正しい。ただし Pest から `config:cache` を実行するのは
  `bootstrap/cache/config.php` を書き換え `--parallel` 実行を壊すため採れない。
- 対応内容: `config:cache` の**実装そのもの**を再現する検査に変える。
  `ConfigCacheCommand` は `'<?php return '.var_export($config, true).';'` を書き出すため、
  `var_export(config()->all(), true)` を `eval` で往復させ、
  往復後も `fortify-options.passkeys.confirmPassword === false` が残ることを検査する。
  これは「serialize 可能であること」と「キーが `all()` に含まれること」の両方を
  1 つのアサーションで忠実に証明する。

## [Warning] 施策 4/6: `User::passkeys()` / `Passkey::$authenticator` の PHPStan 型

- 判断: **一部対応する**
- `passkeys()`: **対応する**。`User` 側で明示 override し
  `@return HasMany<\App\Models\Passkey, $this>` を付ける
  (trait 由来だと vendor base model 型で見えるため、DTO 生成 closure が level 10 で落ちる)
- `$authenticator`: **実測により対応不要と判断**。
  vendor の `Laravel\Passkeys\Passkey` は class docblock に
  `@property-read string|null $authenticator` を持ち、`App\Models\Passkey` は
  これを継承する。PHPStan は `string|null` として解決できる。
  ただし DTO 側の型を `?string` にして narrowing 不要にすることを明記する。

## [Warning] 施策 5: policy deny 経路でも `PasskeyVerified` が発火し guest session に鮮度が残る

- 判断: **対応する (重要な見落とし)**
- 根拠: 正しい。`VerifyPasskey` は `allowsLogin()` 判定の**前**に `PasskeyVerified` を dispatch する。
  TOTP 有効ユーザーの passkey login が deny されても、その前に
  `StampRecentAuthOnPasskeyVerified` が **guest session** に `recent_auth_at` を打ってしまう。
- 対応内容: listener に**本人性バインド**を入れる
  (`SocialAuthController::completeStepUp` と同じ作法)。
  「検証された passkey が**現在ログイン中ユーザー**のものである場合のみ stamp する」。
  guest (login 経路) では認証ユーザーが居ないため stamp されず、deny 時の残留も消える。
  login 成功時の鮮度は `StampRecentAuthOnLogin` が担うため機能欠落も起きない。
  Feature テストで「deny 時に guest session へ鮮度が残らない」を固定する。

## [Warning] 施策 5: satisfier inventory の静的走査が文字列一致で false negative

- 判断: **対応する**
- 根拠: 正しい。alias import / container 解決 / 変数名経由 / メソッド転送を取り逃がす。
- 対応内容: `token_get_all()` ベースの走査に変更する
  (namespace / use / class 名を token から解決し、`RecentAuthState` 型の変数・
  `app(RecentAuthState::class)` の両方を拾う)。
  それでも動的呼び出しは完全には拾えないため、**限界をテスト docblock に明記**し、
  「新しい satisfier を足すときに必ず考えさせる」という目的に役割を限定する。

## [Warning] 施策 5: `ClearRecentAuthOnPasskeyChange` が HTTP session 前提

- 判断: **対応する**
- 対応内容: `session()` 操作の前に session の利用可否を確認する
  (`app()->bound('session') && session()->isStarted()`)。
  CLI / queue / admin cleanup からの発火で例外にならないようにする。
  既存 `UpdateUserPassword::deleteOtherSessionRecords()` が
  `session()->isStarted()` で同じガードをしており作法が揃う。

## [Warning] 施策 2: `PasswordUpdated` イベント名の未確定

- 判断: **対応する (実測して確定させた)**
- 根拠: 実測の結果、Fortify 1.37 の Events は
  `PasswordUpdatedViaController` / `RecoveryCode*` / `TwoFactor*` のみ。
  `PasswordUpdatedViaController` は Fortify の Controller 経由に限定された意味であり、
  アプリが所有する `App\Actions\Fortify\UpdateUserPassword` から記録するほうが
  vendor のイベント意味論に依存せず確実。
- 対応内容: **Action 直記録に確定**する
  (`OrganizationMemberController::resetTwoFactor` と同じ「Action / Controller から直接記録」の作法)。
  `ResetUserPassword` 経路は既存の `Illuminate\Auth\Events\PasswordReset` 購読で
  カバー済みのため触らない。

## [Warning] 施策 2: 将来の password/social 除去 route も同じ lock 規約が必須

- 判断: **対応する (明記を強化)**
- 対応内容: `LoginMethodInventory` の docblock と施策 3 の直列化規約に
  「除去経路は例外なく `EnsureLoginMethodRemains` を通す = 単一の直列化点」を明記し、
  `LoginMethodRemovalRouteTest` の allowlist 検査 (上記 Critical 対応) がそれを強制する。

## [Warning] 施策 6: Inertia prop の Resource collection が不安定

- 判断: **対応する**
- 根拠: 正しい。`Resource::collection($collection->map(dto))` は
  PHPStan と Inertia resolve の両面で不安定。
- 対応内容: `App\Http\Controllers\Settings\SecurityController` を新設して
  route closure から抽出し (元々「Controller は薄く」の観点で推奨していた)、
  DTO collection を `->resolve($request)` した plain array として Inertia に渡す。

## [Suggestion] 施策 1: `EmailTrustPolicyResolver` の container 解決

- 判断: **見送る**
- 根拠: レビュー自身が「現時点では YAGNI の範囲」と評価。
  AGENTS.md 思考原則 2 (今必要なものだけ作る)。

---

## 最重要修正 5 点の反映状況

1. **`Route::bind('passkey')` を `app->booted()` 後勝ちに変更** → 施策 4-c。
   `boot()` は `configureLoginAuthorization()` のみ即時実行し、binder と middleware 後付けを
   `$this->app->booted(...)` に入れた。docblock で「bootstrap/providers.php の順序は
   app provider 間の順序にすぎない」と根拠を明示。
   `PasskeyPackageContractTest` に `getBindingCallback('passkey')` を直接叩く検査を追加。

2. **transport 契約の確定** → 施策 4-d に operation × transport の表を新設。
   登録/削除 = Inertia router (back()+flash)、confirm = fetch 204、login = fetch JSON {redirect}。
   `passkeys.ts` の責務も「登録は送信しない (ceremony と変換まで)」に修正。
   あわせて `EnsureLoginMethodRemains::reject()` を修正し、
   **Inertia には 422 JSON ではなく 302 + errors.login_method を返す**ようにした
   (Inertia protocol 違反の回避 + 禁止事項 7 との整合)。

3. **`ensure-login-method` の適用 route allowlist + 順序 test** →
   `LoginMethodRemovalRouteTest` に「allowlist 外への付与も fail」テストを追加。
   `PasskeyRouteProtectionTest` に `recent-auth` が `ensure-login-method` より
   前に来ることの index 比較を追加。middleware docblock に適用条件
   (streamed / 外部 I/O / afterCommit でない queue dispatch を含む route には付けない) を明記。

4. **`User::passkeys()` の app model 型を明示** → trait を override して
   `@return HasMany<\App\Models\Passkey, $this>` を付けた。
   `$authenticator` については実測の結果、vendor が class docblock に
   `@property-read string|null $authenticator` を持つため対応不要と判断 (根拠を記載)。

5. **satisfier inventory を token/AST 走査へ強化** → `token_get_all()` ベースに変更し、
   alias import / container 解決 / 型付き変数経由を解決する設計にした。
   限界 (完全動的呼び出し) を docblock に明記し、役割を
   「新しい satisfier を足すときに必ず判断させる」に限定した。

## その他の主な反映

- `PasskeyVerified` の deny 経路: **本人性バインド**を追加
  (検証された passkey が現在ログイン中ユーザーのものである場合のみ stamp)。
  guest 文脈 (login 経路 / deny 経路) では stamp されない。Feature テストで固定。
- `ClearRecentAuthOnPasskeyChange`: `app()->bound('session') && session()->isStarted()` ガード。
- `PasswordChanged`: **Action 直記録に確定** (Fortify 1.37 の Events を実測した結果、
  `PasswordUpdatedViaController` しかなく意味が限定されるため)。
- config cache: `var_export()` 往復 (= `ConfigCacheCommand` の実装そのもの) で検査する方式に変更。
  Pest から `config:cache` を実行すると `bootstrap/cache/config.php` を書き換え `--parallel` を壊すため。
- `transactionLevel()`: `RefreshDatabase` 下で 1 以上から始まるため **相対比較** に変更。
- Inertia prop: `SecurityController` へ抽出し、DTO を `resolve($request)` した plain array を渡す方式に変更。

## 依頼

残る [Critical] が無ければ **APPROVED** を出してください。
Warning が残る場合は、実装フェーズで扱えるものか、設計で決め切るべきものかを明示してください。

---

## 修正後の詳細設計書 (全文)

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

**方式は Action 直記録に確定する**（イベント購読にしない）。

実測: Fortify 1.37 の `vendor/laravel/fortify/src/Events/` にあるのは
`PasswordUpdatedViaController` / `RecoveryCodeReplaced` / `RecoveryCodesGenerated` /
`TwoFactorAuthentication*` のみ。`PasswordUpdatedViaController` は
「Fortify の Controller 経由」という限定された意味を持つため、
アプリが所有する `App\Actions\Fortify\UpdateUserPassword` から記録するほうが
vendor のイベント意味論に依存せず確実。
`OrganizationMemberController::resetTwoFactor` / `ResetAdminMfaCommand` と同じ
「Action / Controller から直接記録する」既存作法にも合う。

```php
// app/Actions/Fortify/UpdateUserPassword.php （差分）
public function __construct(
    private readonly SecurityEventRecorder $recorder,   // ← 追加
) {}

public function update(User $user, array $input): void
{
    Validator::make(...)->validateWithBag('updatePassword');

    $user->forceFill(['password' => Hash::make($input['password'])])->save();

    // 「そのユーザーが自分でパスワードを設定したか」の監査証跡。
    // SecurityEventType::PasswordChanged は enum に存在しながら記録経路が無かった。
    // 将来、legacy SSO ユーザーの phantom password を判別する材料にもなる
    // (施策 2 のリスク節参照)。
    $this->recorder->record(SecurityEventType::PasswordChanged, $user);

    Auth::logoutOtherDevices($input['password']);
    $this->deleteOtherSessionRecords($user);
}
```

> `ResetUserPassword`（`/reset-password` 経路）は既に
> `Illuminate\Auth\Events\PasswordReset` → `SecurityEventType::PasswordReset` で
> `RecordSecurityEvent` が購読済みのため触らない。

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
 *
 * ⚠ **将来 password 削除 / SSO 連携解除 route を追加するときも、必ず
 * EnsureLoginMethodRemains を通すこと (= 単一の直列化点)**。
 * passkey だけ守って別経路を作ると TOCTOU が戻る。
 * LoginMethodRemovalRouteTest が deny-by-default で強制する。
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
 * tests/Architecture/LoginMethodRemovalRouteTest.php が deny-by-default で強制する
 * (付与漏れだけでなく **allowlist 外 route への付与**も fail させる)。
 *
 * ⚠ **適用条件 (この middleware を新しい route に付ける前に必ず読むこと)**:
 *   `$next()` を transaction 内で実行するため、controller だけでなく
 *   **同期 event listener / Responsable 変換 / redirect + flash** まで transaction に入る。
 *   したがって次を含む route には付けてはならない:
 *     - streamed / downloadable response (transaction を長時間保持する)
 *     - 外部 I/O (HTTP・S3 等。ロック保持中に外部レイテンシを持ち込む)
 *     - `afterCommit` でない queue dispatch (ロールバック時に job だけ残る)
 *   これらが必要な route を保護する場合は、本 middleware の transaction 方式を
 *   「Service 内 transaction + 判定の再評価」へ再設計すること。
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

    /**
     * 拒否応答。
     *
     * **Inertia には 422 JSON を返さない** (Inertia protocol 違反になり、
     * router が応答を解釈できず無言失敗する)。Inertia は 302 + errors を native に
     * 処理するため、`back()->withErrors()` にして Svelte 側は `$page.props.errors` で読む。
     * 禁止事項 7 (操作系 POST は `back()->with(...)` で完結) とも整合する。
     *
     * 判別子に `expectsJson()` を使えるのは、Inertia が
     * `Accept: text/html, application/xhtml+xml` を送るため (X-Inertia は立つが Accept は HTML)。
     * 純粋な XHR (fetch + Accept: application/json) のみ 422 JSON になる。
     */
    private function reject(Request $request): Response
    {
        $dto = new LoginMethodRequiredDto(
            message: 'この操作を行うと、ログインする手段がなくなります。先に別のログイン手段（パスワードの設定、ソーシャル連携、他のパスキー）を追加してください。',
            settingsUrl: route('settings.security'),
        );

        if ($request->expectsJson()) {
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

        if (! routeHasLoginMethodGuard($route)) {
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

/*
 * **allowlist 外への付与も禁じる (deny-by-default の逆方向)**。
 *
 * EnsureLoginMethodRemains は $next() を DB transaction 内で実行するため、
 * controller / 同期 listener / Responsable 変換 / flash まで transaction に入る。
 * 適用範囲が無自覚に広がると副作用範囲が急拡大する
 * (streamed response / 外部 I/O / afterCommit でない queue dispatch は特に危険)。
 * 付与してよい route を allowlist に固定し、増やすときは必ず判断させる。
 */
test('ensure-login-method middleware を持つ route は guard 必須リストのみ', function (): void {
    $guarded = loginMethodRemovalGuardedRoutes();
    $unexpected = [];

    foreach (Route::getRoutes() as $route) {
        if (! routeHasLoginMethodGuard($route)) {
            continue;
        }
        $name = $route->getName() ?? $route->uri();
        if (! in_array($name, $guarded, true)) {
            $unexpected[] = "route '{$name}' に ensure-login-method が付いているが allowlist に無い"
                .' (middleware は $next を transaction 内で実行する。適用条件を docblock で確認すること)';
        }
    }

    expect($unexpected)->toBe([]);
});

function routeHasLoginMethodGuard(\Illuminate\Routing\Route $route): bool
{
    $middleware = $route->gatherMiddleware();

    return in_array('ensure-login-method', $middleware, true)
        || in_array(EnsureLoginMethodRemains::class, $middleware, true);
}
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
  - `DB::listen()` で発行 SQL と `DB::transactionLevel()` を収集し、
    `DELETE /user/passkeys/{id}` の処理中に
    - `users` に対する `for update` を含む `select` が発行されている
    - その `select` が `passkeys` の `delete` **より前**である
    - 両者が **同一の transaction level** で観測される
    - その level が **リクエスト前の level より 1 以上大きい**（middleware が新たに開いた証明）
  - **`level === 1` を期待してはならない**。`RefreshDatabase` がテスト全体を
    トランザクションで包むため基準 level は 1 以上から始まる。必ず**相対比較**にする
  - 拒否時（`back()->withErrors()`）には `passkeys` の `delete` が **発行されていない**
  - この方針の限界（実レースは再現できない。ロックの**取得**は固定できるが
    ロックの**効果**は DB に委ねる）をテストの docblock に明記する
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

    /**
     * trait 由来の passkeys() は vendor base model 型 (Laravel\Passkeys\Passkey) で
     * 解決されるため、App\Models\Passkey 前提の closure / DTO 生成が PHPStan level 10 で落ちる。
     * app model へ narrowing した override を明示する (trait のメソッドを上書きする)。
     *
     * @return HasMany<\App\Models\Passkey, $this>
     */
    public function passkeys(): HasMany
    {
        return $this->hasMany(\App\Models\Passkey::class);
    }
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
 * ⚠ **boot 順序**: Route::bind() は後勝ちだが、`bootstrap/providers.php` の順序は
 * **app provider 間の順序**にすぎず、auto-discovery された package provider
 * (Laravel\Passkeys\PasskeysServiceProvider) との最終 boot 順序を保証しない。
 * したがって binder 差し替えも **`$this->app->booted()` の中で実行する**
 * (= 全 provider の boot が終わった後に最終上書きする)。route middleware の後付けと
 * 同じ「booted で最終上書き」の形に統一する。
 * この順序依存は PasskeyPackageContractTest が
 * 「binder の最終解決系がアプリ実装」+「他人の passkey は 404」で固定する。
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
        $this->configureLoginAuthorization();

        // binder と middleware は **全 provider boot 後** に最終上書きする。
        // (PasskeysServiceProvider::boot() の Route::bind に確実に後勝ちするため)
        $this->app->booted(function (Application $app): void {
            $this->rebindPasskeyRouteModel();
            self::attachMiddlewareToPasskeyRoutes($app);
        });
    }

    /**
     * {passkey} を「認証ユーザー所有の passkey」にスコープして解決する。
     * 他人の passkey / 不在 id はともに 404 (403 で存在を漏らさない)。
     *
     * vendor の binder (PasskeysServiceProvider::registerRouteBindings) は
     * `app($model)->resolveRouteBinding($value)` でグローバルに解決するため、
     * controller の `abort_unless(..., 403)` に到達し **他人の passkey の存在を漏らす**。
     * booted() から上書きすることで確実に後勝ちさせる。
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
    private static function attachMiddlewareToPasskeyRoutes(Application $app): void
    {
        $routes = $app->make(Router::class)->getRoutes();
        $routes->refreshNameLookups();

        // **順序が重要**: recent-auth を先に通し、その後で手段保持を検査する。
        // 逆順だと stale recent-auth のリクエストでも User 行ロックを取りに行ってしまう。
        // PasskeyRouteProtectionTest が gatherMiddleware() 上の index 比較で固定する。
        foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
            self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
        }

        foreach (self::LOGIN_METHOD_GUARD_ROUTE_NAMES as $name) {
            self::appendMiddlewareIfMissing($routes, $name, 'ensure-login-method');
        }

        // guest route のため NoStoreCacheHeadersForAuthenticatedPages の対象外。
        // challenge を載せる応答をキャッシュさせない。
        self::appendMiddlewareIfMissing($routes, 'passkey.login-options', 'no-store');
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

### 4-d. **transport 契約（client ↔ server。実装前にこれを固定する）**

Response 実装・`passkeys.ts`・Svelte の 3 者が噛み合うよう、operation 単位で先に確定する。

| operation | options 取得 | 送信手段 | 成功応答 | recent-auth stale (409/302) | login-method 拒否 |
|-----------|-------------|---------|---------|------------------------|-----------------|
| **登録** | `fetch GET /user/passkeys/options` → `{options}` JSON | **Inertia `router.post('/user/passkeys', {...})`** | `back()->with('success')` (302) | precheck (`guardWithRecentAuth`) で回避。素通り時は `RequireRecentAuth` の Inertia mutation 分岐 = 409 → `onError` | — |
| **削除** | — | **Inertia `router.delete('/user/passkeys/{id}')`** | `back()->with('success')` (302) | 同上 | **302 + `errors.login_method`**（Inertia native） |
| **step-up confirm** | `fetch GET /passkeys/confirm/options` | **`fetch POST /passkeys/confirm`** | **204** + `no-store` | — | — |
| **ログイン (guest)** | `fetch GET /passkeys/login/options` | **`fetch POST /passkeys/login`** | JSON `{redirect}` (DTO+Resource) → `window.location.assign` | — | — |

**この割り当ての根拠**:

- **登録 / 削除は Inertia**。passkey 一覧（Inertia prop）を更新する必要があり、
  既存 `Settings/Security.svelte` の 2FA が `router.post` / `router.delete` + `back()` flash で
  統一されている。同じ画面で 2 つの transport を混在させない
- **confirm は fetch + 204**。既存 `RecentAuthModal` が `/recent-auth/password` に対して
  `fetch` + 204 契約を持つ。step-up の satisfier は同じ契約に揃える
- **login は guest の fetch**。ceremony 結果を送って着地 URL を受け取る必要があり、
  Inertia ページ遷移とは無関係
- **options 取得は全て fetch**。既存 `/user/two-factor-qr-code` の fetch パターンと同一

**409 / 302+errors の扱い**: `EnsureLoginMethodRemains` は Inertia に **422 JSON を返さない**
（Inertia protocol 違反になり無言失敗する）。`back()->withErrors()` の 302 にして
Svelte 側は `$page.props.errors.login_method` で読む（施策 3 の `reject()` 参照）。

### 4-e. Response 上書き（4 契約）

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
 * passkey ログイン完了。transport 契約 (4-d) により client は fetch で送るため
 * **JSON `{redirect}` を返す**のが主経路。client は受け取った URL へ
 * window.location.assign する。
 *
 * **ログイン直後フロー**のため intended() が許される数少ない経路 (禁止事項 7 の例外条件)。
 * 着地は Fortify 標準ログイン (App\Http\Responses\Fortify\LoginResponse) と揃える。
 * DTO + JsonResource 経由にして response()->json() 直書きを避ける (禁止事項 4)。
 */
final class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    public function toResponse($request): SymfonyResponse
    {
        $target = redirect()->intended(config()->string('fortify.home'))->getTargetUrl();

        if ($request->expectsJson()) {
            return PasskeyLoginRedirectResource::make(new PasskeyLoginRedirectDto(redirect: $target))
                ->response()
                ->withHeaders(['Cache-Control' => 'no-store, private']);
        }

        return redirect()->to($target);
    }
}
```

> `options` 系 3 endpoint（`passkey.login-options` / `passkey.confirm-options` /
> `passkey.registration-options`）は vendor controller が `response()->json()` で返す
> **vendor コード**であり、アプリの禁止事項 4 の対象外（差し替え contract が無い）。
> ただし challenge + PII（email）を載せるため `no-store` が必要。
> `NoStoreCacheHeadersForAuthenticatedPages` が認証済み応答に baseline を張るため
> confirm/registration options は自動でカバーされるが、
> **`passkey.login-options` は guest route** なので対象外。
> → `PasskeyServiceProvider` が `no-store` middleware を後付けし、
> `PasskeyRouteProtectionTest` で固定する。

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
  - **config cache 往復後も値が残る**（`config:cache` 下の保証）。
    `ConfigCacheCommand` は `'<?php return '.var_export($config, true).';'` を書き出すため、
    その**実装そのものを再現**して検査する:

    ```php
    // Pest から config:cache を実行すると bootstrap/cache/config.php を書き換え
    // --parallel 実行を壊すため、serialize 機構だけを忠実に再現する。
    $roundTripped = eval('return '.var_export(config()->all(), true).';');
    expect(data_get($roundTripped, 'fortify-options.passkeys.confirmPassword'))->toBeFalse();
    expect(data_get($roundTripped, 'fortify.features'))->toContain('passkeys');
    ```
  - `Passkeys::passkeyModel()` === `App\Models\Passkey`、`Passkeys::userModel()` === `App\Models\User`
  - `App\Models\User` が `PasskeyUser` を実装している
  - `Passkeys::authorizeLoginUsing` に closure が登録済み（`Passkeys::allowsLogin()` が
    TOTP 有無で結果を変えることで間接検証）
  - **binder の最終解決系がアプリ実装**であること。
    `app('router')->getBindingCallback('passkey')` を直接呼び、
    - guest 文脈 → `ModelNotFoundException`
    - 他人の passkey id → `ModelNotFoundException`（vendor 実装なら解決に成功してしまう）
    - 本人の passkey id → `App\Models\Passkey` インスタンス
    を検証する（Feature テストの 404 と併せて二重に固定）
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
  - **middleware の順序**: `passkey.destroy` の `gatherMiddleware()` 上で
    `recent-auth` の index が `ensure-login-method` の index **より小さい**こと
    （逆順だと stale recent-auth のリクエストでも User 行ロックを取りに行く）
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
 *
 * ⚠ 本 listener は HTTP session を前提とする。将来これらのイベントが
 * CLI / queue / admin cleanup から発火しても壊れないよう session の利用可否を確認する
 * (既存 UpdateUserPassword::deleteOtherSessionRecords() と同じガード作法)。
 */
final class ClearRecentAuthOnPasskeyChange
{
    public function __construct(
        private readonly RecentAuthState $recentAuthState,
    ) {}

    public function handleRegistered(PasskeyRegistered $event): void
    {
        $this->clearIfSessionAvailable();
    }

    public function handleDeleted(PasskeyDeleted $event): void
    {
        $this->clearIfSessionAvailable();
    }

    private function clearIfSessionAvailable(): void
    {
        if (! app()->bound('session') || ! session()->isStarted()) {
            return;   // CLI / queue 文脈では session 操作をしない
        }

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
 *
 * ⚠ **本人性バインドが必須**。VerifyPasskey は
 * `Passkeys::allowsLogin()` の判定 **より前** に PasskeyVerified を dispatch する。
 * 素朴に stamp すると、TOTP 有効ユーザーの passkey login が
 * PasskeyLoginPolicy に deny された場合でも、**guest session に鮮度が残る**。
 * そこで「検証された passkey が **現在ログイン中ユーザー** のものである場合のみ stamp する」
 * (SocialAuthController::completeStepUp の本人性バインドと同じ作法)。
 *   - confirm 経路: 認証済みユーザー本人の passkey → stamp される (期待どおり)
 *   - login 経路:   その時点では guest → stamp されない。
 *                   ログイン成立後の鮮度は StampRecentAuthOnLogin が担うため欠落しない
 *   - deny 経路:    guest のまま終わるので鮮度は残らない (fail-closed)
 */
final class StampRecentAuthOnPasskeyVerified
{
    public function __construct(
        private readonly RecentAuthState $recentAuthState,
    ) {}

    public function handle(PasskeyVerified $event): void
    {
        $current = request()->user();
        if (! $current instanceof User) {
            return;   // guest (login 経路 / deny 経路) では stamp しない
        }

        // 他人の credential での round-trip 完了を成立させない
        if ((string) $event->passkey->user_id !== (string) $current->getKey()) {
            return;
        }

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

/*
 * ⚠ **この走査の限界 (docblock に明記すること)**:
 *   token_get_all() ベースの静的走査であり、
 *     - `use App\Security\RecentAuthState as X` の alias import
 *     - `app(RecentAuthState::class)->confirm(...)` の container 解決
 *     - `RecentAuthState` 型でタイプヒントされた promoted property / 変数経由の呼び出し
 *   までは解決するが、**完全に動的な呼び出し**
 *   (`$cls = 'App\Security\RecentAuthState'; app($cls)->confirm()` 等) は取り逃がす。
 *   本テストの役割は「新しい satisfier を足すときに必ず PR で判断させる」ことに限定する
 *   (完全性の証明ではない)。より強い保証が必要になったら
 *   AGENTS.md のコードベース探索方針どおり code-review-graph の AST グラフへ寄せること。
 */
test('RecentAuthState::confirm の呼び出し元は inventory に登録されたクラスのみ', function (): void {
    $allowed = recentAuthSatisfierClasses();
    $violations = [];
    $checked = 0;

    foreach (phpFilesUnder(app_path()) as $path) {
        $source = file_get_contents($path);
        if (! is_string($source)) {
            continue;
        }

        // token_get_all() で namespace / use(alias 含む) / class 名 / メソッド呼び出しを解決する。
        // 文字列一致ではなく token 列で判定するので、alias import と
        // app(RecentAuthState::class) の両方を拾える。
        $analysis = analyzeRecentAuthConfirmCalls($source);
        if (! $analysis->callsConfirm) {
            continue;
        }

        $checked++;

        if ($analysis->fqcn === null || ! in_array($analysis->fqcn, $allowed, true)) {
            $violations[] = "{$path} が RecentAuthState::confirm() を呼んでいるが satisfier inventory に未登録";
        }
    }

    expect($violations)->toBe([]);
    // 呼び出し元が 1 件も見つからない = 走査が壊れている (空振り drift)
    expect($checked)->toBeGreaterThan(0);
});
```

> `analyzeRecentAuthConfirmCalls()` / `phpFilesUnder()` はテスト内のヘルパとして実装する。
> 既存 Architecture テストのヘルパ配置作法（同ファイル内の関数定義）に合わせる。

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
  - **TOTP 有効ユーザーの passkey login が deny されたとき、guest session に
    `recent_auth_at` が残らない**（`StampRecentAuthOnPasskeyVerified` の本人性バインド）
  - 他人の credential で confirm を試みても鮮度が成立しない
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
  `passkeys: PasskeyListItem[]` / `passkeyLoginAvailable: boolean` を追加。
  `PasskeyListItem` は `resources/js/lib/passkeys.ts` に定義し
  `PasskeyListItemDto` と 1:1 対応させる（`authenticator` は `string | null`）
- **API Resource/DTO**: 新規 `app/DataTransferObjects/Auth/PasskeyListItemDto` +
  `app/Http/Resources/Auth/PasskeyListItemResource`（`$wrap = null`）
- **Controller 抽出**: `routes/web.php` の `settings.security` closure を廃止し
  `App\Http\Controllers\Settings\SecurityController` へ。
  → `routes/web.php` の import 整理、`tests/Feature` の既存 settings.security 参照の確認
- **テストファイル**: `tests/js/pages/Settings/Security.test.ts`（既存があれば更新）、
  新規 `tests/js/lib/passkeys.test.ts`、新規 `tests/js/architecture/passkeys-import-isolation.test.ts`、
  新規 `tests/Feature/Settings/SecurityPagePropsTest.php`（`passkeys` / `passkeyLoginAvailable` prop の型と内容）

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

/**
 * **transport 契約 (施策 4-d) に対応する責務分担**:
 *   本モジュールは「options 取得 + ceremony 実行 + 送信可能な JSON への変換」までを担う。
 *   **登録は送信までしない** (Inertia router.post は呼び出し側 Svelte が行う)。
 *   confirm / login は fetch 完結なので送信まで担う。
 */

/**
 * 登録 ceremony (GET options → navigator.credentials.create)。
 * **送信は行わない**。呼び出し側が Inertia router.post('/user/passkeys', {name, ...credential}) する。
 */
export async function createPasskeyCredential(): Promise<PasskeyOutcome<Record<string, unknown>>> { /* ... */ }

/** ログイン ceremony (GET options → navigator.credentials.get → fetch POST → {redirect}) */
export async function loginWithPasskey(): Promise<PasskeyOutcome<{ redirect: string }>> { /* ... */ }

/** step-up 確認 ceremony (GET confirm-options → navigator.credentials.get → fetch POST → 204) */
export async function confirmWithPasskey(): Promise<PasskeyOutcome<void>> { /* ... */ }
```

`NotAllowedError`（ユーザーキャンセル / タイムアウト）は `{ status: "cancelled" }` に畳み、
呼び出し側は toast を出さず再試行導線を残す。

### 6-b. Inertia prop

`settings.security` の route closure は既に肥大しており、DI をさらに積み増すのは
AGENTS.md「Controller は薄く」に反する。**`App\Http\Controllers\Settings\SecurityController` へ抽出する**
（route closure の廃止も本施策に含む）。

Inertia prop は `Resource::collection($collection->map(dto))` にしない
（PHPStan と Inertia resolve の両面で不安定）。**DTO を Resource で包んで `resolve($request)` した
plain array** を渡す。

```php
// app/Http/Controllers/Settings/SecurityController.php
final class SecurityController extends Controller
{
    public function __construct(
        private readonly PasskeyLoginPolicy $passkeyLoginPolicy,
    ) {}

    public function __invoke(Request $request): InertiaResponse
    {
        $user = $request->user();
        // admin guard 併用のため user() は User|AdminUser の union。narrowing する
        $isUser = $user instanceof User;

        return Inertia::render('Settings/Security', [
            'socialProviders' => array_keys(config()->array('template.social_providers')),
            'linkedProviders' => $isUser ? $user->socialAccounts()->pluck('provider')->all() : [],
            'passkeys' => $isUser ? $this->passkeyList($request, $user) : [],
            // TOTP 有効ユーザーには「ログインには使えないが再認証には使える」旨を出す
            'passkeyLoginAvailable' => $isUser && $this->passkeyLoginPolicy->allowsPasskeyLogin($user),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function passkeyList(Request $request, User $user): array
    {
        return $user->passkeys()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Passkey $p): array => PasskeyListItemResource::make(
                new PasskeyListItemDto(
                    id: (int) $p->getKey(),
                    name: $p->name,
                    // vendor の class docblock が @property-read string|null $authenticator を
                    // 宣言しているため PHPStan は ?string で解決できる (実測確認済み)
                    authenticator: $p->authenticator,
                    lastUsedAt: $p->last_used_at?->toIso8601String(),
                    createdAt: $p->created_at?->toIso8601String(),
                ),
            )->resolve($request))
            ->values()
            ->all();
    }
}
```

```php
// routes/web.php （closure を置き換え）
Route::get('/settings/security', SecurityController::class)->name('settings.security');
```

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
- 登録は `createPasskeyCredential()` で ceremony を実行し、得た credential を
  **`router.post('/user/passkeys', { name, ...credential }, { preserveScroll: true })`** で送る
  （transport 契約 4-d）。成功 flash はサーバ (`back()->with('success')`) を単一の源とし、
  client 楽観 toast を出さない（既存のリカバリコード再生成と同じ二重発火回避）
- 削除は `ConfirmDialog` を挟み `router.delete(...)`
- **`ensure-login-method` の拒否を専用に扱う**: サーバは Inertia に対し
  302 + `errors.login_method` を返す（422 JSON ではない）。
  `$page.props.errors.login_method` を `Alert type="danger"` で表示し、
  「別のログイン手段を追加する」導線（パスワード設定 / ソーシャル連携）を同画面に出す。
  **無言失敗にしない**
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
