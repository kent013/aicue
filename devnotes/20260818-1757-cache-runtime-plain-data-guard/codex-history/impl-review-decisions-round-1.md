# 対応マトリクス: impl-review Round 1

## [Critical] 動的な直接生成 (`new $class`) を黙って見逃す

- 判断: **対応する (ただし母集団を面の中へ限定し、残る限界を明記する)**
- 根拠: 指摘のとおり `T_NEW` の後が解決できない形を落とす分岐が無かった。ただし
  **走査根全体で落とすと 12 件の無関係な誤検出**が出た (`(new $model)->getTable()` /
  `PasskeyFactory::new()` など。前者はキャッシュと無関係な Eloquent のモデル生成、
  後者はそもそも生成ではなくメソッド名である)。誤検出は目録を意味の無い儀式に変えるので、
  落とす範囲を**キャッシュ記号に触れるファイル (L3 の面) の中**へ限定した。
- 対応内容:
  - `T_NEW` の直後が名前として解決できない形を `dynamicNewSites` に集め、
    そのファイルが面なら未分類として落とす (`cachePayloadCollectFromSource`)
  - `::new()` / `->new()` は**メソッド名**なので母集団から外す
  - **具体 store の名前に触れることを面の条件へ追加**した。これにより
    `$class = ArrayStore::class; $store = new $class;` はその時点でファイルが面になり、
    動的生成が落ちる
  - 負例 (面の中の動的生成 2 形) と正例 (面でないファイルの動的生成 /
    `Factory::new()` / 名前で書かれた `new`) を追加
  - **残る限界を冒頭 docblock の「保証しないもの」へ明記**した —
    クラス名を**素の文字列リテラル**で書いて動的生成する形は走査していない。
    L4b の「直接生成を 0 件で pin する」という主張はその構文を除いた範囲である
    (AGENTS.md 走査規約 (b) の「保証範囲を明示的に狭める」側を採った)

## [Warning] L4c が「第 1 引数」という構造を見ていない

- 判断: **対応する**
- 根拠: 直前 token が `(` であることしか見ておらず、`leak($store)` のような
  任意の呼び出しの第 1 引数でも通ってしまう。指摘のとおり穴である。
- 対応内容: 判定を純関数 `cachePayloadStoreLeakViolations()` へ切り出し、
  `new` + `PlainDataGuardedRepository` + `(` の直後であることまで確認する形にした。
  負例 3 形 (第 1 引数のすり替え / 第 2 引数への流出 / 受け皿以外への手渡し) と
  正例 1 形を追加した。

## [Warning] 継承解析の「解決不能なら null」分岐に負例が無い

- 判断: **対応する**
- 根拠: fail-closed 分岐の裏取りが無いのは AGENTS.md の 4 点セット違反である。
- 対応内容: `class Fixture implements $dynamicInterface {}` を合成入力とする負例を追加した。

## [Warning] W2/W3 が「finally に reset」を保証していない

- 判断: **対応する**
- 根拠: 指摘のとおり。afterEach より後に `reset()` があれば通る形だったので、
  flush が throw したときに accumulator が漏れる書き方を素通ししていた。
- 対応内容: `cacheGuardLaneWiringViolations()` に try / finally の位置判定を足し、
  **flush は try の中・reset は finally の中**であることを要求する形にした。
  負例を 3 形 (flush が無い / reset が finally の外 / try-finally の形でない) にした。

## [Warning] W6 がメソッドの中を見ていない

- 判断: **対応する**
- 根拠: ファイル全体の token 順を見ていたので、別メソッドで結線し別メソッドで
  bootstrap する形を正常扱いしていた。
- 対応内容: `cacheGuardBootstrapOrderViolations()` の引数を**メソッド本体の token 列**へ変え、
  W1 / W6 とも反射で切り出した本体を渡す形にした。負例に「別メソッドへ分けた形は
  ファイル全体を見ると 0 件になる」ことを明示する合成入力を追加した。

## [Warning] W4 の trait 検出が短名だけ / パス解決の失敗を黙って除外

- 判断: **対応する**
- 根拠: 指摘のとおり。別名 1 つで検査が黙る形だった。
- 対応内容: 取り込み表 (`use ... as ...` を含む) を作り、**型宣言より後の `use`** だけを
  trait の取り込みとして完全修飾名で突き合わせる形にした
  (namespace 直下の取り込みは対象外 — `tests/TestCase.php` は override のために必要である)。
  `getRealPath()` / `file_get_contents()` の失敗は expect で落とす (fail-closed)。
  負例 4 形 (短名 / 別名 / 完全修飾名 / カンマ区切り) と正例 1 形 (取り込みだけ) を追加した。

## [Warning] W8 の負例が実際の判定関数を通っていない

- 判断: **対応する**
- 根拠: 加工した配列を素の比較で確かめるだけでは、判定側が壊れても負例が緑のままになる。
- 対応内容: 判定を `cacheGuardTokenListViolations()` /
  `cacheGuardLocalCopyViolations()` の 2 つの純関数へ切り出し、W5 / W5b / W8 / W7 の
  すべてがこの関数を通る形にした。W8 の削除負例には「結線 1 行」も足した。

## [Warning] runtime-exposure.md が差分に無い

- 判断: **対応する (ファイルは実在していた。レビューへ渡す差分の指定漏れである)**
- 根拠: `devnotes/20260818-1757-cache-runtime-plain-data-guard/runtime-exposure.md` は
  作成済みだったが、Round 1 のプロンプトを組むときに `git diff` の対象から
  `devnotes/` を落としていた。
- 対応内容: Round 2 のプロンプトへ本文を添付する。内容は wave 0 (`__call` の素通しの分類。
  実測で 18 件中 16 件が落ちることを確認) / wave 1 (全レーン 5862 件中、実行時層の違反 0 件) /
  wave 2 (静的層を入れた後の再計測) と、一意ファイル数・違反サイト数・違反件数の 3 つを持つ。

## [Suggestion] AGENTS.md の「自己テストだけを exact-fit」が実態と食い違う

- 判断: **対応する**
- 対応内容: 継承・実装の宣言は別の名指し目録で扱い実行時層の実装 2 本だけを許す、と書き分けた。

## [Suggestion] guide にも「2 層とも見えない」を明記

- 判断: **対応する**
- 対応内容: `docs/app-integration-guide.md` の不変条件 6 に同じ一文を足した。

## [Suggestion] 冒頭の不変条件説明に `null` が抜けている

- 判断: **対応する**
- 対応内容: 静的 gate 冒頭の 1 行を「配列 / 文字列 / 数値 / 真偽値 / null」に直した。

## [Suggestion] L4g は一致ではなく部分集合

- 判断: **対応する**
- 根拠: TERMINAL には mock 系も含むので「一致」は不正確である。
- 対応内容: テスト名・コメント・`PlainDataGuardedRepository` の docblock を
  「部分集合」へ直した。あわせて docblock の参照先を実際に検査を持つ
  `CachePayloadPlainDataGateTest.php` へ訂正した。

## [Suggestion] `put()` の配列キー分岐が直接テストされていない

- 判断: **対応する**
- 対応内容: 検査 4b を追加した (負例 = `put(['k' => new stdClass], 60)` /
  正例 = 素データの往復)。L2 目録の `put` の件数を 2 へ更新した。
