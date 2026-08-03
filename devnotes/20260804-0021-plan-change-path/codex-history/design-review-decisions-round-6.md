# 対応マトリクス: design-review Round 6

判定: A/D/E は APPROVE、B/C が REQUEST_CHANGES (Critical 1 件)。対応した。

## [Critical] 前提違反の `InvalidArgumentException` を Controller が一括 catch していて 500 にならず、Assert の内部文言が露出しうる

- 判断: **対応する** (指摘のとおり、Round 4 で定めた「前提違反は fail-fast」の契約と
  Controller の catch が矛盾していた)
- 対応内容:
  1. **`PlanChangeNotAllowedException` を新設** (業務上の拒否 = 契約なし / 変更できない state /
     schedule 管理下)。メッセージはそのまま利用者に見せる文言。
  2. Service 段 1 / 段 2 / 段 3 の `InvalidArgumentException` をすべて本例外へ置換。
  3. Controller の catch を
     `PlanChangeNotAllowedException|PlanChangeFailedException|CheckoutInProgressException` に限定し、
     **`InvalidArgumentException` は catch しない** (500 へ通す)。
  4. 併せて段 0 の順序を修正: `assertStripeBillablePlan()` を **先**に置き、
     `personal` / `enterprise` は 422 で倒す。後段の
     `Assert::isInstanceOf($basePrice, PlanPrice::class)` は「決済対象プランなのに base Price が
     無い」= 設定不備のみが到達するため 500 のままでよい (利用者操作では到達しない)。
     `Assert::stringNotEmpty($planChangeToken)` も FormRequest の `ulid` 検証済みで到達しない。
  5. テストを追加:
     - `PlanChangeNotAllowedException` → back + error flash にその文言
     - Assert 由来の `InvalidArgumentException` → **500** (内部文言が flash に載らない)
     - `PlanChangeFailedException` → flash は `USER_MESSAGE` 固定 (reason が出ない)
     - `personal` 要求 → `ValidationException` (422) で、Assert には落ちない (段 0 順序の回帰防止)
  6. 実装順の段 0 を「例外 3 種」に更新。
