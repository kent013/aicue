## 施策別判定

| 施策 | 判定 | コメント |
|---|---|---|
| 1. bug-hunt DB 判定 SSOT | **APPROVE** | 判定境界、委譲、テスト計画とも妥当です。 |
| 2. LLM コスト集計 | **APPROVE** | null コスト、期間境界、UTC、DTO 契約が明確です。 |
| 3. 期間集計コマンド | **APPROVE** | 入力検証、終了コード、JSON 出力契約に問題ありません。 |
| 4. ダミー SOP fixture | **APPROVE** | 実際の抽出ゲートを通す behavioral test が適切です。 |
| 5. pipeline smoke 本体 | **APPROVE** | Round 3 の分類不整合は解消されています。 |
| 6. fake 参照 allowlist | **APPROVE** | 例外範囲と遅延解決条件が必要最小限に固定されています。 |
| 7. bug-hunt 起動導線 | **APPROVE** | orchestrator gate、実LLMモード、option 転送境界が明確です。 |
| 8. ドキュメント | **APPROVE** | 実装上の分類語彙と保証範囲が一致しています。 |
| 9. テスト | **APPROVE** | 正方向・負方向を含む分類判定表が十分に固定されています。 |

施策5では、次の境界が矛盾なく確定しました。

- 成功した段は `null`
- `queued` timeout は `Wiring`
- `running` timeout は `StageTimeout`
- `Llm` は `Analysis` / `LlmEvidence` に限定
- artifact 読み出し不能は `Storage`
- 読み出し後の ffprobe failure は `Render`
- その他の失敗は `Unknown`

判定順と12ケースのテスト表も対応しており、リトライ痕による誤分類を防げています。

## 全体判定

**APPROVED**

設計上の Critical / Warning はすべて解消されています。実装時は設計どおりテストファーストで進め、記載された `composer test`、`composer phpstan`、Pint、bug-hunt self-test の実行結果をもって実装完了を判定してください。