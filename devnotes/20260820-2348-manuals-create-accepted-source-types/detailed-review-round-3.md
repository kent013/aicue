## Round 3 再レビュー結果

Round 2の指摘自体は適切に反映されています。ただし、施策2に古い保証表現が1か所残り、施策5にはSvelte内でnative inputを生成できる未分類構文があります。

## 施策1

判定: **APPROVE**

変更ありません。順序込みpin、型安全性、既存422契約とも妥当です。

## 施策2

判定: **REQUEST_CHANGES**

Featureテストの保証範囲を「422出力契約」に限定した判断は適切です。

[Warning] 古い「構造的な結線を確認する」という説明が、公開面の一貫性テストの箇条書きに残っています。

> 422 文言が `AcceptedSourceDocumentTypes::formatsLabel()` から組まれた完全一致の文であること  
> （施策1のラベルと422の結線）

これは直後の「実際に呼んでいることは保証しない」という説明と矛盾します。

修正案:

「両経路の422文言が、現在の中央ラベルと同じ出力契約を満たすこと」などへ変更してください。「結線」という語は構造的な呼び出し保証に読めるため削除するのが安全です。

実装・テスト内容そのものに追加変更は不要です。

## 施策3

判定: **APPROVE**

共有化、全文一致、表示順、form直下の構造検査を含めて妥当です。

## 施策4

判定: **APPROVE**

propsの保証分担、help全文一致、DESIGN.md、Atomic Designの各観点に問題ありません。新規ファイル数も6件へ正しく訂正されています。

## 施策5

判定: **REQUEST_CHANGES**

判定責務の一本化と自己検査ケース24〜28の追加は適切です。Round 2のWarningは解消されています。

[Critical] 「`.svelte`内のnative input全数」という母集団の主張に対して、native inputを動的に生成できるSvelte構文が未分類です。

少なくとも次の構文があります。

- `<svelte:element this={tag}>`: 実行時に`tag === "input"`となり得る
- `{@html ...}`: HTML文字列に`<input type="file">`を含められる

現在の設計では、どちらも`RegularElement`としての`input`に現れず、diagnosticにもならないため、file inputを目録外で追加してもgateが緑になり得ます。「`.svelte`以外」は保証外に明記されていますが、これらは`.svelte`内です。

AGENTS.mdの共通規約(b)上、保証範囲内で解決できない構文を無言で候補から外すことはできません。

修正案:

- `svelte/compiler`で両構文のmodern AST形状を実測する。
- 動的elementには `unresolved-native-element`、`{@html}`には `opaque-html` などのdiagnosticを追加する。
- 走査対象内で検出した場合はfail-closedとする。
- 自己検査へ最低限、次の負例を追加する。
  - `<svelte:element this={tag} />`
  - `{@html markup}`
- docblockの保証しないものには、ASTとして認識できない別の構文がある場合だけ記載する。保護対象のinputを生成できる既知構文は、保証外へ追い出すよりdiagnosticで止める方が本gateの目的に整合します。

`<svelte:element this="div">`のように静的に非inputと確定できる形を除外するか、すべて診断にするかは実測後に決められます。まず未知・動的な形を非input扱いしないことが必要です。

## 全体判定

**CHANGES_REQUESTED**

施策1・3・4は承認です。施策2は残った保証表現の訂正のみです。施策5は、`.svelte`内でnative inputを生成できる動的elementとraw HTMLをfail-closedの診断対象へ加える必要があります。