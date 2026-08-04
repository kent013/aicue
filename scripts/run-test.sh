#!/usr/bin/env bash
#
# scripts/run-test.sh — composer test の pgsql 経路。グローバルテストロック配下で
# ensure (base DB 冪等 CREATE) → artisan test --parallel を実行する。
#
# 排他は scripts/global-test-lock.sh に一本化した (旧 worktree-local な
# storage/framework/testing/ 配下のロックは廃止)。グローバルロックのスコープ
# (同一 UID・同一マシン) は worktree-local のスコープを厳密に包含するため、
# 内側のロックは 1 つも新しい事象を防がない (後方互換の並走を残さない)。
#
# 待ち方も変わった: 先行レーンがいる場合は **待つ** (旧実装は非ブロッキング取得で
# 即エラー終了していた)。待機中は 30 秒ごとに保持者の身元が stderr に出る。

set -euo pipefail
cd "$(dirname "$0")/.."

# shellcheck source=scripts/global-test-lock.sh
. "$(pwd)/scripts/global-test-lock.sh"
global_test_lock_acquire "composer test"

# 以降、ロック配下の実行は必ず global_test_lock_run を通す
# (fd 7 の非継承と、孫まで含めたプロセスグループの刈り取りを一箇所に集約するため)。
global_test_lock_run php artisan config:clear --ansi

# worktree 固有の base テスト DB (<slug>_test_<worktree-hash>) を冪等に用意する。
# DB 名の安全検証 (dev DB hard-deny + allowlist) は tests/bootstrap.php の
# 単一点ガード + ensure-test-db.php 内の二重防御が担う。
global_test_lock_run php scripts/ci/ensure-test-db.php

global_test_lock_run php artisan test --parallel --processes=4 "$@"
