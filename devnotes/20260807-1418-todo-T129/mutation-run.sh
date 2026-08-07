#!/usr/bin/env bash
# T129 mutation 赤化確認スクリプト。
#
# ★revert は **git checkout を使わない**。本タスクの変更ファイルは
#   - 新規ファイル (untracked) … git checkout が効かず mutation が残留する
#   - 既存ファイル (tracked)   … git checkout すると **本タスクの変更ごと** HEAD に戻る
#   の 2 種類が混在するため、どちらも「実行前スナップショット」への cp で戻す。
set -u
cd /workspace/.claude/worktrees/tasks/T129 || exit 1

OUT=devnotes/20260807-1418-todo-T129
LOG="$OUT/mutation-raw.log"
SNAP=/tmp/T129-mutation-snapshot
: >"$LOG"

FILES=(
  app/Exceptions/InertiaExceptionRenderer.php
  app/Exceptions/ApiExceptionRenderer.php
  app/Support/Http/ErrorScreenDestinations.php
  app/Support/Http/ErrorScreenCachePolicy.php
  app/Support/Http/RetryAfterSeconds.php
  bootstrap/app.php
  resources/js/inertia.ts
  resources/js/pages/Error.svelte
)

rm -rf "$SNAP"; mkdir -p "$SNAP"
for f in "${FILES[@]}"; do
  mkdir -p "$SNAP/$(dirname "$f")"
  cp "$f" "$SNAP/$f"
done

restore_all() {
  for f in "${FILES[@]}"; do
    cp "$SNAP/$f" "$f"
  done
}

RENDERER=app/Exceptions/InertiaExceptionRenderer.php
BOOT=bootstrap/app.php
DEST=app/Support/Http/ErrorScreenDestinations.php
RETRY=app/Support/Http/RetryAfterSeconds.php
API=app/Exceptions/ApiExceptionRenderer.php
POLICY=app/Support/Http/ErrorScreenCachePolicy.php
INERTIA_TS=resources/js/inertia.ts
ERRPAGE=resources/js/pages/Error.svelte

run_php() {
  local label="$1"; shift
  echo "=== $label ===" >>"$LOG"
  for p in "$@"; do
    echo "--- composer test -- $p" >>"$LOG"
    composer test -- "$p" 2>&1 | grep -E '^\{"tool"' | head -1 >>"$LOG"
  done
}

run_js() {
  local label="$1"; shift
  echo "=== $label ===" >>"$LOG"
  echo "--- pnpm vitest run $*" >>"$LOG"
  pnpm vitest run "$@" 2>&1 | grep -E "Test Files|Tests  |FAIL |AssertionError" | head -20 >>"$LOG"
}

py() { python3 -c "$1"; }

# ---------------- M4: StaleAssetVersion 分岐を削除 ----------------
python3 - <<'PY'
p='app/Exceptions/InertiaExceptionRenderer.php'
s=open(p).read()
s=s.replace("""        if (! self::assetVersionMatches($request)) {
            return InertiaErrorScreenPassthrough::StaleAssetVersion;
        }

""","")
open(p,'w').write(s)
PY
run_php "M4 passthroughReason の StaleAssetVersion 分岐を削除" tests/Feature/Errors/InertiaErrorScreenPassthroughTest.php
restore_all

# ---------------- M5: 2 本目の respond を追加 ----------------
python3 - <<'PY'
p='bootstrap/app.php'
s=open(p).read()
s=s.replace("""            return InertiaExceptionRenderer::render($response, $request) ?? $response;
        });
    })->create();""","""            return InertiaExceptionRenderer::render($response, $request) ?? $response;
        });

        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            return $response;
        });
    })->create();""")
open(p,'w').write(s)
PY
run_php "M5 bootstrap/app.php に 2 本目の respond を追加" tests/Architecture/InertiaErrorScreenContractTest.php tests/Feature/Errors/ErrorPagesTest.php
restore_all

# ---------------- M6: bootstrap に Inertia::render 直書き ----------------
python3 - <<'PY'
p='bootstrap/app.php'
s=open(p).read()
s=s.replace("""            return InertiaExceptionRenderer::render($response, $request) ?? $response;""",
"""            if ($status === 599) {
                return \\Inertia\\Inertia::render('Error', [])->toResponse($request);
            }

            return InertiaExceptionRenderer::render($response, $request) ?? $response;""")
open(p,'w').write(s)
PY
run_php "M6 bootstrap/app.php に Inertia::render を直書き" tests/Architecture/InertiaErrorScreenContractTest.php
restore_all

# ---------------- M7: Error.svelte を削除 ----------------
rm "$ERRPAGE"
run_php "M7 resources/js/pages/Error.svelte を削除 (PHP gate)" tests/Architecture/InertiaRenderPageExistsInvariantTest.php
run_js "M7 resources/js/pages/Error.svelte を削除 (JS gate)" tests/js/architecture/inertia-eager-error-page.test.ts
restore_all

# ---------------- M8: eager: true を外す ----------------
python3 - <<'PY'
p='resources/js/inertia.ts'
s=open(p).read()
s=s.replace("""export const EAGER_PAGES = import.meta.glob<ResolvedComponent>("./pages/Error.svelte", {
    eager: true,
});""","""export const EAGER_PAGES: Record<string, ResolvedComponent> = {};""")
open(p,'w').write(s)
PY
run_js "M8 inertia.ts の { eager: true } を外す" tests/js/architecture/inertia-eager-error-page.test.ts
restore_all

# ---------------- M9: try/catch を外す ----------------
python3 - <<'PY'
import re
p='app/Exceptions/InertiaExceptionRenderer.php'
s=open(p).read()
s=s.replace("""        try {
            if (self::passthroughReason""","""        {
            if (self::passthroughReason""")
s=re.sub(r"        \} catch \(Throwable \$e\) \{.*?return null;\n        \}\n", "        }\n", s, flags=re.S)
open(p,'w').write(s)
PY
run_php "M9 render() の try/catch を外す" tests/Feature/Errors/InertiaErrorScreenPassthroughTest.php
restore_all

# ---------------- M10: Retry-After ヘッダ再設定を削除 ----------------
python3 - <<'PY'
p='app/Exceptions/InertiaExceptionRenderer.php'
s=open(p).read()
s=s.replace("""            if ($retryAfterSeconds !== null) {
                $rendered->headers->set('Retry-After', (string) $retryAfterSeconds);
            }
""","")
open(p,'w').write(s)
PY
run_php "M10 差し替え応答の Retry-After ヘッダ再設定を削除" tests/Feature/Errors/InertiaErrorScreenTest.php
restore_all

# ---------------- M11: D1 分岐を削除 ----------------
python3 - <<'PY'
p='app/Support/Http/ErrorScreenDestinations.php'
s=open(p).read()
s=s.replace("""        if ($status->forcesGuestDestinations()) {
            return self::guest();
        }

""","")
open(p,'w').write(s)
PY
run_php "M11 ErrorScreenDestinations::for() の D1 分岐を削除" tests/Feature/Errors/InertiaErrorScreenTest.php tests/Unit/Http/ErrorScreenDestinationsTest.php
restore_all

# ---------------- M12: 負数 / 非数値判定を削除 ----------------
python3 - <<'PY'
p='app/Support/Http/RetryAfterSeconds.php'
s=open(p).read()
s=s.replace("""        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || $value === '' || ! ctype_digit($value)) {""",
"""        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || $value === '') {""")
s=s.replace("""        return $seconds >= 0 ? $seconds : null;""","""        return $seconds;""")
open(p,'w').write(s)
PY
run_php "M12 RetryAfterSeconds::parse() の負数判定を削除" tests/Unit/Http/RetryAfterSecondsTest.php tests/Feature/Api/ApiRetryAfterContractTest.php
restore_all

# ---------------- M13: $authenticated の短絡を戻す ----------------
python3 - <<'PY'
p='app/Exceptions/InertiaExceptionRenderer.php'
s=open(p).read()
s=s.replace("""            $authenticated = $status->forcesGuestDestinations()
                ? false
                : $request->user() !== null;

            $data = new ErrorScreenData(
                status: $status,
                retryAfterSeconds: $retryAfterSeconds,
                destinations: ErrorScreenDestinations::for($status, $authenticated),
            );""","""            $data = new ErrorScreenData(
                status: $status,
                retryAfterSeconds: $retryAfterSeconds,
                destinations: ErrorScreenDestinations::for($status, $request->user() !== null),
            );""")
open(p,'w').write(s)
PY
run_php "M13 authenticated の短絡を戻す (引数評価順の罠を再導入)" tests/Feature/Errors/InertiaErrorScreenTest.php
restore_all

# ---------------- M14: report($e) を削除 ----------------
python3 - <<'PY'
p='app/Exceptions/InertiaExceptionRenderer.php'
s=open(p).read()
s=s.replace("""            report($e);

            return null;""","""            unset($e);

            return null;""")
open(p,'w').write(s)
PY
run_php "M14 catch の report(\$e) を削除" tests/Feature/Errors/InertiaErrorScreenPassthroughTest.php
restore_all

# ---------------- M15: extraHeaders の Retry-After 正規化を外す ----------------
python3 - <<'PY'
p='app/Exceptions/ApiExceptionRenderer.php'
s=open(p).read()
s=s.replace("""            if (strcasecmp($name, 'Retry-After') === 0) {
                $seconds = RetryAfterSeconds::parse($value);
                if ($seconds !== null) {
                    $headers[$name] = (string) $seconds;
                }

                continue;
            }

""","")
open(p,'w').write(s)
PY
run_php "M15 extraHeaders() の Retry-After 正規化を外す" tests/Feature/Api/ApiRetryAfterContractTest.php
restore_all

# ---------------- M16: キャッシュ表現の付与を削除 ----------------
python3 - <<'PY'
p='app/Exceptions/InertiaExceptionRenderer.php'
s=open(p).read()
s=s.replace("""            ErrorScreenCachePolicy::apply($rendered);
""","")
open(p,'w').write(s)
PY
run_php "M16 ErrorScreenCachePolicy::apply() の呼び出しを削除" tests/Feature/Errors/InertiaErrorScreenTest.php
restore_all

# ---------------- M17: addCacheControlDirective を set に戻す ----------------
python3 - <<'PY'
p='app/Support/Http/ErrorScreenCachePolicy.php'
s=open(p).read()
s=s.replace("""        $response->headers->addCacheControlDirective('no-store');
        $response->setPrivate();""","""        $response->headers->set('Cache-Control', 'no-store, private');""")
open(p,'w').write(s)
PY
run_php "M17 addCacheControlDirective を headers->set に戻す" tests/Unit/Http/ErrorScreenCachePolicyTest.php
restore_all

echo "=== DONE ===" >>"$LOG"
echo "--- 復旧確認 (snapshot との差分。空なら mutation は 1 つも残っていない) ---" >>"$LOG"
for f in "${FILES[@]}"; do
  diff -q "$SNAP/$f" "$f" >>"$LOG" 2>&1
done
echo "--- git status ---" >>"$LOG"
git status --short >>"$LOG"
