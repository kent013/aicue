全体判定は **CHANGES_REQUESTED** です。Round 1 指摘の方向性は正しく反映されていますが、統合本文にはまだ契約上の穴が残っています。

## 施策別判定

- P1: **REQUEST_CHANGES**
- P2: **REQUEST_CHANGES**
- P3: **REQUEST_CHANGES**
- P4: **REQUEST_CHANGES**
- P5: **REQUEST_CHANGES**
- P6: **REQUEST_CHANGES**
- P7: **REQUEST_CHANGES**
- P8a: **REQUEST_CHANGES**
- P8b: **REQUEST_CHANGES**

## 指摘

### [Critical] D18 の variant 分離が成立していない

P2では次の両者が同じ `GrandfatheredLegacyFreePlan` になります。

- `free_plan_code='personal'` かつ declarer null（P4で救済済み）
- `free_plan_code=null`（新規未契約）

P4でこの variant の `grantsAccess()` を false にすると、backfill済み既存組織も遮断されます。逆に trueなら未契約を遮断できません。

修正案:

- P2から `GrandfatheredLegacyFreePlan` と `NoPlan` を別variantとして定義する。
- P2では両方 true。
- P4では **`NoPlan::grantsAccess()` だけ false** にする。
- 解決順を以下に固定する。

```text
paid → PaidSubscriptionPlan
free_plan_code=personal + declarerあり → ActivatedPersonalPlan
free_plan_code=personal + declarerなし → GrandfatheredLegacyFreePlan
free_plan_codeなし → NoPlan
```

`EffectivePlanKind`、PHP/TS shape、単体・解決テストにも `no_plan` を追加してください。

### [Critical] D21 が P3/P4/P7 に適用されていない

本文には依然として以下が残っています。

- P3 route定義が `/organizations/{organization:slug}/...`
- route名が `organizations.onboarding.*`
- Controllerが `Organization $organization` route binding前提
- `isCurrentOrganization` と組織切替CTA
- P4の `$this->access->state(...)`
- P7の `organizations.onboarding.checkout` と `{organization}` 引数

修正案:

- P3をcurrent-org解決へ全面変更し、route parameterを削除。
- route名を `onboarding.*` に統一。
- Controllerは `ResolvesCurrentOrganization` 等の既存機構から組織を解決。
- P3の`isCurrentOrganization`、切替CTA、org-slug非対称リスクを削除。
- P4の全`state()`を`effectivePlan()`へ変更。
- P7のcontinuationは組織IDを保持しつつ、membership確認後に引数なしの`route('onboarding.checkout')`を生成する。

### [Critical] D19 の「付与時に debt を先に相殺」が未定義で、実装次第では二重相殺になる

例として purchased raw balanceが`-2`の状態で購入grant `+10`を記録すれば、台帳合計は自然に`8`になります。ここでgrant自体を`8`へ減額すると残高は`6`となり、債務を二重回収します。

またmonthly grant `+10`、purchased debt `-2`の場合、source別clampでは利用可能額が`10`となり、債務が回収されません。

修正案:

- grant行の`delta`は変更しない。
- 「相殺」は書込み処理ではなく残高計算で行う。
- 例えば次を契約化する。

```text
monthlyPositive = max(monthlyRaw - monthlyHold, 0)
purchasedPositive = max(purchasedRaw - purchasedHold, 0)
debt = min(purchasedRaw - purchasedHold, 0)
availableTrueBalance = max(monthlyPositive + purchasedPositive + debt, 0)
```

- `totalAvailable()`も債務控除後の非負値にする。
- `debt`の符号を「負数」か「正の債務額」のどちらかに固定する。推奨は正数。
- purchased grant、monthly grant、signup grant、auto-recharge grantそれぞれで債務が一度だけ回収されるテストを追加する。
- monthly grant失効後も未回収債務が残ることを検証する。

### [Critical] D10/D11 が実際の変更一覧に落ちていない

D10は`plans.is_active`移植を決定していますが、P1には以下がありません。

- `plans.is_active` migration
- Model cast
- Seeder設定
- `PricingService`のactive filter
- invariant/test

さらにP3は「`is_active`列が無いのでfilterしない」と明記しており、D10と正面衝突しています。

D11もP4で`free`行と`fallback_plan='free'`を撤去するとしていますが、P4は逆にfallbackを維持すると記述しています。

修正案:

- P1に`is_active`の全波及を追加し、P3の否定記述を削除。
- P4にfree Plan撤去、fallback切替先、Quota解決、Seeder・Factory・テスト更新を明記。
- grandfatheredのquotaキーを`personal`にするのか、旧`free`設定を別名で保持するのかを確定する。

### [Critical] P8bがP2に存在しない成果物を前提としている

P8bは以下を「P2済み」としますが、P2では非スコープまたは未定です。

- subscription用`BillingCheckoutSession`
- `resolveBillingFeedback`
- billing contact列・更新Action
- `BillingContactShape`

この状態ではP8bは実装不能です。

修正案:

- これらをP2へ正式追加するか、独立フェーズへ切り出す。
- P8bから未提供props/UIを除外する選択でもよい。
- 依存表と変更ファイル、migration、Factory、テストを同時に更新する。

### [Warning] P1とP6のPersonal activate責務が重複している

P1のテストは`activate()`によるmarker claim＋grantを期待しますが、P6は同処理を「追加」としています。

修正案:

- P1でactivate側を完成させる。
- P6は登録経路から旧grantを撤去するだけ、と明記する。
- `claimSignupGrantMarker()`をP6でprivate化する具体的変更も一覧へ追加する。

### [Warning] D16がP7に適用されていない

D16はWelcomeの直リンクをP7で`/pricing`へ変更すると決定していますが、P7本文は「変更しない、P8b所管」としています。

修正案: P7へWelcomeの3リンク変更とテスト更新を移し、未決事項を削除してください。

### [Warning] D4違反がP3に残っている

P3のJSテストには「ineligibleならCTA無効」「declaration未チェックでsubmit不可」が残っています。

修正案: ボタンは押せる状態を維持し、押下後に理由またはvalidation errorを表示するテストへ変更してください。

### [Warning] D22の同値アサートがテスト本文に明記されていない

横断決定には追加されていますが、`GrandfatherFreePlanBackfillTest`の箇条書きにID集合の完全一致がありません。

修正案: expected ID集合と実更新ID集合の双方向一致を明記してください。

## 総括

D18とD19はまだ「方針は正しいが、型・数式へ閉じていない」状態です。特に以下が承認条件です。

1. `NoPlan` variant追加  
2. D21のP3/P4/P7全面適用  
3. debtを台帳書込みではなく横断残高計算で一度だけ相殺  
4. `is_active`とfree撤去の実変更化  
5. P8bの未提供backend依存解消