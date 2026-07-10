#!/usr/bin/env bash
#
# scripts/run-vitest.sh — workspace 単位で vitest を排他実行する。
#
# 同一 workspace で vitest を二重起動すると .vite/ cache と coverage 出力先が
# 同時に書かれて壊れることがある。flock(1) で workspace 派生キーの lock を握り、
# 既に走っている場合は待たずに exit 1 で即終了する。
#
# 注意: lock は workspace 配下ではなく ${TMPDIR:-/tmp} 配下に置く(run-test.sh と同じ理由)。

set -euo pipefail

WORKSPACE="$(cd "$(dirname "$0")/.." && pwd)"
LOCK_DIR="${TMPDIR:-/tmp}"
LOCK_KEY="$(printf '%s' "$WORKSPACE" | shasum -a 256 | cut -c1-16)"
LOCK_FILE="$LOCK_DIR/app-vitest-${LOCK_KEY}.lock"

# flock(1) が無い環境 (素の macOS 等) では排他なしで実行する (devcontainer/Linux では排他あり)
if command -v flock >/dev/null 2>&1; then
    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
        echo "ERROR: vitest is already running in this workspace." >&2
        echo "       workspace: $WORKSPACE" >&2
        echo "       lock file: $LOCK_FILE" >&2
        exit 1
    fi
fi

cd "$WORKSPACE"
exec pnpm exec vitest run "$@"
