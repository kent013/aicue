# 詳細設計: bugfix-bughunt-queue-worker (F-01: bug-hunt 環境のジョブ滞留解消)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）— 本設計は PHP 製品コードを変更しないため影響なし
- **Pest**テストフレームワーク（`composer test`）— 同上（既存テストを壊さないことは確認する）
- **RefreshDatabase** + `--parallel` 並列実行（個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成** — 本設計では該当なし
- **DTO + JsonResource** パターン — 本設計では該当なし
- **アーリーリターン** 推奨（bash では guard 関数 + `die` の既存流儀に従う）
- **コードフォーマット**: bash は既存スクリプトの流儀（`set -euo pipefail`、関数コメント、日本語コメント）に従う
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

### 本設計固有の非交渉要件（AGENTS.md §bug-hunt）

- **dev DB 防御**: 全 DB 操作は用途別 wrapper（`env -i` で shell の `DB_*`/`PG*` 遮断 + DB名 regex +
  role guard）経由のみ。worker 起動も `guard_bughunt_runtime` を同一プロセスの直前に通す。
- **orchestrator gate**: worker の起動/停止は `BUGHUNT_ORCHESTRATOR=1` を持つ親専用の
  provision / teardown の内部処理としてのみ実行される。
- **self-test は実資源（DB/serve/実プロセス常駐）に触れない**。

## 概念設計リファレンス

[devnotes/20260712-1954-bugfix-bughunt-queue-worker/conceptual-design.md](./conceptual-design.md)
（Codex 概念レビュー Round 1 APPROVED。Warning 4 件対応済み: PHP 実評価 drift check /
受け入れ条件の絞り込み / setsid + process group kill / keepdb-check の cmdline 検証）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | worker 共通ヘルパ（connection リスト・pidfile 導出・起動/停止関数・生存照合） | `scripts/bug-hunt-shard.sh` | High |
| 2 | provision への worker 起動配線 + manifest 記録 | `scripts/bug-hunt-shard.sh` | High |
| 3 | teardown への worker 停止配線（process group kill） | `scripts/bug-hunt-shard.sh` | High |
| 4 | keepdb-check への worker 生存確認追加 | `scripts/bug-hunt-shard.sh` | Medium |
| 5 | self-test 拡張（[y] worker 配線 + config drift check） | `scripts/bug-hunt-shard.sh` | High |
| 6 | コメント/ドキュメント整合（env コメント・ヘッダコメント） | `scripts/bug-hunt-shard.sh`, `.env.bughunt.local`, `.env.bughunt.local.example` | Low |

---

## 施策 1: worker 共通ヘルパ（connection リスト・pidfile 導出・起動/停止関数・生存照合）

### 変更箇所

- ファイル: `scripts/bug-hunt-shard.sh`
  - L94-105 付近（資源導出セクション）: `worker_pidfile` / `worker_logfile` を追加
  - L575（`cmd_keepdb_check`）と L577（worktree 文脈ガード）の間、または provision セクション直前:
    `BUGHUNT_WORKER_CONNECTIONS` 定義と `worker_alive` / `start_shard_workers` /
    `stop_shard_workers` を追加

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: なし（bash self-test は施策 5 で拡張）

### 現行コード

```bash
# --- 資源導出 (shard 番号から一意化) ------------------------------------------

shard_db() { [[ "$1" == 0 ]] && echo "${BUGHUNT_DB_PREFIX}" || echo "${BUGHUNT_DB_PREFIX}_$1"; }
shard_port() { echo "$((BASE_PORT + $1))"; }
...
wrapper_path() { echo "${TMP_BASE}/shard-$1-cmd.sh"; }
```

（worker 関連の資源導出・起動関数は存在しない。`grep -n "queue:" scripts/bug-hunt-shard.sh` 該当なし）

### 変更後コード

```bash
# --- 資源導出 (shard 番号から一意化) ------------------------------------------
# （既存の導出関数群に追記）
worker_pidfile() { echo "${TMP_BASE}/worker-$1-$2.pid"; }   # $1=shard $2=connection
worker_logfile() { echo "${TMP_BASE}/worker-$1-$2.log"; }

# --- 専用 queue connection worker (F-01 対策) ----------------------------------
# RunManualAnalysis / RunManualRender / DeleteTakeObjectsJob / DeleteRenderOutputsJob は
# onConnection() で専用 connection (driver=database 固定) を指定するため、
# .env.bughunt.local の QUEUE_CONNECTION=sync (default connection の差し替え) をバイパスする。
# provision が本リストの connection ごとに queue:listen worker を起動し、teardown が停止する。
# ★ リストは config/queue.php の「driver=database の専用 connection (既定 'database' を除く)」と
#   一致させること (self-test [y] が PHP 実評価で drift を機械検出する。順序は不問 = sort 比較)。
BUGHUNT_WORKER_CONNECTIONS=(database-analysis database-render database-media)

# worker pid が「当該 connection の queue:listen」として生きているかの検証 (kill -0 では
# stale pidfile / pid 再利用を誤判定するため /proc cmdline を照合する。Linux 前提 = teardown と同じ)。
# 照合は artisan / queue:listen / connection 名 / --env=bughunt.local を独立に確認する
# (単一パターンだと将来の引数順序変化で偽陰性化するため。Codex 詳細 R1 反映)。
worker_alive() {
    local shard=$1 conn=$2 pid cmdline
    pid="$(cat "$(worker_pidfile "${shard}" "${conn}")" 2>/dev/null || echo)"
    [[ -n "${pid}" && "${pid}" != 0 && -r "/proc/${pid}/cmdline" ]] || return 1
    # 存在確認と読み出しの間にプロセスが終了する race に備え、読めなければ静かに false
    cmdline="$(tr '\0' ' ' < "/proc/${pid}/cmdline" 2>/dev/null || true)"
    [[ -n "${cmdline}" ]] || return 1
    echo "${cmdline}" | grep -q "artisan" \
        && echo "${cmdline}" | grep -q "queue:listen" \
        && echo "${cmdline}" | grep -q -- " ${conn} " \
        && echo "${cmdline}" | grep -q -- "--env=bughunt.local"
}

# 専用 connection worker の起動。serve と同一の env 隔離 (env -i + bughunt 値明示注入)。
# - queue:listen を使う: 各イテレーションで子 (queue:work --once) を起動する Laravel 公式の
#   スーパーバイザ構成。reseed (migrate:fresh) で jobs/cache テーブルが一時消滅して子が
#   異常終了しても master が継続する (queue:work daemon は cache 読みの QueryException で
#   静かに死に F-01 が再発しうる)。
# - setsid で専用 process group (pid==pgid) 化: teardown が process group 一括 kill で
#   master と子を race なく停止するため。
# - --tries=1 は Job 側の $tries=1 と整合。--timeout=1800 は listener が子を kill する天井で、
#   Job 側の $timeout (1,380/1,500) が pcntl alarm で先に効く (予約 TTL 1,800 と同値)。
start_shard_workers() {
    local shard=$1 db=$2 url=$3
    guard_bughunt_runtime "${db}" bughunt
    local conn pid
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        env -i PATH="${PATH}" HOME="${HOME}" \
            DB_CONNECTION=pgsql \
            DB_HOST="$(env_file_required DB_HOST)" DB_PORT="$(env_file_required DB_PORT)" \
            DB_DATABASE="${db}" DB_USERNAME=bughunt DB_PASSWORD="$(env_file_get DB_PASSWORD)" \
            APP_URL="${url}" \
            setsid php artisan queue:listen "${conn}" --env=bughunt.local \
                --sleep=1 --tries=1 --timeout=1800 \
            > "$(worker_logfile "${shard}" "${conn}")" 2>&1 &
        pid=$!
        echo "${pid}" > "$(worker_pidfile "${shard}" "${conn}")"
    done
    # fail-fast: 起動 1 秒後の即死検知 (artisan 起動失敗・接続不能などを provision 段階で顕在化)。
    # 併せて pid==pgid (setsid が新 session/process group を確立したこと) を検証する
    # (group kill / group 消滅待ちの前提条件を起動時不変条件として固定。Codex 詳細 R3 反映)。
    # 失敗時は起動済みの同 shard worker をその場で回収してから die (teardown 依存の残骸を減らす)
    sleep 1
    local pgid
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        pid="$(cat "$(worker_pidfile "${shard}" "${conn}")")"
        pgid="$(ps -o pgid= -p "${pid}" 2>/dev/null | tr -d ' ' || true)"
        if ! worker_alive "${shard}" "${conn}" || [[ "${pgid}" != "${pid}" ]]; then
            stop_shard_workers "${shard}" || true
            die 1 "shard-${shard} worker (${conn}) が起動しない/setsid 不成立 (pid=${pid} pgid=${pgid:-?}。$(worker_logfile "${shard}" "${conn}") 参照)"
        fi
    done
}

# 当該 shard の worker を全停止する (teardown / 起動失敗ロールバックの共通経路)。
# setsid 起動により pid==pgid のため process group 一括 kill (master + queue:work --once 子)。
# cmdline 照合 (worker_alive) 不一致/死亡済みの stale pidfile は kill せず削除のみ (誤 kill 防止優先)。
# 停止シーケンス: TERM → group 消滅待ち (最大 2s) → KILL escalation → 再確認。
# 成功条件は **process group 全体の消滅** (master 単体判定だと終了処理中の queue:work 子の
# DB 接続が残り dropdb と race する)。kill -0 -- -PGID は cmdline 照合済みの自所有 group への
# 存在確認で待機用途として安全。全 shard 横断の pgrep 判定はしない。
# ★ 消滅を確認できた group のみ pidfile を削除する。残留した group の pidfile は保持し
#   (追跡可能性を失わない)、戻り値 1 で失敗を通知する (呼び出し側が dropdb を抑止する)。
# (Codex 詳細 R1/R2/R3 反映)
stop_shard_workers() {
    local shard=$1 conn wpidfile wpid wpgid t rc=0
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        wpidfile="$(worker_pidfile "${shard}" "${conn}")"
        [[ -f "${wpidfile}" ]] || continue
        wpid="$(cat "${wpidfile}" 2>/dev/null || echo)"
        if ! worker_alive "${shard}" "${conn}"; then
            # プロセス不存在 = 真に stale → 削除のみ。プロセスは存在するが所有確認 (cmdline 照合)
            # できない場合は、一時的な /proc 読み出し失敗や pid 再利用の可能性があり
            # 「停止済み」と誤認して追跡情報を消してはならない → pidfile 保持 + 失敗通知
            if [[ -n "${wpid}" && "${wpid}" != 0 ]] && kill -0 "${wpid}" 2>/dev/null; then
                echo "error: shard-${shard} worker (${conn}) pid=${wpid} は存在するが所有確認できない — kill せず pidfile 保持 (${wpidfile})" >&2
                rc=1
            else
                rm -f "${wpidfile}"
            fi
            continue
        fi
        # group kill の前提 (pid==pgid = setsid 成立) を停止側でも検証する。不成立のまま
        # kill -0 -- -pid すると「存在しない group が消滅済み」と誤認し実 worker を残留させる
        wpgid="$(ps -o pgid= -p "${wpid}" 2>/dev/null | tr -d ' ' || true)"
        if [[ "${wpgid}" != "${wpid}" ]]; then
            echo "error: shard-${shard} worker (${conn}) pid=${wpid} pgid=${wpgid:-?} — setsid 不成立のため group kill せず pidfile 保持 (${wpidfile})" >&2
            rc=1
            continue
        fi
        kill -TERM -- "-${wpid}" 2>/dev/null || true
        for t in 1 2 3 4 5; do
            kill -0 -- "-${wpid}" 2>/dev/null || break
            sleep 0.4
        done
        if kill -0 -- "-${wpid}" 2>/dev/null; then
            kill -KILL -- "-${wpid}" 2>/dev/null || true
            sleep 0.4
        fi
        if kill -0 -- "-${wpid}" 2>/dev/null; then
            echo "error: shard-${shard} worker group (${conn}, pgid=${wpid}) が KILL 後も残留 (pidfile 保持: ${wpidfile})" >&2
            rc=1
            continue
        fi
        rm -f "${wpidfile}"
    done
    return "${rc}"
}
```

補足（設計判断の根拠）:

- **`setsid` の pid 保存性**: スクリプト (非対話 shell、job control off) から `... setsid php ... &`
  で起動した場合、setsid 自身は process group leader ではないため fork せずに `setsid(2)` →
  `exec` する。したがって `$!` は php プロセスの pid であり、新セッションのリーダー
  (pid == pgid) になる。`setsid --fork` は使わない（pid が取れなくなるため）。
- **`nohup` は不要**: setsid で制御端末から切り離されるため tty 由来の SIGHUP は届かない
  （serve 側の既存 `nohup` はそのまま触らない）。
- **`queue:listen {connection}` の妥当性**: ListenCommand は connection 引数と
  `--sleep/--tries/--timeout` を子 `queue:work --once` に引き渡す。`--env=bughunt.local` は
  Listener の environment オプションとして子コマンドへ伝播し、さらに `env -i` で注入した
  環境変数はプロセス継承で子にも届く（DB_DATABASE 等の隔離が worker 全系で維持される）。
- **cmdline 照合パターン**: `/proc/{pid}/cmdline` は
  `php artisan queue:listen database-analysis --env=bughunt.local ...`（NUL 区切り）。
  `tr '\0' ' '` 後に artisan / queue:listen / `" ${conn} "`（前後スペース付き = 接頭辞衝突防止）/
  `--env=bughunt.local` を**独立の grep で照合**する（引数順序変化への耐性）。
- **`DB_USERNAME=bughunt` の固定注入は既存流儀の維持（意図的）**: 本スクリプトは
  `artisan_for_shard` / serve 起動ブロックの全 runtime 経路で `DB_USERNAME=bughunt` を
  固定注入しており、provision 冒頭 L626 で
  `[[ "$(env_file_get DB_USERNAME)" == "bughunt" ]] || die` により env ファイル側との乖離を
  fail-fast している。さらに `guard_bughunt_runtime` は user==bughunt を hard-deny の判定軸に
  している（dev DB 防御の非交渉要件: bughunt role は dev DB へ CONNECT できない）。
  worker だけ `env_file_get DB_USERNAME` 参照に変えると「env ファイルを書き換えれば任意 role で
  worker が走る」余地を作り、むしろ防御を弱める。既存経路と同一の固定注入を採る。

### PHPStan適合チェック

- [x] PHP 製品コードの変更なし（`php -r` の config 読み出しは self-test 内の read-only 検査のみ）
- [x] 型注釈・DTO は該当なし（bash）

### テスト計画

- [ ] self-test（施策 5）: `worker_pidfile`/`worker_logfile` の導出、`start_shard_workers` の
      構造検査（setsid / queue:listen / guard_bughunt_runtime を含むこと）、drift check
- [ ] 実機確認（実装フェーズ、worktree 内）: provision 後に
      `pgrep -f "queue:listen database-analysis"` で 3 worker の起動を確認

### リスク

- `setsid` は util-linux（Linux）前提。既存実装も `/proc` / `flock` に依存しており、
  bug-hunt provisioning は Linux devcontainer 前提のため後退ではない（self-test の該当
  検査は構造検査のみで、macOS でも fail しない）。
- queue:listen は各イテレーションでフレームワークを再ブートする（~数百 ms/回 × 3 conn × N shard）。
  並列 8 shard で 24 listener になるが、ポーリングは `--sleep=1` の待機を子プロセス内で行うため
  CPU 負荷は限定的。ハーネス用途では許容（本番は従来どおり queue:work を worker 定義に登録）。

---

## 施策 2: provision への worker 起動配線 + manifest 記録

### 変更箇所

- ファイル: `scripts/bug-hunt-shard.sh` `cmd_provision()`（L600-743）
  - serve ヘルスチェック成功後（L732 の直後、`# (f) shard wrapper 生成` の前）に
    ステップ `(e2)` として worker 起動を追加
  - `(g)` manifest 記録に worker pid を追加

### 波及変更

- TypeScript型定義: なし / API Resource/DTO: なし
- テストファイル: なし（self-test は施策 5）
- `cmd_provision` は `provision-all` からも呼ばれるため、並列 shard でも自動的に worker が起動する
  （追加変更不要であることを明記）

### 現行コード

```bash
    if [[ "${code}" != 200 && "${code}" != 302 ]]; then
        kill -TERM "${serve_pid}" 2>/dev/null || true
        die 1 "shard-${shard} serve (:${port}) が 30s で起動しない (last=${code}、${TMP_BASE}/serve-${shard}.log 参照)"
    fi

    # (f) shard wrapper 生成
    generate_wrapper "${shard}" "${run_id}"

    # (g) manifest 記録
    manifest_update "${run_id}" "${shard}" \
        "db=\"${db}\"" "port=${port}" "app_url=\"${url}\"" \
        "serve_pid=${serve_pid}" "log_offset=${offset}" \
        "started_at=\"$(TZ=Asia/Tokyo date '+%Y-%m-%d %H:%M:%S')\""
    echo "provisioned: shard ${shard} db=${db} url=${url} serve_pid=${serve_pid}"
```

### 変更後コード

```bash
    if [[ "${code}" != 200 && "${code}" != 302 ]]; then
        kill -TERM "${serve_pid}" 2>/dev/null || true
        die 1 "shard-${shard} serve (:${port}) が 30s で起動しない (last=${code}、${TMP_BASE}/serve-${shard}.log 参照)"
    fi

    # (e2) 専用 queue connection worker 起動 (F-01 対策。BUGHUNT_WORKER_CONNECTIONS 参照)
    start_shard_workers "${shard}" "${db}" "${url}"

    # (f) shard wrapper 生成
    generate_wrapper "${shard}" "${run_id}"

    # (g) manifest 記録 (worker pid = pgid。setsid により group 一括 kill の対象 id を兼ねる)。
    # key はハイフンを underscore に正規化 (shell 変数名として扱う消費側が現れても壊れないように)
    local -a worker_pid_entries=()
    local conn
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        worker_pid_entries+=("worker_pid_${conn//-/_}=$(cat "$(worker_pidfile "${shard}" "${conn}")")")
    done
    manifest_update "${run_id}" "${shard}" \
        "db=\"${db}\"" "port=${port}" "app_url=\"${url}\"" \
        "serve_pid=${serve_pid}" "log_offset=${offset}" \
        "${worker_pid_entries[@]}" \
        "started_at=\"$(TZ=Asia/Tokyo date '+%Y-%m-%d %H:%M:%S')\""
    echo "provisioned: shard ${shard} db=${db} url=${url} serve_pid=${serve_pid} workers=${#BUGHUNT_WORKER_CONNECTIONS[@]}"
```

補足:

- **dryrun 経路は不変**: `cmd_provision` は L609-616 で `is_dryrun` なら (e) 以前に return するため、
  worker 起動には到達しない（self-test で「dryrun が worker pidfile を作らない」ことを検証）。
- **実効 env 検証 (c) は不変**: `queue: "sync"` の期待値は default connection に対するもので、
  専用 connection は driver=database のまま worker が処理する（検証項目の変更なし）。
- manifest のキーは `worker_pid_database_analysis` 等（ハイフンを underscore に正規化。
  施策 2 変更後コードの `${conn//-/_}` 参照。shell 変数名として扱う将来の消費側でも壊れない）。
- worker 起動失敗時は `start_shard_workers` 内で **起動済みの同 shard worker を
  `stop_shard_workers` で即時回収してから** `die` する（施策 1 参照。serve と同じ fail-fast
  方針 + teardown 依存の残骸を減らす）。serve 等それ以外の残骸回収は従来どおり親 orchestrator の
  teardown（pidfile ベースで冪等）。

### PHPStan適合チェック

- [x] PHP 製品コード変更なし

### テスト計画

- [ ] self-test（施策 5）: `cmd_provision` の関数定義に `start_shard_workers` 配線が含まれること、
      dryrun provision が worker pidfile を作らないこと
- [ ] 実機確認（実装フェーズ）: `BUGHUNT_ORCHESTRATOR=1 scripts/bug-hunt-shard.sh provision --shard 0
      --run-id <ts>` → analyze トリガー相当（tinker で `AnalysisJobService::trigger` 直呼びは
      guard 済み wrapper 経由の手順で確認）→ `jobs` テーブルからレコードが消費され
      `analysis_jobs.status` が終端状態（completed/failed）へ遷移することを確認 → teardown

### リスク

- worker 3 本 × shard 数のプロセス増（並列 8 で +24）。teardown の pidfile ベース回収で
  管理されるため、serve と同等の運用リスクに収まる。
- LLM/ffmpeg fake 未配線の経路では job は failed 終端になる（概念設計の受け入れ条件どおり。
  completed 到達は fake 配線（別設計）に依存）。

---

## 施策 3: teardown への worker 停止配線（process group kill）

### 変更箇所

- ファイル: `scripts/bug-hunt-shard.sh` `cmd_teardown()`（L835-867）
  - shard ループ内、serve kill の**前**に worker 停止ブロックを追加

### 波及変更

- なし（テストは施策 5 の self-test）

### 現行コード

```bash
cmd_teardown() {
    local run_id=$1 drop_db=${2:-}
    require_orchestrator "teardown"
    local shard pid port
    for shard in 0 1 2 3 4 5 6 7 8; do
        local pidfile="${TMP_BASE}/serve-${shard}.pid"
        [[ -f "${pidfile}" ]] || continue
        pid="$(cat "${pidfile}" 2>/dev/null || echo)"
        ...（serve の cmdline 検証付き kill）...
        rm -f "${pidfile}"
        if [[ "${drop_db}" == "--drop-db" ]] && ! is_dryrun; then
            pg_admin_for_provision dropdb "$(shard_db "${shard}")"
        fi
        rm -f "$(wrapper_path "${shard}")"
        rm -rf "storage/bughunt-coverage/.pcov-ini-${shard}"
    done
    reap_orphan_browser
    echo "teardown done: run-id=${run_id}"
}
```

（worker の停止処理は存在しない。また現行は `serve-{shard}.pid` が無い shard を `continue` で
スキップするため、worker 停止は serve pidfile の有無と独立に行う必要がある）

### 変更後コード

```bash
cmd_teardown() {
    local run_id=$1 drop_db=${2:-}
    require_orchestrator "teardown"
    local shard pid port teardown_rc=0
    for shard in 0 1 2 3 4 5 6 7 8; do
        # 専用 connection worker の停止 (serve より先に止め、--drop-db 時の DB 接続残留を防ぐ)。
        # stop_shard_workers が cmdline 照合 → TERM → group 消滅待ち → KILL escalation →
        # 再確認まで行い、残留があれば pidfile を保持して非ゼロを返す (追跡可能性を失わない)。
        # 停止失敗 shard の dropdb は抑止する (接続保持した孤児 worker と dropdb の衝突防止)。
        local workers_stopped=1
        if ! stop_shard_workers "${shard}"; then
            workers_stopped=0
            teardown_rc=1
            echo "warning: shard-${shard} の worker 停止に失敗 — この shard の dropdb をスキップ (pidfile 保持)" >&2
        fi

        local pidfile="${TMP_BASE}/serve-${shard}.pid"
        if [[ -f "${pidfile}" ]]; then
            pid="$(cat "${pidfile}" 2>/dev/null || echo)"
            ...（serve の cmdline 検証付き kill、既存のまま）...
            rm -f "${pidfile}"
        fi
        if [[ "${drop_db}" == "--drop-db" && "${workers_stopped}" == 1 ]] && ! is_dryrun; then
            pg_admin_for_provision dropdb "$(shard_db "${shard}")"
        fi
        rm -f "$(wrapper_path "${shard}")"
        rm -rf "storage/bughunt-coverage/.pcov-ini-${shard}"
    done
    reap_orphan_browser
    [[ "${teardown_rc}" == 0 ]] \
        || die 1 "teardown 一部失敗: worker group が残留 (該当 shard の DB は破棄していない)。上記 warning の pidfile から手動確認・再 teardown すること"
    echo "teardown done: run-id=${run_id}"
}
```

補足（構造変更の理由）:

- 現行の `[[ -f "${pidfile}" ]] || continue` は「serve pidfile が無い shard は何も掃除しない」
  ロジックのため、そのままでは worker pidfile が孤立残留しうる。shard ループ先頭の `continue` を
  外し、worker 停止 → serve 停止 → dropdb → wrapper/カバレッジ掃除を各々 pidfile 有無で
  独立に判定する形へ再構成する（`--drop-db` の dropdb と wrapper 掃除は従来から pidfile 有無に
  依存すべきでない掃除であり、この再構成は既存挙動の是正も兼ねる。ただし dropdb は
  `--if-exists` 付きのため未 provision shard でも安全）。
- worker 停止は「cmdline 照合 → pid==pgid 検証 → TERM → group 消滅待ち → KILL escalation →
  再確認」（施策 1 の `stop_shard_workers` に集約。起動失敗ロールバックと共通経路）。
  照合に失敗した pidfile は、**pid が実在しない場合のみ** stale として削除する。
  pid が実在するのに所有確認（cmdline 照合）できない場合・pid!=pgid の場合は
  「停止済み」と誤認せず pidfile を保持して失敗を通知する（Codex 詳細 R4 反映）。
- **停止成功 = process group 全体の消滅** を stop_shard_workers の成功条件とし、
  失敗時は (1) 残留 group の pidfile を保持（追跡可能性）、(2) 当該 shard の dropdb を抑止、
  (3) teardown 全体を最後に非ゼロ終了、とする（Codex 詳細 R3 反映）。失敗 shard 以外の
  掃除は継続する（1 shard の残留で他 shard の serve/worker を放置しない）。
- PID 単体への TERM fallback は**置かない**: group が検証後に自然消滅した場合、再利用された
  同一 PID へ TERM を送る微小 race があるため（Codex 詳細 R3 反映）。setsid の成立
  （pid==pgid）は起動時に検証済みの不変条件（施策 1）。

### PHPStan適合チェック

- [x] PHP 製品コード変更なし

### テスト計画

- [ ] self-test（施策 5）: `cmd_teardown` に `stop_shard_workers` 配線が serve kill より前に
      存在すること、停止失敗時の dropdb 抑止（`workers_stopped` ガード）が存在すること、
      dryrun teardown で worker pidfile が残留しないこと
- [ ] 実機確認（実装フェーズ）: provision → teardown 後に
      `pgrep -f "queue:listen"` が 0 件、各 pgid の `kill -0 -- -<pgid>` が失敗すること

### リスク

- teardown は 0..8 の全 shard を舐めるため、複数 run の worker が共存しない前提（既存の
  lock 機構どおり）は不変。
- process group kill は同一 pgid の全プロセスに届く。setsid により pgid は worker 専用のため
  serve や orchestrator に波及しない。

---

## 施策 4: keepdb-check への worker 生存確認追加

### 変更箇所

- ファイル: `scripts/bug-hunt-shard.sh` `cmd_keepdb_check()`（L566-575）

### 波及変更

- なし

### 現行コード

```bash
cmd_keepdb_check() {
    local shard=$1
    cmd_assets_check || die 1 "--keep-db reuse 中止: アセットが stale (上記理由)。provision をスキップせず再 provision してください。"
    local url code
    url="$(shard_url "${shard}")"
    code="$(curl -s -o /dev/null -w '%{http_code}' "${url}/login" || true)"
    [[ "${code}" == "200" || "${code}" == "302" ]] \
        || die 1 "--keep-db reuse 中止: serve (${url}) 応答 ${code} (200/302 期待)。serve 未起動の可能性。"
    echo "keepdb-check: assets fresh + serve ${code} (reuse 可)"
}
```

### 変更後コード

```bash
cmd_keepdb_check() {
    local shard=$1
    cmd_assets_check || die 1 "--keep-db reuse 中止: アセットが stale (上記理由)。provision をスキップせず再 provision してください。"
    local url code
    url="$(shard_url "${shard}")"
    code="$(curl -s -o /dev/null -w '%{http_code}' "${url}/login" || true)"
    [[ "${code}" == "200" || "${code}" == "302" ]] \
        || die 1 "--keep-db reuse 中止: serve (${url}) 応答 ${code} (200/302 期待)。serve 未起動の可能性。"
    # worker 生存確認 (serve だけ生きていて worker が死んだ状態で reuse すると F-01 が再発する)。
    # kill -0 でなく cmdline 照合 (stale pidfile / pid 再利用の誤判定防止。Codex 概念 R1 反映)。
    local conn
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        worker_alive "${shard}" "${conn}" \
            || die 1 "--keep-db reuse 中止: worker (${conn}) が起動していない/照合不一致 (pidfile: $(worker_pidfile "${shard}" "${conn}"))。queued 滞留 (F-01) が再発するため再 provision してください。"
    done
    echo "keepdb-check: assets fresh + serve ${code} + workers alive (reuse 可)"
}
```

**seam を本体に持ち込まない（Codex 詳細 R1 Critical 反映）**: 当初案の
`BUGHUNT_SKIP_WORKER_CHECK` 環境変数 seam は、運用経路で誤設定されると worker 検査が
飛んで F-01 再発を見逃すため**採用しない**。本体コードは無条件で worker 検査を行い、
self-test 側は **サブシェル内で `worker_alive` 関数を上書き（stub）** して検証する
（bash の関数は subshell ローカルに再定義でき、本体・親プロセスへ影響しない）:

```bash
    # self-test [v] 内: 既存 fixture の keepdb-check 検証は worker_alive を stub して呼ぶ
    fg_run_keepdb_ok()   { ( cd "${fg_root}" && PATH="${fg_bin}:${PATH}" && worker_alive() { return 0; }; cmd_keepdb_check "$@" ); }
    fg_run_keepdb_dead() { ( cd "${fg_root}" && PATH="${fg_bin}:${PATH}" && worker_alive() { return 1; }; cmd_keepdb_check "$@" ); }
```

- 既存 [v] の keepdb-check ケース（assets fresh/stale × serve 応答）は `fg_run_keepdb_ok`
  に置き換えて従来の判定を回帰検証する。
- 新規に `fg_run_keepdb_dead`（worker 死亡）で「assets fresh + serve 200 でも exit≠0」を検証し、
  worker 検査が assets/serve 検査の**後段に実在する**ことを機械確認する。
- 実物の `worker_alive` の判定ロジック自体は施策 5 の (y4)（stale pid / cmdline 不一致）で
  stub なしに検証する。

### PHPStan適合チェック

- [x] PHP 製品コード変更なし

### テスト計画

- [ ] self-test（施策 5）: `cmd_keepdb_check` の定義に `worker_alive` 配線があること（構造検査）、
      [v] の stub 置き換え（`fg_run_keepdb_ok` / `fg_run_keepdb_dead`）による前段回帰 + 後段検査
- [ ] 実機確認: provision 後に `keepdb-check --shard 0` が pass、worker を手動 kill すると fail

### リスク

- self-test の stub は subshell ローカルであり、本体の worker 検査経路に迂回路（seam）は
  存在しない。リスクは「stub が実物 `worker_alive` と乖離する」ことだが、実物は (y4) で
  独立に検証されるため二重に担保される。

---

## 施策 5: self-test 拡張（[y] worker 配線 + config drift check）

### 変更箇所

- ファイル: `scripts/bug-hunt-shard.sh` `cmd_self_test()`（L910-1344）
  - 既存 [x] セクションの後に [y] セクションを追加
  - [v] の keepdb-check fixture 呼び出しを `worker_alive` stub 付き subshell
    （`fg_run_keepdb_ok` / `fg_run_keepdb_dead`。施策 4 参照）に置き換え

### 波及変更

- なし

### 現行コード

（[x] セクション末尾、L1312-1335。worker 関連の検査は存在しない）

### 変更後コード

```bash
    echo "[y] queue worker 配線 (F-01 対策): 導出 / 構造 / drift / dryrun 不起動"
    # (y1) pidfile/logfile 導出
    [[ "$(worker_pidfile 3 database-analysis)" == "${TMP_BASE}/worker-3-database-analysis.pid" ]] \
        || t_fail "worker_pidfile 導出"
    [[ "$(worker_logfile 0 database-render)" == "${TMP_BASE}/worker-0-database-render.log" ]] \
        || t_fail "worker_logfile 導出"

    # (y2) config/queue.php との drift check (PHP 実評価。grep でなく実 config を読む)
    local expected_conns actual_conns
    expected_conns="$(cd "${WORKSPACE}" && php -r '
        require "vendor/autoload.php";
        $cfg = require "config/queue.php";
        $names = [];
        foreach ($cfg["connections"] as $name => $conn) {
            if (($conn["driver"] ?? "") === "database" && $name !== "database") { $names[] = $name; }
        }
        sort($names);
        echo implode(" ", $names);
    ' 2>/dev/null || echo "__php_failed__")"
    actual_conns="$(printf '%s\n' "${BUGHUNT_WORKER_CONNECTIONS[@]}" | sort | tr '\n' ' ' | sed 's/ $//')"
    if [[ "${expected_conns}" == "__php_failed__" ]]; then
        t_fail "drift check 実行不能: vendor/autoload.php または config/queue.php を PHP 評価できない (依存未導入なら composer install 後に再実行)"
    elif [[ "${expected_conns}" != "${actual_conns}" ]]; then
        t_fail "drift: config/queue.php の専用 connection (${expected_conns}) と BUGHUNT_WORKER_CONNECTIONS (${actual_conns}) が不一致"
    fi

    # (y3) 構造検査 (既存 [w] と同じ流儀): provision → start_shard_workers → setsid/queue:listen、
    #      teardown → stop_shard_workers が serve kill より前、旧 `|| continue` の復活防止
    local prov_def wrk_def stopw_def td2_def
    prov_def="$(declare -f cmd_provision)"
    echo "${prov_def}" | grep -q 'start_shard_workers' || t_fail "cmd_provision に worker 起動配線が無い"
    wrk_def="$(declare -f start_shard_workers)"
    echo "${wrk_def}" | grep -q 'setsid php artisan queue:listen' || t_fail "start_shard_workers が setsid + queue:listen でない"
    echo "${wrk_def}" | grep -q 'guard_bughunt_runtime' || t_fail "start_shard_workers が guard を通していない"
    echo "${wrk_def}" | grep -q 'env -i' || t_fail "start_shard_workers が env -i 隔離でない"
    echo "${wrk_def}" | grep -q 'stop_shard_workers' || t_fail "start_shard_workers に起動失敗ロールバックが無い"
    echo "${wrk_def}" | grep -q 'ps -o pgid=' || t_fail "start_shard_workers に pid==pgid (setsid 成立) 検証が無い"
    echo "${prov_def}" | grep -qF 'conn//-/_' || t_fail "cmd_provision の manifest worker key が underscore 正規化されていない"
    stopw_def="$(declare -f stop_shard_workers)"
    echo "${stopw_def}" | grep -qF 'kill -TERM -- "-' || t_fail "stop_shard_workers が process group kill でない"
    echo "${stopw_def}" | grep -q 'worker_alive' || t_fail "stop_shard_workers に cmdline 照合 (worker_alive) が無い"
    echo "${stopw_def}" | grep -qF 'kill -0 -- "-' || t_fail "stop_shard_workers に process group 消滅待ちが無い (master 単体判定は dropdb と race)"
    echo "${stopw_def}" | grep -qF 'kill -KILL -- "-' || t_fail "stop_shard_workers に KILL escalation が無い"
    echo "${stopw_def}" | grep -q 'ps -o pgid=' || t_fail "stop_shard_workers に pid==pgid 検証が無い (setsid 不成立の group を消滅済みと誤認する)"
    echo "${stopw_def}" | grep -qF 'kill -TERM "${wpid}"' \
        && t_fail "stop_shard_workers に PID 単体 TERM fallback がある (pid 再利用 race。group kill のみにする)"
    local esc_ln lastrm_ln
    esc_ln="$(echo "${stopw_def}" | grep -nF 'kill -KILL -- "-' | head -1 | cut -d: -f1)"
    lastrm_ln="$(echo "${stopw_def}" | grep -n 'rm -f "\${wpidfile}"' | tail -1 | cut -d: -f1)"
    [[ -n "${esc_ln}" && -n "${lastrm_ln}" && "${esc_ln}" -lt "${lastrm_ln}" ]] \
        || t_fail "stop_shard_workers: 停止確認前に pidfile を削除している (残留 group の追跡情報を失う)"
    td2_def="$(declare -f cmd_teardown)"
    echo "${td2_def}" | grep -q 'stop_shard_workers' || t_fail "cmd_teardown に worker 停止配線が無い"
    echo "${td2_def}" | grep -q 'workers_stopped' || t_fail "cmd_teardown に worker 停止失敗時の dropdb 抑止が無い"
    local wkill2_ln skill_ln
    wkill2_ln="$(echo "${td2_def}" | grep -n 'stop_shard_workers' | head -1 | cut -d: -f1)"
    skill_ln="$(echo "${td2_def}" | grep -n 'serve-\${shard}.pid' | head -1 | cut -d: -f1)"
    [[ -n "${wkill2_ln}" && -n "${skill_ln}" && "${wkill2_ln}" -lt "${skill_ln}" ]] \
        || t_fail "cmd_teardown: worker 停止が serve 停止より後 (DB 接続残留リスク)"
    echo "${td2_def}" | grep -qF '${pidfile}" ]] || continue' \
        && t_fail "cmd_teardown: serve pidfile の '|| continue' が復活している (worker/wrapper 掃除がスキップされる回帰)"
    echo "$(declare -f cmd_keepdb_check)" | grep -q 'worker_alive' \
        || t_fail "cmd_keepdb_check に worker 生存確認が無い"

    # (y4) worker_alive: stale pidfile (存在しない pid) と cmdline 不一致 (自プロセス pid) を fail 判定
    mkdir -p "${TMP_BASE}"
    echo 999999999 > "$(worker_pidfile 7 database-analysis)"
    worker_alive 7 database-analysis && t_fail "worker_alive が存在しない pid を alive 判定"
    echo $$ > "$(worker_pidfile 7 database-analysis)"
    worker_alive 7 database-analysis && t_fail "worker_alive が cmdline 不一致 (bash 自身) を alive 判定"
    rm -f "$(worker_pidfile 7 database-analysis)"

    # (y5) dryrun provision は worker を起動しない (pidfile 不生成)
    export BUGHUNT_SELFTEST_DRYRUN=1
    ("${SCRIPT_PATH}" provision --shard 0 --run-id 20990301-000000) >/dev/null 2>&1 || t_fail "[y5] dryrun provision 失敗"
    unset BUGHUNT_SELFTEST_DRYRUN
    [[ ! -f "$(worker_pidfile 0 database-analysis)" ]] || t_fail "dryrun provision が worker pidfile を生成"

    # (y6) stop_shard_workers の機能検査 (実 worker/DB を使わない軽量プロセスで代替。
    #      worker_alive / kill / sleep は subshell 内 stub = 本体無変更):
    # (y6a) 正常系: setsid sleep を worker に見立て、group kill → 消滅 → pidfile 削除
    setsid sleep 30 > /dev/null 2>&1 &
    local fake_wpid=$!
    echo "${fake_wpid}" > "$(worker_pidfile 8 database-analysis)"
    ( worker_alive() { [[ "$1" == 8 && "$2" == database-analysis ]]; }
      stop_shard_workers 8 ) || t_fail "[y6a] stop_shard_workers (stub) が非ゼロ"
    wait "${fake_wpid}" 2>/dev/null || true    # 回収してから group 不在を確認 (flaky 防止)
    kill -0 -- "-${fake_wpid}" 2>/dev/null && t_fail "[y6a] stop_shard_workers が group を停止していない"
    [[ ! -f "$(worker_pidfile 8 database-analysis)" ]] || t_fail "[y6a] 停止成功後に pidfile が残留"

    # (y6b) 失敗系 (最重要不変条件): TERM/KILL を no-op 化して「group が残留」を再現し、
    #       rc=1 + pidfile 保持を機能検証する (kill -0 は builtin へ委譲 = 実在確認は本物)
    setsid sleep 30 > /dev/null 2>&1 &
    local fake_wpid2=$!
    echo "${fake_wpid2}" > "$(worker_pidfile 8 database-analysis)"
    ( worker_alive() { [[ "$1" == 8 && "$2" == database-analysis ]]; }
      kill() { [[ "${1:-}" == "-TERM" || "${1:-}" == "-KILL" ]] && return 0; builtin kill "$@"; }
      sleep() { :; }    # 待機ループ短縮
      stop_shard_workers 8 ) && t_fail "[y6b] 停止不能 group なのに rc=0"
    [[ -f "$(worker_pidfile 8 database-analysis)" ]] || t_fail "[y6b] 停止失敗時に pidfile が削除された (追跡情報喪失)"
    builtin kill -TERM -- "-${fake_wpid2}" 2>/dev/null || true    # 後片付け
    wait "${fake_wpid2}" 2>/dev/null || true
    rm -f "$(worker_pidfile 8 database-analysis)"

    # (y6c) stale pidfile (死亡済み pid) は kill なしで削除のみ・rc=0
    echo 999999999 > "$(worker_pidfile 8 database-render)"
    stop_shard_workers 8 || t_fail "[y6c] stale pidfile で stop_shard_workers が非ゼロ"
    [[ ! -f "$(worker_pidfile 8 database-render)" ]] || t_fail "[y6c] stale pidfile が削除されない"

    # (y6d) 「pid は存在するが所有確認できない」は pidfile 保持 + rc=1 (誤 stale 判定の防止)。
    #       自プロセス (bash) の pid = 実在するが cmdline 照合に一致しない代表例
    echo $$ > "$(worker_pidfile 8 database-media)"
    stop_shard_workers 8 && t_fail "[y6d] 所有確認できない実在 pid なのに rc=0"
    [[ -f "$(worker_pidfile 8 database-media)" ]] || t_fail "[y6d] 所有確認できない実在 pid の pidfile が削除された"
    rm -f "$(worker_pidfile 8 database-media)"
    t_ok "queue worker wiring (derivation/drift/structure/alive/dryrun/stop 正常系+失敗系)"
```

補足:

- (y2) の `php -r` は **read-only の config 実評価**であり、self-test の「実資源に触れない」
  原則（DB/serve/常駐プロセスを作らない）に反しない。`vendor/autoload.php` は
  `env()` ヘルパ（illuminate/support の helpers.php）を提供するため、Laravel 起動なしで
  `config/queue.php`（素の PHP 配列 return）を評価できる。
- (y2) は `WORKSPACE` 直下で実行する（self-test sandbox は paths 差し替えのみで cwd は
  workspace のままだが、明示 `cd` で頑健化）。
- (y5) は既存 [x] と同様に sandbox 内 dryrun。dryrun は L609-616 で早期 return するため
  worker 起動コードに到達しないことを機械検証する。
- (y6) の `setsid sleep 30` は数秒で回収される transient プロセスであり、self-test の
  「実資源（DB/serve/常駐プロセス）に触れない」原則に反しない（既存 [j] が wrapper を
  実行するのと同水準の軽量実行検査）。`worker_alive` の stub は subshell ローカル。
  停止待ちの実挙動（TERM → group 消滅 → pidfile 削除、stale は削除のみ）を
  構造検査でなく機能として固定する（Codex 詳細 R3 の「残留時に pidfile を消す回帰を
  防げない」指摘への機能面の回答。構造検査 (y3) と二重化）。

### PHPStan適合チェック

- [x] PHP 製品コード変更なし（`php -r` は検査専用）

### テスト計画

- [ ] `scripts/bug-hunt-shard.sh self-test` 全 pass（既存 [a]-[x] の回帰 + 新規 [y]）
- [ ] `bash -n scripts/bug-hunt-shard.sh`（構文チェック）

### リスク

- (y2) は composer install 済み前提（vendor/ 不在だと `__php_failed__` で fail）。
  bug-hunt 実行環境（worktree provision 後）では常に成立する前提であり、
  fail した場合も「検査が実行できない」ことが顕在化する fail-closed 挙動で安全側。

---

## 施策 6: コメント/ドキュメント整合

### 変更箇所

- `scripts/bug-hunt-shard.sh` L26-28（ヘッダコメントの provision 説明）
- `.env.bughunt.local` L50 / `.env.bughunt.local.example` L52（`QUEUE_CONNECTION=sync` のコメント）

### 波及変更

- なし

### 現行コード

```bash
#   provision --shard I --run-id TS [--coverage]
#                                      # createdb(admin) → migrate:fresh+seed → serve → 実効env検証
```

```dotenv
QUEUE_CONNECTION=sync               # 非同期ジョブを同期実行 (探索の決定論性)
```

### 変更後コード

```bash
#   provision --shard I --run-id TS [--coverage]
#                                      # createdb(admin) → migrate:fresh+seed → serve + queue worker
#                                      # (database-analysis/render/media の queue:listen) → 実効env検証
```

```dotenv
# default connection のジョブのみ同期実行。onConnection() で専用 connection
# (database-analysis / database-render / database-media) を指定するジョブは
# provision が起動する queue:listen worker が処理する (bug-hunt-shard.sh 参照)
QUEUE_CONNECTION=sync
```

（`.env.bughunt.local` はテンプレートコメント含め example と同期。self-test の sandbox 用
ENV_FILE fixture には QUEUE_CONNECTION 記載がないため影響なし）

### PHPStan適合チェック

- [x] 該当なし（コメントのみ）

### テスト計画

- [ ] provision の実効 env 検証（`queue: "sync"` 期待）が引き続き pass すること
      （コメント変更のみで値は不変）

### リスク

- なし（コメントのみ。値・キーは不変）

---

## 変更しないことの明示（回帰防止の契約）

| 項目 | 扱い |
|------|------|
| `app/Jobs/**`（onConnection / $tries / $timeout） | 変更しない |
| `config/queue.php`（retry_after 連鎖、AnalysisTimeBudgetInvariantTest / RenderTimeBudgetInvariantTest が固定） | 変更しない |
| `.env.bughunt.local` の `QUEUE_CONNECTION=sync`（値） | 変更しない（コメントのみ更新） |
| provision の実効 env 検証（`queue: "sync"` 期待） | 変更しない |
| dev/prod の worker 運用契約（docs/architecture.md） | 変更しない |
| dev DB 防御 3-way guard / orchestrator gate / wrapper 封じ込め | 変更しない（worker 起動は guard_bughunt_runtime 準拠で追加） |

## テスト計画（全体）

1. `scripts/bug-hunt-shard.sh self-test` — 全 pass（新規 [y] 含む）。
2. `bash -n scripts/bug-hunt-shard.sh` — 構文チェック。
3. 実機確認（worktree 内、実装フェーズで実施）:
   - `BUGHUNT_ORCHESTRATOR=1 scripts/bug-hunt-shard.sh provision --shard 0 --run-id <ts>`
   - `pgrep -f "queue:listen database-"` が 3 プロセス
   - ブラウザまたは curl（セッション認証）で analyze をトリガー →
     `jobs.show` ポーリングで status が `queued` に滞留せず終端状態（completed/failed）へ
     遷移すること（F-01 の再現解消確認。LLM fake 未配線なら failed + UI エラーで可）
   - wrapper 経由 `reseed` 実行後も worker が生存し、以降のジョブを処理すること
     （queue:listen 採用理由の実証）
   - `keepdb-check --shard 0` pass → worker を手動 kill → fail
   - `teardown --run-id <ts>` 後に `pgrep -f "queue:listen"` が 0 件、pidfile 残留なし、
     かつ provision 時に控えた各 worker の pgid について `kill -0 -- -<pgid>` が失敗する
     （process group 全体の消滅 = 受け入れ条件の直接確認）
4. 回帰: `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
   `pnpm typecheck` / `pnpm test` / `pnpm build` — PHP/TS 変更なしのため green 維持を確認
   （bash のみの変更だが、コミット規約に従い全 gate を通す）。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 全施策が `scripts/bug-hunt-shard.sh` 中心の単一機能追加（worker のライフサイクル管理）で相互依存が強く、分割実装の意味がない。1 worktree / 1 タスクで一括実装・検証するのが最短 |
| 競合リスク | `scripts/bug-hunt-shard.sh` を触る他タスク（bug-hunt 系 TODO: F-04 fixture 修正はシーダーのみで衝突しない。F-06 の webhook simulate 案が将来 wrapper サブコマンドを増やす場合は本変更の後着でリベース） |
