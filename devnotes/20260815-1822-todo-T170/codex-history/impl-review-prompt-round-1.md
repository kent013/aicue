【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
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

# system: 実装レビュアー

あなたは Laravel + Svelte アプリの実装レビュアーである。今回の変更対象は **bash スクリプト
(`scripts/setup-worktree.sh`) と Pest の Architecture 契約テスト、および 3 つのドキュメント**である
(PHP / Svelte のアプリコードには触れていない)。

## レビュー観点

1. **設計との一致性**: 詳細設計書の施策 1〜5 と受入確認の内容どおりに実装されているか。
   設計から意図的に外れた箇所があれば、その理由がコード/コメントから読めるか。
2. **正確性 (bash)**: `set -euo pipefail` 下での挙動、`local` 宣言、引用符、`return` の伝播、
   条件式の中に呼び出しを置いていないか、`${var:+...}` 展開、未定義配列への `+=` など。
   **失敗が握り潰される経路が残っていないか**を最優先で見ること。
3. **契約テストの網羅性と実効性**: 18 ケースが「実装を壊したら赤くなる」ものになっているか。
   偽グリーン (実質何も検査していないケース、正規表現の穴) がないか。
   静的検査の正規表現が意図した形だけを検出しているか (過検出・検出漏れ)。
4. **PHPStan level 10 適合性**: テストヘルパの型宣言、`null` 合体の扱い。
5. **セキュリティ**: 秘密ファイルの mode 確定、symlink 追従、親ディレクトリ作成の抑止、
   一時ディレクトリの扱い、テスト自身が秘密を漏らさないか。
6. **ドキュメントの正確性**: AGENTS.md / docs/worktree-isolation-strategy.md / scripts/README.md の
   記述が実装と一致しているか。**保証範囲を誇張していないか** (「遡及しない」「mode は health check
   では見ない」などの限定が正しく書かれているか)。
7. **DESIGN.md 準拠 / Atomic Design 準拠**: 今回の diff は `resources/js` / `resources/css` を
   1 行も含まないため**該当なし**。該当なしと判断した旨だけ述べ、無理に指摘を作らないこと。

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に**全体判定**を `APPROVED` または `CHANGES_REQUESTED` のいずれか 1 語で明記する

---

# user

## 詳細設計書 (devnotes/20260815-1536-worktree-secret-file-mode/detailed-design.md)

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

施策 4 の D-1〜D-13 / S-1〜S-5 が本関数の契約を固定する。詳細は施策 4。

### リスク

- **`install` が無い環境**では動かない。現行も `.env.bughunt.local` の供給で既に `install` に依存しており、
  開発コンテナ (coreutils あり) が前提なので新しいリスクではない。
- **symlink 検査と `install` の間には時間差がある** (検査してから書くまでに供給先を差し替えられれば
  素通りする)。同一ユーザーのローカル開発環境で守りたいのは「うっかり張られた symlink を辿らないこと」で、
  **並行して供給先を書き換える攻撃者までは防がない**。これ以上の防御は今必要でない (思考原則 2)。
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
| S-3 | **`provision_secret_file()` の本体を切り出した文字列**に `install -m 600 -- "${src}" "${dst}"` の行が完全一致で存在する | 広い mode で存在する瞬間を作らない (`cp` → `chmod` の 2 段への退行検出) |
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

**S-3 は検査対象を関数本体に限定する** (ファイル全体を見ると、別の関数やコメント外の残骸に同じ行が
あるだけで通ってしまう)。本体の切り出しは、関数定義の開き行から**列 0 の閉じ括弧**までを取る:

```php
/** setup-worktree.sh から provision_secret_file() の本体だけを切り出す。 */
function provisionSecretFileBody(string $source): string
{
    // 関数定義は列 0 で始まり、閉じ括弧も列 0 に来る (このファイルの既存の書き方)。
    expect($source)->toMatch('/^provision_secret_file\(\) \{$/m', '関数定義の形が変わっている');
    preg_match('/^provision_secret_file\(\) \{$(.*?)^\}$/ms', $source, $m);

    return $m[1] ?? '';
}

// S-3 — 部分一致では `install ... || true` のような退行が通ってしまうので、
//        行頭・行末をアンカーした完全一致で照合する。
expect(provisionSecretFileBody($source))->toMatch(
    '/^ {4}install -m 600 -- "\$\{src\}" "\$\{dst\}"$/m',
    'provision_secret_file が install -m 600 を素の文として実行していない (失敗が握り潰される形になっている)',
);
```

切り出しに失敗したら空文字が返り S-3 は落ちる (fail-closed)。関数の書き方を変えたら
このテストが落ちるが、それは「契約の置き場所が変わった」ことの正しい表示である。

**S-4** は `cp` で 4 本を供給する行を名指しの正規表現で 0 件検査する
(例: `/^\s*cp\s+.*"\$\{REPO_ROOT\}\/(\.env|storage\/oauth-[a-z]+\.key|\.env\.bughunt\.local)"/m`)。

> **保証範囲を誇張しない**: S-1〜S-5 は**既知の回帰形の検出**であって、bash のあらゆる条件コンテキストや
> あらゆる書き方を排除する証明ではない (複数行にまたがる条件式や、変数経由の間接呼び出しには沈黙する)。
> **S-4 も同様**で、`cp` の直接の退行形だけを見る — 変数に入れたコマンド名、`install` 以外の別コマンド、
> 別表記での供給には沈黙する。素の呼び出し行の完全一致 (S-2) と、実挙動を見る D-1〜D-13 を
> 組み合わせて実用上の検出力を確保している、という現行コメントの立て付けをそのまま引き継ぐ。

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

1. `[0/7]` から `[7/7]` まで到達し、`health check: OK` と `✅ worktree 作成完了` が出る
   (setup 自体の終了コードが 0 であることで機械的に確認できる)。
2. 供給結果を**目視ではなく終了コードで**確かめる。任意の 3 本は親に無ければ供給されないのが正常
   (`note: ... が親に無いため供給をスキップ` が出る) なので、親の有無で期待値を切り替える。
   下のブロックをそのまま実行し、**最後に `rc=0` が出ること**を確認する:

   ```bash
   TASK_ID=<task-id>-verify bash -euo pipefail <<'SH'
   WT="/workspace/.claude/worktrees/tasks/${TASK_ID}"
   PARENT=/workspace

   # (a) .env は必須。無ければ失敗。
   if [[ ! -e "${WT}/.env" ]]; then
       echo "NG: .env が供給されていない" >&2
       exit 1
   fi

   # (b) 秘密ファイル 4 本: 親にあれば worktree にあって mode 600 / 親に無ければ worktree にも無い。
   for f in .env storage/oauth-private.key storage/oauth-public.key .env.bughunt.local; do
       if [[ -f "${PARENT}/${f}" ]]; then
           mode=$(stat -c '%a' "${WT}/${f}")   # 供給されていなければ stat が失敗して止まる
           if [[ "${mode}" != 600 ]]; then
               echo "NG: mode=${mode} path=${WT}/${f}" >&2
               exit 1
           fi
           echo "OK: 600 ${f}"
       elif [[ -e "${WT}/${f}" ]]; then
           echo "NG: 親に無い ${f} が worktree に作られている" >&2
           exit 1
       else
           echo "OK: ${f} は親に無いので供給なし (正常)"
       fi
   done

   # (c) public/build (対象外にした側が壊れていないこと)
   if [[ -d "${PARENT}/public/build" ]]; then
       [[ -d "${WT}/public/build" ]] || { echo "NG: public/build が供給されていない" >&2; exit 1; }
       echo "OK: public/build 供給あり"
   else
       [[ ! -e "${WT}/public/build" ]] || { echo "NG: 親に無い public/build が作られている" >&2; exit 1; }
       echo "OK: public/build は親に無いので供給なし (正常)"
   fi

   # (d) 0600 にしたことで読めなくなっていないことの実地確認
   (cd "${WT}" && php artisan --version >/dev/null) || { echo "NG: artisan が動かない" >&2; exit 1; }
   (cd "${WT}" && vendor/bin/pest --version >/dev/null) || { echo "NG: pest が動かない" >&2; exit 1; }
   echo "OK: artisan / pest が worktree 内で動く"
   SH
   echo "rc=$?"
   ```
3. `[timing]` の各値が従来と同程度 (供給段で目に見える遅延が出ていない)。これは目視でよい
   (性能の合否条件は設けない。桁が変わっていたら原因を調べる、という運用)。

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
2. main へマージした**直後**に、main 側のスクリプトでもう一度使い捨て worktree を作って完走を確認する。
   **mode の確認は表示ではなく終了コードに反映させる** (644 のままでも `stat` は成功してしまい、
   マージでの取り込み漏れを偽緑にするため):
   ```bash
   cd /workspace && scripts/setup-worktree.sh merge-verify && \
     test "$(stat -c '%a' /workspace/.claude/worktrees/tasks/merge-verify/.env)" = 600 && \
     scripts/teardown-worktree.sh merge-verify && git branch -D todo/merge-verify
   ```
   V-2 は「実装 worktree にあるファイル」を実行した確認なので、**マージ結果そのものの確認にはならない**。
   マージ後は全セッションがこのスクリプトを使うため、ここを省略しない。
   途中で失敗すると `merge-verify` の worktree が残る。**原因調査のために残るのは正しい**が、
   調査が終わったら `scripts/teardown-worktree.sh merge-verify` と
   `git branch -D todo/merge-verify` で必ず片付ける (放置すると孤児テスト DB の元になる)。

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


## 実装差分 (git diff HEAD -- scripts/ tests/ AGENTS.md docs/)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index e405938..91923dd 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -274,10 +274,15 @@ ## worktree 運用ルール
 - **セットアップ**: `scripts/setup-worktree.sh <task-id>` が
   `.claude/worktrees/tasks/<task-id>` に worktree を作成し `todo/<task-id>` ブランチを切る
   (main 起点・ブランチ名固定、custom branch 非対応)。実行時ファイル
-  (`.env` / `storage/oauth-*.key` / `public/build`)のコピー、worktree 内
+  (`.env` / `storage/oauth-*.key` / `.env.bughunt.local` / `public/build`)の供給、worktree 内
   `composer install --no-scripts`、`pnpm install --frozen-lockfile`、
   post-setup health check、pgsql テスト DB の ensure まで自動で行う。
   失敗時は EXIT trap が作成途中の worktree とブランチを自動削除する
+  - **秘密ファイル 4 本 (`.env` / `storage/oauth-*.key` / `.env.bughunt.local`) は
+    作成時点で mode 0600 に確定**して供給する(供給元の mode に追随させない)。
+    `.env` は必須で、親のチェックアウトに無ければ**worktree を作らずに停止**する
+    (見本ファイルでの代替はしない)。**既存の worktree には遡及しない**(新規作成分だけ 0600)。
+    契約の正本は `tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php`
 - **依存は worktree-local**: `vendor/` は worktree 内 `composer install` の独立ディレクトリ。
   `node_modules` は `pnpm-workspace.yaml#enableGlobalVirtualStore` で実体を共有 store
   (`<store-path>/links/`)に置き、worktree 内 `pnpm install`/`pnpm add` の影響を
diff --git a/docs/worktree-isolation-strategy.md b/docs/worktree-isolation-strategy.md
index 3df021e..2ae07e4 100644
--- a/docs/worktree-isolation-strategy.md
+++ b/docs/worktree-isolation-strategy.md
@@ -20,7 +20,7 @@ ## 分離の 4 軸
 | **vendor (composer)** | worktree-local に独立 install | `setup-worktree.sh [4/7]` の `composer install --no-progress --no-interaction --no-scripts` (最大 3 回リトライ) |
 | **node_modules (pnpm)** | worktree-local install + GVS で実体共有 | `setup-worktree.sh [5/7]` の `pnpm install --frozen-lockfile --config.*` 強制 (同 3 回リトライ)。詳細は [`docs/pnpm-global-virtual-store-runbook.md`](pnpm-global-virtual-store-runbook.md) |
 | **テスト DB (pgsql)** | worktree ごとに別 DB (`<slug>_test_<worktree-hash>`) | `tests/Support/Ci/TestDatabaseEnv::workrootHash()` = worktree root realpath の sha1 先頭 8 桁。`scripts/ci/ensure-test-db.php` が冪等 CREATE |
-| **実行時ファイル** | 親から実コピー (共有しない) | `setup-worktree.sh [2/7]` が `.env` (無ければ `.env.example`) / `storage/oauth-*.key` / `public/build` をコピー |
+| **実行時ファイル** | 親から実供給 (共有しない) | `setup-worktree.sh [2/7]` が `.env` (必須) / `storage/oauth-*.key` / `.env.bughunt.local` を **0600 で作成**して供給し、`public/build` をコピー |
 
 ### なぜ vendor を hardlink 共有しないのか
 
@@ -60,7 +60,7 @@ ## setup の 7 step (`scripts/setup-worktree.sh <task-id>`)
 [0/7] 事前条件チェック + lock 排他 (flock、無ければ mkdir lock)
 [1/7] git worktree add .claude/worktrees/tasks/<task-id> -b todo/<task-id> main
       (+ mise trust。mise 環境で新規 worktree が untrusted だと pnpm が起動できない)
-[2/7] 実行時ファイルの provision (.env 必須 / storage/oauth-*.key・public/build は存在すれば)
+[2/7] 実行時ファイルの供給 (.env 必須 / storage/oauth-*.key・.env.bughunt.local・public/build は親にあれば)
 [3/7] git submodule update --init --recursive (.gitmodules がある場合のみ)
 [4/7] vendor:       composer install --no-scripts (最大 3 回リトライ)
 [5/7] node_modules: pnpm install --frozen-lockfile --config.* 強制 (最大 3 回リトライ)
@@ -74,11 +74,24 @@ ## setup の 7 step (`scripts/setup-worktree.sh <task-id>`)
   中途半端な worktree が残らない。
 - 工程ごとに `[timing] step=... elapsed=...s` を stderr に出す (遅い工程の切り分け用)。
 
+### 実行時ファイルの供給契約 ( `[2/7]` )
+
+秘密ファイル 4 本 (`.env` / `storage/oauth-private.key` / `storage/oauth-public.key` /
+`.env.bughunt.local`) は `provision_secret_file` 1 本を通して供給する。
+
+- **0600 は新規 worktree だけ**に効く。既に作ってある worktree の秘密ファイルには遡及しない。
+- **親の権限は直さない**。単純な cp が供給元の mode を引き継ぐ (= 親が 0644 なら worktree が
+  増えるたびに world-readable な複製が増える) のを断つのが目的で、親を締めるのは別の話である。
+- **`.env` 不在は worktree を作る前に停止する**。見本ファイル (`.env.example`) で代替すると
+  `APP_KEY` も DB 接続も無い「動くように見えて壊れている worktree」が無言で出来上がるため。
+- **契約の正本は `tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php`**
+  (source 専用入口から供給関数だけを実走させる 18 ケース)。
+
 ### post-setup health check ( `[6/7]` )
 
 | # | 検査 | 何を守るか |
 |---|---|---|
-| 1 | `.env` と provision したパスの存在 | コピー漏れで後段が謎エラーになるのを防ぐ |
+| 1 | provision したパスの存在 (`.env` は必須供給なので必ず含まれる)。**mode は見ない** (0600 の保証は上記の契約テストが持つ) | 供給漏れで後段が謎エラーになるのを防ぐ |
 | 2 | `vendor/autoload.php` 経由で `App\Models\User` が解決できる | composer install の完整性 |
 | 3 | `node_modules` が実ディレクトリ (symlink でない) + `.modules.yaml` あり | pnpm install の完了 |
 | 4 | `readlink -f node_modules/svelte` が `<store-path>/links/` 配下 | **GVS の実効** (無効化されると型 identity 衝突が再発する) |
@@ -212,8 +225,9 @@ ## 既知のギャップ
   `composer test` では落ちない (参照実装の aigenba は `WorktreeRuleInvariantTest` で
   regex 固定している)。導入するなら「ブランチ名固定」「teardown がブランチを触らない」
   「install 系 2 層規則」あたりが pin 対象になる。
-- `.env` は親から**実コピー**するため、親の `.env` を後から変えても worktree には反映されない
-  (worktree ごとに直す)。
+- `.env` は親から**実供給**するため、親の `.env` を後から変えても worktree には反映されない
+  (worktree ごとに直す)。供給時に mode を 0600 に確定するのは**新規 worktree だけ**で、
+  既存 worktree の秘密ファイルと親側の権限はそのまま残る。
 
 ## 参考
 
diff --git a/scripts/README.md b/scripts/README.md
index e2a1d8a..807db19 100644
--- a/scripts/README.md
+++ b/scripts/README.md
@@ -18,7 +18,7 @@ ## スクリプト一覧
 | `phpstan.sh` | PHPStan の DX ラッパー。virtiofs 上の phar 並列 open レースを避けるため phar を実 fs に複製してから実行 | `composer phpstan` から自動呼び出し |
 | `ci/ensure-test-db.php` | pgsql テストの base DB を不在時のみ冪等 CREATE (dev-DB 保護の二重防御付き)。併せて出自 (worktree の realpath) を `COMMENT ON DATABASE` で冪等に記録する (孤児 sweep の分類材料。付与失敗は best-effort で無視) | `run-test.sh` / CI から自動呼び出し |
 | `ci/drop-test-db.php` | worktree の base テスト DB と paratest worker DB を回収 (dev-DB は無条件 skip)。`--orphans` で「生存 worktree に紐づかない孤児 DB」の検出も行う (既定 dry-run。`--apply` は `--confirm=<token>` 必須で **LLM は実行しない** = ユーザー実行またはユーザーの明示承認のみ) | worktree teardown / CI cleanup / 孤児回収 (手動) |
-| `setup-worktree.sh` | TODO 用 worktree (`.claude/worktrees/tasks/<task-id>` + `todo/<task-id>` ブランチ) を作成し、実行時ファイルのコピー・worktree-local な `composer install --no-scripts` / `pnpm install` (GVS 共有)・health check・テスト DB ensure まで機械的に実行 (AGENTS.md §worktree 運用ルール) | 実装開始時 (`app-implement` W-3 等) |
+| `setup-worktree.sh` | TODO 用 worktree (`.claude/worktrees/tasks/<task-id>` + `todo/<task-id>` ブランチ) を作成し、実行時ファイルの供給 (秘密ファイルは 0600 で作成。`.env` は必須)・worktree-local な `composer install --no-scripts` / `pnpm install` (GVS 共有)・health check・テスト DB ensure まで機械的に実行 (AGENTS.md §worktree 運用ルール) | 実装開始時 (`app-implement` W-3 等) |
 | `teardown-worktree.sh` | worktree の dirty チェック → テスト DB の best-effort 回収 → `git worktree remove --force`。ブランチの削除/マージは呼び出し側の責務 | 実装完了後 (`app-implement` C-4 等) |
 | `ci/pgsql_test_conn.php` | ensure / drop が共有する pgsql 接続 resolver | (上記 2 スクリプトの内部 include) |
 | `audit-gate.sh` | supply-chain 依存脆弱性 gate の実行ラッパ。composer / pnpm(pyproject.toml があれば pip-audit も)の audit JSON を取得して `audit-gate.ts` に渡す。**取得は fail-closed**: 空出力・前処理 (`uv export`) の失敗はそこで停止し、advisory 0 件として判定へ流さない | `pnpm run audit:gate` から自動呼び出し / CI (`supply-chain-audit` job) / 直接実行 |
diff --git a/scripts/setup-worktree.sh b/scripts/setup-worktree.sh
index 6a746fa..652124b 100755
--- a/scripts/setup-worktree.sh
+++ b/scripts/setup-worktree.sh
@@ -11,7 +11,8 @@
 # 責務:
 #   0) 入力バリデーション + lock 排他
 #   1) git worktree add (.claude/worktrees/tasks/<task-id>, todo/<task-id>, main 起点)
-#   2) 実行時ファイルのプロビジョニング (.env 必須 / storage/oauth-*.key・public/build は存在すればコピー)
+#   2) 実行時ファイルの供給 (秘密ファイル 4 本は 0600 で作成。.env は必須 /
+#      storage/oauth-*.key・.env.bughunt.local・public/build は親にあれば供給)
 #   3) git submodule update --init --recursive (.gitmodules がある場合のみ)
 #   4) vendor: worktree 内 composer install (worktree-local。独立 vendor)
 #   5) node_modules: worktree 内 pnpm install (global virtual store 共有。LLM 自律運用で局所安全)
@@ -30,20 +31,63 @@
 
 set -euo pipefail
 
-# --- bug-hunt 専用 env の provisioning (契約テストから source して単体で叩けるよう関数化) ---
-# .env.bughunt.local は .gitignore 対象で worktree には決して現れない = コピーが唯一の供給路。
-# bug-hunt は worktree 走行が既定 (AGENTS.md) なので、無いと provision が必ず止まる。
+# --- worktree へ供給する実行時ファイルの provisioning (契約テストから source して単体で叩ける) ---
 #
-# ★ mode は親に追随させず 0600 に固定する。親が 0644 だと `cp -p` は
-#   **world-readable な秘密ファイルを新たに作る**ため契約として弱い。
-#   `install -m 600` は作成時点で mode を確定するので、`cp` → `chmod` の 2 段にある
-#   「一瞬だけ広く読める窓」も無い。
-# ★ 今回 0600 を固定する対象は **.env.bughunt.local だけ**である。
-#   既存の .env / storage/oauth-*.key の権限契約は変更しない (別施策)。
-provision_bughunt_env_file() {
-    local repo_root=$1 worktree_dir=$2
-    [[ -f "${repo_root}/.env.bughunt.local" ]] || return 0   # 非利用リポジトリでは no-op
-    install -m 600 "${repo_root}/.env.bughunt.local" "${worktree_dir}/.env.bughunt.local"
+# 契約:
+#   1) 供給先の mode は**作成時点で 0600 に確定**する。供給元の mode に追随させない。
+#      単純な cp は新規作成時に供給元の mode を引き継ぐため、親の .env が 0644 だと
+#      worktree を作るたびに world-readable な秘密ファイルが 1 つ増える (実測)。
+#      さらに cp は**供給先が既に存在するとその mode を変えない**ので、一度広く置かれたら締まらない。
+#      `install -m 600` は作成時点で mode を確定するので、cp → chmod の 2 段にある
+#      「一瞬だけ広く読める窓」も作らない。
+#   2) 供給元が無いとき: required なら止める / optional なら何もしない (空ファイルを作らない)。
+#   3) **供給先の親ディレクトリは作らない**。作ると、供給先のパスを間違えたときに worktree の外へ
+#      静かにディレクトリを作る経路ができる。worktree には storage/ が追跡下で必ず存在する。
+#   4) 要否指定は required / optional だけを受理し、それ以外は落とす (fail-closed)。
+#   5) **供給先が symlink なら落とす**。install は symlink を辿ってリンク先へ書き込むため、
+#      辿った先が worktree の外でも 0600 の秘密ファイルを置いてしまう。
+#
+# なぜ公開鍵 (storage/oauth-public.key) まで 0600 なのか:
+#   worktree へ供給する実行時ファイルは配布物ではなく、**作業者本人の PHP プロセスだけが読む**。
+#   1 本だけ例外にすると「どれを狭く置くか」の判断がこのスクリプトに 2 種類生まれ、
+#   次に供給行を足す人が毎回判断させられる。狭く置いて壊れる利用者は現構成に存在しない。
+#   (別の OS ユーザーのプロセスが worktree の鍵を読む構成は本スクリプトの対象外)
+#
+# なぜ public/build は対象外なのか:
+#   秘密ではないフロントのビルド成果物 (ディレクトリ) だから。ここで扱うのは単一ファイルだけである。
+#
+# 使い方: provision_secret_file <required|optional> <repo_root> <worktree_dir> <relative_path> [hint]
+provision_secret_file() {
+    local requirement=$1 repo_root=$2 worktree_dir=$3 relative=$4 hint=${5:-}
+    local src="${repo_root}/${relative}" dst="${worktree_dir}/${relative}"
+
+    case "${requirement}" in
+        required)
+            if [[ ! -f "${src}" ]]; then
+                echo "error: 必須の供給元がありません: ${src}" >&2
+                [[ -n "${hint}" ]] && echo "       ${hint}" >&2
+                return 1
+            fi
+            ;;
+        optional)
+            if [[ ! -f "${src}" ]]; then
+                echo "    note: ${relative} が親に無いため供給をスキップ${hint:+ (${hint})}" >&2
+                return 0
+            fi
+            ;;
+        *)
+            echo "error: provision_secret_file: 要否指定は required か optional のみ (受け取った値: '${requirement}')" >&2
+            return 2
+            ;;
+    esac
+
+    if [[ -L "${dst}" ]]; then
+        echo "error: 供給先がシンボリックリンクです: ${dst}" >&2
+        return 1
+    fi
+
+    install -m 600 -- "${src}" "${dst}"
+    PROVISIONED_PATHS+=("${relative}")   # health check が存在を再検証する
 }
 
 # ★ source 専用モード: 関数定義だけ取り込んで抜ける (契約テスト用)。
@@ -104,8 +148,9 @@ PROVISIONED_PATHS=()
 # --- post-setup health check ---
 post_setup_health_check() {
     local wt="$1" rc=0 f store_path store_links dep_real
-    # 1. 必須ファイル + provision したファイルの存在
-    for f in .env "${PROVISIONED_PATHS[@]+"${PROVISIONED_PATHS[@]}"}"; do
+    # 1. provision したパスの存在 (.env は required 供給なのでここに必ず含まれる。
+    #    含まれない = [2/7] を通っていないということなので、そもそもここへ到達しない)
+    for f in "${PROVISIONED_PATHS[@]+"${PROVISIONED_PATHS[@]}"}"; do
         if [[ ! -e "${wt}/${f}" ]]; then
             echo "  health-check FAIL: 必須パスが存在しない: ${f}" >&2
             rc=1
@@ -188,6 +233,9 @@ cleanup_on_exit() {
 }
 trap cleanup_on_exit EXIT
 
+# .env が無いときの復旧案内 (事前確認と供給関数の hint で同じ文言を使う)
+ENV_SETUP_HINT="親のチェックアウトで 'cp .env.example .env' → 'php artisan key:generate' を実行してから再実行してください"
+
 # === [0/7] 事前条件チェック + lock ===
 echo ">>> [0/7] 事前条件チェック"
 acquire_lock
@@ -195,6 +243,15 @@ if [[ -e "${WORKTREE_DIR}" ]]; then
     echo "error: ${WORKTREE_DIR} は既に存在します。teardown 先に: scripts/teardown-worktree.sh ${TASK_ID}" >&2
     exit 1
 fi
+# 必須の供給元が無いなら worktree を作る前に止める (作りかけの片付けを発生させない)。
+# ★ これは早く止めるための事前確認であって判定の正本ではない。
+#   決着は [2/7] の provision_secret_file (required) にある。
+if [[ ! -f "${REPO_ROOT}/.env" ]]; then
+    echo "error: 親のチェックアウトに .env がありません: ${REPO_ROOT}/.env" >&2
+    echo "       ${ENV_SETUP_HINT}" >&2
+    echo "       (worktree はまだ作っていないので後片付けは要りません)" >&2
+    exit 1
+fi
 TIMING_LAST=$(date +%s)
 emit_timing "0-precheck"
 
@@ -215,32 +272,21 @@ if command -v mise >/dev/null 2>&1; then
 fi
 
 # === [2/7] 実行時ファイルのプロビジョニング ===
-# .env は必須 (workspace の .env、無ければ committed の .env.example をコピー)。
-# storage/oauth-*.key / public/build は runtime artifact (.gitignore 対象) で、workspace に
-# あればコピー / 無ければ note して続行 (テンプレート初期状態では未生成のことがある。
-# 必要になった時点で worktree 内 `php artisan passport:keys` / `pnpm build` で生成できる)。
-echo ">>> [2/7] .env / .env.bughunt.local / storage/oauth-*.key / public/build を親からコピー"
-if [[ -f "${REPO_ROOT}/.env" ]]; then
-    cp "${REPO_ROOT}/.env" "${WORKTREE_DIR}/.env"
-else
-    cp "${REPO_ROOT}/.env.example" "${WORKTREE_DIR}/.env"   # .env 不在時は committed の .env.example をコピー
-fi
-for f in storage/oauth-private.key storage/oauth-public.key; do
-    if [[ -f "${REPO_ROOT}/${f}" ]]; then
-        cp "${REPO_ROOT}/${f}" "${WORKTREE_DIR}/${f}"
-        PROVISIONED_PATHS+=("${f}")
-    else
-        echo "    note: ${f} が親に無いためコピーをスキップ (必要なら worktree 内で 'php artisan passport:keys')" >&2
-    fi
-done
-# ★ 関数呼び出しを if の条件に置かない。条件内では set -e が効かず、
-#   install の失敗が「無いためスキップ」に化けて秘密ファイルのコピー失敗を隠す。
-if [[ -f "${REPO_ROOT}/.env.bughunt.local" ]]; then
-    provision_bughunt_env_file "${REPO_ROOT}" "${WORKTREE_DIR}"
-    PROVISIONED_PATHS+=(".env.bughunt.local")
-else
-    echo "    note: .env.bughunt.local が親に無いためコピーをスキップ (bug-hunt 未使用なら不要)" >&2
-fi
+# 秘密ファイル 4 本は provision_secret_file 経由で供給する (作成時点で mode を 0600 に確定)。
+# .env は必須で、無ければ止める (見本ファイルで代替すると、動くように見えて壊れている
+# worktree が無言で出来上がる)。storage/oauth-*.key / .env.bughunt.local は runtime artifact
+# (.gitignore 対象) で、親にあれば供給 / 無ければ note して続行 (必要になった時点で worktree 内
+# `php artisan passport:keys` で生成できる)。
+# public/build は秘密でないビルド成果物のディレクトリなので対象外 (cp -r のまま)。
+#
+# ★ 関数呼び出しを if / while / && / || の条件位置に置かない。
+#   条件の中では set -e が効かず、install の失敗が「無いためスキップ」に化けて
+#   秘密ファイルの供給失敗を隠す。要否の判定は関数の中にある。
+echo ">>> [2/7] .env / storage/oauth-*.key / .env.bughunt.local / public/build を親から供給"
+provision_secret_file required "${REPO_ROOT}" "${WORKTREE_DIR}" ".env" "${ENV_SETUP_HINT}"
+provision_secret_file optional "${REPO_ROOT}" "${WORKTREE_DIR}" "storage/oauth-private.key" "必要なら worktree 内で 'php artisan passport:keys'"
+provision_secret_file optional "${REPO_ROOT}" "${WORKTREE_DIR}" "storage/oauth-public.key" "必要なら worktree 内で 'php artisan passport:keys'"
+provision_secret_file optional "${REPO_ROOT}" "${WORKTREE_DIR}" ".env.bughunt.local" "bug-hunt 未使用なら不要"
 if [[ -d "${REPO_ROOT}/public/build" ]]; then
     cp -r "${REPO_ROOT}/public/build" "${WORKTREE_DIR}/public/build"
     PROVISIONED_PATHS+=("public/build")
diff --git a/tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php b/tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php
index d3c9cf7..f964e19 100644
--- a/tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php
+++ b/tests/Architecture/SetupWorktreeRuntimeFilesContractTest.php
@@ -2,126 +2,281 @@
 
 declare(strict_types=1);
 
+use Illuminate\Contracts\Process\ProcessResult;
 use Illuminate\Support\Facades\File;
 use Illuminate\Support\Facades\Process;
 
 /*
- * setup-worktree.sh の実行時ファイル provisioning 契約。
+ * setup-worktree.sh の実行時ファイル供給契約。
  *
- * bug-hunt は worktree 走行が既定 (AGENTS.md) だが、.env.bughunt.local は .gitignore 対象で
- * worktree には決して現れない。親からのコピーが唯一の供給路であり、無いと provision が必ず止まる
- * (bug-hunt run 20260809-152048 で実際に踏み、手動 cp で回避した)。
+ * worktree へ供給する秘密ファイル 4 本 (.env / storage/oauth-private.key /
+ * storage/oauth-public.key / .env.bughunt.local) は provision_secret_file 1 本を通り、
+ * **作成時点で mode を 0600 に確定**する。
  *
- * 秘密ファイルの複製なので **mode は 0600 に固定**する。親が 0644 のとき `cp -p` は
- * world-readable な秘密ファイルを新たに作るため契約として弱く、`cp` → `chmod` の 2 段にも
- * 「一瞬だけ広く読める窓」がある。`install -m 600` は作成時点で mode を確定する。
+ * 単純な cp は新規作成時に供給元の mode を引き継ぐため、親の .env が 0644 だと worktree を
+ * 作るたびに world-readable な秘密ファイルが 1 つ増える。さらに cp は供給先が既に存在すると
+ * その mode を変えないので、一度広く置かれたら締まらない。`install -m 600` は作成時点で
+ * mode を確定するので、cp → chmod の 2 段にある「一瞬だけ広く読める窓」も作らない。
  *
  * setup-worktree.sh は top-level 実行型 (main() を持たない) なので、素朴に source すると
  * composer install / pnpm install / DB 作成まで走る。SETUP_WORKTREE_SOURCE_ONLY で
  * 関数定義だけ取り込んで抜ける guard を使う。
+ *
+ * ★ 保証範囲を誇張しない: 静的ケース (S-1〜S-5) は**既知の回帰形の検出**であって、
+ *   bash のあらゆる条件コンテキスト・あらゆる書き方を排除する証明ではない
+ *   (複数行にまたがる条件式や、変数経由の間接呼び出しには沈黙する)。
+ *   素の呼び出し行の完全一致 (S-2) と、実挙動を見る動的ケース (D-1〜D-13) を
+ *   組み合わせて実用上の検出力を確保している。
  */
 
 /**
- * setup-worktree.sh を source して provision_bughunt_env_file だけを叩く。
+ * setup-worktree.sh を source して provision_secret_file だけを叩く。
  * 引数は位置引数で渡す (文字列連結による shell injection を避ける)。
+ * stdout には、その呼び出しの後に PROVISIONED_PATHS に入っている相対パスが 1 行ずつ出る。
  */
-function runProvisionBughuntEnvFile(string $parent, string $worktree): int
-{
-    $result = Process::timeout(60)
+function runProvisionSecretFile(
+    string $requirement,
+    string $parent,
+    string $worktree,
+    string $relative,
+    string $hint = '',
+): ProcessResult {
+    return Process::timeout(60)
         ->env(['SETUP_WORKTREE_SOURCE_ONLY' => '1'])
         ->run([
             'bash', '-c',
-            'source "$1"; provision_bughunt_env_file "$2" "$3"',
+            'source "$1"; provision_secret_file "$2" "$3" "$4" "$5" "$6"; '
+                .'printf "%s\n" "${PROVISIONED_PATHS[@]+"${PROVISIONED_PATHS[@]}"}"',
             '_',
             base_path('scripts/setup-worktree.sh'),
-            $parent,
-            $worktree,
+            $requirement, $parent, $worktree, $relative, $hint,
         ]);
+}
 
-    return $result->exitCode() ?? 1;
+/**
+ * stdout に出た PROVISIONED_PATHS の中身を配列で返す (空行は落とす)。
+ *
+ * @return list<string>
+ */
+function provisionedPathsOf(ProcessResult $result): array
+{
+    return array_values(array_filter(
+        array_map(trim(...), explode("\n", $result->output())),
+        static fn (string $line): bool => $line !== '',
+    ));
 }
 
-/** @return array{0: string, 1: string} [親, worktree] の一時ディレクトリ */
+/**
+ * 親 / worktree の一時ディレクトリを作る。
+ *
+ * worktree 側には storage/ も作る (供給関数は親ディレクトリを作らない契約なので、
+ * 実 worktree と同じ前提を fixture 側で用意する)。
+ *
+ * @return array{0: string, 1: string} [親, worktree]
+ */
 function makeWorktreeFixture(): array
 {
     $base = sys_get_temp_dir().'/setup-worktree-contract-'.bin2hex(random_bytes(6));
-    File::makeDirectory($base.'/parent', 0700, true);
-    File::makeDirectory($base.'/worktree', 0700, true);
+    File::makeDirectory($base.'/parent/storage', 0700, true);
+    File::makeDirectory($base.'/worktree/storage', 0700, true);
 
     return [$base.'/parent', $base.'/worktree'];
 }
 
-test('親に .env.bughunt.local があれば worktree へコピーされる', function (): void {
+/** setup-worktree.sh の本文。 */
+function setupWorktreeSource(): string
+{
+    return File::get(base_path('scripts/setup-worktree.sh'));
+}
+
+/** setup-worktree.sh から provision_secret_file() の本体だけを切り出す。 */
+function provisionSecretFileBody(string $source): string
+{
+    // 関数定義は列 0 で始まり、閉じ括弧も列 0 に来る (このファイルの既存の書き方)。
+    expect($source)->toMatch('/^provision_secret_file\(\) \{$/m', '関数定義の形が変わっている');
+    preg_match('/^provision_secret_file\(\) \{$(.*?)^\}$/ms', $source, $m);
+
+    // 切り出しに失敗したら空文字が返り、呼び出し側の検査が落ちる (fail-closed)。
+    return $m[1] ?? '';
+}
+
+// --- 動的ケース (source 専用入口から実走) ---
+
+test('D-1: 供給元があれば内容が供給先へ入る (.env)', function (): void {
     [$parent, $worktree] = makeWorktreeFixture();
 
     try {
-        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");
+        File::put($parent.'/.env', "APP_ENV=local\n");
 
-        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
-        expect(File::exists($worktree.'/.env.bughunt.local'))->toBeTrue();
-        expect(File::get($worktree.'/.env.bughunt.local'))->toBe("APP_ENV=bughunt.local\n");
+        $result = runProvisionSecretFile('required', $parent, $worktree, '.env');
+
+        expect($result->exitCode() ?? 1)->toBe(0);
+        expect(File::get($worktree.'/.env'))->toBe("APP_ENV=local\n");
     } finally {
         File::deleteDirectory(dirname($parent));
     }
 });
 
-test('親に .env.bughunt.local が無ければ何もしない (bug-hunt 非利用リポジトリで no-op)', function (): void {
+test('D-2: 親が 0644 でも供給先は 0600 (.env)', function (): void {
     [$parent, $worktree] = makeWorktreeFixture();
 
     try {
-        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
-        expect(File::exists($worktree.'/.env.bughunt.local'))->toBeFalse();
+        File::put($parent.'/.env', "APP_ENV=local\n");
+        chmod($parent.'/.env', 0644);
+
+        expect(runProvisionSecretFile('required', $parent, $worktree, '.env')->exitCode() ?? 1)->toBe(0);
+        expect(decoct(fileperms($worktree.'/.env') & 0777))
+            ->toBe('600', '供給先が world-readable になっている (cp / cp+chmod への退行)');
     } finally {
         File::deleteDirectory(dirname($parent));
     }
 });
 
-test('親が 0644 でもコピー先は 0600 になる', function (): void {
+test('D-3: 親が 0644 でも供給先は 0600 (storage/oauth-private.key)', function (): void {
     [$parent, $worktree] = makeWorktreeFixture();
 
     try {
-        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");
-        chmod($parent.'/.env.bughunt.local', 0644);
+        File::put($parent.'/storage/oauth-private.key', "PRIVATE\n");
+        chmod($parent.'/storage/oauth-private.key', 0644);
+
+        $result = runProvisionSecretFile('optional', $parent, $worktree, 'storage/oauth-private.key');
+
+        expect($result->exitCode() ?? 1)->toBe(0);
+        expect(decoct(fileperms($worktree.'/storage/oauth-private.key') & 0777))
+            ->toBe('600', 'Passport 署名鍵が world-readable で置かれている');
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
 
-        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
+test('D-4: 親が 0644 でも供給先は 0600 (storage/oauth-public.key)', function (): void {
+    // 公開鍵も同じ契約に入れるのは**意図的な選択**である。worktree へ供給する実行時ファイルは
+    // 配布物ではなく作業者本人の PHP プロセスだけが読むので、1 本だけ例外にすると
+    // 「どれを狭く置くか」の判断がスクリプトに 2 種類生まれる。
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        File::put($parent.'/storage/oauth-public.key', "PUBLIC\n");
+        chmod($parent.'/storage/oauth-public.key', 0644);
+
+        $result = runProvisionSecretFile('optional', $parent, $worktree, 'storage/oauth-public.key');
 
-        $mode = fileperms($worktree.'/.env.bughunt.local') & 0777;
-        expect(decoct($mode))->toBe('600', 'コピー先が world-readable になっている (cp -p / cp+chmod への退行)');
+        expect($result->exitCode() ?? 1)->toBe(0);
+        expect(decoct(fileperms($worktree.'/storage/oauth-public.key') & 0777))->toBe('600');
     } finally {
         File::deleteDirectory(dirname($parent));
     }
 });
 
-test('コピー先が既に存在しても上書き後に 0600 になる', function (): void {
+test('D-5: 親が 0644 でも供給先は 0600 (.env.bughunt.local)', function (): void {
     [$parent, $worktree] = makeWorktreeFixture();
 
     try {
-        File::put($parent.'/.env.bughunt.local', "APP_ENV=new\n");
+        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");
         chmod($parent.'/.env.bughunt.local', 0644);
-        File::put($worktree.'/.env.bughunt.local', "APP_ENV=old\n");
-        chmod($worktree.'/.env.bughunt.local', 0666);
 
-        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
+        $result = runProvisionSecretFile('optional', $parent, $worktree, '.env.bughunt.local');
 
-        expect(File::get($worktree.'/.env.bughunt.local'))->toBe("APP_ENV=new\n");
+        expect($result->exitCode() ?? 1)->toBe(0);
+        expect(File::get($worktree.'/.env.bughunt.local'))->toBe("APP_ENV=bughunt.local\n");
         expect(decoct(fileperms($worktree.'/.env.bughunt.local') & 0777))->toBe('600');
     } finally {
         File::deleteDirectory(dirname($parent));
     }
 });
 
-test('コピーに失敗したら非ゼロで落ちる (失敗を握り潰さない)', function (): void {
-    // 秘密ファイルのコピー失敗を「親に無いためスキップ」に化けさせないこと。
-    // コピー先ディレクトリを書き込み不可にして install を失敗させる。
+test('D-6: 供給先が既に 0666 で存在しても上書き後は内容が新しく mode は 0600', function (): void {
     [$parent, $worktree] = makeWorktreeFixture();
 
     try {
-        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");
+        File::put($parent.'/.env', "APP_ENV=new\n");
+        chmod($parent.'/.env', 0644);
+        File::put($worktree.'/.env', "APP_ENV=old\n");
+        chmod($worktree.'/.env', 0666);
+
+        expect(runProvisionSecretFile('required', $parent, $worktree, '.env')->exitCode() ?? 1)->toBe(0);
+
+        expect(File::get($worktree.'/.env'))->toBe("APP_ENV=new\n");
+        expect(decoct(fileperms($worktree.'/.env') & 0777))
+            ->toBe('600', 'cp は供給先が既に存在するとその mode を変えない (退行の入り口)');
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
+
+test('D-7: optional で供給元が無ければ終了コード 0 で供給先を作らない', function (): void {
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        $result = runProvisionSecretFile('optional', $parent, $worktree, '.env.bughunt.local');
+
+        expect($result->exitCode() ?? 1)->toBe(0);
+        expect(File::exists($worktree.'/.env.bughunt.local'))->toBeFalse('空ファイルを作っている');
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
+
+test('D-8: required で供給元が無ければ非ゼロで止まり供給先を作らない', function (): void {
+    // 見本ファイルによる代替が復活していないこと (壊れた worktree を無言で作らない) も兼ねる。
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        File::put($parent.'/.env.example', "APP_ENV=example\n");
+
+        $result = runProvisionSecretFile('required', $parent, $worktree, '.env');
+
+        expect($result->exitCode() ?? 0)->not->toBe(0, '必須の供給元が無いのに成功している');
+        expect(File::exists($worktree.'/.env'))->toBeFalse();
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
+
+test('D-9: 要否指定が required / optional 以外なら非ゼロ (fail-closed)', function (): void {
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        File::put($parent.'/.env', "APP_ENV=local\n");
+
+        $result = runProvisionSecretFile('maybe', $parent, $worktree, '.env');
+
+        expect($result->exitCode() ?? 0)->not->toBe(0, '未知の要否指定が黙って通っている');
+        expect(File::exists($worktree.'/.env'))->toBeFalse();
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
+
+test('D-10: 供給先の親ディレクトリが無ければ非ゼロで、ディレクトリを作らない', function (): void {
+    // install -D への退行検出。供給先パスを間違えたときに worktree の外へ
+    // 静かにディレクトリを作る経路を持たせない。
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        File::put($parent.'/storage/oauth-private.key', "PRIVATE\n");
+        File::deleteDirectory($worktree.'/storage');
+
+        $result = runProvisionSecretFile('optional', $parent, $worktree, 'storage/oauth-private.key');
+
+        expect($result->exitCode() ?? 0)->not->toBe(0, '供給先の親ディレクトリが無いのに成功している');
+        expect(File::isDirectory($worktree.'/storage'))->toBeFalse('親ディレクトリを作っている (install -D への退行)');
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
+
+test('D-11: 供給先ディレクトリが書き込み不可なら非ゼロ (失敗を握り潰さない)', function (): void {
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        File::put($parent.'/.env', "APP_ENV=local\n");
         chmod($worktree, 0500);   // 書き込み不可
 
-        expect(runProvisionBughuntEnvFile($parent, $worktree))
-            ->not->toBe(0, 'コピー失敗が成功扱いになっている');
-        expect(File::exists($worktree.'/.env.bughunt.local'))->toBeFalse();
+        $result = runProvisionSecretFile('required', $parent, $worktree, '.env');
+
+        expect($result->exitCode() ?? 0)->not->toBe(0, '供給失敗が成功扱いになっている');
+        expect(File::exists($worktree.'/.env'))->toBeFalse();
     } finally {
         chmod($worktree, 0700);
         File::deleteDirectory(dirname($parent));
@@ -131,28 +286,107 @@ function makeWorktreeFixture(): array
     'root では書き込み不可ディレクトリでも install が成功するため検証できない',
 );
 
-test('本体の呼び出しが if の条件式に置かれていないこと (set -e で失敗が伝播する)', function (): void {
-    // 関数単体テストでは top-level の set -e 配線までは固定できない。
-    // `if provision_bughunt_env_file ...` の形へ戻ると、install の失敗が
-    // 条件評価に吸われて「無いためスキップ」に化ける (Round 1 の指摘)。
+test('D-12: PROVISIONED_PATHS には供給したものだけが記録される', function (): void {
+    // health check が「存在しないパス」を検査して偽赤になるのを防ぐ。
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        File::put($parent.'/.env', "APP_ENV=local\n");
+
+        $supplied = runProvisionSecretFile('required', $parent, $worktree, '.env');
+        expect($supplied->exitCode() ?? 1)->toBe(0);
+        expect(provisionedPathsOf($supplied))->toBe(['.env']);
+
+        $skipped = runProvisionSecretFile('optional', $parent, $worktree, '.env.bughunt.local');
+        expect($skipped->exitCode() ?? 1)->toBe(0);
+        expect(provisionedPathsOf($skipped))->toBe([], '供給していないパスが記録されている');
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
+
+test('D-13: 供給先が symlink なら非ゼロで落ち、リンク先の内容が変わらない', function (): void {
+    // install は symlink を辿ってリンク先へ書き込むため、辿った先が worktree の外でも
+    // 0600 の秘密ファイルを置いてしまう。
+    [$parent, $worktree] = makeWorktreeFixture();
+
+    try {
+        File::put($parent.'/.env', "APP_ENV=secret\n");
+        File::put($parent.'/outside.txt', "OUTSIDE\n");
+        symlink($parent.'/outside.txt', $worktree.'/.env');
+
+        $result = runProvisionSecretFile('required', $parent, $worktree, '.env');
+
+        expect($result->exitCode() ?? 0)->not->toBe(0, 'symlink を辿って書き込んでいる');
+        expect(File::get($parent.'/outside.txt'))->toBe("OUTSIDE\n", 'リンク先へ秘密が書き込まれている');
+    } finally {
+        File::deleteDirectory(dirname($parent));
+    }
+});
+
+// --- 静的ケース (scripts/setup-worktree.sh の本文を読む) ---
+
+test('S-1: provision_secret_file が条件式の位置で呼ばれていない', function (): void {
+    // 条件の中では set -e が効かず、install の失敗が「無いためスキップ」に化けて
+    // 秘密ファイルの供給失敗を隠す。
     //
-    // ★ 保証範囲: これは**既知の回帰形の検出**であって、
-    //   「あらゆる bash 条件コンテキストに置かれていないことの証明」ではない
-    //   (複数行にまたがる複雑な条件式までは正規表現で追えない)。
-    //   素の呼び出し行の完全一致と組み合わせて、実用上の検出力を確保している。
-    $source = File::get(base_path('scripts/setup-worktree.sh'));
+    // ★ 先頭に \b を置くと `&& provision_...` / `|| provision_...` を捕まえられない。
+    //   \b は「単語文字と非単語文字の境界」なので、直前が空白・直後が & のときは境界が成立せず
+    //   その選択肢へ到達しない。
+    expect(setupWorktreeSource())->not->toMatch(
+        '/(?:\b(?:if|while|until)\s+(?:!\s*)?|(?:&&|\|\|)\s*(?:!\s*)?)provision_secret_file\b/',
+        'provision_secret_file が条件式の位置で呼ばれている (set -e が効かず失敗を握り潰す)',
+    );
+});
 
-    expect($source)->not->toMatch(
-        '/\b(if|while|until|&&|\|\|)\s+provision_bughunt_env_file/',
-        'provision_bughunt_env_file が条件式の位置で呼ばれている (set -e が効かず失敗を握り潰す)',
+test('S-2: 秘密ファイル 4 本が素の呼び出し行の完全一致で主経路に存在する', function (): void {
+    // 供給対象が黙って減る・要否が黙って変わるのを検出する。
+    $source = setupWorktreeSource();
+
+    $calls = [
+        'provision_secret_file required "${REPO_ROOT}" "${WORKTREE_DIR}" ".env" "${ENV_SETUP_HINT}"',
+        'provision_secret_file optional "${REPO_ROOT}" "${WORKTREE_DIR}" "storage/oauth-private.key" "必要なら worktree 内で \'php artisan passport:keys\'"',
+        'provision_secret_file optional "${REPO_ROOT}" "${WORKTREE_DIR}" "storage/oauth-public.key" "必要なら worktree 内で \'php artisan passport:keys\'"',
+        'provision_secret_file optional "${REPO_ROOT}" "${WORKTREE_DIR}" ".env.bughunt.local" "bug-hunt 未使用なら不要"',
+    ];
+
+    foreach ($calls as $call) {
+        expect($source)->toMatch(
+            '/^'.preg_quote($call, '/').'$/mu',
+            "主経路の供給行が見つからない: {$call}",
+        );
+    }
+});
+
+test('S-3: provision_secret_file の本体が install -m 600 を素の文として実行している', function (): void {
+    // 部分一致では `install ... || true` のような退行が通ってしまうので、
+    // 行頭・行末をアンカーした完全一致で照合する。
+    expect(provisionSecretFileBody(setupWorktreeSource()))->toMatch(
+        '/^ {4}install -m 600 -- "\$\{src\}" "\$\{dst\}"$/m',
+        'provision_secret_file が install -m 600 を素の文として実行していない (失敗が握り潰される形になっている)',
     );
-    // 本体からは素の文として呼ばれていること
-    expect($source)->toMatch('/^\s{4}provision_bughunt_env_file "\$\{REPO_ROOT\}" "\$\{WORKTREE_DIR\}"$/m');
 });
 
-test('install -m 600 を使っていること (cp + chmod の 2 段へ退行していない)', function (): void {
-    // 2 段だと cp 直後から chmod までの間だけ world-readable な秘密ファイルが存在する。
-    $source = File::get(base_path('scripts/setup-worktree.sh'));
+test('S-4: 秘密ファイル 4 本を cp で供給する行が 0 件', function (): void {
+    // ★ 保証範囲: cp の直接の退行形だけを見る (変数に入れたコマンド名、別コマンド、
+    //   別表記での供給には沈黙する)。
+    expect(setupWorktreeSource())->not->toMatch(
+        '/^\s*cp\s+.*"\$\{REPO_ROOT\}\/(?:\.env|storage\/oauth-[a-z]+\.key|\.env\.bughunt\.local)"/m',
+        '秘密ファイルが cp で供給されている (関数経由への統一が崩れている)',
+    );
+});
 
-    expect($source)->toContain('install -m 600 "${repo_root}/.env.bughunt.local"');
+test('S-5: .env.example を .env として置く代替が復活していない', function (): void {
+    // .env.example から作った .env は APP_KEY も DB 接続も入っておらず、health check は
+    // 通ってしまうため、動くように見えて壊れている worktree が無言で出来上がる。
+    $source = setupWorktreeSource();
+
+    expect($source)->not->toMatch(
+        '/^\s*(?:cp|install|mv|ln)\s+.*\.env\.example/m',
+        '.env.example を worktree へ置く代替が復活している',
+    );
+    expect($source)->not->toMatch(
+        '/provision_secret_file\s+\S+\s+.*\.env\.example/',
+        '.env.example を供給関数経由で置いている',
+    );
 });
```

## テスト結果

# T170 受入確認の記録 (detailed-design.md §受入確認 の実施結果)

対象設計: `devnotes/20260815-1536-worktree-secret-file-mode/detailed-design.md`
実装 worktree: `.claude/worktrees/tasks/T170` (ブランチ `todo/T170`)

## V-0 テストファースト (失敗の確認)

契約テスト 18 ケースを先に書き、実装前に実行した。

```
{"tool":"pest","result":"failed","tests":18,"passed":6,"assertions":23,"failed":12}
```

- 落ちた 12 件のうち D-1〜D-7 / D-12 は終了コード **127** (`provision_secret_file: command not found`) で失敗。
  新関数がまだ無いことによる失敗であり、テストが実体を見ていることの確認になる。
- S-2 は主経路の呼び出し行が無いため失敗、S-3 は関数定義が無いため fail-closed で失敗、
  S-4 / S-5 は現行の `cp` 供給と `.env.example` 代替を検出して失敗。
- 通ってしまった 6 件 (D-8 / D-9 / D-10 / D-11 / D-13 / S-1) は
  **「非ゼロで落ちること」「現れないこと」を見る否定形**なので、関数不在でも成立する。
  これは否定形ケースの性質上避けられない (実装後に初めて意味を持つ)。

## V-1 実装と静的検査

| 検査 | 結果 |
|---|---|
| `composer test -- --filter=SetupWorktreeRuntimeFilesContractTest` | `passed` 18/18 (assertions 40) |
| `composer test` (全体) | `passed` tests 4918 / passed 4916 / skipped 2 / failed 0 |
| `composer phpstan` | No errors (level 10) |
| `vendor/bin/pint --test` | passed |
| `pnpm lint` / `pnpm typecheck` | 通過 |
| `pnpm test` | 136 files / 1501 tests passed |
| `pnpm build` | built |
| `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | 通過 (10 files / 106 tests) |
| `bash -n scripts/setup-worktree.sh` | 構文 OK |
| `shellcheck` | 未導入のため実施せず |

## V-2 実走確認 (実際に worktree を作る)

実装 worktree のスクリプト実体で、対象を main のチェックアウトにして実走した。

```
cd /workspace
bash /workspace/.claude/worktrees/tasks/T170/scripts/setup-worktree.sh T170-verify
```

- `[0/7]` から `[7/7]` まで到達し `health check: OK` / `✅ worktree 作成完了` (終了コード 0)。
- 設計の確認ブロックをそのまま実行し `rc=0`:

```
OK: 600 .env (親 mode=644)
OK: 600 storage/oauth-private.key (親 mode=600)
OK: 600 storage/oauth-public.key (親 mode=660)
OK: 600 .env.bughunt.local (親 mode=644)
OK: public/build 供給あり
OK: artisan / pest が worktree 内で動く
```

  **親が 644 / 660 の 3 本が供給先で 600 になっている** = 本 TODO の目的そのものが実測できた。
  0600 にしたことで読めなくなる利用者がいないことも `php artisan --version` /
  `vendor/bin/pest --version` の実行で確認した。
- `[timing]` は各段とも従来と同程度 (供給段は 0s)。
- 後片付け: `scripts/teardown-worktree.sh T170-verify` + `git branch -D todo/T170-verify` 実施済み。

## V-3 必須不足で停止することの確認

設計どおり**実走では行わない** (親の `.env` を退避する形は並行セッションに危険)。
契約は D-8 (required 経路) と S-5 (見本による代替の不在) が固定しており、どちらも green。

## V-4 マージ後の再確認

Phase C でマージ直後に実施する (記録は本ファイルへ追記する)。

## 質問

上記の観点でレビューし、全体判定 (APPROVED / CHANGES_REQUESTED) を明記せよ。
