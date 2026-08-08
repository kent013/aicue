## `tests/Support/Queue/QueueDispatchDeferralInventory.php`

[Warning] D5 の実行時代入は、依然として vendor の真偽値文脈と一致していません。

既定値には `(bool) $value` を適用していますが、代入検出は `true` リテラルだけです。そのため、次は commit 後ずらしを発動するにもかかわらず gate を通過します。

```php
$this->afterCommit = 1;
$job->afterCommit = 'yes';
```

これらは文書化された「動的値」の穴ではなく、静的な truthy リテラルです。クラス docblock の「どの層からも迂回できない」とも整合しません。

少なくとも `true`、非ゼロ数値、空でない文字列を検出し、`false`、`null`、`0`、`''`、`'0'` を偽陽性にしない負のコントロールが必要です。完全な定数式評価を行わない場合は、その限界も「保証しないもの」に明記する必要があります。

## `tests/Feature/Billing/BillingCustomerSynchronizerTest.php`

[Suggestion] 2経路の tx level 観測自体は追加され、Round 2 の指摘は解消しています。ただし、次のdocblockは保証を誇張しています。

> `BillingSyncDispatchInvariantTest` が dispatch 窓口を 1 クラスへ閉じ、呼び出し元はこの 2 本に限られる

説明された同テストの保証は「`SyncBillingCustomerDetails` の dispatch 元を `BillingCustomerSynchronizer` に限定すること」であり、`dispatchFor()` の呼び出し元を2本に限定するものではありません。新しい第3の呼び出し元は検出されません。

「現時点で確認済みの2経路」と表現するか、`dispatchFor()` の呼び出し元を固定する別の Architecture inventory が必要です。これは設計全体で明記された「dispatch位置の静的完全性を保証しない」とも揃えるべきです。

## その他

`defersAfterCommit()` の真偽値判定、promoted parameter の値に依存しない拒否、D5の名称・文書更新は適切です。`UpdateBillingContactAction` の追加観測により、既知の2経路はいずれも主契約で固定されています。

CHANGES_REQUESTED