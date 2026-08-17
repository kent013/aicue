#!/usr/bin/env bash
#
# T215: 移植した 31 ファイルが移植元と一致していることを確かめる**一時スクリプト**。
#
# - `scripts/` へは昇格させない (1 回きりの移植の検証であり、恒久的に回す性質が無い。
#   移植元へ毎回アクセスする検査を CI の前提にしないため。AGENTS.md §テストレーンの外部 HTTP 出口)。
# - 実行には `gh` の認証が要る。移植を行う人の手元でだけ実行する。
#
# 使い方: bash devnotes/20260817-1309-todo-t215-job-deferral-gate-port/verify-byte-parity.sh
#
set -uo pipefail

REF=9b54b74522a6627dd44bd828279a1703d38c398e
REPO=rio-development/laravel-claude-template
ROOT=$(cd "$(dirname "$0")/../.." && pwd)
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

FAILED=0
fail() { echo "NG: $*" >&2; FAILED=1; }

fetch() { # fetch <repo-relative-path> -> $TMP/<path>
  local rel="$1"
  mkdir -p "$TMP/$(dirname "$rel")"
  gh api "repos/$REPO/contents/$rel?ref=$REF" --jq '.content' | base64 -d > "$TMP/$rel" || return 1
  [ -s "$TMP/$rel" ]
}

# ---------------------------------------------------------------------------
# (1) byte 一致を要求する 28 本 (glob で数えない = 取り違えを目で見えるようにする)
# ---------------------------------------------------------------------------
IDENTICAL=(
  tests/Support/Queue/JobDeferralContract.php
  tests/Support/Queue/JobDeferralScanner.php
  tests/Support/Queue/DeferralProbeAliasedHorizon.php
  tests/Support/Queue/DeferralProbeInheritedHorizon.php
  tests/Support/Queue/DeferralProbeInheritedHorizonBase.php
  tests/Support/Queue/DeferralProbeInheritedHorizonZero.php
  tests/Support/Queue/DeferralProbeInheritedTries.php
  tests/Support/Queue/DeferralProbeInnerReleasingTrait.php
  tests/Support/Queue/DeferralProbeInteractsOnly.php
  tests/Support/Queue/DeferralProbeMissingContract.php
  tests/Support/Queue/DeferralProbeNestedTraitJob.php
  tests/Support/Queue/DeferralProbeNullableHorizon.php
  tests/Support/Queue/DeferralProbeOuterTrait.php
  tests/Support/Queue/DeferralProbePropertyHorizon.php
  tests/Support/Queue/DeferralProbeShadowedHorizon.php
  tests/Support/Queue/DeferralProbeTimestampHorizon.php
  tests/Support/Queue/DeferralProbeTriesAttributeTrait.php
  tests/Support/Queue/DeferralProbeTriesBase.php
  tests/Support/Queue/DeferralProbeTriesMethod.php
  tests/Support/Queue/DeferralProbeTriesOuterAttributeTrait.php
  tests/Support/Queue/DeferralProbeTriesProperty.php
  tests/Support/Queue/DeferralProbeTriesUninitialized.php
  tests/Support/Queue/DeferralProbeTriesViaNestedTrait.php
  tests/Support/Queue/DeferralProbeTriesViaTrait.php
  tests/Support/Queue/DeferralProbeZeroMaxExceptions.php
  tests/Support/Queue/DeferringNoContractProbeJob.php
  tests/Support/Queue/DeferringReleaseProbeJob.php
  tests/Support/Queue/DeferringThrowProbeJob.php
)

# ---------------------------------------------------------------------------
# (2) 部分適合を許す 3 本
# ---------------------------------------------------------------------------
PARTIAL=(
  tests/Support/Queue/DeferringJobTemplate.php
  tests/Feature/Queue/DeferredRetryHorizonTest.php
  tests/Architecture/JobDeferralTerminationGateTest.php
)

# (3) 件数の自己検査 (列挙の書き漏らしを黙って通さない)
if [ "${#IDENTICAL[@]}" -ne 28 ]; then fail "byte 一致の列挙が 28 本でない (${#IDENTICAL[@]})"; fi
if [ "${#PARTIAL[@]}" -ne 3 ]; then fail "部分適合の列挙が 3 本でない (${#PARTIAL[@]})"; fi
TOTAL=$(( ${#IDENTICAL[@]} + ${#PARTIAL[@]} ))
if [ "$TOTAL" -ne 31 ]; then fail "合計が 31 本でない ($TOTAL)"; fi

echo ">>> 移植元 $REPO@$REF から 31 本を取得する"
for rel in "${IDENTICAL[@]}" "${PARTIAL[@]}"; do
  fetch "$rel" || fail "取得に失敗: $rel"
done
[ "$FAILED" -eq 0 ] || { echo "取得に失敗したため中断する"; exit 1; }

echo ">>> (1) byte 一致 28 本"
for rel in "${IDENTICAL[@]}"; do
  if cmp -s "$TMP/$rel" "$ROOT/$rel"; then
    echo "  OK   $rel"
  else
    fail "$rel が移植元と一致しない"
    diff -u "$TMP/$rel" "$ROOT/$rel" | head -40 >&2
  fi
done

# 許容差分の照合: 移植元と本リポジトリの normal diff から `<` / `>` 行だけを取り出し、
# 期待値 (リテラル) と完全一致させる。想定外の差分は行番号つきで出力する。
check_partial_lines() { # check_partial_lines <rel> <expected-file>
  local rel="$1" expected="$2" actual="$TMP/actual.diff"
  diff "$TMP/$rel" "$ROOT/$rel" | grep -E '^[<>]' > "$actual"
  if diff -q "$expected" "$actual" >/dev/null; then
    echo "  OK   $rel (許容差分どおり)"
  else
    fail "$rel の差分が設計の許容差分と違う"
    echo "--- 想定外の差分 (行番号つき) ---" >&2
    diff -u "$TMP/$rel" "$ROOT/$rel" >&2
  fi
}

echo ">>> (2) 部分適合 3 本"

# 2-1. 配布雛形: docblock 2 箇所 (目録の所在 / 回収経路の有無)
cat > "$TMP/expect-template.txt" <<'EXPECTED'
<  *      分類は `jobDeferralTerminationInventory()` (tests/Pest.php) へ全数申告する。
>  *      分類は `jobDeferralTerminationInventory()`
>  *      (tests/Architecture/JobDeferralTerminationGateTest.php) へ全数申告する。
<  *      検出できない (本リポジトリに回収機構は無い。stuck-job-recovery の領分)。
>  *      検出できない。**本リポジトリには回収の入口が既にある** —
>  *      `work:recover-stuck --stream=<key>` ただ 1 本である (AGENTS.md ドメイン規約 14)。
>  *      退避を正常系に持つジョブを足すときは、そこへ系列を 1 つ足すかどうかを必ず判断すること
>  *      (`App\Enums\Recovery\RecoveryStream` の case / registry / 目録 / Schedule の 4 つを同時に更新する)。
EXPECTED
check_partial_lines tests/Support/Queue/DeferringJobTemplate.php "$TMP/expect-template.txt"

# 2-2. 振る舞い検査: docblock 2 箇所 (同型の先例テストの参照先) +
#      cache 解決の 1 式直結化 (実装中に発覚した必要最小限の適合。理由は下記)。
#
#      ★ 当初の設計は docblock 2 箇所のみを許容差分としていたが、実装後の composer test で
#        `CachePayloadPlainDataGateTest` (aicue 固有の既存 gate。移植元には存在しない) が
#        「role=driver-handoff (T215 で追加) を宣言したのに実測 0 件」で赤くなることが分かった。
#        原因は `$cache = app('cache'); ... $cache->driver()` という**変数を介した 2 行の形**を
#        同 gate の受け手検出 (型宣言の直後に現れる変数だけを追跡する簡易ヒューリスティック) が
#        拾えないため (docblock の `@var` 型注釈はトークン解析の対象外)。
#        `app('cache')->driver()` を 1 式に直結すれば `app()` 呼び出しへの直接連鎖として検出され、
#        読み出し・書き込みを一切行わず driver を解決するだけであることを実測で裏取りできる。
#        振る舞い (「cache を明示的に渡さないと $maxExceptions が無言で効かなくなる」= B4 / M11) は
#        変えていない。未使用になった `use ...Factory as CacheFactory;` importも合わせて削る。
cat > "$TMP/expect-behavior.txt" <<'EXPECTED'
< use Illuminate\Contracts\Cache\Factory as CacheFactory;
<  * (既存 `tests/Feature/Queue/QueuedJobLeaseGuardTest.php` と同じ形)。
>  * (既存 `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` と同じ形)。
<     /** @var CacheFactory $cache */
<     $cache = app('cache');
< 
<     $worker->setCache($cache->driver())
>     // ★ aicue 適合 (T215): `app('cache')->driver()` を 1 式で直結する。
>     //   変数へ分けて渡すと `CachePayloadPlainDataGateTest` の受け手検出 (型宣言直後の変数だけを
>     //   追跡する簡易ヒューリスティック) が `$cache` を拾えず、role=driver-handoff の裏取りが
>     //   「呼び出し 0 件」で落ちる (docblock の `@var` 型注釈はトークン解析の対象外)。
>     //   1 式に直結すれば `app()` 呼び出しへ直接連鎖するチェーンとして検出され、
>     //   読み出し・書き込みを一切行わず driver を解決するだけであることを実測で裏取りできる。
>     $worker->setCache(app('cache')->driver())
<  * `tests/Feature/Queue/QueuedJobLeaseGuardTest.php` も同じ理由でイベントを数えている。
>  * `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` も同じ理由でイベントを数えている。
EXPECTED
check_partial_lines tests/Feature/Queue/DeferredRetryHorizonTest.php "$TMP/expect-behavior.txt"

# 2-3. 静的 gate: 追加だけ (削除・書き換えは 1 行も許さない)。
#      追加してよいのは use 行 / D25 の冒頭コメント 1 段落 / 目録 2 関数の 3 領域で、
#      16 ケース (E1-E16) は 1 行も変えない。ここでは
#        (a) normal diff の指令がすべて `a` (append) であること
#        (b) 追加行数が期待値ちょうどであること
#      を見る。どちらかが崩れれば移植元の本文へ手が入ったことになる。
GATE=tests/Architecture/JobDeferralTerminationGateTest.php
EXPECTED_ADDED_LINES=209
NON_APPEND=$(diff "$TMP/$GATE" "$ROOT/$GATE" | grep -E '^[0-9]' | grep -vE '^[0-9,]+a[0-9,]+$' || true)
if [ -n "$NON_APPEND" ]; then
  fail "$GATE に追加以外の差分 (削除 / 書き換え) がある"
  echo "$NON_APPEND" >&2
  diff -u "$TMP/$GATE" "$ROOT/$GATE" >&2
fi
ADDED=$(diff "$TMP/$GATE" "$ROOT/$GATE" | grep -cE '^> ' || true)
if [ "$ADDED" -ne "$EXPECTED_ADDED_LINES" ]; then
  fail "$GATE の追加行数が期待値と違う (期待 $EXPECTED_ADDED_LINES / 実測 $ADDED)"
  diff -u "$TMP/$GATE" "$ROOT/$GATE" >&2
fi
[ "$FAILED" -eq 0 ] && echo "  OK   $GATE (追加のみ $ADDED 行)"

if [ "$FAILED" -eq 0 ]; then
  echo "OK: 31 本すべてが設計どおり (byte 一致 28 / 部分適合 3)"
else
  echo "NG: 上の差分を確認すること"
fi
exit "$FAILED"
