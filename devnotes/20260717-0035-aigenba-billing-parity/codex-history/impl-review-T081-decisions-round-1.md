# 対応マトリクス: impl-review-T081 Round 1

対象: `devnotes/20260717-0035-aigenba-billing-parity/impl-review-T081-round-1.md`
（gpt-5.3-codex / reasoning=high / one-shot）

Codex の指摘は **Critical 2 件 / Warning 4 件**。うち **Critical 2 件は両方とも対応**、
Warning は 3 件対応 / 1 件は反論（根拠付き）とした。

---

## [Critical] `Onboarding/Checkout.svelte` の同意文言が v1 のまま（`consent_version='v2'` の開示不成立）

- 判断: **対応する**
- 根拠: 設計 C-3 のリスク表は「**v2 同意文言（契約のお支払いカードをオートリチャージにも使う）で開示済み**であり、
  開示の版管理が `consent_version` = aigenba の消費者保護契約そのもの」と明記している。
  版番号だけを `v1 → v2` に上げて文面が「次の画面でカードを登録します。登録しただけでは課金されません。」の
  ままなら、**開示の実体が存在しない**。さらに今回 funding 選択を snippet 化して有償プラン枝でも描画したため、
  有償契約（次の画面は契約決済であってカード登録ではない）で**事実と異なる説明**を提示していた。
  移植元も枝ごとに文言を分けている（`/tmp/aigenba/resources/js/pages/Onboarding/Checkout.svelte:651,719`）。
- 対応内容:
  - `fundingChoiceSection` を `{#snippet fundingChoiceSection(paidPlan: boolean)}` にし、
    カード取得手段の開示行を枝で分岐（`data-testid="funding-consent-card-source"`）。
    - 有償枝: 「お支払いは Stripe で行い、**そのお支払いカードをオートリチャージにも使います**。設定はいつでも変更・停止できます。」
      （aigenba `:651` verbatim 相当）
    - 無償 (personal) 枝: 従来の「次の画面でカードを登録します。…」を維持（P8a の意味論のまま）。
  - 有償枝の CTA / 補足文も aigenba に合わせて分岐（「自動購入に同意して契約を進める」/
    「次の画面で決済に進みます。お支払いの完了後、オートリチャージが自動で有効になります。いつでも停止できます。」
    = aigenba `:719` verbatim 相当）。**disabled にはしない**（禁止事項 #8 は維持）。
  - Vitest 2 ケース追加（`tests/js/pages/OnboardingCheckout.test.ts`）:
    有償枝に v2 開示文言が在ること / 有償枝に v1 文言が出ないこと / 無償枝は v1 文言を保つこと。

## [Critical] 設計テスト #9（並行 race → UNIQUE 違反 → replay 収束）が未実装

- 判断: **対応する**
- 根拠: 指摘どおり `SubscriptionCheckoutIdempotencyTest` に UNIQUE 違反経路のケースが 1 件も無く、
  `isUniqueViolation()`（SQLSTATE `23000`/`23505` + 制約名 `billing_checkout_sessions_org_intent_attempt_unique` /
  SQLite の構成列名）が **実 driver で本当に一致するか**を誰も検証していなかった。
  一致しなければ並行 race が **500** に落ちる（= 設計 DoD「INSERT race の re-read 収束」が空振り）。
  テスト DB は pgsql（`phpunit.xml` + `tests/bootstrap.php`）なので実 driver 検証が可能。
- 対応内容: `tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php` に 3 ケース追加。
  例外の自作注入ではなく、**gateway の `createSubscriptionCheckout` 内で先着プロセスの行を commit** して
  段 5 の INSERT を実 UNIQUE に当てる（「Stripe 作成成功 → INSERT 直前に割り込み」の忠実な再現）。
  1. 先着行が **live pending** → 500 にならず**先着行の `checkout_url` へ 302**、行は 1 行のまま。
     （= pgsql の制約名一致まで含めて `isUniqueViolation()` が true になることの機械固定）
  2. 先着行が **stale pending** → replay せず `?retry=1`（C-1 の「死んだ URL へ収束させない」）。
  3. **`stripe_session_id` の UNIQUE 違反は rethrow**（`UniqueConstraintViolationException` が伝播 =
     attempt_token 以外の制約違反を replay 経路へ流さない）。

---

## [Warning] `resolveAutoRechargeLanding()` の `$request->session()->reflash()`

- 判断: **対応する**
- 根拠: 設計に無い 1 行で、`info` だけでなく `error` も延命する。成功着地 (`?highlight=auto-recharge`) で
  直前の error が同居しうる（設計「feedback バナーが『成功』を偽装する」リスクの裏返し = 失敗の混在）。
  Stripe からの着地は新規リクエストで前段 flash を持たないため、そもそも実益がない。
- 対応内容: `reflash()` を削除し、理由をコメントで残した（`BillingController::resolveAutoRechargeLanding`）。

## [Warning] DTO のデフォルト引数で「必須 shape」の契約が弱まる

- 判断: **対応する**
- 根拠: 設計の DTO 形状は `billingContact: BillingContactShape`（非 null）/
  `subscriptionAttemptToken: string` を**必須**と規定。デフォルト値があると渡し忘れが
  「空 token を front へ出し、POST 時に 422 で初めて気づく」silent failure になる。
- 対応内容:
  - `BillingDashboardDto`: `$feedback` / `$billingContact` のデフォルトを削除し、
    `$billingContact` を **非 null 必須**へ（`toArray()` の `?? new BillingContactDto(null,null,null)` フォールバックも撤去）。
  - `BillingPlansPageDto::$subscriptionAttemptToken`: デフォルト `''` を削除。
  - `OnboardingCheckoutDto::$subscriptionAttemptToken`: デフォルト `''` を削除し、
    **必須引数が任意引数の後に来ない位置**（`$contactUrl` の直後）へ移動（PHP の deprecation 回避）。
    呼び出しは named args のため互換。

## [Warning] 設計テスト #57（部分適用の顕在化 = `RuntimeException`）が未実装

- 判断: **対応する**
- 根拠: 指摘どおり。`applyReusedPaymentMethod` の「Stripe だけ変更済みで TX 内に適格性が失われた」分岐は
  設計が「silent no-op にしない」と明記した中核の安全弁だが、テストが 1 件も無かった。
- 対応内容: `tests/Feature/Billing/SubscriptionPmReuseTest.php` に 1 ケース追加。
  `AutoRechargeGatewayInterface` の mock で `setDefaultPaymentMethod` 呼び出し時に
  `disabled_reason` を立てて適格性を失わせ、**`RuntimeException` が飛ぶこと** +
  **TX rollback でローカル snapshot が一切書かれないこと**（`enabled=false` / `stripe_payment_method_id=null` /
  通知 0 通）を固定した。

## [Warning] `Organization::stripeEmail()` の追加は設計に無い（PII 外部送出境界の拡大か）

- 判断: **反論する**（変更しない。記録は残す）
- 根拠: 移植元 aigenba の `app/Models/Organization.php:118-132` に **同名・同意味論の `stripeEmail()` が存在する**
  （`billing_contact_email` 正本 → owner email fallback。`routeNotificationForMail()` はこれを再利用）。
  設計の変更箇所表は `routeNotificationForMail()` のみを列挙しているが、**同表が根拠として指す aigenba 行
  （`Organization.php:119-138`）はまさに `stripeEmail()` の fallback 意味論そのもの**であり、
  「aigenba verbatim」の範囲内である（設計の記載漏れを verbatim で補完した形）。
  また `UpdateBillingContactAction` が **email 変更時のみ** `BillingCustomerSynchronizer::dispatchFor()` を撃つ
  設計は、`syncStripeCustomerDetails()` が請求先メールを読むこと（= `stripeEmail()` の override）を前提にしないと
  意味を成さない（override が無ければ同期しても常に null を送るだけの空振りになる）。
  送信内容の境界は `UpdateBillingContactTest`「stripeEmail は請求先メール正本 → owner email fallback
  （宛名は Stripe へ送らない）」で機械固定済みで、**`billing_contact_name` は送らない**（aigenba IV-6 verbatim）。
- 次ラウンドで伝えること: 上記 verbatim 根拠。設計本文への追記が必要なら設計側の記載漏れとして扱う。

## [Warning 相当・Codex 判定「Warning」] owner 不在 org で `assertCheckoutReady` が checkout を止める

- 判断: **見送る（設計どおり）**
- 根拠: `assertCheckoutReady()` の呼び出しは設計「段 0: 事前 assert」に明記された手順であり、
  請求先メールが解決できない org に対して Stripe Checkout を開始しない **fail-closed が設計意図**。
  owner が解決できない組織は `routeNotificationForMail()` も null になり請求通知が届かないため、
  「契約させてから請求書が誰にも届かない」より「契約前に止める」ほうが安全側である。
  出口（`PATCH /billing/contact`）は課金ゲート allowlist 内の `/billing` に実在する。

## [Suggestion] bughunt runtime fake の `expireCheckoutSession` が常に `'expired'`

- 判断: **見送る**
- 根拠: 段 4 の 3 分岐（`'complete'` / throw / `'expired'`）は Feature テスト
  （`tests/Support/FakeStripeGateway` の `$expireResult` / `$failOnExpire`）で全て固定済み。
  bughunt runtime fake は「中立帰還」が契約であり、分岐注入の口を増やすのは
  bug-hunt 基盤の no-op 原則に反する。

## Codex が「問題なし」と判定した実装者論点（6 / 9 / 10）

- 6（段 4 の部分適用）: fail-closed 上許容。**変更しない**。
- 9（`state()` の select 列）: `status` 追加で回避済み。`CheckoutStaleThresholdTest` の
  23h/25h 同値テストが結合を固定している。**変更しない**。
- 10（sweeper の intent 非スコープ）: 設計 verbatim。**変更しない**。

---

## 修正後の検証（全 green）

```
composer test          : pass (2517 tests / 2515 passed / 2 skipped / 10132 assertions / 400.7s)
composer phpstan       : pass (741/741 files, [OK] No errors。baseline / widen / @phpstan-ignore なし)
vendor/bin/pint --test : pass
pnpm lint              : pass
pnpm typecheck         : pass
pnpm test              : pass (98 files / 894 tests)
pnpm build             : pass
```
