#!/usr/bin/env bash
# F-1b の実証: aigenba L526 と同形の代入が set -x でキーを露出するか
ANTHROPIC_API_KEY="sk-ant-SECRET-DO-NOT-LEAK"
set -x
real_llm_env=()
[[ -n "${ANTHROPIC_API_KEY:-}" ]] && real_llm_env+=("ANTHROPIC_API_KEY=${ANTHROPIC_API_KEY}")
set +x
