#!/usr/bin/env bash
#
# scripts/with-global-test-lock.sh — 任意コマンドをグローバルテストロック配下で実行する。
#
# ラップ用のシェルスクリプトを持たない lane (package.json の test:packages / test:coverage) 用。
# lane スクリプトを持つ 3 レーンは scripts/global-test-lock.sh を直接 source する
# (直接叩かれた場合も保護されるため)。
#
# **exec は使わない**: exec は fd 7 を閉じてロックを即解放してしまう。
# fd 7 を保持したままの親が子を待ち、終了コードをそのまま返す。

set -euo pipefail

if [ "$#" -lt 1 ]; then
    echo "usage: with-global-test-lock.sh <command> [args...]" >&2
    exit 2
fi

# shellcheck source=scripts/global-test-lock.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/global-test-lock.sh"

global_test_lock_acquire "$*"

status=0
global_test_lock_run "$@" || status=$?
exit "${status}"
