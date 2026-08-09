Round 1 の Warning 3 件 + Suggestion 1 件をすべて対応した (反論なし)。

受入条件 1/9/10 の指摘を直す過程で、**自分のテストが空振りしていたこと**が 3 つ判明した
(orchestrator gate で早期 die / 対照ケースが先に pidfile を削除 / phase 切替をコール回数で書いて脆かった)。
すべて対応マトリクスに正直に記録した。

**mutation による実効性の確認**: `recheck_shard_workers_stopped` の呼び出しを `if false` に置換したところ、
- 対応前: 構造検査 (y7i) しか赤くならなかった (実挙動テストは素通り)
- 対応後: y7i + y7r の 2 件が赤になる (構造と実挙動の両方で検出)

また、テスト追加で dropdb の literal が 6 行増え、raw DB コマンド目録が**赤くなった**。
理由付きで 6 件追加して緑に戻した (deny-by-default が意図どおり働いた実例)。

判定してほしい点:
- (y7p)(y7q)(y7r) が受入条件 1/9/10 の「呼び出し側の帰結」を実際に固定できているか
- y7r の意味的トリガ (pidfile 削除で phase 切替) と zombie=1 を返す理由が妥当か
- フィールド数検査の実装が正しいか

# 対応マトリクス

# 対応マトリクス: impl-review (harness) Round 1

Critical はゼロ。Warning 3 件 + Suggestion 1 件。**全件対応**した（反論なし）。
うち 1 件は対応の過程で**テストが空振りしていたこと**が判明し、テスト設計をやり直した。

## [Warning] `setup-worktree.sh` — 関数呼び出しを `if` の条件に置くと失敗が隠れる
- 判断: **対応する**
- 根拠: 指摘のとおり。`if provision_bughunt_env_file ... && [[ -f ... ]]` では
  条件内で `set -e` が効かず、`install -m 600` の失敗が
  「親に無いためスキップ」に化ける。**秘密ファイルのコピー失敗を握り潰す**のは契約として弱い。
- 対応内容: 条件を `[[ -f "${REPO_ROOT}/.env.bughunt.local" ]]` に変え、
  **関数呼び出しを本体側**へ移した（失敗すれば `set -e` で止まる）。理由もコメントに残した。

## [Warning] `group_scan_once` がフィールド数を検査していない
- 判断: **対応する**
- 根拠: 指摘のとおり。`read -r live zomb unknown` は**余分なトークンを最後の変数へ吸わせる**ため、
  `0 0 0 garbage` のような壊れ方を検出できない。設計の「3 値が非負整数」という安全弁が抜けていた。
- 対応内容: `read -r -a parts` で配列に読み、**`${#parts[@]} -ne 3` を先に弾く**形にした。
  self-test (y7m) に **「余分な 4 フィールド目」ケース**を追加した。

## [Warning] 受入条件 1・9・10 の固定が静的/人工的（呼び出し側の帰結を見ていない）
- 判断: **対応する（指摘が正しく、直す過程でテストの空振りも見つかった）**
- 根拠: 指摘のとおり。y7c は `group_stopped` の stub 検証で
  「`stop_shard_workers` が zombie-only 成功時に pidfile を削除する」ことまでは見ておらず、
  y7h も `cmd_teardown` 本体ではなく同等の if 断片を検証していた。
- 対応内容: **実挙動ケースを 3 つ追加**した。
  - **(y7p)**: zombie のみの group で `stop_shard_workers` が成功し、**pidfile を削除する**
  - **(y7q)**: **`cmd_teardown` 本体**を stub 環境で走らせ、停止失敗 shard について
    (a) teardown が非ゼロ、(b) 当該 shard の dropdb が呼ばれない、(c) pidfile が保持される、
    を同時に見る。**対照**として「停止対象なしの shard では dropdb が呼ばれる」ことも見る
    （これが無いと「常に何も呼ばれない」実装でも通ってしまう）
  - **(y7r)**: **再確認層そのもの**を突く。y7q は `workers_stopped=0` で止まるため
    第 1 層しか見ていないことが分かったので、
    「停止判定は成功するが dropdb 直前の再確認で live が出る」状況を作った

### 途中で判明した問題（正直に記録する）
1. **最初の y7q は空振りだった**。`cmd_teardown` の `require_orchestrator` が先に die するため、
   「停止失敗だから非ゼロ」ではなく「gate だから非ゼロ」で通っていた。
   → `require_orchestrator` を stub して本体を実際に走らせる形に直した。
2. **対照ケースが先に pidfile を消していた**。positive control を同じ shard で回したため、
   `kill` stub の影響で stale 判定に落ちて pidfile が削除され、後続の assert が誤って落ちた。
   → 対照を「同一 run 内の別 shard」で取る形に作り替えた。
3. **y7r の phase 切替をコール回数で書いていたのが脆かった**。`group_stopped` が
   1 回の判定で 2 回走査するため、閾値が実装の走査回数に依存していた。
   → **意味的なトリガ**（停止成功時に pidfile が削除される）で切り替える形に変えた。
   さらに、停止フェーズで `live=0 zombie=0` を返すと
   「kill -0 は成功するのに procfs で 0 件」= 確認不能の fail-closed に掛かって
   停止判定自体が失敗し再確認層へ到達しないため、**zombie を 1 件返す**ようにした。

### mutation による実効性の確認
`recheck_shard_workers_stopped` の呼び出しを `if false` に置換して self-test を走らせた:
- 対応前: **構造検査 (y7i) しか赤くならなかった**（実挙動テストは素通り）
- 対応後: **y7i + y7r の 2 件が赤**になる（構造と実挙動の両方で検出）

## [Suggestion] `cmd_self_test()` 冒頭コメントが末尾の実装と食い違う
- 判断: **対応する**
- 根拠: 「内部生成も現時点では削除しない」と書いたが、末尾は `sandbox_owned == 1` で削除する。
  実装が設計どおりなので、コメントだけが古かった。
- 対応内容: コメントを「**内部生成 (sandbox_owned=1) は従来どおり末尾で削除する**」に修正した。

## 副次: raw DB コマンド目録が想定どおり機能した
上記のテスト追加で `dropdb` の literal を含む行が 6 行増え、
`BughuntRawDbCommandInventoryTest` が**赤くなった**（未登録の literal 行を検出）。
理由付きで目録へ 6 件追加して緑に戻した。deny-by-default が意図どおり働いた実例である。

## 検証（対応後）
- `scripts/bug-hunt-shard.sh self-test`: all passed
- `composer test`: 4114 tests / 4112 passed / 2 skipped / **17709 assertions**（対応前は 17697）
- `composer phpstan`: No errors / `vendor/bin/pint --test`: passed


---

# 実装差分 (全体)

```diff
diff --git a/scripts/bug-hunt-shard.sh b/scripts/bug-hunt-shard.sh
index 02bec3d..17744c9 100755
--- a/scripts/bug-hunt-shard.sh
+++ b/scripts/bug-hunt-shard.sh
@@ -801,8 +801,119 @@ start_shard_workers() {
 # ★ 消滅を確認できた group のみ pidfile を削除する。残留/検証不能の group の pidfile は保持し
 #   (追跡可能性を失わない)、戻り値 1 で失敗を通知する (呼び出し側が dropdb を抑止する)。
 # (Codex 詳細 R1/R2/R3/R4 反映)
+# --- /proc/<pid>/stat の 1 行パース (fixture でテストできるよう独立させる) ------
+# 入力: stat の 1 行 / 出力: "<state> <pgrp>" (解釈できなければ非 0)
+# ★ comm は括弧で囲まれ **プロセス名に空白や ')' を含みうる**ため、先頭からの位置決め
+#   (awk '{print $3}' 等) は state を誤読する。**最後の ') ' より後ろ**を分割すれば
+#   state=1 / ppid=2 / pgrp=3 が確定する。
+parse_proc_stat_line() {
+    local line=$1 rest
+    rest="${line##*') '}"                          # comm の**最後**の閉じ括弧より後ろ
+    [[ "${rest}" != "${line}" ]] || return 1       # ') ' が無い = 想定外の書式
+    local -a f
+    read -r -a f <<< "${rest}"
+    [[ ${#f[@]} -ge 3 ]] || return 1
+    echo "${f[0]} ${f[2]}"                         # state / pgrp (ppid は f[1])
+    return 0
+}
+
+# --- process group のメンバー内訳 (zombie を分離し、解釈不能を unknown に立てる) ---
+# kill -0 -- -PGID は「シグナルを送れるか」であって「動いているか」ではない。
+# zombie (state=Z) は終了済みで DB 接続も資源も持たないのに「生存」と数えられ、
+# PID 1 が zombie を刈らない環境 (本 devcontainer の PID 1 は sleep infinity) では
+# queue:work --once の終了済み子が group に残り続け dropdb が永久に抑止される。
+#
+# ★ 見たいのは「DB 接続を保持しうるプロセスが残っているか」なので判定対象を procfs にする。
+# ★ **解釈できなかった行は unknown に数える** (0 件へ倒さない)。dropdb 直前判定では
+#   「確認不能」は DB を消さない側に倒す = fail-closed。
+#   代償として、対象 PGID と無関係な行の異常でも teardown が止まる (可用性のトレードオフ)。
+# ★ 走査中に消えた pid (open 失敗) は race として無視してよい (消えたのだから残留ではない)。
+#   ただし「読めたが解釈できない」は unknown であり、無視しない。
+#
+# 出力: "<live> <zombie> <unknown>"
+group_member_counts() {
+    local pgid=$1 live=0 zomb=0 unknown=0 statfile line parsed state pgrp
+    for statfile in /proc/[0-9]*/stat; do
+        line="$(cat "${statfile}" 2>/dev/null)" || continue   # race: 消えた pid
+        [[ -n "${line}" ]] || continue
+        if ! parsed="$(parse_proc_stat_line "${line}")"; then
+            unknown=$((unknown + 1))                          # 読めたが解釈不能 = fail-closed 側
+            continue
+        fi
+        state="${parsed%% *}"; pgrp="${parsed##* }"
+        [[ "${pgrp}" == "${pgid}" ]] || continue
+        if [[ "${state}" == "Z" ]]; then
+            zomb=$((zomb + 1))
+        else
+            live=$((live + 1))
+        fi
+    done
+    echo "${live} ${zomb} ${unknown}"
+}
+
+# 1 回分の走査で「生きているメンバーが 0 か」を判定する。stdout に zombie 件数を出す。
+# fail-closed の 3 条件: (a) live>0、(b) unknown>0 (確認不能)、
+# (c) kill -0 -- -PGID が成功しているのに procfs で 1 件も確証できない (procfs が読めていない
+# 可能性を成功へ倒さない)。加えて集計値が非負整数でないときも失敗へ倒す。
+group_scan_once() {
+    local pgid=$1 label=$2 counts live zomb unknown v
+    counts="$(group_member_counts "${pgid}")"
+    # ★ フィールド数も検査する。read -r a b c は余分なトークンを c へ吸わせるため、
+    #   '0 0 0 garbage' のような壊れ方を見逃す (fail-closed の安全弁が抜ける)。
+    local -a parts
+    read -r -a parts <<< "${counts}"
+    if [[ ${#parts[@]} -ne 3 ]]; then
+        echo "error: ${label} の member 集計が不正 ('${counts}') — 確認不能として停止失敗に倒す" >&2
+        return 1
+    fi
+    live="${parts[0]}"; zomb="${parts[1]}"; unknown="${parts[2]}"
+
+    for v in "${live}" "${zomb}" "${unknown}"; do
+        [[ "${v}" =~ ^[0-9]+$ ]] || {
+            echo "error: ${label} の member 集計が不正 ('${counts}') — 確認不能として停止失敗に倒す" >&2
+            return 1
+        }
+    done
+
+    if [[ "${live}" != 0 ]]; then
+        return 1
+    fi
+    if [[ "${unknown}" != 0 ]]; then
+        echo "error: ${label} の判定で /proc の解釈不能行が ${unknown} 件 — 確認不能として停止失敗に倒す" >&2
+        return 1
+    fi
+    if [[ "${zomb}" == 0 ]] && kill -0 -- "-${pgid}" 2>/dev/null; then
+        echo "error: ${label} は kill -0 が成功するのに procfs でメンバーを 1 件も確認できない — 確認不能として停止失敗に倒す" >&2
+        return 1
+    fi
+    echo "${zomb}"
+    return 0
+}
+
+# group が停止したか。**連続 2 回の走査がともに live=0** のときだけ成功とする。
+# 1 回走査だと「zombie を観測した直後に同 PGID へ live member が現れる」窓が残る。
+# ★ これは TOCTOU を**証明**するものではなく**窓を縮小する検出**である (誇張しない)。
+group_stopped() {
+    local pgid=$1 label=$2 zomb1 zomb2
+    zomb1="$(group_scan_once "${pgid}" "${label}")" || return 1
+    sleep 0.1
+    zomb2="$(group_scan_once "${pgid}" "${label}")" || return 1
+    if [[ "${zomb2}" != 0 ]]; then
+        echo "note: ${label} は zombie ${zomb2} 件を残して停止 (PID 1 が刈らない環境。DB 接続は保持しない)" >&2
+    fi
+    return 0
+}
+
+# stop_shard_workers <shard> [out_pgids_array_name]
+# ★ 停止確認できた group の pgid を **nameref で呼び出し側の配列へ積む** (グローバルにしない)。
+#   shard ループを跨いで値が残ると、前 shard の pgid で不要に dropdb を抑止したり、
+#   記録漏れで dropdb 直前の再確認が空振りしたりする。
 stop_shard_workers() {
     local shard=$1 conn wpidfile wpid wpgid t rc=0
+    if [[ -n "${2:-}" ]]; then
+        local -n _out_pgids=$2
+        _out_pgids=()   # ★ 呼び出しごとに必ず初期化する
+    fi
     for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
         wpidfile="$(worker_pidfile "${shard}" "${conn}")"
         [[ -f "${wpidfile}" ]] || continue
@@ -829,19 +940,26 @@ stop_shard_workers() {
         fi
         kill -TERM -- "-${wpid}" 2>/dev/null || true
         for t in 1 2 3 4 5; do
-            kill -0 -- "-${wpid}" 2>/dev/null || break
+            # 待機ループ中は note を撒かないよう出力を捨てる (本判定は下で 1 回だけ行う)
+            group_stopped "${wpid}" "shard-${shard} worker group (${conn}, pgid=${wpid})" \
+                >/dev/null 2>&1 && break
             sleep 0.4
         done
-        if kill -0 -- "-${wpid}" 2>/dev/null; then
+        if ! group_stopped "${wpid}" "shard-${shard} worker group (${conn}, pgid=${wpid})" \
+            >/dev/null 2>&1; then
             kill -KILL -- "-${wpid}" 2>/dev/null || true
             sleep 0.4
         fi
-        if kill -0 -- "-${wpid}" 2>/dev/null; then
+        # ★ 本判定 (stderr つき)。zombie だけが残った場合は note を出して成功にする。
+        if ! group_stopped "${wpid}" "shard-${shard} worker group (${conn}, pgid=${wpid})"; then
             echo "error: shard-${shard} worker group (${conn}, pgid=${wpid}) が KILL 後も残留 (pidfile 保持: ${wpidfile})" >&2
             rc=1
             continue
         fi
         rm -f "${wpidfile}"
+        if [[ -n "${2:-}" ]]; then
+            _out_pgids+=("${wpid}")   # dropdb 直前の再確認用に pgid を残す
+        fi
     done
     return "${rc}"
 }
@@ -1082,7 +1200,17 @@ cmd_provision_all() {
         "started_at=\"$(TZ=Asia/Tokyo date '+%Y-%m-%d %H:%M:%S')\""
 
     if ! is_dryrun; then
-        php artisan optimize:clear > /dev/null
+        # ★ --except=cache: optimize:clear は複合コマンドで、標準タスクのうち cache:clear だけが
+        #   cache store=database のとき **dev DB の cache 表を DELETE** しにいく。
+        #   provision が要るのは bootstrap cache (config/route/view/event/compiled) の破棄であって
+        #   アプリケーションキャッシュではない (bughunt DB は直後に migrate:fresh する)。
+        #   --except はキー名 'cache' とコマンド名 'cache:clear' の両方に一致する。
+        #   ★ このフラグを消すと dev DB 未 migrate 環境で provision 全体が落ちる。消さないこと。
+        # ★ env -i: 本スクリプトの原則 (shell の DB_*/PG* を遮断してから artisan を叩く) へ合流させる。
+        #   ただし env -i が遮断するのは親シェル由来の env だけで Laravel は .env を読む。
+        #   「絶対に DB へ接続しない」とは主張しない (拡張 clear の集合は
+        #   BughuntOptimizeClearTaskInventoryTest が別途 pin する)。
+        env -i PATH="${PATH}" HOME="${HOME:-/tmp}" php artisan optimize:clear --except=cache > /dev/null
         ensure_fresh_assets
     fi
 
@@ -1145,17 +1273,33 @@ cmd_mail_urls() {
 
 # --- teardown -----------------------------------------------------------------
 
+# dropdb 直前の再確認。stop_shard_workers が積んだ pgid 群を nameref で受け、
+# 全て group_stopped を満たすときだけ 0 を返す。
+# ★ 記録が空 (worker を 1 つも止めていない = pidfile が無かった) 場合は成功でよい。
+recheck_shard_workers_stopped() {
+    local -n _pgids=$1
+    local label=$2 pgid
+    for pgid in ${_pgids[@]+"${_pgids[@]}"}; do
+        group_stopped "${pgid}" "${label} recheck (pgid=${pgid})" || return 1
+    done
+    return 0
+}
+
 cmd_teardown() {
     local run_id=$1 drop_db=${2:-}
     require_orchestrator "teardown"
     local shard pid port teardown_rc=0
-    for shard in 0 1 2 3 4 5 6 7 8; do
+    # ★ 範囲は cap から導出する。リテラルを置くと cap 変更時に SHARD_DB_RE とずれ、
+    #   自分の guard で abort する (cap=8→4 の変更時に実際に起きた: bug_hunt_5 で die)。
+    #   seq への外部依存を増やさないため bash 算術ループを使う。
+    for ((shard = 0; shard <= BUGHUNT_SHARD_CAP; shard++)); do
         # 専用 connection worker の停止 (serve より先に止め、--drop-db 時の DB 接続残留を防ぐ)。
         # stop_shard_workers が cmdline 照合 → TERM → group 消滅待ち → KILL escalation →
         # 再確認まで行い、残留があれば pidfile を保持して非ゼロを返す (追跡可能性を失わない)。
         # 停止失敗 shard の dropdb は抑止する (接続保持した孤児 worker と dropdb の衝突防止)。
         local workers_stopped=1
-        if ! stop_shard_workers "${shard}"; then
+        local -a stopped_pgids=()   # ★ shard ごとに新しい配列 (前 shard の pgid を持ち越さない)
+        if ! stop_shard_workers "${shard}" stopped_pgids; then
             workers_stopped=0
             teardown_rc=1
             echo "warning: shard-${shard} の worker 停止に失敗 — この shard の dropdb をスキップ (pidfile 保持)" >&2
@@ -1182,7 +1326,16 @@ cmd_teardown() {
             rm -f "${pidfile}"
         fi
         if [[ "${drop_db}" == "--drop-db" && "${workers_stopped}" == 1 ]] && ! is_dryrun; then
-            pg_admin_for_provision dropdb "$(shard_db "${shard}")"
+            # ★ procfs 走査は一時点のスナップショットではない。TOCTOU 窓は消せないが、
+            #   dropdb 分岐の**直前**でもう一度確認して窓を最小化する。
+            #   再確認で残留を観測したら DB を消さない側へ倒す (fail-closed)。
+            if ! recheck_shard_workers_stopped stopped_pgids "shard-${shard}"; then
+                teardown_rc=1
+                echo "warning: shard-${shard} の worker が dropdb 直前の再確認で残留 — dropdb をスキップ" >&2
+            else
+                # DB 名 guard (SHARD_DB_RE) と admin role 明示は従来どおり wrapper 側が通す。
+                pg_admin_for_provision dropdb "$(shard_db "${shard}")"
+            fi
         fi
         rm -f "$(wrapper_path "${shard}")"
         rm -rf "storage/bughunt-coverage/.pcov-ini-${shard}"
@@ -1221,8 +1374,33 @@ stories_for_shard() {
 # --- self-test (実資源に触れない) ----------------------------------------------
 
 cmd_self_test() {
-    local sandbox failures=0
-    sandbox="$(mktemp -d -t bug-hunt-selftest.XXXXXX)"
+    local sandbox failures=0 sandbox_owned=0
+    # sandbox は「外から与えられていればそれを使い、未指定のときだけ自分で作る」。
+    # 呼び出し側 (Pest の BughuntSelfTestExecutionTest) が隔離境界を握れるようにするため。
+    #
+    # ★ 「既存の絶対ディレクトリ」だけでは境界にならない。/ や WORKSPACE を渡されると
+    #   RUN_BASE / TMP_BASE がそこへ向き、削除しなくても書き込みで実資源を壊せる。
+    #   そこで **専用マーカーファイル**を要求する = 呼び出し側が「捨ててよい空き地」を
+    #   意図的に用意したときだけ受け付ける。
+    # ★ 後始末の契約: 外部指定 (sandbox_owned=0) は**絶対に削除しない**。
+    #   内部生成 (sandbox_owned=1) は従来どおり末尾で削除する。
+    if [[ -n "${BUGHUNT_SANDBOX:-}" ]]; then
+        sandbox="${BUGHUNT_SANDBOX}"
+        [[ "${sandbox}" == /* && "${sandbox}" != / ]] \
+            || die 2 "BUGHUNT_SANDBOX は / 以外の絶対パスであること: '${sandbox}'"
+        [[ -d "${sandbox}" ]] || die 2 "BUGHUNT_SANDBOX が存在しない: '${sandbox}'"
+        # 表記差 (末尾 /. や symlink) を吸収するため物理パスで比較する
+        local _sb_real _ws_real
+        _sb_real="$(cd "${sandbox}" && pwd -P)"
+        _ws_real="$(cd "${WORKSPACE}" && pwd -P)"
+        [[ "${_sb_real}" != "${_ws_real}" ]] \
+            || die 2 "BUGHUNT_SANDBOX にリポジトリルートは指定できない"
+        [[ -f "${sandbox}/.bughunt-selftest-sandbox" ]] \
+            || die 2 "BUGHUNT_SANDBOX に専用マーカー .bughunt-selftest-sandbox が無い (捨ててよい空き地だけを受け付ける)"
+    else
+        sandbox="$(mktemp -d -t bug-hunt-selftest.XXXXXX)"
+        sandbox_owned=1
+    fi
     export BUGHUNT_SANDBOX="${sandbox}"
     mkdir -p "${sandbox}/devnotes" "${sandbox}/tmp/bug-hunt"
     RUN_BASE="${sandbox}/devnotes"
@@ -1738,7 +1916,9 @@ CURLEOF
     stopw_def="$(declare -f stop_shard_workers)"
     echo "${stopw_def}" | grep -qF 'kill -TERM -- "-' || t_fail "stop_shard_workers が process group kill でない"
     echo "${stopw_def}" | grep -q 'worker_alive' || t_fail "stop_shard_workers に cmdline 照合 (worker_alive) が無い"
-    echo "${stopw_def}" | grep -qF 'kill -0 -- "-' || t_fail "stop_shard_workers に process group 消滅待ちが無い (master 単体判定は dropdb と race)"
+    # 成功条件が group 全体の判定であること (master 単体判定に戻すと dropdb と race する)。
+    # T142 で判定を kill -0 から procfs ベースの group_stopped へ移したため、参照先を更新した。
+    echo "${stopw_def}" | grep -qF 'group_stopped' || t_fail "stop_shard_workers に process group 単位の停止判定 (group_stopped) が無い (master 単体判定は dropdb と race)"
     echo "${stopw_def}" | grep -qF 'kill -KILL -- "-' || t_fail "stop_shard_workers に KILL escalation が無い"
     echo "${stopw_def}" | grep -q 'ps -o pgid=' || t_fail "stop_shard_workers に pid==pgid 検証が無い (setsid 不成立の group を消滅済みと誤認する)"
     echo "${stopw_def}" | grep -qF 'kill -TERM "${wpid}"' \
@@ -1848,6 +2028,271 @@ CURLEOF
     rm -f "$(worker_pidfile 8 database-media)"
     t_ok "queue worker wiring (derivation/drift/structure/alive/dryrun/stop 正常系+失敗系)"
 
+    # (y7) group 生存判定 (zombie 除外 + fail-closed)。T142 / bug-hunt run 20260809-152048 の H-1。
+    # kill -0 -- -PGID は zombie も「生存」と数えるため、PID 1 が zombie を刈らない環境で
+    # dropdb が永久に抑止されていた。判定対象を procfs にし、確認不能は失敗へ倒す。
+    echo "[y7] group 生存判定 (zombie 除外 / fail-closed / 2 連続走査)"
+
+    # (y7a) parse_proc_stat_line: comm に空白と ')' を含んでも state/pgrp を誤読しない。
+    #       ★ テスト側でパースを複製すると実装の検証にならないので、実装関数を直接叩く。
+    local parsed
+    parsed="$(parse_proc_stat_line '123 (my ) proc) Z 1 456 0 0')" \
+        || t_fail "[y7a] parse_proc_stat_line が正当な行を拒否"
+    [[ "${parsed}" == "Z 456" ]] \
+        || t_fail "[y7a] parse_proc_stat_line の誤読 (期待 'Z 456' / 実際 '${parsed}')"
+    parsed="$(parse_proc_stat_line '999 (php) S 1 777 0 0')" \
+        || t_fail "[y7a] parse_proc_stat_line が通常行を拒否"
+    [[ "${parsed}" == "S 777" ]] || t_fail "[y7a] 通常行の誤読 ('${parsed}')"
+    parse_proc_stat_line 'garbage-without-paren' \
+        && t_fail "[y7a] ') ' を含まない行を受理した"
+
+    # (y7b) メンバー 0 件 = 停止成功。zombie note は出さない。
+    #       存在しない pgid を使う (kill -0 も失敗するので補完条件にも掛からない)。
+    local out7
+    out7="$( group_stopped 999999999 "[y7b]" 2>&1 )" \
+        || t_fail "[y7b] メンバー 0 件なのに停止失敗"
+    [[ "${out7}" != *"zombie"* ]] || t_fail "[y7b] メンバー 0 件で zombie note を出した"
+
+    # (y7c) 全て zombie = 停止成功 + stderr に note。
+    # (y7d) 非 zombie が 1 件 = 停止失敗。
+    # (y7e) 混在 = 停止失敗。
+    # いずれも group_member_counts を stub して判定側の契約だけを見る。
+    out7="$( group_member_counts() { echo "0 3 0"; }
+             kill() { return 1; }   # kill -0 は失敗させる (補完条件を通さない)
+             group_stopped 4242 "[y7c]" 2>&1 )" \
+        || t_fail "[y7c] 全 zombie なのに停止失敗"
+    [[ "${out7}" == *"zombie 3 件"* ]] || t_fail "[y7c] zombie note が出ていない ('${out7}')"
+
+    ( group_member_counts() { echo "1 0 0"; }
+      group_stopped 4242 "[y7d]" ) 2>/dev/null \
+        && t_fail "[y7d] 非 zombie が居るのに停止成功"
+
+    ( group_member_counts() { echo "1 2 0"; }
+      group_stopped 4242 "[y7e]" ) 2>/dev/null \
+        && t_fail "[y7e] zombie/非 zombie 混在なのに停止成功"
+
+    # (y7f) 走査中に消えた pid を残留と数えない (実 procfs を使う)。
+    #       存在しない pgid への集計は 0 0 0 になるはず (unknown も 0)。
+    out7="$(group_member_counts 999999999)"
+    [[ "${out7}" == "0 0 0" ]] || t_fail "[y7f] 消えた pid / 無関係行を誤って数えた ('${out7}')"
+
+    # (y7k) unknown > 0 は「確認不能」= 停止失敗 (fail-closed)。
+    ( group_member_counts() { echo "0 0 1"; }
+      group_stopped 4242 "[y7k]" ) 2>/dev/null \
+        && t_fail "[y7k] unknown があるのに停止成功 (fail-open)"
+
+    # (y7l) 0 0 0 でも kill -0 が成功するなら「確認不能」= 停止失敗。
+    #       procfs が読めていない可能性を成功へ倒さない。
+    ( group_member_counts() { echo "0 0 0"; }
+      kill() { return 0; }
+      group_stopped 4242 "[y7l]" ) 2>/dev/null \
+        && t_fail "[y7l] kill -0 成功なのに procfs 0 件を停止成功へ倒した (fail-open)"
+
+    # (y7m) 集計出力が不正 (空 / 非数値) でも停止失敗へ倒す。
+    ( group_member_counts() { echo ""; }
+      group_stopped 4242 "[y7m1]" ) 2>/dev/null \
+        && t_fail "[y7m] 空の集計出力で停止成功"
+    ( group_member_counts() { echo "x y z"; }
+      group_stopped 4242 "[y7m2]" ) 2>/dev/null \
+        && t_fail "[y7m] 非数値の集計出力で停止成功"
+    ( group_member_counts() { echo "0 0 0 garbage"; }
+      group_stopped 4242 "[y7m3]" ) 2>/dev/null \
+        && t_fail "[y7m] 余分なフィールドがある集計出力で停止成功 (read が吸って見逃している)"
+
+    # (y7n) 連続 2 回走査: 1 回目 live=0 / 2 回目 live=1 なら失敗。
+    #       zombie 観測経路の race 窓を縮小していることの直接固定。
+    # ★ 呼び出し回数はファイルで数える。group_member_counts は $( ) の中で呼ばれる =
+    #   subshell なので、シェル変数のインクリメントは呼び出し元へ戻らない。
+    local y7n_counter="${TMP_BASE}/y7n-calls"
+    : > "${y7n_counter}"
+    ( group_member_counts() {
+          echo x >> "${y7n_counter}"
+          if [[ "$(wc -l < "${y7n_counter}")" == 1 ]]; then echo "0 1 0"; else echo "1 1 0"; fi
+      }
+      kill() { return 1; }
+      group_stopped 4242 "[y7n]" ) 2>/dev/null \
+        && t_fail "[y7n] 2 回目に非 zombie が出たのに停止成功 (連続 2 回走査が効いていない)"
+    rm -f "${y7n_counter}"
+
+    t_ok "group 生存判定 (parse/0件/全zombie/非zombie/混在/unknown/kill矛盾/2連続走査)"
+
+    # (y7g-j) dropdb への**到達制御**。危険なのは guard の有無ではなく
+    # 「worker 停止失敗時に dropdb へ到達しないか」という制御フローそのもの。
+    echo "[y7x] dropdb 到達制御 (再確認 / wrapper 不呼び出し / guard 経由)"
+
+    # (y7g) 再確認で非 zombie を観測したら失敗する (1 回目ゼロ・2 回目非 zombie の必須ケース)。
+    local y7g_counter="${TMP_BASE}/y7g-calls"
+    : > "${y7g_counter}"
+    ( group_member_counts() {
+          echo x >> "${y7g_counter}"
+          if [[ "$(wc -l < "${y7g_counter}")" == 1 ]]; then echo "0 0 0"; else echo "1 0 0"; fi
+      }
+      kill() { return 1; }
+      _pg=(4242)
+      recheck_shard_workers_stopped _pg "[y7g]" ) 2>/dev/null \
+        && t_fail "[y7g] 再確認で非 zombie が出たのに成功"
+    rm -f "${y7g_counter}"
+
+    # (y7h) 非 zombie 残留のとき dropdb wrapper が **一度も呼ばれない**。
+    #       pg_admin_for_provision を stub し、呼ばれたら痕跡を残す。
+    local y7h_marker="${TMP_BASE}/y7h-dropdb-called"
+    rm -f "${y7h_marker}"
+    ( group_member_counts() { echo "1 0 0"; }
+      pg_admin_for_provision() { echo "$*" >> "${y7h_marker}"; }
+      _pg=(4242)
+      if recheck_shard_workers_stopped _pg "[y7h]"; then
+          pg_admin_for_provision dropdb "$(shard_db 1)"
+      fi ) 2>/dev/null
+    [[ ! -f "${y7h_marker}" ]] \
+        || t_fail "[y7h] 非 zombie 残留なのに dropdb wrapper が呼ばれた ($(cat "${y7h_marker}"))"
+
+    # (y7j) 逆に停止済みなら wrapper を通って dropdb へ進み、**DB 名 guard を必ず通る**。
+    #       guard_shard_db_name を stub して呼ばれたことと引数を確認する。
+    local y7j_marker="${TMP_BASE}/y7j-guard"
+    rm -f "${y7j_marker}"
+    ( group_member_counts() { echo "0 0 0"; }
+      kill() { return 1; }
+      guard_shard_db_name() { echo "$1" >> "${y7j_marker}"; }
+      pg_admin_for_provision() { guard_shard_db_name "$2"; }
+      _pg=(4242)
+      if recheck_shard_workers_stopped _pg "[y7j]"; then
+          pg_admin_for_provision dropdb "$(shard_db 1)"
+      fi ) 2>/dev/null
+    [[ -f "${y7j_marker}" ]] || t_fail "[y7j] 停止済みなのに dropdb 経路へ進まなかった"
+    grep -qx "$(shard_db 1)" "${y7j_marker}" \
+        || t_fail "[y7j] dropdb が DB 名 guard を通っていない (記録: $(cat "${y7j_marker}" 2>/dev/null))"
+    rm -f "${y7j_marker}"
+
+    # (y7i) teardown が worker 停止失敗時に dropdb を抑止する構造を保っていること
+    local td_def
+    td_def="$(declare -f cmd_teardown)"
+    echo "${td_def}" | grep -q 'recheck_shard_workers_stopped' \
+        || t_fail "[y7i] cmd_teardown に dropdb 直前の再確認が無い"
+    echo "${td_def}" | grep -q 'stopped_pgids' \
+        || t_fail "[y7i] cmd_teardown が pgid を shard ローカルで受け渡していない"
+
+    # (y7p) 受入条件 1 の実挙動: zombie だけが残った group で **stop_shard_workers が
+    #       pidfile を削除する** (判定関数の stub 検証だけでなく、呼び出し側の帰結まで見る)。
+    setsid sleep 30 > /dev/null 2>&1 &
+    local fake_z=$!
+    echo "${fake_z}" > "$(worker_pidfile 8 database-analysis)"
+    ( worker_alive() { [[ "$1" == 8 && "$2" == database-analysis ]]; }
+      # 実 kill はさせず、group は「zombie だけが残っている」ことにする
+      kill() { [[ "${1:-}" == "-TERM" || "${1:-}" == "-KILL" ]] && return 0; builtin kill "$@"; }
+      group_member_counts() { echo "0 2 0"; }
+      sleep() { :; }
+      stop_shard_workers 8 ) 2>/dev/null \
+        || t_fail "[y7p] zombie のみの group で stop_shard_workers が失敗した"
+    [[ ! -f "$(worker_pidfile 8 database-analysis)" ]] \
+        || t_fail "[y7p] zombie のみで停止成功なのに pidfile が残った"
+    builtin kill -TERM -- "-${fake_z}" 2>/dev/null || true
+    wait "${fake_z}" 2>/dev/null || true
+
+    # (y7q) 受入条件 9/10 の実挙動: **cmd_teardown 本体**を stub 環境で走らせ、
+    #       worker 停止に失敗した shard では pg_admin_for_provision が**その shard について**
+    #       呼ばれないこと / pidfile が保持されること / teardown が非ゼロで終わることを見る。
+    #       ★ 停止対象が無い他 shard では dropdb が正しく呼ばれる (= 全体が常に非ゼロ、では無い)。
+    #         そのため「marker が空」ではなく「marker に当該 shard の DB が無い」で判定する。
+    local y7q_marker="${TMP_BASE}/y7q-dropdb-called"
+    rm -f "${y7q_marker}"
+    setsid sleep 30 > /dev/null 2>&1 &
+    local fake_q=$!
+    echo "${fake_q}" > "$(worker_pidfile 1 database-analysis)"
+    ( worker_alive() { [[ "$2" == database-analysis ]] && [[ -f "$(worker_pidfile "$1" database-analysis)" ]]; }
+      kill() { [[ "${1:-}" == "-TERM" || "${1:-}" == "-KILL" ]] && return 0; builtin kill "$@"; }
+      group_member_counts() { echo "1 0 0"; }        # 非 zombie 残留 = 停止失敗
+      sleep() { :; }
+      pg_admin_for_provision() { echo "$2" >> "${y7q_marker}"; }
+      reap_orphan_browser() { :; }
+      # orchestrator gate は本ケースの対象外。stub しないと gate で die して
+      # 「停止失敗だから非ゼロ」ではなく「gate だから非ゼロ」の空振り合格になる。
+      require_orchestrator() { :; }
+      cmd_teardown 20990301-000000 --drop-db ) >/dev/null 2>&1 \
+        && t_fail "[y7q] worker 停止に失敗したのに teardown が成功で終わった"
+    # 当該 shard の DB は落とされていない
+    grep -qx "$(shard_db 1)" "${y7q_marker}" 2>/dev/null \
+        && t_fail "[y7q] 停止失敗 shard の dropdb が呼ばれた"
+    # ★ 対照 (空振り防止): 停止対象が無い他 shard では dropdb が呼ばれている。
+    #    これが無いと「常に何も呼ばれない」実装でも y7q が通ってしまう。
+    grep -qx "$(shard_db 0)" "${y7q_marker}" 2>/dev/null \
+        || t_fail "[y7q] 停止対象なしの shard でも dropdb が呼ばれていない (対照が成立せず空振り)"
+    [[ -f "$(worker_pidfile 1 database-analysis)" ]] \
+        || t_fail "[y7q] 停止失敗なのに pidfile が削除された (追跡情報の喪失)"
+    builtin kill -TERM -- "-${fake_q}" 2>/dev/null || true
+    wait "${fake_q}" 2>/dev/null || true
+    rm -f "$(worker_pidfile 1 database-analysis)" "${y7q_marker}"
+
+    # (y7r) **再確認層そのもの**を突く。y7q は workers_stopped=0 で止まるため第 1 層しか見ていない。
+    #       ここでは「停止判定は成功 (workers_stopped=1) だが、dropdb 直前の再確認で live が出る」を作る。
+    #       recheck が無い実装ではこの shard の dropdb が呼ばれてしまう。
+    local y7r_marker="${TMP_BASE}/y7r-dropdb-called"
+    rm -f "${y7r_marker}"
+    setsid sleep 30 > /dev/null 2>&1 &
+    local fake_r=$!
+    echo "${fake_r}" > "$(worker_pidfile 1 database-analysis)"
+    ( worker_alive() { [[ "$2" == database-analysis ]] && [[ -f "$(worker_pidfile "$1" database-analysis)" ]]; }
+      kill() { [[ "${1:-}" == "-TERM" || "${1:-}" == "-KILL" ]] && return 0; builtin kill "$@"; }
+      # ★ 呼び出し回数ではなく**意味的なトリガ**で切り替える (回数は実装の走査回数に依存して脆い)。
+      #   stop_shard_workers は停止成功時に pidfile を削除する。それが消えた後の呼び出し =
+      #   dropdb 直前の再確認フェーズ、とみなして live=1 を返す。
+      #   停止フェーズは zombie を 1 件返す。live=0 かつ zombie=0 だと
+      #   「kill -0 は成功するのに procfs で 0 件」= 確認不能の fail-closed に掛かってしまい、
+      #   停止判定そのものが失敗して再確認層まで到達しないため。
+      group_member_counts() {
+          if [[ -f "$(worker_pidfile 1 database-analysis)" ]]; then echo "0 1 0"; else echo "1 0 0"; fi
+      }
+      sleep() { :; }
+      pg_admin_for_provision() { echo "$2" >> "${y7r_marker}"; }
+      reap_orphan_browser() { :; }
+      require_orchestrator() { :; }
+      cmd_teardown 20990301-000000 --drop-db ) >/dev/null 2>&1 \
+        && t_fail "[y7r] dropdb 直前の再確認で live が出たのに teardown が成功で終わった"
+    grep -qx "$(shard_db 1)" "${y7r_marker}" 2>/dev/null \
+        && t_fail "[y7r] 再確認で live を観測したのに dropdb が呼ばれた (再確認層が効いていない)"
+    builtin kill -TERM -- "-${fake_r}" 2>/dev/null || true
+    wait "${fake_r}" 2>/dev/null || true
+    rm -f "$(worker_pidfile 1 database-analysis)" "${y7r_marker}"
+
+    t_ok "dropdb 到達制御 (再確認 / wrapper 不呼び出し / DB名 guard 経由 / teardown 実挙動)"
+
+    # (y8) teardown のループ範囲が cap から導出されていること (T142 / H-2)。
+    echo "[y8] teardown ループ範囲の cap 導出"
+    echo "${td_def}" | grep -qE 'for shard in 0 1 2' \
+        && t_fail "[y8a] cmd_teardown に数値リテラルのループ範囲が復活している"
+    echo "${td_def}" | grep -q 'BUGHUNT_SHARD_CAP' \
+        || t_fail "[y8a] cmd_teardown のループ範囲が cap から導出されていない"
+
+    # (y8b/y8c) テスト用 cap で実評価する。0..cap は allow、cap+1 は deny。
+    #   本番定数は触らない (局所再代入 + 復元。外部注入の経路は作らない)。
+    local _saved_cap="${BUGHUNT_SHARD_CAP}" _saved_dbre="${SHARD_DB_RE}" _saved_shre="${SHARD_RE}"
+    BUGHUNT_SHARD_CAP=2
+    SHARD_DB_RE="^${BUGHUNT_DB_PREFIX}(_[1-${BUGHUNT_SHARD_CAP}])?$"
+    SHARD_RE="^[0-${BUGHUNT_SHARD_CAP}]$"
+    local n
+    for n in 0 1 2; do
+        ( guard_shard_db_name "$(shard_db "${n}")" ) >/dev/null 2>&1 \
+            || t_fail "[y8b] cap=2 で shard ${n} の DB 名が拒否された"
+        [[ "${n}" =~ ${SHARD_RE} ]] || t_fail "[y8c] cap=2 で shard ${n} の入力が拒否された"
+    done
+    ( guard_shard_db_name "${BUGHUNT_DB_PREFIX}_3" ) >/dev/null 2>&1 \
+        && t_fail "[y8b] cap=2 なのに ${BUGHUNT_DB_PREFIX}_3 が受理された"
+    [[ "3" =~ ${SHARD_RE} ]] && t_fail "[y8c] cap=2 なのに shard 3 の入力が受理された"
+    BUGHUNT_SHARD_CAP="${_saved_cap}"; SHARD_DB_RE="${_saved_dbre}"; SHARD_RE="${_saved_shre}"
+    t_ok "teardown ループ範囲の cap 導出 (SHARD_DB_RE / SHARD_RE の実評価)"
+
+    # (y9) provision-all の optimize:clear が dev DB を触らない形であること (T142 / H-3)。
+    echo "[y9] optimize:clear の dev DB 非接触 (--except=cache + env -i)"
+    local pa_def
+    pa_def="$(declare -f cmd_provision_all)"
+    local oc_line
+    oc_line="$(echo "${pa_def}" | grep -F 'optimize:clear' | grep -v '^\s*#' | head -1)"
+    [[ -n "${oc_line}" ]] || t_fail "[y9] cmd_provision_all に optimize:clear が無い"
+    echo "${oc_line}" | grep -qF -- '--except=cache' \
+        || t_fail "[y9a] optimize:clear に --except=cache が無い (dev DB の cache 表を触る)"
+    echo "${oc_line}" | grep -qE '(^|[[:space:]])env -i' \
+        || t_fail "[y9b] optimize:clear が env -i 経由でない (ambient DB_*/PG* が渡る)"
+    t_ok "optimize:clear (--except=cache / env -i)"
+
     echo "[z] real-llm/fake-llm/real-storage モード制御 (フラグ/キー分離・fail-fast・秘密漏洩防止・引数解析)"
     local _e
     local z_env="${sandbox}/main-with-key.env"
@@ -1976,7 +2421,12 @@ PY
     LLM_MODE="${_saved_llm_mode}"; STORAGE_MODE="${_saved_storage_mode}"
     MODE_ENV=(); LLM_KEY_ENV=()
 
-    rm -rf "${sandbox}"
+    # ★ 自分で作った sandbox だけを削除する。外から与えられた sandbox は借り物なので
+    #   絶対に消さない (呼び出し側が成果物を確認できなくなる / 危険な値を渡された時に
+    #   再帰削除が走る、の両方を防ぐ)。
+    if [[ "${sandbox_owned}" == 1 ]]; then
+        rm -rf "${sandbox}"
+    fi
     unset BUGHUNT_SANDBOX
     if [[ "${failures}" -gt 0 ]]; then
         echo "self-test: ${failures} failure(s)"
diff --git a/scripts/setup-worktree.sh b/scripts/setup-worktree.sh
index 9734b31..6a746fa 100755
--- a/scripts/setup-worktree.sh
+++ b/scripts/setup-worktree.sh
@@ -30,6 +30,28 @@
 
 set -euo pipefail
 
+# --- bug-hunt 専用 env の provisioning (契約テストから source して単体で叩けるよう関数化) ---
+# .env.bughunt.local は .gitignore 対象で worktree には決して現れない = コピーが唯一の供給路。
+# bug-hunt は worktree 走行が既定 (AGENTS.md) なので、無いと provision が必ず止まる。
+#
+# ★ mode は親に追随させず 0600 に固定する。親が 0644 だと `cp -p` は
+#   **world-readable な秘密ファイルを新たに作る**ため契約として弱い。
+#   `install -m 600` は作成時点で mode を確定するので、`cp` → `chmod` の 2 段にある
+#   「一瞬だけ広く読める窓」も無い。
+# ★ 今回 0600 を固定する対象は **.env.bughunt.local だけ**である。
+#   既存の .env / storage/oauth-*.key の権限契約は変更しない (別施策)。
+provision_bughunt_env_file() {
+    local repo_root=$1 worktree_dir=$2
+    [[ -f "${repo_root}/.env.bughunt.local" ]] || return 0   # 非利用リポジトリでは no-op
+    install -m 600 "${repo_root}/.env.bughunt.local" "${worktree_dir}/.env.bughunt.local"
+}
+
+# ★ source 専用モード: 関数定義だけ取り込んで抜ける (契約テスト用)。
+#   実行時 (bash setup-worktree.sh) は環境変数を立てないので通らない。
+if [[ -n "${SETUP_WORKTREE_SOURCE_ONLY:-}" && "${BASH_SOURCE[0]}" != "$0" ]]; then
+    return 0
+fi
+
 if [[ $# -ne 1 || -z "${1:-}" ]]; then
     echo "usage: $0 <task-id>" >&2
     echo "  ブランチ名は todo/<task-id> に固定 (custom branch 非対応)" >&2
@@ -197,7 +219,7 @@ fi
 # storage/oauth-*.key / public/build は runtime artifact (.gitignore 対象) で、workspace に
 # あればコピー / 無ければ note して続行 (テンプレート初期状態では未生成のことがある。
 # 必要になった時点で worktree 内 `php artisan passport:keys` / `pnpm build` で生成できる)。
-echo ">>> [2/7] .env / storage/oauth-*.key / public/build を親からコピー"
+echo ">>> [2/7] .env / .env.bughunt.local / storage/oauth-*.key / public/build を親からコピー"
 if [[ -f "${REPO_ROOT}/.env" ]]; then
     cp "${REPO_ROOT}/.env" "${WORKTREE_DIR}/.env"
 else
@@ -211,6 +233,14 @@ for f in storage/oauth-private.key storage/oauth-public.key; do
         echo "    note: ${f} が親に無いためコピーをスキップ (必要なら worktree 内で 'php artisan passport:keys')" >&2
     fi
 done
+# ★ 関数呼び出しを if の条件に置かない。条件内では set -e が効かず、
+#   install の失敗が「無いためスキップ」に化けて秘密ファイルのコピー失敗を隠す。
+if [[ -f "${REPO_ROOT}/.env.bughunt.local" ]]; then
+    provision_bughunt_env_file "${REPO_ROOT}" "${WORKTREE_DIR}"
+    PROVISIONED_PATHS+=(".env.bughunt.local")
+else
+    echo "    note: .env.bughunt.local が親に無いためコピーをスキップ (bug-hunt 未使用なら不要)" >&2
+fi
 if [[ -d "${REPO_ROOT}/public/build" ]]; then
     cp -r "${REPO_ROOT}/public/build" "${WORKTREE_DIR}/public/build"
     PROVISIONED_PATHS+=("public/build")
diff --git a/tests/Architecture/BughuntOptimizeClearTaskInventoryTest.php b/tests/Architecture/BughuntOptimizeClearTaskInventoryTest.php
new file mode 100644
index 0000000..863d81d
--- /dev/null
+++ b/tests/Architecture/BughuntOptimizeClearTaskInventoryTest.php
@@ -0,0 +1,70 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\ServiceProvider;
+
+/*
+ * optimize:clear の拡張タスク目録 (deny-by-default)。
+ *
+ * bug-hunt の provision は `optimize:clear --except=cache` を叩く (dev DB の cache 表に
+ * 触れないようにするため)。標準タスクのうち DB に触る cache:clear は除外したが、
+ * ServiceProvider::$optimizeClearCommands 経由で **パッケージが登録した clear コマンド** も
+ * 同時に実行される。ここが増えると「dev DB を触らない」前提が静かに崩れる。
+ *
+ * ★ これは証明ではなく **検出** である。集合が増えたら赤くなる。
+ * ★ 保証しないもの: 既存の同名コマンド (filament:optimize-clear / icons:clear) の内部実装が
+ *   依存更新によって DB 接続を始めても、集合検査は赤くならない (集合の増減しか見ていない)。
+ *   そのため rationale は **package version 更新時に再確認する** 運用とする。
+ */
+
+/** key = $optimizeClearCommands のキー / value = [コマンド, 登録元, 非 DB と判断した理由]。 */
+const BUGHUNT_OPTIMIZE_CLEAR_ALLOWLIST = [
+    'filament' => [
+        'filament:optimize-clear',
+        'filament/support',
+        'Filament の component / blade キャッシュ (ファイル) の破棄。DB を触らない',
+    ],
+    'blade-icons' => [
+        'icons:clear',
+        'blade-ui-kit/blade-icons',
+        'アイコンキャッシュ (ファイル) の破棄。DB を触らない',
+    ],
+];
+
+test('optimize:clear の拡張タスクが既知の allowlist と完全一致すること', function (): void {
+    $registered = ServiceProvider::$optimizeClearCommands;
+
+    expect(array_keys($registered))
+        ->toEqualCanonicalizing(
+            array_keys(BUGHUNT_OPTIMIZE_CLEAR_ALLOWLIST),
+            '$optimizeClearCommands の集合が変わった。増えた clear コマンドが DB を触らないかを'
+            .'人が判断してから allowlist に足すこと (bug-hunt の provision がこれを実行する)',
+        );
+
+    foreach (BUGHUNT_OPTIMIZE_CLEAR_ALLOWLIST as $key => [$command, $package, $rationale]) {
+        expect($registered[$key])->toBe(
+            $command,
+            "allowlist の登録コマンドが変わった: {$key} ({$package})",
+        );
+        expect($rationale)->not->toBe('', "rationale が空: {$key}");
+    }
+});
+
+test('bug-hunt の provision が optimize:clear から cache タスクを外していること', function (): void {
+    // --except は OptimizeClearCommand::handle() の $exceptions->hasAny([$command, $key]) により
+    // キー名 'cache' とコマンド名 'cache:clear' の両方に一致する。
+    $script = file_get_contents(base_path('scripts/bug-hunt-shard.sh'));
+
+    // 実行行だけを対象にする (self-test は同じ語を検査文字列として持つため、
+    // 単に 'optimize:clear' を含む行を数えると self-test 側まで拾ってしまう)。
+    $lines = array_values(array_filter(
+        explode("\n", $script),
+        fn (string $line): bool => str_contains($line, 'php artisan optimize:clear')
+            && preg_match('/^\s*#/', $line) !== 1,
+    ));
+
+    expect($lines)->toHaveCount(1, 'optimize:clear の実行行が 1 行ではない');
+    expect($lines[0])->toContain('--except=cache');
+    expect($lines[0])->toContain('env -i');
+});
diff --git a/tests/Architecture/BughuntRawDbCommandInventoryTest.php b/tests/Architecture/BughuntRawDbCommandInventoryTest.php
new file mode 100644
index 0000000..8ee83fb
--- /dev/null
+++ b/tests/Architecture/BughuntRawDbCommandInventoryTest.php
@@ -0,0 +1,138 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * bug-hunt harness の raw DB コマンド目録 (deny-by-default)。
+ *
+ * dev DB 防御の核は「createdb / dropdb は admin 経路 (pg_admin_for_provision) だけが実行し、
+ * その中で DB 名 regex と admin role 明示を通る」ことである。スクリプトのどこかに
+ * raw な createdb / dropdb が増えると、この一点集中が静かに崩れる。
+ *
+ * ★ 保証範囲を先に限定する: これは **literal な出現の検出**であって、
+ *   変数展開 ($cmd) / 関数経由 / env 経由 / eval まで含めた「呼び出しが無いこと」の**証明ではない**。
+ *   そこまで見るには bash の AST 相当の解析が要る。ここでは
+ *   「うっかり dropdb と書いた行が増えていないか」を保守的に検出する。
+ *
+ * ★ なぜ「文字列リテラルを除外する」方式を採らないか: bash の字句解析なしに
+ *   文字列中の dropdb を正しく除外することはできない。除外を試みると逆に**実行行を見落とす**
+ *   穴を作る。そこで「literal が現れる行を全部数え、既知の目録と完全一致するか」という
+ *   保守的な方式にする (inline コメントもメッセージも目録に載せる。冗長だが見落とさない)。
+ */
+
+/** 実行実体。各ちょうど 1 行存在しなければならない。 */
+const BUGHUNT_RAW_DB_REQUIRED = [
+    'op_cmd=(createdb -O bughunt' => 'admin 経路の createdb 実体 (OWNER bughunt 必須)',
+    'op_cmd=(dropdb --if-exists' => 'admin 経路の dropdb 実体',
+];
+
+/**
+ * 存在してよい行 (wrapper 呼び出し / メッセージ / inline コメント / self-test)。
+ * key = 一意な識別部分文字列 / value = [出現回数, 理由]。
+ */
+const BUGHUNT_RAW_DB_ALLOWED = [
+    'die 1 "guard_admin_provision: BUGHUNT_ADMIN_USER' => [1, 'admin role 未設定時のエラーメッセージ'],
+    'local op=$1 db=$2' => [1, 'inline コメント `# op ∈ {createdb, dropdb}`'],
+    '_out_pgids+=("${wpid}")' => [1, 'inline コメント (dropdb 直前の再確認用に pgid を残す)'],
+    'pg_admin_for_provision createdb "${db}"' => [1, 'wrapper 経由の createdb 呼び出し (raw ではない)'],
+    'pg_admin_for_provision dropdb "$(shard_db "${shard}")"' => [1, 'wrapper 経由の dropdb 呼び出し (raw ではない)'],
+    'echo "warning: shard-${shard} の worker 停止に失敗' => [1, 'dropdb スキップの警告文'],
+    'echo "warning: shard-${shard} の worker が dropdb 直前の再確認で残留' => [1, '再確認失敗時の警告文'],
+    'echo "[f] createdb 実行コマンドに OWNER bughunt' => [1, 'self-test の見出し'],
+    "grep -q 'createdb -O bughunt'" => [1, 'self-test の検査条件'],
+    't_fail "createdb に OWNER bughunt' => [1, 'self-test の失敗メッセージ'],
+    't_ok "createdb OWNER bughunt"' => [1, 'self-test の成功ログ'],
+    't_fail "stop_shard_workers に process group 単位の停止' => [1, 'self-test の失敗メッセージ (dropdb と race)'],
+    't_fail "cmd_teardown に worker 停止失敗時の dropdb 抑止が無い' => [1, 'self-test の失敗メッセージ'],
+    'echo "[y7x] dropdb 到達制御' => [1, 'self-test の見出し'],
+    'local y7h_marker=' => [1, 'self-test の marker パス (dropdb-called)'],
+    'pg_admin_for_provision dropdb "$(shard_db 1)"' => [2, 'self-test 内の到達制御ケース 2 件 (y7h / y7j)'],
+    't_fail "[y7h] 非 zombie 残留なのに dropdb wrapper が呼ばれた' => [1, 'self-test の失敗メッセージ'],
+    't_fail "[y7j] 停止済みなのに dropdb 経路へ進まなかった"' => [1, 'self-test の失敗メッセージ'],
+    't_fail "[y7j] dropdb が DB 名 guard を通っていない' => [1, 'self-test の失敗メッセージ'],
+    't_fail "[y7i] cmd_teardown に dropdb 直前の再確認が無い"' => [1, 'self-test の失敗メッセージ'],
+    't_ok "dropdb 到達制御' => [1, 'self-test の成功ログ'],
+    'local y7q_marker=' => [1, 'self-test (y7q) の marker パス'],
+    't_fail "[y7q] 停止失敗 shard の dropdb が呼ばれた"' => [1, 'self-test (y7q) の失敗メッセージ'],
+    't_fail "[y7q] 停止対象なしの shard でも dropdb が呼ばれていない' => [1, 'self-test (y7q) の対照 (空振り防止) の失敗メッセージ'],
+    'local y7r_marker=' => [1, 'self-test (y7r) の marker パス'],
+    't_fail "[y7r] dropdb 直前の再確認で live が出たのに teardown が成功で終わった"' => [1, 'self-test (y7r) の失敗メッセージ'],
+    't_fail "[y7r] 再確認で live を観測したのに dropdb が呼ばれた' => [1, 'self-test (y7r) の失敗メッセージ'],
+];
+
+/**
+ * 行頭コメントを除いた行のうち、単語境界の createdb / dropdb を含むものを返す。
+ *
+ * @return list<string>
+ */
+function bughuntRawDbLiteralLines(string $path): array
+{
+    $lines = file($path, FILE_IGNORE_NEW_LINES);
+    expect($lines)->toBeArray();
+
+    $hits = [];
+    foreach ($lines as $line) {
+        if (preg_match('/^\s*#/', $line) === 1) {
+            continue;   // 行頭コメント (冒頭の説明文で偽陽性になるため除外)
+        }
+        if (preg_match('/\b(createdb|dropdb)\b/', $line) === 1) {
+            $hits[] = trim($line);
+        }
+    }
+
+    return $hits;
+}
+
+test('createdb / dropdb の実行実体が admin 経路にちょうど 1 行ずつ存在すること', function (): void {
+    $hits = bughuntRawDbLiteralLines(base_path('scripts/bug-hunt-shard.sh'));
+
+    foreach (BUGHUNT_RAW_DB_REQUIRED as $key => $reason) {
+        $count = count(array_filter($hits, fn (string $line): bool => str_contains($line, $key)));
+
+        expect($count)->toBe(1, "必須実行行が 1 行ではない: '{$key}' ({$reason}) → {$count} 行");
+    }
+});
+
+test('createdb / dropdb の literal が目録と完全一致すること', function (): void {
+    $hits = bughuntRawDbLiteralLines(base_path('scripts/bug-hunt-shard.sh'));
+
+    // key => 期待件数 (必須 + 許可)
+    $expected = [];
+    foreach (BUGHUNT_RAW_DB_REQUIRED as $key => $_reason) {
+        $expected[$key] = 1;
+    }
+    foreach (BUGHUNT_RAW_DB_ALLOWED as $key => [$count, $_reason]) {
+        expect($expected)->not->toHaveKey($key, "目録に重複キー: '{$key}'");
+        $expected[$key] = $count;
+    }
+
+    // 1. 各行がちょうど 1 つの目録キーに一致すること (未知の行 / 曖昧な行を弾く)
+    $unknown = [];
+    $matched = [];
+    foreach ($hits as $line) {
+        $keys = array_values(array_filter(
+            array_keys($expected),
+            fn (string $key): bool => str_contains($line, $key),
+        ));
+
+        if ($keys === []) {
+            $unknown[] = $line;
+
+            continue;
+        }
+
+        expect($keys)->toHaveCount(
+            1,
+            "1 行が複数の目録キーに一致した (識別キーが曖昧): {$line} → ".implode(' / ', $keys),
+        );
+        $matched[$keys[0]] = ($matched[$keys[0]] ?? 0) + 1;
+    }
+
+    expect($unknown)->toBe([], "目録に無い createdb/dropdb の literal 行が増えている:\n".implode("\n", $unknown));
+
+    // 2. 件数が目録と一致すること (行が消えた場合も検出する)
+    expect($matched)->toEqual($expected, '目録の期待件数と実際の出現件数が一致しない');
+
+    // 3. 合計件数も突き合わせる (必須 2 + 許可分)
+    expect(count($hits))->toBe(array_sum($expected));
+});
diff --git a/tests/Architecture/BughuntSelfTestExecutionTest.php b/tests/Architecture/BughuntSelfTestExecutionTest.php
new file mode 100644
index 0000000..e98ed27
--- /dev/null
+++ b/tests/Architecture/BughuntSelfTestExecutionTest.php
@@ -0,0 +1,92 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\Facades\File;
+use Illuminate\Support\Facades\Process;
+
+/*
+ * bug-hunt harness の**実行配線**ゲート。
+ *
+ * scripts/bug-hunt-shard.sh self-test は「実資源に触れない自己検証」で、
+ * guard / 資源導出 / env 隔離 / worker 停止判定の **実行時挙動** を担う。
+ * 既存の Architecture テスト (BughuntShardCapInvariantTest /
+ * BughuntOrchestratorGateInvariantTest) は静的構造だけを見ており、self-test を
+ * 「参照」はしていても **呼んではいなかった** = 二段防御の片側が自動実行されていなかった。
+ * ここで composer test の配線に載せる。
+ *
+ * 隔離境界はテスト側が握る。self-test は BUGHUNT_SANDBOX が与えられていればそれを使い、
+ * 未指定のときだけ mktemp -d する契約になっている。外部指定は「捨ててよい空き地」だけを
+ * 受け付けるため専用マーカーを要求する (/ や リポジトリルートを渡す事故を構造的に防ぐ)。
+ */
+
+/** self-test へ渡す「捨ててよい空き地」を作る (マーカー必須)。 */
+function makeSelfTestSandbox(): string
+{
+    $dir = sys_get_temp_dir().'/bughunt-selftest-pest-'.bin2hex(random_bytes(6));
+    File::makeDirectory($dir, 0700, true);
+    File::put($dir.'/.bughunt-selftest-sandbox', '');
+    chmod($dir.'/.bughunt-selftest-sandbox', 0600);
+
+    return $dir;
+}
+
+test('bug-hunt harness の self-test が通ること', function (): void {
+    $script = base_path('scripts/bug-hunt-shard.sh');
+    expect(is_readable($script))->toBeTrue();
+
+    $tmp = makeSelfTestSandbox();   // ★ マーカー付き。無いと self-test が die 2 で落ちる
+
+    try {
+        // executable bit に依存せず bash 経由で起動する。
+        // timeout は実測 ~4 秒に対し 120 秒 (CI の遅さを吸収しつつ無限待ちにしない)。
+        $process = Process::timeout(120)
+            ->env(['BUGHUNT_SANDBOX' => $tmp, 'TMPDIR' => $tmp])
+            ->run(['bash', $script, 'self-test']);
+
+        expect($process->exitCode())->toBe(
+            0,
+            "self-test が失敗した:\n".$process->output()."\n".$process->errorOutput(),
+        );
+    } finally {
+        File::deleteDirectory($tmp);
+    }
+});
+
+test('self-test が外から与えた BUGHUNT_SANDBOX を尊重し削除しないこと', function (): void {
+    // 「通ること」だけでは隔離境界の退行を検出できない。外から渡した sandbox が
+    // 実際に使われ (= その配下に成果物ができ)、かつ **消されない** ことを見る。
+    $tmp = makeSelfTestSandbox();
+
+    try {
+        $process = Process::timeout(120)
+            ->env(['BUGHUNT_SANDBOX' => $tmp, 'TMPDIR' => $tmp])
+            ->run(['bash', base_path('scripts/bug-hunt-shard.sh'), 'self-test']);
+
+        expect($process->exitCode())->toBe(0, $process->errorOutput());
+        expect(File::isDirectory($tmp))->toBeTrue('外部指定 sandbox が削除された (借り物を消してはならない)');
+        expect(File::isDirectory($tmp.'/tmp/bug-hunt'))->toBeTrue(
+            '外から与えた BUGHUNT_SANDBOX が使われていない (隔離境界をテストが握れていない)',
+        );
+    } finally {
+        File::deleteDirectory($tmp);
+    }
+});
+
+test('外部 sandbox はマーカーが無ければ拒否されること', function (): void {
+    // 「捨ててよい空き地」の証拠が無いディレクトリを受け付けると、/ や リポジトリルートを
+    // 渡された時に実資源へ書き込みうる。拒否そのものを固定する。
+    $dir = sys_get_temp_dir().'/bughunt-selftest-nomarker-'.bin2hex(random_bytes(6));
+    File::makeDirectory($dir, 0700, true);
+
+    try {
+        $process = Process::timeout(120)
+            ->env(['BUGHUNT_SANDBOX' => $dir, 'TMPDIR' => $dir])
+            ->run(['bash', base_path('scripts/bug-hunt-shard.sh'), 'self-test']);
+
+        expect($process->exitCode())->not->toBe(0, 'マーカー無しの外部 sandbox が受理された');
+        expect($process->errorOutput())->toContain('.bughunt-selftest-sandbox');
+    } finally {
+        File::deleteDirectory($dir);
+    }
+});
diff --git a/tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php b/tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php
new file mode 100644
index 0000000..427e6d1
--- /dev/null
+++ b/tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php
@@ -0,0 +1,118 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\Facades\File;
+use Illuminate\Support\Facades\Process;
+
+/*
+ * setup-worktree.sh の実行時ファイル provisioning 契約。
+ *
+ * bug-hunt は worktree 走行が既定 (AGENTS.md) だが、.env.bughunt.local は .gitignore 対象で
+ * worktree には決して現れない。親からのコピーが唯一の供給路であり、無いと provision が必ず止まる
+ * (bug-hunt run 20260809-152048 で実際に踏み、手動 cp で回避した)。
+ *
+ * 秘密ファイルの複製なので **mode は 0600 に固定**する。親が 0644 のとき `cp -p` は
+ * world-readable な秘密ファイルを新たに作るため契約として弱く、`cp` → `chmod` の 2 段にも
+ * 「一瞬だけ広く読める窓」がある。`install -m 600` は作成時点で mode を確定する。
+ *
+ * setup-worktree.sh は top-level 実行型 (main() を持たない) なので、素朴に source すると
+ * composer install / pnpm install / DB 作成まで走る。SETUP_WORKTREE_SOURCE_ONLY で
+ * 関数定義だけ取り込んで抜ける guard を使う。
+ */
+
+/**
+ * setup-worktree.sh を source して provision_bughunt_env_file だけを叩く。
+ * 引数は位置引数で渡す (文字列連結による shell injection を避ける)。
+ */
+function runProvisionBughuntEnvFile(string $parent, string $worktree): int
+{
+    $result = Process::timeout(60)
+        ->env(['SETUP_WORKTREE_SOURCE_ONLY' => '1'])
+        ->run([
+            'bash', '-c',
+            'source "$1"; provision_bughunt_env_file "$2" "$3"',
+            '_',
+            base_path('scripts/setup-worktree.sh'),
+            $parent,
+            $worktree,
+        ]);
+
+    return $result->exitCode() ?? 1;
+}
+
+/** @return array{0: string, 1: string} [親, worktree] の一時ディレクトリ */
+function makeWorktreeFixture(): array
+{
+    $base = sys_get_temp_dir().'/setup-worktree-contract-'.bin2hex(random_bytes(6));
+    File::makeDirectory($base.'/parent', 0700, true);
+    File::makeDirectory($base.'/worktree', 0700, true);
+
+    return [$base.'/parent', $base.'/worktree'];
+}
+
+test('親に .env.bughunt.local があれば worktree へコピーされる', function (): void {
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");
+
+        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
+        expect(File::exists($worktree.'/.env.bughunt.local'))->toBeTrue();
+        expect(File::get($worktree.'/.env.bughunt.local'))->toBe("APP_ENV=bughunt.local\n");
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
+
+test('親に .env.bughunt.local が無ければ何もしない (bug-hunt 非利用リポジトリで no-op)', function (): void {
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
+        expect(File::exists($worktree.'/.env.bughunt.local'))->toBeFalse();
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
+
+test('親が 0644 でもコピー先は 0600 になる', function (): void {
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");
+        chmod($parent.'/.env.bughunt.local', 0644);
+
+        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
+
+        $mode = fileperms($worktree.'/.env.bughunt.local') & 0777;
+        expect(decoct($mode))->toBe('600', 'コピー先が world-readable になっている (cp -p / cp+chmod への退行)');
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
+
+test('コピー先が既に存在しても上書き後に 0600 になる', function (): void {
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        File::put($parent.'/.env.bughunt.local', "APP_ENV=new\n");
+        chmod($parent.'/.env.bughunt.local', 0644);
+        File::put($worktree.'/.env.bughunt.local', "APP_ENV=old\n");
+        chmod($worktree.'/.env.bughunt.local', 0666);
+
+        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
+
+        expect(File::get($worktree.'/.env.bughunt.local'))->toBe("APP_ENV=new\n");
+        expect(decoct(fileperms($worktree.'/.env.bughunt.local') & 0777))->toBe('600');
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
+
+test('install -m 600 を使っていること (cp + chmod の 2 段へ退行していない)', function (): void {
+    // 2 段だと cp 直後から chmod までの間だけ world-readable な秘密ファイルが存在する。
+    $source = File::get(base_path('scripts/setup-worktree.sh'));
+
+    expect($source)->toContain('install -m 600 "${repo_root}/.env.bughunt.local"');
+});

```
