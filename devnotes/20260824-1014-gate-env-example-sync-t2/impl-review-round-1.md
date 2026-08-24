静的レビューのみ実施しました。ツール制限に従い、記載されたテスト結果は再実行していません。

### [EnvExampleInvariantTest.php](/workspace/.claude/worktrees/tasks/T256/tests/Architecture/EnvExampleInvariantTest.php)

判定: 要修正

Warning — 台帳の負のコントロールが、宣言する検出力を完全には裏取りできていません。

- 規則1の「キー集合が完全一致」は片方向ずつしか検証されていません。
  - V12: `kinds` の余分なキー
  - V13: `classifications` の不足キー
  - `kinds` の不足と `classifications` の余分なキーが未検証です。
  - 例えば各条件が片方向の `array_diff()` に弱体化しても、V1〜V21がすべて通る実装を作れます。
- 規則4はV3の「種別をまたいだ重複」だけです。同一種別内の重複を検出する負例がありません。旧方式のように「値固定と必須キーの交差だけを見る」実装へ退行してもV3は通るため、「台帳全体で一意」の検出力を裏取りできません。

同一種別重複、`kinds` の不足、`classifications` の余分なキーを、それぞれ固有メッセージで確認する負例として追加してください。

Warning — `$withEntryField` の型契約は、将来testsをPHPStan level 10へ編入できる形ではありません。

`$field` が任意の `string`、`$value` が `?string` なので、宣言上は未知のフィールド追加や、`key`・`kind`・`origin`への`null`代入が可能です。それにもかかわらず、戻り値を固定shapeとして宣言しています。現在はtestsが解析対象外なので通りますが、詳細設計の「将来の編入に耐える型注記」と一致しません。完全なentryを受け取って置換するヘルパーなど、型契約上もshapeを維持する形が適切です。

Suggestion — `envExampleCounterexampleIds()`の結果を「集合」と説明していますが、実装は順序込みの`toBe()`です。順序も不変条件ならdocblockへ明記し、集合だけを固定するなら比較前に双方をソートすると意図が一致します。

そのほかの確認結果:

- 行分類は「空白→コメント→UTF-8→禁止文字→代入」の正しい順序です。
- C1判定はUTF-8妥当性確認後に実行されており、`\xC2[\x80-\x9F]`も正しいです。
- 提示差分内の`preg_split()`・`preg_match()`は、失敗を不一致へ畳まず例外にしています。
- R17〜R29はC0、TAB、VT、FF、DEL、C1、不正UTF-8と正例を適切に扱っています。R18が旧実装でも緑だった理由も証跡と整合します。
- `APP_ENV`は必須キー側から削除され、`local`の値固定へ移送されています。
- 旧APIの`envExampleValuePinEntries()`と`envExampleRequiredKeys()`は差分上削除されています。
- M3の実パス＋ファイル名による二段検査はfail-closedです。

### [template-divergence.md](/workspace/.claude/worktrees/tasks/T256/docs/template-divergence.md)

判定: 問題なし

Critical / Warning / Suggestion: なし。

D50が先行変更で使用済みになったことを受け、D51へ再採番した説明は妥当です。登録件数48、状態「監視中」、期限、対象パス、債務から逸脱登録へ移す理由も整合しています。

### [LedgerPins.php](/workspace/.claude/worktrees/tasks/T256/tests/Support/TemplateDivergence/LedgerPins.php)

判定: 問題なし

Critical / Warning / Suggestion: なし。

逸脱件数47→48、採用時債務148→147は、提示された台帳差分と一致します。

### [adoption-debt.tsv](/workspace/.claude/worktrees/tasks/T256/tests/Support/TemplateDivergence/adoption-debt.tsv)

判定: 問題なし

Critical / Warning / Suggestion: なし。

`EnvExampleInvariantTest.php`の債務行だけが削除され、D51との二重登録も残っていません。

## 全体判定

**CHANGES_REQUESTED**

実装本体と乖離台帳は正しく見えますが、走査器規約が要求する検出力の裏取りに、同一種別重複と申告キー集合の反対方向の負例が不足しています。加えて、テスト用ヘルパーの型契約を将来のPHPStan編入に耐える形へ直す必要があります。