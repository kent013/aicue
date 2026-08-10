**Findings**

- [Warning] [docs/architecture.md](/workspace/.claude/worktrees/tasks/T150/docs/architecture.md:758)  
  「分岐の網羅は Svelte 側の `satisfies Record<BillingStateValue, …>`」という記述が、実装者申し送りと実装差分に対して不正確です。今回の重要な学びは「`.svelte` 内の `satisfies` は `pnpm typecheck` で評価されないため、`resources/js/types/dashboard.ts` に置いた」ことなので、architecture 側も `resources/js/types/dashboard.ts` の `BILLING_CALLOUTS` が担う、と明記したほうがよいです。実装自体は妥当です。

**File Verdicts**

- [app/DataTransferObjects/Dashboard/BillingSummaryData.php](/workspace/.claude/worktrees/tasks/T150/app/DataTransferObjects/Dashboard/BillingSummaryData.php:4): APPROVED  
  `has_billing_access` を廃止し、`OnboardingBillingState` を DTO 内で保持して wire 化時に `value` へ落とす形は設計どおりです。PHPStan 向けの `value-of<OnboardingBillingState>` も妥当です。

- [app/DataTransferObjects/Dashboard/DashboardPageData.php](/workspace/.claude/worktrees/tasks/T150/app/DataTransferObjects/Dashboard/DashboardPageData.php:4): APPROVED  
  設計レビューで指摘されていた import も追加済みで、nested shape も `billing_state` に更新されています。

- [app/Services/Dashboard/DashboardService.php](/workspace/.claude/worktrees/tasks/T150/app/Services/Dashboard/DashboardService.php:231): APPROVED  
  `hasActiveAccess()` ではなく `state()` を渡す変更は目的に合っています。課金ゲート判定自体は変更していません。

- [resources/js/types/dashboard.ts](/workspace/.claude/worktrees/tasks/T150/resources/js/types/dashboard.ts:2): APPROVED  
  `BillingSummary` の wire 契約更新は PHP DTO と一致しています。`BILLING_CALLOUTS` を `.ts` 側に移した逸脱は妥当です。mutation evidence のとおり、設計が意図した「state 追加時に型で落とす」保証を実際に成立させる変更です。

- [resources/js/pages/Dashboard.svelte](/workspace/.claude/worktrees/tasks/T150/resources/js/pages/Dashboard.svelte:22): APPROVED  
  page 側は表示分岐だけに閉じており、認可の二重実装や disabled UI はありません。Card 維持も DESIGN.md 上の逸脱は見当たりません。

- [resources/js/lib/passkeys.ts](/workspace/.claude/worktrees/tasks/T150/resources/js/lib/passkeys.ts:37): APPROVED  
  429 だけを `rate_limited` として分け、options 取得と POST 失敗の両方で日本語文言を返す実装は設計どおりです。`PasskeyOutcome` の wire 形状も変えていません。

- [tests/Architecture/OnboardingBillingStateTsSyncInvariantTest.php](/workspace/.claude/worktrees/tasks/T150/tests/Architecture/OnboardingBillingStateTsSyncInvariantTest.php:1): APPROVED  
  enum と TS union の exact sync、空母集団防止、抽出失敗の negative control が入っています。

- [tests/Feature/DashboardTest.php](/workspace/.claude/worktrees/tasks/T150/tests/Feature/DashboardTest.php:206): APPROVED  
  旧 prop 非残存、未契約・pending・expired・free・subscribed の props 到達が押さえられています。`grandfatherFreePlan: false` の fixture 注意も反映済みです。

- [tests/js/pages/Dashboard.test.ts](/workspace/.claude/worktrees/tasks/T150/tests/js/pages/Dashboard.test.ts:15): APPROVED  
  no_subscription / pending_checkout / expired_checkout / subscribed / active_free_plan の表示分岐が揃っています。旧文言の negative assertion も効いています。

- [tests/js/lib/passkeys.test.ts](/workspace/.claude/worktrees/tasks/T150/tests/js/lib/passkeys.test.ts:166): APPROVED  
  options 429、options 500 negative control、登録 options 429、POST 429 が分かれており、429 だけの分岐として十分です。

- [tests/Browser/DashboardBillingCalloutTest.php](/workspace/.claude/worktrees/tasks/T150/tests/Browser/DashboardBillingCalloutTest.php:1): APPROVED  
  実ブラウザ finding の回帰固定として妥当です。1 本統合も実行時間と検出力のバランス上、問題ありません。

- [docs/template-divergence.md](/workspace/.claude/worktrees/tasks/T150/docs/template-divergence.md:274): APPROVED  
  現況説明として `billing_state` ベースへ更新されています。

**全体判定: APPROVED**

実装者申し送りの逸脱は妥当です。むしろ mutation で設計上の空振りを検出し、型検査が実際に効く場所へ移した判断は正しいです。唯一、architecture 文書の「Svelte 側」という表現だけは実装の肝とずれるため、早めに直すのがよいです。