## 施策別判定

### 施策1: worker 共通ヘルパ

**REQUEST_CHANGES**

- [Warning] `stop_shard_workers` の終了待ちは master PID の `worker_alive` だけを確認しています。master が先に終了し、同じ process group の `queue:work --once` 子が終了処理中でも待機を抜けるため、`dropdb` との race を完全には解消していません。  
  修正案: cmdline 検証後に PGID を確定し、TERM 後は `kill -0 -- "-${wpid}"` で process group 全体の消滅を待つ。これは kill 前に所有プロセスを検証済みなので、待機用途として安全です。
- [Suggestion] `/proc/${pid}/cmdline` は存在確認後にプロセスが終了する race があるため、読み出し失敗を静かに `return 1` とする実装が堅牢です。

`DB_USERNAME=bughunt` 固定注入への反論は**妥当**です。既存 runtime 経路との一貫性、provision 冒頭の固定値検査、PostgreSQL role 制約を組み合わせた防御なので、env 参照化より安全です。Round 1 の当該 Critical は撤回します。

### 施策2: provision 配線

**REQUEST_CHANGES**

- [Warning] 補足説明が改訂コードと不整合です。manifest key はコード上 `worker_pid_database_analysis` ですが、補足には `worker_pid_database-analysis` と残っています。また、起動失敗時は即時 rollback する設計なのに「起動済み worker は teardown で回収」と記載されています。  
  修正案: 補足を underscore key と `stop_shard_workers` による即時回収へ更新。
- [Suggestion] self-test に manifest key の underscore 正規化と、起動失敗 rollback の構造検査を追加すると改訂事項を固定できます。

### 施策3: teardown 配線

**REQUEST_CHANGES**

- [Warning] shard ローカル化と共通停止関数への集約は正しい改善です。ただし上記のとおり、master 消滅ではなく process group 消滅まで待たないと「DB 接続残留防止」という設計上の保証を満たしません。  
  修正案: `stop_shard_workers` 内の待機条件を process group の生存確認へ変更。

### 施策4: keepdb-check

**APPROVE**

seam 全廃と、subshell 内の関数 stub は妥当です。本番経路に迂回路を作らず、前段の assets/serve 判定と worker 判定を分離して検査できます。

### 施策5: self-test

**APPROVE**

- [Suggestion] process group 消滅待ちへ修正する場合、`stop_shard_workers` が group 生存確認を持つことも構造検査へ追加してください。
- [Suggestion] 実機 teardown 確認は全体 `pgrep` だけでなく、保存した各 PGIDについて process group が存在しないことを確認すると受け入れ条件に直結します。

### 施策6: コメント整合

**APPROVE**

README への同期方針追記を見送る判断も、今回のスコープ上妥当です。

## 全体判定

**CHANGES_REQUESTED**

Round 1 の主要論点は適切に解消されています。残る必須修正は、TERM 後に master PIDだけでなく **worker process group 全体の終了を待つこと**と、施策2の説明不整合の修正です。