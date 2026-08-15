## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
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


あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（対象は bash スクリプトと Pest 契約テスト）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか。とくに本スクリプトは開発ワークフロー自身が使うため、壊すと後続作業がすべて止まる
6. スコープの適切さ: 過大または過小になっていないか（オーバーエンジニアリング禁止）
7. 保証範囲の誇張がないか: 「守られる」と書いていることが実際に守られる範囲と一致しているか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: worktree-secret-file-mode

worktree へ供給する秘密ファイルの権限を、供給元の権限に依存させず作成時に確定させる。

## 背景・課題

`scripts/setup-worktree.sh` の `[2/7]` は、`.gitignore` 対象で worktree には現れない実行時ファイルを
親 (main のチェックアウト) からコピーして供給する。現状の供給行は次の 5 本である。

| # | 供給先 | 現行の供給手段 | 秘密か |
|---|---|---|---|
| 1 | `.env` | `cp`。親に無ければ `.env.example` を代わりに置く | **はい** (APP_KEY / CIPHERSWEET_KEY / DB_PASSWORD / STRIPE_SECRET / 各 LLM の API キー) |
| 2 | `storage/oauth-private.key` | `cp` (親に無ければ note して続行) | **はい** (Passport の署名鍵) |
| 3 | `storage/oauth-public.key` | `cp` (同上) | 対になる鍵素材 |
| 4 | `.env.bughunt.local` | `install -m 600` | **はい** |
| 5 | `public/build` | `cp -r` (ディレクトリ) | いいえ (ビルド成果物) |

このうち作成時に権限を確定しているのは **4 の 1 本だけ**で、スクリプト内のコメントも
「今回 0600 を固定する対象は `.env.bughunt.local` だけである」と自ら対象を限定すると明記している。

### 何が問題か (実測に基づく)

本コンテナ (umask 0022) で `cp` の挙動を実測した。

- **新規作成時、コピー先の権限は供給元の権限を引き継ぐ** (umask で絞られる分だけ狭くなる)。
  親の `.env` は現在 **0644** なので、**worktree を作るたびに world-readable な `.env` が 1 つ増える**。
  「必ず 0644 になる」のではなく「親が広ければ worktree も広くなる」のが本質で、
  親を 0666 にしている開発者がいれば worktree 側は 0644 になる (umask 頼み = 環境依存)。
- **コピー先が既に存在する場合、`cp` はコピー先の権限を変えない** (0666 の既存ファイルに
  `cp` しても 0666 のまま)。つまり一度広い権限で置かれたら再供給では締まらない。

worktree は並行作業のたびに増えるため、露出面が作業件数だけ掛け算で増える。
`.env` は CipherSweet の鍵 (PII 暗号) と Stripe の秘密鍵を含むので、影響は開発環境に閉じない
(鍵が漏れれば本番データの復号や決済 API の悪用に繋がる)。

### 見本による代替という別の穴

`.env` が親に無いとき、現行は追跡下の `.env.example` をコピーして `.env` として置く。
これは「起動はするが APP_KEY も DB 接続も入っていない worktree」を無言で作る。
後段の health check は `php artisan --version` までしか見ないので**通ってしまい**、
実装作業を始めてから謎のエラーで気付くことになる。さらに `.env.example` は
追跡下の 0644 ファイルなので、そこから作った `.env` も広い権限で生まれる。
**代替経路が広い権限で置くなら、供給行だけ直しても同じ穴が残る。**

### 家系での位置付け (lctl 台帳 worktree-task-isolation)

- 裁定 AG-153 (2026-08-10) が正典を **t1** へ上げた。t1 = t0 + 次の 4 点。
  (1) 供給する秘密ファイルは**作成時点で権限を確定**させ供給元に依存させない
  (2) 供給元が無ければ何もしない (空ファイルを作らない)
  (3) 供給処理の呼び出しを条件式の中に置かない (`set -e` が効かず失敗を握り潰すため)
  (4) 上記を**単体起動で検査できる契約テスト**を持つ
- **本リポジトリは (1)(2)(4) の原型を家系で最初に作った側**であり、AG-153 はその形を還流承認した。
  それでも台帳のセルが `update_pending` なのは、上記のとおり適用範囲がバグ調査専用の 1 本に
  留まっているためである (2026-08-14 の領域深掘り、観測点 aicue@a5553b5)。
- 追従元 (laravel-claude-template) は同じ裁定を先に履行し、供給関数 1 本への集約 /
  見本による代替の撤去 / 主経路への直置き / 単体起動の契約テストという形に落ちている。
  **本設計はその形に合わせる** (家系で 2 通りの解を並存させない)。

## 改善アイデア

**秘密ファイルの供給を 1 つの関数に集約し、`.env` と Passport 鍵にも作成時の権限確定を広げる。
同時に「見本による代替」を撤去して、供給元が無いときは必須なら止め、任意なら何もしない形にする。**

1. `provision_bughunt_env_file` を、供給元・供給先・必須/任意を引数に取る汎用の
   `provision_secret_file` へ一般化する。中身は `install -m 600` 1 コマンド
   (コピーしてから `chmod` する 2 段にはしない = 広い権限で存在する瞬間を作らない)。
2. 秘密ファイル 4 本 (`.env` / `storage/oauth-private.key` / `storage/oauth-public.key` /
   `.env.bughunt.local`) をすべてこの関数経由にする。呼び出しは主たる流れに直置きし、
   `if` の条件式には置かない。
3. `.env` は **必須**。親に無ければ `.env.example` で代替せず、直し方 (親で
   `cp .env.example .env` して `php artisan key:generate`) を示して非ゼロで停止する。
   残りの 3 本は **任意** (親に無ければ何もしない)。
4. `public/build` は秘密ではないディレクトリなので対象外とし、`cp -r` のまま残す。
   ただし「なぜ対象外か」をコメントに残し、次に読む人が判断を再現できるようにする。
5. 契約テストを既存 7 ケースの読み替え + 追加ケースへ広げる。
   既存ケースは 1 つも落とさず、対象を新関数に読み替えて維持する。

## 期待効果

- **使命への貢献**: 直接の機能追加ではなく、AI が自律で worktree を量産する運用の
  事故耐性を上げる基盤整備である。使命 (現場の SOP から動画マニュアルを作る) は
  顧客組織の PII を CipherSweet で暗号化して預かる前提に立っており、その鍵を含む `.env` が
  worktree の数だけ world-readable に複製される状態は、使命の前提 (預かったものを守る) を削る。
- **具体的な改善見込み**:
  - 新規に作る worktree の秘密ファイルは、親の権限がどうであれ 0600 に揃う (環境依存が消える)。
  - 供給元が無いときに「動くように見えて壊れている worktree」が生まれなくなる。
  - 権限契約が単体起動の契約テストで固定され、退行が `composer test` で検出できる。
  - 台帳 (worktree-task-isolation) の aicue セルが t1 に到達する。

## 実装方針（概要）

| 対象 | 変更内容 |
|---|---|
| `scripts/setup-worktree.sh` | `provision_bughunt_env_file` → `provision_secret_file` へ一般化。`[2/7]` の秘密ファイル供給 4 本を関数経由に統一。`.env.example` フォールバックを撤去し必須不足で停止 |
| `tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php` | 既存 7 ケースを新関数へ読み替えて維持 + 追加ケース (対象拡大 / 必須不足で停止 / 引数の fail-closed / 記録の正確さ / 静的な退行検出) |
| `docs/worktree-isolation-strategy.md` | 「実行時ファイル」の軸と `[2/7]` の説明を実装に合わせる。権限契約と遡及しない旨を追記 |
| `AGENTS.md` §worktree 運用ルール | 実行時ファイルのコピーに権限契約 (0600) と `.env` 必須で停止する旨を追記 |
| `scripts/README.md` | `setup-worktree.sh` 行の説明を実装に合わせる |

## 制約・前提

- **このスクリプトは本ワークフロー自身が使う**。壊すと後続の実装作業がすべて止まるため、
  変更後に実際に worktree を作って完走することを受入条件に含める (詳細設計に手順を書く)。
- **既存の worktree には遡及しない**。効くのは新規作成からで、既にある worktree の
  `.env` は広いままである。これを「全部締まった」と書かない。
- 0600 で困る利用者がいないことは、`.env.bughunt.local` が既に 0600 で運用されており
  bug-hunt が worktree から動いている実績で裏付けられる (アプリを動かすのは供給と同じユーザーである)。
- `.env.testing` / `.env.example` は追跡下なので worktree に自動で現れる。供給の対象ではない。
- CI (`.github/workflows/ci.yml`) は `setup-worktree.sh` を呼ばない。影響は開発者と
  エージェントの作業場所準備に閉じる。

## スコープ外

- `public/build` (秘密でないビルド成果物) の権限。
- 既存 worktree の権限の遡及是正、および親の `.env` 自体の権限是正
  (親は人が作るファイルで、スクリプトの責務ではない)。
- `scripts/ci/ensure-test-db.php` の基点テスト DB へのスキーマ適用 (AG-135)。
  台帳では別の feature (php-test-pgsql-lane) の管轄と整理されており、本設計では扱わない。
- `teardown-worktree.sh` の変更。
- 家系の他リポジトリへの還流 (台帳への報告は監督セッションの責務)。


## 参考: 現行 scripts/setup-worktree.sh

```bash
#!/usr/bin/env bash
#
# scripts/setup-worktree.sh — TODO 用 git worktree を作成し依存性・実行時ファイルを整える
#
# 使い方:
#   scripts/setup-worktree.sh T012
#
# ブランチ名は todo/<task-id> に固定 (custom branch 非対応 — teardown のブランチ前提と一致させるため)
# 運用ルール: AGENTS.md §worktree 運用ルール
#
# 責務:
#   0) 入力バリデーション + lock 排他
#   1) git worktree add (.claude/worktrees/tasks/<task-id>, todo/<task-id>, main 起点)
#   2) 実行時ファイルのプロビジョニング (.env 必須 / storage/oauth-*.key・public/build は存在すればコピー)
#   3) git submodule update --init --recursive (.gitmodules がある場合のみ)
#   4) vendor: worktree 内 composer install (worktree-local。独立 vendor)
#   5) node_modules: worktree 内 pnpm install (global virtual store 共有。LLM 自律運用で局所安全)
#   6) post-setup health check (必須ファイル / autoload smoke / GVS 実効 / cold-state ツール smoke)
#   7) pgsql test base DB の冪等 ensure (best-effort)
#
# 設計思想: worktree は依存ディレクトリ (vendor / node_modules) を workspace と共有しない。
# 各 worktree が自前で composer install / pnpm install する。
#   - vendor: hardlink 共有は Docker named volume(btrfs) と worktree(virtiofs) の
#     cross-device で失敗するため採らない。worktree 内 composer install に統一。
#   - node_modules: symlink 共有は worktree 内 pnpm install/add が workspace を直接
#     汚染する footgun のため採らない。pnpm enableGlobalVirtualStore で実体を共有 store
#     (<store-path>/links/) に置き、install/add の影響を自 worktree に局所化する。
# これにより worktree が device 非依存・LLM 自律運用で安全 (誤 install で main/他 worktree を
# 壊さない) になる。

set -euo pipefail

# --- bug-hunt 専用 env の provisioning (契約テストから source して単体で叩けるよう関数化) ---
# .env.bughunt.local は .gitignore 対象で worktree には決して現れない = コピーが唯一の供給路。
# bug-hunt は worktree 走行が既定 (AGENTS.md) なので、無いと provision が必ず止まる。
#
# ★ mode は親に追随させず 0600 に固定する。親が 0644 だと `cp -p` は
#   **world-readable な秘密ファイルを新たに作る**ため契約として弱い。
#   `install -m 600` は作成時点で mode を確定するので、`cp` → `chmod` の 2 段にある
#   「一瞬だけ広く読める窓」も無い。
# ★ 今回 0600 を固定する対象は **.env.bughunt.local だけ**である。
#   既存の .env / storage/oauth-*.key の権限契約は変更しない (別施策)。
provision_bughunt_env_file() {
    local repo_root=$1 worktree_dir=$2
    [[ -f "${repo_root}/.env.bughunt.local" ]] || return 0   # 非利用リポジトリでは no-op
    install -m 600 "${repo_root}/.env.bughunt.local" "${worktree_dir}/.env.bughunt.local"
}

# ★ source 専用モード: 関数定義だけ取り込んで抜ける (契約テスト用)。
#   実行時 (bash setup-worktree.sh) は環境変数を立てないので通らない。
if [[ -n "${SETUP_WORKTREE_SOURCE_ONLY:-}" && "${BASH_SOURCE[0]}" != "$0" ]]; then
    return 0
fi

if [[ $# -ne 1 || -z "${1:-}" ]]; then
    echo "usage: $0 <task-id>" >&2
    echo "  ブランチ名は todo/<task-id> に固定 (custom branch 非対応)" >&2
    exit 1
fi

TASK_ID="$1"
BRANCH_NAME="todo/${TASK_ID}"

# --- 入力バリデーション ---
if ! [[ "${TASK_ID}" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]; then
    echo "error: invalid task-id: '${TASK_ID}' (英数字 + - . _ のみ、先頭は英数字)" >&2
    exit 1
fi
if ! git check-ref-format --branch "${BRANCH_NAME}" >/dev/null 2>&1; then
    echo "error: 派生ブランチ名が不正です: '${BRANCH_NAME}'" >&2
    exit 1
fi

REPO_ROOT="$(git rev-parse --show-toplevel)"
WORKTREE_DIR="${REPO_ROOT}/.claude/worktrees/tasks/${TASK_ID}"
LOCK_FILE="${REPO_ROOT}/.claude/worktrees/.setup.lock"

# --- lock 排他 (flock 優先 / 非 Linux は mkdir フォールバック) ---
# setup/teardown の同時実行を防ぐ。flock (util-linux) が無い macOS 等では
# mkdir のアトミック性を使った lock dir にフォールバックする (EXIT trap で解放)。
LOCK_DIR="${LOCK_FILE}.d"
LOCK_DIR_HELD=0
acquire_lock() {
    mkdir -p "${REPO_ROOT}/.claude/worktrees"
    if command -v flock >/dev/null 2>&1; then
        exec 200>"${LOCK_FILE}"
        if ! flock -n 200; then
            echo "error: 別の setup/teardown が実行中です (${LOCK_FILE})。完了を待って再実行してください。" >&2
            exit 1
        fi
    else
        if ! mkdir "${LOCK_DIR}" 2>/dev/null; then
            echo "error: 別の setup/teardown が実行中です (${LOCK_DIR})。完了を待って再実行してください。" >&2
            echo "       (異常終了で残った stale lock の場合は rmdir '${LOCK_DIR}' で解除)" >&2
            exit 1
        fi
        LOCK_DIR_HELD=1
    fi
}

# --- 実行時ファイルの provision 記録 (health check で存在を再検証する) ---
PROVISIONED_PATHS=()

# --- post-setup health check ---
post_setup_health_check() {
    local wt="$1" rc=0 f store_path store_links dep_real
    # 1. 必須ファイル + provision したファイルの存在
    for f in .env "${PROVISIONED_PATHS[@]+"${PROVISIONED_PATHS[@]}"}"; do
        if [[ ! -e "${wt}/${f}" ]]; then
            echo "  health-check FAIL: 必須パスが存在しない: ${f}" >&2
            rc=1
        fi
    done
    # 2. vendor: autoload 実行 smoke (worktree-local composer install が成立しているか)
    if ! (cd "${wt}" && php -d display_errors=0 -r 'require "vendor/autoload.php"; exit((int) ! class_exists(\App\Models\User::class));' 2>/dev/null); then
        echo "  health-check FAIL: autoload smoke 失敗 (App\\Models\\User が解決できない)" >&2
        rc=1
    fi
    # 3. node_modules: 実ディレクトリ + pnpm install 済み (.modules.yaml)
    if [[ ! -d "${wt}/node_modules" || -L "${wt}/node_modules" ]]; then
        echo "  health-check FAIL: node_modules が実ディレクトリでない (symlink/未作成)" >&2
        rc=1
    fi
    if [[ ! -f "${wt}/node_modules/.modules.yaml" ]]; then
        echo "  health-check FAIL: node_modules/.modules.yaml が無い (pnpm install 未完)" >&2
        rc=1
    fi
    # 4. GVS 実効: 代表 direct external dep (svelte) の realpath が共有 store の links/ 配下に
    #    解決されること (.modules.yaml だけでは GVS 無効 layout も成立し得るため core を直接 assert)
    if store_path="$(cd "${wt}" && pnpm store path --config.enableGlobalVirtualStore=true --config.nodeLinker=isolated 2>/dev/null)"; then
        store_links="${store_path%/}/links"
        dep_real="$(readlink -f "${wt}/node_modules/svelte" 2>/dev/null || true)"
        case "${dep_real}" in
            "${store_links}"/*) : ;;  # OK: GVS 有効
            *) echo "  health-check FAIL: GVS 無効の疑い (node_modules/svelte が store links 配下に解決されない: '${dep_real}')" >&2; rc=1 ;;
        esac
    else
        echo "  health-check FAIL: pnpm store path の取得に失敗" >&2
        rc=1
    fi
    # 5. cold-state ツール smoke: install 直後に test/phpstan/Laravel が cold で実行可能か fail-fast 検出
    if ! (cd "${wt}" && php artisan --version >/dev/null 2>&1); then
        echo "  health-check FAIL: artisan --version 失敗 (Laravel bootstrap が cold で壊れている)" >&2
        rc=1
    fi
    if [[ -x "${wt}/vendor/bin/pest" ]]; then
        if ! (cd "${wt}" && vendor/bin/pest --version >/dev/null 2>&1); then
            echo "  health-check FAIL: vendor/bin/pest --version 失敗 (test runner 実行不可)" >&2
            rc=1
        fi
    fi
    if [[ -x "${wt}/vendor/bin/phpstan" ]]; then
        if ! (cd "${wt}" && vendor/bin/phpstan --version >/dev/null 2>&1); then
            echo "  health-check FAIL: vendor/bin/phpstan --version 失敗 (静的解析実行不可)" >&2
            rc=1
        fi
    fi
    return $rc
}

# --- 工程別 timing ---
TIMING_LAST=""
emit_timing() {
    local label="$1" now elapsed
    now=$(date +%s)
    if [[ -n "${TIMING_LAST}" ]]; then
        elapsed=$(( now - TIMING_LAST ))
        echo "[timing] step=${label} elapsed=${elapsed}s" >&2
    fi
    TIMING_LAST="${now}"
}

# --- 失敗時クリーンアップ ---
SETUP_OK=0
WORKTREE_ADDED=0

cleanup_on_exit() {
    local rc=$?
    if (( ! SETUP_OK )) && (( WORKTREE_ADDED )); then
        echo ">>> [cleanup] setup 失敗のため worktree とブランチを削除します" >&2
        git -C "${REPO_ROOT}" worktree remove --force "${WORKTREE_DIR}" 2>/dev/null || true
        git -C "${REPO_ROOT}" branch -D "${BRANCH_NAME}" 2>/dev/null || true
    fi
    if (( LOCK_DIR_HELD )); then
        rmdir "${LOCK_DIR}" 2>/dev/null || true
    fi
    exit $rc
}
trap cleanup_on_exit EXIT

# === [0/7] 事前条件チェック + lock ===
echo ">>> [0/7] 事前条件チェック"
acquire_lock
if [[ -e "${WORKTREE_DIR}" ]]; then
    echo "error: ${WORKTREE_DIR} は既に存在します。teardown 先に: scripts/teardown-worktree.sh ${TASK_ID}" >&2
    exit 1
fi
TIMING_LAST=$(date +%s)
emit_timing "0-precheck"

# === [1/7] git worktree add ===
echo ">>> [1/7] git worktree add ${WORKTREE_DIR} -b ${BRANCH_NAME} main"
git -C "${REPO_ROOT}" worktree add "${WORKTREE_DIR}" -b "${BRANCH_NAME}" main
WORKTREE_ADDED=1
emit_timing "1-worktree-add"

# --- mise trust ---
# Node/pnpm 等を mise で管理する環境では、新規 worktree の mise.toml が untrusted 扱いになり
# 後続 pnpm install (pnpm→node が mise shim 経由) が "Config not trusted" で落ちる。
# worktree 作成直後に対象 path を trust して bootstrap を回復する。
# mise 非導入環境では skip し基盤を壊さない (冪等・非対話)。set -e を発火させない。
if command -v mise >/dev/null 2>&1; then
    mise trust "${WORKTREE_DIR}" >/dev/null 2>&1 \
        || echo "warning: mise trust 失敗。後続 pnpm install が落ちる場合は手動で 'mise trust ${WORKTREE_DIR}' を実行" >&2
fi

# === [2/7] 実行時ファイルのプロビジョニング ===
# .env は必須 (workspace の .env、無ければ committed の .env.example をコピー)。
# storage/oauth-*.key / public/build は runtime artifact (.gitignore 対象) で、workspace に
# あればコピー / 無ければ note して続行 (テンプレート初期状態では未生成のことがある。
# 必要になった時点で worktree 内 `php artisan passport:keys` / `pnpm build` で生成できる)。
echo ">>> [2/7] .env / .env.bughunt.local / storage/oauth-*.key / public/build を親からコピー"
if [[ -f "${REPO_ROOT}/.env" ]]; then
    cp "${REPO_ROOT}/.env" "${WORKTREE_DIR}/.env"
else
    cp "${REPO_ROOT}/.env.example" "${WORKTREE_DIR}/.env"   # .env 不在時は committed の .env.example をコピー
fi
for f in storage/oauth-private.key storage/oauth-public.key; do
    if [[ -f "${REPO_ROOT}/${f}" ]]; then
        cp "${REPO_ROOT}/${f}" "${WORKTREE_DIR}/${f}"
        PROVISIONED_PATHS+=("${f}")
    else
        echo "    note: ${f} が親に無いためコピーをスキップ (必要なら worktree 内で 'php artisan passport:keys')" >&2
    fi
done
# ★ 関数呼び出しを if の条件に置かない。条件内では set -e が効かず、
#   install の失敗が「無いためスキップ」に化けて秘密ファイルのコピー失敗を隠す。
if [[ -f "${REPO_ROOT}/.env.bughunt.local" ]]; then
    provision_bughunt_env_file "${REPO_ROOT}" "${WORKTREE_DIR}"
    PROVISIONED_PATHS+=(".env.bughunt.local")
else
    echo "    note: .env.bughunt.local が親に無いためコピーをスキップ (bug-hunt 未使用なら不要)" >&2
fi
if [[ -d "${REPO_ROOT}/public/build" ]]; then
    cp -r "${REPO_ROOT}/public/build" "${WORKTREE_DIR}/public/build"
    PROVISIONED_PATHS+=("public/build")
else
    echo "    note: public/build が親に無いためコピーをスキップ (必要なら worktree 内で 'pnpm build')" >&2
fi
emit_timing "2-provision"

# === [3/7] submodule init (worktree 側 cwd、.gitmodules がある場合のみ) ===
# composer install (step 4) が path repository で submodule を解決する構成に備えて先に init する。
if [[ -f "${REPO_ROOT}/.gitmodules" ]]; then
    echo ">>> [3/7] git submodule update --init --recursive"
    git -C "${WORKTREE_DIR}" submodule update --init --recursive
else
    echo ">>> [3/7] submodule なし (skip)"
fi
emit_timing "3-submodule-init"

# === [4/7] vendor: worktree 内 composer install (worktree-local 独立 vendor) ===
# --no-scripts: post-autoload-dump の artisan package:discover 等を skip する。
#   (a) dev DB の cache テーブル不在等で artisan が例外を起こす環境でも install を成立させる、
#   (b) Laravel 12 は bootstrap/cache/packages.php 不在時 runtime auto-discovery する、
#   ため機能影響なし。step 2 で .env を配置済み。
# リトライ: 共有 FS (OrbStack/virtiofs 等) では大量小ファイル展開時に一過性の
# ENOMEM ("Cannot allocate memory") で落ちることがある (再実行で成功する)。composer 内蔵の
# HTTP 層リトライでは FS 書き込み層の失敗を救えないため、ステップ全体を最大 3 回リトライ。
# sync は共有 FS バッファ flush のベストエフォート (set -e を発火させない)。
echo ">>> [4/7] vendor: worktree 内 composer install --no-scripts"
composer_install_ok=0
for _attempt in 1 2 3; do
    if (cd "${WORKTREE_DIR}" && composer install --no-progress --no-interaction --no-scripts); then
        composer_install_ok=1
        break
    fi
    echo "warn: composer install 失敗 (attempt ${_attempt}/3)、リトライします" >&2
    if [[ "${_attempt}" -lt 3 ]]; then sync || true; sleep 3; fi   # 最終試行後は待機しない
done
if [[ "${composer_install_ok}" -ne 1 ]]; then
    echo "error: worktree 内 composer install に失敗しました (一過性障害の自動回復を 3 回試みたが失敗)。lockfile / 認証 / registry 到達性を確認してください" >&2
    exit 1
fi
emit_timing "4-composer-install"

# === [5/7] node_modules: worktree 内 pnpm install (global virtual store 共有) ===
# 実体は共有 store (<store-path>/links/) を symlink 参照するため disk/install は安価、かつ
# install/add の影響が自 worktree に閉じる。
# CRITICAL: 親環境に CI=true 等が立つと pnpm が enableGlobalVirtualStore を自動無効化し得るため、
#   意図を CLI --config.* で明示強制する (CLI は env / PNPM_CONFIG_* / 設定ファイルより最優先)。
# リトライ: composer (step 4) と同一の一過性 ENOMEM 対策で完全対称の最大 3 回。
# --config.* 強制と --frozen-lockfile はリトライ各回で維持。
echo ">>> [5/7] node_modules: worktree 内 pnpm install --frozen-lockfile (global virtual store)"
pnpm_install_ok=0
for _attempt in 1 2 3; do
    if (cd "${WORKTREE_DIR}" && pnpm install \
            --frozen-lockfile \
            --config.ci=false \
            --config.enableGlobalVirtualStore=true \
            --config.nodeLinker=isolated \
            --config.confirmModulesPurge=false); then
        pnpm_install_ok=1
        break
    fi
    echo "warn: pnpm install 失敗 (attempt ${_attempt}/3)、リトライします" >&2
    if [[ "${_attempt}" -lt 3 ]]; then sync || true; sleep 3; fi   # 最終試行後は待機しない
done
if [[ "${pnpm_install_ok}" -ne 1 ]]; then
    echo "error: worktree 内 pnpm install に失敗しました (一過性障害の自動回復を 3 回試みたが失敗)。lockfile / 認証 / registry 到達性を確認してください" >&2
    exit 1
fi
emit_timing "5-pnpm-install"

# === [6/7] post-setup health check ===
echo ">>> [6/7] post-setup health check"
if ! post_setup_health_check "${WORKTREE_DIR}"; then
    echo "error: post-setup health check に失敗しました" >&2
    exit 1
fi
echo "    health check: OK"
emit_timing "6-health-check"

# === [7/7] pgsql test base DB を冪等 ensure ===
# worktree の base テスト DB を先に用意する。pgsql 非接続環境でも setup 全体を
# 壊さないよう warning 扱い (test 実行時に run-test.sh が再 ensure する)。
echo ">>> [7/7] ensure pgsql test base DB"
if [[ ! -f "${WORKTREE_DIR}/scripts/ci/ensure-test-db.php" ]]; then
    echo "    warning: scripts/ci/ensure-test-db.php が worktree に無いため skip (test 実行時に再 ensure されます)" >&2
elif php "${WORKTREE_DIR}/scripts/ci/ensure-test-db.php"; then
    echo "    ensure: OK"
else
    echo "    warning: ensure-test-db に失敗 (pgsql 非接続?)。test 実行時に再 ensure されます" >&2
fi
emit_timing "7-ensure-test-db"

SETUP_OK=1

echo ""
echo "✅ worktree 作成完了: ${WORKTREE_DIR}"
echo "   ブランチ: ${BRANCH_NAME}"
echo ""
echo "📦 依存は worktree-local (vendor=composer install / node_modules=pnpm install + GVS 共有)"
echo "   worktree 内 ルール (詳細は AGENTS.md §worktree 運用ルール):"
echo "   - pnpm install / composer install は許可 (worktree-local。main/他 worktree を汚さない)"
echo "   - pnpm add/remove/update・composer require/remove は task branch 上で行い、"
echo "     変更した package.json / pnpm-lock.yaml / composer.json / composer.lock を必ずコミットすること"
echo ""
echo "次の作業:"
echo "  cd ${WORKTREE_DIR}"
echo "  # 実装 → composer test / composer phpstan / vendor/bin/pint / pnpm test / pnpm lint"
echo "  # 完了後: scripts/teardown-worktree.sh ${TASK_ID}"

```

## 参考: 現行の契約テスト (7 ケース)

```php
<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * setup-worktree.sh の実行時ファイル provisioning 契約。
 *
 * bug-hunt は worktree 走行が既定 (AGENTS.md) だが、.env.bughunt.local は .gitignore 対象で
 * worktree には決して現れない。親からのコピーが唯一の供給路であり、無いと provision が必ず止まる
 * (bug-hunt run 20260809-152048 で実際に踏み、手動 cp で回避した)。
 *
 * 秘密ファイルの複製なので **mode は 0600 に固定**する。親が 0644 のとき `cp -p` は
 * world-readable な秘密ファイルを新たに作るため契約として弱く、`cp` → `chmod` の 2 段にも
 * 「一瞬だけ広く読める窓」がある。`install -m 600` は作成時点で mode を確定する。
 *
 * setup-worktree.sh は top-level 実行型 (main() を持たない) なので、素朴に source すると
 * composer install / pnpm install / DB 作成まで走る。SETUP_WORKTREE_SOURCE_ONLY で
 * 関数定義だけ取り込んで抜ける guard を使う。
 */

/**
 * setup-worktree.sh を source して provision_bughunt_env_file だけを叩く。
 * 引数は位置引数で渡す (文字列連結による shell injection を避ける)。
 */
function runProvisionBughuntEnvFile(string $parent, string $worktree): int
{
    $result = Process::timeout(60)
        ->env(['SETUP_WORKTREE_SOURCE_ONLY' => '1'])
        ->run([
            'bash', '-c',
            'source "$1"; provision_bughunt_env_file "$2" "$3"',
            '_',
            base_path('scripts/setup-worktree.sh'),
            $parent,
            $worktree,
        ]);

    return $result->exitCode() ?? 1;
}

/** @return array{0: string, 1: string} [親, worktree] の一時ディレクトリ */
function makeWorktreeFixture(): array
{
    $base = sys_get_temp_dir().'/setup-worktree-contract-'.bin2hex(random_bytes(6));
    File::makeDirectory($base.'/parent', 0700, true);
    File::makeDirectory($base.'/worktree', 0700, true);

    return [$base.'/parent', $base.'/worktree'];
}

test('親に .env.bughunt.local があれば worktree へコピーされる', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");

        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
        expect(File::exists($worktree.'/.env.bughunt.local'))->toBeTrue();
        expect(File::get($worktree.'/.env.bughunt.local'))->toBe("APP_ENV=bughunt.local\n");
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('親に .env.bughunt.local が無ければ何もしない (bug-hunt 非利用リポジトリで no-op)', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
        expect(File::exists($worktree.'/.env.bughunt.local'))->toBeFalse();
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('親が 0644 でもコピー先は 0600 になる', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");
        chmod($parent.'/.env.bughunt.local', 0644);

        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);

        $mode = fileperms($worktree.'/.env.bughunt.local') & 0777;
        expect(decoct($mode))->toBe('600', 'コピー先が world-readable になっている (cp -p / cp+chmod への退行)');
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('コピー先が既に存在しても上書き後に 0600 になる', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env.bughunt.local', "APP_ENV=new\n");
        chmod($parent.'/.env.bughunt.local', 0644);
        File::put($worktree.'/.env.bughunt.local', "APP_ENV=old\n");
        chmod($worktree.'/.env.bughunt.local', 0666);

        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);

        expect(File::get($worktree.'/.env.bughunt.local'))->toBe("APP_ENV=new\n");
        expect(decoct(fileperms($worktree.'/.env.bughunt.local') & 0777))->toBe('600');
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('コピーに失敗したら非ゼロで落ちる (失敗を握り潰さない)', function (): void {
    // 秘密ファイルのコピー失敗を「親に無いためスキップ」に化けさせないこと。
    // コピー先ディレクトリを書き込み不可にして install を失敗させる。
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");
        chmod($worktree, 0500);   // 書き込み不可

        expect(runProvisionBughuntEnvFile($parent, $worktree))
            ->not->toBe(0, 'コピー失敗が成功扱いになっている');
        expect(File::exists($worktree.'/.env.bughunt.local'))->toBeFalse();
    } finally {
        chmod($worktree, 0700);
        File::deleteDirectory(dirname($parent));
    }
})->skip(
    posix_geteuid() === 0,
    'root では書き込み不可ディレクトリでも install が成功するため検証できない',
);

test('本体の呼び出しが if の条件式に置かれていないこと (set -e で失敗が伝播する)', function (): void {
    // 関数単体テストでは top-level の set -e 配線までは固定できない。
    // `if provision_bughunt_env_file ...` の形へ戻ると、install の失敗が
    // 条件評価に吸われて「無いためスキップ」に化ける (Round 1 の指摘)。
    //
    // ★ 保証範囲: これは**既知の回帰形の検出**であって、
    //   「あらゆる bash 条件コンテキストに置かれていないことの証明」ではない
    //   (複数行にまたがる複雑な条件式までは正規表現で追えない)。
    //   素の呼び出し行の完全一致と組み合わせて、実用上の検出力を確保している。
    $source = File::get(base_path('scripts/setup-worktree.sh'));

    expect($source)->not->toMatch(
        '/\b(if|while|until|&&|\|\|)\s+provision_bughunt_env_file/',
        'provision_bughunt_env_file が条件式の位置で呼ばれている (set -e が効かず失敗を握り潰す)',
    );
    // 本体からは素の文として呼ばれていること
    expect($source)->toMatch('/^\s{4}provision_bughunt_env_file "\$\{REPO_ROOT\}" "\$\{WORKTREE_DIR\}"$/m');
});

test('install -m 600 を使っていること (cp + chmod の 2 段へ退行していない)', function (): void {
    // 2 段だと cp 直後から chmod までの間だけ world-readable な秘密ファイルが存在する。
    $source = File::get(base_path('scripts/setup-worktree.sh'));

    expect($source)->toContain('install -m 600 "${repo_root}/.env.bughunt.local"');
});

```
