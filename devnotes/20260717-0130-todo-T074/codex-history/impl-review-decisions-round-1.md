# 対応マトリクス: impl-review Round 1（CHANGES_REQUESTED / Critical 1・Warning 4）

BE 側は全ファイル APPROVE、実装者報告の逸脱 3 件も「妥当」判定。指摘は FE の 2 点。

## [Critical] `submitPaidPlan()` が `personal` を弾いておらず `/billing/checkout` へ送りうる
- 判断: **対応する**（指摘が正しい。UI 分岐で通常は到達しないが、述語が崩れると無償プランが Stripe checkout へ混入する）
- 対応: **props の `plans` を単一真実源に `isPaidPlanCode()` を導出**し（`currentBaseAmount !== null` = 基本料金を持つものだけ有償。
  personal は null = Stripe checkout を通らない）、`submitPaidPlan()` の先頭で `if (!isPaidPlanCode(chosenPlanCode)) return;` を追加。
  サーバ側も `assertStripeBillablePlan()` で fail-closed（422）だが**二重防御**。
- **回帰テストを追加**: 「無償プラン (personal) は有償 checkout へ送らない」
  （personal 選択時に `paid-plan-submit` が出ない + 自己申告 submit は `/onboarding/activate-personal` へ行き
  `/billing/checkout` は呼ばれない）。

## [Warning] `selectedPlanCode` に `$derived` を使ってから再代入している
- 判断: **対応する**（Svelte 5 runes で誤作動を招く書き方。指摘が正しい）
- 対応: ただし **Codex の提案（`$state(computeInitialPlan(pageData))`）をそのまま採ると別の問題が出た** —
  Svelte が `state_referenced_locally`（"This reference only captures the initial value of `pageData`"）を警告し、
  **Inertia の partial reload で props が変わっても追随しない**。
  → **override を `$state`・表示値を `$derived`** の正しい runes パターンへ:
  `const selectedPlanCode = $derived(chosenPlanCode ?? computeInitialPlan(pageData));`（再代入を撤去し `chosenPlanCode` に一本化）。
  **警告は消え、17 tests green**。

## [Warning] `BillingRequiredController` の owner 解決 `users()->get()->first(...)` は将来の効率懸念
- 判断: **見送る**（Codex 自身が「いまは変更不要」と明記）。既存 `Organization::routeNotificationForMail()` と同一パターン。

## [Warning] PR 本文で「P3 で未変更の `BillingAccess` / `RequireActiveSubscription`」を明示すると合意が早い
- 判断: **対応する**（コミットメッセージに明記）。

## [Suggestion] 各種
- 判断: 対応不要（すべて肯定的評価）。
