#!/usr/bin/env bash
#
# scripts/run-browser-test.sh — Browser テスト (pest-plugin-browser) を排他 + 並列上限付きで実行する。
#
# 背景:
#   - pest-plugin-browser は in-process サーバ + Playwright を起動する。
#     `--parallel` のプロセス数を未指定 (= nproc) にするとブラウザの同時起動で
#     devcontainer がハングし得るため、既定 1 = 直列に固定する
#     (上書きは BROWSER_TEST_PROCESSES=N。2 以上でのみ parallel runner を使う。理由は下記)。
#   - **Chromium / WebKit の 2 レーンを実行する契約**。bfcache 復元シナリオ
#     (tests/Browser/AuthenticatedPageBfcacheTest.php) は Chromium では再現できず
#     (no-store ページを bfcache から evict するため)、WebKit レーンが正本になる。
#     実行時間を理由に WebKit を落とすことはしない (落とすと恒久自動回帰が消える)。
#     レーンの意味と保証範囲は docs/supported-browsers.md。
#     レーン限定実行は BROWSER_TEST_LANES="chromium" のように上書きする。
#   - Browser lane は Feature lane と同じ worktree 固有 base テスト DB
#     (<slug>_test_<worktree-hash>) と per-worker DB (_test_<token>) を使うため、
#     composer test と同じ lock file (storage/framework/testing/test.lock) で
#     相互排他する (同時実行すると migrate:fresh / per-worker DB が衝突する)。
#     lock は worktree-local なので別 worktree の test は止めない。
#   - pest 終了後に playwright run-server (node) が orphan (PPID 1) として残る
#     プラグイン側の後始末漏れがある。orphan は親プロセスのパイプ fd を握って
#     呼び出し元を詰まらせる + プロセスを leak するため、実行前後に掃除する。
#
# 前提: pnpm build 済み (実ブラウザが public/build を読む) +
#       `pnpm exec playwright install chromium webkit` 済み。詳細は docs/testing-browser.md。
#
# 使い方:
#   composer test:browser                          # 全 Browser テスト (Chromium → WebKit)
#   composer test:browser -- --filter=...          # pest 引数の追加 (両レーンへ渡る)
#   BROWSER_TEST_LANES=webkit composer test:browser # レーン限定

set -euo pipefail
cd "$(dirname "$0")/.."

PROCESSES="${BROWSER_TEST_PROCESSES:-1}"
LANES="${BROWSER_TEST_LANES:-chromium webkit}"

# composer test (scripts/run-test.sh) と同一 lock で相互排他する。
# flock(1) が無い環境 (素の macOS 等) では排他なしで実行する (devcontainer/CI では排他あり)。
LOCK_FILE="storage/framework/testing/test.lock"   # worktree-local
mkdir -p "$(dirname "$LOCK_FILE")"
if command -v flock >/dev/null 2>&1; then
    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
        echo "ERROR: another composer test / test:browser is running in this worktree; refusing to start" >&2
        echo "       lock file: $LOCK_FILE" >&2
        exit 1
    fi
fi

cleanup_orphan_playwright() {
    local pid ppid
    for pid in $(pgrep -f "playwright/cli.js run-server" 2>/dev/null || true); do
        ppid=$(ps -o ppid= -p "${pid}" 2>/dev/null | tr -d ' ' || true)
        if [ "${ppid}" = "1" ]; then
            kill "${pid}" 2>/dev/null || true
        fi
    done
}

cleanup_orphan_playwright
trap cleanup_orphan_playwright EXIT

php artisan config:clear --ansi

# worktree 固有の base テスト DB (<slug>_test_<worktree-hash>) を冪等に用意する。
# DB 名の安全検証は tests/bootstrap.php の単一点ガードが担う (run-test.sh と同じ)。
php scripts/ci/ensure-test-db.php

# 既定 (PROCESSES=1) では pest の parallel runner を使わない。
# 1 プロセスは直列と等価である一方、`--parallel --processes=1` で Browser lane を
# 走らせると **全テスト成功でも終了コードが 1 になる** ケースを実測した
# (pest-plugin-browser のページ操作を含むテストで再現。--processes=2 や parallel なしでは
# 発生しない)。緑を赤と誤報告する = レーンの信頼性が失われるため、既定は直列にする。
# 並列数を明示指定 (BROWSER_TEST_PROCESSES>=2) したときのみ parallel runner を使う。
PEST_PARALLEL_ARGS=()
if [ "${PROCESSES}" -gt 1 ]; then
    PEST_PARALLEL_ARGS=(--parallel --processes="${PROCESSES}")
fi

# lock fd (9) を pest / playwright に継承させない。orphan run-server が fd を
# 握ると lock が永久に解放されないため、実行コマンドへ渡す瞬間に 9>&- で閉じる
# (親シェルの fd 9 = lock は保持されたまま)。
#
# レーンは順に実行し、**どれかが失敗したら最後に非ゼロで終わる**
# (先頭レーンの失敗で後続レーンを飛ばすと WebKit の回帰を見落とすため)。
overall=0
for lane in ${LANES}; do
    case "${lane}" in
        chromium) browser="chrome" ;;
        webkit)   browser="safari" ;;   # pest-plugin-browser の safari = Playwright webkit
        *)
            echo "ERROR: unknown browser lane '${lane}' (chromium / webkit)" >&2
            exit 2
            ;;
    esac

    echo ""
    echo "=== Browser lane: ${lane} (playwright: ${browser}) ==="

    code=0
    vendor/bin/pest -c phpunit.browser.xml "${PEST_PARALLEL_ARGS[@]}" \
        --browser "${browser}" "$@" 9>&- || code=$?
    if [ "${code}" -ne 0 ]; then
        overall="${code}"
    fi

    cleanup_orphan_playwright
done

exit "${overall}"
