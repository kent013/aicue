前提: コマンド実行は禁止されているため、レビューは提示 diff とテスト結果記載のみを対象にしています。

**app/Services/Billing/BillingCustomerSynchronizer.php**
[Warning] 設計上 `dispatchFor()` は「必ず transaction 内から呼ぶ」契約ですが、実装はそれを強制せず、追加テストも `SyncBillingCustomerDetails` の `JobQueueing` tx level を観測していません。`Queue::fake()` の afterCommit フラグ確認と人工的な外側 rollback だけでは、実際の呼び出し元が tx 外へ移動しても検出できません。既知 2 経路のどちらか、または actual caller 経由で `baseline + 1` を固定してください。

**tests/Feature/Billing/BillingCustomerSynchronizerTest.php**
[Warning] 上記と同じく、反転後の主張「業務 tx に乗る」を直接検証していません。`外側 tx が rollback` は補助であり、設計書自身が「移設検出には使えない」としているパターンです。

**tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php**
[Warning] `maybeCreateAttempt()` が `ExecuteAutoRechargeAttemptJob` を dispatch するようになった後も、このテストは queue を `database` に固定していません。phpunit の `sync + after_commit=true` レーンでは commit 後に実行 job がインライン実行され得るため、「pending があるから 2 件目が no-op」を見ているつもりが、実行後状態や残高変化で偶然 no-op になる偽グリーンの余地があります。少なくともこのファイルでは `queue.default=database` を固定し、1 件目が `Pending` のまま残っていることも assert すべきです。

**tests/Feature/Billing/TicketLowBalanceNotificationIsolationTest.php**
[Warning] fake channel が実際に呼ばれたことを assert していません。現在の期待値は「通知経路が全く走らない」「DatabaseChannel の bind が効いていない」場合でも reservation が残れば通ります。`ThrowingDatabaseChannel` に呼び出し回数を持たせる、または `Log::warning` の発生を assert して、例外が発生して握られたことまで固定してください。

**tests/Support/Queue/QueueDispatchDeferralInventory.php**
[Warning] D5 の 0 件 pin は `$afterCommit` の既定値 `=== true` と `->afterCommit = true` 代入だけを見ていますが、Laravel 側は実質 truthy な値やインスタンスプロパティを見ます。特に `public function __construct(public bool $afterCommit = true)` のような constructor promotion や `public $afterCommit = 1` は検出外になり得ます。保証範囲を弱めて明記するか、token scan 側に追加してください。

**tests/Architecture/QueueDispatchAtomicityInventoryTest.php**
[Warning] mutation #24 の記録どおり、`deferralCandidateClasses()` 自体を `shouldQueueClasses()` 片側へ潰す変異は現状検出できません。`mergeCandidateClasses()` の純関数テストは有効ですが、実際の production 母集団関数がその merge を使い続けることまでは固定していません。Mailable 経路を重点にした 0 件 pin としては、ここはまだ弱いです。

**app/Support/QueueDispatchAtomicityGuard.php**
判定ロジックは設計の R1〜R5 と概ね一致しています。`sync` 除外を接続名で行う点、欠落・非配列を fail-closed にする点、R4/R5 の分離は妥当です。指摘なし。

**app/Services/Manual/* / app/Services/Capture/* / app/Services/Billing/AutoRechargeService.php / StripeWebhookProcessor.php / TicketLedgerService.php**
tx 内 dispatch への移設自体は設計に沿っています。`TicketLedgerService` の低残高通知についても、保証範囲をコメントで誇張していない点は良いです。上記テスト側の穴を除き、実装上の明確な原子性破壊は見当たりません。

**app/Notifications/Billing/* / app/Jobs/Billing/AutoRechargeTriggerJob.php**
契約の反転 docblock は必要な 6 要素を満たしており、削除ではなく新契約への置換として読めます。`ShouldBeUnique` 撤去も設計と一致しています。指摘なし。

**config/queue.php**
`sync.after_commit=true` の導入は設計 M1 と一致しています。指摘なし。

CHANGES_REQUESTED