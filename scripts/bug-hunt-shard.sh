#!/usr/bin/env bash
#
# scripts/bug-hunt-shard.sh — bug-hunt シャードオーケストレータ (テンプレート版)
#
# 由来: 参照実装 (派生アプリ) の bug-hunt 基盤を汎用化。アプリ名・ドメイン固有処理は持ち込まず、
#   隔離機構 (専用 DB bug_hunt(_N) / 専用ポート :8010+N / env -i / 用途別 guard) のみを共通コアとして提供する。
#
# ★ dev DB (テンプレート slug の DB) を wipe しないための非交渉要件 (本スクリプトの核):
#   - 全 DB 操作を用途別 wrapper に集約 (artisan_for_shard / pg_admin_for_provision /
#     pg_owner_for_shard)。raw artisan/psql/createdb/dropdb の直実行は設計禁止。
#   - 用途別 3-way hard-deny guard (guard_shard_db_name / guard_bughunt_runtime /
#     guard_admin_provision) を破壊的操作の同一プロセス・直前に通す。
#   - env -i で shell の PG*/DB_*/DATABASE_URL 残留を全遮断し bughunt 値のみ明示注入。
#   - createdb/dropdb は CREATEDB 権限を持つ admin role のみ (bughunt role は CREATEDB 無し)。
#     createdb は DB 名 regex 検証後に OWNER bughunt で実行。
#
# シャード i = (DB ${BUGHUNT_DB_PREFIX}[_{i}], serve :8010+i, APP_URL, レポート dir)。
# shard 0 = 直列走行用 (DB ${BUGHUNT_DB_PREFIX} / :8010)。並列 = shard 1..4 (cap は BUGHUNT_SHARD_CAP。
# --parallel は 2 以上 cap 以下の偶数)。
#
# 本スクリプトは機械的制御 (lock / provision / serve / 欠落検知 / teardown / DB guard) に専念する。
# ブラウザ探索は claude -p でも MCP サーバでもなく、外側ハーネスが .claude/agents/bughunt-shard.md を
# Workflow で N 体 fan-out し、各 subagent が隔離ブラウザセッションを Bash で駆動して担う (SKILL.md 参照)。
#
# サブコマンド:
#   provision --shard I --run-id TS [--coverage]
#                                      # createdb(admin) → migrate:fresh+seed → serve + queue worker
#                                      # (database-analysis/render/media の queue:listen) → 実効env検証
#                                      # --coverage: serve を pcov 付き php で起動し実装到達カバレッジを収集
#                                      #             (既定 OFF。pcov 不在なら no-op で続行)。
#   provision-all [--parallel=N] [--coverage] [--hold-lock]
#                                      # (fan-out 用) lock を保持し run-id 採番 → shard 1..N を一括 provision。
#   モードフラグ (provision / provision-all 専用。--keep-db reuse は provision 時に確定した mode を保持):
#     --real-llm      LLM=real (既定)。親 .env の ANTHROPIC_API_KEY を serve/worker に注入。未設定なら fail-fast。
#     --fake-llm      LLM=fake。canned 応答 (再現/切り分け用)。実 API 未接続。
#     --real-storage  storage=real (TESTING_FAKE_STORAGE=false)。※実 S3 配線は未実装 = inert トグル。
#     既定は real-llm + fake-storage。--real-llm と --fake-llm は同時指定不可。mode を変えるには
#     --keep-db を外して再 provision する。
#   reseed    --shard I --run-id TS    # 自 DB のみ migrate:fresh+seed
#   db-check  --shard I --run-id TS    # DB 名 + User::count() 表示
#   db-exists --shard I --run-id TS    # pg_database 存在確認 (owner role, read-only)
#   mail-urls --shard I --run-id TS [--count K]   # 署名 URL 抽出 (offset+port 二重フィルタ)
#   verify-run --run-id TS             # (fan-out 用) 全 shard の shard-report.md 完遂判定 (空/骨子のみは欠落扱い)。
#   teardown  --run-id TS [--drop-db]  # serve 停止 (+DB 破棄, admin role)
#   self-test                          # 実資源に触れない自己検証 (guard / 資源導出 / env 注入 / run)
#   assets-check                       # 配信物 public/build が現行ソースと一致するか read-only 検査 (fresh=0 / stale=1)。
#   keepdb-check --shard I             # --keep-db reuse の正規 preflight: assets-check (fail-closed) → serve liveness。
#
# 子セッションは本スクリプトを直接呼べない (allowlist 外)。provision が生成する
# シャード専用 wrapper tmp/bug-hunt/shard-{i}-cmd.sh (shard/run-id 焼き込み、safe
# subcommands = db-check/db-exists/mail-urls/reseed のみ) だけが子の Bash 許可対象。
#
# orchestrator gate (B-HARNESS-01): provision / provision-all / teardown は環境変数
# BUGHUNT_ORCHESTRATOR=1 が無いと拒否される (default-deny)。親 (orchestrator) だけが export し、
# fan-out する shard worker には渡さない (worker の自走復旧による共有 worktree 破壊を機械的に防ぐ)。
set -euo pipefail

SCRIPT_PATH="$(cd "$(dirname "$0")" && pwd)/$(basename "$0")"
WORKSPACE="$(cd "$(dirname "$0")/.." && pwd)"
cd "${WORKSPACE}"

BASE_PORT=8010
# 並列 shard の上限 (家系共通の標準形。c2c オーナー裁定 AG-048b で 4 に統一)。
# ★ env で上書きしない (ハードコード)。SHARD_DB_RE は「触れてよい DB の allowlist」であり、
#   外から広げられる余地を作ることはガードの緩和にあたる。
# ★ 1 桁前提 (2..9)。ポート採番が BASE_PORT + N である以上 cap <= 9 は構造的制約。
#   下の文字クラス導出 ([0-${CAP}]) もこの前提に依存する。self-test [a] が 1 桁性を assert する。
BUGHUNT_SHARD_CAP=4
# bug-hunt 専用 DB 接頭辞。dev DB (テンプレート slug の DB) とは別名にして隔離する。
# この接頭辞と数値 suffix のみが SHARD_DB_RE に一致し、それ以外の DB 名は全 abort される。
BUGHUNT_DB_PREFIX="${BUGHUNT_DB_PREFIX:-bug_hunt}"
RUN_ID_RE='^[0-9]{8}-[0-9]{6}(-[0-9]+)?$'
SHARD_RE="^[0-${BUGHUNT_SHARD_CAP}]$"   # 0 = 直列走行 (serial)、1..CAP = 並列 shard
# ★ SHARD_DB_RE の代入はここではなく die() 定義直後 (BUGHUNT_DB_PREFIX の形式検証の後) に置く。

# self-test 専用 sandbox (実資源に触れないための paths 差し替え)。
if [[ -n "${BUGHUNT_SANDBOX:-}" ]]; then
    RUN_BASE="${BUGHUNT_SANDBOX}/devnotes"
    TMP_BASE="${BUGHUNT_SANDBOX}/tmp/bug-hunt"
    LOCK_FILE="${BUGHUNT_SANDBOX}/bug-hunt.lock"
    ENV_FILE="${BUGHUNT_SANDBOX}/.env.bughunt.local"
    MAIN_ENV_FILE="${BUGHUNT_SANDBOX}/.env"     # 親リポジトリ .env (実キー ANTHROPIC_API_KEY 由来)
else
    RUN_BASE="devnotes"
    TMP_BASE="tmp/bug-hunt"
    LOCK_FILE="${WORKSPACE}/.claude/bug-hunt.lock"
    ENV_FILE=".env.bughunt.local"
    MAIN_ENV_FILE=".env"                        # 親リポジトリ .env (実キー ANTHROPIC_API_KEY 由来)
fi

is_dryrun() { [[ -n "${BUGHUNT_SELFTEST_DRYRUN:-}" ]]; }
die() { local code=$1; shift; echo "error: $*" >&2; exit "${code}"; }

# ★ prefix は SHARD_DB_RE にそのまま埋め込まれる。regex メタ文字が入ると allowlist が壊れるため
#   (例: 'b.g_hunt' は 'bXg_hunt' にも一致してしまう)、埋め込む前に形を固定する
#   (「別名の bug-hunt DB 群を選ぶ」既存の自由度は保つ)。
[[ "${BUGHUNT_DB_PREFIX}" =~ ^[a-z][a-z0-9_]*$ ]] \
    || die 1 "BUGHUNT_DB_PREFIX が不正: '${BUGHUNT_DB_PREFIX}' (^[a-z][a-z0-9_]*\$ のみ。regex メタ文字は allowlist を壊す)"

# ★ 本スクリプトが createdb/dropdb/migrate してよい shard DB の **allowlist** (dev DB 防御の核)。
#   cap と同期する。「残留も含めて bug-hunt DB を守る / 検出する」側 —
#   tests/Support/Ci/TestDatabaseEnv::DEV_DB_DENYLIST と
#   database/seeders/Concerns/DetectsBughuntDatabase::BUGHUNT_DB_REGEX — は **cap と同期させない**
#   (狭めると過去 cap=8 期の残留 DB を守れなくなる)。方向が逆であることに注意。
SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-${BUGHUNT_SHARD_CAP}])?$"

# ファイルサイズ (bytes)。GNU stat (-c) と BSD stat (-f) の双方に対応し、無ければ wc -c に fallback。
file_size() {
    local f=$1
    [[ -f "${f}" ]] || { echo 0; return 0; }
    stat -c%s "${f}" 2>/dev/null || stat -f%z "${f}" 2>/dev/null || wc -c < "${f}" | tr -d ' '
}

# orchestrator-only ガード (B-HARNESS-01): provision / provision-all / teardown は
# **親 (orchestrator) のみ**が実行できる。worker は orchestrator の shell env を継承しないため
# BUGHUNT_ORCHESTRATOR を持たず default-deny される (worker の自走復旧による共有 worktree 破壊を防ぐ)。
require_orchestrator() {
    is_dryrun && return 0
    [[ -n "${BUGHUNT_ORCHESTRATOR:-}" ]] && return 0
    die 1 "'$1' は orchestrator (親セッション) 専用です。shard worker は serve 障害時に復旧を試みず、環境ハザードとして report に記録し走行を終了してください (親が復旧します)。親が実行する場合は BUGHUNT_ORCHESTRATOR=1 を export してから呼んでください。"
}

# --- 資源導出 (shard 番号から一意化) ------------------------------------------

shard_db() { [[ "$1" == 0 ]] && echo "${BUGHUNT_DB_PREFIX}" || echo "${BUGHUNT_DB_PREFIX}_$1"; }
shard_port() { echo "$((BASE_PORT + $1))"; }
shard_url() { echo "http://127.0.0.1:$((BASE_PORT + $1))"; }
run_dir() { echo "${RUN_BASE}/$1-bug-hunt"; }
shard_report_dir() { echo "$(run_dir "$2")/shard-$1"; }
manifest_path() { echo "$(run_dir "$1")/manifest.json"; }
shard_profile_dir() { echo "${TMP_BASE}/profile-$1"; }
shard_download_dir() { echo "${TMP_BASE}/downloads-$1"; }
shard_trace_dir() { echo "${TMP_BASE}/trace-$1"; }
wrapper_path() { echo "${TMP_BASE}/shard-$1-cmd.sh"; }
worker_pidfile() { echo "${TMP_BASE}/worker-$1-$2.pid"; }   # $1=shard $2=connection
worker_logfile() { echo "${TMP_BASE}/worker-$1-$2.log"; }

# --- 入力検証 -----------------------------------------------------------------

validate_shard() {
    [[ "${1:-}" =~ ${SHARD_RE} ]] || die 2 "invalid --shard: '${1:-}' (0..${BUGHUNT_SHARD_CAP} のみ、0=直列)"
}

# --parallel の受理値: 2 以上 cap 以下の偶数 (固定ストーリーマップを持つ N のみ)。
# 偶数制限は stories_for_shard の固定マップが偶数 N でしか定義されていないことに由来する。
# ★ cap を上げる場合は stories_for_shard へのマップ追加が同一変更で必須。
#   受理集合とマップ定義のずれは self-test [r] と BughuntShardCapInvariantTest が機械検出する。
# ★ (( ... )) は結果 0 を非ゼロ終了として扱うため、必ず `|| return 1` を付け最後に明示 return 0 する。
valid_parallel_n() {
    local n=${1:-}
    [[ "${n}" =~ ^[0-9]+$ ]] || return 1
    (( n >= 2 && n <= BUGHUNT_SHARD_CAP && n % 2 == 0 )) || return 1
    return 0
}

validate_run_id() {
    [[ "${1:-}" =~ ${RUN_ID_RE} ]] || die 2 "invalid --run-id: '${1:-}' (YYYYMMDD-HHMMSS[-n])"
    # path traversal 二重防御 (RUN_ID_RE で '/' '..' は既に排除済み)。realpath -m は GNU 限定のため
    # python の正規化 (os.path.normpath) で移植性を確保する。
    local rd base
    rd="$(python3 -c 'import os,sys; print(os.path.normpath(sys.argv[1]))' "$(run_dir "$1")")"
    base="$(python3 -c 'import os,sys; print(os.path.normpath(sys.argv[1]))' "${RUN_BASE}")"
    [[ "${rd}" == "${base}/"* ]] || die 2 "run dir が ${RUN_BASE}/ の外を指している: ${rd}"
}

require_manifest() {
    local mf; mf="$(manifest_path "$1")"
    [[ -f "${mf}" ]] || die 1 "manifest が無い: ${mf} (run の外で使われた可能性)"
}

# --- run-id 採番 (suffix カウンタ) --------------------------------------------

allocate_run_id() {
    local base="${1:-$(TZ=Asia/Tokyo date +%Y%m%d-%H%M%S)}"
    local run_id="${base}" suffix=1
    while [[ -e "$(run_dir "${run_id}")" ]]; do
        suffix=$((suffix + 1))
        run_id="${base}-${suffix}"
    done
    echo "${run_id}"
}

# --- ★ 用途別 3-way hard-deny guard (非交渉要件) ------------------------------

# 共通核: DB 名 regex。dev DB 名 (大小/前後空白バリアント含む) は SHARD_DB_RE に一致しないため
# 構造的に abort される (dev DB 防御の最終防波堤)。
guard_shard_db_name() {
    local db="${1:-}"
    [[ "${db}" =~ ${SHARD_DB_RE} ]] \
        || die 1 "guard_shard_db_name: DB 名 '${db}' は ${SHARD_DB_RE} に一致しない (dev DB 防御で abort)"
}

# runtime 経路 (artisan_for_shard / pg_owner_for_shard): DB名 regex ∧ APP_ENV ∧ user==bughunt。
guard_bughunt_runtime() {
    local db="${1:-}" user="${2:-}"
    guard_shard_db_name "${db}"
    local app_env; app_env="$(env_file_get APP_ENV)"
    [[ "${app_env}" == "bughunt.local" ]] \
        || die 1 "guard_bughunt_runtime: APP_ENV='${app_env}' が bughunt.local でない (abort)"
    [[ "${user}" == "bughunt" ]] \
        || die 1 "guard_bughunt_runtime: DB user='${user}' が bughunt でない (abort)"
}

# admin provision 経路 (pg_admin_for_provision / createdb / dropdb): DB名 regex ∧ admin_user 明示。
guard_admin_provision() {
    local db="${1:-}" admin_user="${2:-}"
    guard_shard_db_name "${db}"
    [[ -n "${admin_user}" ]] \
        || die 1 "guard_admin_provision: BUGHUNT_ADMIN_USER が未設定 (createdb/dropdb には admin role 明示必須)"
}

# --- .env.bughunt.local 読み出し ----------------------------------------------

env_file_get() {
    [[ -f "${ENV_FILE}" ]] || { echo ""; return 0; }
    local v
    v="$(grep -E "^$1=" "${ENV_FILE}" | head -1 | cut -d= -f2- || true)"
    v="${v%%[[:space:]]#*}"
    v="${v#"${v%%[![:space:]]*}"}"
    v="${v%"${v##*[![:space:]]}"}"
    echo "${v}"
}

env_file_required() {
    local v
    v="$(env_file_get "$1")"
    [[ -n "${v}" ]] || die 1 "${ENV_FILE} に $1 が無い (隔離前提値を確認すること)"
    echo "${v}"
}

# --- モード制御 (real-llm 既定 / fake-llm / real-storage) -----------------------
# bug-hunt は既定 real-llm (実 Anthropic 接続)。--fake-llm 時のみ canned 応答 (T035 の
# CannedPromptFakeRegistrar)。storage は既定 fake (実 S3 未配線 = inert トグル)。
# フラグは provision 時に env -i で明示注入する (残留 env による反転を防ぐため両モードとも明示)。
LLM_MODE="real"        # real (既定) | fake
STORAGE_MODE="fake"    # fake (既定) | real
# MODE_ENV:    フラグのみ (TESTING_FAKE_LLM / TESTING_FAKE_STORAGE)。serve/worker/実効 env 検証に注入可 (秘密なし)。
# LLM_KEY_ENV: ANTHROPIC_API_KEY (実キー)。serve/worker のみに注入 (実 LLM を呼ぶプロセスに限定)。
#              値はここでのみ扱い、echo / manifest_update / migrate/seed/verify には渡さない。
# (set -u 安全: assert が build_mode_env 前に呼ばれても ${#LLM_KEY_ENV[@]} が壊れないよう空配列で初期化)
MODE_ENV=()
LLM_KEY_ENV=()

# 秘密取扱の xtrace ガード: 本スクリプトは既定 set -euo pipefail (-x 無し) だが、-x 有効時も
# 秘密 (キー値・キーを含む代入/起動) を trace に出さない防御。BEGIN/END で秘密取扱区間を挟む。
# ネスト非対応 (単純用途)。
_SECRET_XTRACE_SAVED=0
secret_xtrace_off()     { case $- in *x*) _SECRET_XTRACE_SAVED=1; set +x ;; *) _SECRET_XTRACE_SAVED=0 ;; esac; }
secret_xtrace_restore() { [[ "${_SECRET_XTRACE_SAVED}" == 1 ]] && set -x; return 0; }

# 親 .env から 1 キーを読む。値はログ・stderr に出さない (xtrace 局所退避。command 置換の
# subshell 内で set +x するため親の -x 状態は汚さない)。キーは awk 完全一致 (正規表現メタ文字の
# 事故を防ぐ。dotenv 標準どおり `KEY=value` で `=` 前後に空白を置かない前提)。export 前置・単/
# ダブルクォート・非クォート値の後置コメントを正しく処理する (実キー誤読で real-llm 判定を誤らせないため)。
main_env_get() {
    { set +x; } 2>/dev/null
    [[ -f "${MAIN_ENV_FILE}" ]] || { printf ''; return 0; }
    local v
    v="$(awk -v k="$1" '
        { s=$0; sub(/^[[:space:]]*export[[:space:]]+/, "", s);
          eq=index(s, "=");
          if (eq>0 && substr(s,1,eq-1)==k) { print substr(s, eq+1); exit } }
    ' "${MAIN_ENV_FILE}")"
    if   [[ "${v}" == \"*\" ]]; then v="${v#\"}"; v="${v%\"}"       # "..." を剥がす
    elif [[ "${v}" == \'*\' ]]; then v="${v#\'}"; v="${v%\'}"       # '...' を剥がす
    else v="${v%%[[:space:]]#*}"; fi                               # 非クォート時のみ後置 # コメント除去
    v="${v#"${v%%[![:space:]]*}"}"; v="${v%"${v##*[![:space:]]}"}" # 前後空白除去
    printf '%s' "${v}"
}

# モード env を構築する (フラグと実キーを分離 = 最小権限)。両フラグを常に明示注入し、
# real-llm 時のみ LLM_KEY_ENV に実キーを載せる。秘密取扱区間は xtrace 退避で囲む。
build_mode_env() {
    secret_xtrace_off                              # キー代入を trace に出さない
    MODE_ENV=(); LLM_KEY_ENV=()
    if [[ "${LLM_MODE}" == "fake" ]]; then
        MODE_ENV+=("TESTING_FAKE_LLM=true")
    else
        MODE_ENV+=("TESTING_FAKE_LLM=false")       # real も明示注入 (残留 env による反転防止)
        local key; key="$(main_env_get ANTHROPIC_API_KEY)"
        LLM_KEY_ENV+=("ANTHROPIC_API_KEY=${key}")  # serve/worker 限定
    fi
    if [[ "${STORAGE_MODE}" == "real" ]]; then
        MODE_ENV+=("TESTING_FAKE_STORAGE=false")
    else
        MODE_ENV+=("TESTING_FAKE_STORAGE=true")     # 既定 fake も明示注入
    fi
    secret_xtrace_restore
}

# real-llm ∧ キー空/未設定なら die。キーの読取は build_mode_env の 1 箇所のみ (ここでは再読しない)。
# 既に構築済みの LLM_KEY_ENV を検査する (単一正本)。値を trace に出さないよう xtrace 退避で囲む。
assert_llm_key_present() {
    [[ "${LLM_MODE}" == "real" ]] || return 0
    secret_xtrace_off
    if [[ "${#LLM_KEY_ENV[@]}" -ne 1 || "${LLM_KEY_ENV[0]}" == "ANTHROPIC_API_KEY=" ]]; then
        secret_xtrace_restore
        die 1 "real-llm (既定) だが ${MAIN_ENV_FILE} に ANTHROPIC_API_KEY が無い/空です。\
実キーで探索するか、--fake-llm で canned 応答に切り替えてください (fake-llm は再現/切り分け用)。(キー値はログに出しません)"
    fi
    secret_xtrace_restore
}

# cmd_provision 冒頭と cmd_provision_all のループ前で共通に呼ぶ (分岐差を作らない)。
# build_mode_env (キー読取) → assert_llm_key_present (配列検査) の順で LLM_KEY_ENV を単一正本にする。
prepare_mode_and_preflight() {
    build_mode_env
    assert_llm_key_present
}

# --- ★ 用途別 wrapper (env -i で最小環境、bughunt 値のみ明示注入) --------------

# artisan (migrate:fresh / db:seed / tinker / migrate) — runtime 経路。
artisan_for_shard() {
    local db=$1 url=$2; shift 2
    guard_bughunt_runtime "${db}" bughunt
    env -i PATH="${PATH}" HOME="${HOME}" \
        DB_CONNECTION=pgsql \
        DB_HOST="$(env_file_required DB_HOST)" DB_PORT="$(env_file_required DB_PORT)" \
        DB_DATABASE="${db}" DB_USERNAME=bughunt DB_PASSWORD="$(env_file_get DB_PASSWORD)" \
        APP_URL="${url}" \
        php artisan "$@" --env=bughunt.local
}

# createdb / dropdb — admin 経路 (bughunt role は CREATEDB を持たない)。
pg_admin_for_provision() {
    local op=$1 db=$2   # op ∈ {createdb, dropdb}
    local admin_user; admin_user="$(env_file_get BUGHUNT_ADMIN_USER)"
    guard_admin_provision "${db}" "${admin_user}"
    local -a op_cmd
    case "${op}" in
        createdb) op_cmd=(createdb -O bughunt "${db}") ;;   # ★ OWNER bughunt 必須
        dropdb)   op_cmd=(dropdb --if-exists "${db}") ;;
        *) die 2 "pg_admin_for_provision: unknown op '${op}'" ;;
    esac
    env -i PATH="${PATH}" \
        PGHOST="$(env_file_required DB_HOST)" PGPORT="$(env_file_required DB_PORT)" \
        PGUSER="${admin_user}" PGPASSWORD="$(env_file_get BUGHUNT_ADMIN_PASSWORD)" \
        "${op_cmd[@]}"
}

# read-only psql (pg_database 存在確認等。CREATEDB 不要) — owner bughunt role。
pg_owner_for_shard() {
    local op=$1 db=$2   # op ∈ {exists}
    guard_bughunt_runtime "${db}" bughunt
    local -a op_cmd
    case "${op}" in
        exists) op_cmd=(psql -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname='${db}'") ;;
        *) die 2 "pg_owner_for_shard: unknown op '${op}'" ;;
    esac
    env -i PATH="${PATH}" \
        PGHOST="$(env_file_required DB_HOST)" PGPORT="$(env_file_required DB_PORT)" \
        PGUSER=bughunt PGPASSWORD="$(env_file_get DB_PASSWORD)" \
        "${op_cmd[@]}"
}

# --- メール署名 URL 抽出 (時間 offset × 空間 port の二重フィルタ) --------------

extract_mail_urls() {
    local logfile=$1 offset=$2 port=$3 count=$4
    [[ -f "${logfile}" ]] || return 0
    tail -c +"$((offset + 1))" "${logfile}" \
        | grep -oE "http://127\.0\.0\.1:${port}[^\"'<>[:space:]\\\\]*" \
        | tail -n "${count}" || true
}

# --- offset 記録ヘルパ ---------------------------------------------------------

offset_file() { echo "$(shard_report_dir "$1" "$2")/.log-offset"; }

# --- manifest (python3 ヘルパ。jq 非依存。並列 run の機械的状態台帳) -----------

manifest_update() {
    # usage: manifest_update <run_id> <shard|-> key=value...  (shard='-' は top-level)
    local run_id=$1 shard=$2; shift 2
    local mf; mf="$(manifest_path "${run_id}")"
    RUN_ID="${run_id}" SHARD="${shard}" MF="${mf}" python3 - "$@" <<'PY'
import json, os, sys
mf = os.environ["MF"]
os.makedirs(os.path.dirname(mf), exist_ok=True)
data = {"run_id": os.environ["RUN_ID"], "shards": {}}
if os.path.exists(mf):
    with open(mf) as f:
        data = json.load(f)
target = data if os.environ["SHARD"] == "-" else data.setdefault("shards", {}).setdefault(os.environ["SHARD"], {})
for kv in sys.argv[1:]:
    k, _, v = kv.partition("=")
    try:
        target[k] = json.loads(v)
    except (json.JSONDecodeError, ValueError):
        target[k] = v
with open(mf, "w") as f:
    json.dump(data, f, ensure_ascii=False, indent=2)
PY
}

manifest_get() {
    local mf; mf="$(manifest_path "$1")"
    SHARD="$2" KEY="$3" MF="${mf}" python3 - <<'PY'
import json, os
with open(os.environ["MF"]) as f:
    data = json.load(f)
target = data if os.environ["SHARD"] == "-" else data.get("shards", {}).get(os.environ["SHARD"], {})
v = target.get(os.environ["KEY"], "")
print(v if not isinstance(v, (dict, list)) else json.dumps(v))
PY
}

manifest_valid_shards() {
    # 不正 key (空白入り / パストラバーサル) を除外し有効 shard key (0..cap) のみ出力。
    # 旧 run (cap=8 期) の manifest を読むと shard key cap+1.. は warning + skip になる。
    local mf; mf="$(manifest_path "$1")"
    MF="${mf}" CAP="${BUGHUNT_SHARD_CAP}" python3 - <<'PY'
import json, os, re, sys
with open(os.environ["MF"]) as f:
    data = json.load(f)
cap = os.environ["CAP"]
if not re.fullmatch(r"[2-9]", cap):
    raise SystemExit(f"invalid BUGHUNT_SHARD_CAP for manifest parser: {cap!r}")
for key in data.get("shards", {}):
    if re.fullmatch(rf"[0-{cap}]", key):
        print(key)
    else:
        print(f"warning: manifest に不正な shard key {key!r} — skip", file=sys.stderr)
PY
}

manifest_check() {
    MF="$1" python3 - <<'PY'
import json, os, sys
with open(os.environ["MF"]) as f:
    data = json.load(f)
assert "run_id" in data, "run_id missing"
assert "shards" in data and data["shards"], "shards missing/empty"
for sid, sh in data["shards"].items():
    for key in ("db", "port", "log_offset", "stories"):
        assert key in sh, f"shard {sid}: {key} missing"
print("manifest ok")
PY
}

# --- report 存在検証 (無言の欠落禁止) -----------------------------------------

verify_reports() {
    # usage: verify_reports <run_id> <N>  → 全完遂=0 / 一部欠落=3
    # 「present だが空/骨子のみ」も欠落 (=3) と判定する (最小実質性を非空行数の下限で機械判定)。
    local run_id=$1 n=$2 rc=0 i report nonblank
    local min_lines="${BUGHUNT_REPORT_MIN_LINES:-12}"
    for i in $(seq 1 "${n}"); do
        report="$(shard_report_dir "${i}" "${run_id}")/shard-report.md"
        if [[ ! -f "${report}" ]]; then
            manifest_update "${run_id}" "${i}" report_present=false
            echo "warning: shard-${i} は shard-report.md 不在 = 未走行 (manifest に記録済み)" >&2
            rc=3
            continue
        fi
        nonblank="$(grep -cve '^[[:space:]]*$' "${report}" 2>/dev/null || echo 0)"
        if [[ "${nonblank}" -lt "${min_lines}" ]]; then
            manifest_update "${run_id}" "${i}" report_present=true "report_nonblank_lines=${nonblank}" report_substantive=false
            echo "warning: shard-${i} は shard-report.md が実質空 (非空 ${nonblank} 行 < ${min_lines}) = 走行未完 (manifest に記録済み)" >&2
            rc=3
            continue
        fi
        manifest_update "${run_id}" "${i}" report_present=true "report_nonblank_lines=${nonblank}" report_substantive=true
    done
    return "${rc}"
}

cmd_verify_run() {
    local run_id=$1 n
    require_manifest "${run_id}"
    n="$(manifest_get "${run_id}" - parallel)"
    valid_parallel_n "${n}" || die 2 "verify-run: manifest の parallel が 2..${BUGHUNT_SHARD_CAP} の偶数でない (run-id 不整合): '${n}'"
    local rc=0
    verify_reports "${run_id}" "${n}" || rc=$?
    echo "verify-run: run-id=${run_id} parallel=${n} exit=${rc} (manifest: $(manifest_path "${run_id}"))"
    return "${rc}"
}

# --- shard 専用 wrapper 生成 (子セッションの唯一の Bash 許可対象) --------------

generate_wrapper() {
    local shard=$1 run_id=$2
    local wp; wp="$(wrapper_path "${shard}")"
    mkdir -p "${TMP_BASE}"
    cat > "${wp}" <<EOF
#!/usr/bin/env bash
# 自動生成 (bug-hunt-shard.sh provision): shard ${shard} / run ${run_id} 専用 wrapper。
# 子セッションに許可されるのは本 wrapper のみ。shard/run-id は焼き込み済みで上書き不可。
# safe subcommands (db-check / db-exists / mail-urls / reseed) 以外は拒否する。
set -euo pipefail
SUB="\${1:-}"
shift || true
case "\${SUB}" in
    db-check|db-exists|reseed)
        [[ \$# -eq 0 ]] || { echo "error: \${SUB} は引数を受け付けない" >&2; exit 2; }
        ;;
    mail-urls)
        if [[ \$# -gt 0 ]]; then
            [[ "\${1}" == "--count" && \$# -eq 2 && "\${2}" =~ ^[0-9]+\$ ]] \\
                || { echo "error: mail-urls の引数は --count K のみ" >&2; exit 2; }
        fi
        ;;
    *)
        echo "error: 許可外サブコマンド: '\${SUB}' (db-check / db-exists / mail-urls / reseed のみ)" >&2
        exit 2
        ;;
esac
exec "${SCRIPT_PATH}" "\${SUB}" --shard ${shard} --run-id ${run_id} "\$@"
EOF
    chmod +x "${wp}"
}

# --- config cache 誤接続防止 --------------------------------------------------

clear_stale_config() {
    is_dryrun && return 0
    if [[ -f bootstrap/cache/config.php ]]; then
        artisan_for_shard "$(shard_db 0)" "$(shard_url 0)" config:clear > /dev/null 2>&1 || true
    fi
}

# --- asset freshness guard (stale public/build による配信ドリフトを塞ぐ) --------
# setup-worktree.sh が親の build を cp -r で複製する。存在のみ判定だと stale-but-present な
# manifest を fresh 扱いして古い配信物を配ってしまう。ビルド入力の content fingerprint +
# manifest chunk 実在で鮮度判定する。

BUILD_INPUT_PATHS=(resources package.json pnpm-lock.yaml vite.config.* svelte.config.* tailwind.config.* postcss.config.* tsconfig*.json)

compute_build_fingerprint() {
    {
        local p
        for p in "${BUILD_INPUT_PATHS[@]}"; do
            [[ -e "${p}" ]] || continue
            if [[ -d "${p}" ]]; then
                find "${p}" -type f -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum --
            else
                sha256sum -- "${p}"
            fi
        done
    } | sha256sum | awk '{print $1}'
}

build_inputs_dirty() {
    git rev-parse --is-inside-work-tree >/dev/null 2>&1 || return 1
    [[ -n "$(git status --porcelain -- "${BUILD_INPUT_PATHS[@]}" 2>/dev/null)" ]]
}

manifest_chunks_present() {
    [[ -s public/build/manifest.json ]] || return 1
    php -r '
        $dir = "public/build";
        $m = json_decode(file_get_contents("$dir/manifest.json"), true);
        if (!is_array($m)) { exit(1); }
        $files = [];
        $seen = [];
        $collect = function ($key, $entry) use (&$files, &$seen, $m, &$collect) {
            if (isset($seen[$key])) { return; }
            $seen[$key] = true;
            if (!empty($entry["file"])) { $files[$entry["file"]] = true; }
            foreach (["css", "assets"] as $list) {
                if (!empty($entry[$list])) { foreach ($entry[$list] as $a) { $files[$a] = true; } }
            }
            foreach (["imports", "dynamicImports"] as $rel) {
                if (!empty($entry[$rel])) {
                    foreach ($entry[$rel] as $k) {
                        if (!isset($m[$k])) { fwrite(STDERR, "dangling ref: $k\n"); exit(1); }
                        $collect($k, $m[$k]);
                    }
                }
            }
        };
        foreach ($m as $k => $entry) { if (is_array($entry)) { $collect($k, $entry); } }
        foreach (array_keys($files) as $f) {
            if (!is_file("$dir/$f")) { fwrite(STDERR, "missing chunk: $f\n"); exit(1); }
        }
        exit(0);
    ' >/dev/null 2>&1
}

# 配信物 (public/build) が現行ソースと不一致か (= rebuild が必要か) の判定 SoT。
# 副作用なし。stale (= 要 build) なら 0、fresh なら 1。
assets_are_stale() {
    local fp_file=public/build/.bughunt-build-fingerprint
    local current saved=""
    current="$(compute_build_fingerprint)"
    [[ -f "${fp_file}" ]] && saved="$(cat "${fp_file}")"

    [[ ! -s public/build/manifest.json ]] && return 0
    [[ -z "${saved}" || "${saved}" != "${current}" ]] && return 0
    build_inputs_dirty && return 0
    manifest_chunks_present || return 0
    return 1
}

ensure_fresh_assets() {
    is_dryrun && return 0

    # bug-hunt は配信物 (public/build) を使う。dev server marker が残ると Vite が hot を参照するため除去する。
    if [[ -e public/hot ]]; then
        echo ">>> bug-hunt uses built assets; removing public/hot"
        rm -f public/hot
    fi

    if assets_are_stale; then
        echo ">>> assets stale → pnpm build"
        pnpm build
        compute_build_fingerprint > public/build/.bughunt-build-fingerprint
    fi
}

# 配信物 (public/build) が現行ソースと一致するかを検査する read-only ゲート。
# 契約: build しない / public/hot を触らない / DB に触らない / fingerprint を書かない。exit: fresh=0 / stale=1。
cmd_assets_check() {
    if [[ -e public/hot ]]; then
        echo "error: assets-check: public/hot が存在 (Vite dev-server マーカー)。bug-hunt は built assets を使うため hot 経由だと配信物が現行ソースと乖離する。" >&2
        echo "  対処: 再 provision する (ensure_fresh_assets が public/hot を除去し rebuild する)。" >&2
        return 1
    fi
    if ! assets_are_stale; then
        echo "assets-check: public/build は現行ソースと一致 (fresh)"
        return 0
    fi
    if [[ ! -s public/build/manifest.json ]]; then
        echo "error: assets-check: public/build/manifest.json が無い/空。対処: 再 provision (または pnpm build)。" >&2
    elif [[ ! -s public/build/.bughunt-build-fingerprint ]]; then
        echo "error: assets-check: fingerprint 記録 (.bughunt-build-fingerprint) が無い/空。対処: 再 provision (または pnpm build) で記録を生成。" >&2
    elif [[ "$(cat public/build/.bughunt-build-fingerprint)" != "$(compute_build_fingerprint)" ]]; then
        echo "error: assets-check: public/build が現行ソースと不一致 (fingerprint mismatch = ソース更新後に未 rebuild)。対処: --keep-db を外して再 provision するか pnpm build 後に serve 再起動。" >&2
    elif build_inputs_dirty; then
        echo "error: assets-check: ビルド入力に未コミット変更 (dirty)。対処: 作業ツリーを整理し再 provision する。" >&2
    elif ! manifest_chunks_present; then
        echo "error: assets-check: manifest が指す chunk が public/build に不在 (壊れた/部分 build)。対処: 再 provision (または pnpm build)。" >&2
    else
        echo "error: assets-check: public/build が現行ソースと不一致 (要再 build)。対処: 再 provision するか pnpm build 後に serve 再起動。" >&2
    fi
    return 1
}

# --- filament assets guard (worktree は composer install --no-scripts のため -----
#     post-autoload-dump の filament:upgrade が走らず public/*/filament が欠落する)

FILAMENT_ASSET_MARKER=public/js/filament/.bughunt-filament-version
FILAMENT_REQUIRED_ASSETS=(public/js/filament/filament/app.js public/css/filament/filament/app.css)

filament_version_from_lock() {
    php -r '
        $lock = json_decode((string) file_get_contents("composer.lock"), true);
        foreach (($lock["packages"] ?? []) as $p) {
            if (($p["name"] ?? "") === "filament/filament") { echo $p["version"] ?? ""; return; }
        }
    ' 2>/dev/null
}

filament_assets_present() {
    local f
    for f in "${FILAMENT_REQUIRED_ASSETS[@]}"; do
        [[ -s "${f}" ]] || return 1
    done
    return 0
}

# 冪等 publish: marker (composer.lock の filament version) 一致 ∧ 必須アセット実在なら skip。
# marker は filament:assets 成功後にのみ書く (失敗時は残さず次回再実行)。
# 並列 fan-out (provision-all) は shard を直列 provision するため race しない。
# 将来 provision を並列化する場合は本 helper を worktree 単位の事前フェーズへ移すこと。
ensure_filament_assets() {
    local db=$1 url=$2
    is_dryrun && return 0
    local version; version="$(filament_version_from_lock)"
    [[ -z "${version}" ]] \
        && echo "warning: composer.lock から filament/filament version を解決できない (marker skip 不可 = 毎回 publish 判定)" >&2
    if [[ -n "${version}" && -f "${FILAMENT_ASSET_MARKER}" \
        && "$(cat "${FILAMENT_ASSET_MARKER}")" == "${version}" ]] && filament_assets_present; then
        return 0
    fi
    echo ">>> filament assets missing/stale → filament:assets"
    artisan_for_shard "${db}" "${url}" filament:assets
    filament_assets_present \
        || die 1 "filament:assets 実行後も必須アセットが無い (${FILAMENT_REQUIRED_ASSETS[*]})。filament の publish 先変更を疑い、artisan filament:assets の出力を確認すること"
    [[ -n "${version}" ]] && printf '%s' "${version}" > "${FILAMENT_ASSET_MARKER}"
    return 0
}

cmd_keepdb_check() {
    local shard=$1
    cmd_assets_check || die 1 "--keep-db reuse 中止: アセットが stale (上記理由)。provision をスキップせず再 provision してください。"
    local url code
    url="$(shard_url "${shard}")"
    code="$(curl -s -o /dev/null -w '%{http_code}' "${url}/login" || true)"
    [[ "${code}" == "200" || "${code}" == "302" ]] \
        || die 1 "--keep-db reuse 中止: serve (${url}) 応答 ${code} (200/302 期待)。serve 未起動の可能性。"
    # worker 生存確認 (serve だけ生きていて worker が死んだ状態で reuse すると F-01 が再発する)。
    # kill -0 でなく cmdline 照合 (stale pidfile / pid 再利用の誤判定防止。Codex 概念 R1 反映)。
    local conn
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        worker_alive "${shard}" "${conn}" \
            || die 1 "--keep-db reuse 中止: worker (${conn}) が起動していない/照合不一致 (pidfile: $(worker_pidfile "${shard}" "${conn}"))。queued 滞留 (F-01) が再発するため再 provision してください。"
    done
    echo "keepdb-check: assets fresh + serve ${code} + workers alive (reuse 可)"
}

# --- 専用 queue connection worker (F-01 対策) ----------------------------------
# RunManualAnalysis / RunManualRender / DeleteTakeObjectsJob / DeleteRenderOutputsJob は
# onConnection() で専用 connection (driver=database 固定) を指定するため、
# .env.bughunt.local の QUEUE_CONNECTION=sync (default connection の差し替え) をバイパスする。
# provision が本リストの connection ごとに queue:listen worker を起動し、teardown が停止する。
# ★ リストは config/queue.php の「driver=database の専用 connection (既定 'database' を除く)」と
#   一致させること (self-test [y] が PHP 実評価で drift を機械検出する。順序は不問 = sort 比較)。
BUGHUNT_WORKER_CONNECTIONS=(database-analysis database-render database-media)

# worker pid が「当該 connection の queue:listen」として生きているかの検証 (kill -0 では
# stale pidfile / pid 再利用を誤判定するため /proc cmdline を照合する。Linux 前提 = teardown と同じ)。
# 照合は artisan / queue:listen / connection 名 / --env=bughunt.local を独立に確認する
# (単一パターンだと将来の引数順序変化で偽陰性化するため。Codex 詳細 R1 反映)。
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

# 専用 connection worker の起動。serve と同一の env 隔離 (env -i + bughunt 値明示注入)。
# - queue:listen を使う: 各イテレーションで子 (queue:work --once) を起動する Laravel 公式の
#   スーパーバイザ構成。reseed (migrate:fresh) で jobs/cache テーブルが一時消滅して子が
#   異常終了しても master が継続する (queue:work daemon は cache 読みの QueryException で
#   静かに死に F-01 が再発しうる)。
# - setsid で専用 process group (pid==pgid) 化: teardown が process group 一括 kill で
#   master と子を race なく停止するため。
# - --tries=1 は Job 側の $tries=1 と整合。--timeout=1800 は listener が子を kill する天井で、
#   Job 側の $timeout (1,380/1,500) が pcntl alarm で先に効く (予約 TTL 1,800 と同値)。
start_shard_workers() {
    local shard=$1 db=$2 url=$3
    guard_bughunt_runtime "${db}" bughunt
    local conn pid
    # 秘密 (LLM_KEY_ENV) を展開するプロセス起動を xtrace ガードで挟む (-x 有効時も値を trace に出さない)。
    # worker は serve と同一の env 隔離 + モードフラグ + 実キー (real-llm 時のみ) を注入する。
    secret_xtrace_off
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        env -i PATH="${PATH}" HOME="${HOME}" \
            DB_CONNECTION=pgsql \
            DB_HOST="$(env_file_required DB_HOST)" DB_PORT="$(env_file_required DB_PORT)" \
            DB_DATABASE="${db}" DB_USERNAME=bughunt DB_PASSWORD="$(env_file_get DB_PASSWORD)" \
            APP_URL="${url}" \
            ${MODE_ENV[@]+"${MODE_ENV[@]}"} ${LLM_KEY_ENV[@]+"${LLM_KEY_ENV[@]}"} \
            setsid php artisan queue:listen "${conn}" --env=bughunt.local \
                --sleep=1 --tries=1 --timeout=1800 \
            > "$(worker_logfile "${shard}" "${conn}")" 2>&1 &
        pid=$!
        echo "${pid}" > "$(worker_pidfile "${shard}" "${conn}")"
    done
    secret_xtrace_restore
    # fail-fast: 起動 1 秒後の即死検知 (artisan 起動失敗・接続不能などを provision 段階で顕在化)。
    # 併せて pid==pgid (setsid が新 session/process group を確立したこと) を検証する
    # (group kill / group 消滅待ちの前提条件を起動時不変条件として固定。Codex 詳細 R3 反映)。
    # 失敗時は起動済みの同 shard worker をその場で回収してから die (teardown 依存の残骸を減らす)
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

# 当該 shard の worker を全停止する (teardown / 起動失敗ロールバックの共通経路)。
# setsid 起動により pid==pgid のため process group 一括 kill (master + queue:work --once 子)。
# cmdline 照合 (worker_alive) 不一致/死亡済みの stale pidfile は kill せず削除のみ (誤 kill 防止優先)。
# 停止シーケンス: TERM → group 消滅待ち (最大 2s) → KILL escalation → 再確認。
# 成功条件は **process group 全体の消滅** (master 単体判定だと終了処理中の queue:work 子の
# DB 接続が残り dropdb と race する)。kill -0 -- -PGID は cmdline 照合済みの自所有 group への
# 存在確認で待機用途として安全。全 shard 横断の pgrep 判定はしない。
# ★ 消滅を確認できた group のみ pidfile を削除する。残留/検証不能の group の pidfile は保持し
#   (追跡可能性を失わない)、戻り値 1 で失敗を通知する (呼び出し側が dropdb を抑止する)。
# (Codex 詳細 R1/R2/R3/R4 反映)
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

# --- worktree 文脈ガード -------------------------------------------------------
# bug-hunt provision を worktree 外 (main checkout) から起動するのを in-script で fail-closed 拒否する。
assert_worktree_context() {
    is_dryrun && return 0
    if [[ -n "${BUGHUNT_ALLOW_MAIN:-}" ]]; then
        echo "warning: BUGHUNT_ALLOW_MAIN=1 で worktree 外 (main) 走行を許可。skill Phase 0a を意図的にスキップ — todo/ ブランチ隔離なし・main を直接汚す" >&2
        return 0
    fi
    local gd cgd
    gd="$(cd "${WORKSPACE}" 2>/dev/null && cd "$(git rev-parse --absolute-git-dir 2>/dev/null)" 2>/dev/null && pwd -P)" \
        || die 1 "worktree 判定不能: ${WORKSPACE} が git リポジトリでない。skill (app-bug-hunt) Phase 0a で worktree を切ってから走らせること"
    cgd="$(cd "${WORKSPACE}" 2>/dev/null && cd "$(git rev-parse --git-common-dir 2>/dev/null)" 2>/dev/null && pwd -P)" \
        || die 1 "worktree 判定不能: ${WORKSPACE} の git-common-dir を解決できない"
    if [[ "${gd}" == "${cgd}" ]]; then
        die 1 "bug-hunt を worktree 外 (main: ${WORKSPACE}) から起動しようとしています。\
skill (app-bug-hunt) の Phase 0a は worktree 既定です — main を直接汚さず todo/ ブランチに隔離するため。\
正しい起動: /app-bug-hunt 経由、または \`scripts/setup-worktree.sh bughunt-<date>\` で worktree を切り、その中で本スクリプトを実行する。\
意図的な main 走行 (--keep-db 連続再走など asset 既存の単発確認) のみ BUGHUNT_ALLOW_MAIN=1 を付ける。"
    fi
}

# --- provision ----------------------------------------------------------------

cmd_provision() {
    local shard=$1 run_id=$2
    require_orchestrator "provision"
    assert_worktree_context
    local db port url
    db="$(shard_db "${shard}")"; port="$(shard_port "${shard}")"; url="$(shard_url "${shard}")"
    mkdir -p "$(shard_report_dir "${shard}" "${run_id}")/screenshots" "${TMP_BASE}" \
        "$(shard_profile_dir "${shard}")" "$(shard_download_dir "${shard}")" "$(shard_trace_dir "${shard}")"

    if is_dryrun; then
        manifest_update "${run_id}" "${shard}" \
            "db=\"${db}\"" "port=${port}" "app_url=\"${url}\"" \
            "log_offset=0" "serve_pid=0" "stories=\"(dryrun)\"" \
            "coverage=$( [[ -n "${COVERAGE:-}" ]] && echo true || echo false )"
        generate_wrapper "${shard}" "${run_id}"
        return 0
    fi

    [[ -f "${ENV_FILE}" ]] || die 1 "${ENV_FILE} が無い。先に \`cp .env.bughunt.local.example .env.bughunt.local\` と \`APP_ENV=bughunt.local php artisan key:generate --env=bughunt.local\` を実行すること"
    command -v psql >/dev/null || die 1 "psql クライアントが無い (postgresql-client を導入すること)"

    env_file_required APP_KEY > /dev/null
    env_file_required DB_HOST > /dev/null
    env_file_required DB_PORT > /dev/null
    env_file_required BUGHUNT_ADMIN_USER > /dev/null
    [[ "$(env_file_get APP_ENV)" == "bughunt.local" ]] || die 1 "${ENV_FILE} の APP_ENV が bughunt.local でない"
    [[ "$(env_file_get DB_USERNAME)" == "bughunt" ]] || die 1 "${ENV_FILE} の DB_USERNAME は bughunt 固定"

    # モード env を構築し real-llm の実キーを fail-fast 検証する (createdb の前)。
    # build_mode_env (キー読取) → assert_llm_key_present (配列検査) の単一正本。
    prepare_mode_and_preflight

    clear_stale_config
    ensure_fresh_assets

    # (a) DB 作成 (admin 経路。既存なら skip。中身は次の migrate:fresh が正本)
    if ! pg_owner_for_shard exists "${db}" | grep -q 1; then
        pg_admin_for_provision createdb "${db}"
    fi

    # (b) migrate:fresh + seed (runtime 経路、自 DB のみ)。テンプレート共通シーダーのみ実行する
    #     (ドメイン固有シーダーはアプリ側で本ブロックに追記する)。
    artisan_for_shard "${db}" "${url}" migrate:fresh --seed --force
    artisan_for_shard "${db}" "${url}" db:seed --class=ManualTestSeeder --force
    # 有料プラン組織に active subscription + 初期チケットを付与 (三重ガード付き)。
    # free 組織は未契約のまま = 課金なし経路の探索能力を温存する。
    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntBillingSeeder --force
    # 管理画面 (Filament admin) 探索用 admin user。AdminUserSeeder は local 限定 (DatabaseSeeder が
    # local でしか呼ばない) のため bughunt では明示 seed する。admin MFA は .env.bughunt.local の
    # ADMIN_MFA_REQUIRED=false で無効化済 (email+password ログイン可)。
    artisan_for_shard "${db}" "${url}" db:seed --class=AdminUserSeeder --force
    # CLI OAuth client + CLI session + legacy MCP token を直付与 (fake_externals かつ bughunt.local かつ
    # bug_hunt DB の三重ガード付き。config('testing.fake_externals') 未導入なら seeder 側で no-op)。
    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntOAuthSeeder --force

    # (b2) Filament 静的アセット publish (F-13 対策)。冪等 (marker + 実在確認で skip)。
    ensure_filament_assets "${db}" "${url}"

    # (c) 実効 env 検証 (不一致 fail-fast)。serve/worker と同一フラグ (MODE_ENV) で config が
    #     解決されることを検証する (config key / env 名の typo を provision 段階で検出)。
    #     artisan_for_shard は migrate/seed と共用で MODE_ENV を載せないため、serve と同型の専用
    #     env -i ブロックで MODE_ENV (フラグのみ) を展開する。実キー (LLM_KEY_ENV) は verify に載せない。
    guard_bughunt_runtime "${db}" bughunt
    local effective
    effective="$(env -i PATH="${PATH}" HOME="${HOME}" \
        DB_CONNECTION=pgsql \
        DB_HOST="$(env_file_required DB_HOST)" DB_PORT="$(env_file_required DB_PORT)" \
        DB_DATABASE="${db}" DB_USERNAME=bughunt DB_PASSWORD="$(env_file_get DB_PASSWORD)" \
        APP_URL="${url}" \
        ${MODE_ENV[@]+"${MODE_ENV[@]}"} \
        php artisan tinker --execute='
        echo json_encode([
            "db" => config("database.connections.pgsql.database"),
            "app_url" => config("app.url"),
            "session" => config("session.driver"),
            "cache" => config("cache.default"),
            "queue" => config("queue.default"),
            "mail" => config("mail.default"),
            "filesystem" => config("filesystems.default"),
            "admin_mfa_required" => config("admin.mfa_required"),
            "fake_llm" => config("testing.fake_llm"),
            "fake_storage" => config("testing.fake_storage"),
        ]);' --env=bughunt.local | grep -o '{.*}' | tail -1)"
    EFFECTIVE="${effective}" DB="${db}" URL="${url}" LLM_MODE="${LLM_MODE}" STORAGE_MODE="${STORAGE_MODE}" python3 - <<'PY'
import json, os, sys
e = json.loads(os.environ["EFFECTIVE"])
expected = {
    "db": os.environ["DB"], "app_url": os.environ["URL"],
    "session": "database", "cache": "database", "queue": "sync",
    "mail": "log",
    "admin_mfa_required": False,
    # モードから期待値を導出 (serve/worker と同一フラグで config が解決されることを固定)。
    "fake_llm": (os.environ["LLM_MODE"] == "fake"),
    "fake_storage": (os.environ["STORAGE_MODE"] == "fake"),
}
# filesystem は fake_storage (既定) 時のみ local を必須化。real-storage は inert のため緩める。
if os.environ["STORAGE_MODE"] == "fake":
    expected["filesystem"] = "local"
diff = {k: (e.get(k), v) for k, v in expected.items() if e.get(k) != v}
if diff:
    print(f"error: 隔離前提の実効 env が不一致 (実効値, 期待値): {diff}", file=sys.stderr)
    sys.exit(1)
PY

    # (d) laravel.log の現在バイト数を offset として記録 (この run の時間境界)
    local offset
    offset="$(file_size storage/logs/laravel.log)"
    echo "${offset}" > "$(offset_file "${shard}" "${run_id}")"

    # (e-cov) --coverage モード (コード到達カバレッジ): pcov 付きで serve を起動し到達/未到達行を収集する。
    #   既定 (COVERAGE 未指定) は空配列 = serve コマンド完全不変 (回帰なし)。pcov 不在は warning + 続行。
    local -a coverage_env=()
    if [[ -n "${COVERAGE:-}" ]]; then
        coverage_env+=("BUGHUNT_PCOV=1" "BUGHUNT_PCOV_RUN=${run_id}" "BUGHUNT_PCOV_SHARD=${shard}")
        mkdir -p storage/bughunt-coverage
        if php -m 2>/dev/null | grep -qi '^pcov$'; then
            local scan_default pcov_ini_dir
            scan_default="$(php -i 2>/dev/null | sed -n 's/^Scan this dir for additional .ini files => //p')"
            pcov_ini_dir="$(pwd)/storage/bughunt-coverage/.pcov-ini-${shard}"
            mkdir -p "${pcov_ini_dir}"
            {
                echo "pcov.enabled=1"
                echo "pcov.directory=$(pwd)"
                echo 'pcov.exclude="~/(vendor|storage|bootstrap/cache)/~"'
            } > "${pcov_ini_dir}/zz-bughunt-pcov.ini"
            coverage_env+=("PHP_INI_SCAN_DIR=${scan_default}:${pcov_ini_dir}")
            echo "coverage: pcov enabled via PHP_INI_SCAN_DIR (出力 storage/bughunt-coverage/${run_id}-${shard}.json)" >&2
        else
            echo "warning: --coverage 指定だが pcov 拡張が無い — BUGHUNT_PCOV=1 のみ渡して続行 (middleware は no-op、実 coverage は出ない)" >&2
        fi
    fi

    # (e) serve 起動 + ヘルスチェック。--no-reload 必須 (ServeCommand が --env 時に
    #     passthrough 外の env を php -S 子から破棄する)。coverage_env は同じ env -i 行で明示展開する。
    # 秘密 (LLM_KEY_ENV) を展開するプロセス起動を xtrace ガードで挟む (-x 有効時も値を trace に出さない)。
    # coverage_env / MODE_ENV / LLM_KEY_ENV は同じ env -i 行で明示展開する (real-llm 時のみ実キーが載る)。
    secret_xtrace_off
    env -i PATH="${PATH}" HOME="${HOME}" \
        DB_CONNECTION=pgsql \
        DB_HOST="$(env_file_required DB_HOST)" DB_PORT="$(env_file_required DB_PORT)" \
        DB_DATABASE="${db}" DB_USERNAME=bughunt DB_PASSWORD="$(env_file_get DB_PASSWORD)" \
        APP_URL="${url}" \
        ${coverage_env[@]+"${coverage_env[@]}"} \
        ${MODE_ENV[@]+"${MODE_ENV[@]}"} ${LLM_KEY_ENV[@]+"${LLM_KEY_ENV[@]}"} \
        nohup php artisan serve --env=bughunt.local --port="${port}" --no-reload \
        > "${TMP_BASE}/serve-${shard}.log" 2>&1 &
    local serve_pid=$!
    secret_xtrace_restore
    echo "${serve_pid}" > "${TMP_BASE}/serve-${shard}.pid"
    manifest_update "${run_id}" "${shard}" "serve_pid=${serve_pid}" "port=${port}"
    local t code=000
    for t in $(seq 1 30); do
        code="$(curl -s -o /dev/null -w '%{http_code}' "${url}/login" || true)"
        [[ "${code}" == 200 || "${code}" == 302 ]] && break
        sleep 1
    done
    if [[ "${code}" != 200 && "${code}" != 302 ]]; then
        kill -TERM "${serve_pid}" 2>/dev/null || true
        die 1 "shard-${shard} serve (:${port}) が 30s で起動しない (last=${code}、${TMP_BASE}/serve-${shard}.log 参照)"
    fi

    # (e2) 専用 queue connection worker 起動 (F-01 対策。BUGHUNT_WORKER_CONNECTIONS 参照)
    start_shard_workers "${shard}" "${db}" "${url}"

    # (f) shard wrapper 生成
    generate_wrapper "${shard}" "${run_id}"

    # (g) manifest 記録 (worker pid = pgid。setsid により group 一括 kill の対象 id を兼ねる)。
    # key はハイフンを underscore に正規化 (shell 変数名として扱う消費側が現れても壊れないように)
    local -a worker_pid_entries=()
    local conn
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        worker_pid_entries+=("worker_pid_${conn//-/_}=$(cat "$(worker_pidfile "${shard}" "${conn}")")")
    done
    manifest_update "${run_id}" "${shard}" \
        "db=\"${db}\"" "port=${port}" "app_url=\"${url}\"" \
        "serve_pid=${serve_pid}" "log_offset=${offset}" \
        "${worker_pid_entries[@]}" \
        "started_at=\"$(TZ=Asia/Tokyo date '+%Y-%m-%d %H:%M:%S')\""
    echo "provisioned: shard ${shard} db=${db} url=${url} serve_pid=${serve_pid} workers=${#BUGHUNT_WORKER_CONNECTIONS[@]}"
}

# --- provision-all (fan-out 用の薄い導線。lock 保持で N shard を一括 provision) ----
cmd_provision_all() {
    local n=$1 hold=${2:-}
    require_orchestrator "provision-all"
    assert_worktree_context
    valid_parallel_n "${n}" || die 2 "--parallel は 2..${BUGHUNT_SHARD_CAP} の偶数のみ (固定ストーリーマップのため)"

    if [[ -z "${BUGHUNT_SANDBOX:-}" ]]; then
        mkdir -p "${WORKSPACE}/.claude"
    fi
    exec 222>"${LOCK_FILE}"
    if ! flock -n 222; then
        die 1 "別の bug-hunt run が実行中 (${LOCK_FILE})。完了を待つこと"
    fi

    # モード env / 実キーの fail-fast を run-id 採番・shard provision の前に共通で通す
    # (real-llm でキー欠落なら shard 1 に進む前に止める。dryrun はキー検証をスキップ)。
    is_dryrun || prepare_mode_and_preflight

    if [[ -n "${COVERAGE:-}" ]]; then
        echo "coverage: 全 ${n} shard の serve が pcov 付きで起動する (実装到達カバレッジ収集。pcov 不在なら no-op)" >&2
    fi

    local RUN_ID RUN_DIR
    RUN_ID="$(allocate_run_id)"
    RUN_DIR="$(run_dir "${RUN_ID}")"
    mkdir -p "${RUN_DIR}" "${TMP_BASE}"
    manifest_update "${RUN_ID}" - "parallel=${n}" "mode=fan-out" \
        "started_at=\"$(TZ=Asia/Tokyo date '+%Y-%m-%d %H:%M:%S')\""

    if ! is_dryrun; then
        php artisan optimize:clear > /dev/null
        ensure_fresh_assets
    fi

    local i
    for i in $(seq 1 "${n}"); do
        cmd_provision "${i}" "${RUN_ID}"
        manifest_update "${RUN_ID}" "${i}" "stories=\"$(stories_for_shard "${i}" "${n}")\""
    done

    echo "provisioned-all: run-id=${RUN_ID} parallel=${n} (manifest: $(manifest_path "${RUN_ID}"))"
    echo "run-id=${RUN_ID}"

    if [[ "${hold}" == "--hold-lock" ]] && ! is_dryrun; then
        echo "holding lock until stdin closes (run-id=${RUN_ID})..." >&2
        cat > /dev/null || true
    fi
}

# --- 子セッション safe subcommands ---------------------------------------------

cmd_reseed() {
    local shard=$1 run_id=$2
    local db url
    db="$(shard_db "${shard}")"; url="$(shard_url "${shard}")"
    artisan_for_shard "${db}" "${url}" migrate:fresh --seed --force
    artisan_for_shard "${db}" "${url}" db:seed --class=ManualTestSeeder --force
    # 有料プラン組織に active subscription + 初期チケットを付与 (三重ガード付き)。
    # free 組織は未契約のまま = 課金なし経路の探索能力を温存する。
    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntBillingSeeder --force
    artisan_for_shard "${db}" "${url}" db:seed --class=AdminUserSeeder --force
    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntOAuthSeeder --force
    echo "reseeded: ${db}"
}

cmd_db_check() {
    local shard=$1 run_id=$2
    local db url
    db="$(shard_db "${shard}")"; url="$(shard_url "${shard}")"
    echo "db: ${db}"
    artisan_for_shard "${db}" "${url}" tinker --execute='echo "users: ".\App\Models\User::count();'
}

cmd_db_exists() {
    local shard=$1 run_id=$2
    local db; db="$(shard_db "${shard}")"
    if pg_owner_for_shard exists "${db}" | grep -q 1; then
        echo "exists: ${db}"
    else
        echo "absent: ${db}"
    fi
}

cmd_mail_urls() {
    local shard=$1 run_id=$2 count=$3
    local offset port
    offset="$(cat "$(offset_file "${shard}" "${run_id}")" 2>/dev/null || echo 0)"
    port="$(shard_port "${shard}")"
    extract_mail_urls storage/logs/laravel.log "${offset}" "${port}" "${count}"
}

# --- teardown -----------------------------------------------------------------

cmd_teardown() {
    local run_id=$1 drop_db=${2:-}
    require_orchestrator "teardown"
    local shard pid port teardown_rc=0
    for shard in 0 1 2 3 4 5 6 7 8; do
        # 専用 connection worker の停止 (serve より先に止め、--drop-db 時の DB 接続残留を防ぐ)。
        # stop_shard_workers が cmdline 照合 → TERM → group 消滅待ち → KILL escalation →
        # 再確認まで行い、残留があれば pidfile を保持して非ゼロを返す (追跡可能性を失わない)。
        # 停止失敗 shard の dropdb は抑止する (接続保持した孤児 worker と dropdb の衝突防止)。
        local workers_stopped=1
        if ! stop_shard_workers "${shard}"; then
            workers_stopped=0
            teardown_rc=1
            echo "warning: shard-${shard} の worker 停止に失敗 — この shard の dropdb をスキップ (pidfile 保持)" >&2
        fi

        local pidfile="${TMP_BASE}/serve-${shard}.pid"
        if [[ -f "${pidfile}" ]]; then
            pid="$(cat "${pidfile}" 2>/dev/null || echo)"
            port="$(shard_port "${shard}")"
            if [[ -n "${pid}" && "${pid}" != 0 && -r "/proc/${pid}/cmdline" ]] \
                && tr '\0' ' ' < "/proc/${pid}/cmdline" | grep -q "artisan serve" \
                && tr '\0' ' ' < "/proc/${pid}/cmdline" | grep -q -- "--port=${port}"; then
                # 子 php -S worker を親より先に撃つ (親 kill で init に reparent され孤児化するのを防ぐ)。
                local wpid
                for wpid in $(pgrep -P "${pid}" 2>/dev/null || true); do
                    if [[ -r "/proc/${wpid}/cmdline" ]] \
                        && tr '\0' ' ' < "/proc/${wpid}/cmdline" | grep -q -- "-S " \
                        && tr '\0' ' ' < "/proc/${wpid}/cmdline" | grep -q -- ":${port}"; then
                        kill -TERM "${wpid}" 2>/dev/null || true
                    fi
                done
                kill -TERM "${pid}" 2>/dev/null || true
            fi
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

# 孤児ブラウザ回収: fan-out subagent が close 前に turn budget で落ちると常駐ブラウザが孤児化する。
# teardown で playwright-cli kill-all を撃って回収する (lock により同時 run は無いため全消しで安全)。
reap_orphan_browser() {
    is_dryrun && return 0
    command -v playwright-cli >/dev/null 2>&1 || return 0
    playwright-cli kill-all >/dev/null 2>&1 || true
    echo "reap_orphan_browser: playwright-cli kill-all (孤児ブラウザ回収)" >&2
}

# --- ストーリー割り当て (固定マップ) -------------------------------------------
# stories/ 配下の S1..S7 はテンプレートではスケルトン。アプリが route:list から生成する。
# S3↔S7 の状態依存を shard-1 に閉じ込める既定マップ。N は 2 と cap(=4) のみ。
stories_for_shard() {
    local shard=$1 n=$2
    case "${n}-${shard}" in
        4-1) echo "S3 S7" ;;
        4-2) echo "S1 S2" ;;
        4-3) echo "S4 S5" ;;
        4-4) echo "S6" ;;
        2-1) echo "S3 S7 S6" ;;
        2-2) echo "S1 S2 S4 S5" ;;
        *) die 1 "stories_for_shard: 未定義の組み合わせ N=${n} shard=${shard}" ;;
    esac
}

# --- self-test (実資源に触れない) ----------------------------------------------

cmd_self_test() {
    local sandbox failures=0
    sandbox="$(mktemp -d -t bug-hunt-selftest.XXXXXX)"
    export BUGHUNT_SANDBOX="${sandbox}"
    mkdir -p "${sandbox}/devnotes" "${sandbox}/tmp/bug-hunt"
    RUN_BASE="${sandbox}/devnotes"
    TMP_BASE="${sandbox}/tmp/bug-hunt"
    LOCK_FILE="${sandbox}/bug-hunt.lock"
    ENV_FILE="${sandbox}/.env.bughunt.local"
    # self-test は環境非依存であるべき (外部 env の BUGHUNT_DB_PREFIX に影響されない)。
    BUGHUNT_DB_PREFIX=bug_hunt
    SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-${BUGHUNT_SHARD_CAP}])?$"

    cat > "${ENV_FILE}" <<'ENVEOF'
APP_ENV=bughunt.local
DB_HOST=db
DB_PORT=5432
DB_USERNAME=bughunt
DB_PASSWORD=secret
BUGHUNT_ADMIN_USER=postgres
BUGHUNT_ADMIN_PASSWORD=adminsecret
ENVEOF

    t_fail() { echo "  FAIL: $*"; failures=$((failures + 1)); }
    t_ok() { echo "  ok: $*"; }
    expect_die() { local fn=$1; shift; local rc=0; ( "${fn}" "$@" ) >/dev/null 2>&1 || rc=$?; [[ "${rc}" == 1 ]]; }
    expect_ok() { local fn=$1; shift; local rc=0; ( "${fn}" "$@" ) >/dev/null 2>&1 || rc=$?; [[ "${rc}" == 0 ]]; }

    echo "[a] 資源導出"
    local rc
    # cap は 1 桁 (2..9) であることが文字クラス導出 ([0-${CAP}]) の前提。
    [[ "${BUGHUNT_SHARD_CAP}" =~ ^[2-9]$ ]] || t_fail "BUGHUNT_SHARD_CAP は 2..9 の 1 桁である必要がある (文字クラス導出の前提)"
    # 不正な BUGHUNT_DB_PREFIX では引数解析より前に die 1 する (SHARD_DB_RE への escape なし埋め込み対策)。
    rc=0; (BUGHUNT_DB_PREFIX='b.g_hunt' "${SCRIPT_PATH}" self-test) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 1 ]] || t_fail "不正な BUGHUNT_DB_PREFIX でスクリプトが起動してしまう (exit ${rc})"
    [[ "$(shard_db 0)" == "bug_hunt" ]] || t_fail "shard_db serial"
    [[ "$(shard_db 1)" == "bug_hunt_1" ]] || t_fail "shard_db"
    [[ "$(shard_db 4)" == "bug_hunt_4" ]] || t_fail "shard_db cap"
    [[ "$(shard_port 0)" == "8010" ]] || t_fail "shard_port serial"
    [[ "$(shard_port 4)" == "8014" ]] || t_fail "shard_port cap"
    [[ "$(shard_url 2)" == "http://127.0.0.1:8012" ]] || t_fail "shard_url"
    [[ "$(shard_profile_dir 1)" == "${TMP_BASE}/profile-1" ]] || t_fail "shard_profile_dir"
    [[ "$(shard_download_dir 1)" == "${TMP_BASE}/downloads-1" ]] || t_fail "shard_download_dir"
    [[ "$(shard_trace_dir 1)" == "${TMP_BASE}/trace-1" ]] || t_fail "shard_trace_dir"
    t_ok "derivations + per-shard resource uniqueness"

    echo "[b] 範囲外 shard の拒否 (exit 2、cap=${BUGHUNT_SHARD_CAP})"
    local bad good fp_before
    # ★ 5/8 = 旧 cap (=8) の残骸が通らないことを正のアサーションにする。
    for bad in 5 8 9 -1 x ""; do
        rc=0; (validate_shard "${bad}") 2>/dev/null || rc=$?
        [[ "${rc}" == 2 ]] || t_fail "shard '${bad}' が exit ${rc} (expected 2)"
    done
    for good in 0 1 4; do
        rc=0; (validate_shard "${good}") 2>/dev/null || rc=$?
        [[ "${rc}" == 0 ]] || t_fail "shard ${good} が拒否された"
    done
    t_ok "shard validation"

    echo "[c] guard_shard_db_name: dev DB / 別名バリアント / cap 超過は全 abort、bug_hunt_1..4 は通過"
    local v
    for v in app App ' app ' 'app ' bug_huntx bug_hunt2 bug_hunt_5 bug_hunt_8 bug_hunt_9 'bug_hunt;rm' myapp_dev ''; do
        expect_die guard_shard_db_name "${v}" || t_fail "guard_shard_db_name が '${v}' を abort しない"
    done
    for v in bug_hunt bug_hunt_1 bug_hunt_4; do
        expect_ok guard_shard_db_name "${v}" || t_fail "guard_shard_db_name が '${v}' を拒否"
    done
    t_ok "shard db name deny"

    echo "[d] guard_bughunt_runtime: user≠bughunt / DB名不正で abort、正常で通過"
    expect_ok guard_bughunt_runtime bug_hunt bughunt || t_fail "正常 runtime が拒否された"
    expect_ok guard_bughunt_runtime bug_hunt_2 bughunt || t_fail "shard runtime が拒否された"
    expect_die guard_bughunt_runtime bug_hunt postgres || t_fail "user≠bughunt が通過"
    expect_die guard_bughunt_runtime myapp_dev bughunt || t_fail "dev DB名が通過"
    t_ok "runtime guard"

    echo "[e] guard_admin_provision: admin_user 未設定で abort、設定済み+bug_hunt で通過、dev DB名で abort"
    expect_ok guard_admin_provision bug_hunt postgres || t_fail "正常 admin が拒否された"
    expect_die guard_admin_provision bug_hunt "" || t_fail "admin_user 未設定が通過"
    expect_die guard_admin_provision myapp_dev postgres || t_fail "dev DB名が通過"
    t_ok "admin provision guard"

    echo "[e2] orchestrator gate (B-HARNESS-01): provision/teardown は親専用、worker は default-deny"
    rc=0; ( export BUGHUNT_SELFTEST_DRYRUN=1; unset BUGHUNT_ORCHESTRATOR; require_orchestrator "provision" ) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "dryrun 中に orchestrator gate が誤発火 (rc=${rc})"
    rc=0; ( unset BUGHUNT_SELFTEST_DRYRUN; unset BUGHUNT_ORCHESTRATOR; require_orchestrator "provision" ) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 1 ]] || t_fail "worker (token無し) で gate が die しない (rc=${rc})"
    rc=0; ( unset BUGHUNT_SELFTEST_DRYRUN; export BUGHUNT_ORCHESTRATOR=1; require_orchestrator "teardown" ) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "親 (token有り) で gate が通過しない (rc=${rc})"
    t_ok "orchestrator gate (provision/provision-all/teardown は親専用)"

    echo "[f] createdb 実行コマンドに OWNER bughunt が含まれる"
    local createdb_cmd
    createdb_cmd="$(declare -f pg_admin_for_provision)"
    echo "${createdb_cmd}" | grep -q 'createdb -O bughunt' \
        || t_fail "createdb に OWNER bughunt (-O bughunt) が無い"
    t_ok "createdb OWNER bughunt"

    echo "[g] 悪性 env 注入 smoke: PGDATABASE / DB_DATABASE を撒いても dev に到達しない"
    rc=0; ( export PGDATABASE=myapp_dev PGHOST=evil DATABASE_URL='pgsql://x/myapp_dev' DB_DATABASE=myapp_dev
            guard_bughunt_runtime myapp_dev bughunt ) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 1 ]] || t_fail "悪性 env 下で guard_bughunt_runtime が dev DB を abort しない (rc=${rc})"
    rc=0; ( export PGDATABASE=myapp_dev DB_DATABASE=myapp_dev
            guard_admin_provision myapp_dev postgres ) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 1 ]] || t_fail "悪性 env 下で guard_admin_provision が dev DB を abort しない (rc=${rc})"
    rc=0; ( export PGDATABASE=myapp_dev DB_DATABASE=myapp_dev
            guard_bughunt_runtime bug_hunt bughunt ) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "悪性 env 下で正常 bug_hunt が誤って abort (rc=${rc})"
    t_ok "malicious env injection blocked at guard"

    echo "[h] env_file_get: 後置コメント除去 / 欠損キーは空"
    [[ "$(env_file_get DB_HOST)" == "db" ]] || t_fail "DB_HOST 読み取り失敗"
    [[ -z "$(env_file_get NOPE)" ]] || t_fail "欠損キーが空にならない"
    t_ok "env parsing"

    echo "[i] mail-urls 二重フィルタ"
    local fixture="${sandbox}/laravel.log"
    echo 'old http://127.0.0.1:8010/verify/OLD' > "${fixture}"
    local off; off="$(file_size "${fixture}")"
    {
        echo 'mine http://127.0.0.1:8010/verify/MINE?sig=a'
        echo 'other http://127.0.0.1:8011/verify/OTHER'
        echo 'mine2 http://127.0.0.1:8010/reset/MINE2'
    } >> "${fixture}"
    local urls; urls="$(extract_mail_urls "${fixture}" "${off}" 8010 5)"
    [[ "$(echo "${urls}" | grep -c MINE)" == 2 ]] || t_fail "自シャード URL が 2 件取れない: ${urls}"
    echo "${urls}" | grep -q OLD && t_fail "offset 前の URL が混入"
    echo "${urls}" | grep -q OTHER && t_fail "他シャードの URL が混入"
    t_ok "dual filter"

    echo "[j] wrapper の封じ込め"
    generate_wrapper 0 20260615-150000
    local wp; wp="$(wrapper_path 0)"
    local badcmd
    for badcmd in teardown provision run self-test '' foo; do
        rc=0; "${wp}" ${badcmd:+"${badcmd}"} >/dev/null 2>&1 || rc=$?
        [[ "${rc}" == 2 ]] || t_fail "wrapper が '${badcmd}' を exit ${rc} で通した (expected 2)"
    done
    rc=0; "${wp}" db-check --shard 2 >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 2 ]] || t_fail "wrapper が shard 上書き引数を通した (exit ${rc})"
    rc=0; "${wp}" mail-urls --count xx >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 2 ]] || t_fail "wrapper が不正 --count を通した (exit ${rc})"
    t_ok "wrapper containment"

    echo "[k] run-id 入力検証"
    for bad in '../evil' '20260615' '' 'x;rm' '20260615-150000-'; do
        rc=0; (validate_run_id "${bad}") 2>/dev/null || rc=$?
        [[ "${rc}" == 2 ]] || t_fail "run-id '${bad}' が exit ${rc} (expected 2)"
    done
    for good in '20260615-150000' '20260615-150000-2'; do
        rc=0; (validate_run_id "${good}") 2>/dev/null || rc=$?
        [[ "${rc}" == 0 ]] || t_fail "run-id '${good}' が拒否された"
    done
    t_ok "run-id validation"

    echo "[l] run-id 一意化採番 + manifest 書式"
    mkdir -p "$(run_dir 20990101-000000)" "$(run_dir 20990101-000000-2)"
    [[ "$(allocate_run_id 20990101-000000)" == "20990101-000000-3" ]] || t_fail "suffix が進まない"
    manifest_update 20260615-150000 1 'db="bug_hunt_1"' 'port=8011' 'log_offset=0' 'stories="S1"'
    manifest_check "$(manifest_path 20260615-150000)" >/dev/null 2>&1 || t_fail "manifest_check"
    [[ "$(manifest_get 20260615-150000 1 db)" == "bug_hunt_1" ]] || t_fail "manifest_get db"
    [[ "$(manifest_get 20260615-150000 1 port)" == "8011" ]] || t_fail "manifest_get port"
    [[ -z "$(manifest_get 20260615-150000 1 nope)" ]] || t_fail "manifest_get 欠損キーが空でない"
    t_ok "run-id allocation + manifest schema"

    echo "[m] stories_for_shard 固定マップ (受理される全 N で S1..S7 を網羅 / 未定義は abort、cap=${BUGHUNT_SHARD_CAP})"
    [[ "$(stories_for_shard 1 4)" == "S3 S7" ]] || t_fail "4-1 map"
    [[ "$(stories_for_shard 4 4)" == "S6" ]] || t_fail "4-4 map"
    [[ "$(stories_for_shard 1 2)" == "S3 S7 S6" ]] || t_fail "2-1 map"
    local s mapped n2
    for n2 in $(seq 2 "${BUGHUNT_SHARD_CAP}"); do
        valid_parallel_n "${n2}" || continue
        mapped="$(for s in $(seq 1 "${n2}"); do stories_for_shard "${s}" "${n2}"; done | tr ' ' '\n' | sort -u | tr '\n' ' ')"
        [[ "${mapped}" == "S1 S2 S3 S4 S5 S6 S7 " ]] || t_fail "N=${n2} の story union が S1..S7 でない: '${mapped}'"
    done
    # ★ 旧 cap (=8) の残骸と奇数 N がマップから消えていること (die 1) を正のアサーションにする。
    for n2 in 3 6 8; do
        rc=0; (stories_for_shard 1 "${n2}") 2>/dev/null || rc=$?
        [[ "${rc}" == 1 ]] || t_fail "未定義 N=${n2} が abort しない (exit ${rc})"
    done
    t_ok "stories map (受理される全 N = 2..${BUGHUNT_SHARD_CAP} の偶数)"

    echo "[n] manifest_valid_shards: 改ざん key (空白/トラバーサル) を除外"
    local tamper_mf; tamper_mf="$(manifest_path 20260615-160000)"
    mkdir -p "$(run_dir 20260615-160000)"
    cat > "${tamper_mf}" <<'MFEOF'
{"run_id": "20260615-160000", "shards": {"1": {"serve_pid": 0, "port": 8011},
 "1 2": {"serve_pid": 0, "port": 8011}, "../x": {"serve_pid": 0, "port": 8011}}}
MFEOF
    local valid_keys; valid_keys="$(manifest_valid_shards 20260615-160000 2>/dev/null)"
    [[ "${valid_keys}" == "1" ]] || t_fail "不正 key が valid 扱い: '${valid_keys}'"
    t_ok "manifest shard key tamper resistance"

    # provision-all は flock 依存 (Linux util-linux)。flock 不在環境 (macOS 素) では lock 系 [r]/[s] を skip する。
    local have_flock=1
    command -v flock >/dev/null 2>&1 || have_flock=0

    if [[ "${have_flock}" == 0 ]]; then
        echo "[r][s] provision-all/lock (SKIP: flock 不在。Linux devcontainer では実行される)"
    else
    echo "[r] provision-all (dryrun): run-id 採番 + shard 1..N provision + stories 記録 + run-id 印字"
    export BUGHUNT_SELFTEST_DRYRUN=1
    local pa_log="${sandbox}/provision-all.log"
    rc=0; ("${SCRIPT_PATH}" provision-all --parallel=2) > "${pa_log}" 2>&1 || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "provision-all --parallel=2 (dryrun) が exit ${rc} (expected 0)"
    grep -q '^run-id=' "${pa_log}" || t_fail "provision-all が run-id= を印字しない"
    local pa_run_id; pa_run_id="$(grep '^run-id=' "${pa_log}" | head -1 | cut -d= -f2)"
    [[ -n "${pa_run_id}" ]] || t_fail "provision-all の run-id 抽出に失敗"
    [[ "$(manifest_get "${pa_run_id}" - parallel)" == "2" ]] || t_fail "provision-all: manifest parallel≠2"
    [[ "$(manifest_get "${pa_run_id}" 1 stories)" == "S3 S7 S6" ]] || t_fail "provision-all: shard-1 stories 未記録"
    [[ "$(manifest_get "${pa_run_id}" 2 stories)" == "S1 S2 S4 S5" ]] || t_fail "provision-all: shard-2 stories 未記録"
    [[ ! -f "$(run_dir "${pa_run_id}")/child-pids" ]] || t_fail "provision-all が子を起動している (child-pids 検出)"
    local pa4_log="${sandbox}/provision-all-4.log"
    rc=0; ("${SCRIPT_PATH}" provision-all --parallel=4) > "${pa4_log}" 2>&1 || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "provision-all --parallel=4 (dryrun) が exit ${rc} (expected 0、cap=${BUGHUNT_SHARD_CAP})"
    local pa4_run_id; pa4_run_id="$(sed -n 's/^run-id=//p' "${pa4_log}" | head -1)"
    [[ "$(manifest_get "${pa4_run_id}" - parallel)" == "4" ]] || t_fail "provision-all --parallel=4: manifest parallel≠4"
    [[ "$(manifest_get "${pa4_run_id}" 4 stories)" == "S6" ]] || t_fail "provision-all --parallel=4: shard-4 stories 未記録"

    # ★ 旧 cap の 6/8 が拒否されることが本施策の核。奇数・範囲外も併せて固定する。
    local bad_n
    for bad_n in 3 5 6 8 0 1; do
        rc=0; ("${SCRIPT_PATH}" provision-all "--parallel=${bad_n}") >/dev/null 2>&1 || rc=$?
        [[ "${rc}" == 2 ]] || t_fail "provision-all --parallel=${bad_n} が exit ${rc} (expected 2)"
    done

    # 受理される N すべてに完全なストーリーマップがあること (受理集合とマップのずれ検出)。
    # ★ stories_for_shard は未定義時 die 1 する。command substitution の失敗が set -e で
    #   self-test 全体を殺さないよう、rc を分離して受ける。
    local n i stories srv_rc
    for n in $(seq 2 "${BUGHUNT_SHARD_CAP}"); do
        valid_parallel_n "${n}" || continue
        for i in $(seq 1 "${n}"); do
            srv_rc=0
            stories="$(stories_for_shard "${i}" "${n}")" || srv_rc=$?
            [[ "${srv_rc}" == 0 && -n "${stories}" ]] \
                || t_fail "stories_for_shard 未定義: N=${n} shard=${i} (rc=${srv_rc})"
        done
    done
    unset BUGHUNT_SELFTEST_DRYRUN
    t_ok "provision-all dryrun (cap=${BUGHUNT_SHARD_CAP} 受理 / 旧 cap の 6・8 と奇数 N を拒否 / story map 完全)"

    echo "[s] provision-all は lock 排他 (保持中の lock 下では exit 1)"
    (
        exec 223>"${LOCK_FILE}"
        flock -n 223
        sleep 2
    ) &
    local pa_lock_holder=$!
    sleep 0.3
    export BUGHUNT_SELFTEST_DRYRUN=1
    rc=0; ("${SCRIPT_PATH}" provision-all --parallel=2) >/dev/null 2>&1 || rc=$?
    unset BUGHUNT_SELFTEST_DRYRUN
    [[ "${rc}" == 1 ]] || t_fail "保持中 lock 下で provision-all が exit ${rc} (expected 1)"
    wait "${pa_lock_holder}" || true
    t_ok "provision-all lock 排他"

    echo "[t] verify-run: 不在 / 実質空 / 実質ありの 3 判定 (空/骨子のみは欠落=3)"
    local vr_run_id="${pa_run_id}"
    mkdir -p "$(shard_report_dir 1 "${vr_run_id}")" "$(shard_report_dir 2 "${vr_run_id}")"
    printf '# report\n\n## Findings\n' > "$(shard_report_dir 1 "${vr_run_id}")/shard-report.md"
    { for k in $(seq 1 14); do echo "line ${k}: finding F-2-${k} に関する実走行の記録"; done; } \
        > "$(shard_report_dir 2 "${vr_run_id}")/shard-report.md"
    rc=0; ("${SCRIPT_PATH}" verify-run --run-id "${vr_run_id}") >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 3 ]] || t_fail "verify-run: 実質空 shard を含むのに exit ${rc} (expected 3)"
    [[ "$(manifest_get "${vr_run_id}" 1 report_substantive)" == "False" || "$(manifest_get "${vr_run_id}" 1 report_substantive)" == "false" ]] \
        || t_fail "verify-run: shard-1 が report_substantive=false にならない"
    [[ "$(manifest_get "${vr_run_id}" 2 report_substantive)" == "True" || "$(manifest_get "${vr_run_id}" 2 report_substantive)" == "true" ]] \
        || t_fail "verify-run: shard-2 が report_substantive=true にならない"
    { for k in $(seq 1 14); do echo "line ${k}: shard-1 finding F-1-${k} の実走行記録"; done; } \
        > "$(shard_report_dir 1 "${vr_run_id}")/shard-report.md"
    rc=0; ("${SCRIPT_PATH}" verify-run --run-id "${vr_run_id}") >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "verify-run: 両 shard 実質ありなのに exit ${rc} (expected 0)"
    t_ok "verify-run 空レポート検出"
    fi  # have_flock (provision-all/lock/verify-run 系)

    echo "[w] assert_worktree_context: override / dryrun 素通り + teardown 子 worker kill 配線"
    rc=0; ( export BUGHUNT_ALLOW_MAIN=1; unset BUGHUNT_SELFTEST_DRYRUN; assert_worktree_context ) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "assert_worktree_context: BUGHUNT_ALLOW_MAIN=1 で exit ${rc} (expected 0)"
    rc=0; ( unset BUGHUNT_ALLOW_MAIN; export BUGHUNT_SELFTEST_DRYRUN=1; assert_worktree_context ) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "assert_worktree_context: dryrun で exit ${rc} (expected 0)"
    unset BUGHUNT_SELFTEST_DRYRUN
    local td_def wkill_ln pkill_ln
    td_def="$(declare -f cmd_teardown)"
    echo "${td_def}" | grep -q 'pgrep -P' \
        || t_fail "cmd_teardown に子 worker kill (pgrep -P) 配線が無い"
    wkill_ln="$(echo "${td_def}" | grep -n 'pgrep -P' | head -1 | cut -d: -f1)"
    pkill_ln="$(echo "${td_def}" | grep -n 'kill -TERM "\${pid}"' | head -1 | cut -d: -f1)"
    if [[ -n "${wkill_ln}" && -n "${pkill_ln}" ]]; then
        [[ "${wkill_ln}" -lt "${pkill_ln}" ]] \
            || t_fail "cmd_teardown: 子 worker kill (line ${wkill_ln}) が親 kill (line ${pkill_ln}) より後 = 孤児化リスク"
    else
        t_fail "cmd_teardown: 子/親 kill 行を特定できない"
    fi
    t_ok "assert_worktree_context 素通り + teardown 子→親 kill 順序"

    echo "[u] reap_orphan_browser は dryrun では no-op"
    export BUGHUNT_SELFTEST_DRYRUN=1
    rc=0; reap_orphan_browser || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "reap_orphan_browser dryrun が exit ${rc} (expected 0、no-op)"
    unset BUGHUNT_SELFTEST_DRYRUN
    t_ok "reap_orphan_browser dryrun no-op"

    echo "[v] asset freshness guard (content fingerprint + manifest chunk 実在)"
    local fg_root="${sandbox}/freshguard"
    local fg_bin="${fg_root}/bin"
    mkdir -p "${fg_root}/resources" "${fg_root}/public/build/assets" "${fg_bin}"
    echo 'console.log("app")' > "${fg_root}/resources/app.ts"
    echo '{"name":"fg"}' > "${fg_root}/package.json"
    cat > "${fg_bin}/pnpm" <<'PNPMEOF'
#!/usr/bin/env bash
if [[ "${1:-}" == "build" ]]; then
    mkdir -p public/build/assets
    printf '%s' "called" >> public/build/.pnpm-build-called
    cat > public/build/manifest.json <<'MFEOF'
{"resources/app.ts":{"file":"assets/app-fresh.js","isEntry":true}}
MFEOF
    : > public/build/assets/app-fresh.js
fi
exit 0
PNPMEOF
    chmod +x "${fg_bin}/pnpm"

    fg_run() { ( cd "${fg_root}" && PATH="${fg_bin}:${PATH}" "$@" ); }
    fg_build_called() { [[ -f "${fg_root}/public/build/.pnpm-build-called" ]]; }
    fg_reset_marker() { rm -f "${fg_root}/public/build/.pnpm-build-called"; }
    # keepdb-check は worker 生存確認 (worker_alive) を含む (施策 4)。self-test は実 worker を
    # 起動しないため、worker_alive を subshell ローカルに stub して assets/serve 判定を回帰させる
    # (本体コードに seam は持ち込まない。stub は本体・親プロセスへ影響しない)。
    fg_run_keepdb_ok() {
        ( cd "${fg_root}" || exit 1
          PATH="${fg_bin}:${PATH}"
          worker_alive() { return 0; }
          cmd_keepdb_check "$@" )
    }
    fg_run_keepdb_dead() {
        ( cd "${fg_root}" || exit 1
          PATH="${fg_bin}:${PATH}"
          worker_alive() { return 1; }
          cmd_keepdb_check "$@" )
    }
    fg_make_fresh() {
        mkdir -p "${fg_root}/public/build/assets"
        cat > "${fg_root}/public/build/manifest.json" <<'MFEOF'
{"resources/app.ts":{"file":"assets/app-fresh.js","isEntry":true}}
MFEOF
        : > "${fg_root}/public/build/assets/app-fresh.js"
        fg_run compute_build_fingerprint > "${fg_root}/public/build/.bughunt-build-fingerprint"
    }

    fg_make_fresh
    rm -f "${fg_root}/public/build/.bughunt-build-fingerprint"
    fg_reset_marker
    fg_run ensure_fresh_assets >/dev/null 2>&1
    fg_build_called || t_fail "fingerprint 不在で rebuild されない"

    fg_make_fresh
    echo "stale-hash-0000" > "${fg_root}/public/build/.bughunt-build-fingerprint"
    fg_reset_marker
    fg_run ensure_fresh_assets >/dev/null 2>&1
    fg_build_called || t_fail "fingerprint 不一致で rebuild されない"

    fg_make_fresh
    rm -f "${fg_root}/public/build/assets/app-fresh.js"
    fg_reset_marker
    fg_run manifest_chunks_present && t_fail "chunk 欠落で manifest_chunks_present が 0 を返す"
    fg_run ensure_fresh_assets >/dev/null 2>&1
    fg_build_called || t_fail "chunk 欠落で rebuild されない"

    fg_make_fresh
    fg_reset_marker
    fg_run ensure_fresh_assets >/dev/null 2>&1
    fg_build_called && t_fail "全一致なのに rebuild された (build skip すべき)"
    [[ -s "${fg_root}/public/build/.bughunt-build-fingerprint" ]] || t_fail "skip 後に fingerprint が消えた"

    mkdir -p "${fg_root}/public/build/assets"
    cat > "${fg_root}/public/build/manifest.json" <<'MFEOF'
{"a.ts":{"file":"assets/a.js","imports":["b.ts"]},
 "b.ts":{"file":"assets/b.js","imports":["a.ts"]}}
MFEOF
    : > "${fg_root}/public/build/assets/a.js"
    : > "${fg_root}/public/build/assets/b.js"
    fg_run manifest_chunks_present || t_fail "循環 imports + 全 chunk 実在で manifest_chunks_present が非0"

    cat > "${fg_root}/public/build/manifest.json" <<'MFEOF'
{"a.ts":{"file":"assets/a.js","imports":["ghost.ts"]}}
MFEOF
    : > "${fg_root}/public/build/assets/a.js"
    fg_run manifest_chunks_present && t_fail "dangling ref で manifest_chunks_present が 0 (fail-closed すべき)"

    fg_make_fresh
    : > "${fg_root}/public/hot"
    fg_reset_marker
    local hot_out; hot_out="$(fg_run ensure_fresh_assets 2>&1)"
    echo "${hot_out}" | grep -q 'removing public/hot' || t_fail "hot 除去ログが出ない"
    [[ ! -e "${fg_root}/public/hot" ]] || t_fail "public/hot が除去されない"

    fg_make_fresh
    fg_run assets_are_stale && t_fail "fresh なのに assets_are_stale が stale(0) を返す"
    echo "stale-hash-0000" > "${fg_root}/public/build/.bughunt-build-fingerprint"
    fg_run assets_are_stale || t_fail "fingerprint 不一致で assets_are_stale が fresh(1) を返す"
    fg_make_fresh; rm -f "${fg_root}/public/build/manifest.json"
    fg_run assets_are_stale || t_fail "manifest 欠落で assets_are_stale が fresh(1) を返す"

    fg_make_fresh; fg_reset_marker
    fg_run cmd_assets_check >/dev/null 2>&1 || t_fail "fresh で assets-check が非0"
    fg_build_called && t_fail "assets-check が pnpm build を呼んだ (read-only 違反)"
    fg_make_fresh; echo "stale-hash-0000" > "${fg_root}/public/build/.bughunt-build-fingerprint"; fg_reset_marker
    fp_before="$(cat "${fg_root}/public/build/.bughunt-build-fingerprint")"
    rc=0; fg_run cmd_assets_check >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 1 ]] || t_fail "stale で assets-check が exit ${rc} (expected 1)"
    fg_build_called && t_fail "stale assets-check が pnpm build を呼んだ (read-only 違反)"
    [[ "$(cat "${fg_root}/public/build/.bughunt-build-fingerprint")" == "${fp_before}" ]] \
        || t_fail "assets-check が fingerprint を書き換えた (read-only 違反)"
    fg_make_fresh; : > "${fg_root}/public/hot"; fg_reset_marker
    rc=0; fg_run cmd_assets_check >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 1 ]] || t_fail "public/hot 存在で assets-check が exit ${rc} (expected 1)"
    [[ -e "${fg_root}/public/hot" ]] || t_fail "assets-check が public/hot を削除した (read-only 違反)"
    rm -f "${fg_root}/public/hot"

    rc=0; ("${SCRIPT_PATH}" assets-check) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == "0" || "${rc}" == "1" ]] || t_fail "assets-check dispatch が exit ${rc} (expected 0 or 1)"

    cat > "${fg_bin}/curl" <<'CURLEOF'
#!/usr/bin/env bash
printf '%s' "called" >> "${FG_CURL_MARKER:-/dev/null}"
echo "200"
exit 0
CURLEOF
    chmod +x "${fg_bin}/curl"
    fg_make_fresh
    echo "stale-hash-0000" > "${fg_root}/public/build/.bughunt-build-fingerprint"
    fg_reset_marker
    rm -f "${fg_root}/curl-called"
    rc=0; FG_CURL_MARKER="${fg_root}/curl-called" fg_run_keepdb_ok 0 >/dev/null 2>&1 || rc=$?
    [[ "${rc}" != "0" ]] || t_fail "keepdb-check が stale assets で通過した (freshness ゲート不発)"
    fg_build_called && t_fail "keepdb-check が pnpm build を呼んだ (read-only 違反)"
    [[ ! -f "${fg_root}/curl-called" ]] || t_fail "keepdb-check が stale でも curl(liveness) に到達した (freshness 非先行)"

    fg_make_fresh; fg_reset_marker; rm -f "${fg_root}/curl-called"
    rc=0; FG_CURL_MARKER="${fg_root}/curl-called" fg_run_keepdb_ok 0 >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == "0" ]] || t_fail "keepdb-check が fresh+serve200 で exit ${rc} (expected 0)"
    [[ -f "${fg_root}/curl-called" ]] || t_fail "keepdb-check が fresh で liveness(curl) に到達しない"

    # worker 死亡 (worker_alive=false) は assets fresh + serve 200 でも exit≠0 (worker 検査が後段に実在)
    fg_make_fresh; fg_reset_marker; rm -f "${fg_root}/curl-called"
    rc=0; FG_CURL_MARKER="${fg_root}/curl-called" fg_run_keepdb_dead 0 >/dev/null 2>&1 || rc=$?
    [[ "${rc}" != "0" ]] || t_fail "keepdb-check が worker 死亡でも通過した (F-01 再発の見逃し)"
    [[ -f "${fg_root}/curl-called" ]] || t_fail "keepdb-check の worker 検査が serve(curl) 検査より前に来ている (後段であるべき)"

    t_ok "asset freshness guard (fingerprint/chunk/cycle/dangling/hot/writeback + assets-check/keepdb-check + worker liveness)"

    echo "[x] --coverage: provision/provision-all で受理 + フラグ解釈 + 既定不変 + サブコマンド制限"
    export BUGHUNT_SELFTEST_DRYRUN=1
    rc=0; ("${SCRIPT_PATH}" provision --shard 0 --run-id 20990201-000000 --coverage) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "provision --shard 0 --coverage (dryrun) が exit ${rc} (expected 0)"
    [[ "$(manifest_get 20990201-000000 0 coverage)" == "True" ]] \
        || t_fail "provision --shard 0 --coverage が manifest に coverage=true を残さない"
    rc=0; ("${SCRIPT_PATH}" provision --shard 0 --run-id 20990202-000000) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "provision (no --coverage, dryrun) が exit ${rc} (expected 0)"
    [[ "$(manifest_get 20990202-000000 0 coverage)" == "False" ]] \
        || t_fail "--coverage 未指定で coverage=false にならない (既定 OFF 破れ)"
    if [[ "${have_flock}" == 1 ]]; then
        rc=0; ("${SCRIPT_PATH}" provision-all --parallel=2 --coverage) > "${sandbox}/cov-pa.log" 2>&1 || rc=$?
        [[ "${rc}" == 0 ]] || t_fail "provision-all --parallel=2 --coverage (dryrun) が exit ${rc} (expected 0)"
        grep -q '^run-id=' "${sandbox}/cov-pa.log" || t_fail "provision-all --coverage が run-id= を印字しない"
        local cov_run_id; cov_run_id="$(sed -n 's/^run-id=//p' "${sandbox}/cov-pa.log" | head -1)"
        [[ "$(manifest_get "${cov_run_id}" 1 coverage)" == "True" ]] || t_fail "provision-all --coverage: shard-1 に未伝播"
        [[ "$(manifest_get "${cov_run_id}" 2 coverage)" == "True" ]] || t_fail "provision-all --coverage: shard-2 に未伝播"
    fi
    unset BUGHUNT_SELFTEST_DRYRUN
    for badsub in teardown reseed db-check verify-run self-test; do
        rc=0; ("${SCRIPT_PATH}" "${badsub}" --run-id 20990202-000000 --coverage) >/dev/null 2>&1 || rc=$?
        [[ "${rc}" == 2 ]] || t_fail "--coverage が ${badsub} で exit ${rc} (expected 2 = 拒否)"
    done
    t_ok "coverage flag interpretation + acceptance + default-unchanged"

    echo "[y] queue worker 配線 (F-01 対策): 導出 / 構造 / drift / dryrun 不起動 / stop 機能"
    # (y1) pidfile/logfile 導出
    [[ "$(worker_pidfile 3 database-analysis)" == "${TMP_BASE}/worker-3-database-analysis.pid" ]] \
        || t_fail "worker_pidfile 導出"
    [[ "$(worker_logfile 0 database-render)" == "${TMP_BASE}/worker-0-database-render.log" ]] \
        || t_fail "worker_logfile 導出"

    # (y2) config/queue.php との drift check (PHP 実評価。grep でなく実 config を読む)
    local expected_conns actual_conns
    expected_conns="$(cd "${WORKSPACE}" && php -r '
        require "vendor/autoload.php";
        $cfg = require "config/queue.php";
        $names = [];
        foreach ($cfg["connections"] as $name => $conn) {
            if (($conn["driver"] ?? "") === "database" && $name !== "database") { $names[] = $name; }
        }
        sort($names);
        echo implode(" ", $names);
    ' 2>/dev/null || echo "__php_failed__")"
    actual_conns="$(printf '%s\n' "${BUGHUNT_WORKER_CONNECTIONS[@]}" | sort | tr '\n' ' ' | sed 's/ $//')"
    if [[ "${expected_conns}" == "__php_failed__" ]]; then
        t_fail "drift check 実行不能: vendor/autoload.php または config/queue.php を PHP 評価できない (依存未導入なら composer install 後に再実行)"
    elif [[ "${expected_conns}" != "${actual_conns}" ]]; then
        t_fail "drift: config/queue.php の専用 connection (${expected_conns}) と BUGHUNT_WORKER_CONNECTIONS (${actual_conns}) が不一致"
    fi

    # (y3) 構造検査 (既存 [w] と同じ流儀): provision → start_shard_workers → setsid/queue:listen、
    #      teardown → stop_shard_workers が serve kill より前、旧 `|| continue` の復活防止
    local prov_def wrk_def stopw_def td2_def
    prov_def="$(declare -f cmd_provision)"
    echo "${prov_def}" | grep -q 'start_shard_workers' || t_fail "cmd_provision に worker 起動配線が無い"
    wrk_def="$(declare -f start_shard_workers)"
    echo "${wrk_def}" | grep -q 'setsid php artisan queue:listen' || t_fail "start_shard_workers が setsid + queue:listen でない"
    echo "${wrk_def}" | grep -q 'guard_bughunt_runtime' || t_fail "start_shard_workers が guard を通していない"
    echo "${wrk_def}" | grep -q 'env -i' || t_fail "start_shard_workers が env -i 隔離でない"
    echo "${wrk_def}" | grep -q 'stop_shard_workers' || t_fail "start_shard_workers に起動失敗ロールバックが無い"
    echo "${wrk_def}" | grep -q 'ps -o pgid=' || t_fail "start_shard_workers に pid==pgid (setsid 成立) 検証が無い"
    echo "${prov_def}" | grep -qF 'conn//-/_' || t_fail "cmd_provision の manifest worker key が underscore 正規化されていない"
    stopw_def="$(declare -f stop_shard_workers)"
    echo "${stopw_def}" | grep -qF 'kill -TERM -- "-' || t_fail "stop_shard_workers が process group kill でない"
    echo "${stopw_def}" | grep -q 'worker_alive' || t_fail "stop_shard_workers に cmdline 照合 (worker_alive) が無い"
    echo "${stopw_def}" | grep -qF 'kill -0 -- "-' || t_fail "stop_shard_workers に process group 消滅待ちが無い (master 単体判定は dropdb と race)"
    echo "${stopw_def}" | grep -qF 'kill -KILL -- "-' || t_fail "stop_shard_workers に KILL escalation が無い"
    echo "${stopw_def}" | grep -q 'ps -o pgid=' || t_fail "stop_shard_workers に pid==pgid 検証が無い (setsid 不成立の group を消滅済みと誤認する)"
    echo "${stopw_def}" | grep -qF 'kill -TERM "${wpid}"' \
        && t_fail "stop_shard_workers に PID 単体 TERM fallback がある (pid 再利用 race。group kill のみにする)"
    local esc_ln lastrm_ln
    esc_ln="$(echo "${stopw_def}" | grep -nF 'kill -KILL -- "-' | head -1 | cut -d: -f1)"
    lastrm_ln="$(echo "${stopw_def}" | grep -n 'rm -f "\${wpidfile}"' | tail -1 | cut -d: -f1)"
    [[ -n "${esc_ln}" && -n "${lastrm_ln}" && "${esc_ln}" -lt "${lastrm_ln}" ]] \
        || t_fail "stop_shard_workers: 停止確認前に pidfile を削除している (残留 group の追跡情報を失う)"
    td2_def="$(declare -f cmd_teardown)"
    echo "${td2_def}" | grep -q 'stop_shard_workers' || t_fail "cmd_teardown に worker 停止配線が無い"
    echo "${td2_def}" | grep -q 'workers_stopped' || t_fail "cmd_teardown に worker 停止失敗時の dropdb 抑止が無い"
    local wkill2_ln skill_ln
    wkill2_ln="$(echo "${td2_def}" | grep -n 'stop_shard_workers' | head -1 | cut -d: -f1)"
    skill_ln="$(echo "${td2_def}" | grep -n 'serve-\${shard}.pid' | head -1 | cut -d: -f1)"
    [[ -n "${wkill2_ln}" && -n "${skill_ln}" && "${wkill2_ln}" -lt "${skill_ln}" ]] \
        || t_fail "cmd_teardown: worker 停止が serve 停止より後 (DB 接続残留リスク)"
    echo "${td2_def}" | grep -qF '${pidfile}" ]] || continue' \
        && t_fail "cmd_teardown: serve pidfile の '|| continue' が復活している (worker/wrapper 掃除がスキップされる回帰)"
    echo "$(declare -f cmd_keepdb_check)" | grep -q 'worker_alive' \
        || t_fail "cmd_keepdb_check に worker 生存確認が無い"

    # (y4) worker_alive: stale pidfile (存在しない pid) と cmdline 不一致 (自プロセス pid) を fail 判定
    mkdir -p "${TMP_BASE}"
    echo 999999999 > "$(worker_pidfile 7 database-analysis)"
    worker_alive 7 database-analysis && t_fail "worker_alive が存在しない pid を alive 判定"
    echo $$ > "$(worker_pidfile 7 database-analysis)"
    worker_alive 7 database-analysis && t_fail "worker_alive が cmdline 不一致 (bash 自身) を alive 判定"
    rm -f "$(worker_pidfile 7 database-analysis)"

    # (y5) dryrun provision は worker を起動しない (pidfile 不生成)
    export BUGHUNT_SELFTEST_DRYRUN=1
    ("${SCRIPT_PATH}" provision --shard 0 --run-id 20990301-000000) >/dev/null 2>&1 || t_fail "[y5] dryrun provision 失敗"
    unset BUGHUNT_SELFTEST_DRYRUN
    [[ ! -f "$(worker_pidfile 0 database-analysis)" ]] || t_fail "dryrun provision が worker pidfile を生成"

    # (y6) stop_shard_workers の機能検査 (実 worker/DB を使わない軽量プロセスで代替。
    #      worker_alive / kill / sleep は subshell 内 stub = 本体無変更):
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
    t_ok "queue worker wiring (derivation/drift/structure/alive/dryrun/stop 正常系+失敗系)"

    echo "[z] real-llm/fake-llm/real-storage モード制御 (フラグ/キー分離・fail-fast・秘密漏洩防止・引数解析)"
    local _e
    local z_env="${sandbox}/main-with-key.env"
    cat > "${z_env}" <<'ZENVEOF'
APP_ENV=local
ANTHROPIC_API_KEY=sk-ant-SELFTEST-DUMMY
OTHER=x
ZENVEOF
    local z_env_nokey="${sandbox}/main-no-key.env"
    cat > "${z_env_nokey}" <<'ZENVEOF'
APP_ENV=local
ANTHROPIC_API_KEY=
ZENVEOF
    local _saved_main_env="${MAIN_ENV_FILE}"
    local _saved_llm_mode="${LLM_MODE}" _saved_storage_mode="${STORAGE_MODE}"
    arr_has() { local needle=$1; shift; local e; for e in "$@"; do [[ "$e" == "$needle" ]] && return 0; done; return 1; }

    # [z1] build_mode_env: フラグ/キー分離 (MODE_ENV には実キーが決して含まれない)
    MAIN_ENV_FILE="${z_env}"
    LLM_MODE="real"; STORAGE_MODE="fake"; build_mode_env
    arr_has "TESTING_FAKE_LLM=false" "${MODE_ENV[@]}" || t_fail "[z1] real-llm で MODE_ENV に TESTING_FAKE_LLM=false が無い"
    arr_has "TESTING_FAKE_STORAGE=true" "${MODE_ENV[@]}" || t_fail "[z1] fake-storage 既定で MODE_ENV に TESTING_FAKE_STORAGE=true が無い"
    { [[ "${#LLM_KEY_ENV[@]}" == 1 && "${LLM_KEY_ENV[0]}" == "ANTHROPIC_API_KEY=sk-ant-SELFTEST-DUMMY" ]]; } \
        || t_fail "[z1] real-llm で LLM_KEY_ENV に実キーが載らない"
    arr_has "ANTHROPIC_API_KEY=sk-ant-SELFTEST-DUMMY" "${MODE_ENV[@]}" && t_fail "[z1] MODE_ENV に実キーが混入 (フラグ/キー分離違反)"
    for _e in "${MODE_ENV[@]}"; do [[ "${_e}" == *"sk-ant-SELFTEST-DUMMY"* ]] && t_fail "[z1] MODE_ENV 要素にキー値が含まれる"; done
    LLM_MODE="fake"; STORAGE_MODE="fake"; build_mode_env
    arr_has "TESTING_FAKE_LLM=true" "${MODE_ENV[@]}" || t_fail "[z1] fake-llm で MODE_ENV に TESTING_FAKE_LLM=true が無い"
    [[ "${#LLM_KEY_ENV[@]}" == 0 ]] || t_fail "[z1] fake-llm で LLM_KEY_ENV が空でない"
    LLM_MODE="fake"; STORAGE_MODE="real"; build_mode_env
    arr_has "TESTING_FAKE_STORAGE=false" "${MODE_ENV[@]}" || t_fail "[z1] real-storage で TESTING_FAKE_STORAGE=false が無い"
    t_ok "[z1] build_mode_env フラグ/キー分離"

    # [z1b] main_env_get: export/クォート/後置コメント/完全一致
    local z_parse="${sandbox}/main-parse.env"
    cat > "${z_parse}" <<'ZPEOF'
export EXPKEY=expval
DQ="dqval"
SQ='sqval'
CM=cmval  # trailing comment
ANTHROPIC_API_KEY=realkey
ANTHROPIC_API_KEY_SUFFIX=shouldnotmatch
ZPEOF
    MAIN_ENV_FILE="${z_parse}"
    [[ "$(main_env_get EXPKEY)" == "expval" ]] || t_fail "[z1b] export 前置を剥がせない"
    [[ "$(main_env_get DQ)" == "dqval" ]] || t_fail "[z1b] ダブルクォート除去失敗"
    [[ "$(main_env_get SQ)" == "sqval" ]] || t_fail "[z1b] シングルクォート除去失敗"
    [[ "$(main_env_get CM)" == "cmval" ]] || t_fail "[z1b] 後置コメント除去失敗"
    [[ "$(main_env_get ANTHROPIC_API_KEY)" == "realkey" ]] || t_fail "[z1b] 完全一致キー取得失敗"
    [[ -z "$(main_env_get ANTHROPIC_API)" ]] || t_fail "[z1b] 部分一致キーを誤取得"
    [[ -z "$(main_env_get NOPE)" ]] || t_fail "[z1b] 欠損キーが空でない"
    t_ok "[z1b] main_env_get parsing"

    # [z2] assert_llm_key_present: real∧キー無し→die(1) / real∧キー有り→0 / fake∧キー無し→0
    MAIN_ENV_FILE="${z_env}"; LLM_MODE="real"; STORAGE_MODE="fake"; build_mode_env
    ( assert_llm_key_present ) >/dev/null 2>&1 || t_fail "[z2] real-llm ∧ キー有りで die"
    MAIN_ENV_FILE="${z_env_nokey}"; LLM_MODE="real"; build_mode_env
    rc=0; ( assert_llm_key_present ) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 1 ]] || t_fail "[z2] real-llm ∧ キー無しで die(1) しない (rc=${rc})"
    MAIN_ENV_FILE="${z_env_nokey}"; LLM_MODE="fake"; build_mode_env
    ( assert_llm_key_present ) >/dev/null 2>&1 || t_fail "[z2] fake-llm ∧ キー無しでも rc=0 のはず"
    t_ok "[z2] assert_llm_key_present"

    # [z3] 秘密漏洩防止: stdout/stderr どちらにもキー値が出ない (通常 + set -x 有効実行)
    MAIN_ENV_FILE="${z_env}"; LLM_MODE="real"; STORAGE_MODE="fake"
    local z3_out z3_err
    z3_out="$( ( prepare_mode_and_preflight ) 2>"${sandbox}/z3.err" )"
    z3_err="$(cat "${sandbox}/z3.err")"
    echo "${z3_out}" | grep -q 'sk-ant-SELFTEST-DUMMY' && t_fail "[z3] 通常実行の stdout にキー値が漏洩"
    echo "${z3_err}" | grep -q 'sk-ant-SELFTEST-DUMMY' && t_fail "[z3] 通常実行の stderr にキー値が漏洩"
    z3_out="$( ( set -x; prepare_mode_and_preflight ) 2>"${sandbox}/z3x.err" )"
    z3_err="$(cat "${sandbox}/z3x.err")"
    echo "${z3_out}" | grep -q 'sk-ant-SELFTEST-DUMMY' && t_fail "[z3] set -x 実行の stdout にキー値が漏洩"
    echo "${z3_err}" | grep -q 'sk-ant-SELFTEST-DUMMY' && t_fail "[z3] set -x 実行の stderr にキー値が漏洩 (xtrace ガード不発)"
    prepare_mode_and_preflight
    for _e in "${MODE_ENV[@]}"; do [[ "${_e}" == *"sk-ant-SELFTEST-DUMMY"* ]] && t_fail "[z3] MODE_ENV にキー値混入"; done
    t_ok "[z3] 秘密漏洩防止 (通常/set -x)"

    # [z4] 引数解析: 相互排他 + provision 系専用 + provision 系 dryrun 受理
    rc=0; ("${SCRIPT_PATH}" provision --shard 0 --run-id 20990401-000000 --real-llm --fake-llm) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 2 ]] || t_fail "[z4] --real-llm --fake-llm 同時指定が exit ${rc} (expected 2)"
    for badsub in teardown reseed db-check verify-run self-test; do
        for badflag in --real-llm --fake-llm --real-storage; do
            rc=0; ("${SCRIPT_PATH}" "${badsub}" --run-id 20990401-000000 "${badflag}") >/dev/null 2>&1 || rc=$?
            [[ "${rc}" == 2 ]] || t_fail "[z4] ${badflag} が ${badsub} で exit ${rc} (expected 2)"
        done
    done
    export BUGHUNT_SELFTEST_DRYRUN=1
    rc=0; ("${SCRIPT_PATH}" provision --shard 0 --run-id 20990402-000000 --fake-llm) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "[z4] provision --fake-llm (dryrun) が exit ${rc} (expected 0)"
    rc=0; ("${SCRIPT_PATH}" provision --shard 0 --run-id 20990403-000000 --real-storage) >/dev/null 2>&1 || rc=$?
    [[ "${rc}" == 0 ]] || t_fail "[z4] provision --real-storage (dryrun) が exit ${rc} (expected 0)"
    unset BUGHUNT_SELFTEST_DRYRUN
    t_ok "[z4] 引数解析 (相互排他 / provision 系専用)"

    # [z5] 実効 env 検証の期待値導出 (python 断片) を mode 別に単体評価
    local z5
    z5="$(LLM_MODE=fake STORAGE_MODE=fake python3 - <<'PY'
import os
expected = {}
expected["fake_llm"] = (os.environ["LLM_MODE"] == "fake")
expected["fake_storage"] = (os.environ["STORAGE_MODE"] == "fake")
if os.environ["STORAGE_MODE"] == "fake":
    expected["filesystem"] = "local"
assert expected["fake_llm"] is True and expected["fake_storage"] is True and expected["filesystem"] == "local"
print("ok")
PY
)"
    [[ "${z5}" == "ok" ]] || t_fail "[z5] fake/fake 期待値導出が不正"
    z5="$(LLM_MODE=real STORAGE_MODE=real python3 - <<'PY'
import os
expected = {}
expected["fake_llm"] = (os.environ["LLM_MODE"] == "fake")
expected["fake_storage"] = (os.environ["STORAGE_MODE"] == "fake")
if os.environ["STORAGE_MODE"] == "fake":
    expected["filesystem"] = "local"
assert expected["fake_llm"] is False and expected["fake_storage"] is False and "filesystem" not in expected
print("ok")
PY
)"
    [[ "${z5}" == "ok" ]] || t_fail "[z5] real/real 期待値導出が不正"
    t_ok "[z5] 実効 env 期待値導出 (real/fake/real-storage)"

    # モード globals を復元 (後続に影響させない)
    MAIN_ENV_FILE="${_saved_main_env}"
    LLM_MODE="${_saved_llm_mode}"; STORAGE_MODE="${_saved_storage_mode}"
    MODE_ENV=(); LLM_KEY_ENV=()

    rm -rf "${sandbox}"
    unset BUGHUNT_SANDBOX
    if [[ "${failures}" -gt 0 ]]; then
        echo "self-test: ${failures} failure(s)"
        exit 1
    fi
    echo "self-test: all passed"
}

# --- 引数解析 -----------------------------------------------------------------

usage() {
    # ヘッダコメント (2 行目〜 `set -euo pipefail` の直前) を動的に切り出す。行数固定依存を避け、
    # ヘッダにモード表などを追記しても usage が確実に全文を出す (Codex 実装レビュー R1 Critical 反映)。
    awk 'NR==1{next} /^set -euo pipefail/{exit} {print}' "${SCRIPT_PATH}" | sed 's/^# \{0,1\}//'
    exit 2
}

main() {
    local sub="${1:-}"
    shift || true
    local shard="" run_id="" count=5 drop_db="" parallel=4 hold_lock=""
    COVERAGE=""    # --coverage: pcov 付きで serve 起動しコード到達カバレッジを収集 (既定 OFF)
    # モードは既定 real-llm + fake-storage。専用フラグ変数で「同時指定」「適用範囲」を判定する
    # (LLM_MODE/STORAGE_MODE の上書きだけだと「既定と同値の明示指定」を取りこぼすため)。
    LLM_MODE="real"; STORAGE_MODE="fake"
    local _llm_flag_real=0 _llm_flag_fake=0 _storage_flag_real=0

    while [[ $# -gt 0 ]]; do
        case "$1" in
            --shard) shard="${2:-}"; shift 2 ;;
            --run-id) run_id="${2:-}"; shift 2 ;;
            --count) count="${2:-}"; shift 2 ;;
            --parallel=*) parallel="${1#--parallel=}"; shift ;;
            --parallel) shift ;;
            --coverage) COVERAGE=1; shift ;;
            --real-llm) LLM_MODE="real"; _llm_flag_real=1; shift ;;
            --fake-llm) LLM_MODE="fake"; _llm_flag_fake=1; shift ;;
            --real-storage) STORAGE_MODE="real"; _storage_flag_real=1; shift ;;
            --drop-db) drop_db="--drop-db"; shift ;;
            --hold-lock) hold_lock="--hold-lock"; shift ;;
            *) die 2 "unknown option: $1" ;;
        esac
    done

    if [[ -n "${COVERAGE}" ]]; then
        [[ "${sub}" == "provision" || "${sub}" == "provision-all" ]] \
            || die 2 "--coverage は provision または provision-all でのみ使える"
    fi

    # モードフラグ: 相互排他 + provision 系専用 (--coverage と同じ流儀。teardown --real-llm 等も拒否)。
    if [[ "${_llm_flag_real}" == 1 && "${_llm_flag_fake}" == 1 ]]; then
        die 2 "--real-llm と --fake-llm は同時指定できません (モードを 1 つ選ぶ)"
    fi
    if [[ "${_llm_flag_real}" == 1 || "${_llm_flag_fake}" == 1 || "${_storage_flag_real}" == 1 ]]; then
        [[ "${sub}" == "provision" || "${sub}" == "provision-all" ]] \
            || die 2 "--real-llm / --fake-llm / --real-storage は provision または provision-all でのみ使える"
    fi

    case "${sub}" in
        provision)
            validate_shard "${shard}"; validate_run_id "${run_id}"
            cmd_provision "${shard}" "${run_id}" ;;
        provision-all)
            valid_parallel_n "${parallel}" || die 2 "--parallel は 2..${BUGHUNT_SHARD_CAP} の偶数のみ (固定ストーリーマップのため)"
            cmd_provision_all "${parallel}" "${hold_lock}" ;;
        reseed)
            validate_shard "${shard}"; validate_run_id "${run_id}"
            cmd_reseed "${shard}" "${run_id}" ;;
        db-check)
            validate_shard "${shard}"; validate_run_id "${run_id}"
            cmd_db_check "${shard}" "${run_id}" ;;
        db-exists)
            validate_shard "${shard}"; validate_run_id "${run_id}"
            cmd_db_exists "${shard}" "${run_id}" ;;
        mail-urls)
            validate_shard "${shard}"; validate_run_id "${run_id}"
            [[ "${count}" =~ ^[0-9]+$ ]] || die 2 "--count は整数"
            cmd_mail_urls "${shard}" "${run_id}" "${count}" ;;
        verify-run)
            validate_run_id "${run_id}"
            cmd_verify_run "${run_id}" ;;
        teardown)
            validate_run_id "${run_id}"
            cmd_teardown "${run_id}" "${drop_db}" ;;
        self-test)
            cmd_self_test ;;
        assets-check)
            cmd_assets_check ;;
        keepdb-check)
            validate_shard "${shard}"
            cmd_keepdb_check "${shard}" ;;
        ''|--help|-h)
            usage ;;
        *)
            die 2 "unknown subcommand: ${sub}" ;;
    esac
}

main "$@"
