# 対応マトリクス: design-review Round 3

Critical 6 件・Warning 10 件 (ラベルの機械カウント `grep -c`。
見出しの手数えと食い違っていたので Round 4 で数え直した)。**すべて対応する** (反論は 0 件)。

## S1

### [Critical] 5 状態字句走査へ置き換える方針と、リスク欄の「本体をそのまま移す / 正規表現を書き換えない」が矛盾

- 判断: **対応する** (Round 2 で本文を直したときにリスク欄を直し忘れた。
  そのまま読むと**誤認する正規表現実装が温存される**)
- 対応内容: リスク欄から「関数の本体をそのまま移す」「正規表現を書き換えない」を**削除**し、
  「**解析実装は 5 状態字句走査へ置換する。既存 8 テストの期待結果は変えない**」へ書き換えた。
  等価性の担保は「期待値を変えない」であって「実装を変えない」ではない、と明記する。

### [Warning] `@theme` / `@utility` の語彙境界が未定義 (`@theme-extra` を拾いうる)

- 判断: **対応する**
- 対応内容: at-keyword として**完全一致**させる (`@` の直後の識別子が `theme` / `utility` で
  終わること。後続が識別子文字なら別の at-rule)。**通常状態かつ文の位置**でだけ解釈する。
  固定検体に `@theme-extra` / 未終端コメント / 未終端文字列を足した (後 2 者は例外 = i20)。

## S2

### [Critical] alpha の単位が型契約に現れていない (百分率と 0..1 が同じ `number`)

- 判断: **対応する** (`10` と `0.10` の取り違えが型で落ちない。二重除算・除算漏れの温床)
- 対応内容: **名前で単位を分ける**。
  - `ClassTokenOccurrence.alphaPercent: number | null` (0..100 の整数)
  - `ScannedPair` の alpha 側も `modifierPercent: number | null`
  - `ResolvedAlphaBackground.effectiveAlpha: number` (0 < x < 1)
  **`alpha` という無単位の名前は使わない**。百分率 → 実効値の変換は
  `resolveAlphaBackground()` **1 か所だけ**に集約する。

### [Warning] `.d.ts` は `.ts` の接尾辞でもあり、S2 の照合順が未定義 (S8 で直した問題が S2 に残る)

- 判断: **対応する**
- 対応内容: **最長接尾辞一致**を S2 の拡張子分類にも適用し、
  `vite-env.d.ts` が走査対象外・通常の `.ts` が対象になる固定検体を足した。

### [Warning] 純粋入口が集約用の `files` / `perDirectory` を返す形は責務が合わない

- 判断: **対応する**
- 対応内容: 型を 2 つに分けた —
  `scanClassUsageSource(source, file): SourceClassUsageScan` (1 本のソースの解析結果) と
  `scanClassUsage(): ClassUsageScan` (実リポジトリの集約結果)。
  `files` / `perDirectory` は**集約側だけ**が持つ。

## S3

### [Warning] `scanCssVarReferences()` の走査根が S2 と S3 で食い違う

- 判断: **対応する**
- 対応内容: `var()` 参照の走査根を **`resources/js` と `resources/css` の 2 本**と明示し、
  **2 根とも存在すること**と**それぞれから 1 件以上抽出できること**を gate が固定する。
  ソース解析の本体は純粋入口 (`scanCssVarReferencesSource`) を共有する
  (class の走査根が `resources/js` だけであることとは別の契約である旨も書く)。

## S5

### [Critical] alpha の二重適用がデータフロー上まだ解消していない (台帳が実効値を持つ)

- 判断: **対応する** (指摘のとおり両方に読める。**最も重い**)
- 対応内容: **台帳は実効値を持たず、class 修飾の百分率だけを持つ**形にした。

  ```ts
  interface AlphaPair {
      readonly fg: CssColorSuffix;
      readonly bg: CssColorSuffix;
      readonly modifierPercent: number | null; // bg-primary-soft は null
  }
  ```

  `bg-primary/10` → 10 / `bg-primary-soft` → null / `bg-primary-soft/40` → 40。
  token 固有 alpha と修飾率を合成して `effectiveAlpha` を作るのは
  **`resolveAlphaBackground()` だけ**である。台帳・走査結果・合成検査が同じ単位になる。

### [Warning] 「全件が台帳に載る」と主張しているのに `AlphaPair` に `file` / `count` が無い

- 判断: **対応する** (主張と型が食い違っていた。正典 i16 は
  「走査で見つかった半透明の組は**全件が台帳に載る**ことを**件数まで含めて**要求する」と定めている
  ので、**使用箇所の全数台帳を持つ側**を選ぶ)
- 対応内容: **2 段にする**。
  - `ALPHA_PAIR_USAGE_LEDGER`: `{ file, fg, bg, modifierPercent, count }` の**全数台帳**。
    走査結果と**完全一致** (集合 + 件数) させる (i16 の「件数まで」)。行番号は持たない (s14)
  - `ALPHA_CONTRAST_PAIRS`: 上記を `(fg, bg, modifierPercent)` へ射影した**一意な意味ペア**。
    AA の `it.each` はこちらを回す
  - 射影が一致することも機械で見る (片方だけを増やせない)

## S6

判定 APPROVE。S5 の alpha 契約確定後に値の緑を判断する旨を注記した。

## S7

判定 APPROVE。変更なし。

## S8

### [Critical] 純粋関数の引数型が実定数の `typeof` なので、固定検体から分類表・申告表を増減できない

- 判断: **対応する** (Round 2 で求められた「任意の分類表を渡す入口」を満たしていなかった)
- 対応内容: **構造型**を定義し、実定数は `satisfies` で適合させる。

  ```ts
  export type ComponentDirClassification = Readonly<Record<string, ComponentDirSpec>>;
  export type ComponentSectionMappings = readonly ComponentSectionMapping[];
  ```

  純粋関数は構造型を受け取る。

### [Critical] `parseDesignComponentSections()` が囲みコード・HTML コメント・重複見出しをどう扱うか未定義

- 判断: **対応する** (囲みコードの中に `### DragHandle` を置いて「文書化済み」に見せられる =
  **中心の保証を直接迂回する**穴だった)
- 対応内容: **S9 の共通 Markdown 行走査 (`scanMarkdownLines`) を共有する**
  (独立した弱い解析器を増やさない = i21)。契約は 5 条:
  1. `## Components` は**ちょうど 1 節**
  2. HTML コメントと囲みコードの中の見出しは**数えない**
  3. `###` だけを対象にし `####` は数えない
  4. 同名の節は**例外**
  5. 未終端の囲みコード・コメントは**解析失敗**
  それぞれ固定検体を置く。

### [Warning] 「直下サブディレクトリと分類表の集合一致」と深さ 2 の `atoms/icons` が字面上矛盾

- 判断: **対応する**
- 対応内容: **2 段に定義**した — (1) 直下では分類表キーの**第 1 要素**の集合と比較する、
  (2) 再帰が終わった後に**実際に使用した完全パス集合**と分類表全体を一致させる。

### [Warning] 既定対応がファイル名だけなので、層をまたぐ同名 component が 1 節へ衝突する

- 判断: **対応する**
- 対応内容: **basename の重複を拒否**する (重複するなら申告表で明示する)。
  `atoms/Foo.svelte` と `molecules/Foo.svelte` の固定検体を足した。

## S9

### [Critical] 「囲みコード外で 4 空白以上」だけでは見逃しが 0 にならない (タブ / blockquote / list の内側)

- 判断: **対応する**。**Codex が提示した案 2 (fail-closed の維持 + 有効字下げ列の計算) を採る**
- 根拠: `>␣␣␣␣本文` は raw 行の先頭が `>` なので 4 空白判定に掛からないが、
  blockquote の中の字下げコードになりうる。タブ 1 文字・空白とタブの混在も 4 列以上になりうる。
  現状の保証文 (「見逃しを 0 にした」) は成立していなかった。
- 対応内容:
  - **タブを禁じる** (囲みコードの外にタブがあれば失敗。列の解釈が環境依存になるため)
  - **container marker (`>` と list marker) を取り除いた後の有効字下げ列**を計算し、
    4 以上なら失敗させる
  - 固定検体: 行頭タブ / 1〜3 空白 + タブ / `>␣␣␣␣text` / 入れ子の blockquote /
    list marker の後の字下げコード / 通常の blockquote・list 本文を**誤検出しない**
  - **CommonMark パーサは導入しない**方針は維持する (依存を増やさない)。
    導入を再検討する契機を docblock に書く

### [Warning] `renderedLines()` と `indentedLineNumbers()` が同じ Markdown を 2 回走査する

- 判断: **対応する** (i21 と同じ理由。2 本あると弱い方が緑を作る)
- 対応内容: **1 回の純粋な行分類**にまとめる。

  ```ts
  export function scanMarkdownLines(source: string): {
      readonly renderedLines: readonly string[];
      readonly forbiddenIndentLines: readonly number[];
  };
  ```

  置き場は `tests/js/styles/markdown-lines.ts` (新設。`*.test.ts` ではないので
  責務境界表の母集団には入らない)。`design-system-docs.test.ts` と
  `design-md.ts` (S8 の節抽出) が**同じ実装**を使う。固定検体は
  `design-system-docs.test.ts` の既存の fixture describe に置く (新しい gate を増やさない)。

## S10 / S11

判定 APPROVE。S5 の単位確定と S9 の字下げ規則に合わせて記述を確定した。

## S12

### [Warning] D50 / D51 の保証文が S5・S8・S9 の未解消点を過大に主張している

- 判断: **対応する**
- 対応内容: S5 (alpha の出所の一意化) / S8 (節が描画される本文にあること) /
  S9 (タブと container を含む字下げの拒否) の契約を確定させたうえで、
  **確定した範囲だけ**を D50 / D51 の「揃え続ける不変条件と保証機構」に書き直した。

## 横断指摘

### [Warning] Round 1 対応記録に現在の S9 と反対の説明が残っている

- 判断: **対応する**
- 対応内容: 「Round 2 レビューの横断評価」の記録へ
  **方針の変遷 (Round 1: 落とさない → Round 2: 書かせない → Round 3: 有効字下げ列で判定)** を
  明記し、Round 1 の記録行に「この方針は Round 2 で変更した」と注記した。
