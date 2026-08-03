# 対応マトリクス: conceptual-review Round 3 (APPROVED)

## [Suggestion] コメント・文字列中の一致を除外できる解析方法を詳細設計で明記
- 判断: 採用
- 対応内容: 詳細設計の ValidationAttributeCoverageTest 仕様に「`token_get_all()` (PHP トークナイザ) で
  コメント/文字列リテラルを除外した上で呼び出しを検出する」方式を明記する。

## [Suggestion] 検出対象 API (3 種) の限定と、別経路採用時の規約コメント
- 判断: 採用
- 対応内容: テスト冒頭 docblock に「validation 経路を追加する場合 (`validator()` helper 等) は
  本テストの検出対象へ追加すること」という規約コメントを設計に含める。
