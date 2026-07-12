# 対応マトリクス: design-review Round 4

全体判定: CHANGES_REQUESTED (Critical 1 / Warning 3。施策 3 は APPROVE 化)

## [Critical] 施策1: pid!=pgid (setsid 不成立) の worker を stop_shard_workers が「消滅済み」と誤認し pidfile を削除
- 判断: 対応する
- 根拠: 指摘どおり。`kill -0 -- -pid` は存在しない group に対して失敗するため、
  setsid 不成立の実 worker (別 pgid) を残したまま「停止成功」扱いになる。
- 対応内容: `stop_shard_workers` に停止側の pid==pgid 検証を追加
  (`ps -o pgid= -p` で照合)。不成立なら group kill も pidfile 削除もせず error + rc=1。
  「起動直後 (次 worker 起動前) の PGID 検証」は、`&` 直後は setsid(2) 実行前の
  ps 読み取り race がありうるため採らず、既存の起動 1s 後の一括検証 (施策 1) と
  停止側検証の二重化で担保する (起動側検証は既に R3 で追加済み)。

## [Warning] 施策1: worker_alive の一時的 /proc 読み出し失敗でも stale 扱いで pidfile 削除
- 判断: 対応する
- 根拠: 指摘どおり。「プロセス不存在」と「実在するが所有確認不能」を区別すべき。
- 対応内容: 照合失敗時に `kill -0 pid` で実在確認し、実在する場合は pidfile 保持 + rc=1
  (誤 stale 判定の防止)。実在しない場合のみ削除。pid 再利用のケースも保守側 (保持 + 失敗通知)
  に倒れるが、teardown 非ゼロ終了で手動確認を促す挙動として許容。

## [Warning] 施策5: (y6) が停止失敗時の「rc=1 かつ pidfile 保持」を機能検証していない
- 判断: 対応する
- 対応内容: (y6b) を新設。subshell 内で `kill` の TERM/KILL を no-op 化 (`-0` は builtin へ
  委譲 = 実在確認は本物) + `sleep` no-op 化で「group 残留」を再現し、rc=1 と pidfile 保持を
  機能検証。(y6d) で「実在するが所有確認できない pid (自プロセス $$)」の保持 + rc=1 も検証。

## [Warning] 施策5: 停止確認が PID 単体 kill -0 で flaky
- 判断: 対応する
- 対応内容: (y6a) を `wait "${fake_wpid}"` で回収後に `kill -0 -- "-${fake_wpid}"` が
  失敗することの確認へ変更。
