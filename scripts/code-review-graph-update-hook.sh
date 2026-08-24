#!/usr/bin/env bash
# PostToolUse(Write|Edit) — コード索引 (code-review-graph) の差分更新。
#
# 実行契約 (tests/Architecture/ClaudeHooksWiringTest.php が実挙動で固定する):
#  1. 何が起きても終了コード 0 で終わる (編集作業を止めない)
#  2. 標準出力は常に空
#  3. 告知は標準エラーに 1 行だけ。セッションごと・理由ごとに 1 回だけ
#  4. 更新は必ず flock で排他する。安全に排他できない環境では更新しない
#  5. **明示している 3 つの上限の和**が呼び出し側の時間切れより小さい:
#     標準入力待ち 5 秒 + 更新本体 20 秒 + KILL までの猶予 2 秒 = 27 秒 < 30 秒。
#     台帳テストがこの 3 値と `.claude/settings.json` の timeout を数値で取り出して比較する。
#     **和は「明示した待ちの合計」であって全体の最悪時間ではない** (前処理とプロセス起動の
#     時間は含まない。含める設計 = 前処理ごと内側 timeout で囲む形は採っていない)
#  6. 作業ディレクトリと環境変数に依存しない (リポジトリルートは自分の位置から解決する)
#  7. 最初の外部コマンド呼び出しより前に検索パスを安全化する
#  8. 置き場・ロック・告知フラグが symlink なら何も書かずに終える
#  9. 索引の対象外の拡張子では更新を起動しない (副作用ゼロ)
#
# 索引ツール自身の install / uninstall は実行しないこと (配線の正本が二重化する。AGENTS.md)。

# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---
# set -e は使わない: 途中の失敗で暗黙に終了すると「常に 0 で終わる」契約を守れない。
set -uo pipefail
export LC_ALL=C
_hook_sanitize_path() {
    local element out=''
    local -a elements=()
    IFS=':' read -r -a elements <<< "${PATH-}"
    for element in ${elements[@]+"${elements[@]}"}; do
        # 絶対パスでない要素 (空要素・"." ・相対パス) を落とす
        case "${element}" in
            /*) ;;
            *) continue ;;
        esac
        # 正規化前の別表記も落とす (//, /./, /../, 末尾の /. と /..)
        case "${element}" in
            *//*|*/./*|*/../*|*/.|*/..) continue ;;
        esac
        out="${out:+${out}:}${element}"
    done
    # 空の PATH はカレントディレクトリと解釈されうるので、最小のシステムパスに倒す
    PATH="${out:-/usr/local/bin:/usr/bin:/bin}"
    export PATH
}
_hook_sanitize_path
# ---8< /SHARED_PATH_PROLOGUE >8---

# 呼び出し側 (.claude/settings.json) の 30 秒より内側で自分から諦める
readonly INNER_TIMEOUT_SECONDS=20
# 索引の対象外の拡張子 (台帳テストが完全一致で固定する。索引ツール更新時は棚卸しすること)
readonly SKIP_EXTENSIONS='md txt json yaml yml lock log'

state_dir=''
session_id='unknown'

# 告知: 標準エラーに 1 行だけ。セッションごと・理由ごとに 1 回だけ。
# 目印ファイルの作成は noclobber (O_CREAT|O_EXCL) なので、
#  - 既にあれば作成に失敗する = 重複抑止そのもの (読み書きの競合が起きない)
#  - 目印が symlink でも作成に失敗する = 検査と作成が原子的 (TOCTOU が無い)
emit_warning() {
    local reason="$1" message="$2" flag
    flag="${state_dir}/warned-${reason}-${session_id}"
    ( set -C; : > "${flag}" ) 2> /dev/null || return 0
    printf 'code-review-graph: %s\n' "${message}" >&2
    return 0
}

# --- 段 1: 標準入力を 1 回だけ読む (最大 1 MiB / 最大 5 秒) -------------------
input=''
IFS= read -r -N 1048576 -t 5 input || true

# --- 段 2: 対象外拡張子なら副作用ゼロで終わる --------------------------------
file_path=''
if [[ "${input}" =~ \"file_path\"[[:space:]]*:[[:space:]]*\"([^\"]*)\" ]]; then
    file_path="${BASH_REMATCH[1]}"
fi
# 拡張子は untrusted な値なので、パターン側には置かず**文字列等価**で比べる
# (`*` や `[` を含む拡張子でパターンとして解釈されるのを防ぐ)。
case "${file_path}" in
    *.*)
        extension="${file_path##*.}"
        extension="${extension,,}"
        for skip in ${SKIP_EXTENSIONS}; do
            [ "${extension}" = "${skip}" ] && exit 0
        done
        ;;
esac

# --- 段 3: セッション識別子 (純粋な文字列処理。副作用が無いのでここで済ませる) ---
if [[ "${input}" =~ \"session_id\"[[:space:]]*:[[:space:]]*\"([A-Za-z0-9._-]{1,64})\" ]]; then
    case "${BASH_REMATCH[1]}" in
        .|..) ;;
        *) session_id="${BASH_REMATCH[1]}" ;;
    esac
fi

# --- 段 4: リポジトリルートを自分の位置から解決する ---------------------------
script_path="${BASH_SOURCE[0]}"
script_dir="${script_path%/*}"
[ "${script_dir}" = "${script_path}" ] && script_dir='.'
repo_root="$(cd -- "${script_dir}/.." > /dev/null 2>&1 && pwd -P)" || exit 0
[ -n "${repo_root}" ] || exit 0

# --- 段 5: 置き場の symlink 検査 → 作成 → 再検査 ------------------------------
claude_dir="${repo_root}/.claude"
state_dir="${claude_dir}/code-review-graph-update-hook"
[ -L "${claude_dir}" ] && exit 0
[ -L "${state_dir}" ] && exit 0
mkdir -p "${state_dir}" 2>/dev/null || exit 0
[ -L "${claude_dir}" ] && exit 0
[ -L "${state_dir}" ] && exit 0
[ -d "${state_dir}" ] || exit 0

# --- 段 6: 排他 (非ブロッキング。取れなければ黙って終わる) --------------------
# ロックは flock で取る (プロセスが落ちても解放されるため。ディレクトリロックは
# 落ちたときに解放されず索引更新が恒久的に止まるので採らない)。
# 帰結として、ロックファイルの差し替え (TOCTOU) までは防がない = 保証範囲を下げてある。
lock_file="${state_dir}/update.lock"
[ -L "${lock_file}" ] && exit 0
if ! command -v flock > /dev/null 2>&1; then
    emit_warning 'no-flock' 'flock が無いため索引を更新しません (排他できない環境では更新しない契約です)'
    exit 0
fi
# ★ `exec 9> file 2>/dev/null` と書いてはいけない: コマンドを伴わない exec の
#   リダイレクトは**シェル全体へ永続適用**され、以降の告知 (契約 3) が消える。
#   波括弧のグループなら fd 9 だけが残り、標準エラーの差し替えはグループの外で戻る。
{ exec 9> "${lock_file}"; } 2> /dev/null || exit 0
flock -n 9 || exit 0

# --- 段 7: 前提コマンドの在否 ------------------------------------------------
if ! command -v code-review-graph > /dev/null 2>&1; then
    emit_warning 'tool-missing' \
        'コード索引ツールが未導入です (uv tool install code-review-graph==2.3.7 → code-review-graph build)'
    exit 0
fi
if ! command -v timeout > /dev/null 2>&1; then
    emit_warning 'no-timeout' 'timeout が無いため索引を更新しません (時間切れを保証できないためです)'
    exit 0
fi

# --- 段 8: 差分更新 ----------------------------------------------------------
timeout -k 2 "${INNER_TIMEOUT_SECONDS}" \
    code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1
status=$?
case "${status}" in
    0) ;;
    124|137) emit_warning 'update-timeout' \
        "索引の差分更新が ${INNER_TIMEOUT_SECONDS} 秒で終わらなかったため中断しました" ;;
    *) emit_warning 'update-failed' \
        "索引の差分更新に失敗しました (終了コード ${status}。code-review-graph build を試してください)" ;;
esac

# --- 段 9: 常に成功で終わる --------------------------------------------------
exit 0
