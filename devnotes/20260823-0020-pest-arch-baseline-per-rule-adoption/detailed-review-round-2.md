## 全体判定: CHANGES_REQUESTED

C2への「名前解決を捨て、末尾セグメント一致で安全側に倒す」方針自体は妥当です。php-parserを直接依存へ追加しない判断にも合理性があります。

ただし、S4でメソッド名まで `functionNameSites()` に統合した点と、S3第7条のクラス母集団をPSR-4パスから推測する点に未解決の問題があります。

---

## 施策1: `ArchBaseline`

判定: REQUEST_CHANGES

- [Warning] 「`ReflectionFunction::getName()` は常に小文字を返す」は一般には成立しません。ユーザー定義関数では宣言時の綴りが返る可能性があります。

  修正案: 「vendor presetが対象とする現行の組み込み関数・ヘルパでは正規の小文字名が返り、preset語彙も小文字である」に狭めてください。S3の小文字制約自体は、vendor集合との一致を守る観点から維持して構いません。

- [Suggestion] `SINGLE_OCCURRENCE_NAMES` は関数とメソッドで走査方法が異なるため、次のように分けた方が型と契約が明瞭です。

  ```php
  SINGLE_FUNCTION_NAMES = ['arch'];
  SINGLE_MEMBER_NAMES = ['ignoring', 'toBeUsed'];
  ```

---

## 施策2: `GlobalFunctionCallScanner`

判定: APPROVE

パスAPIとソースAPIの分離、`TOKEN_PARSE`、大小を区別する契約、3形の負例はいずれも妥当です。

V5の非対称も合理的です。S2は「Pestが実際に必要とする例外の腐敗検出」であるため、Pestと同じ大小区別に合わせるべきです。S4とは目的が異なるため、無理に統一する必要はありません。

---

## 施策3: `ArchSurfaceScanner`

判定: REQUEST_CHANGES

- [Critical] `functionNameSites()` はメンバ呼び出しを除外する契約なのに、S4では `ignoring` と `toBeUsed` も同メソッドで `call` 1件に固定しています。

  実際のチェーンは次の形なので、直前が `->` であり必ず除外されます。

  ```php
  ->toBeUsed()
  ->ignoring(...)
  ```

  このままでは新設gateが初日から赤になります。

  修正案:

  - `arch` とcallable 4関数だけを `functionNameSites()` で検査する
  - `ignoring` / `toBeUsed` は `identifierSites()` で各1件に固定する
  - 動的メンバ呼び出しによる回避は、現在の `dynamicMemberSites()` exact-fitで扱う

- [Critical] `functionNameSites()` の戻り値に `index` がありません。S4は唯一の `arch` 呼び出し位置から `statementTokens()` を呼ぶ設計なので、行番号だけでは実装できません。同じ行に複数呼び出しがあれば一意にもなりません。

  修正案: 少なくとも `call` には有意トークン列の `index` を含めてください。

  ```php
  array{
      status: 'call',
      name: string,
      line: int,
      index: int
  }
  ```

- [Warning] `use function` の「対象名の綴りが現れる」という定義が、部分文字列一致にも読めます。共通規約 (e) に抵触する可能性があります。

  修正案: `T_STRING` および名前トークンを `\` で分割し、各セグメントを大小無視の完全一致で比較すると明記してください。`T_NAME_QUALIFIED` 全体への部分文字列検索は禁止します。

  取り込み側にも次の正例・負例が必要です。

  ```php
  use function A\call_user_func;      // 検出
  use function A\mycall_user_func;    // 非検出
  use function A\not_call_user_func;  // 非検出
  use function A\call_user_func_x;    // 非検出
  ```

- [Warning] `unresolved` として挙げた2形は、`TOKEN_PARSE` に先に拒否される可能性が高く、実質的に到達不能です。

  - 入れ子のgroup use
  - セミコロンなしのuse文

  到達不能な結果型を収集するのは共通規約 (d) に反します。

  修正案: 有効なPHP構文として走査不能になる具体例がないなら、`unresolved` を戻り値から削除し、構文不正はすべて `ParseError` 由来の例外へ統一してください。有効だが未対応の構文が実在するなら、その構文を負例として明示してください。

### C2への回答

末尾セグメント方式なら、Round 1で挙げた以下は名前解決なしで扱えます。

- `T_NAME_RELATIVE`
- カンマ区切りuse
- group use / mixed group use
- 複数namespace
- aliasと相対修飾名

「対象関数をimportしながら、元の対象名が構文上まったく現れない」通常の `use function` 構文はありません。したがって基本方針は承認できます。ただし、上記のとおり比較単位を「名前セグメントの完全一致」に固定する必要があります。

---

## 施策4: `VendorArchPresetReader`

判定: APPROVE

純関数とReflectionラッパーの分離、個別件数pinの撤去、単一引用符だけを受理するfail-closed方針は整合しています。

---

## 施策5: `ArchBaselineTest`

判定: REQUEST_CHANGES

- [Critical] S3第7条の母集団を「PSR-4ディレクトリ構造とファイル名」から生成しても、実際の完全修飾クラス名を列挙したことにはなりません。

  次の形を見逃します。

  - 1ファイルに複数のクラスがある
  - ファイル名とクラス名が一致しない
  - namespace宣言が期待パスと異なる
  - enum/interface/trait等をPest側がオブジェクトとして扱う場合
  - 条件付きクラス宣言

  例えば既存例外クラスのファイル内に `FakeObjectStoreDouble` が追加された場合、Pestは前方一致で除外し得ますが、パス由来の母集団はその第2クラスを列挙できません。S3第7条の核心を迂回できます。

  修正案: Pestが実際に構築するオブジェクト名集合を利用するのが最小です。利用可能な公開APIがない場合は、実装前にvendorを再読して取得点を確定してください。独自列挙にする場合はnamespaceと `T_CLASS` / `T_INTERFACE` / `T_TRAIT` / `T_ENUM` を解析する専用走査器が必要で、未解決分岐・正負例・母集団pinも同じPRに必要です。

- [Warning] `realpath()` の配下判定は単純な文字列prefixにしてはいけません。`/app/Foo` と `/app/Foobar` のような誤一致が起こります。

  修正案: PSR-4根の末尾に `DIRECTORY_SEPARATOR` を付けた境界付き比較にしてください。

- [Warning] S4第2条は施策3の修正に合わせて分離が必要です。

  修正案:

  - `arch`: `functionNameSites()` で `call=1`, `import=0`
  - `ignoring` / `toBeUsed`: `identifierSites()` で各1件
  - 3件とも `CHAIN_HOST_FILE` に存在
  - `arch` の `index` からチェーン照合
  - 動的メンバは従来どおりexact-fit

### S1第3条への回答

「実効対象集合が1件以上」は妥当です。現在のPest版ではコア構文が存在するため、xdebugやpolyfillの有無で真偽が揺れません。個数をpinせず、gate全体の実効性喪失だけを検知する点にも意味があります。

---

## 施策6: 検出力テスト

判定: REQUEST_CHANGES

- [Critical] `ignoring` / `toBeUsed` を `functionNameSites()` が1件として返す前提のテストがありません。実装契約上は0件になるため、S4との矛盾を検出できていません。

  修正案: `arch` の関数検査と、`ignoring` / `toBeUsed` の識別子検査を別テストにしてください。

- [Warning] `functionNameSites()` の `call.index` から正しい文を切り出せるテストを追加してください。同一行に複数の名前呼び出しがあるケースが有効です。

- [Warning] `use function` 側にも接頭辞・打ち消し・接尾辞の3形を追加してください。現在のNo.25は呼び出し側だけに見えます。

- [Warning] `unresolved` の負例は、まず `TOKEN_PARSE` を通る有効なPHPであることを確認する必要があります。不正構文なら期待結果を `RuntimeException` に変更してください。

- [Warning] S3第7条の正負例は純粋述語しか検証していません。クラス母集団を作る側にも、少なくとも「同一ファイルの第2クラスを落とさない」負例が必要です。

---

## 施策7: D40登録

判定: APPROVE

Round 1から新たな問題はありません。実装着手時とマージ直前の再測定は必須です。

---

## 施策8: 概念設計訂正

判定: APPROVE

V1の訂正内容は妥当です。

---

## オーバーエンジニアリング評価

名前解決器を末尾セグメント方式へ縮小した判断は良いです。一方、次の2点は削れます。

- 到達可能な未解決構文が示せない `unresolved` 戻り値
- PSR-4パスからクラス名を推測する独自母集団生成

前者は例外へ統一し、後者は可能ならPest自身の解析結果を再利用するのが最小です。

V5の非対称は維持して構いません。S2とS4は目的が異なるため、大小の扱いを揃える方がかえって契約を曖昧にします。