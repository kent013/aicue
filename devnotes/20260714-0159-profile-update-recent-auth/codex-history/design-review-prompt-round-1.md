# アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。競合(tebiki)と異なり標準作業を起点に AI が教材設計し撮影を指示する。

# 禁止事項
1. テストなしの実装完了報告(不変条件は Architecture/Feature テスト登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

# セキュリティ不変条件（関連）
PII(email/name)は CipherSweet、検索は whereBlind()。認証済み `$user->email` は透過復号。
認証要素変更の前段に step-up (recent-auth) を課す。

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。
【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供テキストの分析に集中。ファイル読み込みは許可。

---

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリの詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / DTO + JsonResource / Laratrust RBAC。

【レビュー観点】
1. コードの正確性(ロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性 3. PHPStan level 10 適合性 4. テスト計画の網羅性
5. DTO/JsonResource 遵守 6. Inertia Props vs API Response の使い分け 7. 副作用・後退リスク
8. 波及変更の網羅性(TS 型定義・Resource・テスト) 9. セキュリティ(認可・入力バリデーション・OWASP)
10. DESIGN.md 準拠(UI 変更を含む場合) 11. Atomic Design 準拠(UI 変更を含む場合)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類し、Critical/Warning には修正案を必ず添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【背景】bug-hunt finding F-4-01 (High/authz_bypass)。`user-profile-information.update` が recent-auth 未保護で、stale セッション (remember-me 復元で recent_auth_at 未 stamp) からメール差し替え→パスワードリセットでアカウント乗っ取り可能。旧アドレス通知 + email_verified_at null 化は既に実装済み (action + EmailChangeTest)。本設計は未対応の recent-auth 配線 (email 変更時のみ条件付き) を追加する。概念設計は gpt-5.4 で APPROVED 済み。

---

## 詳細設計書


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
            $route = $routes->getByName($name);
            if ($route !== null && ! in_array('recent-auth', $route->middleware(), true)) {
                $route->middleware('recent-auth');
            }
        }

        foreach (self::CONDITIONAL_RECENT_AUTH_ROUTES as $name => $alias) {
            $route = $routes->getByName($name);
            if ($route !== null && ! in_array($alias, $route->middleware(), true)) {
                $route->middleware($alias);
            }
        }
    });
}
```

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
function putProfile(): void {
    profileForm.put("/user/profile-information", {
        errorBag: "updateProfileInformation",
        preserveScroll: true,
    });
}

function submitProfile(event: SubmitEvent): void {
    event.preventDefault();
    // email 変更時のみ step-up precheck (氏名のみ変更は従来通り即 put)。
    // サーバ側 recent-auth.on-email-change が最終ゲート、これは UX 補助。
    const emailChanged = profileForm.email !== (initialUser?.email ?? "");
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
- client の `emailChanged` 判定は初期値 (`initialUser.email`) との単純比較で、サーバ契約 (raw `!==`) と
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
| 1a | stale + email 変更 (Inertia mutation) は 409 | `withSession` に recent_auth_at 無し | `X-Inertia`, `->put('/user/profile-information', {name, email 変更})` | 409、`assertJsonPath('code','recent_auth_required')`、`$user->refresh()->email` 不変、`Notification::assertNothingSent()` |
| 1b | stale + email 変更 (通常) は 302 → confirm | recent_auth_at 無し | `->put(...)` (非 Inertia) | 302 → `recent-auth.confirm`、`assertSessionHas('url.intended')`、email 不変 |
| 2 | stale + 氏名のみ変更は成功 | recent_auth_at 無し | `->put(...)` (email=現行値, name 変更) | 302 (Fortify 成功 redirect) / email 不変・name 更新、gate されない |
| 3 | fresh + email 変更は成功 | `withSession(['recent_auth_at' => time()])` | `->put(...)` (email 変更) | 成功、email 更新、旧アドレス `EmailChangedSecurityNotification`、`email_verified_at` null |
| 5 | stale + email 欠落/非 string は Validator 422 (gate されない) | recent_auth_at 無し | `->putJson(...)` email 未送信 / email=配列 | 422 (recent-auth 応答でない)。**dataset で欠落と非 string の両方を実行** |

- fresh の作り方: `->withSession(['recent_auth_at' => time()])` (RecentAuthTest 準拠)。
- Notification 検証は `Notification::fake()` + `assertSentTo(new AnonymousNotifiable, ...)` (EmailChangeTest 準拠)。
- テストデータは `User::factory()`。個別 `DatabaseTransactions` は使わない (グローバル `RefreshDatabase`)。

### PHPStan適合チェック
- [x] Pest クロージャの型は既存テスト準拠。`User::factory()->create([...])`。

### リスク
- case 2 の期待ステータス: Fortify の profile 更新成功は `ProfileUpdatedResponse` (redirect 302 + flash)。
  実装時に実レスポンスを確認して assert を確定する (302 or 303)。

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
- **case 4 (listener)**: `StampRecentAuthOnLogin` に `Login` イベント (web guard) を渡し、
  `SessionGuard::viaRemember()===true` の時 `recent_auth_at` が **stamp されない**ことを検証
  (fake guard で viaRemember=true を返させる)。これにより「remember-me 復元 = stale」を固定し、
  S6 の stale gate (1a/1b) と合わせて case 4 を担保する。
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

## 関連する現行コード

### app/Http/Middleware/RequireRecentAuth.php (委譲先)
```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DataTransferObjects\Auth\RecentAuthRequiredDto;
use App\Http\Resources\Auth\RecentAuthRequiredResource;
use App\Security\RecentAuthWindow;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 機微操作の前に generic recent-auth (step-up 再認証) を強制する単一ゲート。
 * alias: `recent-auth`。
 *
 * Fortify 生の `password.confirm` (password 専用・3h 窓) を置き換える。satisfier は
 * ConfirmRecentAuthController (password 再入力) と SocialAuthController の step-up intent
 * (再SSO) に集約され、SSO-only ユーザーも fail-closed で詰まずに再SSO へ誘導される。
 *
 * 判定:
 *   1. `recent_auth_at` が鮮度ウィンドウ内 (RecentAuthWindow) → 通過
 *   2. XHR (expectsJson) または Inertia の非 GET → 409 + { code, message, redirect }(no-store)。
 *      クライアント (素 fetch / recent-auth precheck) が再認証後に元操作を再送
 *   3. それ以外 (通常遷移) → 302 で recent-auth confirm 画面へ。元 URL を intended に保持
 */
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

    /**
     * Inertia protocol の mutation visit (X-Inertia ヘッダ + 非 GET)。
     * Accept は text/html のため expectsJson() では捕捉できない。
     */
    private function isInertiaMutation(Request $request): bool
    {
        return $request->hasHeader('X-Inertia') && ! $request->isMethod('GET');
    }

    private function sameOriginRefererOrDashboard(Request $request): string
    {
        $referer = $request->headers->get('referer');
        if ($referer === null) {
            return route('dashboard');
        }

        // 完全一致 or 「origin + '/'」前置一致のみ same-origin と判定する。
        // 単純な str_starts_with($referer, $origin) だと https://app.host.evil.com を通すため、
        // 区切り '/' まで含めて比較する。
        $origin = $request->getSchemeAndHttpHost();
        if ($referer === $origin || str_starts_with($referer, $origin.'/')) {
            return $referer;
        }

        return route('dashboard');
    }
}
```
### app/Actions/Fortify/UpdateUserProfileInformation.php (email 比較の early-return)
```php
<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Notifications\EmailChangedSecurityNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;
use Webmozart\Assert\Assert;

/**
 * プロフィール (name / email) 更新。
 *
 * メール変更時 (Q11 決定):
 * - 旧アドレスへセキュリティ通知を送る (新アドレスは旧保持者に非開示。乗っ取り検知導線)
 * - email_verified_at を null 化して新アドレスの再検証を要求する
 * - email の一意性は whereBlind で明示チェック (暗号化カラムのため unique rule 不可)
 */
class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        $validated = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ])->validateWithBag('updateProfileInformation');

        $name = $validated['name'];
        $email = $validated['email'];
        Assert::string($name);
        Assert::string($email);

        if ($email === $user->email) {
            $user->forceFill(['name' => $name])->save();

            return;
        }

        if ($this->emailTakenByOther($email, $user)) {
            throw ValidationException::withMessages([
                'email' => ['このメールアドレスには変更できません。'],
            ])->errorBag('updateProfileInformation');
        }

        $oldEmail = $user->email;

        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => null,
        ])->save();

        // 旧アドレスへの on-demand セキュリティ通知 (アカウントを持たない宛先にも送れる経路)
        Notification::route('mail', $oldEmail)
            ->notify(new EmailChangedSecurityNotification);

        $user->sendEmailVerificationNotification();
    }

    /**
     * @phpstan-impure
     */
    private function emailTakenByOther(string $email, User $user): bool
    {
        return User::whereBlind('email', 'email_index', $email)
            ->whereKeyNot($user->getKey())
            ->exists();
    }
}
```
### app/Providers/FortifyServiceProvider.php (attachRecentAuthToSensitiveRoutes 抜粋 L44-127)
```php
{
    /**
     * recent-auth (step-up) を後付け配線する Fortify 登録ルート。
     * いずれも「確立済み第二要素の bypass / 除去」経路であり、通常セッション認証だけで
     * 到達させない (姉妹操作: organizations.members.two-factor.reset /
     * settings.account.destroy 等と同基準)。
     * - recovery-codes 表示 (GET) / 再生成 (POST): TOTP を伴わないログイン成立手段の露出・更新。
     * - disable (DELETE): 第二要素そのものの無効化 (bug-hunt F-H3)。
     *   ※ 2FA 必須組織の準拠ユーザーは BlockTwoFactorDisableForEnforcedOrganizations
     *     (web group、recent-auth より先行) が 422 で拒否するため、本配線が実効するのは
     *     self-disable が許可される非 enforced 組織のユーザー。
     * 付与漏れは RecentAuthRouteTest (Architecture) が CI で検出する。
     *
     * @var list<string>
     */
    private const RECENT_AUTH_ROUTE_NAMES = [
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        'two-factor.disable',
    ];

    public function register(): void
    {
        // Fortify Response contract の差し替え (redirect + flash の Inertia 整合化)。
        // 挙動の意図は各 Response クラスの docblock を参照。
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
        $this->app->singleton(TwoFactorDisabledResponseContract::class, TwoFactorDisabledResponse::class);
        $this->app->singleton(RecoveryCodesGeneratedResponseContract::class, RecoveryCodesGeneratedResponse::class);
        $this->app->singleton(EmailVerificationNotificationSentResponseContract::class, VerificationNotificationSentResponse::class);
        // profile / password 更新は success flash に統一し保存完了を toast 化する
        // (status キーは flash-to-toast が gating するため toast にならない)。
        $this->app->singleton(ProfileInformationUpdatedResponseContract::class, ProfileUpdatedResponse::class);
        $this->app->singleton(PasswordUpdateResponseContract::class, PasswordUpdatedResponse::class);
        // password reset は Fortify が constructor に status を渡して make するため bind (非 singleton)
        $this->app->bind(PasswordResetResponseContract::class, PasswordResetResponse::class);
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
        $this->attachRecentAuthToSensitiveRoutes();
    }

    /**
     * Fortify が登録する機微な 2FA 管理ルートへ recent-auth middleware を後付けする。
     *
     * Fortify 標準の password.confirm は generic recent-auth へ置換済み
     * (config/fortify.php features.twoFactorAuthentication.confirmPassword=false) のため、
     * そのままではリカバリコードの表示/再生成が step-up なしで到達可能になる。
     * ルート登録は Fortify package provider の boot 内で行われるため、全 provider boot 後の
     * booted callback で名前解決して append する。route:cache 下でも
     * CompiledRouteCollection::getByName() が nameCache に memoize した同一 instance を
     * match() が返すため、この変更は dispatch にも有効。
     */
    private function attachRecentAuthToSensitiveRoutes(): void
    {
        $this->app->booted(static function (Application $app): void {
            $routes = $app->make(Router::class)->getRoutes();
            // fluent な ->name() 付与はコレクションの name index に遅延反映のため明示 refresh
            $routes->refreshNameLookups();

            foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
                $route = $routes->getByName($name);
                // 長寿命プロセス等で callback が同一 Route instance に複数回届いても
                // 重複付与しない (idempotent)
                if ($route !== null && ! in_array('recent-auth', $route->middleware(), true)) {
                    $route->middleware('recent-auth');
                }
            }
        });
    }

```
### tests/Architecture/RecentAuthRouteTest.php
```php
<?php

declare(strict_types=1);

use App\Http\Middleware\RequireRecentAuth;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;

/*
 * 機微操作 route に recent-auth middleware が付与されていることを CI で担保する (付与漏れ検出)。
 * 新たな機微操作 route を追加した PR は本 allowlist の更新を PR review で判断すること。
 */

/**
 * @return list<string>
 */
function recentAuthRequiredRouteNames(): array
{
    return [
        // API キー (発行 / 失効)
        'organizations.api-keys.store',
        'organizations.api-keys.revoke',
        // OAuth セッション失効 (組織管理経路。API キー失効と同じ機微度)
        'organizations.api-keys.sessions.revoke',
        // アカウント削除
        'settings.account.destroy',
        // オーナー移譲
        'organizations.transfer-ownership',
        // 組織の 2FA 必須方針トグル (Owner 専権のセキュリティ方針変更)
        'organizations.two-factor-requirement.update',
        // メンバー 2FA リセット (アカウント全体の第二要素を外す機微操作)
        'organizations.members.two-factor.reset',
        // リカバリコード表示 / 再生成 (第二要素の bypass 経路。Fortify 登録ルートへ
        // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() が後付け配線)
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        // 2FA 無効化 (第二要素そのものの除去。bug-hunt F-H3。同じく後付け配線)
        'two-factor.disable',
    ];
}

function routeHasRecentAuth(RoutingRoute $route): bool
{
    foreach ($route->gatherMiddleware() as $middleware) {
        if (! is_string($middleware)) {
            continue;
        }
        // alias 'recent-auth' / 'recent-auth:param' / 完全クラス名のいずれかを許容 (堅牢化)
        if ($middleware === RequireRecentAuth::class || str_starts_with($middleware, 'recent-auth')) {
            return true;
        }
    }

    return false;
}

test('機微操作 route 全件に recent-auth middleware が付与されている', function (): void {
    /** @var Router $router */
    $router = app('router');
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    foreach (recentAuthRequiredRouteNames() as $name) {
        $route = $routes->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' が存在しない (allowlist の更新漏れ?)");
        expect(routeHasRecentAuth($route))->toBeTrue("route '{$name}' に recent-auth middleware が付与されていない (付け忘れ)");
    }
});
```
### resources/js/pages/Settings/Index.svelte (submitProfile + guard 抜粋 L36-116, 256-264)
```svelte
    });

    const initialUser = props.auth?.user ?? null;

    /**
     * Fortify の PUT /user/profile-information は errorBag (updateProfileInformation)
     * を使う。submit 時に errorBag を指定すると Inertia がバッグを解決するため、
     * form.errors はバッグ名でネストされずフィールド名で参照できる。
     */
    const profileForm = useForm({
        name: initialUser?.name ?? "",
        email: initialUser?.email ?? "",
    });

    function submitProfile(event: SubmitEvent): void {
        event.preventDefault();
        profileForm.put("/user/profile-information", {
            errorBag: "updateProfileInformation",
            preserveScroll: true,
        });
    }

    const passwordForm = useForm({
        current_password: "",
        password: "",
    });

    function submitPassword(event: SubmitEvent): void {
        event.preventDefault();
        passwordForm.put("/user/password", {
            errorBag: "updatePassword",
            preserveScroll: true,
            onSuccess: () => {
                passwordForm.reset();
            },
        });
    }

    let deleteDialogOpen = $state(false);
    let deleting = $state(false);

    /* ---- recent-auth (step-up) precheck。stale なら再認証モーダルを挟んで再開する ---- */
    let recentAuthOpen = $state(false);
    let recentAuthStatus = $state<RecentAuthStatus | null>(null);
    let pendingAction: (() => void) | null = null;

    function guardWithRecentAuth(action: () => void): void {
        void withRecentAuth({
            onFresh: action,
            onStale: (status) => {
                recentAuthStatus = status;
                pendingAction = action;
                recentAuthOpen = true;
            },
        });
    }

    function resumePendingAction(): void {
        const action = pendingAction;
        pendingAction = null;
        action?.();
    }

    // アカウント削除は recent-auth (step-up) 必須。precheck で鮮度を確認してから送る
    function deleteAccount(): void {
        guardWithRecentAuth(() => {
            router.delete("/settings/account", {
                preserveScroll: true,
                onStart: () => {
                    deleting = true;
                },
                // ブロック時 (errors.account): ダイアログを閉じ DangerZone の Alert を露出させる
                onError: () => {
                    deleteDialogOpen = false;
                },
                onFinish: () => {
                    deleting = false;
                },
            });
        });
    }
...

    <RecentAuthModal
        bind:open={recentAuthOpen}
        passwordSet={recentAuthStatus?.passwordSet ?? false}
        availableProviders={recentAuthStatus?.availableProviders ?? []}
        canSatisfy={recentAuthStatus?.canSatisfy ?? true}
        onConfirmed={resumePendingAction}
    />
</AppLayout>
```
### tests/Feature/Auth/EmailChangeTest.php (既存回帰)
```php
<?php

declare(strict_types=1);

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use App\Notifications\EmailChangedSecurityNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

test('メール変更時に旧アドレスへセキュリティ通知が送られ再検証が要求される', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'old@example.com']);

    app(UpdateUserProfileInformation::class)->update($user, [
        'name' => $user->name,
        'email' => 'new@example.com',
    ]);

    $user->refresh();
    expect($user->email)->toBe('new@example.com');
    expect($user->email_verified_at)->toBeNull();

    // 旧アドレスへの on-demand 通知 (新アドレスは本文に含めない)
    Notification::assertSentTo(
        new AnonymousNotifiable,
        EmailChangedSecurityNotification::class,
        function ($notification, $channels, $notifiable): bool {
            return $notifiable->routes['mail'] === 'old@example.com';
        },
    );
});

test('他ユーザーの email へは変更できない (中立メッセージ)', function (): void {
    Notification::fake();
    User::factory()->create(['email' => 'taken@example.com']);
    $user = User::factory()->create(['email' => 'me@example.com']);

    expect(fn () => app(UpdateUserProfileInformation::class)->update($user, [
        'name' => $user->name,
        'email' => 'taken@example.com',
    ]))->toThrow(ValidationException::class);

    expect($user->refresh()->email)->toBe('me@example.com');
});

test('email 変更なしの name 更新では通知も再検証も発生しない', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'me@example.com']);

    app(UpdateUserProfileInformation::class)->update($user, [
        'name' => '新しい名前',
        'email' => 'me@example.com',
    ]);

    $user->refresh();
    expect($user->name)->toBe('新しい名前');
    expect($user->email_verified_at)->not->toBeNull();
    Notification::assertNothingSent();
});
```
