提示 diff のみでレビューしました。コマンド実行は禁止条件どおり未実行です。

**app/Services/Billing/StripeWebhookProcessor.php**

[Warning] [StripeWebhookProcessor.php](/workspace/.claude/worktrees/tasks/T162/app/Services/Billing/StripeWebhookProcessor.php:123)  
`handle()` の例外経路で `finalize(... Failed ...)` の戻り値を見ず、CAS が 0 件でも必ず例外を再 throw しています。設計 C の「別世代が先に進めた場合は Stripe に再送を促す理由がない」という契約に対して、成功経路だけでなく失敗経路も同じ扱いにすべきです。

具体的には、処理中の旧 HTTP ワーカーが滞留回収に世代を進められたあと例外を投げた場合、行の所有権は旧世代にないのに Stripe へ 500 を返します。データ破壊までは見えませんが、冪等マシンの意図と運用ノイズの面で設計逸脱です。`finalize()` が `false` の場合は stale generation としてログに留め、throw せず return する分岐が必要です。

**tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php**

[Warning]  
上記の失敗 CAS 経路のテストがありません。現在ある「HTTP 経路で世代を追い越されたら終局書き込みを見送り例外も投げない」は process 成功ケースだけを固定しています。`TicketLedgerService::grantMonthly` の mock 内で `attempts` を進めたうえで例外を投げ、`event(new WebhookReceived(...))` が例外を外へ出さないことを追加で固定してください。

[Suggestion]  
migration の `down()` / `up()` を直接呼ぶテストは、途中 assertion 失敗時に同一プロセス内の後続テストへ schema 破損を残し得ます。`try/finally` で `up()` を必ず戻す形にすると、失敗時の二次被害を抑えられます。

**app/DataTransferObjects/Billing/StaleWebhookClaimDto.php**

問題なし。payload をログ context に含めない設計は、外部由来データのログ流出を避ける方針と一致しています。

**app/DataTransferObjects/Billing/WebhookRecoveryResultDto.php**

問題なし。任意 metadata を持たない DTO で、件数契約も明確です。

**app/Enums/Billing/*.php**

問題なし。`WebhookReplaySafety` と `HandledStripeWebhookEvent::replaySafety()` の網羅 match は設計どおりです。`RecoveryPending` / `WebhookRecoveryReason` も状態と理由の分離に合っています。

**app/Models/Billing/StripeWebhookEvent.php**

問題なし。nullable enum cast と PHPDoc は一致しています。

**database/migrations/2026_08_15_000100_add_recovery_reason_to_stripe_webhook_events_table.php**

問題なし。`recovery_reason IS NOT NULL` と `status = recovery_pending` の双方向 CHECK は設計どおりです。

**database/factories/Billing/StripeWebhookEventFactory.php**

問題なし。`stale()` の updated_at 注意書きと、テスト側 helper で保存後に押し戻す運用は整合しています。

**config/billing.php / routes/console.php**

問題なし。cron 配線、DTO 出力、`RuntimeException` の global 解決はいずれも設計どおりです。

**docs/architecture.md**

問題なし。滞留回収の保証範囲と監視対象は概ね適切に明記されています。

**フロント / DESIGN.md / Atomic Design**

本差分にフロント変更はありません。該当なしです。

**全体判定: CHANGES_REQUESTED**