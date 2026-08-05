#!/usr/bin/env bash
#
# scripts/teardown-worktree.sh — TODO 用 worktree を削除する
#
# 使い方:
#   scripts/teardown-worktree.sh T012
#
# 運用ルール: AGENTS.md §worktree 運用ルール
#
# 責務:
#   1) 入力バリデーション + lock 排他
#   2) worktree dirty チェック (未コミット + untracked、worktree が存在する場合のみ)
#   3) main マージ済みかチェック (警告のみ)
#   4) pgsql test DB の best-effort 回収
#   5) git worktree remove --force (orphan の場合は git worktree prune)
#
# vendor / node_modules は worktree-local (composer install / pnpm install) で workspace と
# 共有しないため、worktree を消せば worktree-local の依存も一緒に消えるだけで、
# workspace 側を汚す経路は構造的に存在しない (汚染検証は不要)。
#
# ブランチ todo/<task-id> の扱い (削除/マージ/残置) は本スクリプトの責務外。

set -euo pipefail

if [[ $# -ne 1 || -z "${1:-}" ]]; then
    echo "usage: $0 <task-id>" >&2
    exit 1
fi
TASK_ID="$1"
if ! [[ "${TASK_ID}" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
    echo "error: invalid task-id: '${TASK_ID}'" >&2
    exit 1
fi

REPO_ROOT="$(git rev-parse --show-toplevel)"
WORKTREE_DIR="${REPO_ROOT}/.claude/worktrees/tasks/${TASK_ID}"
BRANCH_NAME="todo/${TASK_ID}"
LOCK_FILE="${REPO_ROOT}/.claude/worktrees/.setup.lock"

# --- lock 排他 (flock 優先 / 非 Linux は mkdir フォールバック。setup-worktree.sh と同一 lock) ---
LOCK_DIR="${LOCK_FILE}.d"
LOCK_DIR_HELD=0
release_lock_on_exit() {
    local rc=$?
    if (( LOCK_DIR_HELD )); then
        rmdir "${LOCK_DIR}" 2>/dev/null || true
    fi
    exit $rc
}
trap release_lock_on_exit EXIT
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

# --- worktree dirty チェック (worktree が存在する場合のみ) ---
WORKTREE_PRESENT=0
if [[ -d "${WORKTREE_DIR}" ]]; then
    WORKTREE_PRESENT=1
    worktree_status=$(cd "${WORKTREE_DIR}" && git status --porcelain --untracked-files=all)
    if [[ -n "${worktree_status}" ]]; then
        echo "error: ${WORKTREE_DIR} に未コミット変更または untracked ファイルがあります" >&2
        echo "${worktree_status}" >&2
        echo "先に commit / stash / clean してください" >&2
        echo "(依存変更 = package.json / pnpm-lock.yaml / composer.json / composer.lock も必ずコミット)" >&2
        echo "" >&2
        echo "⚠️  ここで git worktree remove --force を使って強制撤去すると、下の DB 回収 (drop-test-db.php)" >&2
        echo "    を通らずテスト DB が孤児として残ります。強制撤去した場合は後で必ず回収してください:" >&2
        echo "      php scripts/ci/drop-test-db.php --orphans          # dry-run で対象を確認" >&2
        echo "      (実 DROP は --apply --confirm=<token> が必要。LLM は実行しないこと)" >&2
        exit 1
    fi
else
    echo "notice: ${WORKTREE_DIR} が存在しません (teardown を経ずに削除された可能性)。prune して継続します。" >&2
fi

# --- main マージ済みかチェック (警告のみ) ---
if git -C "${REPO_ROOT}" rev-parse --verify --quiet "${BRANCH_NAME}" >/dev/null; then
    if git -C "${REPO_ROOT}" merge-base --is-ancestor "${BRANCH_NAME}" main; then
        echo ">>> ${BRANCH_NAME} は main にマージ済み"
    else
        echo "⚠️  warning: ${BRANCH_NAME} は main 未マージです (worktree 削除後もブランチは残ります)" >&2
    fi
fi

# --- pgsql test DB を best-effort 回収 ---
# worktree 削除の前に、worktree の base + paratest worker DB を allowlist guard 付きで drop する。
# best-effort: 接続失敗・スクリプト不在は warning で続行。
if (( WORKTREE_PRESENT )) && [[ -f "${WORKTREE_DIR}/scripts/ci/drop-test-db.php" ]]; then
    echo ">>> drop pgsql test DB (best-effort)"
    php "${WORKTREE_DIR}/scripts/ci/drop-test-db.php" || \
        echo "⚠️  warning: drop-test-db に失敗 (手動回収が必要な場合あり)" >&2
fi

# --- worktree 削除 ---
if (( WORKTREE_PRESENT )); then
    echo ">>> git worktree remove --force ${WORKTREE_DIR}"
    git -C "${REPO_ROOT}" worktree remove --force "${WORKTREE_DIR}"
else
    echo ">>> git worktree prune"
fi
git -C "${REPO_ROOT}" worktree prune

echo ""
echo "✅ worktree teardown 完了: ${TASK_ID}"
echo "   ブランチ ${BRANCH_NAME} の削除/マージは呼び出し側の責務 (本スクリプトは触らない)"
