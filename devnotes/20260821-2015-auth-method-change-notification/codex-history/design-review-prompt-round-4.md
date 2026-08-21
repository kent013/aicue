Round 3 の残る指摘 (inactive 状態の discard() のテスト欠落) に対応しました。反論・見送りは
ありません。

`devnotes/20260821-2015-auth-method-change-notification/detailed-design.md` を再読込し、
対応が Round 3 の指摘を解消しているか確認してください。

## 対応マトリクス

### [Warning] inactive 状態の `discard()` に対応するテストが無い
- 判断: 対応する
- 根拠: 状態遷移表は「inactive | `flush()` / `discard()` | no-op」と明記済みだが、
  追加した 5 テストは `flush()` 側しか見ていなかった。テストなしの不変条件を残さない。
  `discard()` の実装自体は既に `$this->callbacks = []; $this->active = false;` という
  無条件の代入であり inactive 中に呼んでも例外にはならず idempotent なので、コード変更は
  不要でテスト追加のみで済む。
- 対応内容: 施策 2・8 のテスト計画へ「inactive 状態で `discard()` を呼んでも例外にならず
  no-op であること (二重 `discard()` も no-op)、その後 `start()` して通常どおり再利用できる
  こと」の Unit テストを追加しました (提示いただいた例のとおり 1 テストにまとめる想定です)。

## 質問

上記修正で Round 3 の Warning は解消されていますか。まだ残る問題、または今回の修正が新たに
持ち込んだ問題があれば具体的に指摘してください。全て解消していれば全体判定 APPROVED として
ください。
