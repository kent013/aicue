# 対応マトリクス: design-review Round 3

全体判定: CHANGES_REQUESTED (Critical 2 / Warning 2)

## [Critical] 施策1: group 残留時にも pidfile を削除し追跡不能になる
- 判断: 対応する
- 根拠: 指摘どおり。停止を確認できていない group の追跡情報を消すのは誤り。
- 対応内容: `stop_shard_workers` を「TERM → group 消滅待ち (最大 2s) → KILL escalation →
  再確認」のシーケンスに変更。**消滅を確認できた group のみ pidfile を削除**し、
  残留時は pidfile を保持して error ログ + 戻り値 1 で失敗を通知する契約に変更。

## [Critical] 施策3: 停止失敗でも dropdb へ進み、接続保持の孤児 worker を管理不能にする
- 判断: 対応する（ただし「失敗時に teardown を即終了」ではなく「失敗 shard の dropdb を抑止し
  他 shard の掃除は継続、最後に非ゼロ終了」とする）
- 根拠: 停止失敗 shard で dropdb しないのは指摘どおり必須。一方、ループを即時 die すると
  他 shard の serve/worker が放置され、失敗の巻き添えで残骸が増える。掃除は継続し、
  終了コードとメッセージで失敗を通知するのが teardown の責務（冪等な再実行も可能）。
- 対応内容: `cmd_teardown` に `workers_stopped` ガードを導入。stop 失敗時は当該 shard の
  dropdb をスキップ + warning、ループ完了後に `die 1`（pidfile 保持の旨と手動確認導線を明示）。

## [Warning] 施策1: PID 単体 TERM fallback は pid 再利用 race で危険
- 判断: 対応する
- 対応内容: fallback を全廃（group kill のみ）。代わりに起動時 fail-fast で
  `ps -o pgid= -p pid` により pid==pgid（setsid 成立）を検証し、group kill の前提を
  起動時不変条件として固定。self-test (y3) に「PID 単体 fallback が無いこと」の負の検査を追加。

## [Warning] 施策5: 「残留時にも pidfile を消す」回帰を構造検査で防げない
- 判断: 対応する
- 対応内容: (y3) に KILL escalation の存在検査・「escalation より前に pidfile 削除が無い」
  行順検査・`workers_stopped` (dropdb 抑止) 検査・PID fallback 不在検査・pgid 検証の存在検査を
  追加。さらに (y6) を新設し、`setsid sleep` を worker に見立てた**機能検査**
  （TERM → group 消滅 → pidfile 削除 / stale pidfile は削除のみ / 停止成功で rc=0）で二重化。
