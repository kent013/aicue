全体判定: CHANGES_REQUESTED

Round 2 の Critical 2件は解消しています。`recovery_reason` 列も妥当な最小変更です。ただし、回収 cron 自身の再処理が例外終了した場合、再試行経路が失われる問題が残っています。

### 1. 使命との整合性

[Suggestion] 使命との整合性は十分です。

付与イベントのクラッシュ滞留に限定し、保証範囲を「クラッシュ滞留の1経路」に絞った説明も適切です。

### 2. 禁止事項違反

[Suggestion] Feature テストと Architecture inventory を実装対象に含めており、禁止事項への明示的な抵触はありません。

テストファーストについては、詳細設計で各テストの fail 条件を先に固定すれば規約を満たせます。

### 3. 実現可能性

[Warning] `WebhookStaleClaimOutcome` の enum だけでは、commit 後の処理に必要なデータを安全に返せません。

`ClaimedForReplay` 後には少なくとも次が必要です。

- `event_id`
- `type`
- `payload`
- claim 後の `attempts`
- `recovery_reason` または通知用情報

Eloquent Model をトランザクション外へ持ち出すと、状態スナップショットと永続状態の区別が曖昧になります。

修正提案: outcome enum と、読み取り専用スナップショットを持つ DTO を組み合わせてください。例えば `WebhookStaleClaimResultDto` が `outcome`、`eventId`、`type`、`payload`、`attempts`、`reason` を持つ形です。`Skipped` では不要フィールドを nullable にするより、PHPStan level 10 を考えると outcome 別 DTO の方が堅牢です。

### 4. 期待効果の妥当性

[Suggestion] 「件数を常設の観測点とし、`report()` の配送は保証しない」という整理は妥当です。

ただし「置いた瞬間に1回出す」は、commit 後から通知前にプロセスが落ちれば0回になります。正確には「状態遷移を行った実行が、commit 後に1回送信を試みる」です。厳密な一回配送には outbox が必要ですが、本件での導入はスコープ過大です。

### 5. リスク

[Critical] 回収 cron による再処理が通常の例外で失敗すると、その行は `failed` になり、その後の再試行が保証されません。

設計では次のようになります。

1. 過去の Stripe 再送は、滞留中の `received` を見て200で終了している
2. cron が stale claim して `attempts+1`
3. cron 内の `process()` が例外終了
4. 行を `failed` にする
5. cron は `received` しか対象にしない
6. Stripe はすでに配信成功と認識しており、新たな再送が来る保証はない

したがって、スコープ外にある「`failed` は Stripe の再送で再処理される」という前提は、cron 起点の失敗には成立しません。クラッシュは回収できても、一時的な DB/API 障害では永久停止する経路が残ります。

修正提案: 回収実行の失敗には、通常 HTTP 処理の `failed` とは別の再試行契約を与えてください。候補は次のいずれかです。

- 推奨: `RecoveryRetryPending` 状態を追加し、cron が閾値経過後に再 claimする
- 最小変更: 回収失敗時は条件付き UPDATE で `received` のまま維持し、`failure_reason` と `updated_at` を更新して次回回収へ回す
- 代案: `failed` のうち「回収 cron 起点」を識別し、同じ cron の対象に含める

最小変更案は `received` が「実行中」と「再試行待ち」を兼ねるため意味が曖昧です。状態機械としては `RecoveryRetryPending` が最も明確です。いずれも上限到達時には `RecoveryPending + AttemptsExhausted` へ移す必要があります。

[Warning] `UnknownEventType` をすべて `recovery_pending` にすると、Cashier が受信する既知の非処理イベントも運用異常として蓄積します。

本文では `customer.updated` などが通常受信され、もともと `process()` の `null` arm で意図的に no-op とされています。このイベントがクラッシュで `received` に残る状況は限定的ですが、分類名の「未知」と「既知だが処理対象外」が混ざります。

修正提案: `IgnoredStripeWebhookEvent` として正常終局化できるイベントを分類するか、少なくとも recovery reason を `UnhandledEventType` と命名してください。真に未知のイベントと意図的 no-op を運用上区別しない判断なら、その理由を明記してください。

### 6. スコープの適切さ

[Suggestion] `recovery_reason` 専用列は過大ではありません。

状態は「今どう扱うか」、理由は「なぜそこに置かれたか」を表すため、自由文の `failure_reason` に固定コードを混ぜるより適切です。nullable string と enum cast の組み合わせも既存行との互換性を保てます。

[Warning] migration の既存行に対する意味を固定してください。

修正提案: 既存行はすべて `recovery_reason = NULL`、`recovery_pending` へ遷移するときだけ非NULLとし、他状態へ移る場合はNULLへ戻す、という不変条件を定義してください。Factory と Feature テストにも登録が必要です。

### 7. 型安全性

[Warning] `WebhookRecoveryResultDto` の `rested` は意味が曖昧です。

修正提案: `movedToRecoveryPending` または `manualReviewRequired` のように、何を数えたかが型名から分かる名称にしてください。再試行待ち状態を追加する場合は、`replayed`、`scheduledForRetry`、`movedToRecoveryPending`、`skipped` を分離する方が運用値として有効です。

境界での `tryFrom()`、enum cast、条件付き単一 UPDATE、payload 契約維持という方針は PHPStan level 10 と整合します。

### 確認事項への回答

- (a) Round 2 の Critical 2件は解消しています。
- (b) `recovery_reason` 列は妥当な最小変更です。
- (c) 7観点に「回収 replay が例外になった後も、上限まで再試行される」を追加する必要があります。また `recovery_reason` の状態整合も固定してください。
- (d) 現時点では未承認です。回収 cron 自身の失敗後に確実な再試行経路を設ければ、概念設計として承認可能です。