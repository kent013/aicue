仮説どおり、実装は詳細設計とほぼ逐語的に一致していますが、同期検査に実際の Markdown 表を素通りさせる fail-open が残っています。

### `docs/app-integration-guide.md`

判定: 設計どおり。

- 挿入位置、8 件／13 件の構成、アンカー名は詳細設計と一致しています。
- §7 を番号ではなく項目名で参照する規約も守られています。
- HTTP、DTO、UI、LLM 経路への変更はありません。

### `tests/Architecture/IntegrationGuideGateTableSyncTest.php`

[Critical] コードブロック内の見出し・表を本物として受理します。

`integrationGuideAnchorIndex()` と `integrationGuideTableLines()` は行を `trim()` してから見出し・表を判定し、Markdown の fenced code block／4 スペース字下げコードを追跡していません。このため、例えば次の内容でも検査を通せます。

```markdown
    #### 新規リソースで必ず踏む Architecture ゲート
    | ゲート | 何を落とすか | 何をどこへ登録するか |
    |---|---|---|
    ...
```

これは表示上はコードであり、§2 の小見出しや表ではありません。同様に fenced code 内の `## 2. `、アンカー、表も候補になります。機能名が約束する「Integration Guide の Gate Table」と実際の走査対象が一致しておらず、docblockにもこの非保証はありません。走査器共通規約 (b) の fail-closed／保証範囲明記にも反します。

Markdown ASTを利用するか、少なくとも fenced・indented code と CommonMark の見出し構文を識別し、曖昧な形は例外にしてください。対応する負例も必要です。

[Critical] 先頭の `|` を省略した有効な GFM 表行を無言で捨てるため、件数・実在・一意性を迂回できます。

有効な8行の直後に次の9行目を置いた場合、

```markdown
`NoSuchGateTest` | 説明 | 登録先
```

GFMでは同じ表の行になり得ますが、`integrationGuideTableLines()` はこれを表外の行として `$closed = true` にするだけです。その後に `|` 始まりの行がなければ例外にもならず、抽出結果は8件のままです。不存在や重複行も同じ方法で検査対象外にできます。

先頭 `|` 必須の独自文法に限定するなら、表開始後、次の見出しまでに現れた非空の非準拠行を無視せず例外にする必要があります。「先頭 `|` がない追加行」の負例も追加してください。

[Warning] 提示された恒久テストには、上記の fenced code、indented code、先頭 `|` 省略行の負例がありません。現在の15負例は個々のセル構造には強い一方、Markdown上の構造境界を裏取りできていません。

PHPStan観点では、offset参照前の件数検査、`preg_match(...) !== 1`、捕獲群の型絞り、失敗値を戻り値へ混ぜない実装は適切です。

### `docs/template-divergence.md`

[Warning] D40の件数、対象パス、再判定条件、採用時債務から離れる際の保証低下は詳細設計と一致しています。ただし「実在・件数・一意性を同期検査が固定する」という記述は、上記のMarkdown構造バイパスが残る限り実態より強い保証です。検査を修正するか、保証範囲を正確に狭める必要があります。

### `tests/Support/TemplateDivergence/LedgerPins.php`

判定: 適合。

- 登録件数 `36 → 37`
- 採用時債務 `171 → 170`

の2点だけが更新され、変更しないとされた定数には触れていません。

### `tests/Support/TemplateDivergence/adoption-debt.tsv`

判定: 適合。

`docs/app-integration-guide.md` の1行だけが削除され、ヘッダや他の債務には変更がありません。D40、明示件数37、固定件数37、および債務170の三点関係も差分上は整合しています。

### 検証状況

[Warning] 施策3適用後の同期・乖離テストは「再実行中」で結果が確定しておらず、必須の全体検証も提示されていません。少なくとも最終の `composer test` と、規約に列挙されたフロント／packages系検証の全greenが確認できるまでは完了扱いにできません。

resources配下の変更は差分に存在せず、DTO／JsonResource、DESIGN.md、Atomic Designの各観点は非該当です。

CHANGES_REQUESTED