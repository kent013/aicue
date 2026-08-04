【アプリの使命 (North Star)】
<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC
- 本件は **shell (bash) によるテスト実行基盤** の設計であり、アプリコードの変更はほぼ無い
  (新規 Architecture テスト 1 本のみが PHP)

【レビュー観点】
1. コードの正確性(ロジックエラー、エッジケース、null 安全性、**bash の並行・シグナル・fd 意味論**)
2. 既存コードとの整合性(命名規約、パターン、API)
3. PHPStan level 10 適合性(新規 Architecture テスト)
4. テスト計画の網羅性(層 1 shell スイート / 層 2 Architecture テスト)
5. 副作用・後退リスク(排他が成立しない状態を作っていないか、偽グリーンを作っていないか)
6. 波及変更の網羅性(ドキュメント・CI・台帳)
7. セキュリティ(共有 /tmp のロック dir、シンボリックリンク攻撃、他ユーザーの偽 sidecar)
8. **ロック契約の穴**: deadlock 経路、lock leak 経路、二重解放、trap 競合、race condition

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: global-test-lock (テストレーンのグローバルロック)

## 使命・制約(絶対遵守)

### アプリの使命(North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**(撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須(`composer phpstan`)
- **Pest** テストフレームワーク(`composer test`)
- **RefreshDatabase** + `--parallel` 並列実行(`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止)
- テストデータは必ず Factory で生成
- **DTO + JsonResource** パターン
- アーリーリターン推奨 / `declare(strict_types=1)` + 日本語コメント
- **コードフォーマット**: `composer fix`(Pint)/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- shell: `set -euo pipefail` / 厳格 quoting / 外部コマンド依存を増やさない
  (aicue の shell は macOS 素の環境でも壊れない作法を既存スクリプトが踏襲している)

## 概念設計リファレンス

- [`devnotes/20260804-2319-global-test-lock/conceptual-design.md`](./conceptual-design.md)(Round 6 APPROVED)
- c2c 台帳 `global-test-lock`(origin: spirux:T1109/T1110、テンプレ昇格承認済み)

## 実装前に実測した機構の挙動(プロトタイプ検証済み)

本設計のコードは以下を **devcontainer 上で実測**してから書いている(思い込みで書かない)。

| # | 検証項目 | 結果 |
|---|---|---|
| 1 | `set -m` + background 起動で PGID == PID になるか | **なる**(専用プロセスグループが作れる) |
| 2 | `cmd 7>&- &` で fd 7 が子に継承されないか | **継承されない**(`/proc/<pid>/fd/7` 不在) |
| 3 | heartbeat 用 background job も `7>&-` で fd を切れるか | **切れる** |
| 4 | `wait` の終了コードが親へ伝播するか | **する**(rc=3 を確認) |
| 5 | TERM を無視する子 + 孫に対し `kill -KILL -"$pgid"` が効くか | **効く** |
| 6 | **`kill -0 -"$pgid"` は zombie を「生存」と誤判定する** | **する**(SIGKILL 後も Z が残る間 true) |
| 7 | 高速終了する子に対する `ps -o pgid= -p "$pid"` は空を返す(race) | **返す** |

→ 6 と 7 は設計に直接効く。**グループ空判定は zombie を除外した `ps` 走査で行う**(zombie は
fd も DB 接続もポートも保持しないため「消滅」とみなすのが正しい)。
**PGID 検証は取得時に 1 回だけ probe で強制**し、各レーン実行時は best-effort(`ps` が空 = 既に終了 = 正常)とする。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | グローバルロックライブラリの新設 | `scripts/global-test-lock.sh`(新規) | 高 |
| 2 | コマンドラッパの新設 | `scripts/with-global-test-lock.sh`(新規) | 高 |
| 3 | Feature lane を置換 | `scripts/run-test.sh` | 高 |
| 4 | Browser lane を置換 + playwright 掃除の限定 + bughunt pre-flight guard | `scripts/run-browser-test.sh` | 高 |
| 5 | JS lane を置換(`exec` 廃止) | `scripts/run-vitest.sh` | 高 |
| 6 | packages lane をラップ | `package.json` | 高 |
| 7 | 並行挙動検証スイート(層 1) | `scripts/verify-global-test-lock.sh`(新規) | 高 |
| 8 | 構造的不変条件 Architecture テスト(層 2) | `tests/Architecture/GlobalTestLockInventoryTest.php`(新規) | 高 |
| 9 | CI ゲート追加 | `.github/workflows/ci.yml` | 中 |
| 10 | ドキュメント更新 | `scripts/README.md` / `docs/testing-browser.md` / `docs/worktree-isolation-strategy.md` / `docs/template-divergence.md` | 中 |

---

## 施策 1: グローバルロックライブラリの新設

### 変更箇所
- ファイル: `scripts/global-test-lock.sh`(新規)

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策 7(層 1)・施策 8(層 2)で検証する

### 公開 API(lane スクリプトから見える面)

| 関数 | 役割 |
|---|---|
| `global_test_lock_acquire <lane-label>` | ロック取得(ブロッキング)。再入判定・flock 不在判定を含む |
| `global_test_lock_run <cmd> [args...]` | ロック配下でコマンドを専用プロセスグループ実行し、終了コードを返す |

環境変数(いずれも**検証スイート専用**。lane スクリプトは設定してはならない):

| 変数 | 用途 | 既定 |
|---|---|---|
| `GLOBAL_TEST_LOCK_DIR` | ロック基点の上書き | `/tmp/global-test-lane-<uid>.d` |
| `GLOBAL_TEST_LOCK_HEARTBEAT_SECS` | heartbeat 間隔 | `30` |
| `GLOBAL_TEST_LOCK_GRACE_SECS` | シグナル後にグループ消滅を待つ猶予 | `10` |

`GLOBAL_TEST_LOCK_NONCE` は**ライブラリが子へ export する再入トークン**であり、外から与えるものではない。

### 変更後コード

```bash
#!/usr/bin/env bash
#
# scripts/global-test-lock.sh — 全テストレーン共通のグローバルロック (source して使う)。
#
# 目的: 同一 UID・同一マシン (コンテナ) 上で、本規約に参加するテストレーンが
#       同時に 2 本走らないようにする。worktree をまたいだ並列実装の待ち合わせが目的なので
#       「待たずに失敗する」flock -n ではなく **ブロッキング取得** にする。
#
# 設計の正本: devnotes/20260804-2319-global-test-lock/conceptual-design.md
#
# 契約 (非交渉):
#   - ロック保持期間 = 取得 〜 **専用プロセスグループが空になった後**
#     (親の生存期間でも、直接の子の終了時点でもない)
#   - ロック配下では exec を使わない (exec は fd 7 を閉じてロックを即解放する)
#   - 待機中のみ heartbeat を出す (保持中はテストランナー自身が喋る。CI は無競合なので無音)
#   - 再入は「env の nonce == 現存する sidecar の nonce」のときだけ。再入経路は何も獲得しない
#   - flock(1) 不在環境は排他なしで続行 (既存 lane スクリプトの方針を踏襲)。ただし警告を 1 行出す
#   - ロック dir が乗っ取られていたら **明示エラーで停止** する (黙って保護を落とさない)
#
# 保証しないこと:
#   - SIGKILL / 親のクラッシュ / コンテナ強制停止 (trap が走らない)。
#     この場合も flock は OS が解放し、残留 sidecar は次の取得者が上書きするため
#     「ロックリーク」と「stale nonce による誤再入」は防ぐが、残存子孫との併走は防げない
#   - 自ら setsid()/setpgid() で専用プロセスグループを離脱した子孫
#   - 規約に参加しないプロセス (bug-hunt / 手打ちの vendor/bin/pest / 他ツール)

# ---- 内部状態 (source 元シェルに置く) ----
_GTL_FD=7                 # ロック fd。既存 lane が使っていた 9 とは分ける
_GTL_MODE=""              # owner / reentrant / disabled
_GTL_SIDECAR=""
_GTL_NONCE=""
_GTL_HB_PID=""
_GTL_CHILD_PID=""
_GTL_CHILD_PGID=""
_GTL_PREV_MONITOR=0
_GTL_CLEANED=0

_gtl_die() { echo "global-test-lock: ERROR: $*" >&2; exit 1; }
_gtl_warn() { echo "global-test-lock: $*" >&2; }

_gtl_lock_dir() {
    if [ -n "${GLOBAL_TEST_LOCK_DIR:-}" ]; then
        _gtl_warn "using override lock dir ${GLOBAL_TEST_LOCK_DIR} (self-test only)"
        printf '%s\n' "${GLOBAL_TEST_LOCK_DIR}"
        return 0
    fi
    # 基点は /tmp に固定する。${TMPDIR} はプロセスごとに異なりうるため、基点に使うと
    # 同一 UID でもロックが分裂して「マシン全体」の保証が壊れる。
    printf '/tmp/global-test-lane-%s.d\n' "$(id -u)"
}

# ロック dir を 0700 で用意し、乗っ取り (symlink / 別所有者 / 緩い mode) を fail-secure に検出する。
# UID 接尾辞はユーザー間の通常運用上の衝突を分けるだけで、先取りは防げない。防ぐのはここ。
_gtl_ensure_dir() {
    local dir="$1" owner mode
    mkdir -p -m 700 "${dir}" 2>/dev/null || true
    [ -L "${dir}" ] && _gtl_die "lock dir is a symlink (refusing): ${dir}"
    [ -d "${dir}" ] || _gtl_die "lock dir is not a directory (refusing): ${dir}"
    owner="$(stat -c '%u' "${dir}" 2>/dev/null || stat -f '%u' "${dir}" 2>/dev/null || echo '?')"
    mode="$(stat -c '%a' "${dir}" 2>/dev/null || stat -f '%OLp' "${dir}" 2>/dev/null || echo '?')"
    [ "${owner}" = "$(id -u)" ] || _gtl_die "lock dir owner mismatch (uid ${owner}): ${dir}"
    [ "${mode}" = "700" ] || _gtl_die "lock dir mode must be 700 (got ${mode}): ${dir}"
}

_gtl_new_nonce() {
    # 外部コマンドに依存しない一意トークン (pid + 高分解能時刻 + 乱数)。
    printf '%s-%s-%s%s\n' "$$" "${EPOCHREALTIME:-$(date +%s)}" "${RANDOM}" "${RANDOM}"
}

# sidecar の 1 行目 = nonce。所有者検証つきで読む (他ユーザーが置いた偽 sidecar を信じない)。
_gtl_sidecar_nonce() {
    local f="$1" owner line=""
    [ -f "${f}" ] || return 1
    owner="$(stat -c '%u' "${f}" 2>/dev/null || stat -f '%u' "${f}" 2>/dev/null || echo '?')"
    [ "${owner}" = "$(id -u)" ] || return 1
    IFS= read -r line < "${f}" || return 1
    printf '%s\n' "${line}"
}

# 同一 dir 内の一時ファイルへ書いてから mv する (アトミック書き込み)。
_gtl_write_sidecar() {
    local lane="$1" tmp
    tmp="${_GTL_SIDECAR}.tmp.$$"
    {
        printf '%s\n' "${_GTL_NONCE}"
        printf 'pid=%s\n' "$$"
        printf 'lane=%s\n' "${lane}"
        printf 'worktree=%s\n' "$(pwd -P)"
        printf 'since=%s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')"
    } > "${tmp}"
    mv -f "${tmp}" "${_GTL_SIDECAR}"
}

# 待機中だけ heartbeat を出す。無出力の待機を LLM エージェントが「ハング」と誤判断して
# プロセスを kill する事故を防ぐのが目的なので、保持者の身元まで出す。
_gtl_heartbeat_loop() {
    local start="$1" waited=0 holder=""
    while :; do
        sleep "${GLOBAL_TEST_LOCK_HEARTBEAT_SECS:-30}"
        waited=$(( $(date +%s) - start ))
        holder="$(
            {
                # 1 行目 (nonce) は出さず、診断行だけを 1 行に畳む
                read -r _
                while IFS= read -r l; do printf '%s ' "${l}"; done
            } < "${_GTL_SIDECAR}" 2>/dev/null || true
        )"
        echo "global-test-lock: waiting ${waited}s for the global test lane lock — held by ${holder:-<unknown>}" >&2
    done
}

# zombie (Z) は「消滅」とみなす。SIGKILL 済みの Z は fd も DB 接続もポートも保持しないため、
# kill -0 -"$pgid" だけで判定すると永久に「生存」と誤判定して収束しない (実測済み)。
_gtl_group_alive() {
    ps -A -o pgid= -o stat= 2>/dev/null \
        | awk -v g="$1" '{sub(/^ +/, "")} $1 == g && $2 !~ /^Z/ { found = 1 } END { exit !found }'
}

# 上限つきでグループの消滅を待ち、猶予超過ならグループへ SIGKILL を送る。
# **必ず wait より前に呼ぶこと**: 先に wait すると、子が INT/TERM を無視した瞬間に
# wait から戻れず SIGKILL に到達できないまま「ロックを永久保持する deadlock」になる。
_gtl_wait_group_gone() {
    local pgid="$1" grace="${GLOBAL_TEST_LOCK_GRACE_SECS:-10}" waited=0
    while _gtl_group_alive "${pgid}"; do
        if [ "${waited}" -ge "${grace}" ]; then
            _gtl_warn "grace exceeded; SIGKILL process group ${pgid}"
            kill -KILL -"${pgid}" 2>/dev/null || true
            break
        fi
        sleep 1
        waited=$(( waited + 1 ))
    done
    waited=0
    while _gtl_group_alive "${pgid}" && [ "${waited}" -lt 5 ]; do
        sleep 1
        waited=$(( waited + 1 ))
    done
}

# 冪等。INT/TERM ハンドラ実行後に EXIT trap が再度走っても安全。
_gtl_cleanup() {
    [ "${_GTL_CLEANED}" = "1" ] && return 0
    _GTL_CLEANED=1
    if [ -n "${_GTL_HB_PID}" ]; then
        kill "${_GTL_HB_PID}" 2>/dev/null || true
        wait "${_GTL_HB_PID}" 2>/dev/null || true
        _GTL_HB_PID=""
    fi
    # sidecar は **自分の nonce と一致するときだけ** 削除する
    # (再入した子や次の owner の sidecar を消さない)。
    if [ -n "${_GTL_SIDECAR}" ]; then
        local cur=""
        cur="$(_gtl_sidecar_nonce "${_GTL_SIDECAR}" 2>/dev/null || true)"
        [ -n "${cur}" ] && [ "${cur}" = "${_GTL_NONCE}" ] && rm -f "${_GTL_SIDECAR}"
    fi
    [ "${_GTL_MODE}" = "owner" ] && exec 7>&-
    [ "${_GTL_PREV_MONITOR}" = "1" ] || set +m
    return 0
}

# 契約順序: (1) グループへ転送 → (2) 上限つきで消滅待ち → (3) 猶予超過なら SIGKILL →
#           (4) 直接子を wait して reap → (5) sidecar 削除 → (6) fd を閉じて解放 → (7) 自死
_gtl_on_signal() {
    local sig="$1"
    if [ -n "${_GTL_CHILD_PGID}" ]; then
        kill -"${sig}" -"${_GTL_CHILD_PGID}" 2>/dev/null || true
        _gtl_wait_group_gone "${_GTL_CHILD_PGID}"
        [ -n "${_GTL_CHILD_PID}" ] && { wait "${_GTL_CHILD_PID}" 2>/dev/null || true; }
    fi
    _gtl_cleanup
    trap - "${sig}" EXIT
    kill -"${sig}" "$$"
}

# set -m で専用プロセスグループを作れることを取得時に 1 回だけ強制検証する
# (各レーン実行時の ps 検証は、高速終了する子に対して空を返す race があるため best-effort にする)。
_gtl_probe_process_group() {
    local prev=0 pid pgid
    case "$-" in *m*) prev=1 ;; esac
    set -m
    sleep 0.3 &
    pid=$!
    [ "${prev}" = "1" ] || set +m
    pgid="$(ps -o pgid= -p "${pid}" 2>/dev/null | tr -d ' ')"
    kill "${pid}" 2>/dev/null || true
    wait "${pid}" 2>/dev/null || true
    [ "${pgid}" = "${pid}" ] || _gtl_die "job control で専用プロセスグループを作れない (set -m 不可)"
}

global_test_lock_acquire() {
    local lane="${1:-unknown lane}" dir lockfile start
    dir="$(_gtl_lock_dir)"
    _GTL_SIDECAR="${dir}/owner"
    lockfile="${dir}/lock"

    # --- 再入: 何も獲得しない (fd / sidecar / trap / プロセスグループのいずれも新設しない) ---
    if [ -n "${GLOBAL_TEST_LOCK_NONCE:-}" ]; then
        local cur=""
        cur="$(_gtl_sidecar_nonce "${_GTL_SIDECAR}" 2>/dev/null || true)"
        if [ -n "${cur}" ] && [ "${cur}" = "${GLOBAL_TEST_LOCK_NONCE}" ]; then
            _GTL_MODE="reentrant"
            return 0
        fi
    fi

    if ! command -v flock >/dev/null 2>&1; then
        _gtl_warn "flock(1) が無いため排他なしで実行します (devcontainer / CI では排他あり)"
        _GTL_MODE="disabled"
        return 0
    fi

    _gtl_ensure_dir "${dir}"
    _gtl_probe_process_group
    exec 7>"${lockfile}"
    _GTL_MODE="owner"
    trap '_gtl_cleanup' EXIT
    trap '_gtl_on_signal INT' INT
    trap '_gtl_on_signal TERM' TERM

    if ! flock -n 7; then
        start="$(date +%s)"
        # heartbeat 子には fd 7 を渡さない (渡すと解放後もロックが生き続ける)
        _gtl_heartbeat_loop "${start}" 7>&- &
        _GTL_HB_PID=$!
        flock 7                                  # ブロッキング取得 (待つことが目的。上限は設けない)
        kill "${_GTL_HB_PID}" 2>/dev/null || true
        wait "${_GTL_HB_PID}" 2>/dev/null || true
        _GTL_HB_PID=""
    fi

    _GTL_NONCE="$(_gtl_new_nonce)"
    _gtl_write_sidecar "${lane}"                 # 残留 sidecar はここでアトミックに上書きされる
    export GLOBAL_TEST_LOCK_NONCE="${_GTL_NONCE}"
    return 0
}

global_test_lock_run() {
    # 再入 / flock 不在では素通り (fd 7 を保持していないので 7>&- もプロセスグループも不要)
    if [ "${_GTL_MODE}" != "owner" ]; then
        "$@"
        return $?
    fi

    local status=0 pgid=""
    case "$-" in *m*) _GTL_PREV_MONITOR=1 ;; *) _GTL_PREV_MONITOR=0 ;; esac
    set -m
    "$@" 7>&- &                                   # fd 7 は子へ渡さない (orphan による lock leak 防止)
    _GTL_CHILD_PID=$!
    [ "${_GTL_PREV_MONITOR}" = "1" ] || set +m
    _GTL_CHILD_PGID="${_GTL_CHILD_PID}"           # set -m により PGID == PID (取得時に probe 済み)

    # best-effort 検証: 空 = 既に終了 (race) なので異常ではない。値が違うときだけ落とす。
    pgid="$(ps -o pgid= -p "${_GTL_CHILD_PID}" 2>/dev/null | tr -d ' ')"
    if [ -n "${pgid}" ] && [ "${pgid}" != "${_GTL_CHILD_PID}" ]; then
        _gtl_die "専用プロセスグループを作れなかった (pid=${_GTL_CHILD_PID} pgid=${pgid})"
    fi

    wait "${_GTL_CHILD_PID}" || status=$?
    _GTL_CHILD_PID=""
    _gtl_wait_group_gone "${_GTL_CHILD_PGID}"     # 孫が残っている間はロックを離さない
    _GTL_CHILD_PGID=""
    return "${status}"
}
```

### テスト計画
- [ ] 層 1(施策 7)が本ライブラリの全契約を検証する
- [ ] 層 2(施策 8)が「lane から参照されていること」「`exec` を使っていないこと」を固定する
- [ ] 実装前に層 1 を書き、未変更ツリーで fail を観測する(AGENTS.md 思考原則 5)

### リスク
- `set -m` は非対話シェルでも job 通知を出す実装がありうる。実測では出なかったが、
  ノイズが出た場合に備え monitor mode は**起動直後に復元**する(有効期間を最小化)。
- `stat` の GNU/BSD 差は `-c` → `-f` フォールバックで吸収する。両方失敗した場合は
  `?` になり検証に落ちて**停止する**(fail-secure。黙って通さない)。
- `ps -A -o pgid= -o stat=` は GNU/BSD 双方で通る形式。`ps` 不在環境ではグループ空判定が
  できず `_gtl_group_alive` が常に false を返す → 「即座に空」とみなして進む。
  これは flock 不在時と同じ**保護の縮退**であり、`ps` 不在は現実の開発環境では起きない。

---

## 施策 2: コマンドラッパの新設

### 変更箇所
- ファイル: `scripts/with-global-test-lock.sh`(新規)

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 7・8

### 変更後コード

```bash
#!/usr/bin/env bash
#
# scripts/with-global-test-lock.sh — 任意コマンドをグローバルテストロック配下で実行する。
#
# ラップ用のシェルスクリプトを持たない lane (package.json の test:packages) 用。
# lane スクリプトを持つ 3 レーンは scripts/global-test-lock.sh を直接 source する
# (直接叩かれた場合も保護されるため)。
#
# **exec は使わない**: exec は fd 7 を閉じてロックを即解放してしまう。
# fd 7 を保持したままの親が子を待ち、終了コードをそのまま返す。

set -euo pipefail

if [ "$#" -lt 1 ]; then
    echo "usage: with-global-test-lock.sh <command> [args...]" >&2
    exit 2
fi

# shellcheck source=scripts/global-test-lock.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/global-test-lock.sh"

global_test_lock_acquire "$*"

status=0
global_test_lock_run "$@" || status=$?
exit "${status}"
```

### テスト計画
- [ ] 層 1: ラッパ経由でも「実行中にロックが保持される」ことを検証(`exec` 回帰の負のコントロール)
- [ ] 層 1: 終了コードが素通しされること(0 / 非 0 / シグナル)

### リスク
- `BASH_SOURCE` 依存のため `sh` で起動されると壊れる。shebang が `bash` であること、
  呼び出し側が `bash scripts/with-global-test-lock.sh ...` であることを層 2 で固定する。

---

## 施策 3: Feature lane を置換

### 変更箇所
- ファイル: `scripts/run-test.sh`(L9-25 のヘッダ説明と flock ブロック、L38 の実行行)

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし
- ドキュメント: `scripts/README.md`(施策 10)/ `docs/worktree-isolation-strategy.md`(施策 10)
- テストファイル: 施策 8 が composer.json とスクリプト本文を固定

### 現行コード

```bash
# lock は worktree-local (storage/framework/testing/ は worktree ごとに別実体) なので
# 別 worktree の test は止めない (base DB 名 hash が異なり競合しない)。
# flock(1) が無い環境 (素の macOS 等) では排他なしで実行する (devcontainer/CI では排他あり)。

set -euo pipefail
cd "$(dirname "$0")/.."

LOCK_FILE="storage/framework/testing/test.lock"   # worktree-local
mkdir -p "$(dirname "$LOCK_FILE")"
if command -v flock >/dev/null 2>&1; then
    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
        echo "ERROR: another composer test is running in this worktree; refusing to start" >&2
        echo "       lock file: $LOCK_FILE" >&2
        exit 1
    fi
fi

php artisan config:clear --ansi
php scripts/ci/ensure-test-db.php
php artisan test --parallel --processes=4 "$@" 9>&-
```

### 変更後コード

```bash
#!/usr/bin/env bash
#
# scripts/run-test.sh — composer test の pgsql 経路。グローバルテストロック配下で
# ensure (base DB 冪等 CREATE) → artisan test --parallel を実行する。
#
# 排他は scripts/global-test-lock.sh に一本化した (旧 worktree-local な
# storage/framework/testing/test.lock は廃止)。グローバルロックのスコープ
# (同一 UID・同一マシン) は worktree-local のスコープを厳密に包含するため、
# 内側のロックは 1 つも新しい事象を防がない (後方互換の並走を残さない)。
#
# 待ち方も変わった: 先行レーンがいる場合は **待つ** (旧実装は flock -n で即エラー終了)。
# 待機中は 30 秒ごとに保持者の身元が stderr に出る。

set -euo pipefail
cd "$(dirname "$0")/.."

# shellcheck source=scripts/global-test-lock.sh
. "$(pwd)/scripts/global-test-lock.sh"
global_test_lock_acquire "composer test"

# 以降、ロック配下の実行は必ず global_test_lock_run を通す
# (fd 7 の非継承と、孫まで含めたプロセスグループの刈り取りを一箇所に集約するため)。
global_test_lock_run php artisan config:clear --ansi

# worktree 固有の base テスト DB (<slug>_test_<worktree-hash>) を冪等に用意する。
# DB 名の安全検証 (dev DB hard-deny + allowlist) は tests/bootstrap.php の
# 単一点ガード + ensure-test-db.php 内の二重防御が担う。
global_test_lock_run php scripts/ci/ensure-test-db.php

global_test_lock_run php artisan test --parallel --processes=4 "$@"
```

### テスト計画
- [ ] 層 2: `composer.json` の `test` が `scripts/run-test.sh` 経由であり、
      当該スクリプトが `global-test-lock.sh` を source していること
- [ ] 層 2: `storage/framework/testing/test.lock` / `flock -n` の残存がないこと(負のコントロールつき)
- [ ] 層 1: 2 本目の `composer test` 相当が**待機**して、1 本目の終了後に走ること
- [ ] 既存テストの更新: なし(テストコードは触らない)

### リスク
- `global_test_lock_run` は各コマンドを background + `wait` にするため、`--ansi` の
  カラー出力が TTY 判定で変わる可能性がある。`php artisan` は fd 1 が TTY か否かで判定するが、
  background 実行でも fd 1 は継承されるため TTY 判定は変わらない(層 1 で TTY あり/なしを検証)。
- `set -e` 下で `global_test_lock_run` が非 0 を返すとスクリプトが終了 → EXIT trap で解放される。
  終了コードはそのまま呼び出し元へ伝わる。

---

## 施策 4: Browser lane を置換 + playwright 掃除の限定 + bughunt pre-flight guard

### 変更箇所
- ファイル: `scripts/run-browser-test.sh`
  - L41-52: flock ブロック → グローバルロック
  - L54-62: `cleanup_orphan_playwright()` に `@playwright/` 除外を追加
  - 新規: bughunt pre-flight guard
  - L105-106: pest 実行(`9>&-` → `global_test_lock_run`)

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし
- ドキュメント: `docs/testing-browser.md`(施策 10。ロック説明 + 手動復旧 runbook)
- テストファイル: 施策 7・8

### 現行コード

```bash
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
        ppid=$(ps -o ppid= -p "${pid}" 2>/dev/null | tr -d ' ')
        if [ "${ppid}" = "1" ]; then
            kill "${pid}" 2>/dev/null || true
        fi
    done
}
...
    vendor/bin/pest -c phpunit.browser.xml "${PEST_PARALLEL_ARGS[@]}" \
        --browser "${browser}" "$@" 9>&- || code=$?
```

### 変更後コード

```bash
# --- グローバルテストロック (旧 worktree-local flock を置き換え) ---
# shellcheck source=scripts/global-test-lock.sh
. "$(pwd)/scripts/global-test-lock.sh"
global_test_lock_acquire "composer test:browser"

# --- bug-hunt 併走の pre-flight guard (best-effort。保証ではない) ---
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

...

global_test_lock_run php artisan config:clear --ansi
global_test_lock_run php scripts/ci/ensure-test-db.php

...
    code=0
    global_test_lock_run vendor/bin/pest -c phpunit.browser.xml "${PEST_PARALLEL_ARGS[@]}" \
        --browser "${browser}" "$@" || code=$?
```

> **注**: `cleanup_orphan_playwright` の呼び出し位置(起動時 + EXIT trap)は変えない。
> ただし EXIT trap は `trap cleanup_orphan_playwright EXIT` のままだと
> ロックライブラリの `trap '_gtl_cleanup' EXIT` を**上書きしてしまう**。
> `global_test_lock_acquire` **より前**に `trap` を張るか、
> `trap 'cleanup_orphan_playwright; _gtl_cleanup' EXIT` の形へ統合すること。
> **層 1 に「Browser lane 相当の EXIT trap 併用でロックが解放されること」の検証を入れる**
> (trap 上書きは実際に起きうる回帰なので機械で固定する)。

### テスト計画
- [ ] 層 1: `@playwright/cli` 相当の cmdline を持つ偽プロセスが `cleanup_orphan_playwright` の
      選択対象にならないこと(負のコントロール)、および
      `node_modules/playwright/cli.js run-server` 相当は選択されること(正のコントロール)
- [ ] 層 1: bughunt ポートを 1 つ listen させた状態で Browser lane 相当が fail-fast すること
- [ ] 層 1: lane 側 EXIT trap を併用してもロックが解放されること(trap 上書き回帰)
- [ ] 層 2: `composer.json` の `test:browser` が `scripts/run-browser-test.sh` 経由で、
      当該スクリプトが `global-test-lock.sh` を source していること
- [ ] 層 2: 旧 `test.lock` / `flock -n 9` が残っていないこと

### リスク
- **trap 上書き**(上記)。設計として明記し、層 1 で固定する。
- Browser lane はレーンごとに `global_test_lock_run` を呼ぶが、ロックは
  `global_test_lock_acquire` で 1 回取ったまま維持される(`run` は取得しない)。
  Chromium → WebKit の 2 レーン間でロックが落ちないことを層 1 で確認する。
- `/dev/tcp` が無効化された bash ビルドでは guard が常に「未使用」を返す(= skip)。
  best-effort の定義どおりで、保証の後退ではない。

---

## 施策 5: JS lane を置換(`exec` 廃止)

### 変更箇所
- ファイル: `scripts/run-vitest.sh`(全面。L13-30)

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 7・8

### 現行コード

```bash
WORKSPACE="$(cd "$(dirname "$0")/.." && pwd)"
LOCK_DIR="${TMPDIR:-/tmp}"
LOCK_KEY="$(printf '%s' "$WORKSPACE" | shasum -a 256 | cut -c1-16)"
LOCK_FILE="$LOCK_DIR/app-vitest-${LOCK_KEY}.lock"

if command -v flock >/dev/null 2>&1; then
    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
        echo "ERROR: vitest is already running in this workspace." >&2
        ...
        exit 1
    fi
fi

cd "$WORKSPACE"
exec pnpm exec vitest run "$@"
```

### 変更後コード

```bash
#!/usr/bin/env bash
#
# scripts/run-vitest.sh — vitest をグローバルテストロック配下で実行する。
#
# 旧実装は workspace realpath 由来の key で worktree ごとに別ロックを取り (= cross-worktree
# 排他ゼロ)、かつ flock -n で待たずに即エラー終了していた。両方ともグローバルロックへ置き換える。
#
# JS レーンは DB もポートも掴まないが、Browser lane と CPU を奪い合うと
# タイムアウト由来の偽赤を作るため対象に含める (方針判断。成功条件と見直し条件は
# devnotes/20260804-2319-global-test-lock/conceptual-design.md)。
#
# **exec は使わない**: exec は fd 7 を閉じてロックを即解放してしまう
# (旧実装の `exec pnpm exec vitest run` は fd 9 を vitest へ継承させることで偶然
#  ロックを保っていたが、それは orphan による lock leak と表裏一体の形だった)。

set -euo pipefail
cd "$(dirname "$0")/.."

# shellcheck source=scripts/global-test-lock.sh
. "$(pwd)/scripts/global-test-lock.sh"
global_test_lock_acquire "pnpm test"

status=0
global_test_lock_run pnpm exec vitest run "$@" || status=$?
exit "${status}"
```

### テスト計画
- [ ] 層 1: ラッパ実行中に第三のレーンがロックを取得できないこと(`exec` 回帰の負のコントロール)
- [ ] 層 2: `package.json` の `test` が `scripts/run-vitest.sh` 経由で、当該スクリプトが
      `global-test-lock.sh` を source し、`exec` を含まないこと
- [ ] 層 2: `app-vitest-*.lock` / `shasum` 由来の key 生成が残っていないこと

### リスク
- vitest がプロセスグループのリーダーになることで、`vitest run` が端末の
  フォアグラウンドグループでなくなる。`vitest run` は非対話なので影響しない
  (`test:ui` / `test:watch` は対象外なので変わらない)。

---

## 施策 6: packages lane をラップ

### 変更箇所
- ファイル: `package.json`(`scripts.test:packages`)

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 施策 8(lane inventory)

### 現行コード

```json
"test": "bash scripts/run-vitest.sh",
"test:ui": "vitest --ui",
"test:coverage": "vitest --coverage",
"test:watch": "vitest --watch",
"build:packages": "pnpm -F \"./packages/*\" build",
"test:packages": "pnpm -F \"./packages/*\" test"
```

### 変更後コード

```json
"test": "bash scripts/run-vitest.sh",
"test:ui": "vitest --ui",
"test:coverage": "vitest --coverage",
"test:watch": "vitest --watch",
"build:packages": "pnpm -F \"./packages/*\" build",
"test:packages": "bash scripts/with-global-test-lock.sh pnpm -F \"./packages/*\" test"
```

- `test:ui` / `test:coverage` / `test:watch` は **watch / 対話用途**なのでラップしない
  (ロックを無期限保持してしまう)。層 2 の inventory に**理由つきの明示 exemption** として
  登録し、それ以外の `test*` スクリプトは deny-by-default で「ラップ必須」とする。
- `packages/cli` 自身の `test`(`vitest run`)はラップしない。root の `test:packages` が
  公式 entrypoint であり、`pnpm -F @app/cli test` の直叩きは AGENTS.md の検証コマンド一覧に
  無い非公式経路である(既知の限界として docs に明記する)。

### テスト計画
- [ ] 層 2: `test:packages` が `with-global-test-lock.sh` 経由であること
- [ ] 層 2: `test*` スクリプトの deny-by-default 検査(新規レーン追加で fail すること。負のコントロールつき)

### リスク
- `pnpm -F` が内部で更に `pnpm` を起動しても、再入トークンは env 経由で伝播するため
  二重取得にはならない(層 1 の再入ケースで固定)。

---

## 施策 7: 並行挙動検証スイート(層 1)

### 変更箇所
- ファイル: `scripts/verify-global-test-lock.sh`(新規・恒久。`scripts/README.md` 台帳に登録)

### 波及変更
- CI: 施策 9 で `php` job に 1 ステップ追加
- ドキュメント: `scripts/README.md`(施策 10)

### 設計

- 常に `GLOBAL_TEST_LOCK_DIR="$(mktemp -d)"` を使い、**実ロックに触れない**。
  `GLOBAL_TEST_LOCK_HEARTBEAT_SECS=1` / `GLOBAL_TEST_LOCK_GRACE_SECS=2` に縮めて実行時間を数十秒に抑える。
- `flock` 不在環境では該当ケースだけ skip し、skip したことを明示出力する
  (`scripts/bug-hunt-shard.sh self-test` の既存作法と揃える)。
- 各ケースは `t_ok` / `t_fail` の 2 関数で報告し、1 つでも fail したら非 0 で終了する。

### 検証ケース(14 ケース)

| # | ケース | 検証内容 |
|---|---|---|
| a | lock path の導出 | 既定が `/tmp/global-test-lane-<uid>.d/lock`。`GLOBAL_TEST_LOCK_DIR` 上書き時は警告が出る |
| b | lock dir の fail-secure | symlink / 別 mode のディレクトリを与えると**明示エラーで停止**する(黙って続行しない) |
| c | ブロッキング取得 | 2 本目は**即エラーにならず待機**し、1 本目の解放後に実行される(旧 `flock -n` の負のコントロール) |
| d | heartbeat | 待機中に heartbeat 行が stderr に出て、**保持者の pid / lane / worktree** を含む |
| e | 非競合時の無音 | 待たずに取れたケースでは heartbeat が 1 行も出ない(CI ログを汚さない) |
| f | fd 非継承 | ロック配下のコマンドから `/proc/self/fd/7` が見えない。heartbeat 子にも渡らない |
| g | 保持期間 = 実行中 | コマンド実行中は第三のレーンが取得できない(**`exec` 回帰の負のコントロール**) |
| h | 孫の刈り取り | 直接子が**孫を生んで先に終了**しても、孫が消えるまで第三のレーンは取得できない |
| i | シグナル収束 | **INT/TERM を無視する子と孫**に対し、猶予超過後に強制終了して第三のレーンが取得できる(deadlock しない) |
| j | 終了コード契約 | 0 / 非 0 が素通しされる。INT/TERM 時は親が 128+signo で終わる |
| k | 背景子の非残存 | 全ケース終了後に子孫プロセスが残らない |
| l | プロセスグループ | レーンが PGID == PID になる。現行 4 レーン相当のコマンドが**自発的に離脱しない** |
| m | 再入(nonce 一致) | 保持中の子孫からの再呼び出しが deadlock せず素通りする。**再入子の終了後も外側 sidecar が残る** |
| n | 再入の否定 | (1) 保持者を SIGKILL した後、stale nonce を持つ子孫は再入できない (2) **残留 sidecar は次の取得者をブロックせずアトミックに上書きされる** |
| o | flock 不在 | `flock` が PATH に無い状態を作ると、警告を出して**排他なしで実行し終了コードは保つ** |
| p | TTY あり / なし | c〜j が TTY 有無の双方で成立し、monitor mode が復元される |
| q | lane 側 trap 併用 | Browser lane 相当の `trap ... EXIT` を張ってもロックが解放される(trap 上書き回帰) |
| r | playwright 選別 | `@playwright/cli` 相当の cmdline は掃除対象にならず、`node_modules/playwright/cli.js run-server` 相当は対象になる |
| s | bughunt guard | `:8010..:8018` のいずれかを listen させると Browser lane 相当が fail-fast する |

> ケース数を移植元の 11 に合わせることは目的にしていない。aicue のレーン構成
> (4 レーン + bug-hunt 隣接 + Browser lane 固有の掃除)に対して過不足なく定義した結果 14 種
> (a〜s のうち一部は 1 ケースに統合)になっている。

### テスト計画
- [ ] 本スイート自体が「未変更ツリーで fail する」ことを実装前に観測する(テストファースト)
- [ ] CI(施策 9)で毎回走ること

### リスク
- 並行性テストは環境負荷で不安定になりやすい。待ち時間は固定 sleep ではなく
  **条件ポーリング + 上限**で書く(`sleep` 決め打ちを禁止する)。
- 実行時間が伸びると CI の負担になる。heartbeat/grace を縮めて**全体 60 秒以内**を目標にする。

---

## 施策 8: 構造的不変条件 Architecture テスト(層 2)

### 変更箇所
- ファイル: `tests/Architecture/GlobalTestLockInventoryTest.php`(新規)

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 本体(既存テストの変更なし)

### 設計方針

`tests/Architecture/PhpstanWrapperInvariantTest.php` と**同じ作法**にする:
純関数で違反リストを返し、それを `expect(...)->toBe([])` で判定し、
**負のコントロール**(壊れた fixture を検出できること)を必ず添える。
DB を使わない静的検査。

> **層 1 を実行してはならない**(非交渉)。本テストは `composer test` の内側
> = グローバルロック保持中に走るため、そこから並行挙動スイートを起動すると自分自身と競合する。
> 本テストが検証するのは「層 1 が存在し実行可能であること」までとする。

### 変更後コード(骨子)

```php
<?php

declare(strict_types=1);

/*
 * Architecture invariant: 全テストレーンがグローバルテストロックを経由すること。
 *
 * 背景 (SoT = devnotes/20260804-2319-global-test-lock/conceptual-design.md):
 * 複数 worktree の並行実装でテストレーンが同時に走ると、PostgreSQL サーバ・実ブラウザ・
 * CPU/メモリを奪い合い、Browser lane の machine-wide な playwright 掃除が他レーンの
 * run-server を巻き込む。旧実装は worktree-local な flock (cross-worktree 排他ゼロ) かつ
 * flock -n (待たずに即エラー) だったため、これを scripts/global-test-lock.sh へ一本化した。
 *
 * worktree-local flock を「残さず削除する」判断が安全なのは、公式 entrypoint を
 * **全て確実に包めている場合に限る**。よって本テストは deny-by-default の inventory とする:
 * composer.json / package.json の test 系スクリプトは、明示 exemption に無い限り
 * ロック経由でなければ fail する (新レーン追加時に落ちて気づける)。
 *
 * 並行挙動そのものは scripts/verify-global-test-lock.sh (層 1) が検証する。
 * **本テストから層 1 を実行してはならない**: 本テストは composer test の内側
 * = グローバルロック保持中に走るため、自分自身と競合する。
 */

/** watch / 対話用途のため意図的にラップしない script と、その理由。 */
const GLOBAL_TEST_LOCK_EXEMPT = [
    'test:ui' => 'vitest --ui (常駐 UI)。ロックを無期限保持するため対象外',
    'test:coverage' => 'vitest --coverage (watch)。同上',
    'test:watch' => 'vitest --watch (watch)。同上',
];

/** ロック経由と認められる呼び出し先 (これ自身がライブラリを source していることも検査する)。 */
const GLOBAL_TEST_LOCK_LANE_SCRIPTS = [
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
    'scripts/run-vitest.sh',
];

/**
 * composer.json / package.json の test 系 script が全てロック経由かを検査する (純関数)。
 *
 * @param  array<string, string>  $scripts  script 名 => コマンド文字列 (配列形式は改行連結済み)
 * @return list<string> 違反一覧 (空 = 合格)
 */
function globalTestLockLaneViolations(array $scripts): array
{
    $violations = [];

    foreach ($scripts as $name => $command) {
        if ($name !== 'test' && ! str_starts_with($name, 'test:')) {
            continue;
        }
        if (array_key_exists($name, GLOBAL_TEST_LOCK_EXEMPT)) {
            continue;
        }
        if (str_contains($command, 'scripts/with-global-test-lock.sh')) {
            continue;
        }
        $viaLaneScript = false;
        foreach (GLOBAL_TEST_LOCK_LANE_SCRIPTS as $laneScript) {
            if (str_contains($command, $laneScript)) {
                $viaLaneScript = true;
                break;
            }
        }
        if (! $viaLaneScript) {
            $violations[] = "script '{$name}' がグローバルテストロックを経由していない: {$command}";
        }
    }

    return $violations;
}

/**
 * lane スクリプト本体が契約を守っているかを検査する (純関数)。
 *
 * @return list<string> 違反一覧 (空 = 合格)
 */
function globalTestLockLaneScriptViolations(string $path, string $source): array
{
    $violations = [];

    if (! str_contains($source, 'global-test-lock.sh')) {
        $violations[] = "{$path} が scripts/global-test-lock.sh を source していない";
    }
    // 旧 worktree-local ロックの残存 (後方互換の並走) を禁止する。
    if (str_contains($source, 'storage/framework/testing/test.lock')) {
        $violations[] = "{$path} に旧 worktree-local な test.lock が残っている";
    }
    if (preg_match('/app-vitest-/', $source) === 1) {
        $violations[] = "{$path} に旧 workspace-hash ロック (app-vitest-*) が残っている";
    }
    if (preg_match('/\bflock\s+-n\b/', $source) === 1) {
        $violations[] = "{$path} に flock -n (非ブロッキング取得) が残っている";
    }
    // 自己バイパスの禁止。
    if (preg_match('/GLOBAL_TEST_LOCK_DIR=/', $source) === 1) {
        $violations[] = "{$path} が GLOBAL_TEST_LOCK_DIR を設定している (自己バイパス禁止)";
    }
    // exec はロック fd を閉じてロックを即解放するため、ロック配下では使わない。
    if (preg_match('/^\s*exec\s+(?!\d*[<>])/m', $source) === 1) {
        $violations[] = "{$path} が exec を使っている (fd 7 が閉じてロックが即解放される)";
    }

    return $violations;
}

test('scripts/global-test-lock.sh と with-global-test-lock.sh が存在し実行可能であること', ...);
test('scripts/verify-global-test-lock.sh が存在し実行可能であること', ...);
test('composer.json の test 系 script が全てグローバルテストロック経由であること', ...);
test('package.json の test 系 script が全てグローバルテストロック経由であること', ...);
test('lane スクリプトが契約 (source / 旧ロック不在 / flock -n 不在 / exec 不在) を守ること', ...);

/* 負のコントロール (実ファイルは書き換えない) */
test('負のコントロール: 未ラップの新レーンを検出する', function (): void {
    $violations = globalTestLockLaneViolations(['test:e2e' => 'pnpm exec playwright test']);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('test:e2e');
});

test('負のコントロール: 旧 worktree-local ロックへ戻した lane スクリプトを検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    LOCK_FILE="storage/framework/testing/test.lock"
    exec 9>"$LOCK_FILE"
    flock -n 9 || exit 1
    SH;
    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('test.lock');
});

test('負のコントロール: exec を復活させた lane スクリプトを検出する', ...);
```

### PHPStan 適合チェック
- [x] 戻り値の型が明示されている(`list<string>` / `void`)
- [x] null 安全(`file_get_contents` の戻りは `expect(...)->toBeString()` + `@var string` で narrowing。
      既存 `PhpstanWrapperInvariantTest` と同一作法)
- [x] DTO を返している(該当なし。Architecture テストは違反文字列のリストを返す純関数)
- [x] Generics の型パラメータが正しい(`array<string, string>` / `list<string>`)
- [x] `json_decode` の戻りは `mixed` として受け、`is_array()` で narrowing する

### テスト計画
- [ ] 新規テスト: 上記 5 本 + 負のコントロール 3 本
- [ ] 既存テスト `tests/Architecture/PhpstanWrapperInvariantTest.php` の更新: 不要(独立)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認(DB 不使用の静的検査)

### リスク
- `exec` 検出の正規表現は `exec 9>...`(fd リダイレクト形)を誤検出しないよう
  `exec` の直後が数値 + リダイレクト記号のケースを除外する。負のコントロールで両方向を固定する。
- composer.json の script は配列形式なので、読み取り時に改行連結して 1 文字列にしてから渡す。

---

## 施策 9: CI ゲート追加

### 変更箇所
- ファイル: `.github/workflows/ci.yml`(`php` job)

### 波及変更
- なし(job 構成・並列度は変えない)

### 変更後コード

```yaml
      - name: PHPStan
        run: composer phpstan
      # グローバルテストロックの並行挙動ゲート (層 1)。
      # 実ロックには触れず、mktemp -d の scratch 上で待機・シグナル収束・fd 非継承などを検証する。
      - name: Verify global test lock
        run: bash scripts/verify-global-test-lock.sh
      - name: Pest
        run: composer test
```

- CI を**特別扱いしない**方針は維持する(`CI=true` バイパス分岐を作らない)。
  CI では job ごとに独立コンテナ・1 コンテナ 1 テスト実行なのでロックは常に即座に取得でき、
  heartbeat は「待機中のみ」なので 1 行も出ない。コストは `flock` システムコール 1 回。

### テスト計画
- [ ] CI で当該ステップが green になること(PR で確認)
- [ ] 層 2 が「`verify-global-test-lock.sh` が存在し実行可能」を固定しているため、
      スクリプト削除時は `composer test` 側でも落ちる(二重の網)

### リスク
- CI runner が `ps` / `flock` を持つこと(ubuntu-latest は両方持つ)。
  持たない場合はスイートが skip を出力して green になる(偽グリーン回避のため
  **skip したケース数を必ず出力する**)。

---

## 施策 10: ドキュメント更新

### 変更箇所

| ファイル | 変更内容 |
|---|---|
| `scripts/README.md` | `global-test-lock.sh` / `with-global-test-lock.sh` / `verify-global-test-lock.sh` を台帳へ追記。`run-test.sh` / `run-vitest.sh` / `run-browser-test.sh` の行の「flock 排他」記述をグローバルロックへ更新 |
| `docs/testing-browser.md` | 「同一 lock file (`storage/framework/testing/test.lock`) の flock で相互排他」を書き換え。**手動復旧 runbook** を追加(sidecar の読み方 / 保持者の特定 / 残留 sidecar の扱い / bug-hunt 併走時の指針) |
| `docs/worktree-isolation-strategy.md` | 「同一 worktree 内の二重起動だけは `scripts/run-test.sh` の flock で直列化する」を更新。テスト DB は worktree ごとに分離したまま、**実行そのものはグローバルロックで直列化する**という 2 層構造を明記 |
| `docs/template-divergence.md` | **D10** を新設(正典 boundary との意図的差分) |

### `docs/template-divergence.md` D10 の内容(骨子)

| 観点 | テンプレート(正典 = spirux 形) | 本アプリ |
|---|---|---|
| worktree-local flock | 残す(二重ロック) | **削除する**(グローバルロックが厳密に包含するため。思考原則 3) |
| lock file 名 | `/tmp/spirux-global-test.lock`(repo 名固定) | `/tmp/global-test-lane-<uid>.d/lock`(slug 非依存 + UID 分離 + 0700 検証) |
| heartbeat | 常時 30 秒 | **待機中のみ**(保持中はテストランナーが喋る。CI 無音) |
| 再入ガード | owner-pid | **nonce 一致**(PID 再利用の穴を持たない) |
| 検証スイートの置き場 | devnotes 常駐 | **`scripts/` へ昇格 + Architecture テスト + CI ゲート**(禁止事項 1) |
| bug-hunt | (正典に記述なし) | 対象外。非干渉は保証せず best-effort guard のみ(残余リスクとして受容) |

- **揃えている不変条件**: 「ブロッキング取得 / heartbeat / 再入ガード / fd 非継承」の 4 要件は
  正典と同一。差分はいずれも**同じ不変条件をより強い機構で保証する**方向である。
- **スコープ外の観測として記録**: bug-hunt 自身の `.claude/bug-hunt.lock` は worktree-local なので、
  別 worktree からの bug-hunt 同時起動は `playwright-cli kill-all` で相互破壊しうる。
  本設計では触らないが、次に触る人への申し送りとして残す。

### テスト計画
- [ ] `scripts/README.md` の台帳追記は AGENTS.md の規約(昇格時は必ず追記)に従う
- [ ] ドキュメントに書いた不変条件のうち機械化できるものは層 1 / 層 2 に登録済みであること

### リスク
- ドキュメントとスクリプトの乖離。`docs/testing-browser.md` の runbook は
  sidecar のフォーマット(1 行目 = nonce、以降 key=value)に依存するため、
  フォーマットを変えたら runbook も同じ変更で直す。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 4 レーン全ての entrypoint(`composer test` / `test:browser` / `pnpm test` / `test:packages`)を同時に切り替える変更であり、部分適用すると「一部レーンだけロック無し」という**最も危険な中間状態**(worktree-local flock を消したのにグローバルロックが無いレーンが残る)を作る。旧ロックの削除と新ロックの導入は同一変更で完結させる必要がある(AGENTS.md 思考原則 3)。また CI・Architecture テスト・ドキュメントまで含めて 1 単位で green にしないと、層 2 の deny-by-default が落ち続ける |
| 競合リスク | 他 worktree の実装タスクは lane スクリプトを触らないため**コード競合は低い**。ただし本変更が main に入った後、**既存 worktree は rebase/merge するまで旧スクリプトで走る**。旧スクリプト(worktree-local flock)と新スクリプト(グローバルロック)が同時に走ると排他が成立しないため、マージ後は各 worktree で main を取り込むことを実装完了報告に明記する |
| 実装順序 | 施策 7・8(テスト)を先に書いて **未変更ツリーで fail を観測** → 施策 1・2(ライブラリ)→ 施策 3・4・5・6(レーン置換)→ 施策 9(CI)→ 施策 10(ドキュメント) |


---

## 参考: 承認済みの概念設計 (Round 6 APPROVED)

# 概念設計: global-test-lock (テストレーンのグローバルロック)

- 出自: c2c 機能台帳 `global-test-lock` (origin: spirux:T1109/T1110、2026-08-04 オーナー裁定でテンプレ昇格承認済み)
- aicue の台帳ステータス: `reviewing` → 本設計で移植する

## 背景・課題

aicue の実装は必ず worktree (`.claude/worktrees/tasks/<task-id>`) で行う (AGENTS.md §worktree 運用ルール)。
複数の Claude セッションが worktree を並行運用するため、**同一マシン上で複数のテストレーンが同時に走る**
のが常態になっている。

### 実測した現状 (2026-08-04 時点)

| lane | エントリポイント | 排他機構 | スコープ | 取得方式 |
|---|---|---|---|---|
| Feature/Unit/Architecture | `composer test` → `scripts/run-test.sh` | flock fd 9 | `storage/framework/testing/test.lock` = **worktree-local** | `flock -n` (非ブロッキング・即 exit 1) |
| Browser | `composer test:browser` → `scripts/run-browser-test.sh` | flock fd 9 | 同上 (Feature lane と共有) | `flock -n` |
| JS (root) | `pnpm test` → `scripts/run-vitest.sh` | flock fd 9 | `${TMPDIR}/app-vitest-<sha256(WORKSPACE)>.lock` = **worktree ごとに別 key** | `flock -n` |
| JS (packages) | `pnpm test:packages` → `pnpm -F "./packages/*" test` | **なし** | — | — |

つまり **cross-worktree 排他はゼロ**であり、加えて全レーンが `flock -n` (待たずに即エラー終了) である。

### 何が本当に壊れるのか (思い込みでなく実査した結果)

「共有 PostgreSQL テスト DB を取り合う」という前提を検証した結果、**DB 名レベルの衝突は起きない**ことが分かった:
`Tests\Support\Ci\TestDatabaseEnv::pgsqlBaseDatabase()` が base 名を
`<slug>_test_<sha1(realpath(worktree))[0:8]>` で導出し、paratest が更に `_test_<token>` を付す
(`scripts/ci/ensure-test-db.php` / `drop-test-db.php` も同じ base に閉じている)。
したがって「DB 名の取り合い」は aicue には存在しない。**設計を建てる前にここを訂正しておく。**

実在するハザードは以下の 4 つで、**いずれも作用域はマシン (コンテナ) 全体であり、
worktree でもクローンでもない**。この事実がロックのスコープを決める。
なお本ロックが実際に防げるのは、そのうち**同一 UID の参加レーン間**に限られる
(H1 の kill 権限が UID 単位であること、およびロックファイルを UID 単位に置くことによる)。
別 UID のプロセスとの H2 / H3 競合は残余リスクとして残る。

- **H1 (証明済み・破壊的): Browser lane の playwright 掃除がマシン全体スコープ**
  `scripts/run-browser-test.sh` の `cleanup_orphan_playwright()` は
  `pgrep -f "playwright/cli.js run-server"` で**マシン全体**を走査し、PPID=1 のものを kill する
  (起動時 + EXIT trap の 2 回)。worktree A が Browser lane を走らせている最中に
  B が Browser lane を起動すると、プラグイン側の後始末漏れで PPID=1 に再親付けされた
  A の run-server を B が巻き込んで殺す。worktree-local flock はこれを一切防げない。
  **実際に kill が通るのは同一 UID のプロセスのみ**なので、この破壊の作用域は「同一ユーザーのマシン全体」。

- **H2: PostgreSQL サーバという単一共有資源**
  DB 名は分かれても PostgreSQL インスタンスは 1 つ。`artisan test --parallel --processes=4` が
  worktree ごとに 4 本走り、それぞれが per-worker DB の CREATE + `migrate:fresh` を
  全マイグレーションに対して行う。接続数・IO・`pg_database` へのロックが積算され、
  遅延とタイムアウト由来の flake になる。作用域は PostgreSQL インスタンス = マシン全体
  (UID をまたいでも競合する)。

- **H3: devcontainer の CPU / メモリ枯渇**
  Browser lane は Chromium + WebKit の 2 レーン契約 (`docs/testing-browser.md`、非交渉) で
  `memory_limit=1G` + 実ブラウザを起動する。ここに他 worktree の paratest 4 本と vitest
  (`maxWorkers: "50%"`) が重なるとタイムアウト由来の**偽赤**が出る。
  aicue は「緑を赤と誤報告する = レーンの信頼性が失われる」ことを既に非交渉の基準としている
  (run-browser-test.sh が `--parallel --processes=1` を既定にしている理由がまさにこれ)。
  作用域はマシン全体 (UID をまたいでも競合する)。

- **H4 (運用): `flock -n` が待たずに死ぬ**
  並列実装の**待ち合わせ**が目的なのに、後発は即 exit 1 になる。エージェントは
  「ロックに阻まれた」を失敗と解釈してリトライループを回すか、レーンを迂回する。
  排他機構が守るべき挙動を、機構自身が壊している。

## 改善アイデア

**「テストレーンは同一ユーザー・同一マシンで常に 1 本だけ」を単一のグローバルロックで保証し、
既存の worktree-local flock は同じ変更で削除する。**

1. `scripts/global-test-lock.sh` (source されるライブラリ) と
   `scripts/with-global-test-lock.sh` (コマンドラッパ) を新設する。
2. ロックは **ブロッキング取得**。待っている間だけ 30 秒ごとに heartbeat を stderr に出す
   (LLM エージェントが「ハングした」と誤判断してプロセスを kill する事故を防ぐ)。
   heartbeat は**保持者の身元 (pid / 開始時刻 / lane / worktree)** を必ず含める。
3. **owner 再入ガード**で、ロック保持プロセスの子孫から再度呼ばれても deadlock しない。
4. ロック fd をテスト実行コマンドに**継承させない** (orphan 化した子が fd を握り続けて
   ロックが永久に解放されない事故を防ぐ。現 `run-test.sh` が `9>&-` で対処している問題と同種)。
   ただし「fd を子へ渡さない」と「コマンド実行中もロックを保持する」を両立させるには、
   **fd 7 を保持したままの親シェルが必要**である。したがって
   **ロック配下では `exec` を使わない** — 親が `"$@" 7>&-` で子を起動し、
   終了を待って終了コードをそのまま返す (`set -e` 下でも取りこぼさない制御構造にする)。
   `exec cmd 7>&-` はシェル自身を置換して fd 7 を閉じるため、テスト開始と同時に
   ロックが解放されてしまう (排他が成立しない)。これを設計の不変条件として固定する。
5. **レーンは専用プロセスグループで起動し、シグナル受信時もロックは
   そのプロセスグループが空になるまで保持する**。子は fd 7 を持たないため、親が先に死ぬとロックだけ解放されて
   **旧レーンの残党と次のレーンが同時に走る**。しかも `pnpm` / paratest / Playwright は
   孫以下を生むため、**直接の子だけを待っても孫が孤児化する**。
   したがって管理境界をプロセスグループに引き上げる:
   - **起動形態**: ラッパは現在の monitor mode を記録した上で `set -m`
     (bash の job control ビルトイン) を有効にし、レーンを **background job として起動**して
     `$!` から PID を取り、それが**自分自身のプロセスグループのリーダーになっていること
     (PGID == PID) を検証**してから待ちに入る。検証に失敗したら
     「グループ管理境界を作れなかった」として明示エラーで停止する (黙って保証を落とさない)。
     cleanup 時に元の monitor mode を復元する。`setsid` 等の外部コマンドには依存しない
     (macOS 素の環境でも動かすため)。TTY あり / なしの両方を層 1 で検証する。
   - **INT / TERM 受信時の順序** (上限つき処理を `wait` より**前**に置くのが要点):
     (1) **プロセスグループ全体**へ同じシグナルを転送 (`kill -SIG -"$pgid"`) →
     (2) **上限つきで `kill -0 -"$pgid"` をポーリング**しグループの消滅を待つ →
     (3) 猶予を過ぎたらグループへ `SIGKILL` → (4) 直接の子を `wait` して reap →
     (5) nonce 一致を確認して sidecar を削除 → (6) fd 7 を閉じてロックを解放 →
     (7) trap 解除後、親も同シグナルで自死する。
     **先に直接の子を `wait` してはならない**: 子が INT / TERM を無視すると `wait` から戻れず、
     猶予超過の `SIGKILL` に到達できないまま**ロックを永久保持する deadlock** になる。
   - 正常終了時も、子の終了後にグループが空であることを (同じ上限つき手順で) 確認してから解放する。
   - **ロックの保持期間は「取得 〜 専用プロセスグループが空になった後」**であり、
     親の生存期間でも直接の子の終了時点でもない。
   - **保証の限界**: `set -m` が保証できるのは**専用プロセスグループに残る全プロセス**の管理であり、
     「プロセスツリー全体」ではない。子孫が自ら `setsid()` / `setpgid()` でグループを離脱すれば
     管理境界から逃れる。**離脱プロセスは SIGKILL と同様に保証外**として記録する。
     現行 4 レーン (paratest / pest + Playwright / vitest / pnpm) が自発的にグループを離脱しないことは
     実査して確認し、層 1 の検証で固定する。
   - 副作用として、レーンは端末のフォアグラウンドグループではなくなるため
     **端末からの対話入力を必要としない**ことが前提になる (4 レーンとも非対話で成立している)。
     Ctrl-C は親が受けてグループへ転送するので、利用者から見た挙動は変わらない。
6. **再入時は何も獲得しない**。nonce 一致で再入と判定した場合は、
   **fd の取得・sidecar の書き換え・owner 用 trap の登録・プロセスグループの新設を一切行わない**。
   再入した子が終了時に cleanup を走らせると外側 owner の sidecar を消してしまい、
   heartbeat の診断情報が失われる (最悪、外側 owner の解放判定を壊す)。
   再入経路は「素通りしてコマンドを実行するだけ」に徹する。
7. 4 レーン (`composer test` / `composer test:browser` / `pnpm test` / `pnpm test:packages`) を対象にする。
8. **worktree-local flock は残さず削除する** (後述)。

### aicue 固有の判断 1: スコープは「マシン全体 × UID 単位」、名前は slug 非依存の固定名

移植元は `/tmp/spirux-global-test.lock` という**マシン全体の固定名**である。aicue も
**マシン全体スコープを採る**が、名前は spirux 名も aicue 名も使わない。

- **なぜマシン全体か**: H1〜H3 の作用域が全てマシン (コンテナ) 全体だから。
  ロックの作用域は、守るべき資源の作用域と一致していなければならない。
  クローン単位 (`git rev-parse --git-common-dir` 由来のハッシュ等) に狭めると、
  同一マシン上の別クローンが H1 の kill と H2 の PostgreSQL 競合を素通りさせるため、
  対策の作用域が原因の作用域より狭くなり、H1 を「構造的に消した」と言えなくなる。
- **なぜ UID 単位か**: `kill` が実際に通るのは同一 UID のプロセスのみ = H1 の破壊半径は
  「同一 UID のマシン全体」で、それより広げても得るものがない。
  UID 接尾辞の役割は**ユーザー間の通常運用上の衝突を分離する**ことに限られる。
  悪意・事故によるパス先取りは接尾辞では防げないため、
  0700 ディレクトリの所有者・種別・mode 検証で **fail-secure に検出**する (後述)。
- **なぜ slug を名前に入れないか**: aicue は laravel-claude-template 派生であり、
  `AppNameHardcodeTest` が `scripts/` へのアプリ slug 直書きを禁じている。かつ既定 slug は `app`
  なので、slug 由来にすると派生アプリ間で `app-...lock` に化けて意図しない名前衝突を招く。
  そもそも**このロックは repo をまたいで共有されて正しい** (同一マシンの PostgreSQL と CPU は
  repo をまたいで 1 つ) ため、repo 識別子を名前に入れる動機自体がない。

→ **`/tmp/global-test-lane-<uid>.d/lock`** (ディレクトリを mode 0700 で作り、その中にロックファイルを置く)。
テンプレートから派生した全アプリが同じパスを共有し、同一ユーザーのテストレーンは常に 1 本になる。

基点を `${TMPDIR:-/tmp}` ではなく **`/tmp` に固定**する。`TMPDIR` はプロセスごとに異なりうるため、
基点に使うと同一 UID でもロックが分裂し「マシン全体」の保証が崩れる。
また、UID 接尾辞だけでは別ユーザーによる**予測可能パスの先取り**を防げないため、
`/tmp/global-test-lane-<uid>.d/` を 0700 で作成した直後に
**シンボリックリンクでないこと・ディレクトリであること・所有者が自分であること・mode が 0700 であること**
を検証し、1 つでも満たさなければ**明示エラーで停止する** (黙って排他なしに落ちるのは偽の安全になるため)。

検証スイート専用に `GLOBAL_TEST_LOCK_DIR` による基点上書きを設ける (自分自身のロックと衝突せずに
並行挙動を検証するために必須)。上書き時は stderr に警告を 1 行出し、
**lane スクリプトがこの変数を設定していないこと**を Architecture テストで deny-by-default に固定する。

> 実運用上、aicue は devcontainer 1 コンテナ = 1 クローン + その worktree 群なので、
> 「マシン全体 × UID」は事実上「このクローンの全 worktree」と一致する。
> マシン全体を選ぶコストはゼロで、bare-metal / 共有ホストのケースだけを追加で守る。

### aicue 固有の判断 2: worktree-local flock を残さない

移植元の boundary は「含まない: worktree-local flock (各 lane feature 側)」= 二重ロックを残す形だが、
aicue では**削除する**。グローバルロックのスコープ (同一 UID のマシン全体) は
worktree-local ロックのスコープ (単一 worktree) を**厳密に包含**するため、内側のロックは
1 つも新しい事象を防がない。残せば AGENTS.md 思考原則 3「後方互換の並走を残さない」に反し、
かつ有害な `flock -n` (H4) をそのまま温存することになる。

ただし削除が安全なのは「**公式 entrypoint を全て確実に包めている場合**」に限る。
そこで検証スイートに **lane inventory の deny-by-default 検査**を入れる:
`composer.json` / `package.json` のテストレーン相当スクリプトを機械的に列挙し、
グローバルロックを経由しないものが 1 つでもあれば fail させる。
新しいテストレーンが追加されたら検証が落ちて気づける。

テンプレート正典との意図的な差分として `docs/template-divergence.md` に記録する。

### aicue 固有の判断 3: heartbeat は「待機中のみ」+ 保持者の身元を出す

移植元は 30 秒 heartbeat を要件としている。その目的は「無出力の待機をエージェントがハングと誤認するのを防ぐ」
ことであり、**ロック保持中はテストランナー自身が出力する**ので heartbeat は不要である。
待機中のみに限定すると、非競合時 (CI や単独実行) の出力は完全に 0 行になり、CI ログを汚さない。

無期限ブロッキングを採る以上、**「詰まっている」と「壊れて永久に待っている」の切り分け**が
できなければならない。そこでロック取得直後に sidecar (`<lock>.owner`) へ
`nonce / owner pid / 取得時刻 / lane 名 / worktree パス` を書き、解放時に削除する。
待機側の heartbeat はこれを読んで
`waiting 90s for global test lane lock — held by pid 1234 (composer test:browser, .../tasks/T101) since 23:41:02`
の形で出す。sidecar の役割は正確には「**排他の正本ではないが、再入判定の正本**」である (判断 4)。
排他そのものの正本は flock 一点に保つ。sidecar には次の不変条件を課す:
同一ディレクトリ内の一時ファイルへ書いてから `mv` する**アトミック書き込み**、
読み取り時の**所有者・パーミッション検証**、そして**自分の nonce と一致するときだけ削除**。
手動復旧手順は `docs/testing-browser.md` の runbook 節に書く。

**保証境界**: trap が走る INT / TERM / 正常終了については上記を保証する。
**SIGKILL・親プロセスのクラッシュ・コンテナ強制停止では trap は走らない**ため、
子孫が残りうるし sidecar も残りうる — これは**保証外**と明記する。
この場合に**防げるのは 2 つだけ**である:
(a) **ロックリーク** — 排他の正本は flock 一点なので、プロセス消滅時に OS が fd を閉じて
ロックは必ず解放される。**残留 sidecar は次の取得者を一切ブロックせず、アトミックに上書きされる**
(sidecar は排他の正本ではないため)。
(b) **stale nonce による誤再入** — 殺された owner の nonce は新 sidecar と一致しないため、
生き残った子孫は再入できない。
一方で**残存子孫と次レーンの併走は防げない**。ここは保証しない。これらを層 1 の検証対象に含める。

### aicue 固有の判断 4: 再入ガードは「nonce 一致」で成立させる

単なる env フラグでの再入許可は、「ロックを実際には保持していない子孫」が素通りする穴になる
(保持者が終了した後も背景化した子孫は env を持ち続ける)。
そこで判断 3 の sidecar を再利用し、**再入が許されるのは
「env で受け取った nonce が、現に存在する sidecar の nonce と一致するとき」だけ**とする。
nonce は取得のたびに新規生成され、sidecar は保持中しか存在しない。
保持者が解放すれば sidecar は消え、次の保持者は別 nonce を書くため、
生き残った子孫の stale nonce は一致せず、正しくブロッキング取得に回る。
PID 意味論に依存しないので PID 再利用の穴もない。

## 対象 lane の棚卸し (composer.json / package.json を実査)

| lane | コマンド | 掴む資源 | 対象 | 理由 |
|---|---|---|---|---|
| Feature/Unit/Architecture | `composer test` | pgsql サーバ (paratest 4 本 × migrate:fresh)、CPU | **対象** | H2 / H3 / H4 |
| Browser | `composer test:browser` | pgsql サーバ、実ブラウザ 2 engine、in-process HTTP サーバ、**マシン全体の playwright 掃除** | **対象** | H1 / H2 / H3 |
| JS (root) | `pnpm test` | CPU (`maxWorkers: "50%"`)、jsdom メモリ。DB / 固定ポートは掴まない | **対象 (方針判断)** | H3。DB は掴まないが、Browser lane と同時に走ると CPU 枯渇でタイムアウト由来の偽赤を作る |
| JS (packages) | `pnpm test:packages` | CPU のみ (loopback は ephemeral port、fs は `mkdtemp` で hermetic — 実査済み) | **対象 (方針判断)** | H3。かつ**現在ロックが一切ない**唯一のレーンで、ここだけ穴を残す理由がない |

- `composer phpstan` / `pnpm lint` / `pnpm typecheck` / `pnpm build` は**対象外**
  (テスト DB もブラウザも掴まない。直列化しても得るものがない)。
- `packages/cli` は vitest 1 本のみ (`build` / `typecheck` / `lint` はテストレーンではない)。

### JS 2 レーンを含める判断の成功条件と見直し条件

JS レーンの包含は**安全性ではなく方針判断**である (DB もポートも掴まないため、
含めなくても壊れはしない)。採る理由は 2 つ:

1. H3 — 軽い JS レーンでも Browser lane と重なれば CPU を奪い、偽赤を作りうる。
2. 「どのレーンなら同時に走らせてよいか」をエージェントに判断させない。
   ルールが 1 つ (テストレーンは常に 1 本) であること自体に運用価値がある。

- **成功条件**: 4 レーンをコミットゲートとして通す 1 巡の総時間が、直列化前の
  「衝突込みの実効時間 (リトライ・偽赤の再走を含む)」を上回らないこと。
- **見直し条件**: 待ち時間が支配的になった (JS レーンが Browser lane 2 本の後ろで
  恒常的に数分待つ) と観測されたら、**lock class の分離** (DB/ブラウザ資源クラスと
  CPU のみクラス) を再検討する。今それを作らないのは「今必要なものだけ作る」に従うため。

## bug-hunt の扱い: **対象外** (明示的判断)

`scripts/bug-hunt-shard.sh` の隔離基盤 (DB `bug_hunt(_1..8)` / `:8010+N` / `env -i` の用途別 wrapper) は
グローバルロックの**対象にしない**。

1. **資源が構造的に交わらない**。DB 名前空間は `bug_hunt(_[1-8])?` の正規表現で hard-deny ガードされ、
   テストレーンの `app_test_<hash>` とは重ならない。ポートは `:8010..:8018` の固定割当で、
   テストレーンは in-process サーバ / ephemeral port しか使わない。
2. **bug-hunt 自身が排他機構を持つ**。shard i ↔ (DB, port, レポート dir) の 1:1 割当と
   orchestrator/worker の default-deny ガード、`.claude/bug-hunt.lock` の flock が並列安全性を担う。
3. **グローバルロックを被せると 8 並列が 1 直列に潰れ、機能の存在意義が消える**
   (AGENTS.md §bug-hunt が「意図的に隔離された並列実行基盤」と明記している)。
   bug-hunt の 1 run は数十分オーダーで、その間コミットゲートを全面停止させる副作用も釣り合わない。
4. **抜け穴は無い**: bug-hunt worktree の中で `composer test` を打てば、それは通常どおり
   `run-test.sh` 経由でグローバルロックを取る。除外するのは bug-hunt の shard 走行のみ。

### bug-hunt 併走時の残余リスク (受容する / 1 つだけ検証で固定する)

- **bug-hunt 併走問題は全体として残余リスクとして受容する**。bug-hunt はロック規約に
  参加しないため、非干渉も性能も保証されない:
  - Feature / JS レーンとの CPU / PostgreSQL 競合による性能劣化。
  - Browser lane との browser 回収の相互干渉 (方向 2)。
  受容できる根拠は**失敗モードが偽赤 (テストがエラーになる) であり、偽グリーンではない**こと。
  緑を赤と誤報告するのは aicue の非交渉基準に反するが、bug-hunt を同時に起動するのは
  エージェントの明示的な操作であり、pre-flight guard が典型ケースを捕まえた上で
  なお併走させた場合に限られる。**沈黙して誤った緑を出す経路は無い**。
- **ブラウザ回収の相互干渉 (方向ごとに扱いを分ける)**:
  Browser lane は `pgrep -f "playwright/cli.js run-server"` (pest-plugin-browser 同梱 Playwright) を、
  bug-hunt は `playwright-cli kill-all` (`@playwright/cli`) を撃つ。
  **「プロセス名パターンが互いにマッチしないこと」を検証しても非干渉の証明にはならない**
  (`kill-all` が何を列挙して落とすかは、こちらの `pgrep` パターンとは独立に決まる。
  当該環境に `@playwright/cli` は未導入で、実装契約をこちらから確認できない)。
  したがって方向ごとに扱いを分ける:
  - **方向 1 (Browser lane → bug-hunt)**: こちらが制御できる。
    `cleanup_orphan_playwright()` のパターンを
    **pest-plugin-browser 同梱 Playwright の install パスに固定**する
    (`node_modules/playwright*/cli.js run-server` に相当する形へ限定し、
    `@playwright/cli` のプロセスに構造的にマッチしないようにする)。
    検証スイートで「`@playwright/cli` 相当の cmdline がこのパターンに掛からないこと」を
    負のコントロールとして固定する。
  - **方向 2 (bug-hunt → Browser lane)**: **非干渉は保証しない (保証を撤回する)**。
    保証するには両側が参加する共有プロトコル (bug-hunt が活動期間中に同ロックを
    共有モードで保持し、Browser lane が排他取得する) が必要で、
    それは bug-hunt 側 — orchestrator と N 体の subagent worker にまたがる
    security-sensitive なスクリプト — の改造を意味する。
    本件のスコープに対して過大であり、**保証とスコープを一致させるために保証の側を降ろす**。
    残るのは **best-effort の pre-flight guard** である:
    `run-browser-test.sh` の起動時に bughunt ポート (`127.0.0.1:8010..8018`) への
    接続可否を調べ、繋がるものがあれば明確な指示つきで fail-fast する。
    - **TOCTOU がある**ことを設計として明記する。「起動時点で bug-hunt が既に走っている」
      という**実際に起きる頻度の高いケース**だけを捕まえる。
      Browser lane 開始後に bug-hunt が起動する経路、および bug-hunt が
      listen していない起動フェーズにいる経路は捕まえられない。
    - **検知手段は bash の `/dev/tcp` のみ**を使う (`ss` / `lsof` / `netstat` の
      可用性と出力形式の差に依存しない)。bug-hunt は `127.0.0.1:801N` に明示 bind するため
      **IPv4 loopback のみ**を見れば十分で、IPv6 は対象外とする。
      `/dev/tcp` が使えないシェル環境では**検査を skip して続行**する
      (guard であって保証ではないので、ここで止めない)。
    - 将来 bug-hunt 側に共有 interlock を入れる選択肢は残す。採らない理由
      (スコープ過大 / 失敗モードが偽赤に留まる) を記録として残す。
- **(スコープ外の観測)** bug-hunt 自身の `.claude/bug-hunt.lock` は worktree-local なので、
  別 worktree からの bug-hunt 同時起動は `playwright-cli kill-all` で相互破壊しうる。
  本設計の対象外だが、同種の課題として `docs/template-divergence.md` に観測を残す。

## CI の扱い: **特別扱いしない** (ロックは掛かるが常に無競合)

`.github/workflows/ci.yml` は `php` / `frontend` の 2 job で、それぞれ独立した ubuntu-latest コンテナ、
1 コンテナ 1 テスト実行、worktree なし、`/tmp` は新品。したがってロックは**必ず即座に取得でき、
実質 no-op** である。

- **`CI=true` によるバイパス分岐を作らない**。バイパスは「正しさが最も要求される場所に、
  ローカルでは一度も実行されないコードパス」を増やす。単一経路にしておけば、
  CI が検証しているものと開発者が走らせるものが同一になる。
- 判断 3 (heartbeat は待機中のみ) により、CI では heartbeat が 1 行も出ない。
- コストは `flock` システムコール 1 回。有害性なし。

## flock(1) 不在環境 (素の macOS) の方針: **既存方針を踏襲 (排他なしで実行)**

既存 3 スクリプトはいずれも `command -v flock` で分岐し、不在なら排他せず実行する。
グローバルロックも同じにする。ただし現状は完全に無言で skip するため、
**stderr に 1 行の警告を出す**ようにする (挙動は変えず、保護が効いていないことを可視化する)。
aicue の一次開発環境は devcontainer (util-linux 2.41 の flock を確認済み) と CI (ubuntu) であり、
どちらでも排他は有効。

## 期待効果

- **使命への貢献**: aicue の使命 (SOP → シナリオ → ナビ撮影 → 動画) を実現する速度は、
  複数 worktree の並行実装が壊れずに回るかに直結する。テストレーンの相互破壊と偽赤は、
  エージェントに「存在しないバグの調査」をさせて実装スループットを直接削る。
- **H1 が構造的に消える (本ロック規約に参加するテストレーン間に限る)**:
  掃除の破壊半径 (同一 UID のマシン全体) とロックの作用域が一致するため、
  Browser lane 同士が同時に存在しなくなる。
  ただし同一 UID の**別ツール・本規約を未移植のリポジトリ・bug-hunt** は同じロックを取らないため、
  それらからの Playwright プロセスへの干渉は防げない (best-effort guard のみ)。
- **H2 / H3 由来の flake が、テストレーン同士の競合分については解消する**
  (bug-hunt 併走・他ユーザー・flock 不在ホストは残余リスクとして残る)。
- **H4 が消える**: 後発レーンは「失敗」ではなく「待機」になる。エージェントのリトライループと
  レーン迂回がなくなる。
- ロック機構が 3 種類 (worktree-local test.lock / vitest workspace lock / なし) から **1 種類**になる。

> 主張の限界を明示する: 本設計が保証するのは
> 「**本ロック規約を採用したテストレーンが、同一 UID のマシン上で同時に 2 本走らない**」
> ことだけである。「flake がゼロになる」「赤は必ず本物」とは主張しない。
> 規約に参加しないプロセス (未移植リポジトリ、手打ちの `vendor/bin/pest`、bug-hunt、他ツール) は対象外。

## 実装方針 (概要)

| 変更対象 | 変更内容 |
|---|---|
| `scripts/global-test-lock.sh` (新規) | source されるライブラリ。lock path 導出 → nonce 再入判定 → ブロッキング取得 (待機中のみ heartbeat) → sidecar 書込 → EXIT/INT/TERM trap で解放。fd 7 を使う (既存 lane の fd 9 と衝突させない)。実装不変条件: `set -euo pipefail` 前提 / 厳格 quoting / **cleanup の冪等化** (INT・TERM 処理後の EXIT で二重実行しても安全。sidecar は自分の nonce と一致するときだけ削除) / INT・TERM は **trap を解除してから同シグナルで自死**し終了コード契約 (128+signo) を守る / **`exec` 禁止 (fd 7 保持)** |
| `scripts/with-global-test-lock.sh` (新規) | 上記を source し、**`exec` せず** `"$@" 7>&-` で子を起動して待ち、終了コードを引き継ぐ薄いラッパ。ラップ用のシェルスクリプトを持たない `pnpm test:packages` 用 |
| `scripts/run-test.sh` | worktree-local flock ブロック (L16-25) を削除 → `source scripts/global-test-lock.sh`。実行行の `9>&-` を `7>&-` に |
| `scripts/run-browser-test.sh` | 同上 (L43-52 削除)。pest 実行の `9>&-` を `7>&-` に |
| `scripts/run-vitest.sh` | workspace-hash flock ブロック (L13-27) を削除 → source。**既存の `exec` を廃止**し `pnpm exec vitest run "$@" 7>&-` + 終了コード引き継ぎ |
| `package.json` | `test:packages` を `with-global-test-lock.sh` 経由に |
| `docs/testing-browser.md` / `docs/worktree-isolation-strategy.md` / `scripts/README.md` | ロックの説明を更新 (worktree-local flock の記述は削除) + 手動復旧 runbook |
| `docs/template-divergence.md` | 正典 boundary との差分 (worktree-local flock を残さない / 固定名の付け方 / heartbeat は待機中のみ / 再入は nonce) を記録 |
| `scripts/verify-global-test-lock.sh` (新規・恒久) | 並行挙動の検証スイート (ブロッキング / heartbeat / 再入 / fd 非継承 / 解放 / 終了コード / flock 不在)。`scripts/README.md` の台帳に追記する |
| `tests/Architecture/GlobalTestLockInventoryTest.php` (新規) | 構造的不変条件を恒久固定する Pest Architecture テスト (下記) |
| `.github/workflows/ci.yml` | `php` job に `bash scripts/verify-global-test-lock.sh` のステップを 1 つ追加 (並行挙動の恒久ゲート) |

## 検証の恒久化とテストファースト方針

AGENTS.md 禁止事項 1 は「不変条件は対応する Architecture/Feature テストへの登録まで含めて実装済み」
と定めている。`devnotes/` は恒久的な回帰境界ではない (消えても誰も気づけない) ため、
検証は**恒久資産として 2 層**に置く。

**層 1 — 並行挙動の検証スイート `scripts/verify-global-test-lock.sh` (恒久・`scripts/README.md` 台帳に登録)**
ブロッキング待機・heartbeat・再入・fd 非継承・保持期間・解放・終了コード・flock 不在に加え、
**シグナル契約**を検証する:
- INT / TERM を親に送ったとき、**専用プロセスグループに残る全プロセスが消えるまで
  第三のレーンがロックを取得できない**こと
  (直接の子が**孫を生んで先に終了する**ケースを使い、孫の消滅まで待つことを確認する)
- **INT / TERM を無視する直接子と孫**を使い、猶予超過後に強制終了されて
  第三のレーンがロックを取得できること (deadlock しないこと)
- 終了後に**背景に子孫プロセスが残らない**こと
- レーンが**専用プロセスグループのリーダー (PGID == PID) になっている**こと、
  および現行 4 レーンが**自発的にグループを離脱しない**こと
- **TTY あり / なしの両方**で上記が成立すること (monitor mode の復元を含む)
- 親の終了コードが 128+signo になること
- **再入した子の終了後も外側 owner の sidecar が維持される**こと (再入経路が cleanup しない)
- **残留 sidecar (SIGKILL 相当) が次の取得者をブロックせず、上書きされる**こと
- 殺された owner の nonce を持つ子孫が**再入を許されない**こと

いずれも**プロセスを実際に走らせないと観測できない**性質である。
自分自身の実ロックと衝突しないよう、スイートは常に `GLOBAL_TEST_LOCK_DIR` を `mktemp -d` に向けて走る。
CI (`php` job) に 1 ステップ追加して恒久ゲート化する。

**層 2 — 構造的不変条件の Architecture テスト `tests/Architecture/GlobalTestLockInventoryTest.php` (Pest)**
ファイル読み取りだけで判定できる不変条件を deny-by-default で固定する:
1. `composer.json` / `package.json` のテストレーン相当スクリプトが**全て**グローバルロックを経由すること
   (新レーン追加時に落ちる)
2. 旧 worktree-local flock (`storage/framework/testing/test.lock` / `app-vitest-*.lock`) が
   どのスクリプトにも残っていないこと
3. `scripts/verify-global-test-lock.sh` が存在し実行可能であること
4. lane スクリプトが `GLOBAL_TEST_LOCK_DIR` を設定していないこと (自己バイパス禁止)
5. ロック配下で `exec` を使っていないこと (fd 7 保持の不変条件)

> **層 2 が層 1 を実行してはならない**: Architecture テストは `composer test` の内側
> = グローバルロック保持中に走る。そこから並行挙動スイートを起動すると自分自身と競合する。
> 層の分離はこの理由により非交渉とする。

**テストファースト**: 層 1・層 2 を先に書き、**未変更ツリーに対して実行して fail を観測してから**
実装に入る (AGENTS.md 思考原則 5)。未変更ツリーで確実に落ちる負のコントロール:

- 別 worktree からの 2 本目の lane が**待機せず即エラーになる** (H4 / cross-worktree 排他ゼロ)
- 再入時に deadlock する / 再入ガードが存在しない
- ロック fd がテスト実行コマンドに継承される
- `exec` によりコマンド実行中にロックが解放されている (run-vitest.sh)
- 待機中に heartbeat が出ない
- `pnpm test:packages` がロックを一切経由しない (lane inventory)
- bughunt ポート listen 中でも Browser lane が起動できてしまう
- INT / TERM で親が先に死に、子が生きたままロックが解放される

## 制約・前提

- bash + `flock(1)` + `shasum` 相当のみに依存する (PHP / Laravel boot / git を要求しない。
  `pnpm test` レーンは PHP が無くても動く必要がある)。
- **既存のドメインテスト・DB テストには手を入れない** (新規 Architecture テストの追加は行う。
  これは禁止事項 1 への正しい対応であり、上記制約と矛盾しない)。
  DB 名決定ロジック (`TestDatabaseEnv`) も変更しない。
- 検証は Pest ではなく shell の検証スイートで行う (対象がシェルスクリプトの並行挙動であり、
  PHP プロセス内からは fd 継承・ブロッキング待機・シグナルを正しく観測できない)。
  AGENTS.md 禁止事項 1 の「テストなしの実装完了」は本検証スイートで満たす。
- 本機能はテンプレート昇格対象。aicue 側の実装は slug 非依存を保ち、テンプレートへ還流可能な形にする。

## スコープ外

- bug-hunt 基盤 (`scripts/bug-hunt-shard.sh` 等) への変更 (自身の worktree-local lock、
  `playwright-cli kill-all` の対象絞り込みを含む)。
- `composer phpstan` / `pnpm lint` / `pnpm typecheck` / `pnpm build` のラップ。
- CI ワークフローの構造変更 (job 分割・並列化など。検証スイート 1 ステップの追加のみ行う)。
- テスト DB 命名・provision ロジック (`TestDatabaseEnv` / `ensure-test-db.php` / `drop-test-db.php`)。
- テストレーン以外 (bug-hunt shard 走行・手打ちの `vendor/bin/pest` 等) への規約適用の強制。
- **bug-hunt 併走時の非干渉保証** (best-effort guard のみ提供し、保証はしない)。
  bug-hunt 側への共有 interlock 追加は将来の選択肢として記録するに留める。
- ロック待ち時間の上限設定・タイムアウト (待つことが目的なので上限を設けない。
  代わりに sidecar + heartbeat で切り分け可能にする)。
- lock class の分離 (DB/ブラウザクラス vs CPU クラス)。見直し条件に到達したら再検討する。
- c2c 台帳への `status_reported` 追記 (実装・push 完了後の別作業)。


---

## 関連する現行コード (変更対象の実物)

### scripts/run-test.sh
```
#!/usr/bin/env bash
#
# scripts/run-test.sh — composer test の pgsql 経路。同一 worktree の test 二重起動を
# flock で直列化し、ensure (base DB 冪等 CREATE) → artisan test --parallel を実行する。
#
# 同一 worktree で test を二重起動すると、RefreshDatabase の migrate:fresh と
# paratest の per-worker DB が衝突し、後発側が不可解な failure を吐くため排他する。
#
# lock は worktree-local (storage/framework/testing/ は worktree ごとに別実体) なので
# 別 worktree の test は止めない (base DB 名 hash が異なり競合しない)。
# flock(1) が無い環境 (素の macOS 等) では排他なしで実行する (devcontainer/CI では排他あり)。

set -euo pipefail
cd "$(dirname "$0")/.."

LOCK_FILE="storage/framework/testing/test.lock"   # worktree-local
mkdir -p "$(dirname "$LOCK_FILE")"
if command -v flock >/dev/null 2>&1; then
    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
        echo "ERROR: another composer test is running in this worktree; refusing to start" >&2
        echo "       lock file: $LOCK_FILE" >&2
        exit 1
    fi
fi

php artisan config:clear --ansi

# worktree 固有の base テスト DB (<slug>_test_<worktree-hash>) を冪等に用意する。
# DB 名の安全検証 (dev DB hard-deny + allowlist) は tests/bootstrap.php の
# 単一点ガード + ensure-test-db.php 内の二重防御が担う。
php scripts/ci/ensure-test-db.php

# lock fd (9) を artisan test / paratest worker / そこから spawn される子プロセスに
# 継承させない。orphan 化した子が fd 9 を握り続けると lock が解放されず次回の
# flock -n 9 が継続的に拒否されるため、テスト実行コマンドへ渡す瞬間に 9>&- で閉じる
# (親シェルの fd 9 = lock は保持されたまま)。
php artisan test --parallel --processes=4 "$@" 9>&-

```

### scripts/run-browser-test.sh
```
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

```

### scripts/run-vitest.sh
```
#!/usr/bin/env bash
#
# scripts/run-vitest.sh — workspace 単位で vitest を排他実行する。
#
# 同一 workspace で vitest を二重起動すると .vite/ cache と coverage 出力先が
# 同時に書かれて壊れることがある。flock(1) で workspace 派生キーの lock を握り、
# 既に走っている場合は待たずに exit 1 で即終了する。
#
# 注意: lock は workspace 配下ではなく ${TMPDIR:-/tmp} 配下に置く(run-test.sh と同じ理由)。

set -euo pipefail

WORKSPACE="$(cd "$(dirname "$0")/.." && pwd)"
LOCK_DIR="${TMPDIR:-/tmp}"
LOCK_KEY="$(printf '%s' "$WORKSPACE" | shasum -a 256 | cut -c1-16)"
LOCK_FILE="$LOCK_DIR/app-vitest-${LOCK_KEY}.lock"

# flock(1) が無い環境 (素の macOS 等) では排他なしで実行する (devcontainer/Linux では排他あり)
if command -v flock >/dev/null 2>&1; then
    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
        echo "ERROR: vitest is already running in this workspace." >&2
        echo "       workspace: $WORKSPACE" >&2
        echo "       lock file: $LOCK_FILE" >&2
        exit 1
    fi
fi

cd "$WORKSPACE"
exec pnpm exec vitest run "$@"

```

### composer.json (scripts)
```
{
  "setup": [
    "composer install",
    "@php -r \"file_exists('.env') || copy('.env.example', '.env');\"",
    "@php artisan key:generate",
    "@php artisan migrate --force",
    "pnpm install",
    "pnpm run build"
  ],
  "dev": [
    "Composer\\Config::disableProcessTimeout",
    "@php -r \"file_exists('.env') && copy('.env', '.env.local');\"",
    "npx mprocs"
  ],
  "test": [
    "Composer\\Config::disableProcessTimeout",
    "bash scripts/run-test.sh"
  ],
  "test:browser": [
    "Composer\\Config::disableProcessTimeout",
    "bash scripts/run-browser-test.sh"
  ],
  "post-autoload-dump": [
    "Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
    "@php artisan package:discover --ansi",
    "@php artisan filament:upgrade"
  ],
  "post-update-cmd": [
    "@php artisan vendor:publish --tag=laravel-assets --ansi --force"
  ],
  "post-root-package-install": [
    "@php -r \"file_exists('.env') || copy('.env.example', '.env');\""
  ],
  "post-create-project-cmd": [
    "@php artisan key:generate --ansi",
    "@php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\"",
    "@php artisan migrate --graceful --ansi"
  ],
  "pre-package-uninstall": [
    "Illuminate\\Foundation\\ComposerScripts::prePackageUninstall"
  ],
  "phpstan": [
    "bash scripts/phpstan.sh analyse --memory-limit=2G"
  ],
  "fix": [
    "vendor/bin/pint"
  ]
}
```

### package.json (scripts)
```
{
  "audit:gate": "bash scripts/audit-gate.sh",
  "build": "vite build",
  "dev": "NODE_OPTIONS='--max-old-space-size=2048' vite",
  "lint": "eslint resources/js",
  "lint:fix": "eslint resources/js --fix",
  "typecheck": "tsc --noEmit",
  "test": "bash scripts/run-vitest.sh",
  "test:ui": "vitest --ui",
  "test:coverage": "vitest --coverage",
  "test:watch": "vitest --watch",
  "build:packages": "pnpm -F \"./packages/*\" build",
  "test:packages": "pnpm -F \"./packages/*\" test"
}
```

### .github/workflows/ci.yml
```
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  php:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.4"
          coverage: none
      - name: Install composer dependencies
        run: composer install --prefer-dist --no-progress --no-interaction
      - name: Prepare environment
        # passport:keys: OAuth/MCP テストは Passport 鍵 (storage/oauth-*.key) を要する。
        # CI には鍵が無いため生成する (未生成だと "Invalid key supplied" で fail)
        run: |
          cp .env.example .env
          php artisan key:generate
          php artisan passport:keys --force
      # レンダー smoke テスト (施策 4) の前提。Dockerfile (dev/bughunt) と別に CI runner にも
      # ffmpeg/ffprobe と字幕フォントを導入し、存在・フォント解決を fail-fast 検証する (層 1)。
      - name: Provision ffmpeg for render smoke
        run: |
          sudo apt-get update
          # fontconfig を明示 (fc-match の依存。ランナー差異で未導入の可能性をゼロにする。design-review R1)
          sudo apt-get install -y ffmpeg fonts-noto-cjk fontconfig
          ffmpeg -version
          ffprobe -version
          # fc-match の終了コードだけでなく、解決 family が Noto CJK であることを機械的に判定
          # (代替フォントへのフォールバックを検出する。-f '%{family}' で family のみ抽出しノイズ耐性を上げる)
          fc-match -f '%{family}\n' "Noto Sans CJK JP" | grep -qi 'Noto Sans CJK' \
            || { echo "::error::Noto Sans CJK JP did not resolve to a Noto CJK family"; exit 1; }
      - name: Pint (code style)
        run: vendor/bin/pint --test
      - name: PHPStan
        run: composer phpstan
      - name: Pest
        run: composer test

  frontend:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: pnpm/action-setup@v4
        with:
          version: 11.3.0
      - uses: actions/setup-node@v4
        with:
          node-version: 22
          cache: pnpm
      - name: Install dependencies
        run: pnpm install --frozen-lockfile
      - name: ESLint
        run: pnpm lint
      - name: TypeScript
        run: pnpm typecheck
      - name: Vitest
        run: pnpm test
      - name: Build
        run: pnpm build

```
