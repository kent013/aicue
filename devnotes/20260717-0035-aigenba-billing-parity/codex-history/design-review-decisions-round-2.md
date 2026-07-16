# 対応マトリクス: design-review Round 2（CHANGES_REQUESTED / Critical 5・Warning 4）

全指摘に対応（反論なし）。特に Critical 1・3 は**私の設計の実バグ**であり、指摘が無ければ実装時に金銭事故 or F-07 再発になっていた。

## [Critical] D18 の variant 分離が成立していない（GrandfatheredLegacyFree が「backfill 済み既存」と「新規未契約」を畳んでいた）
- 判断: 対応する（**実バグ。指摘が正しい**）
- 対応: **D23** を新設し `NoPlan` variant を追加。**4 variant** に分離し解決順を正規契約として明文化:
  `paid → PaidSubscriptionPlan` / `personal + declarer あり → ActivatedPersonalPlan` /
  `personal + declarer なし → GrandfatheredLegacyFreePlan` / `free_plan_code なし → NoPlan`。
  **P4 の変更は `NoPlan::grantsAccess()` を false にする 1 点のみ**（他 variant 不変 = 既存ユーザーは締め出されない）。
  `EffectivePlanKind` に `no_plan` を追加、PHP/TS shape も更新、`EffectivePlanResolutionTest`（解決順 + 4 variant の
  grantsAccess）を P2 に必須化。

## [Critical] D21 が P3/P4/P7 に適用されていない
- 判断: 対応する
- 対応: P3 の route 定義を **route parameter 無しの current-org スコープ**へ全面変更（`onboarding.{checkout,
  activate-personal,billing-required}`）。`{organization:slug}` バインド・`isCurrentOrganization` prop・組織切替 CTA・
  org-slug 非対称リスク・cross-org 課金リスク行を**削除**（current-org 解決により構造的に発生しないため）。
  P4 の `state()` を全て `effectivePlan()` へ。P7 の continuation は**組織 ID を保持しつつ membership 確認後に
  引数なしの `route('onboarding.checkout')` を生成**する形へ修正。

## [Critical] D19 の「付与時に debt を先に相殺」が二重相殺になる
- 判断: 対応する（**実バグ。指摘が正しい**）
- 根拠: purchased raw `-2` に `+10` を積めば台帳合計は**自然に** `8`。そこで grant を `8` へ減額すると `6` = 二重回収。
  また source 別 clamp のみだと monthly grant `+10` / purchased debt `-2` で利用可能額が `10` になり債務が回収されない。
- 対応: **D24** を新設。**grant 行の `delta` は変更しない**（書込み側で相殺しない）。相殺は**残高計算で一度だけ**:
  `monthlyPositive = max(monthlyRaw - monthlyHold, 0)` / `purchasedPositive = max(purchasedRaw - purchasedHold, 0)` /
  `debt = min(purchasedRaw - purchasedHold, 0)` / `availableTrueBalance = max(monthlyPositive + purchasedPositive + debt, 0)`。
  `totalAvailable()` も債務控除後の非負値。**DTO 境界では debt を正数**に固定。テストは purchased/monthly/signup/
  auto-recharge の**各 grant 経路で債務が一度だけ回収される** + **monthly grant 失効後も未回収債務が残る**を必須化。

## [Critical] D10/D11 が実際の変更一覧に落ちていない
- 判断: 対応する
- 対応: **D10** = P1 に `plans.is_active` の全波及を追加（migration / Model cast / Seeder（personal・starter は
  `is_active=false` で seed）/ `PricingService` の active filter / `PlanActiveFilterTest`）。P3 の「is_active 列が無いので
  filter しない」という否定記述は当該箇所ごと削除済み。
  **D11** = P4 に free 撤去の実変更を明記（`PlanSeeder` から `free` 行削除 / `config/quota.php` の `fallback_plan` を
  **`personal`** へ切替（限度値は旧 free と同値のため実効 limits 不変 = ユーザー影響なし）/ `QuotaService` 回帰テスト /
  Factory・既存テストの `'free'` 参照を `personal` へ更新（削除しない）/ `SeededFreePlanBillingAccessTest` の期待更新）。
  **grandfathered の quota キーは `personal`** に確定。

## [Critical] P8b が P2 に存在しない成果物を前提としている
- 判断: 対応する
- 対応: **D25** を新設。`BillingCheckoutSession` 相当 / `resolveBillingFeedback` / billing contact 列・更新 Action /
  `BillingContactShape` は P2 の非スコープで存在しないため、**P8b から除外し独立フェーズ P9 へ切り出す**。
  P8b の `BillingDashboardShape` から `billingContact` / `feedback` を除去（併せて D18 に従い `currentPlanCode` scalar →
  `effectivePlan` DTO へ）。実装 TODO は **10 本**（P1..P8b + P9）に更新。

## [Warning] P1 と P6 の Personal activate 責務が重複
- 判断: 対応する / 対応: **P1 で `activate()` 側を完成**（marker claim + grant 含む）。**P6 は (a) 登録経路からの旧 grant 撤去 /
  (b) paid webhook への claim+grant ブロック追加 / (c) `claimSignupGrantMarker()` の private 化（D13）** の 3 点のみ、と明記。

## [Warning] D16 が P7 に適用されていない
- 判断: 対応する / 対応: P7 へ `Welcome.svelte` の `/register` 直リンク 3 箇所（L137/160/358）→ `/pricing` 変更と
  `tests/js/pages/Welcome.test.ts` 更新を明記（P8b 所管ではなく **P7 所管**）。

## [Warning] D4 違反が P3 の JS テストに残っている
- 判断: 対応する / 対応: `OnboardingCheckout.test.ts` の期待を「**押せる状態を維持し、押下後に理由 / validation error を
  表示**」へ変更（ineligible 時の CTA 無効・declaration 未チェックで submit 不可 を撤回）。

## [Warning] D22 の同値アサートがテスト本文に無い
- 判断: 対応する / 対応: `GrandfatherFreePlanBackfillTest` に「**expected ID 集合と実更新 ID 集合の双方向完全一致**」を明記。
