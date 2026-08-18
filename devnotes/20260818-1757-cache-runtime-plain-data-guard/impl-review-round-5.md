## tests/Architecture/CacheGuardWiringGateTest.php

[Warning] `cacheGuardIsDirectStatement()` は波括弧の深さしか見ないため、「無条件に実行される文」はまだ保証できません。PHP の波括弧を使わない制御構文が通ります。

```php
->beforeEach(function (): void {
    if (false)
        PlainDataCacheGuard::assertInstalled($this->app);
})
->afterEach(function (): void {
    try {
        if (false)
            PlainDataCacheGuard::flushAndFailIfStray();
    } finally {
        if (false)
            PlainDataCacheGuard::reset();
    }
})
```

代替構文も同様です。

```php
if (false):
    PlainDataCacheGuard::assertInstalled($this->app);
endif;
```

さらに、三項演算子や短絡評価の右辺も波括弧深度は 0 です。単に「入れ子の波括弧内ではない」ではなく、対象呼び出しが独立した式文として直接置かれていることを確認してください。例えば、文境界から対象呼び出しと終端 `;` までの token 形を固定する方法が考えられます。波括弧なしの `if` と短絡評価を負例へ加える必要があります。

[Suggestion] 冒頭 docblock の保証外一覧に、現在も「動的な `uses($traitName)` には沈黙する」と残っています。実装は `UNRESOLVED_USES` で失敗するようになったため、実態と矛盾しています。

## tests/Architecture/CachePayloadPlainDataGateTest.php

[Warning] `cachePayloadUseMap()` は `use function` / `use const` を除外できていません。`T_FUNCTION` または `T_CONST` で `$pending = null` にした後も走査を継続するため、後続の名前 token が再び `$pending` に入り、末尾でクラス取り込み表へ登録されます。

```php
use function Vendor\Tools\Repository;
use const Vendor\Tools\Store;
```

どちらも現在の実装では `Repository` / `Store` の alias として `$map` に入り得ます。既存のクラス import と同名なら上書きも可能です。

`T_USE` の直後が `T_FUNCTION` / `T_CONST` なら、その use 文全体を読み飛ばしてください。グループ use 内の `function` / `const` 指定も同様に個別要素を除外する必要があります。通常の class import と、単独・グループ双方の function/const import を含む正負コントロールを追加してください。

CHANGES_REQUESTED