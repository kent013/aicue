テキスト差分のみのレビューです。コマンド実行・ファイル読み込みはしていません。

**Findings**

`tests/Feature/Billing/TicketLedgerTest.php` / `tests/Feature/Billing/TicketCommitWinsTest.php`  
[Warning] 施策 6 の中核テストが足りません。詳細設計では「候補列挙後に別プロセスが commit した予約は Skipped」「候補列挙後に expires_at が延長された予約は解放されない」を fail-first で追加する計画ですが、差分上は既存 `releaseStale` 系の張り替えと monthly hold 維持だけに見えます。実装自体は `lockExpiredReservation()` で述語再評価しており方向は正しいですが、この施策の主目的が未固定です。

`tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php`  
[Warning] 施策 7 の「4 つの結果の種類がコマンド出力に現れる」テストが不足しています。既存処理の結果カウントは張り替えられていますが、`Recovered / Deferred / Escalated / Skipped` が `work:recover-stuck` の出力語彙として対応表どおり出ることは十分固定されていません。

`tests/Architecture/StuckWorkRecoveryInventoryTest.php`  
[Suggestion] `possibleOutcomes` は「空でない」ことしか見ておらず、目録の申告値と stream 実装・代表テストの対応までは検査していません。静的に完全検査できないのは理解できますが、現状だと「取りうる結果の種類を目録で申告」の gate としては弱めです。最低限、各 stream の申告 outcome が期待集合と一致する exact-fit テストにすると意図がより固定されます。

`app/Services/Billing/StripeWebhookProcessor.php`  
[Suggestion] `staleEventIds()` は現在、Stripe の `event_id` ではなく DB 主キー `id` を返しています。phpdoc は正しいですが、このクラスでは `event_id` が重要語彙なので、将来の誤読を避けるなら `staleWebhookRecordIds()` や `staleRecordIds()` の方が安全です。

**OK**

`app/Services/Recovery/StuckWorkRecoverySweeper.php`  
設計どおり、ページ送り・実効上限・dry-run 非実行・例外時の掃引継続・`limitReached` の後続確認が実装されています。

`app/Services/Manual/AnalysisJobService.php` / `app/Services/Manual/RenderJobService.php`  
候補列挙とロック下再評価が同じ述語に寄せられており、誤回収防止の設計と一致しています。

`app/Services/Billing/TicketLedgerService.php`  
実装は設計どおり、予約解放の private 本体共有と `whereKey + 滞留述語 + lockForUpdate` に寄っています。上記の通り、テスト固定が不足しています。

`app/Services/Recovery/Streams/StaleUploadReservationStream.php` / `app/Console/Commands/Capture/PurgeUploadReservationsCommand.php`  
回収と保持期間削除の分離、S3 cleanup 失敗の `RecoveredWithCleanupFailure` 化、外部 I/O をトランザクション外へ出す方針は設計と一致しています。

`routes/console.php` / `AGENTS.md` / `docs/architecture.md` / `docs/template-divergence.md`  
旧入口から `work:recover-stuck --stream=<key> --apply` への寄せ替え、監視語彙、保証しないものの記述は概ね誠実です。

**全体判定: CHANGES_REQUESTED**

実装方針は設計にかなり沿っていますが、施策 6 と 7 の必須テストが差分上不足しています。特にチケット予約の並行競合・述語再評価は今回の主眼なので、ここをテストで固定してから承認が妥当です。