# 対応マトリクス: conceptual-review Round 4

## 全体判定: APPROVED

## [Suggestion] tokenizer 系変更時の上限再確認を運用条件として設計書へ残す
- 判断: 採用する
- 対応内容: 概念設計 §4 の入力長上限に運用条件（byte-fallback BPE 前提。モデル/tokenizer 変更時は
  config 値 + AnalysisTokenBudgetInvariantTest 定数の再確認必須）を追記した。
