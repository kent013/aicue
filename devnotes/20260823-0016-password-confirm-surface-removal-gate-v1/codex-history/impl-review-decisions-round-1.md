# 対応マトリクス: impl-review Round 1

## [Critical] PHP 内の文字列 FQCN と非 PHP の `RequirePassword` 参照を見逃す

- 判断: **対応する**
- 根拠: 指摘どおり。Laravel は middleware をクラス名の**文字列**でも受けるので
  `->middleware('Illuminate\Auth\Middleware\RequirePassword')` は D1 (alias 一致) にも
  D2 (`X::class` 解決) にも当たらず素通りしていた。また `.github` と拡張子なし `scripts/` を
  必須母集団にしておきながら Tier 2 が `password.confirm` しか見ていないのは、
  正典 I6 (走査根に `.github` と `scripts` を必ず含める) の目的と自家撞着している。
  **見逃す方向の穴**なので (b) 違反。
- 対応内容:
  1. `TermMatchMode::FqcnReference` を新設した (クラス名だけの完全一致。トークン文字集合
     `[A-Za-z0-9_\]`・先頭の逆斜線を落とす・**連続する逆斜線を 1 つへ畳む**・ASCII 大小無視)。
     逆斜線の畳み込みは二重引用符リテラル `"A\\B"` を吸収するため (拾いすぎる方向)。
  2. gate に **D2b** (middleware 位置の文字列リテラルがクラス名へ一致したら
     `classes` へ入れる) と **D4** (非 PHP の生テキストにクラス名が現れたら違反) を足した。
  3. 見本を追加: `positive-middleware-class-string` / `-escaped` (PHP 文字列 FQCN) /
     `positive-fqcn-noext.txt` (拡張子なし PHP) / `positive-fqcn-shell.sh.txt` /
     `positive-fqcn-workflow.yaml.txt` (大小違い) と、負例
     `negative-fqcn-other-namespace.sh.txt` (別 namespace) /
     `negative-fqcn-suffix.sh.txt` (接尾辞) / `negative-fqcn-bare-shortname.sh.txt` (裸の短名)。
  4. 実測: production の走査根に `Illuminate\Auth\Middleware\RequirePassword` の
     生テキスト出現は 0 件で、D4 は緑のまま。

## [Critical] mixed group use の `function`/`const` をクラス import として登録し、対象クラス参照を見逃す

- 判断: **対応する**
- 根拠: 指摘どおりの実装バグ。PHP は関数・定数とクラスの取り込み空間が別なのに、
  印だけ `$j++` で読み飛ばして後続の名前をクラス取り込みとして登録していた。
  結果として `use App\Other\{function AcceptedSourceDocumentTypes};` があると
  同一 namespace の対象クラス参照が `App\Other\…` へ誤解決し、**見逃す**。docblock の
  「`use function` / `use const` は取り込み表に入れない」という宣言とも実装が食い違っていた。
- 対応内容: group use の**要素ごと**に種別を保持し、`function` / `const` の要素は名前と
  別名まで読み進めたうえで**登録しない**ようにした。見本
  `positive-mixed-group-use-function.php.txt` / `positive-use-function-same-name.php.txt` /
  `positive-use-const-same-name.php.txt` を追加し、専用テスト
  「関数・定数の取り込みが同名クラスの解決へ影響しない」で両方向を固定した。
  修正前の実装へ戻して**赤くなることを実測**済み。

## [Warning] `MethodReference` の宣言判定に誤検出がある

- 判断: **対応する**
- 根拠: 指摘どおり。`typeAt()` だけではメソッド本体の中で宣言した名前付き関数や、
  型の中に置いた無名クラスのメソッドを対象クラスのメソッドと誤認する
  (拾いすぎる方向なので (b) 違反ではないが、gate の意味が濁る)。
- 対応内容: `PhpNameResolver` が**位置ごとの波括弧の深さ**を索引し、`TypeSegment` に
  `bodyDepth` を持たせた。宣言と数えるのは `depthAt($i) === $type['bodyDepth']` のときだけ。
  見本 `negative-nested-function-declaration.php.txt` /
  `negative-anonymous-class-method.php.txt` と専用テストを追加し、深さ検査を外すと
  赤くなることを実測済み。

## [Warning] `ClassReference` の「解決済み」不変条件が型で守られていない

- 判断: **対応する**
- 根拠: 指摘どおり。`(string) null` が空文字になって黙って非該当へ落ちるのは fail-open の構造。
- 対応内容: gate 側で `kind === ClassReference && resolvedFqcn === null` を
  **未解決へ入れる**ようにした (キャストを廃止)。型を種別ごとに分ける案は、
  値オブジェクトを 2 本に割る割に得るものが同じなので採らない (思考原則 2)。

## [Warning] 名前解決と middleware 位置の自己検証が宣言した分岐を覆っていない

- 判断: **対応する**
- 根拠: 指摘どおり。mixed group use の欠陥が素通りしていた事実が、裏取り不足の実証になっている。
- 対応内容: 次を追加した。
  - `use function` / `use const` / mixed group use … 上記 Critical 2 の見本 3 本
  - `parent` の解決 … `positive-parent-call.php.txt` (解ける) /
    `unresolved-parent-without-extends.php.txt` (解けないので未解決)
  - trait 内の `static` / `parent` … `unresolved-trait-static-call.php.txt` /
    `unresolved-trait-parent-call.php.txt` (テスト名も 3 種を数えるものへ改名)
  - 1 ファイル内の複数 namespace … password-confirm 側に
    `positive-multiple-namespaces.php.txt` (2 つ目の namespace だけが違反 = **ちょうど 1 件**) と
    `negative-multiple-namespaces.php.txt` (どちらも別クラス = 0 件)、ocr 側にも 1 本
  - M1 の `withoutMiddleware` / `middlewareGroup` / `appendToGroup` / `prependToGroup` … 各 1 本
  - M3 の `$middleware` / `$middlewarePriority` … 各 1 本
  - symlink … `RemovedSurfaceScanTargets::symlinkUnresolvedReason()` を純関数として切り出し
    (`population()` もこの関数を通る)、一時ディレクトリに**配下向き / 外向き / 壊れた**
    symlink を作って 3 分岐を固定した

## [Warning] D40 の対象パスと保証文が実態に一致しない

- 判断: **対応する**
- 根拠: 指摘どおり。共通基盤の逸脱を登録しているのに構成ファイルの一部しか列挙していないのは
  「対象パスの和集合で重複しない」検査の意味を薄める。保証文も実装より強かった。
- 対応内容: `tests/Support/SurfaceRemoval/` の全 14 ファイルと gate 2 本の計 16 パスを列挙した。
  保証文は「**各 gate が列挙した静的構文**への参照は…0 件」へ狭め、
  「あらゆる書き方で 0 件ではない」ことを直後の 1 文で明示した。
  あわせて詳細設計へ「実装時に確定した事項」節を足し、`$cls` を未解決にする記述を
  実装の確定 (クラス参照は `X::class` 構文に限る) で上書きした。

## [Warning] 全体検証はまだ green ではない

- 判断: **対応する**
- 根拠: 「全 green でコミット」は AGENTS.md の完了条件であり、単独再実行の green は代替にならない。
- 対応内容: 修正を入れ終えたあとに `composer test` / `pnpm test` / `pnpm test:packages` を
  もう一度**全体で**取り直す。
