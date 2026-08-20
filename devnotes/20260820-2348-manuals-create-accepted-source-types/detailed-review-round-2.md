## 再レビュー結果

Round 1のCritical 2件は適切に解消されています。特に施策5の「実測構文」と「人による供給元宣言」の分離、diagnosticの分離は妥当です。

ただし、施策2のテストが保証できる範囲の説明と、施策5の判定責務・自己検査にまだ不整合があります。

## 施策1: 人間向けラベルの集約

判定: **APPROVE**

順序込みの完全一致へ変更したことで、`acceptAttribute()` の順序依存まで正しく固定されています。

PHPStan、DTO/JsonResource、既存422文言、設定変更時のトリップワイヤの各観点に問題はありません。

## 施策2: `create()` のInertia props追加

判定: **REQUEST_CHANGES**

Inertia props、認可順序、DTOを作らない判断、作成画面と詳細画面で比較する2項目の明示は適切です。

[Warning] 追加したFeatureテストは、`StoreVideoManualRequest` の出力契約を確認できますが、`formatsLabel()` を実際に呼び出していることまでは証明できません。

置換前の三項演算子を残しても、両フラグで同じ文言を返すため、提示されたテストは緑になります。したがって、次の説明は成立しません。

> 片方のFormRequestの置換を忘れても検出する  
> ラベルと422の結線を確認する

修正案は二択です。

- 現実的な案: テストの役割を「両エンドポイントの422出力契約を固定する」と訂正し、中央メソッドへの構造的な結線はコードレビューで確認すると明記する。
- 構造的保証が必須なら、対象2クラスが `AcceptedSourceDocumentTypes::formatsLabel()` を参照することを検査するArchitectureテストを追加する。

今回の規模では前者で十分です。ただし「置換忘れをテストが検出する」という保証は削除してください。

## 施策3: 外部送信案内の共有化

判定: **APPROVE**

親要素の検証が追加され、wrapperによる`gap`の後退を検出できる設計になりました。空白正規化後の全文一致も妥当です。

Atomic Design、DS token、Svelte fragment、既存testidの維持にも問題はありません。画像固有noticeの親要素検証は、フラグtrueのケースで実施すれば十分です。

## 施策4: 作成画面をprops由来へ変更

判定: **APPROVE**

Feature/component/typecheckの保証分担が正確になりました。help全文一致、表示順、form直下の親子構造も必要な後退リスクをカバーしています。

Inertia props、TypeScript型、アクセシビリティの`aria-describedby`、禁止されているdisabled制御の不追加も適切です。

## 施策5: file inputのaccept供給元目録

判定: **REQUEST_CHANGES**

2軸化、実測ASTに基づく分類、`fileInputs`と`diagnostics`の分離、全rationaleの検査、一意性・正整数・件数の検査は適切です。Round 1のCriticalは解消されています。

[Warning] 母集団・diagnosticの判定責務について、gate設計と自己検査計画が矛盾しています。

gateの記述では、次をgate側が直接検査します。

- `nativeInputCount >= 1`
- `fileInputs.length >= 1`
- `diagnostics` が空

一方、自己検査(B)のケース24では、`evaluateFileInputInventory()` が母集団空を違反として返す前提になっています。しかし、同関数の違反一覧には母集団空やdiagnosticが含まれていません。

このままでは次の問題が残ります。

- ケース24をどの関数へ入力するのか不明確
- gateが`diagnostics`を実際に判定へ使うことの負例がない
- scannerがdiagnosticを正しく集めても、gate側が無視する実装ミスを自己検査できない
- 共通規約(d)の「収集結果を判定に使うこと」の裏取りが弱い

修正案:

`evaluateFileInputInventory()`へ以下も集約してください。

- `svelteFileCount` の非空
- `nativeInputCount` の非空
- `fileInputs` の非空
- `diagnostics` の全件違反化
- 目録との比較

そのうえでgateは、実リポジトリを走査して評価関数の結果が空であることだけを検証します。自己検査には次を追加してください。

- `svelteFileCount = 0`
- `nativeInputCount = 0`
- `fileInputs = []`
- `diagnostics` に1件存在する

別関数にしたい場合は、`evaluateFileInputScan()` と `evaluateFileInputInventory()` に分離しても構いません。ただし両方を合成入力で負例検証し、gateが両方の結果を使う構造にしてください。

[Suggestion] `supply` は人による宣言であり、機械的な由来検証ではないという説明は十分です。可能なら `server-prop` は `syntax === "expression"` のみ許可する単純な整合条件を加えると、宣言値も判定に利用できます。ただしこれは必須ではなく、静的な由来証明へ保証を広げないでください。

[Suggestion] 新規ファイル数はまだ誤っています。変更一覧から数えると新規は6件です。

- 共有Svelte: 1件
- 共有componentテスト: 1件
- support: 2件
- architectureテスト: 2件

合計6件です。「新規5ファイル」の記述を訂正してください。

## 横断評価

- Critical: 解消済み
- PHPStan level 10: 問題なし
- DTO/JsonResourceとInertiaの使い分け: 適切
- RefreshDatabase/Factory方針: 適切
- 認可・テナント境界: 既存順序を維持
- DESIGN.md/Atomic Design: 適合
- 残る修正: テスト保証範囲の正確化と、施策5の評価責務の一本化

## 全体判定

**CHANGES_REQUESTED**

施策1・3・4は承認です。施策2のFeatureテストが構造的結線まで保証するという記述を訂正し、施策5の母集団・diagnostic判定を自己検査可能な評価関数へ集約すれば、承認可能な状態です。