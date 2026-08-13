# 対応マトリクス: design-review Round 4

全件受け入れ（反論なし）。

## [Critical] 正常な guard 遷移の途中状態が `failed-transition` になり観測が早期終了する（施策1）
- 判断: **対応する**
- 根拠: 指摘が正しく、これも私の作り込んだ欠陥。イベントは逐次追記されるので
  正常経路でも必ず `pending` だけの瞬間、`pending → verifying` の瞬間を通る。
  現行規則ではこれらが「上記以外の遷移列」= `failed-transition` に落ち、
  さらに `deriveTrialPhase()` が軸2の異常終端を `complete` とするため、
  **`null` / `retry` / 復元後 `page-hide` が来る前に自動追記が止まる**。
  最終形だけを見た真理値表では絶対に露見しない類の欠陥だった。
- 対応内容:
  - `GuardVerdict` に **`"in-progress"`** を追加した
  - `pending` / `pending → verifying` / guard イベント未発生 を `in-progress` とする
  - `deriveTrialPhase()` は `in-progress` を **`collecting-axis2`** に写す
  - `failed-transition` を**正常遷移の prefix ではない列に限定**した
    （`verifying` から開始 / `pending → null` / 終端後の矛盾遷移）
  - `not-observed` は「`trial-aborted` された時点で guard イベントが 1 件も無い」場合に限定した
  - 「`pending` のまま停止」はイベント列から判断できないため**それ自体を異常としない**

## [Critical] 逐次適用のテストが無い（施策5）
- 判断: **対応する**
- 根拠: 最終形の純粋関数テストが通っても、途中で listener が停止すれば実機で観測できない。
  テスト設計が最終形に偏っていた。
- 対応内容: イベントを 1 件ずつ追記しながら各時点の `GuardVerdict` と `TrialPhase` を
  検証する表を追加した（7 行）。あわせて軸2真理値表に `in-progress` /
  `failed-transition` の境界 6 行を追加した。

## [Warning] 離脱リンク節の説明が手動判定への変更を反映していない（施策3）
- 判断: **対応する**
- 根拠: Round 3 で手動記録へ変更したのに、施策 3 側に
  「押下時に `away-navigation-started` を同期記録するので intercept を検出できる」が
  残っていた。私の消し漏れ。
- 対応内容:
  - 「`away-navigation-started` は**操作事実のみ**を記録し、
    離脱失敗は利用者の手動記録によってのみ `invalid-wrong-route` とする」に統一
  - 画面の操作一覧に **「離脱失敗を記録」** を追加
  - 操作ボタンの活性を `deriveTrialPhase()` の許可表に従わせることを明記

## APPROVE された施策
施策 2 / 施策 4 / 施策 6 / 施策 7
