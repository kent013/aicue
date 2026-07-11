# 対応マトリクス: conceptual-review Round 4

全体判定: **APPROVED**（Round 4）

## [Suggestion] `resolveForUpdate()` の利用を Architecture テスト / 書き込み経路 inventory で固定
- 判断: 部分的に対応する（詳細設計のテスト計画で扱う）
- 根拠: pivot 書き込み経路は `OrganizationMembershipService` の 2 メソッド（ロール変更コマンド適用・招待受諾）に閉じており、専用 inventory テストは現時点では過剰。まずは各書き込み経路の Feature テスト（削除競合時のエラー/未割当挙動）で挙動を固定し、経路が 3 つ以上に増えた時点で ScenarioWritePathInventoryTest と同型の inventory 昇格を検討する（詳細設計のリスク欄に明記）。
