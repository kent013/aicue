Round 2の反論を検討しましたが、Criticalは解消していません。既往レビューが確認したのは保証表現の適正化であり、規約11からの適用除外を承認したものではありません。

## Critical

### 規約11への反論は、機械検査の検出範囲と規約の適用範囲を混同している

対象:

- `app/Http/Middleware/EnsureLoginMethodRemains.php` — 要修正
- `app/Support/Auth/LoginMethodRemovalPostCommitCallbacks.php` — 要修正
- `app/Services/Security/AuthMethodChangeNotifier.php` — 要修正
- `app/Listeners/Auth/NotifyAuthMethodChange.php` — 要修正
- `app/Services/Auth/PasswordCredentialService.php` — 要修正
- `app/Services/Auth/SocialAccountService.php` — 要修正
- `docs/architecture.md` — 要修正
- `docs/template-divergence.md` D36 — 要修正

反論が不十分な点は次のとおりです。

1. 既往レビューは原子性の適用除外を決定していない

提示された既往のWarningは、「commitと通知が1:1」という過大な保証表現をbest-effortへ狭める指摘です。これは実装が何を保証するかを正確に記述したものであって、「業務状態とqueue投入を分離してよい」という規約11の例外判断ではありません。

Round 2〜4のAPPROVEDも、その論点を明示的に再審査して例外を承認した証拠にはなりません。少なくとも提示された経緯には、次がありません。

- 規約11の意味上の対象に本通知が含まれるかの裁定
- 含まれる場合の正式な例外登録
- セキュリティ通知の欠落を許容するリスク受容者
- 「同一トランザクション」を満たさないことを承認する上位規約の変更

2. 静的gateが検出しないことは、許可を意味しない

`QueueDispatchAtomicityInventoryTest` が列挙されたLaravel APIだけを検出し、helperや動的迂回に沈黙することは、検出器の保証範囲です。規約本文の意味上の禁止範囲を、その静的検査の検出能力まで狭める根拠にはなりません。

今回のcollectorは名前も動作もpost-transaction callbackです。Laravelの既知APIを使わず同じ順序を自作したためgateを通過する、という説明は、規約適合の根拠ではなく静的gateの盲点を示しています。

特にD36の以下の主張は修正が必要です。

> 規約11の0件pinを満たしつつ……

満たしているのは「列挙APIが0件」という静的検査だけです。「業務状態の保存とqueue投入が同一トランザクション」という意味上の不変条件は満たしていません。

3. 既存契約との衝突は、規約11を回避する根拠にならない

`EnsureLoginMethodRemains` がtransaction内の外部I/Oや非原子的queue dispatchを禁じること自体は妥当です。しかし、これと規約11が衝突するなら必要なのは、次のいずれかです。

- リポジトリ既存の規約11準拠パターンで設計し直す
- 通知意図を同一トランザクションで耐久化する
- 上位規約について正式な適用除外を得る
- 要件を満たせないとして設計判断をエスカレーションする

「transaction内には置けないので、transaction後に自作callbackで投入する」は、衝突の片側だけを選んだ状態です。D36はテンプレートとの差分登録であり、AGENTS.mdの不変条件に対する免除登録ではありません。

4. best-effort配送とenqueue原子性は別の軸

重複配送、worker失敗、メールバウンスを許容することと、状態変更後に通知ジョブが一度も耐久化されないことは同じではありません。

認証手段変更通知は、業務状態を書かないとしてもアカウント侵害を本人が検知するセキュリティ制御です。「再送不能な業務的損失がない」だけでは、通知欠落のリスクがないとはいえません。

best-effort方針そのものは一貫していますが、それだけでは規約11からの除外根拠になりません。

したがって、詳細設計レビューで不足していたのは「表現の正確性」ではなく、規約11との意味上の衝突を正式に裁定する工程です。実装変更を避ける場合でも、少なくとも権限を持つ主体による明示的な適用除外と、その正本への登録が必要です。

## Warning

### 秘密情報テストの名前・docblockと実際の検証範囲が一致しない

`tests/Unit/Notifications/Auth/AuthMethodChangedNotificationTest.php` — 要修正

テスト名は「全caseの`toMail()`は秘密情報らしき文字列を含まない」ですが、`SocialAccountLinked` は検査せず `continue` しています。さらに、そのcaseでは疑わしいcontextが実際に本文へ出力されます。

現在の実装経路がprovider表示名しか渡さない点は確認できますが、テストが主張できるのは次の範囲です。

- 8 caseではcontextを本文へ出さない
- `SocialAccountLinked` はcontextを本文へ出す
- 実呼び出し元がprovider表示名だけを渡す

テスト名とdocblockをこの契約へ合わせ、`SocialAccountLinked` については `SocialAccountService` からprovider user IDなどが渡らないことを呼び出し境界で固定してください。現状のテストは、将来contextへ秘密値を渡す変更が入ってもSocial caseでは緑のままです。

## 解消済みの指摘

以下は妥当な対応として解消済みです。

- `AuthMethodChangeNotificationTest.php` — enqueue例外時にもパスワード変更が成功し、例外がreportされる実経路テストを追加: OK
- 実passkey登録routeの見送り — 有効なattestation基盤がなく、既存テストもvendorイベント境界を採用している説明は妥当: OK
- `AuthMethodChangeNotifierTest.php` — `Exceptions::assertReported()`追加、クロージャ型明示: OK
- 直接dispatchテスト3件 — `discard()`による後始末追加: OK
- 全検証コマンド — PHPStan level 10を含め全green: OK
- `enum-ts-sync-discovery.test.ts` — 内部enumの理由付き登録と件数pin更新: OK
- `JobExecutionDedupInventoryTest.php` — 重複配送許容の理由付き登録: OK
- `JobDeferralTerminationGateTest.php` — `NO_DEFERRAL`登録: OK
- `QueuedJobLeaseInventoryTest.php` — 既定接続の明示登録: OK
- `PasskeyPackageContractTest.php` — 同期購読者3件と順序の完全一致pin: OK
- D37〜D39、`adoption-debt.tsv`、`LedgerPins.php` — 変更した凍結ファイル4件の意図的逸脱への移行と件数更新: OK

## その他のファイル別判定

- `app/Enums/Auth/AuthMethodChangeEvent.php` — OK
- `app/Notifications/Auth/AuthMethodChangedNotification.php` — OK
- `app/Providers/AppServiceProvider.php` — OK
- `tests/Feature/Auth/AuthMethodChangeNotificationTest.php` — OK。ただし原子性設計を変更する場合は対応テストも更新が必要
- `tests/Feature/Auth/PasskeyAuditTrailTest.php` — OK
- `tests/Feature/Auth/PasskeyDeletionAtomicityTest.php` — OK
- `tests/Feature/Auth/PasskeyRecentAuthInvalidationTest.php` — OK
- `tests/Unit/Enums/Auth/AuthMethodChangeEventTest.php` — OK
- `tests/Unit/Services/Security/AuthMethodChangeNotifierTest.php` — OK
- `tests/Unit/Support/Auth/LoginMethodRemovalPostCommitCallbacksTest.php` — 状態機械の実装とテストは一致。ただし上記Criticalの設計判断に従い存否を再判定
- `tests/js/architecture/enum-ts-sync-discovery.test.ts` — OK

CHANGES_REQUESTED