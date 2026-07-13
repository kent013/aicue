# 対応マトリクス: conceptual-review Round 5

全体判定 **APPROVED**（Critical/Warning なし。全て Suggestion）。

## [Suggestion] 実装テストで固定すべき受け入れ仕様（Codex 締め指摘）
- 判断: **対応する（詳細設計のテスト計画に反映）**
- 対応内容: 以下を Feature テストの受け入れ仕様として固定する:
  1. no-op 保存後の failure null 化（保存世代基準の意図的仕様）。
  2. legacy `scenario_version_at_terminal = null` は not stale＝表示（保守的）。
  3. `scenario_version_changed` CTA 保持（snapshot=失敗確定時 version）。
- その他の Suggestion（lock 順 job→manual、unsignedInteger nullable、fail-safe 残存エッジ）は
  すべて概念設計に反映済み。詳細設計へ引き継ぐ。
