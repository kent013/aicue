# 対応マトリクス: design-review Round 5

Critical 7 件・Warning 6 件 (**行頭のラベル**の機械カウント `grep -c '^\[Critical\]'` 等。
Round 5 の横断指摘に従い、数え方を行頭に限定した)。**すべて対応する** (反論は 0 件)。

## S1

### [Warning] 受理する `AtRule` の形が実装可能な粒度まで閉じていない

- 判断: **対応する**。**postcss の実挙動を実測して期待値を一意に決めた**
  (「実測で決める」と書いて設計を宙吊りにしない)
- 実測 (postcss 8.5 / `postcss.parse(source, { from })`):

  | 入力 | 結果 |
  |---|---|
  | `@theme { --a: 1px; }` | `AtRule(name="theme", params="")` + 子 `Decl` |
  | `@theme-extra { … }` | `AtRule(name="theme-extra")` — **別物** |
  | `@/* c */theme { … }` | **例外** (`CssSyntaxError: At-rule without name`) |
  | `@theme;` | `AtRule(name="theme")` で **`nodes === undefined`** |
  | `@theme foo { … }` | `AtRule(name="theme", params="foo")` |
  | `--x: "@theme { }";` | `Decl` のみ (**at-rule にならない**) |
  | `@theme { --f: "a{b"; --g: 2px; }` | 宣言 2 件を正しく採る (文字列の中の `{` を誤認しない) |
  | `@theme { --a: 1px; --a: 2px; }` | `Decl` が 2 件現れる (**呼び出し側が重複を検出する**) |
  | `@theme { :root { … } }` | 子に `Rule` が現れる |

- 対応内容 (受理契約を 6 条で閉じる。外れたものはすべて**例外** = i20):
  1. `@theme` は `name === "theme"` の完全一致・**`params === ""`**・**`nodes !== undefined`**
  2. 宣言はトップレベル `@theme` の**直接の子 `Decl`** だけ。子に `Rule` / `AtRule` があれば例外
  3. 同名宣言が 2 件以上あれば例外
  4. `@utility` は**ルート直下**・`params` が `^text-[a-z0-9-]+$`・`nodes !== undefined`・
     直接の子は `Decl` だけ・同じ `params` の重複は例外
  5. 構文エラー (未終端コメント / 未終端文字列 / 閉じないブロック / `@/* c */theme`) は
     postcss の例外をそのまま伝播させる
  6. **`ThemeBlock.body` は削除する** (どこからも使っていない。
     使わない出力を持たない = 共通規約 (d))

## S2

### [Critical] `ts.createScanner()` は字句解析器であり、template 補間の構文保証を提供しない

- 判断: **対応する** (指摘のとおり。`` `${cond ? "}" : v}` `` は scanner 単独では解けない)
- 対応内容: `.ts` は **`ts.createSourceFile()` で AST 化**する。
  - **parse diagnostics が 1 件でもあれば解析失敗**にする (括弧不整合などの構文エラーも
    fail-closed になる。scanner では字句エラーしか拾えなかった)
  - ノード種別で分類する —
    `StringLiteral` / `NoSubstitutionTemplateLiteral` は**単位**、
    `TemplateExpression` (置換つき) は **`interpolated` の判定不能**
  - **`ts.createScanner()` は使わない** (「scanner 単独で補間を解決する」という主張を撤回する)

### [Critical] 公開結果型に解析診断の格納先が無い

- 判断: **対応する** (「診断が 1 件でもあれば gate を落とす」を型で実装できなかった)
- 対応内容: `SourceClassUsageScan` に `diagnostics: readonly ClassScanDiagnostic[]` を足し、
  `ClassScanDiagnostic = { file, reason }` の `reason` に
  `parse-failed` / `ts-diagnostic` / `svelte-parse-failed` を持たせる。
  集約ラッパーは例外を握らず**ファイル名つきの診断**として集める
  (準拠実装 `file-input-scan.ts` の `parse-failed` と同じ形)。

### [Critical] class token の区切り規則と固定検体の期待値が両立していない

- 判断: **対応する** (指摘のとおり。`bg-primaryあ` が `bg-primary` へ縮退して**通ってしまう**)
- 対応内容: 分解を **2 段**にする。
  1. まず **CSS の空白** (空白 / タブ / 改行 / CR / FF) で class 候補へ分割する
  2. 候補**全体**を許可文字集合で検証し、**許可外の文字が 1 つでもあれば候補全体を
     `unparsable-token`** にする (`bg-primaryあ` も `bg-(--var)` も候補全体が未解決になる)
  3. そのうえで variant / important / alpha / utility を分解する
  「許可文字以外はすべて区切り」という旧規則は**撤回する**。

### [Warning] variant 状態の継承が単一 modifier の例にしか定義されていない

- 判断: **対応する** (`bg-surface sm:bg-neutral sm:hover:text-danger` から
  `danger on surface` という**実在しない組**が出る)
- 対応内容: variant 条件間の包含はモデル化**しない** (Tailwind の variant の意味論を
  自前で再実装することになり割に合わない)。代わりに
  **異なる variant 列が同じ channel へ影響する単位を `variant-composition` として判定不能**へ落とす
  (`UndecidableReason` へ追加)。固定検体に `sm:` / `hover:` / `sm:hover:` の混在を置く。

## S3

### [Warning] `scanCssVarReferencesSource()` の解析方式が未定義

- 判断: **対応する**
- 対応内容: 入力を**解析器の出力に限る**。
  - `resources/css`: **postcss AST の `Decl.value` と対象 at-rule の `params` だけ**を入力にする
    (`Comment` ノードは入力にしない = コメントの中の `var()` を生きた参照に数えない)
  - `resources/js`: **S2 が確定した AST 上の文字列**だけを入力にする
  - `var(` の受理は「括弧の対応が取れ、第 1 引数が `--` で始まる識別子」まで。
    fallback (`var(--a, var(--b))`) は入れ子で再帰的に拾う
  - 未終端の関数・解析できない値は**診断**として残す
  - 正負例: コメントの中 / CSS 文字列の中 / 入れ子 fallback

## S8

### [Critical] `scanMarkdownLines()` が container 内の囲みコードを認識できない

- 判断: **対応する**。**`>` だけを剥がして「fence に見えたら解析失敗」にする** (fail-closed)
- 根拠: 迂回できるのは **blockquote 接頭辞つきの fence** だけである。
  list の内側の fence は内容開始列が 3 以下なので `^ {0,3}` の fence 判定に**そのまま掛かり**、
  4 以上なら 4 連続空白の禁止に掛かる。したがって扱う必要があるのは `>` の 1 種だけで、
  container 文法の列挙は要らない。
- 対応内容: 囲みコードの外の各行について、行頭の `>` (と各 `>` の直後の空白 1 個まで) を
  **繰り返し剥がした残り**が fence 開始に見えるなら、**解析失敗**にする
  (「正しく囲みコードとして扱う」のではなく「読めない文書として落とす」)。
  実測: `docs/design-system.md` は blockquote 0 行・fence 0 行、
  `DESIGN.md` は blockquote 2 行・fence 0 行なので、現時点で偽陽性は起きない。
  固定検体に blockquote 内 fence / 入れ子 blockquote 内 fence / list 内 fence とその中の `###` を置く。

### [Warning] 実施順が S8 → S9 なのに S8 が S9 の新設物を必須としている

- 判断: **対応する**
- 対応内容: **実施順を S9 → S8 へ入れ替える** (`markdown-lines.ts` は S9 が新設する)。
  節の物理順も入れ替える。

## S9

### [Critical] 「container 文法を一切扱わない」は fence 除外には適用できない

- 判断: **対応する** → S8 の Critical と同じ対処 (`>` を剥がして fence に見えたら解析失敗)
- 対応内容: **2 つの契約を分けて書く**。
  - **契約 A (非描画領域の除去)**: HTML コメントと囲みコードを落とす。
    blockquote 接頭辞つきの fence は**解析失敗**にする (扱えないものを通常本文にしない)
  - **契約 B (字下げの禁止)**: タブと 4 連続空白を拒否する。
    こちらは container 文法を扱わない
  「container 解析不要」は**契約 B にだけ**掛かる主張である、と明記する。

### [Critical] 提示した証明の前提が誤っている (「marker が消費する空白は高々 1 個」)

- 判断: **対応する** (指摘のとおり。CommonMark の list marker の padding は 1〜4 で、
  1 個に限定されない。結論は成立するが**論証が誤り**だった)
- 対応内容: 証明を Codex の提示した形へ差し替える。
  1. すべての有効な container prefix を消費した後の**内容開始列**を基準にする
  2. 字下げコードには、その基準から**さらに 4 列以上**の字下げが要る
  3. タブを禁じた場合、その**追加 4 列を作れるのは連続した U+0020 だけ**である
  4. list marker の幅や padding は**内容開始列を決める prefix 側**であり、追加 4 列の代用にならない
  5. gate は全行を見るので、コードブロックの**少なくとも先頭の非空行**で 4 連続空白を検出する
  固定検体を追加する — marker padding 1〜4 / ordered marker 1〜9 桁と `.` `)` /
  list の最初の block が字下げコードの場合 / 後続 block が字下げコードの場合 /
  blockquote と list の異種入れ子 / lazy continuation が字下げコードではない**正例**。

## S12

### [Critical] D51 が S8 / S9 の未確定保証を確定事項として記述している

- 判断: **対応する**
- 対応内容: D51 の「揃え続ける不変条件」を**2 つの保証に分けて**書き直す —
  (a) 非描画領域の除去 (HTML コメントと囲みコード。**blockquote 接頭辞つき fence は
  解析失敗にする**) と (b) 字下げコードの見逃し 0 (タブ禁止 + 4 連続空白禁止)。
  「完全な CommonMark 解析ではない」ことと、対応する fence 構文・
  **解析失敗にする構文**を明記する。

## 横断指摘

### [Warning] 件数の数え方が本文中のラベルへの言及まで数えている

- 判断: **対応する**
- 対応内容: 件数の正本を **行頭のラベル** (`^\[Critical\]` / `^\[Warning\]` / `^\[Suggestion\]`) に
  限定し、全ラウンドを再集計した —
  Round 1 = C12 / W11 / S1、Round 2 = C7 / W11 / S1、Round 3 = C6 / W10 / S0、
  Round 4 = C2 / W8 / S1、Round 5 = C7 / W6 / S0。

### [Warning] S9 の「方針の変遷」が最終形まで更新されていない

- 判断: **対応する**
- 対応内容: 変遷表に `Round 4 → 5` の行を足し、
  「タブ禁止 + 4 連続空白禁止 (契約 B) + blockquote 接頭辞つき fence は解析失敗 (契約 A)」まで更新した。
