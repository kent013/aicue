# 対応マトリクス: conceptual-review Round 2

## [Warning] justSaved の状態遷移矛盾 (reseed で true にすると 409 後に偽の成功表示) (観点3,5)
- 判断: 対応する
- 根拠: 妥当。`reseed()` は保存成功だけでなく 409 競合リロード / 明示リロードでも呼ばれる。
  「reseed 成功で true」だと保存していないのに「保存しました」が出る。
- 対応内容: true にする契機を `applySaved()` (保存成功パス) のみに固定。`reseed()` は理由を問わず
  常に `justSaved = false`。`applySaved()` は `reseed()` を呼んだ後に `justSaved = true` を立てる
  (順序で保証)。概念設計を更新。

## [Suggestion] 他観点 (使命/禁止事項/F-1-2 帰属/スコープ/型安全) は APPROVE 相当
- 判断: 見送る (変更不要)
- 根拠: Round 1 の対応で解消済みと Codex が確認。維持。
