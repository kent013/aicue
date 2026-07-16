全体判定は **CHANGES_REQUESTED** です。主要5フェーズはほぼ閉じましたが、上位決定とP3/P9に残る穴は実装結果へ影響します。

## 施策別判定

- P1: **APPROVE**
- P2: **REQUEST_CHANGES**
- P3: **REQUEST_CHANGES**
- P4: **APPROVE**
- P5: **APPROVE**
- P6: **REQUEST_CHANGES**
- P7: **REQUEST_CHANGES**
- P8a: **APPROVE**
- P8b: **APPROVE**
- P9: **REQUEST_CHANGES**

## 指摘

### [Critical] 上位決定D26が旧仕様のまま

D26には依然として「price解決不能時はgrandfather側へ倒す」とあります。P2本文の正しい契約は次です。

```text
PaidSubscriptionPlan(planCode: null, entitlement: granted)
```

横断決定は下位セクションより上位なので、実装者がD26に従うと型の意味が再び壊れます。

修正案: D26をnullable `PaidSubscriptionPlan`契約へ置換し、「Grandfatheredへ倒さない」を明記してください。

### [Critical] D21が3 Controllerすべてに適用されていない

P3にはまだ以下が残っています。

- 変更一覧: `OnboardingController::show(Organization, Request)`
- 主要契約: `BillingRequiredController::show(Organization $organization)`
- テスト: route binding由来の「非メンバー404」
- `attemptToken`の取り消し線付き残骸
- `is_active`列が存在しないという古いPHPStan記述

route parameterがないため、`BillingRequiredController`のOrganization引数は解決不能です。

修正案:

- 3 Controllerすべてを`Request`のみの引数へ統一。
- 共通current-org resolverで不在・非所属を認可前404。
- 3 routeすべてに同じ404テストを追加。
- P3の旧route binding・attempt token・`is_active`否定記述を削除。

### [Critical] P9のサブスクcheckout冪等契約が不足

`BillingCheckoutSession`を追加するだけでは、二重subscription作成を防ぐ状態機械が定義されていません。

修正案として最低限、以下を契約化してください。

- `organization_id + subscription_attempt_token`のUNIQUE
- `initiated_by_user_id`によるactor scope
- pending/completed/failed/expired状態
- 同token再送時は既存Checkout URLへ収束
- Stripe idempotency keyとの対応
- plan code不一致でのtoken再利用は422
- 他org・他userのtokenは404
- success/cancel webhookとの競合と再送テスト
- tenantキーをpayloadから受け取らないRequest契約

### [Critical] P9のPII保護がnameまで閉じていない

セキュリティ不変条件はemailとnameの両方をCipherSweet対象としますが、P9はemailのみ明記しています。

修正案:

- `billing_contact_email`と`billing_contact_name`の両方をCipherSweet化。
- blind index列・model cast・Factory・migration・検索契約を明記。
- 検索が必要な項目だけ`whereBlind()`を使う。
- 平文DB非保存・平文where不一致のArchitecture/Featureテストを追加。
- 更新routeは`manageBilling`認可とcurrent-org scopeを明記。

### [Warning] P6にP1済みのシグネチャ変更が残る

P6変更表で`grantSignupGrant(Organization, string)`への変更を再度行う記述があります。

修正案: 「P1完成済み・変更なし」にし、P6の変更を旧登録経路撤去、paid配線、marker API private化だけに限定してください。

### [Warning] P7のTS PlanCodeにEnterpriseが残る

`resources/js/types/plan.ts`の候補に`'enterprise'`が残っています。

修正案:

```ts
type PlanCode = 'personal' | 'starter' | 'standard';
```

### [Warning] P5の単調性テストは負oracle時の比較を明示する

`availableTrueBalance() >= oracle`はoracleが負なら自動的に通ります。

修正案: `availableTrueBalance() >= max(oracle, 0)`に加え、`debt`が`max(-purchasedRaw, 0)`と一致する独立assertを置いてください。

## 全体判定

**CHANGES_REQUESTED**

P1/P4/P5/P8bの再生成は整合しています。残る承認阻害点は、D26の上位矛盾、P3のcurrent-org適用漏れ、P9の冪等状態機械とPII保護です。