# 対応マトリクス: conceptual-review Round 3

## [Warning] / [Warning](型安全性) `json_decode($json)` は `JsonException` を投げない (構文エラーで `null` を返す)
- 判断: 対応する
- 根拠: PHP の事実として正しい。旗を付けないと「構文エラー」と「JSON 値 null」を区別できず、
  fail-closed の分類が 1 つ潰れる。
- 対応内容: 呼び出しを `json_decode(json: $json, associative: false, flags: JSON_THROW_ON_ERROR)` と
  明記し、`JsonException` = 構文エラー / decode 成功後の非 `stdClass` = トップレベル型違反 の
  2 種を別々に fail-closed で扱うことを概念設計に書いた (深さは既定値のまま省略)。

## [Suggestion] 使命 / テストファーストの分離 / S06b の 4 点 / i8 の具体化 / 保証の 3 層 / リスクの受容 / スコープ
- 判断: 見送る (現行のままで趣旨を満たしている)
- 根拠: いずれも Round 2 までの修正で解消済みという評価。
