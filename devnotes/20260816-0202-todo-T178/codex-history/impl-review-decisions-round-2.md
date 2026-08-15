# 対応マトリクス: impl-review Round 2

Round 1 の [Critical] と guest の `sessionEpochMatches` に関する [Warning] は
Codex 側が読み違いとして撤回した。Round 2 の指摘は 1 件のみ。

## [Warning] detailed-design.md の「次の 6 点」が実際の 7 項目と食い違っている

- 判断: **対応する**
- 根拠: そのとおりで、補正 7 (S2 の遅延評価を専用 route で固定する) を Round 1 の対応で
  足したときに前置きの件数を直し忘れた。正本を名乗る文書の内部矛盾は残さない。
- 対応内容: `devnotes/20260815-2103-bfcache-session-generation-cookie/detailed-design.md` の
  「次の 6 点に直した」を「次の 7 点に直した」へ修正した。
