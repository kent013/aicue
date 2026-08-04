# 対応マトリクス: conceptual-review Round 4

## [Warning] `seen` の契約に記述不整合 (実装方針は「不可視も返す」/ 検証表は「入らない」) (観点 7)
- 判断: **対応する**
- 根拠: 指摘のとおりで、参照実装は「返す」側 (`visible:false` / `"gone"` を診断情報として `seen` に残す)。
  検証表の記述が古いままだった。なお `el.hidden` / 祖先 `aria-hidden="true"` は
  **記録時の足切り**で `seen` に入らない (こちらは実装どおり) ので、両者を分けて書く必要がある。
- 対応内容: 検証方法の表を次のように書き換えた。
  - `seen` には `visible:false` / `"gone"` が**診断情報として保持される**。
  - 一方 `el.hidden` / 祖先 `aria-hidden="true"` は**足切りで `seen` に入らない**。
  - finding 抑止判定 (証拠集合) に採用するのは `visible:true` のみ。
  この 3 点をテストの検証項目として分けて列挙した (実測済みの assertion E1 / F1 / C1 に対応)。

## [Suggestion] 観点 1〜6 の肯定コメント
- 判断: 対応不要 (方向性維持)
