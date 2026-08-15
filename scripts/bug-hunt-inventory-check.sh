#!/usr/bin/env bash
#
# scripts/bug-hunt-inventory-check.sh — bug-hunt 目録のドリフト検査 (起動のみ)
#
# 判定は scripts/bug-hunt-inventory.py に一本化してある。**このスクリプトに判定を戻さない**
# (同じ規則が 2 か所に増えると必ず食い違う)。
#
# 使い方: scripts/bug-hunt-inventory-check.sh
#   exit 0=一致 / 2=致命 (抽出不能・抽出条件不一致・母集合 0 件等) / 3=ドリフト
set -euo pipefail

WORKSPACE="$(cd "$(dirname "$0")/.." && pwd)"
exec python3 "${WORKSPACE}/scripts/bug-hunt-inventory.py" check "$@"
