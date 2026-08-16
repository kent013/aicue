# 対応マトリクス: impl-review Round 2

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 2 / Suggestion 1)

## [Warning] TakePreviewPanel の境界条件 (take 同一 / URL のみ変化) に回帰テストが無い

- 判断: **対応する。ただし指摘された形 ($effect の購読 + それを検査するテスト) は採らない**
- 根拠: 指摘のとおりテストが無かった。しかし実際に書いてみて分かったのは、
  **この境界は component テストでは isolate できない**ということである。
  `@testing-library/svelte` の `rerender()` は props をまとめて更新するため、
  `take` に触れず `cut` だけを変えても `$effect` は再実行される。実測として
  `void playbackUrl;` を外しても新テストは緑のままだった = 提案された形のテストは
  **degenerate PASS になる** (禁止事項に照らして、緑になるだけのテストを足す意味は無い)。
- 対応内容: 購読で直すのをやめ、**失敗の持ち方を変えた**。
  `TakePreviewPanel` / `TakePreviewDialog` の両方で、失敗を真偽値ではなく
  **失敗した URL** (`failedUrl`) として持ち、`imageFailed` を
  `failedUrl === playbackUrl` の `$derived` にした。失敗は「その URL の性質」であって
  component の状態ではないので、テイク切り替えでも署名 URL 再取得でも
  **リセットのための購読を書かずに構造的に外れる** (購読の書き漏らしという失敗様式が消える)。
  `$effect` は 2 component とも撤去した。
  テストは `tests/js/components/features/manual/TakePreviewPanel.test.ts` を新設し (7 件)、
  「URL が変われば失敗表示が外れる」と、その**負のコントロール**として
  「URL が変わらない再描画では失敗表示が残る」の 2 本を対にした
  (後者があるので「無条件に失敗表示を消す実装」では緑にならない)。

## [Warning] `substr_count($contents, 'run([')` は表記揺れで無検出になる

- 判断: **対応する**
- 根拠: 指摘のとおり。`->run ([` や `->run(\n    [` は同じ呼び出し表現であり、
  動的構築とは別問題である。「起動点を足したら黙って通らない」という主張と実装が一致していなかった。
- 対応内容: 数え方を `preg_match_all('/->\s*run\s*\(\s*\[/', …)` に変え、空白・改行の揺れを吸収した。
  併せて docblock の「保証しないもの」へ、**配列を変数へ組み立ててから `->run($args)` で渡す形**・
  **`start()` / 静的 `Process::run()`**・vendor 経由の起動は数に入らないことを明記した
  (字句検査の限界を「動的構築」だけに丸めない)。
  AST/tokenizer 化は採らなかった — 母集団 3 ファイルに対して検査機構の複雑さが釣り合わず、
  引数の**並び**は既に Unit テスト (Process::fake の argv) が固定しているためである。

## [Suggestion] StillMaterialConsistencyTest の冒頭コメントが内容と食い違っている

- 判断: **対応する**
- 根拠: 指摘のとおり。Round 1 で C1 通しを同ファイルへ追加したのに、冒頭は
  「C1 は既存テストが持つ」のままだった。保証範囲を誤読させる。
- 対応内容: 冒頭コメントを実態へ更新した (C1 通しは本ファイルが固定する /
  C2・C3 は既存挙動なので RenderPipelineTest・RenderTriggerTest に委ねる、と明記)。
