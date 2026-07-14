# 対応マトリクス: design-review Round 2

## [Warning] S3 static callback からのヘルパ呼び出し / 型契約未確定
- 判断: 対応する
- 根拠: booted callback は static クロージャのため instance メソッドは呼べない。また詳細設計として
  型を「実装時確認」で濁すのは不適切。正当。
- 対応内容:
  - ヘルパを `private static function appendMiddlewareIfMissing(...)` と明記し `self::` で呼ぶ。
  - 型契約を確定: `Router::getRoutes()` は具象 `RouteCollection`、`getByName(string): ?Route` は
    `RouteCollectionInterface` の宣言メソッド。ヘルパ引数型を `RouteCollectionInterface` に確定
    (covariant で具象を渡せる、PHPStan L10 適合)。`refreshNameLookups()` は callback 側の具象に対して呼ぶ。
  - 「実装時確認」のヘッジ文を削除し確定契約に置換。

## [Warning] S5 baseline を profileForm.email で更新すると送信後入力変更で汚れる
- 判断: 対応する
- 根拠: `onSuccess` 時点で `profileForm.email` は「現在入力値」であり「サーバが受理した値」ではない。
  送信後〜応答前に入力が変わると baseline が汚れ、次の email 変更を「変更なし」と誤判定し得る。正当。
- 対応内容: `putProfile()` 冒頭で `const submittedEmail = profileForm.email` をスナップショットし、
  `onSuccess: () => { baselineEmail = submittedEmail; }` に変更。受理値を baseline に固定。

## 残 Suggestion (S1 Assert vs LogicException)
- 判断: 見送り継続 (軽微、PHPStan L10 は通過)。実装時に既存流儀へ寄せる余地は残す。
