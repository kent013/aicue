**ファイル別レビュー（判定 + 指摘）**
- `bootstrap/app.php:177` 判定: **OK**  
  - `AuthenticationException` 契機の `Inertia::clearHistory()` は設計どおり実装。`expectsJson()`/`hasSession()` ガード、`null` 素通しも一致。
- `tests/Feature/Security/InertiaHistoryGuardTest.php:151` 判定: **OK**  
  - T1〜T6 が揃っており、正/負/one-shot/偽陽性許容まで固定できています。  
  - **[Suggestion]** `'/'.AdminPanelPath::resolve()` は `resolve()` の返り値仕様に依存するため、他箇所と同じ呼び方に揃えると将来の変更に強くなります。
- `tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php:202` 判定: **OK**  
  - 204 ログアウト→次の Inertia visit で鍵消失→back で PII 非表示、まで一気通貫で検証できています。  
  - **[Suggestion]** `click('通知を確認')` は文言変更で壊れやすいので、将来 `data-testid` が付けばより堅牢です。

- `app/DataTransferObjects/Billing/QuotaStatusDto.php:1` 判定: **概ねOK**  
  - 施策4の要件（上限+使用量+超過次元、`>`判定、生バイト判定、`max_members` 非強制扱い）が反映。  
  - **[Warning]** 設計文書では `array_values($exceeded)` で `list<string>` を構造保証する方針でしたが、実装は append 前提コメントに変更されています。現状は問題ないものの、設計との差分として明示しておくべきです。
- `app/DataTransferObjects/Billing/BillingDashboardDto.php:11` 判定: **OK**  
  - 型 import/shape が `QuotaStatus` 系へ正しく移行。
- `app/Http/Controllers/Billing/BillingController.php:96` 判定: **OK**  
  - `projects()->count()` と `StorageUsageService::occupiedBytes()` で、実際の制御経路に揃えた集計になっています。
- `resources/js/types/billing.ts:29` 判定: **OK**  
  - PHP DTO shape と整合、追加フィールドの型も妥当。
- `resources/js/pages/Billing/Index.svelte:236` 判定: **OK**  
  - Alert 表示条件、使用量/上限併記、メンバー次元の扱いが設計どおり。Design token 逸脱もなし。
- `resources/js/lib/format-bytes.ts:1` 判定: **OK**  
  - 重複排除できており、`Dashboard` 側移設も適切。
- `resources/js/pages/Dashboard.svelte:20` 判定: **OK**  
  - helper 置換のみで安全。
- `resources/js/pages/Billing/Plans.svelte:92` 判定: **OK**  
  - ダウングレード文言が「超過次元のみ停止」に正しく修正。

- `app/Exceptions/Billing/QuotaExceededException.php:14` 判定: **OK**  
  - 回復導線（「お支払い」画面）を文言へ追加できており、施策4-3に一致。
- `tests/Feature/Billing/QuotaTest.php:64` 判定: **OK**  
  - 新文言に対する回帰防止テストあり。
- `tests/Feature/Billing/BillingQuotaStatusTest.php:1` 判定: **OK**  
  - shape厳密一致、上限内/ちょうど/超過、メンバー非強制まで網羅。
- `tests/js/pages/Billing/Index.test.ts:37` 判定: **OK**  
  - 新 props shape と Alert 条件のUI検証が揃っています。
- `tests/js/lib/format-bytes.test.ts:1` 判定: **OK**  
  - 境界値テストが追加され移設リスクを吸収。
- `tests/js/pages/Billing/Plans.test.ts:162` 判定: **OK**  
  - 変更文言とメンバー非表示方針の検証あり。

- `tests/Feature/Billing/PlanQuotaCoverageTest.php:1` 判定: **OK**  
  - 施策8（seed済み plan_code と quota 定義の機械照合）を達成。
- `tests/Unit/Enums/PlanCodeTest.php:1` 判定: **OK**  
  - 全 case 網羅＋写像固定ができており、施策6に合致。
- `docs/factories.md:46` 判定: **OK**  
  - Plan/PlanPrice を Factory 非作成とする理由固定が明確。

- `docs/supported-browsers.md:8` 判定: **OK**  
  - 経路B/Cの実装参照点、限定保証、保証外、再検討条件まで明文化できています。
- `app/Http/Responses/Fortify/LogoutResponse.php:50` 判定: **OK**  
  - `clearHistory` 発行契機がログアウト単独でない点を正しく固定。
- `resources/js/lib/bfcache-guard.ts:32` 判定: **OK**  
  - `popstate` 非採用理由が「目的未達 + UX悪化」で明確化され、設計意図と一致。
- `AGENTS.md:206` 判定: **OK**  
  - ドメイン規約 #3 に認証失敗契機が追記され、規約として固定。
- `docs/architecture.md:328` 判定: **OK**  
  - proration 方針固定と反転時の前提条件が過不足なく記述。
- `app/Services/Billing/CashierStripeGateway.php:93` 判定: **OK**  
  - docblock 参照の一本化（重複管理回避）ができています。
- `app/Services/Billing/PortalConfigurationSpec.php:13` 判定: **OK**  
  - 再開放条件と verify 責務が明確。
- `devnotes/20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md:79` 判定: **OK**  
  - A-6 の陳腐化解消は適切。
- `config/quota.php:28` / `app/Enums/QuotaKey.php:13` 判定: **OK**  
  - `max_members` 非強制の明文化が整合しています。

**全体所見**
- [Critical] はありません。  
- 施策1〜8は、差分上は実装・テスト・恒久文書への固定まで一貫しています。  
- セキュリティ観点（認証失敗契機の `clearHistory`、偽陽性許容、宙吊り評価、one-shot消費）は、コードとテストと文書が噛み合っています。

**最終判定: APPROVED**