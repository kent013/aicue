# 対応マトリクス: design-review Round 3

Round 3 で全施策 APPROVE / 全体 APPROVED。Critical/Warning は残存なし。

## [Suggestion 施策4] partial mock snippet の `actualParse` がスコープ外
- 判断: 対応する（非 blocking だが正確性のため反映）
- 対応内容: real 実装を `vi.hoisted` の holder（`holder.real`）に保持し、`beforeEach` で
  `holder.mock.mockImplementation(holder.real)` により既定を real へ復帰。fail-safe テストは
  `holder.mock.mockReturnValueOnce(null)` のみ使用（通常実装を恒久上書きしない）。
  → テスト実装メモの snippet を修正済み。

## 結論
- 概念設計: APPROVED（conceptual Round 4）
- 詳細設計: APPROVED（design Round 3）
- 使命整合・禁止事項・コーディングルール（フロント該当分）を満たすことを確認。
