# 対応マトリクス: design-review Round 2

## 施策 2

### [Warning] `start()` の二重呼び出し時の契約とテストが不足
- 判断: 対応する
- 根拠: 指摘のとおり。すでに active な状態で `start()` が再度呼ばれると、積んでいた
  callback を無言で消してしまう (nested middleware・同一 request scope 内の誤った再利用を
  検出すべきところ、正常系に見えてしまう)。fail-fast にする。
- 対応内容: `start()` に「既に active なら `LogicException`」を追加。状態遷移表
  (inactive→start→active / active→push→active / active→flush,discard→inactive /
  inactive→push→例外 / active→start→例外 / inactive→flush,discard→no-op) を
  docblock として明記。Unit テストへ次を追加:
  - active 中の二重 `start()` が `LogicException` になる
  - 二重 `start()` 失敗後も、先に積んだ callback が失われていない
  - `flush()` 後に再度 `start()` できる
  - `discard()` 後に再度 `start()` できる
  - inactive 状態の `flush()` が no-op で、既存の二重 flush 契約と整合する
