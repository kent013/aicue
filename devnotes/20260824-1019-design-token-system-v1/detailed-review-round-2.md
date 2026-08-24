## レビュー前提

仮説は「すべての解析器が、実ファイルと固定検体で同じ純粋実装を通り、解析不能・未分類・新規参照を必ず赤にできれば、正典 v1 の不変条件を維持できる」です。

提供テキストのみを分析し、コマンド実行・ファイル読み書きは行っていません。設計書が参照する `app-design` スキルはこのセッションでは利用できないため、記載された規約を直接評価しました。

Round 1 の主要指摘の多くは反映されていますが、S5・S8・S9には同じ種類の穴が別の形で残っています。

## 施策別判定

### S1 — REQUEST_CHANGES

[Critical] CSS字句走査の前提が、添付された現行 `tokens.css` と矛盾しています。

設計は「tokens.css に文字列リテラルは1件もない」としていますが、`--font-sans` には `'Noto Sans JP'` など多数のquoted stringがあります。文字列状態を認識せずにコメントや波括弧を処理すると、文字列中の `/*`、`{`、`}` をコメント・ブロック境界と誤認できます。「集合一致が赤になるので無言では通らない」という保証も一般には成立しません。

修正案: CSS走査を最低でも通常・コメント・単引用文字列・二重引用文字列・escapeの状態機械にし、`@theme`・コメント開始・波括弧は通常状態だけで解釈してください。次の固定検体を追加します。

- 文字列中の `/* … */`
- 文字列中の `{` / `}`
- escaped quote
- 現行 `--font-sans` と同形の宣言

[Warning] `parseCssColor()` の公開契約は `rgba()` / `rgb()` のみですが、S10では `designColors()` が返す `#1d4ed8` を渡して `kind: "opaque"` を期待しています。

修正案: `#rrggbb` を正式な入力形式に加え、RGB各値・alphaの範囲、余分な末尾文字、NaN相当を厳密に拒否する契約と固定検体を追加してください。

---

### S2 — REQUEST_CHANGES

[Critical] Round 1でS1に指摘された「固定検体から唯一の解析実装を呼べない」問題が、S2に残っています。

公開APIは `scanClassUsage()`、`scanCssVarReferences()`、`unsupportedEntryPoints()` と、実リポジトリを直接読む関数だけです。このままでは、列挙された合成入力を同じ解析実装へ渡せません。

修正案: 次のような純粋入口を唯一の実装にしてください。

```ts
scanClassUsageSource(source: string, file: string): ClassUsageScan
scanCssVarReferencesSource(source: string, file: string): readonly CssVarReference[]
unsupportedEntryPointsSource(source: string, file: string): readonly UnsupportedEntryPoint[]
```

実リポジトリ用関数はファイルを読み、これらを集約するだけにします。

[Warning] TypeScript/Svelteの文字列抽出について、コメント、escaped quote、複数行template literal、`${…}` の境界仕様が不足しています。正規表現だけではコメント内の偽リテラルや途中で閉じた文字列を拾う可能性があります。

修正案: 保証する字句構文を明記し、上記4形の正例・負例を固定してください。解析不能なtemplate構文は `interpolated` として残し、無言で通常リテラルにしないでください。

[Warning] opacity修飾の値域と端点が未定義です。`ScannedPair` は `0 < alpha < 1` を要求しますが、`/0`、`/100`、`/101`、負数、任意値をどう分類するか決まっていません。

修正案: `/100` はopaque、`/0` は親背景依存の透明背景、範囲外・未対応構文はunresolvedなど、一意な規則を定めて固定検体を置いてください。

---

### S3 — APPROVE

Round 1の次の指摘は解消されています。

- variant・important・opacityの分離
- `text-center/50` の拒否
- class語とCSS変数契約のチャネル分離
- 全occurrenceをS2の共通出力から受ける構造

S2の純粋入口と字句仕様が修正されることが前提です。

---

### S4 — APPROVE

`linearizeChannel(0.04)` が実装本体を通るため、0.03928のままでは赤になります。Round 1の検出力の穴は解消されています。8bit全値の検査を補助へ降格した点も妥当です。

---

### S5 — REQUEST_CHANGES

[Critical] `ParsedColor` のalphaと、台帳の「実効alpha」を合成関数が二重適用し得る設計です。

`ALPHA_CONTRAST_PAIRS` の `alpha` は実効値と定義されています。一方、`bg: "primary-soft"` を解決すると `ParsedColor` 自身も `alpha: 0.12` を持ちます。ところが関数は次の形です。

```ts
compositeOverOpaque(color: ParsedColor, alpha: number, base: Rgb)
```

これでは `primary-soft` の0.12を再度0.12倍して0.0144にするのか、`ParsedColor.alpha`を無視するのかが不明です。`primary-soft/40`にも同じ問題があります。

修正案: 合成直前に次の形へ完全正規化してください。

```ts
interface ResolvedAlphaBackground {
    readonly rgb: Rgb;
    readonly effectiveAlpha: number;
}
```

合成関数は `Rgb + effectiveAlpha + base` だけを受けます。次の3形を固定します。

- opaque token `/10` → 0.10
- intrinsic alpha token → 0.12
- intrinsic alpha token `/40` → 0.048

[Warning] ファイルパスの契約が一致していません。`ClassTokenOccurrence.file` はリポジトリ相対とされていますが、台帳例は `components/atoms/...` であり、`resources/js/` がありません。

修正案: 全型・診断・台帳で `resources/js/...` のリポジトリ相対に統一するか、走査根相対に変更してdocblockも揃えてください。固定検体で正規化を確認します。

[Warning] 「キーの取り違えが型で落ちる」という主張を成立させる型定義が示されていません。現行の `CSS_COLOR_SUFFIXES: readonly string[]` からはliteral unionを導出できません。

修正案: CSS suffix型を次のように直接導出し、`AlphaPair.fg/bg`へ使ってください。

```ts
type CanonicalColorSuffix =
    (typeof COLOR_TOKEN_MAP)[keyof typeof COLOR_TOKEN_MAP];

type DerivedColorSuffix =
    (typeof DERIVED_COLOR_TOKENS)[number];

type CssColorSuffix = CanonicalColorSuffix | DerivedColorSuffix;
```

---

### S6 — REQUEST_CHANGES

[Warning] 「disabledは是正対象tokenに依存しない」というリスク評価は正しくありません。`opacity-40`が要素全体へ付く場合でも、基底の `bg-primary` などの変更結果へopacityが適用されるため、表示色はprimary変更の影響を受けます。

修正案: 非依存という説明を削除し、主要Buttonのdisabled状態を目視確認対象へ加えてください。WCAG上の適用除外と、ブランド変更による視覚的後退がないことは別に扱います。

[Warning] Badgeのtone数が、S2/S7では5、S6では6と食い違っています。

修正案: 固定数を散文へ書かず、実装のvariantキーから導出した全toneを確認対象にしてください。

primary-soft追随の機械検査自体は、S10の方向でRound 1指摘を解消しています。

---

### S7 — REQUEST_CHANGES

[Critical] `perDirectory` の空振り検査がS2の契約と矛盾しています。

S2では `lib`、`types`、直下ファイルは抽出0件が正常であり、`components` と `pages` だけを非空必須としています。しかしS7は全エントリへ次を要求しています。

```ts
for (const [dir, count] of scan.perDirectory) {
    expect(count).toBeGreaterThan(0);
}
```

これでは設計どおり実装すると必ず赤になります。

修正案: 直下分類に `requiresOccurrences` を持たせ、その値がtrueの子だけを非空検査してください。分類表のキーと `perDirectory` のキー集合一致も同時に検査します。

役割を複数値にしたこと、個別宣言ペアを役割分類の根拠から外したこと、`border`の2用途を分けたことはRound 1指摘を正しく解消しています。

[Suggestion] 同じroleの重複登録も拒否すると、導出した直積に重複ペアが生じるのを防げます。

---

### S8 — REQUEST_CHANGES

[Critical] 固定検体でディレクトリ・ファイル・節を増減させるための純粋な検査入口が設計されていません。

実リポジトリを直接列挙するgateだけでは、「未分類ディレクトリを足す」「部品を1つ足す」などの固定検体を同じ判定実装へ渡せません。

修正案: 少なくとも以下を純粋関数に分けてください。

- `parseDesignComponentSections(source)`
- `classifyComponentTree(tree, directoryClassification, fileKinds)`
- `compareComponentDocumentation(sections, components, mappings)`

実ファイル用gateは、読み出した値をこの3段へ渡す薄いラッパーにします。

[Warning] `excluded` では再帰停止すると定義している一方、分類表には `templates/_helpers` があります。`templates` で停止するならこの登録は検査に使われない死んだ登録です。

修正案: `templates/_helpers` を削除するか、「excluded配下のディレクトリ名は分類するがファイルは母集団に入れない」方式へ変更してください。どちらを採る場合も、未使用の分類エントリがないことを検査します。

[Warning] `.types.ts` は `.ts` の接尾辞でもあります。照合順が未定義だとhelperへ誤分類できます。

修正案: 最長接尾辞一致と明記し、`Button.types.ts` がtypes、`input-state.ts` がhelperになる固定検体を追加してください。

Atomic Design上の対象範囲をatoms/molecules/organismsに限定した判断自体は妥当です。

---

### S9 — REQUEST_CHANGES

[Critical] 「直前が空行または文書先頭」という開始条件でも、CommonMarkの字下げコードを十分に検出できません。

字下げコードが中断できないのはparagraphです。したがって「空行が必要」と同義ではありません。例えばATX headingの直後にある4空白行はparagraphを中断しておらず、字下げコードになり得ますが、提案状態機械は本文として残します。

```markdown
## 契約

    本当はコード内にある規範文
```

この形で規範の最小断片をコード内へ移しても、検査が緑になる穴が残ります。「過剰に落とし得るのはリスト配下の1形だけ」という保証も成立しません。

修正案は次のいずれかです。

1. paragraph・heading・fence・list等の直前ブロック種別を認識する状態機械を設け、heading直後、thematic break直後、blockquote/list境界も固定する。
2. 現状の文書に4空白行が0件なら、最小構成として「4空白以上の行が現れたら解析不能としてgate自体を失敗させる」。必要になった時点でCommonMark parserを導入する。

後者の方が、現在必要な範囲では小さく、無言の見逃しもありません。

---

### S10 — REQUEST_CHANGES

[Critical] S1の `parseCssColor()` 契約のままでは、次の検査は実装不能です。

```ts
parseCssColor(
    requiredMapValue(designColors(), "primary", "DESIGN.md colors.primary"),
)
```

`designColors()` はhexを返す一方、`parseCssColor()` はrgba/rgbのみと宣言されています。

修正案: S1で `#rrggbb` をopaqueとして正式対応し、次の正負例を固定してください。

- `#1d4ed8` → opaque
- `rgba(29, 78, 216, 0.12)` → alpha
- 範囲外RGB、範囲外alpha、末尾文字つき → 例外

`requiredMapValue()` の導入とprimary-softのRGB・alpha同一性検査は、Round 1指摘を正しく解消しています。

---

### S11 — APPROVE

追加対象は4本で、固定数を散文から廃止し、双方向集合一致だけを正本にしたため、Round 1の算術誤りは解消されています。

責務境界表へパーサ自己検査も登録する判断は妥当です。

---

### S12 — REQUEST_CHANGES

[Warning] D51はRound 1で求められた「対象パスに入る変更全体の説明」をまだ満たしていません。

D51の業務要件起因の説明は、検査目録とテーマ運用契約を述べていますが、同じ `docs/design-system.md` に入る次の変更を説明していません。

- S8のcomponent文書・実装双方向parity
- 新規4 component節
- S9の字下げコード判定変更

「揃え続ける不変条件」にも、component節とcomponentファイルの双方向一致が明記されていません。

修正案: D51の説明・保証機構・再判定条件へ次を追加してください。

- DS再利用componentの文書被覆を機械固定する理由
- §Componentsと対象componentファイルの双方向集合一致
- 字下げコード判定方式を変更する理由
- componentカタログの正本を別へ移した場合、またはMarkdown parserを導入した場合の再判定

また、S9の検出力を直すまでは「4空白以上の字下げコードを落とす」というD51の保証文を確定できません。

---

## 横断指摘

[Warning] 「本作業はPHPを1行も変えない」と「唯一のPHP変更は `LedgerPins.php`」が矛盾しています。

修正案: 「アプリケーションPHPは変更しない。テスト支援PHPの整数定数2本だけを変更する」と訂正してください。

PHPStan、DTO/JsonResource、Inertia Props、DB、tenant認可、OWASP上のアプリ実行経路には直接変更がなく、この範囲で新たな問題は見当たりません。

## Round 1指摘の解消状況

明確に解消されたものは、S3のtoken分解、S4の実装本体を呼ぶしきい値検査、S7の複数役割モデル、S11の固定本数廃止です。

一方、次は未解消または別形で再発しています。

- 固定検体から唯一の実装を呼ぶ入口: S2・S8
- 派生alphaの正規化: S5で二重適用の曖昧さ
- Markdown状態遷移: S9でheading直後を見逃す
- 共有文書変更全体の乖離説明: S12
- 改訂で新規に入った実装不能: S7の`perDirectory`全件非空要求

## 全体判定

**CHANGES_REQUESTED**

主なブロッカーは、S1のCSS文字列処理、S2/S8の純粋な固定検体入口、S5の実効alphaモデル、S7の空振り条件、S9のCommonMark見逃しです。方向性は妥当ですが、現状のままでは「解析不能を赤にする」という中心仮説を満たしていません。