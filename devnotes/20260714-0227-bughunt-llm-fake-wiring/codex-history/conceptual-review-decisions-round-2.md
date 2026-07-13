# 対応マトリクス: conceptual-review Round 2

## [Warning] queue worker + scenario materialize を通した統合テストが必須一覧に無い
- 判断: 対応する
- 根拠: 成功判定に「queue worker」「materialize」を掲げた以上、それを end-to-end で確認するテストが無いとテストなし完了報告の余地が残る。
- 対応内容: 必須テストに「`RunManualAnalysis` を実際に dispatch・実行 → 3 DTO 通過 → ジョブ成功 → scenario materialize → 実 API 未到達 (StrayLlmCallGuard で担保)」の統合テストを追加。

## [Warning] signature 衝突: 対象外 prompt が既知 signature を偶然含む可能性
- 判断: 対応する
- 根拠: 4 対象の 1:1 テストだけでは、将来追加/既存の対象外 prompt の system message が登録済み signature を偶然部分一致し、誤った canned へ silent dispatch する穴が残る。
- 対応内容: 必須テストに「全実 prompt (resources/prompts/*.yaml から列挙) を横断し、登録対象 prompt はちょうど 1 件の signature に一致・未登録対象 prompt は 0 件に一致することを検証する衝突防止テスト」を追加。signature は部分一致で誤爆しない一意句を選ぶ前提を明記。
