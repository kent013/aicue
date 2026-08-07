**tests/Support/StrayHttpRequestGuard.php**

Round 1 の [Critical] は解消しています。

`isSmuggledLoopbackUrl()` は、framework が glob で許可する集合に対して PSR-7 が解釈した実ホストを再検査しており、次の形も適切に処理されます。

- 大文字ホスト: 実ホストを `strtolower()` して判定
- 末尾ドット: `localhost.` は loopback allowlist に一致せず拒否
- 外部ホストの userinfo 詐称: パース後ホストが外部なので拒否
- `http://127.0.0.1:80@127.0.0.1/`: 実ホストも loopback なので許可。妥当
- percent encoding / IDN: RequestInterface と送信処理が同じ PSR-7 URI を使うため、パース結果と実送信先が分離する穴は見当たりません
- パース不能: fail-closed

middleware の入口に置く判断も妥当です。通常の外部 URL fake や正規の loopback fake は影響を受けず、拒否されるのは「loopback パターンに見せかけた外部 URL」です。

[Warning] 将来 `tests/` を PHPStan 対象にすると、`__invoke(callable $handler)` の callable signature 不足が level 10 で問題になる可能性があります。次のような型を付けるべきです。

```php
/**
 * @param callable(RequestInterface, array<string, mixed>): mixed $handler
 */
public function __invoke(callable $handler): Closure
```

**tests/Feature/Support/StrayHttpRequestGuardTest.php**

Round 1 の [Warning] は、case H と case J により解消しています。元 URL の完全一致は、現在観測された「第2層なしでは redirect 後 URL が記録される」という挙動に対して有効な discriminator です。

[Critical] case J は、第2層が壊れた場合に実通信してから赤くなります。M11 で実際に外部送信されたことが確認されており、回帰検出テスト自身が deny-by-default を破る構造です。

case J の先頭で wildcard fake を設置すれば、識別力を保ったまま外部送信を防げます。

```php
Http::fake(['*' => Http::response('', 200)]);
```

第2層が正常なら最外側 middleware が先に `StrayRequestException` を投げ、元 URL が accumulator に残ります。第2層を削除すると fake response まで到達して例外も記録もなくなり、安全に赤くなります。redirect 依存も消えるため、現在の完全一致方式より強い固定になります。

**tests/Architecture/StrayHttpEgressLaneGateTest.php**

Round 1 の [Critical] は解消しています。

- glob 単体で詐称 URLが一致する前提
- 第2層がそれを拒否すること
- 正規 loopback を拒否しないこと
- `LOOPBACK_HOSTS` とパターン側ホスト集合の一致

がそれぞれ固定され、M12/M13 も対象テストを赤化させています。

[Warning] 将来 PHPStan 対象にした場合、次の `$m[1]` は安全に型付けされていません。

```php
$matched = preg_match(..., $pattern, $m);
expect($matched)->toBe(1, ...);
$hosts[] = $m[1];
```

Pest の assertion は PHPStan による `$m` の shape narrowing にはなりません。`preg_match(...) !== 1` を明示的に分岐して fail させた後、`$m` を array shape として注釈する必要があります。

**その他のファイル**

`tests/Pest.php`、exemption enum、既存3テストのコメント変更には新規欠陥は見当たりません。DTO / JsonResource、DESIGN.md、Atomic Design は引き続き非該当です。

Browser lane は未実行なので、そのレーンの実動作だけは残余リスクとして残ります。ただし今回の判定を変える主因ではありません。

全体判定: CHANGES_REQUESTED