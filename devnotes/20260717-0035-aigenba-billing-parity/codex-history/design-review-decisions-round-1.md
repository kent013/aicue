# 対応マトリクス: design-review Round 1（CHANGES_REQUESTED / Critical 5・Warning 3）

指摘はいずれも**並列起草の継ぎ目**を突いており、全て対応した（反論なし）。

## [Critical] 判定モデルの二重化（P2↔P4: EffectivePlan 系 と OnboardingBillingState 系の混在）
- 判断: 対応する
- 対応: **D18** を新設。`EffectivePlan` を**唯一の判定源**に固定し `OnboardingBillingState` は**導入しない**。
  P4 は `GrandfatheredLegacyFreePlan` variant の `grantsAccess()` の扱いだけを変更する。P4 本文（波及変更 / 主要な契約 /
  PHPStan 欄 / 未決事項）を `effectivePlan()` 前提へ全面書き換え。
- 補足根拠: aigenba は 2 段（`OnboardingBillingState` + `SubscriptionEntitlementDto`）だが、AI-CUE には subscription
  checkout session テーブルが無く Pending/ExpiredCheckout 状態を表現できない = **移植対象が存在しない**（原則 4）。

## [Critical] P4 backfill: 「対象集合の SQL 定義」と「分類表」の同値検証が DoD に無い
- 判断: 対応する
- 対応: **D22** を新設。migration テストに「**SQL で更新された ID 集合 == 分類表で grandfather 対象と判定された ID 集合**」の
  同値アサートを必須化（分類表を文書で終わらせず機械検証に落とす）。

## [Critical] P5 の U1（負残高）で「暫定 (a) clamp」と「横断決定 (b) 債務保全」が共存 = 仕様矛盾
- 判断: 対応する（**(b) 債務保全で一本化**）
- 対応: **D19** へ昇格し U1 を「ユーザー判断待ち」から除去。P5 本文の「暫定的に (a) を採用」を撤回。
  `TicketRefundClawbackTest:147` の `-2` 期待は**維持**。表示 clamp（`monthlyRemaining`/`purchasedRemaining`）と
  判定値（`availableTrueBalance()` 非負）を分離し、**DTO に `debt` を明示**、**付与時は debt を先に相殺**する
  （Codex の修正案どおり）。
- 根拠: clamp すると購入→消費→全額返金の債務が回収されず**タダ乗り経路**になる。**金銭の後退は parity に優先しない**。

## [Critical] P7 の `PlanCode::Enterprise` 除外分岐が D1（3 case）と矛盾
- 判断: 対応する
- 対応: `normalizeRaw` から Enterprise 特判を**削除**し `tryFrom` 結果のみで正規化（D2 の再確認）。契約シグネチャ・
  URL 契約・リスク欄の記述を全て更新。Enterprise は AI-CUE に存在しないため未知値として自然に null になる。

## [Critical] P8a の `reconcile` 停止時の運用要件が「注意喚起」止まり
- 判断: 対応する
- 対応: **D20** を新設。「`billing:reconcile-auto-recharge` の**監視アラート実装 / 既存監視への接続確認**」を
  P8a の DoD に必須化（設計項目として明文化）。本コマンドは webhook が恒久 drop した「課金済み・未付与」を回収する
  唯一の経路であり、静かに止まると資金回収済み・未付与が長期滞留する。

## [Warning] P1 の `/pricing` 露出制御にリスク欄で未決風の記述が残る
- 判断: 対応する / 対応: D10 確定（`plans.is_active` 移植で購入導線が揃うまで非公開）の記述へ更新。

## [Warning] P3 の route スコープ表記の揺れ（`organizations.onboarding.*` と `onboarding.*`）
- 判断: 対応する / 対応: **D21** を新設し `onboarding.{checkout,activate-personal,billing-required}` の **1 系統に統一**
  （current-org スコープ = D6）。既存 `onboarding.{mcp,cli}` は `Organizations/Onboarding` 配下の CLI/MCP セットアップで
  別物のため名前衝突が無いことを確認済み。

## [Warning] P8b の「disabled 禁止」原則が PurchaseTickets / PlanCard で二重定義
- 判断: 対応する / 対応: 共通 UI 規約は **D4**（横断決定）に集約済みであることを明示し、各画面は D4 を参照するのみとする。

## [Suggestion]
- U1 を人判断へ上げた切り分けは正しい → D19 で確定させたため残件から除去。
- D12（quota silent 退行防止）を invariant テスト化 → P1 のテスト計画に必須として明記済み。
