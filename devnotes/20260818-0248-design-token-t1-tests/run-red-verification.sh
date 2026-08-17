#!/usr/bin/env bash
# 感度確認 (T221) — 故障を 1 件ずつ注入して、狙った assertion が赤くなることを確かめる。
#
# 一時スクリプト (devnotes 配下。scripts/ へ昇格しない)。
# グローバルテストロックは呼び出し側が 1 回だけ取る前提で、この中では取らない
# (R1〜R6 のたびにロックを取り直すと待ちが積み上がるため)。
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
OUT="$ROOT/devnotes/20260818-0248-design-token-t1-tests/red-verification-raw.txt"
: > "$OUT"

APP_CSS="$ROOT/resources/css/app.css"
TOKENS_CSS="$ROOT/resources/css/tokens.css"
DOC="$ROOT/docs/design-system.md"
DUMMY="$ROOT/tests/js/styles/dummy-sensitivity.test.ts"

run_suite() {
    local label="$1"
    {
        echo "===================================================================="
        echo "== $label"
        echo "===================================================================="
    } >> "$OUT"
    (cd "$ROOT" && pnpm exec vitest run tests/js/styles tests/js/architecture/contrast-invariant.test.ts 2>&1) \
        | grep -E '^\s+(×|✓ tests|❯ tests)|Tests  |Test Files  ' >> "$OUT"
}

restore() {
    cp "$ROOT/.sensitivity-bak/app.css" "$APP_CSS"
    cp "$ROOT/.sensitivity-bak/tokens.css" "$TOKENS_CSS"
    cp "$ROOT/.sensitivity-bak/design-system.md" "$DOC"
    rm -f "$DUMMY"
}

mkdir -p "$ROOT/.sensitivity-bak"
cp "$APP_CSS" "$ROOT/.sensitivity-bak/app.css"
cp "$TOKENS_CSS" "$ROOT/.sensitivity-bak/tokens.css"
cp "$DOC" "$ROOT/.sensitivity-bak/design-system.md"
trap restore EXIT

run_suite "R0 基準 (無変異。全緑であること)"

# R1: app.css から tokens.css の取り込みを消す
perl -0pi -e "s{\@import '\./tokens\.css';\n}{}" "$APP_CSS"
run_suite "R1 app.css から @import './tokens.css' を消す"
restore

# R2: @theme を素の :root にする
perl -0pi -e 's/\@theme \{/:root {/' "$TOKENS_CSS"
run_suite "R2 tokens.css の @theme を :root にする"
restore

# R3: @utility text-body を消す
perl -0pi -e 's/\@utility text-body \{[^}]*\}\n\n//' "$TOKENS_CSS"
run_suite "R3 tokens.css の @utility text-body を消す"
restore

# R4: --color-danger の値だけ変える
perl -0pi -e 's/--color-danger:(\s*)#b91c1c;/--color-danger:${1}#a01010;/' "$TOKENS_CSS"
run_suite "R4 tokens.css の --color-danger の値を変える"
restore

# R5: docs/design-system.md から節を 1 つ消す
perl -0pi -e 's/^## file-scoped allowlist の運用$/## 別名にした節/m' "$DOC"
run_suite "R5 docs/design-system.md の '## file-scoped allowlist の運用' を改名する"
restore

# R6: tests/js/styles/ にダミーの .test.ts を置く
cat > "$DUMMY" <<'EOF'
import { describe, expect, it } from "vitest";

describe("dummy", () => {
    it("always passes", () => {
        expect(true).toBe(true);
    });
});
EOF
run_suite "R6 tests/js/styles/ にダミーの .test.ts を置く"
restore

rm -rf "$ROOT/.sensitivity-bak"
trap - EXIT
echo "done -> $OUT"
