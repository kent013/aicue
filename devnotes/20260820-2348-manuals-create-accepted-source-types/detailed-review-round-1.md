## レビュー前提

仮説は「施策1〜4の方向性は妥当だが、施策5の静的検査は現在の分類モデルと実コードが矛盾しており、そのままでは正しく実装できない」です。

成功条件は、サーバの受理形式・Inertia props・表示・`accept` が同じ情報源へ結線され、静的検査が宣言した保証範囲を実際のテストで裏取りできることです。以下は提供された本文のみを対象にしたレビューです。

## 施策1: 人間向けラベルの集約

判定: **APPROVE**

`formatsLabel(): string` への集約は、機械的な拡張子集合と法務確認済み文面を混同しない設計になっています。両フラグの完全一致に加え、基底・画像拡張子集合を前提として pin する方針も妥当です。

DTO/JsonResource、PHPStan、null安全性、既存422文言への後退リスクにも問題は見当たりません。

[Suggestion] 前提 pin では、配列差分だけでなく並び順も契約なら完全一致で検証してください。`acceptAttribute()` が順序依存であるため、集合だけの比較では表示順の変更を見逃します。既存の `extensions()` 完全一致テストが残るなら、それを前提 pin の根拠として明記すれば十分です。

## 施策2: `create()` のInertia props追加

判定: **REQUEST_CHANGES**

Inertia propsとして短いスカラー3件を直接渡す判断は妥当です。DTOを導入しない理由も、既存の平坦な契約と「今必要なものだけ作る」に整合しています。テナント境界404→認可403の順序も維持されています。

[Warning] `formatsLabel()` を利用する2つのFormRequestのうち、テスト計画が実質的に後付けアップロード側へ偏っています。

`StoreVideoManualRequest` が中央ラベルへ正しく結線されたことは、`formatsLabel()` のUnitテストや後付けアップロードの422テストだけでは証明できません。実装時に片方の置換を忘れても緑になる可能性があります。

修正案:

- `projects.manuals.store` に非対応ファイルを送るFeatureテストを追加する。
- フラグfalseではJPEG等の拒否文言、フラグtrueではHEIC等の拒否文言を完全一致で検証する。
- 後付け経路と同時作成経路の両方について、期待文を `AcceptedSourceDocumentTypes::formatsLabel()` から組み立てて結線を確認する。
- 有効な `title` 等を渡し、`StoreVideoManualRequest` の `document.mimes` だけを確実に発火させる。

なお「作成画面と詳細画面のpropsが同値」は、両面に存在する2件だけが対象です。`sourceDocumentFormatsLabel` は詳細画面に存在しないため、テスト名やコメントで対象を明示してください。

## 施策3: 外部送信案内の共有化

判定: **REQUEST_CHANGES**

`features/manual` 内への共有化、pagesからfeaturesへの依存方向、既存DSクラスの移動はいずれも妥当です。SVG、色、radius、typography tokenの追加もなく、DESIGN.md上の問題はありません。

[Warning] レイアウト維持の根拠として「2つの`<p>`がform直下に残る」と説明していますが、テスト計画にあるのは主に表示順であり、親子構造の検証が明示されていません。

順序テストだけでは、共有コンポーネントにwrapperが追加されても緑のままです。その場合、`flex`の`gap`適用単位が変わり、設計が懸念しているレイアウト後退を検出できません。

修正案:

- `SourceDocumentUpload.test.ts` で両notice要素の `parentElement` が `source-document-upload` のformであることを検証する。
- 作成画面側でも、一般案内が作成form直下かつfile inputを含む`FormField`より前にあることを検証する。
- またはwrapperを許容する設計へ変更し、そのwrapper自身に必要なspacingを明示してテストする。

完全一致の文言テストでは、Svelteソースの改行・インデントによる空白差を避けるため、DOMの空白を正規化したうえで全文比較する方法を明記すると安定します。

## 施策4: 作成画面をprops由来へ変更

判定: **APPROVE**

3つの必須props、`accept={sourceDocumentAccept}`、画像対応可否を別boolで受ける設計は適切です。`accept`文字列から画像対応を逆算しない点も、確定済みの概念設計に従っています。

外部送信案内を入力前に配置し、ボタンをdisabledにせずサーバ422を正本とする点も要件に合っています。DS token、Atomic Design、Lucideの各規約にも違反はありません。

[Suggestion] `pnpm typecheck` はPHP側のInertia prop名とSvelteの`Props`が一致することまでは検証しません。保証の分担は次のように記述するのが正確です。

- Featureテスト: Controllerが正しい名前・値のpropsを返す
- component/pageテスト: 渡されたpropsを表示と`accept`へ正しく使う
- typecheck: Svelte内およびテスト呼び出し側の型整合性

help文言も、ラベルの部分一致だけでなく後半・句点を含む全文一致を1ケース置くと、現行文を維持するという設計意図を直接固定できます。

## 施策5: file inputのaccept供給元目録

判定: **REQUEST_CHANGES**

[Critical] 初期目録の分類と走査ルールが実コードに一致していません。

- `TakeFileUpload.svelte` の `accept={isStill ? "image/*" : "video/*"}` は、定義上 `dynamic` です。`client-literal` にはなりません。
- `CaptureFileFallback.svelte` の `{accept}` は属性shorthandです。shorthandを未解決とするなら `unresolved`、式として扱うなら `dynamic` であり、どちらにしても `client-literal` ではありません。

したがって、記載された4件の初期目録ではgateを緑にできません。

修正案として、構文分類と供給元宣言を分離してください。例えば次の2軸です。

- 実測構文: `static-text | expression`
- 目録上の供給元: `server-prop | client-owned`

SOPの2件は `expression + server-prop`、撮影テイクの2件は `expression + client-owned` になります。静的検査は前者の「構文」だけを検証し、「供給元」は理由付きのレビュー宣言であり由来を証明しない、と明示すれば概念設計の保証範囲とも一致します。

`{accept}` は実コードで使われているため、これを `expression` として受理する正例も自己検査へ必ず追加してください。`{type}` はfileか判断できないため未解決とする負例が必要です。

[Critical] `parse-failed` を現在の `FileInputClassification` に入れる型設計は成立しません。

parseに失敗したファイルではinputを列挙できないため、1始まりの `occurrence` を決定できません。また、動的typeも「file inputの序数」を定義できない状態です。

修正案:

- 正常に分類できたfile inputの一覧と、走査上の問題を別の配列へ分離する。
- parse失敗は `{ file, reason: "parse-failed" }` というファイル単位のdiagnosticにする。
- 動的typeやspreadはnative inputの序数、またはAST位置を持つdiagnosticにする。
- 目録の `occurrence` は「静的にfileと確定したinputの序数」など、曖昧さのない定義に限定する。

[Warning] gate自身の判定ロジックに対する自動の負例が不足しています。

11件の合成入力は主にscannerの検出力を確認しますが、次のgate分岐が壊れても実リポジトリが偶然適合していれば緑になります。

- 目録の不足・余剰
- kind不一致
- 重複キー
- rationale不足
- 件数pin不一致
- native inputまたはfile input母集団が空

修正案:

比較・検証部分をテスト可能な関数へ分離し、少なくとも上記各ケースの負例と正常例を追加してください。これはAGENTS.mdの走査器・gate共通規約(c)(d)に必要です。

[Warning] rationaleの長さ検査が `client-literal` だけでは、静的に由来を証明できない `dynamic` の登録を空理由で通せます。

修正案:

- すべての目録エントリに30文字以上の理由を必須化する。
- `file + occurrence` の一意性、`occurrence` が正整数であることも検証する。
- `FILE_INPUT_COUNT` を残すなら、実測件数だけでなく目録配列長・一意キー数とも一致させる。

[Suggestion] 実装モードの「新規3ファイル」は一覧と一致しません。実際には、共有Svelte 1件、support 2件、architecture test 2件の合計5件です。競合範囲とレビュー範囲を誤らせないよう訂正してください。

施策5は、設計自身が認めているとおり、上記を解消できなければこのPRから外す判断が適切です。現在案を単純な文字列検索へ落として実装するべきではありません。

## 横断評価

- DTO/JsonResourceとInertiaの使い分け: 適切
- PHPStan level 10: 提示されたPHP変更に型上の問題なし
- 認可・テナント境界・入力検証: 既存境界を維持しており問題なし
- OWASP/XSS: propsはSvelteのテキスト・属性として扱われ、新しい生HTML経路なし
- DESIGN.md: 既存token classのみで問題なし
- Atomic Design: `pages → features/manual`、同一domain内features参照とも許容範囲
- 最大の後退リスク: 施策5の分類不整合と、両FormRequestの結線テスト不足

## 全体判定

**CHANGES_REQUESTED**

施策1・4は承認可能です。施策2・3のテスト契約を補強し、施策5は構文分類と供給元宣言を分離したうえで、diagnosticの型とgate自身の負例を再設計する必要があります。