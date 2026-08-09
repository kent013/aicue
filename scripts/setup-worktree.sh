#!/usr/bin/env bash
#
# scripts/setup-worktree.sh — TODO 用 git worktree を作成し依存性・実行時ファイルを整える
#
# 使い方:
#   scripts/setup-worktree.sh T012
#
# ブランチ名は todo/<task-id> に固定 (custom branch 非対応 — teardown のブランチ前提と一致させるため)
# 運用ルール: AGENTS.md §worktree 運用ルール
#
# 責務:
#   0) 入力バリデーション + lock 排他
#   1) git worktree add (.claude/worktrees/tasks/<task-id>, todo/<task-id>, main 起点)
#   2) 実行時ファイルのプロビジョニング (.env 必須 / storage/oauth-*.key・public/build は存在すればコピー)
#   3) git submodule update --init --recursive (.gitmodules がある場合のみ)
#   4) vendor: worktree 内 composer install (worktree-local。独立 vendor)
#   5) node_modules: worktree 内 pnpm install (global virtual store 共有。LLM 自律運用で局所安全)
#   6) post-setup health check (必須ファイル / autoload smoke / GVS 実効 / cold-state ツール smoke)
#   7) pgsql test base DB の冪等 ensure (best-effort)
#
# 設計思想: worktree は依存ディレクトリ (vendor / node_modules) を workspace と共有しない。
# 各 worktree が自前で composer install / pnpm install する。
#   - vendor: hardlink 共有は Docker named volume(btrfs) と worktree(virtiofs) の
#     cross-device で失敗するため採らない。worktree 内 composer install に統一。
#   - node_modules: symlink 共有は worktree 内 pnpm install/add が workspace を直接
#     汚染する footgun のため採らない。pnpm enableGlobalVirtualStore で実体を共有 store
#     (<store-path>/links/) に置き、install/add の影響を自 worktree に局所化する。
# これにより worktree が device 非依存・LLM 自律運用で安全 (誤 install で main/他 worktree を
# 壊さない) になる。

set -euo pipefail

# --- bug-hunt 専用 env の provisioning (契約テストから source して単体で叩けるよう関数化) ---
# .env.bughunt.local は .gitignore 対象で worktree には決して現れない = コピーが唯一の供給路。
# bug-hunt は worktree 走行が既定 (AGENTS.md) なので、無いと provision が必ず止まる。
#
# ★ mode は親に追随させず 0600 に固定する。親が 0644 だと `cp -p` は
#   **world-readable な秘密ファイルを新たに作る**ため契約として弱い。
#   `install -m 600` は作成時点で mode を確定するので、`cp` → `chmod` の 2 段にある
#   「一瞬だけ広く読める窓」も無い。
# ★ 今回 0600 を固定する対象は **.env.bughunt.local だけ**である。
#   既存の .env / storage/oauth-*.key の権限契約は変更しない (別施策)。
provision_bughunt_env_file() {
    local repo_root=$1 worktree_dir=$2
    [[ -f "${repo_root}/.env.bughunt.local" ]] || return 0   # 非利用リポジトリでは no-op
    install -m 600 "${repo_root}/.env.bughunt.local" "${worktree_dir}/.env.bughunt.local"
}

# ★ source 専用モード: 関数定義だけ取り込んで抜ける (契約テスト用)。
#   実行時 (bash setup-worktree.sh) は環境変数を立てないので通らない。
if [[ -n "${SETUP_WORKTREE_SOURCE_ONLY:-}" && "${BASH_SOURCE[0]}" != "$0" ]]; then
    return 0
fi

if [[ $# -ne 1 || -z "${1:-}" ]]; then
    echo "usage: $0 <task-id>" >&2
    echo "  ブランチ名は todo/<task-id> に固定 (custom branch 非対応)" >&2
    exit 1
fi

TASK_ID="$1"
BRANCH_NAME="todo/${TASK_ID}"

# --- 入力バリデーション ---
if ! [[ "${TASK_ID}" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
    echo "error: invalid task-id: '${TASK_ID}' (英数字 + - . _ のみ、先頭は英数字)" >&2
    exit 1
fi
if ! git check-ref-format --branch "${BRANCH_NAME}" >/dev/null 2>&1; then
    echo "error: 派生ブランチ名が不正です: '${BRANCH_NAME}'" >&2
    exit 1
fi

REPO_ROOT="$(git rev-parse --show-toplevel)"
WORKTREE_DIR="${REPO_ROOT}/.claude/worktrees/tasks/${TASK_ID}"
LOCK_FILE="${REPO_ROOT}/.claude/worktrees/.setup.lock"

# --- lock 排他 (flock 優先 / 非 Linux は mkdir フォールバック) ---
# setup/teardown の同時実行を防ぐ。flock (util-linux) が無い macOS 等では
# mkdir のアトミック性を使った lock dir にフォールバックする (EXIT trap で解放)。
LOCK_DIR="${LOCK_FILE}.d"
LOCK_DIR_HELD=0
acquire_lock() {
    mkdir -p "${REPO_ROOT}/.claude/worktrees"
    if command -v flock >/dev/null 2>&1; then
        exec 200>"${LOCK_FILE}"
        if ! flock -n 200; then
            echo "error: 別の setup/teardown が実行中です (${LOCK_FILE})。完了を待って再実行してください。" >&2
            exit 1
        fi
    else
        if ! mkdir "${LOCK_DIR}" 2>/dev/null; then
            echo "error: 別の setup/teardown が実行中です (${LOCK_DIR})。完了を待って再実行してください。" >&2
            echo "       (異常終了で残った stale lock の場合は rmdir '${LOCK_DIR}' で解除)" >&2
            exit 1
        fi
        LOCK_DIR_HELD=1
    fi
}

# --- 実行時ファイルの provision 記録 (health check で存在を再検証する) ---
PROVISIONED_PATHS=()

# --- post-setup health check ---
post_setup_health_check() {
    local wt="$1" rc=0 f store_path store_links dep_real
    # 1. 必須ファイル + provision したファイルの存在
    for f in .env "${PROVISIONED_PATHS[@]+"${PROVISIONED_PATHS[@]}"}"; do
        if [[ ! -e "${wt}/${f}" ]]; then
            echo "  health-check FAIL: 必須パスが存在しない: ${f}" >&2
            rc=1
        fi
    done
    # 2. vendor: autoload 実行 smoke (worktree-local composer install が成立しているか)
    if ! (cd "${wt}" && php -d display_errors=0 -r 'require "vendor/autoload.php"; exit((int) ! class_exists(\App\Models\User::class));' 2>/dev/null); then
        echo "  health-check FAIL: autoload smoke 失敗 (App\\Models\\User が解決できない)" >&2
        rc=1
    fi
    # 3. node_modules: 実ディレクトリ + pnpm install 済み (.modules.yaml)
    if [[ ! -d "${wt}/node_modules" || -L "${wt}/node_modules" ]]; then
        echo "  health-check FAIL: node_modules が実ディレクトリでない (symlink/未作成)" >&2
        rc=1
    fi
    if [[ ! -f "${wt}/node_modules/.modules.yaml" ]]; then
        echo "  health-check FAIL: node_modules/.modules.yaml が無い (pnpm install 未完)" >&2
        rc=1
    fi
    # 4. GVS 実効: 代表 direct external dep (svelte) の realpath が共有 store の links/ 配下に
    #    解決されること (.modules.yaml だけでは GVS 無効 layout も成立し得るため core を直接 assert)
    if store_path="$(cd "${wt}" && pnpm store path --config.enableGlobalVirtualStore=true --config.nodeLinker=isolated 2>/dev/null)"; then
        store_links="${store_path%/}/links"
        dep_real="$(readlink -f "${wt}/node_modules/svelte" 2>/dev/null || true)"
        case "${dep_real}" in
            "${store_links}"/*) : ;;  # OK: GVS 有効
            *) echo "  health-check FAIL: GVS 無効の疑い (node_modules/svelte が store links 配下に解決されない: '${dep_real}')" >&2; rc=1 ;;
        esac
    else
        echo "  health-check FAIL: pnpm store path の取得に失敗" >&2
        rc=1
    fi
    # 5. cold-state ツール smoke: install 直後に test/phpstan/Laravel が cold で実行可能か fail-fast 検出
    if ! (cd "${wt}" && php artisan --version >/dev/null 2>&1); then
        echo "  health-check FAIL: artisan --version 失敗 (Laravel bootstrap が cold で壊れている)" >&2
        rc=1
    fi
    if [[ -x "${wt}/vendor/bin/pest" ]]; then
        if ! (cd "${wt}" && vendor/bin/pest --version >/dev/null 2>&1); then
            echo "  health-check FAIL: vendor/bin/pest --version 失敗 (test runner 実行不可)" >&2
            rc=1
        fi
    fi
    if [[ -x "${wt}/vendor/bin/phpstan" ]]; then
        if ! (cd "${wt}" && vendor/bin/phpstan --version >/dev/null 2>&1); then
            echo "  health-check FAIL: vendor/bin/phpstan --version 失敗 (静的解析実行不可)" >&2
            rc=1
        fi
    fi
    return $rc
}

# --- 工程別 timing ---
TIMING_LAST=""
emit_timing() {
    local label="$1" now elapsed
    now=$(date +%s)
    if [[ -n "${TIMING_LAST}" ]]; then
        elapsed=$(( now - TIMING_LAST ))
        echo "[timing] step=${label} elapsed=${elapsed}s" >&2
    fi
    TIMING_LAST="${now}"
}

# --- 失敗時クリーンアップ ---
SETUP_OK=0
WORKTREE_ADDED=0

cleanup_on_exit() {
    local rc=$?
    if (( ! SETUP_OK )) && (( WORKTREE_ADDED )); then
        echo ">>> [cleanup] setup 失敗のため worktree とブランチを削除します" >&2
        git -C "${REPO_ROOT}" worktree remove --force "${WORKTREE_DIR}" 2>/dev/null || true
        git -C "${REPO_ROOT}" branch -D "${BRANCH_NAME}" 2>/dev/null || true
    fi
    if (( LOCK_DIR_HELD )); then
        rmdir "${LOCK_DIR}" 2>/dev/null || true
    fi
    exit $rc
}
trap cleanup_on_exit EXIT

# === [0/7] 事前条件チェック + lock ===
echo ">>> [0/7] 事前条件チェック"
acquire_lock
if [[ -e "${WORKTREE_DIR}" ]]; then
    echo "error: ${WORKTREE_DIR} は既に存在します。teardown 先に: scripts/teardown-worktree.sh ${TASK_ID}" >&2
    exit 1
fi
TIMING_LAST=$(date +%s)
emit_timing "0-precheck"

# === [1/7] git worktree add ===
echo ">>> [1/7] git worktree add ${WORKTREE_DIR} -b ${BRANCH_NAME} main"
git -C "${REPO_ROOT}" worktree add "${WORKTREE_DIR}" -b "${BRANCH_NAME}" main
WORKTREE_ADDED=1
emit_timing "1-worktree-add"

# --- mise trust ---
# Node/pnpm 等を mise で管理する環境では、新規 worktree の mise.toml が untrusted 扱いになり
# 後続 pnpm install (pnpm→node が mise shim 経由) が "Config not trusted" で落ちる。
# worktree 作成直後に対象 path を trust して bootstrap を回復する。
# mise 非導入環境では skip し基盤を壊さない (冪等・非対話)。set -e を発火させない。
if command -v mise >/dev/null 2>&1; then
    mise trust "${WORKTREE_DIR}" >/dev/null 2>&1 \
        || echo "warning: mise trust 失敗。後続 pnpm install が落ちる場合は手動で 'mise trust ${WORKTREE_DIR}' を実行" >&2
fi

# === [2/7] 実行時ファイルのプロビジョニング ===
# .env は必須 (workspace の .env、無ければ committed の .env.example をコピー)。
# storage/oauth-*.key / public/build は runtime artifact (.gitignore 対象) で、workspace に
# あればコピー / 無ければ note して続行 (テンプレート初期状態では未生成のことがある。
# 必要になった時点で worktree 内 `php artisan passport:keys` / `pnpm build` で生成できる)。
echo ">>> [2/7] .env / .env.bughunt.local / storage/oauth-*.key / public/build を親からコピー"
if [[ -f "${REPO_ROOT}/.env" ]]; then
    cp "${REPO_ROOT}/.env" "${WORKTREE_DIR}/.env"
else
    cp "${REPO_ROOT}/.env.example" "${WORKTREE_DIR}/.env"   # .env 不在時は committed の .env.example をコピー
fi
for f in storage/oauth-private.key storage/oauth-public.key; do
    if [[ -f "${REPO_ROOT}/${f}" ]]; then
        cp "${REPO_ROOT}/${f}" "${WORKTREE_DIR}/${f}"
        PROVISIONED_PATHS+=("${f}")
    else
        echo "    note: ${f} が親に無いためコピーをスキップ (必要なら worktree 内で 'php artisan passport:keys')" >&2
    fi
done
# ★ 関数呼び出しを if の条件に置かない。条件内では set -e が効かず、
#   install の失敗が「無いためスキップ」に化けて秘密ファイルのコピー失敗を隠す。
if [[ -f "${REPO_ROOT}/.env.bughunt.local" ]]; then
    provision_bughunt_env_file "${REPO_ROOT}" "${WORKTREE_DIR}"
    PROVISIONED_PATHS+=(".env.bughunt.local")
else
    echo "    note: .env.bughunt.local が親に無いためコピーをスキップ (bug-hunt 未使用なら不要)" >&2
fi
if [[ -d "${REPO_ROOT}/public/build" ]]; then
    cp -r "${REPO_ROOT}/public/build" "${WORKTREE_DIR}/public/build"
    PROVISIONED_PATHS+=("public/build")
else
    echo "    note: public/build が親に無いためコピーをスキップ (必要なら worktree 内で 'pnpm build')" >&2
fi
emit_timing "2-provision"

# === [3/7] submodule init (worktree 側 cwd、.gitmodules がある場合のみ) ===
# composer install (step 4) が path repository で submodule を解決する構成に備えて先に init する。
if [[ -f "${REPO_ROOT}/.gitmodules" ]]; then
    echo ">>> [3/7] git submodule update --init --recursive"
    git -C "${WORKTREE_DIR}" submodule update --init --recursive
else
    echo ">>> [3/7] submodule なし (skip)"
fi
emit_timing "3-submodule-init"

# === [4/7] vendor: worktree 内 composer install (worktree-local 独立 vendor) ===
# --no-scripts: post-autoload-dump の artisan package:discover 等を skip する。
#   (a) dev DB の cache テーブル不在等で artisan が例外を起こす環境でも install を成立させる、
#   (b) Laravel 12 は bootstrap/cache/packages.php 不在時 runtime auto-discovery する、
#   ため機能影響なし。step 2 で .env を配置済み。
# リトライ: 共有 FS (OrbStack/virtiofs 等) では大量小ファイル展開時に一過性の
# ENOMEM ("Cannot allocate memory") で落ちることがある (再実行で成功する)。composer 内蔵の
# HTTP 層リトライでは FS 書き込み層の失敗を救えないため、ステップ全体を最大 3 回リトライ。
# sync は共有 FS バッファ flush のベストエフォート (set -e を発火させない)。
echo ">>> [4/7] vendor: worktree 内 composer install --no-scripts"
composer_install_ok=0
for _attempt in 1 2 3; do
    if (cd "${WORKTREE_DIR}" && composer install --no-progress --no-interaction --no-scripts); then
        composer_install_ok=1
        break
    fi
    echo "warn: composer install 失敗 (attempt ${_attempt}/3)、リトライします" >&2
    if [[ "${_attempt}" -lt 3 ]]; then sync || true; sleep 3; fi   # 最終試行後は待機しない
done
if [[ "${composer_install_ok}" -ne 1 ]]; then
    echo "error: worktree 内 composer install に失敗しました (一過性障害の自動回復を 3 回試みたが失敗)。lockfile / 認証 / registry 到達性を確認してください" >&2
    exit 1
fi
emit_timing "4-composer-install"

# === [5/7] node_modules: worktree 内 pnpm install (global virtual store 共有) ===
# 実体は共有 store (<store-path>/links/) を symlink 参照するため disk/install は安価、かつ
# install/add の影響が自 worktree に閉じる。
# CRITICAL: 親環境に CI=true 等が立つと pnpm が enableGlobalVirtualStore を自動無効化し得るため、
#   意図を CLI --config.* で明示強制する (CLI は env / PNPM_CONFIG_* / 設定ファイルより最優先)。
# リトライ: composer (step 4) と同一の一過性 ENOMEM 対策で完全対称の最大 3 回。
# --config.* 強制と --frozen-lockfile はリトライ各回で維持。
echo ">>> [5/7] node_modules: worktree 内 pnpm install --frozen-lockfile (global virtual store)"
pnpm_install_ok=0
for _attempt in 1 2 3; do
    if (cd "${WORKTREE_DIR}" && pnpm install \
            --frozen-lockfile \
            --config.ci=false \
            --config.enableGlobalVirtualStore=true \
            --config.nodeLinker=isolated \
            --config.confirmModulesPurge=false); then
        pnpm_install_ok=1
        break
    fi
    echo "warn: pnpm install 失敗 (attempt ${_attempt}/3)、リトライします" >&2
    if [[ "${_attempt}" -lt 3 ]]; then sync || true; sleep 3; fi   # 最終試行後は待機しない
done
if [[ "${pnpm_install_ok}" -ne 1 ]]; then
    echo "error: worktree 内 pnpm install に失敗しました (一過性障害の自動回復を 3 回試みたが失敗)。lockfile / 認証 / registry 到達性を確認してください" >&2
    exit 1
fi
emit_timing "5-pnpm-install"

# === [6/7] post-setup health check ===
echo ">>> [6/7] post-setup health check"
if ! post_setup_health_check "${WORKTREE_DIR}"; then
    echo "error: post-setup health check に失敗しました" >&2
    exit 1
fi
echo "    health check: OK"
emit_timing "6-health-check"

# === [7/7] pgsql test base DB を冪等 ensure ===
# worktree の base テスト DB を先に用意する。pgsql 非接続環境でも setup 全体を
# 壊さないよう warning 扱い (test 実行時に run-test.sh が再 ensure する)。
echo ">>> [7/7] ensure pgsql test base DB"
if [[ ! -f "${WORKTREE_DIR}/scripts/ci/ensure-test-db.php" ]]; then
    echo "    warning: scripts/ci/ensure-test-db.php が worktree に無いため skip (test 実行時に再 ensure されます)" >&2
elif php "${WORKTREE_DIR}/scripts/ci/ensure-test-db.php"; then
    echo "    ensure: OK"
else
    echo "    warning: ensure-test-db に失敗 (pgsql 非接続?)。test 実行時に再 ensure されます" >&2
fi
emit_timing "7-ensure-test-db"

SETUP_OK=1

echo ""
echo "✅ worktree 作成完了: ${WORKTREE_DIR}"
echo "   ブランチ: ${BRANCH_NAME}"
echo ""
echo "📦 依存は worktree-local (vendor=composer install / node_modules=pnpm install + GVS 共有)"
echo "   worktree 内 ルール (詳細は AGENTS.md §worktree 運用ルール):"
echo "   - pnpm install / composer install は許可 (worktree-local。main/他 worktree を汚さない)"
echo "   - pnpm add/remove/update・composer require/remove は task branch 上で行い、"
echo "     変更した package.json / pnpm-lock.yaml / composer.json / composer.lock を必ずコミットすること"
echo ""
echo "次の作業:"
echo "  cd ${WORKTREE_DIR}"
echo "  # 実装 → composer test / composer phpstan / vendor/bin/pint / pnpm test / pnpm lint"
echo "  # 完了後: scripts/teardown-worktree.sh ${TASK_ID}"
