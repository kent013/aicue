# 詳細設計レビュー Round 3

Round 2 の Critical/Warning を反映しました（施策1・2 は Round 2 で APPROVE 済）。施策3 の修正のみ再レビューをお願いします。

## Round 2 指摘への対応（施策3）

### [Critical] `$this->flush()` 非実在 API / flush が device B の server session を破棄
→ **cookie replay を全廃**し、(c)(d) を **`withSession(['password_hash_web' => $oldHash])` の決定的統合
テスト**へ変更。cookie 名も getCookie も使わない。要点は「guard user = 新ハッシュ / session = 旧ハッシュ」の
不一致を作り、AuthenticateSession が logout することを `assertRedirect('/login')` で固定する。

### [Warning] `config('session.cookie')` mixed / getCookie nullable
→ 主方式（withSession）では cookie を扱わないため不要化。実 recaller cookie を経由する任意の追加検証を
行う場合のみ `Assert::string` / `Assert::isInstanceOf($cookie, Symfony...\Cookie::class)` を必須とする注記を残置。

### (a) の現在行残存の id 依存
→ id 一致依存を排除。(a) は「当該 user の他行 delete / 別 user 行 keep」の堅牢な不変条件のみ検証し、
現在行残存は (b)（現在デバイス維持）で担保する形に整理。

## 修正後の施策3 テスト骨子

### (a)（施策2 / 他 user 行削除・別 user 行残存）
```php
test('パスワード変更で当該ユーザーの他セッション行が削除され、別ユーザーの行は残る', function (): void {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $other = User::factory()->create();

    DB::table('sessions')->insert([
        sessionRow('attacker-session', $user->id),
        sessionRow('other-user-session', $other->id),
    ]);

    $this->actingAs($user)->put('/user/password', [
        'current_password' => 'password',
        'password' => 'NewPassword12345',
    ])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', 'attacker-session')->exists())->toBeFalse();
    expect(DB::table('sessions')->where('id', 'other-user-session')->exists())->toBeTrue();
});
```

### (b)（施策2 / 現在デバイス維持）
```php
test('パスワード変更後も現在デバイスはログイン状態を維持する', function (): void {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();

    $this->actingAs($user)->put('/user/password', [
        'current_password' => 'password',
        'password' => 'NewPassword12345',
    ])->assertSessionHasNoErrors();

    $this->actingAs($user)->get('/dashboard')->assertSuccessful();
    expect(Hash::check('NewPassword12345', $user->fresh()->password))->toBeTrue();
});
```

### (c)（施策1 / 旧 hash セッション失効・決定的）
```php
test('旧 password_hash を持つ既存セッションはハッシュ変更後の次リクエストで失効する', function (): void {
    $user = User::factory()->create();
    $oldHash = $user->getAuthPassword();

    $user->forceFill(['password' => Hash::make('NewPassword12345')])->save();

    $this->actingAs($user)
        ->withSession(['password_hash_web' => $oldHash])
        ->get('/dashboard')
        ->assertRedirect('/login');
});
```
（(d) は (c) と同型で remember 由来セッションを表現。recaller の viaRemember 分岐も同一
`validatePasswordHash(currentHash, ...)` primitive を使うため同値の保証。）

### (e)（施策2 / driver != database で skip）
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

残課題があれば指摘してください。無ければ全体判定 APPROVED をお願いします。
