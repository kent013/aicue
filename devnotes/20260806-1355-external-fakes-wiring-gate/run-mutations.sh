#!/usr/bin/env bash
# fake 配線 gate の mutation 実走ドライバ (一時スクリプト)。
#
#   bash devnotes/20260806-1355-external-fakes-wiring-gate/run-mutations.sh <出力ログ> <suite 引数...>
#
# 例 (段階 1: 穴の実在確認 / 段階 3 の全体確認):
#   bash ...\run-mutations.sh /tmp/stage1.log --testsuite=Architecture --testsuite=Feature
# 例 (段階 3: gate 単独):
#   bash ...\run-mutations.sh /tmp/stage3.log --testsuite=Architecture
set -uo pipefail
cd "$(git rev-parse --show-toplevel)"

LOG="$1"
shift

: >"${LOG}"
for M in M1 M2 M3 M4 M5 M5b M6 M6b M7; do
    python3 devnotes/20260806-1355-external-fakes-wiring-gate/mutations.py apply "${M}"
    OUT="$(composer test -- "$@" 2>&1 | tail -40)"
    echo "===== ${M} =====" >>"${LOG}"
    echo "${OUT}" >>"${LOG}"
    echo >>"${LOG}"
done
python3 devnotes/20260806-1355-external-fakes-wiring-gate/mutations.py revert
echo "===== done =====" >>"${LOG}"
