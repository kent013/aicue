# 対応マトリクス: design-review Round 2

判定 REQUEST_CHANGES。Critical 0 / Warning 1 / Suggestion 1。**両方対応**(反論なし)。

## [Warning] M5b は契約 4 を破れない (`null > 0` は false)

- 判断: **対応する**
- 根拠: 完全に正しい。`!== null` を外しても後続の `> 0` が残るため、
  `placeholder_cut_count=null` では依然 `playbackNote=null` になり注記は出ない。
  **mutation として成立していなかった**。
- 対応内容: M5b を「**`null` を表示値へ通すよう分岐を変える**」(テンプレートの表示条件を常時表示に
  する / sentinel を返す) に再定義し、「`!== null` を外すだけでは破れない」理由も表に明記した。

## [Suggestion] 本文 assert は空白正規化する matcher を使え

- 判断: **対応する**
- 対応内容: 契約 1 に「DOM の改行・空白を正規化する `toHaveTextContent` を使う」と明記した。
