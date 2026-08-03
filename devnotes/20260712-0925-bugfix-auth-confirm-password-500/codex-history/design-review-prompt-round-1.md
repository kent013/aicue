# システム指示

## アプリの使命（North Star）— AGENTS.md より

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

## 禁止事項 — AGENTS.md より

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

## 役割

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: 本件はフロント変更なし
11. Atomic Design準拠（UI/frontend 変更を含む場合）: 本件はフロント変更なし

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: bugfix-auth-confirm-password-500 (F-11)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- 新モデルを追加する設計では **対応するFactoryの作成も施策に含める** こと
- **DTO + JsonResource** パターン（AGENTS.md参照）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

[devnotes/20260712-0925-bugfix-auth-confirm-password-500/conceptual-design.md](./conceptual-design.md)
（Codex 概念レビュー Round 1 で APPROVED。Warning 3 件対応済み）

## 根本原因（確定・再現済み）

- Fortify は `config('fortify.views') === true` のとき、feature フラグ
  (`twoFactorAuthentication.confirmPassword => false`) に関係なく
  `GET /user/confirm-password`（`password.confirm`）を無条件登録する
  （`vendor/laravel/fortify/routes/routes.php` L118-121）。
- `ConfirmablePasswordController::show()` は `app(ConfirmPasswordViewResponse::class)` を解決するが、
  この contract は `Fortify::confirmPasswordView()` を呼んだときにのみ bind され、
  Fortify の `registerResponseBindings()` に default binding は存在しない。
- 本アプリは step-up を generic recent-auth（`/recent-auth/confirm`）へ統一した際に
  `confirmPasswordView` を意図的に未登録とした（`FortifyServiceProvider.php` L107-108 のコメント）。
- 結果、直アクセスで
  `BindingResolutionException: Target [Laravel\Fortify\Contracts\ConfirmPasswordViewResponse] is not instantiable`
  → 500。tinker で contract 解決を実行し例外を確認済み（セッション状態に依存しない決定的クラッシュ。
  shard-report の「intended URL 未設定」仮説は誤り）。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 再現 Feature テスト追加（テストファースト） | `tests/Feature/Auth/RecentAuthTest.php` | High |
| 2 | `confirmPasswordView` に recent-auth への救済 redirect を登録 | `app/Providers/FortifyServiceProvider.php` | High |

## 施策1: 再現 Feature テスト追加（テストファースト）

### 変更箇所
- ファイル: `tests/Feature/Auth/RecentAuthTest.php`（`/* ---- middleware */` セクション末尾、
  L98 付近の confirm 画面テスト群の直前に「fortify password.confirm 互換」ブロックとして追加）

### 波及変更
- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: 本施策自体がテスト追加。既存テストの変更なし

### 追加テストコード

```php
/* ------------------------------------------- fortify password.confirm 救済 redirect */

test('GET /user/confirm-password 直アクセスは recent-auth confirm へ 302 (500 にしない)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/user/confirm-password');

    $response->assertRedirect(route('recent-auth.confirm'));
});

test('GET /user/confirm-password は追従すると 200 で ConfirmRecentAuth フォームが出る', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->followingRedirects()->get('/user/confirm-password');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/ConfirmRecentAuth')
            ->where('passwordSet', true)
            ->where('canSatisfy', true));
});

test('GET /user/confirm-password は未ログインなら login へ redirect (既存 auth ガード)', function (): void {
    $this->get('/user/confirm-password')->assertRedirect(route('login'));
});
```

- 実装前に 1・2 本目が **500 で fail** することを確認してから施策2に着手する（fail-first）。
- 3 本目は Fortify の `auth` middleware による既存挙動の固定（回帰ガード）で、実装前から green の想定。
- SSO-only ユーザーの詰み回避（`availableProviders` 提示）は既存テスト
  「confirm 画面は passwordSet / availableProviders / canSatisfy を返す」（同ファイル L100）が
  誘導先画面を保証済みのため、本施策では redirect 先の同一性のみ検証し重複させない。

### PHPStan適合チェック
- [x] テストは既存 Pest スタイル（closure + `$this->actingAs()`）に従う
- [x] Factory 使用（`User::factory()->create()`）、`Model::create()` 手組みなし
- [x] 個別 `DatabaseTransactions` 不使用（Pest.php のグローバル RefreshDatabase）

### テスト計画
- [x] バグ修正の再現テストを先に書く（上記 1・2 本目。500 fail を確認）
- [x] 既存テスト `tests/Feature/Auth/RecentAuthTest.php` への追記（削除・上書きなし）
- [x] 新規テスト3本 — 直アクセス302 / 追従200フォーム表示 / 未ログインガード

### リスク
- なし（テスト追加のみ）

## 施策2: `confirmPasswordView` に recent-auth への救済 redirect を登録

### 変更箇所
- ファイル: `app/Providers/FortifyServiceProvider.php`
  - use 節（L16 付近）: `Illuminate\Http\RedirectResponse` を追加
  - `configureViews()` L107-109: 「confirmPasswordView は登録しない」コメントを
    redirect 登録 + 意図説明コメントに置換

### 波及変更
- TypeScript型定義: なし（新規ページ・props なし。誘導先 `Auth/ConfirmRecentAuth` は既存のまま）
- API Resource/DTO: なし
- テストファイル: 施策1で追加（本施策とセット）
- ルート定義: 変更なし（Fortify 登録ルートをそのまま使い、view response のみ差し替え）

### 現行コード

```php
// app/Providers/FortifyServiceProvider.php L107-110
        // password.confirm (Fortify 生 step-up) は generic recent-auth に置換済みのため
        // confirmPasswordView は登録しない (確認画面は Auth/ConfirmRecentAuth)。

        Fortify::twoFactorChallengeView(static fn (): InertiaResponse => Inertia::render('Auth/TwoFactorChallenge'));
```

### 変更後コード

```php
// use 節に追加
use Illuminate\Http\RedirectResponse;
```

```php
// app/Providers/FortifyServiceProvider.php configureViews() 内
        // password.confirm (Fortify 生 step-up) は generic recent-auth に置換済み。
        // ただし fortify.views=true の間は GET /user/confirm-password が Fortify により
        // 無条件登録され、ConfirmPasswordViewResponse 未 bind だと直アクセスが
        // BindingResolutionException で 500 になる (bug-hunt F-11)。正規の確認画面
        // (recent-auth.confirm、password or 再SSO) へ 302 で誘導する。
        // 注意: これは GET view の救済 redirect であり、`password.confirm` middleware 互換
        // (auth.password_confirmed_at の充足) は提供しない。middleware 互換が必要になったら
        // 別途設計すること (config/fortify.php の TODO(template) 参照)。
        Fortify::confirmPasswordView(
            static fn (): RedirectResponse => redirect()->route('recent-auth.confirm'),
        );

        Fortify::twoFactorChallengeView(static fn (): InertiaResponse => Inertia::render('Auth/TwoFactorChallenge'));
```

### 設計メモ（機構の確認結果）

- `Fortify::confirmPasswordView($view)` は `ConfirmPasswordViewResponse` contract を
  `SimpleViewResponse($view)` として singleton bind する（`vendor/laravel/fortify/src/Fortify.php` L198）。
- `SimpleViewResponse::toResponse()` は `$view` が callable の場合それを呼び、戻り値が
  `Responsable` でなければそのまま返す。`RedirectResponse` は Symfony Response 派生のため
  そのまま HTTP 302 として返る（vendor 実装確認済み）。
- closure は **リクエスト時に評価**されるため、`redirect()->route('recent-auth.confirm')` の
  route 解決が boot 順序に依存する問題はない。
- 誘導先 `recent-auth.confirm`（`ConfirmRecentAuthController::show()`）は `auth` +
  `EnsureEmailIsVerified` 等の既存 middleware 構成のもとで Inertia ページ
  `Auth/ConfirmRecentAuth` を 200 で返す（既存テストで保証済み）。
  直アクセス時は `url.intended` 未設定のため、再認証完了後は既存契約どおり
  `redirect()->intended(route('dashboard'))` で dashboard へ遷移する（新規実装不要）。
- 禁止事項7（操作系 POST での `redirect()->intended()`）は本件に非該当:
  追加するのは GET view response の 302 であり intended を消費しない。

### PHPStan適合チェック
- [x] closure の戻り値型 `RedirectResponse` を明示（level 10 で closure 型推論が閉じる）
- [x] null 安全: 引数なし・セッション非依存のため該当分岐なし
- [x] DTO 返却の論点なし（HTML 302 redirect。JSON 応答なし）
- [x] Generics の型パラメータ: 該当なし

### テスト計画
- [x] 施策1のテスト3本が green になること（fail→green の遷移を確認）
- [x] 既存の `tests/Feature/Auth/RecentAuthTest.php` 全テスト green（recent-auth 本体の回帰なし）
- [x] 既存の `tests/Feature/Auth/FortifyResponseTest.php` green（他 Fortify response 差し替えの回帰なし）
- [x] `composer test`（並列全件）/ `composer phpstan` / `vendor/bin/pint --test` green
- [x] フロント変更なしのため `pnpm test` / `pnpm typecheck` は既存 green の維持確認のみ

### リスク
- **`password.confirm` middleware への影響**: 本アプリのルート・middleware alias に
  `password.confirm` の利用箇所はない（grep 確認済み。passkeys feature も未有効のため
  `passkeys.management_middleware` 経由の利用もなし）。将来利用時の誤認はコード内コメントで防止。
- **`POST /user/confirm-password`（`password.confirm.store`）**: 本修正の対象外。既存どおり
  password 検証のうえ Fortify 独自の `auth.password_confirmed_at` を stamp するのみで、
  本アプリの gate（`recent_auth_at`）へは影響しない（`RecentAuthWindow` は
  `recent_auth_at` のみ参照）。挙動変更なし。
- **回帰面**: 変更は「未 bind だった contract を bind する」だけで、既存の bind 済み
  contract・ルート・画面に触れない。回帰面は実質ゼロ。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 変更が Provider 1 ファイル + テスト 1 ファイルの極小差分で、他施策・他画面との結合がない。standalone にする理由（大規模リファクタ・長期並走）がない |
| 競合リスク | `FortifyServiceProvider::configureViews()` を触る他タスクが同時進行しない限りなし。config/fortify.php の TODO(template)（2FA 管理ルートの recent-auth 後付け配線）を別タスクで実施する場合も本変更とは独立 |

---

## 関連する現行コード

### app/Providers/FortifyServiceProvider.php (全文)
```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\Fortify\EnumerationSafePasswordResetLinkResponse;
use App\Http\Responses\Fortify\LoginResponse;
use App\Http\Responses\Fortify\RecoveryCodesGeneratedResponse;
use App\Http\Responses\Fortify\RegisterResponse;
use App\Http\Responses\Fortify\TwoFactorDisabledResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RecoveryCodesGeneratedResponse as RecoveryCodesGeneratedResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
use Laravel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Fortify Response contract の差し替え (redirect + flash の Inertia 整合化)。
        // 挙動の意図は各 Response クラスの docblock を参照。
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(TwoFactorDisabledResponseContract::class, TwoFactorDisabledResponse::class);
        $this->app->singleton(RecoveryCodesGeneratedResponseContract::class, RecoveryCodesGeneratedResponse::class);
        // forgot-password は成功/失敗の両契約を enumeration-safe な同一応答へ差し替える。
        // Fortify は constructor に status を渡して make するため bind (非 singleton)
        $this->app->bind(SuccessfulPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
        $this->app->bind(FailedPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        $this->configureRateLimiters();
        $this->configureViews();
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $username = $request->input(Fortify::username());
            $throttleKey = Str::transliterate(
                Str::lower(is_string($username) ? $username : '').'|'.$request->ip(),
            );

            return Limit::perMinute(5)->by($throttleKey);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            $loginId = $request->session()->get('login.id');

            return Limit::perMinute(5)->by(is_scalar($loginId) ? (string) $loginId : $request->ip().'|2fa');
        });
    }

    /**
     * 全 Fortify GET ルートを Inertia ページに接続する。
     */
    private function configureViews(): void
    {
        Fortify::loginView(static fn (): InertiaResponse => Inertia::render('Auth/Login', [
            'socialProviders' => array_keys(config()->array('template.social_providers')),
        ]));

        Fortify::registerView(static fn (): InertiaResponse => Inertia::render('Auth/Register', [
            'socialProviders' => array_keys(config()->array('template.social_providers')),
        ]));

        Fortify::requestPasswordResetLinkView(
            static fn (): InertiaResponse => Inertia::render('Auth/ForgotPassword'),
        );

        Fortify::resetPasswordView(static function (Request $request): InertiaResponse {
            $token = $request->route('token');
            $email = $request->query('email');

            return Inertia::render('Auth/ResetPassword', [
                'token' => is_string($token) ? $token : '',
                'email' => is_string($email) ? $email : null,
            ]);
        });

        Fortify::verifyEmailView(static fn (): InertiaResponse => Inertia::render('Auth/VerifyEmail'));

        // password.confirm (Fortify 生 step-up) は generic recent-auth に置換済みのため
        // confirmPasswordView は登録しない (確認画面は Auth/ConfirmRecentAuth)。

        Fortify::twoFactorChallengeView(static fn (): InertiaResponse => Inertia::render('Auth/TwoFactorChallenge'));
    }
}
```

### routes/web.php (recent-auth 周辺抜粋 L152-170)
```php
*/
Route::middleware(['auth', 'verified'])->group(function (): void {
    // ログイン直後の着地点 (課金ゲート外のまま。未契約でも状況把握と復帰導線を提供)
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    /*
    | recent-auth (generic step-up 再認証)。機微操作 route の `recent-auth` middleware が
    | 鮮度切れ時にここへ誘導する。satisfier は password 再入力と再SSO
    | (/auth/{provider}/redirect/step-up)。allowlist は RecentAuthRouteTest が CI 固定。
    */
    Route::get('/recent-auth/confirm', [ConfirmRecentAuthController::class, 'show'])
        ->name('recent-auth.confirm');
    // クライアント主導 step-up の precheck (XHR, no-store)
    Route::get('/recent-auth/status', [ConfirmRecentAuthController::class, 'status'])
        ->name('recent-auth.status');
    Route::post('/recent-auth/password', [ConfirmRecentAuthController::class, 'confirmPassword'])
        ->middleware('throttle:6,1')
        ->name('recent-auth.password');

```

### app/Http/Middleware/RequireRecentAuth.php (抜粋 L29-76)
```php
final class RequireRecentAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();

        if (RecentAuthWindow::isFresh($session->get('recent_auth_at'))) {
            $response = $next($request);
            if (! $response instanceof Response) {
                throw new LogicException('Expected Symfony Response from middleware $next, got '.get_debug_type($response));
            }

            return $response;
        }

        $confirmUrl = route('recent-auth.confirm');

        // XHR (expectsJson) と Inertia の非 GET visit は 409 + code。クライアントが再認証後に
        // 元操作を再送する。Inertia GET は従来どおり 302 → confirm → intended GET replay が
        // 機能するため対象外。409 に x-inertia-location / x-inertia-redirect ヘッダを付けない
        // こと (Inertia core の external redirect 信号と衝突するため)。
        if ($request->expectsJson() || $this->isInertiaMutation($request)) {
            return RecentAuthRequiredResource::make(new RecentAuthRequiredDto(
                message: 'この操作には直近の再認証が必要です。',
                redirect: $confirmUrl,
            ))
                ->response()
                ->setStatusCode(409)
                ->withHeaders(['Cache-Control' => 'no-store']);
        }

        // GET は fullUrl (自 origin 確定)、それ以外は遷移元が無いので referer を intended に。
        // referer はクライアント制御ヘッダで外部 URL になり得るため、same-origin のみ採用し
        // それ以外 (外部 origin / 不在) は dashboard へフォールバックする (open redirect 防止)。
        $intended = $request->isMethod('GET')
            ? $request->fullUrl()
            : $this->sameOriginRefererOrDashboard($request);
        $session->put('url.intended', $intended);

        // 非 GET の 302 fallback (非 Inertia の素フォーム POST 等) は mutation body を保持できない。
        // confirm 成功後に「もう一度操作してください」を案内するための one-shot flag
        // (サイレント喪失防止の defense-in-depth、satisfier 側が消費する)。
        if (! $request->isMethod('GET')) {
            $session->put('recent_auth.dropped_mutation', true);
        }

        return redirect()->route('recent-auth.confirm');
    }
```

### app/Http/Controllers/Auth/ConfirmRecentAuthController.php (show/confirmPassword 抜粋 L42-132)
```php
    /**
     * 鮮度切れ時の 302 フォールバック確認画面 (直接遷移・非 XHR 用)。
     */
    public function show(Request $request): InertiaResponse
    {
        $status = $this->buildStatus($this->currentUser($request));

        return Inertia::render('Auth/ConfirmRecentAuth', [
            'passwordSet' => $status->passwordSet,
            'availableProviders' => array_map(
                static fn (RecentAuthProviderDto $p): array => [
                    'provider' => $p->provider,
                    'capability' => $p->capability->value,
                    'reauthUrl' => $p->reauthUrl,
                ],
                $status->availableProviders,
            ),
            'canSatisfy' => $status->canSatisfy,
        ]);
    }

    /**
     * クライアント主導モーダルの precheck。no-store。
     */
    public function status(Request $request): JsonResponse
    {
        $status = $this->buildStatus($this->currentUser($request));

        return RecentAuthStatusResource::make($status)
            ->response()
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    /**
     * password 再入力 satisfier。
     *
     * レスポンス契約:
     *   - Inertia リクエスト (standalone confirm 画面の form.post、X-Inertia あり)
     *     → `redirect()->intended(dashboard)`。RequireRecentAuth が保持した元 URL へ戻す。
     *   - 非 Inertia XHR (インラインモーダルの fetch、X-Inertia なし) → 204 No Content。
     *     クライアントはモーダルを閉じて pending action を再実行する。
     */
    public function confirmPassword(Request $request): Response|RedirectResponse
    {
        $user = $this->currentUser($request);

        $request->validate([
            'password' => ['required', 'string'],
        ]);

        // fail-closed: password 未設定 (SSO-only) は password 経路で step-up できない。
        if (! $user->hasPassword()) {
            throw ValidationException::withMessages([
                'password' => 'このアカウントはパスワードが設定されていません。SSO で再認証してください。',
            ]);
        }

        $passwordHash = $user->password;
        Assert::string($passwordHash); // hasPassword() true ⇒ 非 null string。PHPStan narrowing。

        $password = $request->string('password')->value();
        if (! Hash::check($password, $passwordHash)) {
            throw ValidationException::withMessages([
                'password' => 'パスワードが正しくありません。',
            ]);
        }

        $this->recentAuthState->confirm(method: 'password');

        // 302 fallback 経路で mutation を破棄していた場合 (RequireRecentAuth の one-shot flag)、
        // intended へ戻した画面で再操作を促す (サイレント喪失の防止)。204 経路 (インライン
        // モーダル) はクライアントが pending action を自前で再開するため読み捨てる
        // (両経路で必ず消費し、次回 step-up に持ち越さない)。
        // 注: RecentAuthState::confirm() の session migrate はデータ保持のため flag/intended は
        // 失われない。
        $droppedMutation = $request->session()->pull('recent_auth.dropped_mutation') === true;

        // standalone 画面 (Inertia) は 204 を処理できず詰むため、intended (RequireRecentAuth が
        // 保持した元 URL、無ければ dashboard) へ戻す。この分岐は Inertia protocol
        // (X-Inertia ヘッダ) のレスポンス契約用であり、Accept 等の他シグナルで判定しない。
        if ($request->hasHeader('X-Inertia')) {
            $redirect = redirect()->intended(route('dashboard'));
            if ($droppedMutation) {
                $redirect->with('info', '再認証が完了しました。先ほどの操作はまだ実行されていません。お手数ですがもう一度操作してください。');
            }

            return $redirect;
        }

        return response()->noContent()->withHeaders(['Cache-Control' => 'no-store, private']);
    }
```

### vendor/laravel/fortify/routes/routes.php (password confirmation 抜粋 L117-131)
```php
    // Password Confirmation...
    if ($enableViews) {
        Route::get(RoutePath::for('password.confirm', '/user/confirm-password'), [ConfirmablePasswordController::class, 'show'])
            ->middleware([config('fortify.auth_middleware', 'auth').':'.config('fortify.guard')])
            ->name('password.confirm');
    }

    Route::get(RoutePath::for('password.confirmation', '/user/confirmed-password-status'), [ConfirmedPasswordStatusController::class, 'show'])
        ->middleware([config('fortify.auth_middleware', 'auth').':'.config('fortify.guard')])
        ->name('password.confirmation');

    Route::post(RoutePath::for('password.confirm', '/user/confirm-password'), [ConfirmablePasswordController::class, 'store'])
        ->middleware([config('fortify.auth_middleware', 'auth').':'.config('fortify.guard')])
        ->name('password.confirm.store');

```

### vendor/laravel/fortify/src/Http/Responses/SimpleViewResponse.php (toResponse 抜粋)
```php
    /**
     * Create an HTTP response that represents the object.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return mixed
     */
    public function toResponse($request)
    {
        if (! is_callable($this->view) || is_string($this->view)) {
            return view($this->view, ['request' => $request]);
        }

        $response = call_user_func($this->view, $request);

        if ($response instanceof Responsable) {
            return $response->toResponse($request);
        }

        return $response;
    }
}
```

### tests/Feature/Auth/RecentAuthTest.php (既存テスト抜粋 L40-115)
```php
/* ---------------------------------------------------------------- middleware */

test('鮮度なしの通常遷移は confirm 画面へ 302 (dropped_mutation flag 付き)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->delete('/settings/account');

    $response->assertRedirect(route('recent-auth.confirm'));
    $response->assertSessionHas('recent_auth.dropped_mutation', true);
});

test('鮮度なしの XHR は 409 + recent_auth_required code (no-store)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->deleteJson('/settings/account');

    $response->assertStatus(409)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJson([
            'code' => 'recent_auth_required',
            'redirect' => route('recent-auth.confirm'),
        ]);
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('鮮度なしの Inertia mutation は 409 (302 にしない)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeaders(['X-Inertia' => 'true'])
        ->delete('/settings/account');

    $response->assertStatus(409)->assertJsonPath('code', 'recent_auth_required');
});

test('stale な recent_auth_at (timeout 超過) はブロックされる', function (): void {
    $user = User::factory()->create();
    $timeout = config()->integer('auth.recent_auth_timeout');

    $response = $this->actingAs($user)
        ->withSession(['recent_auth_at' => time() - $timeout - 1])
        ->delete('/settings/account');

    $response->assertRedirect(route('recent-auth.confirm'));
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('外部 origin の referer は intended に採用されない (open redirect 防止)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withHeaders(['referer' => 'https://evil.example.com/phish'])
        ->delete('/settings/account')
        ->assertRedirect(route('recent-auth.confirm'));

    expect(session('url.intended'))->toBe(route('dashboard'));
});

/* ---------------------------------------------------------------- confirm 画面 / status */

test('confirm 画面は passwordSet / availableProviders / canSatisfy を返す', function (): void {
    $user = User::factory()->create();
    linkGoogleAccount($user, 'g-1');

    $response = $this->actingAs($user)->get('/recent-auth/confirm');

    $response->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Auth/ConfirmRecentAuth')
            ->where('passwordSet', true)
            ->where('canSatisfy', true)
            ->where('availableProviders.0.provider', 'google')
            ->where('availableProviders.0.reauthUrl', route('social.redirect', ['provider' => 'google', 'intent' => 'step-up'])));
});

test('status は鮮度と satisfier 情報を返す (no-store)', function (): void {
```

### config/fortify.php (features 抜粋 L146-163)
```php
    'features' => [
        Features::registration(),
        Features::resetPasswords(),
        Features::emailVerification(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            // Fortify 標準の password.confirm (3h・パスワード限定) は無効化し、step-up を
            // generic recent-auth (15 分窓・パスワード or 再SSO) へ統一する。SSO-only ユーザーを
            // password 固定の確認画面で詰ませないため。
            // TODO(template): この撤去により 2FA 管理エンドポイント (enable/confirm/disable/
            // recovery-codes/qr-code/secret-key) は step-up なしで到達可能になる。アプリでは
            // Fortify 登録ルートへ recent-auth を後付け配線して固めること
            // (参照: aigenba RequireRecentAuthOnFortifyRoutes / spirux attachFortifyRouteMiddleware)。
            'confirmPassword' => false,
        ]),
    ],
```
