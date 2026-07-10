# 対応マトリクス: design-review Round 3

## [Warning] save() 開始時に genericError をクリアしていない
- 判断: 対応する
- 対応内容: save() 冒頭（errors / conflict クリアの並び）に `genericError = null` を追加。
  Vitest に「失敗 → 再保存成功で旧 genericError が消える」ケースを追加。

## [Suggestion] 成功レスポンスも実行時検証
- 判断: 対応する
- 対応内容: `isScenarioDocument` type guard を追加し、成功応答の JSON 破損・shape 不一致は
  汎用エラー（再読み込み案内）へフォールバック。Vitest に「成功応答の shape 不正」ケースを追加。
