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
# のいずれの指紋も無いものを「main 直叩きの疑い」として拒否 (exit 2 + stderr=拒否理由)。
set -euo pipefail

input="$(cat)"
cmd="$(printf '%s' "${input}" | python3 -c 'import sys,json
try: print(json.load(sys.stdin).get("tool_input",{}).get("command",""))
except Exception: print("")' 2>/dev/null || true)"

# 対象は bug-hunt-shard.sh の provision / provision-all の**実行**のみ (subcommand は第1引数固定)。
# `bug-hunt-shard.sh<空白>provision` に限定する = コミットメッセージ等の**文字列言及**
# ("bug-hunt-shard.sh scaffold ... provision" のように間に別語が入る形) では誤発火しない。
printf '%s' "${cmd}" | grep -qE 'bug-hunt-shard\.sh[[:space:]]+provision' || exit 0

# 許可シグナルがあれば通す
if printf '%s' "${cmd}" | grep -qE '\.claude/worktrees/|BUGHUNT_ALLOW_MAIN=|BUGHUNT_SELFTEST_DRYRUN='; then
    exit 0
fi

# それ以外 = worktree 外からの直叩きの疑い → 拒否
cat >&2 <<'MSG'
⛔ bug-hunt provision を worktree 外から直叩きしようとしています (skill app-bug-hunt の Phase 0a スキップ)。
bug-hunt は worktree から走るのが既定です (main を直接汚さず todo/ ブランチに隔離するため)。次のいずれかで起動してください:
  1) /app-bug-hunt 経由 (推奨。Phase 0a が worktree を自動で切る)
  2) scripts/setup-worktree.sh bughunt-<task-id> で worktree を切り、その worktree 内
     (cd .claude/worktrees/tasks/bughunt-<task-id>) から本スクリプトを実行
  3) 意図的な main 走行 (--keep-db 連続再走など asset 既存の単発確認) のみ コマンド先頭に BUGHUNT_ALLOW_MAIN=1 を付ける
MSG
exit 2
