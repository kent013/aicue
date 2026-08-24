# 対応マトリクス: impl-review Round 1

Codex (`gpt-5.6-sol` / reasoning=high) の Round 1 判定は **CHANGES_REQUESTED**
(行頭ラベルの機械カウントで Critical 5 / Warning 10 / Suggestion 5)。
**Critical 5 件と Warning 10 件はすべて対応**、Suggestion 5 件も 4 件対応・1 件は記述訂正で対応した。

★実測の前提: 指摘された 3 つの fail-open (置換だけの補間 / 任意値の中のコロン /
Svelte `<style>`) は**いずれも現在のリポジトリでは出現 0 件**である
(`grep` で確認)。よって修正しても走査結果は 1 件も変わらず、閉じたのは**将来の穴**である。

## [Critical] 置換だけの template literal が無言で消える (`` `${classes}` ``)

- 判断: **対応する (ただし検出ではなく保証範囲の明示的な縮小で閉じる)**
- 根拠: 静的部分にテーマ名前空間の語を 1 つも持たない補間は、class 記述なのか
  他用途の文字列なのかを**静的に判別できない**。全 `TemplateExpression` を判定不能にすると
  `` `テイク ${i}` `` のような無関係な文字列まで台帳に載り、台帳が統制として機能しなくなる。
  AGENTS.md 共通規約 (b) は「保証範囲の外にする構文は docblock へ明記し、明記したなら
  その構文について検出力を主張しない」「利用側 gate は検出力の主張をその構文を除く形へ
  明示的に狭める」ことを認めている。
- 対応内容:
  1. `class-usage.ts` の「保証しないもの」へ本形を名指しで追記し、
     「この形で組み立てると全 gate を迂回できる / 止めているのは規約と人のレビューである」と明記
  2. **固定検体で限界を pin** した (`class-usage.test.ts`:
     「静的部分に監視対象語を持たない補間は単位を作らない」)。
     対比として `` `${classes} bg-primary` `` は `interpolated` になることも同じ it で固定
  3. 乖離登録 D55 の「保証しないもの」へ同じ限界を書き、恒久保証の過大評価を解消

## [Critical] `splitVariants()` が任意値の中のコロンを variant 境界と誤認する

- 判断: **対応する**
- 根拠: 真の fail-open。`text-[color:#ffffff]` は rest が `#ffffff]` になり
  `isWatchedCandidate()` が false を返すため、**hex 直書きなのに occurrence 自体が作られない**。
  文字検証に到達する前に候補から外れるので `unparsable-token` にもならない。
- 対応内容: `splitVariants()` を**角括弧の外のコロンだけ**で分割する形へ直した。
  負例 2 形を追加 (`text-[color:#ffffff]` は `unknown-token` になる /
  `[&_svg]:stroke-current` は従来どおり variant + utility に割れる)。
  `token-reference-closure.test.ts` にも「候補ごと消えずに落ちる」負例を追加。

## [Critical] Svelte AST の `css` ノードを走査していない (`<style>` の var 参照)

- 判断: **対応する**
- 根拠: S3 が「resources/js の var 参照を閉包する」と主張している以上、
  `.svelte` の `<style>` を見ないのは主張と実装の食い違いである。
- 対応内容: `svelteUnits()` が `ast.css.content.styles` を返すようにし、
  `scanCssVarReferencesSource()` が `<style>` の中身を**CSS と同じ postcss 経路**で読むようにした
  (CSS 読み取りを `collectFromCss()` に括り出し、`.css` と `<style>` が同一実装を共有する)。
  負例・正例の両方向を追加 (未知 var は `unresolved` / 写像 token は解決し診断 0 件)。

## [Critical] `parseDesignComponentSections()` が字下げした `## Components` を受理する

- 判断: **対応する**
- 根拠: `line.trim()` で見出しを探すと、`## Components` を字下げコード
  (契約 B が失敗させるのは docs/design-system.md だけで DESIGN.md には適用していない) へ
  移して S8 の双方向一致を迂回できる。
- 対応内容: 判定を `line === "## Components"` (行頭から始まる有効な ATX 見出し) へ変更。
  負例「字下げした `## Components` は受理しない」を `component-doc-parity.test.ts` に追加。
  D56 の「保証しないもの」へも明記した。

## [Critical] 上記 2 経路を通すと token-reference-closure が green のままになる

- 判断: **対応する** (上の 3 件の修正で閉じる)
- 対応内容: 指摘された 3 つの負例を純粋入口の検体として追加した
  (置換だけの補間 = 限界の pin / コロン入り任意値 = `unresolved` /
  `<style>` の未知 var = `unresolved`)。

## [Warning] `alphaOfSuffix()` が `parseCssColor()` と別の簡易パーサになっている

- 判断: **対応する**
- 根拠: 簡易パーサは `rgb(r g b / a)` を alpha と認識できず、
  「色表現の読み出しを 1 実装へ集約する」方針にも反する。
- 対応内容: `parseCssColor()` の結果から判定する形へ置き換えた。

## [Warning] 「補間内部の class 風文字列を二重に拾わない」テストが現在の実装を正解にしている

- 判断: **対応する (Critical 1 と一体)**
- 対応内容: 限界を明示する専用の it を追加し、対比 (`interpolated` が 1 件出る形) を同じ it で固定した。

## [Warning] Badge 全 tone / Button 全 variant の期待値が件数と kind しか見ていない

- 判断: **対応する**
- 根拠: 誤った fg/bg や誤った reason に分類されても通る。詳細設計の
  「全 tone / 全 variant が期待どおりの組へ分解される」を満たしていない。
- 対応内容: `EXPECTED_TONE_PAIRS` / `EXPECTED_VARIANT_PAIRS` を導入し、
  分解結果を `fg on bg` / `fg on bg/修飾率` / `!理由` の表記で**意味まで**突き合わせる形にした。
  キー集合が `TONE_CLASSES` / `VARIANT_CLASSES` と一致することを別に固定するので、
  件数は散文に書かない (設計の要求どおり)。

## [Warning] コロン入り任意値と `<style>` 内未知 var の負例が無い

- 判断: **対応する** (上記 Critical 2 / 3 の対応に含む)

## [Warning] `classifyComponentTree()` がルート直下ファイルを一度も分類しない

- 判断: **対応する**
- 根拠: `resources/js/components/New.svelte` は部品にも未分類にもならず**消える** (fail-open)。
- 対応内容: 走査根直下のファイルを `unclassifiedFiles` へ入れる形にし、負例を追加した。

## [Warning] `ComponentFileKindSpec.kind` が判定に使われず、使用済み suffix の集合一致も無い

- 判断: **対応する**
- 対応内容: `kind` を `switch` + `never` で網羅させ、`usedFileKinds` を集計して
  `COMPONENT_FILE_KINDS` のキーと**集合一致**させた (死んだ登録を落とす)。負例も追加。

## [Warning] 字下げされた `## Components` の負例が無い

- 判断: **対応する** (Critical 4 の対応に含む)

## [Warning] 「既知の要求組」が 2 例だけ

- 判断: **対応する**
- 対応内容: Badge の soft 背景を**全 tone** (5 組) へ広げ、Button 側も
  `neutral|primary` / `neutral|danger` / `text|border` の 3 組を要求する形にした。
  分解の意味の検査は `class-usage.test.ts` が担い、ここは「実リポジトリの走査から消えていない」
  ことを見る、と責務を明記した。

## [Warning] 「是正前の値では 5 組が AA 未達」が 1 組しか固定されていない

- 判断: **対応する**
- 対応内容: 5 組すべてを `PRE_CORRECTION_FAILURES` としてリテラルだけで固定し、
  是正前は AA 未達 / 是正後は充足を `it.each` で回す形にした。
  併せて「danger は是正前でも通る」= 一律に暗くしたのではないことの裏取りも追加した。

## [Warning] D55 / D56 が実態を過大評価している

- 判断: **対応する**
- 対応内容: 実装を閉じたうえで、閉じきれない 1 点 (置換だけの補間) を
  D55 の「保証しないもの」へ、見出しの受理範囲を D56 の「保証しないもの」へ明記した。

## [Suggestion] `CssVarReferenceScan.files` がどの gate からも参照されていない

- 判断: **対応する**
- 対応内容: `token-reference-closure.test.ts` の母集団検査で `files.length > 0` を要求する形にした。

## [Suggestion] `DeclaredPair.fg/bg` が `string` で typo が型で落ちない

- 判断: **対応する**
- 対応内容: `DesignColorKey = keyof typeof COLOR_TOKEN_MAP` を導出して `DeclaredPair` に使った。

## [Suggestion] `PENDING_CONTRAST_PAIRS` の責務記述が実装とずれている

- 判断: **対応する**
- 対応内容: 「各 reason を発火させる検体は `class-usage.test.ts` が担当する」と実装に合わせた。

## [Suggestion] `ThemeBlock.offset` が参照されていない

- 判断: **対応する**
- 対応内容: `offset` を削除した (共通規約 (d)。`body` を持たない理由と同じ)。

## [Suggestion] tokens.test.ts の H 節コメントが旧値のまま

- 判断: **対応する**
- 対応内容: 実測コメントの hex を `#1d4ed8` へ更新した。
