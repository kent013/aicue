## 全体判定: CHANGES_REQUESTED

発火点表、enum 9 種、テスト責務の分離は解消しています。ただし、動的に登録する `TransactionCommitted` listener は当該 transaction と結び付かないため、commit 後通知の安全境界として成立しません。

### [Critical] `TransactionCommitted` の動的 listener は「当該 transaction 専用」にならない

`Event::listen(TransactionCommitted::class, ...)` はアプリケーション全体の event dispatcher への登録です。登録した listener には、次の問題があります。

- rollback 時には `TransactionCommitted` が発火しないため、listener が未実行のまま残る
- その後、同じプロセスで別の transaction が commit すると誤発火する
- 別の DB connection の commit も、connection を明示的に照合しなければ拾う
- 削除 transaction 内で後続 listener が別 connection や nested transaction を使った場合、外側の passkey transaction より先に発火し得る
- 内部フラグは「一度発火した後」の二重発火しか防げず、rollback 後の誤発火を防がない
- 通常の listener 登録には、その closure だけを安全に解除する one-shot subscription の仕組みが示されていない。長寿命プロセスでは未発火 closure が `User` を捕捉したまま蓄積する可能性もある

したがって、「次の `TransactionCommitted` は必ず現在の削除 transaction」という前提は成立しません。プロセスローカルであることは、同一プロセス内の別 transaction との識別を保証しません。

修正案: Round 1 で示した明示的な境界へ戻すのが最小かつ確実です。

1. `PasskeyDeleted` listener は request-local な collector に通知 intent を記録するだけにする。
2. `EnsureLoginMethodRemains` で `DB::transaction()` が正常に戻った後、collector を flush する。
3. transaction が例外終了した場合は flush しない。
4. flush は transaction 外なので、`after_commit=false` の queue 投入でも安全。

概念的には次の境界です。

```text
DB::transaction(
    lock → 判定 → controller → PasskeyDeleted → intent 登録
)
↓ 正常 commit 後だけ
intent flush → AuthMethodChangeNotifier::notify()
```

middleware に通知種別を認識させる必要はありません。専用の request-scoped collector の `flush()` を呼ぶだけにすれば、認証通知の知識は listener / notifier 側に閉じられます。

どうしても `TransactionCommitted` を使うなら、少なくとも connection、transaction level、rollback、listener の解除、長寿命プロセスでの状態破棄をすべて解決する必要があります。しかし transaction 固有 ID がない以上、「登録原因となった transaction」と後続 transaction の厳密な対応付けは脆弱で、今回の局所的要件には過剰です。

### [Warning] `PasskeyRegistered` の transaction 根拠が十分ではない

「route に `EnsureLoginMethodRemains` がない」ことだけでは、vendor action や別 middleware が transaction を開かない証明にはなりません。

修正案: 他の Fortify 行と同様に、登録を実行する vendor action の永続化箇所まで確認し、表の根拠へ「action に transaction なし」と明記してください。Feature テストではイベント単体ではなく実 route を通し、通知投入時の transaction level が `0` であること、または登録 rollback 時に job が残らないことを固定してください。

### [Suggestion] 発火点表の「即時」という表現

`notify()` はメールを即時送信するのではなく、queue job を同期的に投入します。「即時」はメール送信と誤読され得ます。

「transaction 外で直ちに enqueue」または「通常 enqueue」とすると、設計上の境界が明確です。

それ以外は解消済みと判断します。特に enum の 9 case 明示、パスワード設定経路の確定、Notifier の best-effort 契約、テスト責務の分離は妥当です。残る Critical は `TransactionCommitted` の動的購読を、transaction 正常復帰後の明示的 flush に置き換えることで解消できます。