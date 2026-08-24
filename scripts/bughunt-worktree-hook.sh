#!/usr/bin/env bash
# PreToolUse(Bash) ガード — bug-hunt の provision / provision-all を worktree 外から
# 直叩きする (= skill app-bug-hunt の Phase 0a worktree 作成をスキップする) のを
# harness レベルで provision 実行前に止める。
#
# 背景 (2026-06-20, app B1 移植): skill は一度ロードするとコンテキストに手順が展開され、
# 後続ターンで scripts/bug-hunt-shard.sh を main から直叩きすると Phase 0a を飛ばして main を汚す
# (app run 20260620-094245 S10 で実発生)。「skill 経由か」は機械判定できないが、その正しいフロー
# (Phase 0a) を通った観測可能な指紋 = 「worktree 文脈で起動しているか」を call-site で検査する。
# 早期・気づける層 (スクリプト本体ガード require_orchestrator は別軸 = 親セッション判定)。
#
# 判定: bug-hunt-shard.sh の provision/provision-all 呼び出しのうち、コマンド文字列に
#   - worktree パス (.claude/worktrees/) … 正しい Phase 0a フロー
#   - 明示オーバーライド (BUGHUNT_ALLOW_MAIN=) … 意図的 main 走行 (--keep-db 連続再走等)
#   - self-test dryrun (BUGHUNT_SELFTEST_DRYRUN=) … 自己検証
# のいずれの指紋も無いものを「main 直叩きの疑い」として拒否する。
#
# 判定は bash の組み込みだけで完結させる (外部コマンドを 1 つも起動しない)。
# 以前は cat / python3 / grep に依存しており、検索パスからそれらを解決できない環境では
# いずれも 127 で終わって拒否対象が黙って通っていた (無音の素通り)。

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

# 拒否は終了コード 2 で表す — これは harness が「この操作をブロックせよ」と解釈する唯一の値である
# (`PreToolUse` の 2 だけがブロックで、それ以外の非 0 はブロックしない異常として面に出る)。
# 起動子は終了コードを写像しないので、ここで返す値がそのまま harness へ届く (家系の正典 t3 の i7)。
# **帰結として、意図した拒否以外の理由で 2 が返っても同じくブロックになる** (bash が構文エラーで
# 返す 2 はその一例)。畳んで隠さないのは、畳むと配線ミスと実行時の異常を harness も人も
# 区別できなくなるからである。構文エラーが main へ着くこと自体は台帳テストの `bash -n` 検査 (S09)
# が止める。
readonly DENY_EXIT_CODE=2

# 標準入力は 1 回だけ読む。最大 1 MiB / 最大 5 秒 (閉じない相手に待ち続けない)。
input=''
IFS= read -r -N 1048576 -t 5 input || true

# 段 0: 対象語が無ければ外部コマンドを 1 つも起こさずに通す (無関係なコマンドは構造的に無影響)
case "${input}" in
    *bug-hunt-shard.sh*) ;;
    *) exit 0 ;;
esac

# 段 1: tool_input.command を取り出す (JSON エスケープは我々が探すバイト列を増やす方向にしか働かない)
command_text=''
extracted=0
if [[ "${input}" =~ \"command\"[[:space:]]*:[[:space:]]*\"((\\.|[^\"\\])*)\" ]]; then
    command_text="${BASH_REMATCH[1]}"
    extracted=1
fi

# 段 2: 判定
#  - 抽出できた: 抽出値だけで判定する (許可シグナル 2 種とも有効)
#  - 抽出できない: 明示解除 BUGHUNT_ALLOW_MAIN= だけを生入力で見る
#    (痕跡 .claude/worktrees/ は偶然そこにあり得るので抽出失敗時は評価しない)
if [ "${extracted}" -eq 1 ]; then
    subject="${command_text}"
    allow_regex='(\.claude\\?/worktrees\\?/|BUGHUNT_ALLOW_MAIN=|BUGHUNT_SELFTEST_DRYRUN=)'
else
    subject="${input}"
    allow_regex='BUGHUNT_ALLOW_MAIN='
fi

# 実行の検出は「bug-hunt-shard.sh の直後の空白 + provision」に限る
# (コミットメッセージ等の文字列言及では誤発火しない)。JSON の \n \t \r 表記も空白として受ける。
[[ "${subject}" =~ bug-hunt-shard\.sh([[:space:]]|\\[nrt])+provision ]] || exit 0
[[ "${subject}" =~ ${allow_regex} ]] && exit 0

# 拒否メッセージも組み込みで出す (ヒアドキュメント + cat を使わない)。
# これで**このスクリプトは外部コマンドを 1 つも使わない**ことになり、
# 検索パスがどれだけ壊れていても挙動が変わらない。
printf '%s\n' \
    '⛔ bug-hunt provision を worktree 外から直叩きしようとしています (skill app-bug-hunt の Phase 0a スキップ)。' \
    'bug-hunt は worktree から走るのが既定です (main を直接汚さず todo/ ブランチに隔離するため)。次のいずれかで起動してください:' \
    '  1) /app-bug-hunt 経由 (推奨。Phase 0a が worktree を自動で切る)' \
    '  2) scripts/setup-worktree.sh bughunt-<task-id> で worktree を切り、その worktree 内' \
    '     (cd .claude/worktrees/tasks/bughunt-<task-id>) から本スクリプトを実行' \
    '  3) 意図的な main 走行 (--keep-db 連続再走など asset 既存の単発確認) のみ コマンド先頭に BUGHUNT_ALLOW_MAIN=1 を付ける' \
    >&2
exit "${DENY_EXIT_CODE}"
