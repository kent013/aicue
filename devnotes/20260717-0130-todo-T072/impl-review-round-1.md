**全体判定**  
- **CHANGES_REQUESTED**

**ファイル別判定（主要変更ファイル）**  
- `app/Actions/Fortify/CreateNewUser.php` — **APPROVE**  
- `app/Services/Billing/PersonalPlanService.php` — **APPROVE**  
- `app/Services/Billing/TicketLedgerService.php` — **APPROVE**  
- `app/Services/Billing/StripeWebhookProcessor.php` — **REQUEST_CHANGES**  
- `app/Services/Marketing/PricingService.php` — **APPROVE**  
- `app/DataTransferObjects/Marketing/PricingPlanDto.php` — **APPROVE**  
- `app/Http/Controllers/Billing/BillingController.php` — **APPROVE**  
- `database/seeders/PlanSeeder.php` — **APPROVE**  
- `config/quota.php` — **APPROVE**  
- `tests/Architecture/MembershipWriteLockInventoryTest.php` — **APPROVE**  
- `tests/Feature/Billing/SignupGrantOncePerOrgTest.php` — **REQUEST_CHANGES**  
- `tests/Feature/Billing/WebhookIdempotencyTest.php` — **APPROVE**  
- `tests/Feature/Billing/PlanActiveFilterTest.php` — **APPROVE**  
- `tests/Feature/Billing/PersonalPlanServiceTest.php` — **APPROVE**

**指摘**

- [Critical] `app/Services/Billing/StripeWebhookProcessor.php`  
  `subscription_create` 経路で `claimSignupGrantMarker()` を取らずに `grantSignupGrant()` を直接呼んでおり、設計書の移行期方針（「marker 条件付き先取 → 先取時のみ grant」）と不一致です。  
  現状でも ledger 側 partial unique で金銭二重付与は抑止されますが、`signup_tickets_granted_at` が立たないまま残る組織が発生し、真実源（marker）との整合を崩します。  
  **修正案:** `CreateNewUser` と同様に、org ロック下で `claimSignupGrantMarker($organization)` が `true` のときのみ `grantSignupGrant($organization, "signup_grant:org:{$organizationId}")` を実行する分岐へ変更してください（同一 tx 内）。

- [Warning] `tests/Feature/Billing/SignupGrantOncePerOrgTest.php`  
  `paid webhook で付与済み → activate` のテストで `expect($result->granted)->toBeTrue()` を期待しており、上記 Critical の不整合（marker 未更新）を“正”として固定しています。  
  設計上は webhook 側でも marker を立てる想定なので、この期待は将来の正しい実装と衝突します。  
  **修正案:** webhook 側を marker 先取実装に合わせた上で、このケースは `granted=false` を期待し、`signup_tickets_granted_at` が webhook 時点で非 null であることを検証に変更。

- [Suggestion] `tests/Architecture/MembershipWriteLockInventoryTest.php`  
  read-only allowlist + 書込 API 非包含チェックへの置換は妥当で、guard 緩和には見えません。  
  さらに堅くするなら、`$readOnly` 対象に `forceFill([... 'laratrust_team_id' ...])` のような team キー書換がないことも併せて検査すると将来の逸脱検出力が上がります。

**観点別サマリ**

- 設計一致 / aigenba verbatim: 概ね高一致。`PlanCode` 5 case、`is_active`、D28、PersonalPlanService の tx 構成は良好。  
- 並行安全性（`activate`）: org 行ロック→再検証→marker claim→grant の順序は成立。後着 500 回避の例外変換も実装済み。  
- セキュリティ不変条件: tenant キー不信・mass assignment 防御・課金冪等の方向性は順守。ただし webhook 側 marker 未claim が #7 の“真実源一貫性”で未完。  
- D28 波及: UI/DTO/テスト期待更新は十分。  
- `grantSignupGrant` 署名変更の call site: 主要 2 箇所更新済み。  

必要なら、上記 2 点（Critical + Warning整合）を反映した最小パッチ案を次に具体化します。