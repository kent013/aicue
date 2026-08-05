#!/usr/bin/env bash
# scripts/audit-gate.sh — supply-chain 依存脆弱性 gate の実行ラッパ。
#
# composer / pnpm の audit を JSON で取得し、pyproject.toml があるリポジトリでは
# pip-audit も加えて scripts/audit-gate.ts に渡す。judging (severity 判定・
# accept-risk の expiry/cleanup・運用上限の機械強制) は audit-gate.ts に集約する。
#
# 責務境界: **shell = 「有効な出力が得られたか」だけを見る / TypeScript = JSON 妥当性と schema**。
# bash 側で JSON を検証しない (判定ロジックの二重管理を作らない)。
#
# 終了コード: 取得失敗 / high+ 未受容 / expiry 切れ / cleanup 漏れ / 上限超過 のいずれかで非ゼロ。
# 使い方: `pnpm run audit:gate` または直接 `bash scripts/audit-gate.sh`。
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$REPO_ROOT"

PNPM_JSON="$(mktemp)"
COMPOSER_JSON="$(mktemp)"
PIP_JSON=""
REQ_TXT=""
# 取得失敗の原因を残すための stderr ログ。set -u で未定義参照にならないよう
# **acquire を呼ぶ前に**生成し、trap の cleanup にも含める。
STDERR_LOG="$(mktemp)"
trap 'rm -f "$PNPM_JSON" "$COMPOSER_JSON" "$STDERR_LOG" ${PIP_JSON:+"$PIP_JSON"} ${REQ_TXT:+"$REQ_TXT"}' EXIT

# audit ツールの非ゼロ終了には 2 つの意味がある:
#   (i)  脆弱性を検出した      → **正常**。有効な JSON が出ているので judging へ進む
#   (ii) 取得自体に失敗した    → **異常**。ここで fail-closed に止める
# 両者は exit code では区別できないため、**出力が有効な JSON であるか**で区別する。
# 空出力を最小 JSON で捏造して先へ進める旧実装は「blocking gate なのに network 不通なら緑」
# という偽グリーンだったため廃止した (後方互換の並走を残さない)。
#
# 共通の取得本体。exit code の扱いだけを引数 require_zero で切り替える。
_run_acquire() {
    local label="$1" out="$2" require_zero="$3"; shift 3
    echo ">>> ${label}"
    # stderr は捨てない (取得失敗の原因をログに残す)。
    # ログは **取得ごとに truncate** する (> であって >> ではない)。追記にすると
    # composer 失敗時に pnpm の古い stderr が混ざって原因が読めなくなる。
    local code=0
    "$@" > "${out}" 2>"${STDERR_LOG}" || code=$?
    if [[ ! -s "${out}" ]]; then
        echo "::error::audit-gate: ${label} produced no output (exit ${code}). refusing to treat this as 'no advisories'." >&2
        sed -e 's/^/    /' "${STDERR_LOG}" >&2 || true
        exit 1
    fi
    if [[ "${require_zero}" == "yes" && "${code}" -ne 0 ]]; then
        echo "::error::audit-gate: ${label} failed (exit ${code}). its non-zero exit always means failure, never 'findings'." >&2
        sed -e 's/^/    /' "${STDERR_LOG}" >&2 || true
        exit 1
    fi
    # 取得は成功した。stderr は診断用に流しておく (警告等)。
    [[ -s "${STDERR_LOG}" ]] && sed -e 's/^/    /' "${STDERR_LOG}" >&2 || true
}

# audit ツール用: 非空出力を要求し、**非ゼロ exit は許容**する
# (非ゼロ = 脆弱性検出という正常系がありうるため。exit code では取得失敗と区別できない)。
acquire_audit()    { _run_acquire "$1" "$2" no  "${@:3}"; }

# 非 audit の前処理用: 非空出力 **かつ exit 0** を要求する。
# `uv export` の非ゼロには「検出した」という意味が無く、**常に失敗**である。
# 共通ハンドラで済ませると「部分的な / コメントだけの非空出力を残して失敗」したときに
# そのまま pip-audit へ進み、痩せた requirements に対する「advisory 0 件」で緑になる。
acquire_required() { _run_acquire "$1" "$2" yes "${@:3}"; }

acquire_audit "pnpm audit --json"            "$PNPM_JSON"     pnpm audit --json --audit-level=moderate
acquire_audit "composer audit --format=json" "$COMPOSER_JSON" composer audit --format=json

# PyPI 判定は pyproject.toml があるリポジトリでのみ有効化する (テンプレート初期状態では skip)。
if [[ -f pyproject.toml ]]; then
    PIP_JSON="$(mktemp)"
    REQ_TXT="$(mktemp)"
    acquire_required "uv export (requirements)" "$REQ_TXT"  uv export --format=requirements-txt --no-hashes --no-dev
    acquire_audit    "pip-audit --format=json"  "$PIP_JSON" uv tool run --from "pip-audit==2.7.3" pip-audit --format=json --requirement "$REQ_TXT"
fi

echo ">>> audit-gate judging"
pnpm exec tsx scripts/audit-gate.ts "$PNPM_JSON" "$COMPOSER_JSON" ${PIP_JSON:+"$PIP_JSON"}
