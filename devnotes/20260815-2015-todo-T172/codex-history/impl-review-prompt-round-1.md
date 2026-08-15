## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則
## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件(アプリ都合で緩めない)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: 実装レビュー

あなたは Laravel + Svelte のコードレビュアーである。本 PR は **Claude Code の常設 hook 配線**
(`.claude/settings.json`) と、そこから起動される 2 本の bash スクリプト、および配線を
deny-by-default で固定する Architecture テストの新設である。

## レビュー観点

1. **設計との一致性**: 詳細設計書 (下記) の施策 1〜6 と実装が一致しているか。
   設計の誤りを実装側が正しく訂正しているか (設計書冒頭の「実装中に判明した設計の誤り」節を参照)
2. **正確性**: bash の挙動 (リダイレクトの永続適用 / `set -u` / `[[ =~ ]]` の正規表現 /
   パラメータ展開 / `flock` / `timeout` の終了コード) に誤りがないか。
   **fail-open (拒否対象が黙って通る) / 無音の恒久 no-op** を生む経路が残っていないか
3. **セキュリティ**: 敵対的な検索パス・symlink・注入されたシェル関数・細工された JSON /
   セッション識別子に対して、判定と副作用が壊れないか。TOCTOU の扱いが宣言と一致しているか
4. **PHPStan level 10 適合性**: テストは解析対象外だが、型注釈・narrow が正しいか
5. **テスト網羅性**: 契約 (常に 0 で終わる / 標準出力が空 / 告知は 1 回 / 排他 / 内側 timeout /
   対象外拡張子で副作用ゼロ / 起動子の写像) が**実挙動として**固定されているか。
   台帳が空振りしていないか (変異検出の記録も添付)
6. **保証範囲の誇張**: コメント・文書が「保証しないもの」を保証すると読める書き方をしていないか
7. **不要な複雑化**: 今必要でないものを作っていないか (思考原則 2)

DTO/JsonResource パターン・DESIGN.md・Atomic Design は本 PR に該当する差分が無い
(`resources/js` / `resources/css` を 1 行も触っていない)。

## 出力形式

- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を 1 行で書く

---

# user

## 詳細設計書

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

実装 (T172) の途中で本設計の記述が誤っていた箇所が 5 つ見つかった。いずれも本文側を訂正済みで、
以下はその一覧である (後から差分を追えるように、何をどう直したかを残す)。

| # | 誤っていた記述 | 実測 | 訂正 |
|---|---|---|---|
| E1 | 施策 1 段 6 の `exec 9> "${lock_file}" 2> /dev/null` | コマンドを伴わない `exec` のリダイレクトは**シェル全体へ永続適用**され、段 7・段 8 の告知がすべて `/dev/null` へ消える (実行契約 3 が壊れる) | 波括弧のグループ `{ exec 9> "${lock_file}"; } 2> /dev/null` に直した。fd 9 だけが残り、標準エラーの差し替えはグループの外で戻る |
| E2 | 施策 6 の逸脱番号 `D15` | `docs/template-divergence.md` は既に D17 まで使用済み | **D18** に直した |
| E3 | S06 の「`$CLAUDE_PROJECT_DIR` を検証する 5 条件」 | 起動子が持つ検証は 7 条件 (未設定 / 絶対パス / `..` 不在 / `scripts` が実ディレクトリ / `scripts` が symlink でない / 起動先が通常ファイル / 起動先が symlink でない) | 「7 条件」に直した。検査も 7 つ全部を見る |
| E4 | 共有プロローグの開始マーカーが相手ファイルの名前を書く形 | 2 本でマーカー行そのものが違うと、byte 一致の比較対象を「マーカーの内側だけ」に限る必要があり、検査が 1 段複雑になる | マーカー行を 2 本で同一の中立な文言にした (`# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---`)。マーカーごと byte 比較できる |
| E5 | 実起動層の検索パスを `$sandbox/bin:/usr/local/bin:/usr/bin:/bin` にする案 | 索引ツールが `/home/vscode/.local/bin` 以外へ導入された環境では「未導入」を再現できず、B02〜B05 が環境依存になる | sandbox 内に 3 種類の bin (`bin` / `bin-notool` / `bin-notimeout`) を作り、必要な外部コマンド (`mkdir` / `flock` / `timeout` / `sleep`) だけを symlink で持たせる。システムディレクトリは検索パスに一切入れない = 完全に決定的になる |

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

`Write|Edit` は **`Write` と `Edit` のときだけ発火する**。公式の説明はツール名の正確な文字列を
書く形であり、「部分一致で派生ツールも拾う」という前提は置けない。したがって:

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

## 変異検出の記録

# T172 台帳の変異検出 (空振りしていないことの確認)

詳細設計 施策 4「リスク」欄が求めた確認。台帳テスト
`tests/Architecture/ClaudeHooksWiringTest.php` に対し、守るべき対象を 1 つずつ壊して
**実際に赤くなること**を確かめた (壊した後は毎回もとへ戻し、最後に緑を再確認した)。

実行コマンド: `vendor/bin/pest tests/Architecture/ClaudeHooksWiringTest.php`

| # | 壊したもの | 結果 | 落ちた検査 |
|---|---|---|---|
| 基準 | (無変更) | 70 passed / 0 failed | — |
| M1 | `.claude/settings.json` の `timeout` を 30 → 31 | 69 passed / 1 failed | S05/S06 (起動文字列・timeout の完全一致) |
| M2 | 同 `matcher` を `Write\|Edit` → `Write` | 69 passed / 1 failed | S05/S06 |
| M3 | 見本ファイル `.claude/settings.bughunt-hook.example.json` を復活 | 69 passed / 1 failed | S08 (見本方式の非復活) |
| M4 | 検索パス安全化ブロックを 2 本のうち片方だけ変更 | 69 passed / 1 failed | S10 (byte 一致 + 先頭配置) |
| 復帰 | すべて元に戻す | 70 passed / 0 failed | — |

4 種類すべてで**狙った検査だけ**が落ちた = 台帳は空振りしていない。

## 手順上の注意 (次に同じ確認をする人へ)

新規追加ファイル (まだ `HEAD` に無いファイル) を壊した後に
`git restore --staged --worktree <path>` で戻そうとすると、`HEAD` に復元元が無いため
**ファイルごと消える**。変異検出では対象ファイルを退避ディレクトリへ複製しておき、
複製から書き戻すこと (本記録の実行はこの方法で行った)。

## 実装差分 (git diff --cached)

```diff
diff --git a/.claude/settings.bughunt-hook.example.json b/.claude/settings.bughunt-hook.example.json
deleted file mode 100644
index bf58057..0000000
--- a/.claude/settings.bughunt-hook.example.json
+++ /dev/null
@@ -1,19 +0,0 @@
-{
-  "//": "bug-hunt PreToolUse ガードの配線例。この内容を .claude/settings.json の hooks にマージすること。",
-  "//_why": "bug-hunt-shard.sh provision を worktree 外 (main) から直叩きするのを harness レベルで早期にブロックする (scripts/bughunt-worktree-hook.sh)。スクリプト本体の assert_worktree_context が権威層、本フックは早期・観測層の二段防御。",
-  "//_apply": "settings.json は agent 起動設定のため自動編集は保留された。ユーザーが下記 hooks ブロックを .claude/settings.json に手動マージすること。",
-  "hooks": {
-    "PreToolUse": [
-      {
-        "matcher": "Bash",
-        "hooks": [
-          {
-            "type": "command",
-            "command": "bash \"$CLAUDE_PROJECT_DIR/scripts/bughunt-worktree-hook.sh\"",
-            "timeout": 10
-          }
-        ]
-      }
-    ]
-  }
-}
diff --git a/.claude/settings.json b/.claude/settings.json
new file mode 100644
index 0000000..b81bb14
--- /dev/null
+++ b/.claude/settings.json
@@ -0,0 +1,28 @@
+{
+  "hooks": {
+    "PreToolUse": [
+      {
+        "matcher": "Bash",
+        "hooks": [
+          {
+            "type": "command",
+            "command": "/bin/bash -p -c 'd=${CLAUDE_PROJECT_DIR:-}; f=$d/scripts/bughunt-worktree-hook.sh; s=0; if [ -n \"$d\" ] && [ \"${d#/}\" != \"$d\" ] && [ \"${d//../}\" = \"$d\" ] && [ -d \"$d/scripts\" ] && [ ! -L \"$d/scripts\" ] && [ -f \"$f\" ] && [ ! -L \"$f\" ]; then /bin/bash -p \"$f\"; s=$?; fi; if [ \"$s\" = 97 ]; then exit 2; fi; exit 0'",
+            "timeout": 10
+          }
+        ]
+      }
+    ],
+    "PostToolUse": [
+      {
+        "matcher": "Write|Edit",
+        "hooks": [
+          {
+            "type": "command",
+            "command": "/bin/bash -p -c 'd=${CLAUDE_PROJECT_DIR:-}; f=$d/scripts/code-review-graph-update-hook.sh; if [ -n \"$d\" ] && [ \"${d#/}\" != \"$d\" ] && [ \"${d//../}\" = \"$d\" ] && [ -d \"$d/scripts\" ] && [ ! -L \"$d/scripts\" ] && [ -f \"$f\" ] && [ ! -L \"$f\" ]; then /bin/bash -p \"$f\"; fi; exit 0'",
+            "timeout": 30
+          }
+        ]
+      }
+    ]
+  }
+}
diff --git a/.gitignore b/.gitignore
index cf45abc..709d76f 100644
--- a/.gitignore
+++ b/.gitignore
@@ -8,6 +8,8 @@
 .phpactor.json
 .phpunit.result.cache
 /.claude/bug-hunt.lock
+# PostToolUse 索引更新 hook の作業ファイル置き場 (ロック / 告知フラグ)
+/.claude/code-review-graph-update-hook/
 # worktree 実体と setup/teardown lock (scripts/setup-worktree.sh / teardown-worktree.sh)
 /.claude/worktrees/
 /.code-review-graph/
diff --git a/AGENTS.md b/AGENTS.md
index 8f010d1..d1ca8dd 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -229,10 +229,41 @@ ## コードベース探索
   grep / 全ファイル read より先に code-review-graph の MCP tools を試す
 - ただし機械的な文字列検索(TODO コメント抽出、特定リテラル探索など)は
   そのまま `rg` / `grep` を使う方が速い。code-review-graph はあくまで構造把握用
-- セットアップ: `uv tool install code-review-graph` → `code-review-graph build` で
-  初回ビルド(中規模アプリで ~50 秒)。以降は hook で自動更新されない場合
-  `code-review-graph update` で差分更新(~2 秒)
-- SQLite キャッシュ(`.code-review-graph/`)は `.gitignore` 済みでクローン毎に各自再生成
+- セットアップ: 開発コンテナには `docker/Dockerfile` が版を固定して導入済み
+  (`code-review-graph==2.3.7`)。コンテナを作り直していない環境だけ手で
+  `uv tool install code-review-graph==2.3.7` を 1 度実行する。索引の初回ビルドは
+  `code-review-graph build`(中規模アプリで ~15 秒)
+- 以降の差分更新は **`.claude/settings.json` の PostToolUse hook が自動で回す**
+  (§常設 hook 配線)。実行環境の前提は `flock` と `timeout` の 2 つで、
+  どちらか欠けると更新は走らず**セッションごとに 1 行だけ**告知する
+  (手で回すときは `code-review-graph update`。~0.5 秒)
+- SQLite キャッシュ(`.code-review-graph/`)は `.gitignore` 済みでクローン毎に各自再生成。
+  hook の作業ファイル置き場(`.claude/code-review-graph-update-hook/`)も同様で、
+  中身はロックと告知の目印だけなので消して構わない(消せば次のセッションで再告知される)
+
+<!-- CLAUDE_HOOKS_WIRING:BEGIN -->
+## 常設 hook 配線
+
+`.claude/settings.json` は git 追跡下の**配線の正本**である。配線されている hook は 2 本:
+
+| イベント | 対象 | スクリプト | 役割 |
+|---|---|---|---|
+| PreToolUse | Bash | `scripts/bughunt-worktree-hook.sh` | bug-hunt provision の main 直叩きを止める |
+| PostToolUse | Write / Edit | `scripts/code-review-graph-update-hook.sh` | コード索引の差分更新 |
+
+- 対象は **`Write` と `Edit` の 2 つだけ**である(将来の派生ツールを自動で拾うことはない)。
+- 起動子は終了コードの写像器を兼ねる。**PreToolUse をブロックできるのはスクリプトが
+  意図して返す 97 だけ**で、構文エラー・ファイル不在・実行不能はすべて 0 に畳まれる
+  (hook の故障がセッションの Bash 操作を止めない)。
+- 前提コマンド: `flock` / `timeout`(どちらも欠けると索引更新は走らず、セッションごとに
+  1 行だけ告知する)。
+- **`code-review-graph install` / `init` / `uninstall` を実行しないこと**。これらは MCP 設定・
+  hook 配線・本ファイルへの指示注入まで行い、**配線の正本が二重化する**。配線を変えるときは
+  `.claude/settings.json` と `tests/Architecture/ClaudeHooksWiringTest.php` の台帳を同じ
+  変更で直す。
+- 配線を変えたら**新しいセッションを開始するまで反映されない**(設定はセッション開始時に
+  1 度だけ読まれる)。
+<!-- CLAUDE_HOOKS_WIRING:END -->
 
 ## 設計・TODO・devnotes の運用
 
@@ -358,7 +389,7 @@ ## bug-hunt (LLM 探索的バグハント、オプトイン)
   wrapper `tmp/bug-hunt/shard-{i}-cmd.sh` には**露出しない**。段の定義・合否条件・失敗分類の語彙・
   **保証しないもの**は `docs/architecture.md` §パイプライン通し確認 が正本。
 - **worktree 既定**: bug-hunt は worktree から走る (`scripts/bughunt-worktree-hook.sh` の PreToolUse ガードが
-  main 直叩きを早期に止める。配線は `.claude/settings.bughunt-hook.example.json` を `.claude/settings.json` にマージ)。
+  main 直叩きを早期に止める。配線は `.claude/settings.json` に常設済み。§常設 hook 配線)。
 - **スケルトン**: `screens.md` / `operations.md` / `stories/` はテンプレートでは空スケルトン。初回に
   `php artisan route:list` から生成する (SKILL.md Phase 1)。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
 - **capability 語彙**: finding の `capability_tag` の正本は
diff --git a/README.md b/README.md
index 188fa47..d380f54 100644
--- a/README.md
+++ b/README.md
@@ -21,6 +21,12 @@ ## セットアップ
 Stripe 公式 skill(`skills-lock.json` に lock 済み)は `npx skills add docs.stripe.com` で
 `.claude/skills/` 配下に導入する(git 管理外)。
 
+コード索引ツール(`code-review-graph`)は開発コンテナに版を固定して同梱済みで、
+`.claude/settings.json` の hook が編集のたびに差分更新する(`AGENTS.md` §常設 hook 配線)。
+コンテナを作り直していない環境だけ `uv tool install code-review-graph==2.3.7` を 1 度実行し、
+`code-review-graph build` で索引を初回ビルドする(未導入でもセッションごとに 1 行告知が出るだけで、
+編集作業は止まらない)。
+
 管理者(Filament `/admin`)の発行(env / seeder による本番初期投入は廃止済み):
 
 ```bash
diff --git a/devnotes/20260815-1539-claude-hooks-settings-wiring/detailed-design.md b/devnotes/20260815-1539-claude-hooks-settings-wiring/detailed-design.md
index 2a80c2a..a4ff716 100644
--- a/devnotes/20260815-1539-claude-hooks-settings-wiring/detailed-design.md
+++ b/devnotes/20260815-1539-claude-hooks-settings-wiring/detailed-design.md
@@ -53,6 +53,19 @@ ## 設計中に実測で確認した挙動 (この設計の前提)
 
 M1〜M9 の再現手順は本設計の各節に書いたコマンドそのものである。
 
+## 実装中に判明した設計の誤り (この節が本設計への訂正である)
+
+実装 (T172) の途中で本設計の記述が誤っていた箇所が 5 つ見つかった。いずれも本文側を訂正済みで、
+以下はその一覧である (後から差分を追えるように、何をどう直したかを残す)。
+
+| # | 誤っていた記述 | 実測 | 訂正 |
+|---|---|---|---|
+| E1 | 施策 1 段 6 の `exec 9> "${lock_file}" 2> /dev/null` | コマンドを伴わない `exec` のリダイレクトは**シェル全体へ永続適用**され、段 7・段 8 の告知がすべて `/dev/null` へ消える (実行契約 3 が壊れる) | 波括弧のグループ `{ exec 9> "${lock_file}"; } 2> /dev/null` に直した。fd 9 だけが残り、標準エラーの差し替えはグループの外で戻る |
+| E2 | 施策 6 の逸脱番号 `D15` | `docs/template-divergence.md` は既に D17 まで使用済み | **D18** に直した |
+| E3 | S06 の「`$CLAUDE_PROJECT_DIR` を検証する 5 条件」 | 起動子が持つ検証は 7 条件 (未設定 / 絶対パス / `..` 不在 / `scripts` が実ディレクトリ / `scripts` が symlink でない / 起動先が通常ファイル / 起動先が symlink でない) | 「7 条件」に直した。検査も 7 つ全部を見る |
+| E4 | 共有プロローグの開始マーカーが相手ファイルの名前を書く形 | 2 本でマーカー行そのものが違うと、byte 一致の比較対象を「マーカーの内側だけ」に限る必要があり、検査が 1 段複雑になる | マーカー行を 2 本で同一の中立な文言にした (`# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---`)。マーカーごと byte 比較できる |
+| E5 | 実起動層の検索パスを `$sandbox/bin:/usr/local/bin:/usr/bin:/bin` にする案 | 索引ツールが `/home/vscode/.local/bin` 以外へ導入された環境では「未導入」を再現できず、B02〜B05 が環境依存になる | sandbox 内に 3 種類の bin (`bin` / `bin-notool` / `bin-notimeout`) を作り、必要な外部コマンド (`mkdir` / `flock` / `timeout` / `sleep`) だけを symlink で持たせる。システムディレクトリは検索パスに一切入れない = 完全に決定的になる |
+
 ## 施策一覧
 
 | # | 施策名 | 変更ファイル | 優先度 |
@@ -133,7 +146,7 @@ #  9. 索引の対象外の拡張子では更新を起動しない (副作用ゼ
 #
 # 索引ツール自身の install / uninstall は実行しないこと (配線の正本が二重化する。AGENTS.md)。
 
-# ---8< SHARED_PATH_PROLOGUE (bughunt-worktree-hook.sh と byte 一致。台帳テストが固定する) >8---
+# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---
 # set -e は使わない: 途中の失敗で暗黙に終了すると「常に 0 で終わる」契約を守れない。
 set -uo pipefail
 export LC_ALL=C
@@ -236,7 +249,10 @@ # 帰結として、ロックファイルの差し替え (TOCTOU) までは防
     emit_warning 'no-flock' 'flock が無いため索引を更新しません (排他できない環境では更新しない契約です)'
     exit 0
 fi
-exec 9> "${lock_file}" 2> /dev/null || exit 0
+# ★ `exec 9> file 2>/dev/null` と書いてはいけない: コマンドを伴わない exec の
+#   リダイレクトは**シェル全体へ永続適用**され、以降の告知 (契約 3) が消える。
+#   波括弧のグループなら fd 9 だけが残り、標準エラーの差し替えはグループの外で戻る。
+{ exec 9> "${lock_file}"; } 2> /dev/null || exit 0
 flock -n 9 || exit 0
 
 # --- 段 7: 前提コマンドの在否 ------------------------------------------------
@@ -324,12 +340,18 @@ ### テスト計画 (施策 4 の実起動層で固定する)
 `code-review-graph` は sandbox の `bin/` に置いた stub を PATH で見せる
 (stub は起動された事実と引数を記録するファイルを書く)。
 
-**PATH の作り方**: stub ディレクトリは**システムパスの前に足す**
-(`$sandbox/bin:/usr/local/bin:/usr/bin:/bin`)。`mkdir` / `flock` / `timeout` は本物が要るため、
-stub だけの PATH にすると段 5 で終わってしまい、検証したい経路に到達しない。
-「索引ツール未導入」を作るときは、stub ディレクトリから `code-review-graph` を**置かない**
-(PATH からシステムパスを外すのではない) — この区別をテストのヘルパ名でも明示する
-(`claudeHooksPathWithTool()` / `claudeHooksPathWithoutTool()`)。
+**PATH の作り方**: sandbox の中に bin を 3 つ作り、検索パスには**そのどれか 1 つだけ**を置く
+(システムディレクトリは 1 つも入れない = 実行環境に左右されない)。必要な外部コマンド
+(`mkdir` / `flock` / `timeout` / `sleep`) は絶対パスを解決して symlink で持たせる。
+
+| bin | 中身 | 作る状況 |
+|---|---|---|
+| `bin` | 索引ツールの stub + 4 コマンド | 正常 (`claudeHooksPathWithTool()`) |
+| `bin-notool` | 4 コマンドのみ | 索引ツール未導入 (`claudeHooksPathWithoutTool()`) |
+| `bin-notimeout` | 索引ツールの stub + `timeout` 以外の 3 コマンド | `timeout` 不在 (`claudeHooksPathWithoutTimeout()`) |
+
+「索引ツール未導入」をシステムパスの有無で作らないのが要点である
+(索引ツールの導入先は環境によって変わるため、そこに依存させると検査が環境依存になる)。
 
 ### リスク
 
@@ -380,7 +402,7 @@ ### 現行コード (判定部)
 ### 変更後コード (判定部)
 
 ```bash
-# ---8< SHARED_PATH_PROLOGUE (code-review-graph-update-hook.sh と byte 一致) >8---
+# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---
 （施策 1 と完全に同じブロックをここに置く）
 # ---8< /SHARED_PATH_PROLOGUE >8---
 
@@ -603,7 +625,7 @@ ### 静的層
 | S03 | トップレベルキーが `CLAUDE_HOOKS_TOP_LEVEL_KEYS` と完全一致 (順不同・過不足なし) |
 | S04 | hooks のイベント集合が台帳と完全一致 |
 | S05 | 各イベントの matcher / command / timeout が台帳と完全一致 (1 文字でも違えば落ちる) |
-| S06 | 起動文字列が `/bin/bash -p -c ` で始まり、`$CLAUDE_PROJECT_DIR` を検証する 5 条件をすべて含み、PreToolUse は `= 97` の写像を、PostToolUse は無条件 `exit 0` を持つ |
+| S06 | 起動文字列が `/bin/bash -p -c ` で始まり、`$CLAUDE_PROJECT_DIR` を検証する 7 条件をすべて含み、PreToolUse は `= 97` の写像を、PostToolUse は無条件 `exit 0` を持つ |
 | S07 | `.claude/settings.local.json` が存在する場合、`hooks` キーを持たない |
 | S08 | `.claude/settings.bughunt-hook.example.json` が存在しない (見本方式の復活禁止) |
 | S09 | 台帳の 2 スクリプトが実在し `bash -n` を通る |
@@ -739,7 +761,7 @@ ### 変更箇所
 | `AGENTS.md` | (a) §bug-hunt の「見本をマージ」記述を「常設済み」へ差し替え (b) §コードベース探索を自動更新前提へ書き換え + 実行環境前提の明示 (c) **新設**「常設 hook 配線」節 — 2 本の一覧と、索引ツール自身に配線を書かせない明文 (マーカー付き) |
 | `README.md` | セットアップ節に索引ツールの前提を 2 行追記 |
 | `scripts/README.md` | `code-review-graph-update-hook.sh` の台帳行を追加。`bughunt-worktree-hook.sh` の行の「見本をマージ」を「常設配線」へ更新 |
-| `docs/template-divergence.md` | **D15** として起動子の逸脱を記録 |
+| `docs/template-divergence.md` | **D18** として起動子の逸脱を記録 (D17 まで使用済みのため) |
 
 ### `AGENTS.md` に置く明文 (マーカー付き)
 
@@ -770,7 +792,7 @@ ## 常設 hook 配線
 
 マーカーは S12 が存在を検査する (明文ごと消せない)。
 
-### `docs/template-divergence.md` D15 の骨子
+### `docs/template-divergence.md` D18 の骨子
 
 - **逸脱**: hook の起動子を追従元の `/bin/bash "$CLAUDE_PROJECT_DIR/scripts/…"` ではなく、
   起動先を検証して終了コードを写像する形にした。
diff --git a/devnotes/20260815-2015-todo-T172/mutation-detection.md b/devnotes/20260815-2015-todo-T172/mutation-detection.md
new file mode 100644
index 0000000..f6e2c74
--- /dev/null
+++ b/devnotes/20260815-2015-todo-T172/mutation-detection.md
@@ -0,0 +1,25 @@
+# T172 台帳の変異検出 (空振りしていないことの確認)
+
+詳細設計 施策 4「リスク」欄が求めた確認。台帳テスト
+`tests/Architecture/ClaudeHooksWiringTest.php` に対し、守るべき対象を 1 つずつ壊して
+**実際に赤くなること**を確かめた (壊した後は毎回もとへ戻し、最後に緑を再確認した)。
+
+実行コマンド: `vendor/bin/pest tests/Architecture/ClaudeHooksWiringTest.php`
+
+| # | 壊したもの | 結果 | 落ちた検査 |
+|---|---|---|---|
+| 基準 | (無変更) | 70 passed / 0 failed | — |
+| M1 | `.claude/settings.json` の `timeout` を 30 → 31 | 69 passed / 1 failed | S05/S06 (起動文字列・timeout の完全一致) |
+| M2 | 同 `matcher` を `Write\|Edit` → `Write` | 69 passed / 1 failed | S05/S06 |
+| M3 | 見本ファイル `.claude/settings.bughunt-hook.example.json` を復活 | 69 passed / 1 failed | S08 (見本方式の非復活) |
+| M4 | 検索パス安全化ブロックを 2 本のうち片方だけ変更 | 69 passed / 1 failed | S10 (byte 一致 + 先頭配置) |
+| 復帰 | すべて元に戻す | 70 passed / 0 failed | — |
+
+4 種類すべてで**狙った検査だけ**が落ちた = 台帳は空振りしていない。
+
+## 手順上の注意 (次に同じ確認をする人へ)
+
+新規追加ファイル (まだ `HEAD` に無いファイル) を壊した後に
+`git restore --staged --worktree <path>` で戻そうとすると、`HEAD` に復元元が無いため
+**ファイルごと消える**。変異検出では対象ファイルを退避ディレクトリへ複製しておき、
+複製から書き戻すこと (本記録の実行はこの方法で行った)。
diff --git a/docker/Dockerfile b/docker/Dockerfile
index 73f7be0..2eaa671 100644
--- a/docker/Dockerfile
+++ b/docker/Dockerfile
@@ -99,3 +99,16 @@ RUN echo 'eval "$(mise activate bash)"' >> /home/vscode/.bashrc
 # /home/vscode/.local/share/mise/installs に残るため起動直後から使える。
 COPY --chown=vscode:vscode mise.toml /workspace/mise.toml
 RUN mise trust /workspace/mise.toml && mise install
+
+# コード索引 (code-review-graph)。AGENTS.md がコードベース探索の第一選択と定めており、
+# .claude/settings.json の PostToolUse hook が差分更新を回すため、版を固定して導入する。
+# 版を上げるときは scripts/code-review-graph-update-hook.sh の対象外拡張子の棚卸しも行うこと。
+#
+# 導入先は USER に依存させず明示する (HOME 由来だと RUN の位置を動かしただけで
+# /root/.local/bin へ落ち、hook が「未導入」と告知するようになるため)。
+ENV UV_TOOL_DIR=/home/vscode/.local/share/uv/tools
+ENV UV_TOOL_BIN_DIR=/home/vscode/.local/bin
+RUN uv tool install code-review-graph==2.3.7
+
+# 導入先を検索パスへ載せる (hook 側は導入先を知らない = 環境固有の知識を持たせない)
+ENV PATH="/home/vscode/.local/bin:$PATH"
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index d8c97c5..9d71920 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -761,3 +761,51 @@ ### 関連
   `tests/Architecture/RetiredRecoveryReferenceGateTest.php`
 - 設計: `devnotes/20260815-1538-stuck-job-recovery/`
 - 契約の正本: `docs/architecture.md` §滞留回収の共通基盤
+
+---
+
+## D18 ✅ hook の起動子を「起動先の検証 + 終了コードの写像器」にする
+
+常設 hook 配線 (家系の feature `claude-hooks-wiring`) を取り込むにあたり、**起動子の形だけ**
+テンプレートと変えた。配線されている hook の本数・対象・スクリプトの置き場所は正典どおりである。
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 起動子 | `/bin/bash "$CLAUDE_PROJECT_DIR/scripts/…"` (スクリプトを直に起動) | `/bin/bash -p -c '…'` で起動先を検証してから起動し、終了コードを写像する |
+| hook の終了コードの扱い | スクリプトの終了コードがそのまま harness へ届く | PreToolUse は **97 だけ**を 2 (ブロック) へ写し、それ以外はすべて 0 に畳む |
+| 環境からのシェル関数 | 内側へ継承される | `-p` (privileged mode) で遮断する |
+
+### なぜ正当な差分か (logic-driven)
+
+1. **hook の故障がセッションを止めてはならない**。bash は構文エラーでも 2 を返し、
+   PreToolUse の 2 は Bash ツールをブロックする。テンプレートの形では hook スクリプトの
+   1 文字のタイプミスが、そのセッションの Bash 操作を全滅させうる。
+   写像器を**設定ファイル側**に置くと、スクリプトの退行から独立して「拒否できるのは
+   意図した 97 だけ」を保てる。
+2. **起動先の検証は起動子にしか置けない**。`CLAUDE_PROJECT_DIR` が相対値・`..` 入り・
+   `scripts/` が symlink・起動先が symlink のいずれかなら、内側を起動しないのが正しい。
+   これはスクリプトが起動された後では手遅れである。
+3. **シェル関数の注入**は、判定を組み込みだけで書いても環境から乗っ取れる。
+   遮断は起動の瞬間 (`-p`) にしかできない。
+
+検査はすべて bash の組み込み (`[` / パラメータ展開) で行い、外部コマンドを 1 つも使わない。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「配線は常設で、起動子は絶対パスで、排他はスクリプト内にあり、配線は台帳テストで
+> 完全一致 pin される」
+
+1. `.claude/settings.json` は git 追跡下の配線の正本で、見本ファイル方式は復活させない
+   (`ClaudeHooksWiringTest` の S02 / S08)
+2. 起動子は `/bin/bash` の絶対パスで始まる (S06b)
+3. 索引更新の排他は hook スクリプト内の `flock` が持つ (B16 / B17)
+4. hook 種別 / matcher / 起動コマンド文字列 / timeout / トップレベルキーを完全一致で pin する
+   (S03〜S06)。97 → 2 の写像そのものも実起動で固定する (B41〜B50)
+
+### 関連
+
+- 実装: `.claude/settings.json` / `scripts/bughunt-worktree-hook.sh` /
+  `scripts/code-review-graph-update-hook.sh`
+- gate: `tests/Architecture/ClaudeHooksWiringTest.php`
+- 設計: `devnotes/20260815-1539-claude-hooks-settings-wiring/`
+- 規約の正本: `AGENTS.md` §常設 hook 配線
diff --git a/scripts/README.md b/scripts/README.md
index 807db19..125a266 100644
--- a/scripts/README.md
+++ b/scripts/README.md
@@ -33,7 +33,8 @@ ## スクリプト一覧
 | `run-browser-test.sh` | Browser テスト (pest-plugin-browser) を**グローバルテストロック配下**で並列上限付きで実行。**Chromium / WebKit の 2 レーンが契約** (bfcache 復元シナリオは WebKit レーンが正本)。残留 playwright run-server を前後で掃除する (`@playwright/` = bug-hunt 側は除外)。起動時に bughunt ポート `:8010..8018` の best-effort pre-flight guard を掛ける (cap=4 より広く取るのは残留検出のため) | `composer test:browser` 等から呼び出し。レーン限定は `BROWSER_TEST_LANES` / 並列度は `BROWSER_TEST_PROCESSES` |
 | `bug-hunt-shard.sh` | bug-hunt シャードオーケストレータ。隔離環境 (DB `bug_hunt(_N)` / `:8010+N`) の provision / serve / teardown と、**dev DB を wipe しないための用途別 DB wrapper + 3-way hard-deny guard** を提供する (AGENTS.md §bug-hunt) | `/app-bug-hunt` から。`self-test` は実資源に触れず guard を検証 |
 | `bug-hunt-inventory-check.sh` | bug-hunt インベントリのドリフト検知。`route:list` と `.claude/skills/app-bug-hunt/{screens,operations}.md` の差分 (新ルート未追記 / 消失) を出す (exit 3 = 差分あり) | route 追加・削除時 / bug-hunt 実行前 |
-| `bughunt-worktree-hook.sh` | PreToolUse(Bash) ガード。`bug-hunt-shard.sh provision` の **main 直叩き** (worktree 指紋なし) を harness 層で拒否する | `.claude/settings.json` の hook として配線 (`.claude/settings.bughunt-hook.example.json` をマージ) |
+| `bughunt-worktree-hook.sh` | PreToolUse(Bash) ガード。`bug-hunt-shard.sh provision` の **main 直叩き** (worktree 指紋なし) を harness 層で拒否する (拒否は終了コード 97。起動子が 97 だけを 2 へ写す)。判定は bash の組み込みだけで完結し、外部コマンドを 1 つも使わない | `.claude/settings.json` に常設配線 (AGENTS.md §常設 hook 配線) |
+| `code-review-graph-update-hook.sh` | PostToolUse(Write/Edit) hook。コード索引 (code-review-graph) を `flock` 排他 + 内側 20 秒の時間切れ付きで差分更新する。何が起きても終了コード 0 で終わり、標準出力は常に空。告知はセッションごと・理由ごとに標準エラー 1 行だけ | `.claude/settings.json` に常設配線 (AGENTS.md §常設 hook 配線) |
 | `claude` | Claude Code を VSCode 拡張のネイティブバイナリ経由で起動 | (内部スクリプト) |
 | `claude-account` | Claude Code のログインアカウントのプロファイル保存・切替・一覧 (Python 3 標準ライブラリのみ)。`(claudeAiOauth, oauthAccount)` のペアを `~/.claude/account-profiles/` に 0600 でスナップショットし、`switch` で書き戻す (切替直前に現アカウントを再スナップショットするのでトークン回転で失効しない)。`add` は使い捨ての `CLAUDE_CONFIG_DIR` で claude を起動し、現ログイン・起動中セッション無影響で別アカウントを登録する。**本リポジトリは `claude-statusline` を持たないため `autosave` の自動呼び出しは効かない** — 登録は `save` / `add` で手動に行う | 人間が実行 (`scripts/claude-account switch` 等) / `switch-account` スキルから |
 | `codex` | Codex CLI を VSCode 拡張のネイティブバイナリ経由で起動。`app-codex-review` / `app-codex-vscode` スキルの呼び出しラッパを兼ねる | スキルから自動呼び出し / 直接起動 |
diff --git a/scripts/bughunt-worktree-hook.sh b/scripts/bughunt-worktree-hook.sh
index 9270319..2e6c2b9 100755
--- a/scripts/bughunt-worktree-hook.sh
+++ b/scripts/bughunt-worktree-hook.sh
@@ -13,31 +13,87 @@
 #   - worktree パス (.claude/worktrees/) … 正しい Phase 0a フロー
 #   - 明示オーバーライド (BUGHUNT_ALLOW_MAIN=) … 意図的 main 走行 (--keep-db 連続再走等)
 #   - self-test dryrun (BUGHUNT_SELFTEST_DRYRUN=) … 自己検証
-# のいずれの指紋も無いものを「main 直叩きの疑い」として拒否 (exit 2 + stderr=拒否理由)。
-set -euo pipefail
-
-input="$(cat)"
-cmd="$(printf '%s' "${input}" | python3 -c 'import sys,json
-try: print(json.load(sys.stdin).get("tool_input",{}).get("command",""))
-except Exception: print("")' 2>/dev/null || true)"
-
-# 対象は bug-hunt-shard.sh の provision / provision-all の**実行**のみ (subcommand は第1引数固定)。
-# `bug-hunt-shard.sh<空白>provision` に限定する = コミットメッセージ等の**文字列言及**
-# ("bug-hunt-shard.sh scaffold ... provision" のように間に別語が入る形) では誤発火しない。
-printf '%s' "${cmd}" | grep -qE 'bug-hunt-shard\.sh[[:space:]]+provision' || exit 0
-
-# 許可シグナルがあれば通す
-if printf '%s' "${cmd}" | grep -qE '\.claude/worktrees/|BUGHUNT_ALLOW_MAIN=|BUGHUNT_SELFTEST_DRYRUN='; then
-    exit 0
+# のいずれの指紋も無いものを「main 直叩きの疑い」として拒否する。
+#
+# 判定は bash の組み込みだけで完結させる (外部コマンドを 1 つも起動しない)。
+# 以前は cat / python3 / grep に依存しており、検索パスからそれらを解決できない環境では
+# いずれも 127 で終わって拒否対象が黙って通っていた (無音の素通り)。
+
+# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---
+# set -e は使わない: 途中の失敗で暗黙に終了すると「常に 0 で終わる」契約を守れない。
+set -uo pipefail
+export LC_ALL=C
+_hook_sanitize_path() {
+    local element out=''
+    local -a elements=()
+    IFS=':' read -r -a elements <<< "${PATH-}"
+    for element in ${elements[@]+"${elements[@]}"}; do
+        # 絶対パスでない要素 (空要素・"." ・相対パス) を落とす
+        case "${element}" in
+            /*) ;;
+            *) continue ;;
+        esac
+        # 正規化前の別表記も落とす (//, /./, /../, 末尾の /. と /..)
+        case "${element}" in
+            *//*|*/./*|*/../*|*/.|*/..) continue ;;
+        esac
+        out="${out:+${out}:}${element}"
+    done
+    # 空の PATH はカレントディレクトリと解釈されうるので、最小のシステムパスに倒す
+    PATH="${out:-/usr/local/bin:/usr/bin:/bin}"
+    export PATH
+}
+_hook_sanitize_path
+# ---8< /SHARED_PATH_PROLOGUE >8---
+
+# 拒否は終了コード 97 で表す。.claude/settings.json の起動子が 97 だけを 2 へ写像するため、
+# 構文エラー (2) や実行不能 (126/127) が Bash ツールをブロックすることは無い。
+readonly DENY_EXIT_CODE=97
+
+# 標準入力は 1 回だけ読む。最大 1 MiB / 最大 5 秒 (閉じない相手に待ち続けない)。
+input=''
+IFS= read -r -N 1048576 -t 5 input || true
+
+# 段 0: 対象語が無ければ外部コマンドを 1 つも起こさずに通す (無関係なコマンドは構造的に無影響)
+case "${input}" in
+    *bug-hunt-shard.sh*) ;;
+    *) exit 0 ;;
+esac
+
+# 段 1: tool_input.command を取り出す (JSON エスケープは我々が探すバイト列を増やす方向にしか働かない)
+command_text=''
+extracted=0
+if [[ "${input}" =~ \"command\"[[:space:]]*:[[:space:]]*\"((\\.|[^\"\\])*)\" ]]; then
+    command_text="${BASH_REMATCH[1]}"
+    extracted=1
+fi
+
+# 段 2: 判定
+#  - 抽出できた: 抽出値だけで判定する (許可シグナル 2 種とも有効)
+#  - 抽出できない: 明示解除 BUGHUNT_ALLOW_MAIN= だけを生入力で見る
+#    (痕跡 .claude/worktrees/ は偶然そこにあり得るので抽出失敗時は評価しない)
+if [ "${extracted}" -eq 1 ]; then
+    subject="${command_text}"
+    allow_regex='(\.claude\\?/worktrees\\?/|BUGHUNT_ALLOW_MAIN=|BUGHUNT_SELFTEST_DRYRUN=)'
+else
+    subject="${input}"
+    allow_regex='BUGHUNT_ALLOW_MAIN='
 fi
 
-# それ以外 = worktree 外からの直叩きの疑い → 拒否
-cat >&2 <<'MSG'
-⛔ bug-hunt provision を worktree 外から直叩きしようとしています (skill app-bug-hunt の Phase 0a スキップ)。
-bug-hunt は worktree から走るのが既定です (main を直接汚さず todo/ ブランチに隔離するため)。次のいずれかで起動してください:
-  1) /app-bug-hunt 経由 (推奨。Phase 0a が worktree を自動で切る)
-  2) scripts/setup-worktree.sh bughunt-<task-id> で worktree を切り、その worktree 内
-     (cd .claude/worktrees/tasks/bughunt-<task-id>) から本スクリプトを実行
-  3) 意図的な main 走行 (--keep-db 連続再走など asset 既存の単発確認) のみ コマンド先頭に BUGHUNT_ALLOW_MAIN=1 を付ける
-MSG
-exit 2
+# 実行の検出は「bug-hunt-shard.sh の直後の空白 + provision」に限る
+# (コミットメッセージ等の文字列言及では誤発火しない)。JSON の \n \t \r 表記も空白として受ける。
+[[ "${subject}" =~ bug-hunt-shard\.sh([[:space:]]|\\[nrt])+provision ]] || exit 0
+[[ "${subject}" =~ ${allow_regex} ]] && exit 0
+
+# 拒否メッセージも組み込みで出す (ヒアドキュメント + cat を使わない)。
+# これで**このスクリプトは外部コマンドを 1 つも使わない**ことになり、
+# 検索パスがどれだけ壊れていても挙動が変わらない。
+printf '%s\n' \
+    '⛔ bug-hunt provision を worktree 外から直叩きしようとしています (skill app-bug-hunt の Phase 0a スキップ)。' \
+    'bug-hunt は worktree から走るのが既定です (main を直接汚さず todo/ ブランチに隔離するため)。次のいずれかで起動してください:' \
+    '  1) /app-bug-hunt 経由 (推奨。Phase 0a が worktree を自動で切る)' \
+    '  2) scripts/setup-worktree.sh bughunt-<task-id> で worktree を切り、その worktree 内' \
+    '     (cd .claude/worktrees/tasks/bughunt-<task-id>) から本スクリプトを実行' \
+    '  3) 意図的な main 走行 (--keep-db 連続再走など asset 既存の単発確認) のみ コマンド先頭に BUGHUNT_ALLOW_MAIN=1 を付ける' \
+    >&2
+exit "${DENY_EXIT_CODE}"
diff --git a/scripts/code-review-graph-update-hook.sh b/scripts/code-review-graph-update-hook.sh
new file mode 100644
index 0000000..2b888eb
--- /dev/null
+++ b/scripts/code-review-graph-update-hook.sh
@@ -0,0 +1,150 @@
+#!/usr/bin/env bash
+# PostToolUse(Write|Edit) — コード索引 (code-review-graph) の差分更新。
+#
+# 実行契約 (tests/Architecture/ClaudeHooksWiringTest.php が実挙動で固定する):
+#  1. 何が起きても終了コード 0 で終わる (編集作業を止めない)
+#  2. 標準出力は常に空
+#  3. 告知は標準エラーに 1 行だけ。セッションごと・理由ごとに 1 回だけ
+#  4. 更新は必ず flock で排他する。安全に排他できない環境では更新しない
+#  5. 呼び出し側の時間切れ (30 秒) より内側 (20 秒) で自分から諦める
+#  6. 作業ディレクトリと環境変数に依存しない (リポジトリルートは自分の位置から解決する)
+#  7. 最初の外部コマンド呼び出しより前に検索パスを安全化する
+#  8. 置き場・ロック・告知フラグが symlink なら何も書かずに終える
+#  9. 索引の対象外の拡張子では更新を起動しない (副作用ゼロ)
+#
+# 索引ツール自身の install / uninstall は実行しないこと (配線の正本が二重化する。AGENTS.md)。
+
+# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---
+# set -e は使わない: 途中の失敗で暗黙に終了すると「常に 0 で終わる」契約を守れない。
+set -uo pipefail
+export LC_ALL=C
+_hook_sanitize_path() {
+    local element out=''
+    local -a elements=()
+    IFS=':' read -r -a elements <<< "${PATH-}"
+    for element in ${elements[@]+"${elements[@]}"}; do
+        # 絶対パスでない要素 (空要素・"." ・相対パス) を落とす
+        case "${element}" in
+            /*) ;;
+            *) continue ;;
+        esac
+        # 正規化前の別表記も落とす (//, /./, /../, 末尾の /. と /..)
+        case "${element}" in
+            *//*|*/./*|*/../*|*/.|*/..) continue ;;
+        esac
+        out="${out:+${out}:}${element}"
+    done
+    # 空の PATH はカレントディレクトリと解釈されうるので、最小のシステムパスに倒す
+    PATH="${out:-/usr/local/bin:/usr/bin:/bin}"
+    export PATH
+}
+_hook_sanitize_path
+# ---8< /SHARED_PATH_PROLOGUE >8---
+
+# 呼び出し側 (.claude/settings.json) の 30 秒より内側で自分から諦める
+readonly INNER_TIMEOUT_SECONDS=20
+# 索引の対象外の拡張子 (台帳テストが完全一致で固定する。索引ツール更新時は棚卸しすること)
+readonly SKIP_EXTENSIONS='md txt json yaml yml lock log'
+
+state_dir=''
+session_id='unknown'
+
+# 告知: 標準エラーに 1 行だけ。セッションごと・理由ごとに 1 回だけ。
+# 目印ファイルの作成は noclobber (O_CREAT|O_EXCL) なので、
+#  - 既にあれば作成に失敗する = 重複抑止そのもの (読み書きの競合が起きない)
+#  - 目印が symlink でも作成に失敗する = 検査と作成が原子的 (TOCTOU が無い)
+emit_warning() {
+    local reason="$1" message="$2" flag
+    flag="${state_dir}/warned-${reason}-${session_id}"
+    ( set -C; : > "${flag}" ) 2> /dev/null || return 0
+    printf 'code-review-graph: %s\n' "${message}" >&2
+    return 0
+}
+
+# --- 段 1: 標準入力を 1 回だけ読む (最大 1 MiB / 最大 5 秒) -------------------
+input=''
+IFS= read -r -N 1048576 -t 5 input || true
+
+# --- 段 2: 対象外拡張子なら副作用ゼロで終わる --------------------------------
+file_path=''
+if [[ "${input}" =~ \"file_path\"[[:space:]]*:[[:space:]]*\"([^\"]*)\" ]]; then
+    file_path="${BASH_REMATCH[1]}"
+fi
+# 拡張子は untrusted な値なので、パターン側には置かず**文字列等価**で比べる
+# (`*` や `[` を含む拡張子でパターンとして解釈されるのを防ぐ)。
+case "${file_path}" in
+    *.*)
+        extension="${file_path##*.}"
+        extension="${extension,,}"
+        for skip in ${SKIP_EXTENSIONS}; do
+            [ "${extension}" = "${skip}" ] && exit 0
+        done
+        ;;
+esac
+
+# --- 段 3: セッション識別子 (純粋な文字列処理。副作用が無いのでここで済ませる) ---
+if [[ "${input}" =~ \"session_id\"[[:space:]]*:[[:space:]]*\"([A-Za-z0-9._-]{1,64})\" ]]; then
+    case "${BASH_REMATCH[1]}" in
+        .|..) ;;
+        *) session_id="${BASH_REMATCH[1]}" ;;
+    esac
+fi
+
+# --- 段 4: リポジトリルートを自分の位置から解決する ---------------------------
+script_path="${BASH_SOURCE[0]}"
+script_dir="${script_path%/*}"
+[ "${script_dir}" = "${script_path}" ] && script_dir='.'
+repo_root="$(cd -- "${script_dir}/.." > /dev/null 2>&1 && pwd -P)" || exit 0
+[ -n "${repo_root}" ] || exit 0
+
+# --- 段 5: 置き場の symlink 検査 → 作成 → 再検査 ------------------------------
+claude_dir="${repo_root}/.claude"
+state_dir="${claude_dir}/code-review-graph-update-hook"
+[ -L "${claude_dir}" ] && exit 0
+[ -L "${state_dir}" ] && exit 0
+mkdir -p "${state_dir}" 2>/dev/null || exit 0
+[ -L "${claude_dir}" ] && exit 0
+[ -L "${state_dir}" ] && exit 0
+[ -d "${state_dir}" ] || exit 0
+
+# --- 段 6: 排他 (非ブロッキング。取れなければ黙って終わる) --------------------
+# ロックは flock で取る (プロセスが落ちても解放されるため。ディレクトリロックは
+# 落ちたときに解放されず索引更新が恒久的に止まるので採らない)。
+# 帰結として、ロックファイルの差し替え (TOCTOU) までは防がない = 保証範囲を下げてある。
+lock_file="${state_dir}/update.lock"
+[ -L "${lock_file}" ] && exit 0
+if ! command -v flock > /dev/null 2>&1; then
+    emit_warning 'no-flock' 'flock が無いため索引を更新しません (排他できない環境では更新しない契約です)'
+    exit 0
+fi
+# ★ `exec 9> file 2>/dev/null` と書いてはいけない: コマンドを伴わない exec の
+#   リダイレクトは**シェル全体へ永続適用**され、以降の告知 (契約 3) が消える。
+#   波括弧のグループなら fd 9 だけが残り、標準エラーの差し替えはグループの外で戻る。
+{ exec 9> "${lock_file}"; } 2> /dev/null || exit 0
+flock -n 9 || exit 0
+
+# --- 段 7: 前提コマンドの在否 ------------------------------------------------
+if ! command -v code-review-graph > /dev/null 2>&1; then
+    emit_warning 'tool-missing' \
+        'コード索引ツールが未導入です (uv tool install code-review-graph==2.3.7 → code-review-graph build)'
+    exit 0
+fi
+if ! command -v timeout > /dev/null 2>&1; then
+    emit_warning 'no-timeout' 'timeout が無いため索引を更新しません (時間切れを保証できないためです)'
+    exit 0
+fi
+
+# --- 段 8: 差分更新 ----------------------------------------------------------
+timeout -k 5 "${INNER_TIMEOUT_SECONDS}" \
+    code-review-graph update -q --repo "${repo_root}" > /dev/null 2>&1
+status=$?
+case "${status}" in
+    0) ;;
+    124|137) emit_warning 'update-timeout' \
+        "索引の差分更新が ${INNER_TIMEOUT_SECONDS} 秒で終わらなかったため中断しました" ;;
+    *) emit_warning 'update-failed' \
+        "索引の差分更新に失敗しました (終了コード ${status}。code-review-graph build を試してください)" ;;
+esac
+
+# --- 段 9: 常に成功で終わる --------------------------------------------------
+exit 0
diff --git a/tests/Architecture/ClaudeHooksWiringTest.php b/tests/Architecture/ClaudeHooksWiringTest.php
new file mode 100644
index 0000000..3d69273
--- /dev/null
+++ b/tests/Architecture/ClaudeHooksWiringTest.php
@@ -0,0 +1,1227 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\Facades\File;
+use Illuminate\Support\Facades\Process;
+use Webmozart\Assert\Assert;
+
+/*
+ * 常設 hook 配線の台帳 (deny-by-default) と、hook スクリプトの実挙動ゲート。
+ *
+ * 本テストは 2 層で構成する:
+ *  - 静的層 (S01〜S12b): `.claude/settings.json` が下の台帳と**完全一致**することを見る。
+ *    台帳に無い hook・イベント・トップレベルキーはすべて違反 = 配線の正本が 1 か所になる。
+ *  - 実起動層 (B01〜B51): hook スクリプトと起動子を**別プロセスで本当に起動**して、
+ *    終了コード・標準出力の空・告知の回数・排他・敵対的な検索パス・symlink の置き場での
+ *    振る舞いを実証する。静的検査だけでは「書いてあるが効いていない」を検出できない。
+ *
+ * 本テストは DB を触らない (ファイル読み取りと別プロセス起動のみ)。
+ * 関数名を `claudeHooks` 接頭辞で始めるのは、Pest が全テストを 1 プロセスへ読み込むため
+ * 素の名前が他の Architecture テストと衝突するからである。
+ */
+
+/** 設定ファイルのトップレベルに置いてよいキー (全数申告制)。 */
+const CLAUDE_HOOKS_TOP_LEVEL_KEYS = ['hooks'];
+
+/**
+ * 配線台帳。ここに書かれた形と `.claude/settings.json` が完全一致しなければ落ちる。
+ *
+ * `matcher` の `Write|Edit` は **`Write` と `Edit` のときだけ**発火する。
+ * 部分一致で将来の派生ツールを自動で拾うとは書かない (書くと嘘になる)。
+ *
+ * @var array<string, list<array{matcher: string, script: string, timeout: int, deny_exit_code: int|null}>>
+ */
+const CLAUDE_HOOKS_WIRING = [
+    'PreToolUse' => [
+        [
+            'matcher' => 'Bash',
+            'script' => 'scripts/bughunt-worktree-hook.sh',
+            'timeout' => 10,
+            'deny_exit_code' => 97,
+        ],
+    ],
+    'PostToolUse' => [
+        [
+            'matcher' => 'Write|Edit',
+            'script' => 'scripts/code-review-graph-update-hook.sh',
+            'timeout' => 30,
+            'deny_exit_code' => null,
+        ],
+    ],
+];
+
+/**
+ * 索引の対象外拡張子。`scripts/code-review-graph-update-hook.sh` の `SKIP_EXTENSIONS` と
+ * 完全一致すること (索引ツールを更新したらここも棚卸しする)。
+ *
+ * @var list<string>
+ */
+const CLAUDE_HOOKS_SKIP_EXTENSIONS = ['md', 'txt', 'json', 'yaml', 'yml', 'lock', 'log'];
+
+/** 検索パス安全化ブロックの開始・終了マーカー (2 本の hook で byte 一致する)。 */
+const CLAUDE_HOOKS_PROLOGUE_BEGIN = '# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---';
+const CLAUDE_HOOKS_PROLOGUE_END = '# ---8< /SHARED_PATH_PROLOGUE >8---';
+
+/**
+ * S12b の走査対象 (実行面のファイルのみ)。文書は走査しない —
+ * 禁止を説明する文章にコマンド名が出るのは正常であり、走査すると必ず落ちるためである。
+ *
+ * @var list<string>
+ */
+const CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS = [
+    'scripts/*.sh',
+    'scripts/*/*.sh',
+    '.claude/settings*.json',
+    'docker/Dockerfile',
+    'composer.json',
+    'package.json',
+    '.github/workflows/*',
+];
+
+// =============================================================================
+// ヘルパ (静的層)
+// =============================================================================
+
+/** ファイルを読む (読めなければ明示 fail し string へ narrow する)。 */
+function claudeHooksReadFile(string $path): string
+{
+    Assert::fileExists($path);
+    $contents = file_get_contents($path);
+    Assert::string($contents, "読み込めません: {$path}");
+
+    return $contents;
+}
+
+/**
+ * `.claude/settings.json` を配列として読む。
+ *
+ * @return array<string, mixed>
+ */
+function claudeHooksSettings(): array
+{
+    $decoded = json_decode(claudeHooksReadFile(base_path('.claude/settings.json')), true);
+    Assert::isArray($decoded, '.claude/settings.json が JSON オブジェクトではない');
+
+    /** @var array<string, mixed> $decoded */
+    return $decoded;
+}
+
+/**
+ * 起動子の文字列を台帳側で組み立てる (設定を書き換えたら必ずここと食い違って落ちる)。
+ *
+ * 起動子が持つ 3 つの役割:
+ *  1. 起動先の検証 (絶対パス / `..` を含まない / `scripts` が symlink でない実ディレクトリ /
+ *     起動先が symlink でない通常ファイル)。1 つでも欠ければ内側を起動しない
+ *  2. 終了コードの写像 (PreToolUse は 97 だけを 2 へ写す。それ以外はすべて 0)
+ *  3. 環境からのシェル関数の遮断 (`-p` = privileged mode)
+ */
+function claudeHooksExpectedCommand(string $script, ?int $denyExitCode): string
+{
+    $conditions = '[ -n "$d" ] && [ "${d#/}" != "$d" ] && [ "${d//../}" = "$d" ]'
+        .' && [ -d "$d/scripts" ] && [ ! -L "$d/scripts" ] && [ -f "$f" ] && [ ! -L "$f" ]';
+
+    $inner = 'd=${CLAUDE_PROJECT_DIR:-}; f=$d/'.$script.'; ';
+    $inner .= $denyExitCode === null
+        ? 'if '.$conditions.'; then /bin/bash -p "$f"; fi; exit 0'
+        : 's=0; if '.$conditions.'; then /bin/bash -p "$f"; s=$?; fi; '
+            .'if [ "$s" = '.$denyExitCode.' ]; then exit 2; fi; exit 0';
+
+    return "/bin/bash -p -c '".$inner."'";
+}
+
+/**
+ * 検索パス安全化ブロックを取り出す。マーカーが 1 組でなければ違反として文字列を返す。
+ *
+ * shell parser は作らない。見るのは (1) マーカーが 1 組 (2) ブロックの byte 列
+ * (3) 開始マーカーより前が shebang・コメント・空行だけ、の 3 点だけである。
+ *
+ * @return array{block: string, violations: list<string>}
+ */
+function claudeHooksPrologueBlock(string $contents, string $label): array
+{
+    $violations = [];
+
+    $beginCount = substr_count($contents, CLAUDE_HOOKS_PROLOGUE_BEGIN);
+    $endCount = substr_count($contents, CLAUDE_HOOKS_PROLOGUE_END);
+    if ($beginCount !== 1 || $endCount !== 1) {
+        return [
+            'block' => '',
+            'violations' => ["{$label}: 検索パス安全化ブロックのマーカーが 1 組でない (begin={$beginCount} end={$endCount})"],
+        ];
+    }
+
+    $begin = strpos($contents, CLAUDE_HOOKS_PROLOGUE_BEGIN);
+    $end = strpos($contents, CLAUDE_HOOKS_PROLOGUE_END);
+    Assert::integer($begin);
+    Assert::integer($end);
+    if ($end < $begin) {
+        return ['block' => '', 'violations' => ["{$label}: 終了マーカーが開始マーカーより前にある"]];
+    }
+
+    $block = substr($contents, $begin, $end - $begin + strlen(CLAUDE_HOOKS_PROLOGUE_END));
+
+    // 開始マーカーより前は shebang・コメント・空行だけであること
+    // (= 最初の外部コマンド呼び出しより前にプロローグがある、が自動的に成立する)
+    foreach (preg_split('/\r\n|\r|\n/', substr($contents, 0, $begin)) ?: [] as $index => $line) {
+        $trimmed = trim($line);
+        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
+            continue;
+        }
+        $violations[] = "{$label}: 検索パス安全化ブロックより前に実行される行がある (".($index + 1)." 行目: {$trimmed})";
+    }
+
+    return ['block' => $block, 'violations' => $violations];
+}
+
+// =============================================================================
+// ヘルパ (実起動層)
+// =============================================================================
+
+/** 実起動層で必要な外部コマンドの絶対パスを解決する。 */
+function claudeHooksResolveExecutable(string $name): string
+{
+    foreach (['/usr/local/bin/', '/usr/bin/', '/bin/'] as $dir) {
+        if (is_executable($dir.$name)) {
+            return $dir.$name;
+        }
+    }
+
+    throw new RuntimeException("実起動層に必要な外部コマンドが見つかりません: {$name}");
+}
+
+/**
+ * sandbox を作る。実スクリプトを `$sandbox/scripts/` へ複製するので、
+ * `BASH_SOURCE` から解決されるリポジトリルートは sandbox 側になり本物を汚さない。
+ *
+ * 検索パスは**システムディレクトリを一切含めない**。必要な外部コマンド
+ * (`mkdir` / `flock` / `timeout` / `sleep`) だけを sandbox の bin へ symlink するので、
+ * 「索引ツールが未導入」を実行環境に左右されずに作れる。
+ */
+function claudeHooksSandbox(): string
+{
+    $sandbox = sys_get_temp_dir().'/claude-hooks-'.bin2hex(random_bytes(8));
+    File::makeDirectory($sandbox.'/scripts', 0700, true);
+
+    foreach (CLAUDE_HOOKS_WIRING as $entries) {
+        foreach ($entries as $entry) {
+            File::copy(base_path($entry['script']), $sandbox.'/'.$entry['script']);
+        }
+    }
+
+    // 3 種類の bin を用意する (索引ツールの有無 / timeout の有無を作り分ける)
+    foreach (['bin', 'bin-notool', 'bin-notimeout'] as $binDir) {
+        File::makeDirectory($sandbox.'/'.$binDir, 0700, true);
+        foreach (['mkdir', 'flock', 'sleep'] as $name) {
+            symlink(claudeHooksResolveExecutable($name), $sandbox.'/'.$binDir.'/'.$name);
+        }
+    }
+    foreach (['bin', 'bin-notool'] as $binDir) {
+        symlink(claudeHooksResolveExecutable('timeout'), $sandbox.'/'.$binDir.'/timeout');
+    }
+
+    return $sandbox;
+}
+
+/** 索引ツールの stub を置く (起動された事実と引数を `invoked.txt` へ追記する)。 */
+function claudeHooksInstallToolStub(string $sandbox, string $tail = "exit 0\n"): void
+{
+    foreach (['bin', 'bin-notimeout'] as $binDir) {
+        $path = $sandbox.'/'.$binDir.'/code-review-graph';
+        File::put($path, "#!/bin/bash\nprintf '%s\\n' \"\$*\" >> '{$sandbox}/invoked.txt'\n".$tail);
+        chmod($path, 0700);
+    }
+}
+
+/** 索引ツールが解決できる検索パス。 */
+function claudeHooksPathWithTool(string $sandbox): string
+{
+    return $sandbox.'/bin';
+}
+
+/** 索引ツールだけが解決できない検索パス (「未導入」の再現)。 */
+function claudeHooksPathWithoutTool(string $sandbox): string
+{
+    return $sandbox.'/bin-notool';
+}
+
+/** `timeout` だけが解決できない検索パス。 */
+function claudeHooksPathWithoutTimeout(string $sandbox): string
+{
+    return $sandbox.'/bin-notimeout';
+}
+
+/**
+ * 別プロセスで走らせて結果をそろえて返す。
+ *
+ * @param  list<string>  $command
+ * @return array{exitCode: int, output: string, errorOutput: string, elapsed: float}
+ */
+function claudeHooksRun(array $command, string $input = '', ?string $cwd = null, int $timeout = 90): array
+{
+    $pending = Process::timeout($timeout)->input($input);
+    if ($cwd !== null) {
+        $pending = $pending->path($cwd);
+    }
+
+    $startedAt = microtime(true);
+    $result = $pending->run($command);
+    $elapsed = microtime(true) - $startedAt;
+
+    $exitCode = $result->exitCode();
+    Assert::integer($exitCode, '子プロセスの終了コードが取れない');
+
+    return [
+        'exitCode' => $exitCode,
+        'output' => $result->output(),
+        'errorOutput' => $result->errorOutput(),
+        'elapsed' => $elapsed,
+    ];
+}
+
+/**
+ * 索引更新 hook を sandbox 内で起動する (環境は `env -i` で完全に作り直す)。
+ *
+ * @return array{exitCode: int, output: string, errorOutput: string, elapsed: float}
+ */
+function claudeHooksRunUpdateHook(string $sandbox, string $input, ?string $path = null, ?string $cwd = null): array
+{
+    return claudeHooksRun([
+        '/usr/bin/env', '-i', 'PATH='.($path ?? claudeHooksPathWithTool($sandbox)),
+        '/bin/bash', $sandbox.'/scripts/code-review-graph-update-hook.sh',
+    ], $input, $cwd);
+}
+
+/**
+ * bug-hunt ガードを sandbox 内で起動する。
+ *
+ * @return array{exitCode: int, output: string, errorOutput: string, elapsed: float}
+ */
+function claudeHooksRunBughuntHook(string $sandbox, string $input, ?string $path = null, ?string $cwd = null): array
+{
+    return claudeHooksRun([
+        '/usr/bin/env', '-i', 'PATH='.($path ?? claudeHooksPathWithTool($sandbox)),
+        '/bin/bash', $sandbox.'/scripts/bughunt-worktree-hook.sh',
+    ], $input, $cwd);
+}
+
+/** 索引ツールの stub が起動された回数。 */
+function claudeHooksInvocations(string $sandbox): int
+{
+    if (! is_file($sandbox.'/invoked.txt')) {
+        return 0;
+    }
+
+    return count(array_filter(explode("\n", claudeHooksReadFile($sandbox.'/invoked.txt'))));
+}
+
+/** 告知の行数 (標準エラーの非空行)。 */
+function claudeHooksWarningLines(string $stderr): int
+{
+    return count(array_filter(array_map(trim(...), explode("\n", $stderr)), fn (string $l): bool => $l !== ''));
+}
+
+/** PostToolUse の入力 payload。 */
+function claudeHooksWritePayload(string $filePath, string $sessionId = 'sess-a'): string
+{
+    return json_encode([
+        'session_id' => $sessionId,
+        'tool_name' => 'Write',
+        'tool_input' => ['file_path' => $filePath],
+    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
+}
+
+/**
+ * PreToolUse の入力 payload。
+ *
+ * `$escapeSlashes` を真にすると `/` を `\/` へ逃がす (JSON として正当な別表記)。
+ * 許可シグナルの照合がこの表記でも取りこぼさないことを実証するために使う。
+ */
+function claudeHooksBashPayload(string $command, string $description = 'x', bool $escapeSlashes = false): string
+{
+    $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;
+    if (! $escapeSlashes) {
+        $flags |= JSON_UNESCAPED_SLASHES;
+    }
+
+    return json_encode([
+        'session_id' => 'sess-a',
+        'tool_name' => 'Bash',
+        'tool_input' => ['command' => $command, 'description' => $description],
+    ], $flags);
+}
+
+/**
+ * 「含むこと」を理由付きで検査する。
+ *
+ * Pest の `toContain()` は可変長引数なので、第 2 引数を理由として渡すと
+ * **もう 1 つの検索語**として扱われて必ず落ちる。理由を残したい箇所はこちらを使う。
+ */
+function claudeHooksExpectContains(string $haystack, string $needle, string $reason): void
+{
+    expect(str_contains($haystack, $needle))->toBeTrue("{$reason} (期待する文字列: {$needle})");
+}
+
+/** 「含まないこと」を理由付きで検査する。 */
+function claudeHooksExpectNotContains(string $haystack, string $needle, string $reason): void
+{
+    expect(str_contains($haystack, $needle))->toBeFalse("{$reason} (現れてはならない文字列: {$needle})");
+}
+
+/** 台帳から起動子の実文字列を取り出す (台帳の写しではなく本物を走らせるため)。 */
+function claudeHooksLauncherCommand(string $event): string
+{
+    $settings = claudeHooksSettings();
+    Assert::isArray($settings['hooks']);
+    Assert::keyExists($settings['hooks'], $event);
+    $group = $settings['hooks'][$event];
+    Assert::isArray($group);
+    Assert::isArray($group[0]);
+    Assert::isArray($group[0]['hooks']);
+    Assert::isArray($group[0]['hooks'][0]);
+    $command = $group[0]['hooks'][0]['command'];
+    Assert::string($command);
+
+    return $command;
+}
+
+/**
+ * 起動子そのものを走らせる。`CLAUDE_PROJECT_DIR` を渡さないときは環境から消える。
+ *
+ * @return array{exitCode: int, output: string, errorOutput: string, elapsed: float}
+ */
+function claudeHooksRunLauncher(string $command, ?string $projectDir, ?string $cwd = null): array
+{
+    $env = ['/usr/bin/env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin'];
+    if ($projectDir !== null) {
+        $env[] = 'CLAUDE_PROJECT_DIR='.$projectDir;
+    }
+
+    return claudeHooksRun([...$env, '/bin/bash', '-c', $command], '', $cwd);
+}
+
+/** 起動子の内側に置く「終了コードだけを返す」スクリプト。 */
+function claudeHooksWriteExitStub(string $path, int $exitCode): void
+{
+    File::put($path, "#!/bin/bash\nexit {$exitCode}\n");
+    chmod($path, 0700);
+}
+
+// =============================================================================
+// 静的層
+// =============================================================================
+
+test('S01: .claude/settings.json が実在し有効な JSON であること', function (): void {
+    expect(claudeHooksSettings())->toBeArray();
+});
+
+test('S02: .claude/settings.json が git 追跡下にあること (各自任せの見本方式へ戻さない)', function (): void {
+    $result = Process::path(base_path())->timeout(30)
+        ->run(['git', 'ls-files', '--error-unmatch', '.claude/settings.json']);
+
+    expect($result->exitCode())->toBe(0, '.claude/settings.json が git 追跡下にない');
+});
+
+test('S03: トップレベルキーが台帳と完全一致すること (全数申告制)', function (): void {
+    $keys = array_keys(claudeHooksSettings());
+    sort($keys);
+    $expected = CLAUDE_HOOKS_TOP_LEVEL_KEYS;
+    sort($expected);
+
+    expect($keys)->toBe($expected, '台帳に無いトップレベルキーは既定拒否 (台帳を同じ変更で更新すること)');
+});
+
+test('S04: hooks のイベント集合が台帳と完全一致すること', function (): void {
+    $settings = claudeHooksSettings();
+    Assert::isArray($settings['hooks']);
+    $events = array_keys($settings['hooks']);
+    sort($events);
+    $expected = array_keys(CLAUDE_HOOKS_WIRING);
+    sort($expected);
+
+    expect($events)->toBe($expected);
+});
+
+test('S05/S06: 各 hook の matcher / 起動文字列 / timeout が台帳と完全一致すること', function (): void {
+    $settings = claudeHooksSettings();
+    Assert::isArray($settings['hooks']);
+
+    foreach (CLAUDE_HOOKS_WIRING as $event => $entries) {
+        $group = $settings['hooks'][$event];
+        Assert::isArray($group);
+        expect($group)->toHaveCount(count($entries), "{$event} の登録数が台帳と違う");
+
+        foreach ($entries as $index => $entry) {
+            $matcherGroup = $group[$index];
+            Assert::isArray($matcherGroup);
+            expect(array_keys($matcherGroup))->toBe(['matcher', 'hooks']);
+            expect($matcherGroup['matcher'])->toBe($entry['matcher']);
+
+            Assert::isArray($matcherGroup['hooks']);
+            expect($matcherGroup['hooks'])->toHaveCount(1);
+            $hook = $matcherGroup['hooks'][0];
+            Assert::isArray($hook);
+            expect(array_keys($hook))->toBe(['type', 'command', 'timeout']);
+            expect($hook['type'])->toBe('command');
+            expect($hook['timeout'])->toBe($entry['timeout']);
+            expect($hook['command'])->toBe(
+                claudeHooksExpectedCommand($entry['script'], $entry['deny_exit_code']),
+                "{$event} の起動文字列が台帳と 1 文字でも違う",
+            );
+        }
+    }
+});
+
+test('S06b: 起動子が privileged mode / 起動先検証 / 終了コード写像の 3 役をすべて持つこと', function (): void {
+    // claudeHooksExpectedCommand() は台帳側の組み立てなので、そこが緩んでも S05 は緑のままになる。
+    // 「何が書かれていなければならないか」を独立に固定する。
+    foreach (CLAUDE_HOOKS_WIRING as $event => $entries) {
+        foreach ($entries as $entry) {
+            $command = claudeHooksExpectedCommand($entry['script'], $entry['deny_exit_code']);
+
+            expect($command)->toStartWith("/bin/bash -p -c '", "{$event}: 起動子が絶対パス + privileged mode でない");
+            claudeHooksExpectContains($command, '/bin/bash -p "$f"', "{$event}: 内側の起動が privileged mode でない");
+
+            foreach ([
+                '[ -n "$d" ]',                    // 未設定を弾く
+                '[ "${d#/}" != "$d" ]',           // 絶対パスであること
+                '[ "${d//../}" = "$d" ]',         // `..` を含まないこと
+                '[ -d "$d/scripts" ]',            // scripts が実ディレクトリ
+                '[ ! -L "$d/scripts" ]',          // scripts が symlink でない
+                '[ -f "$f" ]',                    // 起動先が通常ファイル
+                '[ ! -L "$f" ]',                  // 起動先が symlink でない
+            ] as $condition) {
+                claudeHooksExpectContains($command, $condition, "{$event}: 起動先の検証が無い");
+            }
+
+            if ($entry['deny_exit_code'] === null) {
+                claudeHooksExpectNotContains($command, 'exit 2', "{$event}: ブロックしない hook が 2 を返しうる");
+            } else {
+                claudeHooksExpectContains(
+                    $command,
+                    'if [ "$s" = '.$entry['deny_exit_code'].' ]; then exit 2; fi',
+                    "{$event}: 拒否コードの写像が無い",
+                );
+            }
+            expect($command)->toEndWith("exit 0'", "{$event}: 既定で 0 に畳んでいない");
+        }
+    }
+});
+
+test('S07: .claude/settings.local.json は hooks キーを持てないこと (常設配線をローカルから殺さない)', function (): void {
+    $path = base_path('.claude/settings.local.json');
+    if (! is_file($path)) {
+        expect(true)->toBeTrue('ローカル設定は無い (常設配線を上書きする経路も無い)');
+
+        return;
+    }
+
+    $decoded = json_decode(claudeHooksReadFile($path), true);
+    Assert::isArray($decoded);
+    expect(array_key_exists('hooks', $decoded))
+        ->toBeFalse('.claude/settings.local.json に hooks を置かないこと (常設配線をローカルから殺す経路になる)');
+});
+
+test('S08: 見本ファイル方式が復活していないこと', function (): void {
+    expect(is_file(base_path('.claude/settings.bughunt-hook.example.json')))
+        ->toBeFalse('オプトインの見本ファイルは常設配線と並走させない (後方互換の並走を残さない)');
+});
+
+test('S09: 台帳の 2 スクリプトが実在し bash -n を通ること', function (): void {
+    foreach (CLAUDE_HOOKS_WIRING as $entries) {
+        foreach ($entries as $entry) {
+            $path = base_path($entry['script']);
+            expect(is_file($path))->toBeTrue("{$entry['script']} が無い");
+
+            $result = Process::timeout(30)->run(['bash', '-n', $path]);
+            expect($result->exitCode())->toBe(0, "{$entry['script']} が bash -n を通らない:\n".$result->errorOutput());
+        }
+    }
+});
+
+test('S10: 2 本の検索パス安全化ブロックが byte 一致し、どちらもファイル先頭にあること', function (): void {
+    $blocks = [];
+    $violations = [];
+
+    foreach (CLAUDE_HOOKS_WIRING as $entries) {
+        foreach ($entries as $entry) {
+            $extracted = claudeHooksPrologueBlock(claudeHooksReadFile(base_path($entry['script'])), $entry['script']);
+            $blocks[$entry['script']] = $extracted['block'];
+            $violations = [...$violations, ...$extracted['violations']];
+        }
+    }
+
+    expect($violations)->toBe([], implode("\n", $violations));
+    expect(count(array_unique($blocks)))->toBe(1, '2 本の検索パス安全化ブロックが byte 一致していない');
+    $block = reset($blocks);
+    Assert::string($block);
+    claudeHooksExpectContains($block, '_hook_sanitize_path', '安全化の実体がブロックに無い');
+});
+
+test('S11: 索引の対象外拡張子が台帳と完全一致すること', function (): void {
+    $contents = claudeHooksReadFile(base_path('scripts/code-review-graph-update-hook.sh'));
+
+    expect(preg_match("/^readonly SKIP_EXTENSIONS='([^']*)'$/m", $contents, $matches))
+        ->toBe(1, 'SKIP_EXTENSIONS の宣言が見つからない');
+
+    expect(preg_split('/\s+/', trim($matches[1])))->toBe(
+        CLAUDE_HOOKS_SKIP_EXTENSIONS,
+        '対象外拡張子が台帳と食い違う (索引ツールを更新したら両方を同じ変更で棚卸しすること)',
+    );
+});
+
+test('S12a: 索引ツール自身に配線を書かせない明文が AGENTS.md にマーカー付きで存在すること', function (): void {
+    $agents = claudeHooksReadFile(base_path('AGENTS.md'));
+
+    expect($agents)->toContain('<!-- CLAUDE_HOOKS_WIRING:BEGIN -->');
+    expect($agents)->toContain('<!-- CLAUDE_HOOKS_WIRING:END -->');
+
+    $begin = strpos($agents, '<!-- CLAUDE_HOOKS_WIRING:BEGIN -->');
+    $end = strpos($agents, '<!-- CLAUDE_HOOKS_WIRING:END -->');
+    Assert::integer($begin);
+    Assert::integer($end);
+    $section = substr($agents, $begin, $end - $begin);
+
+    foreach (['code-review-graph install', 'uninstall', '.claude/settings.json'] as $needle) {
+        claudeHooksExpectContains($section, $needle, '常設 hook 配線の節に必要な記述が無い');
+    }
+    foreach (CLAUDE_HOOKS_WIRING as $entries) {
+        foreach ($entries as $entry) {
+            claudeHooksExpectContains($section, $entry['script'], '常設 hook 配線の節に hook の行が無い');
+        }
+    }
+});
+
+test('S12b: 実行面のファイルが索引ツールに配線を書かせる呼び出しを持たないこと', function (): void {
+    $violations = [];
+
+    foreach (CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS as $glob) {
+        foreach (glob(base_path($glob)) ?: [] as $path) {
+            if (! is_file($path)) {
+                continue;
+            }
+            if (preg_match('/code-review-graph\s+(install|init|uninstall)\b/', claudeHooksReadFile($path)) === 1) {
+                $violations[] = str_replace(base_path().'/', '', $path);
+            }
+        }
+    }
+
+    expect($violations)->toBe([], "配線の正本が二重化する呼び出しがある:\n".implode("\n", $violations));
+});
+
+// =============================================================================
+// 実起動層: 索引更新 hook (B01〜B25)
+// =============================================================================
+
+test('B01: 正常な入力で索引の差分更新が 1 回だけ起動され、静かに 0 で終わること', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        claudeHooksInstallToolStub($sandbox);
+        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/Models/User.php'));
+
+        expect($result['exitCode'])->toBe(0);
+        expect($result['output'])->toBe('', '標準出力は常に空でなければならない');
+        expect($result['errorOutput'])->toBe('', '成功時は告知しない');
+        expect(claudeHooksInvocations($sandbox))->toBe(1);
+        claudeHooksExpectContains(
+            claudeHooksReadFile($sandbox.'/invoked.txt'),
+            'update -q --repo '.$sandbox,
+            '差分更新が sandbox のルートを --repo で受け取っていない',
+        );
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
+
+test('B02〜B05: 告知は理由ごと・セッションごとに 1 回だけであること', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        // B02: 索引ツール未導入 → 1 行だけ告知する
+        $first = claudeHooksRunUpdateHook(
+            $sandbox, claudeHooksWritePayload('app/A.php', 'sess-1'), claudeHooksPathWithoutTool($sandbox),
+        );
+        expect($first['exitCode'])->toBe(0);
+        expect($first['output'])->toBe('');
+        expect(claudeHooksWarningLines($first['errorOutput']))->toBe(1);
+        expect($first['errorOutput'])->toContain('未導入');
+
+        // B03: 同じセッション・同じ理由 → 黙る
+        $second = claudeHooksRunUpdateHook(
+            $sandbox, claudeHooksWritePayload('app/B.php', 'sess-1'), claudeHooksPathWithoutTool($sandbox),
+        );
+        expect(claudeHooksWarningLines($second['errorOutput']))->toBe(0, '同一セッション・同一理由で二重告知した');
+
+        // B04: セッションが変われば再告知する
+        $third = claudeHooksRunUpdateHook(
+            $sandbox, claudeHooksWritePayload('app/C.php', 'sess-2'), claudeHooksPathWithoutTool($sandbox),
+        );
+        expect(claudeHooksWarningLines($third['errorOutput']))->toBe(1);
+
+        // B05: 同じセッションでも理由が違えば告知する (timeout 不在)
+        claudeHooksInstallToolStub($sandbox);
+        $fourth = claudeHooksRunUpdateHook(
+            $sandbox, claudeHooksWritePayload('app/D.php', 'sess-1'), claudeHooksPathWithoutTimeout($sandbox),
+        );
+        expect(claudeHooksWarningLines($fourth['errorOutput']))->toBe(1);
+        expect($fourth['errorOutput'])->toContain('timeout');
+        expect(claudeHooksInvocations($sandbox))->toBe(0, 'timeout が無いのに更新を起動した');
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
+
+test('B06/B07: 敵対的な検索パスでもカレントの偽ツールを起動しないこと', function (string $path): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        // カレントディレクトリに偽の索引ツールを置く (PATH に "." が残っていれば起動される)
+        File::makeDirectory($sandbox.'/cwd', 0700, true);
+        File::put($sandbox.'/cwd/code-review-graph', "#!/bin/bash\ntouch '{$sandbox}/FAKE-RAN'\n");
+        chmod($sandbox.'/cwd/code-review-graph', 0700);
+
+        $result = claudeHooksRunUpdateHook(
+            $sandbox, claudeHooksWritePayload('app/A.php'), $path, $sandbox.'/cwd',
+        );
+
+        expect($result['exitCode'])->toBe(0);
+        expect($result['output'])->toBe('');
+        expect(is_file($sandbox.'/FAKE-RAN'))->toBeFalse("検索パス [{$path}] でカレントの偽ツールが起動された");
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+})->with([
+    'PATH が空' => [''],
+    'PATH がカレント' => ['.'],
+    'PATH が相対値' => ['relative/bin'],
+    'PATH が存在しない絶対パス' => ['/nonexistent-claude-hooks'],
+]);
+
+test('B08/B09: 壊れた JSON でも空入力でも 0 で終わること', function (string $input): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        claudeHooksInstallToolStub($sandbox);
+        $result = claudeHooksRunUpdateHook($sandbox, $input);
+
+        expect($result['exitCode'])->toBe(0);
+        expect($result['output'])->toBe('');
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+})->with([
+    '壊れた JSON' => ['{"session_id": "sess-a", "tool_input": {'],
+    '空入力' => [''],
+]);
+
+test('B10: 標準入力を閉じない相手に待ち続けないこと', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        claudeHooksInstallToolStub($sandbox);
+        // 名前付きパイプの書き手を開いたまま何も書かない = 「閉じない producer」
+        $script = <<<BASH
+        set -u
+        mkfifo '{$sandbox}/pipe'
+        sleep 60 > '{$sandbox}/pipe' &
+        writer=\$!
+        '/bin/bash' '{$sandbox}/scripts/code-review-graph-update-hook.sh' < '{$sandbox}/pipe'
+        code=\$?
+        kill "\${writer}" 2>/dev/null
+        exit "\${code}"
+        BASH;
+
+        $result = claudeHooksRun(['/bin/bash', '-c', $script]);
+
+        expect($result['exitCode'])->toBe(0);
+        expect($result['elapsed'])->toBeLessThan(30.0, '標準入力の待ちが時間切れで打ち切られていない');
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
+
+test('B11: 1 MiB より後ろにしか手掛かりが無い入力でも待ち続けず 0 で終わること', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        claudeHooksInstallToolStub($sandbox);
+        $input = str_repeat('x', 1_200_000).claudeHooksWritePayload('docs/x.md');
+        $result = claudeHooksRunUpdateHook($sandbox, $input);
+
+        expect($result['exitCode'])->toBe(0);
+        expect($result['output'])->toBe('');
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
+
+test('B12〜B14: 置き場・ロックが symlink なら何も書かずに終えること', function (string $case): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        claudeHooksInstallToolStub($sandbox);
+        File::makeDirectory($sandbox.'/decoy', 0700, true);
+
+        match ($case) {
+            '.claude が symlink' => symlink($sandbox.'/decoy', $sandbox.'/.claude'),
+            '置き場が symlink' => (function () use ($sandbox): void {
+                File::makeDirectory($sandbox.'/.claude', 0700, true);
+                symlink($sandbox.'/decoy', $sandbox.'/.claude/code-review-graph-update-hook');
+            })(),
+            'ロックが symlink' => (function () use ($sandbox): void {
+                File::makeDirectory($sandbox.'/.claude/code-review-graph-update-hook', 0700, true);
+                symlink($sandbox.'/decoy/update.lock', $sandbox.'/.claude/code-review-graph-update-hook/update.lock');
+            })(),
+        };
+
+        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));
+
+        expect($result['exitCode'])->toBe(0);
+        expect($result['output'])->toBe('');
+        expect(claudeHooksInvocations($sandbox))->toBe(0, "{$case}: 更新が起動された");
+        expect(File::files($sandbox.'/decoy'))->toBe([], "{$case}: symlink の先に書き込まれた");
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+})->with(['.claude が symlink', '置き場が symlink', 'ロックが symlink']);
+
+test('B15: 置き場の親が書けなければ黙って 0 で終えること', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        claudeHooksInstallToolStub($sandbox);
+        File::makeDirectory($sandbox.'/.claude', 0500, true);
+
+        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));
+
+        expect($result['exitCode'])->toBe(0);
+        expect($result['output'])->toBe('');
+        expect(claudeHooksInvocations($sandbox))->toBe(0);
+    } finally {
+        if (is_dir($sandbox.'/.claude')) {
+            chmod($sandbox.'/.claude', 0700);
+        }
+        File::deleteDirectory($sandbox);
+    }
+});
+
+test('B16: 他が更新中なら待たずに黙って終えること', function (): void {
+    $sandbox = claudeHooksSandbox();
+    $holder = null;
+
+    try {
+        claudeHooksInstallToolStub($sandbox);
+        $stateDir = $sandbox.'/.claude/code-review-graph-update-hook';
+        File::makeDirectory($stateDir, 0700, true);
+
+        $holder = Process::timeout(60)->start(['/bin/bash', '-c', <<<BASH
+            exec 9> '{$stateDir}/update.lock'
+            flock -n 9 || exit 1
+            : > '{$sandbox}/HELD'
+            sleep 20
+            BASH]);
+
+        $waitedUntil = microtime(true) + 15.0;
+        while (! is_file($sandbox.'/HELD') && microtime(true) < $waitedUntil) {
+            usleep(20_000);
+        }
+        expect(is_file($sandbox.'/HELD'))->toBeTrue('ロック保持プロセスを起こせなかった');
+
+        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));
+
+        expect($result['exitCode'])->toBe(0);
+        expect($result['output'])->toBe('');
+        expect(claudeHooksInvocations($sandbox))->toBe(0, 'ロック競合中に更新が起動された');
+        expect($result['elapsed'])->toBeLessThan(10.0, 'ロックを待ってしまっている (非ブロッキングでない)');
+    } finally {
+        $holder?->stop();
+        File::deleteDirectory($sandbox);
+    }
+});
+
+test('B17: 並行起動しても更新は 1 回だけであること', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        // 3 秒かかる更新にして、後続が確実にロック競合へ落ちるようにする
+        claudeHooksInstallToolStub($sandbox, "exec '".claudeHooksResolveExecutable('sleep')."' 3\n");
+
+        $startedAt = microtime(true);
+        $processes = [];
+        for ($i = 0; $i < 5; $i++) {
+            $processes[] = Process::timeout(60)
+                ->input(claudeHooksWritePayload("app/File{$i}.php", "sess-{$i}"))
+                ->start([
+                    '/usr/bin/env', '-i', 'PATH='.claudeHooksPathWithTool($sandbox),
+                    '/bin/bash', $sandbox.'/scripts/code-review-graph-update-hook.sh',
+                ]);
+        }
+        foreach ($processes as $process) {
+            expect($process->wait()->exitCode())->toBe(0);
+        }
+        $elapsed = microtime(true) - $startedAt;
+
+        expect(claudeHooksInvocations($sandbox))->toBe(1, '排他が効いておらず更新が重複起動された');
+        expect($elapsed)->toBeLessThan(30.0, '呼び出し側 timeout (30 秒) を超えた');
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
+
+test('B18: 終わらない更新を内側の時間切れで打ち切り、その旨を 1 行告知すること', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        claudeHooksInstallToolStub($sandbox, "exec '".claudeHooksResolveExecutable('sleep')."' 120\n");
+
+        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));
+
+        expect($result['exitCode'])->toBe(0);
+        expect($result['output'])->toBe('');
+        expect(claudeHooksWarningLines($result['errorOutput']))->toBe(1);
+        expect($result['errorOutput'])->toContain('20 秒');
+        expect($result['elapsed'])->toBeLessThan(45.0, '内側の時間切れ (20 秒) が効いていない');
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
+
+test('B19: 更新が失敗したらその旨を 1 行告知して 0 で終えること', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        claudeHooksInstallToolStub($sandbox, "exit 3\n");
+
+        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));
+
+        expect($result['exitCode'])->toBe(0);
+        expect($result['output'])->toBe('');
+        expect(claudeHooksWarningLines($result['errorOutput']))->toBe(1);
+        expect($result['errorOutput'])->toContain('終了コード 3');
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
+
+test('B20: 細工されたセッション識別子で置き場の外にファイルを作らないこと', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        $payload = '{"session_id":"../../'.basename($sandbox).'-escape","tool_input":{"file_path":"app/A.php"}}';
+        $result = claudeHooksRunUpdateHook($sandbox, $payload, claudeHooksPathWithoutTool($sandbox));
+
+        expect($result['exitCode'])->toBe(0);
+        // 置き場に出来てよいのはロックと告知の目印だけで、いずれも識別子が正規化されたもの
+        foreach (File::files($sandbox.'/.claude/code-review-graph-update-hook') as $file) {
+            expect(in_array($file->getFilename(), ['update.lock', 'warned-tool-missing-unknown'], true))
+                ->toBeTrue('置き場に想定外のファイルが出来た: '.$file->getFilename());
+        }
+        expect(glob(dirname($sandbox).'/*-escape') ?: [])->toBe([], '置き場の外にファイルが作られた');
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
+
+test('B21/B22: 索引の対象外拡張子では副作用ゼロで終えること', function (string $filePath): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        claudeHooksInstallToolStub($sandbox);
+        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload($filePath));
+
+        expect($result['exitCode'])->toBe(0);
+        expect($result['output'])->toBe('');
+        expect($result['errorOutput'])->toBe('');
+        expect(claudeHooksInvocations($sandbox))->toBe(0, "{$filePath} で更新が起動された");
+        expect(is_dir($sandbox.'/.claude'))->toBeFalse("{$filePath} で置き場が作られた (副作用ゼロでない)");
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+})->with([
+    'docs/x.md' => ['docs/x.md'],
+    '大文字の拡張子' => ['docs/x.MD'],
+    'package.json' => ['package.json'],
+    'pnpm-lock.yaml' => ['pnpm-lock.yaml'],
+]);
+
+test('B23/B24: 判定できない入力は更新する側へ倒すこと', function (string $filePath): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        claudeHooksInstallToolStub($sandbox);
+        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload($filePath));
+
+        expect($result['exitCode'])->toBe(0);
+        expect(claudeHooksInvocations($sandbox))->toBe(1, "{$filePath} で更新が起動されなかった");
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+})->with([
+    'blade の複合拡張子' => ['resources/views/x.blade.php'],
+    '拡張子なし' => ['Makefile'],
+    'svelte' => ['resources/js/x.svelte'],
+]);
+
+test('B25: 作業ディレクトリと環境変数に依存せずリポジトリルートを解決すること', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        claudeHooksInstallToolStub($sandbox);
+        // cwd を / にし、CLAUDE_PROJECT_DIR も渡さない (env -i なので元から無い)
+        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'), null, '/');
+
+        expect($result['exitCode'])->toBe(0);
+        expect(claudeHooksReadFile($sandbox.'/invoked.txt'))->toContain('--repo '.$sandbox);
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
+
+// =============================================================================
+// 実起動層: bug-hunt ガード (B26〜B40b)
+// =============================================================================
+
+test('B26/B28/B30〜B33/B40/B40b: provision の直叩きだけを拒否すること', function (string $command, int $expected): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        $result = claudeHooksRunBughuntHook($sandbox, claudeHooksBashPayload($command));
+
+        expect($result['exitCode'])->toBe($expected, "コマンド [{$command}] の判定が違う");
+        expect($result['output'])->toBe('', '標準出力は常に空でなければならない');
+        if ($expected === 97) {
+            expect($result['errorOutput'])->toContain('bug-hunt provision');
+        } else {
+            expect($result['errorOutput'])->toBe('');
+        }
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+})->with([
+    'B26 無関係なコマンド' => ['ls -la', 0],
+    'B28 main からの直叩き' => ['scripts/bug-hunt-shard.sh provision --shard 1', 97],
+    'B30 worktree から' => ['cd .claude/worktrees/tasks/x && scripts/bug-hunt-shard.sh provision', 0],
+    'B31 明示解除' => ['BUGHUNT_ALLOW_MAIN=1 scripts/bug-hunt-shard.sh provision', 0],
+    'B32 self-test dryrun' => ['BUGHUNT_SELFTEST_DRYRUN=1 scripts/bug-hunt-shard.sh provision', 0],
+    'B40 間に別語が入る言及' => ['scripts/bug-hunt-shard.sh scaffold x provision', 0],
+    'B40b provision-all' => ['scripts/bug-hunt-shard.sh provision-all', 97],
+]);
+
+test('B37: JSON が / を \\/ へ逃がしていても worktree の指紋を取りこぼさないこと', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        $payload = claudeHooksBashPayload(
+            'cd .claude/worktrees/tasks/x && scripts/bug-hunt-shard.sh provision',
+            'x',
+            escapeSlashes: true,
+        );
+        claudeHooksExpectContains($payload, '.claude\\/worktrees\\/', 'テスト入力が逃がし表記になっていない');
+
+        expect(claudeHooksRunBughuntHook($sandbox, $payload)['exitCode'])
+            ->toBe(0, '逃がし表記の worktree パスを許可シグナルとして拾えていない');
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
+
+test('B33: 説明文だけに provision があっても誤発火しないこと', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        $payload = claudeHooksBashPayload('echo hello', 'scripts/bug-hunt-shard.sh provision の説明');
+        $result = claudeHooksRunBughuntHook($sandbox, $payload);
+
+        expect($result['exitCode'])->toBe(0, '抽出値ではなく生入力で判定している');
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
+
+test('B27/B29: 検索パスが壊れていても判定が変わらず、外部コマンドを 1 つも起こさないこと', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        // カレントに偽の判定用コマンドを置く (以前の実装はこれらに依存していた)
+        File::makeDirectory($sandbox.'/cwd', 0700, true);
+        foreach (['cat', 'grep', 'python3', 'printf'] as $name) {
+            File::put($sandbox.'/cwd/'.$name, "#!/bin/bash\ntouch '{$sandbox}/FAKE-{$name}'\n");
+            chmod($sandbox.'/cwd/'.$name, 0700);
+        }
+
+        // B27: 無関係なコマンド + 敵対的な検索パス → 0 のまま
+        $passing = claudeHooksRunBughuntHook(
+            $sandbox, claudeHooksBashPayload('ls -la'), '/nonexistent-claude-hooks', $sandbox.'/cwd',
+        );
+        expect($passing['exitCode'])->toBe(0);
+
+        // B29: 拒否対象 + 空の検索パス → 無音の素通りをしない
+        $denied = claudeHooksRunBughuntHook(
+            $sandbox, claudeHooksBashPayload('scripts/bug-hunt-shard.sh provision'), '', $sandbox.'/cwd',
+        );
+        expect($denied['exitCode'])->toBe(97, '検索パスが壊れると拒否対象が黙って通っている');
+        expect($denied['errorOutput'])->toContain('bug-hunt provision');
+
+        expect(glob($sandbox.'/FAKE-*') ?: [])->toBe([], '判定経路が外部コマンドに依存している');
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
+
+test('B34〜B36: JSON を解釈できないときは明示解除だけを見ること', function (string $input, int $expected): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        $result = claudeHooksRunBughuntHook($sandbox, $input);
+
+        expect($result['exitCode'])->toBe($expected);
+        expect($result['output'])->toBe('');
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+})->with([
+    'B34 解除なし' => ['{"tool_input": {"comm scripts/bug-hunt-shard.sh provision', 97],
+    'B35 明示解除あり' => ['{"tool_input": {"comm BUGHUNT_ALLOW_MAIN=1 scripts/bug-hunt-shard.sh provision', 0],
+    'B36 痕跡だけ' => ['{"tool_input": {"comm .claude/worktrees/ scripts/bug-hunt-shard.sh provision', 97],
+]);
+
+test('B38: 標準入力が空でも閉じない相手でも 0 で終えること', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        expect(claudeHooksRunBughuntHook($sandbox, '')['exitCode'])->toBe(0);
+
+        $script = <<<BASH
+        set -u
+        mkfifo '{$sandbox}/pipe'
+        sleep 60 > '{$sandbox}/pipe' &
+        writer=\$!
+        '/bin/bash' '{$sandbox}/scripts/bughunt-worktree-hook.sh' < '{$sandbox}/pipe'
+        code=\$?
+        kill "\${writer}" 2>/dev/null
+        exit "\${code}"
+        BASH;
+
+        $result = claudeHooksRun(['/bin/bash', '-c', $script]);
+        expect($result['exitCode'])->toBe(0);
+        expect($result['elapsed'])->toBeLessThan(30.0);
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
+
+test('B39: 1 MiB より後ろにしか対象語が無い入力では通す (受容済みの限界)', function (): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        $input = str_repeat('x', 1_200_000).claudeHooksBashPayload('scripts/bug-hunt-shard.sh provision');
+        $result = claudeHooksRunBughuntHook($sandbox, $input);
+
+        expect($result['exitCode'])->toBe(0, '読み取り上限を超えた入力は通す (待ち続けないことを優先する)');
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
+
+// =============================================================================
+// 実起動層: 起動子 (B41〜B51)
+// =============================================================================
+
+test('B41〜B49: PreToolUse の起動子が 97 だけを 2 へ写し、それ以外を 0 に畳むこと', function (string $case, int $expected): void {
+    $sandbox = claudeHooksSandbox();
+    $command = claudeHooksLauncherCommand('PreToolUse');
+    $script = $sandbox.'/scripts/bughunt-worktree-hook.sh';
+    $projectDir = $sandbox;
+    $cwd = null;
+
+    try {
+        match ($case) {
+            'B41 拒否 (97)' => claudeHooksWriteExitStub($script, 97),
+            'B42 通過 (0)' => claudeHooksWriteExitStub($script, 0),
+            'B43 構文エラー (2)' => claudeHooksWriteExitStub($script, 2),
+            'B44 起動先が無い' => File::delete($script),
+            'B45 CLAUDE_PROJECT_DIR が無い' => (function () use ($script, &$projectDir): void {
+                claudeHooksWriteExitStub($script, 97);
+                $projectDir = null;
+            })(),
+            'B46 相対値' => (function () use ($script, $sandbox, &$projectDir, &$cwd): void {
+                claudeHooksWriteExitStub($script, 97);
+                $projectDir = basename($sandbox);
+                $cwd = dirname($sandbox);
+            })(),
+            'B47 .. を含む' => (function () use ($script, $sandbox, &$projectDir): void {
+                claudeHooksWriteExitStub($script, 97);
+                $projectDir = dirname($sandbox).'/../'.basename(dirname($sandbox)).'/'.basename($sandbox);
+            })(),
+            'B48 scripts が symlink' => (function () use ($script, $sandbox): void {
+                claudeHooksWriteExitStub($script, 97);
+                rename($sandbox.'/scripts', $sandbox.'/real-scripts');
+                symlink($sandbox.'/real-scripts', $sandbox.'/scripts');
+            })(),
+            'B49 起動先が symlink' => (function () use ($script, $sandbox): void {
+                claudeHooksWriteExitStub($sandbox.'/scripts/real-hook.sh', 97);
+                File::delete($script);
+                symlink($sandbox.'/scripts/real-hook.sh', $script);
+            })(),
+        };
+
+        $result = claudeHooksRunLauncher($command, $projectDir, $cwd);
+
+        expect($result['exitCode'])->toBe($expected, "{$case}: 起動子の写像が違う");
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+})->with([
+    'B41 拒否 (97)' => ['B41 拒否 (97)', 2],
+    'B42 通過 (0)' => ['B42 通過 (0)', 0],
+    'B43 構文エラー (2)' => ['B43 構文エラー (2)', 0],
+    'B44 起動先が無い' => ['B44 起動先が無い', 0],
+    'B45 CLAUDE_PROJECT_DIR が無い' => ['B45 CLAUDE_PROJECT_DIR が無い', 0],
+    'B46 相対値' => ['B46 相対値', 0],
+    'B47 .. を含む' => ['B47 .. を含む', 0],
+    'B48 scripts が symlink' => ['B48 scripts が symlink', 0],
+    'B49 起動先が symlink' => ['B49 起動先が symlink', 0],
+]);
+
+test('B50: PostToolUse の起動子は内側の終了コードにかかわらず常に 0 を返すこと', function (int $inner): void {
+    $sandbox = claudeHooksSandbox();
+
+    try {
+        claudeHooksWriteExitStub($sandbox.'/scripts/code-review-graph-update-hook.sh', $inner);
+        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PostToolUse'), $sandbox);
+
+        expect($result['exitCode'])->toBe(0, "内側が {$inner} のとき起動子が 0 を返していない");
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+})->with([[0], [1], [2], [97], [127]]);
+
+test('B51: 起動子が環境からのシェル関数を内側へ継承させないこと (privileged mode)', function (): void {
+    $sandbox = claudeHooksSandbox();
+    $command = claudeHooksLauncherCommand('PreToolUse');
+
+    try {
+        // 内側で「注入した関数が見えるか」を自分で記録するスクリプト
+        File::put($sandbox.'/scripts/bughunt-worktree-hook.sh', <<<BASH
+        #!/bin/bash
+        if [ "\$(type -t claude_hooks_probe)" = "function" ]; then
+            touch '{$sandbox}/FUNC-LEAKED'
+        fi
+        exit 0
+        BASH);
+        chmod($sandbox.'/scripts/bughunt-worktree-hook.sh', 0700);
+
+        $wrapper = "claude_hooks_probe() { :; }\nexport -f claude_hooks_probe\nexec ".$command;
+        $result = claudeHooksRun([
+            '/usr/bin/env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin', 'CLAUDE_PROJECT_DIR='.$sandbox,
+            '/bin/bash', '-c', $wrapper,
+        ]);
+
+        expect($result['exitCode'])->toBe(0);
+        expect(is_file($sandbox.'/FUNC-LEAKED'))
+            ->toBeFalse('環境から注入したシェル関数が hook へ継承された (privileged mode が効いていない)');
+    } finally {
+        File::deleteDirectory($sandbox);
+    }
+});
diff --git a/tests/Architecture/DockerfileProvisioningTest.php b/tests/Architecture/DockerfileProvisioningTest.php
index ff9cc86..4567f95 100644
--- a/tests/Architecture/DockerfileProvisioningTest.php
+++ b/tests/Architecture/DockerfileProvisioningTest.php
@@ -35,3 +35,19 @@ function dockerfileContents(): string
     // 字幕 (Noto Sans CJK JP) のフォント解決前提。tofu 化を防ぐ退行ガード (独立行アンカー)
     expect(dockerfileContents())->toMatch('/^[ \t]*fonts-noto-cjk[ \t]*\\\\?[ \t]*$/m');
 });
+
+test('static guard: docker/Dockerfile がコード索引ツールを版固定で導入している', function (): void {
+    // .claude/settings.json の PostToolUse hook が差分更新を回す前提。版を上げるときは
+    // このテストも同じ変更で直す = 対象外拡張子 (ClaudeHooksWiringTest の台帳) の棚卸しが
+    // レビューで必ず見える。
+    expect(dockerfileContents())->toMatch('/^RUN uv tool install code-review-graph==2\.3\.7$/m');
+});
+
+test('static guard: docker/Dockerfile が索引ツールの導入先を固定し検索パスへ載せている', function (): void {
+    // 導入先が HOME 由来だと RUN の位置を動かしただけで別ディレクトリへ落ち、
+    // hook がセッションごとに「未導入」と告知するようになる。
+    $contents = dockerfileContents();
+
+    expect($contents)->toMatch('#^ENV UV_TOOL_BIN_DIR=/home/vscode/\.local/bin$#m');
+    expect($contents)->toMatch('#^ENV PATH="/home/vscode/\.local/bin:\$PATH"$#m');
+});
```


## テスト結果 (すべて worktree 内で実行)

- `vendor/bin/pest tests/Architecture/ClaudeHooksWiringTest.php`: 70 passed / 0 failed (217 assertions, 36 秒)
- `vendor/bin/pest tests/Architecture/`: 967 passed / 0 failed (6861 assertions)
- `composer test`: 5044 passed / 0 failed / 2 skipped (21212 assertions)
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm test`: 1501 passed (136 files)
- `pnpm build`: built
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`: passed (106 tests)
- `bash -n` は S09 が両スクリプトに対して自動実行している

## 確認済みの実測 (レビューの前提)

- `exec 9> file 2>/dev/null` は標準エラーの差し替えが**シェル全体へ永続適用**される
  (実測済み。だから波括弧のグループにしてある)
- `{ exec 9> /nonexistent/zz; } 2>/dev/null` は非対話シェルを終了させず非ゼロを返す (実測済み)
- `bash -p` は環境から export されたシェル関数を継承しない (実測済み・B51 で機械化)
