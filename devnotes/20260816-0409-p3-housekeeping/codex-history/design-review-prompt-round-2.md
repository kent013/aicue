Round 1 の指摘 (Warning 2 件 / Suggestion 3 件) を詳細設計へ反映した。対応マトリクスと、
変更した箇所の抜粋を示す。反映が指摘の意図どおりか、また反映によって新しい欠陥
(とくに POSIX sh の挙動と、保証範囲の書きすぎ / 書き足りなさ) が生じていないかを確認してほしい。

出力形式は Round 1 と同じ (施策ごとの判定 + 全体判定)。

---

## 対応マトリクス

# 対応マトリクス: design-review Round 1

全体判定は APPROVED (施策 1 / 施策 2 / 落とす判断 の 3 つとも APPROVE)。
Warning 2 件・Suggestion 3 件の扱いを記録し、詳細設計へ反映した。

## [Warning] W2 の「exit 0」は実機挙動の保証として読めると強すぎる

- 判断: 対応する
- 根拠: 妥当。別 platform の実バイナリは `[ -x ]` を通っても `exec` 時に落ちうるので、
  「exit 0」を固定内容として書くと「拾い直したバイナリは動く」と読める。保証しないものを
  保証しているように書かない (AGENTS.md の一貫した規律)。
- 対応内容: W2 の固定内容を「拾い直した拡張のバイナリまで到達する」「stderr に期待 platform と
  採用パスが出る」に書き換え、終了コード 0 は**テスト用の偽バイナリが 0 で終わるから**である旨を
  明記した。あわせて「あえて固定しないこと」に `exec` が実行形式の不一致で落ちうることを追記。

## [Warning] POSIX sh の関数内変数が呼び出し側へ漏れる

- 判断: 一部対応する (コメントで明示。`unset` は入れない)
- 根拠: 指摘のとおり POSIX sh に `local` は無い。ただしこの関数は
  **コマンド置換 (`$( … )`) の中でしか呼ばない**設計なので、代入は部分シェルに閉じ、
  呼び出し側のシェルへは実際には漏れない。`unset` を足すと早期 return の分岐ごとに
  同じ後始末が並び、8 行の関数に対して読みにくさが勝つ。
- 対応内容: 「コマンド置換の中でしか呼ばないので漏れない」「それでも将来の追記で
  取り違えないよう `_` 始まりにする」「呼び出し側で `_` 始まりの名前を使わない」を
  設計本文と実装コメントの両方に書いた。

## [Suggestion] `proc_open` は stdin を閉じてから stdout / stderr を読む

- 判断: 対応する
- 対応内容: 施策 1 の判定の作りに「stdin へ書く → 閉じる → stdout / stderr を読む → `proc_close`」
  の順序を明記した。

## [Suggestion] 流し込むパスの限定をテスト名かコメントに残す

- 判断: 対応する
- 対応内容: 「渡すのはリポジトリルート相対の `.claude/skills/{key}` だけ (絶対パスや
  `.gitignore` の行そのものは渡さない)」をテスト冒頭のコメントに書く、と設計へ明記した。

## [Suggestion] `sort -V` が GNU 前提であることをテストのコメントに残す

- 判断: 対応する
- 根拠: 新しい回帰テストが `scripts/claude` を実際に起動するため、macOS で走らせたときに
  既存の前提が表面化する。直さない判断は維持しつつ、後続が誤解しないよう書き残すのは安い。
- 対応内容: ラッパのコメントと「あえて固定しないこと」の両方に GNU `sort -V` 前提を明記した。

---

## 反映後の抜粋

### 施策 1 「外部コマンドの扱い」節 (全文)

**外部コマンドの扱い (Codex Round 1 の Warning に対応)**:

- `--no-index` を必ず付ける。付けないと「追跡されているから報告されない」という
  **追跡状態**の影響を受ける。本テストが見たいのは**除外規則そのもの**である。
- `git check-ignore` の終了コードは **0 = 一致あり / 1 = 一致なし / それ以外 = エラー**。
  0 と 1 の両方を正常応答として扱い、それ以外は **skip せず fail** させる (偽グリーン禁止)。
- `proc_open` の手順は **stdin へ書く → stdin を閉じる → stdout / stderr を読む → `proc_close`**
  の順にする (閉じる前に読むと相手が入力待ちのまま止まる)。
- 流し込むのは**リポジトリルート相対の `.claude/skills/{key}` だけ**である
  (絶対パスや `.gitignore` の行そのものは渡さない)。この限定をテスト冒頭のコメントに書く。
- `.gitignore` の glob 評価を PHP 側で再実装しない (git の挙動とズレたら検査の意味が消える)。
- 空振り防止: 登録キーが 1 件も無いときは fail させる
  (`skills-lock.json` が空になったら検査が素通りするため)。
- 保証範囲を誇張しない: 見るのは**版固定ファイルに登録されたキーだけ**である。
  `/.agents` や外部コマンドが生成する状態ファイルの除外は本テストの対象外で、
  「外部由来のものが 1 つも追跡されない」とは読めない。


### 施策 2 「変更後コード」と直後の注記 (全文)

### 変更後コード

```sh
# 拡張の探索は 1 か所にまとめる (完全一致の探索と拾い直しで同じ規則を 2 度書かないため)。
# 引数は拡張ディレクトリ名の接尾辞。最も版が新しいディレクトリの絶対パスを標準出力へ返し、
# 1 つも無ければ非ゼロで返る。
# POSIX sh に local が無いため関数内の変数はグローバルになる。呼び出し側で `_` 始まりの
# 名前を使わないこと。版の比較は GNU の `sort -V` に依存する (現行ラッパと同じ前提)。
find_claude_extension() {
  _suffix="$1"
  _version=$(ls -d \
      "$HOME/.vscode/extensions/anthropic.claude-code-"*"$_suffix" \
      "$HOME/.vscode-server/extensions/anthropic.claude-code-"*"$_suffix" \
      2>/dev/null \
    | sed 's|.*/anthropic\.claude-code-||' \
    | sort -t- -k1 -V \
    | tail -1)
  [ -n "$_version" ] || return 1
  for _root in "$HOME/.vscode/extensions" "$HOME/.vscode-server/extensions"; do
    if [ -d "$_root/anthropic.claude-code-$_version" ]; then
      printf '%s\n' "$_root/anthropic.claude-code-$_version"
      return 0
    fi
  done
  return 1
}

LATEST_EXT=$(find_claude_extension "-$PLATFORM")

if [ -z "$LATEST_EXT" ]; then
  # 環境が完全一致しないときは諦めずに拾い直す。別 platform のバイナリを掴みうるので必ず警告する。
  LATEST_EXT=$(find_claude_extension "")
  if [ -n "$LATEST_EXT" ]; then
    echo "Warning: no Claude Code extension for platform $PLATFORM;" >&2
    echo "         falling back to $LATEST_EXT (it may not run on this machine)." >&2
  fi
fi

if [ -z "$LATEST_EXT" ]; then
  echo "Error: Claude Code VSCode extension not found (platform: $PLATFORM)." >&2
  echo "Install it from the VSCode marketplace first." >&2
  exit 1
fi
```

- 探索規則 (2 系統の横断 / 版の比較 / ディレクトリの復元) が関数 1 つに収まり、
  完全一致と拾い直しで**同じ規則**が使われる。
- 関数は実在するディレクトリしか返さないので、後段の `[ ! -d … ]` は不要になる。
- 警告文には **期待した platform** と **採用した拡張の絶対パス** の 2 つを必ず入れる
  (Codex Round 1 の Warning に対応)。
- `#!/bin/sh` を維持する。POSIX sh に `local` が無く関数内の変数はグローバルになるが、
  **この関数はコマンド置換 (`$( … )`) の中でしか呼ばない**ので、代入は部分シェルに閉じ、
  呼び出し側のシェルへは漏れない。それでも将来の追記で取り違えないよう変数名は `_` 始まりにし、
  「呼び出し側で `_` 始まりの名前を使わない」旨をコメントに残す (Codex Round 1 の Warning)。
- 版の比較は現行ラッパと同じ `sort -t- -k1 -V` で、**GNU の `sort -V` に依存する**。
  この前提は本束が持ち込むものではないため変えない (テスト側のコメントにも書く)。
- 41 行目以降 (`CLAUDE="$LATEST_EXT/resources/native-binary/claude"` 以降) は**触らない**。


### 施策 2 のテスト表 W2 と「あえて固定しないこと」(全文)

| W2 | 完全一致が無く別 platform の拡張だけがあるとき | **拾い直した拡張のバイナリまで到達する** (偽バイナリが引数を記録する) / stderr に期待 platform と採用パスの両方が出る。終了コードが 0 になるのは**テスト用の偽バイナリが 0 で終わるから**であって、実機の別 platform バイナリが動くという意味ではない |
| W3 | 拡張が 1 つも無いとき | exit 1 / stderr のエラー文に platform 名が出る |
| W4 | 拡張はあるがネイティブバイナリが実行可能でないとき | exit 1 / そのパスがメッセージに出る |
| W5 | 既定の bypass | 先頭に `--dangerously-skip-permissions` が付く。`--no-bypass` を渡すと付かず、`--no-bypass` 自体も転送されない |
| W6 | statusline の注入 | 複製先に実行可能な `claude-statusline` があると `--settings` と JSON が前置される。`--no-ctx` で付かない。**statusline が無ければ付かない** (本リポジトリの実態) |
| W7 | 引数のそのまま転送 | 空文字 / 空白入り / シングルクォート入り / `{"a":1}` 風 / `--` / 日本語 が、順序も内容も変わらず渡る |
| W8 | 負のコントロール | 完全一致で見つかった通常経路では warning を **1 文字も出さない** |

**あえて固定しないこと (誇張しない)**:

- 同じ版が両方の置き場にあるときにどちらを優先するか。これは正典の for ループ順から生じる
  副次的性質で、**正典自身が固定していない**。下流だけで固定すると正典が探索順を変えたときに
  本リポジトリだけ落ちる (aigenba が同じ理由で pin しないと判断した先例がある)。
- 代替経路が掴んだバイナリが**実際にこの機械で動くこと**。代替経路は arch を検査しないので
  異機種のバイナリを起動しうる (`[ -x ]` を通っても `exec` が実行形式の不一致で落ちうる)。
  これは**正典が持つ既知の穴**であり、正典が代替経路の成立を固定している以上ここだけ塞ぐと
  新しい乖離になる。**塞がずにテストのコメントへ書き残す**。
- `sort -V` が無い環境での版の比較。現行ラッパと同じ GNU 前提であることをコメントに書くに留める。

