# 詳細設計: claude-hooks-settings-wiring

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、
そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

本施策は開発の進め方の基盤であり、使命への寄与は間接である (概念設計「期待効果」参照)。

### 禁止事項

`AGENTS.md` の禁止事項が正本。本設計に直結するもの:

1. テストなしの実装完了報告 (不変条件は Architecture テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
9. Artifact の使用 (成果物はリポジトリ内のファイルとして出力する)

思考原則 3「**後方互換の並走を残さない**」も本件の中心にある — 見本ファイルは同じ変更で削除する。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest** (`composer test`)。`RefreshDatabase` はグローバル適用、個別 `DatabaseTransactions` 禁止
- 本件のテストは **DB を触らない** (ファイル読み取りと別プロセス起動のみ)
- シェルスクリプトは `bash -n` を通すこと。`shellcheck` は本リポジトリに無いので導入しない
- PHP 8.4 + Laravel 12。テストは `tests/Architecture/` 配下

## 概念設計リファレンス

`devnotes/20260815-1539-claude-hooks-settings-wiring/conceptual-design.md`

## 設計中に実測で確認した挙動 (この設計の前提)

| # | 確認したこと | 結果 |
|---|---|---|
| M1 | 索引の全体構築 (2209 ファイル) | 14.6 秒 |
| M2 | 索引の差分更新 `code-review-graph update -q --repo …` | 0.5 秒 |
| M3 | `IFS= read -r -N 1048576 -t 2 input` は時間切れでも読めた分を変数に残す | 残す (7 バイト読めた状態で 2 秒で復帰) |
| M4 | 標準入力を閉じない相手に対して待ち続けないか | 待たない (`-t` の秒数で復帰し exit 0) |
| M5 | `[[ … =~ \"command\"…\"((\\.|[^\"\\])*)\" ]]` によるエスケープ込みの抽出 | `ls -la \"x\" && bug-hunt-shard.sh provision` を正しく取り出せる |
| M6 | `${d//../}` による `..` の検出 / `${d#/}` による絶対パス判定 / `${ext,,}` の小文字化 | すべて期待どおり |
| M7 | 起動子の写像 (97→2、2→0、0→0、変数なし→0、相対値→0、`..` 入り→0、`scripts/` が symlink→0) | 7 ケースすべて期待どおり |
| M8 | `bash -p` が環境からのシェル関数の継承を止めるか | 止める (`-p` 無しでは `printf` が乗っ取られ、`-p` 有りでは乗っ取られない) |
| M9 | 起動子の文字列を JSON へ入れて読み戻したときの同一性 | 一致する (エスケープは `\"` のみ) |

M1〜M9 の再現手順は本設計の各節に書いたコマンドそのものである。

## 実装中に判明した設計の誤り (この節が本設計への訂正である)

実装 (T172) の途中で本設計の記述が誤っていた箇所が 6 件見つかった。いずれも本文側を訂正済みで、
以下はその一覧である (後から差分を追えるように、何をどう直したかを残す)。

| # | 誤っていた記述 | 実測 | 訂正 |
|---|---|---|---|
| E1 | 施策 1 段 6 の `exec 9> "${lock_file}" 2> /dev/null` | コマンドを伴わない `exec` のリダイレクトは**シェル全体へ永続適用**され、段 7・段 8 の告知がすべて `/dev/null` へ消える (実行契約 3 が壊れる) | 波括弧のグループ `{ exec 9> "${lock_file}"; } 2> /dev/null` に直した。fd 9 だけが残り、標準エラーの差し替えはグループの外で戻る |
| E2 | 施策 6 の逸脱番号 `D15` | `docs/template-divergence.md` は既に D17 まで使用済み | **D18** に直した |
| E3 | S06 の「`$CLAUDE_PROJECT_DIR` を検証する 5 条件」 | 起動子が持つ検証は 7 条件 (未設定 / 絶対パス / `..` 不在 / `scripts` が実ディレクトリ / `scripts` が symlink でない / 起動先が通常ファイル / 起動先が symlink でない) | 「7 条件」に直した。検査も 7 つ全部を見る |
| E4 | 共有プロローグの開始マーカーが相手ファイルの名前を書く形 | 2 本でマーカー行そのものが違うと、byte 一致の比較対象を「マーカーの内側だけ」に限る必要があり、検査が 1 段複雑になる | マーカー行を 2 本で同一の中立な文言にした (`# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---`)。マーカーごと byte 比較できる |
| E5 | 実起動層の検索パスを `$sandbox/bin:/usr/local/bin:/usr/bin:/bin` にする案 | 索引ツールが `/home/vscode/.local/bin` 以外へ導入された環境では「未導入」を再現できず、B02〜B05 が環境依存になる | sandbox 内に 3 種類の bin (`bin` / `bin-notool` / `bin-notimeout`) を作り、必要な外部コマンド (`mkdir` / `flock` / `timeout` / `sleep`) だけを symlink で持たせる。システムディレクトリは検索パスに一切入れない = 完全に決定的になる |
| E6 | 「公式の説明はツール名の正確な文字列を書く形であり、部分一致で派生ツールも拾うという前提は置けない」という**根拠の無い**理由付け | Claude Code 本体 (2.1.233) の判定関数を実読した。matcher が `[a-zA-Z0-9_|]` だけなら正規表現にせず `|` で分割して**完全一致**で比べる。`Write|Edit` はこの経路に入るので `NotebookEdit` には一致しない | 実測を根拠として書き直した。記録は `devnotes/20260815-2015-todo-T172/matcher-semantics-evidence.md`。`^(Write|Edit)$` へのアンカー追加は**採らない** (文字集合から外れて正規表現の経路へ移るだけで、意味論の変化を防げない)。**台帳テストは設定の文字列しか見ないので、本体側の判定機序の変化は検出しない** — 再確認は Claude Code 更新時の人手の運用で担う |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 索引更新 hook の実体を新設する | `scripts/code-review-graph-update-hook.sh` (新規) | 高 |
| 2 | bug-hunt ガードの判定を組み込みだけで完結させる | `scripts/bughunt-worktree-hook.sh` | 高 |
| 3 | `.claude/settings.json` を新設し見本を削除する | `.claude/settings.json` (新規) / `.claude/settings.bughunt-hook.example.json` (削除) | 高 |
| 4 | 配線を台帳化する | `tests/Architecture/ClaudeHooksWiringTest.php` (新規) | 高 |
| 5 | 索引ツールの導入を必須化する | `docker/Dockerfile` / `.gitignore` | 中 |
| 6 | 規約と台帳の文書を更新する | `AGENTS.md` / `README.md` / `scripts/README.md` / `docs/template-divergence.md` | 中 |

実装順序は 1 → 2 → 3 → 4 (テストは 4 を先に赤くしてから 1〜3 を通す形でもよい) → 5 → 6。

---

## 施策 1: 索引更新 hook の実体を新設する

### 変更箇所

- 新規: `scripts/code-review-graph-update-hook.sh`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Architecture/ClaudeHooksWiringTest.php` (施策 4 で新設)
- `.gitignore`: 作業ファイル置き場の追加 (施策 5)
- `scripts/README.md`: 台帳行の追加 (施策 6。`ScriptsReadmeInventoryTest` が deny-by-default で強制)

### 実行契約 (スクリプト冒頭にそのまま書く)

1. 何が起きても終了コード 0 で終わる (編集作業を止めない)
2. 標準出力は常に空
3. 告知は標準エラーに 1 行だけ。セッションごと・理由ごとに 1 回だけ
4. 更新は必ず `flock` で排他する。安全に排他できない環境では更新しない
5. 呼び出し側の時間切れ (30 秒) より内側 (20 秒) で自分から諦める
6. 作業ディレクトリと環境変数に依存しない (リポジトリルートは自分の位置から解決する)
7. 最初の外部コマンド呼び出しより前に検索パスを安全化する
8. 置き場・ロック・告知フラグが symlink なら何も書かずに終える
9. 索引の対象外の拡張子では更新を起動しない (副作用ゼロ)

### 処理順序 (この順序自体が契約である)

| 段 | 何をするか | 失敗したら |
|---|---|---|
| 0 | 検索パス安全化 (共有プロローグ) | 落とせる要素が無ければ最小のシステムパスに倒す |
| 1 | 標準入力を **最大 1 MiB / 最大 5 秒**で 1 回だけ読む | 読めなくても続行 (以降の抽出が空になるだけ) |
| 2 | `file_path` を抽出し、**対象外拡張子なら即 exit 0** | 抽出できなければ更新側へ倒す |
| 3 | セッション識別子を抽出・検証する (副作用が無いのでここで済ませる) | 不正なら固定語 `unknown` に落とす |
| 4 | リポジトリルートを `BASH_SOURCE` から解決する | 解決できなければ exit 0 |
| 5 | `.claude` と置き場の symlink 検査 → 置き場作成 → **再検査** | 作れない/symlink なら **黙って** exit 0 |
| 6 | ロックを開いて `flock -n` で取る | 取れない (他が更新中) なら **黙って** exit 0 |
| 7 | 索引ツール・`timeout` の在否を見る | 無ければ告知 1 行 → exit 0 |
| 8 | `timeout 20 code-review-graph update -q --repo <root>` | 124/137 なら `update-timeout`、他の非 0 なら `update-failed` を告知 |
| 9 | 常に exit 0 | — |

**段 5・6 で黙る理由**: 置き場が作れなければ告知の目印そのものが作れず、編集のたびに
1 行出て邪魔になる。ロック競合は劣化ではなく正常動作 (他のプロセスが同じ仕事をしている)。
**告知の重複抑止は目印ファイルの排他的作成 (noclobber) に委ねる**ので、ロックの外で告知しても
二重に出ない (段 6 より前に出る告知は `no-flock` の 1 つだけである)。

### 実装 (全文)

```bash
#!/usr/bin/env bash
# PostToolUse(Write|Edit) — コード索引 (code-review-graph) の差分更新。
#
# 実行契約 (tests/Architecture/ClaudeHooksWiringTest.php が実挙動で固定する):
#  1. 何が起きても終了コード 0 で終わる (編集作業を止めない)
#  2. 標準出力は常に空
#  3. 告知は標準エラーに 1 行だけ。セッションごと・理由ごとに 1 回だけ
#  4. 更新は必ず flock で排他する。安全に排他できない環境では更新しない
#  5. 呼び出し側の時間切れ (30 秒) より内側 (20 秒) で自分から諦める
#  6. 作業ディレクトリと環境変数に依存しない (リポジトリルートは自分の位置から解決する)
#  7. 最初の外部コマンド呼び出しより前に検索パスを安全化する
#  8. 置き場・ロック・告知フラグが symlink なら何も書かずに終える
#  9. 索引の対象外の拡張子では更新を起動しない (副作用ゼロ)
#
# 索引ツール自身の install / uninstall は実行しないこと (配線の正本が二重化する。AGENTS.md)。

# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---
# set -e は使わない: 途中の失敗で暗黙に終了すると「常に 0 で終わる」契約を守れない。
set -uo pipefail
export LC_ALL=C
_hook_sanitize_path() {
    local element out=''
    local -a elements=()
    IFS=':' read -r -a elements <<< "${PATH-}"
    for element in ${elements[@]+"${elements[@]}"}; do
        # 絶対パスでない要素 (空要素・"." ・相対パス) を落とす
        case "${element}" in
            /*) ;;
            *) continue ;;
        esac
        # 正規化前の別表記も落とす (//, /./, /../, 末尾の /. と /..)
        case "${element}" in
            *//*|*/./*|*/../*|*/.|*/..) continue ;;
        esac
        out="${out:+${out}:}${element}"
    done
    # 空の PATH はカレントディレクトリと解釈されうるので、最小のシステムパスに倒す
    PATH="${out:-/usr/local/bin:/usr/bin:/bin}"
    export PATH
}
_hook_sanitize_path
# ---8< /SHARED_PATH_PROLOGUE >8---

# 呼び出し側 (.claude/settings.json) の 30 秒より内側で自分から諦める
readonly INNER_TIMEOUT_SECONDS=20
# 索引の対象外の拡張子 (台帳テストが完全一致で固定する。索引ツール更新時は棚卸しすること)
readonly SKIP_EXTENSIONS='md txt json yaml yml lock log'

state_dir=''
session_id='unknown'

# 告知: 標準エラーに 1 行だけ。セッションごと・理由ごとに 1 回だけ。
# 目印ファイルの作成は noclobber (O_CREAT|O_EXCL) なので、
#  - 既にあれば作成に失敗する = 重複抑止そのもの (読み書きの競合が起きない)
#  - 目印が symlink でも作成に失敗する = 検査と作成が原子的 (TOCTOU が無い)
emit_warning() {
    local reason="$1" message="$2" flag
    flag="${state_dir}/warned-${reason}-${session_id}"
    ( set -C; : > "${flag}" ) 2> /dev/null || return 0
    printf 'code-review-graph: %s\n' "${message}" >&2
    return 0
}

# --- 段 1: 標準入力を 1 回だけ読む (最大 1 MiB / 最大 5 秒) -------------------
input=''
IFS= read -r -N 1048576 -t 5 input || true

# --- 段 2: 対象外拡張子なら副作用ゼロで終わる --------------------------------
file_path=''
if [[ "${input}" =~ \"file_path\"[[:space:]]*:[[:space:]]*\"([^\"]*)\" ]]; then
    file_path="${BASH_REMATCH[1]}"
fi
# 拡張子は untrusted な値なので、パターン側には置かず**文字列等価**で比べる
# (`*` や `[` を含む拡張子でパターンとして解釈されるのを防ぐ)。
case "${file_path}" in
    *.*)
        extension="${file_path##*.}"
        extension="${extension,,}"
        for skip in ${SKIP_EXTENSIONS}; do
            [ "${extension}" = "${skip}" ] && exit 0
        done
        ;;
esac

# --- 段 3: セッション識別子 (純粋な文字列処理。副作用が無いのでここで済ませる) ---
if [[ "${input}" =~ \"session_id\"[[:space:]]*:[[:space:]]*\"([A-Za-z0-9._-]{1,64})\" ]]; then
    case "${BASH_REMATCH[1]}" in
        .|..) ;;
        *) session_id="${BASH_REMATCH[1]}" ;;
    esac
fi

# --- 段 4: リポジトリルートを自分の位置から解決する ---------------------------
script_path="${BASH_SOURCE[0]}"
script_dir="${script_path%/*}"
[ "${script_dir}" = "${script_path}" ] && script_dir='.'
repo_root="$(cd -- "${script_dir}/.." > /dev/null 2>&1 && pwd -P)" || exit 0
[ -n "${repo_root}" ] || exit 0

# --- 段 5: 置き場の symlink 検査 → 作成 → 再検査 ------------------------------
claude_dir="${repo_root}/.claude"
state_dir="${claude_dir}/code-review-graph-update-hook"
[ -L "${claude_dir}" ] && exit 0
[ -L "${state_dir}" ] && exit 0
mkdir -p "${state_dir}" 2>/dev/null || exit 0
[ -L "${claude_dir}" ] && exit 0
[ -L "${state_dir}" ] && exit 0
[ -d "${state_dir}" ] || exit 0

# --- 段 6: 排他 (非ブロッキング。取れなければ黙って終わる) --------------------
# ロックは flock で取る (プロセスが落ちても解放されるため。ディレクトリロックは
# 落ちたときに解放されず索引更新が恒久的に止まるので採らない)。
# 帰結として、ロックファイルの差し替え (TOCTOU) までは防がない = 保証範囲を下げてある。
lock_file="${state_dir}/update.lock"
[ -L "${lock_file}" ] && exit 0
if ! command -v flock > /dev/null 2>&1; then
    emit_warning 'no-flock' 'flock が無いため索引を更新しません (排他できない環境では更新しない契約です)'
    exit 0
fi
# ★ `exec 9> file 2>/dev/null` と書いてはいけない: コマンドを伴わない exec の
#   リダイレクトは**シェル全体へ永続適用**され、以降の告知 (契約 3) が消える。
#   波括弧のグループなら fd 9 だけが残り、標準エラーの差し替えはグループの外で戻る。
{ exec 9> "${lock_file}"; } 2> /dev/null || exit 0
flock -n 9 || exit 0

# --- 段 7: 前提コマンドの在否 ------------------------------------------------
if ! command -v code-review-graph > /dev/null 2>&1; then
    emit_warning 'tool-missing' \
        'コード索引ツールが未導入です (uv tool install code-review-graph==2.3.7 → code-review-graph build)'
    exit 0
fi
if ! command -v timeout > /dev/null 2>&1; then
    emit_warning 'no-timeout' 'timeout が無いため索引を更新しません (時間切れを保証できないためです)'
    exit 0
fi

# --- 段 8: 差分更新 ----------------------------------------------------------
timeout -k 5 "${INNER_TIMEOUT_SECONDS}" \
    code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1
status=$?
case "${status}" in
    0) ;;
    124|137) emit_warning 'update-timeout' \
        "索引の差分更新が ${INNER_TIMEOUT_SECONDS} 秒で終わらなかったため中断しました" ;;
    *) emit_warning 'update-failed' \
        "索引の差分更新に失敗しました (終了コード ${status}。code-review-graph build を試してください)" ;;
esac

# --- 段 9: 常に成功で終わる --------------------------------------------------
exit 0
```

### 設計上の注意 (実装者向け)

- `set -e` を**使わない**。使うと段 5 の `mkdir` 失敗などで暗黙終了し、契約 1 を破る
  (追従元が実装時に踏んだ罠として台帳に記録がある)。
- `exec 9> …` の後は `flock -n 9` が失敗しても fd は開いたままだが、プロセス終了で解放される。
- `${extension,,}` は bash 4 以降の小文字化。`.MD` のような大文字拡張子も落とす。
- `timeout -k 5` は TERM の 5 秒後に KILL する。KILL された場合の終了コード 137 も時間切れ扱いにする。
- 標準出力へ書く箇所がひとつも無いことを、実装後に目視でも確認すること (契約 2)。
- 告知の目印ファイル (`warned-<理由>-<セッション識別子>`) は 0 バイトで、置き場ごと消して構わない
  (消せば次のセッションで再告知される)。この旨を AGENTS.md に 1 行書く。

### 保証しないもの (施策 1)

- **ロックファイルの差し替え (TOCTOU) は防がない**。`[ -L ]` は 1 回だけ見る。`flock` を使う以上
  「検査してから開く」の間は閉じられない。ディレクトリロックなら閉じられるが、プロセスが
  落ちたときに解放されず索引更新が**恒久的に止まる**ため採らない (可用性を優先する)。
  そもそも置き場の中に symlink を差し込める者は、hook スクリプト自体を書き換えられる。
- **索引の中身の正しさは保証しない** (索引ツールの責務)。
- **`Write` / `Edit` 以外の経路によるファイル変更は拾わない** (`Bash` の `sed -i`、
  `NotebookEdit`、外部エディタでの編集など)。ただし索引ツールの差分更新は「直前のコミット以降の
  差分」を再解析するので、それらの変更も**次の Write / Edit のときにまとめて取り込まれる**
  (取りこぼしは恒久化しない)。

### テスト計画 (施策 4 の実起動層で固定する)

| ID | ケース | 期待 |
|---|---|---|
| B01 | 索引ツールを含む stub PATH で正常な入力 | exit 0 / stdout 空 / stderr 空 / stub が `update` で 1 回起動される |
| B02 | 索引ツール未導入 (stub の無い PATH) | exit 0 / stdout 空 / stderr 1 行 / 文言に `tool-missing` の理由が対応する語を含む |
| B03 | B02 と同じセッションでもう 1 回 | stderr 0 行 (重複抑止) |
| B04 | B02 と同じ理由で別セッション識別子 | stderr 1 行 (セッションが変われば再告知) |
| B05 | 同一セッションで別の理由 (`no-timeout`) | stderr 1 行 (理由ごとに 1 回) |
| B06 | `PATH=` (空) | exit 0 / stdout 空 / カレントに置いた偽 `code-review-graph` が起動されない |
| B07 | `PATH=.` および `PATH=/nonexistent` | 同上 |
| B08 | 壊れた JSON | exit 0 / stdout 空 |
| B09 | 標準入力が空 | exit 0 |
| B10 | 標準入力を閉じない producer | プロセスが自分で終了 / exit 0 / 経過 5 秒 + 余裕以内 |
| B11 | 1 MiB より後ろにだけ `file_path` を置いた入力 | exit 0 / 読み取りが上限で打ち切られる (待ち続けない) |
| B12 | `.claude` が symlink | exit 0 / リンク先に何も書かれない |
| B13 | 置き場が symlink | exit 0 / リンク先に何も書かれない |
| B14 | ロックファイルが symlink | exit 0 / 更新が起動しない |
| B15 | 置き場の親が書けない (0500) | exit 0 / stdout 空 |
| B16 | ロックを別プロセスが保持している | exit 0 / 更新が起動しない / 即座に返る (1 秒以内) |
| B17 | 5 並列起動 | 全部 exit 0 / 更新の起動は 1 回だけ / 合計が呼び出し側 timeout 未満 |
| B18 | 更新が終わらない stub (60 秒 sleep) | exit 0 / 経過が内側 timeout + 余裕以内 / `update-timeout` の告知 1 行 |
| B19 | 更新が非 0 で失敗する stub | exit 0 / `update-failed` の告知 1 行 |
| B20 | `session_id` が `../../etc/passwd` | exit 0 / 置き場の外にファイルが作られない |
| B21 | `file_path` が `docs/x.md` (対象外拡張子) | exit 0 / 更新が起動しない / **置き場・ロック・告知フラグが 1 つも作られない** |
| B22 | `file_path` が `x.MD` (大文字) | B21 と同じ |
| B23 | `file_path` が `resources/views/x.blade.php` | 更新が起動する |
| B24 | `file_path` が拡張子なし (`Makefile`) / 抽出できない | 更新が起動する (対象外側へ倒さない) |
| B25 | cwd を `/` にし `CLAUDE_PROJECT_DIR` を外して起動 | 更新が sandbox のリポジトリルートを `--repo` で受け取る |

実起動は **sandbox に実スクリプトを複製した木** (`$sandbox/scripts/…`) に対して行う
(`BASH_SOURCE` 解決の結果、置き場が sandbox 側になり、本物のリポジトリを汚さない)。
`code-review-graph` は sandbox の `bin/` に置いた stub を PATH で見せる
(stub は起動された事実と引数を記録するファイルを書く)。

**PATH の作り方**: sandbox の中に bin を 3 つ作り、検索パスには**そのどれか 1 つだけ**を置く
(システムディレクトリは 1 つも入れない = 実行環境に左右されない)。必要な外部コマンド
(`mkdir` / `flock` / `timeout` / `sleep`) は絶対パスを解決して symlink で持たせる。

| bin | 中身 | 作る状況 |
|---|---|---|
| `bin` | 索引ツールの stub + 4 コマンド | 正常 (`claudeHooksPathWithTool()`) |
| `bin-notool` | 4 コマンドのみ | 索引ツール未導入 (`claudeHooksPathWithoutTool()`) |
| `bin-notimeout` | 索引ツールの stub + `timeout` 以外の 3 コマンド | `timeout` 不在 (`claudeHooksPathWithoutTimeout()`) |

「索引ツール未導入」をシステムパスの有無で作らないのが要点である
(索引ツールの導入先は環境によって変わるため、そこに依存させると検査が環境依存になる)。

### リスク

- B18 は内側 timeout (20 秒) の実測を伴うため、この 1 ケースだけ約 21 秒かかる。
  値を小さくすると実運用で早すぎる中断が起きるため、テスト時間より運用の正しさを採る。
- stub 方式なので**実際の索引更新の正しさは検査しない** (それは索引ツールの責務)。

---

## 施策 2: bug-hunt ガードの判定を組み込みだけで完結させる

### 変更箇所

- `scripts/bughunt-worktree-hook.sh` (判定部の差し替え)

**変更の範囲を正確に書く**: 「判定条件を 1 文字も変えない」は不正確だった。正しくは —
**正常に `command` を抽出できた経路の拒否対象・許可シグナルは不変**であり、
**抽出に失敗した経路だけ**、現行の「素通り」から概念設計の規則 (明示解除だけを見て、
無ければ拒否) へ変える。これは意図した改善であって、副作用ではない。

### 現行コード (判定部)

```bash
set -euo pipefail

input="$(cat)"
cmd="$(printf '%s' "${input}" | python3 -c 'import sys,json
try: print(json.load(sys.stdin).get("tool_input",{}).get("command",""))
except Exception: print("")' 2>/dev/null || true)"

printf '%s' "${cmd}" | grep -qE 'bug-hunt-shard\.sh[[:space:]]+provision' || exit 0

if printf '%s' "${cmd}" | grep -qE '\.claude/worktrees/|BUGHUNT_ALLOW_MAIN=|BUGHUNT_SELFTEST_DRYRUN='; then
    exit 0
fi

cat >&2 <<'MSG'
⛔ …
MSG
exit 2
```

**この形の欠陥**: 判定経路が `cat` / `python3` / `grep` に依存する。検索パスからこれらを解決
できない環境ではいずれも 127 で終わり、`|| true` と `2>/dev/null` により**拒否対象が黙って通る**
(追従元で実測された無音 fail-open と同型)。配線されていない今は誰も踏んでいないが、
常設した瞬間から実害になる。

### 変更後コード (判定部)

```bash
# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---
（施策 1 と完全に同じブロックをここに置く）
# ---8< /SHARED_PATH_PROLOGUE >8---

# 拒否は終了コード 97 で表す。.claude/settings.json の起動子が 97 だけを 2 へ写像するため、
# 構文エラー (2) や実行不能 (126/127) が Bash ツールをブロックすることは無い。
readonly DENY_EXIT_CODE=97

# 標準入力は 1 回だけ読む。最大 1 MiB / 最大 5 秒 (閉じない相手に待ち続けない)。
input=''
IFS= read -r -N 1048576 -t 5 input || true

# 段 0: 対象語が無ければ外部コマンドを 1 つも起こさずに通す (無関係なコマンドは構造的に無影響)
case "${input}" in
    *bug-hunt-shard.sh*) ;;
    *) exit 0 ;;
esac

# 段 1: tool_input.command を取り出す (JSON エスケープは我々が探すバイト列を増やす方向にしか働かない)
command_text=''
extracted=0
if [[ "${input}" =~ \"command\"[[:space:]]*:[[:space:]]*\"((\\.|[^\"\\])*)\" ]]; then
    command_text="${BASH_REMATCH[1]}"
    extracted=1
fi

# 段 2: 判定
#  - 抽出できた: 抽出値だけで判定する (許可シグナル 2 種とも有効)
#  - 抽出できない: 明示解除 BUGHUNT_ALLOW_MAIN= だけを生入力で見る
#    (痕跡 .claude/worktrees/ は偶然そこにあり得るので抽出失敗時は評価しない)
if [ "${extracted}" -eq 1 ]; then
    subject="${command_text}"
    allow_regex='(\.claude\\?/worktrees\\?/|BUGHUNT_ALLOW_MAIN=|BUGHUNT_SELFTEST_DRYRUN=)'
else
    subject="${input}"
    allow_regex='BUGHUNT_ALLOW_MAIN='
fi

# 実行の検出は「bug-hunt-shard.sh の直後の空白 + provision」に限る
# (コミットメッセージ等の文字列言及では誤発火しない)。JSON の \n \t \r 表記も空白として受ける。
[[ "${subject}" =~ bug-hunt-shard\.sh([[:space:]]|\\[nrt])+provision ]] || exit 0
[[ "${subject}" =~ ${allow_regex} ]] && exit 0

# 拒否メッセージも組み込みで出す (ヒアドキュメント + cat を使わない)。
# これで**このスクリプトは外部コマンドを 1 つも使わない**ことになり、
# 検索パスがどれだけ壊れていても挙動が変わらない。
printf '%s\n' \
    '⛔ bug-hunt provision を worktree 外から直叩きしようとしています (skill app-bug-hunt の Phase 0a スキップ)。' \
    'bug-hunt は worktree から走るのが既定です (main を直接汚さず todo/ ブランチに隔離するため)。次のいずれかで起動してください:' \
    '  1) /app-bug-hunt 経由 (推奨。Phase 0a が worktree を自動で切る)' \
    '  2) scripts/setup-worktree.sh bughunt-<task-id> で worktree を切り、その worktree 内' \
    '     (cd .claude/worktrees/tasks/bughunt-<task-id>) から本スクリプトを実行' \
    '  3) 意図的な main 走行 (--keep-db 連続再走など asset 既存の単発確認) のみ コマンド先頭に BUGHUNT_ALLOW_MAIN=1 を付ける' \
    >&2
exit "${DENY_EXIT_CODE}"
```

- 文面は現行と同一 (行の分け方だけ変わる)。
- `set -euo pipefail` は `set -uo pipefail` に変える (`-e` があると `[[ … ]]` の偽で暗黙終了しうる)。
  これは共有プロローグの一部として 2 本で同じになる。

### 波及変更

- `.claude/settings.json` (施策 3) — 97 → 2 の写像を持つ起動子
- `.claude/skills/app-bug-hunt/SKILL.md` — 拒否時の終了コードに言及があるか確認し、
  あれば「97 を起動子が 2 へ写す」旨に直す (無ければ変更なし)
- `AGENTS.md` §bug-hunt の「配線は `.claude/settings.bughunt-hook.example.json` を
  `.claude/settings.json` にマージ」という記述 → 常設済みの記述へ差し替える (施策 6)

### テスト計画 (施策 4 の実起動層)

| ID | ケース | 期待 |
|---|---|---|
| B26 | 無関係なコマンド (`ls -la`) | exit 0 |
| B27 | 無関係なコマンド + `PATH=/nonexistent` + カレントに偽 `grep`/`python3` | exit 0 / 偽コマンドが起動されない (スクリプトが外部コマンドを使わない証拠) |
| B28 | `scripts/bug-hunt-shard.sh provision --shard 1` | exit 97 / stderr に拒否文面 |
| B29 | B28 + `PATH=` (空) | exit 97 (無音 fail-open が無い) |
| B30 | worktree パスを含む provision | exit 0 |
| B31 | `BUGHUNT_ALLOW_MAIN=1` 付き provision | exit 0 |
| B32 | `BUGHUNT_SELFTEST_DRYRUN=1` 付き provision | exit 0 |
| B33 | JSON の `description` にだけ provision の文字列があり command は別物 | exit 0 (抽出値で判定している証拠) |
| B34 | 壊れた JSON + provision 文字列 + 許可シグナル無し | exit 97 |
| B35 | 壊れた JSON + provision 文字列 + `BUGHUNT_ALLOW_MAIN=` | exit 0 |
| B36 | 壊れた JSON + provision 文字列 + `.claude/worktrees/` のみ | exit 97 (痕跡は抽出失敗時に評価しない) |
| B37 | `.claude\/worktrees\/` (エスケープ形式) を含む provision | exit 0 (許可を取りこぼさない) |
| B38 | 標準入力が空 / 閉じない producer | exit 0 / 自分で終了 / 5 秒 + 余裕以内 |
| B39 | 1 MiB より後ろにだけ provision を置いた入力 | exit 0 (読まない = 通す。受容済みの限界) |
| B40 | `bug-hunt-shard.sh scaffold … provision` (間に別語) | exit 0 (誤発火しない) |
| B40b | `scripts/bug-hunt-shard.sh provision-all` | exit 97 (現行コメントが掲げる対象。回帰防止) |

### リスク

- 抽出正規表現 `\"command\"…` は `tool_input` の外にある同名キーにも当たりうる。現行の
  `tool_input.command` 限定より広い。ただし PreToolUse の matcher が `Bash` に限られており、
  払い出しに `command` キーは 1 つしか現れない。B33 が「説明文では誤発火しない」ことを固定する。
- `\uXXXX` 形式のエスケープは取りこぼす (概念設計「保証しないもの」で受容済み)。

---

## 施策 3: `.claude/settings.json` を新設し見本を削除する

### 変更箇所

- 新規: `.claude/settings.json` (git 追跡)
- 削除: `.claude/settings.bughunt-hook.example.json`

### 変更後コード

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Bash",
        "hooks": [
          {
            "type": "command",
            "command": "/bin/bash -p -c 'd=${CLAUDE_PROJECT_DIR:-}; f=$d/scripts/bughunt-worktree-hook.sh; s=0; if [ -n \"$d\" ] && [ \"${d#/}\" != \"$d\" ] && [ \"${d//../}\" = \"$d\" ] && [ -d \"$d/scripts\" ] && [ ! -L \"$d/scripts\" ] && [ -f \"$f\" ] && [ ! -L \"$f\" ]; then /bin/bash -p \"$f\"; s=$?; fi; if [ \"$s\" = 97 ]; then exit 2; fi; exit 0'",
            "timeout": 10
          }
        ]
      }
    ],
    "PostToolUse": [
      {
        "matcher": "Write|Edit",
        "hooks": [
          {
            "type": "command",
            "command": "/bin/bash -p -c 'd=${CLAUDE_PROJECT_DIR:-}; f=$d/scripts/code-review-graph-update-hook.sh; if [ -n \"$d\" ] && [ \"${d#/}\" != \"$d\" ] && [ \"${d//../}\" = \"$d\" ] && [ -d \"$d/scripts\" ] && [ ! -L \"$d/scripts\" ] && [ -f \"$f\" ] && [ ! -L \"$f\" ]; then /bin/bash -p \"$f\"; fi; exit 0'",
            "timeout": 30
          }
        ]
      }
    ]
  }
}
```

起動子が持つ 3 つの役割:

1. **起動先の検証** — `CLAUDE_PROJECT_DIR` が絶対パスで `..` を含まないこと、`scripts/` が
   symlink でない実ディレクトリであること、起動先が symlink でない通常ファイルであること。
   1 つでも欠ければ内側を起動しない。
2. **終了コードの写像** — PreToolUse は 97 だけを 2 へ写し、それ以外はすべて 0。
   PostToolUse は無条件に 0。
3. **環境からのシェル関数の遮断** — `-p` (privileged mode)。組み込みと同名の関数を
   注入して判定を乗っ取る経路を閉じる。

検査はすべて bash の組み込み (`[` / パラメータ展開) で、外部コマンドを 1 つも使わない。

### 波及変更

- `.claude/settings.bughunt-hook.example.json` の削除に伴い、参照している文書
  (`AGENTS.md` §bug-hunt) を施策 6 で直す。
- `.claude/settings.local.json` は作らない (存在すれば `hooks` を持たないことを台帳が検査する)。

### テスト計画 (施策 4)

静的層 S01〜S12 と、起動子そのものを起動する実起動層 B41〜B48 (下記)。

### リスク

- 設定はセッション開始時にしか読まれないため、**worktree 内では実配線を確認できない**。
  main 統合後の新しいセッションで確認する (申し送り事項)。
- `matcher` に `Bash` を選ぶことで、すべての Bash 呼び出しに hook が挟まる。段 0 の
  組み込み 1 回で抜けるため実測上のコストは無視できるが、`timeout` を 10 秒に据えるのは
  「万一固まっても 10 秒で解放される」ための上限である。

### matcher の対象 (`Write` と `Edit` の 2 つだけ)

`Write|Edit` は **`Write` と `Edit` のときだけ発火する**。根拠は実測である (E6。
matcher が英数字・下線・`|` だけで出来ているときは正規表現にされず、`|` で分割して
**完全一致**で比べられる。Claude Code 2.1.233 で本体を実読した)。
**この挙動は台帳テストでは守れない** — テストが見るのは設定の文字列だけで、本体側の
判定機序が変わっても気づけないので、再確認は Claude Code 更新時の人手の運用で担う。
したがって:

- 台帳のコメントには「**対象はこの 2 ツールだけ**」と書く。将来の派生ツールを自動で拾うとは
  書かない (書くと嘘になる)。
- 存在しないツール名 (`MultiEdit`) を先回りで台帳へ足さない。
- `NotebookEdit` は本リポジトリに notebook が無いため対象にしない。必要になったら
  `Write|Edit|NotebookEdit` と明示列挙して台帳・テストを同じ変更で直す。

---

## 施策 4: 配線を台帳化する

### 変更箇所

- 新規: `tests/Architecture/ClaudeHooksWiringTest.php`

### 台帳 (deny-by-default の正本)

```php
/**
 * 配線台帳。ここに書かれた形と .claude/settings.json が**完全一致**しなければ落ちる。
 * 台帳に無い hook・イベント・トップレベルキーはすべて違反 (既定拒否)。
 */
const CLAUDE_HOOKS_TOP_LEVEL_KEYS = ['hooks'];

const CLAUDE_HOOKS_WIRING = [
    'PreToolUse' => [
        ['matcher' => 'Bash', 'script' => 'scripts/bughunt-worktree-hook.sh', 'timeout' => 10, 'deny_exit_code' => 97],
    ],
    'PostToolUse' => [
        ['matcher' => 'Write|Edit', 'script' => 'scripts/code-review-graph-update-hook.sh', 'timeout' => 30, 'deny_exit_code' => null],
    ],
];

/** 索引の対象外拡張子 (scripts/code-review-graph-update-hook.sh の SKIP_EXTENSIONS と一致すること) */
const CLAUDE_HOOKS_SKIP_EXTENSIONS = ['md', 'txt', 'json', 'yaml', 'yml', 'lock', 'log'];
```

起動コマンド文字列は**台帳側で組み立てて完全一致**を要求する
(`claudeHooksExpectedCommand(string $script, ?int $denyExitCode): string`)。
設定ファイルを書き換えたら必ずテストが落ちる = 配線の正本が 1 か所になる。

### 静的層

| ID | 検査 |
|---|---|
| S01 | `.claude/settings.json` が実在し、有効な JSON である |
| S02 | `.claude/settings.json` が git 追跡下にある (`git ls-files` で確認) |
| S03 | トップレベルキーが `CLAUDE_HOOKS_TOP_LEVEL_KEYS` と完全一致 (順不同・過不足なし) |
| S04 | hooks のイベント集合が台帳と完全一致 |
| S05 | 各イベントの matcher / command / timeout が台帳と完全一致 (1 文字でも違えば落ちる) |
| S06 | 起動文字列が `/bin/bash -p -c ` で始まり、`$CLAUDE_PROJECT_DIR` を検証する 7 条件をすべて含み、PreToolUse は `= 97` の写像を、PostToolUse は無条件 `exit 0` を持つ |
| S07 | `.claude/settings.local.json` が存在する場合、`hooks` キーを持たない |
| S08 | `.claude/settings.bughunt-hook.example.json` が存在しない (見本方式の復活禁止) |
| S09 | 台帳の 2 スクリプトが実在し `bash -n` を通る |
| S10 | 2 スクリプトの `SHARED_PATH_PROLOGUE` ブロックが byte 一致し、かつ**両方でファイル先頭にある** |
| S11 | `scripts/code-review-graph-update-hook.sh` の `SKIP_EXTENSIONS` が `CLAUDE_HOOKS_SKIP_EXTENSIONS` と完全一致 |
| S12a | `AGENTS.md` に「索引ツール自身に配線を書かせない」明文がマーカー付きで存在する |
| S12b | **実行面のファイル**に `code-review-graph install` / `init` / `uninstall` の呼び出しが無い |

**S10 は shell parser を作らない**。次の 3 点だけを見る:

1. 2 本それぞれに開始・終了マーカーが 1 組ずつある
2. マーカー間の内容が byte 一致する
3. 開始マーカー**より前**の行が、shebang・コメント・空行だけである

3 により「プロローグはファイル先頭にある」= 「最初の外部コマンド呼び出しより前にある」が
自動的に成立する。行の中身を解釈しないので誤検出しない。

**S12b の走査範囲は実行面に限る** — `scripts/**/*.sh` / `.claude/settings*.json` /
`docker/Dockerfile` / `composer.json` / `package.json` / `.github/workflows/*`。
文書 (`AGENTS.md` / `README.md` / `docs/**` / `devnotes/**`) は走査しない。禁止を説明する文章に
コマンド名が出るのは正常であり、走査すると設計書・逸脱台帳・本 devnotes で必ず落ちるためである
(明文の存在は S12a が別に見る)。

### 実起動層 (起動子の写像を固定する)

| ID | ケース | 期待 |
|---|---|---|
| B41 | sandbox の `scripts/bughunt-worktree-hook.sh` が 97 を返す | 起動子の終了コード 2 |
| B42 | 同スクリプトが 0 を返す | 0 |
| B43 | 同スクリプトが 2 を返す (構文エラーの模倣) | **0** (ブロックしない) |
| B44 | 同スクリプトが存在しない | 0 |
| B45 | `CLAUDE_PROJECT_DIR` を外す | 0 |
| B46 | `CLAUDE_PROJECT_DIR` が相対値で、その先に 97 を返すスクリプトを置く | 0 |
| B47 | `CLAUDE_PROJECT_DIR` に `..` を含め、解決先に 97 を返すスクリプトを置く | 0 |
| B48 | `scripts/` を symlink にし、その先の同名スクリプトが 97 を返す | 0 |
| B49 | 起動先スクリプトが symlink で、その先が 97 を返す | 0 |
| B50 | PostToolUse の起動子: 内側が 97 でも 2 でも 1 でも | 常に 0 |
| B51 | `printf` を上書きするシェル関数を export した環境で起動子を走らせる | 内側に継承されない (`-p` が効いている証拠。M8 の機械化) |

実起動は `.claude/settings.json` から**実際に読んだ文字列**を `bash -c` で走らせる
(台帳の写しではなく本物を走らせる = 設定を直したら必ずここも動く)。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (ヘルパ関数はすべて `: string` / `: array` 等)
- [x] null 安全 (`file_get_contents` の `false` は `Assert::string()` で narrow する)
- [x] 配列の形は PHPDoc の `array{...}` / `list<string>` で明示する
- [x] `Process::run()` の結果は `exitCode(): ?int` を `Assert::integer()` で narrow する
- 関数名は他の Architecture テストと衝突しないよう `claudeHooks` 接頭辞で始める
  (Pest は全テストを 1 プロセスで読み込むため、素の名前は衝突する)

### テスト計画

本施策そのものがテストである。加えて:

- **変異検出**: 実装後に設定ファイルの timeout を 1 文字変える / matcher を変える /
  見本ファイルを復活させる / プロローグを片方だけ変える、の 4 つで**実際に落ちること**を
  手で確認し、結果を devnotes に残す (台帳が空振りしていないことの確認)。

### リスク

- sandbox を大量に作るため、テストは `sys_get_temp_dir()` 配下に作り `finally` で必ず消す。
- 実起動層は `Process::timeout()` を必ず指定する (無限待ちを作らない)。

---

## 施策 5: 索引ツールの導入を必須化する

### 変更箇所

- `docker/Dockerfile`: 版を固定した導入と `ENV PATH` への追加
- `.gitignore`: `/.claude/code-review-graph-update-hook/` の追加

### 変更後コード

```dockerfile
# コード索引 (code-review-graph)。AGENTS.md がコードベース探索の第一選択と定めており、
# .claude/settings.json の PostToolUse hook が差分更新を回すため、版を固定して導入する。
# 版を上げるときは scripts/code-review-graph-update-hook.sh の対象外拡張子の棚卸しも行うこと。
#
# 導入先は USER に依存させず明示する (HOME 由来だと RUN の位置を動かしただけで
# /root/.local/bin へ落ち、hook が「未導入」と告知するようになるため)。
ENV UV_TOOL_DIR=/home/vscode/.local/share/uv/tools
ENV UV_TOOL_BIN_DIR=/home/vscode/.local/bin
RUN uv tool install code-review-graph==2.3.7

# 導入先を検索パスへ載せる (hook 側は導入先を知らない = 環境固有の知識を持たせない)
ENV PATH="/home/vscode/.local/bin:$PATH"
```

配置は `USER vscode` (現行 91 行目) と `mise install` (現行 101 行目) より**後**に置く
(`uv` は mise の shim 経由で解決されるため)。ただし上の 2 つの環境変数により、
配置がずれても導入先は変わらない。

`.gitignore`:

```
# PostToolUse 索引更新 hook の作業ファイル置き場 (ロック / 告知フラグ)
/.claude/code-review-graph-update-hook/
```

### 波及変更

- `README.md` セットアップ節: 索引ツールが image に入っていること、既存コンテナは
  作り直すか手で `uv tool install` することを 2 行で書く。
- `AGENTS.md` §コードベース探索: 「hook で自動更新されない場合」という人手前提の記述を、
  「PostToolUse hook が自動更新する。前提コマンドは `flock` / `timeout`」へ直す。

### テスト計画

`tests/Architecture/DockerfileProvisioningTest.php` に 3 ケース追加する
(既存の ffmpeg / fonts-noto-cjk と同じ形の静的 guard):

1. `code-review-graph==2.3.7` を**版固定**で導入している (版を上げるときはこのテストも同時に
   直す = 対象外拡張子の棚卸しがレビューで見える)
2. `ENV UV_TOOL_BIN_DIR=/home/vscode/.local/bin` を宣言している (導入先の固定)
3. `ENV PATH` に同じディレクトリが含まれる (これが消えると hook が「未導入」告知に落ちる)

### リスク

- **既存コンテナには効かない**。作り直すまでは hook がセッションごとに 1 行告知する
  (黙って何もしない状態にはならない)。この受け入れ条件は「導入系」として分けてある。
- image のビルド時間が `uv tool install` の分だけ伸びる (数十秒)。ネットワーク依存が 1 つ増える。

---

## 施策 6: 規約と台帳の文書を更新する

### 変更箇所

| ファイル | 変更内容 |
|---|---|
| `AGENTS.md` | (a) §bug-hunt の「見本をマージ」記述を「常設済み」へ差し替え (b) §コードベース探索を自動更新前提へ書き換え + 実行環境前提の明示 (c) **新設**「常設 hook 配線」節 — 2 本の一覧と、索引ツール自身に配線を書かせない明文 (マーカー付き) |
| `README.md` | セットアップ節に索引ツールの前提を 2 行追記 |
| `scripts/README.md` | `code-review-graph-update-hook.sh` の台帳行を追加。`bughunt-worktree-hook.sh` の行の「見本をマージ」を「常設配線」へ更新 |
| `docs/template-divergence.md` | **D18** として起動子の逸脱を記録 (D17 まで使用済みのため) |

### `AGENTS.md` に置く明文 (マーカー付き)

```markdown
<!-- CLAUDE_HOOKS_WIRING:BEGIN -->
## 常設 hook 配線

`.claude/settings.json` は git 追跡下の**配線の正本**である。配線されている hook は 2 本:

| イベント | 対象 | スクリプト | 役割 |
|---|---|---|---|
| PreToolUse | Bash | `scripts/bughunt-worktree-hook.sh` | bug-hunt provision の main 直叩きを止める |
| PostToolUse | Write / Edit | `scripts/code-review-graph-update-hook.sh` | コード索引の差分更新 |

- 起動子は終了コードの写像器を兼ねる。**PreToolUse をブロックできるのはスクリプトが
  意図して返す 97 だけ**で、構文エラー・ファイル不在・実行不能はすべて 0 に畳まれる
  (hook の故障がセッションの Bash 操作を止めない)。
- 前提コマンド: `flock` / `timeout` (どちらも欠けると索引更新は走らず、セッションごとに
  1 行だけ告知する)。
- **`code-review-graph install` / `init` / `uninstall` を実行しないこと**。これらは MCP 設定・
  hook 配線・本ファイルへの指示注入まで行い、**配線の正本が二重化する**。配線を変えるときは
  `.claude/settings.json` と `tests/Architecture/ClaudeHooksWiringTest.php` の台帳を同じ
  変更で直す。
- 配線を変えたら**新しいセッションを開始するまで反映されない** (設定はセッション開始時に
  1 度だけ読まれる)。
<!-- CLAUDE_HOOKS_WIRING:END -->
```

マーカーは S12 が存在を検査する (明文ごと消せない)。

### `docs/template-divergence.md` D18 の骨子

- **逸脱**: hook の起動子を追従元の `/bin/bash "$CLAUDE_PROJECT_DIR/scripts/…"` ではなく、
  起動先を検証して終了コードを写像する形にした。
- **なぜ正当か (logic-driven)**: bash は構文エラーでも 2 を返し、PreToolUse の 2 は
  Bash ツールをブロックする。追従元の形では hook スクリプトの 1 文字のタイプミスが
  そのセッションの Bash 操作を全滅させうる。写像器は設定ファイル側にあるため、
  スクリプトの退行から独立している。
- **揃えている不変条件**: 常設配線であること / 起動子が絶対パスであること /
  排他がスクリプト内にあること / 配線が台帳テストで完全一致 pin されていること。
- **関連**: lctl feature `claude-hooks-wiring` (t2) / `code-index-update-hook` (v1)。

### テスト計画

- `AGENTS.md` のマーカーと明文の存在は S12 が検査する。
- `scripts/README.md` の台帳行は既存の `ScriptsReadmeInventoryTest` が deny-by-default で強制する
  (新スクリプトを足して行を書かなければ落ちる = 追加のテストは不要)。
- `docs/template-divergence.md` は機械検査を持たない (既存の運用どおり)。

### リスク

- `AGENTS.md` は churn が大きいファイルなので、マーカー区間の位置は §bug-hunt の直前に固定し、
  他節との重複記述を作らない (二重管理の回避)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** (= 専用 worktree の単独タスク。**main 直接実装ではない**。AGENTS.md §worktree 運用ルールに従う) |
| 判断根拠 | hook はセッション全体の挙動を変えるので、他の施策と同じ worktree に混ぜると、失敗したときに原因の切り分けができない。設定・スクリプト・台帳テストの 3 点は同時に入らないと台帳が落ちるため、分割もできない。なお `.claude/settings.json` は**セッション開始時にしか読まれない**ので、実配線の確認だけは main 統合後の新しいセッションで行う (これは実装場所の話ではなく、確認時点の話である) |
| 競合リスク | `AGENTS.md` / `scripts/README.md` / `.gitignore` / `docker/Dockerfile` は他タスクも触りうる。いずれも追記中心なので衝突は行単位で解消できる。`tests/Architecture/` は新規ファイルのみで衝突しない |

## 実装後の申し送り (完了報告に必ず含めること)

1. main へ統合した**後の新しいセッション**で、`Write` / `Edit` を 1 回行い、
   索引が実際に前進すること (`code-review-graph status` の Last updated が進む) を確認する。
2. 同じセッションで `ls` などの無関係な Bash を数回叩き、遅延・警告が出ないことを確認する。
3. 既存の開発コンテナは作り直すまで索引ツールが入らない。作り直さない場合は
   `uv tool install code-review-graph==2.3.7` を手で 1 度実行する。
4. 台帳の変異検出 (施策 4 のリスク欄) の結果を devnotes に残す。
