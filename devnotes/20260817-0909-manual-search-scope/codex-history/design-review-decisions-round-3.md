# 対応マトリクス: design-review Round 3

Codex 判定: **APPROVED** (全 7 施策 APPROVE / Critical 0 / Warning 0 / Suggestion 1)。

## [Suggestion] 施策5: 「本索引が確実に効く」は厳密でない。「アクセス経路を提供する」等が正確
- 判断: **対応する**
- 根拠: 正しい。PostgreSQL は小規模テーブルでは索引を選ばず逐次走査を選ぶことがあり、
  **索引の存在は利用の保証ではない**。本設計は他の箇所で「実行計画は 2 通りありうる」
  「Seq Scan が選ばれても異常としない」と書いており、この 1 文だけが強すぎて食い違っていた。
  保証範囲を誇張しない (AGENTS.md の流儀) の観点からも直すべきである。
- 対応内容: 施策 5 のリスク節を
  「本索引が**アクセス経路を提供する**のは (a)(b)(c) の 3 つである。
  **『確実に効く』とは書かない** — PostgreSQL は小規模テーブルでは索引を選ばず
  逐次走査を選ぶことがあり、索引の存在は利用の保証ではない (経路を用意するだけである)。
  実際にどれが選ばれたかは完了条件の `EXPLAIN` で記録する」へ書き換えた。

## 合議の終了

- 概念設計: conceptual-review **Round 1 で APPROVED**
- 詳細設計: design-review **Round 3 で APPROVED**

Round 1 で提出した反論 2 件 (LIKE の `ESCAPE` 句 / `Assert::string`) は
Round 2 で Codex が同意し、指摘を撤回した。
