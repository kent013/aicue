# 対応マトリクス: design-review Round 1

## Round 1 の判定

- 全体判定: **CHANGES_REQUESTED**
- [Critical] 3 件 / [Warning] 14 件 / [Suggestion] 1 件
- 施策別: 1〜6 が REQUEST_CHANGES、7 (D40 登録) と 8 (概念設計の訂正) が APPROVE
- Codex の総括: 「設計の中核である『例外付き規則を 1 シンボルへ隔離する』は妥当です。
  S3 第 7 条も必要です。一方、S4 の `arch()` 呼び出しは完全修飾名・関数 alias で迂回でき、
  `resolvedFunctionCallSites()` は PHP の名前解決規則を一部誤っています」

---

## [Critical] C1. `arch()` 自体が完全修飾名・alias で迂回できる (施策 3 / 5 / 6)

- 判断: **対応する**
- 根拠: 完全に正しい。**設計の中核の穴である**。
  S4 は callable 4 関数だけに完全修飾名・alias の解決を課し、`arch` / `ignoring` / `toBeUsed` は
  素の `T_STRING` 件数 pin のままだった。`\arch(...)` は `T_NAME_FULLY_QUALIFIED` で
  1 トークンに畳まれ、`use function Pest\arch as architectureRule;` なら
  呼び出し位置に `arch` の綴りが 1 度も出ない。
  **これは Round 5 で callable 4 関数について指摘されたのとまったく同じ穴**であり、
  前任者はその対応を callable 側にだけ適用して `arch` 側に横展開していなかった。
  I3 (「arch のチェーンは 1 本」) が成立しないので Critical で正しい。
- 対応内容: `arch` / `ignoring` / `toBeUsed` / callable 5 語彙を**同じ 1 本の走査契約**へ統合した
  (施策 3 の `functionNameSites()`)。`arch` は `call` ちょうど 1 件・`import` 0 件・
  `unresolved` 0 件、callable 4 関数は 3 種とも 0 件で固定する。

## [Critical] C2. `T_NAME_QUALIFIED` の扱いが PHP の名前解決規則と異なる。未設計の合法構文が複数ある (施策 3)

- 判断: **対応する。ただし Codex の実装案 (php-parser の `NameResolver`) は採らず、
  より単純で完全な方式へ設計を差し替える**
- 根拠 (指摘の正しさ): 正しい。名前空間 `A` の中の `Foo\bar()` は `A\Foo\bar()` であって
  `Foo\bar()` ではない。加えて `namespace\f()` (`T_NAME_RELATIVE`) /
  `use function A\f, B\g as h;` (カンマ区切り) / `use A\{function f, function g as h};`
  (mixed group use) / セミコロン形式の複数 namespace が未設計だった。
  **自作の名前解決器は複雑なうえ不完全**という指摘 (観点 4 への回答) はそのとおりである。
- 根拠 (実装案を採らない理由): 3 つある。
  1. **`nikic/php-parser` は本リポジトリの宣言済み依存ではない**。`composer.json` の
     require / require-dev のどちらにも無く、`ta-tikoma/phpunit-architecture-test` などの
     **推移的依存として入っているだけ**である。直接使うなら require-dev への宣言が要り、
     それは AGENTS.md §依存脆弱性の運用が定める新規依存の審査を巻き込む。
     **本設計のスコープ (静的検査の新設) から外れる**。
  2. **既存 131 本の gate は 1 本も構文解析ライブラリを使っていない** (`git grep PhpParser` が
     `tests/` `app/` で 0 件)。ここだけ別方式にすると走査器の読み方が二重化する。
     AGENTS.md 共通規約 (a) も「**構文解析ライブラリの使用は必須ではない**
     (家系の裁定 AG-154 の (2))。字句走査 + 取り込み対応表でよい」と明記している。
  3. **そもそも名前解決が要らない**。本 gate の契約は「その語彙が **0 件**」
     (callable 4 種) と「**ちょうど 1 件**」(`arch` / `ignoring` / `toBeUsed`) という
     **件数**であって、「どの関数が呼ばれているか」ではない。
     名前を解決せず**末尾セグメントの一致で拾いすぎる方向へ倒せば**、
     Codex が挙げた 5 つの未設計構文は**すべて自動的に消える**。
- 対応内容: `resolvedFunctionCallSites()` を廃し、**名前解決を行わない
  `functionNameSites()`** へ差し替えた。
  - **呼び出し位置**: `T_STRING` / `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED` /
    `T_NAME_RELATIVE` のいずれでも、**末尾セグメントが対象名と (大小無視で) 一致**すれば
    `call` として拾う。名前空間は一切解決しない (`A\Foo\call_user_func()` も拾う =
    拾いすぎる方向 = 安全)。
  - **取り込み**: `use function …` 文のトークン列に対象名の綴りが 1 つでも現れたら
    `import` として拾う。**`use` は静的な構文なので、対象関数を取り込む形で
    対象名の綴りが現れない書き方は存在しない** — したがって
    カンマ区切り・group use・mixed group use・別名つきは**構造を解かずに全部捕まる**。
  - **`unresolved`**: group use の入れ子が 2 段以上 / `use` 文が `;` で終わらない、など
    走査器がトークン列を区切れない形。**判別できる形で返し gate を失敗させる** (共通規約 (b))。
  - **複数 namespace 宣言・相対修飾名は判定に関与しなくなった**ので、
    もはや `unresolved` にする理由が無い (末尾セグメントだけを見るため)。
- **この差し替えは設計を単純にする方向である**。走査器から
  「取り込み対応表の構築」「名前空間の把握」「相対解決」の 3 機構が丸ごと消える。

## [Critical] C3. 負例が不足している (施策 6)

- 判断: **対応する** (一部は C2 の差し替えで不要になる)
- 対応内容: 施策 6 のテスト表を作り直した。
  - 完全修飾 / alias 経由の 2 本目の `arch()` → **追加**
  - `T_NAME_RELATIVE` (`namespace\call_user_func()`) → **追加**
  - カンマ区切り / mixed group use → **追加**
  - 例外クラス名の接頭辞衝突 → **追加** (施策 5 の合成負例として)
  - PHP 構文エラー → **追加** (`TOKEN_PARSE` + `ParseError` の負例)
  - **関数名の大文字・小文字差**: 追加。ただし**両方向で意味が違う**ので 2 本置く —
    (i) S4 側は**大小無視で拾う** (`\CALL_USER_FUNC(` を拾う。PHP の関数名は大小無視なので
    拾わないと迂回口になる)、
    (ii) S2 側と Pest 側は**大小を区別する** (下記 W-New を参照)

---

## [Warning] 施策 1: 「17 語彙は恒久的に不活性」は強すぎる

- 判断: **対応する**
- 根拠: 正しい。polyfill やユーザー定義関数で `function_exists()` が真になり得る。
  「恒久」は本リポジトリの語彙では強い断定である。
- 対応内容: 「**PHP 8 の標準環境では組み込みとして存在しない**」へ改め、
  65 件という値も「**基準コミットの実行環境での実測**」と明記した。
  活性判定は**常に実行環境依存**として扱う旨を docblock の正本に書く。

## [Warning] 施策 1: 分類の再計算式が vendor 実装と一致していない

- 判断: **対応する**
- 根拠: 正しい。`ObjectsRepository::allByNamespace()` は
  `function_exists($name) && (new ReflectionFunction($name))->getName() === $name` の
  **2 条件**である。設計は後者 (Reflection による名前の完全一致) を落としていた。
  これは**大文字小文字の差を潰す条件**でもあるので、落とすと分類がずれる。
- 対応内容: 再計算式を vendor と同じ述語に揃えた (施策 1 / 施策 5 の docblock)。

## [Suggestion] 施策 1: `DYNAMIC_MEMBER_INVENTORY` の `count: 0` を許容しない

- 判断: **対応する**
- 根拠: 妥当。配列全体が空 (= 動的構文が 1 件も無い) は望ましい状態だが、
  **`count: 0` の登録行**は「かつて在ったが消えた」腐った登録である。
- 対応内容: S3 に「目録の各行の `count` は 1 以上」を追加した。

## [Warning] 施策 2: 公開 API がパスを取るのに、テストは nowdoc を渡す前提で矛盾

- 判断: **対応する**
- 対応内容: 純関数 `countCallsInSource(string $source, array $names)` を本体にし、
  `countCallsInFile(string $path, array $names)` を読取ラッパーにした。
  施策 3 / 4 の走査器も同じ形 (ソース文字列を取る純関数 + 薄いラッパー) に揃えた。

## [Warning] 施策 2: `token_get_all()` は不正構文を必ずしも失敗させない

- 判断: **対応する**
- 根拠: 正しい。既定モードでは警告を出して続行することがある。
  「トークン化できなければ例外」という契約が成立しない。
- 対応内容: **`token_get_all($source, TOKEN_PARSE)`** を使い、`ParseError` を
  文脈付き `RuntimeException` へ変換する。**3 走査器すべてに適用**し、
  不正 PHP ソースの負例を施策 6 に追加した。

## [Warning] 施策 2: 共通規約 (e) の「打ち消しつき」負例が明確でない

- 判断: **対応する**
- 対応内容: 3 形を名指しで固定した — 接頭辞つき `mysha1` / **打ち消しつき `not_sha1`** /
  接尾辞つき `sha1_file`。

## [Warning] 施策 3: `statementTokens()` の異常系の契約が無い

- 判断: **対応する**
- 対応内容: 開始位置が有意トークン列の範囲外 / 文末 `;` が現れずに EOF に達した場合は
  **黙って空列や EOF までの列を返さず例外**にする。負例を施策 6 に追加した。

## [Warning] 施策 4: 公開 API がクラス名だけを取るので合成入力をテストできない

- 判断: **対応する**
- 対応内容: `forbiddenSymbolsFromSource(string $source, int $expectedArrayCount)` を
  純関数の本体にし、`forbiddenSymbolsOf(string $presetClass)` を Reflection の薄いラッパーにした。

## [Warning] 施策 4: 「73 / 20 / 6 をテストする」が「語彙数を pin しない」と矛盾

- 判断: **対応する**
- 根拠: 正しい。同じ文書の中で二重基準になっていた。
- 対応内容: 個別件数の assert を削除し、**(1) 各 preset が非空 / (2) 代表語彙を含む /
  (3) 3 集合の和集合が `ArchBaseline::allSymbols()` と一致**の 3 点に絞った。
  和集合の総数 pin (`TOTAL_SYMBOL_COUNT` = 97) は `ArchBaseline` 側の 1 か所だけに残す。

## [Warning] 施策 4: 文字列トークンから値を取り出す方法が未定義

- 判断: **対応する**
- 対応内容: **単一引用符の `T_CONSTANT_ENCAPSED_STRING` だけ**を受け付け、
  `\\` と `\'` の 2 つのエスケープだけを解く。それ以外の引用形式・エスケープ・
  配列内の未知トークン (キー付き要素 / spread / 式 / ネスト) は**すべて例外**にする
  (fail-closed)。

## [Warning] 施策 5: S3 第 7 条の検出器の実装方式・正例・負例・空振り検査が未設計

- 判断: **対応する**
- 根拠: 正しい。「必要か過剰か」への回答は「**必要**」で一致したが、
  条を足しただけで実装が無かった。
- 対応内容: 第 7 条を**純粋な述語**と**母集団の列挙**へ分けた。
  - 述語 `hasProperPrefixCollision(list<string> $exceptionNames, list<string> $allClassNames): bool`
    は gate 内の純関数。**合成負例 `['A\Foo']` × `['A\Foo', 'A\FooDouble']` を gate 内に置く**
    (共通規約 (c) が認める「gate 内の合成入力」)
  - 母集団は Composer の `ClassLoader::getPrefixesPsr4()` から得た PSR-4 根の実ディレクトリを
    走査して作る。**空でないこと (床値 + 代表クラス) を pin** する

## [Warning] 施策 5: 「`App\` で始まる」だけでは走査域の証明にならない

- 判断: **対応する**
- 根拠: 正しい。名前空間の接頭辞は classmap や非標準配置でも通る。
- 対応内容: `ReflectionClass::getFileName()` の実パスが、Composer の PSR-4 根
  (`app/` / `database/factories/` / `database/seeders/`) の**実ディレクトリ配下にある**ことを
  `realpath()` 比較で確認する形に変えた。

## [Warning] 施策 5: 層が空の規則を「禁止を検査している」と表現してはいけない

- 判断: **対応する**
- 対応内容: 規則ごとに明示の `description` を持たせ、AB-2 の説明を
  「**vendor 集合との整合用。現環境では検出力を主張しない**」にした。
  テスト名は `descriptionOf()` 由来なので**テスト一覧にもそのまま出る**。
  併せて Codex の助言どおり **「実効対象集合が非空であること」を S1 に追加**した
  (7 規則の和集合のうち vendor と同じ述語で活性と判定される語彙が 1 件以上)。
  これは件数 pin ではないので xdebug の有無で揺れない。

## [Warning] 施策 5: 検証コマンドが AGENTS.md の全コマンドと揃っていない

- 判断: **対応する**
- 対応内容: AGENTS.md `<!-- VERIFICATION_COMMANDS -->` の全 9 コマンドを受入条件に書いた。

## [Warning] 施策 6: 「チェーンを 2 本にすると期待形照合で落ちる」は正しくない

- 判断: **対応する**
- 根拠: 正しい。同じ正しいチェーンを 2 文置けば、1 文目のトークン列は期待形と一致する。
  落ちるのは**件数**の側である。設計の説明が検査の役割を取り違えていた。
- 対応内容: 負例を 2 つに分けた — **チェーン形の負例** (`->ignoring([Foo::class])` 直書き /
  `->not->toBeUsed()` 欠落) は期待形照合で落とし、**2 本目の負例**は
  `functionNameSites()` の `call` 件数が 2 になることで落とす。

## [Warning] 施策 6: 「すべて nowdoc」と実クラスを読むテストが矛盾

- 判断: **対応する**
- 対応内容: 「**合成入力はすべて nowdoc。実コードとの結合確認だけは実ファイルを使う**
  (S2 の正例と、取り違えの負例に使う 2 クラス)」へ書き換えた。

---

## 併せて自分で足した訂正 (Codex の指摘ではない)

### W-New. Pest の使用判定は**大文字小文字を区別する** (vendor 実読)

Codex の [Critical] C3 が「関数名の大小差を実測テストで固定せよ」と求めたので vendor を再読した。
結論は**区別する**である:

- `ObjectsRepository::allByNamespace($v)` は `FunctionDescription::make($v)` を返し、
  `FunctionDescription::make()` は **`name = $v` (= 渡した綴りそのもの)** を設定する
  (`(new ReflectionFunction($v))->getName()` はパス解決にしか使われない)。
  vendor preset の語彙はすべて小文字なので、**層側の名前は小文字**になる。
  なお `allByNamespace()` の関門は
  `function_exists($v) && (new ReflectionFunction($v))->getName() === $v` なので、
  **`RULES` に大文字混じりの綴りを書くと `getName()` (常に小文字) と一致せず層が空になる**。
  → S3 に「語彙はすべて小文字であること」を追加した
- 一方 `ObjectDependenciesDescription::make()` は AST の `Node\Name` を
  `toCodeString()` した綴りをそのまま `uses` に入れる。`SHA1(` と書けば `SHA1` が入る
  (`function_exists()` の絞り込みは大小無視なので**残る**)
- 突き合わせは `$objectToSearch->name === $use` の**完全一致**である

したがって **`SHA1($key)` は Pest arch に検出されない**。これは vendor の限界であり、
本設計では次のように扱う:

- **S2 (使用の証明) は大小を区別する**。Pest と同じ粒度に揃えるため
  (`SHA1(` しか無いクラスの例外登録は S2 で赤になり、実際 Pest も検出しないので**登録を消すのが正しい**)
- **S4 (サーフェスの pin) は大小を無視する**。PHP の関数呼び出し自体は大小無視で成立するため、
  `\CALL_USER_FUNC(` を見逃すと迂回口になる
- **この非対称を gate の docblock に明記する**。「S2 と S4 で大小の扱いが逆」は
  読み手が必ずつまずくので、理由 (前者は Pest の粒度合わせ / 後者は迂回防止) を併記する
- 保証しないもの (a) に「**綴りの大小を変えた呼び出しは Pest arch が検出しない**」を追加する
