# 詳細設計レビュー Round 4

Round 3 の 2 点を反映しました。施策3 の該当箇所のみ再レビューをお願いします。

## Round 3 指摘への対応

### [Critical] (b) の 2 回目 actingAs が再認証してしまう → 対応
変更後は再 actingAs せず、確立済みセッションのまま `$this->get('/dashboard')->assertSuccessful()` で維持を検証。
```php
$this->actingAs($user)->put('/user/password', [
    'current_password' => 'password', 'password' => 'NewPassword12345',
])->assertSessionHasNoErrors();

$this->get('/dashboard')->assertSuccessful(); // 再 actingAs しない
expect(Hash::check('NewPassword12345', $user->fresh()->password))->toBeTrue();
```

### [Warning] (d) の viaRemember 分岐を実 recaller で検証 → 対応
(c) 同型を廃し、実 recaller cookie の統合テストへ変更。device 分離（device A = out-of-band hash 変更 /
device B = recaller のみ提示）。PHPStan L10 のため guard/cookie を Assert::isInstanceOf で narrowing。
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
    $recaller = $login->getCookie($recallerName, false); // decrypt=false: 暗号化生値
    Assert::isInstanceOf($recaller, \Symfony\Component\HttpFoundation\Cookie::class);

    // device A: out-of-band にハッシュ変更 (recaller の password_hash は旧のまま)
    User::query()->whereKey($user->id)->update(['password' => Hash::make('NewPassword12345')]);

    // device B: session cookie を送らず recaller のみ → viaRemember 経路で失効 → login へ
    $this->withCookie($recallerName, $recaller->getValue())
        ->get('/dashboard')
        ->assertRedirect('/login');
});
```
- fallback（環境依存で不安定な場合）: AuthenticateSession の viaRemember 分岐を制御する単体テスト、
  または (d) 削除 + DoD 明記。(c) が同一 `validatePasswordHash` primitive を固定するため未検証時リスクは限定的。

残課題があれば指摘してください。無ければ全体判定 APPROVED をお願いします。
