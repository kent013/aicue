# design-review Round 4: Round 3 指摘への対応報告

Round 3 の Critical 2 / Warning 2 をすべて対応しました（施策 3 のみ「即終了」でなく
「失敗 shard の dropdb 抑止 + 他 shard の掃除継続 + 最後に非ゼロ終了」の変形採用。根拠は
マトリクス参照）。対応マトリクスと改訂後の該当コードを提示します。再レビューし全体判定を出してください。

## 対応マトリクス

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

---

## 改訂後の該当コード

### 施策1: stop_shard_workers (TERM → KILL escalation、成功確認後のみ pidfile 削除、失敗は rc=1)

```bash
# 停止シーケンス: TERM → group 消滅待ち (最大 2s) → KILL escalation → 再確認。
# ★ 消滅を確認できた group のみ pidfile を削除する。残留した group の pidfile は保持し
#   (追跡可能性を失わない)、戻り値 1 で失敗を通知する (呼び出し側が dropdb を抑止する)。
stop_shard_workers() {
    local shard=$1 conn wpidfile wpid t rc=0
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        wpidfile="$(worker_pidfile "${shard}" "${conn}")"
        [[ -f "${wpidfile}" ]] || continue
        if ! worker_alive "${shard}" "${conn}"; then
            rm -f "${wpidfile}"    # stale (照合不一致/死亡済み): kill せず削除のみ
            continue
        fi
        wpid="$(cat "${wpidfile}")"
        kill -TERM -- "-${wpid}" 2>/dev/null || true
        for t in 1 2 3 4 5; do
            kill -0 -- "-${wpid}" 2>/dev/null || break
            sleep 0.4
        done
        if kill -0 -- "-${wpid}" 2>/dev/null; then
            kill -KILL -- "-${wpid}" 2>/dev/null || true
            sleep 0.4
        fi
        if kill -0 -- "-${wpid}" 2>/dev/null; then
            echo "error: shard-${shard} worker group (${conn}, pgid=${wpid}) が KILL 後も残留 (pidfile 保持: ${wpidfile})" >&2
            rc=1
            continue
        fi
        rm -f "${wpidfile}"
    done
    return "${rc}"
}
```

### 施策1: start_shard_workers の fail-fast (pid==pgid 検証を追加、PID fallback は全廃)

```bash
    # fail-fast: 起動 1 秒後の即死検知。併せて pid==pgid (setsid が新 process group を
    # 確立したこと) を検証する (group kill / group 消滅待ちの前提条件を起動時不変条件として固定)。
    sleep 1
    local pgid
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        pid="$(cat "$(worker_pidfile "${shard}" "${conn}")")"
        pgid="$(ps -o pgid= -p "${pid}" 2>/dev/null | tr -d ' ' || true)"
        if ! worker_alive "${shard}" "${conn}" || [[ "${pgid}" != "${pid}" ]]; then
            stop_shard_workers "${shard}" || true
            die 1 "shard-${shard} worker (${conn}) が起動しない/setsid 不成立 (pid=${pid} pgid=${pgid:-?}。$(worker_logfile "${shard}" "${conn}") 参照)"
        fi
    done
```

### 施策3: cmd_teardown (停止失敗時の dropdb 抑止 + 最終非ゼロ終了)

```bash
cmd_teardown() {
    local run_id=$1 drop_db=${2:-}
    require_orchestrator "teardown"
    local shard pid port teardown_rc=0
    for shard in 0 1 2 3 4 5 6 7 8; do
        local workers_stopped=1
        if ! stop_shard_workers "${shard}"; then
            workers_stopped=0
            teardown_rc=1
            echo "warning: shard-${shard} の worker 停止に失敗 — この shard の dropdb をスキップ (pidfile 保持)" >&2
        fi

        local pidfile="${TMP_BASE}/serve-${shard}.pid"
        if [[ -f "${pidfile}" ]]; then
            pid="$(cat "${pidfile}" 2>/dev/null || echo)"
            ...（serve の cmdline 検証付き kill、既存のまま）...
            rm -f "${pidfile}"
        fi
        if [[ "${drop_db}" == "--drop-db" && "${workers_stopped}" == 1 ]] && ! is_dryrun; then
            pg_admin_for_provision dropdb "$(shard_db "${shard}")"
        fi
        rm -f "$(wrapper_path "${shard}")"
        rm -rf "storage/bughunt-coverage/.pcov-ini-${shard}"
    done
    reap_orphan_browser
    [[ "${teardown_rc}" == 0 ]] \
        || die 1 "teardown 一部失敗: worker group が残留 (該当 shard の DB は破棄していない)。上記 warning の pidfile から手動確認・再 teardown すること"
    echo "teardown done: run-id=${run_id}"
}
```

### 施策5: (y3) 追加検査 + (y6) 機能検査の新設

```bash
    echo "${wrk_def}" | grep -q 'ps -o pgid=' || t_fail "start_shard_workers に pid==pgid (setsid 成立) 検証が無い"
    echo "${stopw_def}" | grep -qF 'kill -KILL -- "-' || t_fail "stop_shard_workers に KILL escalation が無い"
    echo "${stopw_def}" | grep -qF 'kill -TERM "${wpid}"' \
        && t_fail "stop_shard_workers に PID 単体 TERM fallback がある (pid 再利用 race。group kill のみにする)"
    local esc_ln lastrm_ln
    esc_ln="$(echo "${stopw_def}" | grep -nF 'kill -KILL -- "-' | head -1 | cut -d: -f1)"
    lastrm_ln="$(echo "${stopw_def}" | grep -n 'rm -f "\${wpidfile}"' | tail -1 | cut -d: -f1)"
    [[ -n "${esc_ln}" && -n "${lastrm_ln}" && "${esc_ln}" -lt "${lastrm_ln}" ]] \
        || t_fail "stop_shard_workers: 停止確認前に pidfile を削除している (残留 group の追跡情報を失う)"
    echo "${td2_def}" | grep -q 'workers_stopped' || t_fail "cmd_teardown に worker 停止失敗時の dropdb 抑止が無い"

    # (y6) stop_shard_workers の機能検査 (実 worker/DB を使わない軽量プロセスで代替):
    setsid sleep 30 > /dev/null 2>&1 &
    local fake_wpid=$!
    echo "${fake_wpid}" > "$(worker_pidfile 8 database-analysis)"
    ( worker_alive() { [[ "$1" == 8 && "$2" == database-analysis ]]; }
      stop_shard_workers 8 ) || t_fail "[y6] stop_shard_workers (stub) が非ゼロ"
    kill -0 "${fake_wpid}" 2>/dev/null && t_fail "[y6] stop_shard_workers が group を停止していない"
    [[ ! -f "$(worker_pidfile 8 database-analysis)" ]] || t_fail "[y6] 停止成功後に pidfile が残留"
    echo 999999999 > "$(worker_pidfile 8 database-render)"
    stop_shard_workers 8 || t_fail "[y6] stale pidfile で stop_shard_workers が非ゼロ"
    [[ ! -f "$(worker_pidfile 8 database-render)" ]] || t_fail "[y6] stale pidfile が削除されない"
```

(y6) の `setsid sleep 30` は数秒で回収される transient プロセスで、self-test の「実資源
(DB/serve/常駐プロセス) に触れない」原則の範囲内 (既存 [j] の wrapper 実行と同水準)。
「残留時に pidfile を消す回帰」は (y3) の行順検査 + (y6) の機能検査で二重に固定します。
