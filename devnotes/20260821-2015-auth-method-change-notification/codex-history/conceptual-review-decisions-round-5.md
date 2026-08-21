# 対応マトリクス: conceptual-review Round 5

## [Suggestion] `flush()` の例外保証の表現だけ明確にする
- 判断: 対応する
- 対応内容: 「callback 内の例外が他の callback や次回の flush に影響しない」という表現を、
  「実行前に空へ移すため次回の `flush()` で再実行されることはない (2 回目は何もしない) が、
  1 件目が例外を投げれば後続 callback は実行されない ( `foreach` の通常の挙動)。
  `AuthMethodChangeNotifier::notify()` が例外を内部で吸収するため現スコープでは実害が無い」
  という、保証範囲を誇張しない書き方へ修正した。

## 総括

Round 1〜5 で Critical 6 件・Warning 8 件・Suggestion 5 件に対応し、Round 5 で
**全体判定 APPROVED** を得た。概念設計は確定。Phase 2 (詳細設計) へ進む。
