**全体判定: CHANGES_REQUESTED**

設計の方向性は妥当ですが、共通契約の中核である「候補列挙後に行ロック下で述語を再評価する」が、解析・レンダ・チケット予約でまだ満たせていません。また撤去 gate の literal 方針が新設メソッド名と衝突しています。この 2 点は実装前に直すべきです。

**施策 1: 回収の共通契約と語彙 — APPROVE**

[Suggestion] `RecoveryStream::cadenceMinutes()` は現状 5/10 分だけなので問題ありませんが、将来 case 追加時に `60 % cadence === 0` を Unit / Architecture 側で固定すると `cron('*/N')` 前提が崩れません。

**施策 2: registry と sweeper — REQUEST_CHANGES**

[Warning] `candidateIds()` の `$pageSize` は `positive-int` 契約ですが、`min(self::PAGE_SIZE, $limit - $candidates)` を PHPStan が正に絞れない可能性があります。  
修正案: `$remaining` / `$pageSize` に `Assert::positiveInteger()` または `/** @var positive-int $pageSize */` を置き、level 10 で明示的に閉じてください。

[Suggestion] `withoutOverlapping()` 前提の運用まで共通化するなら、sweeper の長時間実行・異常終了時の挙動も docs に寄せるとよいです。

**施策 3: 入口コマンドと定期実行 — REQUEST_CHANGES**

[Warning] `withoutOverlapping()` を解析・レンダ・チケット予約にも新規適用すると、Laravel 既定のロック期限が長く、異常終了後に回収が長時間止まるリスクがあります。  
修正案: `withoutOverlapping(<明示分数>)` を採用し、値の根拠を docs / gate の目録に入れてください。少なくとも既定 24 時間に依存しない形が安全です。

[Suggestion] `format()` の `%d%s` と空文字引数は意味がないため削除できます。監視語彙を固定する出力なので、不要な揺れは減らしたほうがよいです。

**施策 4: 解析ジョブ stream — REQUEST_CHANGES**

[Critical] `recover()` が `lockCandidate($id)` で主キー取得したあと `failJob()` に委譲していますが、`failJob()` は terminal guard だけで、`queued/running が stale か` を再評価していません。候補列挙後に worker が `updated_at` を進めた running job を誤って failed にできます。  
修正案: `AnalysisJobService` に `failStaleJob(int $id, CarbonImmutable $sweptAt): bool` のような専用口を作り、同一トランザクション内で `whereKey + status + stale threshold + lockForUpdate()` を満たす行だけを失敗確定してください。共通の失敗確定本体は private に切り出して二重ロックを避けるのがよいです。

**施策 5: レンダジョブ stream — REQUEST_CHANGES**

[Critical] 施策 4 と同じ問題があります。`failJob()` は terminal guard であり、queued 10 分 / running 30 分の stale 条件を行ロック下で再評価しません。  
修正案: `RenderJobService::failStaleJob(int $id, CarbonImmutable $sweptAt): bool` を追加し、queued/running それぞれの閾値 WHERE を含めた locked query で再評価してから失敗確定してください。

**施策 6: チケット予約 stream — REQUEST_CHANGES**

[Warning] `release()` は reserved 状態だけを検査し、`expires_at <= $sweptAt` / expired monthly hold の再評価はしません。`expires_at` が実質 immutable だとしても、共通契約の説明とはズレます。  
修正案: `TicketLedgerService` 側に `releaseExpiredReservation(int $id, CarbonImmutable $sweptAt): bool` のような口を作り、予約行ロック時に stale 述語も再評価してください。競合は `ReservationNotReleasableException` または false で `Skipped` に写像します。

**施策 7: Stripe webhook stream — REQUEST_CHANGES**

[Critical] 新設メソッド名 `recoverStaleEvent()` が、施策 9 の撤去 gate が禁止する `recoverStale` literal と衝突しています。設計どおり literal 走査すると、新コード自身が落ちます。  
修正案: メソッド名を `recoverEvent()` / `recoverReceivedEvent()` / `recoverStuckEvent()` など、撤去対象 literal を含まない名前に変えるか、撤去 gate を token-aware にして旧メソッド宣言・旧呼び出しだけを検出する仕様へ変更してください。前者のほうが単純です。

**施策 8: 撮影アップロード予約 stream — APPROVE**

[Suggestion] S3 削除失敗後は次回自動回収されない設計なので、`RecoveredWithCleanupFailure` の出力だけでなく、ログ文言・監視 runbook に「この件数は手動確認対象」と明記してください。設計本文には書かれていますが、運用可観測性として強調したほうがよいです。

**施策 9: 目録 gate と撤去済み参照 gate — REQUEST_CHANGES**

[Critical] 撤去 gate の対象に `recoverStale` と `sweep()` を literal として含める設計は危険です。新 `StuckWorkRecoverySweeper::sweep()` も存在し、`recoverStaleEvent()` も新設予定です。  
修正案: 撤去 gate は「旧コマンド名」「旧 FQCN」「旧メソッドの宣言・呼び出しペア」に限定してください。単純な部分文字列ではなく、最低でも `ClassName::method` / `->method(` / `function method(` の単位で検出する必要があります。

[Warning] DirectFetchInventory の `IdSuppliedByInternalCaller` は、`recover(int $id)` が public interface である以上、provenance を機械的にはかなり弱くしか示せません。  
修正案: 根拠文に「`recover()` は sweeper からのみ呼ぶ契約」「candidateIds と recover の対応を Recovery 目録 gate が固定する」ことを明記し、可能なら app/tests の呼び出し元 inventory も追加してください。

**施策 10: 目録・docs の波及更新 — REQUEST_CHANGES**

[Warning] 監視語彙の変更は docs 更新だけでなく、既存の runbook / alert query / log parser がある場合に影響します。設計では「aicue にデプロイ定義は無い」とありますが、監視がコード外にある可能性は残ります。  
修正案: `docs/architecture.md` に旧語彙から新語彙への対応表を残し、少なくとも `retry-scheduled -> deferred`、`moved-to-recovery-pending -> escalated`、`replayed -> recovered` を明記してください。

**最重要の修正ポイント**

1. 解析・レンダ・チケット予約で、stale 述語を行ロック下で再評価する専用 Service 口を設ける。
2. 撤去 gate の literal 方針を修正し、新設コード自身を落とさない検出仕様にする。
3. `withoutOverlapping()` のロック期限を明示し、回収停止が長時間無音にならないようにする。