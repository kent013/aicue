## 全体判定: CHANGES_REQUESTED

主導線の SOP 入力を既存の受理形式・開示方針へ揃える目的は、North Star に直接整合しています。特に OCR 有効時に写真・スキャン SOP を新規作成導線から投入できない不整合の回収は妥当です。

ただし、再発防止 gate (D) は現状の概念では「props が真に単一情報源から来ている」ことを保証できません。ここを明確化してから進めるべきです。

### 1. 使命との整合性

[Suggestion] 承認可能です。既存 SOP、特に紙・写真しかない現場の SOP を起点にできる入口を主導線へ戻すため、「現場に既にある作業手順書を起点に」に本質的に寄与します。

[Suggestion] OCR を既定有効化せず、既存フラグが有効な環境だけで UI を追随させる点も、v1 スコープと段階的 rollout に適合します。

### 2. 禁止事項違反

[Suggestion] 設計上、禁止事項への抵触は見当たりません。LLM 呼び出しや prompt の追加はなく、`response()->json()`、disabled UI、破壊的 DB 操作も含みません。

[Warning] 外部送信の開示を共有コンポーネントへ移す際、既存の `data-testid` と表示条件を実質変更しないことが必要です。

修正提案: `SourceDocumentUpload.svelte` 側の既存 testid、文言、表示順を維持する回帰テストを追加し、共有化が UI 仕様変更ではないことを固定してください。

### 3. 実現可能性

[Critical] (D) の「accept の供給元を目録で宣言させる gate」は、素朴な Svelte テキスト走査では実現しても、目的を保証できません。`accept={sourceDocumentAccept}` を検出しても、その変数が Controller の `AcceptedSourceDocumentTypes` 由来であることまでは証明できず、変数名だけ合わせた別値を見逃します。

修正提案: gate の責務を「全 file input の分類漏れを防ぐ目録」に限定してください。SOP の値が単一情報源由来であることは、PHP Feature テストで `create` と `show` の Inertia props を `AcceptedSourceDocumentTypes` の出力と比較し、JS テストでその props が `accept` と表示条件に使われることを検証する、二層の契約に分けるべきです。

[Warning] Svelte の `type="file"` と `accept` は静的な一形態に限られません。属性順、引用符、Svelte 式、spread 属性、条件レンダー等をどう扱うか未定義のまま全数 gate を作ると、検出漏れまたは誤検出を招きます。

修正提案: Svelte コンパイラまたは既存パーサーを使う対象構文を決め、解決不能な file input は必ず失敗させてください。走査対象・非保証範囲・空母集団の扱いを docblock に明記し、負例・正例・fail-closed 分岐をテストで固定してください。

### 4. 期待効果の妥当性

[Suggestion] OCR 有効時に OS の選択画面から画像 SOP を選べるようになる効果、入力前案内と 422 文言の不整合をなくす効果は合理的です。

[Warning] `AcceptedSourceDocumentTypes` にラベルメソッドを増やすだけでは、設定上の拡張子集合とラベルが将来乖離する余地があります。現在の `source_document_mimes` が変更されても、「PDF・Excel・テキスト」のラベルが残る可能性があります。

修正提案: 受理形式を固定されたドメイン定義として扱うなら、拡張子・MIME・accept・表示ラベルを同じ定義から導出してください。設定を唯一の拡張子定義として維持するなら、少なくとも両フラグ状態で extensions、accept、ラベル、FormRequest メッセージが一致するテストを追加してください。

### 5. リスク

[Warning] `accept` はファイル選択 UI のフィルタであり、実際の受理判定ではありません。この点の認識は設計に明記されていますが、画像導線が開くことでアップロード試行数と外部送信対象は増えます。

修正提案: 既存の FormRequest と内容 sniff の二段防御を変更しないことをテストで確認し、一般案内・OCR 固有警告をファイル選択前に見える位置へ置いてください。

[Suggestion] 文言を一箇所へ集約する判断は、法務確認済みの開示の片更新を防ぐため適切です。

### 6. スコープの適切さ

[Warning] A〜C は適切に限定されていますが、D は小さな不整合修正に対して横断的な Svelte 静的検査を新設するため、実装・維持コストが相対的に大きいです。

修正提案: D を上記のとおり「全 file input の明示的分類漏れ検出」に縮小し、SOP の単一情報源保証は Feature/JS 契約テストで担保してください。撮影用アップロードを変更せず、理由付き目録に載せる方針は妥当です。

### 7. 型安全性

[Suggestion] Controller の Inertia props と `Create.svelte` の TypeScript Props を同時に追加する方針は、Laravel 12 + Svelte 5 + Inertia の構成に適合します。

[Warning] 文字列ラベル、accept 文字列、画像対応フラグを個別 props として増やすと、将来の props 追加漏れが起きえます。

修正提案: 少なくとも `create()` / `show()` の props を Feature テストで同じ `AcceptedSourceDocumentTypes` 出力と比較してください。PHP 側は `string` / `bool` を明示的に返す final クラスのメソッドとして保ち、FormRequest の `messages(): array` でその値を利用すれば、PHPStan level 10 に無理なく適合できます。