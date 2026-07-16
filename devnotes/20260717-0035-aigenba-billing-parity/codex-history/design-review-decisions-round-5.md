# 対応マトリクス: design-review Round 5（CHANGES_REQUESTED / Critical 4・Warning 3）

再生成方式が有効と確認できた（P1/P4/P5/P8b はいずれも APPROVE）。残件はすべて対応。

## [Critical] 上位決定 D26 が旧仕様のまま（「price 解決不能時は grandfather 側へ倒す」）
- 判断: 対応する（**横断決定は下位セクションより上位なので、放置すると実装者が型を壊す**。指摘のとおり）
- 対応: D26 を **nullable `PaidSubscriptionPlan` 契約**へ置換。「price から解決できない場合も
  `PaidSubscriptionPlan(planCode: null, grantsAccess: true)` を返す（fallback quota + **ログ・監視必須**）。
  **`GrandfatheredLegacyFreePlan` へ倒してはならない**。`free_plan_code='personal'` でない組織を Grandfathered variant へ入れない」
  を明記し、当初案の撤回理由も併記。

## [Critical] D21 が 3 Controller すべてに適用されていない（P3）
- 判断: 対応する / 対応: **P3 を再生成**。3 Controller すべてを `Request` のみの引数へ統一し、
  `ResolvesCurrentOrganization` に `resolveMemberCurrentOrganization()` を additive 追加（current org 不在 → 404 /
  非所属 → 404 = **認可より前に 404**、不変条件 #2）。**同じ 404 テストを 3 route すべてに**。
  旧 route binding・`attemptToken` 残骸・`is_active` 否定記述を一掃。

## [Critical] P9 のサブスク checkout 冪等契約が不足
- 判断: 対応する / 対応: **P9 を再生成**し、要求された 9 項目を全て契約化:
  `UNIQUE(organization_id, subscription_attempt_token)` / `initiated_by_user_id` actor scope /
  pending・completed・failed・expired / 同 token 再送は既存 Checkout URL へ収束 / Stripe idempotency key 対応 /
  plan code 不一致の token 再利用は **422** / 他 org・他 user の token は **404** / success・cancel webhook との競合と再送テスト /
  tenant キーを payload から受け取らない Request 契約（`ProhibitsProtectedKeys`）。
  実装は AI-CUE 既存の `TicketCheckoutService`（冪等マシンの先例）と同型に寄せ、**`SubscriptionService::startCheckout()` を撤去**して
  生成経路を 2 本にしない。

## [Critical] P9 の PII 保護が name まで閉じていない
- 判断: 対応する（不変条件 #6 は email/name の**両方**が対象。指摘のとおり）
- 対応: `billing_contact_email` / `billing_contact_name` の**両方を CipherSweet 化**。列型は **`text()`**（ciphertext のため。
  `inquiries` の先例と同一判断。aigenba の平文 `string` は**移植しない** = 不変条件が parity に優先）。
  blind index は既存の共有 `blind_indexes` morph 規約に従い、**検索が必要な項目だけ `whereBlind()`**。
  平文 DB 非保存・平文 where 不 hit の Architecture/Feature テストを追加。更新 route は `manageBilling` + current-org scope。

## [Warning] P6 に P1 済みのシグネチャ変更が残る
- 判断: 対応する / 対応: `grantSignupGrant(Organization, string)` は「**P1 で変更済み → P6 は変更なし**」へ。
  P6 の変更を **旧登録経路の撤去 / paid 配線 / marker API の private 化** の 3 点に限定。

## [Warning] P7 の TS PlanCode に Enterprise が残る
- 判断: 対応する / 対応: `resources/js/types/plan.ts` を
  **`export type PlanCode = 'personal' | 'starter' | 'standard';`** に固定（D1 の 3 case と一致。`'enterprise'` を含めない）。

## [Warning] P5 の単調性テストは負 oracle 時の比較を明示する
- 判断: 対応する（`>= oracle` は oracle が負なら自動的に通る。指摘のとおり）
- 対応: **`availableTrueBalance() >= max(oracle, 0)`** へ変更し、加えて **`debt` が `max(-purchasedRaw, 0)` と一致する
  独立アサート**を置く。
