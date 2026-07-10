#!/usr/bin/env bash
# scripts/audit-gate.sh — supply-chain 依存脆弱性 gate のローカル実行ラッパ。
#
# composer / pnpm の audit を JSON で取得し、pyproject.toml があるリポジトリでは
# pip-audit も加えて scripts/audit-gate.ts に渡す。judging (severity 判定・
# accept-risk の expiry/cleanup・運用上限の機械強制) は audit-gate.ts に集約する。
#
# 終了コード: high+ 未受容 / expiry 切れ / cleanup 漏れ / 上限超過 のいずれかで非ゼロ。
# 使い方: `pnpm run audit:gate` または直接 `bash scripts/audit-gate.sh`。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

PNPM_JSON="$(mktemp)"
COMPOSER_JSON="$(mktemp)"
PIP_JSON=""
REQ_TXT=""
trap 'rm -f "$PNPM_JSON" "$COMPOSER_JSON" ${PIP_JSON:+"$PIP_JSON"} ${REQ_TXT:+"$REQ_TXT"}' EXIT

# audit 自体の exit code は無視 (脆弱性検出で非ゼロを返すため)。judging は audit-gate.ts。
# 取得失敗 (network 不通等) で空 JSON になった場合も gate は走る (advisory 0 件扱い)。
echo ">>> pnpm audit --json"
pnpm audit --json --audit-level=moderate > "$PNPM_JSON" 2>/dev/null || true
echo ">>> composer audit --format=json"
composer audit --format=json > "$COMPOSER_JSON" 2>/dev/null || true

# 空ファイル (audit が何も出力しなかった) は最小 JSON で補完し parse 失敗を防ぐ。
[[ -s "$PNPM_JSON" ]] || echo '{"advisories":{}}' > "$PNPM_JSON"
[[ -s "$COMPOSER_JSON" ]] || echo '{"advisories":{}}' > "$COMPOSER_JSON"

# PyPI 判定は pyproject.toml があるリポジトリでのみ有効化する (テンプレート初期状態では skip)。
if [[ -f pyproject.toml ]]; then
    echo ">>> pip-audit --format=json (pyproject.toml detected)"
    PIP_JSON="$(mktemp)"
    REQ_TXT="$(mktemp)"
    uv export --format=requirements-txt --no-hashes --no-dev > "$REQ_TXT" 2>/dev/null || true
    uv tool run --from "pip-audit==2.7.3" pip-audit --format=json --requirement "$REQ_TXT" > "$PIP_JSON" 2>/dev/null || true
    [[ -s "$PIP_JSON" ]] || echo '{"dependencies":[]}' > "$PIP_JSON"
fi

echo ">>> audit-gate judging"
pnpm exec tsx scripts/audit-gate.ts "$PNPM_JSON" "$COMPOSER_JSON" ${PIP_JSON:+"$PIP_JSON"}
