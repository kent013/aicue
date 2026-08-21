## 全体判定: CHANGES_REQUESTED

commit 後に明示的に flush する方式への変更は正しい方向です。ただし、`singleton` と request scope は両立せず、現記述のままでは rollback した通知が後続リクエストで誤送信されます。

### [Critical] `PostCommitCallbacks` を singleton にすると rollback 後の callback が残る

Laravel の通常の `singleton` はアプリケーションコンテナの寿命を持ち、request scope ではありません。Octane 等の長寿命 worker や、同一 application instance で複数リクエストを扱うテストでは、次の挙動になります。

1. リクエスト A で callback を `push()`
2. transaction が rollback し、`flush()` に到達しない
3. callback が singleton 内に残る
4. リクエスト B の正常 commit 後に `flush()`
5. A と B の通知が両方発火する

これは Round 2 の未発火 listener 残留と同じ問題を、collector に移した形です。

修正案:

- `singleton()` ではなく Laravel の `scoped()` binding を使う
- rollback 時に callback を明示的に破棄する `discard()` または `clear()` を追加する
- `flush()` は再入・例外・二重呼び出しに備え、実行前に保持配列を空へ移す

概念的には以下です。

```php
try {
    $response = DB::transaction(fn () => $next($request));
} catch (Throwable $exception) {
    $this->postCommitCallbacks->discard();

    throw $exception;
}

$this->postCommitCallbacks->flush();

return $response;
```

`AuthMethodChangeNotifier::notify()` が例外を吸収するとしても、汎用 collector 自体は callback 実行中の例外で古い callback を保持しない設計にすべきです。

最低限、以下をテストファーストで固定してください。

- rollback 後の collector は空
- rollback したリクエスト A の callback が、正常なリクエスト B で実行されない
- `flush()` を2回呼んでも1回しか実行されない
- callback が例外を投げても、同じ callback が後続 `flush()` で再実行されない
- scoped instance がリクエスト／Octane lifecycle 間で共有されないこと

### [Warning] 汎用的な名前と、実際の flush 境界が一致していない

`PostCommitCallbacks` という名前は、アプリ全体の任意の transaction で使用できるように見えます。しかし実際に flush されるのは `EnsureLoginMethodRemains` が包む transaction だけです。他の Service が `push()` すると、実行されないか、無関係なパスキー削除 transaction の後に実行されます。

修正案は次のいずれかです。

- `LoginMethodRemovalPostCommitCallbacks` など、この middleware 専用であることが分かる名前にする
- `PostCommitCallbacks` のままなら、`push()` をこの transaction 境界以外から利用できない構造・Architecture テストで制限する

現時点では前者の方が「今必要なものだけ作る」と「機能の名前に立ち返る」に合っています。Laravel 全体の汎用 post-commit 基盤を作ったという主張は避けるべきです。

上記以外は解消しています。特に vendor action まで確認した発火点表、transaction 外 enqueue という表現、動的 `TransactionCommitted` listener の撤回は妥当です。`scoped` 化、rollback 時の破棄、用途を表す命名まで直せば承認可能です。