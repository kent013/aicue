# 対応マトリクス: design-review Round 2

判定: B/C は APPROVE、A/D/E が REQUEST_CHANGES (Warning 3 件)。全件対応した。

## [Warning] `normalizeItems()` が不正 item を `continue` するため「正常 1 件 + 不正 1 件」が更新される

- 判断: 対応する (指摘どおり fail-closed になっていなかった)
- 対応内容: `normalizeItems(string $stripeSubscriptionId, StripeSubscription $remote)` に変更し、
  **id / price / quantity のいずれかを解決できない item が 1 つでもあれば即座に
  `UnexpectedSubscriptionShapeException` を throw** する (skip しない)。
  gateway テストに「正常 1 件 + price 解決不能 1 件 → 例外 / update 0 回」を追加。

## [Warning] stale 検知の送信値 (表示用 `resolveCurrentPlanCode()`) と比較対象 (`$org->plan_code`) の混同

- 判断: 対応する (実害あり。`hasChangeableSubscription` = `Subscription::valid()` と
  `ActiveFreePlan` は同時成立しうる。例: `canceled` かつ期末まで有効な grace period 契約では
  表示用 `currentPlanCode` が `personal`、`plan_code` は `standard` → **恒常 422 (stale) の詰み**)
- 対応内容: DTO / TS props に **`planChangeExpectedPlanCode: string|null`**
  (= `organizations.plan_code` そのもの) を追加し、Svelte はこの値を `current_plan_code` として
  送る。表示用 `currentPlanCode` は表示専用のまま据え置き、両者の役割差を docblock に明記。
  テストに「grace period 契約で表示用 (`personal`) と競合制御値 (`standard`) が異なる」
  「POST payload は `planChangeExpectedPlanCode` 由来である」を追加。
  併せて「grace period 契約は段 4 (state 判定) で理由付き拒否 = 行き止まりではない」ことを
  リスク節に明記し、解約予約中の再契約導線は本設計のスコープ外として open question に回す。

## [Warning] `docs/architecture.md` の guard 順が旧設計のまま

- 判断: 対応する
- 対応内容: 施策 E の追記内容を実装順 (契約再読込 → 同一プラン no-op → stale → state →
  schedule → swap) に修正し、**同一プラン no-op を stale より先に置く理由**と
  **stale の期待値が `organizations.plan_code` そのものである**ことを追記した。
