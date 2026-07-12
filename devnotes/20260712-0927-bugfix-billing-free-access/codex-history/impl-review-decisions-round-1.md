# 対応マトリクス: impl-review Round 1

総合判定: **APPROVED**(Critical なし)。以下 Warning/Suggestion への判断を記録する。

## [Warning] tests/Pest.php の contractPaidPlan() が plan_code='standard' を直書き
- 判断: 見送る
- 根拠: 'standard' は PlanSeeder が投入する seeded fixture の参照であり、その意図は
  `tests/Pest.php` の docblock に明文化済み(「プラン名分岐ではなく seeded fixture の参照。
  アプリコードには入らない」)。テスト専用の参照のためにアプリ config
  (`quota.default_paid_plan` 等) を新設するのは「今必要なものだけ作る」原則
  (AGENTS.md 思考原則 2) に反する。PlanSeeder 変更時はテストが即 fail して
  気づける構造であり、実害はテスト限定と Codex 自身も評価している。
- 対応内容: なし(プラン体系を可変化する将来タスクが発生した時点で定数化を検討)

## [Warning] expectsJson() 判定は Accept ヘッダが曖昧なクライアントで HTML redirect 経路に入る余地
- 判断: 見送る
- 根拠: `expectsJson()` は Laravel 標準のコンテンツネゴシエーションであり、
  本アプリのフロントは Inertia/XHR が適切な Accept を送る同一オリジン構成
  (v1 スコープ: PWA・セッション認証)。曖昧な Accept が HTML redirect に落ちても
  billing への誘導 + flash 表示という安全側の挙動になる。JSON 経路は
  `RequireActiveSubscriptionMiddlewareTest` の `getJson` ケースで固定済み。
- 対応内容: なし

## [Suggestion] Plan 側に「課金必須プランか」の明示フラグ導入
- 判断: 見送る
- 根拠: plan_code 不変条件は docblock + docs + 固定テストで担保済み(施策 4)。
  フラグ追加は現時点で不要なオーバーエンジニアリング。プラン追加タスクで再検討。
- 対応内容: なし

## [Suggestion] BillingAccess::hasActiveAccess() の改名 (hasBillingEntitlement 等)
- 判断: 見送る
- 根拠: Codex 自身が「今回は互換優先で据え置きは妥当」と評価。呼び出し箇所への
  波及を伴うリネームはバグ修正 TODO のスコープ外。
- 対応内容: なし(可読性改善タスクとして必要になれば別 TODO で対応)
