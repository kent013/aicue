#!/usr/bin/env bash
#
# scripts/verify-global-test-lock.sh — グローバルテストロック (scripts/global-test-lock.sh) の
# **並行挙動** を実プロセスで検証する恒久スイート (層 1)。
#
# ここで検証する性質 (ブロッキング待機 / fd 非継承 / プロセスグループの刈り取り /
# シグナル収束 / 再入 / 終了コード) は、プロセスを実際に走らせないと観測できない。
# PHP プロセス内 (層 2 = tests/Architecture/GlobalTestLockInventoryTest.php) からは
# 観測できないため、層を分けている。
#
# **層 2 から本スイートを実行してはならない** (非交渉): 層 2 は composer test の内側
# = グローバルロック保持中に走るため、ここを起動すると自分自身と競合する。
#
# 実ロック (/tmp/global-test-lane-<uid>.d) には一切触れない。常に mktemp -d 配下の
# scratch を GLOBAL_TEST_LOCK_DIR で使い、heartbeat / grace を縮めて実行時間を抑える。
#
# 使い方:
#   bash scripts/verify-global-test-lock.sh
#
# 出力: 各ケースを C01..C26 の ID 付きで PASS / FAIL / SKIP 報告し、
#       最後に集計を出す。FAIL が 1 つでもあれば非 0 で終了する。
#       **skip 数を必ず出す** (偽グリーンを避けるため)。
#
# set -e は使わない: 本スイートは「失敗するはずのコマンド」を意図的に実行して
# 終了コードを観測するため、-e があると 1 件目で全体が落ちる。
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LIB="${REPO_ROOT}/scripts/global-test-lock.sh"
WRAP="${REPO_ROOT}/scripts/with-global-test-lock.sh"
BROWSER_LANE="${REPO_ROOT}/scripts/run-browser-test.sh"

PASS=0
FAIL=0
SKIP=0
LANE_PID=""
LANE_RC=0

# scratch は全て TOKEN を含むパスに置く。こうすると `pgrep -f "$TOKEN"` だけで
# 本スイート由来の残党を機械的に検出・掃除できる (C11)。
TOKEN="gtlverify-$$-${RANDOM}${RANDOM}"
SCRATCH="$(mktemp -d)"
WORK="${SCRATCH}/${TOKEN}"
mkdir -p "${WORK}"

# 検証用の縮めた値 (レーンへは環境変数で渡る)
export GLOBAL_TEST_LOCK_HEARTBEAT_SECS=1
export GLOBAL_TEST_LOCK_GRACE_SECS=2
# 実ロックを絶対に掴まないため、継承されうる上書きを一旦落とす
unset GLOBAL_TEST_LOCK_DIR
unset GLOBAL_TEST_LOCK_NONCE

t_ok() { PASS=$((PASS + 1)); printf '  [PASS] %s %s\n' "$1" "$2"; }
t_fail() { FAIL=$((FAIL + 1)); printf '  [FAIL] %s %s\n' "$1" "$2"; }
t_skip() { SKIP=$((SKIP + 1)); printf '  [SKIP] %s %s\n' "$1" "$2"; }

suite_cleanup() {
    local pid
    for pid in $(pgrep -f "${TOKEN}" 2>/dev/null || true); do
        [ "${pid}" = "$$" ] && continue
        kill -KILL "${pid}" 2>/dev/null || true
    done
    rm -rf "${SCRATCH}"
}
trap suite_cleanup EXIT

have() { command -v "$1" >/dev/null 2>&1; }

HAVE_FLOCK=0
have flock && HAVE_FLOCK=1
HAVE_PS=0
have ps && have pgrep && HAVE_PS=1
HAVE_PROC=0
[ -d /proc/self/fd ] && HAVE_PROC=1

# ケースごとに未作成の lock dir パスを 1 つ払い出す。
# 連番はファイルで持つ: 本関数は $(new_dir) = サブシェルで呼ばれるため、
# シェル変数のインクリメントは呼び出し元へ伝播せず全ケースが同じ dir を共有してしまう。
new_dir() {
    local n
    n="$(cat "${WORK}/.seq" 2>/dev/null || echo 0)"
    n=$((n + 1))
    printf '%s\n' "${n}" >"${WORK}/.seq"
    printf '%s/lock-%03d\n' "${WORK}" "${n}"
}

# 第三者視点でロックが保持されているかを見る (別プロセスから flock -n を試す)。
lock_is_held() {
    local f="$1/lock"
    [ -e "${f}" ] || return 1
    flock -n "${f}" true >/dev/null 2>&1 && return 1
    return 0
}
lock_is_free() { ! lock_is_held "$1"; }

# 条件ポーリング (sleep 決め打ちを禁止する: 環境負荷で不安定になるため)。
poll_until() {
    local limit="$1"
    shift
    local i=0 max=$((limit * 10))
    while [ "${i}" -lt "${max}" ]; do
        if "$@"; then return 0; fi
        sleep 0.1
        i=$((i + 1))
    done
    return 1
}

file_exists() { [ -f "$1" ]; }

# zombie (Z) は「消滅」とみなす — ライブラリの _gtl_group_alive と同じ判定にする。
# 本コンテナの PID 1 は子を reap しないため、孤児化して死んだプロセスは Z のまま残り、
# kill -0 では「生存」と誤判定される (fd もポートも保持しないので実体は消滅済み)。
proc_gone() {
    local st
    st="$(ps -o stat= -p "$1" 2>/dev/null | tr -d ' ')"
    [ -z "${st}" ] && return 0
    case "${st}" in Z*) return 0 ;; esac
    return 1
}
proc_alive() { ! proc_gone "$1"; }

# スイート由来で **実際に生きている** プロセス (zombie は消滅済みとみなす)。
live_strays() {
    local pid out=""
    for pid in $(pgrep -f "${TOKEN}" 2>/dev/null || true); do
        [ "${pid}" = "$$" ] && continue
        proc_gone "${pid}" && continue
        out="${out} ${pid}"
    done
    printf '%s' "${out# }"
}
no_strays() { [ -z "$(live_strays)" ]; }

has_children() { [ -n "$(pgrep -P "$1" 2>/dev/null || true)" ]; }
pattern_running() { [ -n "$(pgrep -f "$1" 2>/dev/null || true)" ]; }
is_orphan() { [ "$(ppid_of "$1")" = "1" ]; }

run_lane_fg() {
    local d="$1" errf="$2"
    shift 2
    LANE_RC=0
    GLOBAL_TEST_LOCK_DIR="${d}" bash "${WRAP}" "$@" >"${errf}.out" 2>"${errf}" || LANE_RC=$?
}

# レーンは **monitor mode を有効にして** 起動する。job control を切ったまま `&` で
# 起動すると POSIX 規定によりレーンの SIGINT/SIGQUIT が SIG_IGN で開始され、
# 「入口で無視されたシグナルは trap できない」ため INT の契約 (128+2) を観測できない。
# 実運用では端末から前景実行されるので、job control ありが実挙動に近い。
start_lane() {
    local d="$1" errf="$2" prev=0
    shift 2
    case "$-" in *m*) prev=1 ;; esac
    set -m
    GLOBAL_TEST_LOCK_DIR="${d}" bash "${WRAP}" "$@" >"${errf}.out" 2>"${errf}" &
    LANE_PID=$!
    [ "${prev}" = "1" ] || set +m
}

pgid_of() { ps -o pgid= -p "$1" 2>/dev/null | tr -d ' '; }
ppid_of() { ps -o ppid= -p "$1" 2>/dev/null | tr -d ' '; }

# root の子孫 pid を全て列挙する (プロセスグループ離脱の検出に使う)。
descendants_of() {
    local root="$1" all frontier next found pid kid kids
    all="$(ps -A -o pid= -o ppid= 2>/dev/null)"
    frontier="${root}"
    found=""
    while [ -n "${frontier// /}" ]; do
        next=""
        for pid in ${frontier}; do
            kids="$(printf '%s\n' "${all}" | awk -v p="${pid}" '{ if ($2 == p) print $1 }')"
            for kid in ${kids}; do
                found="${found} ${kid}"
                next="${next} ${kid}"
            done
        done
        frontier="${next}"
    done
    printf '%s\n' "${found}"
}

# ---------------------------------------------------------------------------
# 検証用のヘルパースクリプト群 (パスに TOKEN を含むので pgrep で追跡できる)
# ---------------------------------------------------------------------------
SLEEPER="${WORK}/sleeper.sh"
cat >"${SLEEPER}" <<'EOF'
#!/usr/bin/env bash
# 検証用のダミーレーン本体。$1 秒眠るだけ。
sleep "${1:-5}"
EOF

SPAWNER="${WORK}/spawn-grandchild.sh"
cat >"${SPAWNER}" <<'EOF'
#!/usr/bin/env bash
# 直接子。孫を残して **先に** 終了する ($1=sleeper $2=秒 $3=孫 pid の記録先)。
bash "$1" "$2" &
echo $! >"$3"
exit 0
EOF

IGNORER="${WORK}/ignore-signals.sh"
cat >"${IGNORER}" <<'EOF'
#!/usr/bin/env bash
# INT/TERM を無視する直接子 + 孫 ($1=sleeper $2=秒 $3=孫 pid の記録先)。
# SIG_IGN は fork/exec を越えて継承されるため、孫 (sleep) も無視する。
trap '' INT TERM
bash "$1" "$2" &
echo $! >"$3"
bash "$1" "$2"
EOF

FDCHECK="${WORK}/fd-check.sh"
cat >"${FDCHECK}" <<'EOF'
#!/usr/bin/env bash
# ロック fd (7) が子へ継承されていないことを確認する。
if [ -e "/proc/self/fd/7" ]; then echo "fd7=leak"; else echo "fd7=ok"; fi
EOF

PGIDCHECK="${WORK}/pgid-check.sh"
cat >"${PGIDCHECK}" <<'EOF'
#!/usr/bin/env bash
# 直接子が専用プロセスグループのリーダーで、孫も同じグループに残ることを出力する。
self_pgid="$(ps -o pgid= -p $$ 2>/dev/null | tr -d ' ')"
echo "self=$$"
echo "self_pgid=${self_pgid}"
bash -c 'echo "child_pgid=$(ps -o pgid= -p $$ 2>/dev/null | tr -d " ")"'
# 外側から直接子とその子孫を観測できるだけの寿命を持たせる
sleep 3
EOF

REENTER="${WORK}/reenter.sh"
cat >"${REENTER}" <<'EOF'
#!/usr/bin/env bash
# 保持中の子孫から再度ラッパを呼ぶ (再入)。$1=wrapper $2=lockdir $3=結果ファイル
set -uo pipefail
before="$(head -n 1 "$2/owner" 2>/dev/null || echo MISSING)"
rc=0
bash "$1" bash -c 'exit 0' >/dev/null 2>&1 || rc=$?
after="$(head -n 1 "$2/owner" 2>/dev/null || echo MISSING)"
printf 'rc=%s\nbefore=%s\nafter=%s\n' "${rc}" "${before}" "${after}" >"$3"
EOF

MONITORCHECK="${WORK}/monitor-check.sh"
cat >"${MONITORCHECK}" <<'EOF'
#!/usr/bin/env bash
# monitor mode (set -m) がレーン実行の前後で復元されることを確認する。$1=lib $2=lockdir
set -uo pipefail
# shellcheck source=/dev/null
. "$1"
before=off
case "$-" in *m*) before=on ;; esac
global_test_lock_acquire "C16 monitor"
global_test_lock_run true
after=off
case "$-" in *m*) after=on ;; esac
echo "monitor_before=${before} monitor_after=${after}"
EOF

HOOKLANE="${WORK}/hook-lane.sh"
cat >"${HOOKLANE}" <<'EOF'
#!/usr/bin/env bash
# lane 固有の EXIT フックが **ロック保持中に** 走ることを確認する。
# $1=lib $2=lockdir $3=結果ファイル
set -uo pipefail
GTL_LOCKDIR="$2"
GTL_RESULT="$3"
# shellcheck source=/dev/null
. "$1"

lane_exit_hook() {
    # 別プロセスから flock -n を試す。保持中なら失敗する (= held)。
    if flock -n "${GTL_LOCKDIR}/lock" true >/dev/null 2>&1; then
        echo "hook_lock=free" >>"${GTL_RESULT}"
    else
        echo "hook_lock=held" >>"${GTL_RESULT}"
    fi
}

global_test_lock_acquire "C17 hook lane"
global_test_lock_on_exit lane_exit_hook
global_test_lock_run true
echo "lane_done=1" >>"${GTL_RESULT}"
EOF

DOUBLEACQ="${WORK}/double-acquire.sh"
cat >"${DOUBLEACQ}" <<'EOF'
#!/usr/bin/env bash
# 同一プロセスからの二重 acquire で owner が落ちないことを確認する。
# $1=lib $2=lockdir $3=結果ファイル $4=sleeper
set -uo pipefail
GTL_RESULT="$3"
# shellcheck source=/dev/null
. "$1"
global_test_lock_acquire "C20 first"
global_test_lock_acquire "C20 second"
echo "mode=${_GTL_MODE}" >"${GTL_RESULT}"
global_test_lock_run bash -c 'if [ -e /proc/self/fd/7 ]; then echo fd7=leak; else echo fd7=ok; fi' >>"${GTL_RESULT}"
global_test_lock_run bash "$4" 3
EOF

ABNORMAL="${WORK}/abnormal-exit.sh"
cat >"${ABNORMAL}" <<'EOF'
#!/usr/bin/env bash
# 子を起動した **後** に内部エラーで EXIT へ抜けるケース。
# 残党を残さずグループを収束させてからロックを解放しなければならない。
# $1=lib $2=ignorer $3=sleeper $4=孫 pid の記録先 $5=直接子 pid の記録先
set -uo pipefail
# shellcheck source=/dev/null
. "$1"
global_test_lock_acquire "C22 abnormal"

# global_test_lock_run の内部状態 (子を起動した直後) を再現する。
set -m
bash "$2" "$3" 60 "$4" 7>&- &
_GTL_CHILD_PID=$!
set +m
_GTL_CHILD_PGID="${_GTL_CHILD_PID}"
echo "${_GTL_CHILD_PID}" >"$5"

# 子が signal trap を張り終える前にエラーを起こすと、TERM 無視の検証にならない
# (起動レースで素直に死ぬ)。孫 pid の記録をもって起動完了とみなす。
i=0
while [ ! -s "$4" ] && [ "${i}" -lt 100 ]; do
    sleep 0.1
    i=$((i + 1))
done

_gtl_die "simulated internal error after spawning the lane"
EOF

SURVIVOR="${WORK}/kill-survivor.sh"
cat >"${SURVIVOR}" <<'EOF'
#!/usr/bin/env bash
# SIGKILL を生き延びるプロセスグループの模擬 ($1=lib $2=sleeper)。
# _gtl_group_alive は shell 関数なので、source 後に再定義して注入できる。
set -uo pipefail
# shellcheck source=/dev/null
. "$1"
_gtl_group_alive() { return 0; }   # 常に「生存」
global_test_lock_acquire "C23 survivor"
global_test_lock_run bash "$2" 2
echo "SHOULD_NOT_REACH"
EOF

STRICTLANE="${WORK}/strict-lane.sh"
cat >"${STRICTLANE}" <<'EOF'
#!/usr/bin/env bash
# 実レーン (scripts/run-test.sh / scripts/run-browser-test.sh) と同じ呼び出し条件を
# 再現するフィクスチャ ($1=lib)。
#
# **この 2 条件が非交渉** (どちらかを崩すと race を再現できない):
#   (1) `set -e` あり
#   (2) global_test_lock_run を `|| ...` で受けない
# with-global-test-lock.sh は `global_test_lock_run "$@" || status=$?` と書くため、
# POSIX の規定で関数本体の -e が無効化され、代入失敗が致命傷にならない。
# 一方 run-test.sh / run-browser-test.sh は裸で呼ぶので落ちる。
set -euo pipefail
# shellcheck source=/dev/null
. "$1"
global_test_lock_acquire "strict lane fixture"
global_test_lock_run true
echo "lane_ok=1"
EOF

chmod +x "${SLEEPER}" "${SPAWNER}" "${IGNORER}" "${FDCHECK}" "${PGIDCHECK}" \
    "${REENTER}" "${MONITORCHECK}" "${HOOKLANE}" "${DOUBLEACQ}" "${ABNORMAL}" "${SURVIVOR}" \
    "${STRICTLANE}"

# ---------------------------------------------------------------------------
# C01: lock path の導出
# ---------------------------------------------------------------------------
case_c01() {
    local id="C01" got expected warn
    expected="/tmp/global-test-lane-$(id -u).d"
    got="$(
        # shellcheck source=/dev/null
        . "${LIB}"
        _gtl_lock_dir 2>/dev/null
    )"
    if [ "${got}" = "${expected}" ]; then
        t_ok "${id}" "既定の lock dir が ${expected}"
    else
        t_fail "${id}" "既定の lock dir が想定と違う (got=${got} want=${expected})"
    fi

    warn="$(
        # shellcheck source=/dev/null
        . "${LIB}"
        export GLOBAL_TEST_LOCK_DIR="${WORK}/c01-override"
        _gtl_lock_dir 2>&1 >/dev/null
    )"
    case "${warn}" in
        *"using override lock dir"*) t_ok "${id}" "GLOBAL_TEST_LOCK_DIR 上書き時に警告が出る" ;;
        *) t_fail "${id}" "上書き時の警告が出ない (stderr=${warn})" ;;
    esac
}

# ---------------------------------------------------------------------------
# C02: lock dir の fail-secure (symlink / 緩い mode)
# ---------------------------------------------------------------------------
case_c02() {
    local id="C02" err
    if [ "${HAVE_FLOCK}" -eq 0 ]; then
        t_skip "${id}" "flock(1) が無いので lock dir 検証まで到達しない"
        return
    fi

    mkdir -p -m 700 "${WORK}/c02-real"
    ln -sfn "${WORK}/c02-real" "${WORK}/c02-link"
    err="${WORK}/c02-link.err"
    run_lane_fg "${WORK}/c02-link" "${err}" true
    if [ "${LANE_RC}" -ne 0 ] && grep -q "symlink" "${err}"; then
        t_ok "${id}" "symlink の lock dir で明示エラー停止 (rc=${LANE_RC})"
    else
        t_fail "${id}" "symlink の lock dir を拒否しない (rc=${LANE_RC})"
    fi

    mkdir -p -m 755 "${WORK}/c02-mode"
    chmod 755 "${WORK}/c02-mode"
    err="${WORK}/c02-mode.err"
    run_lane_fg "${WORK}/c02-mode" "${err}" true
    if [ "${LANE_RC}" -ne 0 ] && grep -q "mode must be 700" "${err}"; then
        t_ok "${id}" "mode 755 の lock dir で明示エラー停止 (rc=${LANE_RC})"
    else
        t_fail "${id}" "緩い mode の lock dir を拒否しない (rc=${LANE_RC})"
    fi
}

# ---------------------------------------------------------------------------
# C03 / C04: ブロッキング取得 と heartbeat
# ---------------------------------------------------------------------------
case_c03_c04() {
    local id3="C03" id4="C04" d a_pid b_pid start elapsed hb
    if [ "${HAVE_FLOCK}" -eq 0 ]; then
        t_skip "${id3}" "flock(1) 不在 (排他しないため待機が起きない)"
        t_skip "${id4}" "flock(1) 不在"
        return
    fi

    d="$(new_dir)"
    start_lane "${d}" "${WORK}/c03-a.err" bash "${SLEEPER}" 5
    a_pid="${LANE_PID}"
    if ! poll_until 10 lock_is_held "${d}"; then
        t_fail "${id3}" "1 本目がロックを取得できない"
        t_skip "${id4}" "前提 (1 本目の取得) が崩れた"
        kill -KILL "${a_pid}" 2>/dev/null || true
        return
    fi

    start="$(date +%s)"
    start_lane "${d}" "${WORK}/c03-b.err" true
    b_pid="${LANE_PID}"
    wait "${b_pid}" 2>/dev/null
    elapsed=$(($(date +%s) - start))
    wait "${a_pid}" 2>/dev/null

    if [ "${elapsed}" -ge 3 ]; then
        t_ok "${id3}" "2 本目は即エラーせず待機して実行された (${elapsed}s)"
    else
        t_fail "${id3}" "2 本目が待機していない (${elapsed}s。旧 flock -n の回帰)"
    fi

    hb="$(grep 'waiting' "${WORK}/c03-b.err" 2>/dev/null | head -n 1)"
    if [ -n "${hb}" ] &&
        printf '%s' "${hb}" | grep -q 'pid=' &&
        printf '%s' "${hb}" | grep -q 'lane=' &&
        printf '%s' "${hb}" | grep -q 'worktree='; then
        t_ok "${id4}" "待機中の heartbeat に保持者の身元が出る"
    else
        t_fail "${id4}" "heartbeat が出ない / 身元を含まない (line=${hb})"
    fi
}

# ---------------------------------------------------------------------------
# C05: 非競合時は heartbeat が 1 行も出ない (CI ログを汚さない)
# ---------------------------------------------------------------------------
case_c05() {
    local id="C05" d err
    d="$(new_dir)"
    err="${WORK}/c05.err"
    run_lane_fg "${d}" "${err}" true
    if [ "${LANE_RC}" -eq 0 ] && ! grep -q 'waiting' "${err}"; then
        t_ok "${id}" "無競合では heartbeat が 1 行も出ない"
    else
        t_fail "${id}" "無競合なのに heartbeat が出た (rc=${LANE_RC})"
    fi
}

# ---------------------------------------------------------------------------
# C06: fd 7 の非継承 (レーン本体 / heartbeat 子の双方)
# ---------------------------------------------------------------------------
case_c06() {
    local id="C06" d out a_pid b_pid kids kid leaked checked
    if [ "${HAVE_PROC}" -eq 0 ]; then
        t_skip "${id}" "/proc が無いので fd 継承を観測できない"
        return
    fi

    d="$(new_dir)"
    run_lane_fg "${d}" "${WORK}/c06.err" bash "${FDCHECK}"
    out="$(cat "${WORK}/c06.err.out" 2>/dev/null)"
    if [ "${out}" = "fd7=ok" ]; then
        t_ok "${id}" "レーン本体に fd 7 が継承されない"
    else
        t_fail "${id}" "レーン本体が fd 7 を継承している (out=${out})"
    fi

    if [ "${HAVE_FLOCK}" -eq 0 ] || [ "${HAVE_PS}" -eq 0 ]; then
        t_skip "${id}" "heartbeat 子の fd 検査 (flock / ps 不在)"
        return
    fi

    start_lane "${d}" "${WORK}/c06-a.err" bash "${SLEEPER}" 4
    a_pid="${LANE_PID}"
    if ! poll_until 10 lock_is_held "${d}"; then
        t_fail "${id}" "heartbeat 検査の前提 (1 本目の取得) が崩れた"
        kill -KILL "${a_pid}" 2>/dev/null || true
        return
    fi
    start_lane "${d}" "${WORK}/c06-b.err" true
    b_pid="${LANE_PID}"

    leaked=0
    checked=0
    # 待機中の 2 本目には heartbeat 子がいる。そこに fd 7 が渡っていないことを見る。
    #
    # 併走する `flock 7` の子プロセスは **fd 7 を正当に保持する** (それがブロッキング取得の
    # 実体) ので対象外にする。heartbeat は shell 関数の background 実行なので、
    # argv はラッパのものをそのまま引き継いでいる。それを目印に選別する。
    if poll_until 5 has_children "${b_pid}"; then
        kids="$(pgrep -P "${b_pid}" 2>/dev/null || true)"
        for kid in ${kids}; do
            case "$(ps -o args= -p "${kid}" 2>/dev/null || true)" in
                *with-global-test-lock.sh*) : ;;
                *) continue ;;
            esac
            checked=$((checked + 1))
            [ -e "/proc/${kid}/fd/7" ] && leaked=$((leaked + 1))
        done
    fi

    wait "${b_pid}" 2>/dev/null
    wait "${a_pid}" 2>/dev/null

    if [ "${checked}" -eq 0 ]; then
        t_skip "${id}" "heartbeat 子を観測できなかった (タイミング)"
    elif [ "${leaked}" -eq 0 ]; then
        t_ok "${id}" "heartbeat 子にも fd 7 が渡らない (checked=${checked})"
    else
        t_fail "${id}" "heartbeat 子が fd 7 を保持している (leaked=${leaked})"
    fi
}

# ---------------------------------------------------------------------------
# C07: 保持期間 = コマンド実行中 (exec 回帰の負のコントロール)
# ---------------------------------------------------------------------------
case_c07() {
    local id="C07" d a_pid
    if [ "${HAVE_FLOCK}" -eq 0 ]; then
        t_skip "${id}" "flock(1) 不在"
        return
    fi
    d="$(new_dir)"
    start_lane "${d}" "${WORK}/c07.err" bash "${SLEEPER}" 3
    a_pid="${LANE_PID}"
    if poll_until 10 lock_is_held "${d}"; then
        t_ok "${id}" "コマンド実行中はロックが保持されている"
    else
        t_fail "${id}" "実行中にロックが保持されていない (exec 回帰)"
    fi
    wait "${a_pid}" 2>/dev/null
    if poll_until 10 lock_is_free "${d}"; then
        t_ok "${id}" "レーン終了後にロックが解放される"
    else
        t_fail "${id}" "レーン終了後もロックが解放されない"
    fi
}

# ---------------------------------------------------------------------------
# C08: 孫の刈り取り (直接子が先に終了しても孫が消えるまで離さない)
# ---------------------------------------------------------------------------
case_c08() {
    local id="C08" d gpidf gpid a_pid start elapsed
    if [ "${HAVE_FLOCK}" -eq 0 ] || [ "${HAVE_PS}" -eq 0 ]; then
        t_skip "${id}" "flock / ps 不在"
        return
    fi
    d="$(new_dir)"
    gpidf="${WORK}/c08.gpid"
    rm -f "${gpidf}"
    start="$(date +%s)"
    start_lane "${d}" "${WORK}/c08.err" bash "${SPAWNER}" "${SLEEPER}" 30 "${gpidf}"
    a_pid="${LANE_PID}"

    if ! poll_until 10 file_exists "${gpidf}"; then
        t_fail "${id}" "孫が起動しなかった"
        kill -KILL "${a_pid}" 2>/dev/null || true
        return
    fi
    gpid="$(cat "${gpidf}")"

    # 直接子は即終了する。それでも孫が生きている間はロックが保持されていなければならない。
    sleep 1
    if lock_is_held "${d}" && proc_alive "${gpid}"; then
        t_ok "${id}" "直接子の終了後も孫が居る間はロックを保持する"
    else
        t_fail "${id}" "直接子の終了時点でロックが解放された (孫が孤児化する)"
    fi

    wait "${a_pid}" 2>/dev/null
    elapsed=$(($(date +%s) - start))
    if poll_until 10 lock_is_free "${d}" && poll_until 10 proc_gone "${gpid}"; then
        t_ok "${id}" "猶予超過で孫を刈り取ってから解放した (${elapsed}s)"
    else
        t_fail "${id}" "孫が残ったまま / ロックが解放されない (${elapsed}s)"
    fi
    kill -KILL "${gpid}" 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# C09: シグナル収束 (INT/TERM を無視する子と孫でも deadlock しない)
# ---------------------------------------------------------------------------
case_c09() {
    local id="C09" d gpidf gpid a_pid start elapsed
    if [ "${HAVE_FLOCK}" -eq 0 ] || [ "${HAVE_PS}" -eq 0 ]; then
        t_skip "${id}" "flock / ps 不在"
        return
    fi
    d="$(new_dir)"
    gpidf="${WORK}/c09.gpid"
    rm -f "${gpidf}"
    start_lane "${d}" "${WORK}/c09.err" bash "${IGNORER}" "${SLEEPER}" 120 "${gpidf}"
    a_pid="${LANE_PID}"

    if ! poll_until 10 file_exists "${gpidf}" || ! poll_until 10 lock_is_held "${d}"; then
        t_fail "${id}" "前提 (無視する子孫の起動 + ロック取得) が崩れた"
        kill -KILL "${a_pid}" 2>/dev/null || true
        return
    fi
    gpid="$(cat "${gpidf}")"

    start="$(date +%s)"
    kill -TERM "${a_pid}" 2>/dev/null || true
    wait "${a_pid}" 2>/dev/null
    elapsed=$(($(date +%s) - start))

    if poll_until 20 lock_is_free "${d}"; then
        t_ok "${id}" "TERM 無視の子孫でも猶予超過で強制終了して解放した (${elapsed}s)"
    else
        t_fail "${id}" "ロックが解放されない (deadlock。wait を先に置いた回帰)"
    fi
    if poll_until 10 proc_gone "${gpid}"; then
        t_ok "${id}" "TERM 無視の孫も刈り取られた"
    else
        t_fail "${id}" "TERM 無視の孫が残存している"
    fi
    kill -KILL "${gpid}" 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# C10: 終了コード契約
# ---------------------------------------------------------------------------
case_c10() {
    local id="C10" d a_pid rc
    d="$(new_dir)"
    run_lane_fg "${d}" "${WORK}/c10-0.err" true
    if [ "${LANE_RC}" -eq 0 ]; then
        t_ok "${id}" "成功時の終了コードが 0"
    else
        t_fail "${id}" "成功時の終了コードが 0 でない (${LANE_RC})"
    fi

    run_lane_fg "${d}" "${WORK}/c10-3.err" bash -c 'exit 3'
    if [ "${LANE_RC}" -eq 3 ]; then
        t_ok "${id}" "非 0 の終了コードが素通しされる (3)"
    else
        t_fail "${id}" "非 0 の終了コードが素通しされない (${LANE_RC})"
    fi

    if [ "${HAVE_FLOCK}" -eq 0 ]; then
        t_skip "${id}" "シグナル時の 128+signo (flock 不在)"
        return
    fi

    start_lane "${d}" "${WORK}/c10-int.err" bash "${SLEEPER}" 60
    a_pid="${LANE_PID}"
    poll_until 10 lock_is_held "${d}"
    kill -INT "${a_pid}" 2>/dev/null || true
    rc=0
    wait "${a_pid}" 2>/dev/null || rc=$?
    if [ "${rc}" -eq 130 ]; then
        t_ok "${id}" "INT で 128+2 = 130 を返す"
    else
        t_fail "${id}" "INT の終了コードが 130 でない (${rc})"
    fi

    start_lane "${d}" "${WORK}/c10-term.err" bash "${SLEEPER}" 60
    a_pid="${LANE_PID}"
    poll_until 10 lock_is_held "${d}"
    kill -TERM "${a_pid}" 2>/dev/null || true
    rc=0
    wait "${a_pid}" 2>/dev/null || rc=$?
    if [ "${rc}" -eq 143 ]; then
        t_ok "${id}" "TERM で 128+15 = 143 を返す"
    else
        t_fail "${id}" "TERM の終了コードが 143 でない (${rc})"
    fi
}

# ---------------------------------------------------------------------------
# C12: プロセスグループ (PGID == PID / 子孫が自発的に離脱しない)
# ---------------------------------------------------------------------------
case_c12_probe() {
    # $1 = ラベル, 以降 = ロック配下で走らせるコマンド
    local id="C12" label="$1" d child pgid desc p bad=0
    shift
    d="$(new_dir)"
    start_lane "${d}" "${WORK}/c12-$$.err" "$@"
    local lane="${LANE_PID}"
    if ! poll_until 10 has_children "${lane}"; then
        t_skip "${id}" "${label}: 直接子を観測できなかった"
        kill -KILL "${lane}" 2>/dev/null || true
        wait "${lane}" 2>/dev/null
        return
    fi
    child="$(pgrep -P "${lane}" 2>/dev/null | head -n 1)"
    pgid="$(pgid_of "${child}")"

    if [ "${pgid}" = "${child}" ]; then
        t_ok "${id}" "${label}: 直接子が専用プロセスグループのリーダー (PGID==PID)"
    else
        t_fail "${id}" "${label}: PGID != PID (pid=${child} pgid=${pgid})"
    fi

    # 子孫が生えるまで待つ (即座に見ると空振りして vacuous な PASS になる)
    poll_until 3 has_children "${child}" || true
    desc="$(descendants_of "${child}")"
    for p in ${desc}; do
        [ "$(pgid_of "${p}")" = "${child}" ] || bad=$((bad + 1))
    done
    if [ "${bad}" -eq 0 ]; then
        t_ok "${id}" "${label}: 子孫が専用グループから離脱していない (n=$(printf '%s' "${desc}" | wc -w))"
    else
        t_fail "${id}" "${label}: ${bad} 個の子孫がグループを離脱した"
    fi

    kill -KILL -"${child}" 2>/dev/null || true
    kill -KILL "${lane}" 2>/dev/null || true
    wait "${lane}" 2>/dev/null
    poll_until 10 lock_is_free "${d}" || true
}

case_c12() {
    local id="C12"
    if [ "${HAVE_PS}" -eq 0 ]; then
        t_skip "${id}" "ps / pgrep 不在"
        return
    fi
    case_c12_probe "shell" bash "${PGIDCHECK}"
    case_c12_probe "grandchild" bash "${IGNORER}" "${SLEEPER}" 20 "${WORK}/c12.gpid"

    # 現行レーンを構成する実バイナリでも離脱しないことを best-effort で確認する。
    if have php; then
        case_c12_probe "php" php -r 'sleep(5);'
    else
        t_skip "${id}" "php 不在 (Feature / Browser lane 相当の確認)"
    fi
    if have node; then
        case_c12_probe "node" node -e 'setTimeout(function(){}, 5000)'
    else
        t_skip "${id}" "node 不在 (JS lane 相当の確認)"
    fi
    if have pnpm; then
        case_c12_probe "pnpm" pnpm exec node -e 'setTimeout(function(){}, 5000)'
    else
        t_skip "${id}" "pnpm 不在 (JS lane 相当の確認)"
    fi
}

# ---------------------------------------------------------------------------
# C13: 再入 (nonce 一致) — deadlock せず素通りし、外側 sidecar が維持される
# ---------------------------------------------------------------------------
case_c13() {
    local id="C13" d resf a_pid rc before after
    if [ "${HAVE_FLOCK}" -eq 0 ]; then
        t_skip "${id}" "flock(1) 不在 (再入判定に到達しない)"
        return
    fi
    d="$(new_dir)"
    resf="${WORK}/c13.result"
    rm -f "${resf}"
    start_lane "${d}" "${WORK}/c13.err" bash "${REENTER}" "${WRAP}" "${d}" "${resf}"
    a_pid="${LANE_PID}"

    if ! poll_until 20 file_exists "${resf}"; then
        t_fail "${id}" "再入で deadlock した (20s 以内に完了しない)"
        kill -KILL "${a_pid}" 2>/dev/null || true
        wait "${a_pid}" 2>/dev/null
        return
    fi
    wait "${a_pid}" 2>/dev/null

    rc="$(grep '^rc=' "${resf}" | cut -d= -f2-)"
    before="$(grep '^before=' "${resf}" | cut -d= -f2-)"
    after="$(grep '^after=' "${resf}" | cut -d= -f2-)"

    if [ "${rc}" = "0" ]; then
        t_ok "${id}" "再入した子が deadlock せず正常終了する"
    else
        t_fail "${id}" "再入した子の終了コードが 0 でない (${rc})"
    fi
    if [ -n "${before}" ] && [ "${before}" != "MISSING" ] && [ "${before}" = "${after}" ]; then
        t_ok "${id}" "再入子の終了後も外側 owner の sidecar が維持される"
    else
        t_fail "${id}" "再入子が外側 sidecar を壊した (before=${before} after=${after})"
    fi
}

# ---------------------------------------------------------------------------
# C14: 再入の否定 (stale nonce) と残留 sidecar の上書き
# ---------------------------------------------------------------------------
case_c14() {
    local id="C14" d a_pid a_child nonce_old nonce_new b_pid stale_pid still
    if [ "${HAVE_FLOCK}" -eq 0 ] || [ "${HAVE_PS}" -eq 0 ]; then
        t_skip "${id}" "flock / ps 不在"
        return
    fi
    d="$(new_dir)"
    start_lane "${d}" "${WORK}/c14-a.err" bash "${SLEEPER}" 60
    a_pid="${LANE_PID}"
    if ! poll_until 10 lock_is_held "${d}"; then
        t_fail "${id}" "前提 (owner の取得) が崩れた"
        kill -KILL "${a_pid}" 2>/dev/null || true
        return
    fi
    nonce_old="$(head -n 1 "${d}/owner" 2>/dev/null)"
    a_child="$(pgrep -P "${a_pid}" 2>/dev/null | head -n 1)"

    # SIGKILL: trap は走らない = sidecar が残留し、fd は OS が閉じてロックは解放される。
    kill -KILL "${a_pid}" 2>/dev/null || true
    wait "${a_pid}" 2>/dev/null
    [ -n "${a_child}" ] && kill -KILL "${a_child}" 2>/dev/null

    if [ -f "${d}/owner" ] && poll_until 10 lock_is_free "${d}"; then
        t_ok "${id}" "残留 sidecar は次の取得者をブロックしない"
    else
        t_fail "${id}" "SIGKILL 後にロックが解放されない / sidecar が残っていない"
    fi

    start_lane "${d}" "${WORK}/c14-b.err" bash "${SLEEPER}" 8
    b_pid="${LANE_PID}"
    if ! poll_until 10 lock_is_held "${d}"; then
        t_fail "${id}" "次の owner がロックを取得できない"
        kill -KILL "${b_pid}" 2>/dev/null || true
        return
    fi
    nonce_new="$(head -n 1 "${d}/owner" 2>/dev/null)"
    if [ -n "${nonce_new}" ] && [ "${nonce_new}" != "${nonce_old}" ]; then
        t_ok "${id}" "残留 sidecar がアトミックに上書きされた"
    else
        t_fail "${id}" "sidecar が更新されていない (old=${nonce_old} new=${nonce_new})"
    fi

    # stale nonce を持つ「生き残った子孫」は再入できず、ブロッキング取得に回らねばならない。
    GLOBAL_TEST_LOCK_DIR="${d}" GLOBAL_TEST_LOCK_NONCE="${nonce_old}" \
        bash "${WRAP}" true >"${WORK}/c14-stale.out" 2>"${WORK}/c14-stale.err" &
    stale_pid=$!
    sleep 2
    still=0
    proc_alive "${stale_pid}" && still=1
    if [ "${still}" -eq 1 ]; then
        t_ok "${id}" "stale nonce の子孫は再入できずブロッキング待機する"
    else
        t_fail "${id}" "stale nonce で誤再入した (素通りして即終了)"
    fi
    wait "${b_pid}" 2>/dev/null
    wait "${stale_pid}" 2>/dev/null
}

# ---------------------------------------------------------------------------
# C15: flock(1) 不在環境 (警告つきで排他なし実行。終了コードは保つ)
# ---------------------------------------------------------------------------
case_c15() {
    local id="C15" d nofl c rc
    d="$(new_dir)"
    nofl="${WORK}/nofl-bin"
    mkdir -p "${nofl}"
    for c in bash sh id dirname stat date mv rm sleep ps head cat tr awk grep; do
        if have "${c}"; then ln -sf "$(command -v "${c}")" "${nofl}/${c}"; fi
    done
    if [ -e "${nofl}/flock" ]; then
        t_fail "${id}" "検証用 PATH に flock が混入している"
        return
    fi

    rc=0
    PATH="${nofl}" GLOBAL_TEST_LOCK_DIR="${d}" bash "${WRAP}" bash -c 'exit 7' \
        >"${WORK}/c15.out" 2>"${WORK}/c15.err" || rc=$?

    if [ "${rc}" -eq 7 ]; then
        t_ok "${id}" "flock 不在でも終了コードを保つ (7)"
    else
        t_fail "${id}" "flock 不在時の終了コードが壊れた (${rc})"
    fi
    if grep -q 'flock' "${WORK}/c15.err"; then
        t_ok "${id}" "flock 不在を stderr に 1 行警告する"
    else
        t_fail "${id}" "flock 不在が無言で skip されている"
    fi
    if [ ! -d "${d}" ]; then
        t_ok "${id}" "flock 不在では lock dir を作らない"
    else
        t_fail "${id}" "flock 不在なのに lock dir を作った"
    fi
}

# ---------------------------------------------------------------------------
# C16: TTY あり / なし (monitor mode の復元を含む)
# ---------------------------------------------------------------------------
case_c16() {
    local id="C16" d out rc start elapsed a_pid
    d="$(new_dir)"
    out="$(GLOBAL_TEST_LOCK_DIR="${d}" bash "${MONITORCHECK}" "${LIB}" "${d}" 2>/dev/null)"
    if [ "${out}" = "monitor_before=off monitor_after=off" ]; then
        t_ok "${id}" "TTY 無しで monitor mode が復元される"
    else
        t_fail "${id}" "TTY 無しで monitor mode が復元されない (${out})"
    fi

    if ! have script; then
        t_skip "${id}" "script(1) 不在のため TTY ありの検証を省略"
        return
    fi
    if ! script -q -e -c true /dev/null >/dev/null 2>&1; then
        t_skip "${id}" "pty を確保できないため TTY ありの検証を省略"
        return
    fi

    d="$(new_dir)"
    out="$(script -q -e -c "GLOBAL_TEST_LOCK_DIR='${d}' bash '${MONITORCHECK}' '${LIB}' '${d}'" /dev/null 2>/dev/null | tr -d '\r')"
    case "${out}" in
        *"monitor_before=off monitor_after=off"*) t_ok "${id}" "TTY ありでも monitor mode が復元される" ;;
        *) t_fail "${id}" "TTY ありで monitor mode が復元されない (${out})" ;;
    esac

    d="$(new_dir)"
    rc=0
    script -q -e -c "GLOBAL_TEST_LOCK_DIR='${d}' bash '${WRAP}' bash -c 'exit 3'" /dev/null >/dev/null 2>&1 || rc=$?
    if [ "${rc}" -eq 3 ]; then
        t_ok "${id}" "TTY ありでも終了コードが素通しされる (3)"
    else
        t_fail "${id}" "TTY ありで終了コードが壊れた (${rc})"
    fi

    if [ "${HAVE_FLOCK}" -eq 0 ]; then
        t_skip "${id}" "TTY ありのブロッキング検証 (flock 不在)"
        return
    fi
    d="$(new_dir)"
    start_lane "${d}" "${WORK}/c16-a.err" bash "${SLEEPER}" 4
    a_pid="${LANE_PID}"
    if poll_until 10 lock_is_held "${d}"; then
        start="$(date +%s)"
        script -q -e -c "GLOBAL_TEST_LOCK_DIR='${d}' bash '${WRAP}' true" /dev/null >/dev/null 2>&1
        elapsed=$(($(date +%s) - start))
        if [ "${elapsed}" -ge 2 ]; then
            t_ok "${id}" "TTY ありでもブロッキング待機する (${elapsed}s)"
        else
            t_fail "${id}" "TTY ありで待機していない (${elapsed}s)"
        fi
    else
        t_fail "${id}" "TTY ありのブロッキング検証の前提が崩れた"
    fi
    wait "${a_pid}" 2>/dev/null
}

# ---------------------------------------------------------------------------
# C17: lane の EXIT フックが「ロック保持中に」走る (trap 上書き回帰)
# ---------------------------------------------------------------------------
case_c17() {
    local id="C17" d resf rc
    if [ "${HAVE_FLOCK}" -eq 0 ]; then
        t_skip "${id}" "flock(1) 不在"
        return
    fi
    d="$(new_dir)"
    resf="${WORK}/c17.result"
    rm -f "${resf}"
    rc=0
    GLOBAL_TEST_LOCK_DIR="${d}" bash "${HOOKLANE}" "${LIB}" "${d}" "${resf}" \
        >"${WORK}/c17.out" 2>"${WORK}/c17.err" || rc=$?

    if grep -q 'hook_lock=held' "${resf}" 2>/dev/null; then
        t_ok "${id}" "EXIT フックがロック保持中に実行された"
    else
        t_fail "${id}" "EXIT フックがロック保持中に走っていない ($(cat "${resf}" 2>/dev/null))"
    fi
    if [ "${rc}" -eq 0 ] && poll_until 10 lock_is_free "${d}"; then
        t_ok "${id}" "EXIT フック併用でもロックが解放される (trap 上書きなし)"
    else
        t_fail "${id}" "EXIT フック併用でロックが解放されない (rc=${rc})"
    fi
}

# ---------------------------------------------------------------------------
# C18: playwright 掃除の選別 (@playwright/cli を巻き込まない)
# ---------------------------------------------------------------------------
case_c18() {
    local id="C18" fn plain_js scoped_js plain_pid scoped_pid killed
    if [ "${HAVE_PS}" -eq 0 ]; then
        t_skip "${id}" "ps / pgrep 不在"
        return
    fi

    # 実装からそのまま関数を切り出して評価する (スイート内で複製するとドリフトするため)。
    fn="$(awk '/^cleanup_orphan_playwright\(\) \{/{f=1} f{print; if ($0 == "}") exit}' "${BROWSER_LANE}")"
    if [ -z "${fn}" ]; then
        t_fail "${id}" "run-browser-test.sh から cleanup_orphan_playwright を抽出できない"
        return
    fi

    plain_js="${WORK}/node_modules/playwright/cli.js"
    scoped_js="${WORK}/node_modules/@playwright/playwright/cli.js"
    mkdir -p "$(dirname "${plain_js}")" "$(dirname "${scoped_js}")"
    printf '#!/usr/bin/env bash\nsleep 120\n' >"${plain_js}"
    printf '#!/usr/bin/env bash\nsleep 120\n' >"${scoped_js}"

    # PPID=1 (orphan) にするため二重 fork する。
    (bash "${plain_js}" run-server >/dev/null 2>&1 &) &
    (bash "${scoped_js}" run-server >/dev/null 2>&1 &) &
    wait 2>/dev/null

    if ! poll_until 5 pattern_running "${plain_js} run-server" ||
        ! poll_until 5 pattern_running "${scoped_js} run-server"; then
        t_skip "${id}" "偽 playwright プロセスを起動できなかった"
        return
    fi
    plain_pid="$(pgrep -f "${plain_js} run-server" 2>/dev/null | head -n 1)"
    scoped_pid="$(pgrep -f "${scoped_js} run-server" 2>/dev/null | head -n 1)"

    if ! poll_until 5 is_orphan "${plain_pid}" ||
        ! poll_until 5 is_orphan "${scoped_pid}"; then
        t_skip "${id}" "偽プロセスを orphan (PPID=1) にできなかった (subreaper 環境)"
        kill -KILL "${plain_pid}" "${scoped_pid}" 2>/dev/null || true
        return
    fi

    killed="${WORK}/c18.killed"
    : >"${killed}"
    (
        # 実プロセスを殺さずに「選別結果」だけを観測する。
        kill() { printf '%s\n' "$1" >>"${killed}"; }
        eval "${fn}"
        cleanup_orphan_playwright
    )

    if grep -qx "${plain_pid}" "${killed}"; then
        t_ok "${id}" "node_modules/playwright/cli.js run-server は掃除対象になる (正のコントロール)"
    else
        t_fail "${id}" "本来の orphan run-server を掃除しない (掃除が効かなくなった)"
    fi
    if grep -qx "${scoped_pid}" "${killed}"; then
        t_fail "${id}" "@playwright/ のプロセスを掃除対象にしている (bug-hunt を巻き込む)"
    else
        t_ok "${id}" "@playwright/ のプロセスは掃除対象にならない (負のコントロール)"
    fi

    kill -KILL "${plain_pid}" "${scoped_pid}" 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# C19: bug-hunt pre-flight guard は **ロック取得前** に fail-fast する
# ---------------------------------------------------------------------------
case_c19() {
    local id="C19" d port listener="" lpid="" a_pid start elapsed rc bpid
    if ! have python3; then
        t_skip "${id}" "python3 不在 (bughunt ポートを listen できない)"
        return
    fi
    listener="${WORK}/listen.py"
    cat >"${listener}" <<'PY'
import socket
import sys
import time

s = socket.socket()
s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
s.bind(("127.0.0.1", int(sys.argv[1])))
s.listen(8)
sys.stdout.write("listening\n")
sys.stdout.flush()
time.sleep(120)
PY

    # 候補ポート列挙は「bind できるポートを 1 つ探す」ための fixture であり、bug-hunt の
    # 並列 cap (=4) とは無関係。cap と同期させない (狭めると bind 候補が減るだけで、意味が無い)。
    for port in 8010 8011 8012 8013 8014 8015 8016 8017 8018; do
        python3 "${listener}" "${port}" >"${WORK}/c19.listen" 2>/dev/null &
        lpid=$!
        if poll_until 3 grep -q listening "${WORK}/c19.listen"; then
            break
        fi
        kill -KILL "${lpid}" 2>/dev/null || true
        lpid=""
    done
    if [ -z "${lpid}" ]; then
        t_skip "${id}" "8010..8018 のいずれも bind できなかった"
        return
    fi

    d="$(new_dir)"
    a_pid=""
    if [ "${HAVE_FLOCK}" -eq 1 ]; then
        # 先行レーンにロックを握らせる。guard が acquire より後ろにあると
        # ここで数十秒待たされる = 「待ち時間の無駄」の回帰として検出できる。
        start_lane "${d}" "${WORK}/c19-a.err" bash "${SLEEPER}" 30
        a_pid="${LANE_PID}"
        poll_until 10 lock_is_held "${d}"
    fi

    start="$(date +%s)"
    GLOBAL_TEST_LOCK_DIR="${d}" bash "${BROWSER_LANE}" >"${WORK}/c19.out" 2>"${WORK}/c19.err" &
    bpid=$!
    rc=0
    if poll_until 10 proc_gone "${bpid}"; then
        wait "${bpid}" 2>/dev/null || rc=$?
    else
        kill -KILL "${bpid}" 2>/dev/null || true
        wait "${bpid}" 2>/dev/null
        rc=-1
    fi
    elapsed=$(($(date +%s) - start))

    if [ "${rc}" -gt 0 ] && grep -q 'bug-hunt' "${WORK}/c19.err"; then
        t_ok "${id}" "bughunt ポート listen 中は Browser lane が fail-fast する (rc=${rc}, ${elapsed}s)"
    else
        t_fail "${id}" "bughunt guard が働かない (rc=${rc}, ${elapsed}s)"
    fi
    if [ "${elapsed}" -lt 10 ]; then
        t_ok "${id}" "guard はロック取得前に走る (先行レーンを待たない)"
    else
        t_fail "${id}" "guard がロック取得の後ろにある (${elapsed}s 待たされた)"
    fi

    kill -KILL "${lpid}" 2>/dev/null || true
    wait "${lpid}" 2>/dev/null
    if [ -n "${a_pid}" ]; then
        # SIGKILL だと先行レーンの子 (専用プロセスグループ) が孤児として残る。
        # TERM を送ってライブラリのシグナル契約に収束させる。
        kill -TERM "${a_pid}" 2>/dev/null || true
        wait "${a_pid}" 2>/dev/null
    fi
    return 0
}

# ---------------------------------------------------------------------------
# C20: 二重 acquire で owner が落ちない (後続の run が素通り化しない)
# ---------------------------------------------------------------------------
case_c20() {
    local id="C20" d resf lane held=0
    d="$(new_dir)"
    resf="${WORK}/c20.result"
    rm -f "${resf}"
    GLOBAL_TEST_LOCK_DIR="${d}" bash "${DOUBLEACQ}" "${LIB}" "${d}" "${resf}" "${SLEEPER}" \
        >"${WORK}/c20.out" 2>"${WORK}/c20.err" &
    lane=$!

    if [ "${HAVE_FLOCK}" -eq 1 ]; then
        poll_until 10 lock_is_held "${d}" && held=1
    else
        held=1
    fi
    wait "${lane}" 2>/dev/null

    if grep -q '^mode=owner$' "${resf}" 2>/dev/null; then
        t_ok "${id}" "二重 acquire 後も owner のまま"
    else
        t_fail "${id}" "二重 acquire で owner から落ちた ($(grep '^mode=' "${resf}" 2>/dev/null))"
    fi
    if grep -q '^fd7=ok$' "${resf}" 2>/dev/null; then
        t_ok "${id}" "二重 acquire 後の run でも fd 7 が継承されない"
    else
        t_fail "${id}" "二重 acquire 後の run が素通り化している (fd 7 継承)"
    fi
    if [ "${held}" -eq 1 ]; then
        t_ok "${id}" "二重 acquire 後も実行中にロックが保持される"
    else
        t_fail "${id}" "二重 acquire 後にロックが保持されていない"
    fi
}

# ---------------------------------------------------------------------------
# C21: lock / owner の型検証 (symlink 差し替えを拒否する)
# ---------------------------------------------------------------------------
case_c21() {
    local id="C21" d
    if [ "${HAVE_FLOCK}" -eq 0 ]; then
        t_skip "${id}" "flock(1) 不在 (ファイル型検証に到達しない)"
        return
    fi

    d="${WORK}/c21-lock"
    mkdir -p -m 700 "${d}"
    ln -sfn /dev/null "${d}/lock"
    run_lane_fg "${d}" "${WORK}/c21-lock.err" true
    if [ "${LANE_RC}" -ne 0 ] && grep -q 'lock file is a symlink' "${WORK}/c21-lock.err"; then
        t_ok "${id}" "lock が symlink なら明示エラー停止"
    else
        t_fail "${id}" "symlink の lock を拒否しない (rc=${LANE_RC})"
    fi

    d="${WORK}/c21-owner"
    mkdir -p -m 700 "${d}"
    ln -sfn /dev/null "${d}/owner"
    run_lane_fg "${d}" "${WORK}/c21-owner.err" true
    if [ "${LANE_RC}" -ne 0 ] && grep -q 'sidecar is a symlink' "${WORK}/c21-owner.err"; then
        t_ok "${id}" "owner (sidecar) が symlink なら明示エラー停止"
    else
        t_fail "${id}" "symlink の sidecar を拒否しない (rc=${LANE_RC})"
    fi
}

# ---------------------------------------------------------------------------
# C22: 異常終了経路でも残党を残さず収束させてから解放する
# ---------------------------------------------------------------------------
case_c22() {
    local id="C22" d gpidf cpidf gpid cpid rc
    if [ "${HAVE_FLOCK}" -eq 0 ] || [ "${HAVE_PS}" -eq 0 ]; then
        t_skip "${id}" "flock / ps 不在"
        return
    fi
    d="$(new_dir)"
    gpidf="${WORK}/c22.gpid"
    cpidf="${WORK}/c22.cpid"
    rm -f "${gpidf}" "${cpidf}"

    rc=0
    GLOBAL_TEST_LOCK_DIR="${d}" bash "${ABNORMAL}" "${LIB}" "${IGNORER}" "${SLEEPER}" "${gpidf}" "${cpidf}" \
        >"${WORK}/c22.out" 2>"${WORK}/c22.err" || rc=$?

    gpid="$(cat "${gpidf}" 2>/dev/null || echo '')"
    cpid="$(cat "${cpidf}" 2>/dev/null || echo '')"

    if [ "${rc}" -ne 0 ]; then
        t_ok "${id}" "内部エラーで非 0 終了する (rc=${rc})"
    else
        t_fail "${id}" "内部エラーなのに 0 で終了した"
    fi
    if poll_until 15 lock_is_free "${d}"; then
        t_ok "${id}" "異常終了経路でもロックが解放される"
    else
        t_fail "${id}" "異常終了経路でロックが解放されない"
    fi
    if [ -n "${gpid}" ] && [ -n "${cpid}" ] &&
        poll_until 10 proc_gone "${gpid}" && poll_until 10 proc_gone "${cpid}"; then
        t_ok "${id}" "異常終了経路でも残党 (子・孫) を残さない"
    else
        t_fail "${id}" "異常終了経路で残党が残った (child=${cpid} grandchild=${gpid})"
    fi
    [ -n "${gpid}" ] && kill -KILL "${gpid}" 2>/dev/null
    [ -n "${cpid}" ] && kill -KILL "${cpid}" 2>/dev/null
    return 0
}

# ---------------------------------------------------------------------------
# C23: SIGKILL 生存者がいる間はロックを離さない (諦めて解放しない)
# ---------------------------------------------------------------------------
case_c23() {
    local id="C23" d lane
    if [ "${HAVE_FLOCK}" -eq 0 ]; then
        t_skip "${id}" "flock(1) 不在"
        return
    fi
    d="$(new_dir)"
    GLOBAL_TEST_LOCK_DIR="${d}" bash "${SURVIVOR}" "${LIB}" "${SLEEPER}" \
        >"${WORK}/c23.out" 2>"${WORK}/c23.err" &
    lane=$!

    if ! poll_until 10 lock_is_held "${d}"; then
        t_fail "${id}" "前提 (ロック取得) が崩れた"
        kill -KILL "${lane}" 2>/dev/null || true
        wait "${lane}" 2>/dev/null
        return
    fi

    sleep 6
    if lock_is_held "${d}" && proc_alive "${lane}"; then
        t_ok "${id}" "SIGKILL 生存者がいる間はロックを解放しない"
    else
        t_fail "${id}" "生存者が居るのにロックを解放した (諦めて解放する回帰)"
    fi
    if grep -q 'still holding the lock' "${WORK}/c23.err"; then
        t_ok "${id}" "残存 pid つきの警告を出し続ける (ハングと区別できる)"
    else
        t_fail "${id}" "残存を知らせる警告が出ない"
    fi
    if grep -q 'SHOULD_NOT_REACH' "${WORK}/c23.out"; then
        t_fail "${id}" "解放して先へ進んでしまった"
    else
        t_ok "${id}" "解放を諦めて先へ進まない"
    fi

    kill -KILL "${lane}" 2>/dev/null || true
    wait "${lane}" 2>/dev/null
    poll_until 10 lock_is_free "${d}" || true
}

# ---------------------------------------------------------------------------
# C24: 検証用 env の値検証 (壊れた設定で保護が半分だけ効く状態を作らない)
# ---------------------------------------------------------------------------
case_c24_one() {
    # $1 = ラベル, $2 = env 名, $3 = 値, $4 = 期待する stderr の断片
    local id="C24" d rc=0
    d="$(new_dir)"
    env GLOBAL_TEST_LOCK_DIR="${d}" "$2=$3" bash "${WRAP}" true \
        >"${WORK}/c24.out" 2>"${WORK}/c24.err" || rc=$?
    if [ "${rc}" -ne 0 ] && grep -q "$4" "${WORK}/c24.err"; then
        t_ok "${id}" "$1 で取得時に fail-fast する"
    else
        t_fail "${id}" "$1 を素通しした (rc=${rc})"
    fi
}

case_c24() {
    case_c24_one "heartbeat=0" GLOBAL_TEST_LOCK_HEARTBEAT_SECS 0 'HEARTBEAT_SECS must be >= 1'
    case_c24_one "heartbeat=-1" GLOBAL_TEST_LOCK_HEARTBEAT_SECS -1 'HEARTBEAT_SECS must be a positive integer'
    case_c24_one "heartbeat=abc" GLOBAL_TEST_LOCK_HEARTBEAT_SECS abc 'HEARTBEAT_SECS must be a positive integer'
    case_c24_one "grace=-1" GLOBAL_TEST_LOCK_GRACE_SECS -1 'GRACE_SECS must be a non-negative integer'
    case_c24_one "grace=abc" GLOBAL_TEST_LOCK_GRACE_SECS abc 'GRACE_SECS must be a non-negative integer'

    local id="C24" rc=0
    GLOBAL_TEST_LOCK_DIR="relative/path" bash "${WRAP}" true \
        >"${WORK}/c24-rel.out" 2>"${WORK}/c24-rel.err" || rc=$?
    if [ "${rc}" -ne 0 ] && grep -q 'must be an absolute path' "${WORK}/c24-rel.err"; then
        t_ok "${id}" "GLOBAL_TEST_LOCK_DIR=相対パス で fail-fast する"
    else
        t_fail "${id}" "相対パスの GLOBAL_TEST_LOCK_DIR を素通しした (rc=${rc})"
    fi
}

# ---------------------------------------------------------------------------
# C25: sub-millisecond で終了する子でもレーンが落ちない (pgid probe の race 許容)
#
# 回帰の対象: `pgid="$(ps ...)"` が set -euo pipefail 下で **代入ごと** 失敗し、
# 直下の「空 = race として許容」判定に到達せずレーンが落ちていた
# (T104 が contract テストへ sleep 0.1 の回避策を入れる原因になった偽赤)。
#
# 1 回だけだと race が確率的に外れて偽グリーンになりうるので 20 回反復する。
# ---------------------------------------------------------------------------
case_c25() {
    local id="C25" d i=0 rc fails=0
    if [ "${HAVE_PS}" -eq 0 ]; then
        t_skip "${id}" "ps 不在 (pgid probe そのものに到達しない)"
        t_skip "${id}" "ps 不在 (best-effort probe の die 検査)"
        return
    fi
    d="$(new_dir)"
    : >"${WORK}/c25.err"
    while [ "${i}" -lt 20 ]; do
        i=$((i + 1))
        rc=0
        GLOBAL_TEST_LOCK_DIR="${d}" bash "${STRICTLANE}" "${LIB}" \
            >"${WORK}/c25.out" 2>>"${WORK}/c25.err" || rc=$?
        if [ "${rc}" -ne 0 ] || ! grep -q '^lane_ok=1$' "${WORK}/c25.out"; then
            fails=$((fails + 1))
        fi
    done

    if [ "${fails}" -eq 0 ]; then
        t_ok "${id}" "即座に終了する子でもレーンが落ちない (20/20)"
    else
        t_fail "${id}" "pgid probe の race でレーンが落ちた (${fails}/20)"
    fi
    # best-effort probe が「値が違うときだけ落とす」契約を守っているか
    # (空を不一致と誤判定して die していないこと)。
    if grep -q '専用プロセスグループを作れなかった' "${WORK}/c25.err"; then
        t_fail "${id}" "best-effort probe が空の pgid を不一致として die した"
    else
        t_ok "${id}" "best-effort probe が空の pgid で die しない"
    fi
}

# ---------------------------------------------------------------------------
# C26: ps が使えない環境ではロック取得が fail する (strict 検証が生きていることの正コントロール)
#
# `|| pgid=""` は best-effort probe の意図を成立させるだけで、厳格判定
# (_gtl_probe_process_group の 3 回リトライ → 一度も取れなければ _gtl_die) を弱めない。
#
# **偽グリーン対策**: 単に PATH を空にすると flock / tr / stat など別コマンドの不在で
# 先に落ち、「非ゼロ終了」だけを見ると通ってしまう。そこで
#   (a) 一時 PATH ディレクトリに **必要なコマンドだけ** symlink し、ps だけを置かない
#   (b) 終了コードに加えて **_gtl_probe_process_group 固有のメッセージ** が stderr に出ること
#   (c) acquire に到達した証跡 (override lock dir の警告) が出ていること
# の 3 点を満たして初めて PASS とする。
# ---------------------------------------------------------------------------
case_c26() {
    local id="C26" d fake cmd src rc=0
    if [ "${HAVE_PS}" -eq 0 ]; then
        t_skip "${id}" "ps 不在 (ps 有り環境との対比が取れないため正コントロールにならない)"
        return
    fi
    if [ "${HAVE_FLOCK}" -eq 0 ]; then
        t_skip "${id}" "flock(1) 不在 (probe に到達しない)"
        return
    fi

    d="$(new_dir)"
    fake="${WORK}/c26-bin"
    rm -rf "${fake}"
    mkdir -p "${fake}"
    # ps 以外の依存コマンドだけを通す。ここに ps を **置かない** ことが本ケースの本体。
    for cmd in bash dirname id mkdir stat flock sleep tr rm mv date awk cat head; do
        src="$(command -v "${cmd}" 2>/dev/null || true)"
        [ -n "${src}" ] && ln -sfn "${src}" "${fake}/${cmd}"
    done
    if [ ! -e "${fake}/bash" ] || [ ! -e "${fake}/flock" ] || [ ! -e "${fake}/stat" ]; then
        t_skip "${id}" "隔離 PATH に必要なコマンドを揃えられなかった"
        return
    fi

    env -i "PATH=${fake}" "HOME=${HOME:-/tmp}" \
        "GLOBAL_TEST_LOCK_DIR=${d}" \
        "GLOBAL_TEST_LOCK_HEARTBEAT_SECS=${GLOBAL_TEST_LOCK_HEARTBEAT_SECS}" \
        "GLOBAL_TEST_LOCK_GRACE_SECS=${GLOBAL_TEST_LOCK_GRACE_SECS}" \
        "${fake}/bash" "${STRICTLANE}" "${LIB}" \
        >"${WORK}/c26.out" 2>"${WORK}/c26.err" || rc=$?

    if [ "${rc}" -ne 0 ] &&
        grep -q 'job control で専用プロセスグループを作れない' "${WORK}/c26.err" &&
        grep -q 'using override lock dir' "${WORK}/c26.err"; then
        t_ok "${id}" "ps 不在ならロック取得が明示エラーで fail する (strict 検証は健在)"
    else
        t_fail "${id}" "ps 不在を素通し / 別要因で落ちた (rc=${rc}, err=$(tr '\n' ' ' <"${WORK}/c26.err" | head -c 200))"
    fi
}

# ---------------------------------------------------------------------------
# C11: 全ケース終了後に子孫プロセスが残らない (最後に実行する)
# ---------------------------------------------------------------------------
case_c11() {
    local id="C11" strays p
    if [ "${HAVE_PS}" -eq 0 ]; then
        t_skip "${id}" "pgrep 不在"
        return
    fi
    poll_until 10 no_strays || true
    strays="$(live_strays)"
    if [ -z "${strays}" ]; then
        t_ok "${id}" "スイート由来の子孫プロセスが残っていない"
    else
        t_fail "${id}" "子孫プロセスが残存している: $(for p in ${strays}; do printf '%s[%s] ' "${p}" "$(ps -o args= -p "${p}" 2>/dev/null | head -c 90)"; done)"
    fi
}

main() {
    echo "=== verify-global-test-lock (層 1: 並行挙動) ==="
    echo "scratch: ${WORK}"
    [ "${HAVE_FLOCK}" -eq 0 ] && echo "note: flock(1) が無いため排他系ケースは skip します"
    [ "${HAVE_PS}" -eq 0 ] && echo "note: ps/pgrep が無いためプロセス系ケースは skip します"

    case_c01
    case_c02
    case_c03_c04
    case_c05
    case_c06
    case_c07
    case_c08
    case_c09
    case_c10
    case_c12
    case_c13
    case_c14
    case_c15
    case_c16
    case_c17
    case_c18
    case_c19
    case_c20
    case_c21
    case_c22
    case_c23
    case_c24
    case_c25
    case_c26
    case_c11

    echo ""
    echo "=== 結果: passed=${PASS} failed=${FAIL} skipped=${SKIP} ==="
    if [ "${FAIL}" -gt 0 ]; then
        return 1
    fi
    return 0
}

main
exit $?
