# 対応マトリクス: design-review Round 3

## 施策 2

### [Warning] inactive 状態の `discard()` に対応するテストが無い
- 判断: 対応する
- 根拠: 状態遷移表は「inactive | `flush()` / `discard()` | no-op」と明記済みだが、
  追加した 5 テストは `flush()`側しか見ていなかった。テストなしの不変条件を残さない。
- 対応内容: 施策 2・8 のテスト計画へ「inactive 状態で `discard()` を呼んでも例外にならず
  no-op であること (二重 `discard()` も no-op)」「その後 `start()` して通常どおり再利用
  できること」を追加する。
