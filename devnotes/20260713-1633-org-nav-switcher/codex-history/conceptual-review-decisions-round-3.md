# 対応マトリクス: conceptual-review Round 3

## [Warning] aria-haspopup="true" は menu ポップアップを示唆し disclosure と不一致
- 判断: 対応する
- 根拠: 通常コンテナを開く disclosure パターンでは aria-haspopup は不要 (むしろ誤誘導)。
- 対応内容: トリガー button の a11y 属性を `aria-expanded` + `aria-controls` のみに変更
  (aria-haspopup を削除)。

## その他 [Suggestion] → 追加対応不要 (APPROVED 見込み)
