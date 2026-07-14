# アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。

- 「思考ゼロ・編集ゼロ」/ 標準作業起点で AI が教材設計・撮影指示 / 暗黙知→形式知（SECI）。

# 禁止事項（AGENTS.md）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

# セキュリティ不変条件（抜粋）
tenant キー不信 / 子は親に属する(404先行) / cross-org 不可 / 権限は laratrust_team_id 明示 / PII は CipherSweet。

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵（Laravel/Jetstream 標準）を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。
【ツール使用制限】コマンド実行・ファイル書き込みは行わず、提供テキストの分析に集中。ファイル読み込みは許可。

---

あなたは経験豊富な Web アプリアーキテクトです。Laravel + Svelte アプリ改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + **laravel/framework v13.18.0**（composer.lock ピン。テンプレ記載「Laravel 12」から更新済み）+ Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO+JsonResource / Laratrust RBAC
- session driver 既定 = database（テスト既定 = array）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク（特に AuthenticateSession グローバル有効化のブラスト半径）
8. 波及変更の網羅性（TS型/Props/Resource/テストが変更対象に含まれるか）
9. セキュリティ（認可、入力バリデーション、OWASP、AGENTS.md 不変条件）
10. DESIGN.md 準拠（UI変更なし → 非該当のはず）
11. Atomic Design 準拠（UI変更なし → 非該当のはず）

【特に検証してほしい点】
- 施策2 の順序（forceFill→logoutOtherDevices→行削除）と logoutOtherDevices への新 password 受け渡しが正しいか
- `deleteOtherSessionRecords` の PHPStan L10 適合（config mixed の Assert narrowing、`session()->getId()`、`DB::connection(?string)`）
- 施策1 の `authenticateSessions()` 有効化が既存フロー（login/2FA/SSO/reset/actingAs テスト）を壊さないか
- テスト (c)/(d) の多デバイス cookie 取り回しが現実的か、代替案は妥当か

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning には必ず修正案
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語

---

## 詳細設計書

（下に detailed-design.md 全文）

# 詳細設計: password-logout-other-devices

bug-hunt finding **F-H4 (High / authz_bypass)** の修正詳細設計。認証済みセルフサービスの
パスワード変更（`user-password.update` → `UpdateUserPassword`）が他デバイスの session /
remember-me を失効させるようにする。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画
シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの
現場作業者でも標準化されたマニュアル動画を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

本改善はこの使命の前提となる**セキュリティ不変条件**（組織/チーム/プロジェクトの RBAC と PII
保護のもとで、パスワード変更が侵入セッションを確実に締め出す）を担保する基盤修正である。

### 禁止事項（AGENTS.md）

1. テストなしの実装完了報告（不変条件は対応する Feature/Architecture テスト登録まで含めて実装済み）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

### セキュリティ不変条件（関連）

権限判定は `laratrust_team_id` 明示 / PII は CipherSweet / tenant キー不信。本修正は認証セッション
ライフサイクルの修正であり上記に抵触しない（cross-org・mass-assignment・prompt には触れない）。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`、`--parallel`）
- **RefreshDatabase** グローバル適用（`tests/Pest.php`）。個別 `DatabaseTransactions` 禁止
- テストデータは必ず **Factory** で生成
- **DTO + JsonResource** パターン（本修正は void アクションのためレスポンス生成なし＝該当なし）
- アーリーリターン推奨 / `declare(strict_types=1)` + 日本語コメント
- フォーマット: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + **laravel/framework v13.18.0**（composer.lock ピン。テンプレ記載「Laravel 12」から
  更新済み）+ Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex 概念設計レビュー Round 4 で APPROVED）

## 設計方針（3 層）

| 層 | 機構 | 役割 |
|----|------|------|
| 層1 | `AuthenticateSession` を web グループへ有効化 | **correctness の要**。毎リクエストで session の `password_hash_web` と現在ハッシュを照合し不一致なら logout。並行書き戻しで復活した session 行・他デバイスの recaller も次リクエストで失効させる |
| 層2 | `Auth::logoutOtherDevices($newPassword)` | 現在デバイスの session / recaller を維持（新ハッシュで recaller 再発行）+ `OtherDeviceLogout` イベント発火 |
| 層3 | 他 session 行の best-effort 削除 | 攻撃者を次リクエストを待たず即時にクリーンアップ（失敗しても層1が保証） |

remember_token の rotate は行わない（recaller の password_hash 照合は層1が担うため不要。Jetstream
標準構成に合わせる）。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | `AuthenticateSession` を web グループへ有効化 | `bootstrap/app.php` | High |
| 2 | `UpdateUserPassword::update()` で他セッション失効を実装 | `app/Actions/Fortify/UpdateUserPassword.php` | High |
| 3 | Feature テスト新規（不変条件の固定） | `tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php` | High |

---

## 施策1: `AuthenticateSession` を web グループへ有効化

### 変更箇所
- ファイル: `bootstrap/app.php`（`withMiddleware` クロージャ内、`$middleware->web(append: [...])` の直後）

### 波及変更
- TypeScript 型定義: なし（フロント無関係）
- API Resource/DTO: なし
- Inertia Props: なし
- テストファイル: 既存 Feature テスト全体が AuthenticateSession の影響下に入る → `composer test`
  全 green を実装完了条件にする（下記リスク参照）。middleware 構成を snapshot する Architecture
  テストは存在しない（`grep` 済）ため専用更新は不要。

### 実装方式（フレームワーク標準 API を使用）

framework v13.18.0 の `Illuminate\Foundation\Configuration\Middleware` は web グループに
`$this->authenticatedSessions ? 'auth.session' : null` のスロットを持ち、これを
**`$middleware->authenticateSessions()`** で有効化する（`Middleware::authenticateSessions()`
L770-775）。生の `append(AuthenticateSession::class)` ではなくこの一級 API を使う（フレームワークの
レンジ内でやる=思考原則#1。挿入位置・priority もフレームワークが決定）。

### 現行コード
```php
$middleware->web(append: [
    HandleInertiaRequests::class,
    SecurityHeaders::class,
    RequireTwoFactorForEnforcedOrganizations::class,
    BlockTwoFactorDisableForEnforcedOrganizations::class,
]);
```

### 変更後コード
```php
$middleware->web(append: [
    HandleInertiaRequests::class,
    SecurityHeaders::class,
    RequireTwoFactorForEnforcedOrganizations::class,
    BlockTwoFactorDisableForEnforcedOrganizations::class,
]);

// パスワード変更/リセット時に他デバイスのセッション・remember-me を確実に失効させるため、
// web グループで AuthenticateSession (alias 'auth.session') を有効化する。
// 各認証済みリクエストで session 保存の password_hash と現在ハッシュを照合し、不一致なら
// 現在デバイスを logout する (guest は no-op)。Auth::logoutOtherDevices() の実効性はこの
// middleware が担保する (Laravel 標準の "Log Out Other Browser Sessions" 構成)。
// Filament panel は独自 middleware stack を持ち web グループを経由しないため二重適用にならない。
$middleware->authenticateSessions();
```

### PHPStan 適合チェック
- [x] `authenticateSessions()` は `Middleware` の公開メソッド（戻り値 `$this`）。型安全
- [x] 新規 import 不要（クラス直接参照なし）

### テスト計画
- [ ] 施策3 の Feature テストで「他デバイスの既存 session が失効」を検証（層1 の実効性）
- [ ] 既存 Feature テスト全体を `composer test`（`--parallel`）で回帰確認

### リスク
- **ブラスト半径大**: 全 web 認証リクエストで AuthenticateSession が走る。guest は no-op、認証済みは
  session に `password_hash_web` を初回保存 → 以降照合、のため通常フローは不変。ただし既存テストで
  「途中でユーザーのパスワードハッシュが変わるのに同一セッションで継続」を期待するものがあれば
  失効しうる。→ 実装時に全 suite green を必須確認（想定では login / 2FA challenge / SSO callback /
  reset / `actingAs` はいずれも guest no-op もしくは終端 hash 保存で無影響。Round 3 レビュー確認済）。
- Filament は独自 stack のため二重適用なし（`AdminPanelProvider` 確認済）。

---

## 施策2: `UpdateUserPassword::update()` で他セッション失効を実装

### 変更箇所
- ファイル: `app/Actions/Fortify/UpdateUserPassword.php`（`update()` にロジック追加 + private helper 追加）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし（void アクション、レスポンス生成なし）
- Inertia Props: なし
- テストファイル: 施策3 で新規カバー

### 現行コード
```php
<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

class UpdateUserPassword implements UpdatesUserPasswords
{
    /**
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::default()],
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();
    }
}
```

### 変更後コード
```php
<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Throwable;
use Webmozart\Assert\Assert;

class UpdateUserPassword implements UpdatesUserPasswords
{
    /**
     * パスワード変更の検証と反映、および他デバイスのセッション・remember-me の失効。
     *
     * 強度の SSOT は PasswordPolicy (Password::defaults で配線済)。
     * 確認入力 (confirmed) は使わない (表示トグル + リセット導線 + SSO で代替)。
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::default()],
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        // 新パスワードを確定 (この後の logoutOtherDevices は保存済みハッシュに対し Hash::check する)。
        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();

        // 現在デバイスを維持しつつ他デバイスを失効させる。logoutOtherDevices は password を
        // 再ハッシュし、現在デバイスの recaller (remember-me) を新ハッシュで再発行 (現在リクエストが
        // recaller を持つ場合のみ) + OtherDeviceLogout イベントを発火する。他デバイスの実失効は
        // web グループの AuthenticateSession による password_hash 照合が担保する (correctness の要)。
        // 渡すのは current_password ではなく保存直後の新 password。
        Auth::logoutOtherDevices($input['password']);

        // database driver の場合、当該 user の他 session 行を即時削除する (best-effort)。
        $this->deleteOtherSessionRecords($user);
    }

    /**
     * 現在の session を除き、当該 user の DB session 行を削除する (session driver=database 時のみ)。
     *
     * correctness は AuthenticateSession が担うため best-effort: 失敗しても report して継続する
     * (パスワード変更自体は成功しているため正常応答を維持する)。
     */
    private function deleteOtherSessionRecords(User $user): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $connection = config('session.connection');
        $table = config('session.table', 'sessions');

        Assert::nullOrString($connection);
        Assert::string($table);

        try {
            DB::connection($connection)
                ->table($table)
                ->where('user_id', $user->getAuthIdentifier())
                ->where('id', '!=', session()->getId())
                ->delete();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
```

### 設計上の要点
- **順序**: `forceFill(...save)` → `logoutOtherDevices` → 行削除。`logoutOtherDevices` は
  `Hash::check($input['password'], $user->getAuthPassword())` を行うため、新ハッシュ確定後に呼ぶ
  必要がある（旧ハッシュのまま呼ぶと新パスワードと不一致で `InvalidArgumentException`）。
- **現在デバイス維持**: `logoutOtherDevices` は現在リクエストが recaller を持つ場合のみ recaller を
  新ハッシュで再発行（`SessionGuard::logoutOtherDevices` L748-750 の条件分岐）。現在 session は
  同一リクエストの AuthenticateSession 終端処理（`storePasswordHashInSession`）が新ハッシュを保存
  するため維持される。
- **行削除の接続**: `session.connection` は未設定時 null（既定接続）を許容 → `Assert::nullOrString`。
  `session.table` は string → `Assert::string`。`DB::connection($connection)` は `?string` を受ける。
- **best-effort**: 削除のみ `try/catch` + `report()`。correctness は層1が担保するため削除失敗で応答を
  失敗させない。transaction は使わない（correctness を DB 原子性に依存しない）。

### PHPStan 適合チェック
- [x] 戻り値の型が明示（`void`）
- [x] `config('session.connection')` / `config('session.table', ...)` は mixed → `Assert::nullOrString`
      / `Assert::string` で string|null / string に narrowing してから使用（widen・ignore なし）
- [x] `$user->getAuthIdentifier()` は `Authenticatable` 契約の公開メソッド（mixed 返しだが where 引数可）
- [x] `session()->getId()` は `Store::getId(): string`（Larastan が helper 戻り型を解決）
- [x] `Auth::logoutOtherDevices(string): ?Authenticatable` は Auth ファサードの `@method` 定義済
- [x] `report(Throwable): void` / `catch (Throwable $e)`
- [x] 配列返却なし（void）。DTO 不要

### テスト計画
施策3 で新規 Feature テスト。既存 `tests/Feature` に `user/password` PUT を叩く既存テストは無い
（`grep` 済）ため新規追加が主。バグ修正のため**再現→修正**の順で、まず「他 session が失効しない」
現状を検証するテストを書いてから本施策を適用する。

### リスク
- `logoutOtherDevices` の force 再ハッシュで user が 2 回保存される（forceFill と合わせ）。機能的に
  問題なし（同一パスワードの別 bcrypt）。負荷は無視できる。
- ガードユーザーが `$user` と異なる異常系（Fortify セルフサービス経路では発生しない）では
  `logoutOtherDevices` は `$this->user()` を対象にするため、$user と乖離する可能性は経路上ない。

---

## 施策3: Feature テスト新規（不変条件の固定）

### 変更箇所
- 新規ファイル: `tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php`（Pest）

### 波及変更
- なし（テスト追加のみ）。`RefreshDatabase` はグローバル適用済。個別 `DatabaseTransactions` 不使用。

### テスト設計

テスト環境の既定 `session.driver` は `array`（`phpunit.xml`）。DB 行削除・database driver 依存の
ケースは各テスト内で `config(['session.driver' => 'database'])` に切替える（`sessions` テーブルは
RefreshDatabase で migrate 済）。新パスワードは PasswordPolicy（min12 + mixedCase + numbers、
uncompromised はユニットテストで無効）を満たす `NewPassword12345` 等を使う。

| # | テスト名（意図） | driver | 主アサーション |
|---|---|---|---|
| (a) | 他 session 行が削除され、別 user の行と現在行は残る | database | 攻撃者行 delete、別 user 行 keep |
| (b) | 現在デバイスの session は維持される | database | 変更後も authenticated、`assertSessionHasNoErrors` |
| (c) | 別デバイスの既存 session はパスワード変更後に失効する | database | device B の再アクセスが login へ redirect（層1） |
| (d) | 別デバイスの古い remember-me (recaller) は失効する | database | device B の recaller 再アクセスが login へ redirect（層1 viaRemember） |
| (e) | driver != database では行削除を skip しエラーにならない | array | 変更成功・例外なし |

#### (a) の骨子
```php
test('パスワード変更で当該ユーザーの他セッション行が削除され、別ユーザーの行は残る', function (): void {
    config(['session.driver' => 'database']);

    $user = User::factory()->create(); // password は factory 既定 'password'
    $other = User::factory()->create();

    DB::table('sessions')->insert([
        sessionRow('attacker-session', $user->id),
        sessionRow('victim-current', $user->id),      // ※現在IDと異なるので削除対象
        sessionRow('other-user-session', $other->id), // 別ユーザーは対象外
    ]);

    $this->actingAs($user)->put('/user/password', [
        'current_password' => 'password',
        'password' => 'NewPassword12345',
    ])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', 'attacker-session')->exists())->toBeFalse();
    expect(DB::table('sessions')->where('id', 'other-user-session')->exists())->toBeTrue();
});

// helper (テストファイル内 or Pest helper):
function sessionRow(string $id, int $userId): array {
    return [
        'id' => $id, 'user_id' => $userId, 'ip_address' => null, 'user_agent' => null,
        'payload' => base64_encode(serialize([])), 'last_activity' => time(),
    ];
}
```

#### (b) の骨子
```php
test('パスワード変更後も現在デバイスはログイン状態を維持する', function (): void {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();

    $this->actingAs($user)->put('/user/password', [
        'current_password' => 'password',
        'password' => 'NewPassword12345',
    ])->assertSessionHasNoErrors();

    // 変更後、同一テストクライアントで保護ルートにアクセスでき、ログアウトされていない
    $this->actingAs($user)->get('/dashboard')->assertSuccessful();
    // (パスワードも実際に更新されている)
    expect(Hash::check('NewPassword12345', $user->fresh()->password))->toBeTrue();
});
```

#### (c) / (d) の骨子（多デバイス・層1 の実効性）
device A（現在）でパスワードを変更し、device B（別セッション / 別 recaller）が失効することを検証する。
`login` エンドポイント経由で device B の本物のセッション / recaller Cookie を取得し、パスワード変更後に
そのクッキーで保護ルートへ再アクセスすると login へ redirect される（AuthenticateSession が
password_hash 不一致で logout）ことを確認する。

```php
test('パスワード変更で別デバイスの既存セッションが失効する', function (): void {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();

    // device B: 実ログインしてセッションクッキーを取得
    $login = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
    $sessionCookie = $login->getCookie(config('session.cookie')); // device B の session cookie

    // device A: 別クライアント状態でパスワード変更 (actingAs は guard を直接立てる)
    $this->flushSession();
    $this->actingAs($user)->put('/user/password', [
        'current_password' => 'password',
        'password' => 'NewPassword12345',
    ])->assertSessionHasNoErrors();

    // device B: 旧セッションクッキーで保護ルート → AuthenticateSession が失効させ login へ
    $this->flushSession();
    $this->withCookie(config('session.cookie'), $sessionCookie->getValue())
        ->get('/dashboard')
        ->assertRedirect('/login');
});
```
> 注: (d) は `login` に `remember` を付け、`getCookie(Auth::guard()->getRecallerName())` で recaller を
> 取得し、パスワード変更後にその recaller のみで再アクセス→ `assertRedirect('/login')` を確認する。
> Cookie の暗号化/複合はテストヘルパ（`withCookie` / `withUnencryptedCookie`）で扱う。実装時に
> device 分離のための session/cookie 取り回しを確定する（本設計は検証意図と機構を固定）。

#### (e) の骨子
```php
test('session driver が database でない場合は行削除をスキップしエラーにならない', function (): void {
    config(['session.driver' => 'array']);
    $user = User::factory()->create();

    $this->actingAs($user)->put('/user/password', [
        'current_password' => 'password',
        'password' => 'NewPassword12345',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('NewPassword12345', $user->fresh()->password))->toBeTrue();
});
```

### PHPStan 適合チェック（テスト）
- [x] Factory 経由でユーザー生成（`User::factory()->create()`）。`Model::create` 手組みなし
- [x] `DB::table(...)->insert(array)` の payload は文字列（serialize+base64）
- [x] 個別 `DatabaseTransactions` 不使用（`RefreshDatabase` グローバル）

### リスク
- (c)/(d) の多デバイス cookie 取り回しはフレームワークの暗号化 cookie を跨ぐため、実装時に
  `login` 応答からの cookie 抽出方式（decrypt 有無）を確定する必要がある。検証意図（層1 が別デバイスを
  失効させる）は固定。もし cookie 取り回しが不安定なら、`AuthenticateSession` の password_hash 照合を
  直接叩く統合テスト（session に旧 `password_hash_web` を仕込み、変更後リクエストで logout される）に
  切り替える代替を許容する。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存 `UpdateUserPassword` と `bootstrap/app.php` への追記が中心で、新規ドメインリソースや広域リファクタを伴わない。単一の worktree で完結し、既存 suite の回帰確認で閉じる |
| 競合リスク | `bootstrap/app.php` の middleware 構成に触れるため、他の middleware 変更施策と同時並行すると競合しうる。単独で先行実装するのが安全 |

## 実装完了条件（DoD）

- [ ] 施策1〜3 を実装
- [ ] 新規 Feature テスト (a)〜(e) が green
- [ ] `composer test`（`--parallel`）全 green（AuthenticateSession 追加の回帰なし）
- [ ] `composer phpstan`（level 10）green
- [ ] `vendor/bin/pint --test` green
- [ ] （フロント変更なしのため `pnpm` 系は影響なし。念のため `pnpm typecheck` は変更なしを確認）

## follow-up（本 PR 完了条件外・別 TODO 推奨）

- パスワードリセット経路（`app/Actions/Fortify/ResetUserPassword.php` / `NewPasswordController`）でも
  変更後に全セッション失効を保証する（未ログイン時フローのため「全破棄」設計。本設計とは別）。
  なお施策1 の AuthenticateSession 配線により、リセット後も旧 session は次リクエストで失効する
  多層防御が副次的に働く。


## 関連する現行コード

### app/Actions/Fortify/UpdateUserPassword.php（現行）
```php
class UpdateUserPassword implements UpdatesUserPasswords
{
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::default()],
        ], [
            'current_password.current_password' => __('The provided password does not match your current password.'),
        ])->validateWithBag('updatePassword');

        $user->forceFill(['password' => Hash::make($input['password'])])->save();
    }
}
```

### bootstrap/app.php（現行の web append。他は本文参照）
```php
$middleware->web(append: [
    HandleInertiaRequests::class,
    SecurityHeaders::class,
    RequireTwoFactorForEnforcedOrganizations::class,
    BlockTwoFactorDisableForEnforcedOrganizations::class,
]);
```

### framework 側の確認済み事実
- `Auth` facade: `@method static ...logoutOtherDevices(string $password)` 定義済（PHPStan 安全）。
- `SessionGuard::logoutOtherDevices` L748-750: `if ($this->recaller() || hasQueued(...))` の条件付きで現在デバイスの recaller を再発行。
- `EloquentUserProvider::retrieveByToken` は remember_token のみ `hash_equals`（recaller の password_hash は AuthenticateSession の viaRemember 分岐で照合）。
- `AuthenticateSession::handle`: guest（`! $request->user()`）は即 next（no-op）。viaRemember 時は recaller の password_hash も照合。
- `Middleware::authenticateSessions()`（L770-775）で web グループに `auth.session` を有効化（framework 一級 API）。
- `config/session.php`: `connection = env('SESSION_CONNECTION')`（未設定 null）, `table = env('SESSION_TABLE','sessions')`。
- Filament `AdminPanelProvider` は独自 middleware stack（web グループ非経由）。

---
