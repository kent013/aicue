# Round 6 レビュー結果

**全体判定: CHANGES_REQUESTED**

Round 5 の修正方針は概ね正しく、TypeScript AST 化、class 候補の2段分解、実施順、件数集計、字下げコードの証明は改善されています。

ただし、次の3系統に偽グリーンまたは実装解釈の分岐が残っています。

- S2: class候補の選別規則、variant合成、診断の消費方法が未確定
- S3: `var()` 解析診断を返す型がなく、PostCSSだけでは宣言値内部の文字列を分類できない
- S8/S9/S12: listとblockquoteを組み合わせたcontainer内fenceを検出できない

## Round 5 指摘の解消状況

解消済みです。

- `ts.createSourceFile()` とparse diagnosticsへの移行
- 許可外文字を含むclass候補全体の `unparsable-token` 化
- `SourceClassUsageScan.diagnostics` の追加
- S9の内容開始列を基準にした証明への差し替え
- S9 → S8への実施順変更
- 指摘件数を行頭ラベルだけで数える修正
- S9方針変遷表の更新
- PostCSSの受理形について実測値を確定したこと

部分解消です。

- S1のPostCSS受理契約
- S2のvariant composition
- S3の `var()` 解析
- S8/S9のcontainer内fence
- S12のD51

---

## S1 — REQUEST_CHANGES

[Warning] `@theme` / `@utility` の直接子について、対応マトリクスの「直接の子はDeclだけ」と、詳細設計の「Rule / AtRuleなら例外」が一致していません。後者ではPostCSSの`Comment`が暗黙に許可されます。修正案として、許容する子を `Decl | Comment` としてコメントを無視するのか、`Decl`以外をすべて拒否するのかを一意に定め、`@theme`と`@utility`の双方にコメント入り固定検体を追加してください。

PostCSS採用、`params`、top-level、重複、`nodes`、`@/* c */theme`の扱い自体は実装可能な水準です。

## S2 — REQUEST_CHANGES

[Critical] `.ts`の全`StringLiteral`を走査単位にする一方、「どの空白区切り候補をclass候補として解析するか」が定義されていません。すべての文字列を検証すると、import specifierやURLなどの`@`、括弧等が`unparsable-token`になり、実リポジトリを正常に走査できません。逆に許可文字検証より前に曖昧な除外をすると、`bg-primaryあ`を無視できてしまいます。修正案として、variant/importantを考慮したうえで末尾utilityが監視対象接頭辞を持つかを判定する純粋関数を定義し、監視対象と判定した候補だけを「候補全体」で文字検証してください。import、URL、通常文、`bg-primaryあ`、`sm:bg-primaryあ`の正負例が必要です。

[Critical] `variant-composition`の発火条件と固定検体が矛盾しています。「異なるvariant列が同じchannelへ影響」であれば、基底と`hover:`が同じchannelにある通常ケースも該当しますが、設計はそれを解決可能としています。また問題の例では`sm:bg-neutral`と`sm:hover:text-danger`は異なるchannelなので、文面どおりでは発火しません。修正案として、基底は継承元として除外し、単位内に異なる非空variant列が複数存在する場合はchannelをまたいで`variant-composition`へ落とす、など発火条件を形式化してください。最低限、次を別々に固定する必要があります。

- 基底 + `hover:`だけは解決可能
- 両channelが同じ`hover:`なら解決可能
- `sm:` + `sm:hover:`は判定不能
- `sm:` + `hover:`は同時成立を否定できないため判定不能

[Critical] `diagnostics`は型に追加されましたが、実リポジトリに対して`diagnostics.length === 0`を要求するgateが検査項目に明記されていません。結果へ積むだけでは共通規約(d)に反し、解析失敗を抱えたまま部分的な`pairs`で後続gateが緑になれます。修正案として、`class-usage.test.ts`など責務表上の一箇所を診断ゼロの正本に定め、S3/S5/S7がその保証に依存することを明記してください。

[Warning] 純粋入口は「例外を投げず診断へ積む」と定義されていますが、テスト計画には未終端構文が「例外になる」と残っています。また「解析不能後も後続単位を抽出する」という保証は、TypeScript/Svelteパーサのエラー回復結果に依存します。修正案として、構文解析失敗は診断を返す、と統一してください。診断時のoccurrenceを空にするかbest-effortで返すかも決め、いずれの場合も診断によりgateが必ず失敗する契約にしてください。

[Warning] `TemplateExpression`を記録した後に通常のAST再帰を続けると、補間内部の`StringLiteral`まで独立したclass単位として拾う可能性があります。修正案として、`TemplateExpression`を`interpolated`にした時点でそのsubtreeへ降りないことを明記し、補間内部にclass風文字列がある検体で重複抽出されないことを固定してください。

## S3 — REQUEST_CHANGES

[Critical] `scanCssVarReferencesSource()`と`scanCssVarReferences()`の戻り値が参照配列だけなので、設計が要求する「未終端関数・解析不能値を診断として残す」を実装できません。修正案として、次のような結果型へ変更し、実リポジトリでは診断ゼロを明示的に要求してください。

```ts
interface CssVarReferenceScan {
    readonly references: readonly CssVarReference[];
    readonly diagnostics: readonly CssVarReferenceDiagnostic[];
}
```

[Critical] PostCSSの`Decl.value`はCSS値の構文木ではなく文字列です。そのためPostCSS ASTから値を取り出しただけでは、次の区別はまだできません。

```css
color: var(--color-text);
content: "var(--color-text)";
color: var(--a, var(--b));
color: var(--a /* comment */);
```

修正案として、CSS component valueを解析できる既存parserを直接devDependencyとして採用するか、文字列・コメント・escape・括弧・引数区切りを扱う値解析器の受理契約を詳細化してください。単なる括弧カウントや正規表現ではRound 5のWarningは解消されません。

[Warning] 「対象at-ruleのparams」が未定義です。修正案として、どのat-rule名のparamsを参照母集団に含めるかを列挙し、対象外at-ruleに`var()`が現れた場合に無視するのか解析失敗にするのかを決めてください。

## S4 — APPROVE

実装本体を呼ぶ境界値検査になっており、0.03928のままでは赤になります。Round 5による新たな問題もありません。

## S5 — REQUEST_CHANGES

[Warning] `UndecidableReason`へ`variant-composition`を追加した結果、分類は9種類ですが、テスト計画には「8分類」と残っています。また`PENDING_CONTRAST_PAIRS`の説明にも`variant-composition`がありません。修正案として、分類数を散文から削除してunionから導出し、pendingの列挙もunionから生成するか、少なくとも9理由すべてを網羅してください。`never`への収束だけでなく、各reasonを発火させる固定検体との対応も必要です。

alphaの単位分離、使用箇所台帳、キー一意性、`distinctPairs()`の直接検査は妥当です。

## S6 — APPROVE

S5で赤を確認してから値を変更する順序、派生tokenの追随、目視対象、disabled状態の扱いは具体的です。

## S7 — APPROVE

個別宣言ペアの実在要求、重複拒否、役割分類と個別宣言の分離は整合しています。S2の走査器契約が確定することが実装上の前提です。

## S8 — REQUEST_CHANGES

[Critical] S9のMarkdown走査器が、listとblockquoteを組み合わせたfenceを検出できません。例えば次は、現在の「行頭の`>`だけを剥がす」「raw行へ`^ {0,3}`を当てる」の双方を通過し得ます。

```markdown
- > ```
  > ### DragHandle
  > ```
```

```markdown
> - ```
>   ### DragHandle
>   ```
```

この中の見出しを通常本文として扱えば双方向一致を偽グリーンにできます。修正案として、S9で任意の有効なcontainer prefix列を消費した後のfenceを検出するか、container prefixを伴うfence候補をすべて解析失敗にする保守的契約へ変更してください。S8側はその診断が1件でもあれば必ず失敗させてください。

[Warning] `parseDesignComponentSections()`は`unparsableFenceLines`だけでなく、共有走査器が返すすべての解析失敗を拒否する契約にしてください。修正案として、Markdown結果に共通`diagnostics`を設け、未終端コメント、未終端fence、container fenceなどを同じ経路で消費してください。

ディレクトリ分類、basename重複拒否、最長接尾辞一致、申告表の健全性は妥当です。

## S9 — REQUEST_CHANGES

[Critical] 「扱う必要があるのは`>`の1種類だけ」という根拠は成立しません。単純なlist継続行のfenceだけでなく、listの最初のblockとしてのfenceや、listとblockquoteの異種入れ子があります。契約Bの4連続空白禁止では、`- > ``` `のように4連続空白を含まない形を落とせません。修正案として、契約Aだけはcontainer-awareにするか、CommonMark parserを採用してください。依存を増やさない場合でも、少なくともcontainer prefixの文法とfail-closed分岐は必要です。

[Critical] D51では「対応するfence以外は解析失敗」としていますが、`scanMarkdownLines()`の公開結果では`unparsableFenceLines`がblockquote fenceだけであり、その他の未対応fenceを表現できません。修正案として、診断を理由つきunionへ変更してください。

```ts
type MarkdownDiagnosticReason =
    | "unterminated-html-comment"
    | "unterminated-fence"
    | "container-fence"
    | "unsupported-fence";
```

[Warning] fenceの開始・終了規則がまだ実装者依存です。修正案として、markerは同一文字3個以上、終了markerは開始以上の長さ、最大3空白字下げ、backtick型のinfo string制約など、採用する範囲を明記してください。完全なCommonMarkを扱わない場合は、範囲外を通常本文ではなく診断へ落としてください。

[Warning] 「非描画領域」という呼称はfenced codeには正確ではありません。fenced codeは読者には描画されますが、規範本文として数えない領域です。修正案として「規範判定対象外領域」などへ改称し、HTMLコメントとの意味差を明確にしてください。

内容開始列を基準にした字下げコードの証明自体は、Round 5の誤ったpadding前提を解消しています。

## S10 — APPROVE

生成CSSで不透明度修飾の前提を固定し、派生tokenのRGBとalphaを正本へ結び付ける構成は妥当です。

## S11 — APPROVE

新規4本の責務境界表登録、本数の散文廃止、双方向集合一致は整合しています。

## S12 — REQUEST_CHANGES

[Critical] D51は、現状のS9より強い保証を記述しています。特に「`^ {0,3}`以外は解析失敗」「container文法を扱わずに保証できる」という記述は、listとblockquoteの異種入れ子fenceに対して成立しません。修正案として、S9のcontainer fence契約を確定した後、その実装が実際に拒否・除外する構文だけをD51へ記載してください。

[Warning] D51でもfenced codeを「描画されない領域」としています。修正案として、HTMLコメントは非描画、fenced codeは描画されるが規範判定対象外、と保証を分離してください。

D50、件数更新、adoption debtからの同時削除、`doubleDeclaredPaths`の確認方針は妥当です。

---

## 実装フェーズへの引き渡し可否

現状のままでは、実装者が次の判断を独自に補う必要があるため、そのまま着手できる詳細度には達していません。

1. 通常の文字列からclass候補をどう選ぶか
2. どのvariant組合せを判定不能にするか
3. class/CSS var/Markdownの診断をどのgateが必ず消費するか
4. CSS値内部の文字列・コメントをどう解析するか
5. listとblockquoteが混在するfenceをどう拒否するか

この5点を閉じれば、残る施策は実装フェーズへ渡せる水準です。