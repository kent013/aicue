了解しました。**提示された diff のみ**を対象に、設計 A/B・規約・テスト観点でレビューしました（コマンド実行なし）。

**ファイル別判定**
- [OK] `app/Support/Auth/EmailVerificationContinuation.php:56` — `hasContinuation()` が `resolveUrl()` 委譲で単一出典を維持。IDOR 防御の設計意図と一致。
- [OK] `app/Providers/FortifyServiceProvider.php:235` — `continueUrl` 露出をやめ `continuesToCheckout` に変換。未認証での踏破不能 CTA 排除方針に一致。
- [OK] `resources/js/pages/Auth/VerifyEmail.svelte:6` — props を `continuesToCheckout: boolean` に一本化、CTA 削除＋予告文化で設計 A を正確に実装。
- [OK] `resources/js/pages/Auth/ResetPassword.svelte:57` — `TextLink` footer 追加で離脱導線を確保。Atomic/DS 規約に適合。
- [OK] `resources/js/pages/Auth/TwoFactorChallenge.svelte:107` — `TextLink` footer 追加で離脱導線を確保。踏破可能先 `/login` で妥当。
- [OK] `resources/js/pages/Auth/ConfirmRecentAuth.svelte:38` — `canSatisfy=false` の踏破不能 `/forgot-password` CTA をログアウト導線へ置換。`/dashboard` footer も要件通り。
- [OK] `tests/Feature/Onboarding/OnboardingCheckoutEmailVerificationGuardTest.php:21` — 未認証 checkout 差し戻しを仕様固定。設計 A の根拠テストとして有効。
- [OK] `tests/Feature/Auth/RegisterVerifyFlowTest.php:51` — `continuesToCheckout` true/false と `continueUrl` 不在を振る舞いで固定。回帰検知として十分。
- [OK] `tests/Unit/Support/Auth/EmailVerificationContinuationTest.php:95` — `hasContinuation === (resolveUrl !== null)` の同値性テストは非常に良い。
- [OK] `tests/js/pages/VerifyEmail.test.ts:25` — 「許可 2 ボタンのみ」「link 0」を固定しており、実装依存を下げた回帰検知になっている。
- [OK] `tests/js/architecture/page-shell-structure.test.ts:33` — AuthLayout 離脱導線契約を architecture テスト化。allowlist 健全性チェックも適切。
- [OK] `tests/js/pages/ResetPassword.test.ts:38` — エラー時も離脱導線維持を固定しており F-2-02 再発防止に有効。
- [OK] `tests/js/pages/TwoFactorChallenge.test.ts:24` — 既存挙動＋離脱導線を両方固定。
- [OK] `tests/js/pages/ConfirmRecentAuth.test.ts:36` — `/dashboard` 導線、`/forgot-password` 不在、`POST /logout` を検証できている。
- [OK] `tests/Feature/Auth/RecentAuthTest.php:172` — `canSatisfy=false` 状態と guest ゲート根拠を仕様化できている。
- [OK] `tests/Feature/Auth/RecentAuthPasswordRecoveryTest.php:25` — 案内した回復手順の終端成立まで通して固定しており高品質。
- [OK] `tests/js/architecture/logout-call-site-inventory.test.ts:25` — 新規 `/logout` 呼び出しを deny-by-default inventory に反映済み。
- [OK] `DESIGN.md:459` — Do/Don’t 追加は今回の UI 不変条件と整合。
- [OK] `docs/architecture.md:286` — verify 画面に checkout CTA を置かない理由がセキュリティ観点まで含めて明文化されている。
- [OK] `docs/supported-browsers.md:16` — logout 導線数の更新は T089 との整合性維持として妥当。

**指摘**
- [Critical] なし
- [Warning] なし
- [Suggestion] `verify-email-continue` 文字列は `tests/js/pages/VerifyEmail.test.ts:48` に回帰ガード目的で残っています。DoD の「リポジトリ全体から消える」を厳密運用するなら、「本番コードから消える」に文言修正しておくと運用がブレません。

**全体判定**
- **APPROVED**