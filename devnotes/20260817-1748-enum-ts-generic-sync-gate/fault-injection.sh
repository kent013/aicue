#!/usr/bin/env bash
#
# T218 故障注入の実測スクリプト (一時スクリプト。恒久 scripts/ へは昇格させない)。
#
# 新 gate と抽出器の**感度**を測る。注入ごとに対象ファイルを退避 → 壊す →
# 注入が実際にファイルを変えたことを確かめる → 対象の vitest を走らせて終了コードを見る →
# 退避から戻す。期待はすべて「赤」であり、緑のままなら degenerate PASS である。
#
# 使い方: bash devnotes/20260817-1748-enum-ts-generic-sync-gate/fault-injection.sh
set -uo pipefail

cd "$(dirname "$0")/../.."
ROOT="$(pwd)"
GATE="tests/js/architecture/enum-ts-sync.test.ts"
EXTRACTOR="tests/js/architecture/enum-ts-sync-extractor.test.ts"
LOG="$(mktemp)"
BACKUP="$(mktemp -d)"
trap 'rm -rf "${BACKUP}" "${LOG}"' EXIT

# 注入 1 件を実行する。
#   $1 = 注入の名前 / $2 = 走らせるテスト / $3 = 壊すファイル / $4 = perl の置換式
run_case() {
    local name="$1" target="$2" file="$3" expr="$4"
    local saved="${BACKUP}/saved"

    cp "${ROOT}/${file}" "${saved}"
    perl -0pi -e "${expr}" "${ROOT}/${file}"
    if cmp -s "${ROOT}/${file}" "${saved}"; then
        cp "${saved}" "${ROOT}/${file}"
        echo "| ${name} | ${target} | **注入が効いていない (置換が 0 件)** |"
        return
    fi

    local status=0
    pnpm test "${target}" >"${LOG}" 2>&1 || status=$?
    cp "${saved}" "${ROOT}/${file}"

    if [ "${status}" -eq 0 ]; then
        echo "| ${name} | ${target} | **緑のまま (感度なし)** |"
    else
        local failed note
        failed="$(grep -c -E "^ +× " "${LOG}")"
        note=""
        # beforeAll の中で落ちた注入は個別の × が出ず、ファイルごと停止する。
        if [ "${failed}" -eq 0 ]; then
            note=" / beforeAll で停止: $(grep -m1 -E "EnumTsSyncError:" "${LOG}" | sed 's/^ *//' | cut -c1-70)"
        fi
        echo "| ${name} | ${target} | 赤 (失敗 ${failed} 件${note}) |"
    fi
}

echo "| 注入 | 走らせたテスト | 結果 |"
echo "|---|---|---|"

# --- 本体 gate の感度: 代表 3 組を両方向に壊す ---
run_case 'TS 側: VideoManualStatus から "published" を落とす' "${GATE}" \
    "resources/js/types/manual.ts" 's/ \| "published";/;/'
run_case 'PHP 側: VideoManualStatus へ case を 1 つ足す' "${GATE}" \
    "app/Enums/Manual/VideoManualStatus.php" "s/case Draft = 'draft';/case Draft = 'draft';\n    case Injected = 'injected';/"

run_case 'TS 側: MemberRoleState から "unassigned" を落とす' "${GATE}" \
    "resources/js/types/admin.ts" 's/ConsoleRole \| "owner" \| "unassigned"/ConsoleRole | "owner"/'
run_case 'PHP 側: MemberRoleState へ case を 1 つ足す' "${GATE}" \
    "app/Enums/MemberRoleState.php" "s/case Owner = 'owner';/case Owner = 'owner';\n    case Injected = 'injected';/"

run_case 'TS 側: PlanCode から "enterprise" を落とす' "${GATE}" \
    "resources/js/types/Auth.ts" 's/ \| "enterprise";/;/'
run_case 'PHP 側: PlanCode へ case を 1 つ足す' "${GATE}" \
    "app/Enums/PlanCode.php" "s/case Personal = 'personal';/case Personal = 'personal';\n    case Injected = 'injected';/"

# --- 目録そのものの感度 ---
run_case '目録: 件数 pin を 1 ずらす' "${GATE}" \
    "${GATE}" 's/const EXPECTED_MIRROR_COUNT = 27;/const EXPECTED_MIRROR_COUNT = 26;/'
run_case '目録: 行を 1 つ消す (件数 pin が拾う)' "${GATE}" \
    "${GATE}" 's/\n    \{\n        php: "app\/Enums\/Manual\/ManualSortOption.php",\n.*?\n    \},//s'
run_case '目録: app\/ の外のパスを登録する' "${GATE}" \
    "${GATE}" 's/php: "app\/Enums\/Manual\/RenderKind.php"/php: "config\/app.php"/'

# --- 抽出器 (TS 側) の感度 ---
run_case '抽出器: TypeScript の enum を弾く分岐を外す' "${EXTRACTOR}" \
    "tests/js/support/enum-ts-sync/ts-value-sets.ts" 's/\(part.flags & ts.TypeFlags.EnumLiteral\) !== 0/false/'
run_case '抽出器: 同名の型別名が 2 件ある検査を外す' "${EXTRACTOR}" \
    "tests/js/support/enum-ts-sync/ts-value-sets.ts" 's/if \(aliases.length > 1\)/if (false)/'
run_case '抽出器: 起点を縮めた program でも全体 program を使う' "${EXTRACTOR}" \
    "tests/js/support/enum-ts-sync/program.ts" 's/return buildProgram\(absoluteFiles, parsed\);/return buildProgram([...parsed.fileNames, ...absoluteFiles], parsed);/'

# --- 抽出器 (PHP 側) の感度 ---
run_case '抽出器: 逆斜線の偶奇 (1 文字だけ飛ばす形にする)' "${EXTRACTOR}" \
    "tests/js/support/enum-ts-sync/php-enums.ts" 's/                index \+= 2;\n                continue;\n            \}\n            if \(\(state === SINGLE/                index += 1;\n                continue;\n            }\n            if ((state === SINGLE/s'
run_case '抽出器: 行注釈の中の閉じタグを見逃す' "${EXTRACTOR}" \
    "tests/js/support/enum-ts-sync/php-enums.ts" 's/            \/\/ PHP は.*?\n            if \(ch === "\?" && next === ">"\) \{\n                throw[^\n]*\n            \}\n//s'
run_case '抽出器: case の深さの条件を外す (switch の case を拾う)' "${EXTRACTOR}" \
    "tests/js/support/enum-ts-sync/php-enums.ts" 's/if \(isCode\[at\] !== 1 \|\| depth\[at\] !== 1\) continue;/if (isCode[at] !== 1) continue;/'
run_case '抽出器: backing の値の重複の検査を外す' "${EXTRACTOR}" \
    "tests/js/support/enum-ts-sync/php-enums.ts" 's/if \(values.has\(caseValue\)\)/if (false)/'
run_case '抽出器: ファイル名の語幹の照合を外す' "${EXTRACTOR}" \
    "tests/js/support/enum-ts-sync/php-enums.ts" 's/if \(enumName !== stem\)/if (false)/'

# --- Codex 実装レビュー Round 1 で足した分岐の感度 ---
run_case '抽出器: case の値に改行を許す (Critical の回帰)' "${EXTRACTOR}" \
    "tests/js/support/enum-ts-sync/php-enums.ts" 's/if \(\/\[\\r\\n\]\/.test\(declaration\)\) \{/if (false) {/'
run_case '行列: 起点を縮めた program の行を消す' "${EXTRACTOR}" \
    "${EXTRACTOR}" 's/\n    \/\/ T25b: 起点だけの program では.*?program: "narrow" \},//s'
run_case '目録の体裁: 配下の判定から区切り文字を落とす' "${GATE}" \
    "${GATE}" 's/absolute.startsWith\(root \+ path.sep\)/absolute.startsWith(root)/'
run_case '目録の体裁: symlink の解決先の検査を外す' "${GATE}" \
    "${GATE}" 's/if \(!isUnder\(fs.realpathSync\(absolute\), scanRoot\)\)/if (false)/'
run_case '目録の体裁: symlink 別名の二重登録の検査を外す' "${GATE}" \
    "${GATE}" 's/if \(seenReal.has\(realKey\)\)/if (false)/'
