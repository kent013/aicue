# 対応マトリクス: design-review Round 3 (item2)

## [施策3-b Warning] ReflectionMethod::getFileName/getStartLine/getEndLine の int|string|false で PHPStan L10 エラー
- 判断: 対応
- 対応内容: 各戻り値を変数化し `Webmozart\Assert\Assert::string()/integer()/isArray()` で型を narrow
  してから array_slice する。`(array)`/`(string)` cast は除去。test ファイルに use Assert を追加。
