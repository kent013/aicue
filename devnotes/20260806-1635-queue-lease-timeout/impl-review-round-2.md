**`tests/Architecture/QueuedJobLeaseInventoryTest.php`**

[Warning] `onConnection()` が constructor の行範囲内にあることは、「dispatch 前に必ず実行される」保証にはまだ足りません。`ReflectionMethod::getStartLine()`〜`getEndLine()` は字句上の範囲しか見ないため、以下のようなコードは gate を通りますが pin は実行されません、または条件付きになります。

```php
public function __construct(...)
{
    $pin = fn () => $this->onConnection('database-media');
}
```

```php
public function __construct(..., bool $useMedia = false)
{
    if ($useMedia) {
        $this->onConnection('database-media');
    }
}
```

今回の mutation test は「constructor 外」を検出できることは示していますが、constructor 内で実行されない / 条件付き実行されるケースの偽陰性は残ります。Round 1 の指摘は「dispatch 前に必ず実行」なので、少なくとも `onConnection()` が constructor 直下の実行文であることを token で固定するか、fixture/mutation で closure・条件分岐内を赤くする必要があります。

他ファイルは Round 2 追加差分なしとして、前回判定を維持します。全量 `composer test` 未実行予定である点は最終確認事項ですが、上記の gate 偽陰性が残るため現時点では承認できません。

CHANGES_REQUESTED