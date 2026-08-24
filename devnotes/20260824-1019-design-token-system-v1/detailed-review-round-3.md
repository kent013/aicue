## レビュー前提

全体仮説は「実ファイルと固定検体が同じ純粋実装を通り、解析不能・未分類・新規参照が必ず赤になれば、正典 v1 の不変条件を維持できる」です。

提供テキストのみを分析し、コマンド実行・ファイル読み書きは行っていません。設計中で参照される `app-design` スキルはこのセッションでは利用できないため、記載された規約を直接評価しました。

Round 2 の指摘は多くが反映されていますが、S1・S5・S8・S9では未解消または別形の穴が残っています。

## 施策別判定

### S1 — REQUEST_CHANGES

[Critical] 5状態字句走査へ置き換える方針と、リスク欄の「関数本体をそのまま移す」「正規表現を書き換えない」が矛盾しています。

後者に従うと、Round 2で問題になった文字列中の `/*`・`{`・`}` を誤認する正規表現実装が温存されます。固定検体は正しい方向ですが、実装指示が二通りに読める状態です。

修正案: 「本体をそのまま移す」「正規表現を書き換えない」を削除し、既存8テストの期待結果を維持しつつ、解析実装自体は5状態字句走査へ置換すると明記してください。

[Warning] `@theme` / `@utility` の語彙境界が未定義です。単純な文字列一致では `@theme-extra` や宣言値中の不正な `@theme` をブロック開始として扱う可能性があります。

修正案: CSSのat-keywordとして完全一致させ、通常状態かつ文位置として有効な場合だけ解釈してください。`@theme-extra`、未終端コメント、未終端文字列も固定検体へ追加します。

Round 2のhex対応は解消されています。

---

### S2 — REQUEST_CHANGES

[Critical] alphaの単位が型契約に現れていません。

設計内には次の3種類が混在しています。

- `/10`、`/40`という百分率
- `ClassTokenOccurrence.alpha`
- `ScannedPair.alpha === 0.048`という0〜1の実効値

さらにS5の `resolveAlphaBackground("primary", 10)` は百分率を受けています。`number` だけでは10と0.10を取り違えても型で落ちず、二重除算・除算漏れが起こり得ます。

修正案: 少なくとも次のように名前と単位を分離してください。

```ts
interface ClassTokenOccurrence {
    readonly alphaPercent: number | null; // 0..99
}

interface ResolvedAlphaBackground {
    readonly effectiveAlpha: number; // 0 < x < 1
}
```

`alpha` という無単位の名前は使わず、実効値への変換を1関数だけに集約します。

[Warning] `.d.ts` は `.ts` の接尾辞でもありますが、S2では照合順が未定義です。S8で直した問題がS2に残っています。

修正案: 最長接尾辞一致をS2にも適用し、`vite-env.d.ts` が走査対象外、通常の `.ts` が対象になる固定検体を追加してください。

[Warning] `scanClassUsageSource()` が返す `ClassUsageScan` には、リポジトリ集約用の `files` と `perDirectory` が含まれます。任意の検体ファイルに対して、どのディレクトリ分類を生成するのか定義されていません。

修正案: 純粋なソース解析結果と集約結果を分けます。

```ts
scanClassUsageSource(source, file): SourceClassUsageScan
scanClassUsage(): ClassUsageScan
```

`perDirectory` は実リポジトリ用ラッパーだけが生成する方が責務に合います。

文字列・コメント・template補間の仕様自体はRound 2指摘を概ね解消しています。

---

### S3 — REQUEST_CHANGES

[Warning] `scanCssVarReferences()` の走査根がS2とS3で一致していません。

S2は `resources/js` の走査器として説明されていますが、S3では `resources/js / resources/css` の両方を走査するとしています。公開契約から、どちらが正しいか判断できません。

修正案: CSS変数参照用ラッパーの走査根を明示し、`resources/js` と `resources/css` がそれぞれ存在すること、各根が実際に列挙されたことを検査してください。ソース解析本体は現在の純粋入口を共有できます。

参照チャネルの型分離、`text-white` の是正、冗長な契約登録の拒否は妥当です。

---

### S4 — APPROVE

`linearizeChannel(0.04)` が実装本体を直接通るため、0.03928の実装は確実に赤になります。Round 1の検出力の穴は解消されています。

---

### S5 — REQUEST_CHANGES

[Critical] Round 2の「alpha二重適用」問題が、データフロー上はまだ解消されていません。

設計では次の契約が同時に存在します。

- `ALPHA_CONTRAST_PAIRS.alpha` は実効alpha
- `resolveAlphaBackground(suffix, modifier)` はtoken固有alphaとclass修飾を乗算する
- `primary-soft` の台帳値は `alpha: 0.12`
- `primary-soft/40` の台帳値は `alpha: 0.048`

台帳の `alpha` を `modifier` として渡せば、0.12が再び0.12倍されます。一方、直接 `ResolvedAlphaBackground` に入れるなら、`resolveAlphaBackground()` を台帳経路で使わないことを明記する必要があります。現状は両方に読めます。

修正案: 台帳には実効値ではなくclass修飾だけを持たせる形が明快です。

```ts
interface AlphaPair {
    readonly fg: CssColorSuffix;
    readonly bg: CssColorSuffix;
    readonly modifierPercent: number | null;
}
```

- `bg-primary/10` → `modifierPercent: 10`
- `bg-primary-soft` → `modifierPercent: null`
- `bg-primary-soft/40` → `modifierPercent: 40`

`resolveAlphaBackground()` だけがtoken固有alphaと修飾率を合成し、`effectiveAlpha` を生成します。台帳・走査結果・合成検査が同じ単位を使うようにしてください。

[Warning] `ALPHA_CONTRAST_PAIRS` の説明は「ファイル単位」「走査で見つかった全件」としていますが、`AlphaPair` には `file` も `count` もありません。

この形は「異なる意味ペアの集合」は固定できますが、別ファイルに同じペアが増えたことや、同一ファイル内で同じペアが増えたことは検出しません。

修正案: どちらを保証するか決めてください。

- コントラスト上の一意な意味ペアだけを固定するなら、ファイル単位・全occurrenceという主張を削除する
- 使用箇所の全数台帳にするなら、`file` と `count` を持たせる

ファイルパスのリポジトリ相対統一とsuffix型の導出は解消されています。

---

### S6 — APPROVE

値変更の根拠、派生tokenの追随、disabled状態の目視確認、Badge toneの実装キーからの導出はいずれも妥当です。

ただしS5のalpha契約を確定してから値の緑を判断する必要があります。

---

### S7 — APPROVE

Round 2の次の指摘は解消されています。

- `requiresOccurrences: true` の子だけを非空検査する
- 分類表と `perDirectory` のキー集合を双方向一致させる
- 同一roleの重複を拒否する
- 個別宣言ペアを役割分類の根拠に使わない

複数役割モデルと個別宣言ペアの3条件も整合しています。

---

### S8 — REQUEST_CHANGES

[Critical] 純粋関数の引数型が実定数の `typeof` に固定されているため、固定検体から分類表・申告表を増減できません。

```ts
dirClassification: typeof COMPONENT_DIR_CLASSIFICATION
mappings: typeof COMPONENT_SECTION_MAPPINGS
```

後者は実際の文字列を含むreadonly tuple型です。冗長な申告や追加申告を合成入力で渡すと型エラーになります。Round 2で求めた「同じ判定実装へ任意の分類表を渡す入口」を満たしていません。

修正案: 構造型を定義してください。

```ts
type ComponentDirClassification =
    Readonly<Record<string, ComponentDirSpec>>;

type ComponentSectionMappings =
    readonly ComponentSectionMapping[];
```

実定数は `satisfies` でこの型へ適合させ、純粋関数は構造型を受け取ります。

[Critical] `parseDesignComponentSections()` が、囲みコード・HTMLコメント・重複した `## Components` をどう扱うか未定義です。

単純な見出し正規表現なら、囲みコード内に `### DragHandle` を置いて文書化済みに見せることができます。これは「文書⇔実装の双方向一致」という中心保証を直接迂回します。

修正案:

- `## Components` はちょうど1節であること
- HTMLコメントと囲みコード内の見出しは数えないこと
- `###` だけを対象にし、`####` は数えないこと
- 同名節は例外にすること
- 未終端の囲みコード・コメントは解析失敗にすること

を契約化し、それぞれ固定検体を追加してください。既存のMarkdown可視領域走査を共通化できるなら、独立した弱い解析器を増やさない方が安全です。

[Warning] 「直下サブディレクトリと分類表の集合一致」と、分類表に深さ2の `atoms/icons` が含まれることが字面上矛盾しています。

修正案: 直下では分類表キーの第1要素だけと比較し、その後、再帰中に実際に使用した完全パス集合と分類表全体を一致させる、と二段階に定義してください。

[Warning] 既定対応をファイル名だけで行う場合、異なる層に同名componentがあると1節へ衝突します。

修正案: component basenameの重複を拒否するか、重複時は申告表を必須にしてください。`atoms/Foo.svelte` と `molecules/Foo.svelte` の固定検体を追加します。

`templates/_helpers` の削除と最長接尾辞一致は解消されています。

---

### S9 — REQUEST_CHANGES

[Critical] 「囲みコード外で4空白以上から始まる行」だけでは、字下げコードの見逃しは0になりません。

少なくとも次が通ります。

```markdown
>     読者に本文として見せない規範文
```

これはraw行の先頭が `>` なので4空白判定に掛かりませんが、blockquote内の字下げコードになり得ます。また、タブ1文字や空白とタブの混在もCommonMark上は4列以上の字下げになり得ます。

したがってD51の「書き方を禁じて見逃しを0にした」という保証は現状では成立しません。

修正案は次のいずれかです。

1. CommonMark parserを導入してblock種別を判定する
2. fail-closed方針を維持するなら、タブを禁止し、blockquote・listなどのcontainer markerを除去した後の有効字下げ列も検査する

後者なら最低でも次を固定してください。

- 行頭タブ
- 1〜3空白＋タブ
- `>     text`
- nested blockquote
- list marker後の字下げコード
- 通常のblockquote/list本文は誤検出しない

[Warning] `renderedLines()` と `indentedLineNumbers()` がそれぞれ囲みコード状態を解析すると、同じMarkdownに2本の字句走査ができます。

修正案: 1回の純粋な行分類から、描画行と禁止字下げ行の両方を返してください。

```ts
scanMarkdownLines(source): {
    renderedLines: readonly string[];
    forbiddenIndentLines: readonly number[];
}
```

見出し直後の4空白行を取りこぼすRound 2の問題自体は解消されています。

---

### S10 — APPROVE

`#rrggbb` の正式対応、値域・末尾文字の拒否、派生tokenのRGB・alpha同一性検査により、Round 2の実装不能は解消されています。

S5でalphaの単位を確定することが前提です。

---

### S11 — APPROVE

新規4本の登録、本数の散文廃止、責務境界表との双方向一致は妥当です。S9修正後の字下げ規則に合わせて記述を確定してください。

---

### S12 — REQUEST_CHANGES

[Warning] D50・D51の説明範囲はRound 2指摘どおり拡張されていますが、現在の保証文はS5・S8・S9の未解消点を過大に主張しています。

特に次は現状では保証できません。

- D50: alphaの出所が一意で二重適用されないこと
- D51: component節が描画される本文にあること
- D51: 字下げコードへの退避を見逃し0で拒否すること

修正案: S5・S8・S9の契約を先に確定し、その実装と固定検体で成立する範囲だけをD50・D51へ記載してください。

件数変更、採用時債務2行の削除、D50/D51への分割、D28の訂正方針は妥当です。

## 横断指摘

[Warning] Round 1対応記録に、現在のS9と反対の説明が残っています。

> 「S9 は…段落継続行を落とさない仕様へ直した」

現在のS9は、段落継続行であっても4空白以上なら検査を失敗させる設計です。

修正案: 「Round 1では落とさない方針にしたが、Round 2で書き方自体を禁止する方針へ変更した」と履歴を更新してください。

アプリケーションPHP、PHPStan、DTO/JsonResource、Inertia Props、DB、tenant境界、認可、LLM経路への直接変更はなく、この範囲で新たなセキュリティ問題は見当たりません。Atomic Design上も、新規アプリcomponent追加はなく、S8の対象範囲は妥当です。

## Round 2指摘の解消状況

明確に解消されたもの:

- `parseCssColor()` のhex対応
- S2の文字列・コメント・template補間仕様
- `/0`・`/100`・範囲外alphaの分類方針
- ファイルパスのリポジトリ相対統一
- CSS suffixのliteral union導出
- disabled状態のリスク訂正
- Badge tone数の固定値廃止
- S7の `requiresOccurrences`
- role重複の拒否
- `templates/_helpers` の死んだ登録削除
- S8の最長接尾辞一致
- PHP変更範囲の表現訂正

未解消または別形で残ったもの:

- S1のCSS字句走査: 方針は直ったが「正規表現を書き換えない」と矛盾
- S5のalpha正規化: `effectiveAlpha` と百分率modifierの境界が未確定
- S8の純粋入口: `typeof` により合成分類表を渡せない
- S8の文書解析: 非描画領域の見出しを除外する契約がない
- S9の字下げ禁止: 見出し直後は塞いだが、タブ・container内字下げが残る
- D50/D51: 上記未解消保証を確定事項として記述している

## 全体判定

**CHANGES_REQUESTED**

主要ブロッカーは、S1の相反する実装指示、S5のalpha単位と正規化経路、S8の固定検体型とMarkdown解析、S9のcontainer・タブ経由の見逃しです。方向性は妥当ですが、現状では中心仮説である「解析不能や隠れた規範を必ず赤にする」をまだ満たしていません。