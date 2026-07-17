ありがとうございます。提示差分だけを根拠に、**P3 DoD / v2方針（aigenba verbatim）/ AGENTS 不変条件**でレビューしました。

**全体判定**
- **CHANGES_REQUESTED**

**理由（要約）**
- 実装の大枠は非常に整っており、P3 の主旨（導線追加・ゲート非変更）にも概ね一致しています。
- ただし、**設計に対する明確な逸脱が1点（Critical）**あります。これがあるため承認は不可です。
- それ以外は主に改善提案または軽微確認です。

---

**`resources/js/pages/Onboarding/Checkout.svelte` — REQUEST_CHANGES**
- [Critical] **`/billing/checkout` 送信 payload が設計の「`{plan_code}` のみ」から逸脱**
  - 現状: `submitPaidPlan()` が `{ plan_code: chosenPlanCode }` を送るため、`personal` 選択時にも理論上 `billing/checkout` に `personal` が送信されうる構造です（UI分岐で通常は回避されるが、述語の崩れで混入余地あり）。
  - 設計意図: 「有償 submit を P3 に含める」こと自体は妥当ですが、**送信対象は有償プランに限定**すべきです（aigenba parity上の安全側）。
  - 修正案:
    - `submitPaidPlan()` 内で `chosenPlanCode !== 'personal'` を明示ガードし、違反時は送信せず戻る。
    - 可能なら `isPaidPlanCode()` を `pageData.plans` から導出し、`currentBaseAmount !== null` も併用して防御。

- [Warning] **`selectedPlanCode` に `$derived` を使ってから再代入している点**
  - Svelte 5 runes 的に誤作動を招きやすい書き方です（mutable state に寄せる方が明確）。
  - 修正案: `let selectedPlanCode = $state(computeInitialPlan(pageData));` に変更。

- [Suggestion] `plan_code` エラー表示ロジックは丁寧です。`errors.plan_code` と `lastSubmittedPlanCode` の結合はよい実装です。

---

**`app/Http/Controllers/Onboarding/OnboardingController.php` — APPROVE**
- [Warning] `selectablePlans()` の `array_values(...->all())` は報告逸脱(1)として妥当。  
  - `list<PlanDto>` 維持のための Larastan 対応として合理的で、同リポジトリ先例準拠という説明も成立。
  - 追加修正は不要。

- [Suggestion] 判定順序 `hasActiveAccess() -> manageBilling` は設計一致で良いです。

---

**`app/Http/Controllers/Onboarding/ActivatePersonalController.php` — APPROVE**
- [Suggestion] P3要件どおり `PersonalPlanService::activate()` を呼ぶだけで二重源を作っておらず良いです。
- [Suggestion] `ValidationException::withMessages(['plan_code' => ...])` への変換も設計一致。

---

**`app/Http/Controllers/Onboarding/BillingRequiredController.php` — APPROVE**
- [Suggestion] 離脱ガード `state()->grantsAccess()` → `manageBilling` の順序は設計一致。
- [Warning] owner 解決で `users()->get()->first(...)` は現状要件では問題ないが、将来大規模組織時の効率懸念あり。
  - 修正案（任意）: いまは変更不要。必要になった時点で最適化で十分。

---

**`app/Http/Concerns/ResolvesCurrentOrganization.php` — APPROVE**
- [Suggestion] `resolveMemberCurrentOrganization()` 追加は不変条件 #2（認可前404）に合致。
- [Suggestion] 3 route の 404 テストも揃っており、観点6に適合。

---

**`app/Http/Requests/Onboarding/ActivatePersonalRequest.php` — APPROVE**
- [Suggestion] `ProhibitsProtectedKeys` 配線と `array_replace` merge は良いです。
- [Suggestion] `declaration` 文言のサーバ確定も D4 方針に一致。

---

**DTO群**
- `app/DataTransferObjects/Billing/PlanDto.php` — APPROVE  
- `app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php` — APPROVE  
- `app/DataTransferObjects/Onboarding/BillingRequiredDto.php` — APPROVE  
- [Suggestion] 形状定義と `toArray()` 契約が明確で、Inertia props 一貫性も十分です。

---

**`routes/web.php` — APPROVE**
- [Suggestion] `require-active-subscription` group 外配置、current-org スコープ、3 route 定義とも設計一致。
- [Suggestion] `throttle:10,1` も一致。

---

**`resources/js/pages/Onboarding/BillingRequired.svelte` — APPROVE**
- [Suggestion] AppLayout / Primitive 利用、DS token 使用、問い合わせ導線とも良好。

---

**`resources/js/types/onboarding.ts` — APPROVE**
- [Suggestion] PHP shape との対応が明確で、加法的拡張前提も妥当。

---

**テスト群 — APPROVE**
- `tests/Feature/Onboarding/OnboardingCheckoutTest.php`
- `tests/Feature/Onboarding/ActivatePersonalTest.php`
- `tests/Feature/Onboarding/BillingRequiredTest.php`
- `tests/Unit/DataTransferObjects/Billing/PlanDtoTest.php`
- `tests/js/pages/OnboardingCheckout.test.ts`
- `tests/js/pages/OnboardingBillingRequired.test.ts`

- [Suggestion] **404（current org 不在 / 非所属）を3 routeすべてで検証**できており、必須観点クリア。
- [Suggestion] Factory/seed前提の運用とも整合。既存削除も見当たりません。
- [Warning] テスト結果サマリは十分ですが、最終PR本文で「P3で未変更の `BillingAccess` / `RequireActiveSubscription`」を明示しておくと、レビュア合意が早くなります。

---

**実装者報告の逸脱妥当性**
- 1) `array_values(...->all())` 置換: **妥当**（PHPStan level 10 維持のため、意味論不変）
- 2) PlanFactory 新設なし: **妥当**（このリポの seed 真実源運用と整合）
- 3) 有償 submit を P3 に含めた: **概ね妥当**（詰み回避として必要）。ただし上記 Critical のとおり、**有償限定ガードを明示**してください。

---

**最終結論**
- **CHANGES_REQUESTED**
- まずは `Checkout.svelte` の **有償 submit の送信対象ガード（`personal` 除外）**を入れて再提出してください。  
この1点が解消されれば、他は承認可能な品質です。