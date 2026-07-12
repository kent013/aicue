# 対応マトリクス: conceptual-review Round 1

全体判定: APPROVED (Round 1)。Critical なし。Warning 3 件はすべて対応し、概念設計・詳細設計に反映する。

## [Warning] 観点3: `plan_code === null` = free entitlement の暗黙契約への依存
- 判断: 対応する
- 根拠: 指摘のとおり「plan_code 非 null は有償課金ライフサイクル管理下でのみ set される」は
  webhook 実装 (StripeWebhookProcessor::syncPlanCode / clearPlanCode) の暗黙契約であり、
  将来「請求不要の特別プラン」等で plan_code を流用すると誤遮断になる。
- 対応内容: 概念設計に不変条件「plan_code は有償プラン (Stripe Price を持つプラン) の契約時のみ
  webhook が set する。支払い不要プランは plan_code に載せない (null = 支払い不要 tier)」を明文化。
  BillingAccess / StripeWebhookProcessor / PlanSeeder の docblock と docs/architecture.md に記載し、
  Feature テスト (plan_code set + subscription 不健全 → 遮断、plan_code null → 許可) で挙動を固定する。

## [Warning] 観点5: JSON/XHR 402 メッセージの現行維持は H1 を API 経路で温存する
- 判断: 対応する
- 根拠: 判定変更後、遮断される唯一の理由は「有償プラン契約中の支払い不健全」。
  現行 402 文言「有効なサブスクリプションがありません。お支払いを完了してください。」は
  free 組織向けの誤解を招く文言であり、redirect flash と意味論を揃えるべき。
- 対応内容: flash と 402 を同一文言
  「サブスクリプションのお支払いが確認できないため、ご利用を一時停止しています。お支払い方法をご確認ください。」
  に統一 (middleware 内の単一定数に寄せる)。HTML redirect + flash / JSON 402 の両経路を Feature テストで固定。

## [Warning] 観点7: リネーム (`has_billing_access`) の波及漏れリスク
- 判断: 対応する
- 対応内容: 詳細設計の波及変更チェックで DTO / DashboardService / dashboard.ts / Dashboard.svelte /
  Dashboard.test.ts / DashboardTest.php を全列挙。Feature テストで Inertia payload key を
  `dashboard.billing.has_billing_access` に固定し、旧キー (`has_active_subscription`) が
  リポジトリに残らないことを実装時に grep で確認する手順を明記。

## [Suggestion] 受け入れ条件の明文化 (観点1) / entitlement と resource gate の分離 (観点4)
- 判断: 対応する (低コストで判定のブレを防げる)
- 対応内容: 概念設計のテスト計画に受け入れ条件として明記
  (Free 組織で /projects・POST /projects・/app が通る + Quota/チケット gate は残る)。

## [Suggestion] 理由文言の単一化 (観点3) / hasActiveAccess docblock (観点7) / fail-closed 回帰テスト (観点5)
- 判断: 対応する
- 対応内容: 文言は middleware 内の単一定数。BillingAccess docblock に
  「subscription の有無ではなく billing entitlement を返す」を明記。
  plan_code set + subscription 行なし → 遮断の回帰テストを明示的に計画に入れる。
