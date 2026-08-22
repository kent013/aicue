仮説は「Round 1で塞いだ経路が、自己検証から実母集団まで切れ目なく接続されていれば承認可能」です。主要5変更の方向性は正しく、Round 1の中心的な問題は解消されています。ただし、非PHPのFQCN検出が現行トークン規則では成立しないため、まだ承認できません。

## S1. 自己検証の見本

判定: **REQUEST_CHANGES**

- [Warning] 完全修飾名解決の負例に、「対象と同じ短名を持つ別クラス」がありません。現在の負例は `RequireRecentAuth` や `Thing` なので、短名一致へ退行しても検出できません。

  修正案: 次を追加してください。

  - `App\Other\RequirePassword::class` をmiddleware位置で使う負例
  - `App\Other\AcceptedSourceDocumentTypes::imagesEnabled()` の負例
  - aliasも対象と同じ短名へ寄せた負例

  これはAGENTS.md (a) の「同名の別クラスを拾わない」の直接的な裏取りになります。

- [Warning] `invalid-utf8.bin` が `unresolved` になる自己検証は、現在の公開APIでは実母集団生成へ接続できません。fixtureは `tests/` 配下であり走査根外、公開された純関数は `isBinary()` だけです。

  修正案: 「bytesを受け、Binary / InvalidUtf8 / Textの分類結果を返す」純関数を切り出し、NUL・不正UTF-8の両方を同じ経路で自己検証してください。

## S2. 走査根と実走査母集団

判定: **REQUEST_CHANGES**

- [Warning] NUL判定は純関数化されていますが、UTF-8不正判定は `population()` 内に閉じています。そのため「不正UTF-8 fixtureが `unresolved` に落ちる」というテスト計画を実装できません。

  修正案: 例えば `classifyContents(string $contents): ContentClassification` を設け、`population()` も自己検証も必ずそこを通してください。分類結果は少なくとも `Text / Binary / InvalidUtf8` を区別します。

- [Suggestion] 追跡下symlinkについても方針を明示すると安全です。現在の `is_file()` と `file_get_contents()` は、symlink先が通常ファイルならリポジトリ外も読み得ます。symlinkを `unresolved` にするか、repository root内へ解決されることを検証してください。

その他、読めない追跡パスの `unresolved` 化、`binaryExcluded === []`、代表パスpinは適切です。

## S3. 走査器

判定: **REQUEST_CHANGES**

- [Critical] 非PHP用FQCNの `ExactRun` は、宣言した `TOKEN_CHARACTERS` では絶対に一致しません。

  対象語:

  ```text
  App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabled
  ```

  しかし `\` と `:` は区切りなので、実際には次の複数runへ分割されます。

  ```text
  App
  Support
  Manual
  AcceptedSourceDocumentTypes
  imagesEnabled
  ```

  したがってS6の非PHP FQCN検査と `positive-fqcn-in-text.sh.txt` は成立しません。

  修正案: FQCN参照専用の一致様式を追加してください。例えば次のいずれかです。

  - `FqcnMethodReference` として名前要素・`\`・`::`・メソッド名を構文的に分解して完全一致
  - 専用トークン文字集合 `[A-Za-z0-9_\\:]` を使い、先頭 `\` を正規化して完全一致

  PHPクラス参照として使われる文字列を守るなら、この専用様式もASCII case-insensitiveにするのが整合的です。

- [Warning] `catch (\ParseError|\Error $e)` は親型と子型を同時指定しています。`ParseError` は `Error` の派生なので冗長です。

  修正案: `TOKEN_PARSE` の失敗だけなら `ParseError`、広く処理するなら `Error` の一方にしてください。予期しない実行時障害まで単なる解析未解決へ変換する必要があるかも明記してください。

- [Warning] `self` / `static` を一律未解決とする説明の「解決しても意味がない」は正確ではありません。現在クラスを追跡すれば `self::imagesEnabled()` は対象クラスへ解決できます。

  修正案: 少なくとも `self` は現在クラスへ解決してください。`static` は現在の宣言クラスを候補として保守的に扱い、`parent` はextends参照を解けない場合に未解決とする設計が自然です。見送る場合は「安全側にgateを失敗させるため」と説明してください。

## S4. Aの実行時層

判定: **REQUEST_CHANGES**

- [Warning] `config()->all()` はConfig Repositoryの契約上すでに配列です。PHPStanが正確な戻り型を認識している場合、`is_array($all)` は「常にtrue」の不要条件として報告される可能性があります。Round 1で問題にした `config('manual')` のmixed戻りとは異なります。

  修正案: `config()->all()` は宣言された配列型をそのまま使ってください。Larastan上の要素型だけ不足する場合は、局所的に `array<string,mixed>` を注釈し、実型を緩めないでください。`config('manual')` 側の `is_array()` は維持して構いません。

それ以外の解決済みmiddleware走査、alias側との二重確認、全設定木からのキー生成、既知パスpin、recent-auth確認は適切です。

## S5. Aの静的層

判定: **REQUEST_CHANGES**

- [Warning] 「M1〜M3外の穴は実行時層が塞ぐ」という表現は保証を誇張しています。実行時テストが観測できるのは、テスト環境で実際に構築されたrouteだけです。production限定条件分岐や未実行コードからの再導入は、M1〜M3外なら両層を通過し得ます。

  修正案: 次のように保証を限定してください。

  > M1〜M3外は静的層の保証外。実行時層はテスト起動時に実体化した全routeについて補完するが、環境依存で実体化しない経路までは保証しない。

- [Warning] FQCN解決の自己検証に、同じ短名を持つ別クラスの負例が必要です。

  修正案: `App\Other\RequirePassword` をimport・alias・FQCNでmiddleware位置に置き、違反にしないことを固定してください。

許可形を廃止して検出母集団を「middleware適用・登録構文」に変更した点は、I3との矛盾を解消しています。

## S6. OCRフラグ不在gate

判定: **REQUEST_CHANGES**

- [Critical] 非PHPの完全修飾 `imagesEnabled` 参照は、S3のrun規則では検出不能です。

  修正案: S3にFQCNメソッド参照専用の一致様式を追加し、次を正負例で固定してください。

  - 先頭 `\` の有無
  - ASCII大小違い
  - 同じ短名を持つ別namespace
  - メソッド名の接尾辞付き
  - 対象クラスだが別メソッド
  - 別クラスだが同じメソッド

- [Warning] PHP側にも `App\Other\AcceptedSourceDocumentTypes::imagesEnabled()` の負例を追加してください。現状の `Thing::imagesEnabled()` だけでは短名解決の誤実装を検出できません。

PHP lexemeへの拡張、実行時のキー存在判定、単独実行時の未解決・バイナリ確認、既存テストの正確な参照は適切です。

## S7. コメント文言修正

判定: **APPROVE**

Round 1から変更はなく、Tier 2を許可なし0件固定にする目的に合っています。

## 全体判定

**CHANGES_REQUESTED**

Round 1の本質的な設計問題はほぼ解消されています。残る主要ブロッカーは、`TOKEN_CHARACTERS` と非PHP FQCNの不整合です。これを専用のFQCN一致様式で修正し、同じ短名を持つ別クラスの負例、UTF-8分類の自己検証経路、保証範囲の表現を整えれば承認可能な水準です。