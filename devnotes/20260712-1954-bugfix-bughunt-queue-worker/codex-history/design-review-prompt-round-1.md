# 使命・禁止事項・思考原則（全 Codex 呼び出しに自動適用）

## アプリの使命 (North Star) — AGENTS.md より

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

## 禁止事項 — AGENTS.md より

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: 詳細設計レビュアーとしての指示

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC
- 本件は製品コードでなく **bug-hunt harness (bash スクリプト `scripts/bug-hunt-shard.sh`) の環境ギャップ修正**。
  概念設計は Codex レビュー Round 1 で APPROVED 済み (Warning 4 件は反映済み)。

【本件固有の背景】
bug-hunt finding F-01 (Critical): `RunManualAnalysis`/`RunManualRender` が `onConnection('database-analysis'/'database-render')` をハードコードし (driver=database 固定)、bughunt の `QUEUE_CONNECTION=sync` をバイパスして jobs テーブルに滞留する。provision が queue worker を起動しないため analyze/preview/render が `queued` のまま永久停止。本詳細設計は provision で `queue:listen` worker を shard ごとに起動し、teardown で process group kill する。

【レビュー観点】
1. コードの正確性（bash のロジックエラー、エッジケース、プロセス管理の race、シグナル/プロセスグループ挙動、Laravel queue:listen の実挙動との整合）
2. 既存コードとの整合性（bug-hunt-shard.sh の既存流儀: env -i 隔離、guard、pidfile + cmdline 照合 kill、self-test の書き方）
3. dev DB 防御の非交渉要件（env -i + DB名 regex + role guard を worker 経路でも破っていないか）
4. テスト計画の網羅性（self-test で機械検証できる範囲の妥当性、実機確認手順の十分性）
5. 副作用・後退リスク（既存 provision/teardown/keepdb-check/self-test の回帰、teardown の構造変更 (serve pidfile continue の除去) の影響）
6. 波及変更の網羅性
7. セキュリティ（プロセス誤 kill、パス操作、env 漏洩）
8. スコープ（製品コード不変の契約が守られているか）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

参考として以下のファイルを読み込み可: scripts/bug-hunt-shard.sh (現行実装全体), config/queue.php, app/Jobs/Manual/RunManualAnalysis.php, app/Jobs/Manual/RunManualRender.php, app/Jobs/Capture/DeleteTakeObjectsJob.php, app/Jobs/Manual/DeleteRenderOutputsJob.php, .env.bughunt.local.example, devnotes/20260712-1954-bugfix-bughunt-queue-worker/conceptual-design.md, devnotes/20260712-191243-bug-hunt/shard-0/shard-report.md

---

# user: 詳細設計書

## 詳細設計書

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
| 1 | worker 共通ヘルパ（connection リスト・pidfile 導出・起動関数） | `scripts/bug-hunt-shard.sh` | High |
| 2 | provision への worker 起動配線 + manifest 記録 | `scripts/bug-hunt-shard.sh` | High |
| 3 | teardown への worker 停止配線（process group kill） | `scripts/bug-hunt-shard.sh` | High |
| 4 | keepdb-check への worker 生存確認追加 | `scripts/bug-hunt-shard.sh` | Medium |
| 5 | self-test 拡張（[y] worker 配線 + config drift check） | `scripts/bug-hunt-shard.sh` | High |
| 6 | コメント/ドキュメント整合（env コメント・ヘッダコメント） | `scripts/bug-hunt-shard.sh`, `.env.bughunt.local`, `.env.bughunt.local.example` | Low |

---

## 施策 1: worker 共通ヘルパ（connection リスト・pidfile 導出・起動関数）

### 変更箇所

- ファイル: `scripts/bug-hunt-shard.sh`
  - L94-105 付近（資源導出セクション）: `worker_pidfile` / `worker_logfile` を追加
  - L575（`cmd_keepdb_check`）と L577（worktree 文脈ガード）の間、または provision セクション直前:
    `BUGHUNT_WORKER_CONNECTIONS` 定義と `start_shard_workers` / `assert_worker_alive` を追加

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
#   一致させること (self-test [y] が PHP 実評価で drift を機械検出する)。
BUGHUNT_WORKER_CONNECTIONS=(database-analysis database-render database-media)

# worker pid が「当該 connection の queue:listen」として生きているかの検証 (kill -0 では
# stale pidfile / pid 再利用を誤判定するため /proc cmdline を照合する。Linux 前提 = teardown と同じ)。
worker_alive() {
    local shard=$1 conn=$2 pid
    pid="$(cat "$(worker_pidfile "${shard}" "${conn}")" 2>/dev/null || echo)"
    [[ -n "${pid}" && "${pid}" != 0 && -r "/proc/${pid}/cmdline" ]] || return 1
    tr '\0' ' ' < "/proc/${pid}/cmdline" | grep -q -- "queue:listen ${conn} "
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
    # fail-fast: 起動 1 秒後の即死検知 (artisan 起動失敗・接続不能などを provision 段階で顕在化)
    sleep 1
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        worker_alive "${shard}" "${conn}" \
            || die 1 "shard-${shard} worker (${conn}) が起動しない ($(worker_logfile "${shard}" "${conn}") 参照)"
    done
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
  `tr '\0' ' '` 後に `"queue:listen ${conn} "`（末尾スペース付き）で照合し、
  接頭辞衝突（例: 将来 `database-media2` のような名前）を防ぐ。

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

    # (g) manifest 記録 (worker pid = pgid。setsid により group 一括 kill の対象 id を兼ねる)
    local -a worker_pid_entries=()
    local conn
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        worker_pid_entries+=("worker_pid_${conn}=$(cat "$(worker_pidfile "${shard}" "${conn}")")")
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
- manifest のキーは `worker_pid_database-analysis` 等（JSON キーとしてハイフン可）。
- worker 起動失敗時は `die` により provision 全体が fail する（serve と同じ fail-fast 方針）。
  provision 途中失敗時の残骸（serve・起動済み worker）は、従来どおり親 orchestrator の
  teardown で回収する運用（teardown は pidfile ベースで冪等）。

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
    local shard pid port
    for shard in 0 1 2 3 4 5 6 7 8; do
        # 専用 connection worker の停止 (serve より先に止め、--drop-db 時の DB 接続残留を防ぐ)。
        # setsid 起動により pid==pgid のため process group 一括 kill (master + queue:work --once 子)。
        # cmdline 照合で pid 再利用による無関係プロセスの誤 kill を防ぐ (serve kill と同じ流儀)。
        local conn wpidfile wpid
        for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
            wpidfile="$(worker_pidfile "${shard}" "${conn}")"
            [[ -f "${wpidfile}" ]] || continue
            if worker_alive "${shard}" "${conn}"; then
                wpid="$(cat "${wpidfile}")"
                kill -TERM -- "-${wpid}" 2>/dev/null || kill -TERM "${wpid}" 2>/dev/null || true
            fi
            rm -f "${wpidfile}"
        done
        # worker の DB 接続クローズを短時間待つ (--drop-db の "database is being accessed" 対策)
        if [[ "${drop_db}" == "--drop-db" ]] && ! is_dryrun; then
            local t
            for t in 1 2 3 4 5; do
                pgrep -f "queue:listen .* --env=bughunt.local" >/dev/null 2>&1 || break
                sleep 0.4
            done
        fi

        local pidfile="${TMP_BASE}/serve-${shard}.pid"
        if [[ -f "${pidfile}" ]]; then
            pid="$(cat "${pidfile}" 2>/dev/null || echo)"
            ...（serve の cmdline 検証付き kill、既存のまま）...
            rm -f "${pidfile}"
        fi
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

補足（構造変更の理由）:

- 現行の `[[ -f "${pidfile}" ]] || continue` は「serve pidfile が無い shard は何も掃除しない」
  ロジックのため、そのままでは worker pidfile が孤立残留しうる。shard ループ先頭の `continue` を
  外し、worker 停止 → serve 停止 → dropdb → wrapper/カバレッジ掃除を各々 pidfile 有無で
  独立に判定する形へ再構成する（`--drop-db` の dropdb と wrapper 掃除は従来から pidfile 有無に
  依存すべきでない掃除であり、この再構成は既存挙動の是正も兼ねる。ただし dropdb は
  `--if-exists` 付きのため未 provision shard でも安全）。
- worker kill は「cmdline 照合 → group kill」。照合に失敗した場合（stale pidfile / pid 再利用）は
  kill せず pidfile のみ削除する（誤 kill 防止を優先。取り残しは以下の残留 sweep はせず、
  reap_orphan_browser と同様に次回 provision の fail-fast で顕在化させる）。
- `kill -TERM -- "-${wpid}"` の fallback `kill -TERM "${wpid}"` は、万一 pgid が取れない
  （setsid 不発）環境での master 単体 kill を保険として残す。

### PHPStan適合チェック

- [x] PHP 製品コード変更なし

### テスト計画

- [ ] self-test（施策 5）: `cmd_teardown` の関数定義に worker の group kill 配線
      （`worker_pidfile` 参照 + `kill -TERM -- "-` パターン）が serve kill より前に存在すること、
      dryrun teardown で worker pidfile が削除されること
- [ ] 実機確認（実装フェーズ）: provision → teardown 後に
      `pgrep -f "queue:listen"` が 0 件であること

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
            || die 1 "--keep-db reuse 中止: worker (${conn}) が起動していない/照合不一致 (queued 滞留 = F-01 が再発する)。再 provision してください。"
    done
    echo "keepdb-check: assets fresh + serve ${code} + workers alive (reuse 可)"
}
```

### PHPStan適合チェック

- [x] PHP 製品コード変更なし

### テスト計画

- [ ] self-test（施策 5）: `cmd_keepdb_check` の定義に `worker_alive` 配線があること。
      既存 [v] セクションの keepdb-check fixture（sandbox + fake curl）では worker pidfile が
      無いため fail する — fixture 側で worker pidfile + `/proc` 照合をどう満たすかが課題になる。
      `/proc/{pid}/cmdline` は偽造できないため、self-test では `worker_alive` を関数単位で
      検証する（現プロセスの pid を pidfile に書き cmdline 不一致で fail することを確認）方式とし、
      [v] の keepdb-check 通過ケースは **自プロセス（bash）の pid + 照合パターンを一時的に
      満たせないことから、`BUGHUNT_SELFTEST_DRYRUN` ではなく専用の test seam
      `BUGHUNT_SKIP_WORKER_CHECK=1`（self-test 専用・実行経路では未設定）で worker 検査を
      スキップして従来の assets/serve 判定を検証する**。seam は
      `[[ -n "${BUGHUNT_SKIP_WORKER_CHECK:-}" ]] && return 0` 相当を worker 検査ループの
      直前に置き、self-test [v] のみが設定する。
- [ ] 実機確認: provision 後に `keepdb-check --shard 0` が pass、worker を手動 kill すると fail

### リスク

- test seam（`BUGHUNT_SKIP_WORKER_CHECK`）が本番運用で誤設定されると worker 検査が飛ぶ。
  ただし keepdb-check は「reuse してよいかの助言的 preflight」であり dev DB 防御とは独立
  （guard 系 seam ではない）。スクリプト内コメントで self-test 専用であることを明記する。

---

## 施策 5: self-test 拡張（[y] worker 配線 + config drift check）

### 変更箇所

- ファイル: `scripts/bug-hunt-shard.sh` `cmd_self_test()`（L910-1344）
  - 既存 [x] セクションの後に [y] セクションを追加
  - [v] の keepdb-check fixture 呼び出しに `BUGHUNT_SKIP_WORKER_CHECK=1` を付与（施策 4 参照）

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
        t_fail "drift check: config/queue.php の PHP 実評価に失敗"
    elif [[ "${expected_conns}" != "${actual_conns}" ]]; then
        t_fail "drift: config/queue.php の専用 connection (${expected_conns}) と BUGHUNT_WORKER_CONNECTIONS (${actual_conns}) が不一致"
    fi

    # (y3) 構造検査 (既存 [w] と同じ流儀): provision → start_shard_workers → setsid/queue:listen、
    #      teardown → worker group kill が serve kill より前
    local prov_def wrk_def td2_def
    prov_def="$(declare -f cmd_provision)"
    echo "${prov_def}" | grep -q 'start_shard_workers' || t_fail "cmd_provision に worker 起動配線が無い"
    wrk_def="$(declare -f start_shard_workers)"
    echo "${wrk_def}" | grep -q 'setsid php artisan queue:listen' || t_fail "start_shard_workers が setsid + queue:listen でない"
    echo "${wrk_def}" | grep -q 'guard_bughunt_runtime' || t_fail "start_shard_workers が guard を通していない"
    echo "${wrk_def}" | grep -q 'env -i' || t_fail "start_shard_workers が env -i 隔離でない"
    td2_def="$(declare -f cmd_teardown)"
    echo "${td2_def}" | grep -q 'worker_pidfile' || t_fail "cmd_teardown に worker 停止配線が無い"
    echo "${td2_def}" | grep -qF 'kill -TERM -- "-' || t_fail "cmd_teardown が process group kill でない"
    local wkill2_ln skill_ln
    wkill2_ln="$(echo "${td2_def}" | grep -n 'worker_pidfile' | head -1 | cut -d: -f1)"
    skill_ln="$(echo "${td2_def}" | grep -n 'serve-\${shard}.pid' | head -1 | cut -d: -f1)"
    [[ -n "${wkill2_ln}" && -n "${skill_ln}" && "${wkill2_ln}" -lt "${skill_ln}" ]] \
        || t_fail "cmd_teardown: worker 停止が serve 停止より後 (DB 接続残留リスク)"
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
    t_ok "queue worker wiring (derivation/drift/structure/alive/dryrun)"
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
   - `teardown --run-id <ts>` 後に `pgrep -f "queue:listen"` が 0 件、pidfile 残留なし
4. 回帰: `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
   `pnpm typecheck` / `pnpm test` / `pnpm build` — PHP/TS 変更なしのため green 維持を確認
   （bash のみの変更だが、コミット規約に従い全 gate を通す）。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 全施策が `scripts/bug-hunt-shard.sh` 中心の単一機能追加（worker のライフサイクル管理）で相互依存が強く、分割実装の意味がない。1 worktree / 1 タスクで一括実装・検証するのが最短 |
| 競合リスク | `scripts/bug-hunt-shard.sh` を触る他タスク（bug-hunt 系 TODO: F-04 fixture 修正はシーダーのみで衝突しない。F-06 の webhook simulate 案が将来 wrapper サブコマンドを増やす場合は本変更の後着でリベース） |

## 関連する現行コード（抜粋。全文は scripts/bug-hunt-shard.sh を Read すること）

### cmd_provision の serve 起動〜manifest 記録 (L712-743)
```bash
    env -i PATH="${PATH}" HOME="${HOME}" \
        DB_CONNECTION=pgsql \
        DB_HOST="$(env_file_required DB_HOST)" DB_PORT="$(env_file_required DB_PORT)" \
        DB_DATABASE="${db}" DB_USERNAME=bughunt DB_PASSWORD="$(env_file_get DB_PASSWORD)" \
        APP_URL="${url}" \
        ${coverage_env[@]+"${coverage_env[@]}"} \
        nohup php artisan serve --env=bughunt.local --port="${port}" --no-reload \
        > "${TMP_BASE}/serve-${shard}.log" 2>&1 &
    local serve_pid=$!
    echo "${serve_pid}" > "${TMP_BASE}/serve-${shard}.pid"
    manifest_update "${run_id}" "${shard}" "serve_pid=${serve_pid}" "port=${port}"
    local t code=000
    for t in $(seq 1 30); do
        code="$(curl -s -o /dev/null -w '%{http_code}' "${url}/login" || true)"
        [[ "${code}" == 200 || "${code}" == 302 ]] && break
        sleep 1
    done
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

### cmd_teardown 全体 (L835-867)
```bash
cmd_teardown() {
    local run_id=$1 drop_db=${2:-}
    require_orchestrator "teardown"
    local shard pid port
    for shard in 0 1 2 3 4 5 6 7 8; do
        local pidfile="${TMP_BASE}/serve-${shard}.pid"
        [[ -f "${pidfile}" ]] || continue
        pid="$(cat "${pidfile}" 2>/dev/null || echo)"
        port="$(shard_port "${shard}")"
        if [[ -n "${pid}" && "${pid}" != 0 && -r "/proc/${pid}/cmdline" ]] \
            && tr '\0' ' ' < "/proc/${pid}/cmdline" | grep -q "artisan serve" \
            && tr '\0' ' ' < "/proc/${pid}/cmdline" | grep -q -- "--port=${port}"; then
            local wpid
            for wpid in $(pgrep -P "${pid}" 2>/dev/null || true); do
                if [[ -r "/proc/${wpid}/cmdline" ]] \
                    && tr '\0' ' ' < "/proc/${wpid}/cmdline" | grep -q -- "-S " \
                    && tr '\0' ' ' < "/proc/${wpid}/cmdline" | grep -q -- ":${port}"; then
                    kill -TERM "${wpid}" 2>/dev/null || true
                fi
            done
            kill -TERM "${pid}" 2>/dev/null || true
        fi
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

### cmd_keepdb_check (L566-575) と self-test [v] の keepdb-check fixture (L1289-1308) は scripts/bug-hunt-shard.sh を Read して確認すること。

### config/queue.php の専用 connection (抜粋)
```php
        'database-analysis' => ['driver' => 'database', 'queue' => 'analysis', 'retry_after' => 1560, ...],
        'database-render'   => ['driver' => 'database', 'queue' => 'render',   'retry_after' => 1680, ...],
        'database-media'    => ['driver' => 'database', 'queue' => 'media',    'retry_after' => 300,  ...],
```
