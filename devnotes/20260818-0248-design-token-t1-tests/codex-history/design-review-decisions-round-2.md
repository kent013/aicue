# 対応マトリクス: design-review Round 2

すべて **対応する**。反論は 1 件も無い。

## 施策 3

### [Critical] `hoverDeclarations()` が同一 selector の複数出現をまだ混ぜる

- 判断: 対応する
- 対応内容: 提案の 5 点をすべて実装する形へ書き直した。
  1. 外側 selector を `rulesWithSelector()` で出現ごとに取り、**ちょうど 1 件**を確かめる
  2. その**直接の子**から `&:hover` の Rule を型述語で絞り、**ちょうど 1 件**を確かめる
  3. `collectDeclarations()` を新設し、**直接の子宣言と at-rule 配下の宣言だけ**を集める
  4. 子孫に別の Rule (`&:focus` 等) があっても降りない
  5. 同名プロパティで値が違えば `conflicts` に入れ、テストが空であることを確かめる
- 併せて `utilityRules()` を `rulesWithSelector()` + `soleRule()` に整理し、
  `soleRule()` は規則の**直接の子宣言**だけを返す形に統一した
  (`themeVariables()` も `walkRules` をやめ、`@layer theme` の**直接の子**の Rule だけを見る形に揃えた)。

### [Warning] 「負のコントロール: @layer theme の外を拾わない」が負のコントロールになっていない

- 判断: 対応する
- 根拠: 指摘のとおり。theme 規則が 1 件以上あることしか見ておらず、
  `themeVariables()` を全走査へ戻しても赤にならない。
- 対応内容: 提案どおり **fixture を使った恒久テスト** (`describe("tokens: ヘルパの仕様固定 (fixture)")`)
  を追加した。壊れた形を含む小さな CSS を `postcss.parse()` で読ませ、
  - theme 層の正しい値を採ること / 層外の同名・異値宣言を採らないこと
  - theme 層内の `@media` の中を採らないこと
  - theme 層内の競合だけが `conflicts` に入ること
  - `soleRule()` が入れ子の Rule の宣言を混ぜないこと
  - `hoverDeclarations()` が `&:focus` を混ぜないこと
  を直接確かめる。

### [Warning] `conflicts` の空確認が密閉の層だけ

- 判断: 対応する
- 対応内容: F (経路の層) に `expect(themeVariables(routed).conflicts).toEqual([])` を追加した。

### [Warning] R6 のダミーファイルが空だと vitest 自体が落ちる

- 判断: 対応する
- 対応内容: R6 の行に「**常に成功する `it()` を 1 つ持つ有効なテストファイル**にする
  (空ファイルだと vitest が『テストなし』で落ち、狙った集合一致の失敗を確認できない)」と明記した。

## 施策 7

### [Warning] 「対応する utility 名がその変数へ解決する」が typography に当てはまらない

- 判断: 対応する
- 根拠: 指摘のとおり。typography ramp は `font-size` / `font-weight` / `line-height` を
  literal で出し、変数を参照するのは `font-family` だけである。
- 対応内容: メタ表と引用部を提案の文へ差し替えた
  (「色と角丸の utility は対応する変数を参照し、typography の utility は期待する宣言を
  過不足なく持つこと」)。引用の直後に、色・角丸と typography で出力の形が違うことの注記も足した。
