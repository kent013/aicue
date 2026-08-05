# impl-review Round 1 対応マトリクス (T112)

Codex 全体判定: **APPROVED** (Round 1)
指摘内訳: Critical 0 / Warning 0 / Suggestion 1

| # | 分類 | 指摘 | 判断 | 根拠 |
|---|---|---|---|---|
| 1 | [Suggestion] | `PCRE_DELIMITERS` が `/ # ~ % ! @` に限定されており、PHP で合法な bracket delimiter (`{}` / `[]` / `()` / `<>`) で `\R` が書かれると gate が見逃す。bracket delimiter の正/負コントロール追加の余地がある | **見送る** | (1) 詳細設計が「このリポジトリで実際に使われているもの」と明示的に射程を切っている。(2) 実測でリポジトリ内の bracket-delimited PCRE は **0 件** (`preg_*` の第 1 引数が `/ # ~ % ! @` 以外で始まるものを横断走査して 0 hit)。(3) bracket delimiter は **対になる閉じ delimiter を探す** 別ロジックが要るため、抽出器の複雑度が上がる。ゼロ件の用途のために抽出器を複雑化するのは AGENTS.md 思考原則 2 (今必要なものだけ作る) に反する。(4) Codex 自身も「今回の詳細設計には一致しているため blocking ではない」と評価している |

## 補足

`\R` を bracket delimiter で書く用途が実際に現れた場合は、`pcrePatternLiterals()` の
delimiter 判定を対応表 (`{`→`}` 等) に拡張し、正/負コントロールを追加する。
その時点で射程が変わったことは P15 と同じ形でテスト名に明記する。
