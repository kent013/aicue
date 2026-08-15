Round 1 の指摘への対応を報告します。全 Warning に対応し、Suggestion は 1 件を根拠付きで見送りました。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

## [Warning] 施策 1: 供給先が symlink のときの扱いが未定義
- 判断: 対応する
- 根拠: `install` は供給先の symlink を辿って**リンク先へ** 0600 で書き込む。関数を汎用化して
  「既存の供給先を上書きする」と契約に書く以上、辿った先が worktree の外でも書いてしまう形は残せない。
  4 行で閉じられるので費用も小さい。
- 対応内容: 供給前に `[[ -L "${dst}" ]]` を fail-closed で弾く。`install -m 600 -- "${src}" "${dst}"` と
  `--` も付ける。契約テストに D-13 (供給先が symlink なら非ゼロで落ち、リンク先を書き換えない) を追加。

## [Suggestion] 施策 1: 相対パス引数の絶対パス・`..` を拒否する guard
- 判断: 見送る
- 根拠: 呼び出しは同一ファイル内の 4 行の定数だけで、外部入力を受け取らない。今必要でないものは作らない
  (思考原則 2)。値が変われば S-2 (呼び出し行の完全一致) が落ちるので、黙って変わることはない。
- 対応内容: 見送りの理由を詳細設計の「設計判断」表に残す (次に読む人が再検討できるように)。

## [Suggestion] 施策 2: health check は存在確認、mode 保証は契約テストと明記
- 判断: 対応する
- 根拠: 保証範囲を誇張しない規約に沿う。
- 対応内容: 施策 5 のドキュメント配分に 1 行足す。

## [Warning] 施策 4: S-1 の正規表現が `&& provision_...` / `|| provision_...` を捕まえない
- 判断: 対応する (現行テストから引き継いだ実際の穴)
- 根拠: `\b` は「単語文字と非単語文字の境界」なので、直前が空白で直後が `&` のときは境界が成立せず
  `&&` 側の選択肢に到達しない。**現行の 7 ケース版が既にこの穴を持っている**。偽緑である。
- 対応内容: S-1 の式を
  `/(?:\b(?:if|while|until)\s+(?:!\s*)?|(?:&&|\|\|)\s*(?:!\s*)?)provision_secret_file\b/` に差し替え、
  「なぜ `\b` を先頭に置けないのか」をテストのコメントに残す。

## [Warning] 施策 4: `ProcessResult` の namespace が未明記
- 判断: 対応する
- 根拠: そのとおり。`Process::run()` の戻り値の型は契約側 (`Illuminate\Contracts\Process\ProcessResult`) が正しい
  (実測: `Illuminate\Process\PendingProcess::run()` は型宣言を持たず、契約 interface が
  `vendor/laravel/framework/src/Illuminate/Contracts/Process/ProcessResult.php` に実在する)。
- 対応内容: ヘルパの戻り値型を `Illuminate\Contracts\Process\ProcessResult` にして use を明記。

## [Warning] 施策 4: S-3 / S-4 が偽緑になりうる
- 判断: 対応する
- 根拠: 文字列の部分一致だとコメントや旧コードの残骸でも通る。
- 対応内容: S-3 は**関数本体でだけ現れる形** (`^\s{4}install -m 600 -- "\$\{src\}" "\$\{dst\}"$` の
  行アンカー付き完全一致) を検査する (コメント行は `#` で始まるので巻き込まない)。
  S-4 は「秘密ファイル 4 本を `cp` で供給する行」を名指しの正規表現で 0 件検査する。

## [Suggestion] 施策 4: D-10 は親ディレクトリが作られていないことまで assert
- 判断: 対応する
- 根拠: `install -D` への退行をより直接に捕まえられる。1 行で足りる。
- 対応内容: D-10 に「供給先の親ディレクトリが作られていないこと」の assert を足す。

## [Warning] 受入確認 V-2: `stat` が optional ファイル不在で偽赤になる
- 判断: 対応する
- 根拠: 4 本すべて存在前提の確認コマンドは、任意ファイルを持たない環境で必ず落ちる。
  確認手順自体が偽赤を生むのは受入確認として不適切。
- 対応内容: `.env` は必須として確認し、任意 3 本は**存在するものだけ** mode を見る形へ書き換える。
  併せて「任意ファイルが親に無い環境では note が出るのが正常」と明記する。

## [Warning] 受入確認 V-5: rollback 手順が main を dirty にする
- 判断: 対応する
- 根拠: 通常の「戻し方」として書くと、暫定状態の main が放置される運用を招く。
- 対応内容: V-5 を「作業を止めないための緊急退避 (人間が実行する)」と位置付け直し、
  実行後は commit で決着させるまで main が暫定状態であること、その決着までを同じ作業の責務とすることを書く。


## 修正後の詳細設計書 (全文)

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
| 4 | 契約テストの拡張 (7 → 18 ケース) | `tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php` | 高 |
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
#   5) **供給先が symlink なら落とす**。install は symlink を辿ってリンク先へ書き込むため、
#      辿った先が worktree の外でも 0600 の秘密ファイルを置いてしまう。
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

    if [[ -L "${dst}" ]]; then
        echo "error: 供給先がシンボリックリンクです: ${dst}" >&2
        return 1
    fi

    install -m 600 -- "${src}" "${dst}"
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
| 供給先が symlink | 落とす | `install` はリンクを辿って書くので、worktree の外へ秘密を置く経路になる |
| 失敗時 | 非ゼロで返し、呼び出し側の `set -e` で止める | 秘密ファイルの供給失敗を「無いためスキップ」に化けさせない |
| 相対パス引数の `..` / 絶対パスを弾く guard | **持たない (見送り)** | 呼び出しは同一ファイル内の 4 行の定数だけで外部入力を受けない。今必要でないものは作らない (思考原則 2)。値が黙って変わることは S-2 (呼び出し行の完全一致) が防ぐ。外部から値を渡す呼び出しを足すときは、この判断を見直すこと |

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

## 施策 4: 契約テストの拡張 (7 → 18 ケース)

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
use Illuminate\Contracts\Process\ProcessResult;   // Process::run() の戻り値の型 (契約側)

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
| D-10 | 供給先の親ディレクトリが無ければ非ゼロ。**かつ親ディレクトリが作られていない**こと | `install -D` への退行の検出 (worktree 外へ静かにディレクトリを作らない) |
| D-11 | 供給先ディレクトリが書き込み不可なら非ゼロ (root では skip) | 供給失敗を握り潰さない |
| D-12 | `PROVISIONED_PATHS` には**供給したものだけ**が記録される (`optional` 不在時は記録されない) | health check が存在しないパスを検査して偽赤になるのを防ぐ |
| D-13 | 供給先が symlink なら非ゼロで落ち、**リンク先の内容が変わらない** | symlink を辿って worktree の外へ秘密を置かない |

### 静的ケース (`scripts/setup-worktree.sh` の本文を読む)

| # | ケース | 何を守るか |
|---|---|---|
| S-1 | `provision_secret_file` が `if` / `while` / `until` / `&&` / `\|\|` の直後に現れない | 条件位置に置くと `set -e` が効かず失敗が握り潰される |
| S-2 | 秘密ファイル 4 本が、素の呼び出し行の**完全一致**で主経路に存在する (`.env` は `required`、他 3 本は `optional`) | 供給対象が黙って減る・要否が黙って変わるのを検出 |
| S-3 | 関数本体に `install -m 600 -- "${src}" "${dst}"` の行が**行アンカー付き完全一致**で存在する | 広い mode で存在する瞬間を作らない (`cp` → `chmod` の 2 段への退行検出) |
| S-4 | 秘密ファイル 4 本を `cp` で供給する行が 0 件 | 関数経由への統一が崩れていない |
| S-5 | `.env.example` を `.env` として置く代替が復活していない | 壊れた worktree を無言で作らない |

**S-1 の式** (現行 7 ケース版から引き継いだ穴を塞ぐ):

```php
// ★ 先頭に \b を置くと `&& provision_...` / `|| provision_...` を捕まえられない。
//   \b は「単語文字と非単語文字の境界」なので、直前が空白・直後が & のときは境界が成立せず
//   その選択肢へ到達しない (現行の 7 ケース版はここが偽緑だった)。
expect($source)->not->toMatch(
    '/(?:\b(?:if|while|until)\s+(?:!\s*)?|(?:&&|\|\|)\s*(?:!\s*)?)provision_secret_file\b/',
    'provision_secret_file が条件式の位置で呼ばれている (set -e が効かず失敗を握り潰す)',
);
```

**S-3 / S-4 が部分一致で偽緑にならないようにする**: S-3 は `${src}` / `${dst}` を使う
**関数本体だけに現れる形**を行アンカー付きで照合する (コメント行は `#` で始まるので巻き込まない)。
S-4 は `cp` で 4 本を供給する行を名指しの正規表現で 0 件検査する
(例: `/^\s*cp\s+"\$\{REPO_ROOT\}\/(\.env|storage\/oauth-[a-z]+\.key|\.env\.bughunt\.local)"/m`)。

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

- ケース数が 7 → 18 に増える分、テスト実行時間が伸びる。1 ケースあたり `bash` の子プロセス 1 本なので
  実測でも数百 ms 程度の増加に収まる見込み。**測って想定より重ければケースを削るのではなく理由を記録する**。

---

## 施策 5: ドキュメント追従

### 変更箇所と書く事実 (重複しない配分)

| ファイル | 書くこと |
|---|---|
| `AGENTS.md` §worktree 運用ルール | セットアップの説明にある「実行時ファイルのコピー」を「実行時ファイルの供給 (秘密ファイルは 0600 で作成、`.env` は必須で不在なら worktree を作らずに停止)」へ更新。**既存 worktree には遡及しない**ことを 1 行 |
| `docs/worktree-isolation-strategy.md` | 「分離の 4 軸」表の実行時ファイル行と `[2/7]` の説明を実装に合わせる。§ 実行時ファイルの供給契約として「0600 は新規 worktree だけ / 親の権限は直さない / `.env` 不在は作成前に停止 / 契約テストが正本」を 4 行で書く。**post-setup health check は存在確認までで mode は見ない (mode の保証は契約テストが持つ)** ことを health check の表に 1 行。「既知のギャップ」の `.env` に関する記述を実態へ更新 |
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
2. 供給された秘密ファイルの mode が全部 `600`。**任意の 3 本は親に無ければ供給されないのが正常**
   (`note: ... が親に無いため供給をスキップ` が出る) なので、存在するものだけを見る:
   ```bash
   WT=/workspace/.claude/worktrees/tasks/<task-id>-verify
   [[ -e "${WT}/.env" ]] || { echo "NG: .env が無い"; }          # .env は必須なので不在なら失敗
   for f in .env storage/oauth-private.key storage/oauth-public.key .env.bughunt.local; do
       [[ -e "${WT}/${f}" ]] && stat -c '%a %n' "${WT}/${f}"
   done
   ```
   期待値: 出力された行の mode が**すべて 600**。親に無かったファイルは行が出ない (それが正常)。
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

### V-5 壊れたときの緊急退避 (人間が実行する暫定手順)

マージ後に worktree を作れなくなると、**全セッションの実装作業が止まる**。そのときだけ使う暫定手順で、
通常の「戻し方」ではない。

```bash
cd /workspace
git checkout <マージ直前のコミット> -- scripts/setup-worktree.sh
```

- **これは main を未コミットの暫定状態にする**。作業を再開させるための時間稼ぎであって決着ではない。
- 決着 (revert コミットを積む / 直して commit する) までを**同じ作業の責務**とする。
  暫定状態のまま放置すると、次に `git status` を見た人が原因不明の差分を踏む。
- 契約テストは赤くなる。それは「実装が戻っている」ことの正しい表示である。
- 何が壊れたか (どの確認項目が落ちたか) を devnotes へ記録してから再挑戦する。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 変更対象が `scripts/setup-worktree.sh` 1 本とその契約テストに閉じており、他施策と共有するファイルが無い。一方で**このスクリプト自体が全実装作業の入口**なので、他の実装と同じ worktree に混ぜると、失敗したときに何が原因で作業場所が作れなくなったのか切り分けられなくなる。受入確認 (V-2 / V-4) が worktree の作成・破棄を伴うため、単独で走らせて他の作業と干渉させない |
| 競合リスク | `scripts/setup-worktree.sh` を他の TODO が同時に触ると衝突する。マージ順は本 TODO を先にする。また V-2 / V-4 は `.setup.lock` を取るので、他セッションの setup / teardown とは時間的に排他になる (待てば解決する) |


上記を踏まえて再レビューしてください。出力形式は Round 1 と同じ (施策ごとの判定 + 分類つき指摘 + 全体判定)。
