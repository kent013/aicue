#!/usr/bin/env bash
#
# scripts/run-test.sh — composer test の pgsql 経路。同一 worktree の test 二重起動を
# flock で直列化し、ensure (base DB 冪等 CREATE) → artisan test --parallel を実行する。
#
# 同一 worktree で test を二重起動すると、RefreshDatabase の migrate:fresh と
# paratest の per-worker DB が衝突し、後発側が不可解な failure を吐くため排他する。
#
# lock は worktree-local (storage/framework/testing/ は worktree ごとに別実体) なので
# 別 worktree の test は止めない (base DB 名 hash が異なり競合しない)。
# flock(1) が無い環境 (素の macOS 等) では排他なしで実行する (devcontainer/CI では排他あり)。

set -euo pipefail
cd "$(dirname "$0")/.."

LOCK_FILE="storage/framework/testing/test.lock"   # worktree-local
mkdir -p "$(dirname "$LOCK_FILE")"
if command -v flock >/dev/null 2>&1; then
    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
        echo "ERROR: another composer test is running in this worktree; refusing to start" >&2
        echo "       lock file: $LOCK_FILE" >&2
        exit 1
    fi
fi

php artisan config:clear --ansi

# worktree 固有の base テスト DB (<slug>_test_<worktree-hash>) を冪等に用意する。
# DB 名の安全検証 (dev DB hard-deny + allowlist) は tests/bootstrap.php の
# 単一点ガード + ensure-test-db.php 内の二重防御が担う。
php scripts/ci/ensure-test-db.php

# lock fd (9) を artisan test / paratest worker / そこから spawn される子プロセスに
# 継承させない。orphan 化した子が fd 9 を握り続けると lock が解放されず次回の
# flock -n 9 が継続的に拒否されるため、テスト実行コマンドへ渡す瞬間に 9>&- で閉じる
# (親シェルの fd 9 = lock は保持されたまま)。
php artisan test --parallel --processes=4 "$@" 9>&-
