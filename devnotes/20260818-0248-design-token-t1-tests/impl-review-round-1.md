レビュー仮説は「構文木・Markdown の走査が、条件付き／非描画領域を誤って有効とみなさないこと」です。この観点で、検出を回避できる経路が残っています。

### `tests/js/styles/design-system-docs.test.ts` — 要修正

- [Critical] fenced code の終端判定が CommonMark と一致しません。`raw.trim()` により、4 空白以上インデントされた `` ``` `` も閉じ fence と判定します。CommonMark では閉じ fence のインデントは最大 3 空白なので、次のような実際には fenced code 内の見出し・表が検査対象として露出します。

  ```markdown
  ```text
      ```
  ## 検査の責務境界
  | `tests/js/styles/...` | ... |
  ```
  ```

  これは追補が塞ごうとしている「コードブロックへの退避」をそのまま回避できる fail-open です。開始側も `trim()` により4空白以上を fence と誤認します。少なくとも、最大3空白、同一記号、開始以上の長さ、閉じ側は後続が空白のみ、という条件を原文のインデントを保持して判定し、4空白の偽終端を負の fixture に加える必要があります。

- [Critical] 家系の判定基準にある「同期契約の本文が改変されていないか」は保証していません。`REQUIRED_SECTIONS` が確認するのは節内に可視な非空行が1行以上あることだけです。チェックリストや契約本文を削除・変更して無関係な一文だけ残しても緑です。ファイル冒頭でも「散文を見ない」と明記されており、追補が引用する判定基準との明確な不一致です。守るべき契約要素を構造化して検査するか、固定すべき最小限の契約文を pin する必要があります。

- [Warning] HTML コメント除去はインラインコードの文脈を認識しません。たとえば `` `<!-- example -->` `` は読者に表示されますが除去されます。「HTML コメントを除く」という保証を正確にするなら Markdown パーサ利用が安全です。自前走査を維持する場合は、保証をさらに限定し fixture に境界例を追加すべきです。

### `tests/js/styles/tokens.test.ts` — 要修正

- [Critical] `themeVariables()` が `root.walkAtRules("layer", ...)` を使うため、ルート直下でない `@layer theme` も採取します。たとえば不成立の `@supports` や `@media` の中にあるテーマ層でも A/F が通り得ます。変数は生成 CSS 中に存在しても実際には適用されません。ルートの直接の子である `@layer theme` に限定するか、少なくとも祖先に条件付き at-rule がないことを固定してください。対応する負の fixture も必要です。

- [Warning] `selectors.every(...)` は `:root` 単独や `:host` 単独も受理します。設計と D27 が固定対象として記述する `:root, :host` の形より検査が緩く、`:host` が消える構造変更を検出しません。

- [Warning] `rulesWithSelector()` は構文木全体を走査し、`collectDeclarations()` は任意の at-rule 配下を再帰します。そのため、utility が不成立の `@supports` 内にしかない場合や、hover 宣言が `@media (hover: none)` 内に移った場合でも通り得ます。「hover 時の背景色になる」というテスト名・文書上の保証より実際の検査範囲が弱くなっています。

- TypeScript面では、提示差分に `any`、非null断定、型を黙らせるキャストは見当たりません。

### `tests/js/styles/canonical-source-parity.test.ts` — 問題なし

母集団の非空確認、集合一致、pending 追跡先の実在確認はいずれも設計どおりです。型の絞り込みにも問題は見当たりません。

### `tests/js/styles/design-md.ts` — 問題なし

正規表現パーサの限界がコメントで明示され、集合一致側で空振りを検出する構成です。提示された frontmatter 形式に対して設計どおりです。

### `tests/js/styles/inventory.ts` — 問題なし

判別可能 union、派生値免除の理由、経路層のアンカーと全件検査の区別は明確です。トークン値や hex の追加もありません。

### `docs/design-system.md` — 要修正

- [Warning] 「契約の本文をそこへ移しても『節はあるし本文も空でない』状態にはならない」は保証過大です。別の可視行を1行残せば、契約本体を fenced code／コメントへ移しても節は非空になります。また fenced code 終端の fail-open により、現状は完全退避でも通る構成を作れます。

それ以外の4検査の責務分離、`spacing` が未検査であること、Vite以降を保証しないことは実装と一致しています。

### `docs/template-divergence.md` — 条件付き要修正

D27 は、派生色の値、font fallback、Vite以降、文書の意味を保証しない点を正しく限定しています。ただし「描画されない領域を先に除く」という記述は、現在の fence 終端判定では成立しません。実装修正後に記述と一致します。

### `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` — 問題なし

登録簿と同時に件数 pin を26へ更新しており、必要な同期変更です。

### `devnotes/.../implementation-addendum.md` — 要修正

- [Warning] 「描画されない場所への退避を塞いだ」という結論は、fence の4空白偽終端で回避可能なため現状では成立しません。
- [Warning] 引用した家系基準は「本文の改変」も対象ですが、実装は本文の意味・契約要素を検査していません。採用しない範囲なら、その判断を追補とD27へ明示する必要があります。

### `devnotes/.../red-verification.md` — 追加確認が必要

R1〜R6の感度確認は設計された故障には十分反応しています。ただし今回の主要な穴である次の負のコントロールがありません。

- 4空白でインデントされた偽の fence 終端
- 条件付き at-rule 内の `@layer theme`
- `:root` または `:host` 単独
- 不成立条件内にしか存在しない utility

### 検証記録 — 未完了

`composer test`、`pnpm test`、`pnpm test:packages` が「実行中」のため、AGENTS.md と Definition of Done が求める全 green はまだ確認できていません。一時スクリプトによる ledger 検査は有用ですが、正式レーンの代替にはなりません。

CHANGES_REQUESTED