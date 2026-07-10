# 対応マトリクス: conceptual-review Round 2

全体判定: APPROVED（Critical / Warning なし）

## [Suggestion] 共有ロック不変条件の形骸化防止（Architecture テスト or 明示 inventory）
- 判断: 部分対応（詳細設計に将来ガードの布石を明記）
- 根拠: 「cuts/scenario_version/status を書く経路」を静的に完全列挙する Architecture テストは
  現時点で書き込み経路が ScenarioService 1 つのため、機械検証対象が存在しない。
  過剰設計（今必要なものだけ作る）を避け、規約の正本を AGENTS.md ドメイン固有規約 +
  docs/architecture.md に置く（施策 8）。後続フェーズ（AI 解析 materialize / RenderJob /
  adopt API）で書き込み経路が 2 つ以上になった時点で、経路 inventory を持つ
  Architecture テストへ昇格させることを docs/architecture.md の規約文に明記する。
- 対応内容: detailed-design.md 施策 8 に「経路が増えた時点で inventory 化」の一文を追加。
