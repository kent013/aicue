以下、差分と設計書P8aを突き合わせたレビュー結果です。

- [Critical] 同意上限額の算出が実課金上限を過小評価し、**同意額を超えて課金可能**です。  
  - 根拠コード: `app/Services/Billing/AutoRechargeService.php:329`（`consentTermsFor` が `currentTierFor(maxCount) * maxCount` で上限額計算）, `app/Services/Billing/AutoRechargeService.php:500`（`createAttemptLocked` は `quantity` ごとに `currentTierFor(quantity)` で単価決定）, `app/Services/Billing/AutoRechargeService.php:1017`（`reconsentRequiredFor` も `maxCount` 点のみで判定）, `resources/js/components/features/billing/AutoRechargeCard.svelte:104`（UI表示も同じ計算）。  
  - 再現手順（実コード上の具体順序）:  
    1. ティアを `1枚〜:100円`, `20枚〜:80円`, `50枚〜:70円` とする（`tests/js/support/autoRechargeProps.ts:24` の前提）。  
    2. `threshold=5, max=50` で有効化し同意を取る（同意表示上限は `50*70=3500円`）。  
    3. 真値残高を `1` にしてトリガ（`quantity=49`）。  
    4. 実課金は `49*80=3920円` となり、**同意表示3500円を超過**。  
  - 影響: 同意文言・`consented_max_amount`・再同意判定が同じ欠陥を共有しており、法務/UX/課金安全性の観点でマージブロッカーです。

- [Warning] 「カード未登録時の設定保存」が `disabled_reason=user` を常に立てるため、事前同意済みの自動有効化待ちを潰します。  
  - 根拠コード: `resources/js/components/features/billing/AutoRechargeCard.svelte:210`（`handleSaveDraft` は `enabled=false` 送信）, `app/Services/Billing/AutoRechargeService.php:226`（`enabled=false` で `disabled_reason=User`）, `app/Services/Billing/AutoRechargeService.php:787`（`autoEnableEligible` は `disabled_reason !== null` で不適格）。  
  - 影響: Onboardingで事前同意→Billingで「設定保存」しただけで、setup完了時の自動有効化が起きなくなる。

- [Warning] 自動停止通知をDBトランザクション内で送っており、通知系例外で状態遷移ごとロールバックするリスクがあります。  
  - 根拠コード: `app/Services/Billing/AutoRechargeService.php:654`（`transitionToTerminal` 内で `notifyDisabled` 実行）。  
  - 影響: invoice終端済みでもattemptが`pending`に戻り、後続収束が想定外分岐（`void/deleted`→`canceled`）に流れ得ます。

- [Suggestion] 同意上限額/再同意判定/UI表示を「`maxCount`一点」ではなく、**実際に取り得る`quantity`全域の最大請求額**で統一してください。  
  - 例: `max(amountFor(q))` を `q ∈ [maxCount-(thresholdCount-1), maxCount]`（reserveトリガ実域）で評価し、その関数を `consentTermsFor` / `reconsentRequiredFor` / UI 表示に共通利用。

- [Suggestion] 上記Criticalを固定する回帰テストを追加してください（落ちるべきときに落ちる保証）。  
  - 追加先候補: `tests/Feature/Billing/AutoRechargeServiceTest.php` に「max=50同意後にquantity=49で同意上限超過を検出」ケース。  
  - 併せて `tests/js/components/features/billing/AutoRechargeCard.test.ts` の上限額期待（現状`¥3,500`）を実上限ロジックに合わせて更新。

**総合判定: CHANGES_REQUESTED**