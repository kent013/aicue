# 対応マトリクス: design-review Round 5

**全体判定 APPROVED**。Critical / Warning は 0 件。Suggestion 2 件はいずれも反映した。

## [Suggestion] S5 M17 が「重複委譲」と「余剰委譲」の 2 操作を含む

- 判断: **対応する**
- 根拠: 1 項目に 2 操作を束ねると、実装記録に「どちらの操作で赤くなったか」が残らない。
- 対応内容: M17（委譲の重複）と **M18（必須表に無い余剰委譲）** へ分割し、coverage 表・ID 表・実装順序を同期した。実装順序 7 に「**1 mutation = 1 操作**とし、結果は mutation ID ごとに個別に記録する」を明記した。

## [Suggestion] S8 gate 冒頭の誤字「名乗ってよる種別」

- 判断: **対応する**
- 対応内容: 「名乗ってよい種別」へ修正した（設計本文の全出現）。

## 最終確認（app-design SKILL 2-5）

| 観点 | 結果 |
|------|------|
| 全施策が使命 (AGENTS.md North Star) に寄与するか | 寄与する（間接）。bug-hunt を安全に回し続けるための前提整備であり、§3 で「検知 v1 であり遮断ではない」と限定済み |
| 禁止事項 1（テストなしの実装完了報告） | 全 8 施策にテスト計画（ファイル名 + テストケース名）あり。不変条件は Architecture テストへの登録まで含む |
| 禁止事項 2（PHPStan の widen / baseline） | 該当なし。全施策に PHPStan 適合チェックあり |
| 禁止事項 3（dev DB への破壊操作） | 該当なし |
| 禁止事項 4（`response()->json()` 直書き） | 該当なし（HTTP 応答を追加しない） |
| 禁止事項 5（Prism 直呼び） | 該当なし。むしろ `PrismDirectDispatchScanner` の委譲結線で強化する |
| 禁止事項 6（prompt 文字列の直書き） | 該当なし |
| 禁止事項 7（操作系 POST の `redirect()->intended()`） | 該当なし |
| 禁止事項 8（disabled ボタン） | 該当なし（UI 変更なし） |
| 禁止事項 9（Artifact の使用） | 使用していない。成果物はすべて `devnotes/` 配下のファイル |
| `RefreshDatabase` グローバル適用 / 個別 `DatabaseTransactions` 禁止 | 全テスト計画で明示的に確認済み |
| テストデータは Factory | 新モデルを追加しないため該当なし（合成ソース文字列と config のみ） |
| DTO + JsonResource | 目録・走査結果はすべて `readonly` value object。array shape をコレクション要素に使わない |
