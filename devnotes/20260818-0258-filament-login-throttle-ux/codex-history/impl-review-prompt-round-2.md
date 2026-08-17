# impl-review Round 2

Round 1 の指摘への対応が完了した。対応マトリクスと、テスト側の最新差分 (`tests/` 全体) を示す。
実装本体 (`app/Filament/Auth/Login.php`) と panel 配線 (`app/Providers/Filament/AdminPanelProvider.php`) は
Round 1 から変更していない。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Warning] `assertHasErrors(['key' => 値])` をメッセージの完全一致検査として使っている

- 判断: 対応する
- 根拠: vendor 実装 (`Livewire\Features\SupportValidation\TestsValidation::assertErrorMatchesRuleOrMessage()`) を
  実読すると、連想配列の値は**失敗した規則名と照合し、一致しなければメッセージの部分照合へ落ちる**
  二段構えである。したがって現状も「メッセージを見ている」こと自体は成立しているが、
  (a) 規則名と同名の値を渡すと黙って規則側で緑になる、
  (b) 値に `:` が含まれると `Str::before($value, ':')` で**前半だけの照合に弱まる**
  (現在の ja / en の文言にコロンは無いが、文言の変更や locale の追加で無音のまま弱まる)。
  台帳の保証 (2) 「残り秒数を含む案内」を固定したい検査でこの曖昧さを持つのは不適当である。
- 対応内容: 両ケースとも「key の存在」と「メッセージ」を分け、メッセージは error bag の
  完全一致 (`expect($errors->get($key))->toBe([adminLoginThrottleMessage()])`) で固定した。
  これで「前の試行の理由が残っていない」「案内が 1 本だけ (積み増しでない)」
  「残り秒数を含む正しい文言」が 1 本の期待値になる。
  併せて、なぜ `assertHasErrors(['key' => 値])` を使わないのかをテスト内のコメントに残した。

## [Suggestion] 宣言元検査が固定するのは `authenticate()` だけである

- 判断: 対応する (説明の是正として)
- 根拠: 指摘のとおりで、独自クラス側が `rateLimit()` / `getRateLimitKey()` を上書きして
  上限を骨抜きにする形は、本文走査と宣言元検査では拾えない。AGENTS.md の文化
  (「保証範囲を誇張しない」) に照らして、検査の説明が実力を超えている状態は残さない。
- 対応内容: `ThrottleExemptionPremiseTest` のコメントへ保証範囲の限界と、
  その分を担う振る舞いテスト (`AdminLoginThrottleDisplayTest`) を明記した。
  検査そのものは増やさない (今回の差分に該当する上書きは無く、
  振る舞いテストが上限到達を実際に踏んでいるため。思考原則 2)。

## その他 (実装本体・panel 配線・免除前提の起点変更)

- 判定は「妥当」。変更なし。


## 補足 (Warning への対応の根拠として実読した vendor 実装)

`vendor/livewire/livewire/src/Features/SupportValidation/TestsValidation.php`:

```php
protected function assertErrorMatchesRuleOrMessage($rules, $messages, $key, $ruleOrMessage)
{
    if (Str::contains($ruleOrMessage, ':')) {
        $ruleOrMessage = Str::before($ruleOrMessage, ':');
    }

    if (in_array($ruleOrMessage, $rules)) {
        PHPUnit::assertTrue(true);

        return;
    }

    PHPUnit::assertContains($ruleOrMessage, $messages, "...");
}
```

## 検証結果 (対応後)

- `vendor/bin/pint --test`: passed
- `composer test -- tests/Feature/Filament/AdminLoginThrottleDisplayTest.php`: 4 passed (54 assertions)
- `composer test -- tests/Feature/Security/ThrottleExemptionPremiseTest.php`: 24 passed
- 対応前に実行済みで、対応がテストのみのため再実行不要と判断したもの:
  `composer phpstan` (OK) / `composer test` 全体 (5772 passed) /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` (2007 passed) / `pnpm build` /
  `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106 passed)
  ※ 最終コミット前に `composer test` 全体を再実行する

## テスト側の最新差分 (git diff HEAD -- tests/)

```diff
diff --git a/tests/Feature/Filament/AdminLoginThrottleDisplayTest.php b/tests/Feature/Filament/AdminLoginThrottleDisplayTest.php
new file mode 100644
index 0000000..11f901b
--- /dev/null
+++ b/tests/Feature/Filament/AdminLoginThrottleDisplayTest.php
@@ -0,0 +1,165 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Filament\Auth\Login;
+use App\Models\AdminUser;
+use Filament\Facades\Filament;
+use Filament\Notifications\Notification;
+use Illuminate\Support\Facades\RateLimiter;
+use Illuminate\Support\Facades\Route;
+use Livewire\Livewire;
+
+/*
+|--------------------------------------------------------------------------
+| 管理画面ログインの上限到達時の表示 (裁定 AG-017b)
+|--------------------------------------------------------------------------
+|
+| vendor (Filament) の Login は上限に達すると通知を出して早期 return するだけで、
+| Livewire は入力エラーを次の要求へ持ち越す。結果、上限に達した画面には
+| 直前の試行の入力エラー (「認証に失敗しました。」) が残り、実態と食い違う理由を
+| 表示し続ける。
+|
+| 台帳 (機能 filament-login-throttle-display) が求める保証は 2 つで、
+| 本テストはその両方を、通常の上限 (authenticate 冒頭) と
+| 多要素チャレンジ専用の上限の**両方の経路**で固定する:
+|   (1) 前の要求で付いた入力エラーを画面に残さないこと
+|   (2) 再開までの残り秒数を含む案内を入力欄の位置に示すこと
+|
+| 振る舞いの検査は、独自クラスを名指しせず panel に配線されたクラスを実行時に解決して行う
+| (実装前は vendor の Login に対して不具合が再現し、実装後は同じ検査が独自クラスに対して
+|  緑になる = 「意図した赤」を観測できる)。配線そのものは最後の検査が別途固定する
+| (`Login::class` は名前を字句として解決するだけでクラスの読み込みを起こさないため、
+|  実装前でも「配線されていない」という赤として観測できる)。
+|
+*/
+
+beforeEach(function (): void {
+    // panel 解決 (Filament::auth() 等) を admin panel に固定する
+    Filament::setCurrentPanel('admin');
+    // 上限到達の「あと何秒」を決定的にする (減衰は 60 秒)
+    $this->freezeTime();
+});
+
+/** panel に配線されているログインページのクラス名を返す。 */
+function adminLoginPageClass(): string
+{
+    $loginPage = Filament::getPanel('admin')->getLoginRouteAction();
+
+    expect($loginPage)->toBeString();
+    expect(class_exists((string) $loginPage))->toBeTrue();
+
+    return (string) $loginPage;
+}
+
+/** 上限到達時に入力欄へ出す案内 (残り秒数入り)。 */
+function adminLoginThrottleMessage(int $seconds = 60): string
+{
+    $message = __('auth.throttle', ['seconds' => $seconds]);
+
+    expect($message)->toBeString();
+
+    return (string) $message;
+}
+
+test('通常の上限に達すると、前の試行の入力エラーが残り秒数入りの案内へ差し替わる', function (): void {
+    AdminUser::factory()->create(['email' => 'admin@example.com']);
+
+    $component = Livewire::test(adminLoginPageClass())
+        ->fillForm(['email' => 'admin@example.com', 'password' => 'wrong-password']);
+
+    // vendor の上限は 5 回。5 回目までは従来どおり認証失敗の入力エラーが出る
+    foreach (range(1, 5) as $ignored) {
+        $component->call('authenticate')->assertHasErrors(['data.email']);
+    }
+
+    // 6 回目は上限到達 = 前の試行の入力エラーを捨て、残り秒数の案内へ差し替える
+    $component->call('authenticate')->assertHasErrors(['data.email']);
+
+    $errors = $component->errors();
+
+    // メッセージは error bag の完全一致で固定する
+    // (`assertHasErrors(['key' => 値])` は失敗した規則名とも照合する形なので、
+    //  「残り秒数入りの案内そのもの」を固定したいときは使わない)。
+    // これ 1 本で (1) 前の試行の理由 (認証に失敗しました。) が残っていないこと・
+    // 案内が積み増しではなく差し替えであること・(2) 残り秒数を含むことを同時に固定する
+    expect($errors->get('data.email'))->toBe([adminLoginThrottleMessage()]);
+    expect(array_keys($errors->toArray()))->toBe(['data.email']);
+
+    Notification::assertNotified(
+        __('filament-panels::auth/pages/login.notifications.throttled.title', [
+            'seconds' => 60,
+            'minutes' => 1,
+        ]),
+    );
+});
+
+test('上限に達する前は従来どおり認証失敗の入力エラーが出る (消しすぎの検出)', function (): void {
+    AdminUser::factory()->create(['email' => 'admin@example.com']);
+
+    $component = Livewire::test(adminLoginPageClass())
+        ->fillForm(['email' => 'admin@example.com', 'password' => 'wrong-password'])
+        ->call('authenticate')
+        ->assertHasErrors(['data.email']);
+
+    expect($component->errors()->get('data.email'))
+        ->toContain(__('filament-panels::auth/pages/login.messages.failed'));
+});
+
+test('多要素チャレンジ専用の上限でも、持ち越しエラーが案内へ差し替わりチャレンジ状態は保たれる', function (): void {
+    $admin = AdminUser::factory()->withMfa()->create(['email' => 'admin@example.com']);
+
+    // 1 回目: 資格情報は正しいので多要素チャレンジへ進む (通常の計上 1 回)
+    $component = Livewire::test(adminLoginPageClass())
+        ->fillForm(['email' => 'admin@example.com', 'password' => 'password'])
+        ->call('authenticate');
+
+    expect($component->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();
+
+    // 2 回目: 確認コード未入力で送る = 確認コード欄に入力エラーが立つ (通常の計上 2 回)
+    // (コードを詐称すると偶然正しい TOTP になりうるため、未入力で確実に落とす)
+    $component->call('authenticate')->assertHasErrors(['data.multiFactor.app.code']);
+
+    // 通常側は上限未満のまま、多要素チャレンジ専用の計数だけを上限まで積む
+    // (鍵は vendor と同じく認証識別子で組み立てる。主キーとは限らない)
+    $challengeKey = "filament-multi-factor-challenge:{$admin->getAuthIdentifier()}";
+    while (! RateLimiter::tooManyAttempts($challengeKey, maxAttempts: 5)) {
+        RateLimiter::hit($challengeKey);
+    }
+
+    // 3 回目: 多要素チャレンジ専用の上限に達する経路。
+    // 持ち越しエラーは案内へ差し替わり、チャレンジ表示と入力値は保たれる
+    $component->call('authenticate')->assertHasErrors(['data.multiFactor.app.code']);
+
+    $errors = $component->errors();
+
+    expect($errors->get('data.multiFactor.app.code'))->toBe([adminLoginThrottleMessage()]);
+    expect(array_keys($errors->toArray()))->toBe(['data.multiFactor.app.code']);
+
+    expect($component->get('userUndertakingMultiFactorAuthentication'))->not->toBeNull();
+    expect($component->get('data.email'))->toBe('admin@example.com');
+
+    Notification::assertNotified(
+        __('filament-panels::auth/pages/login.notifications.throttled.title', [
+            'seconds' => 60,
+            'minutes' => 1,
+        ]),
+    );
+});
+
+test('panel のログインページは独自クラスで、ページとして自動発見されていない', function (): void {
+    expect(Filament::getPanel('admin')->getLoginRouteAction())
+        ->toBe(Login::class);
+
+    // 独自ログインページを app/Filament/Pages/ 配下に置くと自動発見が
+    // 通常ページとして登録し、ここに login を含む route が現れる
+    // (置き場所の誤りを検出する。正当なページの追加では赤くしない)
+    foreach (Route::getRoutes() as $route) {
+        $name = $route->getName();
+        if ($name === null || ! str_starts_with($name, 'filament.admin.pages.')) {
+            continue;
+        }
+
+        expect($name)->not->toContain('login');
+    }
+});
diff --git a/tests/Feature/Security/ThrottleExemptionPremiseTest.php b/tests/Feature/Security/ThrottleExemptionPremiseTest.php
index 1dc006d..fc88326 100644
--- a/tests/Feature/Security/ThrottleExemptionPremiseTest.php
+++ b/tests/Feature/Security/ThrottleExemptionPremiseTest.php
@@ -10,6 +10,7 @@
 use Filament\Auth\MultiFactor\App\Actions\SetUpAppAuthenticationAction;
 use Filament\Auth\Pages\EditProfile as FilamentEditProfile;
 use Filament\Auth\Pages\Login as FilamentLogin;
+use Filament\Facades\Filament;
 use GuzzleHttp\Handler\MockHandler;
 use GuzzleHttp\HandlerStack;
 use GuzzleHttp\Middleware;
@@ -172,8 +173,16 @@ function throttlePremiseMethodRateLimits(string $class, string $method): bool
 test('default-livewire.update の前提: Filament の credential 操作が component 内で rateLimit を掛けている', function (): void {
     // panel が公開する credential 面 (login / profile / MFA 管理) の**実行メソッド**に
     // rate limit があること。1 つでも消えたら route 側の防御を設計し直す必要がある。
+    //
+    // login は panel が実際に使うクラスを実行時に解決して対象にする (独自ログインページへ
+    // 差し替えても前提が保たれていることを確かめるため。vendor クラス固定だと、独自クラス側で
+    // 上限を外しても緑のままになる)。
+    $loginPage = Filament::getPanel('admin')->getLoginRouteAction();
+    expect($loginPage)->toBeString();
+    expect(class_exists((string) $loginPage))->toBeTrue();
+
     $targets = [
-        [FilamentLogin::class, 'authenticate'],
+        [(string) $loginPage, 'authenticate'],
         [FilamentEditProfile::class, 'save'],
         [SetUpAppAuthenticationAction::class, 'make'],
         [DisableAppAuthenticationAction::class, 'make'],
@@ -187,6 +196,20 @@ function throttlePremiseMethodRateLimits(string $class, string $method): bool
         );
     }
 
+    // ログインページが独自クラスでも、認証処理そのものは vendor の宣言のままであること。
+    // 上書きされた瞬間に赤くなる = 上限値 (5) と判定順序の複写を検出する。
+    // **保証範囲を誇張しない**: 見ているのは authenticate() の宣言元と本文だけであり、
+    // 独自クラス側が rateLimit() / getRateLimitKey() 等を上書きして上限を骨抜きにする形は
+    // 本検査では拾えない (その手の改変は上限到達の振る舞いを固定する
+    // tests/Feature/Filament/AdminLoginThrottleDisplayTest.php が担う)。
+    expect((new ReflectionMethod((string) $loginPage, 'authenticate'))->getDeclaringClass()->getName())
+        ->toBe(
+            FilamentLogin::class,
+            'ログインページが authenticate() を上書きしています。'
+            .'上限値 (5) と判定順序が vendor から複写されていないか確認し、'
+            .'複写するなら default-livewire.update の免除根拠を設計し直すこと。',
+        );
+
     // negative control: 走査器が「どのメソッドでも true」になっていないこと
     // (常に true を返す検査は deny-by-default を無意味にする)
     expect(throttlePremiseMethodRateLimits(FilamentLogin::class, 'mount'))->toBeFalse(

```

上記で残る指摘があれば挙げ、無ければ全体判定を明示せよ。
