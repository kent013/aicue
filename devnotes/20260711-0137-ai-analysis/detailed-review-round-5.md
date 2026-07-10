## 施策別判定

- 施策1〜5: `APPROVE`
- 施策6: `REQUEST_CHANGES`
- 施策7〜13: `APPROVE`

## 指摘

[Warning] `AnalysisTimeBudgetInvariantTest` の予約TTLが `30 * 60` のハードコードなので、`TicketLedgerService` の実際のTTLが変更されても検出できません。「台帳側を変えたら検出する」という契約を満たしていません。  
修正案: 固定時刻で実際に `reserve()` し、生成された `expires_at - created_at` をTTLとして時間budget連鎖を検証してください。台帳実装を変更せず、公開API経由で実契約を固定できます。

[Suggestion] `AnalysisPipelineTest` の説明にある「sync queue」は、pipeline直接呼び出しへ統一すると運用ノートとの誤解がなくなります。

## 全体判定

`CHANGES_REQUESTED`

`onConnection()`への修正、専用workerの運用契約、connection/queue drift検査は適切です。残る問題は、TTL不変条件テストが実際の台帳設定と接続されていない点だけです。