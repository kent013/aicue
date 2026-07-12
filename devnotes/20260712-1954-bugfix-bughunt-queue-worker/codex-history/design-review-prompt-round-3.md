# design-review Round 3: Round 2 指摘への対応報告

Round 2 の全指摘 (Warning 3 / Suggestion 4) をすべて「対応する」で反映しました。
対応マトリクスと改訂後の該当箇所 (差分中心) を提示します。再レビューし、全体判定を出してください。

## 対応マトリクス

# 対応マトリクス: design-review Round 2

全体判定: CHANGES_REQUESTED (Warning 3 / Suggestion 4。Critical なし。
Round 1 の DB_USERNAME 固定注入 Critical は Codex が反論を認め撤回)

## [Warning] 施策1/3: 終了待ちが master 単体の worker_alive 判定で、group 内の子の DB 接続残留と dropdb が race
- 判断: 対応する
- 根拠: 指摘どおり。master 消滅後も終了処理中の `queue:work --once` 子が接続を持ちうる。
- 対応内容: `stop_shard_workers` の待機条件を `kill -0 -- "-${wpid}"`（process group 全体の
  存在確認。cmdline 照合済みの自所有 group に対する signal 0 で安全）が失敗するまでに変更。
  warning 文言も group 消滅基準へ更新。teardown 側コメント・施策 3 補足も同期。

## [Warning] 施策2: 補足説明が改訂コードと不整合 (manifest key のハイフン表記 / teardown 回収の記述)
- 判断: 対応する
- 対応内容: 補足を `worker_pid_database_analysis`（underscore 正規化）と
  「起動失敗時は start_shard_workers 内で stop_shard_workers による即時回収 → die」に更新。

## [Suggestion] 施策1: /proc cmdline の存在確認→読み出し間の race は静かに return 1
- 判断: 対応する
- 対応内容: `worker_alive` の tr 読み出しに `2>/dev/null || true` を付け、空なら return 1。

## [Suggestion] 施策2: manifest underscore 正規化と起動失敗ロールバックの構造検査を self-test に追加
- 判断: 対応する
- 対応内容: (y3) に `cmd_provision` の `conn//-/_` 検査と `start_shard_workers` の
  `stop_shard_workers` 参照検査を追加。

## [Suggestion] 施策5: stop_shard_workers の group 生存確認の構造検査を追加
- 判断: 対応する
- 対応内容: (y3) に `kill -0 -- "-` の grep 検査を追加。

## [Suggestion] 施策5: 実機 teardown 確認で各 PGID の process group 不在も確認
- 判断: 対応する
- 対応内容: テスト計画（全体）の実機確認に「控えた各 pgid について `kill -0 -- -<pgid>` が
  失敗すること」を追加。

---

## 改訂後の該当セクション抜粋

### 施策1: worker_alive (cmdline 読み出し race 対応)

```bash
worker_alive() {
    local shard=$1 conn=$2 pid cmdline
    pid="$(cat "$(worker_pidfile "${shard}" "${conn}")" 2>/dev/null || echo)"
    [[ -n "${pid}" && "${pid}" != 0 && -r "/proc/${pid}/cmdline" ]] || return 1
    # 存在確認と読み出しの間にプロセスが終了する race に備え、読めなければ静かに false
    cmdline="$(tr '\0' ' ' < "/proc/${pid}/cmdline" 2>/dev/null || true)"
    [[ -n "${cmdline}" ]] || return 1
    echo "${cmdline}" | grep -q "artisan" \
        && echo "${cmdline}" | grep -q "queue:listen" \
        && echo "${cmdline}" | grep -q -- " ${conn} " \
        && echo "${cmdline}" | grep -q -- "--env=bughunt.local"
}
```

### 施策1: stop_shard_workers (process group 消滅待ちへ変更)

```bash
# kill 後は **process group 全体が消滅する**まで shard ローカルに短時間待つ
# (master 単体の消滅判定だと終了処理中の queue:work 子の DB 接続が残り dropdb と race する。
#  kill -0 -- -PGID は cmdline 照合済みの自所有 group に対する存在確認で、待機用途として安全。
#  全 shard 横断の pgrep 判定はしない。Codex 詳細 R1/R2 反映)
stop_shard_workers() {
    local shard=$1 conn wpidfile wpid t
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        wpidfile="$(worker_pidfile "${shard}" "${conn}")"
        [[ -f "${wpidfile}" ]] || continue
        if worker_alive "${shard}" "${conn}"; then
            wpid="$(cat "${wpidfile}")"
            kill -TERM -- "-${wpid}" 2>/dev/null || kill -TERM "${wpid}" 2>/dev/null || true
            for t in 1 2 3 4 5; do
                kill -0 -- "-${wpid}" 2>/dev/null || break
                sleep 0.4
            done
            kill -0 -- "-${wpid}" 2>/dev/null \
                && echo "warning: shard-${shard} worker group (${conn}, pgid=${wpid}) が TERM 後 2s で消滅しない (残留の可能性)" >&2
        fi
        rm -f "${wpidfile}"
    done
}
```

### 施策2: 補足の不整合修正 (該当 2 項の改訂後全文)

- manifest のキーは `worker_pid_database_analysis` 等（ハイフンを underscore に正規化。
  施策 2 変更後コードの `${conn//-/_}` 参照。shell 変数名として扱う将来の消費側でも壊れない）。
- worker 起動失敗時は `start_shard_workers` 内で **起動済みの同 shard worker を
  `stop_shard_workers` で即時回収してから** `die` する（施策 1 参照。serve と同じ fail-fast
  方針 + teardown 依存の残骸を減らす）。serve 等それ以外の残骸回収は従来どおり親 orchestrator の
  teardown（pidfile ベースで冪等）。

### 施策5: (y3) への追加検査

```bash
    echo "${wrk_def}" | grep -q 'stop_shard_workers' || t_fail "start_shard_workers に起動失敗ロールバックが無い"
    echo "${prov_def}" | grep -qF 'conn//-/_' || t_fail "cmd_provision の manifest worker key が underscore 正規化されていない"
    stopw_def="$(declare -f stop_shard_workers)"
    echo "${stopw_def}" | grep -qF 'kill -TERM -- "-' || t_fail "stop_shard_workers が process group kill でない"
    echo "${stopw_def}" | grep -q 'worker_alive' || t_fail "stop_shard_workers に cmdline 照合 (worker_alive) が無い"
    echo "${stopw_def}" | grep -qF 'kill -0 -- "-' || t_fail "stop_shard_workers に process group 消滅待ちが無い (master 単体判定は dropdb と race)"
```

### テスト計画 (全体) 実機確認への追加

- `teardown --run-id <ts>` 後に `pgrep -f "queue:listen"` が 0 件、pidfile 残留なし、
  かつ provision 時に控えた各 worker の pgid について `kill -0 -- -<pgid>` が失敗する
  （process group 全体の消滅 = 受け入れ条件の直接確認）

### 施策3: teardown 側の呼び出しコメントも group 消滅待ち基準へ同期済み

```bash
        # 専用 connection worker の停止 (serve より先に止め、--drop-db 時の DB 接続残留を防ぐ)。
        # stop_shard_workers が cmdline 照合 → process group 一括 kill → process group 全体の
        # 消滅待ち (kill -0 -- -PGID が失敗するまで最大 2s) まで行う
        # (全 shard 横断の pgrep 判定はしない = 他 shard の正常 worker に引きずられない)
        stop_shard_workers "${shard}"
```
