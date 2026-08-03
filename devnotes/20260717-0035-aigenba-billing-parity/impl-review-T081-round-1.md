ありがとうございます。差分だけを精読して、P9正本に照らしてレビューしました（コマンド未実行）。

**[Critical]**
- `resources/js/pages/Onboarding/Checkout.svelte:344`（`funding-consent-terms` 文言）  
  根拠: P9 C-3/D29-b は `consent_version='v2'` で「契約の支払いカードをオートリチャージにも使う」開示を必須化。現行文言は「次の画面でカード登録…課金されません」のまま。しかも snippet 化で有償分岐にも表示。  
  失敗シナリオ: 有償契約フローで事実と異なる説明のまま `v2` 同意を取得し、同意有効性（同意なし課金主張）を崩す。  
  修正案: 有償契約時の文言を `v2` 開示文へ差し替え（カード流用を明示）。`personal`/`paid` で文面を分岐し、Vitest に文言固定テストを追加。

- `tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php:1`（設計テスト #9 未固定）＋`app/Services/Billing/SubscriptionService.php:640`（`isUniqueViolation`）  
  根拠: P9 テスト計画 #9 は「実 driver で UNIQUE 衝突→re-read 収束」を要求。差分上、実 UNIQUE 衝突を発生させるテストが見当たらない。  
  失敗シナリオ: pg/sqlite の例外文言差で `isUniqueViolation()` 判定漏れ→race 時に 500。  
  修正案: 実DBで同一 `(organization_id,intent,attempt_token)` を競合させるテストを追加し、500にならず replay/`?retry` に収束することを固定。

**[Warning]**
- `app/Http/Controllers/Billing/BillingController.php:444`（`reflash()`）  
  根拠: P9正本にない1行。`error` も延命する。  
  失敗シナリオ: 成功着地 (`?highlight=auto-recharge`) で直前エラーが再表示され、成功/失敗が混在。  
  修正案: `reflash()` をやめ、必要キーだけ `keep()` するか `info` のみ明示再設定。

- DTOデフォルトで必須契約が弱まる  
  `app/DataTransferObjects/Billing/BillingPlansPageDto.php:36`、`app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php:62`、`app/DataTransferObjects/Billing/BillingDashboardDto.php:57`  
  根拠: P9は `subscriptionAttemptToken` と `billingContact` を必須shapeで運用。`''`/`null` デフォルトで渡し忘れが silent 化。  
  失敗シナリオ: 画面は出るがPOST時422（空token）で初めて気づく。  
  修正案: デフォルト削除して constructor 必須化、もしくは `Assert::stringNotEmpty` を DTO生成時に追加。

- `Organization::stripeEmail()` 追加の扱い  
  `app/Models/Organization.php:229`  
  根拠: 実装者論点4どおり、設計では「送信境界不変」が前提。ここは境界変更になり得る。  
  失敗シナリオ: 想定外に owner/billing_contact_email が Stripe customer へ同期される。  
  修正案: 設計逸脱として明示承認を取り、`SyncBillingCustomerDetails` の送信項目回帰テストで固定。

- 設計テスト #57（部分適用顕在化）の専用テストが差分上見当たらない  
  根拠: `applyReusedPaymentMethod` の「default PM更新後に適格性喪失→RuntimeException」分岐。  
  失敗シナリオ: 将来の改修で例外経路が無効化されても検知が遅れる。  
  修正案: fakeで「1回目適格/2回目不適格」を作り、例外発火を固定。

**実装者の10論点 判定**
- 1: **Critical**（同意文言v2不整合）
- 2: **Warning**（`reflash`でerror延命）
- 3: **Warning**（DTO必須契約の弱体化）
- 4: **Warning**（PII外部送信境界の要承認）
- 5: **Warning**（owner不在orgでcheckout詰み得る）
- 6: **問題なし**（部分適用はfail-closed上許容範囲）
- 7: **Suggestion**（bughunt fake再現性の改善余地）
- 8: **Critical**（race実driver検証の欠落）
- 9: **問題なし**（`status` select追加で回避済み）
- 10: **問題なし**（sweeper intent非スコープは設計どおり）

必要なら次に、上のCritical 2件を最短で潰すための「具体テスト差分案」まで書きます。