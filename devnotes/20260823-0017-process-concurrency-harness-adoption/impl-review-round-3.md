Round 2 の URI、symlink、5種の sentinel、子由来メッセージの choke point は実装・負例とも解消しています。ただし、秘密が例外のスタックトレースへ残る経路があります。

## ファイルごとの判定

### `ConcurrencyProbeRunner.php`

[Warning] 例外メッセージは伏せられていますが、親プロセスのスタックトレースには `run()` の引数が残ります。

`redactedForDiagnostics()` で新しい例外を生成しても、その trace には概ね次の呼び出しフレームが含まれます。

```php
ConcurrencyProbeRunner::run(
    idempotencyKey: ...,
    plainApiKey: $plainApiKey,
    requestBody: $requestBody,
)
```

Round 2 の根拠どおり `zend.exception_ignore_args=0` なら、文字列引数である plain API key の先頭が `getTraceAsString()` やCIの例外表示へ出ます。メッセージだけを再生成しても trace の引数は変更されません。

少なくとも次を `#[\SensitiveParameter]` にしてください。

```php
public static function run(
    string $idempotencyKey,
    #[\SensitiveParameter] string $plainApiKey,
    #[\SensitiveParameter] array $requestBody,
    // ...
)
```

`redactedForDiagnostics()` と `redactSecrets()` の `$secrets` にも付けると、例外フォーマッタが配列引数を展開する場合まで構造的に閉じられます。

choke point の方向自体は妥当です。問題は「例外メッセージ」と「例外 trace」が別経路である点です。

### `ConcurrencyHarnessFailurePathTest.php`

[Warning] 群4-43は `harnessThrowableText()` で例外メッセージと previous だけを検査し、trace を検査していません。

次も対象に含め、5種の sentinel がないことを確認してください。

- `$thrown->getTraceAsString()`
- previous 各段の `getTraceAsString()`

また、テスト用 `harnessRun()` 自身も plain API key を文字列引数で受けるため、この引数にも `#[\SensitiveParameter]` が必要です。そうしないと、本体側を直してもテストヘルパーのフレームから sentinel が見えます。

URI不一致の負例とsymlink外側保持の負例は、削除対象の分岐を正しく固定できています。

## Round 2 指摘の状態

- 子由来メッセージの伏せ字: メッセージ経路は解消、親 trace 経路が残る
- URI受理条件の負例: 解消
- symlink非追跡の負例: 解消
- 既知の秘密5種の検査: メッセージについては解消、trace検査が不足

CHANGES_REQUESTED