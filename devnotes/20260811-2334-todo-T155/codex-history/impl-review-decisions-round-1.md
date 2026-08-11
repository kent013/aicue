# 対応マトリクス: impl-review Round 1

全体判定 **APPROVED** (Critical 0 / Warning 0 / Suggestion 2)。

## [Suggestion] `href` のテンプレート文字列直書き

- 判断: **見送る**
- 根拠: レビュアー自身が「既存流儀に合っており本件で抽象化する必要はない」と述べている。
  route helper (ziggy 等) はこのリポジトリに無く、本 TODO で導入するのは過剰 (思考原則 2)。

## [Suggestion] `pathOf()` が pathname のみ比較で query / hash を検出しない

- 判断: **対応する**
- 根拠: 主張しているのは「href を固定した」ことなので、比較を狭くしておく理由が無い。
  1 行で強くできるうえ、余計な query が付く退行も落とせるようになる。
- 対応内容: `pathname + search + hash` を連結して比較するよう変更。
  変更後に当該テストファイル 20 件・`pnpm typecheck` / `pnpm lint` が緑であることを再実測した。
