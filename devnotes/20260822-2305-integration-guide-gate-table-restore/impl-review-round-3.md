fence の3経路は閉じています。ただし、同じ根の fail-open が HTML コメント／HTML block に残っています。

### `tests/Architecture/IntegrationGuideGateTableSyncTest.php`

[Critical] HTMLコメント内の見出し・表を本物として受理できます。

現在マスクする非表示領域は fenced code block だけです。例えば次の入力では、表示上は2表ともHTMLコメント内ですが、走査器はアンカーと表を抽出できます。

```markdown
## 2. ドメインモデルの配置

<!--
#### 新規リソースで必ず踏む Architecture ゲート
| ゲート | 説明 | 登録先 |
|---|---|---|
...8行...

#### 条件付きで発火するゲート
| ゲート | 発火条件 | 登録先 |
|---|---|---|
...13行...

## 3. 偽の終端
-->

## 3. 本来の次章
```

`integrationGuideSectionTwo()` はコメント内の偽の `## 3.` で§2を終了し、`integrationGuideAnchorIndex()` と `integrationGuideTableLines()` はコメント内の行を構造行として扱います。したがって件数・実在・一意性がすべて緑でも、描画された§2には索引が存在しません。

docblockの「列0の限定文法だけを受理する」という説明とも一致しません。HTMLコメントを受理対象外にするなら、無視するのではなく例外にする必要があります。CommonMarkにはコメント以外のHTML blockもあるため、部分的な自前追加を続けるより、既存Markdownパーサーを使うか、§2内のHTML block構文を明示的に全面拒否する方が安全です。

少なくとも以下の負例が必要です。

- HTMLコメント内だけに2アンカー・2表・偽の次章を置く
- `<pre>` などのHTML block内にアンカーと表を置く
- 閉じていないHTMLコメント

Round 3で追加された fence の記号種別、実長、後続空白、字下げ拒否の処理自体には新たな問題は見当たりません。

### `docs/template-divergence.md`

[Warning] 上記バイパスが残る間は、D40の「実在・件数・一意性を固定する」という保証が引き続き実態より強い状態です。走査器を閉じれば文言変更は不要です。

### その他の変更ファイル

`docs/app-integration-guide.md`、`LedgerPins.php`、`adoption-debt.tsv` は適合しています。提示された全検証結果も十分ですが、現在のテスト集合は上記HTML構造の反例を含まないため、この問題はgreenのまま残ります。

CHANGES_REQUESTED