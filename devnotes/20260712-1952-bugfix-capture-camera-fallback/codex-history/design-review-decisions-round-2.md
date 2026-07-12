# 対応マトリクス: design-review Round 2 (全体判定: APPROVED)

全施策 APPROVE・全体 APPROVED。残存 Critical/Warning なし。

## [Suggestion] 再入テストの pending Promise を検証後に resolve/reject して完了させる
- 判断: 対応する
- 根拠: 未解決 Promise の残置はテストファイル跨ぎの間欠 fail 要因になり得る (本リポジトリは setup.ts でも同種の後始末を明示する文化)。
- 対応内容: detailed-design.md のテスト計画 (再入ガードのケース) に「検証後は deferred を reject して処理を完了させ、未解決 Promise をテスト間に残さない」を明記。
