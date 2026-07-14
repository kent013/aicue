## 施策3 (d): REQUEST_CHANGES

- [Critical] Cookie処理の組み合わせが逆です。`withUnencryptedCookie()` はテスト側の暗号化を省略するだけで、`EncryptCookies` の復号を無効化しません。復号済み平文を送ると、アプリ側が平文を暗号文として復号しようとします。  
  修正案は次のどちらかです。

```php
// 復号済み平文 → テスト側で再暗号化
$recaller = $login->getCookie($recallerName);

$this->withCookie($recallerName, $recaller->getValue());
```

```php
// 暗号化生値 → そのまま送信
$recaller = $login->getCookie($recallerName, false);

$this->withUnencryptedCookie($recallerName, $recaller->getValue());
```

`flushSession()` + `Auth::forgetGuards()` によるrecaller経路への分離と、単体テストへの必須fallbackは妥当です。

## 全体判定: CHANGES_REQUESTED

上記Cookieペアを一致させれば承認可能です。