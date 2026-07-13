# 対応マトリクス: design-review Round 2

全体判定 **APPROVED**（全 7 施策 APPROVE、新規 Critical/Warning なし）。

Round 1 の全指摘の解消を確認:
- [Critical] 施策3 latest('id') → 「最新 = id 降順（既存契約）」を固定。
- [Critical] 施策7 テスト先行 → 具体テスト名 + red→green 実装順を規定。
- [Warning] 施策5 strlen(bytes) → token budget の既存不変条件として byte 基準を維持と明記。
- [Warning] 施策7 save 実経路 → `ScenarioService::save()` 統合テストを追加。
- [Warning] 施策2 preview lock → preview 失敗の manual.status 不変を回帰テストで固定。

追加対応なし。詳細設計を最終版として確定。
