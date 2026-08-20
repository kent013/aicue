# 対応マトリクス: design-review Round 5 (APPROVED)

全体判定 **APPROVED**。施策 S1〜S11 と実装モード・受入条件のすべてが APPROVE。

## [Suggestion] 「既存台帳が `role: template`」の exit 3 と、context DTO の入力違反の exit 1 を混同しない
- 判断: 対応する (承認を妨げない指摘だが、実装時に取り違えやすいので設計へ書いた)
- 対応内容: S4 に「ガードによる拒否 (exit 3) と context DTO の入力違反 (exit 1) は**別の例外型**で
  区別する (`GenerationRefused` / `RuntimeException`)。同じ例外型で理由文字列だけ変える形にしない」を追記。
  あわせて exit 3 の表の行に「同じ入力で母集合を縮小しようとした」を明示 (拒否 4 経路と表を一致させた)
