# 対応マトリクス: conceptual-review Round 3

## [Warning] `DeterminedScenarioDuration` の PHP テストがテスト項目に無い
- 判断: 対応する
- 根拠: 指摘のとおり。UI テストは props の表示規則しか見ないので、集計式は無検証だった。
- 対応内容: テスト項目を (a)〜(e) に分けて明記し、(b) として集計分岐 4 件
  (空配列 / 全件 null / 混在 / 全件確定) を列挙した。

## [Warning] `list<int|null>` を実引数型宣言に書くと構文エラー
- 判断: 対応する
- 対応内容: PHPDoc の `@param list<int|null>` + 実引数は `array` の形へ書き換えた。

## [Suggestion] その他 (使命 / 効果 / リスク / スコープ / 型安全性)
- 判断: 見送る (指摘なし。現状維持)
