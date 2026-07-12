## 施策別判定

### 施策1: worker停止契約

**REQUEST_CHANGES**

- [Critical] `pid != pgid` を検出した起動失敗時、rollback が `-pid` の存在しないprocess groupを停止した扱いにしてpidfileを削除し、実際のworker PIDを残留させる可能性があります。  
  修正案: `stop_shard_workers` でも保存PIDのPGIDを確認し、`pid != pgid` ならgroup killやpidfile削除をせず、エラー・pidfile保持・`rc=1`としてください。起動側は各worker起動直後にPGIDを検証してから次のworkerを起動するとさらに安全です。
- [Warning] `worker_alive` が一時的な `/proc` 読み出し失敗でfalseになった場合もstale扱いでpidfileを削除します。  
  修正案: cmdline不一致とプロセス不存在を区別し、PIDが存在するのに所有確認できない場合はpidfile保持・失敗通知としてください。

TERM→KILL escalation、成功時だけのpidfile削除、戻り値による失敗通知は適切です。

### 施策3: teardown

**APPROVE**

失敗shardのdropdbを抑止しつつ、他shardの掃除を継続して最後に非ゼロ終了する設計は、即時終了より回収性が高く妥当です。

### 施策5: self-test

**REQUEST_CHANGES**

- [Warning] `(y6)` は停止失敗時の「rc=1かつpidfile保持」を機能検証していません。今回の最重要不変条件なので構造検査だけでは不足です。  
  修正案: subshell内で `worker_alive` と `kill` をstubし、TERM/KILL後もgroupが残るケースを再現して、非ゼロ終了とpidfile保持を検証してください。
- [Warning] 停止確認がPID単体の `kill -0 "${fake_wpid}"` になっています。さらに終了直後は未回収プロセスの影響でflakyになり得ます。  
  修正案: `wait "${fake_wpid}" 2>/dev/null || true` で回収後、`kill -0 -- "-${fake_wpid}"` が失敗することを確認してください。

## 全体判定

**CHANGES_REQUESTED**

通常停止とteardownの失敗伝播は正しくなりました。残る必須点は、`pid != pgid` や所有確認不能時に「停止済み」と誤認してpidfileを消さないこと、および停止失敗時のpidfile保持を機能テストで固定することです。