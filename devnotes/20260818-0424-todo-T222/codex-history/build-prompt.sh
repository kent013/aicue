#!/usr/bin/env bash
# T222 実装レビュー用の Codex プロンプトを組み立てる一時スクリプト。
# 使い方: bash devnotes/20260818-0424-todo-T222/codex-history/build-prompt.sh <round> <テスト結果ファイル>
set -euo pipefail
cd "$(dirname "$0")/../../.."

ROUND="$1"
TEST_SUMMARY="$2"
DIR="devnotes/20260818-0424-todo-T222/codex-history"
OUT="$DIR/impl-review-prompt-round-${ROUND}.md"

{
    cat "$DIR/prompt-head.md"
    echo
    echo '## 詳細設計書'
    echo
    cat devnotes/20260818-0250-flash-notification-relay-sot/detailed-design.md
    echo
    echo '## 実装差分 (git diff)'
    echo
    echo '```diff'
    git diff HEAD --no-color -- app/ resources/ tests/ routes/
    echo '```'
    echo
    echo '## テスト結果'
    echo
    echo '```'
    cat "$TEST_SUMMARY"
    echo '```'
} > "$OUT"

wc -l "$OUT"
