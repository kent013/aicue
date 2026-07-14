# 詳細設計レビュー Round 6（Cookie ペア確定の確認のみ）

Round 5 の [Critical] Cookie ペア不一致を修正しました。framework source
（`MakesHttpRequests::prepareCookiesForRequest`）を確認し、`withCookie` はテスト側で
`encrypt(CookieValuePrefix::create(...).$value)` として**再暗号化**して送り、アプリの
`EncryptCookies` が復号することを確定しました。よって Codex 提示の **option A**（復号済み平文 +
`withCookie`）を採用します。

## 修正後の (d) 該当箇所
```php
$recaller = $login->getCookie($recallerName); // 復号済み平文 (decrypt 既定 true)
Assert::isInstanceOf($recaller, \Symfony\Component\HttpFoundation\Cookie::class);

User::query()->whereKey($user->id)->update(['password' => Hash::make('NewPassword12345')]);

$this->flushSession();
Auth::forgetGuards();

$this->withCookie($recallerName, $recaller->getValue()) // テスト側で再暗号化→アプリが復号
    ->get('/dashboard')
    ->assertRedirect('/login');
```

これで施策1（Round 2 APPROVE）/ 施策2（Round 2 APPROVE）/ 施策3 (a)(b)(c)(e)（承認済）/ (d)
（本修正）が揃います。残課題が無ければ全体判定 APPROVED をお願いします。
