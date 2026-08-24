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
