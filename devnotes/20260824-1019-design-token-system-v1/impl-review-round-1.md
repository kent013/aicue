仮説「S1〜S12 が設計どおり fail-closed で固定された」は棄却です。報告されたテストは green ですが、S2/S3/S8 の中心保証を迂回できる入力があります。

### `tests/js/styles/class-usage.ts`

[Critical] 置換だけの template literal が無言で消えます。`typescriptUnits()` / `svelteUnits()` は静的 quasi に監視対象語がある場合だけ `interpolated` を生成するため、`` `${classes}` `` は occurrence・pair・entry point・diagnostic のすべてが 0 件です。設計上は「完成した class 文字列の補間」を `interpolated` 台帳へ載せる契約なので、S3/S5/S7 をまとめて迂回できます。

[Critical] `splitVariants()` が単純な `candidate.split(":")` なので、任意値内部のコロンを variant 境界と説認します。例えば `text-[color:#ffffff]` は rest が `#ffffff]` となって `isWatchedCandidate()` が false を返し、hex 直書きなのに occurrence 自体が作られません。`text-[color:var(--color-typo)]` も同様です。括弧を許可しない検査へ到達する前に候補から外れるため fail-open です。

[Critical] Svelte AST の `css` ノードを走査していません。`svelteUnits()` が歩くのは `fragment` / `instance` / `module` だけなので、`<style>.x { color: var(--color-typo) }</style>` は `scanCssVarReferencesSource()` から見えません。「resources/js の var 参照を閉包する」という S3 の主張と食い違います。`<style>` を解析するか、入口を明示的に deny して保証範囲を狭める必要があります。

[Warning] `alphaOfSuffix()` が `parseCssColor()` と別の簡易パーサになっています。後者が受理する `rgb(r g b / a)` を前者は alpha と認識できません。色表現の読み出しを一実装へ集約する方針にも反するため、`parseCssColor()` の結果から判定すべきです。

[Suggestion] `CssVarReferenceScan.files` は収集されていますが、どの gate も参照していません。共通規約 (d) に従い、削除するか母集団判定に使用してください。

### `tests/js/styles/class-usage.test.ts`

[Warning] 「補間内部の class 風文字列を二重に拾わない」テストは `occurrences === []` しか確認しておらず、`interpolated` pair まで消えた現在の実装を正解にしています。occurrence は 0 件でも、判定不能 pair がちょうど1件必要です。

[Warning] Badge 全 tone は pair の件数と kind、Button 全 variant は `pairs.length > 0` しか検査していません。誤った fg/bg や誤った reason に分類されても通るため、詳細設計の「全 tone / 全 variant が期待どおりの組へ分解される」を満たしていません。

[Warning] `text-[color:#ffffff]` のようなコロン入り任意値と、Svelte `<style>` 内の未知 var の負例がありません。上記 fail-open を先に再現する検体が必要です。

### `tests/js/styles/token-reference-closure.test.ts`

[Critical] 上記2経路を通すと、未知 token を追加しても本 gate は green のままです。少なくとも以下を純粋入口の負例として固定する必要があります。

- 置換だけの template literalが `interpolated` になる
- コロンを含む任意値が unresolved になる
- Svelte `<style>` 内の未知 `var()` が unresolved になる

### `tests/js/styles/design-md.ts`

[Critical] `parseDesignComponentSections()` が `scan.forbiddenIndentLines` を消費せず、Components 見出しを `line.trim()` で探しています。そのため、次のように `## Components` を字下げコードへ移しても節として認識され、S8 が green になり得ます。

```text
    ## Components
### Button
...
```

DESIGN.md 全体には frontmatter の4空白字下げがあるため単純な全件拒否はできませんが、Components 見出しは `^ {0,3}## Components...$` のように有効な ATX 見出しだけを受理すべきです。この負例も `component-doc-parity.test.ts` に必要です。

### `tests/js/styles/component-doc-parity.test.ts`

[Warning] `classifyComponentTree()` は `tree.files` を一度も分類しません。`resources/js/components/New.svelte` のようなルート直下ファイルは、部品にも未分類ファイルにもならず消えます。ルート直下ファイルを拒否または全数分類してください。

[Warning] `ComponentFileKindSpec.kind` は判定に使われず、`.types.ts` の処理は接尾辞の直書きです。また、実際に使用された file-kind キーの集合一致もありません。死んだ分類エントリを追加でき、共通規約 (d) と詳細設計の「ファイル種別の集合一致」に適合していません。`kind` の discriminated switch と `never` への収束、使用済み suffix 集合の検査が必要です。

### `tests/js/styles/component-doc-parity.test.ts`

[Warning] 字下げされた `## Components` の負例がないため、上記 S8 の迂回を固定できていません。ルート直下部品と未使用 file-kind の負例も追加が必要です。

### `tests/js/architecture/contrast-invariant.test.ts`

[Warning] 「既知の要求組」は Badge primary と Button primary の2例だけです。詳細設計は `TONE_CLASSES` / `VARIANT_CLASSES` の全キーから期待組を導出するよう要求しています。`class-usage.test.ts` 側も意味的な期待値を確認していないため、両方を合わせても要件を満たしません。

[Warning] 「是正前の値では5組がAA未達」という履歴を機械で固定せず、primary-soft の1組だけを検査しています。実測記録だけでなく、5組の既知値を独立した負のコントロールとして残すべきです。

### `tests/js/styles/inventory.ts`

[Suggestion] `DeclaredPair.fg/bg` が `string` なので typo が型で落ちません。`keyof typeof COLOR_TOKEN_MAP` から DESIGN.md 色キー型を導出すると、レビュー観点7の型の閉じ方に合います。

[Suggestion] `PENDING_CONTRAST_PAIRS` は「各 reason の検体を contrast-invariant の it が固定」と説明していますが、実際の検体は `class-usage.test.ts` にあります。責務記述を実装に合わせてください。

### `docs/template-divergence.md`

[Warning] D55 の「実装に現れる組は必ず母集団または判定不能台帳へ入る」と、D56 の「規範は読者に見える本文にしか書けない」は、上記の dynamic template と字下げ見出しにより現状では成立していません。実装を閉じるまで、恒久保証としての登録内容が実態を過大評価しています。

### `tests/js/styles/theme-map.ts`

[Suggestion] `ThemeBlock.offset` は収集されますが、診断を含めて参照箇所がありません。共通規約 (d) を厳密に適用するなら削除するか、エラー表示など実際の判定・診断に使ってください。

### `tests/js/styles/tokens.test.ts`

[Suggestion] H節の実測コメントが旧値 `#2563eb` のままです。検査コードは正しい値を動的取得していますが、設計記録としては `#1d4ed8` に直した方が安全です。

### 問題を認めない変更

以下は提示差分の範囲では設計どおりです。

- `DESIGN.md`: token 値と説明、Components 4節の追加は整合
- `docs/design-system.md`: S9/S11 の文書更新は意図どおり
- `resources/css/tokens.css`: DESIGN.md と6色および primary-soft が同期
- `AppLayout.svelte` / `SidebarNavItems.svelte`: `text-white` → `text-surface` は現テーマで同色で、意味的にも改善
- `LedgerPins.php` / `adoption-debt.tsv`: 提示された検証結果を前提に件数・債務解除は整合
- `canonical-source-parity.test.ts`: 写像パーサへの集約と `@theme` 一意性は妥当
- `design-system-docs.test.ts` / `markdown-lines.ts`: docs/design-system.md に対する契約A/Bの実装は妥当
- `theme-map.test.ts`:主要な構文・値解析の正負例は十分
- `resources/css/tokens.css` と `DESIGN.md` の hex 同期、および Atomic Design / Lucide / SVG の後退は見当たりません

全体判定: **CHANGES_REQUESTED**