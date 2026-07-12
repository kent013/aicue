# design-review Round 5: Round 4 指摘への対応報告

Round 4 の Critical 1 / Warning 3 をすべて対応しました（施策 3 は前ラウンドで APPROVE 済み）。
対応マトリクスと改訂後の該当コードを提示します。再レビューし全体判定を出してください。

## 対応マトリクス

# 対応マトリクス: design-review Round 4

全体判定: CHANGES_REQUESTED (Critical 1 / Warning 3。施策 3 は APPROVE 化)

## [Critical] 施策1: pid!=pgid (setsid 不成立) の worker を stop_shard_workers が「消滅済み」と誤認し pidfile を削除
- 判断: 対応する
- 根拠: 指摘どおり。`kill -0 -- -pid` は存在しない group に対して失敗するため、
  setsid 不成立の実 worker (別 pgid) を残したまま「停止成功」扱いになる。
- 対応内容: `stop_shard_workers` に停止側の pid==pgid 検証を追加
  (`ps -o pgid= -p` で照合)。不成立なら group kill も pidfile 削除もせず error + rc=1。
  起動側検証は R3 で追加済み (起動 1s 後の一括検証) のため、停止側検証との二重化で担保する。

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

---

## 改訂後コード

### 施策1: start_shard_workers 起動時 pid==pgid 検証 (R3 で追加済み・参考掲示)

```bash
    # fail-fast: 起動 1 秒後の即死検知。併せて pid==pgid (setsid が新 session/process group を
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
}
```

### 施策1: stop_shard_workers (Critical + Warning 反映後の全文)

```bash
# 当該 shard の worker を全停止する (teardown / 起動失敗ロールバックの共通経路)。
# 停止シーケンス: TERM → group 消滅待ち (最大 2s) → KILL escalation → 再確認。
# ★ 消滅を確認できた group のみ pidfile を削除する。残留/検証不能の group の pidfile は保持し
#   (追跡可能性を失わない)、戻り値 1 で失敗を通知する (呼び出し側が dropdb を抑止する)。
stop_shard_workers() {
    local shard=$1 conn wpidfile wpid wpgid t rc=0
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        wpidfile="$(worker_pidfile "${shard}" "${conn}")"
        [[ -f "${wpidfile}" ]] || continue
        wpid="$(cat "${wpidfile}" 2>/dev/null || echo)"
        if ! worker_alive "${shard}" "${conn}"; then
            # プロセス不存在 = 真に stale → 削除のみ。プロセスは存在するが所有確認 (cmdline 照合)
            # できない場合は、一時的な /proc 読み出し失敗や pid 再利用の可能性があり
            # 「停止済み」と誤認して追跡情報を消してはならない → pidfile 保持 + 失敗通知
            if [[ -n "${wpid}" && "${wpid}" != 0 ]] && kill -0 "${wpid}" 2>/dev/null; then
                echo "error: shard-${shard} worker (${conn}) pid=${wpid} は存在するが所有確認できない — kill せず pidfile 保持 (${wpidfile})" >&2
                rc=1
            else
                rm -f "${wpidfile}"
            fi
            continue
        fi
        # group kill の前提 (pid==pgid = setsid 成立) を停止側でも検証する。不成立のまま
        # kill -0 -- -pid すると「存在しない group が消滅済み」と誤認し実 worker を残留させる
        wpgid="$(ps -o pgid= -p "${wpid}" 2>/dev/null | tr -d ' ' || true)"
        if [[ "${wpgid}" != "${wpid}" ]]; then
            echo "error: shard-${shard} worker (${conn}) pid=${wpid} pgid=${wpgid:-?} — setsid 不成立のため group kill せず pidfile 保持 (${wpidfile})" >&2
            rc=1
            continue
        fi
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

### 施策5: self-test (y6) 失敗系・所有確認不能系の機能検証 (追加分)

```bash
    # (y6a) 正常系: setsid sleep を worker に見立て、group kill → 消滅 → pidfile 削除
    setsid sleep 30 > /dev/null 2>&1 &
    local fake_wpid=$!
    echo "${fake_wpid}" > "$(worker_pidfile 8 database-analysis)"
    ( worker_alive() { [[ "$1" == 8 && "$2" == database-analysis ]]; }
      stop_shard_workers 8 ) || t_fail "[y6a] stop_shard_workers (stub) が非ゼロ"
    wait "${fake_wpid}" 2>/dev/null || true    # 回収してから group 不在を確認 (flaky 防止)
    kill -0 -- "-${fake_wpid}" 2>/dev/null && t_fail "[y6a] stop_shard_workers が group を停止していない"
    [[ ! -f "$(worker_pidfile 8 database-analysis)" ]] || t_fail "[y6a] 停止成功後に pidfile が残留"

    # (y6b) 失敗系 (最重要不変条件): TERM/KILL を no-op 化して「group が残留」を再現し、
    #       rc=1 + pidfile 保持を機能検証する (kill -0 は builtin へ委譲 = 実在確認は本物)
    setsid sleep 30 > /dev/null 2>&1 &
    local fake_wpid2=$!
    echo "${fake_wpid2}" > "$(worker_pidfile 8 database-analysis)"
    ( worker_alive() { [[ "$1" == 8 && "$2" == database-analysis ]]; }
      kill() { [[ "${1:-}" == "-TERM" || "${1:-}" == "-KILL" ]] && return 0; builtin kill "$@"; }
      sleep() { :; }    # 待機ループ短縮
      stop_shard_workers 8 ) && t_fail "[y6b] 停止不能 group なのに rc=0"
    [[ -f "$(worker_pidfile 8 database-analysis)" ]] || t_fail "[y6b] 停止失敗時に pidfile が削除された (追跡情報喪失)"
    builtin kill -TERM -- "-${fake_wpid2}" 2>/dev/null || true    # 後片付け
    wait "${fake_wpid2}" 2>/dev/null || true
    rm -f "$(worker_pidfile 8 database-analysis)"

    # (y6c) stale pidfile (死亡済み pid) は kill なしで削除のみ・rc=0
    echo 999999999 > "$(worker_pidfile 8 database-render)"
    stop_shard_workers 8 || t_fail "[y6c] stale pidfile で stop_shard_workers が非ゼロ"
    [[ ! -f "$(worker_pidfile 8 database-render)" ]] || t_fail "[y6c] stale pidfile が削除されない"

    # (y6d) 「pid は存在するが所有確認できない」は pidfile 保持 + rc=1 (誤 stale 判定の防止)。
    #       自プロセス (bash) の pid = 実在するが cmdline 照合に一致しない代表例
    echo $$ > "$(worker_pidfile 8 database-media)"
    stop_shard_workers 8 && t_fail "[y6d] 所有確認できない実在 pid なのに rc=0"
    [[ -f "$(worker_pidfile 8 database-media)" ]] || t_fail "[y6d] 所有確認できない実在 pid の pidfile が削除された"
    rm -f "$(worker_pidfile 8 database-media)"
```

### 施策5: self-test 構造検査 (停止側 pgid 検証の存在を固定・追加分)

```bash
    echo "${stopw_def}" | grep -q 'ps -o pgid=' || t_fail "stop_shard_workers に pid==pgid 検証が無い (setsid 不成立の group を消滅済みと誤認する)"
```

以上です。Round 4 の Critical 1 / Warning 3 への対応を確認し、全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。
