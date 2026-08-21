Round 2 の残る指摘 (start() の二重呼び出し) に対応しました。反論・見送りはありません。

`devnotes/20260821-2015-auth-method-change-notification/detailed-design.md` を再読込し、
対応が Round 2 の指摘を解消しているか確認してください。

## 対応マトリクス

### [Warning] `start()` の二重呼び出し時の契約とテストが不足
- 判断: 対応する
- 対応内容: 提示いただいた修正案のとおり、`start()` に「既に active なら
  `LogicException`」を追加しました。あわせて提示いただいた状態遷移表をそのまま
  `LoginMethodRemovalPostCommitCallbacks` の docblock に明記し、「active 中に `start()` を
  再度呼ぶと積んだ callback を無言で消す」という実装は選ばないことを明示しました。
  提示いただいた 5 つの Unit テスト (active 中の二重 `start()` が例外になる / 二重 `start()`
  失敗後も先に積んだ callback が失われていない / `flush()`後に再度 `start()` できる /
  `discard()`後に再度 `start()` できる / inactive 状態の `flush()` が no-op で既存の二重
  flush 契約と整合する) をすべて施策 2 のテスト計画、および施策 8 の Unit テスト記述へ
  追加しました。

## 質問

上記修正で Round 2 の Warning は解消されていますか。まだ残る問題、または今回の修正が新たに
持ち込んだ問題があれば具体的に指摘してください。全て解消していれば全体判定 APPROVED として
ください。
