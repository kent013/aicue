# 対応マトリクス: design-review Round 3（CHANGES_REQUESTED / Critical 6・Warning 3）

全指摘に対応（反論なし）。**Critical 2（同期ラグ組織の締め出し）と Critical 3（reserve が debt を無視）は実バグ**。

## [Critical] NoPlan 分離が正規契約以外へ反映されていない
- 判断: 対応する / 対応: **D18 を D23 で上書き**と明記（当初 `GrandfatheredLegacyFreePlan` を変更対象と書いたのは誤り）。
  P2 の解決表・テスト期待を 4 variant へ（`free_plan_code=null` + paid なし → **NoPlan**）、P2 波及の「+3 variant」→「+4 variant」、
  P4 の変更対象を `NoPlan::grantsAccess()` へ、AI-CUE 側の `state()` 表記を全て `effectivePlan()` へ置換
  （aigenba 側の実装名として言及している箇所のみ、その旨の注記付きで残置）。

## [Critical] webhook 同期ラグ組織が P4 で遮断される（**実バグ**）
- 判断: 対応する
- 対応: **D26** を新設。`effectivePlan()` の paid 判定を **`plan_code` に依存させず、active/trialing subscription があれば
  最優先で `PaidSubscriptionPlan`** へ解決する。plan code は subscription の price から org-scoped に解決し、
  **解決不能時は grandfather 側へ倒す**（F-07 保全優先 = 締め出さない）。
  「`plan_code=null` + active sub」の **P4 後アクセス継続テスト**を追加。

## [Critical] debt が表示計算にしか入っておらず reserve 配賦が債務を無視（**実バグ**）
- 判断: 対応する
- 対応: **D27** を新設。`balance()` / `availableTrueBalance()` / `reserve()` / auto-recharge は**同一 snapshot** を使い、
  不足判定を **`availableTrueBalance < amount`** に統一。配賦は
  `debtAmount = max(-(purchasedRaw), 0)` / `monthlySpendable = max(monthlyPositive - debtAmount, 0)` /
  `purchasedSpendable = purchasedPositive`。**`debt` は raw ledger 負値から算出し hold とは分離**（予約 hold で増減する
  債務ではない）。**`monthly=10 / debt=2` で `reserve(8)` 成功・`reserve(9)` 失敗**を必須テスト化。

## [Critical] D21 適用後も P3 Controller が route model binding 前提
- 判断: 対応する / 対応: Controller 引数から `Organization` を削除し `ResolvesCurrentOrganization` 経由で明示解決。
  **current organization 不在は 404 / current organization がユーザー所属でない不正状態も 404**（セキュリティ不変条件 #2
  「子は親に属する: 認可より前に 404」と整合）。`MembershipScopedOrganizationBinder` 前提のテスト記述を current-org 解決テストへ置換。

## [Critical] D11 の free 削除が Seeder 変更だけで本番既存行を削除できない
- 判断: 対応する（`updateOrCreate` Seeder は削除しない、は正しい）
- 対応: P4 に **data migration `remove_free_plan_row`** を追加。(1) `organizations.plan_code='free'` の参照行・関連
  `plan_prices` の残存を**事前検証し、残っていれば fail-closed**（黙って消さない）、(2) `plans` から `code='free'` 削除、
  (3) **末尾で残余 0 件を検証**。rollback は `down()` で行復元 + `fallback_plan` を `'free'` へ戻す。
  併せて **personal/starter の再公開タイミング**を確定（**P8b で `is_active=true` へ切替**。非公開のまま放置しない）。

## [Critical] D25 が P8b 本文へ完全反映されていない
- 判断: 対応する / 対応: P8b から **billing contact / feedback / サブスク checkout 用 attempt token を完全削除**
  （消化台帳 #9・Index 変更・新規 `BillingContactForm.svelte`・TS shape・`BillingPlansPageProps`・Plans テスト・submit の
  `attempt_token` すべて）。関連 DTO/props/UI/テストは **P9 へ移動**。P8b の Plans は既存 checkout 契約のみで成立させる。

## [Warning] P1 の is_active 反映に古い記述が残る
- 判断: 対応する / 対応: 「料金表件数が 2 件増える」→ **D10 で解消（件数不変）**へ、P3 の「is_active 列が無いので filter しない」→
  **P1 で導入済み・`PricingService` は active のみ返す（P3 が表示する Plan 集合も active のみ）**へ統一。

## [Warning] P6 責務境界が変更表に反映されていない
- 判断: 対応する / 対応: P6 変更表の当該行を「**activate() は P1 で完成済み → P6 は変更なし・回帰確認のみ**」に変え、
  `claimSignupGrantMarker()` の **private 化（D13）** を変更表へ追加。

## [Warning] TicketBalanceDto の shape に debt 反映が不完全
- 判断: 対応する / 対応: `TicketBalanceDto` を「aigenba verbatim + **`debt: int`（正数）**」と明記し、
  `totalAvailable()` を**債務控除後の非負値**と定義。PHP shape / TS shape / constructor / テスト fixture / **P8b の
  per-bucket 表示（debt 表示・説明）** すべてに反映。
