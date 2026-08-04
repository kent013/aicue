【アプリの使命 (North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
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

---

## あなたの役割

Laravel + Svelte アプリ (aicue) の**シェルスクリプト基盤の実装レビュアー**。
本 PR は TODO T099「テストレーンのグローバルロック (cross-worktree 直列化)」の実装であり、
**承認済みの詳細設計 (gpt-5.3-codex Round 5 APPROVED)** に忠実に実装されているかを検証する。

## レビュー観点 (優先順)

1. **設計との一致性**: 詳細設計 (施策 1〜10) の非交渉の契約が実装で守られているか。特に:
   - ロック保持期間 = 取得 〜 **専用プロセスグループが空になった後** (親の生存期間でも直接の子の終了時点でもない)
   - ロック配下で `exec` を使っていない (exec は fd 7 を閉じてロックを即解放する)
   - シグナル時の順序 = グループへ転送 → 猶予待ち → SIGKILL → **その後** wait (wait を先に置くと TERM 無視の子で deadlock)
   - 群生存判定に `kill -0 -$pgid` を使っていない (SIGKILL 後の zombie を生存と誤判定する)
   - 再入ガードは nonce 一致のみ (PID 再利用の穴を作らない)。再入経路は fd も trap も sidecar も獲得しない
   - EXIT trap の所有者はライブラリ 1 箇所 (lane は `global_test_lock_on_exit` で登録)
   - CI バイパス分岐 (`CI=true` で素通り) が無いこと
   - 層 2 (Architecture テスト) から層 1 (shell スイート) を実行していないこと
2. **シェルの正確性**: `set -euo pipefail` 下での挙動、quoting、`&&`/`||` と `set -e` の相互作用、
   trap の再入・冪等性、fd リダイレクトの有効範囲、サブシェルと変数スコープ、race condition。
   **実際に壊れる具体的シナリオを挙げられる指摘のみ Critical にすること**。
3. **検証の妥当性**: 層 1 (`scripts/verify-global-test-lock.sh`) の 24 ケースが
   **本当にその性質を検証しているか** (vacuous に PASS していないか。負のコントロールが機能しているか)。
   層 2 (`tests/Architecture/GlobalTestLockInventoryTest.php`) の deny-by-default が
   新レーン追加・旧実装への逆戻りを実際に検出するか。
4. **PHPStan level 10 適合性** (層 2 の PHP)。型の widen / baseline 化が無いこと。
5. **セキュリティ**: lock dir / sidecar の fail-secure 検証 (symlink / 所有者 / mode)、
   TOCTOU の扱いが設計どおり「別 UID 境界を守る」に留まり、過剰主張していないか。
6. **後方互換の並走を残していないか** (旧 worktree-local flock の完全削除)。

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
  - Critical = 設計契約の破れ / 実運用で壊れる / 偽グリーンを生む
  - Warning = 壊れうるが条件が限定的 / 保守性の実害
  - Suggestion = 好み・軽微
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する

---

## 詳細設計書 (承認済み)

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
| 6 | packages lane / coverage lane をラップ | `package.json` | 高 |
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
| `global_test_lock_on_exit <fn>` | lane 固有の後始末を **cleanup へ追加登録**する(lane が自前で `trap ... EXIT` を張ると `_gtl_cleanup` を上書きしてロックが解放されなくなるため、EXIT trap は本 API に一本化する) |

環境変数(いずれも**検証スイート専用**。lane スクリプトは設定してはならない):

| 変数 | 用途 | 既定 |
|---|---|---|
| `GLOBAL_TEST_LOCK_DIR` | ロック基点の上書き | `/tmp/global-test-lane-<uid>.d` |
| `GLOBAL_TEST_LOCK_HEARTBEAT_SECS` | heartbeat 間隔 | `30` |
| `GLOBAL_TEST_LOCK_GRACE_SECS` | シグナル後、SIGKILL に踏み切るまでの猶予 | `30` |

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
_GTL_EXIT_HOOKS=""          # lane 固有の後始末 (関数名の空白区切り)
_GTL_HEARTBEAT_SECS=30      # 検証済み値を固定 (以後 env は読まない)
_GTL_GRACE_SECS=30

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

# 検証用 env の値検証。不正値を放置すると剰余がゼロ除算になり、sleep / -ge / 算術展開が
# 失敗して **cleanup の途中でシェルが終了**し、残存グループと次のレーンが併走しうる。
# 取得時に fail-fast する (壊れた設定で保護が半分だけ効く状態を作らない)。
_gtl_validate_env() {
    # 検証済みの値は内部変数 (_GTL_HEARTBEAT_SECS / _GTL_GRACE_SECS) へ固定し、以後は
    # 環境変数を読まない。acquire 後に env を書き換えて検証を迂回する経路を塞ぐため。
    local hb="${GLOBAL_TEST_LOCK_HEARTBEAT_SECS:-30}" gr="${GLOBAL_TEST_LOCK_GRACE_SECS:-30}"
    case "${hb}" in
        ''|*[!0-9]*) _gtl_die "GLOBAL_TEST_LOCK_HEARTBEAT_SECS must be a positive integer: ${hb}" ;;
    esac
    [ "${hb}" -ge 1 ] || _gtl_die "GLOBAL_TEST_LOCK_HEARTBEAT_SECS must be >= 1: ${hb}"
    case "${gr}" in
        ''|*[!0-9]*) _gtl_die "GLOBAL_TEST_LOCK_GRACE_SECS must be a non-negative integer: ${gr}" ;;
    esac
    if [ -n "${GLOBAL_TEST_LOCK_DIR:-}" ]; then
        case "${GLOBAL_TEST_LOCK_DIR}" in
            /*) : ;;
            *) _gtl_die "GLOBAL_TEST_LOCK_DIR must be an absolute path: ${GLOBAL_TEST_LOCK_DIR}" ;;
        esac
    fi
    _GTL_HEARTBEAT_SECS="${hb}"
    _GTL_GRACE_SECS="${gr}"
}

_gtl_new_nonce() {
    # 外部コマンドに依存しない一意トークン (pid + 高分解能時刻 + 乱数)。
    printf '%s-%s-%s%s\n' "$$" "${EPOCHREALTIME:-$(date +%s)}" "${RANDOM}" "${RANDOM}"
}

# sidecar の 1 行目 = nonce。所有者検証つきで読む (他ユーザーが置いた偽 sidecar を信じない)。
_gtl_sidecar_nonce() {
    local f="$1" owner line=""
    [ -L "${f}" ] && return 1          # symlink は読まない (fail-secure)
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
    [ -L "${_GTL_SIDECAR}" ] && _gtl_die "sidecar is a symlink (refusing): ${_GTL_SIDECAR}"
    # 保証範囲: これらの型検証が防ぐのは **別 UID 境界** (0700 dir + 所有者検証との併せ技) であって、
    # 「symlink 攻撃の完全防止」ではない。rm -f 後のリダイレクトには同一 UID プロセスとの
    # TOCTOU が残る。同一 UID は既に自分自身と同じ権限を持つため、ここを完全に閉じる意味はない。
    rm -f "${tmp}"                     # 既存 (symlink 含む) を消してから書く
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
        sleep "${_GTL_HEARTBEAT_SECS}"
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

# グループの消滅を待つ。猶予超過でグループへ SIGKILL を送り、**その後は上限を設けず**
# 空になるまで待ち続ける (契約: グループが空になるまでロックを離さない)。
# **必ず wait より前に呼ぶこと**: 先に wait すると、子が INT/TERM を無視した瞬間に
# wait から戻れず SIGKILL に到達できないまま「ロックを永久保持する deadlock」になる。
_gtl_wait_group_gone() {
    local pgid="$1" grace="${_GTL_GRACE_SECS}" waited=0 nagged=0

    # 第 1 段: 猶予内に自発終了するのを待つ
    while _gtl_group_alive "${pgid}"; do
        if [ "${waited}" -ge "${grace}" ]; then
            _gtl_warn "grace exceeded; SIGKILL process group ${pgid}"
            kill -KILL -"${pgid}" 2>/dev/null || true
            break
        fi
        sleep 1
        waited=$(( waited + 1 ))
    done

    # 第 2 段: SIGKILL 後も**空になるまで待ち続ける** (上限を設けない)。
    #
    # ここで諦めて戻ると fd 7 が閉じ、「グループが空になるまで保持」という
    # 非交渉の契約が破れる (残党と次のレーンが併走する)。SIGKILL を生き延びるのは
    # 割り込み不可能な待ち (D state = stuck IO) だけであり、その状況でロックを
    # 手放すより保持し続ける方が安全。ハングと区別できるよう heartbeat 間隔で
    # 残存 pid つきの警告を出し続ける。
    waited=0
    while _gtl_group_alive "${pgid}"; do
        sleep 1
        waited=$(( waited + 1 ))
        nagged=$(( waited % _GTL_HEARTBEAT_SECS ))
        if [ "${nagged}" -eq 0 ]; then
            _gtl_warn "still holding the lock: process group ${pgid} has survivors after SIGKILL (${waited}s): $(
                ps -A -o pgid= -o pid= -o stat= 2>/dev/null \
                    | awk -v g="${pgid}" '{sub(/^ +/, "")} $1 == g && $3 !~ /^Z/ { printf "%s ", $2 }'
            )"
        fi
    done
}

# 稼働中の専用プロセスグループを収束させる (シグナル経路と cleanup 経路の共通実装)。
# **ロック解放より必ず先に呼ぶこと**: 子を起動した後に内部エラー (_gtl_die) や
# set -e による中断で EXIT へ抜けると、稼働中の子・孫を残したまま fd 7 が閉じ、
# 残党と次のレーンが併走して保持期間契約が破れる。
# 冪等: _GTL_CHILD_PGID が空なら何もしない (二重処理を避ける)。
_gtl_reap_active_group() {
    local sig="${1:-TERM}"
    [ -n "${_GTL_CHILD_PGID}" ] || return 0
    kill -"${sig}" -"${_GTL_CHILD_PGID}" 2>/dev/null || true
    _gtl_wait_group_gone "${_GTL_CHILD_PGID}"      # 猶予超過で SIGKILL → 以後は空になるまで待つ
    if [ -n "${_GTL_CHILD_PID}" ]; then
        wait "${_GTL_CHILD_PID}" 2>/dev/null || true
    fi
    _GTL_CHILD_PID=""
    _GTL_CHILD_PGID=""
}

# 冪等。INT/TERM ハンドラ実行後に EXIT trap が再度走っても安全。
_gtl_cleanup() {
    [ "${_GTL_CLEANED}" = "1" ] && return 0
    _GTL_CLEANED=1
    # (1) まず稼働中のプロセスグループを収束させる (異常終了経路の残党を残さない)
    _gtl_reap_active_group TERM
    # (2) lane 固有の後始末を **ロックを保持したまま** 走らせる
    #     (Browser lane の orphan playwright 掃除は、レーン本体が消えた後・
    #      次のレーンが入る前に行う必要があるため、この順序が正しい)
    local hook
    for hook in ${_GTL_EXIT_HOOKS}; do
        "${hook}" || _gtl_warn "exit hook failed (ignored): ${hook}"
    done
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

# 契約順序: (1) グループへ転送 → (2) 猶予内の消滅待ち → (3) 猶予超過なら SIGKILL し、
#           以後は **上限なし** で空になるまで待つ →
#           (4) 直接子を wait して reap → (5) sidecar 削除 → (6) fd を閉じて解放 → (7) 自死
_gtl_on_signal() {
    local sig="$1"
    _gtl_reap_active_group "${sig}"   # 受信シグナルをそのままグループへ転送して収束させる
    _gtl_cleanup                      # ここでは _GTL_CHILD_PGID が空なので二重処理にならない
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

    # 同一プロセスからの二重取得は no-op。
    # ここを素通しすると owner → reentrant に状態が落ち、以降の global_test_lock_run が
    # 「素通り実行」になって fd 非継承もプロセスグループ管理も失われる。
    if [ -n "${_GTL_MODE}" ]; then
        return 0
    fi

    _gtl_validate_env
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
    # dir を 0700 + 所有者検証した上で、ファイル自体の型も検証する (多層防御)。
    [ -L "${lockfile}" ] && _gtl_die "lock file is a symlink (refusing): ${lockfile}"
    [ -L "${_GTL_SIDECAR}" ] && _gtl_die "sidecar is a symlink (refusing): ${_GTL_SIDECAR}"
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

# lane 固有の後始末を cleanup へ追加登録する。
# **lane 側で trap ... EXIT を張ってはならない**: acquire の前に張れば acquire 側の
# trap に上書きされ、後に張れば _gtl_cleanup を消してロックが解放されなくなる。
# EXIT trap の所有者はライブラリ 1 箇所に固定し、lane はここへ登録する。
global_test_lock_on_exit() {
    # 関数名の誤記が実行時に `|| true` で黙殺されるのを防ぐため、登録時に存在を検証する。
    [ "$#" -eq 1 ] || _gtl_die "global_test_lock_on_exit takes exactly 1 argument"
    case "$1" in
        ''|*[!A-Za-z0-9_]*) _gtl_die "invalid exit hook name: $1" ;;
    esac
    declare -F "$1" >/dev/null 2>&1 || _gtl_die "exit hook is not a defined function: $1"
    _GTL_EXIT_HOOKS="${_GTL_EXIT_HOOKS} $1"
    # flock 不在 / 再入で cleanup が走らない経路でも lane の後始末は必要なので、
    # owner 以外のときだけ素の EXIT trap を張る (owner 時は _gtl_cleanup が呼ぶ)。
    if [ "${_GTL_MODE}" != "owner" ]; then
        trap '_gtl_cleanup' EXIT
    fi
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

# --- グローバルテストロック (旧 worktree-local flock を置き換え) ---
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

...

global_test_lock_run php artisan config:clear --ansi
global_test_lock_run php scripts/ci/ensure-test-db.php

...
    code=0
    global_test_lock_run vendor/bin/pest -c phpunit.browser.xml "${PEST_PARALLEL_ARGS[@]}" \
        --browser "${browser}" "$@" || code=$?
```

```bash
# 起動時の掃除は従来どおり。EXIT trap は **自前で張らず** ライブラリへ登録する。
#
# `trap cleanup_orphan_playwright EXIT` を自前で張ると、acquire 前なら acquire 側の
# `trap '_gtl_cleanup' EXIT` に上書きされ、acquire 後ならこちらが `_gtl_cleanup` を消して
# **ロックが永久に解放されなくなる**。EXIT trap の所有者はライブラリ 1 箇所に固定する。
cleanup_orphan_playwright
global_test_lock_on_exit cleanup_orphan_playwright
```

> `global_test_lock_on_exit` で登録したフックは **ロックを保持したまま先に実行**される。
> orphan playwright の掃除は「次のレーンが入る前に終える」必要があるため、この順序が正しい。
> **層 1 に「lane の EXIT フック併用でロックが解放されること」の検証を入れる**
> (trap 上書きは実際に起きうる回帰なので機械で固定する)。

### テスト計画
- [ ] 層 1: `@playwright/cli` 相当の cmdline を持つ偽プロセスが `cleanup_orphan_playwright` の
      選択対象にならないこと(負のコントロール)、および
      `node_modules/playwright/cli.js run-server` 相当は選択されること(正のコントロール)
- [ ] 層 1: bughunt ポートを 1 つ listen させた状態で Browser lane 相当が fail-fast すること
- [ ] 層 1: `global_test_lock_on_exit` 併用でロックが解放され、かつフックが
      **ロック保持中に**実行されること(trap 上書き回帰)
- [ ] 層 2: lane スクリプトが自前で `trap ... EXIT` を張っていないこと
- [ ] 層 2: `composer.json` の `test:browser` が `scripts/run-browser-test.sh` 経由で、
      当該スクリプトが `global-test-lock.sh` を source していること
- [ ] 層 2: 旧 `test.lock` / `flock -n 9` が残っていないこと

### リスク
- **trap 上書き**(上記)。`global_test_lock_on_exit` へ一本化し、層 1 と層 2 の両方で固定する。
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

## 施策 6: packages lane / coverage lane をラップ

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
"test:coverage": "bash scripts/with-global-test-lock.sh pnpm exec vitest run --coverage",
"test:watch": "vitest --watch",
"build:packages": "pnpm -F \"./packages/*\" build",
"test:packages": "bash scripts/with-global-test-lock.sh pnpm -F \"./packages/*\" test"
```

- `test:coverage` は **`vitest run --coverage` へ寄せてラップ対象に入れる**。
  現行の `vitest --coverage` は watch モードだが、`test:watch` が既にあるので watch の
  二重提供であり、one-shot にした方が用途が明確になる。one-shot である以上、
  無ロック経路として残す理由がない(CPU 競合は `pnpm test` と同じ)。
- exempt するのは `test:ui`(常駐 UI サーバ)と `test:watch`(常駐 watch)の **2 つだけ**。
  いずれも**無期限にロックを保持してしまう**のがラップしない理由であり、
  「非公式だから」ではない。層 2 の inventory に**理由つきの明示 exemption** として登録し、
  それ以外の `test*` スクリプトは deny-by-default で「ラップ必須」とする。
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

### 検証ケース(24 ケース。ID は CI ログと対応させるため C01.. で固定する)

| # | ケース | 検証内容 |
|---|---|---|
| C01 | lock path の導出 | 既定が `/tmp/global-test-lane-<uid>.d/lock`。`GLOBAL_TEST_LOCK_DIR` 上書き時は警告が出る |
| C02 | lock dir の fail-secure | symlink / 別 mode のディレクトリを与えると**明示エラーで停止**する(黙って続行しない) |
| C03 | ブロッキング取得 | 2 本目は**即エラーにならず待機**し、1 本目の解放後に実行される(旧 `flock -n` の負のコントロール) |
| C04 | heartbeat | 待機中に heartbeat 行が stderr に出て、**保持者の pid / lane / worktree** を含む |
| C05 | 非競合時の無音 | 待たずに取れたケースでは heartbeat が 1 行も出ない(CI ログを汚さない) |
| C06 | fd 非継承 | ロック配下のコマンドから `/proc/self/fd/7` が見えない。heartbeat 子にも渡らない |
| C07 | 保持期間 = 実行中 | コマンド実行中は第三のレーンが取得できない(**`exec` 回帰の負のコントロール**) |
| C08 | 孫の刈り取り | 直接子が**孫を生んで先に終了**しても、孫が消えるまで第三のレーンは取得できない |
| C09 | シグナル収束 | **INT/TERM を無視する子と孫**に対し、猶予超過後に強制終了して第三のレーンが取得できる(deadlock しない) |
| C10 | 終了コード契約 | 0 / 非 0 が素通しされる。INT/TERM 時は親が 128+signo で終わる |
| C11 | 背景子の非残存 | 全ケース終了後に子孫プロセスが残らない |
| C12 | プロセスグループ | レーンが PGID == PID になる。現行 4 レーン相当のコマンドが**自発的に離脱しない** |
| C13 | 再入(nonce 一致) | 保持中の子孫からの再呼び出しが deadlock せず素通りする。**再入子の終了後も外側 sidecar が残る** |
| C14 | 再入の否定 | (1) 保持者を SIGKILL した後、stale nonce を持つ子孫は再入できない (2) **残留 sidecar は次の取得者をブロックせずアトミックに上書きされる** |
| C15 | flock 不在 | `flock` が PATH に無い状態を作ると、警告を出して**排他なしで実行し終了コードは保つ** |
| C16 | TTY あり / なし | C03〜C10 が TTY 有無の双方で成立し、monitor mode が復元される |
| C17 | lane の EXIT フック | `global_test_lock_on_exit` で登録したフックが **ロック保持中に**実行され、その後ロックが解放される(trap 上書き回帰) |
| C18 | playwright 選別 | `@playwright/cli` 相当の cmdline は掃除対象にならず、`node_modules/playwright/cli.js run-server` 相当は対象になる |
| C19 | bughunt guard | `:8010..:8018` のいずれかを listen させると Browser lane 相当が **ロック取得前に** fail-fast する(先行レーンを待たされない) |
| C20 | 二重 acquire | 同一プロセスで `global_test_lock_acquire` を 2 回呼んでも owner のままで、後続の `global_test_lock_run` が素通り化しない |
| C21 | ファイルの型検証 | `lock` / `owner` を symlink に差し替えると**明示エラーで停止**する |
| C22 | 異常終了経路の収束 | 子を起動した**後**に内部エラー(`_gtl_die` 相当)で EXIT へ抜けても、残党を残さずグループを収束させてからロックを解放する |
| C23 | SIGKILL 生存者 | `_gtl_group_alive` を「常に生存」へ差し替えると、**ロックが解放されない**まま警告が出続ける(諦めて解放しないことの回帰)。`_gtl_group_alive` は shell 関数なので、スイートは source 後に再定義して注入する |
| C24 | env 値の検証 | `GLOBAL_TEST_LOCK_HEARTBEAT_SECS` が `0` / 負数 / 非数、`GLOBAL_TEST_LOCK_GRACE_SECS` が負数 / 非数、`GLOBAL_TEST_LOCK_DIR` が相対パスのとき、**取得時に fail-fast する**(壊れた設定で保護が半分だけ効く状態を作らない) |

> ケース数を移植元の 11 に合わせることは目的にしていない。aicue のレーン構成
> (4 レーン + bug-hunt 隣接 + Browser lane 固有の掃除)に対して過不足なく定義した結果 24 ケースになった。
> 各ケースは `C01` 〜 `C24` の ID で出力し、CI ログから失敗ケースを直接特定できるようにする。

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
    'test:ui' => 'vitest --ui (常駐 UI サーバ)。無期限にロックを保持するため対象外',
    'test:watch' => 'vitest --watch (常駐 watch)。同上',
];

/** ロック経由と認められる呼び出し先 (これ自身がライブラリを source していることも検査する)。 */
const GLOBAL_TEST_LOCK_LANE_SCRIPTS = [
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
    'scripts/run-vitest.sh',
];

/**
 * 構造検査の対象スクリプト = lane スクリプト 3 本 + 汎用ラッパ。
 * ラッパを対象外にすると、将来 `exec "$@"` へ戻されても層 2 は
 * 「存在し実行可能」だけで通過してしまう (ロックが即解放される致命的回帰を見逃す)。
 * ライブラリ本体 (scripts/global-test-lock.sh) は対象外 —
 * trap / exec fd リダイレクトを**正当に持つ唯一のファイル**だから。
 */
const GLOBAL_TEST_LOCK_GUARDED_SCRIPTS = [
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
    'scripts/run-vitest.sh',
    'scripts/with-global-test-lock.sh',
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
        // 部分一致で通すと `with-global-test-lock.sh true && unlocked-test` のような
        // 「ラッパ名は含むが実体は無ロック」が素通りする。
        // **最終行 (= 実際に走るコマンド) が公式入口そのものであること**を要求し、
        // 同一行のシェル演算子で別コマンドを繋ぐことを禁止する。
        $lines = array_values(array_filter(
            array_map(trim(...), preg_split('/\R/', $command) ?: []),
            static fn (string $l): bool => $l !== '',
        ));
        $last = $lines === [] ? '' : $lines[count($lines) - 1];

        if (preg_match('/(&&|\|\||;|(?<!\|)\|(?!\|))/', $last) === 1) {
            $violations[] = "script '{$name}' がロック配下のコマンドをシェル演算子で連結している: {$last}";

            continue;
        }

        $entrypoints = array_merge(['scripts/with-global-test-lock.sh'], GLOBAL_TEST_LOCK_LANE_SCRIPTS);
        $viaEntrypoint = false;
        foreach ($entrypoints as $entrypoint) {
            if (preg_match('#^bash\s+'.preg_quote($entrypoint, '#').'(?:\s|$)#', $last) === 1) {
                $viaEntrypoint = true;
                break;
            }
        }
        if (! $viaEntrypoint) {
            $violations[] = "script '{$name}' がグローバルテストロックを経由していない: {$last}";
        }
    }

    return $violations;
}

/**
 * shell ソースから **実行行だけ** を取り出す (純関数)。
 *
 * 全ての静的検査はこの結果を単一の解析入力として使う。変更後スクリプトは
 * 「旧 worktree-local な test.lock を廃止した」「flock -n をやめた」といった説明を
 * **コメントに書く**ため、生ソースを検査すると正しい実装が偽赤になる。
 *
 * 行頭 (空白を除く) が `#` の行だけを落とす。行末コメントの除去はしない —
 * `'#'` のような引用符内の `#` を壊してコードを誤って削るリスクの方が大きい。
 */
function globalTestLockCodeLines(string $source): string
{
    $lines = preg_split('/\R/', $source) ?: [];
    $code = array_filter(
        $lines,
        static fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1,
    );

    return implode("\n", $code);
}

/**
 * lane スクリプト / ラッパ本体が契約を守っているかを検査する (純関数)。
 *
 * @return list<string> 違反一覧 (空 = 合格)
 */
function globalTestLockLaneScriptViolations(string $path, string $source): array
{
    $violations = [];
    $code = globalTestLockCodeLines($source);

    if (! str_contains($code, 'global-test-lock.sh')) {
        $violations[] = "{$path} が scripts/global-test-lock.sh を source していない";
    }
    // 旧 worktree-local ロックの残存 (後方互換の並走) を禁止する。
    if (str_contains($code, 'storage/framework/testing/test.lock')) {
        $violations[] = "{$path} に旧 worktree-local な test.lock が残っている";
    }
    if (preg_match('/app-vitest-/', $code) === 1) {
        $violations[] = "{$path} に旧 workspace-hash ロック (app-vitest-*) が残っている";
    }
    if (preg_match('/\bflock\s+-n\b/', $code) === 1) {
        $violations[] = "{$path} に flock -n (非ブロッキング取得) が残っている";
    }
    // 自己バイパスの禁止。
    if (preg_match('/GLOBAL_TEST_LOCK_DIR=/', $code) === 1) {
        $violations[] = "{$path} が GLOBAL_TEST_LOCK_DIR を設定している (自己バイパス禁止)";
    }
    // exec はロック fd を閉じてロックを即解放するため、ロック配下では使わない。
    // ただし `exec 3<>...` のような **fd リダイレクト形は正当** なので除外する
    // (run-browser-test.sh の /dev/tcp guard が使う)。
    if (preg_match('/^\s*exec\s+(?!\d*[<>])/m', $code) === 1) {
        $violations[] = "{$path} が exec を使っている (fd 7 が閉じてロックが即解放される)";
    }
    // EXIT trap の所有者はライブラリ 1 箇所。lane が自前で張ると _gtl_cleanup を
    // 上書きしてロックが解放されなくなる (逆順なら lane 側が消される)。
    // 後始末は global_test_lock_on_exit へ登録する。
    if (preg_match('/^\s*trap\b[^\n]*\bEXIT\b/m', $code) === 1) {
        $violations[] = "{$path} が自前で trap ... EXIT を張っている (global_test_lock_on_exit を使うこと)";
    }
    // ラッパ / lane は必ず acquire → run の順で公開 API を **実際に呼ぶ** こと。
    // str_contains ではコメント/文字列だけでも通ってしまうため、呼び出し形を正規表現で見る。
    $acquireAt = preg_match('/^\s*global_test_lock_acquire\b/m', $code, $mA, PREG_OFFSET_CAPTURE) === 1
        ? $mA[0][1]
        : null;
    $runAt = preg_match('/^\s*global_test_lock_run\b/m', $code, $mR, PREG_OFFSET_CAPTURE) === 1
        ? $mR[0][1]
        : null;

    if ($acquireAt === null) {
        $violations[] = "{$path} が global_test_lock_acquire を呼んでいない";
    }
    if ($runAt === null) {
        $violations[] = "{$path} が global_test_lock_run を呼んでいない";
    }
    if ($acquireAt !== null && $runAt !== null && $acquireAt > $runAt) {
        $violations[] = "{$path} が global_test_lock_run を acquire より前に呼んでいる";
    }

    return $violations;
}

test('scripts/global-test-lock.sh と with-global-test-lock.sh が存在し実行可能であること', ...);
test('scripts/verify-global-test-lock.sh が存在し実行可能であること', ...);
test('composer.json の test 系 script が全てグローバルテストロック経由であること', ...);
test('package.json の test 系 script が全てグローバルテストロック経由であること', ...);
test('lane スクリプトとラッパが契約 (source / 旧ロック不在 / flock -n 不在 / exec 不在 / 自前 EXIT trap 不在 / acquire+run 使用) を守ること', function (): void {
    foreach (GLOBAL_TEST_LOCK_GUARDED_SCRIPTS as $rel) {
        $source = file_get_contents(base_path($rel));
        expect($source)->toBeString();
        /** @var string $source */
        expect(globalTestLockLaneScriptViolations($rel, $source))->toBe([]);
    }
});

/* 負のコントロール (実ファイルは書き換えない) */
test('負のコントロール: 未ラップの新レーンを検出する', function (): void {
    $violations = globalTestLockLaneViolations(['test:e2e' => 'pnpm exec playwright test']);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('test:e2e');
});

test('負のコントロール: ラッパ名を含むだけの偽装 (演算子連結) を検出する', function (): void {
    $violations = globalTestLockLaneViolations([
        'test:e2e' => 'bash scripts/with-global-test-lock.sh true && pnpm exec playwright test',
    ]);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('連結');
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

test('負のコントロール: exec を復活させたラッパを検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    global_test_lock_acquire "$*"
    exec "$@"
    SH;
    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('exec');
});

test('負のコントロール: 自前 EXIT trap を張った lane スクリプトを検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    global_test_lock_acquire "lane"
    trap cleanup_orphan_playwright EXIT
    global_test_lock_run vendor/bin/pest
    SH;
    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('trap');
});

test('負のコントロール: exec の fd リダイレクト形は違反にしない', function (): void {
    $ok = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    (exec 3<>"/dev/tcp/127.0.0.1/8010") 2>/dev/null || true
    global_test_lock_acquire "lane"
    global_test_lock_run vendor/bin/pest
    SH;
    expect(globalTestLockLaneScriptViolations('fixture.sh', $ok))->toBe([]);
});
```

### PHPStan 適合チェック
- [x] 戻り値の型が明示されている(`list<string>` / `void`)
- [x] null 安全(`file_get_contents` の戻りは `expect(...)->toBeString()` + `@var string` で narrowing。
      既存 `PhpstanWrapperInvariantTest` と同一作法)
- [x] DTO を返している(該当なし。Architecture テストは違反文字列のリストを返す純関数)
- [x] Generics の型パラメータが正しい(`array<string, string>` / `list<string>`)
- [x] `json_decode` の戻りは `mixed` として受け、`is_array()` で narrowing する

### テスト計画
- [ ] 新規テスト: 正のテスト 5 本 + 負のコントロール 6 本
      (未ラップ新レーン / 旧 worktree-local ロック復活 / `exec` 復活 / 自前 EXIT trap /
      `exec` fd リダイレクト形は違反にしない = 偽陽性の固定 / ラッパ名を含むだけの演算子連結偽装)
- [ ] 既存テスト `tests/Architecture/PhpstanWrapperInvariantTest.php` の更新: 不要(独立)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認(DB 不使用の静的検査)

### リスク
- `exec` 検出の正規表現は `exec 9>...`(fd リダイレクト形)を誤検出しないよう
  `exec` の直後が数値 + リダイレクト記号のケースを除外する。負のコントロールで両方向を固定する。
- **静的検査は必ず `globalTestLockCodeLines()` の出力に対して行う**。変更後スクリプトは
  「旧 `test.lock` を廃止した」「`flock -n` をやめた」という説明を**コメントに書く**ため、
  生ソースを走査すると正しい実装が偽赤になる(設計レビューで実際に指摘された落とし穴)。
  行末コメントの除去は行わない(引用符内の `#` を壊してコードを誤削除するリスクの方が大きい)。
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

## 実装差分 (git diff。devnotes は除外)

```diff
diff --git a/.github/workflows/ci.yml b/.github/workflows/ci.yml
index f8ecac0..789da74 100644
--- a/.github/workflows/ci.yml
+++ b/.github/workflows/ci.yml
@@ -40,6 +40,10 @@ jobs:
         run: vendor/bin/pint --test
       - name: PHPStan
         run: composer phpstan
+      # グローバルテストロックの並行挙動ゲート (層 1)。
+      # 実ロックには触れず、mktemp -d の scratch 上で待機・シグナル収束・fd 非継承などを検証する。
+      - name: Verify global test lock
+        run: bash scripts/verify-global-test-lock.sh
       - name: Pest
         run: composer test
 
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 4fd26a1..6312929 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -303,3 +303,70 @@ ### 関連
   `app/Http/Middleware/RequireActiveSubscription.php` /
   `app/DataTransferObjects/Dashboard/BillingSummaryData.php`
 - 設計: `devnotes/20260712-0927-bugfix-billing-free-access/` (概念設計 + 詳細設計 施策 1〜5)
+
+## D10 ✅ テストレーンのグローバルロック (worktree-local flock を残さず削除)
+
+| 観点 | テンプレート(正典 = spirux 形) | 本アプリ |
+|---|---|---|
+| worktree-local flock | 残す (グローバルロックとの二重ロック) | **削除する** (グローバルロックが厳密に包含するため。思考原則 3) |
+| lock file 名 | `/tmp/spirux-global-test.lock` (repo 名固定) | `/tmp/global-test-lane-<uid>.d/lock` (slug 非依存 + UID 分離 + 0700 / 所有者 / symlink 検証) |
+| heartbeat | 常時 30 秒 | **待機中のみ** (保持中はテストランナー自身が喋る。CI は無競合なので 1 行も出ない) |
+| 再入ガード | owner-pid 一致 | **nonce 一致** (sidecar の 1 行目と env の突き合わせ。PID 再利用の穴を持たない) |
+| 検証スイートの置き場 | devnotes 常駐 | **`scripts/verify-global-test-lock.sh` へ昇格 + Architecture テスト + CI ゲート** (禁止事項 1) |
+| bug-hunt | (正典に記述なし) | 対象外。非干渉は保証せず best-effort の pre-flight guard のみ (残余リスクとして受容) |
+
+### なぜ正当な差分か (logic-driven)
+
+aicue の実装は必ず worktree で行う (AGENTS.md §worktree 運用ルール) ため、
+**同一マシン上で複数のテストレーンが同時に走るのが常態**である。実在するハザードは
+(H1) Browser lane の playwright 掃除が `pgrep -f` で**マシン全体**を走査して他レーンの
+run-server を kill する、(H2) PostgreSQL サーバという単一共有資源の奪い合い、
+(H3) devcontainer の CPU/メモリ枯渇によるタイムアウト由来の偽赤、
+(H4) `flock -n` が待たずに死ぬためエージェントがリトライループを回す、の 4 つで、
+**いずれも作用域はマシン (コンテナ) 全体**である。
+
+ロックの作用域は守るべき資源の作用域と一致していなければならない。したがって
+worktree-local ロックは 1 つも新しい事象を防がない (グローバルロックのスコープが
+厳密に包含する)。残せば有害な `flock -n` (H4) をそのまま温存することになるため、
+**同じ変更で削除する**。lock 名に slug を入れないのは `AppNameHardcodeTest` が
+`scripts/` へのアプリ slug 直書きを禁じていること、および**このロックは repo を
+またいで共有されて正しい** (同一マシンの PostgreSQL と CPU は repo をまたいで 1 つ) ため。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「ブロッキング取得 / 待機中の heartbeat / 再入ガード / ロック fd の非継承」の 4 要件は
+> 正典と同一。差分はいずれも**同じ不変条件をより強い機構で保証する**方向である。
+> 加えて aicue は「ロック保持期間 = 取得 〜 **専用プロセスグループが空になった後**」
+> (親の生存期間でも直接の子の終了時点でもない) を非交渉の契約として追加している。
+
+- 並行挙動 (層 1): `scripts/verify-global-test-lock.sh` (C01〜C24)。CI の `php` job で毎回走る
+- 構造的不変条件 (層 2): `tests/Architecture/GlobalTestLockInventoryTest.php`。
+  composer.json / package.json の `test*` script は明示 exemption (`test:ui` / `test:watch`) 以外
+  **deny-by-default でロック経由必須**。旧 `test.lock` / `app-vitest-*` / `flock -n` / `exec` /
+  lane 自前の `trap ... EXIT` / `GLOBAL_TEST_LOCK_DIR` 設定の残存を検出する
+- **層 2 から層 1 を実行してはならない** (非交渉): 層 2 は `composer test` の内側 =
+  ロック保持中に走るため、自己競合する
+
+### 保証しないこと (明示)
+
+- SIGKILL / 親のクラッシュ / コンテナ強制停止 (trap が走らない)。この場合も flock は OS が
+  解放し、残留 sidecar は次の取得者がアトミックに上書きするため「ロックリーク」と
+  「stale nonce による誤再入」は防ぐが、**残存子孫と次レーンの併走は防げない**
+- 自ら `setsid()` / `setpgid()` で専用プロセスグループを離脱した子孫
+- 規約に参加しないプロセス (bug-hunt / 未移植リポジトリ / 手打ちの `vendor/bin/pest` / 他ツール)
+- 別 UID のプロセスとの H2 / H3 競合
+
+### スコープ外の観測 (次に触る人への申し送り)
+
+bug-hunt 自身の `.claude/bug-hunt.lock` は **worktree-local** なので、別 worktree からの
+bug-hunt 同時起動は `playwright-cli kill-all` で相互破壊しうる。本設計では触っていない
+(bug-hunt 側 = orchestrator と N 体の subagent worker にまたがる security-sensitive な
+スクリプトの改造になり、スコープに対して過大)。同種の課題として記録に残す。
+
+### 関連
+
+- 実装: `scripts/global-test-lock.sh` / `scripts/with-global-test-lock.sh` /
+  `scripts/verify-global-test-lock.sh` / `scripts/run-test.sh` /
+  `scripts/run-browser-test.sh` / `scripts/run-vitest.sh` / `package.json`
+- 設計: `devnotes/20260804-2319-global-test-lock/` (概念設計 Round 6 / 詳細設計 Round 5)
+- c2c 台帳: `global-test-lock` (origin: spirux:T1109/T1110、テンプレ昇格承認済み)
diff --git a/docs/testing-browser.md b/docs/testing-browser.md
index c9febbd..fb5e213 100644
--- a/docs/testing-browser.md
+++ b/docs/testing-browser.md
@@ -27,11 +27,19 @@ ## 実行
 ```
 
 `composer test:browser` は `scripts/run-browser-test.sh` 経由で
-`vendor/bin/pest -c phpunit.browser.xml` を呼ぶ。`composer test` (Feature pgsql lane) と
-同一 lock file (`storage/framework/testing/test.lock`) の flock で相互排他し、
-共有する pgsql テスト DB / ブラウザ資源の奪い合いを防ぐ。並列数を未指定 (= nproc) に
+`vendor/bin/pest -c phpunit.browser.xml` を呼ぶ。排他は **グローバルテストロック**
+(`scripts/global-test-lock.sh` / `/tmp/global-test-lane-<uid>.d/lock`) に一本化されており、
+`composer test` / `pnpm test` / `pnpm test:packages` を含む全テストレーンと
+**同一 UID・同一マシン単位**で相互排他する (worktree をまたぐ)。旧 worktree-local な
+flock は cross-worktree の相互破壊を防げないため廃止した。先行レーンがいる場合は
+**待つ** (旧実装は待たずに即エラー終了していた)。並列数を未指定 (= nproc) に
 すると同時起動で環境がハングし得るため既定 1 に固定している。
 
+Browser lane は起動時に bug-hunt 環境のポート (`127.0.0.1:8010..8018`) を
+best-effort で覗き、listen していれば **ロックを取る前に** fail-fast する。
+bug-hunt はロック規約に参加しない (意図的に隔離された 8 並列基盤) ため、
+非干渉は保証しない — TOCTOU のある guard であり、失敗モードが偽赤に留まる範囲で受容している。
+
 ### ブラウザレーン (Chromium + WebKit)
 
 `composer test:browser` は **Chromium レーン → WebKit レーンの順で 2 回** pest を実行し、
@@ -159,5 +167,29 @@ ## トラブルシュート
   「canned response の追加」を参照。
 - **orphan playwright run-server**: `scripts/run-browser-test.sh` が pest 終了後に掃除するが、
   残った場合は `pkill -f 'playwright/cli.js run-server'`。
-- **`composer test` と同時実行できない**: 仕様 (同一 pgsql テスト DB を共有するため
-  test.lock で排他)。先行する test の終了を待つ。
+- **他のテストレーンと同時実行できない**: 仕様 (グローバルテストロックで
+  同一 UID・同一マシン単位に直列化している)。**エラーにはならず待つ**ので、
+  待機の heartbeat が出ている間はそのまま待てばよい。
+- **bug-hunt が走行中で起動できない**: `scripts/bug-hunt-shard.sh teardown` で
+  bug-hunt 環境を落としてから再実行する。
+
+## グローバルテストロックの手動復旧 runbook
+
+排他の正本は `flock` **一点**であり、ロックは OS がプロセス消滅時に必ず解放する。
+したがって「ロックが取れないまま永久に詰まる」ことは、保持者が実際に生きている場合しか起きない。
+
+- **誰が握っているか調べる**: `cat /tmp/global-test-lane-$(id -u).d/owner`
+  (sidecar。1 行目 = nonce、以降 `pid=` / `lane=` / `worktree=` / `since=` の key=value)。
+  待機中の heartbeat は 30 秒ごとにこの内容を stderr へ出す。
+- **保持者を止める**: 上記 `pid=` のプロセスへ `kill -TERM <pid>`。ライブラリが
+  専用プロセスグループへシグナルを転送し、猶予 30 秒を過ぎたら SIGKILL する。
+  **グループが空になるまでロックは解放されない**(残党と次のレーンを併走させないため)。
+  空にならない場合は `still holding the lock: ... has survivors after SIGKILL` の警告に
+  残存 pid が出るので、それを調べる (SIGKILL を生き延びるのは stuck IO = D state だけ)。
+- **sidecar が残っているが誰も走っていない**: SIGKILL / クラッシュで trap が走らなかった場合に起きる。
+  **何もしなくてよい** — sidecar は排他の正本ではなく、次の取得者がアトミックに上書きする。
+  手で消す必要はない (消しても害はない)。
+- **ロックファイルを消さない**: `lock` を `rm` しても排他は直らない (既存の保持者は
+  inode 側のロックを持ち続ける)。保持者プロセスを止めるのが唯一の正しい手順。
+- **並行挙動を疑うとき**: `bash scripts/verify-global-test-lock.sh` (実ロックには触れない。
+  C01〜C24 の並行挙動を実プロセスで検証する)。
diff --git a/docs/worktree-isolation-strategy.md b/docs/worktree-isolation-strategy.md
index 8b3e87a..51373ca 100644
--- a/docs/worktree-isolation-strategy.md
+++ b/docs/worktree-isolation-strategy.md
@@ -32,8 +32,21 @@ ### なぜテスト DB を worktree ごとに分けるのか
 
 複数 worktree で `composer test` が同時に走ると、`RefreshDatabase` の `migrate:fresh` と
 paratest の per-worker DB が衝突して**不可解な failure**になる。DB 名を worktree path の hash で
-分けることで、別 worktree のテストは互いに止めない (同一 worktree 内の二重起動だけは
-`scripts/run-test.sh` の flock で直列化する)。
+分けることで、**DB 名の取り合いは構造的に起きない**。
+
+ただし分離できるのは名前空間だけで、**PostgreSQL サーバ・実ブラウザ・CPU/メモリは
+マシン全体で 1 つ**である。そこで 2 層構造にしている:
+
+| 層 | 何を分けるか | 機構 |
+|---|---|---|
+| **リソース名前空間** | テスト DB 名 (`<slug>_test_<worktree-hash>`) | `TestDatabaseEnv` の hash 導出 (worktree ごと) |
+| **実行そのもの** | テストレーンの同時実行 | `scripts/global-test-lock.sh` のグローバルロック (`/tmp/global-test-lane-<uid>.d/lock` = **同一 UID・同一マシン**単位。worktree をまたいで直列化する) |
+
+グローバルロックは**ブロッキング取得**なので、後発レーンはエラーにならず待つ。
+対象は `composer test` / `composer test:browser` / `pnpm test` / `pnpm test:packages`
+(+ `pnpm test:coverage`)。旧 worktree-local な flock はスコープが厳密に包含されるため廃止した
+(後方互換の並走を残さない)。並行挙動は `scripts/verify-global-test-lock.sh`、
+構造的不変条件は `tests/Architecture/GlobalTestLockInventoryTest.php` が固定する。
 
 **dev DB 防御は分離とは別レイヤで多重化されている** (AGENTS.md 禁止事項 #3):
 `TestDatabaseEnv` の allowlist (`<slug>_test_` + 8 桁 hash + paratest token のみ) と dev DB denylist を
diff --git a/package.json b/package.json
index 4b76121..0ce417b 100644
--- a/package.json
+++ b/package.json
@@ -12,10 +12,10 @@
         "typecheck": "tsc --noEmit",
         "test": "bash scripts/run-vitest.sh",
         "test:ui": "vitest --ui",
-        "test:coverage": "vitest --coverage",
+        "test:coverage": "bash scripts/with-global-test-lock.sh pnpm exec vitest run --coverage",
         "test:watch": "vitest --watch",
         "build:packages": "pnpm -F \"./packages/*\" build",
-        "test:packages": "pnpm -F \"./packages/*\" test"
+        "test:packages": "bash scripts/with-global-test-lock.sh pnpm -F \"./packages/*\" test"
     },
     "dependencies": {
         "@inertiajs/svelte": "^3.0.0",
diff --git a/scripts/README.md b/scripts/README.md
index faec5cb..bf550cc 100644
--- a/scripts/README.md
+++ b/scripts/README.md
@@ -10,8 +10,11 @@ ## スクリプト一覧
 
 | スクリプト | 用途 | 実行タイミング |
 |---|---|---|
-| `run-test.sh` | `composer test` の pgsql 経路。同一 worktree の test 二重起動を flock で直列化し、base テスト DB の冪等 CREATE (`ci/ensure-test-db.php`) → `artisan test --parallel` を実行 | `composer test` から自動呼び出し (直接呼ぶ必要なし) |
-| `run-vitest.sh` | workspace 単位で vitest を flock 排他実行 (`.vite/` cache と coverage 出力の同時書き込み破損を防ぐ) | `pnpm test` から自動呼び出し |
+| `global-test-lock.sh` | 全テストレーン共通のグローバルロック (source して使うライブラリ)。`/tmp/global-test-lane-<uid>.d/lock` を**ブロッキング取得**し、待機中のみ保持者の身元つき heartbeat を出す。レーンは専用プロセスグループで起動し、**グループが空になるまで**ロックを保持する。公開 API は `global_test_lock_acquire` / `global_test_lock_run` / `global_test_lock_on_exit` | 各 lane スクリプトから source (直接実行しない) |
+| `with-global-test-lock.sh` | 任意コマンドをグローバルテストロック配下で実行する汎用ラッパ (lane スクリプトを持たない `pnpm test:packages` / `test:coverage` 用) | `package.json` の script から自動呼び出し |
+| `verify-global-test-lock.sh` | グローバルテストロックの**並行挙動**検証スイート (層 1・C01〜C24)。実ロックには触れず `mktemp -d` の scratch 上で待機・heartbeat・fd 非継承・プロセスグループ刈り取り・シグナル収束・再入・終了コードを実プロセスで検証する | CI (`php` job) から自動実行 / ロック機構を変更したら手動実行 |
+| `run-test.sh` | `composer test` の pgsql 経路。**グローバルテストロック配下**で base テスト DB の冪等 CREATE (`ci/ensure-test-db.php`) → `artisan test --parallel` を実行 | `composer test` から自動呼び出し (直接呼ぶ必要なし) |
+| `run-vitest.sh` | vitest を**グローバルテストロック配下**で実行 (`exec` は使わない = fd 7 を保持したまま子を待つ) | `pnpm test` から自動呼び出し |
 | `phpstan.sh` | PHPStan の DX ラッパー。virtiofs 上の phar 並列 open レースを避けるため phar を実 fs に複製してから実行 | `composer phpstan` から自動呼び出し |
 | `ci/ensure-test-db.php` | pgsql テストの base DB を不在時のみ冪等 CREATE (dev-DB 保護の二重防御付き) | `run-test.sh` / CI から自動呼び出し |
 | `ci/drop-test-db.php` | worktree の base テスト DB と paratest worker DB を回収 (dev-DB は無条件 skip) | worktree teardown / CI cleanup |
@@ -22,7 +25,7 @@ ## スクリプト一覧
 | `audit-gate.sh` | supply-chain 依存脆弱性 gate のローカル実行ラッパ。composer / pnpm(pyproject.toml があれば pip-audit も)の audit JSON を取得して `audit-gate.ts` に渡す | `pnpm run audit:gate` から自動呼び出し / 直接実行 |
 | `audit-gate.ts` | audit JSON の統合判定 (high+ fail / moderate warn / `docs/supply-chain/accepted-advisories.yaml` の expiry・cleanup・severity 別上限を機械強制) | `audit-gate.sh` / CI から自動呼び出し |
 | `audit-gate.test.ts` | `audit-gate.ts` の unit テスト (正規化・expiry 判定・accept-risk 照合) | `pnpm test` (vitest の include に `scripts/**/*.test.ts` が入っている) |
-| `run-browser-test.sh` | Browser テスト (pest-plugin-browser) を排他 + 並列上限付きで実行。**Chromium / WebKit の 2 レーンが契約** (bfcache 復元シナリオは WebKit レーンが正本)。Feature lane と同じ lock file で相互排他し、残留 playwright run-server を前後で掃除する | `composer test:browser` 等から呼び出し。レーン限定は `BROWSER_TEST_LANES` / 並列度は `BROWSER_TEST_PROCESSES` |
+| `run-browser-test.sh` | Browser テスト (pest-plugin-browser) を**グローバルテストロック配下**で並列上限付きで実行。**Chromium / WebKit の 2 レーンが契約** (bfcache 復元シナリオは WebKit レーンが正本)。残留 playwright run-server を前後で掃除する (`@playwright/` = bug-hunt 側は除外)。起動時に bughunt ポート `:8010..8018` の best-effort pre-flight guard を掛ける | `composer test:browser` 等から呼び出し。レーン限定は `BROWSER_TEST_LANES` / 並列度は `BROWSER_TEST_PROCESSES` |
 | `bug-hunt-shard.sh` | bug-hunt シャードオーケストレータ。隔離環境 (DB `bug_hunt(_N)` / `:8010+N`) の provision / serve / teardown と、**dev DB を wipe しないための用途別 DB wrapper + 3-way hard-deny guard** を提供する (AGENTS.md §bug-hunt) | `/app-bug-hunt` から。`self-test` は実資源に触れず guard を検証 |
 | `bug-hunt-inventory-check.sh` | bug-hunt インベントリのドリフト検知。`route:list` と `.claude/skills/app-bug-hunt/{screens,operations}.md` の差分 (新ルート未追記 / 消失) を出す (exit 3 = 差分あり) | route 追加・削除時 / bug-hunt 実行前 |
 | `bughunt-worktree-hook.sh` | PreToolUse(Bash) ガード。`bug-hunt-shard.sh provision` の **main 直叩き** (worktree 指紋なし) を harness 層で拒否する | `.claude/settings.json` の hook として配線 (`.claude/settings.bughunt-hook.example.json` をマージ) |
diff --git a/scripts/global-test-lock.sh b/scripts/global-test-lock.sh
new file mode 100755
index 0000000..92e7728
--- /dev/null
+++ b/scripts/global-test-lock.sh
@@ -0,0 +1,379 @@
+#!/usr/bin/env bash
+#
+# scripts/global-test-lock.sh — 全テストレーン共通のグローバルロック (source して使う)。
+#
+# 目的: 同一 UID・同一マシン (コンテナ) 上で、本規約に参加するテストレーンが
+#       同時に 2 本走らないようにする。worktree をまたいだ並列実装の待ち合わせが目的なので
+#       「待たずに失敗する」flock -n ではなく **ブロッキング取得** にする。
+#
+# 設計の正本: devnotes/20260804-2319-global-test-lock/conceptual-design.md
+#
+# 契約 (非交渉):
+#   - ロック保持期間 = 取得 〜 **専用プロセスグループが空になった後**
+#     (親の生存期間でも、直接の子の終了時点でもない)
+#   - ロック配下では exec を使わない (exec は fd 7 を閉じてロックを即解放する)
+#   - 待機中のみ heartbeat を出す (保持中はテストランナー自身が喋る。CI は無競合なので無音)
+#   - 再入は「env の nonce == 現存する sidecar の nonce」のときだけ。再入経路は何も獲得しない
+#   - flock(1) 不在環境は排他なしで続行 (既存 lane スクリプトの方針を踏襲)。ただし警告を 1 行出す
+#   - ロック dir が乗っ取られていたら **明示エラーで停止** する (黙って保護を落とさない)
+#   - CI バイパス分岐は作らない (CI が検証するものと開発者が走らせるものを同一に保つ)
+#
+# 保証しないこと:
+#   - SIGKILL / 親のクラッシュ / コンテナ強制停止 (trap が走らない)。
+#     この場合も flock は OS が解放し、残留 sidecar は次の取得者が上書きするため
+#     「ロックリーク」と「stale nonce による誤再入」は防ぐが、残存子孫との併走は防げない
+#   - 自ら setsid()/setpgid() で専用プロセスグループを離脱した子孫
+#   - 規約に参加しないプロセス (bug-hunt / 手打ちの vendor/bin/pest / 他ツール)
+#
+# 並行挙動の検証は scripts/verify-global-test-lock.sh (層 1)、
+# 構造的不変条件は tests/Architecture/GlobalTestLockInventoryTest.php (層 2)。
+
+# ---- 内部状態 (source 元シェルに置く) ----
+_GTL_FD=7                 # ロック fd。既存 lane が使っていた 9 とは分ける
+_GTL_MODE=""              # owner / reentrant / disabled
+_GTL_SIDECAR=""
+_GTL_NONCE=""
+_GTL_HB_PID=""
+_GTL_CHILD_PID=""
+_GTL_CHILD_PGID=""
+_GTL_PREV_MONITOR=0
+_GTL_CLEANED=0
+_GTL_EXIT_HOOKS=""          # lane 固有の後始末 (関数名の空白区切り)
+_GTL_HEARTBEAT_SECS=30      # 検証済み値を固定 (以後 env は読まない)
+_GTL_GRACE_SECS=30
+
+_gtl_die() { echo "global-test-lock: ERROR: $*" >&2; exit 1; }
+_gtl_warn() { echo "global-test-lock: $*" >&2; }
+
+_gtl_lock_dir() {
+    if [ -n "${GLOBAL_TEST_LOCK_DIR:-}" ]; then
+        _gtl_warn "using override lock dir ${GLOBAL_TEST_LOCK_DIR} (self-test only)"
+        printf '%s\n' "${GLOBAL_TEST_LOCK_DIR}"
+        return 0
+    fi
+    # 基点は /tmp に固定する。${TMPDIR} はプロセスごとに異なりうるため、基点に使うと
+    # 同一 UID でもロックが分裂して「マシン全体」の保証が壊れる。
+    # アプリ slug は名前に入れない (このロックは repo をまたいで共有されて正しい)。
+    printf '/tmp/global-test-lane-%s.d\n' "$(id -u)"
+}
+
+# ロック dir を 0700 で用意し、乗っ取り (symlink / 別所有者 / 緩い mode) を fail-secure に検出する。
+# UID 接尾辞はユーザー間の通常運用上の衝突を分けるだけで、先取りは防げない。防ぐのはここ。
+_gtl_ensure_dir() {
+    local dir="$1" owner mode
+    mkdir -p -m 700 "${dir}" 2>/dev/null || true
+    [ -L "${dir}" ] && _gtl_die "lock dir is a symlink (refusing): ${dir}"
+    [ -d "${dir}" ] || _gtl_die "lock dir is not a directory (refusing): ${dir}"
+    owner="$(stat -c '%u' "${dir}" 2>/dev/null || stat -f '%u' "${dir}" 2>/dev/null || echo '?')"
+    mode="$(stat -c '%a' "${dir}" 2>/dev/null || stat -f '%OLp' "${dir}" 2>/dev/null || echo '?')"
+    [ "${owner}" = "$(id -u)" ] || _gtl_die "lock dir owner mismatch (uid ${owner}): ${dir}"
+    [ "${mode}" = "700" ] || _gtl_die "lock dir mode must be 700 (got ${mode}): ${dir}"
+}
+
+# 検証用 env の値検証。不正値を放置すると剰余がゼロ除算になり、sleep / -ge / 算術展開が
+# 失敗して **cleanup の途中でシェルが終了**し、残存グループと次のレーンが併走しうる。
+# 取得時に fail-fast する (壊れた設定で保護が半分だけ効く状態を作らない)。
+_gtl_validate_env() {
+    # 検証済みの値は内部変数 (_GTL_HEARTBEAT_SECS / _GTL_GRACE_SECS) へ固定し、以後は
+    # 環境変数を読まない。acquire 後に env を書き換えて検証を迂回する経路を塞ぐため。
+    local hb="${GLOBAL_TEST_LOCK_HEARTBEAT_SECS:-30}" gr="${GLOBAL_TEST_LOCK_GRACE_SECS:-30}"
+    case "${hb}" in
+        ''|*[!0-9]*) _gtl_die "GLOBAL_TEST_LOCK_HEARTBEAT_SECS must be a positive integer: ${hb}" ;;
+    esac
+    [ "${hb}" -ge 1 ] || _gtl_die "GLOBAL_TEST_LOCK_HEARTBEAT_SECS must be >= 1: ${hb}"
+    case "${gr}" in
+        ''|*[!0-9]*) _gtl_die "GLOBAL_TEST_LOCK_GRACE_SECS must be a non-negative integer: ${gr}" ;;
+    esac
+    if [ -n "${GLOBAL_TEST_LOCK_DIR:-}" ]; then
+        case "${GLOBAL_TEST_LOCK_DIR}" in
+            /*) : ;;
+            *) _gtl_die "GLOBAL_TEST_LOCK_DIR must be an absolute path: ${GLOBAL_TEST_LOCK_DIR}" ;;
+        esac
+    fi
+    _GTL_HEARTBEAT_SECS="${hb}"
+    _GTL_GRACE_SECS="${gr}"
+}
+
+_gtl_new_nonce() {
+    # 外部コマンドに依存しない一意トークン (pid + 高分解能時刻 + 乱数)。
+    printf '%s-%s-%s%s\n' "$$" "${EPOCHREALTIME:-$(date +%s)}" "${RANDOM}" "${RANDOM}"
+}
+
+# sidecar の 1 行目 = nonce。所有者検証つきで読む (他ユーザーが置いた偽 sidecar を信じない)。
+_gtl_sidecar_nonce() {
+    local f="$1" owner line=""
+    [ -L "${f}" ] && return 1          # symlink は読まない (fail-secure)
+    [ -f "${f}" ] || return 1
+    owner="$(stat -c '%u' "${f}" 2>/dev/null || stat -f '%u' "${f}" 2>/dev/null || echo '?')"
+    [ "${owner}" = "$(id -u)" ] || return 1
+    IFS= read -r line < "${f}" || return 1
+    printf '%s\n' "${line}"
+}
+
+# 同一 dir 内の一時ファイルへ書いてから mv する (アトミック書き込み)。
+_gtl_write_sidecar() {
+    local lane="$1" tmp
+    tmp="${_GTL_SIDECAR}.tmp.$$"
+    [ -L "${_GTL_SIDECAR}" ] && _gtl_die "sidecar is a symlink (refusing): ${_GTL_SIDECAR}"
+    # 保証範囲: これらの型検証が防ぐのは **別 UID 境界** (0700 dir + 所有者検証との併せ技) であって、
+    # 「symlink 攻撃の完全防止」ではない。rm -f 後のリダイレクトには同一 UID プロセスとの
+    # TOCTOU が残る。同一 UID は既に自分自身と同じ権限を持つため、ここを完全に閉じる意味はない。
+    rm -f "${tmp}"                     # 既存 (symlink 含む) を消してから書く
+    {
+        printf '%s\n' "${_GTL_NONCE}"
+        printf 'pid=%s\n' "$$"
+        printf 'lane=%s\n' "${lane}"
+        printf 'worktree=%s\n' "$(pwd -P)"
+        printf 'since=%s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')"
+    } > "${tmp}"
+    mv -f "${tmp}" "${_GTL_SIDECAR}"
+}
+
+# 待機中だけ heartbeat を出す。無出力の待機を LLM エージェントが「ハング」と誤判断して
+# プロセスを kill する事故を防ぐのが目的なので、保持者の身元まで出す。
+_gtl_heartbeat_loop() {
+    local start="$1" waited=0 holder=""
+    while :; do
+        sleep "${_GTL_HEARTBEAT_SECS}"
+        waited=$(( $(date +%s) - start ))
+        holder="$(
+            {
+                # 1 行目 (nonce) は出さず、診断行だけを 1 行に畳む
+                read -r _
+                while IFS= read -r l; do printf '%s ' "${l}"; done
+            } < "${_GTL_SIDECAR}" 2>/dev/null || true
+        )"
+        echo "global-test-lock: waiting ${waited}s for the global test lane lock — held by ${holder:-<unknown>}" >&2
+    done
+}
+
+# zombie (Z) は「消滅」とみなす。SIGKILL 済みの Z は fd も DB 接続もポートも保持しないため、
+# kill -0 -"$pgid" だけで判定すると永久に「生存」と誤判定して収束しない (実測済み)。
+_gtl_group_alive() {
+    ps -A -o pgid= -o stat= 2>/dev/null \
+        | awk -v g="$1" '{sub(/^ +/, "")} $1 == g && $2 !~ /^Z/ { found = 1 } END { exit !found }'
+}
+
+# グループの消滅を待つ。猶予超過でグループへ SIGKILL を送り、**その後は上限を設けず**
+# 空になるまで待ち続ける (契約: グループが空になるまでロックを離さない)。
+# **必ず wait より前に呼ぶこと**: 先に wait すると、子が INT/TERM を無視した瞬間に
+# wait から戻れず SIGKILL に到達できないまま「ロックを永久保持する deadlock」になる。
+_gtl_wait_group_gone() {
+    local pgid="$1" grace="${_GTL_GRACE_SECS}" waited=0 nagged=0
+
+    # 第 1 段: 猶予内に自発終了するのを待つ
+    while _gtl_group_alive "${pgid}"; do
+        if [ "${waited}" -ge "${grace}" ]; then
+            _gtl_warn "grace exceeded; SIGKILL process group ${pgid}"
+            kill -KILL -"${pgid}" 2>/dev/null || true
+            break
+        fi
+        sleep 1
+        waited=$(( waited + 1 ))
+    done
+
+    # 第 2 段: SIGKILL 後も**空になるまで待ち続ける** (上限を設けない)。
+    #
+    # ここで諦めて戻ると fd 7 が閉じ、「グループが空になるまで保持」という
+    # 非交渉の契約が破れる (残党と次のレーンが併走する)。SIGKILL を生き延びるのは
+    # 割り込み不可能な待ち (D state = stuck IO) だけであり、その状況でロックを
+    # 手放すより保持し続ける方が安全。ハングと区別できるよう heartbeat 間隔で
+    # 残存 pid つきの警告を出し続ける。
+    waited=0
+    while _gtl_group_alive "${pgid}"; do
+        sleep 1
+        waited=$(( waited + 1 ))
+        nagged=$(( waited % _GTL_HEARTBEAT_SECS ))
+        if [ "${nagged}" -eq 0 ]; then
+            _gtl_warn "still holding the lock: process group ${pgid} has survivors after SIGKILL (${waited}s): $(
+                ps -A -o pgid= -o pid= -o stat= 2>/dev/null \
+                    | awk -v g="${pgid}" '{sub(/^ +/, "")} $1 == g && $3 !~ /^Z/ { printf "%s ", $2 }'
+            )"
+        fi
+    done
+}
+
+# 稼働中の専用プロセスグループを収束させる (シグナル経路と cleanup 経路の共通実装)。
+# **ロック解放より必ず先に呼ぶこと**: 子を起動した後に内部エラー (_gtl_die) や
+# set -e による中断で EXIT へ抜けると、稼働中の子・孫を残したまま fd 7 が閉じ、
+# 残党と次のレーンが併走して保持期間契約が破れる。
+# 冪等: _GTL_CHILD_PGID が空なら何もしない (二重処理を避ける)。
+_gtl_reap_active_group() {
+    local sig="${1:-TERM}"
+    [ -n "${_GTL_CHILD_PGID}" ] || return 0
+    kill -"${sig}" -"${_GTL_CHILD_PGID}" 2>/dev/null || true
+    _gtl_wait_group_gone "${_GTL_CHILD_PGID}"      # 猶予超過で SIGKILL → 以後は空になるまで待つ
+    if [ -n "${_GTL_CHILD_PID}" ]; then
+        wait "${_GTL_CHILD_PID}" 2>/dev/null || true
+    fi
+    _GTL_CHILD_PID=""
+    _GTL_CHILD_PGID=""
+}
+
+# 冪等。INT/TERM ハンドラ実行後に EXIT trap が再度走っても安全。
+_gtl_cleanup() {
+    [ "${_GTL_CLEANED}" = "1" ] && return 0
+    _GTL_CLEANED=1
+    # (1) まず稼働中のプロセスグループを収束させる (異常終了経路の残党を残さない)
+    _gtl_reap_active_group TERM
+    # (2) lane 固有の後始末を **ロックを保持したまま** 走らせる
+    #     (Browser lane の orphan playwright 掃除は、レーン本体が消えた後・
+    #      次のレーンが入る前に行う必要があるため、この順序が正しい)
+    local hook
+    for hook in ${_GTL_EXIT_HOOKS}; do
+        "${hook}" || _gtl_warn "exit hook failed (ignored): ${hook}"
+    done
+    if [ -n "${_GTL_HB_PID}" ]; then
+        kill "${_GTL_HB_PID}" 2>/dev/null || true
+        wait "${_GTL_HB_PID}" 2>/dev/null || true
+        _GTL_HB_PID=""
+    fi
+    # sidecar は **自分の nonce と一致するときだけ** 削除する
+    # (再入した子や次の owner の sidecar を消さない)。
+    if [ -n "${_GTL_SIDECAR}" ]; then
+        local cur=""
+        cur="$(_gtl_sidecar_nonce "${_GTL_SIDECAR}" 2>/dev/null || true)"
+        [ -n "${cur}" ] && [ "${cur}" = "${_GTL_NONCE}" ] && rm -f "${_GTL_SIDECAR}"
+    fi
+    [ "${_GTL_MODE}" = "owner" ] && exec 7>&-
+    [ "${_GTL_PREV_MONITOR}" = "1" ] || set +m
+    return 0
+}
+
+# 契約順序: (1) グループへ転送 → (2) 猶予内の消滅待ち → (3) 猶予超過なら SIGKILL し、
+#           以後は **上限なし** で空になるまで待つ →
+#           (4) 直接子を wait して reap → (5) sidecar 削除 → (6) fd を閉じて解放 → (7) 自死
+_gtl_on_signal() {
+    local sig="$1"
+    _gtl_reap_active_group "${sig}"   # 受信シグナルをそのままグループへ転送して収束させる
+    _gtl_cleanup                      # ここでは _GTL_CHILD_PGID が空なので二重処理にならない
+    trap - "${sig}" EXIT
+    kill -"${sig}" "$$"
+}
+
+# set -m で専用プロセスグループを作れることを取得時に 1 回だけ強制検証する
+# (各レーン実行時の ps 検証は、高速終了する子に対して空を返す race があるため best-effort にする)。
+_gtl_probe_process_group() {
+    local prev=0 pid pgid attempt=0
+    case "$-" in *m*) prev=1 ;; esac
+    # ps が空を返す race (probe 対象が先に終わった) は「作れなかった」ではないので数回試す。
+    while [ "${attempt}" -lt 3 ]; do
+        attempt=$(( attempt + 1 ))
+        set -m
+        sleep 0.3 &
+        pid=$!
+        [ "${prev}" = "1" ] || set +m
+        pgid="$(ps -o pgid= -p "${pid}" 2>/dev/null | tr -d ' ')"
+        kill "${pid}" 2>/dev/null || true
+        wait "${pid}" 2>/dev/null || true
+        [ "${pgid}" = "${pid}" ] && return 0
+        [ -n "${pgid}" ] && break      # 値が取れて不一致 = 本当に作れていない
+    done
+    _gtl_die "job control で専用プロセスグループを作れない (set -m 不可)"
+}
+
+global_test_lock_acquire() {
+    local lane="${1:-unknown lane}" dir lockfile start
+
+    # 同一プロセスからの二重取得は no-op。
+    # ここを素通しすると owner → reentrant に状態が落ち、以降の global_test_lock_run が
+    # 「素通り実行」になって fd 非継承もプロセスグループ管理も失われる。
+    if [ -n "${_GTL_MODE}" ]; then
+        return 0
+    fi
+
+    _gtl_validate_env
+    dir="$(_gtl_lock_dir)"
+    _GTL_SIDECAR="${dir}/owner"
+    lockfile="${dir}/lock"
+
+    # --- 再入: 何も獲得しない (fd / sidecar / trap / プロセスグループのいずれも新設しない) ---
+    if [ -n "${GLOBAL_TEST_LOCK_NONCE:-}" ]; then
+        local cur=""
+        cur="$(_gtl_sidecar_nonce "${_GTL_SIDECAR}" 2>/dev/null || true)"
+        if [ -n "${cur}" ] && [ "${cur}" = "${GLOBAL_TEST_LOCK_NONCE}" ]; then
+            _GTL_MODE="reentrant"
+            return 0
+        fi
+    fi
+
+    if ! command -v flock >/dev/null 2>&1; then
+        _gtl_warn "flock(1) が無いため排他なしで実行します (devcontainer / CI では排他あり)"
+        _GTL_MODE="disabled"
+        return 0
+    fi
+
+    _gtl_ensure_dir "${dir}"
+    # dir を 0700 + 所有者検証した上で、ファイル自体の型も検証する (多層防御)。
+    [ -L "${lockfile}" ] && _gtl_die "lock file is a symlink (refusing): ${lockfile}"
+    [ -L "${_GTL_SIDECAR}" ] && _gtl_die "sidecar is a symlink (refusing): ${_GTL_SIDECAR}"
+    _gtl_probe_process_group
+    exec 7>"${lockfile}"
+    _GTL_MODE="owner"
+    trap '_gtl_cleanup' EXIT
+    trap '_gtl_on_signal INT' INT
+    trap '_gtl_on_signal TERM' TERM
+
+    if ! flock -n 7; then
+        start="$(date +%s)"
+        # heartbeat 子には fd 7 を渡さない (渡すと解放後もロックが生き続ける)
+        _gtl_heartbeat_loop "${start}" 7>&- &
+        _GTL_HB_PID=$!
+        flock 7                                  # ブロッキング取得 (待つことが目的。上限は設けない)
+        kill "${_GTL_HB_PID}" 2>/dev/null || true
+        wait "${_GTL_HB_PID}" 2>/dev/null || true
+        _GTL_HB_PID=""
+    fi
+
+    _GTL_NONCE="$(_gtl_new_nonce)"
+    _gtl_write_sidecar "${lane}"                 # 残留 sidecar はここでアトミックに上書きされる
+    export GLOBAL_TEST_LOCK_NONCE="${_GTL_NONCE}"
+    return 0
+}
+
+global_test_lock_run() {
+    # 再入 / flock 不在では素通り (fd 7 を保持していないので 7>&- もプロセスグループも不要)
+    if [ "${_GTL_MODE}" != "owner" ]; then
+        "$@"
+        return $?
+    fi
+
+    local status=0 pgid=""
+    case "$-" in *m*) _GTL_PREV_MONITOR=1 ;; *) _GTL_PREV_MONITOR=0 ;; esac
+    set -m
+    "$@" 7>&- &                                   # fd 7 は子へ渡さない (orphan による lock leak 防止)
+    _GTL_CHILD_PID=$!
+    [ "${_GTL_PREV_MONITOR}" = "1" ] || set +m
+    _GTL_CHILD_PGID="${_GTL_CHILD_PID}"           # set -m により PGID == PID (取得時に probe 済み)
+
+    # best-effort 検証: 空 = 既に終了 (race) なので異常ではない。値が違うときだけ落とす。
+    pgid="$(ps -o pgid= -p "${_GTL_CHILD_PID}" 2>/dev/null | tr -d ' ')"
+    if [ -n "${pgid}" ] && [ "${pgid}" != "${_GTL_CHILD_PID}" ]; then
+        _gtl_die "専用プロセスグループを作れなかった (pid=${_GTL_CHILD_PID} pgid=${pgid})"
+    fi
+
+    wait "${_GTL_CHILD_PID}" || status=$?
+    _GTL_CHILD_PID=""
+    _gtl_wait_group_gone "${_GTL_CHILD_PGID}"     # 孫が残っている間はロックを離さない
+    _GTL_CHILD_PGID=""
+    return "${status}"
+}
+
+# lane 固有の後始末を cleanup へ追加登録する。
+# **lane 側で trap ... EXIT を張ってはならない**: acquire の前に張れば acquire 側の
+# trap に上書きされ、後に張れば _gtl_cleanup を消してロックが解放されなくなる。
+# EXIT trap の所有者はライブラリ 1 箇所に固定し、lane はここへ登録する。
+global_test_lock_on_exit() {
+    # 関数名の誤記が実行時に `|| true` で黙殺されるのを防ぐため、登録時に存在を検証する。
+    [ "$#" -eq 1 ] || _gtl_die "global_test_lock_on_exit takes exactly 1 argument"
+    case "$1" in
+        ''|*[!A-Za-z0-9_]*) _gtl_die "invalid exit hook name: $1" ;;
+    esac
+    declare -F "$1" >/dev/null 2>&1 || _gtl_die "exit hook is not a defined function: $1"
+    _GTL_EXIT_HOOKS="${_GTL_EXIT_HOOKS} $1"
+    # flock 不在 / 再入で cleanup が走らない経路でも lane の後始末は必要なので、
+    # owner 以外のときだけ素の EXIT trap を張る (owner 時は _gtl_cleanup が呼ぶ)。
+    if [ "${_GTL_MODE}" != "owner" ]; then
+        trap '_gtl_cleanup' EXIT
+    fi
+}
diff --git a/scripts/run-browser-test.sh b/scripts/run-browser-test.sh
index 1d22024..2355e9a 100755
--- a/scripts/run-browser-test.sh
+++ b/scripts/run-browser-test.sh
@@ -16,10 +16,10 @@
 #     レーンの意味と保証範囲は docs/supported-browsers.md。
 #     レーン限定実行は BROWSER_TEST_LANES="chromium" のように上書きする。
 #   - Browser lane は Feature lane と同じ worktree 固有 base テスト DB
-#     (<slug>_test_<worktree-hash>) と per-worker DB (_test_<token>) を使うため、
-#     composer test と同じ lock file (storage/framework/testing/test.lock) で
-#     相互排他する (同時実行すると migrate:fresh / per-worker DB が衝突する)。
-#     lock は worktree-local なので別 worktree の test は止めない。
+#     (<slug>_test_<worktree-hash>) と per-worker DB (_test_<token>) を使い、さらに
+#     実ブラウザ 2 engine と **マシン全体スコープの playwright 掃除** を伴う。
+#     排他は scripts/global-test-lock.sh のグローバルロック (同一 UID・同一マシン) に
+#     一本化した。旧 worktree-local ロックは cross-worktree の相互破壊を防げないため廃止した。
 #   - pest 終了後に playwright run-server (node) が orphan (PPID 1) として残る
 #     プラグイン側の後始末漏れがある。orphan は親プロセスのパイプ fd を握って
 #     呼び出し元を詰まらせる + プロセスを leak するため、実行前後に掃除する。
@@ -38,37 +38,83 @@ cd "$(dirname "$0")/.."
 PROCESSES="${BROWSER_TEST_PROCESSES:-1}"
 LANES="${BROWSER_TEST_LANES:-chromium webkit}"
 
-# composer test (scripts/run-test.sh) と同一 lock で相互排他する。
-# flock(1) が無い環境 (素の macOS 等) では排他なしで実行する (devcontainer/CI では排他あり)。
-LOCK_FILE="storage/framework/testing/test.lock"   # worktree-local
-mkdir -p "$(dirname "$LOCK_FILE")"
-if command -v flock >/dev/null 2>&1; then
-    exec 9>"$LOCK_FILE"
-    if ! flock -n 9; then
-        echo "ERROR: another composer test / test:browser is running in this worktree; refusing to start" >&2
-        echo "       lock file: $LOCK_FILE" >&2
-        exit 1
-    fi
+# --- bug-hunt 併走の pre-flight guard (best-effort。保証ではない) ---
+#
+# **ロック取得より前**に実行する。取得後に落とすと、先行レーンの終了を数分待ってから
+# 「bug-hunt が走っているので実行できません」と言うことになり、待ち時間が無駄になる。
+#
+# bug-hunt は本ロック規約に参加しない (意図的に隔離された並列実行基盤で、
+# global lock を被せると 8 並列が 1 直列に潰れる)。そのため bug-hunt の
+# `playwright-cli kill-all` (@playwright/cli) が Browser lane の run-server を
+# 巻き込む可能性を **こちらからは証明できない**。
+#
+# ここで行うのは「起動時点で bug-hunt が既に走っている」という頻度の高いケースだけを
+# 捕まえる best-effort guard であり、**TOCTOU がある** (Browser lane 開始後に
+# bug-hunt が起動する経路、bug-hunt が listen していない起動フェーズは捕まえられない)。
+# 非干渉は保証しない — 失敗モードが偽赤であって偽グリーンではないため受容する。
+#
+# 検知は bash の /dev/tcp のみを使う (ss/lsof/netstat の可用性と出力形式に依存しない)。
+# bug-hunt は 127.0.0.1:801N に明示 bind するので IPv4 loopback だけ見れば足りる。
+# /dev/tcp が使えないシェルでは検査を skip して続行する (guard であって保証ではない)。
+bughunt_port_in_use() {
+    local port
+    for port in {8010..8018}; do
+        if (exec 3<>"/dev/tcp/127.0.0.1/${port}") 2>/dev/null; then
+            exec 3<&- 3>&- 2>/dev/null || true
+            printf '%s\n' "${port}"
+            return 0
+        fi
+    done
+    return 1
+}
+
+if busy_port="$(bughunt_port_in_use)"; then
+    echo "ERROR: bug-hunt 環境が走行中です (127.0.0.1:${busy_port} が listen 中)。" >&2
+    echo "       bug-hunt の playwright-cli kill-all が Browser lane の run-server を" >&2
+    echo "       巻き込む可能性があるため、bug-hunt の終了を待ってから実行してください" >&2
+    echo "       (scripts/bug-hunt-shard.sh teardown / docs/testing-browser.md)。" >&2
+    exit 1
 fi
 
+# --- グローバルテストロック (旧 worktree-local ロックを置き換え) ---
+# shellcheck source=scripts/global-test-lock.sh
+. "$(pwd)/scripts/global-test-lock.sh"
+global_test_lock_acquire "composer test:browser"
+
+# orphan 化した playwright run-server (pest-plugin-browser 同梱 Playwright) を掃除する。
+#
+# **@playwright/cli は対象外にする**: bug-hunt が使うのは @playwright/cli であり、
+# 別プロセス名前空間である。pgrep のパターンは既存のまま維持し (正のマッチを弱めない)、
+# cmdline に "@playwright/" を含むプロセスを明示除外することで、こちらの掃除が
+# bug-hunt のブラウザを巻き込む経路 (方向 1) を構造的に塞ぐ。
 cleanup_orphan_playwright() {
-    local pid ppid
+    local pid ppid args
     for pid in $(pgrep -f "playwright/cli.js run-server" 2>/dev/null || true); do
-        ppid=$(ps -o ppid= -p "${pid}" 2>/dev/null | tr -d ' ' || true)
+        args="$(ps -o args= -p "${pid}" 2>/dev/null || true)"
+        case "${args}" in
+            *"@playwright/"*) continue ;;   # bug-hunt の @playwright/cli は触らない
+        esac
+        ppid="$(ps -o ppid= -p "${pid}" 2>/dev/null | tr -d ' ' || true)"
         if [ "${ppid}" = "1" ]; then
             kill "${pid}" 2>/dev/null || true
         fi
     done
 }
 
+# 起動時の掃除は従来どおり。EXIT trap は **自前で張らず** ライブラリへ登録する。
+#
+# `trap cleanup_orphan_playwright EXIT` を自前で張ると、acquire 前なら acquire 側の
+# `trap '_gtl_cleanup' EXIT` に上書きされ、acquire 後ならこちらが `_gtl_cleanup` を消して
+# **ロックが永久に解放されなくなる**。EXIT trap の所有者はライブラリ 1 箇所に固定する。
+# 登録したフックは **ロックを保持したまま** 実行される (次のレーンが入る前に掃除を終える)。
 cleanup_orphan_playwright
-trap cleanup_orphan_playwright EXIT
+global_test_lock_on_exit cleanup_orphan_playwright
 
-php artisan config:clear --ansi
+global_test_lock_run php artisan config:clear --ansi
 
 # worktree 固有の base テスト DB (<slug>_test_<worktree-hash>) を冪等に用意する。
 # DB 名の安全検証は tests/bootstrap.php の単一点ガードが担う (run-test.sh と同じ)。
-php scripts/ci/ensure-test-db.php
+global_test_lock_run php scripts/ci/ensure-test-db.php
 
 # 既定 (PROCESSES=1) では pest の parallel runner を使わない。
 # 1 プロセスは直列と等価である一方、`--parallel --processes=1` で Browser lane を
@@ -81,12 +127,9 @@ if [ "${PROCESSES}" -gt 1 ]; then
     PEST_PARALLEL_ARGS=(--parallel --processes="${PROCESSES}")
 fi
 
-# lock fd (9) を pest / playwright に継承させない。orphan run-server が fd を
-# 握ると lock が永久に解放されないため、実行コマンドへ渡す瞬間に 9>&- で閉じる
-# (親シェルの fd 9 = lock は保持されたまま)。
-#
 # レーンは順に実行し、**どれかが失敗したら最後に非ゼロで終わる**
 # (先頭レーンの失敗で後続レーンを飛ばすと WebKit の回帰を見落とすため)。
+# ロックは acquire で 1 回取ったまま 2 レーンを通して保持される (run は取得しない)。
 overall=0
 for lane in ${LANES}; do
     case "${lane}" in
@@ -102,8 +145,8 @@ for lane in ${LANES}; do
     echo "=== Browser lane: ${lane} (playwright: ${browser}) ==="
 
     code=0
-    vendor/bin/pest -c phpunit.browser.xml "${PEST_PARALLEL_ARGS[@]}" \
-        --browser "${browser}" "$@" 9>&- || code=$?
+    global_test_lock_run vendor/bin/pest -c phpunit.browser.xml "${PEST_PARALLEL_ARGS[@]}" \
+        --browser "${browser}" "$@" || code=$?
     if [ "${code}" -ne 0 ]; then
         overall="${code}"
     fi
diff --git a/scripts/run-test.sh b/scripts/run-test.sh
index 2d9d745..ca625be 100755
--- a/scripts/run-test.sh
+++ b/scripts/run-test.sh
@@ -1,38 +1,30 @@
 #!/usr/bin/env bash
 #
-# scripts/run-test.sh — composer test の pgsql 経路。同一 worktree の test 二重起動を
-# flock で直列化し、ensure (base DB 冪等 CREATE) → artisan test --parallel を実行する。
+# scripts/run-test.sh — composer test の pgsql 経路。グローバルテストロック配下で
+# ensure (base DB 冪等 CREATE) → artisan test --parallel を実行する。
 #
-# 同一 worktree で test を二重起動すると、RefreshDatabase の migrate:fresh と
-# paratest の per-worker DB が衝突し、後発側が不可解な failure を吐くため排他する。
+# 排他は scripts/global-test-lock.sh に一本化した (旧 worktree-local な
+# storage/framework/testing/ 配下のロックは廃止)。グローバルロックのスコープ
+# (同一 UID・同一マシン) は worktree-local のスコープを厳密に包含するため、
+# 内側のロックは 1 つも新しい事象を防がない (後方互換の並走を残さない)。
 #
-# lock は worktree-local (storage/framework/testing/ は worktree ごとに別実体) なので
-# 別 worktree の test は止めない (base DB 名 hash が異なり競合しない)。
-# flock(1) が無い環境 (素の macOS 等) では排他なしで実行する (devcontainer/CI では排他あり)。
+# 待ち方も変わった: 先行レーンがいる場合は **待つ** (旧実装は非ブロッキング取得で
+# 即エラー終了していた)。待機中は 30 秒ごとに保持者の身元が stderr に出る。
 
 set -euo pipefail
 cd "$(dirname "$0")/.."
 
-LOCK_FILE="storage/framework/testing/test.lock"   # worktree-local
-mkdir -p "$(dirname "$LOCK_FILE")"
-if command -v flock >/dev/null 2>&1; then
-    exec 9>"$LOCK_FILE"
-    if ! flock -n 9; then
-        echo "ERROR: another composer test is running in this worktree; refusing to start" >&2
-        echo "       lock file: $LOCK_FILE" >&2
-        exit 1
-    fi
-fi
+# shellcheck source=scripts/global-test-lock.sh
+. "$(pwd)/scripts/global-test-lock.sh"
+global_test_lock_acquire "composer test"
 
-php artisan config:clear --ansi
+# 以降、ロック配下の実行は必ず global_test_lock_run を通す
+# (fd 7 の非継承と、孫まで含めたプロセスグループの刈り取りを一箇所に集約するため)。
+global_test_lock_run php artisan config:clear --ansi
 
 # worktree 固有の base テスト DB (<slug>_test_<worktree-hash>) を冪等に用意する。
 # DB 名の安全検証 (dev DB hard-deny + allowlist) は tests/bootstrap.php の
 # 単一点ガード + ensure-test-db.php 内の二重防御が担う。
-php scripts/ci/ensure-test-db.php
+global_test_lock_run php scripts/ci/ensure-test-db.php
 
-# lock fd (9) を artisan test / paratest worker / そこから spawn される子プロセスに
-# 継承させない。orphan 化した子が fd 9 を握り続けると lock が解放されず次回の
-# flock -n 9 が継続的に拒否されるため、テスト実行コマンドへ渡す瞬間に 9>&- で閉じる
-# (親シェルの fd 9 = lock は保持されたまま)。
-php artisan test --parallel --processes=4 "$@" 9>&-
+global_test_lock_run php artisan test --parallel --processes=4 "$@"
diff --git a/scripts/run-vitest.sh b/scripts/run-vitest.sh
index c465d2a..a3a0a8d 100755
--- a/scripts/run-vitest.sh
+++ b/scripts/run-vitest.sh
@@ -1,30 +1,26 @@
 #!/usr/bin/env bash
 #
-# scripts/run-vitest.sh — workspace 単位で vitest を排他実行する。
+# scripts/run-vitest.sh — vitest をグローバルテストロック配下で実行する。
 #
-# 同一 workspace で vitest を二重起動すると .vite/ cache と coverage 出力先が
-# 同時に書かれて壊れることがある。flock(1) で workspace 派生キーの lock を握り、
-# 既に走っている場合は待たずに exit 1 で即終了する。
+# 旧実装は workspace realpath 由来の key で worktree ごとに別ロックを取り (= cross-worktree
+# 排他ゼロ)、かつ非ブロッキング取得で待たずに即エラー終了していた。両方ともグローバルロックへ
+# 置き換えた (排他は scripts/global-test-lock.sh に一本化)。
 #
-# 注意: lock は workspace 配下ではなく ${TMPDIR:-/tmp} 配下に置く(run-test.sh と同じ理由)。
+# JS レーンは DB もポートも掴まないが、Browser lane と CPU を奪い合うと
+# タイムアウト由来の偽赤を作るため対象に含める (方針判断。成功条件と見直し条件は
+# devnotes/20260804-2319-global-test-lock/conceptual-design.md)。
+#
+# **exec は使わない**: exec は fd 7 を閉じてロックを即解放してしまう
+# (旧実装の `exec pnpm exec vitest run` は fd 9 を vitest へ継承させることで偶然
+#  ロックを保っていたが、それは orphan による lock leak と表裏一体の形だった)。
 
 set -euo pipefail
+cd "$(dirname "$0")/.."
 
-WORKSPACE="$(cd "$(dirname "$0")/.." && pwd)"
-LOCK_DIR="${TMPDIR:-/tmp}"
-LOCK_KEY="$(printf '%s' "$WORKSPACE" | shasum -a 256 | cut -c1-16)"
-LOCK_FILE="$LOCK_DIR/app-vitest-${LOCK_KEY}.lock"
-
-# flock(1) が無い環境 (素の macOS 等) では排他なしで実行する (devcontainer/Linux では排他あり)
-if command -v flock >/dev/null 2>&1; then
-    exec 9>"$LOCK_FILE"
-    if ! flock -n 9; then
-        echo "ERROR: vitest is already running in this workspace." >&2
-        echo "       workspace: $WORKSPACE" >&2
-        echo "       lock file: $LOCK_FILE" >&2
-        exit 1
-    fi
-fi
+# shellcheck source=scripts/global-test-lock.sh
+. "$(pwd)/scripts/global-test-lock.sh"
+global_test_lock_acquire "pnpm test"
 
-cd "$WORKSPACE"
-exec pnpm exec vitest run "$@"
+status=0
+global_test_lock_run pnpm exec vitest run "$@" || status=$?
+exit "${status}"
diff --git a/scripts/verify-global-test-lock.sh b/scripts/verify-global-test-lock.sh
new file mode 100755
index 0000000..2665a17
--- /dev/null
+++ b/scripts/verify-global-test-lock.sh
@@ -0,0 +1,1376 @@
+#!/usr/bin/env bash
+#
+# scripts/verify-global-test-lock.sh — グローバルテストロック (scripts/global-test-lock.sh) の
+# **並行挙動** を実プロセスで検証する恒久スイート (層 1)。
+#
+# ここで検証する性質 (ブロッキング待機 / fd 非継承 / プロセスグループの刈り取り /
+# シグナル収束 / 再入 / 終了コード) は、プロセスを実際に走らせないと観測できない。
+# PHP プロセス内 (層 2 = tests/Architecture/GlobalTestLockInventoryTest.php) からは
+# 観測できないため、層を分けている。
+#
+# **層 2 から本スイートを実行してはならない** (非交渉): 層 2 は composer test の内側
+# = グローバルロック保持中に走るため、ここを起動すると自分自身と競合する。
+#
+# 実ロック (/tmp/global-test-lane-<uid>.d) には一切触れない。常に mktemp -d 配下の
+# scratch を GLOBAL_TEST_LOCK_DIR で使い、heartbeat / grace を縮めて実行時間を抑える。
+#
+# 使い方:
+#   bash scripts/verify-global-test-lock.sh
+#
+# 出力: 各ケースを C01..C24 の ID 付きで PASS / FAIL / SKIP 報告し、
+#       最後に集計を出す。FAIL が 1 つでもあれば非 0 で終了する。
+#       **skip 数を必ず出す** (偽グリーンを避けるため)。
+#
+# set -e は使わない: 本スイートは「失敗するはずのコマンド」を意図的に実行して
+# 終了コードを観測するため、-e があると 1 件目で全体が落ちる。
+set -uo pipefail
+
+REPO_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
+LIB="${REPO_ROOT}/scripts/global-test-lock.sh"
+WRAP="${REPO_ROOT}/scripts/with-global-test-lock.sh"
+BROWSER_LANE="${REPO_ROOT}/scripts/run-browser-test.sh"
+
+PASS=0
+FAIL=0
+SKIP=0
+LANE_PID=""
+LANE_RC=0
+
+# scratch は全て TOKEN を含むパスに置く。こうすると `pgrep -f "$TOKEN"` だけで
+# 本スイート由来の残党を機械的に検出・掃除できる (C11)。
+TOKEN="gtlverify-$$-${RANDOM}${RANDOM}"
+SCRATCH="$(mktemp -d)"
+WORK="${SCRATCH}/${TOKEN}"
+mkdir -p "${WORK}"
+
+# 検証用の縮めた値 (レーンへは環境変数で渡る)
+export GLOBAL_TEST_LOCK_HEARTBEAT_SECS=1
+export GLOBAL_TEST_LOCK_GRACE_SECS=2
+# 実ロックを絶対に掴まないため、継承されうる上書きを一旦落とす
+unset GLOBAL_TEST_LOCK_DIR
+unset GLOBAL_TEST_LOCK_NONCE
+
+t_ok() { PASS=$((PASS + 1)); printf '  [PASS] %s %s\n' "$1" "$2"; }
+t_fail() { FAIL=$((FAIL + 1)); printf '  [FAIL] %s %s\n' "$1" "$2"; }
+t_skip() { SKIP=$((SKIP + 1)); printf '  [SKIP] %s %s\n' "$1" "$2"; }
+
+suite_cleanup() {
+    local pid
+    for pid in $(pgrep -f "${TOKEN}" 2>/dev/null || true); do
+        [ "${pid}" = "$$" ] && continue
+        kill -KILL "${pid}" 2>/dev/null || true
+    done
+    rm -rf "${SCRATCH}"
+}
+trap suite_cleanup EXIT
+
+have() { command -v "$1" >/dev/null 2>&1; }
+
+HAVE_FLOCK=0
+have flock && HAVE_FLOCK=1
+HAVE_PS=0
+have ps && have pgrep && HAVE_PS=1
+HAVE_PROC=0
+[ -d /proc/self/fd ] && HAVE_PROC=1
+
+# ケースごとに未作成の lock dir パスを 1 つ払い出す。
+# 連番はファイルで持つ: 本関数は $(new_dir) = サブシェルで呼ばれるため、
+# シェル変数のインクリメントは呼び出し元へ伝播せず全ケースが同じ dir を共有してしまう。
+new_dir() {
+    local n
+    n="$(cat "${WORK}/.seq" 2>/dev/null || echo 0)"
+    n=$((n + 1))
+    printf '%s\n' "${n}" >"${WORK}/.seq"
+    printf '%s/lock-%03d\n' "${WORK}" "${n}"
+}
+
+# 第三者視点でロックが保持されているかを見る (別プロセスから flock -n を試す)。
+lock_is_held() {
+    local f="$1/lock"
+    [ -e "${f}" ] || return 1
+    flock -n "${f}" true >/dev/null 2>&1 && return 1
+    return 0
+}
+lock_is_free() { ! lock_is_held "$1"; }
+
+# 条件ポーリング (sleep 決め打ちを禁止する: 環境負荷で不安定になるため)。
+poll_until() {
+    local limit="$1"
+    shift
+    local i=0 max=$((limit * 10))
+    while [ "${i}" -lt "${max}" ]; do
+        if "$@"; then return 0; fi
+        sleep 0.1
+        i=$((i + 1))
+    done
+    return 1
+}
+
+file_exists() { [ -f "$1" ]; }
+
+# zombie (Z) は「消滅」とみなす — ライブラリの _gtl_group_alive と同じ判定にする。
+# 本コンテナの PID 1 は子を reap しないため、孤児化して死んだプロセスは Z のまま残り、
+# kill -0 では「生存」と誤判定される (fd もポートも保持しないので実体は消滅済み)。
+proc_gone() {
+    local st
+    st="$(ps -o stat= -p "$1" 2>/dev/null | tr -d ' ')"
+    [ -z "${st}" ] && return 0
+    case "${st}" in Z*) return 0 ;; esac
+    return 1
+}
+proc_alive() { ! proc_gone "$1"; }
+
+# スイート由来で **実際に生きている** プロセス (zombie は消滅済みとみなす)。
+live_strays() {
+    local pid out=""
+    for pid in $(pgrep -f "${TOKEN}" 2>/dev/null || true); do
+        [ "${pid}" = "$$" ] && continue
+        proc_gone "${pid}" && continue
+        out="${out} ${pid}"
+    done
+    printf '%s' "${out# }"
+}
+no_strays() { [ -z "$(live_strays)" ]; }
+
+has_children() { [ -n "$(pgrep -P "$1" 2>/dev/null || true)" ]; }
+pattern_running() { [ -n "$(pgrep -f "$1" 2>/dev/null || true)" ]; }
+is_orphan() { [ "$(ppid_of "$1")" = "1" ]; }
+
+run_lane_fg() {
+    local d="$1" errf="$2"
+    shift 2
+    LANE_RC=0
+    GLOBAL_TEST_LOCK_DIR="${d}" bash "${WRAP}" "$@" >"${errf}.out" 2>"${errf}" || LANE_RC=$?
+}
+
+# レーンは **monitor mode を有効にして** 起動する。job control を切ったまま `&` で
+# 起動すると POSIX 規定によりレーンの SIGINT/SIGQUIT が SIG_IGN で開始され、
+# 「入口で無視されたシグナルは trap できない」ため INT の契約 (128+2) を観測できない。
+# 実運用では端末から前景実行されるので、job control ありが実挙動に近い。
+start_lane() {
+    local d="$1" errf="$2" prev=0
+    shift 2
+    case "$-" in *m*) prev=1 ;; esac
+    set -m
+    GLOBAL_TEST_LOCK_DIR="${d}" bash "${WRAP}" "$@" >"${errf}.out" 2>"${errf}" &
+    LANE_PID=$!
+    [ "${prev}" = "1" ] || set +m
+}
+
+pgid_of() { ps -o pgid= -p "$1" 2>/dev/null | tr -d ' '; }
+ppid_of() { ps -o ppid= -p "$1" 2>/dev/null | tr -d ' '; }
+
+# root の子孫 pid を全て列挙する (プロセスグループ離脱の検出に使う)。
+descendants_of() {
+    local root="$1" all frontier next found pid kid kids
+    all="$(ps -A -o pid= -o ppid= 2>/dev/null)"
+    frontier="${root}"
+    found=""
+    while [ -n "${frontier// /}" ]; do
+        next=""
+        for pid in ${frontier}; do
+            kids="$(printf '%s\n' "${all}" | awk -v p="${pid}" '{ if ($2 == p) print $1 }')"
+            for kid in ${kids}; do
+                found="${found} ${kid}"
+                next="${next} ${kid}"
+            done
+        done
+        frontier="${next}"
+    done
+    printf '%s\n' "${found}"
+}
+
+# ---------------------------------------------------------------------------
+# 検証用のヘルパースクリプト群 (パスに TOKEN を含むので pgrep で追跡できる)
+# ---------------------------------------------------------------------------
+SLEEPER="${WORK}/sleeper.sh"
+cat >"${SLEEPER}" <<'EOF'
+#!/usr/bin/env bash
+# 検証用のダミーレーン本体。$1 秒眠るだけ。
+sleep "${1:-5}"
+EOF
+
+SPAWNER="${WORK}/spawn-grandchild.sh"
+cat >"${SPAWNER}" <<'EOF'
+#!/usr/bin/env bash
+# 直接子。孫を残して **先に** 終了する ($1=sleeper $2=秒 $3=孫 pid の記録先)。
+bash "$1" "$2" &
+echo $! >"$3"
+exit 0
+EOF
+
+IGNORER="${WORK}/ignore-signals.sh"
+cat >"${IGNORER}" <<'EOF'
+#!/usr/bin/env bash
+# INT/TERM を無視する直接子 + 孫 ($1=sleeper $2=秒 $3=孫 pid の記録先)。
+# SIG_IGN は fork/exec を越えて継承されるため、孫 (sleep) も無視する。
+trap '' INT TERM
+bash "$1" "$2" &
+echo $! >"$3"
+bash "$1" "$2"
+EOF
+
+FDCHECK="${WORK}/fd-check.sh"
+cat >"${FDCHECK}" <<'EOF'
+#!/usr/bin/env bash
+# ロック fd (7) が子へ継承されていないことを確認する。
+if [ -e "/proc/self/fd/7" ]; then echo "fd7=leak"; else echo "fd7=ok"; fi
+EOF
+
+PGIDCHECK="${WORK}/pgid-check.sh"
+cat >"${PGIDCHECK}" <<'EOF'
+#!/usr/bin/env bash
+# 直接子が専用プロセスグループのリーダーで、孫も同じグループに残ることを出力する。
+self_pgid="$(ps -o pgid= -p $$ 2>/dev/null | tr -d ' ')"
+echo "self=$$"
+echo "self_pgid=${self_pgid}"
+bash -c 'echo "child_pgid=$(ps -o pgid= -p $$ 2>/dev/null | tr -d " ")"'
+# 外側から直接子とその子孫を観測できるだけの寿命を持たせる
+sleep 3
+EOF
+
+REENTER="${WORK}/reenter.sh"
+cat >"${REENTER}" <<'EOF'
+#!/usr/bin/env bash
+# 保持中の子孫から再度ラッパを呼ぶ (再入)。$1=wrapper $2=lockdir $3=結果ファイル
+set -uo pipefail
+before="$(head -n 1 "$2/owner" 2>/dev/null || echo MISSING)"
+rc=0
+bash "$1" bash -c 'exit 0' >/dev/null 2>&1 || rc=$?
+after="$(head -n 1 "$2/owner" 2>/dev/null || echo MISSING)"
+printf 'rc=%s\nbefore=%s\nafter=%s\n' "${rc}" "${before}" "${after}" >"$3"
+EOF
+
+MONITORCHECK="${WORK}/monitor-check.sh"
+cat >"${MONITORCHECK}" <<'EOF'
+#!/usr/bin/env bash
+# monitor mode (set -m) がレーン実行の前後で復元されることを確認する。$1=lib $2=lockdir
+set -uo pipefail
+# shellcheck source=/dev/null
+. "$1"
+before=off
+case "$-" in *m*) before=on ;; esac
+global_test_lock_acquire "C16 monitor"
+global_test_lock_run true
+after=off
+case "$-" in *m*) after=on ;; esac
+echo "monitor_before=${before} monitor_after=${after}"
+EOF
+
+HOOKLANE="${WORK}/hook-lane.sh"
+cat >"${HOOKLANE}" <<'EOF'
+#!/usr/bin/env bash
+# lane 固有の EXIT フックが **ロック保持中に** 走ることを確認する。
+# $1=lib $2=lockdir $3=結果ファイル
+set -uo pipefail
+GTL_LOCKDIR="$2"
+GTL_RESULT="$3"
+# shellcheck source=/dev/null
+. "$1"
+
+lane_exit_hook() {
+    # 別プロセスから flock -n を試す。保持中なら失敗する (= held)。
+    if flock -n "${GTL_LOCKDIR}/lock" true >/dev/null 2>&1; then
+        echo "hook_lock=free" >>"${GTL_RESULT}"
+    else
+        echo "hook_lock=held" >>"${GTL_RESULT}"
+    fi
+}
+
+global_test_lock_acquire "C17 hook lane"
+global_test_lock_on_exit lane_exit_hook
+global_test_lock_run true
+echo "lane_done=1" >>"${GTL_RESULT}"
+EOF
+
+DOUBLEACQ="${WORK}/double-acquire.sh"
+cat >"${DOUBLEACQ}" <<'EOF'
+#!/usr/bin/env bash
+# 同一プロセスからの二重 acquire で owner が落ちないことを確認する。
+# $1=lib $2=lockdir $3=結果ファイル $4=sleeper
+set -uo pipefail
+GTL_RESULT="$3"
+# shellcheck source=/dev/null
+. "$1"
+global_test_lock_acquire "C20 first"
+global_test_lock_acquire "C20 second"
+echo "mode=${_GTL_MODE}" >"${GTL_RESULT}"
+global_test_lock_run bash -c 'if [ -e /proc/self/fd/7 ]; then echo fd7=leak; else echo fd7=ok; fi' >>"${GTL_RESULT}"
+global_test_lock_run bash "$4" 3
+EOF
+
+ABNORMAL="${WORK}/abnormal-exit.sh"
+cat >"${ABNORMAL}" <<'EOF'
+#!/usr/bin/env bash
+# 子を起動した **後** に内部エラーで EXIT へ抜けるケース。
+# 残党を残さずグループを収束させてからロックを解放しなければならない。
+# $1=lib $2=ignorer $3=sleeper $4=孫 pid の記録先 $5=直接子 pid の記録先
+set -uo pipefail
+# shellcheck source=/dev/null
+. "$1"
+global_test_lock_acquire "C22 abnormal"
+
+# global_test_lock_run の内部状態 (子を起動した直後) を再現する。
+set -m
+bash "$2" "$3" 60 "$4" 7>&- &
+_GTL_CHILD_PID=$!
+set +m
+_GTL_CHILD_PGID="${_GTL_CHILD_PID}"
+echo "${_GTL_CHILD_PID}" >"$5"
+
+# 子が signal trap を張り終える前にエラーを起こすと、TERM 無視の検証にならない
+# (起動レースで素直に死ぬ)。孫 pid の記録をもって起動完了とみなす。
+i=0
+while [ ! -s "$4" ] && [ "${i}" -lt 100 ]; do
+    sleep 0.1
+    i=$((i + 1))
+done
+
+_gtl_die "simulated internal error after spawning the lane"
+EOF
+
+SURVIVOR="${WORK}/kill-survivor.sh"
+cat >"${SURVIVOR}" <<'EOF'
+#!/usr/bin/env bash
+# SIGKILL を生き延びるプロセスグループの模擬 ($1=lib $2=sleeper)。
+# _gtl_group_alive は shell 関数なので、source 後に再定義して注入できる。
+set -uo pipefail
+# shellcheck source=/dev/null
+. "$1"
+_gtl_group_alive() { return 0; }   # 常に「生存」
+global_test_lock_acquire "C23 survivor"
+global_test_lock_run bash "$2" 2
+echo "SHOULD_NOT_REACH"
+EOF
+
+chmod +x "${SLEEPER}" "${SPAWNER}" "${IGNORER}" "${FDCHECK}" "${PGIDCHECK}" \
+    "${REENTER}" "${MONITORCHECK}" "${HOOKLANE}" "${DOUBLEACQ}" "${ABNORMAL}" "${SURVIVOR}"
+
+# ---------------------------------------------------------------------------
+# C01: lock path の導出
+# ---------------------------------------------------------------------------
+case_c01() {
+    local id="C01" got expected warn
+    expected="/tmp/global-test-lane-$(id -u).d"
+    got="$(
+        # shellcheck source=/dev/null
+        . "${LIB}"
+        _gtl_lock_dir 2>/dev/null
+    )"
+    if [ "${got}" = "${expected}" ]; then
+        t_ok "${id}" "既定の lock dir が ${expected}"
+    else
+        t_fail "${id}" "既定の lock dir が想定と違う (got=${got} want=${expected})"
+    fi
+
+    warn="$(
+        # shellcheck source=/dev/null
+        . "${LIB}"
+        export GLOBAL_TEST_LOCK_DIR="${WORK}/c01-override"
+        _gtl_lock_dir 2>&1 >/dev/null
+    )"
+    case "${warn}" in
+        *"using override lock dir"*) t_ok "${id}" "GLOBAL_TEST_LOCK_DIR 上書き時に警告が出る" ;;
+        *) t_fail "${id}" "上書き時の警告が出ない (stderr=${warn})" ;;
+    esac
+}
+
+# ---------------------------------------------------------------------------
+# C02: lock dir の fail-secure (symlink / 緩い mode)
+# ---------------------------------------------------------------------------
+case_c02() {
+    local id="C02" err
+    if [ "${HAVE_FLOCK}" -eq 0 ]; then
+        t_skip "${id}" "flock(1) が無いので lock dir 検証まで到達しない"
+        return
+    fi
+
+    mkdir -p -m 700 "${WORK}/c02-real"
+    ln -sfn "${WORK}/c02-real" "${WORK}/c02-link"
+    err="${WORK}/c02-link.err"
+    run_lane_fg "${WORK}/c02-link" "${err}" true
+    if [ "${LANE_RC}" -ne 0 ] && grep -q "symlink" "${err}"; then
+        t_ok "${id}" "symlink の lock dir で明示エラー停止 (rc=${LANE_RC})"
+    else
+        t_fail "${id}" "symlink の lock dir を拒否しない (rc=${LANE_RC})"
+    fi
+
+    mkdir -p -m 755 "${WORK}/c02-mode"
+    chmod 755 "${WORK}/c02-mode"
+    err="${WORK}/c02-mode.err"
+    run_lane_fg "${WORK}/c02-mode" "${err}" true
+    if [ "${LANE_RC}" -ne 0 ] && grep -q "mode must be 700" "${err}"; then
+        t_ok "${id}" "mode 755 の lock dir で明示エラー停止 (rc=${LANE_RC})"
+    else
+        t_fail "${id}" "緩い mode の lock dir を拒否しない (rc=${LANE_RC})"
+    fi
+}
+
+# ---------------------------------------------------------------------------
+# C03 / C04: ブロッキング取得 と heartbeat
+# ---------------------------------------------------------------------------
+case_c03_c04() {
+    local id3="C03" id4="C04" d a_pid b_pid start elapsed hb
+    if [ "${HAVE_FLOCK}" -eq 0 ]; then
+        t_skip "${id3}" "flock(1) 不在 (排他しないため待機が起きない)"
+        t_skip "${id4}" "flock(1) 不在"
+        return
+    fi
+
+    d="$(new_dir)"
+    start_lane "${d}" "${WORK}/c03-a.err" bash "${SLEEPER}" 5
+    a_pid="${LANE_PID}"
+    if ! poll_until 10 lock_is_held "${d}"; then
+        t_fail "${id3}" "1 本目がロックを取得できない"
+        t_skip "${id4}" "前提 (1 本目の取得) が崩れた"
+        kill -KILL "${a_pid}" 2>/dev/null || true
+        return
+    fi
+
+    start="$(date +%s)"
+    start_lane "${d}" "${WORK}/c03-b.err" true
+    b_pid="${LANE_PID}"
+    wait "${b_pid}" 2>/dev/null
+    elapsed=$(($(date +%s) - start))
+    wait "${a_pid}" 2>/dev/null
+
+    if [ "${elapsed}" -ge 3 ]; then
+        t_ok "${id3}" "2 本目は即エラーせず待機して実行された (${elapsed}s)"
+    else
+        t_fail "${id3}" "2 本目が待機していない (${elapsed}s。旧 flock -n の回帰)"
+    fi
+
+    hb="$(grep 'waiting' "${WORK}/c03-b.err" 2>/dev/null | head -n 1)"
+    if [ -n "${hb}" ] &&
+        printf '%s' "${hb}" | grep -q 'pid=' &&
+        printf '%s' "${hb}" | grep -q 'lane=' &&
+        printf '%s' "${hb}" | grep -q 'worktree='; then
+        t_ok "${id4}" "待機中の heartbeat に保持者の身元が出る"
+    else
+        t_fail "${id4}" "heartbeat が出ない / 身元を含まない (line=${hb})"
+    fi
+}
+
+# ---------------------------------------------------------------------------
+# C05: 非競合時は heartbeat が 1 行も出ない (CI ログを汚さない)
+# ---------------------------------------------------------------------------
+case_c05() {
+    local id="C05" d err
+    d="$(new_dir)"
+    err="${WORK}/c05.err"
+    run_lane_fg "${d}" "${err}" true
+    if [ "${LANE_RC}" -eq 0 ] && ! grep -q 'waiting' "${err}"; then
+        t_ok "${id}" "無競合では heartbeat が 1 行も出ない"
+    else
+        t_fail "${id}" "無競合なのに heartbeat が出た (rc=${LANE_RC})"
+    fi
+}
+
+# ---------------------------------------------------------------------------
+# C06: fd 7 の非継承 (レーン本体 / heartbeat 子の双方)
+# ---------------------------------------------------------------------------
+case_c06() {
+    local id="C06" d out a_pid b_pid kids kid leaked checked
+    if [ "${HAVE_PROC}" -eq 0 ]; then
+        t_skip "${id}" "/proc が無いので fd 継承を観測できない"
+        return
+    fi
+
+    d="$(new_dir)"
+    run_lane_fg "${d}" "${WORK}/c06.err" bash "${FDCHECK}"
+    out="$(cat "${WORK}/c06.err.out" 2>/dev/null)"
+    if [ "${out}" = "fd7=ok" ]; then
+        t_ok "${id}" "レーン本体に fd 7 が継承されない"
+    else
+        t_fail "${id}" "レーン本体が fd 7 を継承している (out=${out})"
+    fi
+
+    if [ "${HAVE_FLOCK}" -eq 0 ] || [ "${HAVE_PS}" -eq 0 ]; then
+        t_skip "${id}" "heartbeat 子の fd 検査 (flock / ps 不在)"
+        return
+    fi
+
+    start_lane "${d}" "${WORK}/c06-a.err" bash "${SLEEPER}" 4
+    a_pid="${LANE_PID}"
+    if ! poll_until 10 lock_is_held "${d}"; then
+        t_fail "${id}" "heartbeat 検査の前提 (1 本目の取得) が崩れた"
+        kill -KILL "${a_pid}" 2>/dev/null || true
+        return
+    fi
+    start_lane "${d}" "${WORK}/c06-b.err" true
+    b_pid="${LANE_PID}"
+
+    leaked=0
+    checked=0
+    # 待機中の 2 本目には heartbeat 子がいる。そこに fd 7 が渡っていないことを見る。
+    #
+    # 併走する `flock 7` の子プロセスは **fd 7 を正当に保持する** (それがブロッキング取得の
+    # 実体) ので対象外にする。heartbeat は shell 関数の background 実行なので、
+    # argv はラッパのものをそのまま引き継いでいる。それを目印に選別する。
+    if poll_until 5 has_children "${b_pid}"; then
+        kids="$(pgrep -P "${b_pid}" 2>/dev/null || true)"
+        for kid in ${kids}; do
+            case "$(ps -o args= -p "${kid}" 2>/dev/null || true)" in
+                *with-global-test-lock.sh*) : ;;
+                *) continue ;;
+            esac
+            checked=$((checked + 1))
+            [ -e "/proc/${kid}/fd/7" ] && leaked=$((leaked + 1))
+        done
+    fi
+
+    wait "${b_pid}" 2>/dev/null
+    wait "${a_pid}" 2>/dev/null
+
+    if [ "${checked}" -eq 0 ]; then
+        t_skip "${id}" "heartbeat 子を観測できなかった (タイミング)"
+    elif [ "${leaked}" -eq 0 ]; then
+        t_ok "${id}" "heartbeat 子にも fd 7 が渡らない (checked=${checked})"
+    else
+        t_fail "${id}" "heartbeat 子が fd 7 を保持している (leaked=${leaked})"
+    fi
+}
+
+# ---------------------------------------------------------------------------
+# C07: 保持期間 = コマンド実行中 (exec 回帰の負のコントロール)
+# ---------------------------------------------------------------------------
+case_c07() {
+    local id="C07" d a_pid
+    if [ "${HAVE_FLOCK}" -eq 0 ]; then
+        t_skip "${id}" "flock(1) 不在"
+        return
+    fi
+    d="$(new_dir)"
+    start_lane "${d}" "${WORK}/c07.err" bash "${SLEEPER}" 3
+    a_pid="${LANE_PID}"
+    if poll_until 10 lock_is_held "${d}"; then
+        t_ok "${id}" "コマンド実行中はロックが保持されている"
+    else
+        t_fail "${id}" "実行中にロックが保持されていない (exec 回帰)"
+    fi
+    wait "${a_pid}" 2>/dev/null
+    if poll_until 10 lock_is_free "${d}"; then
+        t_ok "${id}" "レーン終了後にロックが解放される"
+    else
+        t_fail "${id}" "レーン終了後もロックが解放されない"
+    fi
+}
+
+# ---------------------------------------------------------------------------
+# C08: 孫の刈り取り (直接子が先に終了しても孫が消えるまで離さない)
+# ---------------------------------------------------------------------------
+case_c08() {
+    local id="C08" d gpidf gpid a_pid start elapsed
+    if [ "${HAVE_FLOCK}" -eq 0 ] || [ "${HAVE_PS}" -eq 0 ]; then
+        t_skip "${id}" "flock / ps 不在"
+        return
+    fi
+    d="$(new_dir)"
+    gpidf="${WORK}/c08.gpid"
+    rm -f "${gpidf}"
+    start="$(date +%s)"
+    start_lane "${d}" "${WORK}/c08.err" bash "${SPAWNER}" "${SLEEPER}" 30 "${gpidf}"
+    a_pid="${LANE_PID}"
+
+    if ! poll_until 10 file_exists "${gpidf}"; then
+        t_fail "${id}" "孫が起動しなかった"
+        kill -KILL "${a_pid}" 2>/dev/null || true
+        return
+    fi
+    gpid="$(cat "${gpidf}")"
+
+    # 直接子は即終了する。それでも孫が生きている間はロックが保持されていなければならない。
+    sleep 1
+    if lock_is_held "${d}" && proc_alive "${gpid}"; then
+        t_ok "${id}" "直接子の終了後も孫が居る間はロックを保持する"
+    else
+        t_fail "${id}" "直接子の終了時点でロックが解放された (孫が孤児化する)"
+    fi
+
+    wait "${a_pid}" 2>/dev/null
+    elapsed=$(($(date +%s) - start))
+    if poll_until 10 lock_is_free "${d}" && poll_until 10 proc_gone "${gpid}"; then
+        t_ok "${id}" "猶予超過で孫を刈り取ってから解放した (${elapsed}s)"
+    else
+        t_fail "${id}" "孫が残ったまま / ロックが解放されない (${elapsed}s)"
+    fi
+    kill -KILL "${gpid}" 2>/dev/null || true
+}
+
+# ---------------------------------------------------------------------------
+# C09: シグナル収束 (INT/TERM を無視する子と孫でも deadlock しない)
+# ---------------------------------------------------------------------------
+case_c09() {
+    local id="C09" d gpidf gpid a_pid start elapsed
+    if [ "${HAVE_FLOCK}" -eq 0 ] || [ "${HAVE_PS}" -eq 0 ]; then
+        t_skip "${id}" "flock / ps 不在"
+        return
+    fi
+    d="$(new_dir)"
+    gpidf="${WORK}/c09.gpid"
+    rm -f "${gpidf}"
+    start_lane "${d}" "${WORK}/c09.err" bash "${IGNORER}" "${SLEEPER}" 120 "${gpidf}"
+    a_pid="${LANE_PID}"
+
+    if ! poll_until 10 file_exists "${gpidf}" || ! poll_until 10 lock_is_held "${d}"; then
+        t_fail "${id}" "前提 (無視する子孫の起動 + ロック取得) が崩れた"
+        kill -KILL "${a_pid}" 2>/dev/null || true
+        return
+    fi
+    gpid="$(cat "${gpidf}")"
+
+    start="$(date +%s)"
+    kill -TERM "${a_pid}" 2>/dev/null || true
+    wait "${a_pid}" 2>/dev/null
+    elapsed=$(($(date +%s) - start))
+
+    if poll_until 20 lock_is_free "${d}"; then
+        t_ok "${id}" "TERM 無視の子孫でも猶予超過で強制終了して解放した (${elapsed}s)"
+    else
+        t_fail "${id}" "ロックが解放されない (deadlock。wait を先に置いた回帰)"
+    fi
+    if poll_until 10 proc_gone "${gpid}"; then
+        t_ok "${id}" "TERM 無視の孫も刈り取られた"
+    else
+        t_fail "${id}" "TERM 無視の孫が残存している"
+    fi
+    kill -KILL "${gpid}" 2>/dev/null || true
+}
+
+# ---------------------------------------------------------------------------
+# C10: 終了コード契約
+# ---------------------------------------------------------------------------
+case_c10() {
+    local id="C10" d a_pid rc
+    d="$(new_dir)"
+    run_lane_fg "${d}" "${WORK}/c10-0.err" true
+    if [ "${LANE_RC}" -eq 0 ]; then
+        t_ok "${id}" "成功時の終了コードが 0"
+    else
+        t_fail "${id}" "成功時の終了コードが 0 でない (${LANE_RC})"
+    fi
+
+    run_lane_fg "${d}" "${WORK}/c10-3.err" bash -c 'exit 3'
+    if [ "${LANE_RC}" -eq 3 ]; then
+        t_ok "${id}" "非 0 の終了コードが素通しされる (3)"
+    else
+        t_fail "${id}" "非 0 の終了コードが素通しされない (${LANE_RC})"
+    fi
+
+    if [ "${HAVE_FLOCK}" -eq 0 ]; then
+        t_skip "${id}" "シグナル時の 128+signo (flock 不在)"
+        return
+    fi
+
+    start_lane "${d}" "${WORK}/c10-int.err" bash "${SLEEPER}" 60
+    a_pid="${LANE_PID}"
+    poll_until 10 lock_is_held "${d}"
+    kill -INT "${a_pid}" 2>/dev/null || true
+    rc=0
+    wait "${a_pid}" 2>/dev/null || rc=$?
+    if [ "${rc}" -eq 130 ]; then
+        t_ok "${id}" "INT で 128+2 = 130 を返す"
+    else
+        t_fail "${id}" "INT の終了コードが 130 でない (${rc})"
+    fi
+
+    start_lane "${d}" "${WORK}/c10-term.err" bash "${SLEEPER}" 60
+    a_pid="${LANE_PID}"
+    poll_until 10 lock_is_held "${d}"
+    kill -TERM "${a_pid}" 2>/dev/null || true
+    rc=0
+    wait "${a_pid}" 2>/dev/null || rc=$?
+    if [ "${rc}" -eq 143 ]; then
+        t_ok "${id}" "TERM で 128+15 = 143 を返す"
+    else
+        t_fail "${id}" "TERM の終了コードが 143 でない (${rc})"
+    fi
+}
+
+# ---------------------------------------------------------------------------
+# C12: プロセスグループ (PGID == PID / 子孫が自発的に離脱しない)
+# ---------------------------------------------------------------------------
+case_c12_probe() {
+    # $1 = ラベル, 以降 = ロック配下で走らせるコマンド
+    local id="C12" label="$1" d child pgid desc p bad=0
+    shift
+    d="$(new_dir)"
+    start_lane "${d}" "${WORK}/c12-$$.err" "$@"
+    local lane="${LANE_PID}"
+    if ! poll_until 10 has_children "${lane}"; then
+        t_skip "${id}" "${label}: 直接子を観測できなかった"
+        kill -KILL "${lane}" 2>/dev/null || true
+        wait "${lane}" 2>/dev/null
+        return
+    fi
+    child="$(pgrep -P "${lane}" 2>/dev/null | head -n 1)"
+    pgid="$(pgid_of "${child}")"
+
+    if [ "${pgid}" = "${child}" ]; then
+        t_ok "${id}" "${label}: 直接子が専用プロセスグループのリーダー (PGID==PID)"
+    else
+        t_fail "${id}" "${label}: PGID != PID (pid=${child} pgid=${pgid})"
+    fi
+
+    # 子孫が生えるまで待つ (即座に見ると空振りして vacuous な PASS になる)
+    poll_until 3 has_children "${child}" || true
+    desc="$(descendants_of "${child}")"
+    for p in ${desc}; do
+        [ "$(pgid_of "${p}")" = "${child}" ] || bad=$((bad + 1))
+    done
+    if [ "${bad}" -eq 0 ]; then
+        t_ok "${id}" "${label}: 子孫が専用グループから離脱していない (n=$(printf '%s' "${desc}" | wc -w))"
+    else
+        t_fail "${id}" "${label}: ${bad} 個の子孫がグループを離脱した"
+    fi
+
+    kill -KILL -"${child}" 2>/dev/null || true
+    kill -KILL "${lane}" 2>/dev/null || true
+    wait "${lane}" 2>/dev/null
+    poll_until 10 lock_is_free "${d}" || true
+}
+
+case_c12() {
+    local id="C12"
+    if [ "${HAVE_PS}" -eq 0 ]; then
+        t_skip "${id}" "ps / pgrep 不在"
+        return
+    fi
+    case_c12_probe "shell" bash "${PGIDCHECK}"
+    case_c12_probe "grandchild" bash "${IGNORER}" "${SLEEPER}" 20 "${WORK}/c12.gpid"
+
+    # 現行レーンを構成する実バイナリでも離脱しないことを best-effort で確認する。
+    if have php; then
+        case_c12_probe "php" php -r 'sleep(5);'
+    else
+        t_skip "${id}" "php 不在 (Feature / Browser lane 相当の確認)"
+    fi
+    if have node; then
+        case_c12_probe "node" node -e 'setTimeout(function(){}, 5000)'
+    else
+        t_skip "${id}" "node 不在 (JS lane 相当の確認)"
+    fi
+    if have pnpm; then
+        case_c12_probe "pnpm" pnpm exec node -e 'setTimeout(function(){}, 5000)'
+    else
+        t_skip "${id}" "pnpm 不在 (JS lane 相当の確認)"
+    fi
+}
+
+# ---------------------------------------------------------------------------
+# C13: 再入 (nonce 一致) — deadlock せず素通りし、外側 sidecar が維持される
+# ---------------------------------------------------------------------------
+case_c13() {
+    local id="C13" d resf a_pid rc before after
+    if [ "${HAVE_FLOCK}" -eq 0 ]; then
+        t_skip "${id}" "flock(1) 不在 (再入判定に到達しない)"
+        return
+    fi
+    d="$(new_dir)"
+    resf="${WORK}/c13.result"
+    rm -f "${resf}"
+    start_lane "${d}" "${WORK}/c13.err" bash "${REENTER}" "${WRAP}" "${d}" "${resf}"
+    a_pid="${LANE_PID}"
+
+    if ! poll_until 20 file_exists "${resf}"; then
+        t_fail "${id}" "再入で deadlock した (20s 以内に完了しない)"
+        kill -KILL "${a_pid}" 2>/dev/null || true
+        wait "${a_pid}" 2>/dev/null
+        return
+    fi
+    wait "${a_pid}" 2>/dev/null
+
+    rc="$(grep '^rc=' "${resf}" | cut -d= -f2-)"
+    before="$(grep '^before=' "${resf}" | cut -d= -f2-)"
+    after="$(grep '^after=' "${resf}" | cut -d= -f2-)"
+
+    if [ "${rc}" = "0" ]; then
+        t_ok "${id}" "再入した子が deadlock せず正常終了する"
+    else
+        t_fail "${id}" "再入した子の終了コードが 0 でない (${rc})"
+    fi
+    if [ -n "${before}" ] && [ "${before}" != "MISSING" ] && [ "${before}" = "${after}" ]; then
+        t_ok "${id}" "再入子の終了後も外側 owner の sidecar が維持される"
+    else
+        t_fail "${id}" "再入子が外側 sidecar を壊した (before=${before} after=${after})"
+    fi
+}
+
+# ---------------------------------------------------------------------------
+# C14: 再入の否定 (stale nonce) と残留 sidecar の上書き
+# ---------------------------------------------------------------------------
+case_c14() {
+    local id="C14" d a_pid a_child nonce_old nonce_new b_pid stale_pid still
+    if [ "${HAVE_FLOCK}" -eq 0 ] || [ "${HAVE_PS}" -eq 0 ]; then
+        t_skip "${id}" "flock / ps 不在"
+        return
+    fi
+    d="$(new_dir)"
+    start_lane "${d}" "${WORK}/c14-a.err" bash "${SLEEPER}" 60
+    a_pid="${LANE_PID}"
+    if ! poll_until 10 lock_is_held "${d}"; then
+        t_fail "${id}" "前提 (owner の取得) が崩れた"
+        kill -KILL "${a_pid}" 2>/dev/null || true
+        return
+    fi
+    nonce_old="$(head -n 1 "${d}/owner" 2>/dev/null)"
+    a_child="$(pgrep -P "${a_pid}" 2>/dev/null | head -n 1)"
+
+    # SIGKILL: trap は走らない = sidecar が残留し、fd は OS が閉じてロックは解放される。
+    kill -KILL "${a_pid}" 2>/dev/null || true
+    wait "${a_pid}" 2>/dev/null
+    [ -n "${a_child}" ] && kill -KILL "${a_child}" 2>/dev/null
+
+    if [ -f "${d}/owner" ] && poll_until 10 lock_is_free "${d}"; then
+        t_ok "${id}" "残留 sidecar は次の取得者をブロックしない"
+    else
+        t_fail "${id}" "SIGKILL 後にロックが解放されない / sidecar が残っていない"
+    fi
+
+    start_lane "${d}" "${WORK}/c14-b.err" bash "${SLEEPER}" 8
+    b_pid="${LANE_PID}"
+    if ! poll_until 10 lock_is_held "${d}"; then
+        t_fail "${id}" "次の owner がロックを取得できない"
+        kill -KILL "${b_pid}" 2>/dev/null || true
+        return
+    fi
+    nonce_new="$(head -n 1 "${d}/owner" 2>/dev/null)"
+    if [ -n "${nonce_new}" ] && [ "${nonce_new}" != "${nonce_old}" ]; then
+        t_ok "${id}" "残留 sidecar がアトミックに上書きされた"
+    else
+        t_fail "${id}" "sidecar が更新されていない (old=${nonce_old} new=${nonce_new})"
+    fi
+
+    # stale nonce を持つ「生き残った子孫」は再入できず、ブロッキング取得に回らねばならない。
+    GLOBAL_TEST_LOCK_DIR="${d}" GLOBAL_TEST_LOCK_NONCE="${nonce_old}" \
+        bash "${WRAP}" true >"${WORK}/c14-stale.out" 2>"${WORK}/c14-stale.err" &
+    stale_pid=$!
+    sleep 2
+    still=0
+    proc_alive "${stale_pid}" && still=1
+    if [ "${still}" -eq 1 ]; then
+        t_ok "${id}" "stale nonce の子孫は再入できずブロッキング待機する"
+    else
+        t_fail "${id}" "stale nonce で誤再入した (素通りして即終了)"
+    fi
+    wait "${b_pid}" 2>/dev/null
+    wait "${stale_pid}" 2>/dev/null
+}
+
+# ---------------------------------------------------------------------------
+# C15: flock(1) 不在環境 (警告つきで排他なし実行。終了コードは保つ)
+# ---------------------------------------------------------------------------
+case_c15() {
+    local id="C15" d nofl c rc
+    d="$(new_dir)"
+    nofl="${WORK}/nofl-bin"
+    mkdir -p "${nofl}"
+    for c in bash sh id dirname stat date mv rm sleep ps head cat tr awk grep; do
+        if have "${c}"; then ln -sf "$(command -v "${c}")" "${nofl}/${c}"; fi
+    done
+    if [ -e "${nofl}/flock" ]; then
+        t_fail "${id}" "検証用 PATH に flock が混入している"
+        return
+    fi
+
+    rc=0
+    PATH="${nofl}" GLOBAL_TEST_LOCK_DIR="${d}" bash "${WRAP}" bash -c 'exit 7' \
+        >"${WORK}/c15.out" 2>"${WORK}/c15.err" || rc=$?
+
+    if [ "${rc}" -eq 7 ]; then
+        t_ok "${id}" "flock 不在でも終了コードを保つ (7)"
+    else
+        t_fail "${id}" "flock 不在時の終了コードが壊れた (${rc})"
+    fi
+    if grep -q 'flock' "${WORK}/c15.err"; then
+        t_ok "${id}" "flock 不在を stderr に 1 行警告する"
+    else
+        t_fail "${id}" "flock 不在が無言で skip されている"
+    fi
+    if [ ! -d "${d}" ]; then
+        t_ok "${id}" "flock 不在では lock dir を作らない"
+    else
+        t_fail "${id}" "flock 不在なのに lock dir を作った"
+    fi
+}
+
+# ---------------------------------------------------------------------------
+# C16: TTY あり / なし (monitor mode の復元を含む)
+# ---------------------------------------------------------------------------
+case_c16() {
+    local id="C16" d out rc start elapsed a_pid
+    d="$(new_dir)"
+    out="$(GLOBAL_TEST_LOCK_DIR="${d}" bash "${MONITORCHECK}" "${LIB}" "${d}" 2>/dev/null)"
+    if [ "${out}" = "monitor_before=off monitor_after=off" ]; then
+        t_ok "${id}" "TTY 無しで monitor mode が復元される"
+    else
+        t_fail "${id}" "TTY 無しで monitor mode が復元されない (${out})"
+    fi
+
+    if ! have script; then
+        t_skip "${id}" "script(1) 不在のため TTY ありの検証を省略"
+        return
+    fi
+    if ! script -q -e -c true /dev/null >/dev/null 2>&1; then
+        t_skip "${id}" "pty を確保できないため TTY ありの検証を省略"
+        return
+    fi
+
+    d="$(new_dir)"
+    out="$(script -q -e -c "GLOBAL_TEST_LOCK_DIR='${d}' bash '${MONITORCHECK}' '${LIB}' '${d}'" /dev/null 2>/dev/null | tr -d '\r')"
+    case "${out}" in
+        *"monitor_before=off monitor_after=off"*) t_ok "${id}" "TTY ありでも monitor mode が復元される" ;;
+        *) t_fail "${id}" "TTY ありで monitor mode が復元されない (${out})" ;;
+    esac
+
+    d="$(new_dir)"
+    rc=0
+    script -q -e -c "GLOBAL_TEST_LOCK_DIR='${d}' bash '${WRAP}' bash -c 'exit 3'" /dev/null >/dev/null 2>&1 || rc=$?
+    if [ "${rc}" -eq 3 ]; then
+        t_ok "${id}" "TTY ありでも終了コードが素通しされる (3)"
+    else
+        t_fail "${id}" "TTY ありで終了コードが壊れた (${rc})"
+    fi
+
+    if [ "${HAVE_FLOCK}" -eq 0 ]; then
+        t_skip "${id}" "TTY ありのブロッキング検証 (flock 不在)"
+        return
+    fi
+    d="$(new_dir)"
+    start_lane "${d}" "${WORK}/c16-a.err" bash "${SLEEPER}" 4
+    a_pid="${LANE_PID}"
+    if poll_until 10 lock_is_held "${d}"; then
+        start="$(date +%s)"
+        script -q -e -c "GLOBAL_TEST_LOCK_DIR='${d}' bash '${WRAP}' true" /dev/null >/dev/null 2>&1
+        elapsed=$(($(date +%s) - start))
+        if [ "${elapsed}" -ge 2 ]; then
+            t_ok "${id}" "TTY ありでもブロッキング待機する (${elapsed}s)"
+        else
+            t_fail "${id}" "TTY ありで待機していない (${elapsed}s)"
+        fi
+    else
+        t_fail "${id}" "TTY ありのブロッキング検証の前提が崩れた"
+    fi
+    wait "${a_pid}" 2>/dev/null
+}
+
+# ---------------------------------------------------------------------------
+# C17: lane の EXIT フックが「ロック保持中に」走る (trap 上書き回帰)
+# ---------------------------------------------------------------------------
+case_c17() {
+    local id="C17" d resf rc
+    if [ "${HAVE_FLOCK}" -eq 0 ]; then
+        t_skip "${id}" "flock(1) 不在"
+        return
+    fi
+    d="$(new_dir)"
+    resf="${WORK}/c17.result"
+    rm -f "${resf}"
+    rc=0
+    GLOBAL_TEST_LOCK_DIR="${d}" bash "${HOOKLANE}" "${LIB}" "${d}" "${resf}" \
+        >"${WORK}/c17.out" 2>"${WORK}/c17.err" || rc=$?
+
+    if grep -q 'hook_lock=held' "${resf}" 2>/dev/null; then
+        t_ok "${id}" "EXIT フックがロック保持中に実行された"
+    else
+        t_fail "${id}" "EXIT フックがロック保持中に走っていない ($(cat "${resf}" 2>/dev/null))"
+    fi
+    if [ "${rc}" -eq 0 ] && poll_until 10 lock_is_free "${d}"; then
+        t_ok "${id}" "EXIT フック併用でもロックが解放される (trap 上書きなし)"
+    else
+        t_fail "${id}" "EXIT フック併用でロックが解放されない (rc=${rc})"
+    fi
+}
+
+# ---------------------------------------------------------------------------
+# C18: playwright 掃除の選別 (@playwright/cli を巻き込まない)
+# ---------------------------------------------------------------------------
+case_c18() {
+    local id="C18" fn plain_js scoped_js plain_pid scoped_pid killed
+    if [ "${HAVE_PS}" -eq 0 ]; then
+        t_skip "${id}" "ps / pgrep 不在"
+        return
+    fi
+
+    # 実装からそのまま関数を切り出して評価する (スイート内で複製するとドリフトするため)。
+    fn="$(awk '/^cleanup_orphan_playwright\(\) \{/{f=1} f{print; if ($0 == "}") exit}' "${BROWSER_LANE}")"
+    if [ -z "${fn}" ]; then
+        t_fail "${id}" "run-browser-test.sh から cleanup_orphan_playwright を抽出できない"
+        return
+    fi
+
+    plain_js="${WORK}/node_modules/playwright/cli.js"
+    scoped_js="${WORK}/node_modules/@playwright/playwright/cli.js"
+    mkdir -p "$(dirname "${plain_js}")" "$(dirname "${scoped_js}")"
+    printf '#!/usr/bin/env bash\nsleep 120\n' >"${plain_js}"
+    printf '#!/usr/bin/env bash\nsleep 120\n' >"${scoped_js}"
+
+    # PPID=1 (orphan) にするため二重 fork する。
+    (bash "${plain_js}" run-server >/dev/null 2>&1 &) &
+    (bash "${scoped_js}" run-server >/dev/null 2>&1 &) &
+    wait 2>/dev/null
+
+    if ! poll_until 5 pattern_running "${plain_js} run-server" ||
+        ! poll_until 5 pattern_running "${scoped_js} run-server"; then
+        t_skip "${id}" "偽 playwright プロセスを起動できなかった"
+        return
+    fi
+    plain_pid="$(pgrep -f "${plain_js} run-server" 2>/dev/null | head -n 1)"
+    scoped_pid="$(pgrep -f "${scoped_js} run-server" 2>/dev/null | head -n 1)"
+
+    if ! poll_until 5 is_orphan "${plain_pid}" ||
+        ! poll_until 5 is_orphan "${scoped_pid}"; then
+        t_skip "${id}" "偽プロセスを orphan (PPID=1) にできなかった (subreaper 環境)"
+        kill -KILL "${plain_pid}" "${scoped_pid}" 2>/dev/null || true
+        return
+    fi
+
+    killed="${WORK}/c18.killed"
+    : >"${killed}"
+    (
+        # 実プロセスを殺さずに「選別結果」だけを観測する。
+        kill() { printf '%s\n' "$1" >>"${killed}"; }
+        eval "${fn}"
+        cleanup_orphan_playwright
+    )
+
+    if grep -qx "${plain_pid}" "${killed}"; then
+        t_ok "${id}" "node_modules/playwright/cli.js run-server は掃除対象になる (正のコントロール)"
+    else
+        t_fail "${id}" "本来の orphan run-server を掃除しない (掃除が効かなくなった)"
+    fi
+    if grep -qx "${scoped_pid}" "${killed}"; then
+        t_fail "${id}" "@playwright/ のプロセスを掃除対象にしている (bug-hunt を巻き込む)"
+    else
+        t_ok "${id}" "@playwright/ のプロセスは掃除対象にならない (負のコントロール)"
+    fi
+
+    kill -KILL "${plain_pid}" "${scoped_pid}" 2>/dev/null || true
+}
+
+# ---------------------------------------------------------------------------
+# C19: bug-hunt pre-flight guard は **ロック取得前** に fail-fast する
+# ---------------------------------------------------------------------------
+case_c19() {
+    local id="C19" d port listener="" lpid="" a_pid start elapsed rc bpid
+    if ! have python3; then
+        t_skip "${id}" "python3 不在 (bughunt ポートを listen できない)"
+        return
+    fi
+    listener="${WORK}/listen.py"
+    cat >"${listener}" <<'PY'
+import socket
+import sys
+import time
+
+s = socket.socket()
+s.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
+s.bind(("127.0.0.1", int(sys.argv[1])))
+s.listen(8)
+sys.stdout.write("listening\n")
+sys.stdout.flush()
+time.sleep(120)
+PY
+
+    for port in 8010 8011 8012 8013 8014 8015 8016 8017 8018; do
+        python3 "${listener}" "${port}" >"${WORK}/c19.listen" 2>/dev/null &
+        lpid=$!
+        if poll_until 3 grep -q listening "${WORK}/c19.listen"; then
+            break
+        fi
+        kill -KILL "${lpid}" 2>/dev/null || true
+        lpid=""
+    done
+    if [ -z "${lpid}" ]; then
+        t_skip "${id}" "8010..8018 のいずれも bind できなかった"
+        return
+    fi
+
+    d="$(new_dir)"
+    a_pid=""
+    if [ "${HAVE_FLOCK}" -eq 1 ]; then
+        # 先行レーンにロックを握らせる。guard が acquire より後ろにあると
+        # ここで数十秒待たされる = 「待ち時間の無駄」の回帰として検出できる。
+        start_lane "${d}" "${WORK}/c19-a.err" bash "${SLEEPER}" 30
+        a_pid="${LANE_PID}"
+        poll_until 10 lock_is_held "${d}"
+    fi
+
+    start="$(date +%s)"
+    GLOBAL_TEST_LOCK_DIR="${d}" bash "${BROWSER_LANE}" >"${WORK}/c19.out" 2>"${WORK}/c19.err" &
+    bpid=$!
+    rc=0
+    if poll_until 10 proc_gone "${bpid}"; then
+        wait "${bpid}" 2>/dev/null || rc=$?
+    else
+        kill -KILL "${bpid}" 2>/dev/null || true
+        wait "${bpid}" 2>/dev/null
+        rc=-1
+    fi
+    elapsed=$(($(date +%s) - start))
+
+    if [ "${rc}" -gt 0 ] && grep -q 'bug-hunt' "${WORK}/c19.err"; then
+        t_ok "${id}" "bughunt ポート listen 中は Browser lane が fail-fast する (rc=${rc}, ${elapsed}s)"
+    else
+        t_fail "${id}" "bughunt guard が働かない (rc=${rc}, ${elapsed}s)"
+    fi
+    if [ "${elapsed}" -lt 10 ]; then
+        t_ok "${id}" "guard はロック取得前に走る (先行レーンを待たない)"
+    else
+        t_fail "${id}" "guard がロック取得の後ろにある (${elapsed}s 待たされた)"
+    fi
+
+    kill -KILL "${lpid}" 2>/dev/null || true
+    wait "${lpid}" 2>/dev/null
+    if [ -n "${a_pid}" ]; then
+        # SIGKILL だと先行レーンの子 (専用プロセスグループ) が孤児として残る。
+        # TERM を送ってライブラリのシグナル契約に収束させる。
+        kill -TERM "${a_pid}" 2>/dev/null || true
+        wait "${a_pid}" 2>/dev/null
+    fi
+    return 0
+}
+
+# ---------------------------------------------------------------------------
+# C20: 二重 acquire で owner が落ちない (後続の run が素通り化しない)
+# ---------------------------------------------------------------------------
+case_c20() {
+    local id="C20" d resf lane held=0
+    d="$(new_dir)"
+    resf="${WORK}/c20.result"
+    rm -f "${resf}"
+    GLOBAL_TEST_LOCK_DIR="${d}" bash "${DOUBLEACQ}" "${LIB}" "${d}" "${resf}" "${SLEEPER}" \
+        >"${WORK}/c20.out" 2>"${WORK}/c20.err" &
+    lane=$!
+
+    if [ "${HAVE_FLOCK}" -eq 1 ]; then
+        poll_until 10 lock_is_held "${d}" && held=1
+    else
+        held=1
+    fi
+    wait "${lane}" 2>/dev/null
+
+    if grep -q '^mode=owner$' "${resf}" 2>/dev/null; then
+        t_ok "${id}" "二重 acquire 後も owner のまま"
+    else
+        t_fail "${id}" "二重 acquire で owner から落ちた ($(grep '^mode=' "${resf}" 2>/dev/null))"
+    fi
+    if grep -q '^fd7=ok$' "${resf}" 2>/dev/null; then
+        t_ok "${id}" "二重 acquire 後の run でも fd 7 が継承されない"
+    else
+        t_fail "${id}" "二重 acquire 後の run が素通り化している (fd 7 継承)"
+    fi
+    if [ "${held}" -eq 1 ]; then
+        t_ok "${id}" "二重 acquire 後も実行中にロックが保持される"
+    else
+        t_fail "${id}" "二重 acquire 後にロックが保持されていない"
+    fi
+}
+
+# ---------------------------------------------------------------------------
+# C21: lock / owner の型検証 (symlink 差し替えを拒否する)
+# ---------------------------------------------------------------------------
+case_c21() {
+    local id="C21" d
+    if [ "${HAVE_FLOCK}" -eq 0 ]; then
+        t_skip "${id}" "flock(1) 不在 (ファイル型検証に到達しない)"
+        return
+    fi
+
+    d="${WORK}/c21-lock"
+    mkdir -p -m 700 "${d}"
+    ln -sfn /dev/null "${d}/lock"
+    run_lane_fg "${d}" "${WORK}/c21-lock.err" true
+    if [ "${LANE_RC}" -ne 0 ] && grep -q 'lock file is a symlink' "${WORK}/c21-lock.err"; then
+        t_ok "${id}" "lock が symlink なら明示エラー停止"
+    else
+        t_fail "${id}" "symlink の lock を拒否しない (rc=${LANE_RC})"
+    fi
+
+    d="${WORK}/c21-owner"
+    mkdir -p -m 700 "${d}"
+    ln -sfn /dev/null "${d}/owner"
+    run_lane_fg "${d}" "${WORK}/c21-owner.err" true
+    if [ "${LANE_RC}" -ne 0 ] && grep -q 'sidecar is a symlink' "${WORK}/c21-owner.err"; then
+        t_ok "${id}" "owner (sidecar) が symlink なら明示エラー停止"
+    else
+        t_fail "${id}" "symlink の sidecar を拒否しない (rc=${LANE_RC})"
+    fi
+}
+
+# ---------------------------------------------------------------------------
+# C22: 異常終了経路でも残党を残さず収束させてから解放する
+# ---------------------------------------------------------------------------
+case_c22() {
+    local id="C22" d gpidf cpidf gpid cpid rc
+    if [ "${HAVE_FLOCK}" -eq 0 ] || [ "${HAVE_PS}" -eq 0 ]; then
+        t_skip "${id}" "flock / ps 不在"
+        return
+    fi
+    d="$(new_dir)"
+    gpidf="${WORK}/c22.gpid"
+    cpidf="${WORK}/c22.cpid"
+    rm -f "${gpidf}" "${cpidf}"
+
+    rc=0
+    GLOBAL_TEST_LOCK_DIR="${d}" bash "${ABNORMAL}" "${LIB}" "${IGNORER}" "${SLEEPER}" "${gpidf}" "${cpidf}" \
+        >"${WORK}/c22.out" 2>"${WORK}/c22.err" || rc=$?
+
+    gpid="$(cat "${gpidf}" 2>/dev/null || echo '')"
+    cpid="$(cat "${cpidf}" 2>/dev/null || echo '')"
+
+    if [ "${rc}" -ne 0 ]; then
+        t_ok "${id}" "内部エラーで非 0 終了する (rc=${rc})"
+    else
+        t_fail "${id}" "内部エラーなのに 0 で終了した"
+    fi
+    if poll_until 15 lock_is_free "${d}"; then
+        t_ok "${id}" "異常終了経路でもロックが解放される"
+    else
+        t_fail "${id}" "異常終了経路でロックが解放されない"
+    fi
+    if [ -n "${gpid}" ] && [ -n "${cpid}" ] &&
+        poll_until 10 proc_gone "${gpid}" && poll_until 10 proc_gone "${cpid}"; then
+        t_ok "${id}" "異常終了経路でも残党 (子・孫) を残さない"
+    else
+        t_fail "${id}" "異常終了経路で残党が残った (child=${cpid} grandchild=${gpid})"
+    fi
+    [ -n "${gpid}" ] && kill -KILL "${gpid}" 2>/dev/null
+    [ -n "${cpid}" ] && kill -KILL "${cpid}" 2>/dev/null
+    return 0
+}
+
+# ---------------------------------------------------------------------------
+# C23: SIGKILL 生存者がいる間はロックを離さない (諦めて解放しない)
+# ---------------------------------------------------------------------------
+case_c23() {
+    local id="C23" d lane
+    if [ "${HAVE_FLOCK}" -eq 0 ]; then
+        t_skip "${id}" "flock(1) 不在"
+        return
+    fi
+    d="$(new_dir)"
+    GLOBAL_TEST_LOCK_DIR="${d}" bash "${SURVIVOR}" "${LIB}" "${SLEEPER}" \
+        >"${WORK}/c23.out" 2>"${WORK}/c23.err" &
+    lane=$!
+
+    if ! poll_until 10 lock_is_held "${d}"; then
+        t_fail "${id}" "前提 (ロック取得) が崩れた"
+        kill -KILL "${lane}" 2>/dev/null || true
+        wait "${lane}" 2>/dev/null
+        return
+    fi
+
+    sleep 6
+    if lock_is_held "${d}" && proc_alive "${lane}"; then
+        t_ok "${id}" "SIGKILL 生存者がいる間はロックを解放しない"
+    else
+        t_fail "${id}" "生存者が居るのにロックを解放した (諦めて解放する回帰)"
+    fi
+    if grep -q 'still holding the lock' "${WORK}/c23.err"; then
+        t_ok "${id}" "残存 pid つきの警告を出し続ける (ハングと区別できる)"
+    else
+        t_fail "${id}" "残存を知らせる警告が出ない"
+    fi
+    if grep -q 'SHOULD_NOT_REACH' "${WORK}/c23.out"; then
+        t_fail "${id}" "解放して先へ進んでしまった"
+    else
+        t_ok "${id}" "解放を諦めて先へ進まない"
+    fi
+
+    kill -KILL "${lane}" 2>/dev/null || true
+    wait "${lane}" 2>/dev/null
+    poll_until 10 lock_is_free "${d}" || true
+}
+
+# ---------------------------------------------------------------------------
+# C24: 検証用 env の値検証 (壊れた設定で保護が半分だけ効く状態を作らない)
+# ---------------------------------------------------------------------------
+case_c24_one() {
+    # $1 = ラベル, $2 = env 名, $3 = 値, $4 = 期待する stderr の断片
+    local id="C24" d rc=0
+    d="$(new_dir)"
+    env GLOBAL_TEST_LOCK_DIR="${d}" "$2=$3" bash "${WRAP}" true \
+        >"${WORK}/c24.out" 2>"${WORK}/c24.err" || rc=$?
+    if [ "${rc}" -ne 0 ] && grep -q "$4" "${WORK}/c24.err"; then
+        t_ok "${id}" "$1 で取得時に fail-fast する"
+    else
+        t_fail "${id}" "$1 を素通しした (rc=${rc})"
+    fi
+}
+
+case_c24() {
+    case_c24_one "heartbeat=0" GLOBAL_TEST_LOCK_HEARTBEAT_SECS 0 'HEARTBEAT_SECS must be >= 1'
+    case_c24_one "heartbeat=-1" GLOBAL_TEST_LOCK_HEARTBEAT_SECS -1 'HEARTBEAT_SECS must be a positive integer'
+    case_c24_one "heartbeat=abc" GLOBAL_TEST_LOCK_HEARTBEAT_SECS abc 'HEARTBEAT_SECS must be a positive integer'
+    case_c24_one "grace=-1" GLOBAL_TEST_LOCK_GRACE_SECS -1 'GRACE_SECS must be a non-negative integer'
+    case_c24_one "grace=abc" GLOBAL_TEST_LOCK_GRACE_SECS abc 'GRACE_SECS must be a non-negative integer'
+
+    local id="C24" rc=0
+    GLOBAL_TEST_LOCK_DIR="relative/path" bash "${WRAP}" true \
+        >"${WORK}/c24-rel.out" 2>"${WORK}/c24-rel.err" || rc=$?
+    if [ "${rc}" -ne 0 ] && grep -q 'must be an absolute path' "${WORK}/c24-rel.err"; then
+        t_ok "${id}" "GLOBAL_TEST_LOCK_DIR=相対パス で fail-fast する"
+    else
+        t_fail "${id}" "相対パスの GLOBAL_TEST_LOCK_DIR を素通しした (rc=${rc})"
+    fi
+}
+
+# ---------------------------------------------------------------------------
+# C11: 全ケース終了後に子孫プロセスが残らない (最後に実行する)
+# ---------------------------------------------------------------------------
+case_c11() {
+    local id="C11" strays p
+    if [ "${HAVE_PS}" -eq 0 ]; then
+        t_skip "${id}" "pgrep 不在"
+        return
+    fi
+    poll_until 10 no_strays || true
+    strays="$(live_strays)"
+    if [ -z "${strays}" ]; then
+        t_ok "${id}" "スイート由来の子孫プロセスが残っていない"
+    else
+        t_fail "${id}" "子孫プロセスが残存している: $(for p in ${strays}; do printf '%s[%s] ' "${p}" "$(ps -o args= -p "${p}" 2>/dev/null | head -c 90)"; done)"
+    fi
+}
+
+main() {
+    echo "=== verify-global-test-lock (層 1: 並行挙動) ==="
+    echo "scratch: ${WORK}"
+    [ "${HAVE_FLOCK}" -eq 0 ] && echo "note: flock(1) が無いため排他系ケースは skip します"
+    [ "${HAVE_PS}" -eq 0 ] && echo "note: ps/pgrep が無いためプロセス系ケースは skip します"
+
+    case_c01
+    case_c02
+    case_c03_c04
+    case_c05
+    case_c06
+    case_c07
+    case_c08
+    case_c09
+    case_c10
+    case_c12
+    case_c13
+    case_c14
+    case_c15
+    case_c16
+    case_c17
+    case_c18
+    case_c19
+    case_c20
+    case_c21
+    case_c22
+    case_c23
+    case_c24
+    case_c11
+
+    echo ""
+    echo "=== 結果: passed=${PASS} failed=${FAIL} skipped=${SKIP} ==="
+    if [ "${FAIL}" -gt 0 ]; then
+        return 1
+    fi
+    return 0
+}
+
+main
+exit $?
diff --git a/scripts/with-global-test-lock.sh b/scripts/with-global-test-lock.sh
new file mode 100755
index 0000000..55441a0
--- /dev/null
+++ b/scripts/with-global-test-lock.sh
@@ -0,0 +1,26 @@
+#!/usr/bin/env bash
+#
+# scripts/with-global-test-lock.sh — 任意コマンドをグローバルテストロック配下で実行する。
+#
+# ラップ用のシェルスクリプトを持たない lane (package.json の test:packages / test:coverage) 用。
+# lane スクリプトを持つ 3 レーンは scripts/global-test-lock.sh を直接 source する
+# (直接叩かれた場合も保護されるため)。
+#
+# **exec は使わない**: exec は fd 7 を閉じてロックを即解放してしまう。
+# fd 7 を保持したままの親が子を待ち、終了コードをそのまま返す。
+
+set -euo pipefail
+
+if [ "$#" -lt 1 ]; then
+    echo "usage: with-global-test-lock.sh <command> [args...]" >&2
+    exit 2
+fi
+
+# shellcheck source=scripts/global-test-lock.sh
+. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/global-test-lock.sh"
+
+global_test_lock_acquire "$*"
+
+status=0
+global_test_lock_run "$@" || status=$?
+exit "${status}"
diff --git a/tests/Architecture/GlobalTestLockInventoryTest.php b/tests/Architecture/GlobalTestLockInventoryTest.php
new file mode 100644
index 0000000..478a31b
--- /dev/null
+++ b/tests/Architecture/GlobalTestLockInventoryTest.php
@@ -0,0 +1,339 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * Architecture invariant: 全テストレーンがグローバルテストロックを経由すること。
+ *
+ * 背景 (SoT = devnotes/20260804-2319-global-test-lock/conceptual-design.md):
+ * 複数 worktree の並行実装でテストレーンが同時に走ると、PostgreSQL サーバ・実ブラウザ・
+ * CPU/メモリを奪い合い、Browser lane の machine-wide な playwright 掃除が他レーンの
+ * run-server を巻き込む。旧実装は worktree-local な flock (cross-worktree 排他ゼロ) かつ
+ * flock -n (待たずに即エラー) だったため、これを scripts/global-test-lock.sh へ一本化した。
+ *
+ * worktree-local flock を「残さず削除する」判断が安全なのは、公式 entrypoint を
+ * **全て確実に包めている場合に限る**。よって本テストは deny-by-default の inventory とする:
+ * composer.json / package.json の test 系スクリプトは、明示 exemption に無い限り
+ * ロック経由でなければ fail する (新レーン追加時に落ちて気づける)。
+ *
+ * 並行挙動そのものは scripts/verify-global-test-lock.sh (層 1) が検証する。
+ * **本テストから層 1 を実行してはならない**: 本テストは composer test の内側
+ * = グローバルロック保持中に走るため、自分自身と競合する。
+ */
+
+/** watch / 対話用途のため意図的にラップしない script と、その理由。 */
+const GLOBAL_TEST_LOCK_EXEMPT = [
+    'test:ui' => 'vitest --ui (常駐 UI サーバ)。無期限にロックを保持するため対象外',
+    'test:watch' => 'vitest --watch (常駐 watch)。同上',
+];
+
+/** ロック経由と認められる呼び出し先 (これ自身がライブラリを source していることも検査する)。 */
+const GLOBAL_TEST_LOCK_LANE_SCRIPTS = [
+    'scripts/run-test.sh',
+    'scripts/run-browser-test.sh',
+    'scripts/run-vitest.sh',
+];
+
+/**
+ * 構造検査の対象スクリプト = lane スクリプト 3 本 + 汎用ラッパ。
+ * ラッパを対象外にすると、将来 `exec "$@"` へ戻されても層 2 は
+ * 「存在し実行可能」だけで通過してしまう (ロックが即解放される致命的回帰を見逃す)。
+ * ライブラリ本体 (scripts/global-test-lock.sh) は対象外 —
+ * trap / exec fd リダイレクトを**正当に持つ唯一のファイル**だから。
+ */
+const GLOBAL_TEST_LOCK_GUARDED_SCRIPTS = [
+    'scripts/run-test.sh',
+    'scripts/run-browser-test.sh',
+    'scripts/run-vitest.sh',
+    'scripts/with-global-test-lock.sh',
+];
+
+/**
+ * JSON の scripts セクションを「script 名 => コマンド文字列」へ正規化する (純関数)。
+ * composer.json は配列形式を採るため、改行連結して 1 文字列にする。
+ *
+ * @return array<string, string>
+ */
+function globalTestLockScriptsFromJson(string $json): array
+{
+    /** @var mixed $decoded */
+    $decoded = json_decode($json, true);
+    if (! is_array($decoded)) {
+        return [];
+    }
+
+    /** @var mixed $scripts */
+    $scripts = $decoded['scripts'] ?? null;
+    if (! is_array($scripts)) {
+        return [];
+    }
+
+    $normalized = [];
+    /** @var mixed $command */
+    foreach ($scripts as $name => $command) {
+        $lines = is_array($command) ? $command : [$command];
+        /** @var array<array-key, mixed> $lines */
+        $normalized[(string) $name] = implode("\n", array_map(
+            static fn (mixed $line): string => is_scalar($line) ? (string) $line : '',
+            $lines,
+        ));
+    }
+
+    return $normalized;
+}
+
+/**
+ * composer.json / package.json の test 系 script が全てロック経由かを検査する (純関数)。
+ *
+ * @param  array<string, string>  $scripts  script 名 => コマンド文字列 (配列形式は改行連結済み)
+ * @return list<string> 違反一覧 (空 = 合格)
+ */
+function globalTestLockLaneViolations(array $scripts): array
+{
+    $violations = [];
+
+    foreach ($scripts as $name => $command) {
+        if ($name !== 'test' && ! str_starts_with($name, 'test:')) {
+            continue;
+        }
+        if (array_key_exists($name, GLOBAL_TEST_LOCK_EXEMPT)) {
+            continue;
+        }
+        // 部分一致で通すと `with-global-test-lock.sh true && unlocked-test` のような
+        // 「ラッパ名は含むが実体は無ロック」が素通りする。
+        // **最終行 (= 実際に走るコマンド) が公式入口そのものであること**を要求し、
+        // 同一行のシェル演算子で別コマンドを繋ぐことを禁止する。
+        $lines = array_values(array_filter(
+            array_map(trim(...), preg_split('/\R/', $command) ?: []),
+            static fn (string $l): bool => $l !== '',
+        ));
+        $last = $lines === [] ? '' : $lines[count($lines) - 1];
+
+        if (preg_match('/(&&|\|\||;|(?<!\|)\|(?!\|))/', $last) === 1) {
+            $violations[] = "script '{$name}' がロック配下のコマンドをシェル演算子で連結している: {$last}";
+
+            continue;
+        }
+
+        $entrypoints = array_merge(['scripts/with-global-test-lock.sh'], GLOBAL_TEST_LOCK_LANE_SCRIPTS);
+        $viaEntrypoint = false;
+        foreach ($entrypoints as $entrypoint) {
+            if (preg_match('#^bash\s+'.preg_quote($entrypoint, '#').'(?:\s|$)#', $last) === 1) {
+                $viaEntrypoint = true;
+                break;
+            }
+        }
+        if (! $viaEntrypoint) {
+            $violations[] = "script '{$name}' がグローバルテストロックを経由していない: {$last}";
+        }
+    }
+
+    return $violations;
+}
+
+/**
+ * shell ソースから **実行行だけ** を取り出す (純関数)。
+ *
+ * 全ての静的検査はこの結果を単一の解析入力として使う。変更後スクリプトは
+ * 「旧 worktree-local な test.lock を廃止した」「flock -n をやめた」といった説明を
+ * **コメントに書く**ため、生ソースを検査すると正しい実装が偽赤になる。
+ *
+ * 行頭 (空白を除く) が `#` の行だけを落とす。行末コメントの除去はしない —
+ * `'#'` のような引用符内の `#` を壊してコードを誤って削るリスクの方が大きい。
+ */
+function globalTestLockCodeLines(string $source): string
+{
+    $lines = preg_split('/\R/', $source) ?: [];
+    $code = array_filter(
+        $lines,
+        static fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1,
+    );
+
+    return implode("\n", $code);
+}
+
+/**
+ * lane スクリプト / ラッパ本体が契約を守っているかを検査する (純関数)。
+ *
+ * @return list<string> 違反一覧 (空 = 合格)
+ */
+function globalTestLockLaneScriptViolations(string $path, string $source): array
+{
+    $violations = [];
+    $code = globalTestLockCodeLines($source);
+
+    if (! str_contains($code, 'global-test-lock.sh')) {
+        $violations[] = "{$path} が scripts/global-test-lock.sh を source していない";
+    }
+    // 旧 worktree-local ロックの残存 (後方互換の並走) を禁止する。
+    if (str_contains($code, 'storage/framework/testing/test.lock')) {
+        $violations[] = "{$path} に旧 worktree-local な test.lock が残っている";
+    }
+    if (preg_match('/app-vitest-/', $code) === 1) {
+        $violations[] = "{$path} に旧 workspace-hash ロック (app-vitest-*) が残っている";
+    }
+    if (preg_match('/\bflock\s+-n\b/', $code) === 1) {
+        $violations[] = "{$path} に flock -n (非ブロッキング取得) が残っている";
+    }
+    // 自己バイパスの禁止。
+    if (preg_match('/GLOBAL_TEST_LOCK_DIR=/', $code) === 1) {
+        $violations[] = "{$path} が GLOBAL_TEST_LOCK_DIR を設定している (自己バイパス禁止)";
+    }
+    // exec はロック fd を閉じてロックを即解放するため、ロック配下では使わない。
+    // ただし `exec 3<>...` のような **fd リダイレクト形は正当** なので除外する
+    // (run-browser-test.sh の /dev/tcp guard が使う)。
+    if (preg_match('/^\s*exec\s+(?!\d*[<>])/m', $code) === 1) {
+        $violations[] = "{$path} が exec を使っている (fd 7 が閉じてロックが即解放される)";
+    }
+    // EXIT trap の所有者はライブラリ 1 箇所。lane が自前で張ると _gtl_cleanup を
+    // 上書きしてロックが解放されなくなる (逆順なら lane 側が消される)。
+    // 後始末は global_test_lock_on_exit へ登録する。
+    if (preg_match('/^\s*trap\b[^\n]*\bEXIT\b/m', $code) === 1) {
+        $violations[] = "{$path} が自前で trap ... EXIT を張っている (global_test_lock_on_exit を使うこと)";
+    }
+    // ラッパ / lane は必ず acquire → run の順で公開 API を **実際に呼ぶ** こと。
+    // str_contains ではコメント/文字列だけでも通ってしまうため、呼び出し形を正規表現で見る。
+    $acquireAt = preg_match('/^\s*global_test_lock_acquire\b/m', $code, $mA, PREG_OFFSET_CAPTURE) === 1
+        ? $mA[0][1]
+        : null;
+    $runAt = preg_match('/^\s*global_test_lock_run\b/m', $code, $mR, PREG_OFFSET_CAPTURE) === 1
+        ? $mR[0][1]
+        : null;
+
+    if ($acquireAt === null) {
+        $violations[] = "{$path} が global_test_lock_acquire を呼んでいない";
+    }
+    if ($runAt === null) {
+        $violations[] = "{$path} が global_test_lock_run を呼んでいない";
+    }
+    if ($acquireAt !== null && $runAt !== null && $acquireAt > $runAt) {
+        $violations[] = "{$path} が global_test_lock_run を acquire より前に呼んでいる";
+    }
+
+    return $violations;
+}
+
+test('scripts/global-test-lock.sh と with-global-test-lock.sh が存在し実行可能であること', function (): void {
+    foreach (['scripts/global-test-lock.sh', 'scripts/with-global-test-lock.sh'] as $rel) {
+        $path = base_path($rel);
+        expect(file_exists($path))->toBeTrue("{$rel} が見つからない");
+        expect(is_executable($path))->toBeTrue("{$rel} に実行権が無い");
+    }
+});
+
+test('scripts/verify-global-test-lock.sh が存在し実行可能であること', function (): void {
+    // 層 1 (並行挙動スイート) の存在だけを固定する。**実行はしない** —
+    // 本テストはグローバルロック保持中に走るため、起動すると自己競合する。
+    $path = base_path('scripts/verify-global-test-lock.sh');
+    expect(file_exists($path))->toBeTrue('scripts/verify-global-test-lock.sh が見つからない');
+    expect(is_executable($path))->toBeTrue('scripts/verify-global-test-lock.sh に実行権が無い');
+});
+
+test('composer.json の test 系 script が全てグローバルテストロック経由であること', function (): void {
+    $json = file_get_contents(base_path('composer.json'));
+    expect($json)->toBeString();
+    /** @var string $json */
+    $scripts = globalTestLockScriptsFromJson($json);
+    expect($scripts)->not->toBe([]);
+    expect(array_key_exists('test', $scripts))->toBeTrue('composer.json に test script が無い');
+    expect(globalTestLockLaneViolations($scripts))->toBe([]);
+});
+
+test('package.json の test 系 script が全てグローバルテストロック経由であること', function (): void {
+    $json = file_get_contents(base_path('package.json'));
+    expect($json)->toBeString();
+    /** @var string $json */
+    $scripts = globalTestLockScriptsFromJson($json);
+    expect($scripts)->not->toBe([]);
+    expect(array_key_exists('test', $scripts))->toBeTrue('package.json に test script が無い');
+    expect(globalTestLockLaneViolations($scripts))->toBe([]);
+});
+
+test('lane スクリプトとラッパが契約 (source / 旧ロック不在 / flock -n 不在 / exec 不在 / 自前 EXIT trap 不在 / acquire+run 使用) を守ること', function (): void {
+    foreach (GLOBAL_TEST_LOCK_GUARDED_SCRIPTS as $rel) {
+        $source = file_get_contents(base_path($rel));
+        expect($source)->toBeString();
+        /** @var string $source */
+        expect(globalTestLockLaneScriptViolations($rel, $source))->toBe([]);
+    }
+});
+
+/*
+ * 負のコントロール (実ファイルは書き換えない):
+ * gate が「壊れた状態」を実際に検出することを fixture で確認する。空振り gate を green にしないため。
+ */
+test('負のコントロール: 未ラップの新レーンを検出する', function (): void {
+    $violations = globalTestLockLaneViolations(['test:e2e' => 'pnpm exec playwright test']);
+    expect($violations)->not->toBe([]);
+    expect(implode("\n", $violations))->toContain('test:e2e');
+});
+
+test('負のコントロール: ラッパ名を含むだけの偽装 (演算子連結) を検出する', function (): void {
+    $violations = globalTestLockLaneViolations([
+        'test:e2e' => 'bash scripts/with-global-test-lock.sh true && pnpm exec playwright test',
+    ]);
+    expect($violations)->not->toBe([]);
+    expect(implode("\n", $violations))->toContain('連結');
+});
+
+test('負のコントロール: 旧 worktree-local ロックへ戻した lane スクリプトを検出する', function (): void {
+    $broken = <<<'SH'
+    #!/usr/bin/env bash
+    LOCK_FILE="storage/framework/testing/test.lock"
+    exec 9>"$LOCK_FILE"
+    flock -n 9 || exit 1
+    SH;
+    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
+    expect($violations)->not->toBe([]);
+    expect(implode("\n", $violations))->toContain('test.lock');
+    expect(implode("\n", $violations))->toContain('flock -n');
+});
+
+test('負のコントロール: exec を復活させたラッパを検出する', function (): void {
+    $broken = <<<'SH'
+    #!/usr/bin/env bash
+    . "$(dirname "$0")/global-test-lock.sh"
+    global_test_lock_acquire "$*"
+    exec "$@"
+    SH;
+    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
+    expect($violations)->not->toBe([]);
+    expect(implode("\n", $violations))->toContain('exec');
+});
+
+test('負のコントロール: 自前 EXIT trap を張った lane スクリプトを検出する', function (): void {
+    $broken = <<<'SH'
+    #!/usr/bin/env bash
+    . "$(dirname "$0")/global-test-lock.sh"
+    global_test_lock_acquire "lane"
+    trap cleanup_orphan_playwright EXIT
+    global_test_lock_run vendor/bin/pest
+    SH;
+    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
+    expect($violations)->not->toBe([]);
+    expect(implode("\n", $violations))->toContain('trap');
+});
+
+test('負のコントロール: exec の fd リダイレクト形は違反にしない', function (): void {
+    $ok = <<<'SH'
+    #!/usr/bin/env bash
+    . "$(dirname "$0")/global-test-lock.sh"
+    (exec 3<>"/dev/tcp/127.0.0.1/8010") 2>/dev/null || true
+    global_test_lock_acquire "lane"
+    global_test_lock_run vendor/bin/pest
+    SH;
+    expect(globalTestLockLaneScriptViolations('fixture.sh', $ok))->toBe([]);
+});
+
+test('負のコントロール: 自己バイパス (GLOBAL_TEST_LOCK_DIR 設定) と acquire/run の順序違反を検出する', function (): void {
+    $broken = <<<'SH'
+    #!/usr/bin/env bash
+    . "$(dirname "$0")/global-test-lock.sh"
+    GLOBAL_TEST_LOCK_DIR=/tmp/bypass
+    global_test_lock_run vendor/bin/pest
+    global_test_lock_acquire "lane"
+    SH;
+    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
+    expect(implode("\n", $violations))->toContain('自己バイパス');
+    expect(implode("\n", $violations))->toContain('acquire より前');
+});
```

## テスト結果

- 層 1 `bash scripts/verify-global-test-lock.sh`: **passed=65 failed=0 skipped=0** (C01〜C24 全 ID を網羅、所要 約 50 秒)
- 層 2 `vendor/bin/pest tests/Architecture/GlobalTestLockInventoryTest.php`: **12 passed / 36 assertions**
- `tests/Architecture` 全体: 176 tests / 164 passed / 12 errors
  — errors は全て `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` の
  **PostgreSQL 未接続** (`could not translate host name "db"`) によるもので、本実装とは無関係
  (main でも同一環境で同様に落ちる)
- `composer phpstan` (level 10): **No errors**
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm test` (グローバルロック経由): **106 files / 968 tests passed**
- `pnpm test:packages` (`with-global-test-lock.sh` 経由): **7 files / 56 tests passed**
- `pnpm build`: passed
- `bash scripts/bug-hunt-shard.sh self-test`: all passed (bug-hunt 基盤を壊していないことの確認)
- `composer test` (Feature/Unit/Architecture 全体) と `composer test:browser` は
  **本環境に PostgreSQL が無いため実行できない** (docker-compose の `db` サービスが未起動。
  main でも同一のエラーで落ちることを確認済み)。CI (`php` job) で実行される。

## 実装者からの補足 (テストファーストの観測結果)

未変更ツリーに対して層 1 / 層 2 を先に走らせ、fail を観測してから実装に入った:

- 層 1: passed=9 failed=38 skipped=6 (ブロッキング取得・再入・fd 非継承・シグナル収束・
  `@playwright/` 除外・bughunt guard・env 検証がすべて未実装として fail)
- 層 2: 12 tests / 9 passed / 3 failed
  (`global-test-lock.sh` 不在 / `test:coverage` `test:packages` が未ラップ /
   `run-test.sh` が旧 `test.lock` + `flock -n` のまま)

## 設計から意図的に逸脱した点 (レビュー対象)

1. `_gtl_probe_process_group` に **最大 3 回のリトライ**を入れた。設計は 1 回 probe だが、
   `ps` が空を返す race (probe 対象が先に終了) は「グループを作れなかった」ではないため、
   値が取れて不一致のときだけ即 die する形にした (fail-secure は維持)。
2. それ以外は詳細設計のコードをそのまま実装している。
