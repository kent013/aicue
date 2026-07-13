【アプリの使命 (North Star) — AGENTS.md より】
**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(migrate:fresh 等)をエージェント判断で実行すること
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(app/Prompts/ の factory 経由のみ)
6. prompt 文字列のコード直書き(resources/prompts/*.yaml に置く)
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ(Laravel/Fortify 標準)。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: レビュアーの役割

あなたは Laravel + Svelte アプリのコードレビュアーである。TODO T024「パスワード変更時に他セッション失効」(bug-hunt F-H4 authz_bypass の修正) の実装差分をレビューせよ。

レビュー観点:
- **設計との一致性**: 添付の詳細設計書のとおり実装されているか(3層設計: 層1 AuthenticateSession 有効化 / 層2 logoutOtherDevices / 層3 best-effort 行削除)
- **正確性**: パスワード変更時に他デバイスの session/remember-me を確実に失効させ、かつ現在デバイスを維持できるか。順序(save→logoutOtherDevices→行削除)は正しいか。エッジケース(console/queue 文脈、session 未初期化、driver 非database)の扱いは妥当か
- **PHPStan L10 適合性**: 型 narrowing(Assert)・widen/ignore なし
- **DTO/JsonResource パターン**: void アクションのため該当なしだが response()->json() 直書きがないか
- **テスト網羅性**: (a)〜(e) が不変条件を正しく固定しているか。テストが実装を実際に検証しているか(トートロジーでないか)。特に (c)(d) の correctness 証明が妥当か
- **セキュリティ**: 認証セッションライフサイクルの穴(現在デバイス誤失効、他デバイス失効漏れ、best-effort 削除の情報漏洩)がないか
- **Atomic Design / DESIGN.md**: フロント変更なしのため該当なし

出力形式: ファイルごとに判定、Critical/Warning/Suggestion に分類、最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示せよ。

---

## user

### 詳細設計書
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

        // session 未初期化文脈 (console/queue 等) では現在ID除外の前提が崩れるため何もしない。
        if (! session()->isStarted()) {
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
- **isStarted ガード**: `deleteOtherSessionRecords` は driver ガードに加え `session()->isStarted()`
  でガードする。session 未初期化文脈で `session()->getId()` に依存した削除条件が崩れるのを防ぐ。
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
- [x] `session()->getId()` は `Store::getId(): string` / `session()->isStarted(): bool`（Larastan が helper 戻り型を解決）
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

テストは 2 つの責務に分けて設計する（Round 1〜2 レビュー対応）:
- **(a)(b)(e) = 施策2 のオーケストレーション**（実 PUT `/user/password` を叩く。単一クライアント）。
- **(c)(d) = 施策1（AuthenticateSession の password_hash 照合 = correctness の要）**。cookie replay を
  廃し、**`withSession(['password_hash_web' => $oldHash])` による決定的統合テスト**で照合ロジックを
  直接検証する（Round 2 Critical 対応。`$this->flush()` 等の非実在 API・暗号化 cookie 取り回しに
  依存しない）。end-to-end（実 PUT が別デバイスを失効させる）は「施策2 が hash を変える → 施策1 が
  旧 hash セッションを失効させる」の合成として (a)+(b)+(c) がカバーする。

| # | テスト名（意図） | 検証対象 | driver | 主アサーション |
|---|---|---|---|---|
| (a) | 当該 user の他 session 行が削除され、別 user の行は残る | 施策2 | database | attacker 行 delete、別 user 行 keep（現在行残存は (b) で担保） |
| (b) | 現在デバイスの session は維持される | 施策2 | database | 変更後も authenticated、`assertSessionHasNoErrors` |
| (c) | 旧 password_hash_web の既存/復活セッションは次リクエストで失効 | 施策1 | array/database 非依存 | `assertRedirect('/login')` |
| (d) | recaller の viaRemember 分岐が旧 hash を失効させる | 施策1 | database | 実 recaller のみで再アクセス→ `assertRedirect('/login')` |
| (e) | driver != database では行削除を skip しエラーにならない | 施策2 | array | 変更成功・例外なし |

#### (a) の骨子（施策2 / 他 user 行削除・別 user 行残存）
現在 session id は実行中に再生成されうるため id 一致に依存しない**堅牢な不変条件**のみを検証する:
「当該 user の（現在 id 以外の）行は削除され、別 user の行は残る」。現在行の残存は (b) で担保する。
```php
test('パスワード変更で当該ユーザーの他セッション行が削除され、別ユーザーの行は残る', function (): void {
    config(['session.driver' => 'database']);

    $user = User::factory()->create();  // password は factory 既定 'password'
    $other = User::factory()->create();

    DB::table('sessions')->insert([
        sessionRow('attacker-session', $user->id),    // 攻撃者行 (現在 id ではない) → 削除すべき
        sessionRow('other-user-session', $other->id), // 別ユーザー → 対象外・残存すべき
    ]);

    $this->actingAs($user)->put('/user/password', [
        'current_password' => 'password',
        'password' => 'NewPassword12345',
    ])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', 'attacker-session')->exists())->toBeFalse();
    expect(DB::table('sessions')->where('id', 'other-user-session')->exists())->toBeTrue();
});

// helper (テストファイル内):
function sessionRow(string $id, int $userId): array {
    return [
        'id' => $id, 'user_id' => $userId, 'ip_address' => null, 'user_agent' => null,
        'payload' => base64_encode(serialize([])), 'last_activity' => time(),
    ];
}
```

#### (b) の骨子（施策2 / 現在デバイス維持）
```php
test('パスワード変更後も現在デバイスはログイン状態を維持する', function (): void {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();

    $this->actingAs($user)->put('/user/password', [
        'current_password' => 'password',
        'password' => 'NewPassword12345',
    ])->assertSessionHasNoErrors();

    // 変更後は再 actingAs せず、確立済みセッションのまま保護ルートに到達できること＝維持を検証。
    // (再 actingAs すると新規認証になり「維持」を検証できないため使わない。Round 3 レビュー対応)
    $this->get('/dashboard')->assertSuccessful();
    expect(Hash::check('NewPassword12345', $user->fresh()->password))->toBeTrue();
});
```

#### (c) の骨子（施策1 / 旧 hash セッションの失効・決定的）
cookie を使わず `withSession` で「旧 `password_hash_web` を持つセッション」を直接構築する。actingAs が
使う guard user が**新ハッシュ**を持ち、session の `password_hash_web` が**旧ハッシュ**であることが要点
（両者不一致で AuthenticateSession が logout する）。
```php
test('旧 password_hash を持つ既存セッションはハッシュ変更後の次リクエストで失効する', function (): void {
    $user = User::factory()->create();
    $oldHash = $user->getAuthPassword(); // 変更前ハッシュ

    // パスワード（ハッシュ）を変更。actingAs が使う in-memory $user が新ハッシュを持つ。
    $user->forceFill(['password' => Hash::make('NewPassword12345')])->save();

    // 旧 password_hash_web を持つ既存/復活セッションを模して保護ルートへ
    $this->actingAs($user)
        ->withSession(['password_hash_web' => $oldHash])
        ->get('/dashboard')
        ->assertRedirect('/login'); // AuthenticateSession が hash 不一致で logout
});
```
> `AuthenticateSession::validatePasswordHash` は `hashPasswordForCookie($current)` と raw `$current` の
> 両形式を stored 値と `hash_equals` するフォールバックを持つ。stored に旧 raw hash を入れると新 hash と
> どちらの形式でも一致しないため確実に logout する。この (c) が「並行書き戻しで復活した session 行」
> （= 旧 hash を保持）の失効も同時に固定する（層1 correctness の中核証明）。

#### (d) の骨子（施策1 / recaller の viaRemember 分岐を実 cookie で検証）
recaller の viaRemember 分岐は「recaller cookie 第3セグメントの password_hash と現在 hash の照合」という
**session-hash 分岐とは別の不変条件**（Round 3 レビュー対応）。実 recaller cookie を用いた決定的統合
テストで検証する。device 分離は「device A = out-of-band DB ハッシュ変更（guard 非依存）／device B =
recaller cookie のみ提示」で行う。session cookie は明示送信しない（Laravel テストは応答 cookie を自動
再送しないため recaller 経路に落ちる）。
```php
test('別デバイスの古い remember-me (recaller) はハッシュ変更後に失効する', function (): void {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();

    // recaller cookie 名を PHPStan L10 安全に取得
    $guard = Auth::guard('web');
    Assert::isInstanceOf($guard, \Illuminate\Auth\SessionGuard::class);
    $recallerName = $guard->getRecallerName();

    // device B: remember 付き実ログイン → recaller cookie を暗号化生値 (decrypt=false) で capture
    $login = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => 'on',
    ]);
    // 復号済み平文 recaller を取得 (decrypt 既定 true)。withCookie はテスト側で再暗号化し、
    // アプリの EncryptCookies が復号する組合せ (source: MakesHttpRequests::prepareCookiesForRequest)。
    $recaller = $login->getCookie($recallerName);
    Assert::isInstanceOf($recaller, \Symfony\Component\HttpFoundation\Cookie::class);

    // device A: out-of-band にハッシュ変更 (recaller の password_hash は旧のまま = viaRemember で不一致)
    User::query()->whereKey($user->id)->update(['password' => Hash::make('NewPassword12345')]);

    // 既存の guard/session を破棄して recaller 単独認証を成立させる (Round 4 レビュー対応)
    $this->flushSession();
    Auth::forgetGuards();

    // device B: session cookie を送らず recaller (平文→withCookie が再暗号化) のみ提示
    // → viaRemember 経路で recaller の旧 password_hash と新 hash が不一致 → 失効 → login へ
    $this->withCookie($recallerName, $recaller->getValue())
        ->get('/dashboard')
        ->assertRedirect('/login');
});
```
> **必須 fallback（Round 4 で Codex 合意。削除オプションは撤回）**: 実 recaller 統合テストが実行環境で
> 不安定な場合は削除せず、**`AuthenticateSession` の viaRemember 分岐を制御する単体テスト**に置換する
> （`Request` に recaller cookie（第3セグメント=旧 hash）を仕込み、guard の `viaRemember()`=true と
> user（新 hash）を与えて middleware を通し、`AuthenticationException`/logout を確認）。remember-me 失効の
> セキュリティ不変条件は必ずいずれかのテストで固定する（未検証化しない）。
> 補足: `phpstan.neon` の解析対象は `app/config/database/routes` のみで `tests` は対象外。`Auth::forgetGuards()`
> は Auth ファサード `@method`、`withUnencryptedCookie` は MakesHttpRequests に実在。Assert ガードは
> ランタイム null 安全のため残す。

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
- [x] (d) の guard/cookie は `Assert::isInstanceOf`（`SessionGuard` / `Symfony ... Cookie`）で narrowing
      してから `getRecallerName()` / `getValue()` を呼ぶ（`Auth::guard()` は `Guard|StatefulGuard` 返しで
      `getRecallerName()` を持たないため必須）

### リスク
- (c) は cookie を使わず **`withSession(['password_hash_web' => $oldHash])`** の決定的統合テスト（要点は
  「guard user = 新ハッシュ / session = 旧ハッシュ」の不一致）。(d) は viaRemember 固有分岐を検証するため
  **実 recaller cookie 統合テスト**（Assert ガードで PHPStan L10 安全）。実 cookie が環境依存で不安定な
  場合は上記 (d) の fallback（AuthenticateSession 単体テスト or DoD 明記）を採る。検証意図（層1 が
  旧ハッシュのセッション/recaller を失効させる）は固定。

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

### 施策1（AuthenticateSession 有効化）の個別回帰チェックリスト（ブラスト半径管理）

グローバル有効化で抜けやすい認証フローを個別に green 確認する（既存テストで担保 or 追加）:

- [ ] `login`（Fortify `AuthenticationTest`）: 通常ログイン後に保護ページへ到達できる
- [ ] `two-factor-challenge`: 2FA challenge 通過後に保護ページへ到達できる（challenge 中は guest 相当で no-op）
- [ ] `user/confirm-password`（`RecentAuthTest` / password.confirm 経路）: 再認証確認後に元操作へ復帰できる
- [ ] SSO callback（`SocialAuthTest`）: SSO ログイン成立後の次リクエストで保護ページへ到達できる
- [ ] password reset（`NewPasswordController`）: リセット完了・ログイン後に保護ページへ到達できる
- [ ] `actingAs` 系既存テスト: hash 初回保存で通常どおり通ること（suite 全体で確認）

## follow-up（本 PR 完了条件外・別 TODO 推奨）

- パスワードリセット経路（`app/Actions/Fortify/ResetUserPassword.php` / `NewPasswordController`）でも
  変更後に全セッション失効を保証する（未ログイン時フローのため「全破棄」設計。本設計とは別）。
  なお施策1 の AuthenticateSession 配線により、リセット後も旧 session は次リクエストで失効する
  多層防御が副次的に働く。


### 実装差分 (git diff)
```diff
diff --git a/app/Actions/Fortify/UpdateUserPassword.php b/app/Actions/Fortify/UpdateUserPassword.php
index 8c8ed51..0dad1ad 100644
--- a/app/Actions/Fortify/UpdateUserPassword.php
+++ b/app/Actions/Fortify/UpdateUserPassword.php
@@ -5,16 +5,20 @@
 namespace App\Actions\Fortify;
 
 use App\Models\User;
+use Illuminate\Support\Facades\Auth;
+use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Hash;
 use Illuminate\Support\Facades\Validator;
 use Illuminate\Validation\Rules\Password;
 use Illuminate\Validation\ValidationException;
 use Laravel\Fortify\Contracts\UpdatesUserPasswords;
+use Throwable;
+use Webmozart\Assert\Assert;
 
 class UpdateUserPassword implements UpdatesUserPasswords
 {
     /**
-     * パスワード変更の検証と反映。
+     * パスワード変更の検証と反映、および他デバイスのセッション・remember-me の失効。
      *
      * 強度の SSOT は PasswordPolicy (Password::defaults で配線済)。
      * 確認入力 (confirmed) は使わない (表示トグル + リセット導線 + SSO で代替)。
@@ -32,8 +36,53 @@ public function update(User $user, array $input): void
             'current_password.current_password' => __('The provided password does not match your current password.'),
         ])->validateWithBag('updatePassword');
 
+        // 新パスワードを確定 (この後の logoutOtherDevices は保存済みハッシュに対し Hash::check する)。
         $user->forceFill([
             'password' => Hash::make($input['password']),
         ])->save();
+
+        // 現在デバイスを維持しつつ他デバイスを失効させる。logoutOtherDevices は password を
+        // 再ハッシュし、現在デバイスの recaller (remember-me) を新ハッシュで再発行 (現在リクエストが
+        // recaller を持つ場合のみ) + OtherDeviceLogout イベントを発火する。他デバイスの実失効は
+        // web グループの AuthenticateSession による password_hash 照合が担保する (correctness の要)。
+        // 渡すのは current_password ではなく保存直後の新 password。
+        Auth::logoutOtherDevices($input['password']);
+
+        // database driver の場合、当該 user の他 session 行を即時削除する (best-effort)。
+        $this->deleteOtherSessionRecords($user);
+    }
+
+    /**
+     * 現在の session を除き、当該 user の DB session 行を削除する (session driver=database 時のみ)。
+     *
+     * correctness は AuthenticateSession が担うため best-effort: 失敗しても report して継続する
+     * (パスワード変更自体は成功しているため正常応答を維持する)。
+     */
+    private function deleteOtherSessionRecords(User $user): void
+    {
+        if (config('session.driver') !== 'database') {
+            return;
+        }
+
+        // session 未初期化文脈 (console/queue 等) では現在ID除外の前提が崩れるため何もしない。
+        if (! session()->isStarted()) {
+            return;
+        }
+
+        $connection = config('session.connection');
+        $table = config('session.table', 'sessions');
+
+        Assert::nullOrString($connection);
+        Assert::string($table);
+
+        try {
+            DB::connection($connection)
+                ->table($table)
+                ->where('user_id', $user->getAuthIdentifier())
+                ->where('id', '!=', session()->getId())
+                ->delete();
+        } catch (Throwable $e) {
+            report($e);
+        }
     }
 }
diff --git a/bootstrap/app.php b/bootstrap/app.php
index 383b176..c84dfa9 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -88,6 +88,14 @@
             BlockTwoFactorDisableForEnforcedOrganizations::class,
         ]);
 
+        // パスワード変更/リセット時に他デバイスのセッション・remember-me を確実に失効させるため、
+        // web グループで AuthenticateSession (alias 'auth.session') を有効化する。
+        // 各認証済みリクエストで session 保存の password_hash と現在ハッシュを照合し、不一致なら
+        // 現在デバイスを logout する (guest は no-op)。Auth::logoutOtherDevices() の実効性はこの
+        // middleware が担保する (Laravel 標準の "Log Out Other Browser Sessions" 構成)。
+        // Filament panel は独自 middleware stack を持ち web グループを経由しないため二重適用にならない。
+        $middleware->authenticateSessions();
+
         // REST API v1 / MCP の middleware alias (routes/api.php・routes/ai.php で使う)。
         // API キー認証は auth guard ('auth:api-key') に置換済みのため alias なし。
         // recent-auth は web の機微操作 route 用 (generic step-up 再認証)。
diff --git a/tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php b/tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php
new file mode 100644
index 0000000..dac594e
--- /dev/null
+++ b/tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php
@@ -0,0 +1,135 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\User;
+use Illuminate\Auth\SessionGuard;
+use Illuminate\Support\Facades\Auth;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Hash;
+use Symfony\Component\HttpFoundation\Cookie;
+use Webmozart\Assert\Assert;
+
+/*
+ * bug-hunt F-H4 (authz_bypass) の不変条件を固定する。
+ * セルフサービスのパスワード変更 (user-password.update → UpdateUserPassword) が
+ * 他デバイスの session / remember-me を確実に失効させ、かつ現在デバイスを維持することを検証する。
+ *
+ * (a)(b)(e) = 施策2 のオーケストレーション (実 PUT /user/password)。
+ * (c)(d)     = 施策1 (AuthenticateSession の password_hash 照合 = correctness の要)。
+ */
+
+/**
+ * database driver 用の session 行を組み立てる (payload は serialize+base64 の文字列)。
+ *
+ * @return array<string, mixed>
+ */
+function sessionRow(string $id, int $userId): array
+{
+    return [
+        'id' => $id,
+        'user_id' => $userId,
+        'ip_address' => null,
+        'user_agent' => null,
+        'payload' => base64_encode(serialize([])),
+        'last_activity' => time(),
+    ];
+}
+
+// (a) 施策2 / 他 user 行削除・別 user 行残存
+test('パスワード変更で当該ユーザーの他セッション行が削除され、別ユーザーの行は残る', function (): void {
+    config(['session.driver' => 'database']);
+
+    $user = User::factory()->create();  // password は factory 既定 'password'
+    $other = User::factory()->create();
+
+    DB::table('sessions')->insert([
+        sessionRow('attacker-session', $user->id),    // 攻撃者行 (現在 id ではない) → 削除すべき
+        sessionRow('other-user-session', $other->id), // 別ユーザー → 対象外・残存すべき
+    ]);
+
+    $this->actingAs($user)->put('/user/password', [
+        'current_password' => 'password',
+        'password' => 'NewPassword12345',
+    ])->assertSessionHasNoErrors();
+
+    expect(DB::table('sessions')->where('id', 'attacker-session')->exists())->toBeFalse();
+    expect(DB::table('sessions')->where('id', 'other-user-session')->exists())->toBeTrue();
+});
+
+// (b) 施策2 / 現在デバイス維持
+test('パスワード変更後も現在デバイスはログイン状態を維持する', function (): void {
+    config(['session.driver' => 'database']);
+    $user = User::factory()->create();
+
+    $this->actingAs($user)->put('/user/password', [
+        'current_password' => 'password',
+        'password' => 'NewPassword12345',
+    ])->assertSessionHasNoErrors();
+
+    // 変更後は再 actingAs せず、確立済みセッションのまま保護ルートに到達できること＝維持を検証。
+    // (再 actingAs すると新規認証になり「維持」を検証できないため使わない)
+    $this->get('/dashboard')->assertSuccessful();
+    expect(Hash::check('NewPassword12345', $user->fresh()->password))->toBeTrue();
+});
+
+// (c) 施策1 / 旧 hash セッションの失効 (決定的)
+test('旧 password_hash を持つ既存セッションはハッシュ変更後の次リクエストで失効する', function (): void {
+    $user = User::factory()->create();
+    $oldHash = $user->getAuthPassword(); // 変更前ハッシュ
+
+    // パスワード（ハッシュ）を変更。actingAs が使う in-memory $user が新ハッシュを持つ。
+    $user->forceFill(['password' => Hash::make('NewPassword12345')])->save();
+
+    // 旧 password_hash_web を持つ既存/復活セッションを模して保護ルートへ
+    $this->actingAs($user)
+        ->withSession(['password_hash_web' => $oldHash])
+        ->get('/dashboard')
+        ->assertRedirect('/login'); // AuthenticateSession が hash 不一致で logout
+});
+
+// (d) 施策1 / recaller の viaRemember 分岐を実 cookie で検証
+test('別デバイスの古い remember-me (recaller) はハッシュ変更後に失効する', function (): void {
+    config(['session.driver' => 'database']);
+    $user = User::factory()->create();
+
+    // recaller cookie 名を PHPStan L10 安全に取得
+    $guard = Auth::guard('web');
+    Assert::isInstanceOf($guard, SessionGuard::class);
+    $recallerName = $guard->getRecallerName();
+
+    // device B: remember 付き実ログイン → recaller cookie を capture (decrypt 既定 true = 平文)
+    $login = $this->post('/login', [
+        'email' => $user->email,
+        'password' => 'password',
+        'remember' => 'on',
+    ]);
+    $recaller = $login->getCookie($recallerName);
+    Assert::isInstanceOf($recaller, Cookie::class);
+
+    // device A: out-of-band にハッシュ変更 (recaller の password_hash は旧のまま = viaRemember で不一致)
+    User::query()->whereKey($user->id)->update(['password' => Hash::make('NewPassword12345')]);
+
+    // 既存の guard/session を破棄して recaller 単独認証を成立させる
+    $this->flushSession();
+    Auth::forgetGuards();
+
+    // device B: session cookie を送らず recaller (平文→withCookie が再暗号化) のみ提示
+    // → viaRemember 経路で recaller の旧 password_hash と新 hash が不一致 → 失効 → login へ
+    $this->withCookie($recallerName, $recaller->getValue())
+        ->get('/dashboard')
+        ->assertRedirect('/login');
+});
+
+// (e) 施策2 / driver != database では行削除を skip
+test('session driver が database でない場合は行削除をスキップしエラーにならない', function (): void {
+    config(['session.driver' => 'array']);
+    $user = User::factory()->create();
+
+    $this->actingAs($user)->put('/user/password', [
+        'current_password' => 'password',
+        'password' => 'NewPassword12345',
+    ])->assertSessionHasNoErrors();
+
+    expect(Hash::check('NewPassword12345', $user->fresh()->password))->toBeTrue();
+});

```

### テスト結果
- 新規テスト (PasswordUpdateSessionInvalidationTest): 5 passed, 12 assertions
- 全 suite: composer test (--parallel) → 1564 passed / 2 skipped / 0 failed
- composer phpstan (level 10): No errors
- vendor/bin/pint --test: passed
- pnpm lint / typecheck / test / build: 全 green (フロント変更なし)
