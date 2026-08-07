## S1: REQUEST_CHANGES

[Warning] 追加した`read-only`検証分岐が、現在のテストデータでは一度も実行されません。

`CACHE_PAYLOAD_SURFACE_INVENTORY`には`write`と`lock-only`しかなく、`read-only` entryは0件です。そのため、今回追加した次の条件はArchitectureテスト本体を実行しても未検証です。

- 空の`methods`を拒否する
- 許可語彙外を拒否する
- 読み出し終端がないCHAINだけの形を拒否する

実装を誤って反転・削除しても、現在の21テストは緑のままです。これはテストファーストおよび「正負コントロールで静的走査ロジックを固定する」という本設計の方針に反します。

修正案: role判定を純関数へ切り出し、最低限以下の正負コントロールを追加してください。

```php
cachePayloadRoleViolations('read-only', ['get']);          // 許可
cachePayloadRoleViolations('read-only', ['store', 'get']); // 許可
cachePayloadRoleViolations('read-only', []);               // 拒否
cachePayloadRoleViolations('read-only', ['store']);        // 拒否
cachePayloadRoleViolations('read-only', ['lock']);         // 拒否
cachePayloadRoleViolations('read-only', ['put']);          // 拒否
```

同じ純関数で`write`と`lock-only`も検証すれば、3 roleの判定規則を実在ファイルの偶然の構成に依存せず固定できます。

[Warning] `read-only = 読むだけ`というrole名・説明と、許可語彙が一致していません。

現在は`CACHE_PAYLOAD_NON_WRITE_METHODS`をすべて許可していますが、ここには読み出しではない操作が含まれます。

```text
pull, forget, delete, deleteMultiple, flush, clear,
increment, decrement, setDefaultDriver, forgetDriver,
purge, extend, refreshEventDispatcher
```

したがって`Cache::flush()`だけを呼ぶファイルも、終端条件を満たして`read-only`として通ります。

修正案は次のどちらかです。

1. `read-only`を維持し、許可集合を本当に読み出し・照会だけの専用語彙へ絞る。
2. roleを`non-payload-write`などへ変更し、「任意payloadを書かない操作」という意味に合わせる。

本gateの目的はpayload制約なので、2の方が分類の実態に合っています。ただし`lock-only`を独立させる現在の設計は維持できます。

3 roleのうち、`write`はL2 exact-fitとの結合により実測書き込みが必要となり、`lock-only`も語彙集合と最低1件条件で正しく固定されています。残る問題は`read-only`の未テストと名称・許可語彙の不一致です。

## S2: APPROVE

指摘なし。DTO往復、素データ性、不正値、日時例外のテスト計画は妥当です。

## S3: APPROVE

指摘なし。configファイル上の宣言と実行時値を別々にpinする設計は妥当です。

## S4: APPROVE

指摘なし。誤ったallowlist方針を削除し、既存採番を維持しています。

## S5: APPROVE

指摘なし。不変条件の追記と番号競合時の同期手順は整合しています。

## 全体判定: CHANGES_REQUESTED

Round 3の直接指摘は反映されています。ただし、新設した`read-only`分岐が現状では未実行であり、さらに「読むだけ」という名称と許可操作が一致していません。この2点をroleの正負コントロールと語彙定義で固定すれば、設計全体を承認できます。