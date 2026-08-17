# 対応マトリクス: design-review Round 1

指摘はすべて **対応する**。反論は 1 件も無い。
うち 3 件の [Critical] は、実測 (`probe-scope-and-r2.mjs`) で指摘が正しいことを確かめてから直した。

## 施策 1

### [Suggestion] 「書式変更時は集合一致が赤になる」の表現が強い

- 判断: 対応する
- 対応内容: 「抽出結果が変わり集合一致で気付ける**ことが多い**が、
  別の最上位らしい文字列を拾う誤解析まで防げるわけではない」に弱めた。

## 施策 2

### [Warning] `COMPILED_VALUE_EXEMPT_TOKENS` と `DERIVED_COLOR_TOKENS` の集合関係が未固定

- 判断: 対応する（提案の前者 = 派生色は全件値免除、を契約にする）
- 対応内容: 「**派生トークンは全件が値免除である**」を契約として宣言のコメントに書き、
  施策 4 に `Object.keys(COMPILED_VALUE_EXEMPT_TOKENS)` と `DERIVED_COLOR_TOKENS` の
  集合一致テストを追加した。免除の理由が 30 文字以上あることも同時に見る。

### [Warning] `rounded` / `typography` を checked と宣言しながら子の母集団が未固定

- 判断: 対応する
- 根拠: 指摘のとおり。`typography.subtitle` や `rounded.xl` を DESIGN.md に足しても
  固定配列 (`TYPOGRAPHY_RAMPS` / `RADIUS_TOKENS`) に入らず見逃す。
  「checked と宣言しているのに全項目を見ていない」は説明として嘘になる。
- 対応内容: 施策 1 に `designTypographyNames()` を追加し、施策 4 に
  **「検査の母集団」describe** を新設して 3 つの集合一致を固定した。
  1. DESIGN.md typography の子キー ⇔ `TYPOGRAPHY_RAMPS`
  2. tokens.css の `@utility text-*` ⇔ `TYPOGRAPHY_RAMPS`
  3. DESIGN.md rounded のキー ⇔ `RADIUS_TOKENS`
  併せて `kind: "checked"` の意味を「**担当がいる**ことを表すのであって全項目網羅の主張ではない。
  網羅は母集団の集合一致が別に固定する」と型のコメントに明記した (施策 4 の [Warning] への対応も兼ねる)。

### [Suggestion] `satisfies readonly string[]` は綴りを検証しない

- 判断: 対応する
- 対応内容: `satisfies` を外して `readonly string[]` の型注釈にし、
  「compile-time に typo を検出する」という説明を削除した。

## 施策 3

### [Critical] `cssVariables()` の走査範囲が広すぎ、`@theme` 由来を保証していない

- 判断: 対応する
- 根拠: **実測で指摘が正しいことを確認した**。`@theme` を `:root` に書き換えると、
  全走査では `--color-primary` が `#2563eb` のまま取れて**緑で通る**。
  `@layer theme` に限定すると `undefined` になり赤くなる。
  正常時のテーマ変数は `@layer theme > :root, :host` の 1 か所だけに出ている (18 件)。
- 対応内容: `cssVariables()` を **`themeVariables()`** に作り替えた。
  - `@layer theme` の at-rule 配下だけを見る
  - その**直接の子**である `:root` / `:host` の規則だけを見る (`rule.parent !== layer` で入れ子を除く)
  - 規則の**直接の子宣言**だけを取る
  - 同名で値の違う宣言があれば `conflicts` に集め、**空であることをテストが確かめる**
  - 絞り込みの理由をファイル冒頭の「走査範囲を絞る理由 (重要)」に書いた

### [Critical] `declarationsOf()` が同一 selector の全規則と全子孫を混ぜている

- 判断: 対応する
- 対応内容: 2 つに分けた。
  - `utilityRules()`: selector 完全一致の規則の**直接の子宣言**を**出現ごと**に返す
  - `soleRule()`: 出現がちょうど 1 件であることを確かめてから返す (0 件も重複もここで落ちる)
  - `hoverDeclarations()`: 外側規則の中の **`&:hover` 入れ子**を明示的に辿ってから宣言を拾う
    (`&:focus` の中は拾わない)。`@media (hover: hover)` は Tailwind の実装詳細なので契約にせず、
    その中の宣言は拾う (指摘の許容範囲どおり)
  - 併せて C / E / B は**キー集合ごと**一致させる形にした (余分なプロパティも検出する)

### [Critical] R2 の割り当てが誤っている

- 判断: 対応する
- 根拠: 実測の結果は指摘とほぼ一致した。特に **E は緑**である
  (`.rounded-md` は既定テーマから `border-radius: var(--radius-md)` を出し続ける)。
  「E は `var(--radius-*)` でなくなる」という旧記述は誤りだった。
- 対応内容: R2 の行を実測どおりに書き直した。
  - 赤: **A の色** (`@layer theme` に出ない) / **A の radius** (既定の `0.375rem` になる) /
    **A の font** (既定の先頭 family になる) / **C** / **D** / **F**
  - 緑: **B** (`@utility` は残る) / **E** / **G**
  - 「A をテーマ層限定にしたからこそ A が赤くなる」ことを R 表の下に注記した

### [Warning] B が「4 プロパティを持つ」だけで余分を検出しない

- 判断: 対応する
- 対応内容: 宣言の**キー集合ごと**の一致に変えた。DESIGN.md に `letterSpacing` が無い ramp に
  `letter-spacing` を勝手に足した場合も赤になる。テスト名も
  「宣言が DESIGN.md と過不足なく一致する」に変えた。

### [Warning] font-family は先頭 family しか比較していないのに保証が強い

- 判断: 対応する（先頭 family に限定する側を選ぶ）
- 根拠: DESIGN.md は `"Noto Sans JP, sans-serif"`、tokens.css は 10 個近いフォールバック列で、
  そもそも全体一致はしない。DESIGN.md 側がフォールバック列の正本を持っていない。
- 対応内容: テスト名を「`--font-sans` の**先頭 family**と一致する」に変え、
  ファイル冒頭の「保証しないもの」と D27 の「見ていないもの」に
  「font-family の先頭以外のフォールバック列」を明記した。

### [Warning] G の行ベースのコメント除去が複数行コメントを誤解析する

- 判断: 対応する
- 対応内容: 行の走査をやめ、`postcss.parse()` した AST の**先頭ノード**を見る形にした。
  コメントノードを除いた先頭 2 つが `@import` の at-rule であり、params が
  `tailwindcss` / `./tokens.css` であることを確かめる。

## 施策 4

### [Warning] TODO の `toContain` は表の実在を保証しない

- 判断: 対応する
- 対応内容: `docs/TODO.md` / `docs/TODO-closed.md` の各行を
  `/^\|\s*(T\d{3,})\s*\|/` で走査して **ID 列だけ**を集合にし、`Set#has` で完全一致させる。
  ID が 1 件も取れなかったら抽出の空振りとして落とす。

### [Warning] `checked.by` は網羅を保証しない

- 判断: 対応する（施策 2 の母集団修正で実体を直し、記述も分けた）
- 対応内容: 上記「検査の母集団」describe を新設して網羅を実際に固定し、
  型のコメントで「`checked` は担当がいることを表すだけ」と区別を明記した。

## 施策 5

### [Warning] `contrast-invariant.test.ts` の実在を確かめていない

- 判断: 対応する
- 対応内容: `EXTERNAL_GATE_FILES` という定数に分け、`statSync(...).isFile()` を確かめてから
  母集団へ入れる形にした。ファイルを消して文書の行を残した場合はここで落ちる。

### [Warning] `listed` が節全体のバッククォート文字列を拾っている

- 判断: 対応する
- 対応内容: `tableCellLiterals(section, column)` を新設し、
  **表の行のセル**だけを見る形にした (`|` 始まりの行をセル分割して指定列の最初のバッククォートを取る)。
  Canonical source 表は 2 列目、責務境界表は 1 列目を見る。
  散文に同じフルパスを書いても通らない。

### [Warning] 自動検出できる範囲が `tests/js/styles/` 直下だけ

- 判断: 対応する
- 対応内容: ファイル冒頭の「保証しないもの」に
  「自動で母集団に入るのは `tests/js/styles/` 直下と `EXTERNAL_GATE_FILES` に明示登録した分だけ。
  別の場所へ足しても自動では見つからない」と書いた。

## 施策 6

### [Warning] 「デザイントークンの検査は 4 本ある」が完全な一覧と読める

- 判断: 対応する
- 対応内容: 「**本節で責務境界を管理する**デザイントークン検査は 4 本ある
  (DS purity 系など、トークンの値以外を見る検査は本節の管理対象ではない)」に書き換えた。

### [Warning] 「spacing はどの検査も見ていない」が曖昧

- 判断: 対応する
- 対応内容: 「`spacing:` は**値も tokens.css への実装写像の有無も検査していない**」に限定した。

### [Warning] 表の説明が施策 3・5 の修正後でないと成立しない

- 判断: 対応する
- 対応内容: 施策 3 のテーマ層限定と施策 5 の実在確認を先に直した上で、
  表の文言を確定させた (「`@theme` が解釈されない」は themeVariables の限定によって実際に成立する)。

## 施策 7

### [Warning] D27 の保証が実装より強すぎる

- 判断: 対応する
- 対応内容: 「揃え続ける不変条件」を
  「**inventory に登録された DESIGN.md 対応の**色・角丸・文字組が、生成 CSS に同じ値で現れ、
  対応する utility 名がその変数へ解決すること」に限定した。
  併せて「見ていないもの」節を新設し、`--color-primary-soft` の値 (出現のみ) /
  font-family の先頭以外 / 生成 CSS より先 を明記した。
  母集団の取りこぼし (typography / radius) は施策 4 の集合一致で塞いだので、
  「固定 inventory に未登録の新しい項目」は除外事項から外れた。

### [Warning] `決めた日 | 2026-08-18` が未来日

- 判断: 対応する
- 根拠: 確認したところ `TemplateDivergenceLedgerFormatTest` は `CarbonImmutable::today()` を
  基準日にしており、`config/app.php` の timezone は **UTC** である。
  日本時間 2026-08-18 02:48 は UTC ではまだ 2026-08-17 なので、指摘のとおり未来日になる。
- 対応内容: `2026-08-17` に直し、施策 7 の冒頭に
  「日本時間の深夜に実装すると UTC ではまだ前日である。実装時点の UTC 日付を入れること」
  という注意を書いた。

## 実装順序

### [Warning] 施策 3〜5 は通常の Red-Green にならない

- 判断: 対応する
- 根拠: 指摘のとおり。`tokens.css` の現在の中身は正しいので、施策 3・4 のテストは
  書いた瞬間に緑になる。
- 対応内容: §実装順序 を書き直し、**通常の Red-Green が成り立つもの (施策 5・6) と
  感度確認 (故障注入) で確かめるもの (施策 3・4)** に分けた。手順は提案どおり
  「テストを追加 → 基準結果を確認 → 故障を 1 件ずつ注入 → 狙った assertion の失敗を確認 →
  必ず戻して緑を確認」とし、記録は
  `red-verification.md` に**「想定した assertion」と「実際に落ちた assertion」を別の列で**残す、
  食い違ったら実測を正本にして設計を直す、と明記した。
