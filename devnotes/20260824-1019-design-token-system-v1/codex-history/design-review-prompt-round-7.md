# アプリの使命（North Star）

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。
# 禁止事項

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)
【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest
- devDependency に postcss / typescript / svelte(svelte/compiler) が既に在り、既存の走査器が使っている

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は行頭に [Critical] [Warning] [Suggestion] を置いて分類する
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

# これは Round 7 (最終確認ラウンド) である

Round 1〜6 の指摘は**すべて対応済み（反論 0 件）**である。
Round 6 が名指しした「実装フェーズへ渡すために閉じる 5 点」を、いずれも形式的な契約として閉じた:

1. class 候補の選別 — `isWatchedCandidate()` を先に、候補全体の文字検証を後に
2. variant 合成 — 単位内の非空 variant 列の集合 S の濃度で判定 (|S|<=1 解決可能 / |S|>=2 判定不能)
3. 診断の消費先 — 診断ごとに消費する gate を 1 本ずつ名指しし diagnostics.length === 0 を要求
4. CSS 値解析 — コメントは postcss が Decl.value から除去済み (実測)。文字列は引用区間読み飛ばしの受理契約 6 条
5. container fence — container 文法を扱わず「囲みコードの外の行に fence marker が現れ、正規の
   top-level fence 行でなければ診断」で `- > ```` も `> - ```` も落とす

**本ラウンドは最終確認である。** 判定と、残る Critical / Warning があればそれだけを挙げてほしい。
実装フェーズへ渡せる具体性に達しているかどうかを明確に述べること。

---

# 添付 A: Round 6 のレビュー全文

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
---

# 添付 B: Claude 側の対応マトリクス（Round 6）

# 対応マトリクス: design-review Round 6

Critical 9 件・Warning 9 件 (行頭ラベルの機械カウント)。**すべて対応する** (反論は 0 件)。

Round 6 は「実装フェーズへ渡すために閉じるべき 5 点」を名指しした。
本ラウンドはその 5 点を**形式的な契約**として閉じることに集中する。

| # | 閉じる点 | 決着 |
|---|---|---|
| 1 | 通常の文字列から class 候補をどう選ぶか | 監視対象接頭辞の判定を純粋関数 `isWatchedCandidate()` として定義し、**監視対象と判定した候補だけ**を候補全体の文字検証へ回す |
| 2 | どの variant 組合せを判定不能にするか | **単位内の非空 variant 列の集合の要素数が 2 以上なら `variant-composition`** (channel を跨いで単位全体を落とす) |
| 3 | 診断をどの gate が必ず消費するか | 診断ごとに**消費する gate を 1 本ずつ名指し**し、`diagnostics.length === 0` を要求する it を置く |
| 4 | CSS 値内部の文字列・コメントをどう解析するか | コメントは **postcss が `Decl.value` から除いている** (実測)。文字列は**引用区間を読み飛ばす値走査**の受理契約を明文化する |
| 5 | list と blockquote が混在する fence をどう拒否するか | **container 文法を扱わず**、「囲みコードの外に fence marker (3 個以上連続した ` または ~) が現れ、その行が正規の top-level fence 行でないなら**診断**」にする |

## S1

### [Warning] `@theme` / `@utility` の直接子に `Comment` が暗黙に許可されている

- 判断: **対応する** (対応マトリクスと本文で食い違っていた)
- 対応内容: 許容する直接子を **`Decl` と `Comment` の 2 種**とし、**`Comment` は無視する**
  (tokens.css は `@theme` の中に節見出しコメントを持つので、拒否すると実装できない)。
  `Rule` / `AtRule` / その他のノードは**例外**。
  `@theme` と `@utility` の双方に**コメント入りの固定検体**を置く。

## S2

### [Critical] どの空白区切り候補を class 候補として解析するかが未定義

- 判断: **対応する** (指摘のとおり。全文字列を検証すると import 指定子や URL が
  `unparsable-token` になり、実リポジトリを走査できない)
- 対応内容: **監視対象の判定を先に、文字検証を後に**する純粋関数を定義する。

  ```ts
  /** 候補が「テーマの名前空間の utility になりうる形か」を判定する (文字検証はしない)。 */
  export function isWatchedCandidate(candidate: string): boolean;
  ```

  判定は 3 段:
  1. 先頭から `<何らかの文字列>:` の並びを variant 列として剥がす (`:` が無くなるまで)
  2. 残りの先頭の `!` を剥がす
  3. 残りが**監視対象接頭辞**のいずれかで始まるなら監視対象。
     接頭辞は ds-purity の `UNIVERSAL_PATTERNS` が見ている名前空間と同じ集合
     (`bg-` / `text-` / `border-` / `ring-` / `divide-` / `outline-` / `rounded-` /
     `fill-` / `stroke-` / `decoration-` / `accent-` / `caret-` / `placeholder-` /
     `from-` / `to-` / `via-`) を**1 か所に宣言**して共有する

  **監視対象と判定した候補だけ**を候補全体の許可文字検証へ回す。
  正負例: `"./Button.types"` (import 指定子) は非監視 / `"https://example.com/a"` は非監視 /
  通常の文 (`"保存しました"`) は非監視 / `bg-primaryあ` は監視 → `unparsable-token` /
  `sm:bg-primaryあ` も同じ / `text-center` は監視 → 契約表で解決。

### [Critical] `variant-composition` の発火条件と固定検体が矛盾している

- 判断: **対応する** (文面どおりだと通常ケースまで発火し、指摘の例では発火しない)
- 対応内容: 発火条件を**形式化**する。
  - 単位内の各候補は variant 列 `V` を持つ (素の宣言は空列)。
  - **非空の `V` の集合** `S` を作る。
  - `|S| ≤ 1` → **解決可能**。基底を `S` の唯一の列で channel ごとに上書きした状態を作る。
  - `|S| ≥ 2` → **`variant-composition` の判定不能** (channel を跨いで単位全体を落とす)。
  ★基底は**継承元**であって `S` には入れない。
  固定検体 4 形 (Round 6 が要求したもの):
  1. 基底 + `hover:` → 解決可能
  2. 両 channel が同じ `hover:` → 解決可能
  3. `sm:` + `sm:hover:` → 判定不能
  4. `sm:` + `hover:` → 判定不能 (同時成立を否定できない)

### [Critical] `diagnostics` を消費する gate が検査項目に無い

- 判断: **対応する** (積むだけで誰も見ない = 共通規約 (d) 違反そのもの)
- 対応内容: **診断ごとに消費する gate を 1 本ずつ名指し**する。

  | 診断 | 消費する gate | 検査 |
  |---|---|---|
  | class 走査 (`ClassScanDiagnostic`) | `tests/js/styles/class-usage.test.ts` | 実リポジトリ走査の `diagnostics.length === 0` |
  | CSS var 走査 (`CssVarReferenceDiagnostic`) | `tests/js/styles/token-reference-closure.test.ts` | 同上 |
  | Markdown 走査 (`MarkdownDiagnostic`) | `tests/js/styles/design-system-docs.test.ts` (docs) / `tests/js/styles/component-doc-parity.test.ts` (DESIGN.md) | 同上 |

  S3 / S5 / S7 / S8 は**この保証に依存する**ことを各節と `docs/design-system.md` の
  責務境界表の行に明記する。

### [Warning] 「例外を投げず診断へ積む」と「未終端は例外になる」が食い違う

- 判断: **対応する**
- 対応内容: **構文解析の失敗はすべて診断**へ統一する (例外を投げない)。
  診断が出たファイルの `occurrences` / `pairs` は**空にする** (best-effort で返さない —
  部分結果を後続 gate が使う形を作らない)。診断があれば gate は必ず落ちる。

### [Warning] `TemplateExpression` の subtree へ降りると補間内部の文字列を二重に拾う

- 判断: **対応する**
- 対応内容: `TemplateExpression` を `interpolated` として記録した時点で
  **その subtree へは降りない**。補間内部に class 風の文字列を置いた検体で
  重複抽出が起きないことを固定する。

## S3

### [Critical] `var()` 走査の戻り値に診断の格納先が無い

- 判断: **対応する** (S2 と同じ穴)
- 対応内容: 結果型を導入する。

  ```ts
  interface CssVarReferenceScan {
      readonly references: readonly CssVarReference[];
      readonly diagnostics: readonly CssVarReferenceDiagnostic[];
  }
  ```

  実リポジトリに対しては `diagnostics.length === 0` を明示的に要求する。

### [Critical] postcss の `Decl.value` は文字列なので、文字列・コメント・入れ子を区別できない

- 判断: **対応する**。ただし**新しい依存は足さない**
- 実測 (postcss 8.5): **コメントは `Decl.value` から既に除かれている**
  (`color: var(--a /* c */)` → `value === "var(--a )"`、原文は `raws.value.raw`)。
  よって残る問題は**文字列区間**だけである。
- 対応内容: **値走査の受理契約**を明文化する (これで括弧カウントだけの実装にはならない)。
  1. 値を左から 1 文字ずつ走査する。`'` / `"` で始まる区間は**エスケープ (`\`) を尊重して**読み飛ばす
  2. 閉じない引用があれば診断 `unterminated-string`
  3. 引用区間の**外**で `var(` を見つけたら、括弧の対応を数えて引数列を取る。
     閉じない括弧は診断 `unterminated-function`
  4. 第 1 引数は前後の空白を除いて `--` で始まる識別子でなければ診断 `unresolvable-var`
  5. fallback (第 2 引数以降) は同じ規則で**再帰的に**走査する
  6. `raws.value.raw` は**使わない** (コメントを含む生値を入力にしない)
  正負例: `content: "var(--x)"` は参照に数えない / `color: var(--a /* c */)` は
  `--a` を 1 件拾う / `var(--a, var(--b))` は 2 件 / `var(--a` は診断 /
  `--f: "a,b", c` は参照 0 件・診断 0 件。

### [Warning] 「対象 at-rule の params」が未定義

- 判断: **対応する**
- 対応内容: 参照母集団に含める at-rule を **`@media` / `@supports` / `@container` の
  3 つに限定**して列挙する (条件式に `var()` を書ける at-rule)。
  **列挙外の at-rule の params に `var(` が現れたら診断** `unsupported-at-rule-params` にする
  (無視しない = fail-closed)。

## S5

### [Warning] 分類が 9 種類になったのに「8 分類」の記述が残り、pending の列挙も古い

- 判断: **対応する**
- 対応内容: **分類数を散文から削る**。固定検体の網羅は
  `UndecidableReason` の union から**機械的に導出**して
  「各 reason を発火させる検体が 1 つ以上ある」ことを検査する。
  `PENDING_CONTRAST_PAIRS` の説明も union から生成する
  (少なくとも 9 理由すべてを含む)。

## S8

### [Critical] list と blockquote を組み合わせた container 内 fence を検出できない

- 判断: **対応する** → S9 の Critical と同じ対処で解決する
- 対応内容: S9 の走査器が**container prefix を伴う fence 候補をすべて診断**にするので、
  S8 は「Markdown 走査の**診断が 1 件でもあれば必ず失敗**」を要求する。

### [Warning] `parseDesignComponentSections()` はすべての解析失敗を拒否する契約にすべき

- 判断: **対応する**
- 対応内容: `unparsableFenceLines` という個別の口をやめ、
  **共通の `diagnostics` を 1 本**にして、未終端コメント / 未終端 fence /
  container fence / 未対応 fence を**同じ経路**で消費する。

## S9

### [Critical] 「扱う必要があるのは `>` の 1 種類だけ」は成立しない

- 判断: **対応する**。**container 文法を扱わずに済む判定へ変える**
- 根拠: `- > ``` ` / `> - ``` ` は「行頭の `>` だけを剥がす」でも
  「raw 行へ `^ {0,3}` を当てる」でも通過し、4 連続空白も含まないので契約 B でも落ちない。
- 対応内容 (**契約 A の判定を書き換える**):
  囲みコードの外の各行について、
  **fence marker (3 個以上連続した `` ` `` または `~`) が行のどこかに現れたら**、
  その行が**正規の top-level fence 行** (`^ {0,3}` の直後に marker が来て、
  backtick 型なら info string にバッククォートを含まない) で**ない**限り、
  **診断**にする。
  - これで `- > ``` ` も `> - ``` ` も `  > ``` ` も**すべて落ちる**。
    container 文法 (list marker の記法・padding・入れ子順) を**1 つも書かない**。
  - 行内コード span は 1〜2 個のバッククォートなので誤検出しない。
  - 実測: `docs/design-system.md` と `DESIGN.md` はどちらも fence 0 行なので偽陽性は起きない。

### [Critical] 診断が blockquote fence だけしか表現できない

- 判断: **対応する**
- 対応内容: 理由つき union にする。

  ```ts
  type MarkdownDiagnosticReason =
      | "unterminated-html-comment"
      | "unterminated-fence"
      | "container-fence"      // container prefix を伴う fence 候補
      | "unsupported-fence";   // 受理範囲外の fence 記法
  ```

  `unparsableFenceLines` は**廃止**し、`diagnostics` に一本化する。

### [Warning] fence の開始・終了規則が実装者依存

- 判断: **対応する**
- 対応内容: 受理範囲を明記する — marker は**同一文字 3 個以上**、
  開始は**字下げ 3 空白まで**、終了は**開始と同じ種類で開始以上の長さ・後続は空白のみ**、
  backtick 型は**info string にバッククォートを含められない**。
  範囲外は**通常本文にせず診断** (`unsupported-fence`) にする。

### [Warning] 「非描画領域」という呼称が fenced code には正確でない

- 判断: **対応する**
- 対応内容: 呼称を **「規範判定対象外領域」**へ改める。
  意味差も併記する — **HTML コメントは読者に描画されない**、
  **囲みコードは描画されるが規範の本文として数えない**。

## S12

### [Critical] D51 が現状の S9 より強い保証を記述している

- 判断: **対応する**
- 対応内容: S9 の確定 (fence marker の出現で判定する形) に合わせて D51 を書き直す。
  「`^ {0,3}` 以外は解析失敗」という言い方をやめ、
  **「囲みコードの外に fence marker が現れ、正規の top-level fence 行でなければ診断にする」**
  と、実装が実際に拒否する形で書く。

### [Warning] D51 でも fenced code を「描画されない領域」としている

- 判断: **対応する**
- 対応内容: D51 の保証を 2 つに分ける —
  (a) **HTML コメントは非描画なので落とす**、
  (b) **囲みコードは描画されるが規範判定の対象外として落とす**。

---

# 詳細設計書（Round 1〜6 の指摘を反映した改訂版）

# 詳細設計: design-token-system 正典 v1 追従

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) → 実行単位 (`GuardedPrompt`) の**1 本道のみ**)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

加えて `app-design` スキルが設計判断に直結するものとして挙げる核: 既存テストの削除・上書き禁止 /
`DatabaseTransactions` の個別使用禁止 / やたらに複雑な案を提案しない。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）。**アプリケーション PHP は 1 行も変えない**。
  変更するのはテスト支援 PHP (`tests/Support/TemplateDivergence/LedgerPins.php`) の
  `int` 定数 2 本の値だけで、型は変わらないため PHP 側の型の母集団に変化は無い
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）。**本作業は DB を使わない**
- **DTO + JsonResource** パターン（本作業には該当なし）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- **TS 側の型の閉じ方** (概念設計「型の方針」):
  discriminated union / `as const satisfies` / 分類の網羅を `never` へ収束
- **AGENTS.md「静的検査 (gate) と走査器の共通規約」の 5 条**を新設・変更する走査器すべてに適用し、
  **同じ PR で 4 点** (負例と正例 / 解決できない形を落とす分岐 / 空振り検知 / docblock) を揃える

## 概念設計リファレンス

- [devnotes/20260824-1019-design-token-system-v1/conceptual-design.md](./conceptual-design.md) (Codex `gpt-5.6-terra` Round 3 で APPROVED)
- 実測記録: [contrast-measurements.md](./contrast-measurements.md)
- 逆引き表: [token-change-impact.md](./token-change-impact.md)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 写像 (tokens.css) の読み出しを 1 実装へ集約し、`@theme` ブロックの一意性を機械で固定する (i21 / i2 前半) | `tests/js/styles/theme-map.ts` (新) / `tests/js/styles/theme-map.test.ts` (新) / `canonical-source-parity.test.ts` / `tokens.test.ts` | 高 (他施策の土台) |
| S2 | class 走査器を新設する (i15 / i16 / i9 の共通入力 + 未対応入口の deny) | `tests/js/styles/class-usage.ts` (新) / `tests/js/styles/class-usage.test.ts` (新) | 高 (土台) |
| S3 | 参照の閉包 gate を新設し、写像の外の色語を落とす (i9) | `token-reference-closure.test.ts` (新) / `inventory.ts` / `AppLayout.svelte` / `SidebarNavItems.svelte` | 高 |
| S4 | 線形化しきい値を 0.04045 へ揃える (i13) | `contrast-invariant.test.ts` | 高 (S5 の前提) |
| S5 | 半透明背景 × 不透明文字の合成検査を新設する (i16) | `contrast-invariant.test.ts` / `inventory.ts` | 高 |
| S6 | トークン値を是正する (i16 の帰結) | `DESIGN.md` / `resources/css/tokens.css` | 高 |
| S7 | 実装からの逆向き被覆と役割分類の是正 (i15 / i14) | `contrast-invariant.test.ts` / `inventory.ts` | 高 |
| S8 | 文書 ⇔ 実装の双方向一致 gate を新設する (i10) | `component-doc-parity.test.ts` (新) / `design-md.ts` / `inventory.ts` / `DESIGN.md` | 中 |
| S9 | 非描画領域の除去と字下げの禁止を 2 契約に分け、行分類を 1 実装へ集約する (i12 の残余。**S8 の前提**) | `tests/js/styles/markdown-lines.ts` (新) / `design-system-docs.test.ts` / `design-md.ts` / `docs/design-system.md` | 中 |
| S10 | 不透明度修飾の生成形を契約として固定する (i6 の補強 / S5 の前提の裏取り) | `tokens.test.ts` | 中 |
| S11 | 責務境界表へ新設 gate を登録する (i11 の帰結) | `docs/design-system.md` | 中 (必須。書かないと S1/S2/S3/S8 で既存 gate が落ちる) |
| S12 | 共有パスの採用時債務を決着させる (乖離台帳 D50 / D51 の新設と D28 の本文訂正) | `docs/template-divergence.md` / `LedgerPins.php` / `adoption-debt.tsv` | 中 (必須) |

**実施順**: S1 → S2 → S4 → S10 → S5 → S6 → **S3 → S7** → **S9 → S8** → S11 → S12。
S4 を S5 より先に置くのは、しきい値を直してから合成の期待値を書くため
(逆順だと 0.03928 基準の期待値を書いて後で全部直すことになる)。
S6 (値の是正) は S5 の赤を確認した**後**に行う (テストファースト。思考原則 5)。
**S3 を S7 より先に置く** (Round 1 レビューの指摘で入れ替えた): S7 の逆向き被覆は
S3 が `text-white` を `text-surface` へ直した**後**に現れる `(surface, primary)` の赤を
前提にしているため、逆順だと S7 の「先に赤くするテスト」の記述が実際の実行順と食い違う。
**S9 を S8 より先に置く** (Round 5 の Warning で入れ替えた): S8 の節抽出は
S9 が新設する `tests/js/styles/markdown-lines.ts` (`scanMarkdownLines()`) を使うので、
逆順だと S8 の前提が存在しない。
S11 は S1 / S2 / S3 / S8 が新設する `tests/js/styles/*.test.ts` を既存 `design-system-docs.test.ts` の
双方向集合一致が要求するので、**同じコミットの中**で行う。

> **Codex レビューの反映**: 本書は Codex (`gpt-5.6-sol` / reasoning=high) の詳細設計レビューを
> 6 ラウンド受け、**全件を対応して改訂した版**である (反論 0 件)。
> ★スキルの既定は最大 5 ラウンドだが、Round 5 で設計の骨格 (解析器の選択) を作り替えたため
> **確認のために 1 ラウンド超過**した。
> 件数は**行頭のラベル**の機械カウント (`grep -c '^\[Critical\]'` 等) を正本にする
> (本文中のラベルへの言及まで数えていたため Round 5 で数え方を行頭に限定した)。
> - Round 1: Critical 12 / Warning 11 / Suggestion 1 →
>   [decisions-round-1](./codex-history/design-review-decisions-round-1.md)
> - Round 2: Critical 7 / Warning 11 / Suggestion 1 →
>   [decisions-round-2](./codex-history/design-review-decisions-round-2.md)
> - Round 3: Critical 6 / Warning 10 →
>   [decisions-round-3](./codex-history/design-review-decisions-round-3.md)
> - Round 4: Critical 2 / Warning 8 / Suggestion 1 →
>   [decisions-round-4](./codex-history/design-review-decisions-round-4.md)
> - Round 5: Critical 7 / Warning 6 →
>   [decisions-round-5](./codex-history/design-review-decisions-round-5.md)
> - Round 6: Critical 9 / Warning 9 →
>   [decisions-round-6](./codex-history/design-review-decisions-round-6.md)

---

## S1 写像の読み出しを 1 実装へ集約し、`@theme` ブロックの一意性を固定する (i21 / i2 前半)

### 変更箇所

- 新規: `tests/js/styles/theme-map.ts` (パーサ。gate ではない)
- 新規: `tests/js/styles/theme-map.test.ts` (パーサの自己検査 = 固定検体の負例・正例)
- `tests/js/styles/canonical-source-parity.test.ts` (L29-35 の `cssColorTokens()` を削除して移設、
  L66-69 の radius 抽出と L122 の `@utility` 抽出も移設)
- `tests/js/styles/tokens.test.ts` (`REPO_ROOT` の import 元は `design-md.ts` のままでよい。
  写像のテキストを読む必要が生じた箇所だけ `theme-map.ts` を使う)

### 波及変更

- TypeScript 型定義: `theme-map.ts` の公開型を新設 (下記)。`ParsedColor` / `Rgb` は
  S5 (合成) と S10 (派生の導出検査) が import する
- API Resource/DTO: なし
- テストファイル: `canonical-source-parity.test.ts` の import 追加 / ローカル関数の削除

> ⚠ `theme-map.ts` は `*.test.ts` ではないので `design-system-docs.test.ts` の
> `gateFiles()` の母集団には入らない。一方 `theme-map.test.ts` は**入る**ので
> S11 で責務境界表へ行を足す (足さないと既存 gate が赤くなる)。

### 現行コード

```ts
// tests/js/styles/canonical-source-parity.test.ts (L27-35)
const tokensCss = fs.readFileSync(path.join(REPO_ROOT, "resources/css/tokens.css"), "utf-8");

function cssColorTokens(): Map<string, string> {
    const map = new Map<string, string>();
    for (const m of tokensCss.matchAll(/--color-([a-z-]+):\s*([^;]+);/g)) {
        map.set(m[1], m[2].replace(/\/\*.*?\*\//g, "").trim().toLowerCase());
    }
    return map;
}
```

`--radius-*` の抽出 (L66-69) と `@utility text-*` の抽出 (L122) も**同ファイルの中に直書き**されている。
`tokens.test.ts` は生成 CSS 側を読むので写像のテキストは読んでいないが、
S3 (参照の閉包) が `@theme` の宣言集合を必要とするため、**このまま新 gate に 2 本目の
パーサを書くと i21 に反する**。

### 変更後コード

```ts
// tests/js/styles/theme-map.ts (新設)
/**
 * 実装写像 (resources/css/tokens.css) の読み出し — 検査テスト共有。
 *
 * ★正典 i21: 正本と写像の読み出しは**それぞれ 1 実装へ集約する**。
 *   同じ関心の解析が 2 本あると弱い方が緑を作る (「片方だけが読める写像」が成立する)。
 *   正本 (DESIGN.md) 側は design-md.ts が担当する。本ファイルは写像側だけを担当する。
 *
 * 【走査対象】呼び出し側が渡した CSS ソース文字列。実ファイルを読むのは薄いラッパーだけである。
 * 【解析の方式】**postcss で構文木にしてから読む**。自前の字句走査は書かない。
 *   ★`postcss` は**既に devDependency で、`tokens.test.ts` が生成 CSS の解析に使っている**
 *     (同じ解析器を写像側にも使う = 思考原則 1「フレームワークのレンジ内でやる」)。
 *     手書きの字句走査で解こうとしていた次の 4 つは、すべて解析器の側で解決する —
 *     (a) 文字列リテラルの中の `/*` `{` `}` の誤認 (`--font-sans` は引用符つきの値を 8 個持つ)、
 *     (b) at-keyword の境界 (`@theme-extra` は別の `name` になる)、
 *     (c) 宣言値の中の `@theme` (`Decl` の値であって `AtRule` にならない)、
 *     (d) 未終端のコメント・文字列・閉じないブロック (`CssSyntaxError` が飛ぶ = fail-closed)。
 *   ★受理する形は**実測して一意に決めた** (postcss 8.5 で確認。下の「実測表」)。
 *   読み方は 6 条 (外れたものはすべて**例外** = i20):
 *     1. `@theme` は `AtRule` かつ `name === "theme"` の**完全一致**で、
 *        **`params === ""`** かつ **`nodes !== undefined`** (ブロックを持つ) であること
 *     2. `topLevel` は `parent` が `Root` であること
 *     3. 宣言は**トップレベル `@theme` の直接の子 `Decl`** だけを採る。
 *        許容する直接子は **`Decl` と `Comment` の 2 種**で、**`Comment` は無視する**
 *        (tokens.css は `@theme` の中に節見出しコメントを持つので拒否すると実装できない)。
 *        `Rule` / 別の `AtRule` / その他のノードがあれば**例外**
 *     4. 同名宣言が 2 件以上あれば**例外** (postcss は後勝ちにせず `Decl` を 2 件返すので検出できる)
 *     5. `@utility` は**ルート直下**・`params` が `^text-[a-z0-9-]+$`・`nodes !== undefined`・
 *        直接の子が `Decl` と `Comment` だけ (Comment は無視)・同じ `params` の重複が無いこと
 *     6. 構文エラー (未終端コメント / 未終端文字列 / 閉じないブロック) は postcss の例外を伝播させる
 * 【保証しないもの】
 *   - Tailwind の解釈 (宣言が生成 CSS に出るか) は見ない。それは tokens.test.ts の担当
 *   - postcss の AST 形状に依存する。postcss の major 更新で形が変われば
 *     固定検体が最初に落ちる (無言で緑にはならない)
 *   - 値の意味 (色空間・単位) は見ない。色だけは parseCssColor が明示的に扱う
 */
export interface ThemeBlock {
    /** ソース先頭からのブロック開始位置 (診断用。期待値には使わない) */
    readonly offset: number;
    /** ルート直下の `@theme` か (条件つき at-rule の内側なら false) */
    readonly topLevel: boolean;
}
/* ★`body` (ブロック本文の文字列) は**持たない** — どこからも使わない出力を作らない
   (共通規約 (d)「集めた走査結果を判定に使わない形を作らない」)。宣言は AST から採る。 */

/** 1 本のソースを解析した結果。 */
export interface ThemeMap {
    /** 見つかった `@theme` ブロック全件 (0 件・2 件以上も呼び出し側が判定できるよう返す) */
    readonly blocks: readonly ThemeBlock[];
    /** ルート直下の `@theme` 直下の CSS 変数宣言 `{ 変数名 → 値 }` */
    readonly declarations: ReadonlyMap<string, string>;
    /** `@utility text-<name>` の宣言 `{ name → { プロパティ → 値 } }` */
    readonly rampUtilities: ReadonlyMap<string, ReadonlyMap<string, string>>;
}

/**
 * ★**唯一の解析実装**。実ファイル用の関数はすべてこの薄いラッパーである
 *   (Round 1 レビューの指摘: 固定検体を解析する入口が公開 API に無いと、
 *   `theme-map.test.ts` が任意入力を検査できず i18 の裏取りにならない)。
 * `file` は例外メッセージに載せる識別子であって、ファイルを読むためのものではない。
 */
export function parseThemeMap(source: string, file: string): ThemeMap;

/** `resources/css/tokens.css` を読んで `parseThemeMap` に渡す薄いラッパー。 */
export function tokensCssThemeMap(): ThemeMap;

/** `--color-<suffix>` だけを suffix で引ける形にしたもの (コメント除去・小文字化)。 */
export function cssColorTokens(): ReadonlyMap<string, string>;

/** `--radius-<suffix>` だけを suffix で引ける形にしたもの。 */
export function cssRadiusTokens(): ReadonlyMap<string, string>;

/** `@utility text-<name>` の宣言 (`tokensCssThemeMap().rampUtilities` の別名)。 */
export function cssRampUtilities(): ReadonlyMap<string, ReadonlyMap<string, string>>;

/**
 * 色の値を厳密に解析する (派生 token の値の検査と、合成の入力に使う)。
 *
 * 【受理する形】`#rrggbb` (大小文字どちらも) / `rgba(r, g, b, a)` / `rgb(r g b / a)`。
 *   ★`#rrggbb` は必須である — 正本 (`designColors()`) が返すのは hex で、
 *     S10 の「派生 token は正本の primary の RGB を alpha 0.12 にしたもの」の検査が
 *     正本側の hex を本関数へ渡す。
 * 【厳密に拒否する】RGB が 0..255 の整数でない / alpha が 0..1 でない /
 *   余分な末尾文字がある / 数値にならない / 上記以外の関数記法 (`color-mix(…)` 等)。
 *   いずれも**例外**にする (i20: 読めるものだけ拾う形にしない)。
 */
export function parseCssColor(value: string): ParsedColor;

/** 色の正規化形 (S5 の合成と S10 の派生検査が共有する)。 */
export type ParsedColor =
    | { readonly kind: "opaque"; readonly rgb: Rgb }
    | { readonly kind: "alpha"; readonly rgb: Rgb; readonly alpha: number };

export interface Rgb {
    readonly r: number;
    readonly g: number;
    readonly b: number;
}
```

**postcss の実挙動 (実測。設計の期待値の根拠)**:

| 入力 | 結果 |
|---|---|
| `@theme { --a: 1px; }` | `AtRule(name="theme", params="")` + 子 `Decl` |
| `@theme-extra { … }` | `AtRule(name="theme-extra")` — 別物 |
| `@/* c */theme { … }` | **例外** (`CssSyntaxError: At-rule without name`) |
| `@theme;` | `AtRule(name="theme")` で `nodes === undefined` |
| `@theme foo { … }` | `AtRule(name="theme", params="foo")` |
| `--x: "@theme { }";` | `Decl` のみ (at-rule にならない) |
| `@theme { --f: "a{b"; --g: 2px; }` | 宣言 2 件を正しく採る |
| `@theme { --a: 1px; --a: 2px; }` | `Decl` が 2 件現れる (呼び出し側が重複を検出できる) |
| `@theme { :root { … } }` | 子に `Rule` が現れる |

- `canonical-source-parity.test.ts` は**ローカル関数を削除**して `theme-map.ts` を使う
  (後方互換の並走を残さない = AGENTS.md 思考原則 3)。
- `@theme` の一意性は `canonical-source-parity.test.ts` に describe を 1 つ足して固定する
  (写像の形の検査なので 正本 ⇔ 写像 の gate が持つのが自然)。

```ts
describe("canonical source parity: 写像の形", () => {
    it("@theme ブロックがリポジトリに 1 つだけある (2 つ目の宣言が検査を素通りする経路を塞ぐ)", () => {
        // 走査は git 追跡下の *.css 全数。tokens.css の外に @theme を置くと
        // canonical-source-parity / tokens の両方が見ない token 空間が育つ。
        const cssFiles = trackedCssFiles();
        expect(cssFiles.length, "*.css が 1 件も取れない (走査の空振り)").toBeGreaterThan(0);
        // ★判定は parseThemeMap の結果で行う (コメントの中の @theme を数えない)。
        const withTheme = cssFiles.filter(
            (rel) => parseThemeMap(readCss(rel), rel).blocks.length > 0,
        );
        expect(withTheme).toEqual(["resources/css/tokens.css"]);
        expect(tokensCssThemeMap().blocks.length, "tokens.css の @theme が 1 ブロックでない").toBe(1);
        expect(tokensCssThemeMap().blocks[0].topLevel, "@theme がルート直下でない").toBe(true);
    });
});
```

写像のキー空間と正本のキー空間の橋渡しが一意であることも同じ describe で固定する。

```ts
    it("COLOR_TOKEN_MAP の逆写像が一意である (suffix → DESIGN キーが後勝ちにならない)", () => {
        // 走査器は suffix 空間を返し、gate は逆写像で DESIGN キー空間へ写す。
        // 値に重複があると逆引きが後勝ちになり、別のトークンの値で検査してしまう。
        const suffixes = Object.values(COLOR_TOKEN_MAP);
        expect(suffixes.length, "COLOR_TOKEN_MAP が空 (走査の空振り)").toBeGreaterThan(0);
        expect(new Set(suffixes).size).toBe(suffixes.length);
    });
```

- `trackedCssFiles()` は `git ls-files -- '*.css'` を使わず、**`resources/` の再帰走査**で
  取る (テスト実行で子プロセスを起こさない。`vitest-inventory-gate` が
  「収集フェーズで spawn しない」規約を持つのと同じ配慮)。
  走査根は `resources/` 1 本で、**存在しなければ fail-fast** にする。
  `node_modules` / `vendor` / `public/build` は走査根の外なので自然に落ちる。
  **保証範囲**: `resources/` の外に置いた CSS は見ない — これを docblock に明記する
  (アプリの CSS はすべて `resources/css` にあり、`vite.config` の入口も同ディレクトリである)。

### 型適合チェック

- [x] 戻り値の型が明示されている (`readonly` / `ReadonlyMap` で外から書き換えられない)
- [x] `null` 安全: 解析失敗は `undefined` を返さず**例外**にする (i20 = 解析の失敗を pass に変えない)
- [x] 配列返却ではなく `ReadonlyMap` / `interface` を返す
- [x] Generics の型パラメータが正しい (`ReadonlyMap<string, ReadonlyMap<string, string>>`)

### テスト計画

- [x] **先に赤くするテスト**: `canonical-source-parity.test.ts` の新 describe「@theme ブロックが
      リポジトリに 1 つだけある」。実装前は `parseThemeMap()` が存在しないので**コンパイルエラーで赤**。
      次に `theme-map.ts` を空実装 (`throw`) で置いて**実行時エラーで赤**を確認してから実装する
- [x] 既存テスト `canonical-source-parity.test.ts` の 8 it は**移設後も同じ期待値で緑**であること
      (リファクタの等価性の確認)
- [x] 新規: `tests/js/styles/theme-map.test.ts` — **固定検体を `parseThemeMap(source, file)` へ
      直接渡して**パーサの仕様を固定する (i18。実ファイルを読む経路は検体を差し込めない)
  - 負例 1: `@theme` を 2 ブロック持つ検体 → `blocks.length === 2` (呼び出し側が落とせる)
  - 負例 2: `@media` の中の `@theme` → **ブロックとして数える**が `topLevel === false` で、
    `declarations` は**トップレベルの `@theme` だけ**を見る (i2 後半と同じ絞り込み)
  - 負例 3: **コメントの中の `@theme`** (`/* @theme { --color-x: red; } *​/`) →
    `blocks.length === 0` (コメント除去を先に行う仕様の裏取り)
  - 負例 4: 同名変数の再宣言 → 例外 (i20)
  - 負例 5: `@theme` の中に別の `AtRule` がある → 例外 (深さ 1 段の前提を破る形)
  - 負例 6: **閉じないブロック** (`@theme {` のまま EOF) → 例外 (`CssSyntaxError`)
  - 負例 7: `parseCssColor("color-mix(in oklab, red 10%, transparent)")` → 例外
    (扱えない色表現を「読めた」ことにしない)
  - 負例 8: `parseCssColor("rgba(300, 0, 0, 0.1)")` (RGB が範囲外) → 例外
  - 負例 9: `parseCssColor("rgba(29, 78, 216, 1.5)")` (alpha が範囲外) → 例外
  - 負例 10: `parseCssColor("#1d4ed8ff")` (余分な末尾文字) → 例外
  - 負例 10b: `@theme-extra { … }` / `@utility-extra text-x { … }` →
    **数えない** (postcss の `name` の完全一致)
  - 負例 10c: 未終端のコメント (`/*` のまま EOF) → 例外 (postcss の `CssSyntaxError`)
  - 負例 10d: 未終端の文字列 (`'` のまま EOF) → 例外 (同上)
  - 負例 10e: **宣言値の中の `@theme`** (`--x: '@theme { }';`) → ブロックとして数えない
  - 負例 10f: `@/* c */theme { }` → **例外** (`CssSyntaxError: At-rule without name`。実測値)
  - 負例 10g: `@theme` の中に `Rule` (`:root { }`) がある → 例外
  - 負例 10h: `@theme;` (ブロック無し) → 例外 (`nodes === undefined`)
  - 負例 10i: `@theme foo { }` (params つき) → 例外
  - 負例 10j: `@utility text-x` が 2 つ → 例外 / `@utility bg-x { }` (params が規則外) → 例外
  - 正例 4: `@theme { --f: "a{b"; --g: 2px; }` → 宣言 2 件を正しく採る
    (文字列の中の `{` を誤認しない。実測で確認済み)
  - 正例 5: `@theme { /* 節見出し */ --a: 1px; }` / `@utility text-x { /* c */ font-size: 1px; }` →
    **`Comment` を無視して**宣言を採る (現行 tokens.css がこの形である)
  - **負例 11〜14 (文字列状態の裏取り。Round 2 の Critical)**:
    - `--x: '/* not a comment */';` → コメントとして潰されず、宣言が 1 件取れる
    - `--x: '{';` / `--y: '}';` → ブロックの対応が壊れない
    - `--x: 'it\\'s';` (エスケープした引用符) → 文字列がそこで閉じない
    - **現行 `--font-sans` と同形の宣言** (引用符つき family を 8 個持つ) →
      値が丸ごと 1 つの宣言として取れる
  - 正例 1: 現行 tokens.css と同形の検体で色 / radius / ramp が期待どおり取れる
  - 正例 2: `parseCssColor("rgba(29, 78, 216, 0.12)")` →
    `{ kind: "alpha", rgb: { r: 29, g: 78, b: 216 }, alpha: 0.12 }`
  - 正例 3: `parseCssColor("#1d4ed8")` →
    `{ kind: "opaque", rgb: { r: 29, g: 78, b: 216 } }`
- [x] 母集団の非空: `tokensCssThemeMap().declarations.size > 0` / `cssColorTokens().size > 0` /
      `cssRampUtilities().size > 0` (共通規約 (b) の 3 点目)
- [x] 個別の `DatabaseTransactions` を使っていない (DB を使わない)

### リスク

- リファクタで既存 8 it の期待値が変わると、**値の drift を見逃す穴**が開く。
  → 等価性の担保は「**期待値を変えない**」であって「実装を変えない」ではない。
  **解析実装そのものは postcss ベースへ置換する** (Round 3 の Critical: 旧リスク欄の
  「本体をそのまま移す」「正規表現を書き換えない」は、Round 2 で問題になった
  **文字列の中の `/*` `{` `}` を誤認する実装を温存する**指示になっていた)。
  移設の受け入れ条件は「既存 8 it が同じ期待値で緑になること」だけである。
- `resources/` 再帰走査は将来 CSS を別の場所へ置いたときに見落とす。
  → docblock に保証範囲として明記し、`vite.config.ts` の入口が
  `resources/css/app.css` であることを根拠として書く。

---

## S2 class 走査器を新設する (i15 / i16 / i9 の共通入力)

### 変更箇所

- 新規: `tests/js/styles/class-usage.ts` (走査器。gate ではない)
- 新規: `tests/js/styles/class-usage.test.ts` (走査器の自己検査 = 固定検体の負例・正例)

> ⚠ `class-usage.ts` は `*.test.ts` ではないので `design-system-docs.test.ts` の
> `gateFiles()` の母集団には入らない (母集団は `tests/js/styles/*.test.ts`)。
> 一方 `class-usage.test.ts` は**入る**ので S11 で責務境界表へ行を足す。

### 波及変更

- TypeScript 型定義: 下記の公開型 (走査結果) を新設。`inventory.ts` が理由の union を参照する
- API Resource/DTO: なし
- テストファイル: S3 / S5 / S7 の gate が本走査器を import する

### 変更後コード (公開する型と関数)

```ts
// tests/js/styles/class-usage.ts (新設)
/**
 * resources/js の class 記述から「前景 × 背景の組」と「解決できなかった形」を導出する走査器。
 *
 * 【走査分母】resources/js のディレクトリ単位の再帰走査 (`*.svelte` / `*.ts`)。
 *   ファイルを足したら自動で分母に入る (正典 i15 / s14: 固定のファイル列挙は足し忘れが静かに起きる)。
 *
 * 【解析の方式】**既存の解析器で構文木 / トークン列にしてから読む**。自前の字句走査は書かない。
 *   ★準拠実装がリポジトリに在る — `tests/js/support/file-input-scan.ts` は
 *     `svelte/compiler` の `parse()` で `.svelte` を AST にし、解析できない形を
 *     診断へ落とす (`parse-failed` / `unresolved-*`)。`typescript` も既に devDependency で、
 *     `tests/js/support/enum-ts-sync/*` が `ts` の API を使っている。
 *   - `.svelte`: `parse(source, { modern: true })` の AST を歩き、
 *     `class` 属性の `Text` チャンクと、式の中の**文字列リテラルのノード**を単位にする。
 *     parse が失敗したら診断 `parse-failed` にして**gate を落とす**
 *   - `.ts`: **`ts.createSourceFile()` で AST 化**し、ノード種別で分類する —
 *     `StringLiteral` / `NoSubstitutionTemplateLiteral` は**単位**、
 *     `TemplateExpression` (置換つき) は **`interpolated` の判定不能**。
 *     ★**`ts.createScanner()` は使わない** (Round 5 の Critical: scanner は字句解析器であり、
 *       `` `${cond ? "}" : v}` `` の `}` が補間の終端か object literal の内側かを
 *       判断するには構文文脈が要る。scanner を順に呼ぶだけでは解けない)。
 *     **parse diagnostics が 1 件でもあれば解析失敗**にする
 *     (括弧の不整合など構文エラー全般が fail-closed になる。scanner では字句エラーしか拾えない)
 *   ★`TemplateExpression` を `interpolated` として記録したら、**その subtree へは降りない**
 *     (降りると補間内部の `StringLiteral` を独立した class 単位として二重に拾う。Round 6 の Warning)
 *   ★**構文解析の失敗はすべて診断**にする (例外は投げない)。
 *     診断が出たファイルの `occurrences` / `pairs` は**空にする** —
 *     部分結果を後続 gate が使う形を作らない (best-effort で返さない。Round 6 の Warning)。
 *   ★**未終端**のコメント・文字列・template・補間は解析器がエラーとして返すので**診断**にする。
 *     単純な波括弧の数え上げで補間の終端を誤認し、**以降のソースを無言で読み落とす**経路は
 *     この方式では生じない (Round 4 の Critical)。
 *   ★解析不能後に**残りのファイルを無言で捨てない** — 診断は必ず結果に残り、
 *     gate は診断が 1 件でもあれば落ちる (共通規約 (b) の 1 点目)。
 *
 * 【走査単位 (これが保証する構文集合)】**文字列リテラル**。単位の中だけで状態と組を作る。
 *   ★**それ以外の形については検出力を主張しない**。代わりに、扱えない**既知の入口**を
 *     語彙の deny (unsupportedEntryPoints()) で 0 件に固定する。
 *
 * 【class 候補の分解 (3 段)】
 *   1. まず **CSS の空白** (空白 / タブ / 改行 / CR / FF) で class 候補へ分割する
 *   2. **監視対象かどうかを先に判定する** (`isWatchedCandidate()`)。
 *      ★これが無いと、import 指定子 (`"./Button.types"`) や URL のような
 *        「そもそも class ではない文字列」まで文字検証に掛かって `unparsable-token` になり、
 *        実リポジトリを正常に走査できない (Round 6 の Critical)。
 *      判定は 3 段で、**文字検証はしない** —
 *        (a) 先頭から `<何らかの文字列>:` の並びを variant 列として剥がす
 *        (b) 残りの先頭の `!` を剥がす
 *        (c) 残りが**監視対象接頭辞**のいずれかで始まるなら監視対象
 *      監視対象接頭辞は `WATCHED_UTILITY_PREFIXES` に**1 か所だけ**宣言し、
 *      S3 (閉包) と共有する (`bg-` / `text-` / `border-` / `ring-` / `divide-` /
 *      `outline-` / `rounded-` / `fill-` / `stroke-` / `decoration-` / `accent-` /
 *      `caret-` / `placeholder-` / `from-` / `to-` / `via-`)
 *   3. **監視対象と判定した候補だけ**を、候補**全体**の許可文字検証へ回す
 *      (英数字 / `_` / `-` / `:` / `/` / `.` / `%` / `[` / `]` / `!` / `#`。
 *      ds-purity.ts の CLASS_TOKEN_PATTERN と同じ集合)。
 *      **許可外の文字が 1 つでもあれば候補全体を `unparsable-token`** にする
 *   4. そのうえで variant / important / alpha / utility を分解する
 *   ★「許可文字以外はすべて区切り」という規則は**採らない** (Round 5 の Critical:
 *     それだと `bg-primaryあ` が `bg-primary` へ縮退して**有効な token として通り**、
 *     `bg-(--var)` も候補全体を未解決にする根拠を失う)。
 *
 * 【文字列リテラルの扱い (解析器が保証する範囲)】
 *   - 3 種のリテラル (単引用 / 二重引用 / バッククォート) の判別・エスケープ・
 *     コメントとの区別は**解析器の責務**である (自前で状態を持たない)
 *   - 置換を含む template literal は `interpolated` の判定不能にする
 *     (無言で「通常のリテラル」に落とさない = 共通規約 (b))
 *
 * 【不透明度修飾の受理範囲】`/` + 半角数字 1〜3 桁で値が **0..100** の形だけを受理する。
 *   - `/100` は**修飾なし (不透明)** と同じ扱い (`alpha === null`)
 *   - `/0` は**透明**なので背景が親から来る = `keyword-color` と同じ判定不能
 *   - 範囲外 (`/101`) / 負数 / 小数 / 任意値 (`/[0.35]`) は
 *     `unresolved: "unsupported-alpha-syntax"` にして**素通りさせない**
 *
 * 【状態の作り方】素の宣言を基底の状態とし、同じ修飾の連なり (`hover:` / `disabled:` …) を
 *   持つ宣言は基底をその修飾で上書きした状態とする。組は状態の内側だけで作る。
 *   ★**発火条件を形式化する** (Round 6 の Critical: 旧文面だと通常ケースまで該当し、
 *     肝心の例では発火しなかった) —
 *       各候補は variant 列 `V` を持つ (素の宣言は空列)。
 *       単位内の**非空の `V` の集合**を `S` とする (**基底は継承元なので `S` に入れない**)。
 *       `|S| ≤ 1` → **解決可能**。基底を `S` の唯一の列で channel ごとに上書きした状態を作る。
 *       `|S| ≥ 2` → **`variant-composition` の判定不能** (channel を跨いで単位全体を落とす)。
 *     variant 条件の包含関係は Tailwind の意味論であり、自前で再実装しない。
 *   これをしないと `"bg-surface text-danger hover:bg-danger hover:text-neutral"` から
 *   `text-danger on bg-danger` (比 1.0) という**実在しない組**が生まれる。
 *
 * 【保証しないもの (誇張しない)】
 *   - **宣言の単位をまたいで成立する組**。実例: atoms/input-state.ts は `text-text` を
 *     INPUT_BASE_CLASSES に、`bg-surface` / `bg-neutral` を inputStateClass() の戻り値に持つ。
 *     ただしこの穴の大部分は役割の直積 (i14) が覆っている — 両方の token に役割が在れば、
 *     その組は宣言が割れていても既に母集団の内側にある。見えないのは
 *     「直積に現れない役割の組み合わせの 2 token が同じ要素に載り、かつ宣言の単位が割れている」
 *     場合だけである
 *   - **親から渡る class** (`extraClass`) と**親要素から継承する背景** (正典 i22 (2))
 *   - **実行時に組み立てられる class** (正典 i22 (1))
 *   - **DOM の実際の入れ子**。同じ単位に載っていることは「同じ要素にある」ことの近似である
 *   - **変種の修飾の綴りが正しいこと**。`hoverr:bg-primary` は token としては解決する
 *     (変種の名前空間は Tailwind のもので、本アプリの写像ではない)
 */

/**
 * ★**すべての利用側 (S3 / S5 / S7) が同じ抽出結果から導出する**ための共通出力
 *   (Round 1 レビューの Critical: これが無いと S3 が 2 本目の走査器を書くことになり i21 に反する)。
 *
 * 解析は **3 段を独立に**行う — 変種の修飾 (`sm:` `hover:`) / 重要度の修飾 (`!`) /
 * 不透明度の修飾 (`/NN`)。**不透明度の修飾は色 utility にだけ許す**ので、
 * `text-center/50` は `unresolved: "alpha-on-non-color"` になり**素通りしない**。
 */
export interface ClassTokenOccurrence {
    /** リポジトリ相対のファイルパス */
    readonly file: string;
    /** 走査単位 (文字列リテラル) の識別子。行番号は持たない (正典 s14) */
    readonly unit: string;
    /** 区切りで分割したままの生のトークン (診断用。期待値には使わない) */
    readonly raw: string;
    /** 変種の修飾を出現順に並べたもの (`["sm", "hover"]`)。素の宣言は空配列 */
    readonly variants: readonly string[];
    /** 重要度の修飾が付いているか */
    readonly important: boolean;
    /** 変種・重要度・不透明度を取り除いた utility 名 (`bg-primary` / `text-center`) */
    readonly utility: string;
    /**
     * 不透明度修飾の**百分率** (0..100 の整数)。`null` は修飾なし。
     * ★名前で単位を分ける (Round 3 の Critical: `10` と `0.10` を同じ `number` で扱うと
     *   取り違えが型で落ちず、二重除算・除算漏れの温床になる)。
     *   0..1 の実効値を持つのは `ResolvedAlphaBackground.effectiveAlpha` **だけ**である。
     */
    readonly alphaPercent: number | null;
    /** utility 名が何へ解決したか */
    readonly resolution: TokenResolution;
}

/** utility 名の解決結果 (判別可能 union。未解決を無言で候補から外さない = 共通規約 (b))。 */
export type TokenResolution =
    | { readonly kind: "color"; readonly channel: ColorChannel; readonly suffix: string }
    | { readonly kind: "ramp"; readonly name: string }
    | { readonly kind: "radius"; readonly name: string }
    | { readonly kind: "contract"; readonly word: string }
    | { readonly kind: "unresolved"; readonly reason: UnresolvedReason };

/** 色 utility の channel。**前景 / 背景以外も分類する** (i17 の非テキスト境界を混ぜないため)。 */
export type ColorChannel = "background" | "foreground" | "border" | "ring" | "other";

/** 解決できなかった理由。 */
export type UnresolvedReason =
    | "unknown-token"            // テーマ名前空間の接頭辞を持つが写像にも契約表にも無い
    | "alpha-on-non-color"       // 色でない utility に不透明度修飾が付いている
    | "unsupported-alpha-syntax" // 不透明度修飾の書き方が受理範囲外 (下記)
    | "unparsable-token";        // 区切りで割れた形 (`bg-(--var)` / 非 ASCII の混入)

/** `var(--…)` 参照 (class ではない別チャネル)。 */
export interface CssVarReference {
    readonly file: string;
    readonly name: string;
    readonly resolution: TokenResolution;
}

/*
 * ★**純粋入口が唯一の実装**である (Round 2 の Critical。Round 1 で S1 に指摘された穴が
 *   S2 で再発していた)。実リポジトリ用の関数は**ファイルを読んで集約するだけ**の
 *   薄いラッパーで、固定検体は下の 3 本へ直接渡す。
 */
export function scanClassUsageSource(source: string, file: string): SourceClassUsageScan;
export function scanCssVarReferencesSource(source: string, file: string): readonly CssVarReference[];
export function unsupportedEntryPointsSource(
    source: string,
    file: string,
): readonly UnsupportedEntryPoint[];

export function scanCssVarReferences(): readonly CssVarReference[];

/** 走査で得た 1 つの組。 */
export type ScannedPair =
    | { readonly kind: "opaque"; readonly file: string; readonly fg: string; readonly bg: string }
    | {
          readonly kind: "alpha-background";
          readonly file: string;
          readonly fg: string;
          readonly bg: string;
          /** class 修飾の百分率 (0..100)。`null` は修飾なし (token の値が持つ alpha だけ) */
          readonly modifierPercent: number | null;
      }
    | { readonly kind: "undecidable"; readonly file: string; readonly reason: UndecidableReason };

/**
 * 静的に組を決められない理由 (正典 i16 が「例外にして素通りさせない」と定めた形)。
 *
 * ★`double-alpha` は**値域から外した** (Round 1 レビューの Critical)。
 *   alpha を値に持つ token への修飾は実効 alpha が `token の alpha × 修飾の alpha` に
 *   確定する (S10 が生成形を固定する) ので、**静的に決められる形**であり
 *   例外へ逃がすのは i16 に反する。合成対象として計算する。
 */
export type UndecidableReason =
    | "foreground-alpha"          // 前景にも不透明度修飾がある
    | "keyword-color"             // bg-transparent / bg-current 等の色キーワードと `/0` (透明)
    | "alpha-background-no-text"  // 同じ宣言に前景が無い alpha 背景
    | "opaque-and-alpha-background" // 同じ状態に塗り面の背景と alpha 背景が同居
    | "multiple-background"       // 同じ状態に不透明な背景の宣言が 2 つ以上 (勝敗を静的に決められない)
    | "multiple-foreground"       // 同じ状態に前景の宣言が 2 つ以上
    | "variant-composition"       // 異なる variant 列が同じ channel へ影響する (包含関係を解かない)
    | "element-opacity"           // 要素全体の不透明度指定 (opacity-*) が同居
    | "interpolated";             // 補間で完成した class 文字列を差し込む単位

/** 不透明のみの不完全な単位 (前景か背景の片方しか無い) の集計。 */
export interface IncompleteOpaqueCounts {
    readonly backgroundOnly: number;
    readonly foregroundOnly: number;
}

/**
 * **1 本のソース**の解析結果 (純粋入口が返す形)。
 * ★集約用の `files` / `perDirectory` は持たない (Round 3 の Warning: 任意の検体に対して
 *   どのディレクトリ分類を生成するのかが定義できず、責務が合わない)。
 */
export interface SourceClassUsageScan {
    /** ★全 class トークンの共通出力。S3 / S5 / S7 はここから導出する (2 本目の走査器を書かない) */
    readonly occurrences: readonly ClassTokenOccurrence[];
    readonly pairs: readonly ScannedPair[];
    readonly incompleteOpaque: IncompleteOpaqueCounts;
    /**
     * ★解析そのものが失敗したことの記録 (Round 5 の Critical: これが無いと
     *   「診断が 1 件でもあれば gate を落とす」を型で実装できなかった)。
     *   純粋入口は**例外を投げず**にここへ積む。集約ラッパーも例外を握らず
     *   ファイル名つきで積む (準拠実装 `file-input-scan.ts` の `parse-failed` と同じ形)。
     */
    readonly diagnostics: readonly ClassScanDiagnostic[];
}

export interface ClassScanDiagnostic {
    readonly file: string;
    readonly reason: "svelte-parse-failed" | "ts-diagnostic";
    /** 解析器が返したメッセージ (診断出力用。期待値には使わない) */
    readonly detail: string;
}

/** **実リポジトリ**の集約結果 (薄いラッパーが返す形)。 */
export interface ClassUsageScan extends SourceClassUsageScan {
    /** 走査したファイル (リポジトリ相対、ソート済み)。空なら呼び出し側が落とす */
    readonly files: readonly string[];
    /** `resources/js` の直下の子ごとの抽出件数 (どれかが丸ごと読めていない状態を捕まえる) */
    readonly perDirectory: ReadonlyMap<string, number>;
}

export function scanClassUsage(): ClassUsageScan;

/** 走査器が扱えない**既知の入口**の出現 (0 件であることを gate が固定する)。 */
export interface UnsupportedEntryPoint {
    readonly file: string;
    readonly kind: "class-directive" | "class-helper-library" | "interpolated-prefix";
}

export function unsupportedEntryPoints(): readonly UnsupportedEntryPoint[];
```

### 走査分母と走査根

**走査根は `resources/js` の 1 本**で、**全体を再帰走査**する
(Round 1 レビューの Critical: 固定 3 根 (`components` / `pages` / `lib`) は
実測で `app.ts` / `inertia.ts` / `vite-env.d.ts` / `types/` の 4 つを取り落としており、
docblock の「resources/js の走査分母」と食い違っていた。新しい直下ディレクトリからも迂回できる)。
走査根が存在しなければ **fail-fast** (`PrismDirectDispatchScanner::roots()` に倣う)。

**拡張子の全数分類** (未分類が現れたら不合格):

★照合は**最長接尾辞一致**である (Round 3 の Warning: `.d.ts` は `.ts` の接尾辞でもあり、
照合順が未定義だと `vite-env.d.ts` が走査対象に入る)。S8 のファイル種別分類と同じ規則を使う。

| 拡張子 | 扱い | 理由 |
|---|---|---|
| `.svelte` | 走査する | 画面のマークアップ |
| `.ts` | 走査する | variant 表・helper の class 文字列 |
| `.d.ts` | 走査しない | 型宣言のみ。class 文字列を持たない (**`.ts` より長いので先に一致する**) |
| `.gitkeep` | 走査しない | 空ディレクトリの目印 |

**`resources/js` 直下の子の全数分類** (新しい直下の子が現れたら不合格):

```ts
// tests/js/styles/inventory.ts
export const JS_SCAN_CHILD_CLASSIFICATION = {
    "components": { requiresOccurrences: true },
    "pages": { requiresOccurrences: true },
    "lib": { requiresOccurrences: false, reason: "テーマ名前空間の class トークンが実測 0 件" },
    "types": { requiresOccurrences: false, reason: "型定義のみで class 文字列を持たない" },
    // 直下のファイル (app.ts / inertia.ts / vite-env.d.ts) をまとめた 1 枠
    "(直下のファイル)": { requiresOccurrences: false, reason: "実測 0 件。起動と型宣言だけを持つ" },
} as const;
```

- `perDirectory` のキーは上の分類表のキーと**集合一致**する
  (分類していない子が現れても、分類したのに走査していない子があっても赤)。
- **`requiresOccurrences: true` の子だけ**が 0 でないことを gate が固定する
  (motivation の「ディレクトリごとに 1 件以上抽出できる」形)。
  **要求しない子に 0 件を強いない** — 0 件が正常なので、要求すると正常な状態を赤にする
  (Round 2 の Critical)。
- `resources/views/vendor/mail/html/themes/template.css` は**走査根の外**である。
  Laravel 同梱メールテーマの独立パレットで DS token の写像ではない
  (既に `contrast-invariant.test.ts` の docblock が同じ線引きを持つ)。

### deny する既知の入口 (実測で現状すべて 0 件)

| kind | 判定 | 現状 |
|---|---|---|
| `class-directive` | Svelte の `class:` に**識別子が直接続く**形 (`class:foo=` / `class:foo`)。`class: extraClass` (props の分割代入。コロンの後に空白) は**別物**なので当たらない | 0 件 |
| `class-helper-library` | `clsx` / `twMerge` / `tailwind-merge` / `classnames` / `cva` が区切りで分割したトークンとして現れる (import・呼び出しとも) | 0 件 |
| `interpolated-prefix` | テーマ名前空間の接頭辞の**直後**に補間が来る形 | 0 件 |

### 型適合チェック

- [x] 戻り値の型が明示されている
- [x] `null` 安全: `alpha` は `number | null` を明示し、`ScannedPair` の判別で分岐を強制する
- [x] 配列返却ではなく判別可能 union を返している (`ScannedPair`)
- [x] `UndecidableReason` の網羅を `switch` の default で `never` へ収束させる

### テスト計画

- [x] **先に赤くするテスト**: `class-usage.test.ts` に固定検体を置き、
      **純粋入口 `scanClassUsageSource(source, file)` へ直接渡して**
      「状態単位の組の作り方」を先に書く。実装前は import が解決せず**赤**
- [x] 字句の負例・正例 (Round 2 の Warning + Round 4 の Critical。
      **解析器に任せた結果がこうなること**を固定検体で確かめる):
  - コメントの中のリテラル (`// "bg-primary text-danger"`) は**拾わない**
  - エスケープした引用符 (`'it\\'s bg-primary'`) で文字列が途中で閉じない
  - 複数行のバッククォートリテラルを 1 単位として扱う
  - `${…}` を含む単位は `interpolated` の判定不能になる (通常リテラルに落とさない)
  - **補間の中に閉じ波括弧を含む文字列** (`` `${ cond ? "}" : x }` ``) を
    終端と誤認しない (**以降のソースを読み落とさない**)
  - **補間の中の object literal と入れ子 template** を終端と誤認しない
  - **未終端**のブロックコメント / 3 種のリテラル / template / 補間は**例外**になる
  - `.svelte` の parse 失敗は診断 `svelte-parse-failed` として残り、**gate が落ちる**
  - `.ts` の parse diagnostics (括弧の不整合など) は診断 `ts-diagnostic` として残る
  - 診断が出たファイルは `occurrences` / `pairs` が**空になる** (部分結果を返さない)
  - **補間内部の class 風文字列を二重に拾わない**
    (`` `${"bg-primary text-danger"}` `` から単位が 1 件も出ない)
- [x] **監視対象の判定** (Round 6 の Critical。`isWatchedCandidate()` の正負例):
  `"./Button.types"` / `"https://example.com/a"` / `"保存しました"` は**非監視** (無視される) /
  `bg-primaryあ` と `sm:bg-primaryあ` は**監視 → `unparsable-token`** /
  `text-center` は監視 → 契約表で解決 / `!bg-primary` と `sm:hover:bg-primary` は監視 → 解決
- [x] **variant の合成** (Round 5 の Warning + Round 6 の Critical。**4 形を別々に固定する**):
  1. 基底 + `hover:` (`"bg-surface hover:text-danger"`) → **解決可能** (`(danger, surface)`)
  2. 両 channel が同じ `hover:` (`"bg-surface text-text hover:bg-danger hover:text-neutral"`)
     → **解決可能**
  3. `sm:` + `sm:hover:` (`"bg-surface sm:bg-neutral sm:hover:text-danger"`) → **判定不能**
  4. `sm:` + `hover:` (`"bg-surface sm:bg-neutral hover:text-danger"`) → **判定不能**
     (同時成立を否定できない)
- [x] 不透明度修飾の端点 (Round 2 の Warning):
  `bg-primary/100` → `alphaPercent === null` (不透明) / `bg-primary/0` → `keyword-color` の判定不能 /
  `bg-primary/101` と `bg-primary/[0.35]` → `unsupported-alpha-syntax` の未解決
- [x] **拡張子の最長接尾辞一致** (Round 3 の Warning): `vite-env.d.ts` は走査対象外 /
      `app.ts` は対象 / `Badge.svelte` は対象、を固定検体で固定する
- [x] 負例 (共通規約 (c) / i18):
  - `"bg-surface text-danger hover:bg-danger hover:text-neutral"` から
    `(danger, surface)` と `(neutral, danger)` の**2 組だけ**が出る
    (`(danger, danger)` / `(neutral, surface)` が出たら赤)
  - **状態の継承の片側だけ上書き** (Round 1 レビューの Warning。上の検体は両方を上書きするので
    「継承していない実装」を検出できない):
    - `"text-text hover:bg-danger"` → `(text, danger)` が出る (前景を基底から継承する)
    - `"bg-surface hover:text-danger"` → `(danger, surface)` が出る (背景を基底から継承する)
  - 同じ状態に不透明な背景が 2 つ (`"bg-surface bg-neutral text-text"`) →
    `multiple-background` の判定不能になる (どちらが勝つかは生成 CSS の順で決まり静的に決められない)
  - 同じ状態に前景が 2 つ (`"bg-surface text-text text-danger"`) → `multiple-foreground`
  - **二重 alpha は判定不能にしない**: `"bg-primary-soft/40 text-text"` →
    `kind: "alpha-background"` / `modifierPercent === 40` の組が出る
    (実効値 0.048 = 0.12 × 0.40 を作るのは `resolveAlphaBackground()` だけである)
  - class トークンの分解: 接頭辞つき `sm:bg-primary` / 打ち消しつき `!bg-primary` /
    接尾辞つき `bg-primary/10` の**3 形**をそれぞれ正しく解決する
    (素の部分文字列一致だと 3 形が一緒に消える。共通規約 (e))
  - 非 ASCII の混入 (`bg-primaryあ`) は**候補全体**が `resolution.kind === "unresolved"` /
    `reason === "unparsable-token"` になる (**`bg-primary` へ縮退して通らない**ことを固定する)
  - `bg-(--var)` も**候補全体**が `unparsable-token` になる
  - **色でない utility への不透明度修飾**: `text-center/50` は
    `unresolved: "alpha-on-non-color"` になる (`text-center` として通さない)。
    一方 `sm:text-center` と `!text-center` は `utility === "text-center"` /
    `resolution.kind === "contract"` として**正しく解決する** (3 形を別々に固定する = 共通規約 (e))
  - deny 語彙 3 群それぞれについて、合成入力で `unsupportedEntryPoints()` が**検出する**
    (`class:foo={x}` / `clsx(...)` / 接頭辞の直後に補間) ことと、
    紛らわしい形 (`class: extraClass` / `flash-to-toast` / 補間が完成した class を差し込む形) を
    **誤検出しない**ことの両方向
  - `ramp` と整列語の取り違え: `text-body` / `text-center` を前景色として拾わない
  - **DESIGN.md のキーとの衝突**: `text-primary` は前景色 `primary`、
    `text-text` は前景色 `text` として解決する (`COLOR_TOKEN_MAP` の `text-primary` キーは
    本文色 = `--color-text` であって別物)
- [x] 正例: 実在する `atoms/Badge.types.ts` の**全 tone** / `atoms/Button.types.ts` の**全 variant** を
      (件数は散文に書かず `TONE_CLASSES` / `VARIANT_CLASSES` のキーから導出して)
      期待どおりの組へ分解する (**既知の要求組が抽出結果から実際に生成されること** = 正典 i15)
- [x] **分類分岐の点灯は固定検体で確かめる** (Round 1 レビューの Warning。
      実リポジトリに「不完全な単位が必ず存在する」ことを要求すると、コードが良くなって
      0 件になった正常状態を赤にしてしまう)。`incompleteOpaque.backgroundOnly` /
      `foregroundOnly` と、**`UndecidableReason` の全分類**は、それぞれ
      **合成入力で 1 件出る**ことを固定する。
      ★**分類数を散文に書かない** (Round 6 の Warning)。網羅は union から機械的に導出し、
      「各 reason を発火させる検体が 1 つ以上ある」ことを検査する
- [x] **診断ゼロの正本は本 gate である** (Round 6 の Critical。積むだけで誰も見ない形にしない):
      `class-usage.test.ts` が実リポジトリ走査に対して
      `scanClassUsage().diagnostics` が**空**であることを要求する。
      S3 / S5 / S7 はこの保証に依存する (各節と責務境界表の行に明記する)
- [x] 空振り検知 (実リポジトリに対して要求するのはここまで):
      `files.length > 0` / `occurrences.length > 0` / `pairs.length > 0` /
      `perDirectory` の**「要求する」2 つ** (`components` / `pages`) がそれぞれ > 0
      (共通規約 (b) の 3 点目)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 状態単位の作り方が Tailwind の実際の勝敗 (生成 CSS の順序) と一致しない場合がある。
  → `atoms/input-state.ts` のコメントが既に「Tailwind は同一プロパティの utility が並んだ場合、
  勝敗が class 属性の順ではなく生成 CSS の順で決まる」と記録している。本走査器は
  **同じ状態に同じ channel の宣言が 2 つ以上ある単位**を
  `multiple-background` / `multiple-foreground` / `opaque-and-alpha-background` の
  **判定不能**として扱い、勝敗を勝手に決めずに素通りもさせない。
- 走査単位が「同じ要素」の近似であることは誇張しない (docblock に明記)。

---

## S4 線形化しきい値を 0.04045 へ揃える (i13)

### 変更箇所

- `tests/js/architecture/contrast-invariant.test.ts` (L45-49)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 同ファイルの負のコントロール (L146-153) に errata の裏取りを 1 行足す
- **共有パス**: このファイルは `docs/template-fingerprints.json` のキーに在り、
  `adoption-debt.tsv` にも在る → **S12 で決着させる**

### 現行コード

```ts
/** sRGB チャンネルの線形化 (WCAG 2.x 相対輝度の定義) */
function linearize(channel: number): number {
    const c = channel / 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}
```

### 変更後コード

```ts
/**
 * sRGB チャンネルの線形化 (WCAG 2.x 相対輝度の定義)。
 *
 * しきい値は **0.04045** を使う。WCAG 2.0 / 2.1 本文の 0.03928 は
 * **2022-02-22 の errata で訂正済み**で、IEC 61966-2-1 (sRGB) の正しい値が 0.04045 である。
 * ★**8bit の色値では判定結果は変わらない** (境界は 0.03928*255 = 10.02 と
 *   0.04045*255 = 10.31 の間にあり、整数のチャンネル値 10 と 11 のどちらも
 *   両しきい値の同じ側に落ちる)。正しい方へ揃えるだけの変更である。
 */
export function linearizeChannel(c: number): number {
    return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

function linearize(channel: number): number {
    return linearizeChannel(channel / 255);
}
```

★**正規化済みチャンネル (0..1) を受ける純粋関数 `linearizeChannel()` を切り出す**のが本施策の要点である
(Round 1 レビューの Critical: 8bit の全値で「両しきい値の判定が一致する」ことを確かめるだけの検査は
**実装本体を 1 度も呼ばない**ので、実装が 0.03928 のままでも緑になり、i13 を固定できない)。

負のコントロールへ追加する検査:

```ts
it("負のコントロール: 線形化のしきい値が errata 後の 0.04045 である", () => {
    // 2 つのしきい値の**間**の値でだけ実装の差が出る。
    //   c = 0.04 → 0.04045 実装は線形枝 = 0.04 / 12.92 = 0.0030959752321981426
    //              0.03928 実装は pow 枝  =              0.0030954995810608932
    // ★実装本体 (linearizeChannel) を呼ぶので、0.03928 のままならこの toBe が落ちる。
    expect(linearizeChannel(0.04)).toBe(0.04 / 12.92);
    // 両しきい値の外側では当然一致する (この it が「何でも通る」形でないことの裏取り)。
    expect(linearizeChannel(0.03)).toBe(0.03 / 12.92);
    expect(linearizeChannel(0.5)).toBeCloseTo(Math.pow((0.5 + 0.055) / 1.055, 2.4), 12);
});

it("補助: errata のしきい値の差が 8bit では判定を変えない", () => {
    // 「揃えたら結果が変わった」= どちらかの実装が間違っていたことになるので、
    // 変わらないことを 8bit の全チャンネル値で固定する (i18 の既知値)。
    // ★これは**性質の検査**であって実装のしきい値は固定しない (上の it が固定する)。
    for (let channel = 0; channel <= 255; channel += 1) {
        const c = channel / 255;
        expect(c <= 0.03928, `channel=${channel}`).toBe(c <= 0.04045);
    }
});
```

### 型適合チェック

- [x] 戻り値の型が明示されている / [x] `null` 安全 (数値のみ) / [x] 配列返却なし / [x] Generics なし

### テスト計画

- [x] **先に赤くするテスト**: 上の「線形化のしきい値が errata 後の 0.04045 である」を
      **先に書く**。現行実装 (0.03928 + `linearizeChannel` の切り出しなし) では
      **コンパイルエラー → 切り出し後は `toBe` の不一致**で赤になる。
      赤を確認してからしきい値を直す (テストファースト。思考原則 5)
- [x] 既存の 12 ペア + 負のコントロール 4 件が**同じ値で緑**であること (差が出ないことの実証)

### リスク

- 実質的な後退リスクは無い (8bit では判定不変)。値だけを直す変更なので、
  **仕組みが機能していない段階で値を弄るな**の原則には触れない (仕組みは既にある)。

---

## S10 不透明度修飾の生成形を契約として固定する (i6 の補強)

### 変更箇所

- `tests/js/styles/tokens.test.ts` (`UTILITY_CANDIDATES` に `alpha` 区分を追加 / describe を 1 つ追加)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし / テストファイル: 同ファイルのみ

### 変更後コード

```ts
const UTILITY_CANDIDATES = {
    color: /* 既存 */,
    radius: /* 既存 */,
    ramp: /* 既存 */,
    hover: /* 既存 */,
    /**
     * 不透明度修飾。**S5 (合成の検査) が置く前提「修飾は同じ色の alpha になる」の裏取り**。
     * 代表として不透明 token の /10、alpha を値に持つ派生 token の /40 (= 二重) を取る。
     */
    alpha: ["bg-primary/10", "bg-primary-soft/40"],
} as const;
```

```ts
/* ===== H. 不透明度修飾の生成形 (密閉の層) ===== */

describe("tokens/H: 不透明度修飾は同じ色の alpha として生成される", () => {
    /**
     * ★S5 の合成モデルはこの生成形を前提にしている。前提が版で変わったら
     *   ここが赤くなって「見直す契機」になる (正典 i16 が要求する形)。
     *
     * 実測 (Tailwind 4.3):
     *   .bg-primary\/10 {
     *       background-color: color-mix(in srgb, #1d4ed8 10%, transparent);
     *       @supports (color: color-mix(in lab, red, red)) {
     *           background-color: color-mix(in oklab, var(--color-primary) 10%, transparent);
     *       }
     *   }
     * fallback 側は**正本の hex をリテラルで埋め込む**ので、値の突き合わせも兼ねる。
     */
    it("不透明 token の /10 は正本の hex を 10% で透明と混ぜた形になる", () => {
        const decls = soleRule(sealed, ".bg-primary\\/10");
        // ★`Map#get` の undefined が文字列補間で "undefined" になり、
        //   「意図した解析失敗」ではなく「文字列が一致しないだけ」の赤に化けるのを防ぐ
        //   (Round 1 レビューの Warning)。不在は例外にする。
        const expected = requiredMapValue(designColors(), "primary", "DESIGN.md colors.primary");
        expect(requiredMapValue(decls, "background-color", ".bg-primary/10")).toBe(
            `color-mix(in srgb, ${expected} 10%, transparent)`,
        );
    });

    it("@supports の中は var() 参照の oklab 混色になる", () => {
        // 条件つき at-rule の中は soleRule が拾わないので、条件つきの側を明示的に見る。
        // 条件の綴りは allowlist と突き合わせる (D の ALLOWED_HOVER_CONDITIONS と同じ方針)。
        …
    });

    it("alpha を値に持つ派生 token への修飾は実効 alpha が積になる (S5 が合成対象にする根拠)", () => {
        const decls = soleRule(sealed, ".bg-primary-soft\\/40");
        const soft = requiredMapValue(cssColorTokens(), "primary-soft", "--color-primary-soft");
        expect(requiredMapValue(decls, "background-color", ".bg-primary-soft/40")).toBe(
            `color-mix(in srgb, ${soft} 40%, transparent)`,
        );
        // 透明との混色は乗算済み alpha なので、実効 alpha は token の alpha × 修飾の alpha に確定する。
        const parsed = parseCssColor(soft);
        expect(parsed.kind).toBe("alpha");
        if (parsed.kind !== "alpha") return;
        expect(parsed.alpha * 0.4).toBeCloseTo(0.048, 6);
    });

    /**
     * ★派生 token の**導出関係**を機械で固定する (Round 1 レビューの Critical)。
     *   `COMPILED_VALUE_EXEMPT_TOKENS` が免除しているのは「DESIGN.md に期待値が無い」
     *   ことの表明にとどまり、**別の rgba へ静かに差し替わる**ことまで許してはいない。
     *   これが無いと、S6 で primary を直したのに primary-soft を直し忘れた状態が
     *   (生成 CSS の出現とコントラストが偶然通れば) 検出できない。
     */
    it("--color-primary-soft は正本の primary の RGB を alpha 0.12 にしたものである", () => {
        const soft = parseCssColor(
            requiredMapValue(cssColorTokens(), "primary-soft", "--color-primary-soft"),
        );
        const primary = parseCssColor(
            requiredMapValue(designColors(), "primary", "DESIGN.md colors.primary"),
        );
        expect(soft.kind).toBe("alpha");
        expect(primary.kind).toBe("opaque");
        if (soft.kind !== "alpha" || primary.kind !== "opaque") return;
        expect(soft.rgb).toEqual(primary.rgb);
        expect(soft.alpha).toBe(0.12);
    });
});
```

`requiredMapValue()` は共有ヘルパとして `tests/js/styles/theme-map.ts` に置く
(`Map#get` の `undefined` を文字列補間で `"undefined"` に化けさせないため。
不在は**例外**にする = i20)。

### 型適合チェック

- [x] 戻り値の型が明示されている / [x] `null` 安全 (`soleRule` は 0 件も重複も落とす) /
      [x] 配列返却なし / [x] Generics なし

### テスト計画

- [x] **先に赤くするテスト**: 上の 4 it。`UTILITY_CANDIDATES.alpha` を足す前は
      `.bg-primary\/10` の規則が生成されないので `soleRule` が「1 件でない」で**赤**になる。
      派生の導出関係の it は、`--color-primary-soft` の RGB を 1 文字変えた検体で
      赤になることを確認してから実値へ戻す
- [x] 空振り防止: 既存の `it.each(Object.entries(UTILITY_CANDIDATES))` が
      新区分 `alpha` も 0 件でないことを自動で見る (区分を足すだけで検査が増える形)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- Tailwind の版が上がって生成形が変わると赤くなる。**それは緩める理由ではなく、
  合成モデルを見直す契機である** (同ファイル冒頭のリスク欄と同じ方針を明記する)。

---

## S5 半透明背景 × 不透明文字の合成検査を新設する (i16)

### 変更箇所

- `tests/js/architecture/contrast-invariant.test.ts` (合成関数と describe を追加 / docblock の
  「検査しないもの」を書き換え)
- `tests/js/styles/inventory.ts` (`ALPHA_PAIR_USAGE_LEDGER` / `ALPHA_CONTRAST_PAIRS` /
  `UNDECIDABLE_PAIR_LEDGER` を新設、
  `PENDING_CONTRAST_PAIRS` を書き換え)

### 波及変更

- TypeScript 型定義: `inventory.ts` に台帳の型を新設 (`UndecidableReason` を `class-usage.ts` から import)
- API Resource/DTO: なし
- テストファイル: `contrast-invariant.test.ts` のみ
- **共有パス**: `contrast-invariant.test.ts` → S12

### 現行コード

```ts
// tests/js/styles/inventory.ts (L97-101)
export const PENDING_CONTRAST_PAIRS = [
    "WCAG 1.4.11 非テキストコントラスト (3:1): border / border-strong / focus ring",
    "alpha 合成ペア: Badge の bg-<tone>/10 + text-<tone>、bg-primary-soft、ring-primary/35、" +
        "bg-text/70 + text-surface (合成後の実効色が親背景に依存しトークン単体では定まらない)",
] as const;
```

### 変更後コード

```ts
// tests/js/styles/inventory.ts
/**
 * 半透明の背景 × 不透明な文字の組の台帳 (正典 i16)。
 *
 * ★**走査で見つかった半透明の組は全件がここに載る**ことを contrast-invariant が
 *   集合一致で固定する (件数だけの pin にしない = 新しい使用を件数更新で通せない)。
 * ★**下地は宣言しない**。実在する不透明な下地 = 役割分類の「面」(`surface` 役割を持つ token =
 *   `SURFACE_ROLE_TOKENS`) の**すべて**の上で 4.5:1 を要求するので、部品がどちらに置かれても成立する。
 * ★**「面」と「テキストを載せる塗り」は別物である** (思考原則 4)。
 *   `border` は Button の hover 塗りとしてテキストを載せるので
 *   `declared-text-background` の役割を持つが、**容器の背景として宣言された用途は無い**ので
 *   「面」ではなく、半透明の合成の**下地には数えない**。
 *   下地に数えると、実際には起きない重ね方 (ソフト背景のバッジを Button の hover 塗りの上へ置く)
 *   を根拠にテーマ値の是正を要求することになる。この線引きは**宣言であって導出ではない**ことを
 *   gate 本体に書く (静的走査は親要素を辿れない = 正典 i22 (2))。
 * ★行番号は持たない (正典 s14)。ファイル単位までである。
 * ★パスは**リポジトリ相対** (`resources/js/…`) で統一する。走査器の
 *   `ClassTokenOccurrence.file` / `perDirectory` のキーも同じ空間である
 *   (Round 2 の Warning: 型はリポジトリ相対、台帳例は走査根相対で食い違っていた)。
 * ★`fg` / `bg` の型は `CssColorSuffix` (下記の literal union) である。
 *   `readonly string[]` では取り違えが型で落ちないので、
 *   `COLOR_TOKEN_MAP` と `DERIVED_COLOR_TOKENS` から直接導出する。
 */
/**
 * ★**使用箇所の全数台帳**である (正典 i16「走査で見つかった半透明の組は
 *   全件が台帳に載ることを件数まで含めて要求する」)。走査結果と**集合 + 件数**で完全一致させる。
 * ★キーは **tokens.css の `--color-<suffix>` 空間** である (下の「2 つのキー空間」を参照)。
 * ★`modifierPercent` は **class 修飾の百分率だけ**である (実効値ではない)。
 *   `bg-primary-soft` は token の値が alpha 0.12 を持つので `null`、
 *   `bg-primary-soft/40` は `40` になる。実効値を作るのは `resolveAlphaBackground()` だけ。
 * ★行番号は持たない (正典 s14)。ファイル単位までである。
 */
export const ALPHA_PAIR_USAGE_LEDGER = [
    { file: "resources/js/components/atoms/Badge.types.ts", fg: "danger", bg: "danger",
      modifierPercent: 10, count: 1 },
    { file: "resources/js/components/atoms/Badge.types.ts", fg: "primary", bg: "primary-soft",
      modifierPercent: null, count: 1 },
    { file: "resources/js/components/atoms/Badge.types.ts", fg: "success", bg: "success",
      modifierPercent: 10, count: 1 },
    { file: "resources/js/components/atoms/Badge.types.ts", fg: "tertiary", bg: "tertiary",
      modifierPercent: 10, count: 1 },
    { file: "resources/js/components/atoms/Badge.types.ts", fg: "warning", bg: "warning",
      modifierPercent: 10, count: 1 },
    { file: "resources/js/components/molecules/SubtitleOverlay.svelte", fg: "surface", bg: "text",
      modifierPercent: 70, count: 2 },
    { file: "resources/js/components/molecules/PendingInvitationsNotice.svelte",
      fg: "text", bg: "primary-soft", modifierPercent: 40, count: 1 },
    /* … 実装時に走査結果で確定させる (実測で 20 行前後) … */
] as const satisfies readonly AlphaPairUsage[];

/**
 * 上の台帳を `(fg, bg, modifierPercent)` へ**射影した一意な意味ペア**。
 * AA の `it.each` はこちらを回す (同じ意味ペアを 20 回検査しても情報は増えない)。
 * ★**「射影が一致する」という it は置かない** — 導出しているので恒真に近く、
 *   共通規約 (d)「集めた走査結果を判定に使わない形を作らない」の形骸化に当たる。
 *   代わりに**導出関数 `distinctPairs()` の仕様**を固定検体で固定する。
 */
export const ALPHA_CONTRAST_PAIRS: readonly AlphaPair[] = distinctPairs(ALPHA_PAIR_USAGE_LEDGER);

/**
 * 静的に組を決められなかった単位の台帳 (正典 i16「例外にして静かに素通りさせない」)。
 *
 * ★識別子は **(ファイル, 理由, 件数) の完全一致**である (Round 1 レビューの Critical:
 *   (ファイル, 理由) だけだと、同じファイルに同じ理由の未解析箇所が**増えても集合が変わらず**
 *   追加を検出できない)。**行番号は持たない** (正典 s14: 無関係な 1 行の追加でずれ、
 *   期待値の機械的な更新が常態化して統制が形骸化する)。
 * ★不透明のみの不完全な単位 (前景か背景の片方しか無い) は**ここに載せない** —
 *   `bg-surface` 単独が 39 単位・`bg-neutral` 単独が 20 単位あり、実体集合で pin すると
 *   期待値の機械的な更新が常態化して統制が形骸化する (正典 s14 と同じ理由)。
 *   そちらは「分類の全数性」を固定検体で受け、組そのものは i14 の役割直積が覆う。
 * ★`double-alpha` は**もう理由の値域に無い**。実効 alpha が積で確定するので
 *   使用箇所の台帳へ載せて計算する (i16 は「静的に決められない形」だけを例外にする)。
 */
export const UNDECIDABLE_PAIR_LEDGER = [
    { file: "resources/js/components/atoms/Button.types.ts", reason: "keyword-color", count: 2,
      note: "ghost / danger-ghost の bg-transparent。背景は親から来る" },
    { file: "resources/js/components/atoms/Button.types.ts", reason: "element-opacity", count: 2,
      note: "success / danger の hover:opacity-90 (要素全体の不透明度)" },
    { file: "resources/js/components/atoms/input-state.ts", reason: "interpolated", count: 1,
      note: "完成した class 文字列を補間で差し込む (border の状態)" },
    { file: "resources/js/components/atoms/input-state.ts", reason: "foreground-alpha", count: 1,
      note: "placeholder:text-text-secondary/70 (前景に不透明度修飾)" },
    { file: "resources/js/components/features/notifications/NotificationListItem.svelte",
      reason: "alpha-background-no-text", count: 1,
      note: "unread 時の bg-primary-soft/40 だけを持つリテラル (前景は別のリテラル)" },
    /* … alpha-background-no-text の残り 12 ファイル (実装時に走査結果で確定させる) … */
] as const satisfies readonly UndecidableEntry[];

/** 未検査であることを明示する pending 集合。**i16 の完了後も空にならない**。 */
export const PENDING_CONTRAST_PAIRS = [
    "WCAG 1.4.11 非テキストコントラスト (3:1): border / border-strong / focus ring (正典 i17 により本 gate の対象外)",
    // ★列挙は `UndecidableReason` の union から**生成する** (散文で数を書かない。
    //   分類を足したのに pending の説明が古いまま、という食い違いを作らない)。
    `UNDECIDABLE_PAIR_LEDGER に載せた分類: ${undecidableReasonLabels().join(" / ")}。` +
        "値域の正本は UndecidableReason で、分類の全数性は contrast-invariant の it が" +
        "never への収束と「各 reason を発火させる検体が 1 つ以上ある」ことで固定する",
] as const;
```

```ts
// tests/js/architecture/contrast-invariant.test.ts (追加)

/**
 * 半透明の背景を不透明な下地の上へ合成する。
 *
 * 【本 gate が採用する**近似モデル** (版や環境で変わりうるので gate 本体に書く)】
 *   1. 不透明度修飾は `color-mix(…, transparent)` へ展開され、**透明との混色は
 *      同じ色の alpha になる** (透明側の乗算済み色が寄与しないため色相・明度は変わらない)。
 *      alpha を値に持つ token にさらに修飾が付く形は**実効 alpha が積**になる。
 *      生成形そのものは tokens.test.ts の「H. 不透明度修飾の生成形」が固定する
 *   2. 合成は**チャンネルごとの `a*FG + (1-a)*BG`** で、ガンマ符号化された sRGB 値を
 *      直接ブレンドする (web の既定)
 *   3. 比の計算に使うのは **8bit へ丸めた値**である。丸めまで再現しないと
 *      docs/design-system.md の記録値と 0.01 ずれる
 *   ★これは「ブラウザが必ずこう描く」という主張ではない (Round 1 レビューの Warning)。
 *     **本 gate が判定に使う近似**であり、近似が判定を変えていないことは
 *     「丸めない合成との比が 4.5 の境界を跨がない」検査が別に固定する。
 *   ★広い色域 (Display P3 等) の実描画との厳密一致は**測っていない** (正典の未決論点 q3)。
 */

/**
 * 合成の入力は**完全に正規化してから**渡す (Round 2 の Critical:
 * `ParsedColor` 自身の alpha と台帳の実効 alpha を関数が二重適用しうる形だった)。
 *
 * 正規化の規則は 1 本である —
 *   `effectiveAlpha = (token の値が持つ alpha ?? 1) × ((modifierPercent ?? 100) / 100)`
 * 3 形:
 *   不透明 token の `/10`                → 1 × 0.10 = 0.10
 *   値に alpha を持つ token (修飾なし)   → 0.12 × 1 = 0.12
 *   値に alpha を持つ token の `/40`     → 0.12 × 0.40 = 0.048
 */
interface ResolvedAlphaBackground {
    readonly rgb: Rgb;
    readonly effectiveAlpha: number;
}

/**
 * ★**token 固有 alpha と class 修飾を合成する唯一の場所**である。
 *   引数は**百分率** (`modifierPercent`)、戻り値は **0..1 の実効値** (`effectiveAlpha`) で、
 *   名前が単位を表す (Round 3 の Critical)。
 */
function resolveAlphaBackground(
    suffix: CssColorSuffix,
    modifierPercent: number | null,
): ResolvedAlphaBackground;

/** ★`ParsedColor` を直接受けない (alpha の出所を 1 つにする)。 */
function compositeOverOpaque(background: ResolvedAlphaBackground, base: Rgb): Rgb { … }

describe("architecture/contrast-invariant: 半透明背景 × 不透明文字 (面のすべての上で 4.5:1)", () => {
    it("走査で見つかった半透明の組と使用箇所台帳が (ファイル, 組, 修飾, 件数) で完全一致する", () => { … });

    it("判定不能の単位と台帳が (ファイル, 理由, 件数) の完全一致で揃う", () => { … });
    it("台帳の理由が UndecidableReason の値域に収まり、分類が全数である (never で収束)", () => { … });
    it("台帳の行が一意で、件数と修飾率が値域に収まる", () => {
        // ★集合 + 件数の比較は、同じキーを複数行へ分割したり count: 0 を登録したりすると
        //   正規化のしかた次第で意図しない一致が起きる (Round 4 の Warning)。
        //   キーの一意性と値域を独立した不変条件として固定する。
        expectUnique(ALPHA_PAIR_USAGE_LEDGER, (r) => [r.file, r.fg, r.bg, r.modifierPercent]);
        expectUnique(UNDECIDABLE_PAIR_LEDGER, (r) => [r.file, r.reason]);
        for (const r of [...ALPHA_PAIR_USAGE_LEDGER, ...UNDECIDABLE_PAIR_LEDGER]) {
            expect(Number.isInteger(r.count) && r.count > 0, `${r.file}: count`).toBe(true);
        }
        for (const r of ALPHA_PAIR_USAGE_LEDGER) {
            const m = r.modifierPercent;
            expect(m === null || (Number.isInteger(m) && m >= 0 && m <= 100)).toBe(true);
        }
    });
    it("distinctPairs の仕様 (重複除去・並び順・キー生成) を固定検体で固定する", () => {
        // ★「射影と ALPHA_CONTRAST_PAIRS が集合一致する」は導出しているので恒真に近い。
        //   共通規約 (d) の形骸化に当たるため置かず、導出関数そのものを固定する。
        … });
    it.each(ALPHA_CONTRAST_PAIRS)("%o が面のすべての上で 4.5:1 以上", ({ fg, bg, modifierPercent }) => {
        for (const base of SURFACE_ROLE_TOKENS) { … }
    });
    it("負のコントロール: 是正前の値では 5 組が AA を割る", () => {
        // 家系で実在した違反値を固定する (正典 i18 (d))。
        // primary #2563EB の 12% を neutral #F4F4F5 の上へ合成 → 4.01 で 4.5 を割る。
        expect(ratioOfComposite("#2563eb", "#2563eb", 0.12, "#f4f4f5")).toBeLessThan(4.5);
        // 是正後の値では通る。
        expect(ratioOfComposite("#1d4ed8", "#1d4ed8", 0.12, "#f4f4f5")).toBeGreaterThanOrEqual(4.5);
    });
    it("負のコントロール: 8bit の丸めを省くと記録値とずれる", () => { … });
    it("近似の裏取り: 丸めない合成との比が 4.5 の境界を跨ぐ組が無い", () => {
        // 8bit へ丸める近似が**判定そのものを変えていない**ことを固定する。
        // 跨ぐ組が現れたら、その組は近似の当否に判定が依存しているので、
        // 近似モデルの側を見直す契機になる (緩める理由にはしない)。
        for (const pair of ALPHA_CONTRAST_PAIRS) {
            for (const base of SURFACE_ROLE_TOKENS) {
                const rounded = ratioRounded(pair, base);
                const exact = ratioUnrounded(pair, base);
                expect(rounded >= 4.5, `${pair.fg} on ${pair.bg} over ${base}`).toBe(exact >= 4.5);
            }
        }
    });
});
```

### 2 つのキー空間 (取り違えの防止)

`inventory.ts` は**2 つのキー空間**を扱う。取り違えると別のトークンを検査してしまうので、
どちらの空間かを宣言ごとに docblock へ書き、境界は `COLOR_TOKEN_MAP` の 1 本だけにする。

**suffix 空間の literal union は導出する** (Round 2 の Warning: 現行の
`CSS_COLOR_SUFFIXES: readonly string[]` からは union を作れず、
「キーの取り違えが型で落ちる」という主張が成立していなかった):

```ts
type CanonicalColorSuffix = (typeof COLOR_TOKEN_MAP)[keyof typeof COLOR_TOKEN_MAP];
type DerivedColorSuffix = (typeof DERIVED_COLOR_TOKENS)[number];
export type CssColorSuffix = CanonicalColorSuffix | DerivedColorSuffix;

/**
 * ★**台帳は実効値を持たない** (Round 3 の Critical: 実効値を持つと
 *   `resolveAlphaBackground()` へ渡したときに token 固有 alpha が二重に掛かる読み方ができた)。
 *   持つのは **class 修飾の百分率だけ**で、token 固有 alpha と合成して実効値を作るのは
 *   `resolveAlphaBackground()` **1 か所だけ**である。
 */
export interface AlphaPair {
    readonly fg: CssColorSuffix;
    readonly bg: CssColorSuffix;
    /** class 修飾の百分率 (0..100)。`bg-primary-soft` のような修飾なしは `null` */
    readonly modifierPercent: number | null;
}

/** 使用箇所の全数台帳の 1 行 (正典 i16 の「全件が台帳に載ることを件数まで」)。 */
export interface AlphaPairUsage extends AlphaPair {
    /** リポジトリ相対パス。**行番号は持たない** (正典 s14) */
    readonly file: string;
    /** そのファイルでの出現数 (完全一致で固定する) */
    readonly count: number;
}
```

| 空間 | 使う宣言 | 例 |
|---|---|---|
| **DESIGN.md の色キー** (13 件) | 役割分類 (`COLOR_TOKEN_ROLES` と、そこから導出する `SURFACE_ROLE_TOKENS` / `TEXT_ON_SURFACE_TOKENS` / `FILL_TOKENS` / `FILL_LABEL_TOKENS` / `NON_TEXT_BOUNDARY_REASONS` / `DECLARED_CONTRAST_PAIRS`) | `text-primary` = **本文色** |
| **tokens.css の `--color-<suffix>`** (14 件) | 半透明の台帳 (`ALPHA_PAIR_USAGE_LEDGER` / `ALPHA_CONTRAST_PAIRS`)、走査器の出力、生成 CSS 検査 | `text` = 本文色 / `text-primary` は**存在しない** |

- 派生トークン `primary-soft` は**DESIGN.md に無い**ので、半透明の台帳は
  suffix 空間で書かなければ表現できない (これが空間を分ける実質的な理由である)。
- **走査器 (`class-usage.ts`) は suffix 空間だけを返す**。役割の母集団と突き合わせるときに
  gate が `COLOR_TOKEN_MAP` の逆写像で DESIGN キー空間へ写す。
- 逆写像が一意であること (`COLOR_TOKEN_MAP` の値に重複が無いこと) は
  **S1 で `canonical-source-parity.test.ts` に it を 1 本足して固定する**
  (重複があると逆写像が後勝ちになり、別のトークンの値で検査してしまう)。

### 型適合チェック

- [x] 戻り値の型が明示されている
- [x] `null` 安全: `hex()` は不在で例外 (既存の形を踏襲)
- [x] 配列返却ではなく `as const satisfies` の台帳 (キーの取り違えが型で落ちる)
- [x] `UndecidableReason` の網羅を `never` へ収束させる

### テスト計画

- [x] **先に赤くするテスト**: `it.each(ALPHA_CONTRAST_PAIRS)` の AA 検査。
      **S6 (値の是正) の前なので 5 組が実際に赤くなる** — これが本設計の
      「実測が設計の見込みを覆した」記録そのものである
- [x] 集合一致の 2 it も、台帳を空で置いた状態で**先に赤**を確認する
- [x] 負のコントロール: 是正前の値で落ちること / 是正後の値で通ること / 丸めを省くとずれること
- [x] **実効 alpha の正規化を固定検体で 3 形とも固定する** (Round 2 の Critical):
      `resolveAlphaBackground("primary", 10).effectiveAlpha === 0.1` /
      `resolveAlphaBackground("primary-soft", null).effectiveAlpha === 0.12` /
      `resolveAlphaBackground("primary-soft", 40).effectiveAlpha` が 0.048 (0.0144 ではない)。
      ★台帳が持つのは `modifierPercent` だけなので、**実効値を台帳から渡す経路が型で存在しない**
      (Round 3 の Critical への構造的な対処)
- [x] 既存テストの削除・上書きをしない:
      「未検査宣言 (PENDING_CONTRAST_PAIRS) が空でない」は**据え置く**
      (i17 の 1 行と判定不能の 1 行が残るので空にならない)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 台帳が 20 行前後になり、初見では冗長に見える。
  → 「不透明のみの不完全な単位は載せない」線引きを docblock に書き、
  台帳が肥大しないことを構造で担保する。
- 走査結果と台帳の集合一致は、**新しいソフト背景を足すたびに台帳の更新を要求する**。
  これは意図した摩擦である (正典 i16 の「全件が台帳に載る」)。

---

## S6 トークン値を是正する (i16 の帰結)

### 変更箇所

- `DESIGN.md` frontmatter L6-9 / L16-17 (色値 6 件)
- `DESIGN.md` L71-72 (§Overview の色記述) / L79 / L82 / L100 / L102 (§Colors・§状態色の本文)
- `DESIGN.md` L107-110 (**§状態色の規約文の改定**)
- `DESIGN.md` L112-114 付近 (**ソフト背景の置き場の規約行を追加**)
- `resources/css/tokens.css` L13-17 / L28-29 (色値 6 件 + `--color-primary-soft`)

### 波及変更

- TypeScript 型定義: なし (値だけの変更)
- API Resource/DTO: なし
- テストファイル: `canonical-source-parity` の値一致 / `tokens` の値検査 /
  `contrast-invariant` の不透明ペアと半透明ペアが**自動で追随する**
  (どれも DESIGN.md から導出しているので期待値の手書きは 1 か所も無い)
- `docs/design-system.md`: 値は書かれていないので更新不要 (grep で確認済み)。
  ただし §テーマの差し替え方の手順に「合成の検査も通ること」を 1 行足す (S11 に含める)
- **メールテンプレート** `resources/views/vendor/mail/html/themes/template.css` は
  独立パレットなので**追随させない** (DS token の写像ではない。既存の線引きどおり)

### 現行コード / 変更後コード

| 位置 | 現行 | 変更後 |
|---|---|---|
| `DESIGN.md:6` / `tokens.css:13` | `#2563EB` | `#1D4ED8` (blue-700) |
| `DESIGN.md:7` / `tokens.css:14` | `#1D4ED8` | `#1E40AF` (blue-800) |
| `DESIGN.md:8` / `tokens.css:16` | `#0F766E` | `#115E59` (teal-800) |
| `DESIGN.md:9` / `tokens.css:17` | `#115E59` | `#134E4A` (teal-900) |
| `DESIGN.md:16` / `tokens.css:28` | `#15803D` | `#166534` (green-800) |
| `DESIGN.md:17` / `tokens.css:29` | `#B45309` | `#92400E` (amber-800) |
| `tokens.css:15` | `rgba(37, 99, 235, 0.12)` | `rgba(29, 78, 216, 0.12)` |
| `DESIGN.md:18` / `tokens.css:30` | `#B91C1C` | **据え置き** (soft でも 4.98 で足りる) |

§状態色の規約文 (現行 L107-110):

```markdown
状態色・アクセントは Tailwind の **-700 段**で揃える(`tertiary` teal-700 / `success` green-700 /
`warning` amber-700 / `danger` red-700)。`neutral`(#F4F4F5)や `surface`(#FFFFFF)の上で
**本文コントラスト 4.5:1** を確保するための下限であり、これより明るい段は使わない
(`tests/js/architecture/contrast-invariant.test.ts` が機械検証する)。
```

変更後:

```markdown
状態色・アクセントの段は**段の名前ではなくコントラストの実測で決める**。満たすべき条件は 2 つで、
**面として分類した token の上で本文コントラスト 4.5:1** と、
**同じ色のソフト背景(不透明度 10〜12%)の上でも 4.5:1** である。後者が効くため、
実際に選べるのは概ね **-800 段**になる(既定テーマは `tertiary` teal-800 / `success` green-800 /
`warning` amber-800 / `danger` red-700 で、`danger` だけは -700 でも両条件を満たす)。
**段を機械的に揃えるのではなく、`tests/js/architecture/contrast-invariant.test.ts` の
実測で決めること**(不透明ペアと半透明ペアの両方を機械検証する)。

ソフト背景は状態色の opacity 修飾で表現する(`bg-success/10`, `bg-danger/10`,
`bg-primary-soft` 等)。**ソフト背景の部品は面として分類した token の上にのみ置く**
(既定テーマでは `neutral` / `surface`)。塗り面(`bg-primary` 等)の上へ重ねると
合成後の実効色が前景と同色になり、どの値を選んでも 4.5:1 を満たせない
(静的走査は親要素を辿れないため、この規約は機械では部分的にしか保証されない —
保証範囲は contrast gate の docblock が持つ)。
```

### 型適合チェック

- [x] 該当なし (値のみ。TypeScript の変更なし)

### テスト計画

- [x] **順序が本質**: S5 で `it.each(ALPHA_CONTRAST_PAIRS)` の 5 組が**赤**であることを
      確認した後に本施策で値を変える (テストファースト。思考原則 5)
- [x] 是正後に緑になる範囲を実測で確認済み ([contrast-measurements.md](./contrast-measurements.md))
- [x] `canonical-source-parity` の値一致 / `tokens/A` の値検査が
      **DESIGN.md と tokens.css の両方を直さないと赤**であること (片側だけの変更を落とす既存機構)
- [x] **派生 token の追随は機械で保証する**: S10 が足す
      「`--color-primary-soft` は正本の primary の RGB を alpha 0.12 にしたもの」の it が、
      `primary` を直して `primary-soft` を直し忘れた状態を**赤**にする
      (Round 1 レビューの Warning。値免除の穴を塞ぐ)
- [x] 逆引き表 ([token-change-impact.md](./token-change-impact.md)) の 131 行で、
      非テキスト用途 (`border-*` / `ring-*` / `decoration-*` / `accent-*`) と
      テキストを載せない塗り面 (Toggle トラック / アイコン帯) を目視レビューする
- [x] **目視確認する画面** — ブランド色を動かすので、逆引き表の机上確認だけで終わらせない:
  1. 撮影画面のガイド帯・字幕帯 (`features/capture/ShootingGuideOverlay` /
     `molecules/SubtitleOverlay`。`bg-text/70` + `text-surface`)
  2. 通知一覧の未読行 (`features/notifications/NotificationListItem`。
     `bg-primary-soft/40` と `bg-primary-soft` + `text-primary`)
  3. Badge の**全 tone** を並べて出す画面 (`pages/Welcome.svelte` の状態表示)。
     確認対象の tone は**散文に数を書かず `TONE_CLASSES` のキーから導出する**
     (Round 2 の Warning: 「5 tone」はソフト背景を持つ tone の数、
     「6 tone」は `BadgeTone` の全数で、散文が 2 つの数を混ぜていた)
  4. サイドバーの選択中 (`templates/AppLayout` / `templates/_helpers/SidebarNavItems`。
     `bg-primary` + `text-surface`)
  5. 料金ページの強調カード (`pages/Guest/Pricing.svelte`。`border-primary/30` + `bg-primary-soft`)
  6. **主要 Button の disabled 状態** (`atoms/Button.svelte` の primary / danger。
     `opacity-40` が変更後の塗りへ掛かる)

### リスク

- **ブランド印象が変わる** (primary が blue-600 → blue-700)。
  → i1 によりテーマ値はプロジェクト裁量であり、正典が値を定めているわけではない。
  変更理由は「i16 を満たすための帰結」であり、規約文の改定として DESIGN.md に記録する。
  家系の先行事例 (motivation:T194) は同じ方向・同じ段へ動いている。
- **hover の視認性**: `primary` と `primary-hover` の差が blue-700 → blue-800 になり、
  明度差は現行 (blue-600 → blue-700) と同程度に保たれる (逆引き表で確認)。
- **disabled の見え方は是正対象 token に依存する** (Round 2 の Warning で訂正)。
  `opacity-40` は要素全体に掛かるので、**変更後の `bg-primary` へ**適用される。
  SC 1.4.3 は無効化された UI 部品を適用除外にしているので**機械検査の対象ではない**が、
  ブランド変更による視覚的後退が無いことは別の問題なので、
  **主要 Button の disabled 状態を目視確認対象へ加える** (下の目視確認 6 面目)。

---

## S3 参照の閉包 gate を新設する (i9)

### 変更箇所

- 新規: `tests/js/styles/token-reference-closure.test.ts`
- `tests/js/styles/inventory.ts` (`NON_TOKEN_WORD_CONTRACT` を新設)
- `resources/js/components/templates/AppLayout.svelte` (L299 / L427: `text-white` → `text-surface`)
- `resources/js/components/templates/_helpers/SidebarNavItems.svelte` (L38: 同上)

### 波及変更

- TypeScript 型定義: 契約表の型を新設
- API Resource/DTO: なし
- テストファイル: `contrast-invariant.test.ts` の逆向き被覆に `(surface, primary)` が現れる
  (S7 で `surface` を `FILL_LABEL_TOKENS` へ足すことで直積の内側に入る)
- **見た目の変化は無い**: `--color-surface` は `#FFFFFF` で `text-white` と同色

### 変更後コード

```ts
// tests/js/styles/inventory.ts
/**
 * **token を指さない語**の契約表 (正典 i9)。
 *
 * ★これは許可一覧ではなく**検査対象の定義**である。テーマの名前空間の接頭辞を持つ語のうち、
 *   写像の宣言集合へ解決しないものは**全数がここに登録されていなければ不合格**になる。
 * ★Tailwind 既定テーマの色語 (`white` / `black` / raw palette) は**登録しない** —
 *   写像の外の token 空間を参照する形なので落とすのが正しい
 *   (実在した `text-white` 3 箇所は本施策で `text-surface` へ直す)。
 * ★**チャネルを型で分ける** (Round 1 レビューの Warning)。class の語と `var()` 参照を
 *   同じ無型の表へ入れると、**別のチャネルでの出現によって登録が生きているように見える**
 *   (`--app-sidebar-w` が class 語として出現しなくなっても、`var()` 側の出現で
 *   冗長判定をすり抜ける)。出現の突き合わせと冗長判定は**チャネル別**に行う。
 * ★登録するのは**正規化後の有効な完全 token** である。`text-center/50` のような
 *   「色でない utility に不透明度修飾が付いた形」は走査器が
 *   `unresolved: "alpha-on-non-color"` にするので、**契約表に登録しても救われない**。
 */
export type NonTokenWord =
    | { readonly kind: "class-word"; readonly word: string; readonly reason: string }
    | { readonly kind: "css-variable"; readonly name: string; readonly reason: string };

export const NON_TOKEN_WORD_CONTRACT = [
    { kind: "class-word", word: "bg-transparent", reason: "CSS の全域キーワード。色 token を指さない" },
    { kind: "class-word", word: "border-transparent",
      reason: "同上。全 variant で外形高さを揃えるための透明枠 (DESIGN.md §Components)" },
    { kind: "class-word", word: "border-2", reason: "境界の太さ。色ではない" },
    { kind: "class-word", word: "border-b", reason: "境界の辺の指定。色ではない" },
    { kind: "class-word", word: "border-b-0", reason: "同上 (打ち消し)" },
    { kind: "class-word", word: "border-l-2", reason: "同上" },
    { kind: "class-word", word: "border-r", reason: "同上" },
    { kind: "class-word", word: "border-t", reason: "同上" },
    { kind: "class-word", word: "border-dashed", reason: "境界の線種。色ではない" },
    { kind: "class-word", word: "divide-y", reason: "区切り線の軸。色ではない (色は divide-border が持つ)" },
    { kind: "class-word", word: "outline-none", reason: "outline の打ち消し。色ではない" },
    { kind: "class-word", word: "ring-2", reason: "focus ring の太さ。色ではない" },
    { kind: "class-word", word: "ring-3", reason: "同上" },
    { kind: "class-word", word: "rounded-full",
      reason: "角丸 ramp の外の真円 UI。radius token を指さず ds-purity の file-scoped allowlist が管轄する" },
    { kind: "class-word", word: "text-center", reason: "テキストの整列。色でも ramp でもない" },
    { kind: "class-word", word: "text-left", reason: "同上" },
    { kind: "class-word", word: "text-right", reason: "同上" },
    { kind: "css-variable", name: "--app-sidebar-w",
      reason: "同一要素の style 属性で宣言する局所変数。@theme の token ではない " +
              "(他ファイルのローカル宣言を解決の根拠に数えない)" },
] as const satisfies readonly NonTokenWord[];
```

```ts
// tests/js/styles/token-reference-closure.test.ts (新設)
/**
 * 参照の閉包 (正典 i9) — 自リポジトリのスタイルと画面のコードが参照する token 名が、
 * すべて写像 (resources/css/tokens.css の @theme) の宣言集合へ解決することを検査する。
 *
 * 【なぜ要るか】綴り誤りは「無スタイル」として静かに消える。Tailwind は未知の utility を
 *   エラーにせず、単に生成しない。
 * 【解決の根拠は写像 1 か所だけ】他ファイルのローカル宣言 (style 属性 / 別 CSS の :root) を
 *   根拠に数えると、正本の外に token 空間が静かに育つ形が通ってしまう。
 * 【走査対象】
 *   - resources/js: 文字列リテラルの中の class トークン (class-usage.ts と同じ走査単位)
 *   - resources/js / resources/css: `var(--…)` 参照
 * 【保証しないもの】
 *   - resources/views 配下 (Laravel 同梱メールテーマの独立パレット) は対象外
 *   - 変種の修飾の綴り (`hoverr:`) は見ない (Tailwind の名前空間で写像ではない)
 *   - 走査単位の外 (動的に組み立てた class) は見ない。既知の入口は class-usage.ts が deny する
 */
```

検査項目:

1. `scanClassUsage().occurrences` のうち `resolution.kind === "unresolved"` が **0 件**であること。
   すなわち、テーマ名前空間の接頭辞を持つ class トークンはすべて
   **写像の宣言集合 / ramp 集合 / radius 集合 / 契約表 (`class-word`)** のいずれかへ解決する。
   ★走査器は S2 の 1 本だけを使う (2 本目のパーサを書かない = i21)
2. `scanCssVarReferences()` のうち `unresolved` が **0 件**であること
   (**写像の宣言集合か契約表 (`css-variable`)** へ解決する)。
   ★`var()` 参照の走査根は **`resources/js` と `resources/css` の 2 本**である
   (class トークンの走査根が `resources/js` の 1 本であることとは**別の契約**。
   Round 3 の Warning: S2 と S3 で走査根の説明が食い違っていた)。
   **2 根とも存在すること**と**それぞれ列挙したファイル数が 0 でないこと**を gate が固定する。
   ★**根ごとの参照件数の非空は要求しない** (Round 4 の Warning:
   参照を正当に消しただけで赤くなる)。要求するのは**参照の総数が 0 でないこと**だけで、
   これは「アプリのスタイルが token を 1 つも参照しないことは無い」というドメインの不変条件である。
   ソース解析の本体は純粋入口 `scanCssVarReferencesSource(source, file)` を共有する。
   ★**入力は解析器の出力に限る** —
   `resources/css` は **postcss AST の `Decl.value` と対象 at-rule の `params` だけ**、
   `resources/js` は **S2 が確定した AST 上の文字列だけ**。

   **結果型に診断を持たせる** (Round 6 の Critical。参照配列だけでは診断を実装できない):

   ```ts
   export interface CssVarReferenceScan {
       readonly references: readonly CssVarReference[];
       readonly diagnostics: readonly CssVarReferenceDiagnostic[];
   }
   export type CssVarDiagnosticReason =
       | "unterminated-string"
       | "unterminated-function"
       | "unresolvable-var"
       | "unsupported-at-rule-params";
   ```

   **値走査の受理契約** (Round 6 の Critical。括弧カウントだけの実装にしない):
   1. **コメントは postcss が `Decl.value` から既に除いている** (実測:
      `color: var(--a /* c */)` → `value === "var(--a )"`、原文は `raws.value.raw`)。
      よって **`raws.value.raw` は使わない**
   2. 値を左から 1 文字ずつ走査する。`'` / `"` で始まる区間は**エスケープ (`\`) を尊重して**読み飛ばす
   3. 閉じない引用は診断 `unterminated-string`
   4. 引用区間の**外**で `var(` を見つけたら括弧の対応を数えて引数列を取る。
      閉じない括弧は診断 `unterminated-function`
   5. 第 1 引数は前後の空白を除いて `--` で始まる識別子でなければ診断 `unresolvable-var`
   6. fallback (第 2 引数以降) は同じ規則で**再帰的に**走査する

   **参照母集団に含める at-rule** は `@media` / `@supports` / `@container` の 3 つに限定して列挙する
   (条件式に `var()` を書ける at-rule)。**列挙外の at-rule の params に `var(` が現れたら
   診断 `unsupported-at-rule-params`** にする (無視しない = fail-closed)。

   正負例: `content: "var(--x)"` は参照 0 件 / `color: var(--a /* c */)` は `--a` を 1 件 /
   `var(--a, var(--b))` は 2 件 / `var(--a` は診断 / `--f: "a,b", c` は参照 0 件・診断 0 件 /
   `@media (min-width: var(--x))` は参照 1 件 / `@page { … var(--x) … }` は診断
3. **契約表に冗長な登録が無い**。判定は**チャネル別**に行う —
   `class-word` の登録は class トークンとして 1 回以上出現し、かつ写像へは解決しないこと。
   `css-variable` の登録は `var()` 参照として 1 回以上出現し、かつ写像へは解決しないこと
4. **母集団が空でない** (class トークン数 > 0 / `var()` 参照の**総数** > 0 /
   走査根が 2 本とも存在し、列挙したファイル数がそれぞれ > 0)。
   **`scanCssVarReferences().diagnostics` が空である** (本 gate が CSS var 診断の消費先である)。
   ★**契約表のチャネルごとの非空は要求しない** (最後の局所変数の例外を解消した
   正常な状態を赤にしないため)。チャネルごとの判定分岐は**固定検体で点灯**させる
5. 負のコントロール (固定検体):
   - `text-white` を含む検体 → 不合格になる (**Tailwind 既定テーマの色語を通さない**)
   - `bg-primaryy` (綴り誤り) → 不合格になる
   - `var(--color-does-not-exist)` → 不合格になる
   - 別ファイルの `:root` に `--color-foo` を宣言した検体 → **解決の根拠に数えない**
     (写像 1 か所だけという境界そのものを pin する)
   - 契約表の語 (`text-center` 等) は誤検出しない
   - **変種 / 重要度 / 不透明度の 3 形を別々に固定する** (共通規約 (e)) —
     接頭辞つき `sm:text-center` は解決する / 打ち消しつき `!text-center` は解決する /
     **接尾辞つき `text-center/50` は不合格**になる
     (色でない utility への不透明度修飾を「同じ語」として通すと、未知の utility が静かに通る)
   - `css-variable` の登録語を class トークンとして書いた検体 (`--app-sidebar-w` を class に置く) →
     **チャネルが違うので解決の根拠にならず不合格**になる

### 型適合チェック

- [x] 戻り値の型が明示されている / [x] `null` 安全 (解決失敗は結果に残して gate が落とす) /
      [x] 配列返却ではなく union / [x] Generics 正しい

### テスト計画

- [x] **先に赤くするテスト**: 検査 1。`text-white` が 3 箇所あるので**実装した時点で赤**になる。
      赤を確認してからアプリ側 3 箇所を直す (テストファースト。バグ修正の再現テストと同じ形)
- [x] 検査 3 (冗長な登録) を先に書き、契約表を空で置いて赤 → 埋めて緑
- [x] 負のコントロール 6 種を固定検体で置く (一時的に壊す形では代替しない = 正典 i18)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 契約表が Tailwind の構造 utility を足すたびに増える。
  → 増えるのは**テーマ名前空間の接頭辞を持つ語だけ**である (実測 17 件)。
  `flex` / `px-3` / `gap-2` のような語は接頭辞を持たないので母集団に入らない。
  この限定を docblock に書く。
- `text-white` → `text-surface` の置き換えでサイドバー選択中の見た目が変わらないこと
  (`--color-surface` = `#FFFFFF`) を実装時に目視で確認する。

---

## S7 実装からの逆向き被覆と役割分類の是正 (i15 / i14)

### 変更箇所

- `tests/js/styles/inventory.ts`
  (**`COLOR_TOKEN_ROLES` を新設して 5 つの役割配列をそこから導出する** /
  `CONTRAST_EXEMPT_TOKENS` を `NON_TEXT_BOUNDARY_REASONS` へ作り替える /
  `DECLARED_CONTRAST_PAIRS` を新設)
- `tests/js/architecture/contrast-invariant.test.ts` (逆向き被覆の describe を追加 /
  役割分類の全数性の it を個別宣言ペアまで含む形へ拡張)

### 波及変更

- TypeScript 型定義: `DeclaredPair` を新設
- API Resource/DTO: なし
- テストファイル: `contrast-invariant.test.ts` のみ。既存の 4 it と `it.each(PAIRS)` は据え置く
- **共有パス**: `contrast-invariant.test.ts` → S12

### 現行コード

```ts
// tests/js/styles/inventory.ts (L59-85)
export const FILL_TOKENS = ["primary","primary-hover","tertiary","tertiary-hover","success","warning","danger"] as const;
export const FILL_LABEL_TOKENS = ["neutral"] as const;
export const CONTRAST_EXEMPT_TOKENS = {
    "border": "1px の区切り線・入力欄の枠。テキストではなく WCAG 1.4.11 (非テキスト 3:1) の領域。…",
    "border-strong": "区切りの強調・ghost ボタンの枠。…",
} as const;
```

### 走査で判明した役割分類の食い違い 2 件と、その決着

| 食い違い | 実測 | 決着 |
|---|---|---|
| `bg-border` が**テキストを載せた塗り面**として使われている (`atoms/Button.types.ts` の neutral variant の hover) のに、`border` は 1.4.11 の免除に入っている | `text-text` on `bg-border` = 13.96 で AA を満たす | **`border` を免除から外し、個別宣言ペアで受ける**。`FILL_TOKENS` には**入れない** |
| `text-surface` が塗り面のラベルとして使われている (字幕帯 / 撮影中バッジ / サイドバーの選択中) のに、`surface` は `FILL_LABEL_TOKENS` に無い | `surface` × 全塗り面 = 6.70〜9.48 で全組が AA を満たす (是正後の値) | **`FILL_LABEL_TOKENS` へ `surface` を追加する** (直積が全組成立するので直積で受けられる) |

**`border` を `FILL_TOKENS` へ入れない理由 (設計判断)**: 入れると直積に
`neutral on border` (**1.15**) と `surface on border` (**1.27**) が生まれるが、
**この 2 組は実装に 1 件も存在しない** (`bg-border` の上に載るのは `text-text` だけである)。
実在しない組を検査すると誤検知になる。正典 i14 は
「役割の直積で表現できない正当な 1 対 1 の組は**個別宣言ペア**として理由つきで足し、
直積と同じ閾値を課す」と定めており、これはまさにその用途である。
**逆に `surface` は直積が全組成立するので直積側で受ける** — 個別宣言ペアは
「直積で表現できないもの」に限る (安易に個別宣言へ逃がすと母集団が痩せる)。

### 変更後コード

```ts
// tests/js/styles/inventory.ts

/**
 * 色 token の役割。**1 つの token が複数の役割を持ちうる** (思考原則 4: 別物の用途を統合しない)。
 *
 * ★Round 1 レビューの Critical を受けた作り直しである。旧設計は
 *   「個別宣言ペアに現れた token を役割分類済みと数える」形だったため、
 *   **任意の新 token を 1 組だけ登録すれば全色被覆の既定拒否を通せる**穴があった。
 *   役割の全数性は本表のキーと DESIGN.md の色キーの集合一致だけで見る。
 */
export type ColorRole =
    /** 面 = 容器の背景。**半透明の合成の下地でもある** (i16) */
    | "surface"
    /** 面の上に載るテキスト色 */
    | "text-on-surface"
    /** 塗り面 (solid fill) */
    | "fill"
    /** 塗り面の上に載るラベル色 */
    | "fill-label"
    /** 直積で表現できない、テキストを載せる塗り (個別宣言ペアの背景側にだけ現れる) */
    | "declared-text-background"
    /** 1px 境界・focus ring 等。WCAG 1.4.11 の別の閾値体系なので本 gate の対象外 (i17。理由必須) */
    | "non-text-boundary";

/**
 * ★**役割分類の唯一の宣言**。既存の 5 つの配列は**ここから導出する** (i4)。
 * ★キーは **DESIGN.md の色キー空間**である (`text-primary` は本文色 = `--color-text`)。
 */
export const COLOR_TOKEN_ROLES = {
    "primary": ["text-on-surface", "fill"],
    "primary-hover": ["fill"],
    "tertiary": ["text-on-surface", "fill"],
    "tertiary-hover": ["fill"],
    "neutral": ["surface", "fill-label"],
    "surface": ["surface", "fill-label"],
    // ★2 役割を持つ: 1px 枠 (対象外) と、Button の neutral variant の hover 塗り (検査する)
    "border": ["non-text-boundary", "declared-text-background"],
    "border-strong": ["non-text-boundary"],
    "text-primary": ["text-on-surface"],
    "text-secondary": ["text-on-surface"],
    "success": ["text-on-surface", "fill"],
    "warning": ["text-on-surface", "fill"],
    "danger": ["text-on-surface", "fill"],
} as const satisfies Readonly<Record<string, readonly ColorRole[]>>;

/** 導出 (固定配列を持たない = i4)。 */
export const SURFACE_ROLE_TOKENS = tokensWithRole("surface");
export const TEXT_ON_SURFACE_TOKENS = tokensWithRole("text-on-surface");
export const FILL_TOKENS = tokensWithRole("fill");
export const FILL_LABEL_TOKENS = tokensWithRole("fill-label");

/**
 * `non-text-boundary` の役割を持つ token の理由 (理由必須。正典 i17)。
 *
 * ★**キー集合が `tokensWithRole("non-text-boundary")` と一致する**ことを機械で見る
 *   (理由だけ残る / 役割だけ足す のどちらも落とす)。
 * ★**「この token は一切検査しない」という意味ではない**。`border` は
 *   `declared-text-background` の役割も持つので、その用途は個別宣言ペアで検査される。
 */
export const NON_TEXT_BOUNDARY_REASONS = {
    "border":
        "1px の区切り線・入力欄の枠としての用途。WCAG 1.4.11 (非テキスト 3:1) の別の閾値体系で、" +
        "装飾的な境界線は 1.4.11 の適用除外にあたるため、使用箇所ごとの役割分類が要る " +
        "(家系の未決論点 q2 の担当)。**テキストを載せる塗りとしての用途は別の役割で検査する**",
    "border-strong":
        "3 つの用途がいずれも本 gate の対象外である — (1) 1px の区切り線・入力欄の枠 " +
        "(WCAG 1.4.11 の非テキスト 3:1 で別の閾値体系。役割モデルが未定のため家系の未決論点 q2 の担当)、" +
        "(2) Toggle のトラック (テキストを載せない塗り)、" +
        "(3) 無効化したタブのラベル (SC 1.4.3 は無効化された UI 部品を適用除外にしている)。" +
        "実測 2.56 で 3:1 に届かないので、値の是正は 1.4.11 の役割モデルを DESIGN.md に" +
        "定めてから別バッチで行う",
} as const;

/**
 * 役割の直積で表現できない正当な 1 対 1 の組 (理由必須。正典 i14)。
 *
 * ★直積と**同じ閾値** (4.5:1) を課す。
 * ★**キーは DESIGN.md の色キー空間**である。走査器が返す CSS suffix 空間とは別なので、
 *   突き合わせは COLOR_TOKEN_MAP の逆写像で行う。
 * ★**役割分類の既定拒否をここで迂回できない** — 本表に現れた token を
 *   「分類済み」と数えるのはやめ、分類の全数性は `COLOR_TOKEN_ROLES` だけで見る。
 *   本表に対しては別の集合一致を課す (下の 3 条)。
 */
export const DECLARED_CONTRAST_PAIRS = [
    {
        fg: "text-primary",
        bg: "border",
        reason:
            "Button の neutral variant の hover (hover:bg-border + text-text)。" +
            "border を塗り面の役割へ入れると直積に neutral on border (1.15) と " +
            "surface on border (1.27) が生まれるが、この 2 組は実装に 1 件も無い。" +
            "border の 1px 枠としての用途は WCAG 1.4.11 (別の閾値体系) で本 gate の対象外である",
    },
] as const satisfies readonly DeclaredPair[];
```

**個別宣言ペアに課す 5 条** (これが無いと「1 組登録して全色被覆を通す」経路が残る):

1. 背景側は `declared-text-background` の役割を持つこと
2. 前景側は `text-on-surface` か `fill-label` の役割を持つこと
3. `declared-text-background` の役割を持つ token は、**本表の背景側に 1 回以上現れる**こと
   (役割だけ宣言して組を書かない = 死んだ宣言を作らせない)。
   加えて背景側は `surface` / `fill` の役割を**持たない**こと
   (持つなら直積で受けられるので個別宣言は冗長である)
4. **各個別宣言ペアが、走査された不透明ペアに 1 回以上現れる**こと
   (Round 4 の Warning: 3 条までだと、同じ背景へ**実装に存在しない前景**を足して
   母集団を広げられた。実在しない組を検査すると誤検知になるので、実在を要求する)
5. **同一 `(fg, bg)` の重複宣言を拒否する**こと

```ts
// tests/js/architecture/contrast-invariant.test.ts (追加)

/** 個別宣言ペアも直積と同じ閾値を課す (正典 i14)。 */
const PAIRS = [
    ...TEXT_ON_SURFACE_TOKENS.flatMap(/* 既存 */),
    ...FILL_LABEL_TOKENS.flatMap(/* 既存 */),
    ...DECLARED_CONTRAST_PAIRS.map((p) => [p.fg, p.bg, "個別宣言ペア"] as const),
];

describe("architecture/contrast-invariant: 実装からの逆向き被覆 (i15)", () => {
    it("走査の分母が空でない (ディレクトリ単位の走査が生きている)", () => {
        // ★非空を要求するのは `requiresOccurrences: true` の子だけである
        //   (Round 2 の Critical: 全件へ要求すると、抽出 0 件が正常な lib / types /
        //   直下ファイルで**設計どおり実装すると必ず赤**になる)。
        const scan = scanClassUsage();
        expect(scan.files.length).toBeGreaterThan(0);

        // 分類表と走査結果のキーが集合一致する
        // (分類していない子が現れても、分類したのに走査していない子があっても赤)
        expect([...scan.perDirectory.keys()].sort()).toEqual(
            Object.keys(JS_SCAN_CHILD_CLASSIFICATION).sort(),
        );

        for (const [dir, spec] of Object.entries(JS_SCAN_CHILD_CLASSIFICATION)) {
            if (!spec.requiresOccurrences) continue;
            expect(scan.perDirectory.get(dir), `${dir} から 1 件も抽出できていない`)
                .toBeGreaterThan(0);
        }
    });

    it("走査で得た不透明ペアがすべて母集団 (役割の直積 + 個別宣言) の内側にある", () => {
        // 役割の宣言を書かずに新しい組を足す経路を塞ぐ。
        // 走査は CSS suffix 空間なので COLOR_TOKEN_MAP の逆写像で母集団へ写す。
        …
    });

    it("既知の要求組が抽出結果から実際に生成される (抽出の空振り防止)", () => {
        // Badge の全 tone と Button の全 variant が期待どおり出ること (正典 i15)。
        // 期待値は TONE_CLASSES / VARIANT_CLASSES のキーから導出する (件数を散文に書かない)。
        …
    });

    it("面の役割とテキストの役割が素である (自己ペア = 比 1.0 が混入しない)", () => {
        // 既存 it の等価な置き換え (導出後の配列で見る)。
        const surfaces = new Set<string>(SURFACE_ROLE_TOKENS);
        expect(TEXT_ON_SURFACE_TOKENS.filter((t) => surfaces.has(t))).toEqual([]);
    });

    it("走査器が扱えない既知の入口が 0 件である", () => {
        expect(unsupportedEntryPoints()).toEqual([]);
    });

});
```

> **解決できなかった class トークン (`resolution.kind === "unresolved"`) を 0 件に固定するのは
> S3 (参照の閉包) の担当**である。同じ主張を 2 つの gate へ書くと、片方を緩めたときに
> もう片方が残っていることが分かりにくくなる (責務境界は `docs/design-system.md` の表が正本)。
> 走査器は `unresolved` を**結果に必ず残す** (無言で候補から外さない = 共通規約 (b) の 1 点目)。

既存 it の書き換え (**個別宣言ペアを分類の根拠に数えない**形へ):

```ts
it("役割宣言が DESIGN.md の全色トークンを覆う (deny-by-default)", () => {
    // ★分類の全数性は COLOR_TOKEN_ROLES **だけ**で見る。
    //   個別宣言ペアに現れることを「分類済み」と数えると、任意の新 token を
    //   1 組登録するだけで既定拒否を通せてしまう (Round 1 レビューの Critical)。
    expect(Object.keys(COLOR_TOKEN_ROLES).sort()).toEqual(Object.keys(COLOR_TOKEN_MAP).sort());
    for (const [token, roles] of Object.entries(COLOR_TOKEN_ROLES)) {
        expect(roles.length, `${token}: 役割が 0 件`).toBeGreaterThan(0);
        // 同じ役割の重複登録を拒否する (導出した直積に重複ペアが生じるのを防ぐ。Round 2 の Suggestion)
        expect(new Set(roles).size, `${token}: 役割が重複している`).toBe(roles.length);
    }
});

it("non-text-boundary の役割と理由の集合が一致する (理由だけ残る / 役割だけ足す を落とす)", () => {
    expect(Object.keys(NON_TEXT_BOUNDARY_REASONS).sort()).toEqual(
        [...tokensWithRole("non-text-boundary")].sort(),
    );
    for (const [token, reason] of Object.entries(NON_TEXT_BOUNDARY_REASONS)) {
        expect(reason.length, `${token}: 理由`).toBeGreaterThan(30);
    }
});

it("個別宣言ペアが 5 条を満たす (直積の既定拒否を迂回できない)", () => {
    const declaredBackgrounds = new Set(DECLARED_CONTRAST_PAIRS.map((p) => p.bg));
    const scanned = new Set(
        scanClassUsage().pairs.filter((p) => p.kind === "opaque").map((p) => `${p.fg}|${p.bg}`),
    );
    expectUnique(DECLARED_CONTRAST_PAIRS, (p) => [p.fg, p.bg]);
    for (const p of DECLARED_CONTRAST_PAIRS) {
        expect(rolesOf(p.bg), `${p.bg}: 背景側の役割`).toContain("declared-text-background");
        expect(rolesOf(p.bg), `${p.bg}: 直積で受けられる背景は個別宣言にしない`)
            .not.toContain("surface");
        expect(rolesOf(p.bg)).not.toContain("fill");
        expect(
            rolesOf(p.fg).some((r) => r === "text-on-surface" || r === "fill-label"),
            `${p.fg}: 前景側の役割`,
        ).toBe(true);
        expect(p.reason.length, `${p.fg} on ${p.bg}: 理由`).toBeGreaterThan(30);
        // 実装に存在しない個別宣言ペアを足せないようにする (走査は suffix 空間なので写す)
        expect(
            scanned.has(`${toSuffix(p.fg)}|${toSuffix(p.bg)}`),
            `${p.fg} on ${p.bg}: 実装に 1 件も無い個別宣言ペア`,
        ).toBe(true);
    }
    // 役割だけ宣言して組を書かない = 死んだ宣言を作らせない
    for (const token of tokensWithRole("declared-text-background")) {
        expect(declaredBackgrounds.has(token), `${token}: 役割はあるが個別宣言ペアが無い`).toBe(true);
    }
});
```

### 型適合チェック

- [x] 戻り値の型が明示されている
- [x] `null` 安全 (`hex()` は不在で例外。`COLOR_TOKEN_MAP` の逆写像が引けない suffix は例外)
- [x] 配列返却ではなく `as const satisfies readonly DeclaredPair[]` の宣言
- [x] Generics の型パラメータが正しい

### テスト計画

- [x] **先に赤くするテスト**: 「走査で得た不透明ペアがすべて母集団の内側にある」。
      役割分類を直す**前**に実行すると `(text, border)` が母集団の外なので**赤**になる。
      **S3 が先に済んでいる**ので `text-white` は `text-surface` へ直っており、
      `(surface, primary)` も同時に赤で現れる — `surface` に `fill-label` の役割を足し、
      `border` に `declared-text-background` の役割と個別宣言ペアを足すまで赤が続く。
      これが役割分類と実装の食い違いの実証である
- [x] 「個別宣言ペアが 5 条を満たす」は、次の 2 つの検体で**赤**になることを先に確認する —
      (a) `border` に `surface` の役割を足した状態 (直積で受けられるのに個別宣言している)、
      (b) **実装に存在しない前景**を同じ背景へ足した状態 (母集団の水増し)
- [x] 「役割宣言が DESIGN.md の全色トークンを覆う」は、`COLOR_TOKEN_ROLES` から 1 キーを
      抜いた検体で**赤**になることを確認する (個別宣言ペアで迂回できないことの裏取り)
- [x] `it.each(PAIRS)` に個別宣言ペアが加わることで**組の総数が増える**ことを確認する
      (母集団を痩せさせていないことの確認)
- [x] 既存テストの削除・上書きをしない: 既存の 4 it (役割の被覆 / 0 件でない / 素である /
      pending が空でない) と `it.each(PAIRS)` は**すべて据え置く** (被覆の it は拡張のみ)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- 個別宣言ペアは「直積で表現できないもの」に限る規律が緩むと母集団が痩せる。
  → 登録の理由に「直積へ入れると実在しない組が生まれる」ことを**具体的な比の値つき**で
  書くことを様式にする (上記 `reason` の形)。レビューで判断できる。
- `surface` に `fill-label` の役割を足すと直積が 7 組増える。是正後の値では全組が
  6.70〜9.48 で成立する (実測)。**是正前の値では `surface on primary` が 5.17 で成立する**ので、
  S6 の前に足しても赤にはならない。

---

## S9 非描画領域の除去と字下げの禁止を 2 契約に分け、行分類を 1 実装へ集約する (i12 の残余)

### 変更箇所

- `tests/js/styles/design-system-docs.test.ts` (`renderedLines()` / docblock / fixture)
- `docs/design-system.md` (「落とすのは HTML コメント / fenced code の 2 つ」の記述を訂正)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 同ファイルの fixture に負例を追加
- **共有パス**: `docs/design-system.md` → S12

### 現行コード

```ts
// tests/js/styles/design-system-docs.test.ts (L25-28 の docblock)
 *   - **描画されない領域の全種類**。潰すのは HTML コメントと fenced code の 2 つだけで、
 *     4 空白字下げのコードブロックや HTML 要素による非表示は見ていない
```

### 変更後コード

```ts
/**
 * 4 空白以上の字下げ行を**落とすのではなく、gate 自体を失敗させる** (Round 2 の Critical)。
 *
 * ★経緯: 当初は CommonMark の indented code block を状態機械で近似して落とす設計だったが、
 *   「直前の描画行が空行」という近似では**見出しの直後**の 4 空白行を取りこぼす。
 *   CommonMark で字下げコードが中断できないのは**段落**であって、
 *   見出しや区切り線の直後の 4 空白行は字下げコードになりうる。
 *   したがって近似のままだと、規範の最小断片を
 *     ## 契約
 *     (空行)
 *     ␣␣␣␣本当は読者に見えないコードの中にある規範文
 *   の形へ退避させて**緑にできる穴が残る**。
 *
 * ★**契約は 2 つあり、混ぜない** (Round 5 の Critical: 「container 文法を扱わない」は
 *   字下げの検出には言えるが、**囲みコードの除去には言えない**)。
 *
 * 【契約 A — 規範判定対象外領域の除去】
 *   ★呼称は「非描画領域」ではなく**「規範判定対象外領域」**である (Round 6 の Warning) —
 *     **HTML コメントは読者に描画されない**が、**囲みコードは描画される**。
 *     どちらも「規範の本文として数えない」点だけが共通である。
 *   落とすのは HTML コメントと囲みコードの 2 つ。
 *   ★**fence の受理範囲を明記する** (実装者依存にしない。Round 6 の Warning) —
 *     marker は**同一文字 3 個以上** (`` ` `` または `~`)、開始は**字下げ 3 空白まで**、
 *     終了は**開始と同じ種類で開始以上の長さ・後続は空白のみ**、
 *     backtick 型は**info string にバッククォートを含められない**。
 *   ★**container を伴う fence 候補はすべて診断にする** (Round 6 の Critical。
 *     `- > ``` ` や `> - ``` ` は「行頭の `>` を剥がす」でも `^ {0,3}` でも通過し、
 *     4 連続空白も含まないので契約 B でも落ちない) —
 *     **囲みコードの外の行に fence marker (3 個以上連続した `` ` `` または `~`) が
 *     どこかに現れたら、その行が上の受理範囲を満たす正規の top-level fence 行でない限り、診断**にする。
 *     ★これで `- > ``` ` も `> - ``` ` も `  > ``` ` も落ちる。
 *       **container 文法 (list marker の記法・padding・入れ子の順) を 1 つも書かない**。
 *     ★行内コード span は 1〜2 個のバッククォートなので誤検出しない。
 *     ★実測: `docs/design-system.md` と `DESIGN.md` はどちらも fence 0 行なので偽陽性は起きない。
 *
 * 【契約 B — 字下げの禁止】囲みコードの外に次のいずれかがあれば **gate を失敗させる**。
 *   1. **タブを含む行** (列の解釈が環境依存になるため)
 *   2. **4 個以上連続した半角空白を含む行** (行頭に限らない)
 *   ★**契約 B は container 文法を 1 つも扱わない**。
 *
 *   ★**見逃しが 0 であることの証明** (Round 5 で論証を差し替えた。
 *     旧論証の「container marker が消費する空白は marker ごとに高々 1 個」は**誤り**で、
 *     CommonMark の list marker の padding は 1〜4 である):
 *     (1) すべての有効な container prefix を消費した後の**内容開始列**を基準にする。
 *     (2) 字下げコードには、その基準から**さらに 4 列以上**の字下げが要る。
 *     (3) タブを禁じた場合、その追加 4 列を作れるのは**連続した U+0020 だけ**である。
 *     (4) list marker の幅や padding は**内容開始列を決める prefix 側**であり、
 *         追加 4 列の代用にはならない。
 *     (5) gate は全行を見るので、コードブロックの**少なくとも先頭の非空行**で
 *         4 連続空白を検出する。
 *     よって `>␣␣␣␣text` も `-␣␣␣␣␣␣text` も `1)␣␣␣␣␣text` も契約 B で落ちる。
 *
 *   - i12 の目的 (契約の本文を読者に見えない場所へ退避させられないこと) は、
 *     **そもそも書かせない**ことで満たす。
 *   - 実測: `docs/design-system.md` は囲みコードの外にタブが **0 件**、
 *     4 連続空白も **0 件**である。現時点で偽陽性は起きない。
 *   - **偽陽性の class は 1 つだけ**である — 本文の中で意図的に 4 空白以上を並べる書き方
 *     (表の桁揃え等)。**書き方を直す**のが正しい対応であり、検査は緩めない。
 *   - 失敗のメッセージには**直し方**を書く (「囲みコード ``` を使うこと」)。
 * ★**CommonMark パーサは導入しない**: `marked` / `commonmark` / `markdown-it` はいずれも未導入で、
 *   この 1 検査のために依存を増やすのは「今必要なものだけ作る」に反する。
 *   **導入を再検討する契機**は「本書に字下げコードを書く正当な必要が出たとき」である
 *   (そのときは block レベルの解析が要る)。
 * ★保証しないもの: HTML 要素による非表示 (`<details>` / `hidden` 属性等) は見ていない。
 */
```

**行の分類は 1 回だけ行う** (Round 3 の Warning: `renderedLines()` と
`indentedLineNumbers()` がそれぞれ囲みコード状態を解析すると、同じ Markdown に
2 本の字句走査ができて弱い方が緑を作る = i21 と同じ問題)。

```ts
// tests/js/styles/markdown-lines.ts (新設。*.test.ts ではないので責務境界表の母集団に入らない)
export interface MarkdownScan {
    /** 規範判定の対象になる行 (HTML コメントと囲みコードを "" へ潰したもの。**行数は保つ**) */
    readonly renderedLines: readonly string[];
    /** 契約 B: 囲みコードの外でタブ、または 4 個以上連続した半角空白を含む行の行番号 (1 始まり) */
    readonly forbiddenIndentLines: readonly number[];
    /**
     * 契約 A: 解析できなかった形 (1 件でもあれば gate が落ちる)。
     * ★`unparsableFenceLines` という個別の口は**廃止**し、理由つきの診断へ一本化した
     *   (Round 6: blockquote fence 以外の未対応 fence を表現できなかった)。
     */
    readonly diagnostics: readonly MarkdownDiagnostic[];
}

export interface MarkdownDiagnostic {
    /** 1 始まりの行番号 (診断出力用。期待値には使わない) */
    readonly line: number;
    readonly reason: MarkdownDiagnosticReason;
}

export type MarkdownDiagnosticReason =
    | "unterminated-html-comment"
    | "unterminated-fence"
    | "container-fence"     // container prefix を伴う fence 候補
    | "unsupported-fence";  // 受理範囲外の fence 記法

export function scanMarkdownLines(source: string): MarkdownScan;
```

- `design-system-docs.test.ts` (S9) と `design-md.ts` の
  `parseDesignComponentSections()` (S8) が**同じ実装**を使う。
- 固定検体は `design-system-docs.test.ts` の既存の fixture describe に置く
  (新しい `*.test.ts` を増やさない = 責務境界表の行を増やさない)。

`docs/design-system.md` の訂正:

```markdown
ただし**完全な Markdown 解析ではない** — 4 空白字下げのコードブロックと
HTML 要素による非表示は見ていない。
```
↓
```markdown
落とすのは HTML コメントと囲みコードの 2 つで、**引用記号を付けた囲みコード
(`> ` で始まる ``` の行)は解析できない書き方として検査自体を失敗させる**
(引用の中にコードを置かないこと)。
加えて**タブと 4 個以上連続した半角空白も検査自体を失敗させる**
(字下げコードは書かず囲みコードを使うこと)。
字下げコードの位置を近似で判定すると見出し直後や引用の中の形を取りこぼし、
そこへ規範の断片を退避させられる。タブを禁じたうえで 4 連続空白を拒否すれば、
引用やリストの記号が何段入れ子になっていても字下げコードは書けないので、
**字下げについては引用やリストの文法を一切扱わずに見逃しを 0 にできる**。
ただし**完全な Markdown 解析ではない** — HTML 要素による非表示は見ていない。
```

### 型適合チェック

- [x] 戻り値の型が明示されている (`readonly string[]`)
- [x] `null` 安全 (状態は判別可能な形で持つ)
- [x] 配列返却は行配列という性質上正しい (行数保存が契約)
- [x] Generics なし

### テスト計画

- [x] **先に赤くするテスト**: 実文書に対する
      「囲みコードの外にタブと 4 連続空白が無い」「Markdown 走査の診断が 0 件である」の
      2 it を**先に書く** (本 gate が docs 側 Markdown 診断の消費先である)。
      実装前は `scanMarkdownLines()` が存在せず**コンパイルエラーで赤**。
      次に空実装 (`throw`) で**実行時エラーの赤**を確認してから実装する
- [x] 負のコントロール (固定検体を `scanMarkdownLines()` へ直接渡す):
  1. **空行の後の 4 空白字下げ行**を検出する
  2. **見出しの直後の 4 空白字下げ行**を検出する
     (Round 2 の近似が取りこぼしていた形)
  3. **段落の継続行** (直前が空行でない 4 空白字下げ行) も検出する
     (CommonMark では本文だが、本 gate は書き方そのものを禁じるので区別しない。
     この「厳しい側へ倒す」判断を docblock に明記する)
  4. **行頭タブ**を検出する
  5. **1〜3 空白 + タブ**を検出する
  6. **`>␣␣␣␣text`** (blockquote の中の字下げコード) を検出する
     (Round 3 の Critical が指摘した見逃し)
  7. **入れ子の blockquote** (`> >␣␣␣␣text`) を検出する
  8. **list marker の後の字下げコード** (`-␣␣␣␣␣␣text`) を検出する
  9. **`1)␣␣␣␣␣text`** (ordered list の別記法) を検出する
     (**container 文法を 1 つも書かずに落ちる**ことの裏取り = 本改訂の要点)
  10. **行の途中の 4 連続空白** (`text␣␣␣␣text`) を検出する
      (「行頭に限らない」ことの明示。偽陽性 class をテストで見えるようにする)
  11. **marker の padding 1〜4** (`-␣text` 〜 `-␣␣␣␣text` の各段の継続行が字下げコードになる形)
  12. **ordered marker の 1〜9 桁**と `.` / `)` の両方
  13. **list の最初の block が字下げコード**の場合と、**後続 block が字下げコード**の場合
  14. **blockquote と list の異種入れ子** (`> -␣␣␣␣␣text` / `- >␣␣␣␣text`)
  15. **lazy continuation は字下げコードではない**という**正例** (誤検出しない)
  16. **囲みコードの中の 4 空白字下げ行とタブ**は検出しない (偽陽性を出さない負のコントロール)
  17. **通常の blockquote 本文** (`> text`) と**通常の list 本文** (`- text` /
      2 空白の継続行) は検出しない (偽陽性を出さない負のコントロール)
  18. 1〜3 空白の字下げ行は検出しない
  19. **契約 A の負例 (`container-fence` の診断になる)**: `> ``` ` / `> > ``` ` /
      `- > ``` ` / `> - ``` ` / `  > ``` ` / 行の途中に現れる 3 連バッククォート。
      その中に置いた規範の断片や `### 部品名` が**通常本文として数えられない**ことを固定する
  20. **契約 A の正例**: `^ {0,3}` の正規の fence は通常の fence として扱われ、中身が落ちる
      (診断にならない)。行内コード span (1〜2 個のバッククォート) も診断にならない
  21. **契約 A の負例 (`unsupported-fence` / `unterminated-fence`)**:
      開始より短い終了 marker / 種類の違う終了 marker /
      backtick 型で info string にバッククォートを含む行 / EOF まで閉じない fence
  21. **行数が保存される**こと (既存の it が自動で見る)
- [x] 既存の 8 it が同じ期待値で緑であること (`docs/design-system.md` に
      4 空白以上の字下げ行が 1 行も無いことを実測済み)
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- **本書に字下げコード・タブ・4 連続空白を書けなくなる**(囲みコードを使うことになる)。
  実測で現状 0 行なので既存の記述は影響を受けない。
  リストの継続行は**3 空白以内**にする必要がある (S11 で足す行はこの制約に合わせてある)。
  → 赤くなったら**書き方を直す**のが正しい対応であり、検査を緩めない。
  拾いすぎる方向へ倒すのは共通規約 (b)「拾いすぎる方向へ倒すのは可、見逃す方向へ倒すのは不可」に沿う。
- 逆に**近似の状態機械で落とす**実装にすると、見出し直後の字下げコードへ
  規範の断片を退避させて緑にできる (Round 2 レビューで是正した点)。そちらは見逃す方向なので採らない。
- 本施策で `docs/design-system.md` の**記述の訂正**が要る (「落とすのは 2 つ」の説明)。
  この文書は共有パスなので S12 の D51 で決着させる。

---

## S8 文書 ⇔ 実装の双方向一致 gate を新設する (i10)

### 変更箇所

- 新規: `tests/js/styles/component-doc-parity.test.ts`
- `tests/js/styles/design-md.ts` (`designComponentSections()` を追加 — 正本の解析は 1 実装へ集約)
- `tests/js/styles/inventory.ts` (`COMPONENT_DIR_CLASSIFICATION` / `COMPONENT_FILE_KINDS` /
  `COMPONENT_SECTION_MAPPINGS` を新設)
- `DESIGN.md` (§Components 冒頭の対象範囲を明記 + **4 節を追加**)

### 波及変更

- TypeScript 型定義: 分類表と申告表の型を新設
- API Resource/DTO: なし
- テストファイル: 新設 1 本 (S11 で責務境界表へ行を足す)

### 現行コード

```markdown
## Components

> component 仕様は実装(`resources/js/components/`)と型定義が真実。本節は意味論と
> 使い分けルールのみを定義する。各 component を追加したら本節に追記すること。
```

31 節が並ぶ。実測で**節を持たない部品が 4 本**ある。

### 変更後コード

```markdown
## Components

> component 仕様は実装(`resources/js/components/`)と型定義が真実。本節は意味論と
> 使い分けルールのみを定義する。
> **本節が対象にするのは DS の再利用部品(`atoms` / `molecules` / `organisms`)である。**
> `features/` のドメイン部品と `templates/` のレイアウト骨格は本節の対象外
> (前者は各 feature の設計が使い分けを決め、後者は §Layout と
> `tests/js/architecture/page-shell-structure.test.ts` が担当する)。
> **対象の component を追加したら本節に追記すること**
> (`tests/js/styles/component-doc-parity.test.ts` が双方向の集合一致で強制する)。
```

追加する 4 節 (アルファベット順ではなく既存の並びの流儀 = 概ね atom → molecule に従う):

| 節 | 対応ファイル | 節に書く意味論 |
|---|---|---|
| `### DragHandle` | `atoms/DragHandle.svelte` | 並べ替えのつかみ手。`GripVertical` 固定 / `touch-none` でタッチをスクロールに奪わせない / 小コントロールなので `rounded-sm` / **並べ替えができない状態の表現は別途定義する** (禁止事項 8 は「必須条件未充足を理由に disabled にする」ことの禁止であって、あらゆる disabled の禁止ではない) |
| `### OrganizationChoiceCard` | `molecules/OrganizationChoiceCard.svelte` | 組織を 1 件選ぶ遷移カード。遷移先 URL は親が渡す (組織文脈を molecule が解決しない) |
| `### PendingInvitationsNotice` | `molecules/PendingInvitationsNotice.svelte` | 自分宛の保留中招待の件数だけを出す誘導専用 notice。**受諾 UI は持たない** (受諾は通知一覧) |
| `### SubtitleOverlay` | `molecules/SubtitleOverlay.svelte` | 映像へ重畳する字幕 overlay。焼込ではなく DOM overlay (MediaRecorder の stream に含まれない) / primary=上部帯・secondary=下部メイン / 位置は `AssSubtitleWriter` (ASS) と一致 / 長文は line-clamp で省略 |

**判定は 3 段の純粋関数に分ける** (Round 2 の Critical。S2 と同じ穴が S8 にもあった —
実リポジトリを直接列挙する gate だけでは「未分類ディレクトリを足す」「部品を 1 つ足す」の
固定検体を同じ判定実装へ渡せない):

```ts
/**
 * DESIGN.md の本文から §Components の `###` 節名を取り出す (design-md.ts に置く)。
 *
 * ★**S9 が新設する共通 Markdown 行走査 (`scanMarkdownLines`) を共有する** — 独立した弱い解析器を
 *   増やさない (i21)。単純な見出し正規表現だと、囲みコードの中に `### DragHandle` を置いて
 *   「文書化済み」に見せられ、**双方向一致という中心の保証を直接迂回できる** (Round 3 の Critical)。
 *   ★**S9 が前提施策である** (実施順は S9 → S8)。
 *   ★Markdown 走査の **`diagnostics` が 1 件でもあれば解析失敗**にする (未終端コメント /
 *     未終端 fence / container fence / 未対応 fence を**同じ経路**で消費する。Round 6 の Warning)。
 *     `- > ``` ` の中へ `### 部品名` を置いて「文書化済み」に見せる迂回もここで落ちる。
 *     ★**本 gate が DESIGN.md 側 Markdown 診断の消費先である**。
 *     実測: `DESIGN.md` は blockquote 2 行・fence 0 行なので現時点で偽陽性は起きない。
 * ★契約 5 条 (いずれも固定検体で裏取りする):
 *   1. `## Components` は**ちょうど 1 節**であること (0 件も 2 件も例外)
 *   2. HTML コメントと囲みコードの中の見出しは**数えない**
 *   3. `###` だけを対象にし、`####` 以降は数えない
 *   4. 同名の節が 2 つあれば**例外**
 *   5. Markdown 走査の診断 (未終端コメント / 未終端 fence / container fence /
      未対応 fence) が 1 件でもあれば**解析失敗** (i20)
 */
export function parseDesignComponentSections(source: string): readonly string[];

/**
 * ディレクトリ木を分類表で仕分ける (部品の母集団と、未分類の検出結果を返す)。
 * ★引数は**構造型**である (Round 3 の Critical: `typeof COMPONENT_DIR_CLASSIFICATION` にすると
 *   実定数の literal 型に固定され、**固定検体から分類表を増減できない**)。
 */
export type ComponentDirClassification = Readonly<Record<string, ComponentDirSpec>>;
export type ComponentFileKinds = Readonly<Record<string, ComponentFileKindSpec>>;
export type ComponentSectionMappings = readonly ComponentSectionMapping[];

export function classifyComponentTree(
    tree: ComponentTree,
    dirClassification: ComponentDirClassification,
    fileKinds: ComponentFileKinds,
): ComponentClassification;

/** 節と部品を申告表つきで突き合わせる (双方向の差分と、申告表の失効・重複・冗長を返す)。 */
export function compareComponentDocumentation(
    sections: readonly string[],
    components: readonly string[],
    mappings: ComponentSectionMappings,
): ComponentDocDiff;
```

実定数は `as const satisfies ComponentDirClassification` の形で構造型へ適合させる
(literal 型の情報は保ちつつ、純粋関数へは構造型として渡せる)。

実ファイル用の gate は、DESIGN.md を読み・ディレクトリを列挙し・この 3 段へ渡すだけの
薄いラッパーにする。固定検体は 3 段へ直接渡す。

**探索規則** (Round 1 レビューの Warning。再帰の境界を実装者依存にしない):

1. **集合一致は 2 段で見る** (Round 3 の Warning: 分類表は深さ 2 の `atoms/icons` を含むので、
   直下の集合とそのまま比べると字面上矛盾する) —
   (1) `resources/js/components` の**直下**のサブディレクトリ集合と、
   分類表キーの**第 1 要素**の集合を一致させる。
   (2) 再帰が終わった後に、**実際に使用した完全パスの集合**と分類表**全体**を一致させる
2. `kind: "excluded"` の分類は**そこで再帰を止める** (中は一切見ない)
3. `kind: "documented"` の分類は**その直下のファイルだけ**を部品の母集団に入れる
4. `documented` の直下にさらにサブディレクトリがある場合 (`atoms/icons`)、
   **そのパス自体が分類表に無ければ不合格**にする (深さ 2 以降も同じ規則を適用する)
5. **部品の basename の重複を無条件に拒否する** (Round 3 の Warning: 既定の対応が
   ファイル名だけなので、`atoms/Foo.svelte` と `molecules/Foo.svelte` があると 1 節へ衝突する)。
   ★判定は `classifyComponentTree()` で行い、**申告表では救わない** (Round 4 の Warning:
   同関数は申告表を受け取らないので、救う口を書くと二通りに読める)。
   実測でも重複 basename は 0 件で、救う必要のある実例が無い。
   将来重複が要るようになったら、そのとき判定を `compareComponentDocumentation()` 側へ移す
   (この契機を docblock に書く)
6. 分類表のキーは実在するディレクトリであり、かつ**実際に判定へ使われた**こと。
   `excluded` の配下は規則 2 で再帰を止めるので、そこに入れ子のキーを登録しても
   **判定に使われない死んだ登録**になる (Round 2 の Warning)。
   したがって `templates/_helpers` の登録は**削除する** (`templates` が `excluded` で止まる)。
   使われなかった分類エントリが 1 つでもあれば不合格にする

```ts
// tests/js/styles/inventory.ts
/** §Components の対象にするサブディレクトリの全数分類 (既定拒否。キーは components からの相対パス)。 */
export const COMPONENT_DIR_CLASSIFICATION = {
    atoms: { kind: "documented" },
    molecules: { kind: "documented" },
    organisms: { kind: "documented" },
    templates: {
        kind: "excluded",
        reason: "レイアウトの骨格。使い分けは DESIGN.md §Layout と page-shell-structure.test.ts が担当する",
    },
    features: {
        kind: "excluded",
        reason: "ドメイン部品。使い分けは各 feature の設計が決め、DS の再利用部品カタログではない",
    },
    "atoms/icons": {
        kind: "excluded",
        reason: "Lucide に無いブランド/SSO ロゴの SVG 内包専用。svg-inline-allowlist.test.ts が担当する",
    },
    // ★`templates/_helpers` は登録しない — `templates` が excluded で再帰を止めるので
    //   判定に使われない死んだ登録になる (Round 2 の Warning)。
} as const;

/**
 * 対象ディレクトリ直下のファイル種別の全数分類 (既定拒否)。
 *
 * ★照合は**最長接尾辞一致**である (Round 2 の Warning: `.types.ts` は `.ts` の接尾辞でもあり、
 *   照合順が未定義だと `Button.types.ts` が helper へ誤分類されうる)。
 */
export const COMPONENT_FILE_KINDS = {
    ".svelte": { kind: "component", requiresSection: true },
    ".types.ts": {
        kind: "types",
        requiresSection: false,
        reason: "型と variant 表。同名の *.svelte が対になっていることを検査する",
    },
    ".ts": {
        kind: "helper",
        requiresSection: false,
        reason: "共有 helper。現状 1 件 = atoms/input-state.ts (入力系 atom の共通スタイル定義)",
    },
    ".gitkeep": { kind: "marker", requiresSection: false, reason: "空ディレクトリの目印" },
} as const;

/** 既定の対応 (節名 = ファイル名) に乗らない対応の申告 (理由必須。正典 i10)。 */
export const COMPONENT_SECTION_MAPPINGS = [
    { section: "Input / Textarea / Select(入力系 atom)",
      files: ["atoms/Input.svelte", "atoms/Textarea.svelte", "atoms/Select.svelte"],
      reason: "3 つの入力 atom は同じ枠・同じ状態表現を共有するため 1 節で意味論を定義している" },
    { section: "Toast", files: ["organisms/ToastContainer.svelte"],
      reason: "節名は利用者から見た概念 (Toast)、実装は容器 1 本 (ToastContainer)" },
    { section: "PageHeader / PageHeaderSection",
      files: ["molecules/PageHeader.svelte", "molecules/PageHeaderSection.svelte"],
      reason: "ページ見出しと節見出しは対で使うため 1 節で使い分けを定義している" },
] as const;
```

検査項目:

1. **双方向の集合一致**: §Components の `###` 節と、対象ディレクトリ直下の `*.svelte` が
   (申告表を適用したうえで) 集合一致する
2. **サブディレクトリの全数分類**: 実在するサブディレクトリが分類表と集合一致する (未分類は不合格)
3. **ファイル種別の全数分類**: 対象ディレクトリ直下の拡張子が分類表と集合一致する (未分類は不合格)
4. **`.types.ts` に対の `*.svelte` がある** (孤立した型ファイルを作らせない)
5. **申告表の健全性**: 失効 (存在しない節 / 存在しないファイル) / 重複 (同じファイルが 2 つの節に) /
   **冗長** (既定の対応で足りるのに申告している) をそれぞれ落とす
6. **母集団が空でない** (節数 > 0 / 部品数 > 0)
7. 負のコントロール (**固定検体を 3 段の純粋関数へ直接渡す**): 節を 1 つ消すと赤 /
   部品を 1 つ足すと赤 / 申告を冗長にすると赤 / 未分類のサブディレクトリを足すと赤 /
   **`documented` の下に未分類の入れ子ディレクトリを足すと赤** (規則 4 の裏取り) /
   **`excluded` の下のファイルは母集団に入らない** (規則 2 の裏取り) /
   **使われなかった分類エントリがあると赤** (規則 5 の裏取り)
8. ファイル種別の**最長接尾辞一致**の裏取り (固定検体):
   `Button.types.ts` → `types` / `input-state.ts` → `helper` /
   `Badge.svelte` → `component`
9. 節の抽出の負のコントロール (固定検体を `parseDesignComponentSections()` へ直接渡す):
   囲みコードの中の `### DragHandle` は**数えない** / HTML コメントの中の見出しも数えない /
   `#### X` は数えない / `## Components` が 2 つあれば例外 / 同名の `###` が 2 つあれば例外 /
   未終端の囲みコードは診断 /
   **container を伴う fence (`> ``` ` / `- > ``` ` / `> - ``` `) の中の `### X` は
   「数えない」のではなく診断になる** (扱えないものを通常本文として数えない)
10. basename 重複の負のコントロール: `atoms/Foo.svelte` と `molecules/Foo.svelte` を置くと不合格

### 型適合チェック

- [x] 戻り値の型が明示されている
- [x] `null` 安全 (節の抽出に失敗したら例外 = i20)
- [x] 配列返却ではなく `as const satisfies` の宣言
- [x] `kind` の網羅を `never` へ収束させる

### テスト計画

- [x] **先に赤くするテスト**: 検査 1 (双方向の集合一致)。DESIGN.md に 4 節を足す**前**は
      「実装にあるのに節が無い」で赤になる (13 部品事件と同じ形が実在することの実証)
- [x] 検査 5 の冗長判定を先に書き、`Input / Textarea / Select` を申告しない状態で赤を確認する
- [x] 負のコントロール 4 種を固定検体で置く
- [x] 個別の `DatabaseTransactions` を使っていない

### リスク

- DESIGN.md §Components に節を足す作業が今後の部品追加ごとに要る。
  → それが i10 の目的である。`docs/design-system.md` の
  「コンポーネント追加時のチェックリスト」に既に
  「DESIGN.md §Components に意味論・使い分けを追記」が入っており、規約は変わらない。
- `features/` を対象外にする判断は、DESIGN.md 冒頭の「各 component を追加したら本節に追記する」と
  食い違っていた。→ 同じ PR で冒頭の文を対象範囲つきに直す (上記)。

---

## S11 責務境界表へ新設 gate を登録する (i11 の帰結)

### 変更箇所

- `docs/design-system.md` (§検査の責務境界の表に 4 行追加 / **本数の記述そのものを廃止** /
  §トークン変更時の運用契約に 1 行追加 / §テーマの差し替え方に 1 行追加)

### 波及変更

- テストファイル: 既存 `design-system-docs.test.ts` の
  「責務境界表の 1 列目と実在する検査ファイルが集合一致する (双方向)」が**この行なしでは赤**
- **共有パス**: `docs/design-system.md` → S12

### 変更後コード (表に追加する 4 行)

| 検査 | 見ている写像 | 代表的に検出する壊れ方 |
|------|------------|--------------------|
| `tests/js/styles/token-reference-closure.test.ts` | 参照側 (resources/js / resources/css) ⇒ tokens.css の宣言集合 | token 名の綴り誤りが無スタイルとして静かに消える / 写像の外の色語 (Tailwind 既定の white 等) の混入 |
| `tests/js/styles/component-doc-parity.test.ts` | DESIGN.md §Components ⇔ resources/js/components の部品ファイル | 文書に載らない部品が増える / 節だけ残って実装が消える |
| `tests/js/styles/class-usage.test.ts` | 走査器そのもの (固定検体) | 状態単位の分解の退行 / 未対応入口の deny の空振り |
| `tests/js/styles/theme-map.test.ts` | 写像パーサそのもの (固定検体) | `@theme` の検出・宣言の抽出・色表現の解析の退行 |

**本数の記述そのものを廃止する** (Round 1 レビューの Critical: 既存 4 本 + 新規 4 本 = 8 本で、
「4 本 → 6 本」は算術的に誤っていた。**数字は機械検査の対象外なので必ず陳腐化する**)。

| 現行 | 変更後 |
|---|---|
| 「本節で責務境界を管理するデザイントークン検査は **4 本ある**」 | 「本節で責務境界を管理するデザイントークン検査は**下表に挙げたものがすべてである**」 |
| 「保証しないもの: … **4 本のどれも**見ていない」 | 「保証しないもの: … **下表のどれも**見ていない」 |

表の双方向集合一致 (`design-system-docs.test.ts`) だけを正本にする。

`§トークン変更時の運用契約` へ追加する 1 行
(★S9 の決着により**字下げ 4 以上の継続行を作らない**。1 行に収める):

```markdown
- [ ] トークンの**値**を変える場合は `contrast-invariant.test.ts` の不透明ペアと**半透明ペア(合成)**の両方が緑であること(ソフト背景の色は面の上での合成後の値で判定される)
```

`§テーマの差し替え方` の 3 手順へ追加:

```markdown
3. parity テスト green を確認(**contrast-invariant の合成検査も含む**。
   状態色を明るい段に戻すとソフト背景側で落ちる)
```

★継続行の字下げは **3 空白以内**にする (S9 の gate が 4 空白以上を失敗させる)。
上の例は 3 空白なので通る。

### 型適合チェック

- [x] 該当なし (Markdown)

### テスト計画

- [x] **先に赤くするテスト**: S3 / S8 / S2 の新 `*.test.ts` を置いた時点で
      既存の「責務境界表の 1 列目と実在する検査ファイルが集合一致する」が**赤**になる。
      その赤を確認してから本施策で行を足す
- [x] 既存の「Canonical source 表の 2 列目のパスがすべて実在する」が緑のままであること
- [x] 規範の最小断片 (`SECTION_CONTRACT_PHRASES`) を**変えない**
      (契約の文言は変えず、行と本数だけを足す)

### リスク

- **本数の記述は廃止する**ので陳腐化しない。表そのものが機械で突き合わされており、
  数字は「表と実体が一致していること」に何も足していなかった。
  数字を最小断片 (`SECTION_CONTRACT_PHRASES`) に入れない (文言固定は増やさない = 既存方針)。

---

## S12 共有パスの採用時債務を決着させる (乖離台帳)

### 変更箇所

- `docs/template-divergence.md` (宣言行 46 → 48 / **D50 と D51 を追加**)
- `tests/Support/TemplateDivergence/LedgerPins.php`
  (`DIVERGENCE_ENTRY_COUNT` 46 → **48** / `ADOPTION_DEBT_COUNT` 148 → 146)
- `tests/Support/TemplateDivergence/adoption-debt.tsv` (2 行削除)

### 乖離台帳の確認段 (app-design スキル 3-0)

`docs/template-fingerprints.json` のキーに在るか (= テンプレートと共有するパスか) を実測した。

| 変更するパス | 指紋台帳のキー | 採用時債務 | 決着 |
|---|---|---|---|
| `docs/design-system.md` | **在る** | **在る** (採用時 sha と現況が一致) | **(3) 意図的逸脱として登録 (D51) を書き、債務から削る** |
| `tests/js/architecture/contrast-invariant.test.ts` | **在る** | **在る** (同上) | **(3) 意図的逸脱として登録 (D50) を書き、債務から削る** |
| `tests/js/support/ds-purity.ts` | 在る | 在る | **変更しない** (i9 が同じ穴を塞ぐので `white`/`black` を禁止リストへ足す案は採らない) |
| `DESIGN.md` | 無い | — | 登録不要 |
| `resources/css/tokens.css` | 無い | — | 登録不要 |
| `resources/css/app.css` | 無い (変更もしない) | — | — |
| `tests/js/styles/*` (既存 5 + 新設 3) | 無い | — | 登録不要 (既存の D28 が同領域の逸脱を説明済み) |
| `postcss.config.js` | 在る (変更しない) | — | — |

**判定の根拠**: `FingerprintReconciler` は債務パスの現況が採用時 sha と違えば
`mutatedDebtPaths` として落とす。かつ債務パスと登録の対象パスの**両方に在る** (`doubleDeclaredPaths`)
のも落とす。したがって「登録を書く」と「債務から削る」は**同じ変更で行う**。

### 追加する登録 (D50 / D51 の 2 件)

**2 エントリに分ける** (Round 1 レビューの Warning: 1 エントリの説明がコントラストだけでは、
`docs/design-system.md` に入る**別の変更理由** (検査目録の正本化 / 描画されない領域の除去範囲 /
運用契約への合成検査の追加) を説明できない。**パス単位で採用時債務を解除するのだから、
登録理由は変更全体を説明していなければならない**)。

```markdown
## D50 デザイントークンのコントラスト検査を、半透明の合成と実装からの逆向き被覆まで広げる

| 行 | 内容 |
|---|---|
| 対象パス | `tests/js/architecture/contrast-invariant.test.ts` |
| 業務要件起因の説明 | 撮影 PWA の状態表示 (撮影中 / 完了 / 警告) はソフト背景のバッジで出しており、作業者はその 1 個の色で工程の状態を読む。テンプレートの検査は不透明な組だけを見るため、実際に画面へ出ているソフト背景の可読性が 1 件も検査されていなかった (実測で 5 組が AA 未達) |
| 揃え続ける不変条件と保証機構 | 半透明の背景 × 不透明な文字の組が、面として分類した token のすべての上で 4.5:1 を満たすこと。走査で見つかった半透明の組が (ファイル, 組, 修飾率, 件数) で全件台帳に載り、静的に決められない形は理由と件数つきで別台帳に載ること。台帳が持つのは class 修飾の百分率だけで、token 固有 alpha との合成は 1 か所 (`resolveAlphaBackground()`) に集約されること。実装の class から導出した前景 × 背景の組が役割の母集団 (役割の全数分類の直積 + 個別宣言ペア) の内側にあること。線形化しきい値が errata 後の 0.04045 であること。`contrast-invariant.test.ts` と `tests/js/styles/class-usage.ts` が保証する |
| 再判定の条件 | 正典が半透明の合成を不変条件から外したとき。または Tailwind の不透明度修飾の展開形が変わって合成モデルの前提が崩れたとき (`tokens.test.ts` の「不透明度修飾の生成形」が赤くなる)。広色域の実描画との差を実測して系統的なずれが出たとき (家系の未決論点 q3) |
| 決めた日 | 2026-08-24 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260824-1019-design-token-system-v1/ |
| 状態 | 恒久 |
| 見直し期限 | — |
```

```markdown
## D51 デザインシステム運用ガイドを検査目録の正本にし、部品カタログの被覆と字下げの禁止まで機械で固定する

| 行 | 内容 |
|---|---|
| 対象パス | `docs/design-system.md` |
| 業務要件起因の説明 | 本アプリはデザイントークン検査を独自系統で持つ (D28) ため、検査の本数も置き場もテンプレートと一致せず、責務境界の表を機械照合の入力にしている以上テンプレートの散文をそのまま維持できない。加えて (1) 撮影 PWA のテーマ値を動かす運用契約 (半透明の合成検査を通すこと) を書き足す必要があり、(2) DS の再利用部品が文書に載らないまま増える事故 (家系で実在) を機械で止めるため部品カタログの被覆を契約として書く必要があり、(3) 契約の本文を読者に見えない場所へ退避させる経路を塞ぐため字下げコードの扱いを書き換える必要がある |
| 揃え続ける不変条件と保証機構 | 正本の宣言表が全数宣言であり検査側の宣言と役割とパスの組で集合一致すること。責務境界表と `tests/js/styles/*.test.ts` の実体が双方向に集合一致すること。DESIGN.md §Components の節 (囲みコード・HTML コメントの中の見出しを数えず、`## Components` がちょうど 1 節であること) と対象サブディレクトリの部品ファイルが双方向に集合一致すること。節ごとの規範の最小断片が読者に描画される本文に在ること。描画されない領域 (HTML コメントと囲みコード) を検査の前に落とし、保証は 2 つに分かれる — (a) **規範判定対象外領域の除去**: HTML コメント (読者に描画されない) と囲みコード (描画されるが規範の本文として数えない) を落とす。囲みコードの外の行に fence marker (3 個以上連続したバッククォートまたはチルダ) が現れ、その行が字下げ 3 空白までの正規の top-level fence 行でなければ**診断**にする (container を伴う fence を通常本文として数えない)。未終端のコメント・未終端の fence・受理範囲外の fence 記法も同じ診断へ落とし、診断が 1 件でもあれば検査を失敗させる。(b) **字下げコードの拒否**: タブと 4 個以上連続した半角空白を含む行が現れたら検査自体を失敗させる (タブを禁じた前提では、container prefix を消費した後の内容開始列からさらに 4 列以上の字下げが要り、その 4 列を作れるのは連続した U+0020 だけなので、container 文法を扱わずに字下げコードの見逃しを 0 にできる)。**完全な CommonMark 解析ではない** — 保証するのはこの 2 命題の範囲だけである。行の分類は 1 実装 (`scanMarkdownLines()`) に集約されること。`tests/js/styles/design-system-docs.test.ts` と `tests/js/styles/component-doc-parity.test.ts` が保証する |
| 再判定の条件 | 検査目録を文書ではなく機械可読な台帳へ移したとき。部品カタログの正本を DESIGN.md 以外へ移したとき。Markdown パーサを導入して字下げコードを解析できるようにしたとき。または正典が運用ガイドの節構成そのものを不変条件として明文化したとき |
| 決めた日 | 2026-08-24 |
| 決めた人 | 開発者 |
| 根拠 | devnotes/20260824-1019-design-token-system-v1/ |
| 状態 | 恒久 |
| 見直し期限 | — |
```

各エントリには観点表 (テンプレート / 本アプリ)、`### なぜ正当な差分か(logic-driven)`、
`### 揃えている不変条件(これは保証し続ける)`、`### 保証しないもの`、`### 関連` を
エントリ形式どおりに書く。**対象パスは全登録の和集合で重複しない**規約があるので、
既存 D28 の対象パス (`tests/js/styles/tokens.test.ts` /
`tests/js/styles/design-system-docs.test.ts`) とは重ならないことを確認する
(実測: `docs/design-system.md` と `contrast-invariant.test.ts` はどの登録にも現れていない)。

> **D28 の本文も同じ変更で直す**: 「保証しないもの」に書かれた
> 「描画されない領域として除くのは HTML コメントと fenced code の 2 つだけで、
> 4 空白字下げのコードブロックと HTML 要素による非表示は見ていない」は S9 で事実が変わる
> (**除くのは 2 つのままだが、字下げ 4 以上は除かずに検査を失敗させる**)。
> 台帳の中身を実態に合わせるのは登録の維持であって新規登録ではない (件数は変わらない)。

### 型適合チェック

- [x] `LedgerPins.php` は `int` 定数の値変更のみ。型は変わらない (PHPStan level 10 に影響なし)
- [x] `declare(strict_types=1)` は既に在る

### テスト計画

- [x] **先に赤くする**: S4 で `contrast-invariant.test.ts` を 1 文字変えた時点で
      `TemplateDivergenceFingerprintTest` が `mutatedDebtPaths` で**赤**になる。
      その赤を確認してから本施策で決着させる
- [x] `TemplateDivergenceLedgerFormatTest` (9 行ちょうど / 値域 / 対象パスの実在と重複なし /
      件数の 3 点一致) が緑であること
- [x] `TemplateDivergenceFingerprintTest` の `doubleDeclaredPaths` が空であること
      (債務から削り忘れると赤)
- [x] `composer test` 全体が緑 (PHP 側の唯一の変更が定数 2 本なので他への波及なし)

### リスク

- 債務件数を減らす変更なので、**掃除の方向**である (D34 の期限つき縮小の趣旨に沿う)。
- D 番号は再利用しない規約なので `D50` / `D51` (現在の最大が `D49`) を使う。
- 件数の 3 点一致 (本文の宣言行 46 → **48** / 見出しの実数 / `DIVERGENCE_ENTRY_COUNT`) を
  同じ変更で揃える。**エントリ形式の例 (`## D1 <逸脱の要約>`) は囲みコードの中なので
  見出しの実数に数えない** — 実測で本文の `## D<n> ` 見出しは 47 個検出されるが、
  うち 1 個がその例である (現行の宣言行 46 と整合)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 12 施策が 1 本の依存鎖でつながっており、途中の状態では**必ず赤いテストが残る**。とくに S5 (合成検査) を入れた時点で 5 組が赤になり、S6 (値の是正) を同じ作業単位で行わなければ main がマージ不能になる。同様に S1 / S2 / S3 / S8 が新 `*.test.ts` を作った時点で既存 `design-system-docs.test.ts` が赤になり、S11 が同じ作業単位に無いと閉じない。S4 が共有パスを触った時点で `TemplateDivergenceFingerprintTest` が赤になり、S12 が同じ作業単位に無いと閉じない。分割すると「赤いまま main に入れる」か「後方互換の並走を残す」のどちらかになり、AGENTS.md 思考原則 3 と禁止事項 1 に触れる |
| 競合リスク | `tests/js/styles/inventory.ts` に 6 つの台帳・分類表を追加するため、同ファイルを触る他タスクと衝突しうる。`docs/TODO.md` の Open は T249 (別 feature「起動 probe の共通 runner 一元化」) のみで、`tests/js/styles/` には触らないため**現時点で衝突なし**。`DESIGN.md` / `resources/css/tokens.css` / `docs/design-system.md` も T249 の対象外 |

### 実装中に「後方互換の並走を残さない」ために同じ作業単位で消すもの (AGENTS.md 思考原則 3)

| 消すもの | 移す先 |
|---|---|
| `canonical-source-parity.test.ts` のローカル `cssColorTokens()` / radius 抽出 / `@utility` 抽出 | `tests/js/styles/theme-map.ts` |
| `PENDING_CONTRAST_PAIRS` の「alpha 合成ペア」の 1 行 | `ALPHA_PAIR_USAGE_LEDGER` + `UNDECIDABLE_PAIR_LEDGER` (pending には判定不能の分類だけが残る) |
| `CONTRAST_EXEMPT_TOKENS` (token 単位の排他な免除) | `COLOR_TOKEN_ROLES` の複数役割 + `NON_TEXT_BOUNDARY_REASONS` + `DECLARED_CONTRAST_PAIRS` |
| `SURFACE_ROLE_TOKENS` / `TEXT_ON_SURFACE_TOKENS` / `FILL_TOKENS` / `FILL_LABEL_TOKENS` の**固定配列** | `COLOR_TOKEN_ROLES` からの導出 (i4: 母集団を固定配列に書かない) |
| `UndecidableReason` の `double-alpha` | 使用箇所台帳の `modifierPercent` として載せ、`resolveAlphaBackground()` が実効値 (積) を作る |
| `resources/js` の `text-white` 3 箇所 | `text-surface` |
| `docs/design-system.md` の「落とすのは HTML コメントと fenced code の 2 つ」 | 「2 つを落とし、4 空白以上の行は検査自体を失敗させる」へ訂正 |
| `docs/design-system.md` の「検査は 4 本ある」 | 「下表に挙げたものがすべてである」(数字を持たない形へ) |
| `adoption-debt.tsv` の 2 行 | `docs/template-divergence.md` の D50 / D51 |

### migration の扱い

**DB migration は 1 本も要らない**。本作業はスタイルの正本・写像・検査・文書・乖離台帳のみで、
スキーマ・モデル・Factory・route・DTO に触れない。したがって
`docs/architecture.md` / `docs/factories.md` への追記も不要である
(新規モデルを追加していないため)。

### 検証コマンド (全 green でコミット)

`composer test` / `composer phpstan` / `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`

- `pnpm build` は**必須**である (トークン値を変えるので生成 CSS が変わる)。
- `composer test` は `TemplateDivergenceFingerprintTest` /
  `TemplateDivergenceLedgerFormatTest` を含むので S12 の決着を検証する。

---

## Round 1 レビューの横断評価への対応

| 横断の指摘 | 対応 |
|---|---|
| PHPStan・DTO/JsonResource・Inertia Props・DB・tenant 認可への直接変更は無い | **アプリケーション PHP は変更しない**。テスト支援 PHP (`LedgerPins.php`) の `int` 定数 2 本だけを変更する |
| Atomic Design 上、新規アプリ component は無い。S8 のディレクトリ分類規則は明確化が要る | S8 に**探索規則 5 条**を明記し、未分類の入れ子を固定検体で落とすようにした |
| **最大の後退リスク**: 正規表現ベースの class / CSS 解析が解析不能を検出できず「候補なし」として落とすこと | `TokenResolution` に `unresolved` を持たせ、**結果に必ず残して S3 の gate が落とす**形にした (無言で候補から外さない = 共通規約 (b) の 1 点目)。`unparsable-token` (区切りで割れた形) と `alpha-on-non-color` (色でない utility への不透明度修飾) を値域へ明示した |
| S4 のように本体を呼ばない負例、S9 のように仕様と矛盾する負例は i18 の裏取りにならない | S4 は `linearizeChannel()` を切り出して**実装本体を呼ぶ既知値検査**へ、S9 は開始条件を 1 本に統一して**段落継続行を落とさない**仕様へ直した |

### Round 1 の指摘で設計判断そのものが変わった 4 点 (記録)

1. **`bg-primary-soft/40` は静的に決められる** — 実効 alpha は `0.12 × 0.40 = 0.048` に確定するので、
   判定不能へ逃がすのは i16 に反する。合成対象に加えた
2. **個別宣言ペアを役割分類の根拠に数えない** — 数えると 1 組の登録で既定拒否を通せる。
   役割は `COLOR_TOKEN_ROLES` の**複数役割**で持ち、`border` は
   「非テキスト境界」と「テキストを載せる塗り」の 2 つを持つ (思考原則 4)
3. **字下げコードは段落を中断できない** — CommonMark の規則に従い、段落の継続行は落とさない。
   旧設計の「落とす側に倒す」は fail-closed ではなく**仕様の誤り**だった。
   ★**この記録は Round 1 時点の判断であり、現在の設計ではない** —
   Round 2 で「書き方自体を禁止する」方針へ再変更し、Round 4 で判定条件を
   「タブ禁止 + 4 連続空白禁止」へ確定させた (下の「方針の変遷」が正本)
4. **走査根は `resources/js` の 1 本** — 固定 3 根は実測で 4 つの入口を取り落としており、
   docblock の主張と実装が食い違っていた

---

## Round 2 レビューの横断評価への対応

| 横断の指摘 | 対応 |
|---|---|
| 「PHP を 1 行も変えない」と「唯一の PHP 変更は `LedgerPins.php`」が矛盾 | 「**アプリケーション PHP は変更しない。テスト支援 PHP の整数定数 2 本だけを変更する**」へ訂正した |
| PHPStan・DTO/JsonResource・Inertia Props・DB・tenant 認可・OWASP のアプリ実行経路に新たな問題は無い | 変更なし |

### Round 2 で設計判断そのものが変わった 3 点 (記録)

1. **字下げコードは「落とす」のをやめて「書かせない」** — 近似の状態機械では
   **見出し直後**の字下げコードを取りこぼし、そこへ規範の断片を退避させて緑にできた。
   囲みコードの外に字下げ 4 以上の行があれば gate 自体を失敗させる形にして、見逃しを 0 にした
   (i12 の目的をより強く満たす)
2. **合成の入力は完全正規化してから渡す** — `ParsedColor` の alpha と台帳の実効 alpha を
   合成関数が二重適用しうる形だった。`ResolvedAlphaBackground { rgb, effectiveAlpha }` を
   挟み、alpha の出所を 1 つにした
3. **固定検体の入口を純粋関数に統一する** — Round 1 で S1 に指摘された穴が
   S2 (class 走査) と S8 (文書 ⇔ 実装) で再発していた。3 施策とも
   「純粋入口が唯一の実装、実ファイル用は薄いラッパー」に揃えた

### Round 2 で見つかった事実誤認 (記録)

- **`tokens.css` に文字列リテラルは実在する** (`--font-sans` の引用符つき family が 8 個)。
  「文字列リテラルは 1 件も無い」と書いて字句走査を単純化していたのは誤りで、
  文字列状態を持たない走査は文字列の中の `/*` `{` `}` を境界と誤認する
- **`parseCssColor()` に hex を渡す経路が設計内にあった** (`designColors()` は hex を返す) のに、
  契約は rgba/rgb のみだった。`#rrggbb` を正式対応にした
- **Badge の tone 数の 5 と 6 は別物** (ソフト背景を持つ tone が 5、`BadgeTone` の全数が 6)。
  散文が 2 つの数を混ぜていたので、**件数を散文に書かず実装のキーから導出する**形に直した

---

## Round 3 レビューの横断評価への対応

| 横断の指摘 | 対応 |
|---|---|
| Round 1 の対応記録に、現在の S9 と反対の説明 (「段落継続行を落とさない仕様へ直した」) が残っている | 下の「方針の変遷」で 3 ラウンド分の履歴を明示し、Round 1 の記録行に注記を足した |
| アプリケーション PHP・PHPStan・DTO/JsonResource・Inertia Props・DB・tenant 境界・認可・LLM 経路への直接変更は無い | 変更なし |
| Atomic Design 上、新規アプリ component の追加は無く S8 の対象範囲は妥当 | 変更なし |

### S9 の方針の変遷 (3 ラウンドで 2 回変わったので履歴を残す)

| ラウンド | 方針 | 変更した理由 |
|---|---|---|
| Round 1 の設計 | 状態機械で近似して**落とす**。段落の継続行も落とす側に倒す | — |
| Round 1 → 2 | 段落の継続行は**落とさない** (CommonMark では字下げコードは段落を中断できない) | 状態遷移の説明とテスト計画が両立していなかった (仕様の誤り) |
| Round 2 → 3 | 近似で落とすのをやめ、**書き方の側を禁じて失敗させる** | 「直前が空行」の近似では**見出し直後**の字下げコードを取りこぼし、そこへ規範の断片を退避させて緑にできた |
| Round 3 → 4 | 禁止の判定を**有効字下げ列 (container marker を除去した後) + タブ禁止**へ拡げる | `>␣␣␣␣本文` とタブ混在が素朴な 4 空白判定をすり抜けており、「見逃し 0」の保証が成立していなかった |
| Round 4 → 5 | **契約を 2 つに分ける** — (A) 非描画領域の除去では `>` 接頭辞つき fence を**解析失敗**にする / (B) 字下げの禁止は**タブ禁止 + 4 連続空白禁止**の 2 条で container 文法を扱わない | 「container 文法を扱わない」は字下げの検出には言えるが**囲みコードの除去には言えず**、`> ``` ` の中へ規範文や `### 部品名` を退避できた。また旧証明の前提 (marker が消費する空白は高々 1 個) が CommonMark と食い違っていた (padding は 1〜4) |

### Round 3 で設計判断そのものが変わった 3 点 (記録)

1. **単位は名前で分ける** — `alpha` という無単位の名前をやめ、
   `alphaPercent` (0..100) と `effectiveAlpha` (0..1) に分けた。
   台帳が持つのは修飾の百分率だけで、**実効値を台帳から渡す経路が型で存在しない**
2. **半透明の台帳は使用箇所の全数台帳にする** — 正典 i16 の「件数まで含めて要求する」に従い、
   `(file, fg, bg, modifierPercent, count)` を完全一致で固定し、
   AA の検査はそれを意味ペアへ射影したものを回す
3. **文書の行分類は 1 実装に集約する** — `scanMarkdownLines()` を新設し、
   S8 の節抽出と S9 の字下げ検査が同じ実装を使う (弱い方が緑を作る形を作らない)

---

## Round 4 レビューの横断評価への対応

| 横断の指摘 | 対応 |
|---|---|
| Round 3 の指摘件数がレビュー本文と一致しない | 件数を**ラベルの機械カウント**へ統一し、全ラウンドを数え直して冒頭と各対応マトリクスの見出しを揃えた |
| Round 1 対応表に古い S9 方針が現在形で残っている | 当該行に「Round 1 時点の判断であり現在の設計ではない」と**その場で**注記した |
| アプリケーション PHP・DB・tenant 境界・認可・LLM 経路・DTO/JsonResource・Inertia Props への変更は無い | 変更なし |
| Atomic Design 上も新規 component 追加は無く、S8 の境界は妥当 | 変更なし |
| 主なリスクは静的 gate が偽グリーンになることによる設計統制の後退 | S1 / S2 / S9 の解析方式を作り替えて対処した (下記) |

### Round 4 で設計の骨格が変わった 3 点 (記録)

**共通の判断**: 「手書きの字句走査で頑張る」のをやめ、
**既にこのリポジトリで使っている解析器へ寄せた** (思考原則 1 / 先人の知恵を探せ)。

1. **写像の解析は `postcss`** — `tokens.test.ts` が既に使っている解析器を写像側にも使う。
   文字列リテラルの誤認・at-keyword の境界・宣言値の中の `@theme`・未終端の各種構文が
   **すべて解析器の側で解決**し、自前の 5 状態走査を書く理由が消えた
2. **class の走査は `svelte/compiler` + `typescript`** —
   準拠実装 `tests/js/support/file-input-scan.ts` と同じ形にする。
   template 補間の終端誤認で**以降のソースを無言で読み落とす**経路が構造的に消える
3. **Markdown の字下げ判定は「タブ禁止 + 4 連続空白禁止」** —
   container 文法 (引用・リスト・ordered marker の各種記法) を**1 つも扱わずに**、
   字下げコードの見逃しを 0 にできることを証明つきで示した。
   Round 3 で列挙が要ると思われていた文法は**全部要らなくなった**

### 3 ラウンドで縮んだもの (記録)

| 当初の設計 | 最終形 | 消えた複雑さ |
|---|---|---|
| 自前 5 状態 CSS 字句走査 | `postcss.parse()` | 状態機械・at-keyword 境界・文の位置の定義 |
| 自前の TS/Svelte 文字列抽出 (正規表現 → 状態スタック) | `svelte/compiler` の AST + `ts.createScanner()` | 補間の終端判定・入れ子 template・未終端の扱い |
| CommonMark の container 文法の列挙と再帰除去 | 「タブ禁止 + 4 連続空白禁止」の 2 条 | 引用・リスト・ordered marker の全記法 |

---

## Round 5 レビューの横断評価への対応

| 横断の指摘 | 対応 |
|---|---|
| 件数の数え方が本文中のラベルへの言及まで数えている | 件数の正本を**行頭のラベル** (`^\[Critical\]` 等) に限定し、全ラウンドを再集計した |
| S9 の「方針の変遷」が最終形まで更新されていない | 変遷表に `Round 4 → 5` の行を足した |
| アプリケーション PHP・DB・tenant 境界・認可・LLM 経路・DTO/JsonResource・Inertia Props への変更は無い | 変更なし |
| Atomic Design の境界 (atoms / molecules / organisms を対象、features / templates を対象外) は妥当 | 変更なし |

### Round 5 で設計判断そのものが変わった 4 点 (記録)

1. **`.ts` は AST で読む** — `ts.createScanner()` は字句解析器なので
   `` `${cond ? "}" : v}` `` の補間終端を構文的に解けない。`ts.createSourceFile()` にし、
   **parse diagnostics が 1 件でもあれば解析失敗**にする (括弧不整合も fail-closed になる)
2. **class 候補は「空白で割ってから候補全体を検証する」** — 「許可文字以外はすべて区切り」だと
   `bg-primaryあ` が `bg-primary` へ**縮退して通ってしまう**。候補全体に許可外の文字が
   1 つでもあれば候補全体を未解決にする
3. **囲みコードの除去には container の扱いが要る** — 「container 文法を扱わない」は
   字下げの検出にだけ言える主張だった。`>` を剥がして fence に見える行は**解析失敗**にする
   (扱えないものを通常本文として数えない)
4. **証明の前提を差し替えた** — 旧論証の「marker が消費する空白は高々 1 個」は CommonMark と
   食い違っていた (list marker の padding は 1〜4)。内容開始列を基準にする形へ直した

### 解析器へ寄せた結果 (3 ラウンド分の要約)

| 当初の設計 | 最終形 | 消えた複雑さ |
|---|---|---|
| 自前 5 状態 CSS 字句走査 | `postcss.parse()` + 6 条の受理契約 (実測で期待値を確定) | 状態機械・at-keyword 境界・文の位置の定義 |
| 自前の TS/Svelte 文字列抽出 | `svelte/compiler` の AST + `ts.createSourceFile()` | 補間の終端判定・入れ子 template・未終端の扱い・構文エラーの扱い |
| CommonMark の container 文法の列挙と再帰除去 | 契約 A (`>` を剥がして fence なら解析失敗) + 契約 B (タブ禁止 + 4 連続空白禁止) | 引用・リスト・ordered marker の全記法の列挙 |

---

## Round 6 レビューが名指しした「実装フェーズへ渡すために閉じる 5 点」と決着

| # | 閉じる点 | 決着 (いずれも**形式的な契約**として書いた) |
|---|---|---|
| 1 | 通常の文字列から class 候補をどう選ぶか | `isWatchedCandidate()` を純粋関数として定義 (variant 列と `!` を剥がした残りが `WATCHED_UTILITY_PREFIXES` で始まるか)。**監視対象と判定した候補だけ**を候補全体の文字検証へ回す |
| 2 | どの variant 組合せを判定不能にするか | 単位内の**非空 variant 列の集合 `S`** を作り、`\|S\| ≤ 1` は解決可能・`\|S\| ≥ 2` は `variant-composition`。基底は継承元なので `S` に入れない |
| 3 | 診断をどの gate が必ず消費するか | class 走査 → `class-usage.test.ts` / CSS var 走査 → `token-reference-closure.test.ts` / Markdown 走査 → `design-system-docs.test.ts` (docs) と `component-doc-parity.test.ts` (DESIGN.md)。各 gate が `diagnostics.length === 0` を要求する |
| 4 | CSS 値内部の文字列・コメントをどう解析するか | **コメントは postcss が `Decl.value` から既に除いている** (実測)。残る文字列は「引用区間をエスケープ込みで読み飛ばす」値走査の受理契約 6 条で閉じた。新しい依存は足さない |
| 5 | list と blockquote が混在する fence をどう拒否するか | **container 文法を扱わない** — 囲みコードの外の行に fence marker (3 個以上連続) が現れ、正規の top-level fence 行でなければ**診断**にする。`- > \`\`\`` も `> - \`\`\`` も落ちる |

### Round 6 で設計判断そのものが変わった 4 点 (記録)

1. **class 候補は「監視対象か」を先に判定する** — 全文字列を文字検証に掛けると
   import 指定子や URL が `unparsable-token` になり実リポジトリを走査できない。
   逆に文字検証より前に曖昧な除外をすると `bg-primaryあ` を見逃す。順序が本質だった
2. **`variant-composition` の発火条件を集合の濃度で定義した** — 旧文面は
   通常ケースまで該当し、肝心の例では発火しなかった
3. **診断は「積む」だけでなく「誰が消費するか」まで書く** — 積むだけで誰も見ない形は
   共通規約 (d) 違反そのものである。診断ごとに消費先の gate を 1 本ずつ名指しした
4. **fence の判定を「行のどこかに marker が現れたか」へ変えた** — container prefix を
   剥がす方式では list と blockquote の混在に負ける。**marker の出現**で見れば
   container 文法を 1 つも書かずに全形を落とせる

### 呼称の訂正 (Round 6 の Warning)

「非描画領域」→ **「規範判定対象外領域」**。
**HTML コメントは読者に描画されない**が、**囲みコードは描画される**。
両者に共通するのは「規範の本文として数えない」ことだけである。
