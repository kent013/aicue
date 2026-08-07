**指摘**
[Critical] `app/Enums/Storage/ExternalClientBoundaryExemption.php` / `tests/Architecture/ExternalClientTimeoutInventoryTest.php`  
追加された `DefaultDiskWithoutAwsClient` と `InjectedPinnedControlClient` の免除条件が、コメント上の約束だけで gate に検証されていません。特に `SopTextExtractor` / `SourceDocumentService` は `Storage` の既定 disk が「ローカル」である前提ですが、既定 disk が env で `s3` に変わると、S3 集約 adapter を迂回した外部到達が現在の目録のまま通ります。  
対策: 既定 disk を明示 local にする、または免除 enum ごとの precondition gate を追加してください。少なくとも `DefaultDiskWithoutAwsClient` は `disk('s3')` 不使用だけでなく「実行時に S3 disk を解決しない」ことを固定する必要があります。

[Warning] `tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php`  
設計で代表経路に含まれていた「customer 新規」経路が実装テストから落ちています。現在の helper は常に `$organization->stripe_id = 'cus_gate'` を保存するため、`createOrGetStripeCustomer` の create 側を behavioral に固定できていません。予算 10 回にはまだ余裕がありますが、設計の「分岐集合を代表する」主張は弱まっています。  
対策: 新規 customer が実運用で到達不能なら、その不変条件をテスト名か別 gate で固定する。到達可能なら dataset に追加してください。

[Suggestion] `mprocs.yaml`  
設計上は `--timeout=540 → 300` が必須ですが、提示された `git diff` には `mprocs.yaml` の hunk がありません。一方で mutation evidence とテスト結果は mprocs gate 通過を主張しているため、ここは「提示 diff の欠落」と見ます。実コミットに `--timeout=300` が含まれていることだけ確認してください。

**ファイル別判定**
`app/Support/ExternalClientTimeouts.php`: OK。Stripe / AWS の timeout と retry 語彙は設計と一致。`max_attempts` と `@retries` の区別も明確です。

`app/Providers/ExternalClientTimeoutServiceProvider.php`: OK。Stripe の process-wide client pin は正しい調整点です。`new CurlClient` を使う判断も gate と整合しています。

`bootstrap/providers.php`: OK。provider 登録漏れをテストで固定している点も妥当です。

`config/filesystems.php`: OK。S3 disk の client default に data 系 timeout を pin しています。

`config/services.php`: OK。SES の Laravel MailManager 契約に依存する点は behavioral test で押さえています。

`app/Providers/AppServiceProvider.php`: OK。SNS singleton に制御系 options を明示しており、`services.ses` を自動継承しない点を補っています。

`app/Services/Capture/TakeObjectStorage.php`: OK。`headObject` の per-command `@http` / `@retries` は設計通りです。

`app/Enums/Storage/S3OperationSurface.php`: OK。保証範囲を誇張していません。

`app/Enums/Storage/ExternalClientBoundaryExemption.php`: Critical 上記。追加免除の precondition が gate 化されていません。

`tests/Architecture/ExternalClientTimeoutInventoryTest.php`: Warning/Critical 上記以外は良好。exact-fit、空振り防止、behavioral、負のコントロールはかなり厚いです。

`tests/Support/ExternalClientBoundaryScanner.php` / `PhpTokenScan.php` / `ScanScopeKind.php`: OK。設計逸脱のうち R3 拡張、R4 緩和、R5 条件化は実測理由があり、保証範囲も docs に書かれています。

`tests/Support/Storage/S3SurfaceInventory.php`: OK。

`tests/Feature/Capture/TakeObjectStorageTest.php`: OK。per-command が client default を上書きする負のコントロールが効いています。

`tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php`: OK。成功パス限定であることを明記しており、異常系 delete の存在を誇張していません。

`tests/Feature/Providers/ExternalClientTimeoutServiceProviderTest.php`: OK。ambient state 偽グリーン対策と finally 復元があります。

`tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php`: Warning 上記。

`tests/Support/Billing/CountingStripeHttpClient.php`: OK。

`tests/Unit/Support/Billing/GatewayFailureClassifierTest.php`: OK。message を観測語彙に出さない確認も妥当です。

`tests/Architecture/QueueWorkerLeaseInvariantTest.php` / `QueuedJobLeaseInventoryTest.php`: OK。T122/T131 の帯条件は `300 < 360` と `LOCK_TTL/uniqueFor < 360` の方向で壊していません。

`docs/architecture.md`: OK。wall-clock deadline ではないこと、Bulk の保証範囲、デプロイ順序の受容事項が誇張なく書かれています。

**全体判定: CHANGES_REQUESTED**  
実装の本体はよくできていますが、追加免除が gate の偽陰性を作る点はこの TODO の性質上ブロッカーです。そこを機械検証へ寄せれば、残りは小さなテスト網羅の補強で済みます。