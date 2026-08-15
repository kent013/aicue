Round 2 の Warning 1 件と Suggestion 1 件を反映した。対応マトリクスと反映後の抜粋を示す。
残るのは施策 2 の判定のみである。反映が意図どおりか確認してほしい。
出力形式は Round 1 / 2 と同じ。

---

## 対応マトリクス

# 対応マトリクス: design-review Round 2

全体判定は CHANGES_REQUESTED (施策 2 のみ)。施策 1 と落とす判断は APPROVE。

## [Warning] 「絶対パスを返す」という保証が実装から成立しない

- 判断: 対応する (提案された 2 案のうち前者を採る)
- 根拠: 指摘のとおり。`find_claude_extension` が返すのは `$HOME` から組み立てた文字列であり、
  絶対パスかどうかは `$HOME` の設定に依存する。これは現行ラッパも同じ形で組み立てているので
  本束が持ち込む前提ではないが、**記述だけが実装より強い**のは保証の書きすぎである。
  関数内で `cd` + `pwd` による正規化を足す案 (後者) は、小さな掃除の範囲で
  ラッパへ新しい処理を持ち込むことになるので採らない。
- 対応内容: (a) 関数コメントを「最も版が新しい拡張ディレクトリの **$HOME 配下のパス**を返す」に
  改めた。(b) 設計本文に「絶対パスであることは `$HOME` が絶対パスで設定されていることに依存する
  (現行ラッパと同じ前提)。『任意の環境で絶対パスを返す』とは書かない」を追記した。
  (c) 回帰テストは絶対パスの偽 `HOME` を使うと明記した。
  (d) 警告文の要件を「採用した拡張の絶対パス」から「採用した拡張のパス」に直した。

## [Suggestion] 関数コメントのスコープ説明を呼び出し方まで含めて一続きに書く

- 判断: 対応する
- 根拠: 妥当。「グローバルになる」だけ先に書くと漏洩があるように読める。
- 対応内容: 提案された文面に沿って「POSIX sh に local は無いが、この関数はコマンド置換の中でのみ
  呼ぶため変数代入は親シェルへ漏れない。将来の直接呼び出しとの衝突を避けるため関数内の変数は
  `_` 始まりとし、呼び出し側では同じ名前を使わない」の一続きへ書き換えた。

## W2 / `sort -V` の扱い

- 判断: 変更なし (Round 2 で「適切」「妥当」と評価されたため維持)

---

## 反映後の抜粋 (施策 2 の変更後コードと直後の注記)

### 変更後コード

```sh
# 拡張の探索は 1 か所にまとめる (完全一致の探索と拾い直しで同じ規則を 2 度書かないため)。
# 引数は拡張ディレクトリ名の接尾辞。最も版が新しい拡張ディレクトリの $HOME 配下のパスを
# 標準出力へ返し、1 つも無ければ非ゼロで返る。
# POSIX sh に local は無いが、この関数はコマンド置換の中でのみ呼ぶため変数代入は親シェルへ
# 漏れない。将来の直接呼び出しとの衝突を避けるため関数内の変数は `_` 始まりとし、
# 呼び出し側では同じ名前を使わない。版の比較は GNU の `sort -V` に依存する (現行ラッパと同じ)。
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
- 警告文には **期待した platform** と **採用した拡張のパス** の 2 つを必ず入れる
  (Codex Round 1 の Warning に対応)。
- 返すのは `$HOME` 配下のパスであり、**それが絶対パスであることは `$HOME` が絶対パスで
  設定されていることに依存する** (現行ラッパが `$HOME` から組み立てているのと同じ前提で、
  本束が新しく持ち込む前提ではない)。「任意の環境で絶対パスを返す」とは書かない。
  回帰テストは絶対パスの偽 `HOME` を使う (Codex Round 2 の Warning に対応)。
- `#!/bin/sh` を維持する。POSIX sh に `local` が無く関数内の変数はグローバルになるが、
  **この関数はコマンド置換 (`$( … )`) の中でしか呼ばない**ので、代入は部分シェルに閉じ、
  呼び出し側のシェルへは漏れない。それでも将来の追記で取り違えないよう変数名は `_` 始まりにし、
  「呼び出し側で `_` 始まりの名前を使わない」旨をコメントに残す (Codex Round 1 の Warning)。
- 版の比較は現行ラッパと同じ `sort -t- -k1 -V` で、**GNU の `sort -V` に依存する**。
  この前提は本束が持ち込むものではないため変えない (テスト側のコメントにも書く)。
- 41 行目以降 (`CLAUDE="$LATEST_EXT/resources/native-binary/claude"` 以降) は**触らない**。

