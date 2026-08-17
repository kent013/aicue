# 全体判定: CHANGES_REQUESTED

Round 1 の主要部分は適切に修正されています。特にテーマ変数のスコープ限定、R2 の訂正、母集団の集合一致、文書目録の双方向検査は妥当です。

ただし、同名 hover 規則の扱いがまだ未解決で、Round 1 の Critical が一部残っています。

## 施策別判定

- 施策1: APPROVE
- 施策2: APPROVE
- 施策3: REQUEST_CHANGES
- 施策4: APPROVE
- 施策5: APPROVE
- 施策6: APPROVE
- 施策7: REQUEST_CHANGES

## 残る指摘

### 施策3

[Critical] `hoverDeclarations()` は同一 selector の複数出現をまだ混ぜます。

通常 utility は `soleRule()` により0件・重複を検出しますが、hover は次の状態です。

- 外側の `.hover\:bg-*` が複数あっても落ちない
- `&:hover` が複数あっても落ちない
- 値が競合しても後勝ちで通り得る
- `nested.walkDecls()` が `&:hover` 配下の別の入れ子規則まで走査する

これは必須論点である「同名 selector が複数回現れる場合」への対応として不十分です。

修正案:

1. 外側の selector を出現ごとに取得し、ちょうど1件と確認する。
2. その直接の子にある `&:hover` 規則を取得し、ちょうど1件と確認する。
3. `&:hover` の直接宣言と、直接・間接の `@media` 配下の宣言だけを収集する。
4. 子孫に別の Rule があっても、その宣言は収集しない。
5. 同一プロパティに異なる値があれば競合として落とす。

`@media (hover: hover)` の具体的な条件を固定しない方針は問題ありません。

[Warning] 「負のコントロール: テーマ変数の収集が `@layer theme` の外を拾わない」は、実際には負のコントロールになっていません。現在のテストは対象 theme 規則が1件以上あることだけを確認しており、`themeVariables()` を将来全走査へ戻しても赤にならない可能性があります。

修正案: 小さな `postcss.parse()` fixture を恒久テストとして追加してください。例えば次を同居させます。

- theme 層の正しい `--test-token`
- theme 層外の同名・異値宣言
- `@media` 内の同名宣言
- theme 層内の競合宣言

その上で、層外と `@media` が無視され、競合だけが `conflicts` に入ることを直接確認します。同様に `utilityRules()` の「直接宣言のみ」と hover の `&:focus` 除外も fixture で固定すると、ヘルパの仕様が後退しません。

[Warning] `conflicts` の空確認が sealed 側だけです。実 app.css 側で、誤った値の後に正しい値が再宣言された場合、F は後勝ちの正しい値を見て通ります。

修正案: F にも次を追加してください。

```ts
expect(themeVariables(routed).conflicts).toEqual([]);
```

[Warning] R6 の「ダミー `.test.ts`」は、空ファイルだと Vitest 自体が「テストなし」で失敗し、目当ての集合一致を確認できない可能性があります。

修正案: R6 は、常に成功する最小の `it()` を持つ有効なテストファイルを一時追加すると明記してください。記録では `design-system-docs` の集合一致 assertion が実際に落ちたことを確認します。

### 施策7

[Warning] D27 の「対応する utility 名がその変数へ解決する」は typography には正確に当てはまりません。

色と角丸は CSS 変数を参照しますが、typography ramp は `font-size`、`font-weight`、`line-height` などを literal で出し、変数参照は主に `font-family` です。

修正案: メタ表と引用部分を、例えば次のように変更してください。

> inventory に登録された DESIGN.md 対応の色・角丸・文字組が生成 CSS に期待する値で現れ、色・角丸 utility は対応する変数を参照し、typography utility は期待する宣言を過不足なく持つこと。

この修正により、施策3の実際の assertion と D27 の保証表現が一致します。

## D27 書式判定

D27 の形式自体は APPROVE です。

- メタ行は9行
- 件数は25件から26件への増加
- D27 の採番は妥当
- 日付 `2026-08-17` は UTC 基準で妥当
- 決めた人・状態・見直し期限・根拠は値域内
- 対象パスの形式も適合

上記の施策3と保証表現を直せば、全体を承認できます。