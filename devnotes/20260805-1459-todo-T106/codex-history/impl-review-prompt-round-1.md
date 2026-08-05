【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。


## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


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



## あなたの役割

Laravel 13 + Svelte 5 (Inertia) アプリのコードレビュアーとして、T106 (passkey 導入とログイン手段保持 guard) の実装差分をレビューする。

## レビュー観点

1. **設計との一致性**: 詳細設計書 (施策 2〜6) の意図どおりか。意図的な逸脱には正当な理由があるか
2. **正確性**: ロジックの誤り・境界条件・競合 (TOCTOU)・fail-closed の向き
3. **セキュリティ**: 認可より前の 404 / 存在オラクル / session 汚染 / CSRF / throttle / open redirect
4. **PHPStan level 10 適合**: 型の widen や @phpstan-ignore による回避が無いか
5. **DTO / JsonResource パターン**: `response()->json()` の直書きが無いか
6. **テスト網羅性**: 各施策にテストがあるか。テストが「実装をなぞるだけ」になっていないか。空振り (0 件検査で green) しないか
7. **DESIGN.md 準拠**: color / radius / typography は design token 経由で参照し hex 直書き (`#RRGGBB`) を増やしていないか
8. **Atomic Design 準拠**: `atoms -> molecules -> organisms -> features/{domain} -> templates -> pages` の単方向 import。atom は単機能・状態を持たない。アイコンは Lucide のみ (SVG 直書きを増やさない)

## 出力形式

ファイルごとに判定を述べ、指摘は [Critical] / [Warning] / [Suggestion] に分類する。
最後に全体判定を `APPROVED` または `CHANGES_REQUESTED` で明示する。

**Critical の定義**: セキュリティ不変条件の破れ / 禁止事項違反 / 実際に壊れるバグ / テスト無しの機能追加。
「もっと良くできる」は Suggestion に留めること。

---

## 詳細設計書 (施策 2〜6 が本 TODO の担当。施策 1 は先行 TODO T105 で実装済み)

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
- 新規: `app/Enums/Auth/LoginMethodRemovalKind.php`

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
```

> `LoginMethodKind`（`password` / `social` / `passkey` の列挙）は**作らない**。
> `LoginMethodSet` は「空かどうか / 何個か」しか使わず、要素の種別で分岐する箇所が無い
> （AGENTS.md 思考原則 2: 今必要なものだけ作る）。
> UI に手段の内訳を出す要件が生まれたときに導入する。

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

  **応答契約は transport で分岐する**（施策 3 `reject()` / 施策 4-d）。
  Inertia は 422 JSON を解釈できないため 302 + errors、純 XHR のみ 422 JSON。

  | 前提 | リクエスト種別 | 期待 |
  |------|--------------|------|
  | password/social なし・passkey **1 件** | **Inertia** (`X-Inertia`, Accept: html) | **302** + `errors.login_method` |
  | password/social なし・passkey **1 件** | **純 XHR** (`Accept: application/json`) | **422** + `message` / `settingsUrl` |
  | password/social なし・passkey **1 件** | 通常フォーム POST | `back()` + `withErrors('login_method')` |
  | password/social なし・passkey **2 件** | Inertia | 1 件削除**できる**（302 + success flash）、残 1 件 |
  | password あり・passkey 1 件 | Inertia | 削除**できる** |
  | google 連携あり・passkey 1 件 | Inertia | 削除**できる** |
  | TOTP confirmed・passkey 2 件・他手段なし | Inertia | passkey は手段に数えないので **302 + errors.login_method** |
  | 削除対象が**他人の** passkey | 任意 | inventory 評価より前に **404**（403 ではない） |

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

## 実装フェーズで確定させる項目（詳細設計レビュー Round 2 の申し送り）

設計としては APPROVED だが、以下は**実装時に実測して固定**する。

1. **Inertia mutation に対する `RequireRecentAuth` の 409 の挙動**
   Inertia の 409 は外部 location response にも使われるため、通常の JSON エラーとして
   `onError` で安定処理できるとは限らない。precheck (`guardWithRecentAuth`) をすり抜けた
   鮮度切れ（precheck 後に期限が切れるレース）を Feature テスト + JS テストで実測し、
   実際の callback と画面挙動を固定する。
   **成立しなければ Inertia 向けは `back()->withErrors()` に統一する**
   （`EnsureLoginMethodRemains` と同じ方針。既存の `RequireRecentAuth` を触るため、
   その場合は `RecentAuthTest` の回帰も併せて更新する）。

2. **middleware 実行順テストの精度**
   `Route::gatherMiddleware()` の alias 文字列比較に加え、可能なら
   `Router::gatherRouteMiddleware($route)` で解決後のクラス順も検査する
   （`$middlewarePriority` による並べ替えまで含めて実行順を保証できる）。

3. **fetch ラッパの HTTP ヘッダ契約**
   `passkeys.ts` の fetch 系（confirm / login）は
   `Accept: application/json` / `Content-Type: application/json` /
   CSRF ヘッダ（`X-XSRF-TOKEN`。既存 `RecentAuthModal` と同じ作法）を必ず送る。
   **`passkey.login` は `Accept: application/json` が無いと
   `PasskeyLoginResponse` の JSON Resource 分岐に入らない**（`expectsJson()` が false になる）。
   リダイレクト応答・非 JSON 応答を受け取った場合の拒否方法も含め、
   `tests/js/lib/passkeys.test.ts` でヘッダを固定する。

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


---

## 実装差分 (git diff)

```diff
diff --git a/.env.example b/.env.example
index bdac9fe..cced2bf 100644
--- a/.env.example
+++ b/.env.example
@@ -179,6 +179,12 @@ GOOGLE_CLIENT_ID=
 GOOGLE_CLIENT_SECRET=
 GOOGLE_REDIRECT_URI="${APP_URL}/auth/google/callback"
 
+# パスキー (WebAuthn) は専用の env を持たない。Fortify が APP_URL から
+# relying party id (ホスト) と allowed origins ([APP_URL]) を、user handle secret を
+# APP_KEY から導出する (同一オリジン PWA 前提)。
+# ⚠ APP_KEY をローテートすると既存パスキーの user handle が変わり全件無効になる。
+#    運用契約は docs/architecture.md §パスキー (WebAuthn)。
+
 # reCAPTCHA v2 invisible (site_key 未設定時は captcha 無しで動く。
 # secret_key は production では未設定 = fail-closed)
 RECAPTCHA_SITE_KEY=
diff --git a/app/Actions/Fortify/UpdateUserPassword.php b/app/Actions/Fortify/UpdateUserPassword.php
index 0dad1ad..b364a2a 100644
--- a/app/Actions/Fortify/UpdateUserPassword.php
+++ b/app/Actions/Fortify/UpdateUserPassword.php
@@ -4,7 +4,9 @@
 
 namespace App\Actions\Fortify;
 
+use App\Enums\SecurityEventType;
 use App\Models\User;
+use App\Services\Security\SecurityEventRecorder;
 use Illuminate\Support\Facades\Auth;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Hash;
@@ -17,6 +19,10 @@
 
 class UpdateUserPassword implements UpdatesUserPasswords
 {
+    public function __construct(
+        private readonly SecurityEventRecorder $recorder,
+    ) {}
+
     /**
      * パスワード変更の検証と反映、および他デバイスのセッション・remember-me の失効。
      *
@@ -41,6 +47,16 @@ public function update(User $user, array $input): void
             'password' => Hash::make($input['password']),
         ])->save();
 
+        // 「そのユーザーが自分でパスワードを設定したか」の監査証跡。
+        // SecurityEventType::PasswordChanged は enum に存在しながら記録経路が無かった
+        // (/reset-password 経路は Illuminate の PasswordReset イベント → RecordSecurityEvent が
+        //  既に購読済みのため本 Action だけが欠けていた)。
+        // 将来、前方修正前に作られた legacy SSO ユーザーの phantom password
+        // (docs/template-divergence.md D13) を判別する材料にもなる。
+        // Fortify の PasswordUpdatedViaController ではなく Action 直記録にするのは、
+        // vendor イベントの意味論 (「Fortify の Controller 経由」) に依存しないため。
+        $this->recorder->record(SecurityEventType::PasswordChanged, $user);
+
         // 現在デバイスを維持しつつ他デバイスを失効させる。logoutOtherDevices は password を
         // 再ハッシュし、現在デバイスの recaller (remember-me) を新ハッシュで再発行 (現在リクエストが
         // recaller を持つ場合のみ) + OtherDeviceLogout イベントを発火する。他デバイスの実失効は
diff --git a/app/DataTransferObjects/Auth/LoginMethodRemoval.php b/app/DataTransferObjects/Auth/LoginMethodRemoval.php
new file mode 100644
index 0000000..36d6ee9
--- /dev/null
+++ b/app/DataTransferObjects/Auth/LoginMethodRemoval.php
@@ -0,0 +1,74 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Auth;
+
+use App\Enums\Auth\LoginMethodRemovalKind;
+use App\Models\Passkey;
+use App\Models\User;
+use Webmozart\Assert\Assert;
+
+/**
+ * 「今から何を除去しようとしているか」を表す閉じた variant。
+ *
+ * private constructor + 名前付き static factory で **不正状態を生成できない**ようにする
+ * (provider 空文字、他人の passkey、種別と payload の不整合をコンストラクタで排除する)。
+ *
+ * 生成点は EnsureLoginMethodRemains::removalFor() と、不変条件検査 (テスト) のみ。
+ */
+final class LoginMethodRemoval
+{
+    private function __construct(
+        public readonly LoginMethodRemovalKind $kind,
+        public readonly ?Passkey $passkey = null,
+        public readonly ?string $provider = null,
+    ) {}
+
+    /** 除去しない (現在状態の照会) */
+    public static function none(): self
+    {
+        return new self(LoginMethodRemovalKind::None);
+    }
+
+    /** password の除去 (将来の password 削除 route 用) */
+    public static function password(): self
+    {
+        return new self(LoginMethodRemovalKind::Password);
+    }
+
+    /** SSO 連携 1 件の解除 (将来の連携解除 route 用) */
+    public static function social(string $provider): self
+    {
+        Assert::stringNotEmpty($provider);
+
+        return new self(LoginMethodRemovalKind::Social, provider: $provider);
+    }
+
+    /**
+     * passkey 1 件の削除。
+     *
+     * $passkey は **binder が対象 User に属することを 404 で確定させた後**に渡すこと
+     * (App\Http\Routing\SelfScopedPasskeyBinder)。二重防御として所有を assert する
+     * (fail-closed。他人の passkey を投影対象にすると「他人の credential を消せば
+     * 自分の手段が残る」という誤判定になりうる)。
+     */
+    public static function passkey(Passkey $passkey, User $owner): self
+    {
+        $ownerKey = $owner->getKey();
+        Assert::scalar($ownerKey);   // bigint PK。string 比較に落とすため型を確定させる
+
+        Assert::true(
+            (string) $passkey->user_id === (string) $ownerKey,
+            'LoginMethodRemoval::passkey は対象 User 所有の passkey のみ受け付ける',
+        );
+
+        return new self(LoginMethodRemovalKind::Passkey, passkey: $passkey);
+    }
+
+    /** 全 passkey を除外した集合の評価用 (不変条件検査) */
+    public static function allPasskeys(): self
+    {
+        return new self(LoginMethodRemovalKind::AllPasskeys);
+    }
+}
diff --git a/app/DataTransferObjects/Auth/LoginMethodRequiredDto.php b/app/DataTransferObjects/Auth/LoginMethodRequiredDto.php
new file mode 100644
index 0000000..193eb39
--- /dev/null
+++ b/app/DataTransferObjects/Auth/LoginMethodRequiredDto.php
@@ -0,0 +1,25 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Auth;
+
+/**
+ * ログイン手段が 0 になる操作を拒否したときに、純 XHR へ返す 422 ボディ。
+ *
+ * Inertia には返さない (Inertia protocol は 422 JSON を解釈できず無言失敗するため、
+ * `back()->withErrors()` の 302 を返す。EnsureLoginMethodRemains::reject() 参照)。
+ */
+final readonly class LoginMethodRequiredDto
+{
+    /**
+     * 422 契約の判別子。クライアントは status だけでなく code 厳格一致で
+     * 自分宛ての応答のみ処理する (他の 422 契約との誤食防止)。
+     */
+    public const string CODE = 'login_method_required';
+
+    public function __construct(
+        public string $message,
+        public string $settingsUrl,
+    ) {}
+}
diff --git a/app/DataTransferObjects/Auth/LoginMethodSet.php b/app/DataTransferObjects/Auth/LoginMethodSet.php
new file mode 100644
index 0000000..97613c5
--- /dev/null
+++ b/app/DataTransferObjects/Auth/LoginMethodSet.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Auth;
+
+/**
+ * ログインに使える手段の集合 (LoginMethodInventory の戻り値)。
+ *
+ * 要素は 'password' / 'social:{provider}' / 'passkey' の識別子文字列。
+ * **要素の種別で分岐する呼び出し側は現時点で存在しない** (使うのは「空か / 何個か」だけ)
+ * ため、LoginMethodKind のような列挙は作らない (AGENTS.md 思考原則 2)。
+ * UI に手段の内訳を出す要件が生まれたときに導入する。
+ *
+ * HTTP 応答には出さない内部 DTO (露出するのは LoginMethodRequiredDto のメッセージのみ)。
+ */
+final class LoginMethodSet
+{
+    /** @param list<string> $methods 'password' / 'social:google' / 'passkey' */
+    public function __construct(public readonly array $methods) {}
+
+    public function isEmpty(): bool
+    {
+        return $this->methods === [];
+    }
+
+    public function count(): int
+    {
+        return count($this->methods);
+    }
+}
diff --git a/app/DataTransferObjects/Auth/PasskeyListItemDto.php b/app/DataTransferObjects/Auth/PasskeyListItemDto.php
new file mode 100644
index 0000000..cd0668a
--- /dev/null
+++ b/app/DataTransferObjects/Auth/PasskeyListItemDto.php
@@ -0,0 +1,24 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Auth;
+
+/**
+ * Settings/Security の passkey 一覧 1 件分。
+ *
+ * `resources/js/lib/passkeys.ts` の PasskeyListItem と 1:1 対応させる
+ * (項目を増やすときは両方を同時に変更すること)。
+ * credential 本体 (公開鍵 / signature counter) は**露出しない**。
+ */
+final readonly class PasskeyListItemDto
+{
+    public function __construct(
+        public int $id,
+        public string $name,
+        /** 認証器名 (AAGUID から解決。不明なら null) */
+        public ?string $authenticator,
+        public ?string $lastUsedAt,
+        public ?string $createdAt,
+    ) {}
+}
diff --git a/app/DataTransferObjects/Auth/PasskeyLoginRedirectDto.php b/app/DataTransferObjects/Auth/PasskeyLoginRedirectDto.php
new file mode 100644
index 0000000..360b350
--- /dev/null
+++ b/app/DataTransferObjects/Auth/PasskeyLoginRedirectDto.php
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Auth;
+
+/**
+ * passkey ログイン成功時に client (fetch) へ返す着地 URL。
+ *
+ * client は受け取った URL へ `window.location.assign` する
+ * (WebAuthn ceremony は fetch 完結のため Inertia のページ遷移とは無関係。
+ *  transport 契約は詳細設計 施策 4-d)。
+ */
+final readonly class PasskeyLoginRedirectDto
+{
+    public function __construct(
+        public string $redirect,
+    ) {}
+}
diff --git a/app/Enums/Auth/LoginMethodRemovalKind.php b/app/Enums/Auth/LoginMethodRemovalKind.php
new file mode 100644
index 0000000..4e54421
--- /dev/null
+++ b/app/Enums/Auth/LoginMethodRemovalKind.php
@@ -0,0 +1,30 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Auth;
+
+/**
+ * 「今から何を除去しようとしているか」の閉じた variant。
+ *
+ * LoginMethodRemoval (DTO) と LoginMethodInventory の投影評価が共有する判別子。
+ * backing value を持たないのは、永続化も HTTP 露出もしない純粋な内部分岐のため
+ * (値を持たせると「どこかで文字列と往復している」と誤読される)。
+ */
+enum LoginMethodRemovalKind
+{
+    /** 除去しない (現在状態の照会) */
+    case None;
+
+    /** password の除去 (将来の password 削除 route 用) */
+    case Password;
+
+    /** SSO 連携 1 件の解除 (将来の連携解除 route 用) */
+    case Social;
+
+    /** passkey 1 件の削除 */
+    case Passkey;
+
+    /** 全 passkey の除外 (不変条件検査用の仮想投影) */
+    case AllPasskeys;
+}
diff --git a/app/Http/Controllers/Settings/SecurityController.php b/app/Http/Controllers/Settings/SecurityController.php
new file mode 100644
index 0000000..85f9e96
--- /dev/null
+++ b/app/Http/Controllers/Settings/SecurityController.php
@@ -0,0 +1,83 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Settings;
+
+use App\DataTransferObjects\Auth\PasskeyListItemDto;
+use App\Http\Controllers\Controller;
+use App\Http\Resources\Auth\PasskeyListItemResource;
+use App\Models\Passkey;
+use App\Models\User;
+use App\Services\Auth\PasskeyLoginPolicy;
+use Illuminate\Http\Request;
+use Inertia\Inertia;
+use Inertia\Response as InertiaResponse;
+
+/**
+ * セキュリティ設定画面 (GET /settings/security)。
+ *
+ * 2FA / ソーシャル連携 / パスキーの管理面。route closure から抽出したのは
+ * passkey 一覧の組み立てで DI (PasskeyLoginPolicy) が必要になり、
+ * closure に積み増すと「Controller は薄く」の作法から外れるため。
+ */
+final class SecurityController extends Controller
+{
+    public function __construct(
+        private readonly PasskeyLoginPolicy $passkeyLoginPolicy,
+    ) {}
+
+    public function __invoke(Request $request): InertiaResponse
+    {
+        $user = $request->user();
+        // admin guard 併用のため user() は User|AdminUser の union。narrowing する
+        $isUser = $user instanceof User;
+
+        return Inertia::render('Settings/Security', [
+            'socialProviders' => array_keys(config()->array('template.social_providers')),
+            'linkedProviders' => $isUser ? $user->socialAccounts()->pluck('provider')->all() : [],
+            'passkeys' => $isUser ? $this->passkeyList($request, $user) : [],
+            // TOTP 有効ユーザーには「ログインには使えないが再認証には使える」旨を出すための判別子。
+            // 判定は PasskeyLoginPolicy に集約 (login 認可 / inventory と同一条件)。
+            'passkeyLoginAvailable' => $isUser && $this->passkeyLoginPolicy->allowsPasskeyLogin($user),
+        ]);
+    }
+
+    /**
+     * Inertia prop 用の passkey 一覧。
+     *
+     * `Resource::collection()` にせず **DTO を Resource で包んで resolve() した plain array**
+     * を渡す (PHPStan と Inertia の prop 解決の双方で安定するため)。
+     *
+     * @return list<array<string, mixed>>
+     */
+    private function passkeyList(Request $request, User $user): array
+    {
+        $items = [];
+
+        // App\Models\Passkey 型で扱うため relation ではなくモデルを user_id スコープで引く
+        // (PasskeyUser interface の宣言により relation は vendor 型で解決されるため。
+        //  User モデルの該当コメント参照)。
+        $passkeys = Passkey::query()
+            ->where('user_id', $user->getKey())
+            ->orderByDesc('created_at')
+            ->orderByDesc('id')
+            ->get();
+
+        foreach ($passkeys as $passkey) {
+            $items[] = PasskeyListItemResource::make(
+                new PasskeyListItemDto(
+                    id: $passkey->id,
+                    name: $passkey->name,
+                    // vendor が @property-read string|null $authenticator を宣言している
+                    // (AAGUID から解決。不明なら null)
+                    authenticator: $passkey->authenticator,
+                    lastUsedAt: $passkey->last_used_at?->toIso8601String(),
+                    createdAt: $passkey->created_at?->toIso8601String(),
+                ),
+            )->toArray($request);
+        }
+
+        return $items;
+    }
+}
diff --git a/app/Http/Middleware/EnsureLoginMethodRemains.php b/app/Http/Middleware/EnsureLoginMethodRemains.php
new file mode 100644
index 0000000..21726be
--- /dev/null
+++ b/app/Http/Middleware/EnsureLoginMethodRemains.php
@@ -0,0 +1,143 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Middleware;
+
+use App\DataTransferObjects\Auth\LoginMethodRemoval;
+use App\DataTransferObjects\Auth\LoginMethodRequiredDto;
+use App\Http\Resources\Auth\LoginMethodRequiredResource;
+use App\Models\Passkey;
+use App\Models\User;
+use App\Services\Auth\LoginMethodInventory;
+use Closure;
+use Illuminate\Http\Request;
+use Illuminate\Support\Facades\DB;
+use LogicException;
+use Symfony\Component\HttpFoundation\Response;
+
+/**
+ * ログイン手段を減らす操作の前に「実行後も最低 1 つ手段が残る」ことを保証する関門。
+ * alias: `ensure-login-method`。
+ *
+ * **評価するのは現在状態ではなく「操作が成功した後の投影状態」**。
+ * 素朴に現在を数えると削除対象自身が残存手段として数えられ、
+ * 「唯一の passkey を削除できてしまう」= 意図と正反対の挙動になる。
+ *
+ * **直列化規約 (TOCTOU 対策)**:
+ *   投影が正しくても、確認と削除が別トランザクションなら破れる
+ *   (passkey 2 件のユーザーが別々の passkey を同時削除 → 両方が「もう片方が残る」と判定 → 0 件)。
+ *   そこで本 middleware が
+ *     (1) DB::transaction() を開き
+ *     (2) 対象 User 行を lockForUpdate() で取得し
+ *     (3) **ロック取得後に** 投影を評価し
+ *     (4) **同一トランザクション内で $next() を実行**して vendor の削除まで完了させる。
+ *   ロック取得順序は User → credential に固定する。
+ *   本アプリのドメイン固有規約 1「シナリオ整合の共有ロック規約」と同型の作法。
+ *
+ * **単一の直列化点であること**が不変条件であり、
+ * tests/Architecture/LoginMethodRemovalRouteTest が deny-by-default で強制する
+ * (付与漏れだけでなく **allowlist 外 route への付与**も fail させる)。
+ *
+ * ⚠ **適用条件 (この middleware を新しい route に付ける前に必ず読むこと)**:
+ *   `$next()` を transaction 内で実行するため、controller だけでなく
+ *   **同期 event listener / Responsable 変換 / redirect + flash** まで transaction に入る。
+ *   したがって次を含む route には付けてはならない:
+ *     - streamed / downloadable response (transaction を長時間保持する)
+ *     - 外部 I/O (HTTP・S3 等。ロック保持中に外部レイテンシを持ち込む)
+ *     - `afterCommit` でない queue dispatch (ロールバック時に job だけ残る)
+ *   これらが必要な route を保護する場合は、本 middleware の transaction 方式を
+ *   「Service 内 transaction + 判定の再評価」へ再設計すること。
+ */
+final class EnsureLoginMethodRemains
+{
+    public function __construct(
+        private readonly LoginMethodInventory $inventory,
+    ) {}
+
+    public function handle(Request $request, Closure $next): Response
+    {
+        $user = $request->user();
+        if (! $user instanceof User) {
+            return $this->pass($next, $request);   // 未認証は auth middleware の責務
+        }
+
+        return DB::transaction(function () use ($request, $next, $user): Response {
+            // (2) 対象 User 行をロック (以降の投影評価はこのロック下でのみ有効)
+            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
+
+            // (3) ロック取得後に投影を評価する
+            $remaining = $this->inventory->remainingAfter($locked, $this->removalFor($request, $locked));
+
+            if ($remaining->isEmpty()) {
+                return $this->reject($request);
+            }
+
+            // (4) 同一トランザクション内で削除まで完了させる
+            return $this->pass($next, $request);
+        });
+    }
+
+    /**
+     * route から「今から何を除去しようとしているか」を決める。
+     *
+     * 対象 passkey が当該 User に属することは **binder が 404 で確定済み**
+     * (App\Http\Routing\SelfScopedPasskeyBinder)。DTO 側でも二重に assert する。
+     */
+    private function removalFor(Request $request, User $user): LoginMethodRemoval
+    {
+        $passkey = $request->route('passkey');
+        if ($passkey instanceof Passkey) {
+            return LoginMethodRemoval::passkey($passkey, $user);
+        }
+
+        // 将来の除去 route (password 削除 / SSO 解除) はここに分岐を足す。
+        // 未知の除去 route を素通しさせないため fail-closed で落とす
+        // (LoginMethodRemovalRouteTest が「middleware を付けたのに分岐が無い」を先に検出する)。
+        throw new LogicException(
+            'EnsureLoginMethodRemains: 除去対象を決定できない route です。removalFor() に分岐を追加してください。',
+        );
+    }
+
+    /**
+     * 拒否応答。
+     *
+     * **Inertia には 422 JSON を返さない** (Inertia protocol 違反になり、
+     * router が応答を解釈できず無言失敗する)。Inertia は 302 + errors を native に
+     * 処理するため `back()->withErrors()` にして Svelte 側は `$page.props.errors` で読む。
+     * 禁止事項 7 (操作系 POST は `back()->with(...)` で完結) とも整合する。
+     *
+     * 判別子に `expectsJson()` を使えるのは、Inertia が
+     * `Accept: text/html, application/xhtml+xml` を送るため (X-Inertia は立つが Accept は HTML)。
+     * 純粋な XHR (fetch + Accept: application/json) のみ 422 JSON になる。
+     */
+    private function reject(Request $request): Response
+    {
+        $dto = new LoginMethodRequiredDto(
+            message: 'この操作を行うと、ログインする手段がなくなります。先に別のログイン手段（パスワードの設定、ソーシャル連携、他のパスキー）を追加してください。',
+            settingsUrl: route('settings.security'),
+        );
+
+        if ($request->expectsJson()) {
+            return LoginMethodRequiredResource::make($dto)
+                ->response()
+                ->setStatusCode(422)
+                ->withHeaders(['Cache-Control' => 'no-store']);
+        }
+
+        return back()->withErrors(['login_method' => $dto->message]);
+    }
+
+    /**
+     * @param  Closure(Request): mixed  $next
+     */
+    private function pass(Closure $next, Request $request): Response
+    {
+        $response = $next($request);
+        if (! $response instanceof Response) {
+            throw new LogicException('Expected Symfony Response from middleware $next, got '.get_debug_type($response));
+        }
+
+        return $response;
+    }
+}
diff --git a/app/Http/Middleware/NoStoreResponse.php b/app/Http/Middleware/NoStoreResponse.php
new file mode 100644
index 0000000..381a1a4
--- /dev/null
+++ b/app/Http/Middleware/NoStoreResponse.php
@@ -0,0 +1,39 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Middleware;
+
+use Closure;
+use Illuminate\Http\Request;
+use Symfony\Component\HttpFoundation\Response;
+
+/**
+ * 応答に `no-store` を無条件で保証する middleware。alias: `no-store`。
+ *
+ * NoStoreCacheHeadersForAuthenticatedPages は「認証済みか」で対象を決めるため、
+ * **guest route** は対象外になる。WebAuthn の challenge (random_bytes(32)) を載せる
+ * `passkey.login-options` は guest route であり、キャッシュされると challenge の
+ * 使い回しやログイン導線の破綻を招くため個別に保証する。
+ *
+ * 既に `no-store` を持つ応答は書き換えない (directive が縮む方向の上書きをしない)。
+ * 付与対象は tests/Architecture/PasskeyRouteProtectionTest が固定する。
+ */
+final class NoStoreResponse
+{
+    /**
+     * @param  Closure(Request): Response  $next
+     */
+    public function handle(Request $request, Closure $next): Response
+    {
+        $response = $next($request);
+
+        if ($response->headers->hasCacheControlDirective('no-store')) {
+            return $response;
+        }
+
+        $response->headers->set('Cache-Control', 'no-store, private');
+
+        return $response;
+    }
+}
diff --git a/app/Http/Resources/Auth/LoginMethodRequiredResource.php b/app/Http/Resources/Auth/LoginMethodRequiredResource.php
new file mode 100644
index 0000000..959eb40
--- /dev/null
+++ b/app/Http/Resources/Auth/LoginMethodRequiredResource.php
@@ -0,0 +1,35 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Resources\Auth;
+
+use App\DataTransferObjects\Auth\LoginMethodRequiredDto;
+use Illuminate\Http\Request;
+use Illuminate\Http\Resources\Json\JsonResource;
+
+/**
+ * ログイン手段保持 guard の拒否ボディ ({ code, message, settingsUrl })。
+ *
+ * `response()->json()` 直接使用を避けるための JsonResource (禁止事項 4)。
+ * no-store ヘッダは middleware 側で付与する。`data` ラップはしない (top-level)。
+ *
+ * @property-read LoginMethodRequiredDto $resource
+ */
+final class LoginMethodRequiredResource extends JsonResource
+{
+    /** @var string|null */
+    public static $wrap = null;
+
+    /**
+     * @return array{code: 'login_method_required', message: string, settingsUrl: string}
+     */
+    public function toArray(Request $request): array
+    {
+        return [
+            'code' => LoginMethodRequiredDto::CODE,
+            'message' => $this->resource->message,
+            'settingsUrl' => $this->resource->settingsUrl,
+        ];
+    }
+}
diff --git a/app/Http/Resources/Auth/PasskeyListItemResource.php b/app/Http/Resources/Auth/PasskeyListItemResource.php
new file mode 100644
index 0000000..41d2aa7
--- /dev/null
+++ b/app/Http/Resources/Auth/PasskeyListItemResource.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Resources\Auth;
+
+use App\DataTransferObjects\Auth\PasskeyListItemDto;
+use Illuminate\Http\Request;
+use Illuminate\Http\Resources\Json\JsonResource;
+
+/**
+ * Settings/Security の passkey 一覧 1 件分 (Inertia prop)。
+ *
+ * `data` ラップはしない (Inertia prop は plain array で渡す)。
+ *
+ * @property-read PasskeyListItemDto $resource
+ */
+final class PasskeyListItemResource extends JsonResource
+{
+    /** @var string|null */
+    public static $wrap = null;
+
+    /**
+     * @return array{id: int, name: string, authenticator: string|null, lastUsedAt: string|null, createdAt: string|null}
+     */
+    public function toArray(Request $request): array
+    {
+        return [
+            'id' => $this->resource->id,
+            'name' => $this->resource->name,
+            'authenticator' => $this->resource->authenticator,
+            'lastUsedAt' => $this->resource->lastUsedAt,
+            'createdAt' => $this->resource->createdAt,
+        ];
+    }
+}
diff --git a/app/Http/Resources/Auth/PasskeyLoginRedirectResource.php b/app/Http/Resources/Auth/PasskeyLoginRedirectResource.php
new file mode 100644
index 0000000..e3904b5
--- /dev/null
+++ b/app/Http/Resources/Auth/PasskeyLoginRedirectResource.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Resources\Auth;
+
+use App\DataTransferObjects\Auth\PasskeyLoginRedirectDto;
+use Illuminate\Http\Request;
+use Illuminate\Http\Resources\Json\JsonResource;
+
+/**
+ * passkey ログイン成功の JSON ボディ ({ redirect })。
+ *
+ * `response()->json()` 直接使用を避けるための JsonResource (禁止事項 4)。
+ * `data` ラップはしない (top-level)。
+ *
+ * @property-read PasskeyLoginRedirectDto $resource
+ */
+final class PasskeyLoginRedirectResource extends JsonResource
+{
+    /** @var string|null */
+    public static $wrap = null;
+
+    /**
+     * @return array{redirect: string}
+     */
+    public function toArray(Request $request): array
+    {
+        return ['redirect' => $this->resource->redirect];
+    }
+}
diff --git a/app/Http/Responses/Passkey/PasskeyConfirmationResponse.php b/app/Http/Responses/Passkey/PasskeyConfirmationResponse.php
new file mode 100644
index 0000000..64c904c
--- /dev/null
+++ b/app/Http/Responses/Passkey/PasskeyConfirmationResponse.php
@@ -0,0 +1,51 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Responses\Passkey;
+
+use Illuminate\Http\Request;
+use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
+use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
+
+/**
+ * passkey による step-up 確認の応答 (vendor contract の差し替え)。
+ *
+ * vendor の PasskeyConfirmationController::store() は
+ * `$session->passwordConfirmed()` を呼び **Fortify の auth.password_confirmed_at を書く**。
+ * 本アプリは RecentAuthState の契約で「Fortify の鍵には書かない (意味汚染・権限漏れ回避)」
+ * としており、将来 password.confirm を使う route が生えると passkey confirm が
+ * それを黙って満たす潜在的な権限漏れになる。
+ *
+ * controller 実行後・session 保存前である本メソッドで確実に除去する
+ * (Response 差し替えがアプリ責務である理由がこの継ぎ目)。
+ * 鮮度そのものは StampRecentAuthOnPasskeyVerified が recent_auth_at へ既に打っている。
+ *
+ * 応答契約は recent-auth.password (ConfirmRecentAuthController::confirmPassword) と揃える
+ * (Inertia = intended redirect / 素の XHR = 204 + no-store)。
+ */
+final class PasskeyConfirmationResponse implements PasskeyConfirmationResponseContract
+{
+    /**
+     * @param  Request  $request
+     */
+    public function toResponse($request): SymfonyResponse
+    {
+        $request->session()->forget('auth.password_confirmed_at');
+
+        // RequireRecentAuth の 302 fallback が積んだ one-shot flag は必ず消費する
+        // (両経路で消費し、次回 step-up に持ち越さない。ConfirmRecentAuthController と同契約)。
+        $droppedMutation = $request->session()->pull('recent_auth.dropped_mutation') === true;
+
+        if ($request->hasHeader('X-Inertia')) {
+            $redirect = redirect()->intended(route('dashboard'));
+            if ($droppedMutation) {
+                $redirect->with('info', '再認証が完了しました。先ほどの操作はまだ実行されていません。お手数ですがもう一度操作してください。');
+            }
+
+            return $redirect;
+        }
+
+        return response()->noContent()->withHeaders(['Cache-Control' => 'no-store, private']);
+    }
+}
diff --git a/app/Http/Responses/Passkey/PasskeyDeletedResponse.php b/app/Http/Responses/Passkey/PasskeyDeletedResponse.php
new file mode 100644
index 0000000..a75b1d0
--- /dev/null
+++ b/app/Http/Responses/Passkey/PasskeyDeletedResponse.php
@@ -0,0 +1,30 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Responses\Passkey;
+
+use Illuminate\Http\Request;
+use Laravel\Passkeys\Contracts\PasskeyDeletedResponse as PasskeyDeletedResponseContract;
+use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
+
+/**
+ * passkey 削除完了 (vendor contract の差し替え)。
+ *
+ * vendor 既定は `new JsonResponse(status: 204)` を直に返し禁止事項 4 に触れる。
+ * transport 契約 (詳細設計 4-d) により削除は Inertia の `router.delete` で送るため、
+ * `back()->with('success')` で一覧 prop を再取得させる。
+ *
+ * ⚠ 本応答は EnsureLoginMethodRemains が開いた **transaction の中**で生成される
+ * (middleware が $next() を transaction 内で実行するため)。外部 I/O を持ち込まないこと。
+ */
+final class PasskeyDeletedResponse implements PasskeyDeletedResponseContract
+{
+    /**
+     * @param  Request  $request
+     */
+    public function toResponse($request): SymfonyResponse
+    {
+        return back()->with('success', 'パスキーを削除しました。');
+    }
+}
diff --git a/app/Http/Responses/Passkey/PasskeyLoginResponse.php b/app/Http/Responses/Passkey/PasskeyLoginResponse.php
new file mode 100644
index 0000000..7dd6187
--- /dev/null
+++ b/app/Http/Responses/Passkey/PasskeyLoginResponse.php
@@ -0,0 +1,45 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Responses\Passkey;
+
+use App\DataTransferObjects\Auth\PasskeyLoginRedirectDto;
+use App\Http\Resources\Auth\PasskeyLoginRedirectResource;
+use Illuminate\Http\Request;
+use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
+use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
+
+/**
+ * passkey ログイン完了 (vendor contract の差し替え)。
+ *
+ * vendor 既定は `config('passkeys.redirect')` へ redirect するだけで、
+ * transport 契約 (詳細設計 4-d) の「client は fetch で送り着地 URL を受け取る」に合わない。
+ * **JSON `{redirect}` を返す**のが主経路で、client は受け取った URL へ
+ * window.location.assign する。
+ *
+ * **ログイン直後フロー**のため `redirect()->intended()` が許される数少ない経路
+ * (禁止事項 7 の例外条件)。着地は Fortify 標準ログイン
+ * (App\Http\Responses\Fortify\LoginResponse) と揃える。
+ * DTO + JsonResource 経由にして `response()->json()` 直書きを避ける (禁止事項 4)。
+ */
+final class PasskeyLoginResponse implements PasskeyLoginResponseContract
+{
+    /**
+     * @param  Request  $request
+     */
+    public function toResponse($request): SymfonyResponse
+    {
+        // intended() は session の url.intended を pull するため 1 度しか読めない。
+        // JSON / redirect のどちらの分岐でも同じ着地になるよう先に確定させる。
+        $target = redirect()->intended(config()->string('fortify.home'))->getTargetUrl();
+
+        if ($request->expectsJson()) {
+            return PasskeyLoginRedirectResource::make(new PasskeyLoginRedirectDto(redirect: $target))
+                ->response($request)
+                ->withHeaders(['Cache-Control' => 'no-store, private']);
+        }
+
+        return redirect()->to($target);
+    }
+}
diff --git a/app/Http/Responses/Passkey/PasskeyRegistrationResponse.php b/app/Http/Responses/Passkey/PasskeyRegistrationResponse.php
new file mode 100644
index 0000000..f39f08e
--- /dev/null
+++ b/app/Http/Responses/Passkey/PasskeyRegistrationResponse.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Responses\Passkey;
+
+use Illuminate\Http\Request;
+use Laravel\Passkeys\Contracts\PasskeyRegistrationResponse as PasskeyRegistrationResponseContract;
+use Laravel\Passkeys\Passkey as VendorPasskey;
+use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
+
+/**
+ * passkey 登録完了 (vendor contract の差し替え)。
+ *
+ * vendor 既定は `new JsonResponse([...])` を直に返し禁止事項 4 に触れる。
+ * transport 契約 (詳細設計 4-d) により登録は Inertia の `router.post` で送るため、
+ * `back()->with('success')` で一覧 prop を再取得させる (既存 2FA カードと同じ作法)。
+ * 操作系 POST のため `redirect()->intended()` は使わない (禁止事項 7)。
+ *
+ * 成功 toast はサーバ flash を単一の源とし、client 側で楽観 toast を出さない
+ * (リカバリコード再生成と同じ二重発火回避)。
+ */
+final class PasskeyRegistrationResponse implements PasskeyRegistrationResponseContract
+{
+    /**
+     * vendor contract が要求する setter。
+     *
+     * 登録された passkey は **応答本文に載せない** (一覧は Inertia prop =
+     * SecurityController が単一の源)。保持する必要が無いため保存もしない。
+     */
+    public function withPasskey(VendorPasskey $passkey): static
+    {
+        return $this;
+    }
+
+    /**
+     * @param  Request  $request
+     */
+    public function toResponse($request): SymfonyResponse
+    {
+        return back()->with('success', 'パスキーを登録しました。');
+    }
+}
diff --git a/app/Http/Routing/RouteBindingTypes.php b/app/Http/Routing/RouteBindingTypes.php
index 234fa1e..856f5c8 100644
--- a/app/Http/Routing/RouteBindingTypes.php
+++ b/app/Http/Routing/RouteBindingTypes.php
@@ -121,7 +121,13 @@ final class RouteBindingTypes
      *
      * @var array<string, class-string>
      */
-    public const CUSTOM_BINDER = ['organization' => MembershipScopedOrganizationBinder::class];
+    public const CUSTOM_BINDER = [
+        'organization' => MembershipScopedOrganizationBinder::class,
+        // {passkey} は Fortify (vendor) が登録する route の param。app 側から
+        // Route::pattern を掛けると vendor の route 定義変更に追随できないため、
+        // binder が「認証ユーザー所有 + 数値正規化」を担う (他人の passkey は 404)。
+        'passkey' => SelfScopedPasskeyBinder::class,
+    ];
 
     /**
      * モデル binding ではない文字列 param。型制約の対象外。
diff --git a/app/Http/Routing/SelfScopedPasskeyBinder.php b/app/Http/Routing/SelfScopedPasskeyBinder.php
new file mode 100644
index 0000000..d9e3679
--- /dev/null
+++ b/app/Http/Routing/SelfScopedPasskeyBinder.php
@@ -0,0 +1,87 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Routing;
+
+use App\Models\Passkey;
+use App\Models\User;
+use Illuminate\Database\Eloquent\ModelNotFoundException;
+use Illuminate\Routing\Route;
+use Illuminate\Support\Facades\Auth;
+
+/**
+ * `{passkey}` route binding を「認証済み web ユーザー所有の passkey」にスコープして解決する
+ * explicit binder。
+ *
+ * **差し替える理由 (セキュリティ不変条件 2「子は親に属する = 認可より前に 404」)**:
+ * vendor の binder (Laravel\Passkeys\PasskeysServiceProvider::registerRouteBindings) は
+ * `app($model)->resolveRouteBinding($value)` でグローバルに id 解決するため、
+ * controller の `abort_unless($passkey->user_id === $user->getKey(), 403)` に到達し
+ * **他人の passkey の存在を 403/404 の差で漏らす**。所有者スコープで解決すれば
+ * 「他人の passkey」と「存在しない id」が等しく 404 になる。
+ *
+ * 併せて 22P02 (pgsql invalid_text_representation) 対策も担う。`{passkey}` は
+ * RouteBindingTypes::CUSTOM_BINDER 分類のため Route::pattern による宣言的な数値制約を
+ * 掛けない (vendor route の param に app 側から pattern を掛けると vendor 側の
+ * route 定義変更に追随できない)。代わりに本 binder が非数値・範囲外を 404 に倒す。
+ *
+ * 登録は PasskeyServiceProvider::boot() の `$this->app->booted()` から
+ * `Route::bind('passkey', self::class)` で行い、vendor provider の boot に**後勝ち**させる。
+ */
+final class SelfScopedPasskeyBinder implements NormalizesRouteBindingInput
+{
+    /**
+     * @throws ModelNotFoundException<Passkey>
+     */
+    public function bind(mixed $value, ?Route $route = null): Passkey
+    {
+        $user = Auth::guard('web')->user();
+        if (! $user instanceof User) {
+            // auth middleware が先に 302/401 に倒すのが正常系。到達しても fail-closed で 404。
+            throw (new ModelNotFoundException)->setModel(Passkey::class);
+        }
+
+        $id = $this->normalizeIntegerId($value);
+        if ($id === null) {
+            throw (new ModelNotFoundException)->setModel(Passkey::class);
+        }
+
+        // 所有者スコープの where を **解決クエリ自体に**含める (取得後に弾くと 403/404 の
+        // 差で存在が漏れる)。App\Models\Passkey 型で返すためモデル直クエリを使う
+        // (relation は PasskeyUser interface の宣言により vendor 型で解決される)。
+        $passkey = Passkey::query()
+            ->whereKey($id)
+            ->where('user_id', $user->getKey())
+            ->first();
+
+        if (! $passkey instanceof Passkey) {
+            throw (new ModelNotFoundException)->setModel(Passkey::class);
+        }
+
+        return $passkey;
+    }
+
+    /**
+     * route 引数を bigint PK として安全な int に正規化する。
+     * 非数値・bigint 範囲外は「存在し得ない id」として null を返し 404 に倒す
+     * (MembershipScopedOrganizationBinder と同じ作法)。
+     */
+    private function normalizeIntegerId(mixed $value): ?int
+    {
+        if (is_int($value)) {
+            return $value >= 0 ? $value : null;
+        }
+
+        if (! is_string($value) || ! ctype_digit($value)) {
+            return null;
+        }
+
+        // bigint 上限を超える桁は DB へ渡さない (22003 numeric_value_out_of_range 回避)
+        if (strlen(ltrim($value, '0')) > 18) {
+            return null;
+        }
+
+        return (int) $value;
+    }
+}
diff --git a/app/Listeners/Auth/ClearRecentAuthOnPasskeyChange.php b/app/Listeners/Auth/ClearRecentAuthOnPasskeyChange.php
new file mode 100644
index 0000000..a7b83c6
--- /dev/null
+++ b/app/Listeners/Auth/ClearRecentAuthOnPasskeyChange.php
@@ -0,0 +1,59 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Listeners\Auth;
+
+use App\Security\RecentAuthState;
+use Laravel\Passkeys\Events\PasskeyDeleted;
+use Laravel\Passkeys\Events\PasskeyRegistered;
+
+/**
+ * credential 集合の変化 = recent-auth 失効 (2026-08-04 裁定 A)。
+ *
+ * パスキーは単独でログインできる強い資格であり、集合が変わったら
+ * 直前に済ませた本人確認は失効させる、という家系統一原則
+ * (統一原則のほうが複数年の保守で分類漏れ事故を生みにくく、UX の実害は
+ *  登録直後のタップ 1 回程度、という Codex 判定 A に基づくオーナー裁定)。
+ *
+ * **強化オプション (新規登録直後のパスキーを即 re-step-up の satisfier に使えなくする) は
+ * 裁定で明示的に見送られている。実装しないこと。**
+ * 再検討条件: パスキーが 2FA 準拠判定に算入される時、または放置端末起点の実被害が観測された時。
+ *
+ * 本 listener は RecentAuthState::clear() の初の production 利用者である
+ * (docblock は「認証要素変更後に失効させる」と宣言していたが呼び出し元が無かった)。
+ *
+ * ⚠ EnsureLoginMethodRemains がトランザクション内で $next() を実行するため、
+ * PasskeyDeleted はそのトランザクション内で発火する。ロールバック時には
+ * session 側の clear() だけが残りうるが、これは「再認証を余計に 1 回要求する」
+ * 方向の誤差であり fail-safe。
+ *
+ * ⚠ 本 listener は HTTP session を前提とする。将来これらのイベントが
+ * CLI / queue / admin cleanup から発火しても壊れないよう session の利用可否を確認する
+ * (既存 UpdateUserPassword::deleteOtherSessionRecords() と同じガード作法)。
+ */
+final class ClearRecentAuthOnPasskeyChange
+{
+    public function __construct(
+        private readonly RecentAuthState $recentAuthState,
+    ) {}
+
+    public function handleRegistered(PasskeyRegistered $event): void
+    {
+        $this->clearIfSessionAvailable();
+    }
+
+    public function handleDeleted(PasskeyDeleted $event): void
+    {
+        $this->clearIfSessionAvailable();
+    }
+
+    private function clearIfSessionAvailable(): void
+    {
+        if (! app()->bound('session') || ! session()->isStarted()) {
+            return;   // CLI / queue 文脈では session 操作をしない
+        }
+
+        $this->recentAuthState->clear();
+    }
+}
diff --git a/app/Listeners/Auth/StampRecentAuthOnLogin.php b/app/Listeners/Auth/StampRecentAuthOnLogin.php
index 233c284..f520f86 100644
--- a/app/Listeners/Auth/StampRecentAuthOnLogin.php
+++ b/app/Listeners/Auth/StampRecentAuthOnLogin.php
@@ -24,9 +24,12 @@
  *
  * ⚠ 重要: 本 listener は「web guard の Login が全て credential-presentation である」前提に立つ。
  *   現行コードの web guard login は (1) Fortify password (2) Fortify TOTP (3) SSO
- *   Auth::login() の 3 種のみ。**将来 web guard に loginUsingId / impersonation /
- *   magic-link 等の非 credential login を追加する場合は、本 listener がそれらも fresh 扱いして
- *   しまうため必ず見直すこと**。
+ *   Auth::login() (4) passkey (PasskeyLoginController::store の $guard->login()) の 4 種のみ。
+ *   (4) は WebAuthn の user verification (生体 / PIN) を伴うため credential-presentation
+ *   として fresh 扱いしてよい (passkey login 可否そのものは PasskeyLoginPolicy が
+ *   ログイン成立前に判定する)。
+ *   **将来 web guard に loginUsingId / impersonation / magic-link 等の非 credential login を
+ *   追加する場合は、本 listener がそれらも fresh 扱いしてしまうため必ず見直すこと**。
  */
 final class StampRecentAuthOnLogin
 {
diff --git a/app/Listeners/Auth/StampRecentAuthOnPasskeyVerified.php b/app/Listeners/Auth/StampRecentAuthOnPasskeyVerified.php
new file mode 100644
index 0000000..d12e000
--- /dev/null
+++ b/app/Listeners/Auth/StampRecentAuthOnPasskeyVerified.php
@@ -0,0 +1,63 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Listeners\Auth;
+
+use App\Models\User;
+use App\Security\RecentAuthState;
+use Laravel\Passkeys\Events\PasskeyVerified;
+
+/**
+ * passkey 検証成立を recent-auth の satisfier として stamp する。
+ *
+ * ⚠ PasskeyVerified は VerifyPasskey::__invoke() の**中**で dispatch されるため、
+ * **login 経路と confirm 経路の両方**で発火する。経路ごとの最終 session state:
+ *
+ *   | 経路                  | 発火順                        | 最終 recent_auth_method |
+ *   |-----------------------|-------------------------------|-------------------------|
+ *   | passkey login         | PasskeyVerified → Login        | 'login' (後勝ち)        |
+ *   | passkey confirm       | PasskeyVerified のみ           | 'passkey'               |
+ *   | passkey 登録 / 削除   | PasskeyRegistered / Deleted    | 未設定 (clear 済み)     |
+ *
+ * login 経路では StampRecentAuthOnLogin が後勝ちで 'login' を書く。最終状態は決定的だが、
+ * 順序に依存するため RecentAuthMethodStampingTest が経路別に固定する。
+ *
+ * ⚠ **本人性バインドが必須**。VerifyPasskey は
+ * `Passkeys::allowsLogin()` の判定 **より前** に PasskeyVerified を dispatch する。
+ * 素朴に stamp すると、TOTP 有効ユーザーの passkey login が
+ * PasskeyLoginPolicy に deny された場合でも、**guest session に鮮度が残る**。
+ * そこで「検証された passkey が **現在ログイン中ユーザー** のものである場合のみ stamp する」
+ * (SocialAuthController::completeStepUp の本人性バインドと同じ作法)。
+ *   - confirm 経路: 認証済みユーザー本人の passkey → stamp される (期待どおり)
+ *   - login 経路:   その時点では guest → stamp されない。
+ *                   ログイン成立後の鮮度は StampRecentAuthOnLogin が担うため欠落しない
+ *   - deny 経路:    guest のまま終わるので鮮度は残らない (fail-closed)
+ */
+final class StampRecentAuthOnPasskeyVerified
+{
+    public function __construct(
+        private readonly RecentAuthState $recentAuthState,
+    ) {}
+
+    public function handle(PasskeyVerified $event): void
+    {
+        $current = request()->user();
+        if (! $current instanceof User) {
+            return;   // guest (login 経路 / deny 経路) では stamp しない
+        }
+
+        // 他人の credential での round-trip 完了を成立させない。
+        // 型を確定できない値は「一致しない」に倒す (fail-closed)。
+        $passkeyUserId = $event->passkey->getAttribute('user_id');
+        $currentKey = $current->getKey();
+        if (! is_scalar($passkeyUserId) || ! is_scalar($currentKey)) {
+            return;
+        }
+        if ((string) $passkeyUserId !== (string) $currentKey) {
+            return;
+        }
+
+        $this->recentAuthState->confirm(method: 'passkey');
+    }
+}
diff --git a/app/Models/Passkey.php b/app/Models/Passkey.php
new file mode 100644
index 0000000..c2454c1
--- /dev/null
+++ b/app/Models/Passkey.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Models;
+
+use Database\Factories\PasskeyFactory;
+use Illuminate\Database\Eloquent\Factories\HasFactory;
+use Illuminate\Support\Carbon;
+use Laravel\Passkeys\Passkey as BasePasskey;
+
+/**
+ * vendor モデル (Laravel\Passkeys\Passkey) の app サブクラス。
+ *
+ * 差し替える理由:
+ *   1. Factory の置き場所 (AGENTS.md: テストデータは必ず Factory で生成 / 新規モデルは Factory 必須)
+ *   2. アプリ側の型として route binding / DTO で扱えるようにする
+ *
+ * 差し替えは PasskeyServiceProvider::register() の Passkeys::usePasskeyModel() で行う。
+ * credential 本体 (公開鍵 / signature counter) は vendor の cast (json) が扱う。
+ *
+ * カラムの型は vendor の Laravel\Passkeys\Passkey が class docblock で宣言しているが、
+ * larastan の model property 解決は継承元の docblock を引き継がないため、
+ * サブクラス側で明示する (vendor の宣言と一致させること)。
+ *
+ * @property int $id
+ * @property int $user_id
+ * @property string $name
+ * @property string $credential_id
+ * @property array<string, mixed> $credential
+ * @property Carbon|null $last_used_at
+ * @property Carbon|null $created_at
+ * @property Carbon|null $updated_at
+ * @property-read string|null $authenticator
+ *
+ * @use HasFactory<PasskeyFactory>
+ */
+final class Passkey extends BasePasskey
+{
+    /** @use HasFactory<PasskeyFactory> */
+    use HasFactory;
+
+    /** vendor と同一テーブル (publish 済み migration の passkeys) */
+    protected $table = 'passkeys';
+
+    protected static function newFactory(): PasskeyFactory
+    {
+        return PasskeyFactory::new();
+    }
+}
diff --git a/app/Models/User.php b/app/Models/User.php
index ea23000..c52780a 100644
--- a/app/Models/User.php
+++ b/app/Models/User.php
@@ -17,6 +17,8 @@
 use Laratrust\Contracts\LaratrustUser;
 use Laratrust\Traits\HasRolesAndPermissions;
 use Laravel\Fortify\TwoFactorAuthenticatable;
+use Laravel\Passkeys\Contracts\PasskeyUser;
+use Laravel\Passkeys\PasskeyAuthenticatable;
 use Laravel\Passport\Contracts\OAuthenticatable;
 use Laravel\Passport\HasApiTokens;
 use ParagonIE\CipherSweet\BlindIndex;
@@ -25,13 +27,13 @@
 use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
 use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
 
-class User extends Authenticatable implements CipherSweetEncrypted, LaratrustUser, MustVerifyEmail, OAuthenticatable
+class User extends Authenticatable implements CipherSweetEncrypted, LaratrustUser, MustVerifyEmail, OAuthenticatable, PasskeyUser
 {
     // Passport OAuth guard (mcp-oauth / api-oauth) が withAccessToken() / token() を要求する
     use HasApiTokens;
 
     /** @use HasFactory<UserFactory> */
-    use HasFactory, HasRolesAndPermissions, Notifiable, TwoFactorAuthenticatable, UsesCipherSweet;
+    use HasFactory, HasRolesAndPermissions, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable, UsesCipherSweet;
 
     /**
      * PII (email / name) は CipherSweet で暗号化するため、平文 where() では検索できない。
@@ -84,6 +86,20 @@ public function socialAccounts(): HasMany
         return $this->hasMany(SocialAccount::class);
     }
 
+    /*
+     * 登録済みパスキー (WebAuthn credential) の relation `passkeys()` は
+     * PasskeyAuthenticatable trait が供給する (実体クラスは
+     * PasskeyServiceProvider::register() の Passkeys::usePasskeyModel() で
+     * App\Models\Passkey に差し替え済み)。
+     *
+     * **app モデル型へ narrowing した override は置かない**: PasskeyUser interface が
+     * `HasMany<Laravel\Passkeys\Passkey, Model>` を宣言しており、HasMany の型引数は
+     * 不変 (covariant 宣言が無い) ため、narrowing は PHPStan level 10 の
+     * method.childReturnType になる。App\Models\Passkey 型が必要な箇所
+     * (SecurityController / SelfScopedPasskeyBinder) は Passkey モデルを
+     * user_id スコープで直接クエリする。
+     */
+
     /**
      * password が設定されているか (recent-auth の password satisfier 可否)。
      *
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index bd8502a..39c946e 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -9,7 +9,9 @@
 use App\Http\Routing\MembershipScopedOrganizationBinder;
 use App\Http\Routing\RouteBindingTypes;
 use App\Listeners\Audit\RejectNonCriticalAudit;
+use App\Listeners\Auth\ClearRecentAuthOnPasskeyChange;
 use App\Listeners\Auth\StampRecentAuthOnLogin;
+use App\Listeners\Auth\StampRecentAuthOnPasskeyVerified;
 use App\Listeners\Billing\MarkBillingNotificationDelivered;
 use App\Listeners\Mail\FilterSuppressedRecipients;
 use App\Listeners\RecordLlmCallCost;
@@ -57,6 +59,9 @@
 use Kent013\PrismPrompt\Events\PromptExecutionFailed;
 use Laravel\Cashier\Cashier;
 use Laravel\Cashier\Events\WebhookReceived;
+use Laravel\Passkeys\Events\PasskeyDeleted;
+use Laravel\Passkeys\Events\PasskeyRegistered;
+use Laravel\Passkeys\Events\PasskeyVerified;
 use OwenIt\Auditing\Events\Auditing;
 use Stripe\Service\PriceService;
 use Webmozart\Assert\Assert;
@@ -191,6 +196,15 @@ public function boot(): void
         // ログイン成功 → recent-auth スタンプ (機微操作 step-up の起点)
         Event::listen(Login::class, StampRecentAuthOnLogin::class);
 
+        // passkey 検証成立 → recent-auth satisfier (confirm 経路。login 経路では Login が後勝ち)。
+        // 本人性バインド (検証された credential が現在ログイン中ユーザーのものか) は listener 側。
+        Event::listen(PasskeyVerified::class, StampRecentAuthOnPasskeyVerified::class);
+
+        // credential 集合の変化 → recent-auth 失効 (2026-08-04 裁定 A)。
+        // パスキーは単独でログインできる強い資格のため、増減したら直前の本人確認を失効させる。
+        Event::listen(PasskeyRegistered::class, [ClearRecentAuthOnPasskeyChange::class, 'handleRegistered']);
+        Event::listen(PasskeyDeleted::class, [ClearRecentAuthOnPasskeyChange::class, 'handleDeleted']);
+
         // 通知送信完了 → 課金通知の配信済みマーク (renewal reminder 等の再送抑止)
         Event::listen(NotificationSent::class, MarkBillingNotificationDelivered::class);
 
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index da71856..24c2318 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -180,6 +180,18 @@ private function configureRateLimiters(): void
 
             return Limit::perMinute(5)->by(is_scalar($loginId) ? (string) $loginId : $request->ip().'|2fa');
         });
+
+        // passkey (WebAuthn) endpoint。config/fortify.php の limiters.passkeys が
+        // この名前を指しており、未設定だと Fortify が throttle 自体を外す
+        // (= 未認証の challenge 発行 GET /passkeys/login/options が無制限になる)。
+        // 未認証の login-options を含むため、認証済みは user 単位・未認証は IP 単位で絞る。
+        RateLimiter::for('passkeys', function (Request $request) {
+            $identifier = $request->user()?->getAuthIdentifier();
+
+            return Limit::perMinute(10)->by(
+                is_scalar($identifier) ? 'passkey|'.$identifier : 'passkey|'.$request->ip(),
+            );
+        });
     }
 
     /**
diff --git a/app/Providers/PasskeyServiceProvider.php b/app/Providers/PasskeyServiceProvider.php
new file mode 100644
index 0000000..59ae756
--- /dev/null
+++ b/app/Providers/PasskeyServiceProvider.php
@@ -0,0 +1,140 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Providers;
+
+use App\Http\Responses\Passkey\PasskeyConfirmationResponse;
+use App\Http\Responses\Passkey\PasskeyDeletedResponse;
+use App\Http\Responses\Passkey\PasskeyLoginResponse;
+use App\Http\Responses\Passkey\PasskeyRegistrationResponse;
+use App\Http\Routing\SelfScopedPasskeyBinder;
+use App\Models\Passkey;
+use App\Models\User;
+use App\Services\Auth\PasskeyLoginPolicy;
+use Illuminate\Contracts\Foundation\Application;
+use Illuminate\Http\Request;
+use Illuminate\Routing\RouteCollectionInterface;
+use Illuminate\Routing\Router;
+use Illuminate\Support\Facades\Route;
+use Illuminate\Support\ServiceProvider;
+use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
+use Laravel\Passkeys\Contracts\PasskeyDeletedResponse as PasskeyDeletedResponseContract;
+use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
+use Laravel\Passkeys\Contracts\PasskeyRegistrationResponse as PasskeyRegistrationResponseContract;
+use Laravel\Passkeys\Contracts\PasskeyUser;
+use Laravel\Passkeys\Passkey as VendorPasskey;
+use Laravel\Passkeys\Passkeys;
+
+/**
+ * laravel/passkeys (Fortify 1.37 が推移依存で持つ) のアプリ側アダプタ。
+ *
+ * route / controller / action / migration は Fortify + laravel/passkeys が提供する
+ * (AGENTS.md 思考原則 1: フレームワークのレンジ内でやる)。本 Provider は
+ * 「アプリ固有の不変条件を vendor に被せる」ことだけを担う:
+ *
+ *   1. **binder 差し替え**: vendor の binder はグローバルに id 解決し、controller が
+ *      `abort_unless($passkey->user_id === $user->getKey(), 403)` で弾く。403 は
+ *      **他人の passkey の存在を漏らす**。認証ユーザーの passkeys() 経由に張り替えて
+ *      不整合を **認可より前に 404** にする (AGENTS.md セキュリティ不変条件 2)。
+ *   2. **Response contract 上書き**: vendor 既定は `new JsonResponse(...)` を直に返し
+ *      禁止事項 4 に触れる。Inertia / DTO+JsonResource へ差し替える。
+ *      あわせて confirm 経路が書く `auth.password_confirmed_at` を除去する
+ *      (RecentAuthState の契約「Fortify の鍵には書かない」を守る)。
+ *   3. **vendor route 加工**: recent-auth / ensure-login-method / no-store の後付け配線。
+ *   4. **login 認可**: TOTP 有効ユーザーの passkey login を拒否 (PasskeyLoginPolicy に委譲)。
+ *
+ * ⚠ **boot 順序**: Route::bind() は後勝ちだが、`bootstrap/providers.php` の順序は
+ * **app provider 間の順序**にすぎず、auto-discovery された package provider
+ * (Laravel\Passkeys\PasskeysServiceProvider) との最終 boot 順序を保証しない。
+ * したがって binder 差し替えも **`$this->app->booted()` の中で実行する**
+ * (= 全 provider の boot が終わった後に最終上書きする)。route middleware の後付けと
+ * 同じ「booted で最終上書き」の形に統一する。
+ * この順序依存は tests/Architecture/PasskeyPackageContractTest が
+ * 「binder の最終解決系がアプリ実装」+「他人の passkey は 404」で固定する。
+ */
+final class PasskeyServiceProvider extends ServiceProvider
+{
+    /** recent-auth を後付けする passkey route (credential 集合を触る管理経路) */
+    private const RECENT_AUTH_ROUTE_NAMES = [
+        'passkey.registration-options',
+        'passkey.store',
+        'passkey.destroy',
+    ];
+
+    /** ログイン手段を減らす passkey route */
+    private const LOGIN_METHOD_GUARD_ROUTE_NAMES = [
+        'passkey.destroy',
+    ];
+
+    public function register(): void
+    {
+        Passkeys::usePasskeyModel(Passkey::class);
+
+        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
+        $this->app->singleton(PasskeyConfirmationResponseContract::class, PasskeyConfirmationResponse::class);
+        $this->app->singleton(PasskeyRegistrationResponseContract::class, PasskeyRegistrationResponse::class);
+        $this->app->singleton(PasskeyDeletedResponseContract::class, PasskeyDeletedResponse::class);
+    }
+
+    public function boot(): void
+    {
+        $this->configureLoginAuthorization();
+
+        // binder と middleware は **全 provider boot 後** に最終上書きする
+        // (PasskeysServiceProvider::boot() の Route::bind に確実に後勝ちするため)。
+        $this->app->booted(static function (Application $app): void {
+            Route::bind('passkey', SelfScopedPasskeyBinder::class);
+            self::attachMiddlewareToPasskeyRoutes($app);
+        });
+    }
+
+    /**
+     * passkey login の最終ゲート。判定は PasskeyLoginPolicy に集約する
+     * (LoginMethodInventory と条件を二重定義しないため。closure にロジックを書かない)。
+     */
+    private function configureLoginAuthorization(): void
+    {
+        Passkeys::authorizeLoginUsing(static function (Request $request, PasskeyUser $user, VendorPasskey $passkey): bool {
+            if (! $user instanceof User) {
+                return false;   // fail-closed
+            }
+
+            return app(PasskeyLoginPolicy::class)->allowsPasskeyLogin($user);
+        });
+    }
+
+    /**
+     * Fortify が登録した passkey route へアプリ側 middleware を後付けする。
+     * FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() と同じ作法
+     * (route:cache 下でも CompiledRouteCollection の nameCache が同一 instance を返すため有効)。
+     */
+    private static function attachMiddlewareToPasskeyRoutes(Application $app): void
+    {
+        $routes = $app->make(Router::class)->getRoutes();
+        $routes->refreshNameLookups();
+
+        // **順序が重要**: recent-auth を先に通し、その後で手段保持を検査する。
+        // 逆順だと stale recent-auth のリクエストでも User 行ロックを取りに行ってしまう。
+        // PasskeyRouteProtectionTest が gatherMiddleware() 上の index 比較で固定する。
+        foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
+            self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
+        }
+
+        foreach (self::LOGIN_METHOD_GUARD_ROUTE_NAMES as $name) {
+            self::appendMiddlewareIfMissing($routes, $name, 'ensure-login-method');
+        }
+
+        // guest route のため NoStoreCacheHeadersForAuthenticatedPages の対象外。
+        // WebAuthn challenge を載せる応答をキャッシュさせない。
+        self::appendMiddlewareIfMissing($routes, 'passkey.login-options', 'no-store');
+    }
+
+    private static function appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void
+    {
+        $route = $routes->getByName($name);
+        if ($route !== null && ! in_array($alias, $route->middleware(), true)) {
+            $route->middleware($alias);
+        }
+    }
+}
diff --git a/app/Services/Auth/LoginMethodInventory.php b/app/Services/Auth/LoginMethodInventory.php
new file mode 100644
index 0000000..5bc35f9
--- /dev/null
+++ b/app/Services/Auth/LoginMethodInventory.php
@@ -0,0 +1,89 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Auth;
+
+use App\DataTransferObjects\Auth\LoginMethodRemoval;
+use App\DataTransferObjects\Auth\LoginMethodSet;
+use App\Enums\Auth\LoginMethodRemovalKind;
+use App\Models\User;
+
+/**
+ * 「ログイン画面から本人がアカウントに入れる手段」の集合。
+ *
+ * 基準は「データが存在する」ではなく「**使える**」。
+ * feature を落とした後も使えない手段を数えると guard が形骸化するため。
+ *
+ * 唯一の公開 API は remainingAfter()。「現在の手段」も
+ * LoginMethodRemoval::none() で表現する (API を 2 本にすると片方だけ使う実装が生える)。
+ *
+ * ⚠ 呼び出し側の契約: 除去の可否判定に使う場合、本メソッドは
+ * **対象 User 行を lockForUpdate() で取得した同一トランザクション内**で呼ぶこと
+ * (EnsureLoginMethodRemains が唯一の呼び出し点)。ロック外の評価は TOCTOU で破れる。
+ *
+ * ⚠ canSatisfy (ConfirmRecentAuthController) とは**別概念**。あちらは
+ * 「step-up 再認証を成立させられるか」で ProviderCapability による絞り込みが入る。
+ * こちらは「ログインできるか」なので capability では絞らない。統合しないこと。
+ *
+ * ⚠ **将来 password 削除 / SSO 連携解除 route を追加するときも、必ず
+ * EnsureLoginMethodRemains を通すこと (= 単一の直列化点)**。
+ * passkey だけ守って別経路を作ると TOCTOU が戻る。
+ * tests/Architecture/LoginMethodRemovalRouteTest が deny-by-default で強制する。
+ */
+final class LoginMethodInventory
+{
+    public function __construct(
+        private readonly PasskeyLoginPolicy $passkeyLoginPolicy,
+    ) {}
+
+    /** $removal が成功した後に残るログイン手段の集合 */
+    public function remainingAfter(User $user, LoginMethodRemoval $removal): LoginMethodSet
+    {
+        /** @var list<string> $methods */
+        $methods = [];
+
+        // --- password ---
+        if ($removal->kind !== LoginMethodRemovalKind::Password && $user->hasPassword()) {
+            $methods[] = 'password';
+        }
+
+        // --- social ---
+        // capability では絞らない (identity_only でもログインはできる)。
+        // ただし config に無い provider は SocialAuthController::ensureProviderEnabled() が
+        // 404 にするため、連携行があってもログインには使えない → 数えない。
+        $enabled = array_keys(config()->array('template.social_providers'));
+        foreach ($user->socialAccounts()->pluck('provider') as $provider) {
+            if (! is_string($provider) || ! in_array($provider, $enabled, true)) {
+                continue;   // fail-closed
+            }
+            if ($removal->kind === LoginMethodRemovalKind::Social && $removal->provider === $provider) {
+                continue;   // 今から外す
+            }
+            $methods[] = 'social:'.$provider;
+        }
+
+        // --- passkey ---
+        if ($this->passkeyLoginPolicy->allowsPasskeyLogin($user) && $this->hasRemainingPasskey($user, $removal)) {
+            $methods[] = 'passkey';
+        }
+
+        return new LoginMethodSet(array_values(array_unique($methods)));
+    }
+
+    private function hasRemainingPasskey(User $user, LoginMethodRemoval $removal): bool
+    {
+        if ($removal->kind === LoginMethodRemovalKind::AllPasskeys) {
+            return false;
+        }
+
+        $query = $user->passkeys();
+
+        if ($removal->kind === LoginMethodRemovalKind::Passkey && $removal->passkey !== null) {
+            // 削除対象自身を残存手段として数えない (投影)
+            $query->whereKeyNot($removal->passkey->getKey());
+        }
+
+        return $query->exists();
+    }
+}
diff --git a/app/Services/Auth/PasskeyLoginPolicy.php b/app/Services/Auth/PasskeyLoginPolicy.php
new file mode 100644
index 0000000..e8f22d1
--- /dev/null
+++ b/app/Services/Auth/PasskeyLoginPolicy.php
@@ -0,0 +1,41 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Auth;
+
+use App\Enums\TwoFactorStatus;
+use App\Models\User;
+use Laravel\Fortify\Features;
+
+/**
+ * その User が passkey で **ログイン** することを許されるか (credential の有無とは無関係)。
+ *
+ * 判定を 1 箇所に集約する理由: 同じ条件が
+ *   (1) Passkeys::authorizeLoginUsing() の closure (vendor のログイン直前ゲート)
+ *   (2) LoginMethodInventory の passkey 判定
+ *   (3) Settings/Security の passkeyLoginAvailable prop
+ * で必要になり、別々に書けば必ず乖離するため。
+ *
+ * **TOTP confirmed のユーザーを拒否する理由**:
+ * vendor の PasskeyLoginController::store() は $guard->login() を直接呼び、
+ * Fortify の two-factor challenge を通らない。2026-08-04 裁定 A の再検討条件が
+ * 「パスキーが 2FA 準拠判定に算入される時」である以上、現時点で passkey は 2FA 相当ではなく、
+ * passkey login で TOTP を置き換えるのは assurance の後退にあたる。
+ * これは c2c 未裁定の論点 (AG-新) であり、裁定が出たら **このクラス 1 箇所**を
+ * 書き換えれば上記経路が同時に反転する。
+ *
+ * feature flag に連動するため、`config/fortify.php` から Features::passkeys() を外すと
+ * 「passkey はログイン手段として数えない」も同時に成立する (= キルスイッチ)。
+ */
+final class PasskeyLoginPolicy
+{
+    public function allowsPasskeyLogin(User $user): bool
+    {
+        if (! Features::enabled(Features::passkeys())) {
+            return false;   // feature off なら route ごと存在しない
+        }
+
+        return $user->twoFactorStatus() !== TwoFactorStatus::Enabled;
+    }
+}
diff --git a/app/Services/Auth/SocialAccountService.php b/app/Services/Auth/SocialAccountService.php
index ceba26a..875ad13 100644
--- a/app/Services/Auth/SocialAccountService.php
+++ b/app/Services/Auth/SocialAccountService.php
@@ -11,7 +11,6 @@
 use App\Services\Organization\OrganizationProvisioningService;
 use App\Services\Security\SecurityEventRecorder;
 use Illuminate\Support\Facades\DB;
-use Illuminate\Support\Str;
 use Laravel\Socialite\Contracts\User as SocialiteUser;
 
 /**
@@ -60,11 +59,17 @@ public function register(string $provider, SocialiteUser $socialiteUser): User
                 ? now()
                 : null;
 
+            // SSO 登録は password を **持たない** (null のまま)。
+            // users.password は nullable であり、password 経路の可否は User::hasPassword() が
+            // fail-closed で判定する契約 (0001_01_01_000000_create_users_table.php)。
+            // ランダム値 (旧 Str::password(32)) を入れると hasPassword() が常に true になり、
+            // recent-auth の passwordSet と EnsureLoginMethodRemains の双方が形骸化する。
+            // **前方修正のみ**: 既存 SSO ユーザーの phantom password は遡及是正しない
+            // (password 登録後に SSO 連携したユーザーの実パスワード消失リスクのため)。
+            // → docs/template-divergence.md D13。
             $user = (new User([
                 'name' => $socialiteUser->getName() ?? $email,
                 'email' => $email,
-                // SSO 登録はパスワードを持たない (ランダム値をハッシュ化して保存)
-                'password' => Str::password(32),
             ]))->forceFill([
                 'terms_accepted_at' => now(),
                 'consent_version' => config()->string('legal.consent_version'),
diff --git a/bootstrap/app.php b/bootstrap/app.php
index b226506..2a42510 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -7,11 +7,13 @@
 use App\Http\Middleware\BughuntCoverageMiddleware;
 use App\Http\Middleware\EnforceMcpTransport;
 use App\Http\Middleware\EnsureEmailIsVerifiedOrBack;
+use App\Http\Middleware\EnsureLoginMethodRemains;
 use App\Http\Middleware\EnsureProjectBelongsToCurrentOrganization;
 use App\Http\Middleware\HandleInertiaRequests;
 use App\Http\Middleware\IdempotentRequest;
 use App\Http\Middleware\McpConsentOrganizationBinder;
 use App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages;
+use App\Http\Middleware\NoStoreResponse;
 use App\Http\Middleware\RedirectToHttps;
 use App\Http\Middleware\RequireActiveSubscription;
 use App\Http\Middleware\RequireApiKeyAbility;
@@ -128,6 +130,13 @@
             'recent-auth' => RequireRecentAuth::class,
             // profile 更新の email 変更時のみ step-up を課す条件付きゲート
             'recent-auth.on-email-change' => RequireRecentAuthOnEmailChange::class,
+            // ログイン手段を減らす操作の関門 (投影後評価 + User 行ロックによる直列化)。
+            // 付与対象は LoginMethodRemovalRouteTest が deny-by-default で強制する
+            // (allowlist 外への付与も fail。$next を transaction 内で実行するため)
+            'ensure-login-method' => EnsureLoginMethodRemains::class,
+            // guest route の応答に no-store を保証する (認証済み baseline の対象外を補う)。
+            // 現在の付与先は passkey.login-options (WebAuthn challenge を載せる guest route)
+            'no-store' => NoStoreResponse::class,
             'require-active-subscription' => RequireActiveSubscription::class,
             // `verified` の web POST 向け代替。未認証時に back + error flash で元ページへ戻す
             // (context 別文言は EmailVerificationGateContext)。organizations.store /
diff --git a/bootstrap/providers.php b/bootstrap/providers.php
index f2100b0..bf55aba 100644
--- a/bootstrap/providers.php
+++ b/bootstrap/providers.php
@@ -5,12 +5,17 @@
 use App\Providers\Filament\AdminPanelProvider;
 use App\Providers\FortifyServiceProvider;
 use App\Providers\McpPassportServiceProvider;
+use App\Providers\PasskeyServiceProvider;
 use App\Providers\SeoServiceProvider;
 
 return [
     AppServiceProvider::class,
     AdminPanelProvider::class,
     FortifyServiceProvider::class,
+    // passkey (laravel/passkeys) の app アダプタ。Fortify が feature flag で route を
+    // 登録するため **FortifyServiceProvider より後**に置く。ただし binder / middleware の
+    // 後付けは provider 順序に依存しないよう $app->booted() 内で最終上書きする
+    PasskeyServiceProvider::class,
     // Passport は composer.json の dont-discover で自動 discovery を無効化し、
     // grant / repository を差し替えた本 Provider を唯一の登録点にする (WP23)
     McpPassportServiceProvider::class,
diff --git a/config/fortify.php b/config/fortify.php
index 3bd1059..6da9b9e 100644
--- a/config/fortify.php
+++ b/config/fortify.php
@@ -117,6 +117,11 @@
     'limiters' => [
         'login' => 'login',
         'two-factor' => 'two-factor',
+        // passkey endpoint の絞り。**未設定だと FortifyServiceProvider::passkeyThrottleMiddleware()
+        // が null を返し、未認証の GET /passkeys/login/options が無制限**になる
+        // (毎回 random_bytes(32) + session 書き込みが走る)。
+        // limiter 本体は App\Providers\FortifyServiceProvider::configureRateLimiters()。
+        'passkeys' => 'passkeys',
     ],
 
     /*
@@ -166,6 +171,17 @@
             // (auth.password_confirmed_at の充足) は現行未提供 (bug-hunt F-11)。
             'confirmPassword' => false,
         ]),
+
+        // パスキー (WebAuthn)。現場 PWA でパスワード入力を不要にする。
+        // **この 1 行が実質的なキルスイッチ**: 外すと passkey route が消え、
+        // PasskeyLoginPolicy が false を返して LoginMethodInventory も passkey を数えなくなる。
+        //
+        // confirmPassword=false の理由は 2FA と同一 — 本アプリは Fortify 標準の
+        // password.confirm (3h・パスワード限定) を撤去し generic recent-auth
+        // (15 分窓・パスワード or 再SSO) へ統一済みで、残すと SSO-only ユーザーが詰む。
+        // step-up は App\Providers\PasskeyServiceProvider が recent-auth を後付け配線する
+        // (PasskeyRouteProtectionTest / PasswordConfirmMiddlewareAbsenceTest が CI 固定)。
+        Features::passkeys(['confirmPassword' => false]),
     ],
 
 ];
diff --git a/database/factories/PasskeyFactory.php b/database/factories/PasskeyFactory.php
new file mode 100644
index 0000000..c31f0ca
--- /dev/null
+++ b/database/factories/PasskeyFactory.php
@@ -0,0 +1,37 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Factories;
+
+use App\Models\Passkey;
+use App\Models\User;
+use Illuminate\Database\Eloquent\Factories\Factory;
+
+/**
+ * @extends Factory<Passkey>
+ */
+final class PasskeyFactory extends Factory
+{
+    /** @var class-string<Passkey> */
+    protected $model = Passkey::class;
+
+    /**
+     * WebAuthn ceremony を伴わないテスト (削除 / 一覧 / 手段カウント / 認可) 用の最小形。
+     * 実 ceremony を検証するテストは vendor の WebAuthn helper で credential を生成すること。
+     *
+     * @return array<string, mixed>
+     */
+    public function definition(): array
+    {
+        return [
+            'user_id' => User::factory(),
+            'name' => fake()->words(2, true),
+            // credential_id は base64url unpadded
+            // (VerifyPasskey が Base64UrlSafe::encodeUnpadded で照合する形式)
+            'credential_id' => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='),
+            'credential' => ['type' => 'public-key'],
+            'last_used_at' => null,
+        ];
+    }
+}
diff --git a/database/migrations/2026_08_05_051541_create_passkeys_table.php b/database/migrations/2026_08_05_051541_create_passkeys_table.php
new file mode 100644
index 0000000..403b732
--- /dev/null
+++ b/database/migrations/2026_08_05_051541_create_passkeys_table.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+use Laravel\Passkeys\Passkeys;
+
+/**
+ * passkeys テーブル (laravel/passkeys から publish。`vendor:publish --tag=passkeys-migrations`)。
+ *
+ * 自前で書き起こさず publish するのは、credential_id の unique 制約や
+ * cascadeOnDelete (アカウント削除で credential も消える) といった vendor の
+ * 前提をそのまま引き継ぐため。列を足す必要が生じたら別 migration で追加する。
+ */
+return new class extends Migration
+{
+    /**
+     * Run the migrations.
+     */
+    public function up(): void
+    {
+        Schema::create('passkeys', function (Blueprint $table): void {
+            $table->id();
+            $table->foreignIdFor(Passkeys::userModel(), 'user_id')->constrained()->cascadeOnDelete();
+            $table->string('name');
+            $table->string('credential_id')->unique();
+            $table->json('credential');
+            $table->timestamp('last_used_at')->nullable();
+            $table->timestamps();
+
+            $table->index('user_id');
+        });
+    }
+
+    /**
+     * Reverse the migrations.
+     */
+    public function down(): void
+    {
+        Schema::dropIfExists('passkeys');
+    }
+};
diff --git a/resources/js/components/features/auth/PasskeySection.svelte b/resources/js/components/features/auth/PasskeySection.svelte
new file mode 100644
index 0000000..86beb4c
--- /dev/null
+++ b/resources/js/components/features/auth/PasskeySection.svelte
@@ -0,0 +1,256 @@
+<script lang="ts">
+    import { router } from "@inertiajs/svelte";
+    import { KeyRound } from "@lucide/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
+    import Badge from "@/components/atoms/Badge.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import Input from "@/components/atoms/Input.svelte";
+    import FormField from "@/components/molecules/FormField.svelte";
+    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
+    import {
+        canCreatePasskey,
+        createPasskeyCredential,
+        isPasskeySupported,
+        type PasskeyListItem,
+    } from "@/lib/passkeys";
+    import { addToast } from "@/lib/stores/toast";
+
+    /**
+     * セキュリティ設定のパスキーカード。
+     *
+     * 契約:
+     * - 登録 / 削除は **recent-auth 必須**。precheck は呼び出し側 (page) が持つ `guard` に委譲する
+     *   (再認証モーダルはページに 1 つだけ置き、二重モーダルを作らない)。
+     * - 登録は ceremony (fetch) → **Inertia `router.post`** で送る (transport 契約)。
+     *   成功 flash はサーバ (`back()->with('success')`) を単一の源とし client 楽観 toast を出さない。
+     * - 削除は ConfirmDialog → `router.delete`。ログイン手段が 0 になる場合サーバは
+     *   302 + `errors.login_method` を返すため、`loginMethodError` として受け取り明示表示する
+     *   (**無言失敗にしない**)。
+     * - **必須条件未充足でボタンを disabled にしない** (AGENTS.md 禁止事項 8)。
+     *   非対応端末でも押せて、押下時にエラーを出す。
+     */
+    interface Props {
+        passkeys?: PasskeyListItem[];
+        /** passkey での「ログイン」が許されるか (TOTP 有効時は false。再認証には使える) */
+        passkeyLoginAvailable?: boolean;
+        twoFactorEnabled?: boolean;
+        /** EnsureLoginMethodRemains の拒否メッセージ ($page.props.errors.login_method) */
+        loginMethodError?: string;
+        /** recent-auth precheck。fresh なら即実行、stale なら再認証モーダルを挟んで再開する */
+        guard: (action: () => void) => void;
+    }
+
+    let {
+        passkeys = [],
+        passkeyLoginAvailable = false,
+        twoFactorEnabled = false,
+        loginMethodError,
+        guard,
+    }: Props = $props();
+
+    const supported = isPasskeySupported();
+    let creatable = $state(false);
+    void (async () => {
+        creatable = await canCreatePasskey();
+    })();
+
+    let newPasskeyName = $state("");
+    let nameError = $state("");
+    let registering = $state(false);
+
+    function registerPasskey(): void {
+        if (registering) return;
+        // 非対応端末でも押下できる (disabled にしない)。押した結果として理由を出す。
+        if (!supported) {
+            addToast(
+                "error",
+                "このブラウザはパスキーに対応していません。パスワードまたはソーシャルログインをご利用ください。",
+            );
+            return;
+        }
+        const name = newPasskeyName.trim();
+        if (name === "") {
+            nameError = "パスキーの名前を入力してください。";
+            return;
+        }
+        nameError = "";
+
+        guard(() => {
+            void (async () => {
+                registering = true;
+                try {
+                    const outcome = await createPasskeyCredential();
+                    if (outcome.status === "cancelled") return;
+                    if (outcome.status === "unsupported") {
+                        addToast("error", "このブラウザはパスキーに対応していません。");
+                        return;
+                    }
+                    if (outcome.status === "failed") {
+                        addToast("error", outcome.message);
+                        return;
+                    }
+                    router.post(
+                        "/user/passkeys",
+                        { name, credential: outcome.value },
+                        {
+                            preserveScroll: true,
+                            onSuccess: () => {
+                                newPasskeyName = "";
+                            },
+                            onError: () => {
+                                addToast("error", "パスキーの登録に失敗しました。");
+                            },
+                        },
+                    );
+                } finally {
+                    registering = false;
+                }
+            })();
+        });
+    }
+
+    let deleteTarget = $state<PasskeyListItem | null>(null);
+    let deleteDialogOpen = $state(false);
+    let deleting = $state(false);
+
+    function requestDelete(passkey: PasskeyListItem): void {
+        deleteTarget = passkey;
+        deleteDialogOpen = true;
+    }
+
+    function confirmDelete(): void {
+        const target = deleteTarget;
+        if (target === null) return;
+        guard(() => {
+            router.delete(`/user/passkeys/${target.id}`, {
+                preserveScroll: true,
+                onStart: () => {
+                    deleting = true;
+                },
+                onFinish: () => {
+                    deleting = false;
+                    deleteDialogOpen = false;
+                    deleteTarget = null;
+                },
+            });
+        });
+    }
+
+    function formatDate(value: string | null): string {
+        if (value === null) return "未使用";
+        const parsed = new Date(value);
+        return Number.isNaN(parsed.getTime()) ? "不明" : parsed.toLocaleDateString("ja-JP");
+    }
+</script>
+
+<Card padding="lg">
+    <div class="flex items-center justify-between gap-4">
+        <h2 class="text-h3">パスキー</h2>
+        {#if passkeys.length > 0}
+            <Badge tone="success" testId="passkey-count">{passkeys.length} 件登録済み</Badge>
+        {:else}
+            <Badge tone="neutral" testId="passkey-count">未登録</Badge>
+        {/if}
+    </div>
+    <p class="mt-1 text-caption text-text-secondary">
+        指紋・顔認証・端末のロック解除でログインできます。
+    </p>
+
+    <div class="mt-4 flex flex-col gap-4">
+        {#if loginMethodError}
+            <Alert type="danger" title="削除できません" testId="passkey-login-method-error">
+                {loginMethodError}
+                {#snippet action()}
+                    <div class="flex flex-wrap gap-3">
+                        <Button variant="ghost" href="/settings" testId="passkey-add-password">
+                            パスワードを設定する
+                        </Button>
+                    </div>
+                {/snippet}
+            </Alert>
+        {/if}
+
+        {#if !passkeyLoginAvailable && twoFactorEnabled}
+            <!-- 誤認させない: 2FA 有効時はログインには使えないが再認証には使える -->
+            <Alert type="info" testId="passkey-2fa-notice">
+                2要素認証を有効にしているため、パスキーでのログインはできません。この画面での再認証にはご利用いただけます。
+            </Alert>
+        {/if}
+
+        {#if !supported}
+            <Alert type="warning" testId="passkey-unsupported">
+                このブラウザはパスキーに対応していません。パスワードまたはソーシャルログインをご利用ください。
+            </Alert>
+        {:else if !creatable}
+            <Alert type="warning" testId="passkey-not-creatable">
+                この端末ではパスキーを作成できません。画面ロック（生体認証・PIN）を設定すると利用できます。
+            </Alert>
+        {/if}
+
+        {#if passkeys.length > 0}
+            <ul class="flex flex-col gap-3" data-testid="passkey-list">
+                {#each passkeys as passkey (passkey.id)}
+                    <li
+                        class="flex items-center justify-between gap-4 rounded-md border border-border p-3"
+                    >
+                        <div class="flex min-w-0 items-center gap-3">
+                            <KeyRound class="size-5 shrink-0 text-primary" aria-hidden="true" />
+                            <div class="min-w-0">
+                                <p class="truncate text-body">{passkey.name}</p>
+                                <p class="text-caption text-text-secondary">
+                                    {passkey.authenticator ?? "認証器不明"} ・ 最終利用 {formatDate(
+                                        passkey.lastUsedAt,
+                                    )}
+                                </p>
+                            </div>
+                        </div>
+                        <Button
+                            variant="danger-ghost"
+                            size="sm"
+                            onclick={() => requestDelete(passkey)}
+                            testId={`delete-passkey-${passkey.id}`}
+                        >
+                            削除
+                        </Button>
+                    </li>
+                {/each}
+            </ul>
+        {/if}
+
+        <div class="flex flex-col gap-3">
+            <FormField label="パスキーの名前" id="passkey-name" error={nameError}>
+                {#snippet children({ id, describedBy, invalid })}
+                    <Input
+                        {id}
+                        type="text"
+                        bind:value={newPasskeyName}
+                        error={invalid}
+                        aria-describedby={describedBy}
+                        placeholder="例: 現場用スマホ"
+                        testId="passkey-name-input"
+                    />
+                {/snippet}
+            </FormField>
+            <div>
+                <Button onclick={registerPasskey} loading={registering} testId="register-passkey-button">
+                    パスキーを登録
+                </Button>
+            </div>
+        </div>
+    </div>
+</Card>
+
+<ConfirmDialog
+    bind:open={deleteDialogOpen}
+    title="パスキーの削除"
+    message={`パスキー「${deleteTarget?.name ?? ""}」を削除しますか？ この端末からはパスキーでログインできなくなります。`}
+    confirmLabel="削除する"
+    confirmVariant="danger"
+    processing={deleting}
+    onConfirm={confirmDelete}
+    onCancel={() => {
+        deleteTarget = null;
+    }}
+    testId="delete-passkey-dialog"
+/>
diff --git a/resources/js/components/organisms/RecentAuthModal.svelte b/resources/js/components/organisms/RecentAuthModal.svelte
index dbe5d51..838d000 100644
--- a/resources/js/components/organisms/RecentAuthModal.svelte
+++ b/resources/js/components/organisms/RecentAuthModal.svelte
@@ -7,6 +7,7 @@
     import FormField from "@/components/molecules/FormField.svelte";
     import Modal from "@/components/organisms/Modal.svelte";
     import { csrfToken } from "@/lib/csrf";
+    import { confirmWithPasskey, isPasskeySupported } from "@/lib/passkeys";
     import type { AvailableReauthProvider } from "@/lib/recent-auth";
     import { providerLabel } from "@/lib/social";
 
@@ -15,6 +16,8 @@
      * 「同一画面の再認証 (step-up) モーダル」。
      * - password 設定済みユーザー: パスワード再入力 → POST /recent-auth/password (XHR=204 成功)。
      * - 再SSO 可能な provider (availableProviders): reauthUrl へフルリダイレクト。
+     * - パスキー登録済み (passkeyAvailable): WebAuthn 検証 → POST /passkeys/confirm (204)。
+     *   TOTP 有効ユーザーでも **再認証には使える** (PasskeyLoginPolicy が縛るのはログインのみ)。
      * - canSatisfy=false (再認証手段なし): 回復導線 (パスワードリセット) を案内する。
      * 認可の最終ゲートは各操作の recent-auth middleware (本モーダルは UX 補助)。
      */
@@ -23,6 +26,12 @@
         passwordSet?: boolean;
         availableProviders?: AvailableReauthProvider[];
         canSatisfy?: boolean;
+        /**
+         * パスキーでの再認証を提示するか。呼び出し側が「登録済みパスキーがある」ことを知っている
+         * 画面 (Settings/Security) だけ true を渡す (状態を知らない画面で空 ceremony を
+         * 起動させないため)。
+         */
+        passkeyAvailable?: boolean;
         /** password satisfier 成功時 (204)。呼び出し側が pending action を再開する */
         onConfirmed: () => void;
     }
@@ -32,9 +41,35 @@
         passwordSet = false,
         availableProviders = [],
         canSatisfy = true,
+        passkeyAvailable = false,
         onConfirmed,
     }: Props = $props();
 
+    const passkeySupported = isPasskeySupported();
+    let passkeySubmitting = $state(false);
+
+    async function submitPasskey(): Promise<void> {
+        if (passkeySubmitting) return;
+        passkeySubmitting = true;
+        error = "";
+        try {
+            const outcome = await confirmWithPasskey();
+            if (outcome.status === "ok") {
+                open = false;
+                onConfirmed();
+                return;
+            }
+            // キャンセルは失敗として騒がない (再試行導線を残す)
+            if (outcome.status === "cancelled") return;
+            error =
+                outcome.status === "unsupported"
+                    ? "このブラウザはパスキーに対応していません。"
+                    : outcome.message;
+        } finally {
+            passkeySubmitting = false;
+        }
+    }
+
     let password = $state("");
     let error = $state("");
     let submitting = $state(false);
@@ -45,6 +80,7 @@
             password = "";
             error = "";
             submitting = false;
+            passkeySubmitting = false;
         }
     });
 
@@ -121,10 +157,25 @@
             <FormError message={error} testId="recent-auth-error" />
         {/if}
 
-        {#if availableProviders.length > 0}
+        {#if passkeyAvailable && passkeySupported}
             {#if passwordSet}
                 <Divider label="または" />
             {/if}
+            <Button
+                variant="ghost"
+                fullWidth
+                loading={passkeySubmitting}
+                onclick={() => void submitPasskey()}
+                testId="recent-auth-passkey"
+            >
+                パスキーで再認証
+            </Button>
+        {/if}
+
+        {#if availableProviders.length > 0}
+            {#if passwordSet || (passkeyAvailable && passkeySupported)}
+                <Divider label="または" />
+            {/if}
             <div class="flex flex-col gap-2">
                 {#each availableProviders as provider (provider.provider)}
                     <Button
diff --git a/resources/js/lib/passkeys.ts b/resources/js/lib/passkeys.ts
new file mode 100644
index 0000000..df13135
--- /dev/null
+++ b/resources/js/lib/passkeys.ts
@@ -0,0 +1,377 @@
+import { csrfToken } from "@/lib/csrf";
+
+/**
+ * WebAuthn (passkey) ceremony の薄いラッパ。
+ *
+ * サーバとの JSON 契約は laravel/passkeys が定義する
+ * (`{ options }` を受け取り、credential を JSON で返す)。
+ *
+ * **feature detection を必ず経由すること**。現場 PWA が主戦場であり、
+ * 非対応端末 / 生体未設定端末は常態である (docs/supported-browsers.md)。
+ *
+ * **transport 契約 (詳細設計 施策 4-d) に対応する責務分担**:
+ *   本モジュールは「options 取得 + ceremony 実行 + 送信可能な JSON への変換」までを担う。
+ *   **登録は送信までしない** (Inertia `router.post` は呼び出し側 Svelte が行う。
+ *   passkey 一覧 prop を更新する必要があるため)。confirm / login は fetch 完結なので
+ *   送信まで担う。
+ *
+ * eslint の noInlineConfig (T102) により inline eslint-disable は使えない。
+ * base64url 変換は型安全に書き、`any` / `@ts-ignore` を持ち込まないこと。
+ */
+
+/** Settings/Security の passkey 一覧 1 件 (PasskeyListItemDto と 1:1) */
+export interface PasskeyListItem {
+    id: number;
+    name: string;
+    authenticator: string | null;
+    lastUsedAt: string | null;
+    createdAt: string | null;
+}
+
+/** ceremony の結果種別。キャンセル/タイムアウトはエラーとして騒がない */
+export type PasskeyOutcome<T> =
+    | { status: "ok"; value: T }
+    | { status: "cancelled" }
+    | { status: "unsupported" }
+    | { status: "failed"; message: string };
+
+const GENERIC_FAILURE = "パスキーの処理に失敗しました。時間をおいて再度お試しください。";
+
+/** この端末で passkey ceremony を開始できるか (API の存在確認) */
+export function isPasskeySupported(): boolean {
+    return (
+        typeof window !== "undefined" &&
+        typeof window.PublicKeyCredential === "function" &&
+        typeof navigator !== "undefined" &&
+        typeof navigator.credentials?.get === "function"
+    );
+}
+
+/** この端末で passkey を **作成** できるか (プラットフォーム認証器 + user verification) */
+export async function canCreatePasskey(): Promise<boolean> {
+    if (!isPasskeySupported()) return false;
+    try {
+        return await window.PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
+    } catch {
+        // 端末/ブラウザによっては未実装で throw する。作成不可として畳む (騒がない)
+        return false;
+    }
+}
+
+/* ------------------------------------------------------------------ *
+ * base64url <-> ArrayBuffer
+ * サーバ (webauthn-lib の normalizer) は binary を base64url (padding なし) で送る。
+ * ------------------------------------------------------------------ */
+
+export function base64UrlToBuffer(value: string): ArrayBuffer {
+    const padded = value.replace(/-/g, "+").replace(/_/g, "/");
+    const binary = atob(padded + "=".repeat((4 - (padded.length % 4)) % 4));
+    const bytes = new Uint8Array(binary.length);
+    for (let i = 0; i < binary.length; i += 1) {
+        bytes[i] = binary.charCodeAt(i);
+    }
+    return bytes.buffer;
+}
+
+export function bufferToBase64Url(value: ArrayBuffer): string {
+    const bytes = new Uint8Array(value);
+    let binary = "";
+    for (let i = 0; i < bytes.length; i += 1) {
+        binary += String.fromCharCode(bytes[i]);
+    }
+    return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
+}
+
+/* ------------------------------------------------------------------ *
+ * サーバ options の最小 shape (信用せず narrowing する)
+ * ------------------------------------------------------------------ */
+
+type JsonRecord = Record<string, unknown>;
+
+function isRecord(value: unknown): value is JsonRecord {
+    return typeof value === "object" && value !== null && !Array.isArray(value);
+}
+
+function readString(source: JsonRecord, key: string): string | null {
+    const value = source[key];
+    return typeof value === "string" && value !== "" ? value : null;
+}
+
+function readDescriptors(source: JsonRecord, key: string): PublicKeyCredentialDescriptor[] {
+    const raw = source[key];
+    if (!Array.isArray(raw)) return [];
+    const descriptors: PublicKeyCredentialDescriptor[] = [];
+    for (const entry of raw) {
+        if (!isRecord(entry)) continue;
+        const id = readString(entry, "id");
+        if (id === null) continue;
+        // type は WebAuthn 仕様上 "public-key" のみ (webauthn-lib も他を送らない)
+        descriptors.push({ id: base64UrlToBuffer(id), type: "public-key" });
+    }
+    return descriptors;
+}
+
+/**
+ * 共通 fetch。`Accept: application/json` は必須
+ * (無いと Laravel が redirect を返し、PasskeyLoginResponse も JSON 分岐に入らない)。
+ */
+async function requestJson(url: string, body?: JsonRecord): Promise<Response> {
+    const headers: Record<string, string> = {
+        Accept: "application/json",
+        "X-Requested-With": "XMLHttpRequest",
+    };
+    if (body !== undefined) {
+        headers["Content-Type"] = "application/json";
+        headers["X-XSRF-TOKEN"] = csrfToken();
+    }
+    return fetch(url, {
+        method: body === undefined ? "GET" : "POST",
+        headers,
+        credentials: "same-origin",
+        body: body === undefined ? undefined : JSON.stringify(body),
+    });
+}
+
+/** options endpoint から `{ options }` を取り出す (不正 shape は null) */
+async function fetchOptions(url: string): Promise<JsonRecord | null> {
+    try {
+        const res = await requestJson(url);
+        if (!res.ok) return null;
+        const payload: unknown = await res.json();
+        if (!isRecord(payload) || !isRecord(payload.options)) return null;
+        return payload.options;
+    } catch {
+        return null;
+    }
+}
+
+/** ユーザーキャンセル / タイムアウトを「失敗」として騒がないために畳む */
+function isCancellation(error: unknown): boolean {
+    return (
+        error instanceof Error &&
+        (error.name === "NotAllowedError" || error.name === "AbortError")
+    );
+}
+
+/* ------------------------------------------------------------------ *
+ * ceremony
+ * ------------------------------------------------------------------ */
+
+/** ArrayBuffer 相当のフィールドだけを base64url へ写す (存在しないキーは落とす) */
+function encodeBufferField(source: JsonRecord, key: string): string | null {
+    const value = source[key];
+    return value instanceof ArrayBuffer ? bufferToBase64Url(value) : null;
+}
+
+/**
+ * navigator.credentials の戻りを送信可能な JSON へ変換する。
+ *
+ * 種別判定は `instanceof AuthenticatorAttestationResponse` **ではなく** フィールドの
+ * 有無で行う。認証器レスポンスのクラスはグローバルに存在しない実行環境があり
+ * (jsdom / 一部の WebView)、instanceof は ReferenceError で ceremony 全体を落とすため。
+ */
+function serializeCredential(credential: PublicKeyCredential): JsonRecord {
+    const response = credential.response as unknown as JsonRecord;
+    const clientDataJSON = encodeBufferField(response, "clientDataJSON");
+    const attestationObject = encodeBufferField(response, "attestationObject");
+
+    const serializedResponse: JsonRecord = {};
+    if (clientDataJSON !== null) serializedResponse.clientDataJSON = clientDataJSON;
+
+    if (attestationObject !== null) {
+        // 登録 (attestation)
+        serializedResponse.attestationObject = attestationObject;
+    } else {
+        // 認証 (assertion)。userHandle は null を明示的に送る (仕様上 null がありうる)
+        const authenticatorData = encodeBufferField(response, "authenticatorData");
+        const signature = encodeBufferField(response, "signature");
+        if (authenticatorData !== null) serializedResponse.authenticatorData = authenticatorData;
+        if (signature !== null) serializedResponse.signature = signature;
+        serializedResponse.userHandle = encodeBufferField(response, "userHandle");
+    }
+
+    return {
+        id: credential.id,
+        rawId: bufferToBase64Url(credential.rawId),
+        type: credential.type,
+        response: serializedResponse,
+    };
+}
+
+function toCreationOptions(options: JsonRecord): PublicKeyCredentialCreationOptions | null {
+    const challenge = readString(options, "challenge");
+    const rp = isRecord(options.rp) ? options.rp : null;
+    const user = isRecord(options.user) ? options.user : null;
+    const userId = user === null ? null : readString(user, "id");
+    if (challenge === null || rp === null || user === null || userId === null) return null;
+
+    const params = Array.isArray(options.pubKeyCredParams)
+        ? options.pubKeyCredParams
+              .filter(isRecord)
+              .filter((entry): entry is JsonRecord => typeof entry.alg === "number")
+              .map((entry): PublicKeyCredentialParameters => ({
+                  type: "public-key",
+                  alg: entry.alg as number,
+              }))
+        : [];
+
+    return {
+        challenge: base64UrlToBuffer(challenge),
+        rp: {
+            id: readString(rp, "id") ?? undefined,
+            name: readString(rp, "name") ?? "",
+        },
+        user: {
+            id: base64UrlToBuffer(userId),
+            name: readString(user, "name") ?? "",
+            displayName: readString(user, "displayName") ?? "",
+        },
+        pubKeyCredParams: params,
+        excludeCredentials: readDescriptors(options, "excludeCredentials"),
+        timeout: typeof options.timeout === "number" ? options.timeout : undefined,
+        authenticatorSelection: isRecord(options.authenticatorSelection)
+            ? {
+                  residentKey: readString(options.authenticatorSelection, "residentKey") as
+                      | ResidentKeyRequirement
+                      | undefined,
+                  userVerification: readString(options.authenticatorSelection, "userVerification") as
+                      | UserVerificationRequirement
+                      | undefined,
+              }
+            : undefined,
+        attestation: (readString(options, "attestation") ?? undefined) as
+            | AttestationConveyancePreference
+            | undefined,
+    };
+}
+
+function toRequestOptions(options: JsonRecord): PublicKeyCredentialRequestOptions | null {
+    const challenge = readString(options, "challenge");
+    if (challenge === null) return null;
+
+    return {
+        challenge: base64UrlToBuffer(challenge),
+        rpId: readString(options, "rpId") ?? undefined,
+        allowCredentials: readDescriptors(options, "allowCredentials"),
+        timeout: typeof options.timeout === "number" ? options.timeout : undefined,
+        userVerification: (readString(options, "userVerification") ?? undefined) as
+            | UserVerificationRequirement
+            | undefined,
+    };
+}
+
+/**
+ * 登録 ceremony (GET options → navigator.credentials.create)。
+ * **送信は行わない**。呼び出し側が
+ * `router.post('/user/passkeys', { name, credential })` する (transport 契約 4-d)。
+ */
+export async function createPasskeyCredential(): Promise<PasskeyOutcome<JsonRecord>> {
+    if (!isPasskeySupported()) return { status: "unsupported" };
+
+    const options = await fetchOptions("/user/passkeys/options");
+    if (options === null) {
+        return { status: "failed", message: "パスキーの登録を開始できませんでした。" };
+    }
+
+    const creationOptions = toCreationOptions(options);
+    if (creationOptions === null) {
+        return { status: "failed", message: GENERIC_FAILURE };
+    }
+
+    try {
+        const credential = await navigator.credentials.create({ publicKey: creationOptions });
+        if (!(credential instanceof PublicKeyCredential)) {
+            return { status: "failed", message: GENERIC_FAILURE };
+        }
+        return { status: "ok", value: serializeCredential(credential) };
+    } catch (error) {
+        if (isCancellation(error)) return { status: "cancelled" };
+        return { status: "failed", message: GENERIC_FAILURE };
+    }
+}
+
+/** ログイン ceremony (GET options → navigator.credentials.get → POST → `{ redirect }`) */
+export async function loginWithPasskey(
+    remember = false,
+): Promise<PasskeyOutcome<{ redirect: string }>> {
+    const assertion = await assertPasskey("/passkeys/login/options");
+    if (assertion.status !== "ok") return assertion;
+
+    try {
+        const res = await requestJson("/passkeys/login", {
+            credential: assertion.value,
+            remember,
+        });
+        if (!res.ok) {
+            return { status: "failed", message: await readErrorMessage(res) };
+        }
+        const payload: unknown = await res.json();
+        const redirect = isRecord(payload) ? readString(payload, "redirect") : null;
+        if (redirect === null) {
+            return { status: "failed", message: GENERIC_FAILURE };
+        }
+        return { status: "ok", value: { redirect } };
+    } catch {
+        return { status: "failed", message: "通信エラーが発生しました。" };
+    }
+}
+
+/** step-up 確認 ceremony (GET confirm-options → navigator.credentials.get → POST → 204) */
+export async function confirmWithPasskey(): Promise<PasskeyOutcome<void>> {
+    const assertion = await assertPasskey("/passkeys/confirm/options");
+    if (assertion.status !== "ok") return assertion;
+
+    try {
+        const res = await requestJson("/passkeys/confirm", { credential: assertion.value });
+        // 成功は 204 No Content (recent-auth.password と同契約)
+        if (res.status === 204) return { status: "ok", value: undefined };
+        return { status: "failed", message: await readErrorMessage(res) };
+    } catch {
+        return { status: "failed", message: "通信エラーが発生しました。" };
+    }
+}
+
+/** options 取得 + assertion ceremony の共通部 */
+async function assertPasskey(optionsUrl: string): Promise<PasskeyOutcome<JsonRecord>> {
+    if (!isPasskeySupported()) return { status: "unsupported" };
+
+    const options = await fetchOptions(optionsUrl);
+    if (options === null) {
+        return { status: "failed", message: "パスキーの認証を開始できませんでした。" };
+    }
+
+    const requestOptions = toRequestOptions(options);
+    if (requestOptions === null) {
+        return { status: "failed", message: GENERIC_FAILURE };
+    }
+
+    try {
+        const credential = await navigator.credentials.get({ publicKey: requestOptions });
+        if (!(credential instanceof PublicKeyCredential)) {
+            return { status: "failed", message: GENERIC_FAILURE };
+        }
+        return { status: "ok", value: serializeCredential(credential) };
+    } catch (error) {
+        if (isCancellation(error)) return { status: "cancelled" };
+        return { status: "failed", message: GENERIC_FAILURE };
+    }
+}
+
+/** サーバのエラー本文から表示可能なメッセージを取り出す (取れなければ既定文言) */
+async function readErrorMessage(response: Response): Promise<string> {
+    try {
+        const payload: unknown = await response.json();
+        if (!isRecord(payload)) return GENERIC_FAILURE;
+        const direct = readString(payload, "message");
+        if (direct !== null) return direct;
+        const errors = payload.errors;
+        if (isRecord(errors)) {
+            for (const value of Object.values(errors)) {
+                if (Array.isArray(value) && typeof value[0] === "string") return value[0];
+            }
+        }
+        return GENERIC_FAILURE;
+    } catch {
+        return GENERIC_FAILURE;
+    }
+}
diff --git a/resources/js/pages/Auth/Login.svelte b/resources/js/pages/Auth/Login.svelte
index 9ff89fb..9cb2a45 100644
--- a/resources/js/pages/Auth/Login.svelte
+++ b/resources/js/pages/Auth/Login.svelte
@@ -1,5 +1,6 @@
 <script lang="ts">
     import { useForm } from "@inertiajs/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Checkbox from "@/components/atoms/Checkbox.svelte";
     import Input from "@/components/atoms/Input.svelte";
@@ -8,6 +9,7 @@
     import FormField from "@/components/molecules/FormField.svelte";
     import PasswordInput from "@/components/molecules/PasswordInput.svelte";
     import AuthLayout from "@/components/templates/AuthLayout.svelte";
+    import { isPasskeySupported, loginWithPasskey } from "@/lib/passkeys";
     import { providerLabel } from "@/lib/social";
 
     interface Props {
@@ -27,6 +29,38 @@
         event.preventDefault();
         form.post("/login");
     }
+
+    /*
+     * パスキーでのログイン。
+     * ceremony は fetch 完結で、成功時にサーバから受け取った着地 URL へ遷移する
+     * (Inertia のページ遷移とは無関係。transport 契約は詳細設計 施策 4-d)。
+     * 2要素認証を有効にしているアカウントはサーバが拒否する (PasskeyLoginPolicy)。
+     * 失敗しても**パスワード欄と SSO ボタンは残す** (回復導線を消さない)。
+     */
+    const passkeySupported = isPasskeySupported();
+    let passkeyError = $state("");
+    let passkeyProcessing = $state(false);
+
+    async function submitPasskey(): Promise<void> {
+        if (passkeyProcessing) return;
+        passkeyProcessing = true;
+        passkeyError = "";
+        try {
+            const outcome = await loginWithPasskey(form.remember);
+            if (outcome.status === "ok") {
+                window.location.assign(outcome.value.redirect);
+                return;
+            }
+            // キャンセルは失敗として騒がない (再試行導線を残す)
+            if (outcome.status === "cancelled") return;
+            passkeyError =
+                outcome.status === "unsupported"
+                    ? "このブラウザはパスキーに対応していません。"
+                    : outcome.message;
+        } finally {
+            passkeyProcessing = false;
+        }
+    }
 </script>
 
 <AuthLayout title="ログイン" {appName}>
@@ -61,6 +95,27 @@
         <Button type="submit" loading={form.processing} fullWidth>ログイン</Button>
     </form>
 
+    {#if passkeySupported}
+        <Divider label="または" class="my-6" />
+        <div class="flex flex-col gap-2">
+            {#if passkeyError}
+                <Alert type="danger" testId="passkey-login-error">{passkeyError}</Alert>
+            {/if}
+            <Button
+                variant="ghost"
+                fullWidth
+                loading={passkeyProcessing}
+                onclick={() => void submitPasskey()}
+                testId="passkey-login-button"
+            >
+                パスキーでログイン
+            </Button>
+            <p class="text-caption text-text-secondary">
+                2要素認証を有効にしているアカウントでは、パスキーでログインできません。
+            </p>
+        </div>
+    {/if}
+
     {#if socialProviders.length > 0}
         <Divider label="または" class="my-6" />
         <div class="flex flex-col gap-3">
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index df69394..186c328 100644
--- a/resources/js/pages/Settings/Security.svelte
+++ b/resources/js/pages/Settings/Security.svelte
@@ -11,12 +11,14 @@
     import FormField from "@/components/molecules/FormField.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
+    import PasskeySection from "@/components/features/auth/PasskeySection.svelte";
     import PageHeader from "@/components/molecules/PageHeader.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import PageContainer from "@/components/templates/PageContainer.svelte";
     import PageContent from "@/components/templates/PageContent.svelte";
     import { Settings } from "@lucide/svelte";
     import { useForm } from "@inertiajs/svelte";
+    import type { PasskeyListItem } from "@/lib/passkeys";
     import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
     import type { SharedProps } from "@/lib/shared-props";
     import { providerLabel } from "@/lib/social";
@@ -25,14 +27,31 @@
     interface Props {
         socialProviders?: string[];
         linkedProviders?: string[];
+        passkeys?: PasskeyListItem[];
+        /** passkey での「ログイン」が許されるか (TOTP 有効時は false。再認証には使える) */
+        passkeyLoginAvailable?: boolean;
     }
 
-    let { socialProviders = [], linkedProviders = [] }: Props = $props();
+    let {
+        socialProviders = [],
+        linkedProviders = [],
+        passkeys = [],
+        passkeyLoginAvailable = false,
+    }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
     const twoFactorEnabled = $derived(shared.auth?.user?.twoFactorEnabled ?? false);
 
+    /**
+     * EnsureLoginMethodRemains はログイン手段が 0 になる削除を
+     * **302 + errors.login_method** で拒否する (Inertia に 422 JSON を返すと無言失敗するため)。
+     * ここで拾って PasskeySection に渡し、画面上で明示する。
+     */
+    const loginMethodError = $derived(
+        (page.props as unknown as { errors?: Record<string, string> }).errors?.login_method,
+    );
+
     /* ----------------------------------------------------------------
      * 2FA 管理
      * 未有効 → 有効化開始 (POST) → QR + コード確認 (confirming)
@@ -521,6 +540,14 @@
                 {/if}
             </Card>
 
+            <PasskeySection
+                {passkeys}
+                {passkeyLoginAvailable}
+                {twoFactorEnabled}
+                {loginMethodError}
+                guard={guardWithRecentAuth}
+            />
+
             <Card padding="lg">
                 <h2 class="text-h3">ソーシャルログイン連携</h2>
                 <p class="mt-1 text-caption text-text-secondary">
@@ -577,6 +604,7 @@
             passwordSet={recentAuthStatus?.passwordSet ?? false}
             availableProviders={recentAuthStatus?.availableProviders ?? []}
             canSatisfy={recentAuthStatus?.canSatisfy ?? true}
+            passkeyAvailable={passkeys.length > 0}
             onConfirmed={resumePendingAction}
         />
         </PageContent>
diff --git a/routes/web.php b/routes/web.php
index d312f4a..e2e1e1e 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -45,11 +45,11 @@
 use App\Http\Controllers\Seo\SitemapController;
 use App\Http\Controllers\Settings\AccountController;
 use App\Http\Controllers\Settings\ProfileController;
+use App\Http\Controllers\Settings\SecurityController;
 use App\Http\Controllers\Webhooks\SesNotificationController;
 use App\Http\Middleware\HandleInertiaRequests;
 use App\Http\Middleware\LocalOnly;
 use App\Http\Middleware\NoIndex;
-use App\Models\User;
 use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
 use Illuminate\Cookie\Middleware\EncryptCookies;
 use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
@@ -57,7 +57,6 @@
 use Illuminate\Session\Middleware\StartSession;
 use Illuminate\Support\Facades\Route;
 use Illuminate\View\Middleware\ShareErrorsFromSession;
-use Inertia\Inertia;
 
 // トップページ (SEO full 分類の参考実装。SeoManager へのメタ供給は HomeController)
 Route::get('/', HomeController::class)->name('home');
@@ -186,18 +185,8 @@
 
     Route::get('/settings', [ProfileController::class, 'index'])->name('settings');
 
-    Route::get('/settings/security', function () {
-        // admin guard 追加で user() は User|AdminUser の union になるため narrowing する
-        $user = request()->user();
-        $linkedProviders = $user instanceof User
-            ? $user->socialAccounts()->pluck('provider')->all()
-            : [];
-
-        return Inertia::render('Settings/Security', [
-            'socialProviders' => array_keys(config()->array('template.social_providers')),
-            'linkedProviders' => $linkedProviders,
-        ]);
-    })->name('settings.security');
+    // 2FA / ソーシャル連携 / パスキーの管理面 (passkey 一覧の組み立てに DI が要るため Controller)
+    Route::get('/settings/security', SecurityController::class)->name('settings.security');
 
     // アカウント削除は step-up (recent-auth) 必須
     Route::delete('/settings/account', [AccountController::class, 'destroy'])
diff --git a/tests/Architecture/DocumentTitleCoverageTest.php b/tests/Architecture/DocumentTitleCoverageTest.php
index 0d0f3a0..dccd144 100644
--- a/tests/Architecture/DocumentTitleCoverageTest.php
+++ b/tests/Architecture/DocumentTitleCoverageTest.php
@@ -67,6 +67,10 @@ function documentTitleUnresolvableAllowlist(): array
         'two-factor.qr-code' => 'Fortify の 2FA QR (SVG/JSON) endpoint。ページを描画しない',
         'two-factor.secret-key' => 'Fortify の 2FA secret (JSON) endpoint。ページを描画しない',
         'two-factor.recovery-codes' => 'Fortify のリカバリコード (JSON) endpoint。ページを描画しない',
+        // --- passkey (WebAuthn) の options endpoint (JSON)。ceremony 用 challenge を返すのみ ---
+        'passkey.login-options' => 'WebAuthn ログイン options (JSON) endpoint。ページを描画しない',
+        'passkey.confirm-options' => 'WebAuthn 再認証 options (JSON) endpoint。ページを描画しない',
+        'passkey.registration-options' => 'WebAuthn 登録 options (JSON) endpoint。ページを描画しない',
         // --- Route::view の Blade スタブ (Inertia ではない。title は blade 側が持つ) ---
         'legal.terms' => 'Route::view の Blade スタブ (Inertia 非経由)。NoIndex middleware 付きの文面プレースホルダ',
         'legal.privacy' => 'Route::view の Blade スタブ (Inertia 非経由)。同上',
diff --git a/tests/Architecture/LoginMethodRemovalRouteTest.php b/tests/Architecture/LoginMethodRemovalRouteTest.php
new file mode 100644
index 0000000..3f4a5dd
--- /dev/null
+++ b/tests/Architecture/LoginMethodRemovalRouteTest.php
@@ -0,0 +1,155 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\EnsureLoginMethodRemains;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Support\Facades\Route;
+
+/*
+ * 「ログイン手段を減らす route」の分類 invariant (deny-by-default)。
+ *
+ * ログイン手段を全部消して自分で締め出す事故は復旧コストが高く、現場を止める。
+ * 候補となる route を構造的に列挙し、
+ *   (a) guard 必須 (ensure-login-method middleware を持つ) か
+ *   (b) 免除 (理由文字列つき)
+ * のどちらかに **必ず分類させる**。分類漏れは fail = 将来 SSO 解除 / passkey 削除 /
+ * パスワード削除 route を足したとき、guard の要否を必ず考えさせる。
+ *
+ * 本テストは分類漏れ・drift を落とす役割に限定する。実挙動 (投影評価・ロック・422) は
+ * tests/Feature/Auth/LoginMethodRetentionTest.php が担保する。
+ *
+ * 候補の構造的定義: 認証系 URI 空間 ('user/passkeys', 'settings/social', 'user/password',
+ * 'settings/account') に属する破壊的メソッド (DELETE / PUT / PATCH) の named route。
+ */
+
+/** @return list<string> guard 必須の route 名 */
+function loginMethodRemovalGuardedRoutes(): array
+{
+    return [
+        // passkey 削除 (credential 集合を減らす。最初の被保護 route)
+        'passkey.destroy',
+    ];
+}
+
+/** @return array<string, string> route 名 => 免除理由 (非空必須) */
+function loginMethodRemovalExemptRoutes(): array
+{
+    return [
+        // アカウント自体を消す操作。手段が 0 になるのは目的であって事故ではない。
+        // 別途 recent-auth (step-up) で保護済み。
+        'settings.account.destroy' => 'アカウント除去そのものであり、手段が残らないことが意図',
+        // 第二要素の除去であってログイン手段の除去ではない
+        // (TOTP を外してもパスワード / SSO / passkey は残る)。
+        'two-factor.disable' => '第二要素の除去でありログイン手段ではない',
+        // 変更であって除去ではない。current_password 必須で null 化できない。
+        'user-password.update' => 'パスワードの変更であり除去経路ではない (current_password 必須)',
+    ];
+}
+
+function routeHasLoginMethodGuard(RoutingRoute $route): bool
+{
+    $middleware = $route->gatherMiddleware();
+
+    return in_array('ensure-login-method', $middleware, true)
+        || in_array(EnsureLoginMethodRemains::class, $middleware, true);
+}
+
+test('ログイン手段を減らしうる route は guard 必須か免除のどちらかに分類されている', function (): void {
+    $prefixes = ['user/passkeys', 'settings/social', 'user/password', 'settings/account'];
+    $destructive = ['DELETE', 'PUT', 'PATCH'];
+
+    $guarded = loginMethodRemovalGuardedRoutes();
+    $exempt = loginMethodRemovalExemptRoutes();
+
+    $checked = 0;
+    $violations = [];
+
+    foreach (Route::getRoutes() as $route) {
+        $uri = $route->uri();
+        $matchesPrefix = false;
+        foreach ($prefixes as $prefix) {
+            if (str_starts_with($uri, $prefix)) {
+                $matchesPrefix = true;
+                break;
+            }
+        }
+        if (! $matchesPrefix || array_intersect($destructive, $route->methods()) === []) {
+            continue;
+        }
+
+        $name = $route->getName();
+        if ($name === null) {
+            $violations[] = "route {$uri} に名前が無く分類できない";
+
+            continue;
+        }
+
+        $checked++;
+
+        if (array_key_exists($name, $exempt)) {
+            expect(trim($exempt[$name]))->not->toBe('', "route '{$name}' の免除理由が空 (運用劣化)");
+
+            continue;
+        }
+
+        if (! in_array($name, $guarded, true)) {
+            $violations[] = "route '{$name}' が未分類 (guard 必須 or 免除のどちらかに登録すること)";
+
+            continue;
+        }
+
+        if (! routeHasLoginMethodGuard($route)) {
+            $violations[] = "route '{$name}' に ensure-login-method middleware が付与されていない";
+        }
+    }
+
+    expect($violations)->toBe([]);
+    // 1 本も検査されない = 候補判定が壊れた (空振り drift) ので fail させる
+    expect($checked)->toBeGreaterThan(0);
+});
+
+test('guard 必須リストの route は全て実在する', function (): void {
+    $routes = app('router')->getRoutes();
+    $routes->refreshNameLookups();
+
+    foreach (loginMethodRemovalGuardedRoutes() as $name) {
+        expect($routes->getByName($name))->not->toBeNull("route '{$name}' が存在しない (リネーム/削除に追従していない)");
+    }
+});
+
+test('免除リストの route は全て実在する', function (): void {
+    $routes = app('router')->getRoutes();
+    $routes->refreshNameLookups();
+
+    foreach (array_keys(loginMethodRemovalExemptRoutes()) as $name) {
+        expect($routes->getByName($name))->not->toBeNull("route '{$name}' が存在しない (陳腐化した免除登録)");
+    }
+});
+
+/*
+ * **allowlist 外への付与も禁じる (deny-by-default の逆方向)**。
+ *
+ * EnsureLoginMethodRemains は $next() を DB transaction 内で実行するため、
+ * controller / 同期 listener / Responsable 変換 / flash まで transaction に入る。
+ * 適用範囲が無自覚に広がると副作用範囲が急拡大する
+ * (streamed response / 外部 I/O / afterCommit でない queue dispatch は特に危険)。
+ * 付与してよい route を allowlist に固定し、増やすときは必ず判断させる。
+ */
+test('ensure-login-method middleware を持つ route は guard 必須リストのみ', function (): void {
+    $guarded = loginMethodRemovalGuardedRoutes();
+    $unexpected = [];
+
+    foreach (Route::getRoutes() as $route) {
+        if (! routeHasLoginMethodGuard($route)) {
+            continue;
+        }
+        $name = $route->getName() ?? $route->uri();
+        if (! in_array($name, $guarded, true)) {
+            $unexpected[] = "route '{$name}' に ensure-login-method が付いているが allowlist に無い"
+                .' (middleware は $next を transaction 内で実行する。適用条件を docblock で確認すること)';
+        }
+    }
+
+    expect($unexpected)->toBe([]);
+});
diff --git a/tests/Architecture/PasskeyPackageContractTest.php b/tests/Architecture/PasskeyPackageContractTest.php
new file mode 100644
index 0000000..925eb5b
--- /dev/null
+++ b/tests/Architecture/PasskeyPackageContractTest.php
@@ -0,0 +1,145 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Responses\Passkey\PasskeyConfirmationResponse;
+use App\Http\Responses\Passkey\PasskeyDeletedResponse;
+use App\Http\Responses\Passkey\PasskeyLoginResponse;
+use App\Http\Responses\Passkey\PasskeyRegistrationResponse;
+use App\Models\Passkey;
+use App\Models\User;
+use Illuminate\Database\Eloquent\ModelNotFoundException;
+use Laravel\Fortify\Features;
+use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
+use Laravel\Passkeys\Contracts\PasskeyDeletedResponse as PasskeyDeletedResponseContract;
+use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
+use Laravel\Passkeys\Contracts\PasskeyRegistrationResponse as PasskeyRegistrationResponseContract;
+use Laravel\Passkeys\Contracts\PasskeyUser;
+use Laravel\Passkeys\Http\Controllers\PasskeyConfirmationController;
+use Laravel\Passkeys\Http\Controllers\PasskeyLoginController;
+use Laravel\Passkeys\Http\Controllers\PasskeyRegistrationController;
+use Laravel\Passkeys\Passkeys;
+
+/*
+ * laravel/passkeys (Fortify 1.37 の推移依存) とアプリの結線契約を固定する。
+ *
+ * 守る事故:
+ *   - パッケージ側 routes の二重登録 (Fortify が feature flag でゲートした route と衝突する)
+ *   - Fortify 標準の password.confirm が復活し SSO-only ユーザーが詰む
+ *   - config:cache 下で fortify-options.passkeys が落ちる
+ *   - binder が vendor 実装のまま残り、他人の passkey の存在が 403 で漏れる
+ *
+ * DB を伴う実挙動 (他人の passkey が 404 になること) は
+ * tests/Feature/Auth/PasskeyRouteAccessTest.php が担保する
+ * (Architecture レーンは RefreshDatabase を持たないため DB に触れない)。
+ */
+
+/** @return list<string> Fortify が登録する passkey route の名前 */
+function passkeyRouteNames(): array
+{
+    return [
+        'passkey.login-options',
+        'passkey.login',
+        'passkey.confirm-options',
+        'passkey.confirm',
+        'passkey.registration-options',
+        'passkey.store',
+        'passkey.destroy',
+    ];
+}
+
+test('パッケージ側の passkey routes は登録されない (Fortify 側が唯一の登録点)', function (): void {
+    expect(Passkeys::shouldRegisterRoutes())->toBeFalse();
+});
+
+test('passkeys feature が有効 (キルスイッチが on)', function (): void {
+    expect(Features::enabled(Features::passkeys()))->toBeTrue();
+});
+
+test('passkey route 7 本が実在し vendor controller に紐づく', function (): void {
+    $routes = app('router')->getRoutes();
+    $routes->refreshNameLookups();
+
+    $expectedControllers = [
+        PasskeyLoginController::class,
+        PasskeyConfirmationController::class,
+        PasskeyRegistrationController::class,
+    ];
+
+    foreach (passkeyRouteNames() as $name) {
+        $route = $routes->getByName($name);
+        expect($route)->not->toBeNull("route '{$name}' が存在しない");
+
+        $controller = $route->getAction('controller');
+        expect($controller)->toBeString();
+
+        $matched = false;
+        foreach ($expectedControllers as $expected) {
+            if (str_starts_with((string) $controller, $expected.'@')) {
+                $matched = true;
+                break;
+            }
+        }
+        expect($matched)->toBeTrue("route '{$name}' の action が vendor controller ではない: {$controller}");
+    }
+});
+
+test('passkeys の confirmPassword は false (generic recent-auth へ統一)', function (): void {
+    expect(config('fortify-options.passkeys.confirmPassword'))->toBeFalse();
+});
+
+test('passkeys の throttle limiter が設定されている (未認証 challenge 無制限の防止)', function (): void {
+    expect(config('fortify.limiters.passkeys'))->toBe('passkeys');
+});
+
+/*
+ * config:cache 下でも値が残ることを検査する。
+ * ConfigCacheCommand は `'<?php return '.var_export($config, true).';'` を書き出すため、
+ * その **serialize 機構そのものを再現**して往復させる
+ * (Pest から config:cache を実行すると bootstrap/cache/config.php を書き換え、
+ *  --parallel 実行を壊すため実行しない)。
+ */
+test('config cache 往復後も fortify-options.passkeys と features が残る', function (): void {
+    $subset = [
+        'fortify' => config('fortify'),
+        'fortify-options' => config('fortify-options'),
+    ];
+
+    $exported = var_export($subset, true);
+    /** @var array<string, mixed> $roundTripped */
+    $roundTripped = eval('return '.$exported.';');
+
+    expect(data_get($roundTripped, 'fortify-options.passkeys.confirmPassword'))->toBeFalse();
+    expect(data_get($roundTripped, 'fortify.features'))->toContain('passkeys');
+    expect(data_get($roundTripped, 'fortify.limiters.passkeys'))->toBe('passkeys');
+});
+
+test('モデル差し替えが app 実装になっている', function (): void {
+    expect(Passkeys::passkeyModel())->toBe(Passkey::class);
+    expect(Passkeys::userModel())->toBe(User::class);
+    expect(is_a(User::class, PasskeyUser::class, true))->toBeTrue();
+});
+
+test('Response contract 4 本が app 実装に差し替えられている (response()->json 直書きの回避)', function (): void {
+    expect(app(PasskeyLoginResponseContract::class))->toBeInstanceOf(PasskeyLoginResponse::class);
+    expect(app(PasskeyConfirmationResponseContract::class))->toBeInstanceOf(PasskeyConfirmationResponse::class);
+    expect(app(PasskeyRegistrationResponseContract::class))->toBeInstanceOf(PasskeyRegistrationResponse::class);
+    expect(app(PasskeyDeletedResponseContract::class))->toBeInstanceOf(PasskeyDeletedResponse::class);
+});
+
+/*
+ * binder の **最終解決系**がアプリ実装であることを固定する。
+ *
+ * vendor の binder は `app($model)->resolveRouteBinding($value)` でグローバル解決するため、
+ * guest 文脈でも解決に成功しうる (= その後 controller の 403 に到達して存在が漏れる)。
+ * アプリ実装 (SelfScopedPasskeyBinder) は guest を DB へ行かずに 404 相当へ倒すので、
+ * **DB に触れずに** 差し替えの成否を判定できる。
+ */
+test('passkey binder の最終解決系がアプリ実装 (guest は DB を引かずに 404 相当)', function (): void {
+    $callback = app('router')->getBindingCallback('passkey');
+
+    expect($callback)->not->toBeNull('{passkey} の explicit binder が登録されていない');
+
+    // class binding は Router::createClassBinding により ($value, $route) の 2 引数 closure になる
+    expect(fn () => $callback('1', null))->toThrow(ModelNotFoundException::class);
+});
diff --git a/tests/Architecture/PasskeyRouteProtectionTest.php b/tests/Architecture/PasskeyRouteProtectionTest.php
new file mode 100644
index 0000000..9610876
--- /dev/null
+++ b/tests/Architecture/PasskeyRouteProtectionTest.php
@@ -0,0 +1,96 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\EnsureLoginMethodRemains;
+use App\Http\Middleware\NoStoreResponse;
+use App\Http\Middleware\RequireRecentAuth;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Routing\Router;
+
+/*
+ * passkey route の middleware 構成を列挙で固定する。
+ *
+ * passkey route は **vendor (Fortify) が登録**し、アプリ側は
+ * PasskeyServiceProvider::attachMiddlewareToPasskeyRoutes() が booted 後に後付けする。
+ * 後付けは「配線が消えても route は生き続ける」壊れ方をするため、構成を機械的に固定する。
+ */
+
+/** @return array<string, list<string>> route 名 => 必須 middleware (alias 文字列) */
+function passkeyRouteMiddlewareInventory(): array
+{
+    return [
+        // guest。challenge を載せるため no-store を後付けする
+        // (NoStoreCacheHeadersForAuthenticatedPages は認証済みのみが対象)
+        'passkey.login-options' => ['web', 'guest:web', 'throttle:passkeys', 'no-store'],
+        'passkey.login' => ['web', 'guest:web', 'throttle:passkeys'],
+        // step-up satisfier。recent-auth は課さない (これ自体が satisfier のため)
+        'passkey.confirm-options' => ['web', 'auth:web', 'throttle:passkeys'],
+        'passkey.confirm' => ['web', 'auth:web', 'throttle:passkeys'],
+        // credential 集合を増やす管理経路
+        'passkey.registration-options' => ['web', 'auth:web', 'throttle:passkeys', 'recent-auth'],
+        'passkey.store' => ['web', 'auth:web', 'throttle:passkeys', 'recent-auth'],
+        // credential 集合を減らす管理経路 (手段保持 guard つき)
+        'passkey.destroy' => ['web', 'auth:web', 'recent-auth', 'ensure-login-method'],
+    ];
+}
+
+function passkeyRoute(string $name): RoutingRoute
+{
+    $routes = app('router')->getRoutes();
+    $routes->refreshNameLookups();
+    $route = $routes->getByName($name);
+
+    expect($route)->not->toBeNull("route '{$name}' が存在しない");
+
+    return $route;
+}
+
+test('passkey route の middleware 構成が inventory と一致する', function (): void {
+    foreach (passkeyRouteMiddlewareInventory() as $name => $expected) {
+        $actual = passkeyRoute($name)->gatherMiddleware();
+
+        foreach ($expected as $middleware) {
+            // toContain は可変長 needle を取るため、メッセージ付きの表明は in_array で行う
+            expect(in_array($middleware, $actual, true))->toBeTrue(
+                "route '{$name}' に middleware '{$middleware}' が付与されていない (実際: ".implode(', ', $actual).')',
+            );
+        }
+    }
+});
+
+test('passkey route に password.confirm が付いていない (generic recent-auth へ統一済み)', function (): void {
+    foreach (array_keys(passkeyRouteMiddlewareInventory()) as $name) {
+        expect(in_array('password.confirm', passkeyRoute($name)->gatherMiddleware(), true))
+            ->toBeFalse("route '{$name}' に password.confirm が復活している");
+    }
+});
+
+/*
+ * **実行順**: recent-auth を先に通し、その後で手段保持を検査する。
+ * 逆順だと stale recent-auth のリクエストでも User 行ロックを取りに行ってしまう。
+ * alias 文字列だけでなく **解決後のクラス列** ($middlewarePriority による並べ替え込み) で見る。
+ */
+test('passkey.destroy は recent-auth が ensure-login-method より先に走る', function (): void {
+    /** @var Router $router */
+    $router = app('router');
+    $resolved = $router->gatherRouteMiddleware(passkeyRoute('passkey.destroy'));
+
+    $recentAuthIndex = array_search(RequireRecentAuth::class, $resolved, true);
+    $loginMethodIndex = array_search(EnsureLoginMethodRemains::class, $resolved, true);
+
+    expect($recentAuthIndex)->not->toBeFalse('RequireRecentAuth が解決後の middleware 列に無い');
+    expect($loginMethodIndex)->not->toBeFalse('EnsureLoginMethodRemains が解決後の middleware 列に無い');
+    expect($recentAuthIndex)->toBeLessThan(
+        $loginMethodIndex,
+        'recent-auth より先に ensure-login-method が走ると stale なリクエストでも User 行ロックを取る',
+    );
+});
+
+test('passkey.login-options の no-store は解決後も NoStoreResponse に落ちる', function (): void {
+    /** @var Router $router */
+    $router = app('router');
+    $resolved = $router->gatherRouteMiddleware(passkeyRoute('passkey.login-options'));
+
+    expect($resolved)->toContain(NoStoreResponse::class);
+});
diff --git a/tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php b/tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php
new file mode 100644
index 0000000..6352a74
--- /dev/null
+++ b/tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\Facades\Route;
+
+/*
+ * `password.confirm` middleware の **全 route での不在** を deny-by-default で固定する。
+ *
+ * 本アプリは Fortify 標準の password.confirm (3h 窓・パスワード限定) を撤去し、
+ * generic recent-auth (15 分窓・パスワード or 再SSO or パスキー) へ統一している。
+ * password.confirm が復活すると:
+ *   1. SSO-only ユーザー (password 未設定) がその route で**詰む** (satisfier が無い)
+ *   2. confirmPasswordView は recent-auth.confirm への redirect でしかなく
+ *      `auth.password_confirmed_at` を満たせないため無限ループになる (bug-hunt F-11)
+ *
+ * 特に laravel/passkeys は config 既定が `management_middleware = ['password.confirm']` で、
+ * `fortify-options.passkeys.confirmPassword` を落とすと即座に復活する。
+ */
+test('password.confirm middleware を持つ route が 1 本も無い', function (): void {
+    $violations = [];
+    $checked = 0;
+
+    foreach (Route::getRoutes() as $route) {
+        $checked++;
+
+        foreach ($route->gatherMiddleware() as $middleware) {
+            if (! is_string($middleware)) {
+                continue;
+            }
+            if ($middleware === 'password.confirm' || str_starts_with($middleware, 'password.confirm:')) {
+                $violations[] = $route->getName() ?? implode('|', $route->methods()).':'.$route->uri();
+            }
+        }
+    }
+
+    expect($violations)->toBe(
+        [],
+        'password.confirm は generic recent-auth へ置換済み。復活すると SSO-only ユーザーが詰む: '
+        .implode(', ', $violations),
+    );
+    // route 走査自体が空振りしていないこと
+    expect($checked)->toBeGreaterThan(0);
+});
diff --git a/tests/Architecture/RecentAuthRouteTest.php b/tests/Architecture/RecentAuthRouteTest.php
index bc6517e..55995ba 100644
--- a/tests/Architecture/RecentAuthRouteTest.php
+++ b/tests/Architecture/RecentAuthRouteTest.php
@@ -2,7 +2,11 @@
 
 declare(strict_types=1);
 
+use App\Http\Controllers\Auth\ConfirmRecentAuthController;
+use App\Http\Controllers\Auth\SocialAuthController;
 use App\Http\Middleware\RequireRecentAuth;
+use App\Listeners\Auth\StampRecentAuthOnLogin;
+use App\Listeners\Auth\StampRecentAuthOnPasskeyVerified;
 use Illuminate\Routing\Route as RoutingRoute;
 use Illuminate\Routing\Router;
 
@@ -40,6 +44,13 @@ function recentAuthRequiredRouteNames(): array
         // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()。
         // routeHasRecentAuth は 'recent-auth.on-email-change' も str_starts_with で検出)
         'user-profile-information.update',
+        // passkey 管理 (credential 集合を増減させる経路。配線は
+        // App\Providers\PasskeyServiceProvider::attachMiddlewareToPasskeyRoutes())。
+        // passkey.confirm / passkey.confirm-options は **satisfier 側**のため対象外
+        // (自分自身に step-up を要求すると詰む)。
+        'passkey.registration-options',
+        'passkey.store',
+        'passkey.destroy',
     ];
 }
 
@@ -70,3 +81,166 @@ function routeHasRecentAuth(RoutingRoute $route): bool
         expect(routeHasRecentAuth($route))->toBeTrue("route '{$name}' に recent-auth middleware が付与されていない (付け忘れ)");
     }
 });
+
+/*
+|--------------------------------------------------------------------------
+| satisfier 集合の inventory (deny-by-default)
+|--------------------------------------------------------------------------
+|
+| RecentAuthState::confirm() は「鮮度が成立した」と宣言する唯一の writer であり、
+| 呼び出し元が増えることは step-up の成立条件が増えることそのものである。
+| 未登録の呼び出し元が生えたら fail させ、PR review で必ず判断させる。
+|
+| ⚠ **この走査の限界**: token_get_all() ベースの静的走査であり、
+|   「RecentAuthState を参照しているファイルの中の `->confirm(` 呼び出し」という
+|   保守的な近似で検出する。完全に動的な呼び出し
+|   (`$cls = 'App\Security\RecentAuthState'; app($cls)->confirm()`) は取り逃がす。
+|   本テストの役割は「新しい satisfier を足すときに必ず PR で判断させる」ことに限定し、
+|   完全性の証明ではない。より強い保証が必要になったら AGENTS.md のコードベース探索方針
+|   どおり code-review-graph の AST グラフへ寄せること。
+*/
+
+/**
+ * RecentAuthState::confirm() を呼んでよいクラスの FQCN。
+ *
+ * @return list<string>
+ */
+function recentAuthSatisfierClasses(): array
+{
+    return [
+        // password 再入力
+        ConfirmRecentAuthController::class,
+        // 再SSO (step-up intent。本人性バインド済み)
+        SocialAuthController::class,
+        // fresh credential login (web guard・非 recaller)
+        StampRecentAuthOnLogin::class,
+        // passkey 検証成立 (confirm 経路。login 経路では Login が後勝ち。本人性バインド済み)
+        StampRecentAuthOnPasskeyVerified::class,
+    ];
+}
+
+/**
+ * @return list<string> app/ 配下の php ファイル絶対パス
+ */
+function recentAuthPhpFilesUnderApp(): array
+{
+    $files = [];
+    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
+
+    foreach ($iterator as $file) {
+        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
+            $files[] = $file->getPathname();
+        }
+    }
+
+    sort($files);
+
+    return $files;
+}
+
+/**
+ * ソースが RecentAuthState::confirm() を呼んでいるか、および宣言クラスの FQCN。
+ *
+ * @return array{callsConfirm: bool, fqcn: string|null}
+ */
+function analyzeRecentAuthConfirmCalls(string $source): array
+{
+    $tokens = PhpToken::tokenize($source);
+    $count = count($tokens);
+
+    $namespace = null;
+    $class = null;
+    $referencesState = false;
+    $callsConfirm = false;
+
+    for ($i = 0; $i < $count; $i++) {
+        $token = $tokens[$i];
+
+        if ($token->is(T_NAMESPACE) && $namespace === null) {
+            $parts = [];
+            for ($j = $i + 1; $j < $count; $j++) {
+                if ($tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
+                    $parts[] = $tokens[$j]->text;
+                } elseif ($tokens[$j]->text === ';' || $tokens[$j]->text === '{') {
+                    break;
+                }
+            }
+            $namespace = implode('\\', $parts);
+
+            continue;
+        }
+
+        if ($token->is(T_CLASS) && $class === null) {
+            for ($j = $i + 1; $j < $count; $j++) {
+                if ($tokens[$j]->is(T_STRING)) {
+                    $class = $tokens[$j]->text;
+                    break;
+                }
+                if ($tokens[$j]->text === '(') {
+                    break;   // 匿名クラス
+                }
+            }
+
+            continue;
+        }
+
+        // RecentAuthState への参照 (import / FQCN / 短縮名のいずれか)
+        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
+            && str_contains($token->text, 'RecentAuthState')) {
+            $referencesState = true;
+
+            continue;
+        }
+
+        // `->confirm(` の検出
+        if ($token->is(T_OBJECT_OPERATOR)
+            && isset($tokens[$i + 1])
+            && $tokens[$i + 1]->is(T_STRING)
+            && $tokens[$i + 1]->text === 'confirm') {
+            $callsConfirm = true;
+        }
+    }
+
+    $fqcn = ($namespace !== null && $namespace !== '' && $class !== null)
+        ? $namespace.'\\'.$class
+        : null;
+
+    return [
+        'callsConfirm' => $referencesState && $callsConfirm,
+        'fqcn' => $fqcn,
+    ];
+}
+
+test('RecentAuthState::confirm の呼び出し元は inventory に登録されたクラスのみ', function (): void {
+    $allowed = recentAuthSatisfierClasses();
+    $violations = [];
+    $checked = 0;
+
+    foreach (recentAuthPhpFilesUnderApp() as $path) {
+        $source = file_get_contents($path);
+        if (! is_string($source)) {
+            continue;
+        }
+
+        $analysis = analyzeRecentAuthConfirmCalls($source);
+        if (! $analysis['callsConfirm']) {
+            continue;
+        }
+
+        $checked++;
+
+        if ($analysis['fqcn'] === null || ! in_array($analysis['fqcn'], $allowed, true)) {
+            $violations[] = "{$path} が RecentAuthState::confirm() を呼んでいるが satisfier inventory に未登録";
+        }
+    }
+
+    expect($violations)->toBe([]);
+    // 呼び出し元が 1 件も見つからない = 走査が壊れている (空振り drift)
+    expect($checked)->toBeGreaterThan(0);
+});
+
+test('satisfier inventory のクラスは全て実在する', function (): void {
+    foreach (recentAuthSatisfierClasses() as $fqcn) {
+        expect(class_exists($fqcn))->toBeTrue("satisfier inventory のクラス {$fqcn} が存在しない");
+    }
+});
diff --git a/tests/Feature/Auth/LoginMethodInventoryTest.php b/tests/Feature/Auth/LoginMethodInventoryTest.php
new file mode 100644
index 0000000..3ebfbb8
--- /dev/null
+++ b/tests/Feature/Auth/LoginMethodInventoryTest.php
@@ -0,0 +1,193 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Auth\LoginMethodRemoval;
+use App\Models\Passkey;
+use App\Models\SocialAccount;
+use App\Models\User;
+use App\Services\Auth\LoginMethodInventory;
+use App\Services\Auth\PasskeyLoginPolicy;
+use Illuminate\Http\Request;
+use Laravel\Fortify\Features;
+use Laravel\Passkeys\Passkeys;
+
+/*
+ * LoginMethodInventory (投影後のログイン手段集合) と PasskeyLoginPolicy の契約。
+ *
+ * 基準は「データが存在する」ではなく「**使える**」。feature を落とした後も使えない手段を
+ * 数えると EnsureLoginMethodRemains が形骸化する。
+ */
+
+function inventory(): LoginMethodInventory
+{
+    return app(LoginMethodInventory::class);
+}
+
+function linkSocialAccount(User $user, string $provider = 'google'): void
+{
+    $account = new SocialAccount([
+        'provider' => $provider,
+        'provider_user_id' => 'ext-'.$user->getKey().'-'.$provider,
+    ]);
+    $account->user()->associate($user);
+    $account->save();
+}
+
+/** config/fortify.php の features から passkeys を外す (キルスイッチの再現) */
+function disablePasskeyFeature(): void
+{
+    config()->set(
+        'fortify.features',
+        array_values(array_filter(
+            config()->array('fortify.features'),
+            static fn (mixed $feature): bool => $feature !== Features::passkeys(),
+        )),
+    );
+}
+
+test('password ユーザーは password を手段に持つ', function (): void {
+    $user = User::factory()->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->methods)
+        ->toContain('password');
+});
+
+test('SSO 登録ユーザー (ssoOnly) は password を手段に持たない', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->methods)
+        ->not->toContain('password');
+});
+
+test('連携済み provider は social: 付きで数えられる', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    linkSocialAccount($user);
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->methods)
+        ->toContain('social:google');
+});
+
+test('config から外された provider は連携行があっても数えない (fail-closed)', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    linkSocialAccount($user);
+
+    config()->set('template.social_providers', []);
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->isEmpty())->toBeTrue();
+});
+
+test('social 除去の投影で当該 provider が集合から消える', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    linkSocialAccount($user);
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::social('google'))->isEmpty())
+        ->toBeTrue();
+});
+
+test('password 除去の投影で password が集合から消える', function (): void {
+    $user = User::factory()->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::password())->isEmpty())
+        ->toBeTrue();
+});
+
+test('passkey は登録済みなら手段に数えられる', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    Passkey::factory()->for($user)->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->methods)
+        ->toContain('passkey');
+});
+
+test('削除対象の passkey は残存手段として数えない (投影)', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::passkey($passkey, $user))->isEmpty())
+        ->toBeTrue();
+});
+
+test('passkey が 2 件あれば 1 件削除しても手段が残る', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    $first = Passkey::factory()->for($user)->create();
+    Passkey::factory()->for($user)->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::passkey($first, $user))->methods)
+        ->toContain('passkey');
+});
+
+test('allPasskeys 投影では passkey が全て消える', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    Passkey::factory()->count(2)->for($user)->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::allPasskeys())->isEmpty())
+        ->toBeTrue();
+});
+
+test('feature off では passkey を手段に数えない (キルスイッチが inventory に連動する)', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    Passkey::factory()->for($user)->create();
+
+    disablePasskeyFeature();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->isEmpty())->toBeTrue();
+});
+
+test('TOTP confirmed ユーザーは passkey を手段に数えない (passkey login が拒否されるため)', function (): void {
+    $user = User::factory()->ssoOnly()->withTwoFactor()->create();
+    Passkey::factory()->for($user)->create();
+
+    expect(inventory()->remainingAfter($user, LoginMethodRemoval::none())->isEmpty())->toBeTrue();
+});
+
+/* ---------------------------------------------------------------- 不正状態の排除 */
+
+test('他人の passkey を LoginMethodRemoval::passkey に渡すと例外', function (): void {
+    $user = User::factory()->create();
+    $other = User::factory()->create();
+    $passkey = Passkey::factory()->for($other)->create();
+
+    expect(fn () => LoginMethodRemoval::passkey($passkey, $user))
+        ->toThrow(InvalidArgumentException::class);
+});
+
+test('空 provider を LoginMethodRemoval::social に渡すと例外', function (): void {
+    expect(fn () => LoginMethodRemoval::social(''))
+        ->toThrow(InvalidArgumentException::class);
+});
+
+/* -------------------------------------------- inventory と login authorization の一致 */
+
+/*
+ * 構造 gate では「同じ判定を 2 箇所に書いていない」ことしか固定できないため、
+ * 意味レベル (両者の結論が常に一致すること) をここで固定する。
+ */
+test('inventory の passkey 判定と Passkeys::allowsLogin が一致する (TOTP × feature の 4 組合せ)', function (
+    bool $twoFactor,
+    bool $featureEnabled,
+): void {
+    $factory = User::factory()->ssoOnly();
+    $user = ($twoFactor ? $factory->withTwoFactor() : $factory)->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    if (! $featureEnabled) {
+        disablePasskeyFeature();
+    }
+
+    $inventoryHasPasskey = in_array(
+        'passkey',
+        inventory()->remainingAfter($user->fresh() ?? $user, LoginMethodRemoval::none())->methods,
+        true,
+    );
+
+    $vendorAllows = Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey);
+
+    expect($inventoryHasPasskey)->toBe($vendorAllows);
+    expect($vendorAllows)->toBe(app(PasskeyLoginPolicy::class)->allowsPasskeyLogin($user->fresh() ?? $user));
+})->with([
+    'TOTP なし / feature on' => [false, true],
+    'TOTP あり / feature on' => [true, true],
+    'TOTP なし / feature off' => [false, false],
+    'TOTP あり / feature off' => [true, false],
+]);
diff --git a/tests/Feature/Auth/LoginMethodRetentionTest.php b/tests/Feature/Auth/LoginMethodRetentionTest.php
new file mode 100644
index 0000000..a444a5a
--- /dev/null
+++ b/tests/Feature/Auth/LoginMethodRetentionTest.php
@@ -0,0 +1,222 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Passkey;
+use App\Models\SocialAccount;
+use App\Models\User;
+use Illuminate\Support\Facades\DB;
+use Inertia\Testing\AssertableInertia;
+
+/*
+ * EnsureLoginMethodRemains の実挙動 (投影後評価・transport 別の拒否契約・直列化機構)。
+ *
+ * 分類 invariant (どの route に guard が必要か) は
+ * tests/Architecture/LoginMethodRemovalRouteTest.php が担う。
+ */
+
+/** password / social を持たず passkey だけでログインするユーザー */
+function passkeyOnlyUser(int $passkeys = 1): User
+{
+    $user = User::factory()->ssoOnly()->create();
+    Passkey::factory()->count($passkeys)->for($user)->create();
+
+    return $user;
+}
+
+function linkGoogleTo(User $user): void
+{
+    $account = new SocialAccount(['provider' => 'google', 'provider_user_id' => 'g-'.$user->getKey()]);
+    $account->user()->associate($user);
+    $account->save();
+}
+
+/* ------------------------------------------------------------ 拒否 (手段が 0 になる) */
+
+/*
+ * Inertia には **422 JSON を返さない** (protocol 違反で router が解釈できず無言失敗する)。
+ * 302 + errors を返し、Inertia が DELETE の 302 を 303 へ変換する。
+ * 次の Inertia 訪問で `$page.props.errors.login_method` として読めることまで固定する
+ * (Svelte 側の表示契約そのもの)。
+ */
+test('唯一の passkey の削除は Inertia に redirect + errors.login_method で拒否される', function (): void {
+    $user = passkeyOnlyUser();
+    $passkey = $user->passkeys()->firstOrFail();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->withHeaders(['X-Inertia' => 'true'])
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertStatus(303)
+        ->assertRedirect(route('settings.security'));
+
+    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();
+
+    // withHeaders はテスト内で永続するため明示的に捨てる。
+    // GET は素の HTML 訪問で検査する (X-Inertia を付けると asset version 不一致で 409 になる)
+    $this->flushHeaders();
+
+    $this->actingAs($user)
+        ->get(route('settings.security'))
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->component('Settings/Security')
+            ->where('errors.login_method', 'この操作を行うと、ログインする手段がなくなります。先に別のログイン手段（パスワードの設定、ソーシャル連携、他のパスキー）を追加してください。'));
+});
+
+test('唯一の passkey の削除は純 XHR に 422 + login_method_required で拒否される', function (): void {
+    $user = passkeyOnlyUser();
+    $passkey = $user->passkeys()->firstOrFail();
+
+    $response = $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->deleteJson("/user/passkeys/{$passkey->getKey()}");
+
+    $response->assertStatus(422)
+        ->assertHeader('Cache-Control', 'no-store, private')
+        ->assertJsonPath('code', 'login_method_required')
+        ->assertJsonPath('settingsUrl', route('settings.security'));
+    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();
+});
+
+test('唯一の passkey の削除は通常フォーム POST に back + errors で拒否される', function (): void {
+    $user = passkeyOnlyUser();
+    $passkey = $user->passkeys()->firstOrFail();
+
+    $response = $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}");
+
+    $response->assertRedirect(route('settings.security'));
+    $response->assertSessionHasErrors('login_method');
+});
+
+test('TOTP confirmed ユーザーは passkey が 2 件あっても手段に数えないため削除が拒否される', function (): void {
+    $user = User::factory()->ssoOnly()->withTwoFactor()->create();
+    Passkey::factory()->count(2)->for($user)->create();
+    $passkey = $user->passkeys()->firstOrFail();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertSessionHasErrors('login_method');
+
+    expect($user->passkeys()->count())->toBe(2);
+});
+
+/* ------------------------------------------------------------ 許可 (手段が残る) */
+
+test('passkey が 2 件あれば 1 件削除できる', function (): void {
+    $user = passkeyOnlyUser(passkeys: 2);
+    $passkey = $user->passkeys()->firstOrFail();
+
+    $response = $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}");
+
+    $response->assertRedirect(route('settings.security'));
+    $response->assertSessionHasNoErrors();
+    $response->assertSessionHas('success');
+    expect($user->passkeys()->count())->toBe(1);
+});
+
+test('password があれば唯一の passkey を削除できる', function (): void {
+    $user = User::factory()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertSessionHasNoErrors();
+
+    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeFalse();
+});
+
+test('google 連携があれば唯一の passkey を削除できる', function (): void {
+    $user = User::factory()->ssoOnly()->create();
+    linkGoogleTo($user);
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertSessionHasNoErrors();
+
+    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeFalse();
+});
+
+/* ------------------------------------------------------------ 直列化規約 (SQL レベル) */
+
+/*
+ * **このテストの限界を明記する**:
+ *   RefreshDatabase がテスト全体を 1 トランザクションで包むため、独立 connection による
+ *   実レース (passkey 2 件を同時削除して 0 件になる) は再現できない。
+ *   ここで固定するのは **機構**:
+ *     (a) 削除より前に users への `for update` select が発行される
+ *     (b) 両者が同一の transaction level で観測される
+ *     (c) その level がリクエスト開始前の level より大きい (middleware が新たに開いた証明)
+ *   ロックの **効果** (競合トランザクションの待機) は DB に委ねる。
+ */
+test('passkey 削除は users 行の for update ロック取得後に同一トランザクションで実行される', function (): void {
+    $user = passkeyOnlyUser(passkeys: 2);
+    $passkey = $user->passkeys()->firstOrFail();
+
+    $baseLevel = DB::transactionLevel();
+
+    /** @var list<array{sql: string, level: int}> $observed */
+    $observed = [];
+    DB::listen(function ($query) use (&$observed): void {
+        $observed[] = ['sql' => strtolower($query->sql), 'level' => DB::transactionLevel()];
+    });
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertSessionHasNoErrors();
+
+    $lockIndex = null;
+    $deleteIndex = null;
+    foreach ($observed as $index => $entry) {
+        if ($lockIndex === null && str_contains($entry['sql'], 'from "users"') && str_contains($entry['sql'], 'for update')) {
+            $lockIndex = $index;
+        }
+        if ($deleteIndex === null && str_starts_with($entry['sql'], 'delete from "passkeys"')) {
+            $deleteIndex = $index;
+        }
+    }
+
+    expect($lockIndex)->not->toBeNull('users 行の lockForUpdate が発行されていない');
+    expect($deleteIndex)->not->toBeNull('passkeys の delete が発行されていない');
+    expect($lockIndex)->toBeLessThan($deleteIndex, 'ロック取得より前に削除が走っている (TOCTOU)');
+
+    $lockLevel = $observed[$lockIndex]['level'];
+    expect($observed[$deleteIndex]['level'])->toBe($lockLevel, 'ロックと削除が別トランザクション');
+    // RefreshDatabase がテスト全体を包むため level は 1 から始まらない。必ず相対比較する
+    expect($lockLevel)->toBeGreaterThan($baseLevel, 'middleware が新しいトランザクションを開いていない');
+});
+
+test('拒否時には passkeys の delete が発行されない', function (): void {
+    $user = passkeyOnlyUser();
+    $passkey = $user->passkeys()->firstOrFail();
+
+    /** @var list<string> $statements */
+    $statements = [];
+    DB::listen(function ($query) use (&$statements): void {
+        $statements[] = strtolower($query->sql);
+    });
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertSessionHasErrors('login_method');
+
+    $deletes = array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'delete from "passkeys"'));
+    expect($deletes)->toBe([]);
+});
diff --git a/tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php b/tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php
new file mode 100644
index 0000000..8a98156
--- /dev/null
+++ b/tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php
@@ -0,0 +1,103 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Passkey;
+use App\Models\User;
+use App\Security\RecentAuthState;
+use Laravel\Passkeys\Events\PasskeyDeleted;
+use Laravel\Passkeys\Events\PasskeyRegistered;
+use Laravel\Passkeys\Events\PasskeyVerified;
+
+/*
+ * 2026-08-04 裁定 A: **credential 集合の変化 = recent-auth 失効**。
+ *
+ * パスキーは単独でログインできる強い資格であり、集合が変わったら直前に済ませた
+ * 本人確認は失効させる (家系統一原則)。UX の実害は「登録直後のタップ 1 回」に限られる。
+ *
+ * **裁定で見送られた強化オプション (登録直後の passkey を satisfier から除外する) は
+ * 実装しない**。そのことも本テストが明示的に固定する (実装されたら fail する)。
+ */
+
+test('passkey 削除で recent-auth 鮮度が失効する (実 HTTP 経路)', function (): void {
+    $user = User::factory()->create();   // password あり = 削除しても手段が残る
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertSessionHasNoErrors();
+
+    expect(session()->has('recent_auth_at'))->toBeFalse();
+});
+
+test('passkey 削除の直後は機微操作が step-up を要求する', function (): void {
+    $user = User::factory()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertSessionHasNoErrors();
+
+    // 同一 session で続けてアカウント削除 (recent-auth 必須) を試みる
+    $this->actingAs($user)
+        ->delete('/settings/account')
+        ->assertRedirect(route('recent-auth.confirm'));
+
+    expect(User::query()->whereKey($user->getKey())->exists())->toBeTrue();
+});
+
+/*
+ * 登録経路は WebAuthn ceremony を要するため HTTP では実走できない。
+ * vendor が dispatch する PasskeyRegistered に対する **listener の契約**を固定する。
+ */
+test('passkey 登録で recent-auth 鮮度が失効する', function (): void {
+    $user = User::factory()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->startSession();
+    app(RecentAuthState::class)->confirm(method: 'password');
+    expect(session()->has('recent_auth_at'))->toBeTrue();
+
+    PasskeyRegistered::dispatch($user, $passkey);
+
+    expect(session()->has('recent_auth_at'))->toBeFalse();
+    expect(session()->has('recent_auth_method'))->toBeFalse();
+});
+
+test('PasskeyDeleted イベント単体でも鮮度が失効する', function (): void {
+    $user = User::factory()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->startSession();
+    app(RecentAuthState::class)->confirm(method: 'password');
+
+    PasskeyDeleted::dispatch($user, $passkey);
+
+    expect(session()->has('recent_auth_at'))->toBeFalse();
+});
+
+/*
+ * **裁定で見送られた強化オプションが実装されていないこと**の明示。
+ * 「登録直後の passkey は satisfier に使えない」を実装すると本テストが fail する。
+ * 再検討条件: パスキーが 2FA 準拠判定に算入される時、または放置端末起点の実被害が観測された時。
+ */
+test('登録直後の passkey でも再認証 (satisfier) は成立する', function (): void {
+    $user = User::factory()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user);
+    $this->startSession();
+    request()->setUserResolver(static fn (): User => $user);
+
+    // 登録 → 鮮度失効
+    PasskeyRegistered::dispatch($user, $passkey);
+    expect(session()->has('recent_auth_at'))->toBeFalse();
+
+    // その passkey で confirm すると鮮度が成立する (裁定どおり除外しない)
+    PasskeyVerified::dispatch($user, $passkey);
+    expect(session('recent_auth_method'))->toBe('passkey');
+});
diff --git a/tests/Feature/Auth/PasskeyRouteAccessTest.php b/tests/Feature/Auth/PasskeyRouteAccessTest.php
new file mode 100644
index 0000000..2075bd7
--- /dev/null
+++ b/tests/Feature/Auth/PasskeyRouteAccessTest.php
@@ -0,0 +1,100 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Passkey;
+use App\Models\User;
+
+/*
+ * passkey route の到達制御 (未認証 / step-up / 他人の credential / キャッシュ / throttle)。
+ *
+ * WebAuthn ceremony 自体はブラウザ API を要するため自動化しない。
+ * ここで固定するのは **ceremony に到達する前の関門**。
+ */
+
+test('未認証は passkey 登録 options に到達できない', function (): void {
+    $this->get('/user/passkeys/options')->assertRedirect('/login');
+});
+
+test('未認証は passkey 削除に到達できない', function (): void {
+    $this->delete('/user/passkeys/1')->assertRedirect('/login');
+});
+
+test('recent-auth 鮮度切れの Inertia mutation は 409 (step-up 要求)', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withHeaders(['X-Inertia' => 'true'])
+        ->post('/user/passkeys', ['name' => 'テスト'])
+        ->assertStatus(409)
+        ->assertJsonPath('code', 'recent_auth_required');
+});
+
+test('recent-auth 鮮度切れの登録 options 取得は confirm 画面へ誘導される', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->get('/user/passkeys/options')
+        ->assertRedirect(route('recent-auth.confirm'));
+});
+
+/*
+ * **他人の passkey と不在 id が同じ 404 になること** (AGENTS.md セキュリティ不変条件 2)。
+ * vendor 実装のままだと controller の `abort_unless(..., 403)` に到達し、
+ * 403 と 404 の差で他人の passkey の存在が漏れる。
+ */
+test('他人の passkey の削除は 404 (403 にしない)', function (): void {
+    $user = User::factory()->create();
+    $other = User::factory()->create();
+    $passkey = Passkey::factory()->for($other)->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertNotFound();
+
+    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();
+});
+
+test('不在 id の削除も同じ 404 (存在を漏らさない)', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/user/passkeys/999999')
+        ->assertNotFound();
+});
+
+test('非数値 id の削除は 500 ではなく 404 (pgsql 22P02 の回避)', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/user/passkeys/abc')
+        ->assertNotFound();
+});
+
+test('bigint 範囲外の id の削除も 404 (pgsql 22003 の回避)', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/user/passkeys/99999999999999999999999999')
+        ->assertNotFound();
+});
+
+test('guest の login options 応答は no-store (challenge をキャッシュさせない)', function (): void {
+    $response = $this->get('/passkeys/login/options');
+
+    $response->assertOk();
+    expect($response->headers->get('Cache-Control'))->toContain('no-store');
+});
+
+test('passkeys limiter が未認証の challenge 発行を絞る', function (): void {
+    // limiter は 10/min。11 回目で 429 になる
+    for ($i = 0; $i < 10; $i++) {
+        $this->get('/passkeys/login/options')->assertOk();
+    }
+
+    $this->get('/passkeys/login/options')->assertStatus(429);
+});
diff --git a/tests/Feature/Auth/PasskeyTwoFactorInteractionTest.php b/tests/Feature/Auth/PasskeyTwoFactorInteractionTest.php
new file mode 100644
index 0000000..54cf1de
--- /dev/null
+++ b/tests/Feature/Auth/PasskeyTwoFactorInteractionTest.php
@@ -0,0 +1,79 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Auth\LoginMethodRemoval;
+use App\Models\Passkey;
+use App\Models\User;
+use App\Services\Auth\LoginMethodInventory;
+use App\Services\Auth\PasskeyLoginPolicy;
+use Illuminate\Http\Request;
+use Laravel\Passkeys\Passkeys;
+
+/*
+ * passkey と TOTP (2FA) の関係 — **c2c 未裁定の論点に対する fail-closed 既定**。
+ *
+ * vendor の PasskeyLoginController::store() は $guard->login() を直接呼び、Fortify の
+ * two-factor challenge を通らない。したがって passkey login を許すと TOTP を迂回できる。
+ * PasskeyLoginPolicy が「TOTP confirmed なら passkey login を拒否」で fail-closed に倒す。
+ *
+ * 裁定が出たら PasskeyLoginPolicy 1 箇所を書き換えれば、login 認可 / inventory /
+ * UI prop の 3 経路が同時に反転する。本テストはその**現行既定**を固定する。
+ */
+
+function allowsPasskeyLoginFor(User $user): bool
+{
+    $passkey = Passkey::factory()->for($user)->create();
+
+    return Passkeys::allowsLogin(Request::create('/passkeys/login', 'POST'), $passkey);
+}
+
+test('TOTP confirmed ユーザーは passkey login を拒否される', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    expect(allowsPasskeyLoginFor($user))->toBeFalse();
+});
+
+test('TOTP 無効ユーザーは passkey login を許可される', function (): void {
+    $user = User::factory()->create();
+
+    expect(allowsPasskeyLoginFor($user))->toBeTrue();
+});
+
+test('TOTP pending (未 confirm) ユーザーは passkey login を許可される', function (): void {
+    $user = User::factory()->create();
+    $user->forceFill(['two_factor_secret' => encrypt('pending-secret')])->save();
+
+    expect(allowsPasskeyLoginFor($user->fresh() ?? $user))->toBeTrue();
+});
+
+/*
+ * TOTP confirmed ユーザーにとって passkey は **初めからログイン手段に数えられていない**。
+ * したがって全 passkey を消しても残存手段の集合は変わらない
+ * (= passkey しか無い TOTP ユーザーの手段はもともと空)。
+ */
+test('TOTP confirmed ユーザーの手段集合は passkey の増減に影響されない', function (): void {
+    $user = User::factory()->withTwoFactor()->create();   // password あり
+    Passkey::factory()->count(2)->for($user)->create();
+
+    $inventory = app(LoginMethodInventory::class);
+
+    expect($inventory->remainingAfter($user, LoginMethodRemoval::none())->methods)
+        ->toBe($inventory->remainingAfter($user, LoginMethodRemoval::allPasskeys())->methods);
+});
+
+/*
+ * passkey は **2FA 準拠判定に算入しない**。2FA 必須組織に属する TOTP 未設定ユーザーは、
+ * passkey を持っていても RequireTwoFactorForEnforcedOrganizations のゲートに掛かる。
+ */
+test('passkey 保有は 2FA 必須組織のゲートを免除しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->forceFill(['two_factor_required' => true])->save();
+    Passkey::factory()->for($owner)->create();
+
+    // passkey login 自体は許可される (TOTP 未設定のため)
+    expect(app(PasskeyLoginPolicy::class)->allowsPasskeyLogin($owner))->toBeTrue();
+
+    // しかし 2FA 準拠にはならないため業務画面は 2FA 設定へ誘導される
+    $this->actingAs($owner)->get('/dashboard')->assertRedirect(route('settings.security'));
+});
diff --git a/tests/Feature/Auth/RecentAuthMethodStampingTest.php b/tests/Feature/Auth/RecentAuthMethodStampingTest.php
new file mode 100644
index 0000000..a797a7d
--- /dev/null
+++ b/tests/Feature/Auth/RecentAuthMethodStampingTest.php
@@ -0,0 +1,136 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Passkey;
+use App\Models\SocialAccount;
+use App\Models\User;
+use Illuminate\Http\Request;
+use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
+use Laravel\Passkeys\Events\PasskeyVerified;
+use Laravel\Socialite\Contracts\Provider;
+use Laravel\Socialite\Contracts\User as SocialiteUserContract;
+use Laravel\Socialite\Facades\Socialite;
+use Mockery\MockInterface;
+
+/*
+ * recent-auth の satisfier ごとの最終 session state を経路別に固定する。
+ *
+ * PasskeyVerified は VerifyPasskey::__invoke() の**中**で dispatch されるため
+ * login 経路と confirm 経路の両方で発火する。最終状態は「順序」に依存するため
+ * (login では StampRecentAuthOnLogin が後勝ちで 'login' を書く)、経路ごとに固定する。
+ *
+ * **限界**: WebAuthn ceremony はブラウザ API を要するため自動化しない。
+ * passkey 経路は「vendor が dispatch する PasskeyVerified を直接発火させて
+ * **アプリ側 listener の契約**を検証する」形にとどめる (ceremony の正しさは vendor の責務)。
+ */
+
+function stampSocialiteCallback(string $providerUserId): void
+{
+    /** @var SocialiteUserContract&MockInterface $socialiteUser */
+    $socialiteUser = Mockery::mock(SocialiteUserContract::class);
+    $socialiteUser->shouldReceive('getId')->andReturn($providerUserId);
+    $socialiteUser->shouldReceive('getEmail')->andReturn('stamp@example.com');
+    $socialiteUser->shouldReceive('getName')->andReturn('Stamp User');
+
+    $driver = Mockery::mock(Provider::class);
+    $driver->shouldReceive('user')->andReturn($socialiteUser);
+    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
+}
+
+test('password 再入力の satisfier は method=password を記録する', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)
+        ->postJson('/recent-auth/password', ['password' => 'password'])
+        ->assertNoContent();
+
+    expect(session('recent_auth_method'))->toBe('password');
+});
+
+test('再SSO の satisfier は method=sso + provider を記録する', function (): void {
+    $user = User::factory()->create();
+    $account = new SocialAccount(['provider' => 'google', 'provider_user_id' => 'sso-stamp-1']);
+    $account->user()->associate($user);
+    $account->save();
+
+    stampSocialiteCallback('sso-stamp-1');
+
+    $this->actingAs($user)->get('/auth/google/redirect/step-up');
+    $this->actingAs($user)->get('/auth/google/callback');
+
+    expect(session('recent_auth_method'))->toBe('sso');
+    expect(session('recent_auth_provider'))->toBe('google');
+});
+
+test('通常ログインは method=login を記録する', function (): void {
+    $user = User::factory()->create();
+
+    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
+        ->assertRedirect();
+
+    expect(session('recent_auth_method'))->toBe('login');
+});
+
+/* ------------------------------------------------------------ passkey 経路 */
+
+test('passkey confirm 経路 (認証済み本人) は method=passkey を記録する', function (): void {
+    $user = User::factory()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->actingAs($user);
+    $this->startSession();
+    // confirm 経路では VerifyPasskey が「認証済みユーザー本人」の文脈で dispatch する
+    request()->setUserResolver(static fn (): User => $user);
+
+    PasskeyVerified::dispatch($user, $passkey);
+
+    expect(session('recent_auth_method'))->toBe('passkey');
+    expect(session('recent_auth_at'))->toBeInt();
+});
+
+test('guest 文脈 (login 経路 / deny 経路) では鮮度を stamp しない', function (): void {
+    $user = User::factory()->create();
+    $passkey = Passkey::factory()->for($user)->create();
+
+    $this->startSession();
+    request()->setUserResolver(static fn (): ?User => null);
+
+    PasskeyVerified::dispatch($user, $passkey);
+
+    // TOTP 有効ユーザーの passkey login が deny されても guest session に鮮度は残らない
+    expect(session()->has('recent_auth_at'))->toBeFalse();
+});
+
+test('他人の credential での検証は鮮度を成立させない (本人性バインド)', function (): void {
+    $user = User::factory()->create();
+    $other = User::factory()->create();
+    $passkey = Passkey::factory()->for($other)->create();
+
+    $this->actingAs($user);
+    $this->startSession();
+    request()->setUserResolver(static fn (): User => $user);
+
+    PasskeyVerified::dispatch($other, $passkey);
+
+    expect(session()->has('recent_auth_at'))->toBeFalse();
+});
+
+/*
+ * vendor の PasskeyConfirmationController::store() は `$session->passwordConfirmed()` で
+ * **Fortify の auth.password_confirmed_at を書く**。本アプリは RecentAuthState の契約で
+ * 「Fortify の鍵には書かない」としているため、Response 差し替えで確実に除去する。
+ */
+test('passkey confirm の応答は auth.password_confirmed_at を残さない', function (): void {
+    $this->startSession();
+    session()->put('auth.password_confirmed_at', time());
+
+    $request = Request::create('/passkeys/confirm', 'POST');
+    $request->setLaravelSession(session()->driver());
+
+    $response = app(PasskeyConfirmationResponseContract::class)->toResponse($request);
+
+    expect(session()->has('auth.password_confirmed_at'))->toBeFalse();
+    expect($response->getStatusCode())->toBe(204);
+    expect($response->headers->get('Cache-Control'))->toContain('no-store');
+});
diff --git a/tests/Feature/Auth/RecentAuthTest.php b/tests/Feature/Auth/RecentAuthTest.php
index 95abd6f..b532b6c 100644
--- a/tests/Feature/Auth/RecentAuthTest.php
+++ b/tests/Feature/Auth/RecentAuthTest.php
@@ -394,3 +394,22 @@ function linkGoogleAccount(User $user, string $providerUserId): void
     $response->assertSessionHasErrors('password');
     expect(session('recent_auth_at'))->toBeNull();
 });
+
+/*
+ * T106 施策 2: SSO 登録ユーザーの passwordSet が実挙動と一致する。
+ * phantom password 是正前は password 経路が使えないのに passwordSet=true になっていた
+ * (= 確認モーダルがパスワード入力欄を出して詰む)。
+ */
+test('T106: SSO 登録直後のユーザーは passwordSet=false / canSatisfy=true (再SSO が satisfier)', function (): void {
+    $this->withSession(['social_auth_intent' => 'register']);
+    fakeStepUpSocialiteCallback('g-t106-status');
+
+    $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
+
+    $user = User::whereBlind('email', 'email_index', 'step-up@example.com')->firstOrFail();
+
+    $this->actingAs($user)->getJson('/recent-auth/status')
+        ->assertOk()
+        ->assertJsonPath('passwordSet', false)
+        ->assertJsonPath('canSatisfy', true);
+});
diff --git a/tests/Feature/Auth/SocialAuthTest.php b/tests/Feature/Auth/SocialAuthTest.php
index 82015d1..efc40ee 100644
--- a/tests/Feature/Auth/SocialAuthTest.php
+++ b/tests/Feature/Auth/SocialAuthTest.php
@@ -217,3 +217,26 @@ function fakeSocialiteCallback(SocialiteUserContract $user): void
 test('無効なプロバイダは 404', function (): void {
     $this->get('/auth/unknown/redirect/login')->assertNotFound();
 });
+
+/*
+ * T106 施策 2: SSO 登録の phantom password 是正 (前方修正のみ)。
+ *
+ * 旧実装は `Str::password(32)` をハッシュ化して保存していたため、SSO-only ユーザーでも
+ * `User::hasPassword()` が常に true を返していた (recent-auth の passwordSet と
+ * EnsureLoginMethodRemains の双方が形骸化する)。
+ * **既存ユーザーへの遡及是正は行わない** (password 登録後に SSO 連携したユーザーの
+ * 実パスワード消失リスクのため。docs/template-divergence.md D13)。
+ */
+test('T106: SSO register で作られた User は password を持たない', function (): void {
+    $this->withSession(['social_auth_intent' => 'register']);
+    fakeSocialiteCallback(fakeSocialiteUser('g-t106', 'sso-t106@example.com'));
+
+    $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
+
+    $user = User::whereBlind('email', 'email_index', 'sso-t106@example.com')->firstOrFail();
+
+    expect($user->getAttribute('password'))->toBeNull();
+    expect($user->hasPassword())->toBeFalse();
+    // 施策 1 (T105) との相互作用の回帰: email_verified_at は従来どおり立つ
+    expect($user->email_verified_at)->not->toBeNull();
+});
diff --git a/tests/Feature/Settings/SecurityPagePropsTest.php b/tests/Feature/Settings/SecurityPagePropsTest.php
new file mode 100644
index 0000000..535f922
--- /dev/null
+++ b/tests/Feature/Settings/SecurityPagePropsTest.php
@@ -0,0 +1,76 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Passkey;
+use App\Models\User;
+use Inertia\Testing\AssertableInertia;
+use Laravel\Fortify\Features;
+
+/*
+ * Settings/Security の Inertia prop 契約 (passkeys 一覧 / passkeyLoginAvailable)。
+ *
+ * prop の shape は resources/js/lib/passkeys.ts の PasskeyListItem と 1:1。
+ * credential 本体 (公開鍵 / signature counter) を露出しないことも固定する。
+ */
+
+test('passkey 未登録なら passkeys は空配列', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)->get(route('settings.security'))
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->component('Settings/Security')
+            ->where('passkeys', [])
+            ->where('passkeyLoginAvailable', true));
+});
+
+test('登録済み passkey が一覧 prop に載る (credential 本体は載せない)', function (): void {
+    $user = User::factory()->create();
+    Passkey::factory()->for($user)->create(['name' => '現場用スマホ']);
+
+    $this->actingAs($user)->get(route('settings.security'))
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->component('Settings/Security')
+            ->has('passkeys', 1, fn (AssertableInertia $item) => $item
+                ->has('id')
+                ->where('name', '現場用スマホ')
+                ->where('authenticator', null)
+                ->where('lastUsedAt', null)
+                ->has('createdAt')
+                ->missing('credential')
+                ->missing('credential_id')
+                ->missing('user_id')));
+});
+
+test('TOTP 有効ユーザーは passkeyLoginAvailable が false (再認証には使える)', function (): void {
+    $user = User::factory()->withTwoFactor()->create();
+
+    $this->actingAs($user)->get(route('settings.security'))
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->where('passkeyLoginAvailable', false));
+});
+
+test('feature off では passkeyLoginAvailable が false (キルスイッチ)', function (): void {
+    $user = User::factory()->create();
+
+    config()->set(
+        'fortify.features',
+        array_values(array_filter(
+            config()->array('fortify.features'),
+            static fn (mixed $feature): bool => $feature !== Features::passkeys(),
+        )),
+    );
+
+    $this->actingAs($user)->get(route('settings.security'))
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->where('passkeyLoginAvailable', false));
+});
+
+test('他人の passkey は一覧に載らない', function (): void {
+    $user = User::factory()->create();
+    $other = User::factory()->create();
+    Passkey::factory()->for($other)->create();
+
+    $this->actingAs($user)->get(route('settings.security'))
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('passkeys', []));
+});
diff --git a/tests/js/architecture/passkeys-import-isolation.test.ts b/tests/js/architecture/passkeys-import-isolation.test.ts
new file mode 100644
index 0000000..e907af6
--- /dev/null
+++ b/tests/js/architecture/passkeys-import-isolation.test.ts
@@ -0,0 +1,71 @@
+import { describe, expect, it } from "vitest";
+import fs from "node:fs/promises";
+import path from "node:path";
+import { fileURLToPath } from "node:url";
+
+/*
+ * `@/lib/passkeys` の import 元を allowlist で固定する (deny-by-default)。
+ *
+ * 理由: WebAuthn ceremony は「options 取得 → 認証器操作 → 送信」の 3 段で、
+ * 送信先とレスポンス契約 (Inertia か fetch か / 302 か 204 か) が operation ごとに違う
+ * (詳細設計 施策 4-d の transport 契約)。呼び出し元が無秩序に増えると
+ * 契約の食い違いが**無言失敗**として現れる (router が応答を解釈できない)。
+ *
+ * 増やすときは transport 契約の該当行と併せて判断すること。
+ */
+
+const HERE = path.dirname(fileURLToPath(import.meta.url));
+const RESOURCES_JS = path.resolve(HERE, "../../../resources/js");
+
+/** `@/lib/passkeys` を import してよいファイル (resources/js からの相対パス) */
+const ALLOWED_IMPORTERS: ReadonlySet<string> = new Set([
+    // パスキーの登録 / 削除 (Inertia transport)
+    "components/features/auth/PasskeySection.svelte",
+    // step-up 再認証 (fetch + 204 transport)
+    "components/organisms/RecentAuthModal.svelte",
+    // guest のパスキーログイン (fetch + {redirect} transport)
+    "pages/Auth/Login.svelte",
+    // passkeys prop の型 (PasskeyListItem) を PasskeySection へ渡す page
+    "pages/Settings/Security.svelte",
+]);
+
+const TARGET_EXTENSIONS: ReadonlySet<string> = new Set([".ts", ".svelte"]);
+
+const listFiles = async (dir: string): Promise<string[]> => {
+    const entries = await fs.readdir(dir, { recursive: true, withFileTypes: true });
+    const files: string[] = [];
+    for (const entry of entries) {
+        if (!entry.isFile()) continue;
+        if (!TARGET_EXTENSIONS.has(path.extname(entry.name))) continue;
+        const parent = (entry as unknown as { parentPath?: string }).parentPath ?? dir;
+        files.push(path.join(parent, entry.name));
+    }
+    return files;
+};
+
+const IMPORT_PATTERN = /from\s+["'](@\/lib\/passkeys)["']|import\s+["'](@\/lib\/passkeys)["']/;
+
+describe("passkeys import isolation", () => {
+    it("@/lib/passkeys の import 元は allowlist のみ", async () => {
+        const files = await listFiles(RESOURCES_JS);
+        const importers: string[] = [];
+
+        for (const file of files) {
+            const relative = path.relative(RESOURCES_JS, file).split(path.sep).join("/");
+            if (relative === "lib/passkeys.ts") continue;
+            const content = await fs.readFile(file, "utf-8");
+            if (IMPORT_PATTERN.test(content)) {
+                importers.push(relative);
+            }
+        }
+
+        const unexpected = importers.filter((file) => !ALLOWED_IMPORTERS.has(file));
+        expect(unexpected).toEqual([]);
+
+        // 走査が空振りしていない (allowlist の全員が実際に import している)
+        expect(importers.length).toBeGreaterThan(0);
+        for (const allowed of ALLOWED_IMPORTERS) {
+            expect(importers).toContain(allowed);
+        }
+    });
+});
diff --git a/tests/js/lib/passkeys.test.ts b/tests/js/lib/passkeys.test.ts
new file mode 100644
index 0000000..21a5c7b
--- /dev/null
+++ b/tests/js/lib/passkeys.test.ts
@@ -0,0 +1,250 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import {
+    base64UrlToBuffer,
+    bufferToBase64Url,
+    canCreatePasskey,
+    confirmWithPasskey,
+    createPasskeyCredential,
+    isPasskeySupported,
+    loginWithPasskey,
+} from "@/lib/passkeys";
+
+/*
+ * WebAuthn ラッパの分岐契約。
+ *
+ * **限界**: 実 ceremony は jsdom でエミュレートできない (仮想認証器が要る)。
+ * ここで固定するのは
+ *   - feature detection (非対応端末で throw しない / unsupported を返す)
+ *   - キャンセル (NotAllowedError) を "cancelled" に畳むこと
+ *   - base64url 変換の往復
+ *   - fetch のヘッダ契約 (Accept: application/json が無いと
+ *     PasskeyLoginResponse の JSON 分岐に入らない)
+ * 実 ceremony の確認は docs/supported-browsers.md の実機受入確認に委ねる。
+ */
+
+const originalNavigator = globalThis.navigator;
+
+interface CredentialsStub {
+    create: ReturnType<typeof vi.fn>;
+    get: ReturnType<typeof vi.fn>;
+}
+
+function stubWebAuthnApis(credentials: Partial<CredentialsStub> = {}): CredentialsStub {
+    const stub: CredentialsStub = {
+        create: vi.fn(),
+        get: vi.fn(),
+        ...credentials,
+    } as CredentialsStub;
+
+    Object.defineProperty(globalThis, "navigator", {
+        configurable: true,
+        value: { credentials: stub },
+    });
+
+    const publicKeyCredential = function PublicKeyCredentialStub() {
+        // 実体は使わない (instanceof 判定にのみ使う)
+    } as unknown as typeof PublicKeyCredential;
+    (
+        publicKeyCredential as unknown as {
+            isUserVerifyingPlatformAuthenticatorAvailable: () => Promise<boolean>;
+        }
+    ).isUserVerifyingPlatformAuthenticatorAvailable = () => Promise.resolve(true);
+
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: publicKeyCredential,
+    });
+
+    return stub;
+}
+
+function removeWebAuthnApis(): void {
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: undefined,
+    });
+}
+
+afterEach(() => {
+    vi.restoreAllMocks();
+    Object.defineProperty(globalThis, "navigator", {
+        configurable: true,
+        value: originalNavigator,
+    });
+    removeWebAuthnApis();
+});
+
+describe("feature detection", () => {
+    it("PublicKeyCredential 不在では未対応と判定する", () => {
+        removeWebAuthnApis();
+        expect(isPasskeySupported()).toBe(false);
+    });
+
+    it("PublicKeyCredential があれば対応と判定する", () => {
+        stubWebAuthnApis();
+        expect(isPasskeySupported()).toBe(true);
+    });
+
+    it("未対応端末では canCreatePasskey が false (throw しない)", async () => {
+        removeWebAuthnApis();
+        await expect(canCreatePasskey()).resolves.toBe(false);
+    });
+
+    it("isUserVerifyingPlatformAuthenticatorAvailable の reject を false に畳む", async () => {
+        stubWebAuthnApis();
+        (
+            window.PublicKeyCredential as unknown as {
+                isUserVerifyingPlatformAuthenticatorAvailable: () => Promise<boolean>;
+            }
+        ).isUserVerifyingPlatformAuthenticatorAvailable = () => Promise.reject(new Error("nope"));
+
+        await expect(canCreatePasskey()).resolves.toBe(false);
+    });
+
+    it("未対応端末では ceremony が unsupported を返す (例外にしない)", async () => {
+        removeWebAuthnApis();
+        await expect(createPasskeyCredential()).resolves.toEqual({ status: "unsupported" });
+        await expect(loginWithPasskey()).resolves.toEqual({ status: "unsupported" });
+        await expect(confirmWithPasskey()).resolves.toEqual({ status: "unsupported" });
+    });
+});
+
+describe("base64url", () => {
+    it("往復して元の文字列に戻る", () => {
+        const samples = ["AQIDBA", "-_-_", "aGVsbG8", "AA"];
+        for (const sample of samples) {
+            expect(bufferToBase64Url(base64UrlToBuffer(sample))).toBe(sample);
+        }
+    });
+
+    it("padding / + / を含まない", () => {
+        const bytes = new Uint8Array([251, 255, 190, 239]);
+        const encoded = bufferToBase64Url(bytes.buffer);
+        expect(encoded).not.toContain("=");
+        expect(encoded).not.toContain("+");
+        expect(encoded).not.toContain("/");
+    });
+});
+
+describe("ceremony の分岐", () => {
+    let fetchMock: ReturnType<typeof vi.fn>;
+
+    beforeEach(() => {
+        fetchMock = vi.fn();
+        vi.stubGlobal("fetch", fetchMock);
+    });
+
+    function optionsResponse(options: Record<string, unknown>): unknown {
+        return { ok: true, status: 200, json: () => Promise.resolve({ options }) };
+    }
+
+    const loginOptions = {
+        challenge: "AQIDBA",
+        rpId: "localhost",
+        allowCredentials: [{ id: "AQIDBA", type: "public-key" }],
+        userVerification: "required",
+        timeout: 60000,
+    };
+
+    it("ユーザーキャンセル (NotAllowedError) を cancelled に畳む", async () => {
+        const credentials = stubWebAuthnApis();
+        fetchMock.mockResolvedValue(optionsResponse(loginOptions));
+        const cancelled = new Error("cancelled");
+        cancelled.name = "NotAllowedError";
+        credentials.get.mockRejectedValue(cancelled);
+
+        await expect(loginWithPasskey()).resolves.toEqual({ status: "cancelled" });
+    });
+
+    it("options 取得失敗は failed (メッセージ付き)", async () => {
+        stubWebAuthnApis();
+        fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });
+
+        const outcome = await loginWithPasskey();
+        expect(outcome.status).toBe("failed");
+    });
+
+    it("options 取得は Accept: application/json を送る", async () => {
+        stubWebAuthnApis();
+        fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });
+
+        await loginWithPasskey();
+
+        expect(fetchMock).toHaveBeenCalledWith(
+            "/passkeys/login/options",
+            expect.objectContaining({
+                method: "GET",
+                credentials: "same-origin",
+                headers: expect.objectContaining({ Accept: "application/json" }),
+            }),
+        );
+    });
+
+    it("登録 ceremony は options endpoint を叩き、送信までは行わない", async () => {
+        const credentials = stubWebAuthnApis();
+        fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });
+
+        await createPasskeyCredential();
+
+        expect(fetchMock).toHaveBeenCalledTimes(1);
+        expect(fetchMock.mock.calls[0][0]).toBe("/user/passkeys/options");
+        expect(credentials.create).not.toHaveBeenCalled();
+    });
+
+    it("confirm は POST に CSRF / Content-Type ヘッダを付ける", async () => {
+        const credentials = stubWebAuthnApis();
+        document.cookie = "XSRF-TOKEN=test-token";
+        fetchMock.mockImplementation((url: string) =>
+            url.endsWith("/options")
+                ? Promise.resolve(optionsResponse(loginOptions))
+                : Promise.resolve({ ok: true, status: 204, json: () => Promise.resolve({}) }),
+        );
+
+        // navigator.credentials.get が PublicKeyCredential インスタンスを返すよう偽装する
+        const credential = Object.create(
+            (window.PublicKeyCredential as unknown as { prototype: object }).prototype,
+        ) as Record<string, unknown>;
+        credential.id = "cred-id";
+        credential.rawId = new Uint8Array([1, 2, 3, 4]).buffer;
+        credential.type = "public-key";
+        credential.response = {};
+        credentials.get.mockResolvedValue(credential);
+
+        const outcome = await confirmWithPasskey();
+
+        expect(outcome.status).toBe("ok");
+        const postCall = fetchMock.mock.calls.find(([url]) => url === "/passkeys/confirm");
+        expect(postCall).toBeDefined();
+        expect(postCall?.[1]).toMatchObject({
+            method: "POST",
+            headers: expect.objectContaining({
+                Accept: "application/json",
+                "Content-Type": "application/json",
+                "X-XSRF-TOKEN": "test-token",
+            }),
+        });
+    });
+
+    it("login は redirect を含まない応答を failed に畳む (非 JSON / 想定外 shape の拒否)", async () => {
+        const credentials = stubWebAuthnApis();
+        fetchMock.mockImplementation((url: string) =>
+            url.endsWith("/options")
+                ? Promise.resolve(optionsResponse(loginOptions))
+                : Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) }),
+        );
+
+        const credential = Object.create(
+            (window.PublicKeyCredential as unknown as { prototype: object }).prototype,
+        ) as Record<string, unknown>;
+        credential.id = "cred-id";
+        credential.rawId = new Uint8Array([1, 2, 3, 4]).buffer;
+        credential.type = "public-key";
+        credential.response = {};
+        credentials.get.mockResolvedValue(credential);
+
+        const outcome = await loginWithPasskey();
+        expect(outcome.status).toBe("failed");
+    });
+});
diff --git a/tests/js/pages/LoginPasskey.test.ts b/tests/js/pages/LoginPasskey.test.ts
new file mode 100644
index 0000000..31894f7
--- /dev/null
+++ b/tests/js/pages/LoginPasskey.test.ts
@@ -0,0 +1,86 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+import Login from "@/pages/Auth/Login.svelte";
+
+/*
+ * ログイン画面のパスキー導線 (T106 施策 6)。
+ * - 非対応ブラウザではボタン自体を出さない (押しても何もできない導線を出さない)
+ * - 失敗時もパスワード欄と SSO ボタンを残す (回復導線を消さない)
+ */
+
+const fetchMock = vi.fn();
+
+function stubPasskeySupport(): void {
+    Object.defineProperty(globalThis, "navigator", {
+        configurable: true,
+        value: { credentials: { create: vi.fn(), get: vi.fn() } },
+    });
+    const publicKeyCredential = function PublicKeyCredentialStub() {
+        // instanceof 判定にのみ使う
+    } as unknown as typeof PublicKeyCredential;
+    (
+        publicKeyCredential as unknown as {
+            isUserVerifyingPlatformAuthenticatorAvailable: () => Promise<boolean>;
+        }
+    ).isUserVerifyingPlatformAuthenticatorAvailable = () => Promise.resolve(true);
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: publicKeyCredential,
+    });
+}
+
+function removePasskeySupport(): void {
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: undefined,
+    });
+}
+
+beforeEach(() => {
+    vi.stubGlobal("fetch", fetchMock);
+});
+
+afterEach(() => {
+    cleanup();
+    vi.unstubAllGlobals();
+    removePasskeySupport();
+    fetchMock.mockReset();
+});
+
+describe("Auth/Login パスキー導線", () => {
+    it("非対応ブラウザではパスキーボタンを出さない", () => {
+        removePasskeySupport();
+        render(Login, { props: { appName: "My App", socialProviders: [] } });
+
+        expect(screen.queryByTestId("passkey-login-button")).toBeNull();
+    });
+
+    it("対応ブラウザではボタンと 2FA の但し書きを出す", () => {
+        stubPasskeySupport();
+        render(Login, { props: { appName: "My App", socialProviders: [] } });
+
+        const button = screen.getByTestId("passkey-login-button");
+        expect(button).toBeInTheDocument();
+        expect(button).not.toBeDisabled();
+        expect(
+            screen.getByText(/2要素認証を有効にしているアカウントでは、パスキーでログインできません。/),
+        ).toBeInTheDocument();
+    });
+
+    it("失敗してもパスワード欄と SSO ボタンを残す (回復導線を消さない)", async () => {
+        stubPasskeySupport();
+        fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });
+
+        render(Login, { props: { appName: "My App", socialProviders: ["google"] } });
+
+        await fireEvent.click(screen.getByTestId("passkey-login-button"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("passkey-login-error")).toBeInTheDocument();
+        });
+        expect(screen.getByLabelText("パスワード")).toBeInTheDocument();
+        expect(screen.getByTestId("sso-login-google")).toBeInTheDocument();
+    });
+});
diff --git a/tests/js/pages/SettingsSecurityPasskey.test.ts b/tests/js/pages/SettingsSecurityPasskey.test.ts
new file mode 100644
index 0000000..39b83b5
--- /dev/null
+++ b/tests/js/pages/SettingsSecurityPasskey.test.ts
@@ -0,0 +1,241 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+import Security from "@/pages/Settings/Security.svelte";
+
+/*
+ * セキュリティ設定のパスキーカード (T106 施策 6)。
+ * - 非対応 / 作成不可の端末に理由を出す (ボタンは disabled にしない = AGENTS.md 禁止事項 8)
+ * - 2FA 有効時は「ログインには使えないが再認証には使える」を明示する (誤認防止)
+ * - 登録 / 削除は recent-auth precheck を通す (stale なら再認証モーダル)
+ * - EnsureLoginMethodRemains の拒否 (errors.login_method) を画面に出す (無言失敗にしない)
+ */
+
+const { routerPostMock, routerDeleteMock, pageState, addToastMock } = vi.hoisted(() => ({
+    routerPostMock: vi.fn(),
+    routerDeleteMock: vi.fn(),
+    pageState: {
+        props: {} as Record<string, unknown>,
+        url: "/settings/security",
+    },
+    addToastMock: vi.fn(),
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { post: routerPostMock, delete: routerDeleteMock },
+    page: pageState,
+}));
+
+vi.mock("@/lib/stores/toast", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@/lib/stores/toast")>()),
+    addToast: addToastMock,
+}));
+
+const fetchMock = vi.fn();
+
+function setPageProps(options: { twoFactor?: boolean; errors?: Record<string, string> } = {}): void {
+    pageState.props = {
+        appName: "AI-CUE",
+        auth: { user: { id: 1, name: "テスト太郎", twoFactorEnabled: options.twoFactor ?? false } },
+        errors: options.errors ?? {},
+    };
+}
+
+/** WebAuthn 対応端末を偽装する */
+function stubPasskeySupport(creatable = true): void {
+    Object.defineProperty(globalThis, "navigator", {
+        configurable: true,
+        value: { credentials: { create: vi.fn(), get: vi.fn() } },
+    });
+    const publicKeyCredential = function PublicKeyCredentialStub() {
+        // instanceof 判定にのみ使う
+    } as unknown as typeof PublicKeyCredential;
+    (
+        publicKeyCredential as unknown as {
+            isUserVerifyingPlatformAuthenticatorAvailable: () => Promise<boolean>;
+        }
+    ).isUserVerifyingPlatformAuthenticatorAvailable = () => Promise.resolve(creatable);
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: publicKeyCredential,
+    });
+}
+
+function removePasskeySupport(): void {
+    Object.defineProperty(window, "PublicKeyCredential", {
+        configurable: true,
+        writable: true,
+        value: undefined,
+    });
+}
+
+function jsonResponse(ok: boolean, status: number, body: unknown): unknown {
+    return { ok, status, json: () => Promise.resolve(body) };
+}
+
+function stubRecentAuth(recent: boolean): void {
+    fetchMock.mockImplementation((input: RequestInfo | URL) => {
+        const url = String(input);
+        if (url.includes("/recent-auth/status")) {
+            return Promise.resolve(
+                jsonResponse(true, 200, {
+                    recent,
+                    passwordSet: true,
+                    availableProviders: [],
+                    canSatisfy: true,
+                    confirmedAt: recent ? 1 : null,
+                }),
+            );
+        }
+        return Promise.resolve(jsonResponse(false, 500, {}));
+    });
+}
+
+const passkeys = [
+    {
+        id: 7,
+        name: "現場用スマホ",
+        authenticator: "iCloud Keychain",
+        lastUsedAt: "2026-08-01T00:00:00+09:00",
+        createdAt: "2026-07-01T00:00:00+09:00",
+    },
+];
+
+beforeEach(() => {
+    setPageProps();
+    stubPasskeySupport();
+    vi.stubGlobal("fetch", fetchMock);
+});
+
+afterEach(() => {
+    cleanup();
+    vi.unstubAllGlobals();
+    removePasskeySupport();
+    routerPostMock.mockReset();
+    routerDeleteMock.mockReset();
+    addToastMock.mockReset();
+    fetchMock.mockReset();
+});
+
+describe("Settings/Security パスキーカード", () => {
+    it("非対応ブラウザでは理由を出すが登録ボタンは disabled にしない", () => {
+        removePasskeySupport();
+        render(Security, { props: {} });
+
+        expect(screen.getByTestId("passkey-unsupported")).toBeInTheDocument();
+        expect(screen.getByTestId("register-passkey-button")).not.toBeDisabled();
+    });
+
+    it("非対応ブラウザで登録を押すと理由をトーストで出す (無言失敗にしない)", async () => {
+        removePasskeySupport();
+        render(Security, { props: {} });
+
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        expect(addToastMock).toHaveBeenCalledWith("error", expect.stringContaining("対応していません"));
+        expect(routerPostMock).not.toHaveBeenCalled();
+    });
+
+    it("プラットフォーム認証器が使えない端末には作成不可の理由を出す", async () => {
+        stubPasskeySupport(false);
+        render(Security, { props: {} });
+
+        await waitFor(() => {
+            expect(screen.getByTestId("passkey-not-creatable")).toBeInTheDocument();
+        });
+    });
+
+    it("2FA 有効時は「ログイン不可・再認証は可」を明示する", () => {
+        setPageProps({ twoFactor: true });
+        render(Security, { props: { passkeyLoginAvailable: false } });
+
+        expect(screen.getByTestId("passkey-2fa-notice")).toBeInTheDocument();
+    });
+
+    it("2FA 無効かつ passkeyLoginAvailable なら 2FA 注意書きを出さない", () => {
+        render(Security, { props: { passkeyLoginAvailable: true } });
+
+        expect(screen.queryByTestId("passkey-2fa-notice")).toBeNull();
+    });
+
+    it("登録済みパスキーを一覧表示する", () => {
+        render(Security, { props: { passkeys } });
+
+        expect(screen.getByTestId("passkey-list")).toBeInTheDocument();
+        expect(screen.getByText("現場用スマホ")).toBeInTheDocument();
+        expect(screen.getByTestId("passkey-count")).toHaveTextContent("1 件登録済み");
+    });
+
+    it("名前未入力の登録はエラー表示のみで ceremony を開始しない", async () => {
+        render(Security, { props: {} });
+
+        await fireEvent.click(screen.getByTestId("register-passkey-button"));
+
+        expect(screen.getByText("パスキーの名前を入力してください。")).toBeInTheDocument();
+        expect(fetchMock).not.toHaveBeenCalled();
+    });
+
+    it("削除は確認ダイアログを挟み、確認までは DELETE しない", async () => {
+        stubRecentAuth(true);
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+
+        // 一覧側にも同名が出るためダイアログ本体で照合する
+        expect(screen.getByTestId("delete-passkey-dialog")).toHaveTextContent("現場用スマホ");
+        expect(routerDeleteMock).not.toHaveBeenCalled();
+    });
+
+    it("確認後は recent-auth precheck を通して DELETE する", async () => {
+        stubRecentAuth(true);
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        await waitFor(() => {
+            expect(routerDeleteMock).toHaveBeenCalledWith(
+                "/user/passkeys/7",
+                expect.objectContaining({ preserveScroll: true }),
+            );
+        });
+        expect(fetchMock).toHaveBeenCalledWith("/recent-auth/status", expect.anything());
+    });
+
+    it("recent-auth が stale なら再認証モーダルを開き DELETE しない", async () => {
+        stubRecentAuth(false);
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
+        });
+        expect(routerDeleteMock).not.toHaveBeenCalled();
+    });
+
+    it("ログイン手段保持 guard の拒否メッセージを画面に出す (無言失敗にしない)", () => {
+        setPageProps({ errors: { login_method: "この操作を行うと、ログインする手段がなくなります。" } });
+        render(Security, { props: { passkeys } });
+
+        const alert = screen.getByTestId("passkey-login-method-error");
+        expect(alert).toBeInTheDocument();
+        expect(alert).toHaveTextContent("ログインする手段がなくなります");
+        // 回復導線 (別のログイン手段を追加する) を同画面に出す
+        expect(screen.getByTestId("passkey-add-password")).toBeInTheDocument();
+    });
+
+    it("登録済みパスキーがあれば再認証モーダルにパスキー導線が出る", async () => {
+        stubRecentAuth(false);
+        render(Security, { props: { passkeys } });
+
+        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("recent-auth-passkey")).toBeInTheDocument();
+        });
+    });
+});

```

---

## テスト結果

- `composer test`: **2799 tests / 2797 passed / 0 failed / 2 skipped** (11240 assertions)
- `composer phpstan` (level 10): **No errors**
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build`: passed
- `pnpm test` (vitest): **114 files / 1035 tests passed**
- `pnpm typecheck:packages` / `pnpm test:packages`: passed

## 実装者からの申し送り (設計からの意図的な逸脱)

1. **`User::passkeys()` の narrowing override を置かなかった**。設計は
   `@return HasMany<App\Models\Passkey, $this>` の override を指示していたが、
   `Laravel\Passkeys\Contracts\PasskeyUser` が `HasMany<Laravel\Passkeys\Passkey, Model>` を宣言しており、
   Laravel の `HasMany` は型引数が **不変** (covariant 宣言が無い) ため PHPStan level 10 で
   `method.childReturnType` になる (実測)。代わりに App 型が必要な 2 箇所
   (`SecurityController` / `SelfScopedPasskeyBinder`) で `Passkey` モデルを user_id スコープで直接クエリした。
2. **binder を closure ではなく class binding (`SelfScopedPasskeyBinder`) にした**。
   既存 Architecture gate `RouteBindingTypeConstraintInventoryTest` の IV-5 が
   「CUSTOM_BINDER 分類の binder は `NormalizesRouteBindingInput` を実装したクラスであること」を
   要求しているため (`{passkey}` param を 5 分類のいずれかに登録する必要がある)。
3. **`no-store` alias 用に `NoStoreResponse` middleware を新設した**。設計は
   `passkey.login-options` に `no-store` を後付けすると書いていたが、その alias が存在しなかった。
4. **`RecentAuthModal` に passkey 再認証導線を足した** (設計の変更箇所リストには無いが、
   `passkeys-import-isolation` の allowlist には RecentAuthModal が含まれていた)。
   これが無いと `passkey.confirm` / `PasskeyConfirmationResponse` / `StampRecentAuthOnPasskeyVerified` の
   confirm 経路が UI から到達不能になる。呼び出し側が `passkeyAvailable` prop を渡す形にして
   recent-auth の backend 契約 (`RecentAuthStatusDto`) は変更していない。
5. **passkey カードは `components/features/auth/PasskeySection.svelte` へ切り出した**
   (`Settings/Security.svelte` の肥大回避。設計のリスク節が示唆していた選択肢)。
   recent-auth モーダルはページに 1 つだけ置き、`guard` 関数を prop で渡す。
6. **Inertia への拒否応答テストは `assertSessionHasErrors` を使わず、
   後続の Inertia 訪問で `$page.props.errors.login_method` を検証する形にした**
   (X-Inertia 経由だとテスト時に session errors が json serialization 経由で配列化し
   `assertSessionHasErrors` が使えないため。かつこちらの方が Svelte 側の表示契約そのものを固定する)。

## 特に見てほしい点

- `EnsureLoginMethodRemains` の transaction 内 `$next()` 実行が、実際に安全か
  (同期 listener `ClearRecentAuthOnPasskeyChange` が transaction 内で session を触る点を含む)
- `SelfScopedPasskeyBinder` が「他人の passkey」「不在 id」「非数値」「bigint 範囲外」を
  すべて同じ 404 に倒せているか
- `PasskeyLoginPolicy` に判定を集約したことで、vendor の login ゲート / inventory / UI prop の
  3 経路が本当に同時に反転するか
- `StampRecentAuthOnPasskeyVerified` の本人性バインドが、deny 経路 (TOTP ユーザーの passkey login) で
  guest session に鮮度を残さないことを保証できているか
- Architecture テストが「空振りで green」にならない設計になっているか
