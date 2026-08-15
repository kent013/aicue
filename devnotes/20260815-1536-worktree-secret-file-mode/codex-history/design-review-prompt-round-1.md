## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。



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
- PHPStan level 10 / Pest テストフレームワーク
- 本設計の変更対象は **bash スクリプト (scripts/setup-worktree.sh) と Pest の契約テスト、およびドキュメント**である
- このスクリプトは開発ワークフロー (LLM エージェントが TODO ごとに git worktree を切って実装する運用) 自身が使う。壊すと後続の実装作業がすべて止まる

【レビュー観点】
1. bash としての正確性 (set -euo pipefail 下での挙動、戻り値、変数展開、配列、引用符、set -e が効かない位置)
2. 既存コード・既存運用との整合性 (health check、EXIT trap、lock 排他、teardown との関係)
3. 契約テストの網羅性と、テストが実際に退行を捕まえられるか (偽緑になっていないか)
4. 副作用・後退リスク。とくに「開発ワークフロー自身が使うスクリプトを壊さない」ための受入確認手順が十分か
5. セキュリティ (秘密ファイルの露出面、mode 確定のタイミング、シンボリックリンク等の悪用余地)
6. 保証範囲の誇張がないか (「守られる」と書いていることが実際に守られる範囲と一致しているか)
7. スコープの適切さ (オーバーエンジニアリング禁止。今必要なものだけ)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: worktree-secret-file-mode

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

> 本設計が触るのは bash スクリプトと Pest の契約テストなので、直接該当するのは **1 (テストなしの実装完了報告)** と
> **9 (Artifact 不使用)** である。残りは変更対象に該当しないが、`composer test` 全体を緑にするので影響は受ける。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- テストデータは Factory で生成（本設計は DB を使わないので該当なし）
- **コードフォーマット**: `composer fix`（Pint）
- shell スクリプトは既存の書式に合わせる（`set -euo pipefail` / 日本語コメント / 4 スペースインデント）

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) （Codex 概念設計レビュー Round 2 で APPROVED）
- 家系台帳: lctl feature `worktree-task-isolation` / 裁定 AG-153 の t1 定義

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 秘密ファイル供給関数の一般化 (`provision_secret_file`) | `scripts/setup-worktree.sh` | 高 |
| 2 | 供給 4 本の関数経由への統一と見本による代替の撤去 | `scripts/setup-worktree.sh` | 高 |
| 3 | `.env` 不足の事前確認 (`[0/7]`) | `scripts/setup-worktree.sh` | 中 |
| 4 | 契約テストの拡張 (7 → 17 ケース) | `tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php` | 高 |
| 5 | ドキュメント追従 | `AGENTS.md` / `docs/worktree-isolation-strategy.md` / `scripts/README.md` | 中 |

---

## 施策 1: 秘密ファイル供給関数の一般化 (`provision_secret_file`)

### 変更箇所

- ファイル: `scripts/setup-worktree.sh` (L33-47 の `provision_bughunt_env_file`)

### 波及変更

- 契約テスト: `tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php` (施策 4 で全面改訂)
- TypeScript 型定義 / API Resource / DTO: なし (PHP・JS のアプリコードに触れない)

### 現行コード

```bash
provision_bughunt_env_file() {
    local repo_root=$1 worktree_dir=$2
    [[ -f "${repo_root}/.env.bughunt.local" ]] || return 0   # 非利用リポジトリでは no-op
    install -m 600 "${repo_root}/.env.bughunt.local" "${worktree_dir}/.env.bughunt.local"
}
```

### 変更後コード

```bash
# --- worktree へ供給する実行時ファイルの provisioning (契約テストから source して単体で叩ける) ---
#
# 契約:
#   1) 供給先の mode は**作成時点で 0600 に確定**する。供給元の mode に追随させない。
#      単純な cp は新規作成時に供給元の mode を引き継ぐため、親の .env が 0644 だと
#      worktree を作るたびに world-readable な秘密ファイルが 1 つ増える (実測)。
#      さらに cp は**供給先が既に存在するとその mode を変えない**ので、一度広く置かれたら締まらない。
#      `install -m 600` は作成時点で mode を確定するので、cp → chmod の 2 段にある
#      「一瞬だけ広く読める窓」も作らない。
#   2) 供給元が無いとき: required なら止める / optional なら何もしない (空ファイルを作らない)。
#   3) **供給先の親ディレクトリは作らない**。作ると、供給先のパスを間違えたときに worktree の外へ
#      静かにディレクトリを作る経路ができる。worktree には storage/ が追跡下で必ず存在する。
#   4) 要否指定は required / optional だけを受理し、それ以外は落とす (fail-closed)。
#
# なぜ公開鍵 (storage/oauth-public.key) まで 0600 なのか:
#   worktree へ供給する実行時ファイルは配布物ではなく、**作業者本人の PHP プロセスだけが読む**。
#   1 本だけ例外にすると「どれを狭く置くか」の判断がこのスクリプトに 2 種類生まれ、
#   次に供給行を足す人が毎回判断させられる。狭く置いて壊れる利用者は現構成に存在しない。
#   (別の OS ユーザーのプロセスが worktree の鍵を読む構成は本スクリプトの対象外)
#
# なぜ public/build は対象外なのか:
#   秘密ではないフロントのビルド成果物 (ディレクトリ) だから。ここで扱うのは単一ファイルだけである。
#
# 使い方: provision_secret_file <required|optional> <repo_root> <worktree_dir> <relative_path> [hint]
provision_secret_file() {
    local requirement=$1 repo_root=$2 worktree_dir=$3 relative=$4 hint=${5:-}
    local src="${repo_root}/${relative}" dst="${worktree_dir}/${relative}"

    case "${requirement}" in
        required)
            if [[ ! -f "${src}" ]]; then
                echo "error: 必須の供給元がありません: ${src}" >&2
                [[ -n "${hint}" ]] && echo "       ${hint}" >&2
                return 1
            fi
            ;;
        optional)
            if [[ ! -f "${src}" ]]; then
                echo "    note: ${relative} が親に無いため供給をスキップ${hint:+ (${hint})}" >&2
                return 0
            fi
            ;;
        *)
            echo "error: provision_secret_file: 要否指定は required か optional のみ (受け取った値: '${requirement}')" >&2
            return 2
            ;;
    esac

    install -m 600 "${src}" "${dst}"
    PROVISIONED_PATHS+=("${relative}")   # health check が存在を再検証する
}
```

- `PROVISIONED_PATHS` は本体側で `PROVISIONED_PATHS=()` と宣言済み。契約テストは source 専用入口から
  未宣言の状態で叩くが、bash の `arr+=(x)` は未宣言でも配列を作るので `set -u` でも落ちない。
- `[[ -n "${hint}" ]] && echo ...` は**戻り値を作らない位置**に置く (直後に `return 1` があるので
  `set -e` の影響を受けない)。`optional` 側は `${hint:+ (${hint})}` の展開で分岐を作らない。

### 設計判断

| 論点 | 決定 | 理由 |
|---|---|---|
| 関数の引数 | 要否 / repo_root / worktree_dir / 相対パス / hint | 相対パスを 1 か所で src・dst の両方に展開でき、`PROVISIONED_PATHS` にそのまま記録できる |
| hint 引数 | 持つ | 現行の「無いためスキップ (必要なら `php artisan passport:keys`)」という復旧案内を落とさないため。呼び出し側の `else echo` ブロックと 1:1 で置き換わる |
| 親ディレクトリ作成 | しない (`install -D` を使わない) | 上記契約 3 |
| 供給先が既存の場合 | 上書きして 0600 にする | `install` は常に mode を設定する。cp は既存 mode を維持するので、ここが退行の入り口になる |
| 失敗時 | 非ゼロで返し、呼び出し側の `set -e` で止める | 秘密ファイルの供給失敗を「無いためスキップ」に化けさせない |

### テスト計画

施策 4 の D-1〜D-12 / S-1〜S-5 が本関数の契約を固定する。詳細は施策 4。

### リスク

- **`install` が無い環境**では動かない。現行も `.env.bughunt.local` の供給で既に `install` に依存しており、
  開発コンテナ (coreutils あり) が前提なので新しいリスクではない。
- **`.env` を 0600 にすると読めなくなる利用者**がいれば壊れる。本リポジトリでは worktree の中で
  同一ユーザーの PHP が読むだけなので該当しない (概念設計の前提)。実走確認 (V-2) で `php artisan --version` /
  `vendor/bin/pest --version` / テスト DB ensure まで通ることを確かめる。

---

## 施策 2: 供給 4 本の関数経由への統一と見本による代替の撤去

### 変更箇所

- ファイル: `scripts/setup-worktree.sh` (L217-250 の `=== [2/7] 実行時ファイルのプロビジョニング ===`)
- ファイル: `scripts/setup-worktree.sh` (L105-113 の `post_setup_health_check` の必須ファイルループ)

### 現行コード

```bash
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
```

### 変更後コード

```bash
# === [2/7] 実行時ファイルのプロビジョニング ===
# 秘密ファイル 4 本は provision_secret_file 経由で供給する (作成時点で mode を 0600 に確定)。
# public/build は秘密でないビルド成果物のディレクトリなので対象外 (cp -r のまま)。
#
# ★ 関数呼び出しを if / while / && / || の条件位置に置かない。
#   条件の中では set -e が効かず、install の失敗が「無いためスキップ」に化けて
#   秘密ファイルの供給失敗を隠す。要否の判定は関数の中にある。
echo ">>> [2/7] .env / storage/oauth-*.key / .env.bughunt.local / public/build を親から供給"
provision_secret_file required "${REPO_ROOT}" "${WORKTREE_DIR}" ".env" "${ENV_SETUP_HINT}"
provision_secret_file optional "${REPO_ROOT}" "${WORKTREE_DIR}" "storage/oauth-private.key" "必要なら worktree 内で 'php artisan passport:keys'"
provision_secret_file optional "${REPO_ROOT}" "${WORKTREE_DIR}" "storage/oauth-public.key" "必要なら worktree 内で 'php artisan passport:keys'"
provision_secret_file optional "${REPO_ROOT}" "${WORKTREE_DIR}" ".env.bughunt.local" "bug-hunt 未使用なら不要"
if [[ -d "${REPO_ROOT}/public/build" ]]; then
    cp -r "${REPO_ROOT}/public/build" "${WORKTREE_DIR}/public/build"
    PROVISIONED_PATHS+=("public/build")
else
    echo "    note: public/build が親に無いためコピーをスキップ (必要なら worktree 内で 'pnpm build')" >&2
fi
emit_timing "2-provision"
```

health check 側 (L108) の必須ファイルループは、`.env` が `PROVISIONED_PATHS` に必ず入るようになるため
二重に数えないよう `.env` の直書きを外す:

```bash
    # 1. provision したパスの存在 (.env は required 供給なのでここに必ず含まれる。
    #    含まれない = [2/7] を通っていないということなので、そもそもここへ到達しない)
    for f in "${PROVISIONED_PATHS[@]+"${PROVISIONED_PATHS[@]}"}"; do
```

> `.env` の存在検査が消えないことは、施策 4 の S-2 (主経路が `.env` を `required` で供給している行の
> 完全一致) が固定する。

### 設計判断: `.env.example` による代替の撤去

- 撤去する。`.env.example` から作った `.env` は `APP_KEY` も DB 接続も入っておらず、
  `[6/7]` の health check (`php artisan --version` まで) は通ってしまうため、
  **動くように見えて壊れている worktree** が無言で出来上がる。
- さらに `.env.example` は追跡下の 0644 ファイルで、そこから作る `.env` も広い mode で生まれる。
  供給行だけ直しても代替経路に同じ穴が残る。
- 代わりに `.env` を `required` にして止める。**後方互換の並走を残さない** (思考原則 3) ので、
  フォールバックのコードは同じ変更で消す。

### 波及変更

- `composer setup` / `post-root-package-install` の `file_exists('.env') || copy('.env.example', '.env')` は
  **親のチェックアウトを整える経路**であり、本変更の対象外 (触らない)。むしろ `.env` が無い人の
  正しい回復手段なので、停止時の hint から案内する。

### テスト計画

- D-7 / D-8 (要否ごとの供給元不在時の挙動) と S-5 (`.env.example` の代替が復活していない)。
- 実走確認 V-2 (施策 5 の後の「受入確認」節) で `[2/7]` が完走することを確かめる。

### リスク

- **`.env` を持たないチェックアウトで worktree を作れなくなる**。これは意図した破壊的変更で、
  止まった人が復旧できるよう hint に手順を書く。CI は `setup-worktree.sh` を呼ばないので CI への影響はない。
- 供給行を関数に寄せたことで、**呼び出し行が長くなる** (hint を含むため)。可読性のためにも
  hint は 1 行に収まる短さにする。

---

## 施策 3: `.env` 不足の事前確認 (`[0/7]`)

### 変更箇所

- ファイル: `scripts/setup-worktree.sh` (L191-199 の `=== [0/7] 事前条件チェック + lock ===`)

### 変更後コード

```bash
# .env が無いときの復旧案内 (事前確認と供給関数の hint で同じ文言を使う)
ENV_SETUP_HINT="親のチェックアウトで 'cp .env.example .env' → 'php artisan key:generate' を実行してから再実行してください"

# === [0/7] 事前条件チェック + lock ===
echo ">>> [0/7] 事前条件チェック"
acquire_lock
if [[ -e "${WORKTREE_DIR}" ]]; then
    echo "error: ${WORKTREE_DIR} は既に存在します。teardown 先に: scripts/teardown-worktree.sh ${TASK_ID}" >&2
    exit 1
fi
# 必須の供給元が無いなら worktree を作る前に止める (作りかけの片付けを発生させない)。
# ★ これは早く止めるための事前確認であって判定の正本ではない。
#   決着は [2/7] の provision_secret_file (required) にある。
if [[ ! -f "${REPO_ROOT}/.env" ]]; then
    echo "error: 親のチェックアウトに .env がありません: ${REPO_ROOT}/.env" >&2
    echo "       ${ENV_SETUP_HINT}" >&2
    echo "       (worktree はまだ作っていないので後片付けは要りません)" >&2
    exit 1
fi
TIMING_LAST=$(date +%s)
emit_timing "0-precheck"
```

### 設計判断

- 判定を 2 か所に置くことになるが、**役割が違う**ので重複ではない。
  `[0/7]` は「worktree を作る前に止める」ための事前確認、`[2/7]` は「供給の決着」である。
  事前確認を消しても壊れず (worktree を作ってから止まり trap が片付ける)、
  供給側を消すと契約が消える。文言 (`ENV_SETUP_HINT`) は変数 1 本で共有し二重管理しない。

### テスト計画

- S-6 相当の静的検査は置かない (事前確認は「あると親切」な層で、契約の正本ではないため。
  ここまで pin すると、将来 `[0/7]` を整理するときにテストが理由なく赤くなる)。
- 契約として固定するのは供給関数側 (D-8) だけ。**この非対称は意図的**である。

### リスク

- なし (追加の早期 return のみ)。

---

## 施策 4: 契約テストの拡張 (7 → 17 ケース)

### 変更箇所

- ファイル: `tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php` (全面改訂)

### 既存 7 ケースの引き継ぎ (1 つも落とさない)

| 既存ケース | 新ケース | 扱い |
|---|---|---|
| 親に `.env.bughunt.local` があればコピーされる | D-5 | 新関数へ読み替えて維持 |
| 親に無ければ何もしない | D-7 | 対象を一般化して維持 |
| 親が 0644 でもコピー先は 0600 | D-2 (.env) / D-5 (bughunt) | 対象を拡大して維持 |
| コピー先が既に存在しても上書き後 0600 | D-6 | 維持 |
| コピー失敗で非ゼロ | D-11 | 維持 (root では skip も維持) |
| 呼び出しが条件式に置かれていない | S-1 | 対象を 4 本すべてに拡大して維持 |
| `install -m 600` を使っている | S-3 | 維持 |

### 共通ヘルパ

```php
/**
 * setup-worktree.sh を source して provision_secret_file だけを叩く。
 * 引数は位置引数で渡す (文字列連結による shell injection を避ける)。
 * stdout には、その呼び出しで PROVISIONED_PATHS に記録された相対パスが 1 行ずつ出る。
 */
function runProvisionSecretFile(
    string $requirement,
    string $parent,
    string $worktree,
    string $relative,
    string $hint = '',
): ProcessResult {
    return Process::timeout(60)
        ->env(['SETUP_WORKTREE_SOURCE_ONLY' => '1'])
        ->run([
            'bash', '-c',
            'source "$1"; provision_secret_file "$2" "$3" "$4" "$5" "$6"; '
                .'printf "%s\n" "${PROVISIONED_PATHS[@]+"${PROVISIONED_PATHS[@]}"}"',
            '_',
            base_path('scripts/setup-worktree.sh'),
            $requirement, $parent, $worktree, $relative, $hint,
        ]);
}
```

- fixture は親 / worktree の一時ディレクトリを作り、**worktree 側に `storage/` も作る**
  (関数が親ディレクトリを作らない契約なので、実 worktree と同じ前提を fixture 側で用意する)。

### 動的ケース (source 専用入口から実走)

| # | ケース | 何を守るか |
|---|---|---|
| D-1 | 供給元があれば内容が供給先へ入る (`.env`) | 供給そのもの |
| D-2 | 親が 0644 でも供給先は 0600 (`.env`) | **対象拡大の本丸**。cp への退行検出 |
| D-3 | 親が 0644 でも供給先は 0600 (`storage/oauth-private.key`) | Passport 署名鍵の露出 |
| D-4 | 親が 0644 でも供給先は 0600 (`storage/oauth-public.key`) | 公開鍵も同じ契約に入れる**意図的な選択**の固定 |
| D-5 | 親が 0644 でも供給先は 0600 (`.env.bughunt.local`) | 既存契約の維持 |
| D-6 | 供給先が既に 0666 で存在しても、上書き後は内容が新しく mode は 0600 | cp が既存 mode を維持する退行の検出 |
| D-7 | `optional` で供給元が無ければ終了コード 0・供給先を作らない | 空ファイルを作らない |
| D-8 | `required` で供給元が無ければ非ゼロ・供給先を作らない | 見本による代替の復活と、静かな continue の検出 |
| D-9 | 要否指定が `required` / `optional` 以外なら非ゼロ | fail-closed |
| D-10 | 供給先の親ディレクトリが無ければ非ゼロ (勝手に作らない) | worktree 外へ静かにディレクトリを作らない |
| D-11 | 供給先ディレクトリが書き込み不可なら非ゼロ (root では skip) | 供給失敗を握り潰さない |
| D-12 | `PROVISIONED_PATHS` には**供給したものだけ**が記録される (`optional` 不在時は記録されない) | health check が存在しないパスを検査して偽赤になるのを防ぐ |

### 静的ケース (`scripts/setup-worktree.sh` の本文を読む)

| # | ケース | 何を守るか |
|---|---|---|
| S-1 | `provision_secret_file` が `if` / `while` / `until` / `&&` / `\|\|` の直後に現れない | 条件位置に置くと `set -e` が効かず失敗が握り潰される |
| S-2 | 秘密ファイル 4 本が、素の呼び出し行の**完全一致**で主経路に存在する (`.env` は `required`、他 3 本は `optional`) | 供給対象が黙って減る・要否が黙って変わるのを検出 |
| S-3 | `install -m 600` を使っている (`cp` → `chmod` の 2 段へ退行していない) | 広い mode で存在する瞬間を作らない |
| S-4 | 秘密ファイル 4 本を `cp` で供給する行が残っていない | 関数経由への統一が崩れていない |
| S-5 | `.env.example` を `.env` として置く代替が復活していない | 壊れた worktree を無言で作らない |

> **保証範囲を誇張しない**: S-1〜S-5 は**既知の回帰形の検出**であって、bash のあらゆる条件コンテキストや
> あらゆる書き方を排除する証明ではない (複数行にまたがる条件式や、変数経由の間接呼び出しには沈黙する)。
> 素の呼び出し行の完全一致 (S-2) と組み合わせて実用上の検出力を確保している、という現行コメントの
> 立て付けをそのまま引き継ぐ。

### テストの実行方法

```bash
composer test -- --filter=SetupWorktreeRuntimeFilesContractTest   # 単体
composer test                                                     # 全体 (最後に必ず 1 回)
```

`composer test` はグローバルテストロック配下で走るため、他レーンが動いていると**待つ** (正常)。
待機中は 30 秒ごとに heartbeat が出る。kill もロックファイルの削除もしない (AGENTS.md)。

### PHPStan 適合チェック

- [ ] ヘルパ関数の戻り値型 (`ProcessResult`) が明示されている
- [ ] `Process::run()` の戻り値を直接使い、`exitCode()` の `null` は `?? 1` で潰す (現行と同じ)
- [ ] DTO 返却は不要 (テストヘルパ)
- [ ] Generics 不要

### リスク

- ケース数が 7 → 17 に増える分、テスト実行時間が伸びる。1 ケースあたり `bash` の子プロセス 1 本なので
  実測でも数百 ms 程度の増加に収まる見込み。**測って想定より重ければケースを削るのではなく理由を記録する**。

---

## 施策 5: ドキュメント追従

### 変更箇所と書く事実 (重複しない配分)

| ファイル | 書くこと |
|---|---|
| `AGENTS.md` §worktree 運用ルール | セットアップの説明にある「実行時ファイルのコピー」を「実行時ファイルの供給 (秘密ファイルは 0600 で作成、`.env` は必須で不在なら worktree を作らずに停止)」へ更新。**既存 worktree には遡及しない**ことを 1 行 |
| `docs/worktree-isolation-strategy.md` | 「分離の 4 軸」表の実行時ファイル行と `[2/7]` の説明を実装に合わせる。§ 実行時ファイルの供給契約として「0600 は新規 worktree だけ / 親の権限は直さない / `.env` 不在は作成前に停止 / 契約テストが正本」を 4 行で書く。「既知のギャップ」の `.env` に関する記述を実態へ更新 |
| `scripts/README.md` | `setup-worktree.sh` 行の説明にある「実行時ファイルのコピー」を「実行時ファイルの供給 (秘密ファイルは 0600)」へ短く更新 |

### 設計判断

- 3 か所に同じ説明を書かない。**運用ルールの正本は AGENTS.md、設計意図は
  `docs/worktree-isolation-strategy.md`、実装の正本はスクリプト本体**という現行の役割分担を守る。
- `docs/template-divergence.md` への登録は**しない**。本変更はテンプレートからの逸脱ではなく、
  むしろ家系正典 (t1) への追従だからである。

### テスト計画

- ドキュメントの機械検査は本設計では追加しない (追加すると、次に文面を直す人が理由なく赤くなる)。
- 代わりに実装時のチェックとして「AGENTS.md / strategy / README の 3 か所を更新したか」を
  受入確認のチェックリストに入れる。

### リスク

- ドキュメントと実装がずれる余地は残る。ずれの検出は台帳側の巡回 (lctl) が担っている。

---

## 受入確認 (実装フェーズで実行する — 設計フェーズでは実行しない)

**このスクリプトは本ワークフロー自身が使う。壊すと後続の実装がすべて止まる。**
以下を順に実行し、結果を実装 worktree の `devnotes/{dir}/` へ記録する。

### V-0 テストファースト (失敗の確認)

1. 施策 4 の契約テストを**先に**書く。
2. `composer test -- --filter=SetupWorktreeRuntimeFilesContractTest` を実行し、
   **新関数がまだ無いことによる失敗**を確認する (D-* が `command not found` 由来で落ちること)。
   ここで緑になったらテストが実質何も検査していないので、書き直す。

### V-1 実装と静的検査

1. 施策 1〜3 を実装する。
2. `composer test -- --filter=SetupWorktreeRuntimeFilesContractTest` が green。
3. `composer test` 全体が green。
4. `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` /
   `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` が green
   (AGENTS.md の検証コマンド一式)。
5. `bash -n scripts/setup-worktree.sh` (構文検査) が通る。`shellcheck` があれば併せて掛ける。

### V-2 実走確認 (本命 — 実際に worktree を作る)

**変更後のスクリプトを、本番と同じ呼ばれ方 (main のチェックアウトを作業対象にする形) で走らせる。**

```bash
# cwd が REPO_ROOT を決める (git rev-parse --show-toplevel)。
# スクリプトの実体だけを実装 worktree のものに差し替え、対象は main のチェックアウトにする。
cd /workspace
bash /workspace/.claude/worktrees/tasks/<task-id>/scripts/setup-worktree.sh <task-id>-verify
```

⚠ **注意事項**

- `.setup.lock` を取るため、他セッションの setup / teardown と同時には走れない。
  「別の setup/teardown が実行中です」で止まったら**壊れではない**。待って再実行する。
- 検証用の worktree 名は `<task-id>-verify` のように**本作業とは別の id** にする
  (本作業の worktree を消してしまわないため)。
- この確認は `composer install` + `pnpm install` を実走するので数分かかる。短縮のために
  手順を飛ばさない (飛ばすと「壊れていないこと」の根拠が消える)。

**確認項目**

1. `[0/7]` から `[7/7]` まで到達し、`health check: OK` と `✅ worktree 作成完了` が出る。
2. 供給された秘密ファイルの mode が全部 `600`:
   ```bash
   stat -c '%a %n' /workspace/.claude/worktrees/tasks/<task-id>-verify/.env \
        /workspace/.claude/worktrees/tasks/<task-id>-verify/storage/oauth-private.key \
        /workspace/.claude/worktrees/tasks/<task-id>-verify/storage/oauth-public.key \
        /workspace/.claude/worktrees/tasks/<task-id>-verify/.env.bughunt.local
   ```
3. `public/build` がディレクトリとしてコピーされている (対象外にした側が壊れていないこと)。
4. worktree の中で `php artisan --version` と `vendor/bin/pest --version` が通る
   (0600 にしたことで読めなくなっていないことの実地確認)。
5. `[timing]` の各値が従来と同程度 (供給段で目に見える遅延が出ていない)。

**後片付け**

```bash
cd /workspace
scripts/teardown-worktree.sh <task-id>-verify
git branch -D todo/<task-id>-verify
```

### V-3 必須不足で停止することの確認

**実走では行わない。**親の `.env` を一時的に退避する形は、同じチェックアウトで他のセッションが
並行して走っている本ワークフローでは危険である (退避中に他の setup やテストが `.env` を読む)。
この契約は施策 4 の **D-8** (供給関数の required 経路) と **S-5** (見本による代替の不在) が固定する。
`[0/7]` の事前確認まで含めた挙動を実地で見たい場合は、**別のクローン**を作ってそこで試す。

### V-4 マージのタイミングと、マージ後の再確認

1. **マージ前に `.setup.lock` が空いていること**を確認する
   (`ls /workspace/.claude/worktrees/.setup.lock.d` が無い / flock が取れる)。
   bash はスクリプトを実行しながら読み進めるため、**実行中のスクリプトを書き換えると壊れうる**。
2. main へマージした**直後**に、main 側のスクリプトでもう一度使い捨て worktree を作って完走を確認する:
   ```bash
   cd /workspace && scripts/setup-worktree.sh merge-verify && \
     stat -c '%a %n' /workspace/.claude/worktrees/tasks/merge-verify/.env && \
     scripts/teardown-worktree.sh merge-verify && git branch -D todo/merge-verify
   ```
   V-2 は「実装 worktree にあるファイル」を実行した確認なので、**マージ結果そのものの確認にはならない**。
   マージ後は全セッションがこのスクリプトを使うため、ここを省略しない。

### V-5 壊れたときの戻し方

まず**作業を止めないこと**を優先する。原因調査より先に、main の該当ファイルを直前の版へ戻す:

```bash
cd /workspace
git checkout <マージ直前のコミット> -- scripts/setup-worktree.sh
```

契約テストが赤くなるが、それは「実装が戻っている」ことの正しい表示である。
戻した事実と、何が壊れたかを devnotes へ記録してから再挑戦する。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 変更対象が `scripts/setup-worktree.sh` 1 本とその契約テストに閉じており、他施策と共有するファイルが無い。一方で**このスクリプト自体が全実装作業の入口**なので、他の実装と同じ worktree に混ぜると、失敗したときに何が原因で作業場所が作れなくなったのか切り分けられなくなる。受入確認 (V-2 / V-4) が worktree の作成・破棄を伴うため、単独で走らせて他の作業と干渉させない |
| 競合リスク | `scripts/setup-worktree.sh` を他の TODO が同時に触ると衝突する。マージ順は本 TODO を先にする。また V-2 / V-4 は `.setup.lock` を取るので、他セッションの setup / teardown とは時間的に排他になる (待てば解決する) |


## 関連する現行コード: scripts/setup-worktree.sh (全文)

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

## 関連する現行コード: tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php (全文)

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
