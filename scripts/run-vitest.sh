#!/usr/bin/env bash
#
# scripts/run-vitest.sh — vitest をグローバルテストロック配下で実行する。
#
# 旧実装は workspace realpath 由来の key で worktree ごとに別ロックを取り (= cross-worktree
# 排他ゼロ)、かつ非ブロッキング取得で待たずに即エラー終了していた。両方ともグローバルロックへ
# 置き換えた (排他は scripts/global-test-lock.sh に一本化)。
#
# JS レーンは DB もポートも掴まないが、Browser lane と CPU を奪い合うと
# タイムアウト由来の偽赤を作るため対象に含める (方針判断。成功条件と見直し条件は
# devnotes/20260804-2319-global-test-lock/conceptual-design.md)。
#
# **exec は使わない**: exec は fd 7 を閉じてロックを即解放してしまう
# (旧実装の `exec pnpm exec vitest run` は fd 9 を vitest へ継承させることで偶然
#  ロックを保っていたが、それは orphan による lock leak と表裏一体の形だった)。

set -euo pipefail
cd "$(dirname "$0")/.."

# shellcheck source=scripts/global-test-lock.sh
. "$(pwd)/scripts/global-test-lock.sh"
global_test_lock_acquire "pnpm test"

status=0
global_test_lock_run pnpm exec vitest run "$@" || status=$?
exit "${status}"
