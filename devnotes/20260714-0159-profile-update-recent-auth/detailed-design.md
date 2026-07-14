# 詳細設計: profile-update-recent-auth

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを
生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも
標準化されたマニュアル動画を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

本改善は、認証要素 (email) 変更経路に step-up を課し、アカウント乗っ取り (メール差し替え→
パスワードリセット) を塞ぐことで「現場作業者が安心して使えるアプリ」の信頼基盤を守る。

### 禁止事項

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テスト登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

### セキュリティ不変条件（関連）
- PII(email/name)は CipherSweet、検索は `whereBlind()`。認証済みユーザーの `$user->email` は透過復号。
- 認証要素変更の前段に step-up (recent-auth) を課す (本設計が拡張する不変条件)。

### コーディングルール
- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`)、**RefreshDatabase** グローバル適用 + `--parallel` (個別 `DatabaseTransactions` 禁止)
- テストデータは Factory で生成
- **DTO + JsonResource** パターン (応答生成は委譲先 `RequireRecentAuth` の `RecentAuthRequiredResource` に集約)
- アーリーリターン推奨、`composer fix` (Pint) / `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

[devnotes/20260714-0159-profile-update-recent-auth/conceptual-design.md](./conceptual-design.md)
（概念設計は Codex `gpt-5.4` Round 3 で **APPROVED**）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 条件付き middleware 新設 (email 変更時のみ recent-auth 委譲) | `app/Http/Middleware/RequireRecentAuthOnEmailChange.php` (新規) | High |
| S2 | middleware alias 登録 | `bootstrap/app.php` | High |
| S3 | `user-profile-information.update` へ後付け配線 | `app/Providers/FortifyServiceProvider.php` | High |
| S4 | Architecture allowlist 追加 | `tests/Architecture/RecentAuthRouteTest.php` | High |
| S5 | client precheck (email 変更時のみ step-up) | `resources/js/pages/Settings/Index.svelte` | Mid |
| S6 | Feature テスト (テストマトリクス 1a/1b/2/3/5) | `tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php` (新規) | High |
| S7 | client テスト (case 6: 再認証後の再開) + viaRemember listener (case 4) | `tests/js/pages/SettingsIndex.test.ts` / `tests/Feature/Auth/RecentAuthTest.php` | High |

> 注: 修正方針 (2)「旧アドレス通知 + `email_verified_at` null 化」は既に
> `UpdateUserProfileInformation` に実装済み・`tests/Feature/Auth/EmailChangeTest.php` で固定済み。
> 本設計では action 本体を変更せず、回帰を維持する。
> **必須回帰**: `tests/Feature/Auth/EmailChangeTest.php` (旧アドレス通知 / `email_verified_at` null 化 /
> 重複 email 不可 = `whereBlind` 準拠) を本タスクの実行セットに明示的に含め、action 経路の回帰を担保する。

---

## S1: 条件付き middleware 新設

### 変更箇所
- ファイル: `app/Http/Middleware/RequireRecentAuthOnEmailChange.php` (新規)

### 波及変更
- TypeScript型定義: なし
- API Resource/DTO: なし (応答は委譲先 `RequireRecentAuth` の `RecentAuthRequiredResource` を再利用)
- テストファイル: `tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php` (S6), `tests/Architecture/RecentAuthRouteTest.php` (S4)

### 設計（email 同一性判定契約）
action `UpdateUserProfileInformation` の early-return 条件 (`$email === $user->email`) と
**完全に同一の raw 文字列比較**を使い、正規化ドリフト由来の bypass を構造的に排除する。

- 抽出: `is_string($request->input('email')) ? {文字列} : null` で `?string`。
- gate 条件 (両方満たす時のみ委譲): (1) submitted が `is_string`、(2) submitted `!==` `$user->email`。
- 欠落/非 string: gate せず `$next` へ流し Validator 422 に委ねる (非 string は email 変更を
  起こせず fail-safe 維持、bypass 不可)。
- case-only/whitespace 差: `!==` で「変更」と判定 → gate (action の通知送出挙動と一貫)。
- 応答生成・409/302 分岐・intended 保持・dropped_mutation flag は全て委譲先 `RequireRecentAuth` が担う。

### 変更後コード
```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * profile 更新 (PUT /user/profile-information) のうち **メールアドレス変更を伴う場合のみ**
 * recent-auth (step-up) を要求する条件付きゲート。alias: `recent-auth.on-email-change`。
 *
 * 氏名のみの変更は乗っ取りベクタではないため素通しし、日常操作の摩擦を増やさない。
 * email 変更は「認証要素変更」であり、UpdateUserProfileInformation が旧アドレス通知 +
 * email_verified_at null 化を行う経路。ここを stale セッション (remember-me 復元で
 * recent_auth_at 未 stamp) から素通しさせない。
 *
 * 判定契約 (UpdateUserProfileInformation::update の early-return と同一の raw 比較):
 *   - submitted email が is_string かつ現行 email と `!==` の時のみ RequireRecentAuth へ委譲。
 *   - 欠落 / 非 string は gate せず後続 (Validator の required/email 422) に委ねる。
 *     非 string は email 変更を起こせないため fail-safe (bypass 不可)。
 *
 * 応答 (409 + RecentAuthRequiredResource / 302 → recent-auth.confirm) は委譲先が生成する。
 */
final class RequireRecentAuthOnEmailChange
{
    public function __construct(private readonly RequireRecentAuth $requireRecentAuth) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->changesEmail($request)) {
            return $this->requireRecentAuth->handle($request, $next);
        }

        $response = $next($request);
        \Webmozart\Assert\Assert::isInstanceOf($response, Response::class);

        return $response;
    }

    /**
     * 送信 email が現行 email を変更するか (action の early-return と同一の raw 比較)。
     */
    private function changesEmail(Request $request): bool
    {
        $submitted = $request->input('email');
        if (! is_string($submitted)) {
            return false; // 欠落 / 非 string → 変更を起こせない。Validator に委ねる
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return false; // auth 前段は 'auth' middleware が担保。非 User なら gate 対象外
        }

        return $submitted !== $user->email;
    }
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている (`Response` / `bool`)
- [x] null安全 (`is_string` narrowing、`$user instanceof User`、`Assert::isInstanceOf`)
- [x] DTOを返している (独自 JSON なし。委譲先が JsonResource を返す)
- [x] Genericsの型パラメータ: 該当なし
- [x] `$request->input('email')` の mixed を `is_string` で `?string`/string に narrowing

### テスト計画
- S6 の Feature テストで全分岐を固定 (テストマトリクス参照)。
- 委譲先 `RequireRecentAuth` の 409/302 ロジックは既存 `RecentAuthTest` が担保 (重複実装しない)。

### リスク
- `$request->input('email')` は raw ボディ値。action と同一 source のため判定ドリフトなし。
- `$user instanceof User` が false になるのは auth guard 未通過時のみ (route は `auth` group 内のため
  通常発生しない)。false → gate せず後続へ流すが、その場合 action も User を要求し安全側に倒れる。

---

## S2: middleware alias 登録

### 変更箇所
- ファイル: `bootstrap/app.php` (`$middleware->alias([...])` 内、`'recent-auth' => RequireRecentAuth::class` の直後)

### 波及変更
- TypeScript型定義: なし / API Resource/DTO: なし / テスト: S4 が付与を検証

### 現行コード
```php
$middleware->alias([
    'recent-auth' => RequireRecentAuth::class,
    // ... 他 alias
]);
```

### 変更後コード
```php
$middleware->alias([
    'recent-auth' => RequireRecentAuth::class,
    // profile 更新の email 変更時のみ step-up を課す条件付きゲート
    'recent-auth.on-email-change' => RequireRecentAuthOnEmailChange::class,
    // ... 他 alias
]);
```
`use App\Http\Middleware\RequireRecentAuthOnEmailChange;` を import に追加。

### PHPStan適合チェック
- [x] クラス参照の import 追加。型変更なし。

### テスト計画
- S4 の Architecture テストが alias 経由の付与を `str_starts_with($m, 'recent-auth')` で検出。

### リスク
- alias 名に `.` を含むが Laravel の alias key は任意文字列可。route middleware 指定
  `->middleware('recent-auth.on-email-change')` で解決される。`str_starts_with('recent-auth')`
  判定にも合致。

---

## S3: `user-profile-information.update` へ後付け配線

### 変更箇所
- ファイル: `app/Providers/FortifyServiceProvider.php`
  - `attachRecentAuthToSensitiveRoutes()` (booted callback) を拡張し、
    `user-profile-information.update` に `recent-auth.on-email-change` を idempotent に append。

### 波及変更
- TypeScript型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Architecture/RecentAuthRouteTest.php` (S4)

### 現行コード
```php
private const RECENT_AUTH_ROUTE_NAMES = [
    'two-factor.recovery-codes',
    'two-factor.regenerate-recovery-codes',
    'two-factor.disable',
];

private function attachRecentAuthToSensitiveRoutes(): void
{
    $this->app->booted(static function (Application $app): void {
        $routes = $app->make(Router::class)->getRoutes();
        $routes->refreshNameLookups();

        foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
            $route = $routes->getByName($name);
            if ($route !== null && ! in_array('recent-auth', $route->middleware(), true)) {
                $route->middleware('recent-auth');
            }
        }
    });
}
```

### 変更後コード
```php
/**
 * recent-auth 無条件付与 (第二要素 bypass/除去 経路)。
 * @var list<string>
 */
private const RECENT_AUTH_ROUTE_NAMES = [
    'two-factor.recovery-codes',
    'two-factor.regenerate-recovery-codes',
    'two-factor.disable',
];

/**
 * email 変更時のみ recent-auth を課す条件付き付与 (氏名のみ変更は素通し)。
 * profile 更新は Fortify 登録ルートのため booted で後付けする。
 * @var array<string, string>  route name => middleware alias
 */
private const CONDITIONAL_RECENT_AUTH_ROUTES = [
    'user-profile-information.update' => 'recent-auth.on-email-change',
];

private function attachRecentAuthToSensitiveRoutes(): void
{
    $this->app->booted(static function (Application $app): void {
        $routes = $app->make(Router::class)->getRoutes();
        $routes->refreshNameLookups();

        foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
            self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
        }

        foreach (self::CONDITIONAL_RECENT_AUTH_ROUTES as $name => $alias) {
            self::appendMiddlewareIfMissing($routes, $name, $alias);
        }
    });
}

/**
 * named route に middleware alias を idempotent に append する (未登録時のみ)。
 *
 * booted callback (static クロージャ) から呼ぶため **static** で定義し
 * `self::appendMiddlewareIfMissing(...)` で呼ぶ。
 */
private static function appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void
{
    $route = $routes->getByName($name);
    if ($route !== null && ! in_array($alias, $route->middleware(), true)) {
        $route->middleware($alias);
    }
}
```

**型契約 (確定)**:
- `Illuminate\Routing\Router::getRoutes()` の戻り値は具象 `Illuminate\Routing\RouteCollection`。
- `RouteCollection` は `Illuminate\Routing\RouteCollectionInterface` を実装し、`getByName(string): ?Route`
  は同 interface の宣言メソッド。よってヘルパ引数型は **`RouteCollectionInterface`** で確定する
  (実装クラスの具象を interface 型引数へ渡す通常の型互換。PHPStan L10 適合)。
  `getByName` の戻り値は `?Route`、null チェック済み。
- callback 内で使う `refreshNameLookups()` は具象 `RouteCollection` のメソッドだが、これは callback で
  取得した具象 `$routes` に対して呼ぶため型問題なし (ヘルパ内では使わない)。
- import 追加: `use Illuminate\Routing\RouteCollectionInterface;` / `use Illuminate\Routing\Route as RoutingRoute;` は不要
  (ヘルパは Route 型を明示参照しないため)。

### PHPStan適合チェック
- [x] `@var array<string, string>` を明示。`getByName` は `?Route`、null チェック済み。
- [x] `$route->middleware()` は `list<string>`、`in_array(..., true)` で strict。

### テスト計画
- S4 Architecture テスト + S6 Feature テストで実際の gate 挙動を固定。

### リスク
- Fortify がルートを boot で登録 → booted callback で解決。route:cache 下でも
  `CompiledRouteCollection::getByName()` が memoize 同一 instance を返す (既存 docblock の前提を踏襲)。
- middleware 実行順: append のため group の `auth`/`verified` の後。email 参照に必要な認証は済んでいる。

---

## S4: Architecture allowlist 追加

### 変更箇所
- ファイル: `tests/Architecture/RecentAuthRouteTest.php` の `recentAuthRequiredRouteNames()`

### 波及変更
- なし (テスト自体)

### 変更後コード
```php
function recentAuthRequiredRouteNames(): array
{
    return [
        // ... 既存
        'two-factor.disable',
        // profile 更新 (email 変更時のみ条件付き step-up。配線は
        // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()。
        // routeHasRecentAuth は 'recent-auth.on-email-change' も str_starts_with で検出)
        'user-profile-information.update',
    ];
}
```

### テスト計画
- 既存 `test('機微操作 route 全件に recent-auth middleware が付与されている')` が新 entry を検証。
- `routeHasRecentAuth` は `str_starts_with($middleware, 'recent-auth')` のため条件付き alias に合致 (変更不要)。

### リスク
- なし。付与漏れ検出の allowlist 拡張のみ。

---

## S5: client precheck (email 変更時のみ step-up)

### 変更箇所
- ファイル: `resources/js/pages/Settings/Index.svelte` の `submitProfile`

### 波及変更
- TypeScript型定義: なし (既存 `withRecentAuth` / `RecentAuthStatus` を再利用)
- テストファイル: `tests/js/pages/SettingsIndex.test.ts` (S7)

### 現行コード
```svelte
function submitProfile(event: SubmitEvent): void {
    event.preventDefault();
    profileForm.put("/user/profile-information", {
        errorBag: "updateProfileInformation",
        preserveScroll: true,
    });
}
```

### 変更後コード
```svelte
// baseline email。更新成功のたびに最新値へ同期し、連続操作 (変更→再編集) 時の
// precheck 判定ドリフトを抑える。
let baselineEmail = $state(initialUser?.email ?? "");

function putProfile(): void {
    // 送信時点の email をスナップショット。onSuccess で「サーバが受理した値」を baseline にするため、
    // 送信後〜応答前に入力が変わっても現在入力値で baseline を汚さない。
    const submittedEmail = profileForm.email;
    profileForm.put("/user/profile-information", {
        errorBag: "updateProfileInformation",
        preserveScroll: true,
        onSuccess: () => {
            // 成功時、受理された送信値を baseline に (連続操作の判定ズレ防止)
            baselineEmail = submittedEmail;
        },
    });
}

function submitProfile(event: SubmitEvent): void {
    event.preventDefault();
    // email 変更時のみ step-up precheck (氏名のみ変更は従来通り即 put)。
    // サーバ側 recent-auth.on-email-change が最終ゲート、これは UX 補助。
    const emailChanged = profileForm.email !== baselineEmail;
    if (emailChanged) {
        guardWithRecentAuth(putProfile);
        return;
    }
    putProfile();
}
```
`guardWithRecentAuth` / `resumePendingAction` / `RecentAuthModal` は既存 (account 削除で使用中) を
そのまま利用。`pendingAction` に `putProfile` が積まれ、再認証成功後 (`onConfirmed`) に再送される。

### 設計上の注意
- client の `emailChanged` 判定は baseline (更新成功で同期) との単純比較で、サーバ契約 (raw `!==`) と
  意味的に一致する。**ズレてもサーバが最終ゲート**のため安全 (precheck は UX のみ)。
- precheck が fresh を返しても、サーバ側で stale と判定された場合 (競合) は 409 が返る。
  Inertia の `useForm.put` は 409 を成功として扱わず `onError` も errorBag に載らないため、
  UX 補助の precheck を主経路とする (account 削除と同じ二層構造)。

### PHPStan適合チェック
- N/A (TypeScript/Svelte)。`pnpm typecheck` / `pnpm lint` で検証。

### テスト計画
- S7 client テスト: (a) email 変更あり → precheck 発火 (fresh なら put、stale なら modal)、
  (b) 氏名のみ変更 → precheck を経ず直 put。

### リスク
- 氏名のみ変更で precheck をスキップするため、この経路はサーバでも gate されない (設計通り)。

---

## S6: Feature テスト (テストマトリクス)

### 変更箇所
- ファイル: `tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php` (新規)

### テスト計画（テストマトリクス）

| # | test | 前提 | 送信 / 種別 | 期待 |
|---|------|------|-----------|------|
| 1a | stale + email 変更 (Inertia mutation) は 409 | `withSession` に recent_auth_at 無し | `X-Inertia`, `->put('/user/profile-information', {name, email 変更})` | 409、`assertJsonPath('code','recent_auth_required')`、`$user->refresh()->email` **不変**、`email_verified_at` **不変**、`Notification::assertNothingSent()` |
| 1b | stale + email 変更 (通常) は confirm へ redirect | recent_auth_at 無し | `->put(...)` (非 Inertia) | `assertRedirect(route('recent-auth.confirm'))`、`assertSessionHas('url.intended')`、email **不変**、`email_verified_at` **不変**、`assertNothingSent()` |
| 2 | stale + 氏名のみ変更は成功 (gate されない) | recent_auth_at 無し | `->put(...)` (email=現行値, name 変更) | `assertRedirect(...)` (成功遷移先)、email 不変・name 更新、`assertNothingSent()`。gate されないことを確認 |
| 3 | fresh + email 変更は成功 | `withSession(['recent_auth_at' => time()])` | `->put(...)` (email 変更) | 成功、email 更新、旧アドレス `EmailChangedSecurityNotification`、`email_verified_at` null |
| 5a | **stale + email 欠落** は Validator 422 (gate されない) | recent_auth_at 無し | `->putJson('/user/profile-information', {name のみ, email 未送信})` | 422 (recent-auth 応答でない = 409/302 でない)、email 不変 |
| 5b | **stale + email 非 string (配列)** は Validator 422 (gate されない) | recent_auth_at 無し | `->putJson(..., ['name'=>..., 'email'=>['x']])` | 422 (recent-auth 応答でない)、email 不変 |

> case 5 の dataset は **「email 欠落」「email 非 string (配列)」の 2 種のみ**に厳密に限定する
> (string 値は含めない)。string かつ変更ありの入力は middleware が委譲 → 409/302 になり期待と衝突するため。
> string 変更の gate 挙動は 1a/1b が担う。テスト名/データセット名で「欠落」「非string」を明示する。

- fresh の作り方: `->withSession(['recent_auth_at' => time()])` (RecentAuthTest 準拠)。
- 遮断/成功の判定は `assertRedirect(...)` 主体 (status 302/303 の断定を避け Fortify Response 実装に追従)。
- Notification 検証は `Notification::fake()` + `assertSentTo(new AnonymousNotifiable, ...)` (EmailChangeTest 準拠)。
- テストデータは `User::factory()`。個別 `DatabaseTransactions` は使わない (グローバル `RefreshDatabase`)。

### PHPStan適合チェック
- [x] Pest クロージャの型は既存テスト準拠。`User::factory()->create([...])`。

### リスク
- case 2/3 の成功遷移先: Fortify の profile 更新成功は `ProfileUpdatedResponse` (redirect + flash)。
  実装時に実レスポンスの遷移先を確認し `assertRedirect(...)` の引数を確定する (status は断定しない)。

---

## S7: client 再開テスト (case 6) + viaRemember listener (case 4)

### 変更箇所
- `tests/js/pages/SettingsIndex.test.ts` (case 6)
- `tests/Feature/Auth/RecentAuthTest.php` (case 4: listener の viaRemember スキップ)

### テスト計画
- **case 6 (client)**: email を変更 → `submitProfile` → `withRecentAuth` が stale status を返すよう
  `/recent-auth/status` を stale スタブ → `RecentAuthModal` が開く → `onConfirmed` (resumePendingAction)
  発火で `profileForm.put` が呼ばれ、編集済み name/email が保持されたまま再送されることを検証
  (`putMock` の引数 or `profileForm` state を確認)。既存 `stubRecentAuthFresh` に倣い stale スタブを追加。
  - 追加検証: stale 時の `put` 呼び出し回数が **再認証後に 1 回** であること (二重送信回帰の捕捉)。
    precheck 段階では put されず、`onConfirmed` 後に初めて 1 回呼ばれる。
- **case 4 (listener)**: `StampRecentAuthOnLogin` に `Login` イベント (web guard) を渡し、
  `SessionGuard::viaRemember()===true` の時 `recent_auth_at` が **stamp されない**ことを検証
  (fake guard で viaRemember=true を返させる)。これにより「remember-me 復元 = stale」を固定し、
  S6 の stale gate (1a/1b) と合わせて case 4 を担保する。
  - **対照ケース**: `viaRemember()===false` (通常 credential login) では `recent_auth_at` が
    **stamp される**ことも 1 本置き、契約 (fresh login のみ stamp) を両側から固定する。
  - 注: viaRemember の実 recaller cookie ログインは Feature で再現が煩雑なため、listener 単位で
    分岐を固定する (StampRecentAuthOnLogin docblock の「viaRemember は fresh 扱いしない」契約の直接検証)。

### PHPStan / lint
- client: `pnpm test` / `pnpm typecheck`。listener テスト: `composer test` / `composer phpstan`。

### リスク
- listener テストで `SessionGuard` の mock 構築が必要。既存 `RecentAuthTest` の Mockery 利用に倣う。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 既存 recent-auth 機構・FortifyServiceProvider・Settings/Index.svelte・RecentAuthRouteTest への追記が中心で、独立した新規ドメインではない。既存資産へ段階的に組み込む方が整合性を保てる。 |
| 競合リスク | 低。変更は recent-auth 周辺に閉じる。FortifyServiceProvider の booted callback 拡張は two-factor 系配線と独立。Settings/Index.svelte は既存 `guardWithRecentAuth` 再利用で衝突なし。 |

## 使命・禁止事項チェック（最終）
- [x] 使命寄与: 認証要素変更の step-up を profile 経路へ拡張しアカウント乗っ取りを防ぐ。
- [x] 禁止事項 1: 全施策に Architecture/Feature/client テストを付与。
- [x] 禁止事項 2: PHPStan L10 を意識 (`is_string` narrowing、`@var`、`Assert`)。widen/baseline なし。
- [x] 禁止事項 4: 独自 `response()->json()` を作らず委譲先の `RecentAuthRequiredResource` (JsonResource) に集約。
- [x] 禁止事項 8: UI の disabled は使わない (押下時に step-up モーダル / エラー表示)。
- [x] `DatabaseTransactions` 個別使用なし (グローバル `RefreshDatabase`)。
