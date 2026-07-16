全体判定: **APPROVED**

Round 2 の Critical は閉じています。amount ベース reserve の維持も妥当です。parity はドメイン上の意味・不変条件を揃えることであり、異なる課金単位のAPI形状まで機械的に一致させることではありません。

## 1. 使命との整合性

[Suggestion] North Star の支持基盤として適切に限定されています。可変コスト reserve は Analysis/Render の実課金モデルを守るため、使命と整合します。

## 2. 禁止事項・不変条件

[Suggestion] reserve→commit/release、冪等マシン、DTO、テストファーストを維持しており問題ありません。

## 3. 実現可能性

[Warning] P1からP6までの移行期間に発生する新規登録grantについて、規約からは読み取れますが、P1のフェーズ記述では明示が弱いです。

修正提案: P1に「backfill後の旧登録時grantも、P6までは従来の契機を維持しつつ、orgロック下でmarkerを同時設定する」を追記し、この期間に登録されたorgをP6後にactivateしても再付与されないテストを追加してください。

## 4. 期待効果

[Suggestion] 段階的な濫用防止、grandfathered orgが自然収束しないこと、会計精緻化の効果が適切に限定されています。

## 5. リスク

[Suggestion] P5で詳細化予定の「既存reserved行の扱い」は重要です。`consume_source`未設定の旧予約について、commit時に再配分するのか、移行時に固定するのかを詳細設計で必ず確定してください。

## 6. スコープ

[Warning] 事実訂正前の表現が一箇所残っています。

「改善アイデア」の撤去対象にある「source 分割台帳」は、実際には維持・精緻化するため削除が必要です。

またテスト計画末尾の「P3/P9」は、現行フェーズでは「P3/P8b」です。

修正提案: 上記2箇所を機械的に訂正してください。

## 7. 型安全性

[Suggestion] `PlanCode` enum、`EffectivePlan` variant、`SubscriptionSnapshot`、`TicketBalanceDto`への集約でPHPStan level 10に適合可能です。

重点3点であるフェーズ順序、grandfathering、signup grant冪等移行はいずれも概念設計として閉じています。P5 rollbackも「台帳切替ではない」という訂正後の前提なら成立します。