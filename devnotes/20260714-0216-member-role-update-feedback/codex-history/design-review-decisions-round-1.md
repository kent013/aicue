# 対応マトリクス: design-review Round 1

全体判定は Round 1 で APPROVED。残 Warning 2 点を堅牢化として反映する。

## [Warning] S1: `pageErrors.role` の配列化に弱い
- 判断: 対応する
- 根拠: Laravel の error bag は文言を配列で持つ経路もある。現契約は文字列だが、正規化を一本化すると将来変更に堅牢。低コスト。
- 対応内容: `roleMessage` 派生を追加し、`Array.isArray` で先頭要素へ正規化。エラー表示・`aria-describedby` 条件・invalid 判定をこの一本の派生に集約。

## [Warning] S2: フォーカス復帰テストが `tick()` 依存で不安定化しうる
- 判断: 対応する
- 根拠: 明示待機で非決定性を下げる。testing-library の慣用に沿う。
- 対応内容: ケース6 を `await waitFor(() => expect(screen.getByTestId("member-role-2")).toHaveFocus())` に変更。

## チェック観点（Codex 追認）
- 正確性/既存整合/PHPStan/テスト網羅/Inertia Props/副作用/セキュリティ/DESIGN/Atomic すべて追認済み。追加対応不要。
