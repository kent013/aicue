Round 7の残件は解消されています。transaction levelを基準値との相対比較にしたことで、グローバル `RefreshDatabase` と検査対象の不変条件が両立しています。

## D1 / F4

判定: APPROVE

次の点を確認しました。

- `verify()` 呼び出し直前にbaselineを取得している
- `beforeRespond` の入口でbaselineと一致する
- 割り込み操作の終了後にもbaselineへ戻っている
- 絶対値0に依存していない
- 同期callbackは待機せず、その場で更新して戻るためデッドロックしない
- snapshot取得後・応答前という必要な順序を決定的に再現できる
- 実トランザクション間の排他と、verifyへの適用箇所の検査を分離している

一点だけ、実装時の表現上の注意があります。

同一プロセス・同一DB接続では、自分が既に取得した行ロックは再入可能です。そのため「callback内のdisableが待たずに完了したこと」単独では、行ロックを保持していなかった証明にはなりません。主証明は今回修正したtransaction levelの相対比較と、三段構成のコード配置です。より直接測るなら、第2段までに `FOR UPDATE` が発行されていないことをquery listenerで確認できます。これは承認阻害ではありません。

## 施策別判定

| 施策 | 判定 |
|---|---|
| A1 | APPROVE |
| A2 | APPROVE |
| A3 | APPROVE |
| B1 | APPROVE |
| B2 | APPROVE |
| B3 | APPROVE |
| B4 | APPROVE |
| C1 | APPROVE |
| C2 | APPROVE |
| D1 | APPROVE |
| D2 | APPROVE |
| E1 | APPROVE |
| F1 | APPROVE |
| F2 | APPROVE |
| F3 | APPROVE |
| F4 | APPROVE |

## 全体判定

APPROVED

設計上の承認阻害事項は解消されています。前段2件の完了確認と、記載済みの全検証コマンド・並行テスト・漏洩テストを受入条件として実装へ進められます。