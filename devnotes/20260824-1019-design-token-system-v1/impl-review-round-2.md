Round 1 の主要な fail-open は概ね閉じています。ただし、共通規約 (b)/(d) と乖離登録の保証表現に残件があります。

### `tests/js/styles/class-usage.ts`

判定: コロン入り任意値、Svelte `<style>`、alpha パーサ重複への対応は妥当です。固定検体も実装分岐を直接通っています。

「置換だけの補間」を保証範囲外にする判断自体は、AGENTS.md 共通規約 (b) に適合可能です。一般の TypeScript template expression をすべて class と断定できない、という理由も妥当です。docblock で具体的な迂回形と非保証を明記し、限界を固定検体にした点も正しいです。

ただし、この判断は「穴を検出で閉じた」のではなく「保証命題を狭めた」ものです。最終的な文書・gate の主張も例外込みに狭まっている必要があります。

### `tests/js/styles/token-reference-closure.test.ts`

[Warning] gate の docblock に「走査単位の外の動的 class は見ない。既知の入口は class-usage.ts が deny する」とありますが、`` `${classes}` `` と `class={classes}` は今や明示的に既知であり、deny もしません。この文は保証範囲の縮小と矛盾します。「deny するのは `unsupportedEntryPoints()` が列挙する3入口だけで、置換だけの補間は非保証」と明記してください。

それ以外の負例追加は適切です。コロン入り任意値が occurrence ごと消えないこと、Svelte `<style>` が同じ CSS 解析経路を通ることを両方向で固定できています。

### `tests/js/styles/component-doc-parity.test.ts`

[Warning] `ComponentFileKindSpec.kind` は switch に入っただけで、分類結果を決めていません。実際の挙動は引き続き `requiresSection` と `.types.ts` の直書きで決まります。例えば `{ kind: "helper", requiresSection: true }` が component として扱われても gate は通ります。これは `kind` が意味的な判定に使われていないため、共通規約 (d) への対応として不十分です。

`kind` を正本にして、次のように分岐させるのが自然です。

- `component`: Components 母集団へ追加
- `types`: 対応する component の存在確認
- `helper`: 母集団へ追加しない
- default: `never`

そうすれば `requiresSection` 自体を削除でき、矛盾した組合せも型・実装の両方から消せます。

ルート直下ファイルの未分類化と `usedFileKinds` の集合一致は正しく閉じています。

### `tests/js/styles/design-md.ts`

判定: 指摘した fail-open は閉じています。`line === "## Components"` によって字下げコード内の偽見出しを受理せず、対応する負例も同じ純粋入口を通っています。

0〜3空白を許す一般的な ATX 見出しより厳しい契約ですが、「行頭から始まる見出しだけ」という保証範囲が明記されているため問題ありません。

### `docs/template-divergence.md`

[Warning] D56 は依然として保証を広く書きすぎています。「タブと4連続空白が現れたら失敗する」契約を `design-system-docs.test.ts` と `component-doc-parity.test.ts` の双方が保証する、と読めますが、後者は DESIGN.md の `forbiddenIndentLines` を消費していません。今回閉じたのは Components 見出しの字下げであり、DESIGN.md 全体のタブ・4連続空白拒否ではありません。

D56 は次の2つを分けて記述する必要があります。

- `docs/design-system.md`: タブ・4連続空白を全面的に拒否
- `DESIGN.md §Components`: Markdown 診断を拒否し、対象見出しは行頭開始だけを受理

また、D55 の絶対表現「画面に実在する組は必ず母集団に入る」は、置換だけの補間を除外した現在の保証とは厳密には一致しません。「走査器が保証する文字列リテラルの範囲では」を不変条件本文にも入れると、後段の「保証しないもの」と矛盾しません。

### `tests/js/architecture/contrast-invariant.test.ts`

判定: Round 1 の2件は閉じています。

- Badge 全 tone と主要 Button 組を実リポジトリ走査で固定
- 是正前の5組と是正後の値を独立リテラルで固定
- danger が据え置きで通る正例も追加

台帳や現在の写像から独立した負のコントロールになっており、検出力の裏取りとして適切です。

### `tests/js/styles/class-usage.test.ts`

判定: tone / variant の意味比較は十分に強化されています。キー集合一致もあるため、新 variant の検体追加漏れも検出できます。

置換だけの補間の検体は「検出力」ではなく「非保証範囲の pin」であることが明示されており、共通規約 (b) の扱いとして妥当です。

### `tests/js/styles/inventory.ts`

判定: `DesignColorKey`、pending の責務記述はいずれも適切に修正されています。

### `tests/js/styles/theme-map.ts`

判定: 未使用の `offset` は削除され、共通規約 (d) に適合しました。

### `tests/js/styles/tokens.test.ts`

判定: コメントの旧 hex は修正済みで、生成形の検査内容にも問題は見当たりません。

### その他

`LedgerPins.php`、`adoption-debt.tsv`、`canonical-source-parity.test.ts`、`markdown-lines.ts`、`theme-map.test.ts`、`design-system-docs.test.ts` は提示差分の範囲で新たな問題は見当たりません。

Round 1 の Critical 5件については、4件が実装で閉じ、置換だけの補間は共通規約 (b) に沿った保証範囲縮小として受け入れ可能です。ただし、その縮小が利用側 docblock と D55/D56 の保証本文へ一貫して反映されていません。また、component file の `kind` は意味的にはまだ判定に使われていません。

全体判定: **CHANGES_REQUESTED**