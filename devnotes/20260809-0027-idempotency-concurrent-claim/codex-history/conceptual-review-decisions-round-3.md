# 対応マトリクス: conceptual-review Round 3

## [Warning] エラー envelope の「余分なキーがない」保証とアサーションの不一致
- 判断: **対応する**
- 根拠: 指摘のとおり `assertJsonStructure()` は指定キーの存在しか見ず、余分なキーを拒まない。
  主張と検証手段が一致していなかった。
- 対応内容: スコープ外節の記述を階層まで明示する形に更新した
  (「top-level は `error` のみ / `error` 配下は `code`・`message`・`status` の 3 キーのみ」)。
  検証手段は `assertJsonCount(1)` + `assertJsonCount(3, 'error')` + `assertJsonPath()` 3 本。

## [Suggestion] `report()` に元例外を渡すと message まで載る
- 判断: **対応する**
- 根拠: 5 項目限定という要件と矛盾する。AGENTS.md の「例外 message はログに載せない」とも同型。
- 対応内容: 結論 8-5 に「元例外をそのまま `report()` に渡さない / `previous` にも連結しない。
  許可した 5 項目だけを持つ専用の例外を組み立てて `report()` する」を追記した。

## その他 (Suggestion 1 / 3 / 4 / 5 / 6 / 7)
- 判断: **対応不要** (いずれも現状維持で妥当と評価されている)
