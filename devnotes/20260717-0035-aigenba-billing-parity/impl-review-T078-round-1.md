**レビュー結果（T078 / P7 設計適合）**

**[Critical] `?plan=` 契約違反：`free` が handoff されてしまう**
- **該当**: `resources/js/pages/Pricing.svelte:120`（`href={`/register?plan=${encodeURIComponent(plan.code)}`}`）、`tests/js/pages/Pricing.test.ts:95`（`?plan=free` を期待）、`app/Services/Onboarding/IntendedPlanResolver.php::normalizeRaw()`
- **なぜ問題か**: P7 の URL 契約は `PlanCode`（`personal|starter|standard|business|enterprise`）です。`free` は allowlist 外なので `normalizeRaw()` で `null` 化され、プラン意図が失われます。
- **失敗シナリオ（入力→結果）**: 未認証ユーザーが料金表の無料プラン CTA を押下（`/register?plan=free`）→ `intendedPlan=null` → 登録時 `intended_plan=null` 送信 → org-scoped へ promote されない → `/onboarding/checkout` で `defaultPlanCode=standard` が強調され、無料意図が引き継がれない。
- **推奨修正**: 料金表 CTA の handoff 値を `PlanCode` に合わせる（例: `free -> personal` マッピング）か、サーバ DTO 側の `plan.code` を `personal` に統一。併せて `tests/js/pages/Pricing.test.ts` の期待値を更新。

**[Warning] D16 の「3箇所 inertia 遷移」要件が1箇所未達**
- **該当**: `resources/js/pages/Welcome.svelte:134`（guest nav の「無料で始める」が `<a href="/pricing">` のみ）
- **なぜ問題か**: 設計では D16 で 3 箇所とも `/pricing` + inertia 作法を要求。hero/下部 CTA は `Button inertia` ですが、nav だけ full reload になります。
- **失敗シナリオ（入力→結果）**: LP 上部 nav の「無料で始める」をクリック → SPA 遷移ではなくページ再読込 → UX 一貫性が崩れる。
- **推奨修正**: nav も `Button`/Inertia Link 化して `inertia` を揃える（または設計側に「nav は例外」を明記）。

**[Warning] 設計テスト計画の一部（既存テスト更新）が差分上で未実施**
- **該当**: 未変更（差分なし）`tests/Feature/Auth/FortifyResponseTest.php`、`tests/Feature/Auth/EmailVerificationGateTest.php`、`tests/Feature/Marketing/PricingPageTest.php`
- **なぜ問題か**: P7 設計は「既存テスト更新で非退行固定」を要求。新規テストは追加されていますが、既存回帰網への固定が薄いままです。
- **失敗シナリオ（入力→結果）**: 将来 `VerifyEmailResponseContract` bind が崩れても、既存の契約テストで即検知できない可能性。
- **推奨修正**: 設計記載どおり既存3テストに最小追加（verify fallback、pricing href、gate 非退行）を入れる。

**[Suggestion] P7 と無関係な `devnotes` 修正は分離推奨**
- **該当**: `devnotes/20260717-0037-analysis-generate-timeout/coverage-check.php`、`.../provider-quality-probe.php`
- **なぜ問題か**: 機能PRのレビュー範囲が広がり、cherry-pick/revert 時のノイズになります。
- **失敗シナリオ（入力→結果）**: 緊急リバート時に関係ない変更まで巻き込む。
- **推奨修正**: 別PR/別コミットに分離。

---

**問題なし（観点別）**
- **禁止事項 #7/#4/#2**: 操作系 POST 応答での `redirect()->intended()` は見当たらず（`VerifyEmailResponse` は verify 完了 GET 文脈）、`response()->json()` 直書きなし、`@phpstan-ignore`/baseline/widen なし。
- **設計適合（主要契約）**: `IntendedPlanResolver`（pending常時上書き・org no-op・Enterprise 除外）、`OnboardingReturnResolver`（正規化/peek再検証）、`EmailVerificationContinuation`（org id保持＋membership検証）、`VerifyEmailResponseContract` bind 追加は実装済み。
- **open-redirect 防御**: `OnboardingReturnResolver::normalizePath()` で `https://evil`、`//evil`、`%2F%2Fevil`、`/\evil`、`javascript:...`、制御文字混入が拒否される構造で、差分上の明確なすり抜けは確認できません。
- **cross-org/IDOR**: `EmailVerificationContinuation::resolveUrl()` が `is_int` + `$user->organizations()->whereKey(...)` を通すため、他組織ID注入を遮断。
- **既存赤3件の因果**: ご提示のとおり、T078 差分に `PlanSeeder` / `plans.code='free'` / 当該テスト修正は含まれず、既存赤（`SeededFreePlanBillingAccessTest`）は本差分起因ではないと判断します。