# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED** (Critical/Warning なし、Suggestion 2 件のみ)。合議終了。

## [Suggestion] ManualTestSeeder: Cashier 必須列変更時の保守メモ
- 判断: 見送る
- 根拠: `attachFakeActiveSubscription` の docblock で既に「subscription('default') が active を返すための最小カラムのみを設定する」と意図を明記済み。テスト helper `createFakeSubscription` (tests/Pest.php) と同一の生成経路・列であり、Cashier の必須列が増えれば両者同時に test が fail して検知できる。追加メモは冗長で over-engineering のため見送る。
- 対応内容: 変更なし。

## [Suggestion] SeededFreePlanBillingAccessTest: 有償 plan 取得を `?? throw` に
- 判断: 見送る
- 根拠: 詳細設計の骨子どおり `expect($paid)->not->toBeNull()` で担保しており、Codex も「現状でも実害はなし」と明記。テスト内の後続参照は `$paid?->` で null 安全に閉じており PHPStan level 10 を通過済み。設計との一致性を優先し現状維持。
- 対応内容: 変更なし。
