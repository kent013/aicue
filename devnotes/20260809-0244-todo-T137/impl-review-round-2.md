## `tests/Feature/Billing/BillingCustomerSynchronizerTest.php`

[Warning] 主契約の観測は追加されましたが、保証できるのは `RenameOrganizationAction` の1経路だけです。

詳細設計では `BillingCustomerSynchronizer::dispatchFor()` の呼び出し元が2経路ある前提でした。もう一方がトランザクション外へ移動しても、今回のテストは緑のままです。次のいずれかが必要です。

- もう一方の実呼び出し元にも同じ tx level テストを追加する
- `dispatchFor()` の全呼び出し元を固定する Architecture inventory と、各呼び出し元の behavioral test を対応させる

`afterCommit` フラグ、rollback、commit 後の jobs 行テストは補助契約として妥当です。

## `tests/Support/Queue/QueueDispatchDeferralInventory.php`

[Warning] `defersAfterCommit()` の判定が PHP の真偽値文脈と一致していません。

```php
return $value !== null && $value !== false;
```

この判定では `0`、`''`、`'0'`、`[]` も違反になりますが、PHP の真偽値文脈では falsy なので commit 後ずらしを発動しません。説明している vendor 挙動に合わせるなら、実質的には次の判定です。

```php
return (bool) $value;
```

現状は迂回を見逃す方向ではありませんが、「vendor と同じ意味論」という主張が誤っており、将来正当な falsy 値で gate が偽陽性になります。

[Warning] constructor promotion の検査もまだ deny-by-default になっていません。

検出するのは promoted parameter の「デフォルト値」だけです。以下はすり抜けます。

```php
public function __construct(public bool $afterCommit = false) {}

// 呼び出し側
new SomeJob(afterCommit: true);
```

デフォルトなしの promoted property も同様です。動的代入の既知制約とは別経路であり、現在の「constructor promotion も見る」という表現は保証範囲を誇張しています。0件 pin を優先するなら、promoted `afterCommit` parameter 自体を値にかかわらず違反にするのが単純です。

## `tests/Architecture/QueueDispatchAtomicityInventoryTest.php`

[Suggestion] D5テスト名と失敗メッセージは、実装を広げた内容に合わせて「既定値が true」ではなく「commit 後ずらしを発動する既定値」に変更した方がよいです。現在は `1` も検出するため、表示上の契約が実装より狭くなっています。

mutation #24への対応は妥当です。現在の集合では片側化が意味論上同値であり、集合が分離した時点では既存の和集合一致テストも赤くなります。trip-wireを含め、残存リスクは明示されています。

## `tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php`

指摘は解消しています。database queueへの固定と `Pending` 状態の確認により、下流ジョブのインライン実行による偽グリーンを防げています。

## `tests/Feature/Billing/TicketLowBalanceNotificationIsolationTest.php`

指摘は解消しています。チャネルの実呼び出しを確認しているため、「通知経路が走らなくても予約だけ成功して緑」という経路は閉じています。

CHANGES_REQUESTED