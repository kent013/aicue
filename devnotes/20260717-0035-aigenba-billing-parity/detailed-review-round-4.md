全体判定は **CHANGES_REQUESTED** です。主要方針は閉じていますが、本文にはまだ実装結果を変える矛盾が残っています。

## 施策別判定

- P1: **REQUEST_CHANGES**
- P2: **REQUEST_CHANGES**
- P3: **REQUEST_CHANGES**
- P4: **REQUEST_CHANGES**
- P5: **REQUEST_CHANGES**
- P6: **REQUEST_CHANGES**
- P7: **APPROVE**
- P8a: **REQUEST_CHANGES**
- P8b: **REQUEST_CHANGES**
- P9: **APPROVE**

## 指摘

### [Critical] 非activeの有償契約が`NoPlan`へ落ち、P2の挙動不変を破る

正規解決順はactive/trialing subscriptionしかpaidとして扱っていません。そのため以下が`NoPlan`になります。

```text
plan_code='standard'
subscription=past_due / paused / canceled / 不在
free_plan_code=null
```

P2では`NoPlan::grantsAccess()=true`なので、現行では遮断される支払い不健全組織が許可されます。

修正案:

```text
1. active/trialing subあり → PaidSubscriptionPlan（同期ラグ対応）
2. plan_code非null → PaidSubscriptionPlan（entitlementはdeniedを含む）
3. personal + declarerあり → ActivatedPersonalPlan
4. personal + declarerなし → GrandfatheredLegacyFreePlan
5. それ以外 → NoPlan
```

`plan_code非null + sub不在/past_due/paused`がP2・P4とも遮断されるテストを必須化してください。

### [Critical] D26の「解決不能ならgrandfather側」が型の意味を壊す

active subのpriceからplan codeを解決できない場合に`GrandfatheredLegacyFreePlan`を返すと、実際には有償契約中なのにkindとquotaがpersonal扱いになります。

修正案:

- `PaidSubscriptionPlan`のplan codeをnullableにする、または`UnresolvedPaidSubscriptionPlan`を追加する。
- `grantsAccess=true`、`planCode=null`としてfallback quotaを適用する。
- ログ・監視を必須化する。
- `free_plan_code='personal'`でない組織をGrandfathered variantへ入れない。

### [Critical] D21がBillingRequiredControllerへ未適用

`OnboardingController`と`ActivatePersonalController`はcurrent-org解決へ変更されていますが、`BillingRequiredController::show(Organization $organization)`が残っています。route parameterがないため解決できません。

修正案:

- 3 Controllerすべてから`Organization`引数を削除。
- 共通のcurrent-org resolverを使う。
- current org不在・非所属の404テストを3 routeすべてに適用する。

### [Critical] D27とD24でdebtの正規数式が二重化している

D27はdebtをraw ledgerから算出すると確定していますが、D24とP5本文には依然として次が残っています。

```text
debt = min(purchasedRaw - purchasedHold, 0)
```

これはholdによってDTO上の債務が増減します。

修正案:

```text
debtAmount = max(-purchasedRaw, 0)
monthlyPositive = max(monthlyRaw - monthlyHold, 0)
purchasedPositive = max(purchasedRaw - purchasedHold, 0)
monthlySpendable = max(monthlyPositive - debtAmount, 0)
availableTrueBalance = monthlySpendable + purchasedPositive
```

このsnapshotを`balance`、`reserve`、legacy再配賦、auto-rechargeで共有し、旧式数式を全文削除してください。

### [Critical] D25でsubscription tokenとticket tokenを混同している

P8bから削除すべきなのはsubscription checkout用attempt tokenです。一方、`PurchaseTickets`のattempt tokenは既存のチケット決済冪等性に必要です。

現在はPurchaseTickets側まで取り消し線で削除され、同時にresume処理やPOST期待ではtokenを使っています。

修正案:

- `Billing/Plans`からsubscription用`attemptToken`を削除し、POSTは`{plan_code}`のみ。
- `PurchaseTicketsPageDto.attemptToken`は維持。
- `TicketPurchaseResumeStateTest`のtoken再利用も維持。
- P9でsubscription checkout専用tokenを導入する。
- 両者を型名でも区別する（例: `ticketAttemptToken` / `subscriptionAttemptToken`）。

### [Critical] P8bからbillingContact/feedbackがまだ除去されていない

以下が残っています。

- 消化台帳#9
- Indexの`feedback`バナー
- `BillingContactForm.svelte`新設
- TSの`billingContact / feedback`

修正案: これらをP8bから完全削除し、P9の変更一覧・DTO・props・テストへ移してください。

### [Warning] P4に旧変更対象が残っている

以下を`NoPlan`へ直す必要があります。

- `BillingAccess`変更表の`state()`
- 主要契約の「Grandfatheredを変更」
- 既存テストの「stateベース」
- 分類説明の「新state」

### [Warning] free削除migrationの`down()`でconfigは戻せない

DB migrationの`down()`はリポジトリ内の`config/quota.php`を書き換えられません。

修正案: rollbackは「コード/config revert後にmigration down」の順序として運用手順へ分離してください。

### [Warning] personal/starter再公開がP8b変更一覧にない

P4注記ではP8bで再公開するとしていますが、P8bのmigration・Seeder・テスト一覧にありません。

修正案: P8bへ再公開data migration、残余検証、`/pricing`露出テストを追加してください。

### [Warning] P6のactivate重複が変更表に残る

P6変更表は依然`PersonalPlanService::activateWithinTransaction`へ処理を追加すると記載しています。

修正案: 「P1完成済み・変更なし」に置換し、`claimSignupGrantMarker()` private化を変更表へ明記してください。

## 全体判定

**CHANGES_REQUESTED**

特に、非active有償契約が`NoPlan=true`へ落ちる問題と、ticket/subscriptionのattempt token混同は実装前に必ず修正が必要です。