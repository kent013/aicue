# 対応マトリクス: impl-review Round 2

Codex Round 2 の判定は **CHANGES_REQUESTED**、内訳は **Warning 3 件のみ (Critical 0)**。
**3 件すべて対応**した (反論 0 件)。
Round 1 の Critical 5 件は「4 件が実装で閉じ、置換だけの補間は共通規約 (b) に沿った
保証範囲縮小として受け入れ可能」と明示的に承認された。

## [Warning] token-reference-closure の docblock が保証範囲の縮小と矛盾している

- 判断: 対応する
- 根拠: 「既知の入口は class-usage.ts が deny する」と書くと、`` `${classes}` `` も
  deny されていると読める。実際に deny で 0 件固定しているのは 3 入口だけである。
- 対応内容: 「deny で 0 件に固定しているのは `unsupportedEntryPoints()` が列挙する 3 入口だけ。
  静的部分にテーマ名前空間の語を持たない補間は deny もせず単位も作らない = 非保証」と明記した。

## [Warning] `ComponentFileKindSpec.kind` が意味的な判定に使われていない

- 判断: 対応する
- 根拠: `switch` に入れただけでは実挙動は `requiresSection` と `.types.ts` の直書きが決めており、
  `{ kind: "helper", requiresSection: true }` のような**矛盾した組合せ**が表現できてしまう。
  共通規約 (d) への対応として不十分という指摘は正しい。
- 対応内容: Codex の提案どおり **`kind` を判定の正本**にした —
  `component` は母集団へ追加 / `types` は対の component の存在確認 / `helper` は追加しない /
  default は `never`。**`requiresSection` は削除**し、矛盾した組合せを型と実装の両方から消した。
  `.types.ts` の直書きも `kind === "types"` 起点の照合へ置き換えた。
  「kind が母集団への入れ方を決める」ことの裏取りとして、同じ木で `.ts` の kind を
  `component` へ差し替えると母集団が増える固定検体を追加した。

## [Warning] D55 / D56 の保証本文が実態より広い

- 判断: 対応する
- 対応内容:
  - **D55**: 不変条件の本文へ「**走査器が保証する構文集合 (文字列リテラルの中の class トークン)
    の範囲で**」を 2 箇所に入れ、後段の「保証しないもの」と矛盾しない形にした
  - **D56**: 文書の走査の保証を**対象文書ごとに 2 つへ分けて**書き直した —
    (a) `docs/design-system.md` はタブ・4 連続空白まで全面的に拒否する、
    (b) `DESIGN.md` §Components は Markdown 診断の拒否と「行頭から始まる有効な ATX 見出しだけを
    受理する」ことが保証範囲で、**DESIGN.md 全体のタブ・4 連続空白は拒否しない**
    (frontmatter が 4 空白字下げを使うため)。
    「揃えている不変条件」側にも契約 B の適用対象が `docs/design-system.md` だけである旨を明記した
