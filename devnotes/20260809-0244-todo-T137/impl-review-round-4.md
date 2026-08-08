## `tests/Support/Queue/QueueDispatchDeferralInventory.php`

[Warning] `truthyLiteral()` の数値評価が PHP の数値リテラル意味論と一致しません。

```php
return ((float) $token['text']) !== 0.0;
```

`T_LNUMBER` には16進・2進・8進表記も入ります。例えば次は実行時には truthy ですが、文字列からの float cast では `0.0` となり、検出をすり抜ける可能性があります。

```php
$job->afterCommit = 0x1;
$job->afterCommit = 0b1;
$job->afterCommit = 0o1;
```

「非ゼロ数値を検出する」という主張と一致させるには、基数と数値区切りを含むPHP整数リテラルとして評価する必要があります。少なくとも10進、16進、2進、8進それぞれのゼロ／非ゼロを負・正のコントロールへ追加してください。

[Warning] 文字列リテラルも、ソース表記ではなくPHPが評価した値で判定できていません。

```php
$literal = substr($token['text'], 1, -1);
```

例えば `"\x30"` の実行時値は `"0"` なので falsy ですが、現在の検出器は文字列 `\x30` として truthy 判定し、偽陽性になります。エスケープを完全評価しない方針なら、エスケープを含む文字列は「評価不能」に倒し、その制限を明記する方が安全です。

## `tests/Architecture/QueueDispatchAtomicityInventoryTest.php`

[Suggestion] D5の0件 pinテスト名は更新されていますが、次のテスト名はまだ `true` 代入だけを固定するように読めます。

```php
D5: first-party ランタイム PHP に $afterCommit への true 代入は 1 件も無い
```

実装契約に合わせて「truthyな単一リテラル代入」に変更するのが適切です。

## `docs/architecture.md`

[Suggestion] D5の表がまだ次の表現です。

```text
truthy な既定値 / promoted parameter / = true 代入
```

実装は `1`、`'yes'`、`2.5` も検出するため、「truthyな単一リテラル代入」と記載すると実装および保証しない範囲と一致します。

`BillingCustomerSynchronizerTest` の保証範囲の修正は妥当で、Round 3のSuggestionは解消しています。

CHANGES_REQUESTED