# 対応マトリクス: design-review Round 1

施策 A/B/C は APPROVE。施策 D の 6 Warning に対応。

## [Warning] 未契約 + 非管理者 → billing-required の回帰テストが不足
- 判断: 対応する
- 根拠: ActiveFreePlan+非管理者→dashboard と最も取り違えやすい境界。
- 対応内容: テスト #6 (未契約 + manageBilling 非保持 → onboarding.billing-required) を新規追加し、8 行境界表に明示。

## [Warning] continuation テストの契約状態が active/free で曖昧
- 判断: 対応する
- 対応内容: continuation テストを ActiveFreePlan (free_plan_code=personal) に固定。paid Subscribed 非管理者は直接アクセス #2 で担保。continuation は verification→onboarding.checkout→dashboard の接続確認に限定。

## [Warning] 既存 owner continuation テストの保証範囲を過大表現
- 判断: 対応する
- 根拠: 既存テストは第一段 redirect + session 消去のみ固定、最終 billing.index 着地は未固定。
- 対応内容: 主張を「continuation 第一段 + session 消去の回帰防止」に狭め、owner の最終分岐着地は直接アクセス #1/#3 で担保と明記。

## [Warning] dashboard 描画テストが不具体 (component 名/302と200の分離)
- 判断: 対応する
- 根拠: 実 component 名は 'Dashboard' (DashboardController の Inertia::render('Dashboard') / DashboardTest.php line 56)。
- 対応内容: 302 確認 → 別 GET → 200 + `component('Dashboard')` の 3 段に分離。業務導線の存在自体は不変条件にせず「課金ゲートに阻まれず 200 で開く (soft dead-end でない)」のみ固定。

## [Warning] 完了条件がリポジトリ必須の検証コマンドを満たさない
- 判断: 対応する
- 対応内容: 完了条件に AGENTS.md の全検証コマンド (composer test / phpstan / pint --test / pnpm lint / typecheck / test / build / typecheck:packages / build:packages / test:packages) を追加。テストファースト赤の記録・A/B/C の本ブランチ実行緑の記録も追加。

## [Warning] screens.md の扱いが変更一覧と完了条件で不整合
- 判断: 対応する
- 対応内容: `.claude/skills/app-bug-hunt/screens.md` を施策一覧の変更ファイルに追加し、挙動変更と同一 PR で更新すると明記。

## [Suggestion] DESIGN.md / Atomic Design 追加対応不要
- 判断: 反論不要 (同意。UI コンポーネント/Props 変更なし)
