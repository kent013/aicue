## 施策別判定

### 施策1: worker 共通ヘルパ

**REQUEST_CHANGES**

- [Critical] TERM 後2秒経過しても group が残っている場合でも pidfile を削除します。これにより、起動失敗 rollback や teardown 後に worker が残留しても、次回の停止処理で追跡できません。  
  修正案: 2秒後も残る場合は process group に `SIGKILL` を送り、消滅を再確認してから pidfile を削除する。なお残る場合は pidfileを保持して `die` し、dropdbへ進まない設計にしてください。
- [Warning] `kill -TERM -- "-${wpid}"` 失敗時の PID 単体 fallback は危険です。group が検証後に自然消滅した場合、再利用された同一PIDへTERMを送る微小な raceがあります。  
  修正案: setsidを起動不変条件とし、PID fallbackを廃止する。起動確認時に `ps -o pgid= -p "${pid}"` 等で `pid == pgid` も検証すると確実です。

`worker_alive` の `/proc` 読み出し race 対応は妥当です。

### 施策2: provision 配線

**APPROVE**

説明と実装方針の不整合は解消されています。manifest key の正規化と rollback の構造検査も適切です。

### 施策3: teardown 配線

**REQUEST_CHANGES**

- [Critical] group残留時にも pidfile削除後、dropdbへ進むため、接続を保持した孤児workerを管理不能にします。  
  修正案: `stop_shard_workers` が全groupの停止成功を保証し、失敗時は非ゼロを返す契約にする。`cmd_teardown` は失敗時にdropdbせず終了してください。

### 施策4: keepdb-check

**APPROVE**

変更なし。seamを持たない設計は適切です。

### 施策5: self-test

**REQUEST_CHANGES**

- [Warning] 現在の構造検査では「残留時にもpidfileを消す」回帰を防げません。  
  修正案: TERM→KILL escalation、group消滅後のみpidfile削除、停止失敗時のdropdb抑止を検査対象へ追加してください。

### 施策6: コメント整合

**APPROVE**

## 全体判定

**CHANGES_REQUESTED**

process groupを待つ修正自体は正しいです。残る問題は、停止できなかったgroupの追跡情報を削除して処理を継続する点です。TERM→KILL→消滅確認を停止関数の成功条件にすれば、承認可能です。