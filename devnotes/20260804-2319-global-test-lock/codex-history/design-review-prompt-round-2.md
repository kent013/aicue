# Round 2: Round 1 指摘への対応

Round 1 の [Critical] 1 件・[Warning] 3 件・[Suggestion] 2 件すべてに対応しました。

- trap 競合: 公開 API `global_test_lock_on_exit` を新設し、**EXIT trap の所有者をライブラリ 1 箇所に固定**。
  層 2 に「lane が自前で `trap ... EXIT` を張っていないこと」を追加して構造で固定しました。
- 二重 acquire: `_GTL_MODE` 設定済みなら no-op で return するガードを追加(C20 で検証)。
- ファイル型検証: `lock` / `owner` の symlink 拒否を read / write / open の 3 箇所に追加(C21 で検証)。
- `test:coverage`: `vitest run --coverage` へ寄せてラップ対象に入れ、exempt を `test:ui` / `test:watch` の 2 つに縮小。
- bughunt guard をロック取得前へ移動、ケース ID を `C01..C21` に統一。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

## [Critical] 施策 4/1: EXIT trap 競合の解決案に穴がある
- 判断: **対応する**(提示された「exit hook を追加登録する公開 API」案を採用)
- 根拠: 完全に正しい。当初の note は「acquire より前に張るか、`trap 'cleanup_orphan_playwright; _gtl_cleanup' EXIT`
  へ統合すること」と書いていたが、前者は acquire 側の `trap '_gtl_cleanup' EXIT` に上書きされ、
  後者は lane 側が内部関数 `_gtl_cleanup` を知っている前提になり境界が壊れる。
  「実装者が正しく書けば大丈夫」という解決は解決になっていない。
- 対応内容: 公開 API **`global_test_lock_on_exit <fn>`** を新設し、**EXIT trap の所有者をライブラリ 1 箇所に固定**した。
  - `_gtl_cleanup` の先頭で登録フックを順に実行する。**ロックを保持したまま先に走らせる**
    (orphan playwright の掃除は次のレーンが入る前に終える必要があるため、この順序が正しい)。
  - owner でない経路 (flock 不在 / 再入) では `_gtl_cleanup` が呼ばれないため、
    `global_test_lock_on_exit` 側で素の EXIT trap を張ってフックが確実に走るようにした。
  - `run-browser-test.sh` は `trap cleanup_orphan_playwright EXIT` をやめ、
    `global_test_lock_on_exit cleanup_orphan_playwright` に置き換えた。
  - 層 1 の C17 を「フックが**ロック保持中に**実行され、その後ロックが解放される」に強化。
  - 層 2 に「**lane スクリプトが自前で `trap ... EXIT` を張っていないこと**」を追加(構造で固定)。

## [Warning] 施策 1: 同一プロセスでの二重 acquire で owner → reentrant に落ちる
- 判断: **対応する**
- 根拠: 正しい。`_GTL_MODE` が `reentrant` に落ちると後続の `global_test_lock_run` が素通り実行になり、
  **fd 非継承もプロセスグループ管理も失われる**。設計の中核が静かに無効化される最悪の穴。
- 対応内容: `global_test_lock_acquire` の冒頭に「`_GTL_MODE` が既に設定済みなら no-op で return」
  のガードを追加した(owner / reentrant / disabled のいずれでも状態を変えない)。
  層 1 に **C20「二重 acquire しても owner のままで、後続 run が素通り化しない」**を追加。

## [Warning] 施策 1: `lock` / `owner` ファイル自体の型検証(symlink 拒否)が不足
- 判断: **対応する**
- 根拠: 妥当。dir は 0700 + 所有者検証しているが、ファイル単体の型は見ていなかった。多層防御として安い。
- 対応内容:
  - `exec 7>"${lockfile}"` の直前に `lock` / `owner` の symlink 拒否 (`_gtl_die`) を追加。
  - `_gtl_sidecar_nonce`(読み)に `[ -L ] && return 1` を追加(偽 sidecar を読まない)。
  - `_gtl_write_sidecar`(書き)に symlink 拒否と、tmp ファイルの `rm -f` 先行を追加
    (tmp パスに置かれた symlink 経由での書き込みを防ぐ)。
  - 層 1 に **C21「`lock` / `owner` を symlink に差し替えると明示エラーで停止する」**を追加。

## [Warning] 施策 8/6: `test:coverage` の exempt が無ロック経路として残る
- 判断: **対応する**(提示された 2 案のうち「`vitest run --coverage` に寄せてラップ対象へ入れる」を採用)
- 根拠: 正しい。現行の `vitest --coverage` は watch だが、`test:watch` が既にあるので watch の二重提供であり、
  one-shot にした方が用途が明確になる。one-shot である以上、無ロックで残す理由がない
  (CPU 競合の性質は `pnpm test` と同一)。「非公式だから」という逃げの根拠を採らないという点でも
  こちらが正しい。
- 対応内容: `package.json` の `test:coverage` を
  `bash scripts/with-global-test-lock.sh pnpm exec vitest run --coverage` に変更。
  層 2 の exemption を **`test:ui` と `test:watch` の 2 つだけ**に縮小し、
  exempt の根拠を「**無期限にロックを保持してしまう常駐プロセスだから**」と明記した
  (「非公式だから」ではないことを本文に書いた)。

## [Suggestion] 施策 4: bug-hunt pre-flight guard はロック取得前に実行した方がよい
- 判断: **対応する**
- 根拠: そのとおり。取得後に落とすと、先行レーンの終了を数分待たされた末に
  「bug-hunt が走っているので実行できません」と言うことになる。無駄な待機を作らない。
- 対応内容: `run-browser-test.sh` で guard を `global_test_lock_acquire` の**前**へ移動し、
  その理由をコメントに明記。層 1 の C19 も「**ロック取得前に** fail-fast する
  (先行レーンを待たされない)」に強化した。

## [Suggestion] 施策 7: ケース ID を `C01..` 形式にする
- 判断: **対応する**
- 根拠: 妥当。当初表記は `a..s` で 19 行あるのに本文が「14 ケース」と書いており、実数とズレていた
  (指摘のとおり保守性以前に誤記だった)。
- 対応内容: 全ケースを `C01` 〜 `C21` に振り直し(新規 C20 / C21 を含む)、
  本文のケース数を実数に修正。「CI ログから失敗ケースを直接特定できるよう ID を出力する」ことを
  設計に明記した。C16 の「c〜j」という旧表記も `C03〜C10` に直した。

## APPROVE を受けた施策(変更なし)
- 施策 2 / 3 / 5 / 6(`test:coverage` を除く)/ 7 / 9 / 10 は指摘なしのため維持。


---

## 改訂後の詳細設計書 (全文)

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
| `global_test_lock_on_exit <fn>` | lane 固有の後始末を **cleanup へ追加登録**する(lane が自前で `trap ... EXIT` を張ると `_gtl_cleanup` を上書きしてロックが解放されなくなるため、EXIT trap は本 API に一本化する) |

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
_GTL_EXIT_HOOKS=""          # lane 固有の後始末 (関数名の空白区切り)

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
    # lane 固有の後始末は **ロックを保持したまま** 先に走らせる
    # (例: Browser lane の orphan playwright 掃除は、次のレーンが入る前に終える必要がある)。
    local hook
    for hook in ${_GTL_EXIT_HOOKS}; do
        "${hook}" || true
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

    # 同一プロセスからの二重取得は no-op。
    # ここを素通しすると owner → reentrant に状態が落ち、以降の global_test_lock_run が
    # 「素通り実行」になって fd 非継承もプロセスグループ管理も失われる。
    if [ -n "${_GTL_MODE}" ]; then
        return 0
    fi

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

### 検証ケース(21 ケース。ID は CI ログと対応させるため C01.. で固定する)

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

> ケース数を移植元の 11 に合わせることは目的にしていない。aicue のレーン構成
> (4 レーン + bug-hunt 隣接 + Browser lane 固有の掃除)に対して過不足なく定義した結果 21 ケースになった。
> 各ケースは `C01` 〜 `C21` の ID で出力し、CI ログから失敗ケースを直接特定できるようにする。

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

再レビューをお願いします。各施策の判定 (APPROVE / REQUEST_CHANGES) と全体判定
(APPROVED / CHANGES_REQUESTED) を明示し、残る指摘は [Critical] / [Warning] / [Suggestion] の
分類つきでお願いします。
