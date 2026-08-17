### `tests/js/styles/design-system-docs.test.ts` — 要修正

- [Critical] 4空白の偽終端は修正されていますが、CommonMark の fence 判定はまだ不完全です。バッククォート fence の info string にはバッククォートを含められませんが、`FENCE_OPEN` は後続を検査しないため、` ```foo`bar ` のような無効な開始行を fence と誤認します。その後の本物の開始 fence を終了と誤認し、本来 fenced code 内にある後続行を検査へ露出させられます。これは再び fail-open です。

  ```markdown
  ```foo`bar
  ```
  ## 検査の責務境界
  この表は機械で実体と突き合わせている
  ```
  ```

  バッククォート fence だけは後続の info string にバッククォートがないことを確認し、この配置を負の fixture に追加する必要があります。

- [Critical] 行内コード文脈の見送りは、`SECTION_CONTRACT_PHRASES` 導入後は妥当ではありません。行内コード内の `<!-- … -->` は読者には表示されますが、現在の正規化では除去され、その前後が連結されます。たとえば次は、表示上は最小断片と一致しないのに検査上は一致します。

  ```markdown
  `DESIGN.md が唯一<!-- visible marker -->の真実`
  ```

  正規化後は `` `DESIGN.md が唯一の真実` `` となり、`toContain()` が通ります。したがって「誤って潰すだけで fail-open ではない」という判断は成立しません。完全な Markdown パーサを追加しなくても、行内コード内にコメント記号を検出した場合は明示的に fail させる方法で塞げます。

- [Warning] 最小断片の導入、`REQUIRED_SECTIONS` との集合一致、非描画領域への退避 fixture は、Round 1 の「非空しか見ない」という指摘への方向性として妥当です。ただし上記2経路により、現時点ではまだ保証を回避できます。

- TypeScriptについては、`any`、非null断定、型を黙らせるキャストは見当たりません。

### `tests/js/styles/tokens.test.ts` — 一部要修正

- Round 1 の [Critical]「条件付き at-rule 内の `@layer theme` を採る」は解消しています。`root.nodes` の直接走査と負の fixture が対応しており、走査範囲も設計意図と一致します。

- Round 1 の [Warning]「条件付き at-rule 内にしかない通常 utility」は解消しています。`hasConditionalAncestor()` により、通常 utility を不成立条件下から拾う経路は閉じています。

- [Warning] hover 条件の修正は不完全です。`CollectedDeclarations.conditions` が at-rule 名を捨てて `params` だけを保存するため、次の両方が同じ `"(hover: hover)"` として許可されます。

  ```css
  @media (hover: hover) { ... }       /* 意図した条件 */
  @supports (hover: hover) { ... }    /* 成立しない可能性がある別の条件 */
  ```

  `ALLOWED_HOVER_CONDITIONS` は `{ name: "media", params: "(hover: hover)" }` のように at-rule 名と条件文の組で照合すべきです。負の fixture に `@supports (hover: hover)` を加えるとこの穴を固定できます。

- `:root`／`:host` 単独を受理する判断自体は妥当です。アプリの不変条件を「無条件でテーマ変数が到達すること」と置くなら、vendor の複合 selector の綴りを完全一致で pin する必要はありません。ただし、コメントやD27の再判定条件に `:root, :host` の形を固定するような記述が残るなら、「両方の完全一致は要求しない」と同期させるべきです。

### `docs/design-system.md` — 要修正

最小断片までしか保証しないこと、周辺説明の骨抜きを検出しないことは明確になりました。一方、「Markdown の規則に合わせてある」「描画されない領域へ移すと赤になる」という記述は、無効なバッククォート info string と行内コード内コメントの回避経路が残るため、現状では保証過大です。

### `docs/template-divergence.md` D27 — 条件付き要修正

構造検査と最小断片を分けた記述は改善されています。ただし「描画されない領域を先に除く」という保証は、上記 Markdown 解析の2経路を修正して初めて成立します。

### 対応マトリクスの判定

- Critical 1（4空白の偽終端）: 直接の故障は解消。ただし別の CommonMark fence 誤認による同種の fail-open が残る
- Critical 2（本文改変）: 最小断片方式は妥当。ただし行内コード内コメントで回避可能
- Critical 3（theme layer の走査範囲）: 解消
- Warning（singleton selector）: 見送りは妥当。文書の保証範囲だけ同期が必要
- Warning（条件付き通常 utility）: 解消
- Warning（hover 条件）: 部分解消。at-rule 名を捨てるため回避可能
- Warning（行内コード）: 見送りは不適切。最小断片検査との組み合わせで fail-open になる
- Warning（文書の保証過大）: 改善したが、残存する解析穴のため完全には解消していない
- 負のコントロール: 大幅に改善。上記2つの Markdown 境界例と `@supports (hover: hover)` が不足
- 検証レーン: 全レーン完了を確認。提示結果上は問題なし

CHANGES_REQUESTED