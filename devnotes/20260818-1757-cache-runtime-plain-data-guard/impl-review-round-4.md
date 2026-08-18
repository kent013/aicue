## tests/Architecture/CacheGuardWiringGateTest.php

[Warning] W2/W3 は同一 `try` と `finally` の対応を確認できるようになりましたが、その `try` が `afterEach` クロージャの直下で無条件に実行されることは確認していません。次の形が通ります。

```php
->afterEach(function (): void {
    if (false) {
        try {
            PlainDataCacheGuard::flushAndFailIfStray();
        } finally {
            PlainDataCacheGuard::reset();
        }
    }
})
```

`cacheGuardTryStatement()` が返した範囲は `afterEach` 内にありますが、実行時には flush/reset とも走りません。同様に `assertInstalled()` も `beforeEach` 内の条件分岐へ入れれば通ります。

少なくとも対象の `try` と `assertInstalled()` が各クロージャ本体の直接の文であることを確認するか、制御構造内に入れた負例を追加してください。

[Suggestion] `cacheGuardTryStatement()` の説明は「finally を持つ最初の try を返す」と読めますが、実装は最初に見つけた try が finally を持たなければ即座に `null` を返します。現在の用途では fail-closed ですが、再利用時に誤読しやすいため、docblockを「最初の try 文を解析し、それ自身が finally を持つ場合だけ返す」に合わせるのが適切です。

## tests/Architecture/CachePayloadPlainDataGateTest.php

[Warning] 名前解決は同一名前空間と `namespace\Foo` に対応し、前回の Critical は解消しています。一方、提示された説明では、この gate 側の取り込み表が引き続き group use 非対応です。

```php
use Tests\Support\Cache\{
    PlainDataGuardedRepository as GuardedRepository
};

final class Bypass extends GuardedRepository {}
```

別 gate が group use を禁止していても、この走査器自身は完全修飾名へ解決できず、収集結果を正常な非対象として扱います。AGENTS.md (a) はクラス参照を扱う走査に group use 解決を明示的に要求しているため、次のどちらかが必要です。

- group use を完全修飾名へ解決する
- group use を未解決として返し、この gate 自身を失敗させる

別 gate に依存するなら、少なくともその依存を機械的に固定しない限り、この gate 単体の fail-closed にはなりません。

## 文書

guide の3目録の書き分けと、D30 の L4h・動的生成目録の追記は整合しています。動的 `new` の rationale が人間の申告であるという保証範囲の限定も妥当です。

CHANGES_REQUESTED