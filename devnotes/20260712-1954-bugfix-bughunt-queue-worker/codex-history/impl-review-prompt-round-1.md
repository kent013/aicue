# アプリの使命 (North Star)

**AI-CUE** は SOP を起点に AI が動画シナリオを生成し、PWA でナビ撮影して標準化マニュアル動画を作る。bug-hunt harness はこのアプリの探索的検証基盤で、専用 queue connection のジョブ(解析/レンダ/削除)が処理されないと中核ジャーニー後半が検証不能になる (F-01)。

# 禁止事項 / 非交渉要件 (bug-hunt)

- dev DB 防御: 全 DB 操作は用途別 wrapper (env -i で DB_*/PG* 遮断 + DB名 regex + role guard) 経由のみ。worker 起動も guard_bughunt_runtime を直前に通す。
- orchestrator gate: worker 起動/停止は BUGHUNT_ORCHESTRATOR=1 の親専用 provision/teardown の内部処理のみ。
- self-test は実資源 (DB/serve/常駐プロセス) に触れない。
- テストなしの実装完了報告禁止。PHP/TS の型 widen 禁止。

【ツール使用制限】コマンド実行・書き込み禁止、テキスト分析に集中。ファイル読み込みは許可。

---

# system: 実装レビュアー (bash / bug-hunt harness)

あなたは経験豊富なシェルスクリプト/インフラのコードレビュアーです。bug-hunt harness (bash, set -euo pipefail, Linux devcontainer 前提) の変更をレビューしてください。

【レビュー観点】
1. 設計との一致性 (下記 detailed-design.md、施策1-6)
2. 正確性 (setsid process group kill・pid==pgid 検証・cmdline 照合・stop シーケンス TERM→group消滅待ち→KILL→再確認・所有確認不能時の pidfile 保持・teardown の dropdb 抑止・非ゼロ終了)
3. 競合/race (dropdb と worker DB 接続の残留 race、pid 再利用、/proc 読み出し race)
4. 冪等性・fail-closed (起動失敗ロールバック、drift check の fail-closed、dryrun 不起動)
5. dev DB 防御の非交渉要件を弱めていないか (env -i 隔離、DB_USERNAME=bughunt 固定注入、guard_bughunt_runtime)
6. self-test の網羅性 (構造検査 + 機能検査 (y6a-d)、seam を本体に持ち込まない stub 方式)
7. 後退リスク (teardown 再構成で serve/wrapper/coverage 掃除の回帰がないか、旧 `|| continue` 復活防止)

【出力形式】
- ファイル/施策ごとに判定 (APPROVE / REQUEST_CHANGES)
- 指摘は [Critical] [Warning] [Suggestion]、Critical/Warning には修正案を必ず添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語

---

# user

## 詳細設計書
リポジトリ内の `devnotes/20260712-1954-bugfix-bughunt-queue-worker/detailed-design.md` を読んでください (Codex 設計レビュー Round 5 で APPROVED 済み)。6 施策:
- S1: worker 共通ヘルパ (BUGHUNT_WORKER_CONNECTIONS / worker_pidfile・logfile / worker_alive の cmdline 照合 / start_shard_workers (setsid + queue:listen + 起動時 pid==pgid 検証 + 失敗ロールバック) / stop_shard_workers (TERM→group消滅待ち→KILL→再確認、pid==pgid 検証、所有確認不能は保持+rc=1))
- S2: provision に worker 起動配線 (e2) + manifest に worker pid (underscore 正規化)
- S3: teardown 再構成 (worker 停止を serve より前、workers_stopped ガードで停止失敗 shard の dropdb 抑止、最後に teardown_rc 非ゼロ die)
- S4: keepdb-check に worker 生存確認 (無条件、seam なし)
- S5: self-test [y] 追加 (導出/drift(PHP実評価)/構造検査/worker_alive/dryrun不起動/stop機能検査 y6a-d) + [v] keepdb を worker_alive stub 化
- S6: コメント整合 (ヘッダ / .env.bughunt.local.example)

## 実装差分 (git diff)
```diff
diff --git a/.env.bughunt.local.example b/.env.bughunt.local.example
index f30f0c4..074eae2 100644
--- a/.env.bughunt.local.example
+++ b/.env.bughunt.local.example
@@ -49,7 +49,10 @@ BUGHUNT_ADMIN_PASSWORD=             # 上書き必須
 
 SESSION_DRIVER=database
 CACHE_STORE=database
-QUEUE_CONNECTION=sync               # 非同期ジョブを同期実行 (探索の決定論性)
+# default connection のジョブのみ同期実行。onConnection() で専用 connection
+# (database-analysis / database-render / database-media) を指定するジョブは
+# provision が起動する queue:listen worker が処理する (bug-hunt-shard.sh 参照)
+QUEUE_CONNECTION=sync
 
 # 外部サービス (LLM/Stripe/Captcha/SSO 等) を fake 化する capability flag。
 # config('testing.fake_externals') を通して fake セットを有効化する
diff --git a/scripts/bug-hunt-shard.sh b/scripts/bug-hunt-shard.sh
index e619158..a2a9afc 100755
--- a/scripts/bug-hunt-shard.sh
+++ b/scripts/bug-hunt-shard.sh
@@ -23,7 +23,8 @@
 #
 # サブコマンド:
 #   provision --shard I --run-id TS [--coverage]
-#                                      # createdb(admin) → migrate:fresh+seed → serve → 実効env検証
+#                                      # createdb(admin) → migrate:fresh+seed → serve + queue worker
+#                                      # (database-analysis/render/media の queue:listen) → 実効env検証
 #                                      # --coverage: serve を pcov 付き php で起動し実装到達カバレッジを収集
 #                                      #             (既定 OFF。pcov 不在なら no-op で続行)。
 #   provision-all [--parallel=N] [--coverage] [--hold-lock]
@@ -103,6 +104,8 @@ shard_profile_dir() { echo "${TMP_BASE}/profile-$1"; }
 shard_download_dir() { echo "${TMP_BASE}/downloads-$1"; }
 shard_trace_dir() { echo "${TMP_BASE}/trace-$1"; }
 wrapper_path() { echo "${TMP_BASE}/shard-$1-cmd.sh"; }
+worker_pidfile() { echo "${TMP_BASE}/worker-$1-$2.pid"; }   # $1=shard $2=connection
+worker_logfile() { echo "${TMP_BASE}/worker-$1-$2.log"; }
 
 # --- 入力検証 -----------------------------------------------------------------
 
@@ -571,7 +574,136 @@ cmd_keepdb_check() {
     code="$(curl -s -o /dev/null -w '%{http_code}' "${url}/login" || true)"
     [[ "${code}" == "200" || "${code}" == "302" ]] \
         || die 1 "--keep-db reuse 中止: serve (${url}) 応答 ${code} (200/302 期待)。serve 未起動の可能性。"
-    echo "keepdb-check: assets fresh + serve ${code} (reuse 可)"
+    # worker 生存確認 (serve だけ生きていて worker が死んだ状態で reuse すると F-01 が再発する)。
+    # kill -0 でなく cmdline 照合 (stale pidfile / pid 再利用の誤判定防止。Codex 概念 R1 反映)。
+    local conn
+    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
+        worker_alive "${shard}" "${conn}" \
+            || die 1 "--keep-db reuse 中止: worker (${conn}) が起動していない/照合不一致 (pidfile: $(worker_pidfile "${shard}" "${conn}"))。queued 滞留 (F-01) が再発するため再 provision してください。"
+    done
+    echo "keepdb-check: assets fresh + serve ${code} + workers alive (reuse 可)"
+}
+
+# --- 専用 queue connection worker (F-01 対策) ----------------------------------
+# RunManualAnalysis / RunManualRender / DeleteTakeObjectsJob / DeleteRenderOutputsJob は
+# onConnection() で専用 connection (driver=database 固定) を指定するため、
+# .env.bughunt.local の QUEUE_CONNECTION=sync (default connection の差し替え) をバイパスする。
+# provision が本リストの connection ごとに queue:listen worker を起動し、teardown が停止する。
+# ★ リストは config/queue.php の「driver=database の専用 connection (既定 'database' を除く)」と
+#   一致させること (self-test [y] が PHP 実評価で drift を機械検出する。順序は不問 = sort 比較)。
+BUGHUNT_WORKER_CONNECTIONS=(database-analysis database-render database-media)
+
+# worker pid が「当該 connection の queue:listen」として生きているかの検証 (kill -0 では
+# stale pidfile / pid 再利用を誤判定するため /proc cmdline を照合する。Linux 前提 = teardown と同じ)。
+# 照合は artisan / queue:listen / connection 名 / --env=bughunt.local を独立に確認する
+# (単一パターンだと将来の引数順序変化で偽陰性化するため。Codex 詳細 R1 反映)。
+worker_alive() {
+    local shard=$1 conn=$2 pid cmdline
+    pid="$(cat "$(worker_pidfile "${shard}" "${conn}")" 2>/dev/null || echo)"
+    [[ -n "${pid}" && "${pid}" != 0 && -r "/proc/${pid}/cmdline" ]] || return 1
+    # 存在確認と読み出しの間にプロセスが終了する race に備え、読めなければ静かに false
+    cmdline="$(tr '\0' ' ' < "/proc/${pid}/cmdline" 2>/dev/null || true)"
+    [[ -n "${cmdline}" ]] || return 1
+    echo "${cmdline}" | grep -q "artisan" \
+        && echo "${cmdline}" | grep -q "queue:listen" \
+        && echo "${cmdline}" | grep -q -- " ${conn} " \
+        && echo "${cmdline}" | grep -q -- "--env=bughunt.local"
+}
+
+# 専用 connection worker の起動。serve と同一の env 隔離 (env -i + bughunt 値明示注入)。
+# - queue:listen を使う: 各イテレーションで子 (queue:work --once) を起動する Laravel 公式の
+#   スーパーバイザ構成。reseed (migrate:fresh) で jobs/cache テーブルが一時消滅して子が
+#   異常終了しても master が継続する (queue:work daemon は cache 読みの QueryException で
+#   静かに死に F-01 が再発しうる)。
+# - setsid で専用 process group (pid==pgid) 化: teardown が process group 一括 kill で
+#   master と子を race なく停止するため。
+# - --tries=1 は Job 側の $tries=1 と整合。--timeout=1800 は listener が子を kill する天井で、
+#   Job 側の $timeout (1,380/1,500) が pcntl alarm で先に効く (予約 TTL 1,800 と同値)。
+start_shard_workers() {
+    local shard=$1 db=$2 url=$3
+    guard_bughunt_runtime "${db}" bughunt
+    local conn pid
+    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
+        env -i PATH="${PATH}" HOME="${HOME}" \
+            DB_CONNECTION=pgsql \
+            DB_HOST="$(env_file_required DB_HOST)" DB_PORT="$(env_file_required DB_PORT)" \
+            DB_DATABASE="${db}" DB_USERNAME=bughunt DB_PASSWORD="$(env_file_get DB_PASSWORD)" \
+            APP_URL="${url}" \
+            setsid php artisan queue:listen "${conn}" --env=bughunt.local \
+                --sleep=1 --tries=1 --timeout=1800 \
+            > "$(worker_logfile "${shard}" "${conn}")" 2>&1 &
+        pid=$!
+        echo "${pid}" > "$(worker_pidfile "${shard}" "${conn}")"
+    done
+    # fail-fast: 起動 1 秒後の即死検知 (artisan 起動失敗・接続不能などを provision 段階で顕在化)。
+    # 併せて pid==pgid (setsid が新 session/process group を確立したこと) を検証する
+    # (group kill / group 消滅待ちの前提条件を起動時不変条件として固定。Codex 詳細 R3 反映)。
+    # 失敗時は起動済みの同 shard worker をその場で回収してから die (teardown 依存の残骸を減らす)
+    sleep 1
+    local pgid
+    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
+        pid="$(cat "$(worker_pidfile "${shard}" "${conn}")")"
+        pgid="$(ps -o pgid= -p "${pid}" 2>/dev/null | tr -d ' ' || true)"
+        if ! worker_alive "${shard}" "${conn}" || [[ "${pgid}" != "${pid}" ]]; then
+            stop_shard_workers "${shard}" || true
+            die 1 "shard-${shard} worker (${conn}) が起動しない/setsid 不成立 (pid=${pid} pgid=${pgid:-?}。$(worker_logfile "${shard}" "${conn}") 参照)"
+        fi
+    done
+}
+
+# 当該 shard の worker を全停止する (teardown / 起動失敗ロールバックの共通経路)。
+# setsid 起動により pid==pgid のため process group 一括 kill (master + queue:work --once 子)。
+# cmdline 照合 (worker_alive) 不一致/死亡済みの stale pidfile は kill せず削除のみ (誤 kill 防止優先)。
+# 停止シーケンス: TERM → group 消滅待ち (最大 2s) → KILL escalation → 再確認。
+# 成功条件は **process group 全体の消滅** (master 単体判定だと終了処理中の queue:work 子の
+# DB 接続が残り dropdb と race する)。kill -0 -- -PGID は cmdline 照合済みの自所有 group への
+# 存在確認で待機用途として安全。全 shard 横断の pgrep 判定はしない。
+# ★ 消滅を確認できた group のみ pidfile を削除する。残留/検証不能の group の pidfile は保持し
+#   (追跡可能性を失わない)、戻り値 1 で失敗を通知する (呼び出し側が dropdb を抑止する)。
+# (Codex 詳細 R1/R2/R3/R4 反映)
+stop_shard_workers() {
+    local shard=$1 conn wpidfile wpid wpgid t rc=0
+    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
+        wpidfile="$(worker_pidfile "${shard}" "${conn}")"
+        [[ -f "${wpidfile}" ]] || continue
+        wpid="$(cat "${wpidfile}" 2>/dev/null || echo)"
+        if ! worker_alive "${shard}" "${conn}"; then
+            # プロセス不存在 = 真に stale → 削除のみ。プロセスは存在するが所有確認 (cmdline 照合)
+            # できない場合は、一時的な /proc 読み出し失敗や pid 再利用の可能性があり
+            # 「停止済み」と誤認して追跡情報を消してはならない → pidfile 保持 + 失敗通知
+            if [[ -n "${wpid}" && "${wpid}" != 0 ]] && kill -0 "${wpid}" 2>/dev/null; then
+                echo "error: shard-${shard} worker (${conn}) pid=${wpid} は存在するが所有確認できない — kill せず pidfile 保持 (${wpidfile})" >&2
+                rc=1
+            else
+                rm -f "${wpidfile}"
+            fi
+            continue
+        fi
+        # group kill の前提 (pid==pgid = setsid 成立) を停止側でも検証する。不成立のまま
+        # kill -0 -- -pid すると「存在しない group が消滅済み」と誤認し実 worker を残留させる
+        wpgid="$(ps -o pgid= -p "${wpid}" 2>/dev/null | tr -d ' ' || true)"
+        if [[ "${wpgid}" != "${wpid}" ]]; then
+            echo "error: shard-${shard} worker (${conn}) pid=${wpid} pgid=${wpgid:-?} — setsid 不成立のため group kill せず pidfile 保持 (${wpidfile})" >&2
+            rc=1
+            continue
+        fi
+        kill -TERM -- "-${wpid}" 2>/dev/null || true
+        for t in 1 2 3 4 5; do
+            kill -0 -- "-${wpid}" 2>/dev/null || break
+            sleep 0.4
+        done
+        if kill -0 -- "-${wpid}" 2>/dev/null; then
+            kill -KILL -- "-${wpid}" 2>/dev/null || true
+            sleep 0.4
+        fi
+        if kill -0 -- "-${wpid}" 2>/dev/null; then
+            echo "error: shard-${shard} worker group (${conn}, pgid=${wpid}) が KILL 後も残留 (pidfile 保持: ${wpidfile})" >&2
+            rc=1
+            continue
+        fi
+        rm -f "${wpidfile}"
+    done
+    return "${rc}"
 }
 
 # --- worktree 文脈ガード -------------------------------------------------------
@@ -731,15 +863,25 @@ PY
         die 1 "shard-${shard} serve (:${port}) が 30s で起動しない (last=${code}、${TMP_BASE}/serve-${shard}.log 参照)"
     fi
 
+    # (e2) 専用 queue connection worker 起動 (F-01 対策。BUGHUNT_WORKER_CONNECTIONS 参照)
+    start_shard_workers "${shard}" "${db}" "${url}"
+
     # (f) shard wrapper 生成
     generate_wrapper "${shard}" "${run_id}"
 
-    # (g) manifest 記録
+    # (g) manifest 記録 (worker pid = pgid。setsid により group 一括 kill の対象 id を兼ねる)。
+    # key はハイフンを underscore に正規化 (shell 変数名として扱う消費側が現れても壊れないように)
+    local -a worker_pid_entries=()
+    local conn
+    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
+        worker_pid_entries+=("worker_pid_${conn//-/_}=$(cat "$(worker_pidfile "${shard}" "${conn}")")")
+    done
     manifest_update "${run_id}" "${shard}" \
         "db=\"${db}\"" "port=${port}" "app_url=\"${url}\"" \
         "serve_pid=${serve_pid}" "log_offset=${offset}" \
+        "${worker_pid_entries[@]}" \
         "started_at=\"$(TZ=Asia/Tokyo date '+%Y-%m-%d %H:%M:%S')\""
-    echo "provisioned: shard ${shard} db=${db} url=${url} serve_pid=${serve_pid}"
+    echo "provisioned: shard ${shard} db=${db} url=${url} serve_pid=${serve_pid} workers=${#BUGHUNT_WORKER_CONNECTIONS[@]}"
 }
 
 # --- provision-all (fan-out 用の薄い導線。lock 保持で N shard を一括 provision) ----
@@ -835,34 +977,48 @@ cmd_mail_urls() {
 cmd_teardown() {
     local run_id=$1 drop_db=${2:-}
     require_orchestrator "teardown"
-    local shard pid port
+    local shard pid port teardown_rc=0
     for shard in 0 1 2 3 4 5 6 7 8; do
+        # 専用 connection worker の停止 (serve より先に止め、--drop-db 時の DB 接続残留を防ぐ)。
+        # stop_shard_workers が cmdline 照合 → TERM → group 消滅待ち → KILL escalation →
+        # 再確認まで行い、残留があれば pidfile を保持して非ゼロを返す (追跡可能性を失わない)。
+        # 停止失敗 shard の dropdb は抑止する (接続保持した孤児 worker と dropdb の衝突防止)。
+        local workers_stopped=1
+        if ! stop_shard_workers "${shard}"; then
+            workers_stopped=0
+            teardown_rc=1
+            echo "warning: shard-${shard} の worker 停止に失敗 — この shard の dropdb をスキップ (pidfile 保持)" >&2
+        fi
+
         local pidfile="${TMP_BASE}/serve-${shard}.pid"
-        [[ -f "${pidfile}" ]] || continue
-        pid="$(cat "${pidfile}" 2>/dev/null || echo)"
-        port="$(shard_port "${shard}")"
-        if [[ -n "${pid}" && "${pid}" != 0 && -r "/proc/${pid}/cmdline" ]] \
-            && tr '\0' ' ' < "/proc/${pid}/cmdline" | grep -q "artisan serve" \
-            && tr '\0' ' ' < "/proc/${pid}/cmdline" | grep -q -- "--port=${port}"; then
-            # 子 php -S worker を親より先に撃つ (親 kill で init に reparent され孤児化するのを防ぐ)。
-            local wpid
-            for wpid in $(pgrep -P "${pid}" 2>/dev/null || true); do
-                if [[ -r "/proc/${wpid}/cmdline" ]] \
-                    && tr '\0' ' ' < "/proc/${wpid}/cmdline" | grep -q -- "-S " \
-                    && tr '\0' ' ' < "/proc/${wpid}/cmdline" | grep -q -- ":${port}"; then
-                    kill -TERM "${wpid}" 2>/dev/null || true
-                fi
-            done
-            kill -TERM "${pid}" 2>/dev/null || true
+        if [[ -f "${pidfile}" ]]; then
+            pid="$(cat "${pidfile}" 2>/dev/null || echo)"
+            port="$(shard_port "${shard}")"
+            if [[ -n "${pid}" && "${pid}" != 0 && -r "/proc/${pid}/cmdline" ]] \
+                && tr '\0' ' ' < "/proc/${pid}/cmdline" | grep -q "artisan serve" \
+                && tr '\0' ' ' < "/proc/${pid}/cmdline" | grep -q -- "--port=${port}"; then
+                # 子 php -S worker を親より先に撃つ (親 kill で init に reparent され孤児化するのを防ぐ)。
+                local wpid
+                for wpid in $(pgrep -P "${pid}" 2>/dev/null || true); do
+                    if [[ -r "/proc/${wpid}/cmdline" ]] \
+                        && tr '\0' ' ' < "/proc/${wpid}/cmdline" | grep -q -- "-S " \
+                        && tr '\0' ' ' < "/proc/${wpid}/cmdline" | grep -q -- ":${port}"; then
+                        kill -TERM "${wpid}" 2>/dev/null || true
+                    fi
+                done
+                kill -TERM "${pid}" 2>/dev/null || true
+            fi
+            rm -f "${pidfile}"
         fi
-        rm -f "${pidfile}"
-        if [[ "${drop_db}" == "--drop-db" ]] && ! is_dryrun; then
+        if [[ "${drop_db}" == "--drop-db" && "${workers_stopped}" == 1 ]] && ! is_dryrun; then
             pg_admin_for_provision dropdb "$(shard_db "${shard}")"
         fi
         rm -f "$(wrapper_path "${shard}")"
         rm -rf "storage/bughunt-coverage/.pcov-ini-${shard}"
     done
     reap_orphan_browser
+    [[ "${teardown_rc}" == 0 ]] \
+        || die 1 "teardown 一部失敗: worker group が残留 (該当 shard の DB は破棄していない)。上記 warning の pidfile から手動確認・再 teardown すること"
     echo "teardown done: run-id=${run_id}"
 }
 
@@ -1204,6 +1360,21 @@ PNPMEOF
     fg_run() { ( cd "${fg_root}" && PATH="${fg_bin}:${PATH}" "$@" ); }
     fg_build_called() { [[ -f "${fg_root}/public/build/.pnpm-build-called" ]]; }
     fg_reset_marker() { rm -f "${fg_root}/public/build/.pnpm-build-called"; }
+    # keepdb-check は worker 生存確認 (worker_alive) を含む (施策 4)。self-test は実 worker を
+    # 起動しないため、worker_alive を subshell ローカルに stub して assets/serve 判定を回帰させる
+    # (本体コードに seam は持ち込まない。stub は本体・親プロセスへ影響しない)。
+    fg_run_keepdb_ok() {
+        ( cd "${fg_root}" || exit 1
+          PATH="${fg_bin}:${PATH}"
+          worker_alive() { return 0; }
+          cmd_keepdb_check "$@" )
+    }
+    fg_run_keepdb_dead() {
+        ( cd "${fg_root}" || exit 1
+          PATH="${fg_bin}:${PATH}"
+          worker_alive() { return 1; }
+          cmd_keepdb_check "$@" )
+    }
     fg_make_fresh() {
         mkdir -p "${fg_root}/public/build/assets"
         cat > "${fg_root}/public/build/manifest.json" <<'MFEOF'
@@ -1297,17 +1468,23 @@ CURLEOF
     echo "stale-hash-0000" > "${fg_root}/public/build/.bughunt-build-fingerprint"
     fg_reset_marker
     rm -f "${fg_root}/curl-called"
-    rc=0; FG_CURL_MARKER="${fg_root}/curl-called" fg_run cmd_keepdb_check 0 >/dev/null 2>&1 || rc=$?
+    rc=0; FG_CURL_MARKER="${fg_root}/curl-called" fg_run_keepdb_ok 0 >/dev/null 2>&1 || rc=$?
     [[ "${rc}" != "0" ]] || t_fail "keepdb-check が stale assets で通過した (freshness ゲート不発)"
     fg_build_called && t_fail "keepdb-check が pnpm build を呼んだ (read-only 違反)"
     [[ ! -f "${fg_root}/curl-called" ]] || t_fail "keepdb-check が stale でも curl(liveness) に到達した (freshness 非先行)"
 
     fg_make_fresh; fg_reset_marker; rm -f "${fg_root}/curl-called"
-    rc=0; FG_CURL_MARKER="${fg_root}/curl-called" fg_run cmd_keepdb_check 0 >/dev/null 2>&1 || rc=$?
+    rc=0; FG_CURL_MARKER="${fg_root}/curl-called" fg_run_keepdb_ok 0 >/dev/null 2>&1 || rc=$?
     [[ "${rc}" == "0" ]] || t_fail "keepdb-check が fresh+serve200 で exit ${rc} (expected 0)"
     [[ -f "${fg_root}/curl-called" ]] || t_fail "keepdb-check が fresh で liveness(curl) に到達しない"
 
-    t_ok "asset freshness guard (fingerprint/chunk/cycle/dangling/hot/writeback + assets-check/keepdb-check)"
+    # worker 死亡 (worker_alive=false) は assets fresh + serve 200 でも exit≠0 (worker 検査が後段に実在)
+    fg_make_fresh; fg_reset_marker; rm -f "${fg_root}/curl-called"
+    rc=0; FG_CURL_MARKER="${fg_root}/curl-called" fg_run_keepdb_dead 0 >/dev/null 2>&1 || rc=$?
+    [[ "${rc}" != "0" ]] || t_fail "keepdb-check が worker 死亡でも通過した (F-01 再発の見逃し)"
+    [[ -f "${fg_root}/curl-called" ]] || t_fail "keepdb-check の worker 検査が serve(curl) 検査より前に来ている (後段であるべき)"
+
+    t_ok "asset freshness guard (fingerprint/chunk/cycle/dangling/hot/writeback + assets-check/keepdb-check + worker liveness)"
 
     echo "[x] --coverage: provision/provision-all で受理 + フラグ解釈 + 既定不変 + サブコマンド制限"
     export BUGHUNT_SELFTEST_DRYRUN=1
@@ -1334,6 +1511,123 @@ CURLEOF
     done
     t_ok "coverage flag interpretation + acceptance + default-unchanged"
 
+    echo "[y] queue worker 配線 (F-01 対策): 導出 / 構造 / drift / dryrun 不起動 / stop 機能"
+    # (y1) pidfile/logfile 導出
+    [[ "$(worker_pidfile 3 database-analysis)" == "${TMP_BASE}/worker-3-database-analysis.pid" ]] \
+        || t_fail "worker_pidfile 導出"
+    [[ "$(worker_logfile 0 database-render)" == "${TMP_BASE}/worker-0-database-render.log" ]] \
+        || t_fail "worker_logfile 導出"
+
+    # (y2) config/queue.php との drift check (PHP 実評価。grep でなく実 config を読む)
+    local expected_conns actual_conns
+    expected_conns="$(cd "${WORKSPACE}" && php -r '
+        require "vendor/autoload.php";
+        $cfg = require "config/queue.php";
+        $names = [];
+        foreach ($cfg["connections"] as $name => $conn) {
+            if (($conn["driver"] ?? "") === "database" && $name !== "database") { $names[] = $name; }
+        }
+        sort($names);
+        echo implode(" ", $names);
+    ' 2>/dev/null || echo "__php_failed__")"
+    actual_conns="$(printf '%s\n' "${BUGHUNT_WORKER_CONNECTIONS[@]}" | sort | tr '\n' ' ' | sed 's/ $//')"
+    if [[ "${expected_conns}" == "__php_failed__" ]]; then
+        t_fail "drift check 実行不能: vendor/autoload.php または config/queue.php を PHP 評価できない (依存未導入なら composer install 後に再実行)"
+    elif [[ "${expected_conns}" != "${actual_conns}" ]]; then
+        t_fail "drift: config/queue.php の専用 connection (${expected_conns}) と BUGHUNT_WORKER_CONNECTIONS (${actual_conns}) が不一致"
+    fi
+
+    # (y3) 構造検査 (既存 [w] と同じ流儀): provision → start_shard_workers → setsid/queue:listen、
+    #      teardown → stop_shard_workers が serve kill より前、旧 `|| continue` の復活防止
+    local prov_def wrk_def stopw_def td2_def
+    prov_def="$(declare -f cmd_provision)"
+    echo "${prov_def}" | grep -q 'start_shard_workers' || t_fail "cmd_provision に worker 起動配線が無い"
+    wrk_def="$(declare -f start_shard_workers)"
+    echo "${wrk_def}" | grep -q 'setsid php artisan queue:listen' || t_fail "start_shard_workers が setsid + queue:listen でない"
+    echo "${wrk_def}" | grep -q 'guard_bughunt_runtime' || t_fail "start_shard_workers が guard を通していない"
+    echo "${wrk_def}" | grep -q 'env -i' || t_fail "start_shard_workers が env -i 隔離でない"
+    echo "${wrk_def}" | grep -q 'stop_shard_workers' || t_fail "start_shard_workers に起動失敗ロールバックが無い"
+    echo "${wrk_def}" | grep -q 'ps -o pgid=' || t_fail "start_shard_workers に pid==pgid (setsid 成立) 検証が無い"
+    echo "${prov_def}" | grep -qF 'conn//-/_' || t_fail "cmd_provision の manifest worker key が underscore 正規化されていない"
+    stopw_def="$(declare -f stop_shard_workers)"
+    echo "${stopw_def}" | grep -qF 'kill -TERM -- "-' || t_fail "stop_shard_workers が process group kill でない"
+    echo "${stopw_def}" | grep -q 'worker_alive' || t_fail "stop_shard_workers に cmdline 照合 (worker_alive) が無い"
+    echo "${stopw_def}" | grep -qF 'kill -0 -- "-' || t_fail "stop_shard_workers に process group 消滅待ちが無い (master 単体判定は dropdb と race)"
+    echo "${stopw_def}" | grep -qF 'kill -KILL -- "-' || t_fail "stop_shard_workers に KILL escalation が無い"
+    echo "${stopw_def}" | grep -q 'ps -o pgid=' || t_fail "stop_shard_workers に pid==pgid 検証が無い (setsid 不成立の group を消滅済みと誤認する)"
+    echo "${stopw_def}" | grep -qF 'kill -TERM "${wpid}"' \
+        && t_fail "stop_shard_workers に PID 単体 TERM fallback がある (pid 再利用 race。group kill のみにする)"
+    local esc_ln lastrm_ln
+    esc_ln="$(echo "${stopw_def}" | grep -nF 'kill -KILL -- "-' | head -1 | cut -d: -f1)"
+    lastrm_ln="$(echo "${stopw_def}" | grep -n 'rm -f "\${wpidfile}"' | tail -1 | cut -d: -f1)"
+    [[ -n "${esc_ln}" && -n "${lastrm_ln}" && "${esc_ln}" -lt "${lastrm_ln}" ]] \
+        || t_fail "stop_shard_workers: 停止確認前に pidfile を削除している (残留 group の追跡情報を失う)"
+    td2_def="$(declare -f cmd_teardown)"
+    echo "${td2_def}" | grep -q 'stop_shard_workers' || t_fail "cmd_teardown に worker 停止配線が無い"
+    echo "${td2_def}" | grep -q 'workers_stopped' || t_fail "cmd_teardown に worker 停止失敗時の dropdb 抑止が無い"
+    local wkill2_ln skill_ln
+    wkill2_ln="$(echo "${td2_def}" | grep -n 'stop_shard_workers' | head -1 | cut -d: -f1)"
+    skill_ln="$(echo "${td2_def}" | grep -n 'serve-\${shard}.pid' | head -1 | cut -d: -f1)"
+    [[ -n "${wkill2_ln}" && -n "${skill_ln}" && "${wkill2_ln}" -lt "${skill_ln}" ]] \
+        || t_fail "cmd_teardown: worker 停止が serve 停止より後 (DB 接続残留リスク)"
+    echo "${td2_def}" | grep -qF '${pidfile}" ]] || continue' \
+        && t_fail "cmd_teardown: serve pidfile の '|| continue' が復活している (worker/wrapper 掃除がスキップされる回帰)"
+    echo "$(declare -f cmd_keepdb_check)" | grep -q 'worker_alive' \
+        || t_fail "cmd_keepdb_check に worker 生存確認が無い"
+
+    # (y4) worker_alive: stale pidfile (存在しない pid) と cmdline 不一致 (自プロセス pid) を fail 判定
+    mkdir -p "${TMP_BASE}"
+    echo 999999999 > "$(worker_pidfile 7 database-analysis)"
+    worker_alive 7 database-analysis && t_fail "worker_alive が存在しない pid を alive 判定"
+    echo $$ > "$(worker_pidfile 7 database-analysis)"
+    worker_alive 7 database-analysis && t_fail "worker_alive が cmdline 不一致 (bash 自身) を alive 判定"
+    rm -f "$(worker_pidfile 7 database-analysis)"
+
+    # (y5) dryrun provision は worker を起動しない (pidfile 不生成)
+    export BUGHUNT_SELFTEST_DRYRUN=1
+    ("${SCRIPT_PATH}" provision --shard 0 --run-id 20990301-000000) >/dev/null 2>&1 || t_fail "[y5] dryrun provision 失敗"
+    unset BUGHUNT_SELFTEST_DRYRUN
+    [[ ! -f "$(worker_pidfile 0 database-analysis)" ]] || t_fail "dryrun provision が worker pidfile を生成"
+
+    # (y6) stop_shard_workers の機能検査 (実 worker/DB を使わない軽量プロセスで代替。
+    #      worker_alive / kill / sleep は subshell 内 stub = 本体無変更):
+    # (y6a) 正常系: setsid sleep を worker に見立て、group kill → 消滅 → pidfile 削除
+    setsid sleep 30 > /dev/null 2>&1 &
+    local fake_wpid=$!
+    echo "${fake_wpid}" > "$(worker_pidfile 8 database-analysis)"
+    ( worker_alive() { [[ "$1" == 8 && "$2" == database-analysis ]]; }
+      stop_shard_workers 8 ) || t_fail "[y6a] stop_shard_workers (stub) が非ゼロ"
+    wait "${fake_wpid}" 2>/dev/null || true    # 回収してから group 不在を確認 (flaky 防止)
+    kill -0 -- "-${fake_wpid}" 2>/dev/null && t_fail "[y6a] stop_shard_workers が group を停止していない"
+    [[ ! -f "$(worker_pidfile 8 database-analysis)" ]] || t_fail "[y6a] 停止成功後に pidfile が残留"
+
+    # (y6b) 失敗系 (最重要不変条件): TERM/KILL を no-op 化して「group が残留」を再現し、
+    #       rc=1 + pidfile 保持を機能検証する (kill -0 は builtin へ委譲 = 実在確認は本物)
+    setsid sleep 30 > /dev/null 2>&1 &
+    local fake_wpid2=$!
+    echo "${fake_wpid2}" > "$(worker_pidfile 8 database-analysis)"
+    ( worker_alive() { [[ "$1" == 8 && "$2" == database-analysis ]]; }
+      kill() { [[ "${1:-}" == "-TERM" || "${1:-}" == "-KILL" ]] && return 0; builtin kill "$@"; }
+      sleep() { :; }    # 待機ループ短縮
+      stop_shard_workers 8 ) && t_fail "[y6b] 停止不能 group なのに rc=0"
+    [[ -f "$(worker_pidfile 8 database-analysis)" ]] || t_fail "[y6b] 停止失敗時に pidfile が削除された (追跡情報喪失)"
+    builtin kill -TERM -- "-${fake_wpid2}" 2>/dev/null || true    # 後片付け
+    wait "${fake_wpid2}" 2>/dev/null || true
+    rm -f "$(worker_pidfile 8 database-analysis)"
+
+    # (y6c) stale pidfile (死亡済み pid) は kill なしで削除のみ・rc=0
+    echo 999999999 > "$(worker_pidfile 8 database-render)"
+    stop_shard_workers 8 || t_fail "[y6c] stale pidfile で stop_shard_workers が非ゼロ"
+    [[ ! -f "$(worker_pidfile 8 database-render)" ]] || t_fail "[y6c] stale pidfile が削除されない"
+
+    # (y6d) 「pid は存在するが所有確認できない」は pidfile 保持 + rc=1 (誤 stale 判定の防止)。
+    #       自プロセス (bash) の pid = 実在するが cmdline 照合に一致しない代表例
+    echo $$ > "$(worker_pidfile 8 database-media)"
+    stop_shard_workers 8 && t_fail "[y6d] 所有確認できない実在 pid なのに rc=0"
+    [[ -f "$(worker_pidfile 8 database-media)" ]] || t_fail "[y6d] 所有確認できない実在 pid の pidfile が削除された"
+    rm -f "$(worker_pidfile 8 database-media)"
+    t_ok "queue worker wiring (derivation/drift/structure/alive/dryrun/stop 正常系+失敗系)"
+
     rm -rf "${sandbox}"
     unset BUGHUNT_SANDBOX
     if [[ "${failures}" -gt 0 ]]; then
```

## テスト結果
- `bash -n scripts/bug-hunt-shard.sh`: syntax OK。
- `scripts/bug-hunt-shard.sh self-test`: 全 pass (既存 [a]-[x] 回帰 + 新規 [y])。[y] の drift check は config/queue.php を PHP 実評価し database-analysis/render/media の 3 connection が BUGHUNT_WORKER_CONNECTIONS と一致することを確認。(y6a-d) は setsid sleep を worker に見立て stop の正常系/停止失敗時 pidfile 保持+rc=1/stale削除/所有確認不能保持 を機能検証。[v] の keepdb-check は worker_alive stub (ok/dead) で assets/serve 判定を回帰 + worker 検査が後段に実在することを確認。
- 回帰ゲート: pint --test / phpstan (No errors) / pnpm typecheck / lint / build すべて green (PHP/TS 変更なし)。
- 未実施: 実 postgres + BUGHUNT_ORCHESTRATOR での live provisioning/teardown (実機確認)。bughunt インフラ一式が必要なため本セッションでは self-test の構造・機能検査で代替。この点の設計妥当性も評価してください。

全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。
