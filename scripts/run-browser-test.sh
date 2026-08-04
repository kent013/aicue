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
#     (Playwright 既定の --disable-back-forward-cache で bfcache 自体が無効。
#      仮に有効化しても no-store ページは cookie 変更で evict される)、
#     WebKit レーンが正本になる (ただし WebKit も現状は復元せず skip。原因未特定)。
#     実行時間を理由に WebKit を落とすことはしない (落とすと恒久自動回帰が消える)。
#     レーンの意味と保証範囲は docs/supported-browsers.md。
#     レーン限定実行は BROWSER_TEST_LANES="chromium" のように上書きする。
#   - Browser lane は Feature lane と同じ worktree 固有 base テスト DB
#     (<slug>_test_<worktree-hash>) と per-worker DB (_test_<token>) を使い、さらに
#     実ブラウザ 2 engine と **マシン全体スコープの playwright 掃除** を伴う。
#     排他は scripts/global-test-lock.sh のグローバルロック (同一 UID・同一マシン) に
#     一本化した。旧 worktree-local ロックは cross-worktree の相互破壊を防げないため廃止した。
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

# --- bug-hunt 併走の pre-flight guard (best-effort。保証ではない) ---
#
# **ロック取得より前**に実行する。取得後に落とすと、先行レーンの終了を数分待ってから
# 「bug-hunt が走っているので実行できません」と言うことになり、待ち時間が無駄になる。
#
# bug-hunt は本ロック規約に参加しない (意図的に隔離された並列実行基盤で、
# global lock を被せると 8 並列が 1 直列に潰れる)。そのため bug-hunt の
# `playwright-cli kill-all` (@playwright/cli) が Browser lane の run-server を
# 巻き込む可能性を **こちらからは証明できない**。
#
# ここで行うのは「起動時点で bug-hunt が既に走っている」という頻度の高いケースだけを
# 捕まえる best-effort guard であり、**TOCTOU がある** (Browser lane 開始後に
# bug-hunt が起動する経路、bug-hunt が listen していない起動フェーズは捕まえられない)。
# 非干渉は保証しない — 失敗モードが偽赤であって偽グリーンではないため受容する。
#
# 検知は bash の /dev/tcp のみを使う (ss/lsof/netstat の可用性と出力形式に依存しない)。
# bug-hunt は 127.0.0.1:801N に明示 bind するので IPv4 loopback だけ見れば足りる。
# /dev/tcp が使えないシェルでは検査を skip して続行する (guard であって保証ではない)。
bughunt_port_in_use() {
    local port
    for port in {8010..8018}; do
        if (exec 3<>"/dev/tcp/127.0.0.1/${port}") 2>/dev/null; then
            exec 3<&- 3>&- 2>/dev/null || true
            printf '%s\n' "${port}"
            return 0
        fi
    done
    return 1
}

if busy_port="$(bughunt_port_in_use)"; then
    echo "ERROR: bug-hunt 環境が走行中です (127.0.0.1:${busy_port} が listen 中)。" >&2
    echo "       bug-hunt の playwright-cli kill-all が Browser lane の run-server を" >&2
    echo "       巻き込む可能性があるため、bug-hunt の終了を待ってから実行してください" >&2
    echo "       (scripts/bug-hunt-shard.sh teardown / docs/testing-browser.md)。" >&2
    exit 1
fi

# --- グローバルテストロック (旧 worktree-local ロックを置き換え) ---
# shellcheck source=scripts/global-test-lock.sh
. "$(pwd)/scripts/global-test-lock.sh"
global_test_lock_acquire "composer test:browser"

# orphan 化した playwright run-server (pest-plugin-browser 同梱 Playwright) を掃除する。
#
# **@playwright/cli は対象外にする**: bug-hunt が使うのは @playwright/cli であり、
# 別プロセス名前空間である。pgrep のパターンは既存のまま維持し (正のマッチを弱めない)、
# cmdline に "@playwright/" を含むプロセスを明示除外することで、こちらの掃除が
# bug-hunt のブラウザを巻き込む経路 (方向 1) を構造的に塞ぐ。
cleanup_orphan_playwright() {
    local pid ppid args
    for pid in $(pgrep -f "playwright/cli.js run-server" 2>/dev/null || true); do
        args="$(ps -o args= -p "${pid}" 2>/dev/null || true)"
        case "${args}" in
            *"@playwright/"*) continue ;;   # bug-hunt の @playwright/cli は触らない
        esac
        ppid="$(ps -o ppid= -p "${pid}" 2>/dev/null | tr -d ' ' || true)"
        if [ "${ppid}" = "1" ]; then
            kill "${pid}" 2>/dev/null || true
        fi
    done
}

# 起動時の掃除は従来どおり。EXIT trap は **自前で張らず** ライブラリへ登録する。
#
# `trap cleanup_orphan_playwright EXIT` を自前で張ると、acquire 前なら acquire 側の
# `trap '_gtl_cleanup' EXIT` に上書きされ、acquire 後ならこちらが `_gtl_cleanup` を消して
# **ロックが永久に解放されなくなる**。EXIT trap の所有者はライブラリ 1 箇所に固定する。
# 登録したフックは **ロックを保持したまま** 実行される (次のレーンが入る前に掃除を終える)。
cleanup_orphan_playwright
global_test_lock_on_exit cleanup_orphan_playwright

global_test_lock_run php artisan config:clear --ansi

# worktree 固有の base テスト DB (<slug>_test_<worktree-hash>) を冪等に用意する。
# DB 名の安全検証は tests/bootstrap.php の単一点ガードが担う (run-test.sh と同じ)。
global_test_lock_run php scripts/ci/ensure-test-db.php

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

# レーンは順に実行し、**どれかが失敗したら最後に非ゼロで終わる**
# (先頭レーンの失敗で後続レーンを飛ばすと WebKit の回帰を見落とすため)。
# ロックは acquire で 1 回取ったまま 2 レーンを通して保持される (run は取得しない)。
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
    global_test_lock_run vendor/bin/pest -c phpunit.browser.xml "${PEST_PARALLEL_ARGS[@]}" \
        --browser "${browser}" "$@" || code=$?
    if [ "${code}" -ne 0 ]; then
        overall="${code}"
    fi

    cleanup_orphan_playwright
done

exit "${overall}"
