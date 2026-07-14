# 詳細設計レビュー Round 5

Round 4 の (d) 3 点を反映しました。施策3 (d) のみ再レビューをお願いします（(b) は Round 4 で承認済）。

## Round 4 指摘への対応（施策3 (d)）

### [Critical] 二重暗号化 → 対応
復号済み平文 recaller（`getCookie($recallerName)` 既定 decrypt=true）を **`withUnencryptedCookie`** で送信
（EncryptCookies の復号をスキップ）。平文 + skip-decrypt で内部整合。

### [Critical] 既存 guard/session が残り recaller 経路に落ちない → 対応
recaller 単独リクエスト前に **`$this->flushSession(); Auth::forgetGuards();`** を実行。

### [Warning] fallback は削除でなく単体テスト必須化 → 対応
「(d) 削除」オプションを撤回。実統合テストが不安定なら **AuthenticateSession の viaRemember 分岐を制御する
単体テスト**へ置換（未検証化しない）。

## 修正後の (d)
```php
test('別デバイスの古い remember-me (recaller) はハッシュ変更後に失効する', function (): void {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();

    $guard = Auth::guard('web');
    Assert::isInstanceOf($guard, \Illuminate\Auth\SessionGuard::class);
    $recallerName = $guard->getRecallerName();

    $login = $this->post('/login', [
        'email' => $user->email, 'password' => 'password', 'remember' => 'on',
    ]);
    $recaller = $login->getCookie($recallerName); // 復号済み平文 (decrypt 既定 true)
    Assert::isInstanceOf($recaller, \Symfony\Component\HttpFoundation\Cookie::class);

    User::query()->whereKey($user->id)->update(['password' => Hash::make('NewPassword12345')]);

    $this->flushSession();
    Auth::forgetGuards();

    $this->withUnencryptedCookie($recallerName, $recaller->getValue())
        ->get('/dashboard')
        ->assertRedirect('/login');
});
```
- 補足: phpstan 解析対象は app/config/database/routes のみで tests は対象外。`Auth::forgetGuards()`（facade @method）
  / `withUnencryptedCookie`（MakesHttpRequests）実在確認済。

残課題があれば指摘してください。無ければ全体判定 APPROVED をお願いします。
