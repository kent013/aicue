## レビュー前提

仮説は「解析不能・未分類・未対応構文を必ず観測可能な結果へ落とし、実ファイルと固定検体が同じ純粋実装を通れば、静的 gate の偽グリーンを防げる」です。

指定どおり、提供テキストだけを分析し、コマンド実行・ファイル読み書きは行っていません。

Round 4 の改善方針、特に PostCSS と既存コンパイラ API へ寄せた判断は妥当です。ただし、S2 の TypeScript 解析と、S8/S9 が共有する Markdown 行分類に中心保証を迂回できる穴が残っています。

## Round 4 指摘の解消状況

解消されています。

- S3: 走査根の生存と参照件数の分離
- S5: 台帳キーの一意性、件数・modifier の値域
- S5: `distinctPairs()` の直接検査への変更
- S7: 個別宣言ペアの実在要求と重複拒否
- S8: basename 重複を無条件禁止へ統一
- Round 1 の古いS9方針への注記

部分解消です。

- S1: PostCSS 採用で字句解析問題は解消方向だが、受理するAST形状が未確定
- S2: 手書きbrace countは撤去されたが、`ts.createScanner()` だけでは構文解析にならない
- S9: 「4連続空白を禁止する」という命題は修復可能だが、提示された証明は誤っている
- S12: D51が未確定なS9保証を再び確定事項としている
- 指摘件数: raw grep がレビュー本文中のラベルへの言及まで数えている

## 施策別判定

### S1 — REQUEST_CHANGES

[Warning] PostCSSへ移した方針は妥当ですが、受理する `AtRule` の形が実装可能な粒度まで閉じていません。

少なくとも次が未定義です。

- ブロックを持たない `@theme;`
- paramsを持つ `@theme foo { ... }`
- `@utility` がルート直下でない場合
- `@utility text-x` の重複
- `@utility` 内の `Rule` / `AtRule`
- `ThemeBlock.body` をASTからどう安全に構築するか

また、`@/* c */theme` の期待値を「どちらでもよいので実測で決める」とする記述は、詳細設計の固定検体として未確定です。

修正案:

- `@theme` は `nodes !== undefined`、許容するparamsを明示し、それ以外を例外にする
- `@utility` のtop-level、params文法、直接子、重複規則も5条と同じ粒度で定義する
- 未使用なら `ThemeBlock.body` を削除する。必要ならPostCSSのsource位置だけから構築する規則を明記する
- `@/* c */theme` の期待結果を設計段階で一意に決める

### S2 — REQUEST_CHANGES

[Critical] `ts.createScanner()` は字句解析器であり、設計が主張するtemplate補間の構文保証を提供しません。

特に、`TemplateHead` の後にある `}` が補間終端か、object literal等の内側かを判断するには構文文脈が必要です。TypeScript scannerでは、parserが適切な位置でtemplate tokenの再走査を指示します。scannerだけを順番に呼ぶ設計では、Round 4で問題になった次の形を構造的には解決できません。

```ts
`${condition ? "}" : value}`
`${{ key: `nested-${value}` }.key}`
```

また、scannerは未終端文字列等の字句エラーは通知できますが、括弧不整合などの構文エラー全般をfail-closedにはできません。

修正案:

- `.ts` は `ts.createSourceFile()` でAST化する
- parse diagnosticsが1件でもあれば解析失敗にする
- `StringLiteral` / `NoSubstitutionTemplateLiteral` / `TemplateExpression` をASTノードとして分類する
- `TemplateExpression` は補間ありとして判定不能へ落とす
- scannerを使う場合もparserの補助用途に限定し、「scanner単独で補間を解決する」と主張しない

[Critical] 公開結果型に、設計本文が要求する解析診断の格納先がありません。

本文では `.svelte` のparse失敗を `parse-failed` 診断として残すとしていますが、`SourceClassUsageScan` には `diagnostics` がなく、`UndecidableReason` にも `parse-failed` はありません。この型のままでは「診断が1件でもあればgateを落とす」を実装できません。

修正案:

```ts
interface SourceClassUsageScan {
    readonly occurrences: readonly ClassTokenOccurrence[];
    readonly pairs: readonly ScannedPair[];
    readonly incompleteOpaque: IncompleteOpaqueCounts;
    readonly diagnostics: readonly ClassScanDiagnostic[];
}
```

純粋入口が例外を投げる方式にするなら、集約ラッパーで例外を握らずファイル名つきで再送出する、と統一してください。

[Critical] class tokenの区切り規則と固定検体の期待値が両立していません。

「許可文字以外はすべて区切り」とすると、

```text
bg-primaryあ
bg-(--var)
```

はそれぞれ `bg-primary`、`bg-`、`--var` 等へ分割されます。前者は有効な `bg-primary` として通る可能性があり、後者も元の候補全体を `unparsable-token` にする根拠を失います。

修正案:

- まずCSS whitespaceでclass候補全体を分割する
- 各候補全体を許可文字集合で検証する
- 許可外文字が1つでもあれば、その候補全体を `unparsable-token` にする
- その後にvariant / important / alpha / utilityを分解する

[Warning] variant状態の継承が単一modifierの例にしか定義されていません。

例えば次では、`sm:hover:` 状態の背景は `sm:bg-neutral` を継承する可能性があります。

```text
bg-surface sm:bg-neutral sm:hover:text-danger
```

「同じmodifier列だけで基底を上書き」すると、`danger on surface` という誤った組を作ります。

修正案:

- variant条件間の包含を正式にモデル化する、または
- 複数の異なるvariant列が同じchannelへ影響する単位を `variant-composition` として判定不能へ落とす
- `sm:`、`hover:`、`sm:hover:` が混在する固定検体を追加する

### S3 — REQUEST_CHANGES

Round 4の「根ごとの参照非空を要求しない」修正は解消されています。

[Warning] `scanCssVarReferencesSource()` の解析方式が未定義です。

`var()` はCSSコメント、CSS文字列、Svelte/TS文字列、fallbackの入れ子等に現れ得ます。単純な正規表現では、コメント内の参照を生きた参照として数えたり、文字列中の文字をCSS参照と誤認したりします。

修正案:

- `resources/css` はPostCSS ASTの宣言値・対象at-rule paramsだけを入力にする
- `resources/js` はS2で確定したAST上の対象文字列だけを入力にする
- `var()` 関数の括弧、fallback、文字列、コメントの受理範囲を定義する
- 未終端関数や解析不能値を診断として残す
- コメント内、CSS文字列内、nested fallbackの正負例を追加する

### S4 — APPROVE

実装本体を呼ぶ境界値検査になっており、旧しきい値のままでは確実に赤になります。

### S5 — APPROVE

Round 4の台帳一意性・値域・`distinctPairs()`に関する指摘は解消されています。alphaの単位分離と全数台帳も整合しています。

### S6 — APPROVE

値変更の前にS5の赤を確認する順序、派生tokenの追随検査、disabled状態を含む目視計画は妥当です。

### S7 — APPROVE

各個別宣言ペアの実在要求と重複拒否が追加され、Round 4の水増し経路は閉じています。

### S8 — REQUEST_CHANGES

basename重複の矛盾は解消されています。

[Critical] S8が共有する `scanMarkdownLines()` がcontainer内のfenced codeを認識できる契約になっていません。

例えば次の `### DragHandle` はcomponent節ではなく、blockquote内のコードです。

```markdown
> ```
> ### DragHandle
> ```
```

S9が「container文法を一切扱わない」ままトップレベルのfenceだけを認識すると、この見出しを実在節として数え、component文書化gateを偽グリーンにできます。

修正案は次のいずれかです。

- CommonMark parserでblock種別を判定する
- container内fenceを正式対応する
- 手書きを維持するなら、トップレベル以外のfence候補を解析成功扱いせず、gate自体を失敗させる

固定検体にblockquote内・list内のfenced codeと、その中の `###` を追加してください。

[Warning] 実施順が `S8 → S9` なのに、S8はS9で新設する `scanMarkdownLines()` を必須としています。

修正案: S9をS8より先へ移すか、`markdown-lines.ts` の新設をS8の前提施策として明示してください。

### S9 — REQUEST_CHANGES

[Critical] 「container文法を一切扱わない」は字下げ検出には適用できますが、fenced code除外には適用できません。

次のコード内に規範の最小断片を置いた場合、container fenceを認識しない走査器では通常本文として数えられます。タブも4連続空白もないため、今回の2条件でも落ちません。

```markdown
> ```
> 必須の規範文
> ```
```

これはi12の中心保証を直接迂回します。

修正案:

- 「字下げコードの検出にはcontainer解析不要」と
  「fenced code・コメントの非描画領域判定」を別契約に分ける
- container fenceを解析しないなら、その構文を明示的な解析失敗にする
- blockquote/list内fence、その中のタブ・4空白・規範文について固定検体を置く

[Critical] 提示された証明は、結論は修復可能ですが、現状の論証は正しくありません。

誤っている箇所は次です。

> container marker が消費する空白は marker ごとに高々1個

CommonMarkのlist marker後のpaddingは1個に限定されません。したがって、この前提から「必ず4連続空白になる」は導けません。

ただし、命題自体は次の形なら成立させられます。

- すべての有効なcontainer prefixを消費した後の内容開始列を基準にする
- indented codeには、その基準からさらに4列以上の字下げが必要
- タブを禁止した場合、その追加4列を作れるのは連続したU+0020だけ
- list markerの幅やpaddingは内容開始列を決めるprefix側であり、追加4列の代用にはならない
- gateは全行を見るため、コードブロックの少なくとも先頭の非空行で4連続空白を検出する

この論証をCommonMarkのlist-item例外も含めて固定してください。最低でも次が必要です。

- marker padding 1〜4
- ordered marker 1〜9桁、`.` と `)`
- listの最初のblockがindented codeの場合
- 後続blockがindented codeの場合
- blockquote/listの異種入れ子
- lazy continuationがindented codeではない正例

したがって質問への回答は、**「タブ禁止＋4連続空白禁止」という結論は成立させられるが、現在の証明は誤った前提を含むため正しくない**です。

### S10 — APPROVE

生成CSSによるalpha修飾の前提固定と、派生tokenのRGB・alpha関係の検査は妥当です。

### S11 — APPROVE

責務境界表の双方向一致、本数の散文廃止、新規4本の登録は整合しています。S8/S9の修正後も表の方針自体は変更不要です。

### S12 — REQUEST_CHANGES

[Critical] D51がS8/S9の未確定保証を確定事項として記述しています。

container内のfenced codeを正しく除外または拒否できる契約がないため、「描画されない領域を落とす」「見逃し0」はまだ保証できません。

修正案:

- S8/S9のcontainer fence方針を確定してからD51へ反映する
- 「字下げコードの見逃し0」と「fenced codeの除外」は別の保証として記述する
- CommonMark全体を保証しない場合、対応するfence構文と解析失敗にする構文を明記する

## 横断指摘

[Warning] Round 4のWarning件数は9件ではなく、判定ラベルとしては8件です。

添付Aの判定ラベルを数えると次です。

- Critical: 2
- Warning: 8
- Suggestion: 1

`grep -c '\[Warning\]'` が9になるのは、序文中の次のメタ記述まで数えるためです。

> 添付Aには `[Warning]` が8件ではなく…

これは指摘ラベルではありません。

修正案: 件数の正本を行頭のラベルだけに限定してください。

```text
^\[Critical\]
^\[Warning\]
^\[Suggestion\]
```

過去ラウンドも同じ方法で再集計が必要です。

[Warning] S9の「方針の変遷」が現在の最終形まで更新されていません。

表の最終行は「有効字下げ列＋タブ禁止」のままですが、現在の設計は「タブ禁止＋4連続空白禁止」です。本文ではこの表を正本と呼んでいるため矛盾します。

修正案: `Round 4 → 5` の行を追加し、現在の最終方針まで更新してください。

## セキュリティ・アプリ設計整合性

アプリケーションPHP、DB、tenant境界、認可、LLM経路、DTO/JsonResource、Inertia Propsへの変更はなく、サーバー側セキュリティへの新たな問題は見当たりません。

Atomic Designについても新規アプリcomponentはなく、S8の `atoms / molecules / organisms` を対象、`features / templates` を対象外とする境界は妥当です。

主なリスクは、静的gateの解析不能や非描画領域を通常入力として扱うことによる偽グリーンです。

## 全体判定

**CHANGES_REQUESTED**

最終ブロッカーは次の3系統です。

- S2: `ts.createScanner()` ではtemplate補間を構文的に解決できず、診断を保持する型もない
- S2: 許可外文字を区切りにすると、`bg-primaryあ` 等が有効tokenへ縮退する
- S8/S9: container内のfenced codeを認識しないと、規範文やcomponent見出しをコード内へ退避してgateを偽グリーンにできる

PostCSS採用、台帳の厳密化、個別宣言ペア、basename方針は実装フェーズへ渡せる水準です。上記を閉じ、S9の証明とD51を同期すれば、全体も承認可能な状態になります。