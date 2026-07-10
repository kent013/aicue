#!/usr/bin/env bash
#
# scripts/bug-hunt-inventory-check.sh — bug-hunt インベントリ ドリフト検知 (テンプレート版)
#
# 設計: 参照実装 (派生アプリ) の bug-hunt 基盤の汎用移植
#
# route:list の GET×inertia(web) / 非GET×web 集合と
# .claude/skills/app-bug-hunt/{screens.md,operations.md} の差分を検出する。
# 新ルート未追記・消失ルート未削除を warning 出力する (手動運用が既定、CI 任意)。
#
# 使い方: scripts/bug-hunt-inventory-check.sh   (exit 0=差分なし、3=差分あり)
set -euo pipefail

WORKSPACE="$(cd "$(dirname "$0")/.." && pwd)"
cd "${WORKSPACE}"

SKILL_DIR=".claude/skills/app-bug-hunt"
SCREENS="${SKILL_DIR}/screens.md"
OPS="${SKILL_DIR}/operations.md"

drift=0

# screens.md / operations.md が「設計上ブラウザ非対象」と明記しているルート名 prefix。
# これらは UX ブラウザ監査の対象外として意図的にインベントリ表から外しているため、
# drift 検出 (新ルート未追記) からも除外する。新たに非対象を増やす場合は両方を更新すること。
# filament.* は S9 (管理画面) が screens.md/operations.md に手動メンテで載せる admin guard ルート。
# route:list 抽出側 (forward) は uri prefix 'admin' で既に除外済み。reverse (消失検知) でも除外し、
# admin インベントリ行が誤って「消失候補」warning にならないようにする。
OUT_OF_SCOPE_PREFIXES='seo.|social.|recent-auth.sso.|two-factor.qr-code|two-factor.secret-key|two-factor.recovery-codes|password.confirmation|cashier.|passport.|livewire|default-livewire|mcp.|oauth.|webhooks.|sanctum.|filament.'

# 対象 GET×inertia(web) のルート名 (admin/api/debug/mcp/seo/oauth/xhr-only 等は除外)。
get_screen_names() {
    php artisan route:list --json | OOS="${OUT_OF_SCOPE_PREFIXES}" python3 -c "
import json,os,re,sys
oos=re.compile('^('+os.environ['OOS']+')')
for r in json.load(sys.stdin):
    if 'GET' not in r['method']: continue
    uri=r['uri']; mw=str(r.get('middleware',[]))
    if uri.startswith(('api/','admin','_','.well-known','storage','sanctum','livewire','oauth','mcp')) or 'debug' in uri: continue
    if 'web' not in mw: continue
    name=r.get('name')
    if not name or oos.match(name): continue
    print(name)" | sort -u
}

# 対象 非GET×web の操作名 (webhook/passport/livewire/out-of-scope は除外)。
get_op_names() {
    php artisan route:list --json | OOS="${OUT_OF_SCOPE_PREFIXES}" python3 -c "
import json,os,re,sys
oos=re.compile('^('+os.environ['OOS']+')')
for r in json.load(sys.stdin):
    m=r['method'].split('|')[0]
    if m in ('GET','HEAD','OPTIONS'): continue
    mw=str(r.get('middleware',[])); name=r.get('name')
    if 'web' not in mw or not name: continue
    if oos.match(name) or 'webhook' in name: continue
    print(name)" | sort -u
}

check() {
    local label=$1 file=$2; shift 2
    local names; names="$("$@")"
    echo "== ${label} =="
    local n
    while IFS= read -r n; do
        [[ -z "${n}" ]] && continue
        if ! grep -qF "${n}" "${file}"; then
            echo "  [新ルート未追記] ${n} が ${file} に無い"
            drift=3
        fi
    done <<< "${names}"
    # file に書かれた route 名が route:list から消えていないか (簡易: name 列を抽出して照合)
    local listed
    listed="$(grep -oE '[a-z0-9-]+\.[a-z0-9.-]+|^\| `?/' "${file}" 2>/dev/null || true)"
    # 消失検知は名前トークン単位で行う (誤検知を避けるため warning のみ)
    while IFS= read -r tok; do
        [[ -z "${tok}" ]] && continue
        # out-of-scope として表に記録した名前は消失検知から除外する。
        echo "${tok}" | grep -qE "^(${OUT_OF_SCOPE_PREFIXES})" && continue
        case "${tok}" in
            *.*)
                if ! echo "${names}" | grep -qF "${tok}"; then
                    echo "  [消失候補] ${file} の '${tok}' が現 route:list に無い (削除漏れの可能性)"
                fi
                ;;
        esac
    done < <(grep -oE '\| [a-z0-9-]+\.[a-z0-9.-]+ ' "${file}" | tr -d '| ' | sort -u)
}

check "screens (GET×inertia)" "${SCREENS}" get_screen_names
check "operations (非GET×web)" "${OPS}" get_op_names

if [[ "${drift}" == 3 ]]; then
    echo "drift 検出: インベントリと route:list に差分あり (上記を確認)"
    exit 3
fi
echo "drift なし: インベントリは route:list と整合"
