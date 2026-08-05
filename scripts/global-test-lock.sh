#!/usr/bin/env bash
#
# scripts/global-test-lock.sh — 全テストレーン共通のグローバルロック (source して使う)。
#
# 目的: 同一 UID・同一マシン (コンテナ) 上で、本規約に参加するテストレーンが
#       同時に 2 本走らないようにする。worktree をまたいだ並列実装の待ち合わせが目的なので
#       「待たずに失敗する」flock -n ではなく **ブロッキング取得** にする。
#
# 設計の正本: devnotes/20260804-2319-global-test-lock/conceptual-design.md
#
# 契約 (非交渉):
#   - ロック保持期間 = 取得 〜 **専用プロセスグループが空になった後**
#     (親の生存期間でも、直接の子の終了時点でもない)
#   - ロック配下では exec を使わない (exec は fd 7 を閉じてロックを即解放する)
#   - 待機中のみ heartbeat を出す (保持中はテストランナー自身が喋る。CI は無競合なので無音)
#   - 再入は「env の nonce == 現存する sidecar の nonce」のときだけ。再入経路は何も獲得しない
#   - flock(1) 不在環境は排他なしで続行 (既存 lane スクリプトの方針を踏襲)。ただし警告を 1 行出す
#   - ロック dir が乗っ取られていたら **明示エラーで停止** する (黙って保護を落とさない)
#   - CI バイパス分岐は作らない (CI が検証するものと開発者が走らせるものを同一に保つ)
#
# 保証しないこと:
#   - SIGKILL / 親のクラッシュ / コンテナ強制停止 (trap が走らない)。
#     この場合も flock は OS が解放し、残留 sidecar は次の取得者が上書きするため
#     「ロックリーク」と「stale nonce による誤再入」は防ぐが、残存子孫との併走は防げない
#   - 自ら setsid()/setpgid() で専用プロセスグループを離脱した子孫
#   - 規約に参加しないプロセス (bug-hunt / 手打ちの vendor/bin/pest / 他ツール)
#
# 並行挙動の検証は scripts/verify-global-test-lock.sh (層 1)、
# 構造的不変条件は tests/Architecture/GlobalTestLockInventoryTest.php (層 2)。

# ---- 内部状態 (source 元シェルに置く) ----
_GTL_FD=7                 # ロック fd。既存 lane が使っていた 9 とは分ける
_GTL_MODE=""              # owner / reentrant / disabled
_GTL_SIDECAR=""
_GTL_NONCE=""
_GTL_HB_PID=""
_GTL_CHILD_PID=""
_GTL_CHILD_PGID=""
_GTL_PREV_MONITOR=0
_GTL_CLEANED=0
_GTL_EXIT_HOOKS=""          # lane 固有の後始末 (関数名の空白区切り)
_GTL_HEARTBEAT_SECS=30      # 検証済み値を固定 (以後 env は読まない)
_GTL_GRACE_SECS=30

_gtl_die() { echo "global-test-lock: ERROR: $*" >&2; exit 1; }
_gtl_warn() { echo "global-test-lock: $*" >&2; }

_gtl_lock_dir() {
    if [ -n "${GLOBAL_TEST_LOCK_DIR:-}" ]; then
        _gtl_warn "using override lock dir ${GLOBAL_TEST_LOCK_DIR} (self-test only)"
        printf '%s\n' "${GLOBAL_TEST_LOCK_DIR}"
        return 0
    fi
    # 基点は /tmp に固定する。${TMPDIR} はプロセスごとに異なりうるため、基点に使うと
    # 同一 UID でもロックが分裂して「マシン全体」の保証が壊れる。
    # アプリ slug は名前に入れない (このロックは repo をまたいで共有されて正しい)。
    printf '/tmp/global-test-lane-%s.d\n' "$(id -u)"
}

# ロック dir を 0700 で用意し、乗っ取り (symlink / 別所有者 / 緩い mode) を fail-secure に検出する。
# UID 接尾辞はユーザー間の通常運用上の衝突を分けるだけで、先取りは防げない。防ぐのはここ。
_gtl_ensure_dir() {
    local dir="$1" owner mode
    mkdir -p -m 700 "${dir}" 2>/dev/null || true
    [ -L "${dir}" ] && _gtl_die "lock dir is a symlink (refusing): ${dir}"
    [ -d "${dir}" ] || _gtl_die "lock dir is not a directory (refusing): ${dir}"
    owner="$(stat -c '%u' "${dir}" 2>/dev/null || stat -f '%u' "${dir}" 2>/dev/null || echo '?')"
    mode="$(stat -c '%a' "${dir}" 2>/dev/null || stat -f '%OLp' "${dir}" 2>/dev/null || echo '?')"
    [ "${owner}" = "$(id -u)" ] || _gtl_die "lock dir owner mismatch (uid ${owner}): ${dir}"
    [ "${mode}" = "700" ] || _gtl_die "lock dir mode must be 700 (got ${mode}): ${dir}"
}

# 検証用 env の値検証。不正値を放置すると剰余がゼロ除算になり、sleep / -ge / 算術展開が
# 失敗して **cleanup の途中でシェルが終了**し、残存グループと次のレーンが併走しうる。
# 取得時に fail-fast する (壊れた設定で保護が半分だけ効く状態を作らない)。
_gtl_validate_env() {
    # 検証済みの値は内部変数 (_GTL_HEARTBEAT_SECS / _GTL_GRACE_SECS) へ固定し、以後は
    # 環境変数を読まない。acquire 後に env を書き換えて検証を迂回する経路を塞ぐため。
    local hb="${GLOBAL_TEST_LOCK_HEARTBEAT_SECS:-30}" gr="${GLOBAL_TEST_LOCK_GRACE_SECS:-30}"
    case "${hb}" in
        ''|*[!0-9]*) _gtl_die "GLOBAL_TEST_LOCK_HEARTBEAT_SECS must be a positive integer: ${hb}" ;;
    esac
    [ "${hb}" -ge 1 ] || _gtl_die "GLOBAL_TEST_LOCK_HEARTBEAT_SECS must be >= 1: ${hb}"
    case "${gr}" in
        ''|*[!0-9]*) _gtl_die "GLOBAL_TEST_LOCK_GRACE_SECS must be a non-negative integer: ${gr}" ;;
    esac
    if [ -n "${GLOBAL_TEST_LOCK_DIR:-}" ]; then
        case "${GLOBAL_TEST_LOCK_DIR}" in
            /*) : ;;
            *) _gtl_die "GLOBAL_TEST_LOCK_DIR must be an absolute path: ${GLOBAL_TEST_LOCK_DIR}" ;;
        esac
    fi
    _GTL_HEARTBEAT_SECS="${hb}"
    _GTL_GRACE_SECS="${gr}"
}

_gtl_new_nonce() {
    # 外部コマンドに依存しない一意トークン (pid + 高分解能時刻 + 乱数)。
    printf '%s-%s-%s%s\n' "$$" "${EPOCHREALTIME:-$(date +%s)}" "${RANDOM}" "${RANDOM}"
}

# sidecar の 1 行目 = nonce。所有者検証つきで読む (他ユーザーが置いた偽 sidecar を信じない)。
_gtl_sidecar_nonce() {
    local f="$1" owner line=""
    [ -L "${f}" ] && return 1          # symlink は読まない (fail-secure)
    [ -f "${f}" ] || return 1
    owner="$(stat -c '%u' "${f}" 2>/dev/null || stat -f '%u' "${f}" 2>/dev/null || echo '?')"
    [ "${owner}" = "$(id -u)" ] || return 1
    IFS= read -r line < "${f}" || return 1
    printf '%s\n' "${line}"
}

# 同一 dir 内の一時ファイルへ書いてから mv する (アトミック書き込み)。
_gtl_write_sidecar() {
    local lane="$1" tmp
    tmp="${_GTL_SIDECAR}.tmp.$$"
    [ -L "${_GTL_SIDECAR}" ] && _gtl_die "sidecar is a symlink (refusing): ${_GTL_SIDECAR}"
    # 保証範囲: これらの型検証が防ぐのは **別 UID 境界** (0700 dir + 所有者検証との併せ技) であって、
    # 「symlink 攻撃の完全防止」ではない。rm -f 後のリダイレクトには同一 UID プロセスとの
    # TOCTOU が残る。同一 UID は既に自分自身と同じ権限を持つため、ここを完全に閉じる意味はない。
    rm -f "${tmp}"                     # 既存 (symlink 含む) を消してから書く
    {
        printf '%s\n' "${_GTL_NONCE}"
        printf 'pid=%s\n' "$$"
        printf 'lane=%s\n' "${lane}"
        printf 'worktree=%s\n' "$(pwd -P)"
        printf 'since=%s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')"
    } > "${tmp}"
    mv -f "${tmp}" "${_GTL_SIDECAR}"
}

# 待機中だけ heartbeat を出す。無出力の待機を LLM エージェントが「ハング」と誤判断して
# プロセスを kill する事故を防ぐのが目的なので、保持者の身元まで出す。
_gtl_heartbeat_loop() {
    local start="$1" waited=0 holder=""
    while :; do
        sleep "${_GTL_HEARTBEAT_SECS}"
        waited=$(( $(date +%s) - start ))
        holder="$(
            {
                # 1 行目 (nonce) は出さず、診断行だけを 1 行に畳む
                read -r _
                while IFS= read -r l; do printf '%s ' "${l}"; done
            } < "${_GTL_SIDECAR}" 2>/dev/null || true
        )"
        echo "global-test-lock: waiting ${waited}s for the global test lane lock — held by ${holder:-<unknown>}" >&2
    done
}

# zombie (Z) は「消滅」とみなす。SIGKILL 済みの Z は fd も DB 接続もポートも保持しないため、
# kill -0 -"$pgid" だけで判定すると永久に「生存」と誤判定して収束しない (実測済み)。
_gtl_group_alive() {
    ps -A -o pgid= -o stat= 2>/dev/null \
        | awk -v g="$1" '{sub(/^ +/, "")} $1 == g && $2 !~ /^Z/ { found = 1 } END { exit !found }'
}

# グループの消滅を待つ。猶予超過でグループへ SIGKILL を送り、**その後は上限を設けず**
# 空になるまで待ち続ける (契約: グループが空になるまでロックを離さない)。
# **必ず wait より前に呼ぶこと**: 先に wait すると、子が INT/TERM を無視した瞬間に
# wait から戻れず SIGKILL に到達できないまま「ロックを永久保持する deadlock」になる。
_gtl_wait_group_gone() {
    local pgid="$1" grace="${_GTL_GRACE_SECS}" waited=0 nagged=0

    # 第 1 段: 猶予内に自発終了するのを待つ
    while _gtl_group_alive "${pgid}"; do
        if [ "${waited}" -ge "${grace}" ]; then
            _gtl_warn "grace exceeded; SIGKILL process group ${pgid}"
            kill -KILL -"${pgid}" 2>/dev/null || true
            break
        fi
        sleep 1
        waited=$(( waited + 1 ))
    done

    # 第 2 段: SIGKILL 後も**空になるまで待ち続ける** (上限を設けない)。
    #
    # ここで諦めて戻ると fd 7 が閉じ、「グループが空になるまで保持」という
    # 非交渉の契約が破れる (残党と次のレーンが併走する)。SIGKILL を生き延びるのは
    # 割り込み不可能な待ち (D state = stuck IO) だけであり、その状況でロックを
    # 手放すより保持し続ける方が安全。ハングと区別できるよう heartbeat 間隔で
    # 残存 pid つきの警告を出し続ける。
    waited=0
    while _gtl_group_alive "${pgid}"; do
        sleep 1
        waited=$(( waited + 1 ))
        nagged=$(( waited % _GTL_HEARTBEAT_SECS ))
        if [ "${nagged}" -eq 0 ]; then
            _gtl_warn "still holding the lock: process group ${pgid} has survivors after SIGKILL (${waited}s): $(
                ps -A -o pgid= -o pid= -o stat= 2>/dev/null \
                    | awk -v g="${pgid}" '{sub(/^ +/, "")} $1 == g && $3 !~ /^Z/ { printf "%s ", $2 }'
            )"
        fi
    done
}

# 稼働中の専用プロセスグループを収束させる (シグナル経路と cleanup 経路の共通実装)。
# **ロック解放より必ず先に呼ぶこと**: 子を起動した後に内部エラー (_gtl_die) や
# set -e による中断で EXIT へ抜けると、稼働中の子・孫を残したまま fd 7 が閉じ、
# 残党と次のレーンが併走して保持期間契約が破れる。
# 冪等: _GTL_CHILD_PGID が空なら何もしない (二重処理を避ける)。
_gtl_reap_active_group() {
    local sig="${1:-TERM}"
    [ -n "${_GTL_CHILD_PGID}" ] || return 0
    kill -"${sig}" -"${_GTL_CHILD_PGID}" 2>/dev/null || true
    _gtl_wait_group_gone "${_GTL_CHILD_PGID}"      # 猶予超過で SIGKILL → 以後は空になるまで待つ
    if [ -n "${_GTL_CHILD_PID}" ]; then
        wait "${_GTL_CHILD_PID}" 2>/dev/null || true
    fi
    _GTL_CHILD_PID=""
    _GTL_CHILD_PGID=""
}

# 冪等。INT/TERM ハンドラ実行後に EXIT trap が再度走っても安全。
_gtl_cleanup() {
    [ "${_GTL_CLEANED}" = "1" ] && return 0
    _GTL_CLEANED=1
    # (1) まず稼働中のプロセスグループを収束させる (異常終了経路の残党を残さない)
    _gtl_reap_active_group TERM
    # (2) lane 固有の後始末を **ロックを保持したまま** 走らせる
    #     (Browser lane の orphan playwright 掃除は、レーン本体が消えた後・
    #      次のレーンが入る前に行う必要があるため、この順序が正しい)
    local hook
    for hook in ${_GTL_EXIT_HOOKS}; do
        "${hook}" || _gtl_warn "exit hook failed (ignored): ${hook}"
    done
    if [ -n "${_GTL_HB_PID}" ]; then
        kill "${_GTL_HB_PID}" 2>/dev/null || true
        wait "${_GTL_HB_PID}" 2>/dev/null || true
        _GTL_HB_PID=""
    fi
    # sidecar は **自分の nonce と一致するときだけ** 削除する
    # (再入した子や次の owner の sidecar を消さない)。
    if [ -n "${_GTL_SIDECAR}" ]; then
        local cur=""
        cur="$(_gtl_sidecar_nonce "${_GTL_SIDECAR}" 2>/dev/null || true)"
        [ -n "${cur}" ] && [ "${cur}" = "${_GTL_NONCE}" ] && rm -f "${_GTL_SIDECAR}"
    fi
    [ "${_GTL_MODE}" = "owner" ] && exec 7>&-
    [ "${_GTL_PREV_MONITOR}" = "1" ] || set +m
    return 0
}

# 契約順序: (1) グループへ転送 → (2) 猶予内の消滅待ち → (3) 猶予超過なら SIGKILL し、
#           以後は **上限なし** で空になるまで待つ →
#           (4) 直接子を wait して reap → (5) sidecar 削除 → (6) fd を閉じて解放 → (7) 自死
_gtl_on_signal() {
    local sig="$1"
    _gtl_reap_active_group "${sig}"   # 受信シグナルをそのままグループへ転送して収束させる
    _gtl_cleanup                      # ここでは _GTL_CHILD_PGID が空なので二重処理にならない
    trap - "${sig}" EXIT
    kill -"${sig}" "$$"
}

# set -m で専用プロセスグループを作れることを取得時に 1 回だけ強制検証する
# (各レーン実行時の ps 検証は、高速終了する子に対して空を返す race があるため best-effort にする)。
_gtl_probe_process_group() {
    local prev=0 pid pgid attempt=0
    case "$-" in *m*) prev=1 ;; esac
    # ps が空を返す race (probe 対象が先に終わった) は「作れなかった」ではないので数回試す。
    while [ "${attempt}" -lt 3 ]; do
        attempt=$(( attempt + 1 ))
        set -m
        sleep 0.3 &
        pid=$!
        [ "${prev}" = "1" ] || set +m
        # `|| pgid=""` が必須: 呼び出し元レーンは `set -euo pipefail` で動くため、
        # ps の非ゼロ (probe 対象が先に終わった / ps 自体が不在) が代入へ伝播して
        # **リトライにも _gtl_die にも到達せず、その場でレーンごと落ちる**。
        # 空は下の判定が「取れなかった」として扱う (厳格判定はこの関数が担い続ける)。
        pgid=""
        pgid="$(ps -o pgid= -p "${pid}" 2>/dev/null | tr -d ' ')" || pgid=""
        kill "${pid}" 2>/dev/null || true
        wait "${pid}" 2>/dev/null || true
        [ "${pgid}" = "${pid}" ] && return 0
        [ -n "${pgid}" ] && break      # 値が取れて不一致 = 本当に作れていない
    done
    _gtl_die "job control で専用プロセスグループを作れない (set -m 不可)"
}

global_test_lock_acquire() {
    local lane="${1:-unknown lane}" dir lockfile start

    # 同一プロセスからの二重取得は no-op。
    # ここを素通しすると owner → reentrant に状態が落ち、以降の global_test_lock_run が
    # 「素通り実行」になって fd 非継承もプロセスグループ管理も失われる。
    if [ -n "${_GTL_MODE}" ]; then
        return 0
    fi

    _gtl_validate_env
    dir="$(_gtl_lock_dir)"
    _GTL_SIDECAR="${dir}/owner"
    lockfile="${dir}/lock"

    # --- 再入: 何も獲得しない (fd / sidecar / trap / プロセスグループのいずれも新設しない) ---
    if [ -n "${GLOBAL_TEST_LOCK_NONCE:-}" ]; then
        local cur=""
        cur="$(_gtl_sidecar_nonce "${_GTL_SIDECAR}" 2>/dev/null || true)"
        if [ -n "${cur}" ] && [ "${cur}" = "${GLOBAL_TEST_LOCK_NONCE}" ]; then
            _GTL_MODE="reentrant"
            return 0
        fi
    fi

    if ! command -v flock >/dev/null 2>&1; then
        _gtl_warn "flock(1) が無いため排他なしで実行します (devcontainer / CI では排他あり)"
        _GTL_MODE="disabled"
        return 0
    fi

    _gtl_ensure_dir "${dir}"
    # dir を 0700 + 所有者検証した上で、ファイル自体の型も検証する (多層防御)。
    [ -L "${lockfile}" ] && _gtl_die "lock file is a symlink (refusing): ${lockfile}"
    [ -L "${_GTL_SIDECAR}" ] && _gtl_die "sidecar is a symlink (refusing): ${_GTL_SIDECAR}"
    _gtl_probe_process_group
    exec 7>"${lockfile}"
    _GTL_MODE="owner"
    trap '_gtl_cleanup' EXIT
    trap '_gtl_on_signal INT' INT
    trap '_gtl_on_signal TERM' TERM

    if ! flock -n 7; then
        start="$(date +%s)"
        # heartbeat 子には fd 7 を渡さない (渡すと解放後もロックが生き続ける)
        _gtl_heartbeat_loop "${start}" 7>&- &
        _GTL_HB_PID=$!
        flock 7                                  # ブロッキング取得 (待つことが目的。上限は設けない)
        kill "${_GTL_HB_PID}" 2>/dev/null || true
        wait "${_GTL_HB_PID}" 2>/dev/null || true
        _GTL_HB_PID=""
    fi

    _GTL_NONCE="$(_gtl_new_nonce)"
    _gtl_write_sidecar "${lane}"                 # 残留 sidecar はここでアトミックに上書きされる
    export GLOBAL_TEST_LOCK_NONCE="${_GTL_NONCE}"
    return 0
}

global_test_lock_run() {
    # 再入 / flock 不在では素通り (fd 7 を保持していないので 7>&- もプロセスグループも不要)
    if [ "${_GTL_MODE}" != "owner" ]; then
        "$@"
        return $?
    fi

    local status=0 pgid=""
    case "$-" in *m*) _GTL_PREV_MONITOR=1 ;; *) _GTL_PREV_MONITOR=0 ;; esac
    set -m
    "$@" 7>&- &                                   # fd 7 は子へ渡さない (orphan による lock leak 防止)
    _GTL_CHILD_PID=$!
    [ "${_GTL_PREV_MONITOR}" = "1" ] || set +m
    _GTL_CHILD_PGID="${_GTL_CHILD_PID}"           # set -m により PGID == PID (取得時に probe 済み)

    # best-effort 検証: 空 = 既に終了 (race) なので異常ではない。値が違うときだけ落とす。
    #
    # `|| pgid=""` が必須: レーンは `set -euo pipefail` で動くため、既に終了した pid に
    # 対する ps の非ゼロが代入へ伝播し、**直下の -n 判定に到達する前にレーンごと落ちる**
    # (偽赤)。空を許容するという下の意図を成立させるために、代入の失敗をここで吸収する。
    # 厳格判定は取得時 1 回の _gtl_probe_process_group が担う (ここは元から best-effort)。
    pgid=""
    pgid="$(ps -o pgid= -p "${_GTL_CHILD_PID}" 2>/dev/null | tr -d ' ')" || pgid=""
    if [ -n "${pgid}" ] && [ "${pgid}" != "${_GTL_CHILD_PID}" ]; then
        _gtl_die "専用プロセスグループを作れなかった (pid=${_GTL_CHILD_PID} pgid=${pgid})"
    fi

    wait "${_GTL_CHILD_PID}" || status=$?
    _GTL_CHILD_PID=""
    _gtl_wait_group_gone "${_GTL_CHILD_PGID}"     # 孫が残っている間はロックを離さない
    _GTL_CHILD_PGID=""
    return "${status}"
}

# lane 固有の後始末を cleanup へ追加登録する。
# **lane 側で trap ... EXIT を張ってはならない**: acquire の前に張れば acquire 側の
# trap に上書きされ、後に張れば _gtl_cleanup を消してロックが解放されなくなる。
# EXIT trap の所有者はライブラリ 1 箇所に固定し、lane はここへ登録する。
global_test_lock_on_exit() {
    # 関数名の誤記が実行時に `|| true` で黙殺されるのを防ぐため、登録時に存在を検証する。
    [ "$#" -eq 1 ] || _gtl_die "global_test_lock_on_exit takes exactly 1 argument"
    case "$1" in
        ''|*[!A-Za-z0-9_]*) _gtl_die "invalid exit hook name: $1" ;;
    esac
    declare -F "$1" >/dev/null 2>&1 || _gtl_die "exit hook is not a defined function: $1"
    _GTL_EXIT_HOOKS="${_GTL_EXIT_HOOKS} $1"
    # flock 不在 / 再入で cleanup が走らない経路でも lane の後始末は必要なので、
    # owner 以外のときだけ素の EXIT trap を張る (owner 時は _gtl_cleanup が呼ぶ)。
    if [ "${_GTL_MODE}" != "owner" ]; then
        trap '_gtl_cleanup' EXIT
    fi
}
