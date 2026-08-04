# 対応マトリクス: impl-review Round 3

Codex の全体判定は **APPROVED** (Critical / Warning ゼロ)。Suggestion 1 件のみ。

## [Suggestion] `declare -p CI` や動的な間接参照までは完全検出しないので、docblock の「漏れがない」は「通常の直接参照を deny-by-default」と書く方が厳密

- 判断: **対応する**
- 根拠: 静的検査が保証できる範囲を過大に書くのは、後から読む人に**実際より強い保証**を
  信じさせる (aicue が繰り返し避けてきた「保証とスコープの不一致」そのもの)。
  文言の修正だけで正確になるならコストはゼロ。
- 対応内容: `globalTestLockCiReferenceViolations()` の docblock から「漏れがない」を外し、
  **保証範囲**を明記した:
  - 検出するのは shell の通常の直接参照 (変数展開 / `-v` / `printenv` / `env | grep`)
  - `declare -p CI` や変数名を組み立てる間接参照までは意味論的に完全検出しない (静的検査の射程外)
  - 回帰防止としては十分 = 「CI バイパスを足す人が意図的に難読化して書く前提は取らない」
  と、判断根拠まで含めて書いた。

## 合議の終了

Round 1 APPROVED → (Suggestion 採用による追加変更) Round 2 CHANGES_REQUESTED →
Round 3 **APPROVED**。Critical / Warning は残っていない。
