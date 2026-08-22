仮説は「撤去機構を再導入する代表的な書き方が、実行時層と静的層の少なくとも一方ではなく、両方で fail-closed に検出され、静的層の検出結果が許可処理なしで 0 件になるなら正典 v1 を満たす」です。

この基準では、設計の方向性は妥当ですが、現状は静的層の 0 件固定、PHP 字句走査、middleware 解決、設定木探索に検出漏れがあります。コマンド実行・ファイル書き込みは行っていません。

## S1. 自己検証の見本

判定: **REQUEST_CHANGES**

- [Critical] `negative-binary.bin.txt` を走査器へ直接渡す計画と、NUL 判定を `RemovedSurfaceScanTargets::population()` が担う設計が接続していません。`scanText()` 自体はNULを除外しないため、この負例は検出されるはずです。

  修正案: NUL分類を純関数として切り出して fixture から直接自己検証するか、バイナリを scanner の負例から外し、母集団生成側の自己検証へ移してください。さらに実母集団の `binaryExcluded` が空であることを gate で要求するのが安全です。

- [Warning] `positive-unregistered-route-key.php.txt` の `'password.confirm.legacy-view'` は、設計した後方境界では `password.confirm` に一致しません。「route 集合を差し替える」ことでは、この字句不一致を解消できません。

  修正案: fixture を正確に `'password.confirm' => 'タイトル'` とし、登録 route 集合を空または別集合に差し替えて条件4を検証してください。

- [Warning] PHPの名前・メソッドが大文字小文字を区別しない点、`namespace\...`、group-use alias、bracketed namespace、heredoc/nowdoc の検出例がありません。

  修正案: 少なくとも大文字小文字違い、`use A\{B as C}`、`namespace\B::IMAGESENABLED()`、heredoc の正例を追加してください。

## S2. 走査根と実走査母集団

判定: **REQUEST_CHANGES**

- [Critical] git追跡下なのに `is_file()` が偽のファイルを黙って除外すると、母集団生成が fail-open になります。削除途中や壊れたsymlinkに撤去語がある場合、検査対象から消えます。

  修正案: 空要素以外の追跡パスが通常ファイルとして読めない場合は、必ず `unresolved` に登録してください。`continue` で捨てないでください。

- [Critical] NULファイルを `binaryExcluded` に入れるだけで、その配列を失敗条件にしていません。拡張子なしの実行スクリプトへNULを一つ入れるだけで静的層を迂回できます。これは「母集団の生成・既定拒否」と整合しません。

  修正案: 現在の実測が0件なら、`binaryExcluded === []` を不変条件にしてください。少なくとも新規バイナリ除外を無言で許容しない設計が必要です。

- [Suggestion] 各根の件数だけでなく、根ごとに代表パスを1件 pin すると、誤ったroot割当やパス計算も検出できます。

## S3. 走査器

判定: **REQUEST_CHANGES**

- [Critical] 現行 `PhpTokenScan::normalize()` の `token_get_all($source)` は構文検証を行いません。壊れたPHPが必ず例外になるという設計は成立せず、`unresolved-broken-php.php.txt` の期待と矛盾します。

  修正案: 新走査器内で `token_get_all($source, TOKEN_PARSE)` による事前検証を行い、`ParseError` を `unresolved` に変換してください。共有 `PhpTokenScan` の挙動を変更する場合は既存利用者への波及テストが必要です。

- [Critical] `strpos()` と前後文字集合による判定は、AGENTS.md (e) の「区切り文字で分割したトークンの完全一致」ではありません。特に、直前と直後で `.` の扱いを変える非対称境界は、トークンの完全一致として説明できません。

  修正案: 区切り文字とトークン化規則を明示したうえで、生成したトークンまたはトークン列との完全一致に変更してください。`password.confirm` は通常語と異なるため、「dot区切りパスの末尾2要素」など専用の字句規則に分けるのが明確です。

- [Critical] PHP側を文字列リテラルだけに限定すると、S6の次の再流入を検出できません。

  ```php
  public bool $imageSourceDocumentsEnabled;
  const OCR_ANALYSIS_ENABLED = true;
  ```

  修正案: コメント/docblockを除いたPHPトークン列について、文字列だけでなく `T_STRING`、`T_VARIABLE`、定数名等も対象にする `scanPhpLexemes()` 相当を追加してください。`T_VARIABLE` は先頭の `$` を除いて完全一致させます。

- [Warning] `valueLiteral` が「単独の文字列」であることの判定が不足しています。直後だけを見ると、`'安全な値'.SomeClass::class` を単独文字列と誤判定できます。

  修正案: 値文字列の後続が配列要素・式の終端（`,`、`]` 等）であることまで確認してください。

- [Warning] PHPのクラス名・メソッド名は大文字小文字を区別しません。完全修飾名をcase-sensitiveに比較すると迂回できます。また、`T_NAME_RELATIVE`、group-use alias、複数namespace、namespaceブロックの扱いが未定義です。

  修正案: FQCNとメソッド名は先頭 `\` を正規化し、ASCII case-insensitiveで比較してください。対応する名前構文と、対応しない構文をdocblockに明記し、未解決分岐と正負例を追加してください。

## S4. Aの実行時層

判定: **REQUEST_CHANGES**

- [Critical] `Route::getRoutes()` の各routeに対する `gatherMiddleware()` だけでは、middleware groupの展開やaliasのクラス解決を保証できません。`RequirePassword::class` の直接指定やgroup内への再導入を見逃す可能性があります。

  修正案: 宣言済みmiddlewareに加え、Routerのroute middleware解決結果も調べてください。`password.confirm` alias、パラメータ付きalias、実体であるパスワード確認middlewareクラスを検出対象にします。recent-authの生存確認も同じ解決済み集合で行えば、alias名のハードコードが不要になります。

- [Critical] `fortify` と `fortify-options` だけを列挙する設計は「設定木から母集団を生成」していません。新しい設定ファイルに `confirmPassword` が追加されても検出できません。

  修正案: `config()->all()` の全設定木を再帰走査し、キー名が厳密に `confirmPassword` のものを生成してください。既知の2パスが含まれることも代表値としてpinすると、パッケージ設定の未ロードを検出できます。

- [Warning] `expect($tree)->toBeArray()` は通常、PHPStanの型を `array<array-key,mixed>` へ絞り込みません。示されたコードのまま補助関数へ渡すとlevel 10で問題になる可能性があります。

  修正案: `if (! is_array($tree)) { throw ...; }` または型絞り込みを認識できるAssertを使ってから再帰関数へ渡してください。`config('manual')` も同様です。

## S5. Aの静的層

判定: **REQUEST_CHANGES**

- [Critical] 正典の「静的字句走査層は許可一覧を持たず0件固定」に対し、4条件による許可形を1種設けています。場所のallowlistでなくても、出現を違反集合から除く許可規則であることは変わりません。設計書内のI3説明とも矛盾します。

  修正案: 「文字列 `password.confirm` の全出現」を母集団にせず、「撤去したmiddlewareの適用・登録を表す構文」をscannerの検出対象として定義し、その検出結果を許可処理なしで0件固定してください。SEOのroute名対応表は最初から撤去surfaceの出現に分類しません。概念設計Round 5でこの形を例外として明示承認しているなら、その正典条項を引用して矛盾を解消する必要があります。

- [Critical] 文字列aliasだけを見ると、次のような直接再導入を静的層が検出できません。

  ```php
  ->middleware(\Illuminate\Auth\Middleware\RequirePassword::class)
  ```

  修正案: 実際のalias解決先クラスについて、完全修飾名を解決したクラス参照も静的検出対象にしてください。未使用コードに置かれた再導入候補も静的層で止める必要があります。

- [Warning] 各 `ScanOutcome::$unresolved` が必ず違反判定に使われることをテスト構造上明示してください。母集団側の `unresolved` だけが空でも、構文解析側の未解決を無視すれば (d) 違反になります。

- [Warning] テスト名の「見出し対応表」は、本文の「見出しとは断定しない」と矛盾します。

  修正案: 「登録済みroute名をキーとする文字列値の対応表」に統一してください。

## S6. OCRフラグの不在gate

判定: **REQUEST_CHANGES**

- [Critical] `ocr_analysis_enabled`、`OCR_ANALYSIS_ENABLED`、`imageSourceDocumentsEnabled` のPHP側を文字列リテラルだけに限定するのは不十分です。特にInertia propはDTO・Resource・controllerのプロパティ名や変数名として復活できます。

  修正案: PHPの識別子・変数・定数・文字列を含む字句走査へ変更し、各語についてPHP正例も追加してください。

- [Critical] `imagesEnabled` のFQCN解決について、PHPのcase-insensitive性と名前構文の網羅範囲が不足しています。

  修正案: クラス・メソッドをcase-insensitiveで比較し、alias付きgroup use、`namespace\...`、bracketed namespaceを実装または未解決扱いにしてください。保証対象から外すだけでは、保護対象の静的呼び出しを書けるため不十分です。

- [Warning] `config('manual')` の型はPest assertionだけでなく、明示的な `is_array()` 分岐で絞り込んでください。

- [Warning] S6を単独実行してもfail-closedになるよう、利用した全 `ScanOutcome::$unresolved` と母集団の `unresolved`／`binaryExcluded` をS6自身または共有assertionで判定してください。

- [Suggestion] 消しすぎ確認として参照する既存テストは、ファイル名だけでなく正確なtest名と担保するassertionをdocblockに記載すると、役割分担が明確になります。

## S7. コメント文言修正

判定: **APPROVE**

コメントのみの変更で、UI、props、Atomic Design、DTO/APIには影響しません。許可形を増やさずTier 2を0件にする方針も適切です。S5/S6のgateが当該語の不在を直接検証するため、独立した文言テストも不要です。

## 全体判定

**CHANGES_REQUESTED**

優先して直すべき点は次の5点です。

1. S5の許可形を廃止し、撤去機構の構文的参照そのものを0件固定する。
2. PHPの文字列以外の識別子・変数・定数も走査する。
3. middleware group・alias・直接クラス指定を解決後のroute集合で検査する。
4. `confirmPassword` を `config()->all()` 全体から生成する。
5. 壊れたPHP、読めない追跡ファイル、NULファイルを確実にfail-closedにする。

これらを反映すれば、正典v1とAGENTS.mdの走査器5条件に沿った、最小スコープの設計になります。