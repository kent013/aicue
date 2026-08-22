## 全体判定: CHANGES_REQUESTED

設計の中核である「例外付き規則を1シンボルへ隔離する」は妥当です。S3 第7条も必要です。一方、S4 の `arch()` 呼び出しは完全修飾名・関数 alias で迂回でき、`resolvedFunctionCallSites()` は PHP の名前解決規則を一部誤っています。このままでは共通規約 (a)(b) と I3 を満たしません。

---

## 施策1: `ArchBaseline`

判定: REQUEST_CHANGES

- [Warning] 「17語彙は恒久的に不活性」は強すぎる表現です。PHP 8 の組み込み関数から削除されていても、polyfill やユーザー定義関数により `function_exists()` が真になる可能性があります。

  修正案: 「PHP 8 標準環境では組み込みとして存在しない」に変更し、65件という値も「基準コミットの実行環境での実測」と明記してください。活動判定は常に実行環境依存として扱います。

- [Warning] 分類の再計算式が vendor 実装と完全一致していません。設計記載の `getClass() !== null || function_exists()` では、Reflection による名前完全一致条件が欠けています。

  修正案:

  ```php
  PhpCoreExpressions::getClass($symbol) !== null
  || (
      function_exists($symbol)
      && (new ReflectionFunction($symbol))->getName() === $symbol
  )
  ```

- [Suggestion] `DYNAMIC_MEMBER_INVENTORY` は「配列全体が空」を許容してよい一方、登録行の `count: 0` は許容しないことを契約に明記すると、腐った登録を残さずに済みます。

---

## 施策2: `GlobalFunctionCallScanner`

判定: REQUEST_CHANGES

- [Warning] 公開APIはファイルパスを受け取る一方、施策6は nowdoc のソース文字列を走査器へ渡す前提です。テスト計画とAPIが一致していません。

  修正案: 純粋な `countCallsInSource(string $source, array $names)` を本体とし、`countCalls(string $path, ...)` をファイル読取ラッパーにしてください。あるいはテストが一時ファイルを使うことを明記します。

- [Warning] `token_get_all()` は通常モードでは不正構文を必ずしも失敗させません。「トークン化できなければ例外」という契約を満たせません。

  修正案: `token_get_all($source, TOKEN_PARSE)` を使い、`ParseError` を文脈付き `RuntimeException` に変換してください。対応する不正PHPソースの負例も必要です。

- [Warning] 共通規約 (e) の「打ち消しつき」負例が明確ではありません。

  修正案: `mysha1`、`not_sha1`、`sha1_file` のように、接頭辞・打ち消し・接尾辞の3形を明示的に固定してください。

---

## 施策3: `ArchSurfaceScanner`

判定: REQUEST_CHANGES

- [Critical] `arch()` 自体が `identifierSites()` の `T_STRING` 件数だけで管理されています。次のような別名・完全修飾呼び出しで、2本目の表明を件数pinの外に作れます。

  ```php
  \arch(...);

  use function Pest\arch as architectureRule;
  architectureRule(...);
  ```

  これにより I3 の「arch チェーンは1本」が成立しません。

  修正案: `arch` も callable 4関数と同じ完全修飾名前解決の対象に含め、解決済み呼び出しがちょうど1件、未解決が0件であることを固定してください。その唯一の位置からチェーンを照合します。

- [Critical] `T_NAME_QUALIFIED` をそのまま完全修飾名と扱うのはPHPの規則と異なります。名前空間 `A` 内の `Foo\bar()` は原則として `A\Foo\bar()` です。また、次の合法構文が未設計です。

  - `namespace\call_user_func()` (`T_NAME_RELATIVE`)
  - comma-separated `use function A\f, B\g as h;`
  - mixed group use `use A\{function f, function g as h};`
  - セミコロン形式を含む複数 namespace
  - alias と相対修飾名の組み合わせ

  修正案: 自作の import 解決器を広げるより、既に依存にある `nikic/php-parser` の `NameResolver` を利用してください。`FuncCall` の名前を解決し、動的な `Expr` は未解決または明示した保証外として返す方が単純で堅牢です。

- [Warning] `statementTokens()` で開始位置が不正、または文末 `;` が存在しない場合の契約がありません。

  修正案: 空列やEOFまでの列を黙って返さず例外にし、その負例を追加してください。

---

## 施策4: `VendorArchPresetReader`

判定: REQUEST_CHANGES

- [Warning] 公開APIがクラス名だけを受け取るため、「配列なし・2個・可変要素」の nowdoc 合成入力をテストできません。

  修正案: `forbiddenSymbolsFromSource(string $source, int $expectedArrays)` のような純粋な抽出関数を用意し、Reflectionを使うメソッドを薄いラッパーにしてください。

- [Warning] 「73 / 20 / 6 をテストする」は、直後の「語彙数をpinしない」と矛盾します。

  修正案: 個別件数のassertは削除し、各presetが非空、代表語彙を含む、3集合の和集合が `ArchBaseline` と一致する、の3点に絞ってください。

- [Warning] 文字列トークンから値を取り出す方法が未定義です。単純な引用符の除去ではエスケープを誤解釈します。

  修正案: 現行vendor形式である単一引用符・許可されたエスケープだけを明示的に解析し、それ以外は例外にしてください。配列内の未知トークン、key、spread、式、ネストも fail-closed にします。

---

## 施策5: `ArchBaselineTest`

判定: REQUEST_CHANGES

- [Critical] S4 は、施策3で述べた別名・完全修飾 `arch()` の迂回を検出できません。I3を中核不変条件として掲げる以上、修正必須です。

- [Warning] S3 第7条そのものは必要であり、過剰ではありません。vendor の前方一致除外に対する直接的な防御です。ただし、走査域の全クラス名をどう取得・解決するか、その検出器の正例・負例・空振り検査が設計されていません。

  修正案: Pest が実際に構築した対象オブジェクト名を再利用するか、PSR-4根を PHP Parser で解決してください。単なる名前空間prefix判定やファイル名推測は避けます。少なくとも `Foo` と `FooDouble` の合成負例を追加してください。

- [Warning] 「`App\` 等で始まる」だけでは、例外クラスがPestの実際の走査域にある証明になりません。classmapや非標準配置でもprefix条件を通せます。

  修正案: Reflectionの実ファイルがComposerの対象PSR-4根配下にあること、またはPestの対象オブジェクト集合にクラス名が存在することを確認してください。

- [Warning] 65語彙について正確な件数pinを追加する必要はありません。環境差で不安定になるため、この判断は妥当です。ただし、AB-2のような層が完全に空の規則を「禁止を検査している」と表現してはいけません。

  修正案: 「vendor集合との互換性保持用で、現環境では検出力を主張しない」とテスト名・docblock・成果説明を揃えてください。活動分類にはvendorと同じ述語を使い、少なくとも全体の実効対象集合が非空であることを確認するとよいです。

- [Warning] 正典の検証コマンドにはPHP以外の既定コマンドも含まれています。

  修正案: 最終受入条件はAGENTS.mdの全検証コマンドに合わせてください。変更がPHPだけであることは、省略理由にはなりません。

---

## 施策6: 走査器の検出力テスト

判定: REQUEST_CHANGES

- [Critical] 次の負例が不足しています。

  - 完全修飾またはalias経由の2本目の `arch()`
  - `T_NAME_RELATIVE`
  - comma-separated / mixed group use
  - 例外クラス名の接頭辞衝突
  - PHP構文エラー
  - Pest実体に対する関数名の大文字・小文字差の挙動

  特にPHP関数名は言語上大文字小文字を区別しないため、`SHA1()` がPest内部で正規化されるかを実測テストで固定してください。

- [Warning] 「チェーンを2本にすると `statementTokens()` の期待形照合で落ちる」は正しくありません。別々の文として同じ正しいチェーンを2本置けば、最初の文のトークン列は期待形と一致します。

  修正案: この負例は `identifierSites()` または解決済み `arch()` 呼び出し件数が2になることで落としてください。チェーン形のテストと件数テストを分けます。

- [Warning] 「すべてnowdoc」と、実クラス `FakeObjectStore` 等を読むテストが矛盾しています。

  修正案: 「合成入力はすべてnowdoc。実コードとの結合確認だけは実ファイルを使う」と書き換えてください。

- [Warning] vendor件数 `73 / 20 / 6` のassertは施策4の方針と矛盾するため削除してください。

---

## 施策7: D40登録

判定: APPROVE

登録理由、対象パス、再判定条件、共有pinの同時更新は整合しています。着手時とマージ直前に番号・件数を再測定する方針も適切です。

---

## 施策8: 概念設計のV1訂正

判定: APPROVE

`toBeUsed` の完全一致というvendor実装へ記述を合わせる修正は妥当です。設計判断を変えず、事実誤認だけを訂正しています。

---

## 指定された5点への回答

1. 65語彙の件数pinは不要です。ただし「恒久的に不活性」は修正し、空の依存層について検出力を主張しないことをテスト名を含めて徹底すべきです。

2. S4の自己参照自体は許容できます。nowdoc規約も有効ですが、それだけでは完全修飾・alias経由の `arch()` を防げないため不十分です。

3. `resolvedFunctionCallSites()` にはPHP名前解決上の見落としがあります。特に `T_NAME_QUALIFIED` の扱いは誤りです。PHP Parserの名前解決機構を使うのが最小かつ安全です。

4. per-rule分解とS1〜S5は目的に見合います。一方、自作の完全修飾名解決器は複雑なうえ不完全で、ここがオーバーエンジニアリングです。既存parserの利用で縮小できます。

5. S3第7条は必要です。vendorが前方一致で除外する以上、I2の実効的な波及半径を1クラスへ閉じるための必須条件です。ただし実装方式と検出力テストを追加する必要があります。