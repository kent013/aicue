承認条件5点のうち、方針はすべて正しいですが、本文への統合が4点未完です。全体判定は **CHANGES_REQUESTED** です。

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

### [Critical] NoPlan分離が正規契約以外へ反映されていない

P2/P4に旧3variant契約が残っています。

- P2解決表: `free_plan_code=null`を依然`GrandfatheredLegacyFreePlan`
- P2テスト: 同ケースをGrandfathered期待
- P2波及: 「+3 variant」
- P4: `GrandfatheredLegacyFreePlan::grantsAccess()`を変更
- D18もGrandfathered変更のまま
- P4前提・コメントに`state()`が残存

修正案:

- D18をD23で上書きし、変更対象を`NoPlan`へ訂正。
- P2解決表を4行へ変更。
- `free_plan_code=null`のテスト期待を`NoPlan`へ変更。
- P4本文の変更対象をすべて`NoPlan`へ変更。
- `state()`表記を全削除。

### [Critical] webhook同期ラグ組織がP4で遮断される

分類2は次の状態です。

```text
plan_code=null
active/trialing subscriptionあり
free_plan_code=null
```

backfill対象外ですが、P2の実装契約は`plan_code !== null`の場合だけ`PaidSubscriptionPlan`を返します。そのためP4後は`NoPlan=false`となり、分類表に反して既存ユーザーが締め出されます。

修正案:

- `effectivePlan()`のpaid判定を`plan_code`だけに依存させない。
- active/trialing subscriptionがあれば最優先で`PaidSubscriptionPlan`へ解決する。
- 必要なplan codeはsubscriptionのpriceからorg-scopedに解決する。
- 解決不能時の扱いも明示する。F-07保全を優先するなら、移行時はgrandfather対象へ含める方が安全です。
- 「plan_code null + active sub」のP4後アクセス継続テストを追加。

### [Critical] debtは表示計算に入ったがreserve配賦が債務を無視している

`availableTrueBalance()`はdebtを控除しますが、`reserve()`は依然として次で判定します。

```text
availableMonthly + availablePurchased < amount
```

例: monthly=10、purchased debt=-2の場合、真の利用可能額は8ですが、`reserve(10)`が通ります。

修正案:

- `balance()`、`availableTrueBalance()`、`reserve()`、auto-rechargeで同一の内部snapshotを使う。
- reserveの不足判定を`availableTrueBalance < amount`へ統一。
- 配賦時もdebt控除後のmonthly利用可能額を使う。例:

```text
debtAmount = max(-(purchasedRaw), 0)
monthlySpendable = max(monthlyPositive - debtAmount, 0)
purchasedSpendable = purchasedPositive
```

- `monthly=10 / debt=2`で`reserve(8)`成功、`reserve(9)`失敗を必須テスト化。
- `debt`は予約holdで増減する「債務」ではないため、原則としてraw ledger負値から算出し、holdとは分離してください。

### [Critical] D21適用後もP3 Controllerがroute model binding前提

route parameterを削除した一方で、Controller契約は依然以下です。

```php
show(Organization $organization, Request $request)
__invoke(ActivatePersonalRequest $request, Organization $organization)
```

Laravelは引数のOrganizationをrouteから解決できません。

修正案:

- Controller引数から`Organization`を削除。
- `ResolvesCurrentOrganization`経由で明示解決する。
- current organization不在は404。
- current organizationがuser所属でない不正状態も404。
- `MembershipScopedOrganizationBinder`前提のテスト記述をcurrent-org解決テストへ置換。

### [Critical] D11のfree削除がSeeder変更だけで、本番既存行を削除できない

`PlanSeeder`から定義を消しても、既存DBの`free`行は残ります。`updateOrCreate` Seederは削除を行いません。

修正案:

- P4 data migrationにfree Plan行の削除を含める。
- 参照行・FK・関連`plan_prices`の有無を事前検証し、残っていればfail-closedまたは明示削除。
- migration末尾で`plans.code='free'`残余0件を検証。
- rollback方針も明記する。
- personal/starterをいつ`is_active=true`にするかも確定する。現状は非公開化後、再公開フェーズがありません。

### [Critical] D25がP8b本文へ完全反映されていない

除外宣言後も以下が残っています。

- 消化台帳#9がP8bで`BillingContactForm`追加
- Index変更に`feedback`、`BillingContactForm`
- 新規`BillingContactForm.svelte`
- TSの`billingContact / feedback`
- `BillingPlansPageProps`の`attemptToken`
- Plansテストの`attemptToken`
- Plans submitの`attempt_token`

サブスクcheckout用attempt tokenはP9成果物なので、P8bでは生成根拠がありません。

修正案:

- P8bからbilling contact、feedback、subscription checkout用attempt tokenを完全削除。
- P9へ関連DTO・props・UI・テストを移動。
- P8bのPlansは既存checkout契約だけで成立させるか、Plans自体をP9後にする。

### [Warning] P1のis_active反映に古い記述が残る

P1波及・リスクは「料金表件数が2件増える」「is_activeが無い」としています。P3 PHPStan節にも同じ否定があります。

修正案: D10確定後の記述へ統一し、P3で表示するPlan集合がactiveのみか全件かを明記してください。

### [Warning] P6責務境界が変更表に反映されていない

冒頭ではP1でactivate完成としていますが、P6変更表は依然activateへclaimを追加するとしています。

修正案: P6の当該行を「変更なし・回帰確認」に変え、`claimSignupGrantMarker()` private化を変更表へ追加してください。

### [Warning] TicketBalanceDtoのshapeにdebt反映が不完全

「verbatim」「+monthly/purchased/active/expiry」と書かれていますが、D24では`debt`が追加されています。P8bのper-bucket表示にもdebt表示・説明がありません。

修正案: PHP shape、TS shape、DTO constructor、P8b表示、テストfixtureへ`debt: int`を追加してください。

## 全体判定

**CHANGES_REQUESTED**

特に、同期ラグ中のactive subscriptionが`NoPlan`へ落ちる問題と、reserveがdebtを無視する問題は実装前に必ず閉じる必要があります。