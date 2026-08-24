# Round 2: Round 1 の指摘への対応

Round 1 の全指摘 (Critical 2 / Warning 5) に対応した。対応マトリクスは次のとおり。

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


## 追加・変更した見本 (git status)

```
 A tests/Architecture/fixtures/surface-removal/content/binary-with-nul.hex.txt
 A tests/Architecture/fixtures/surface-removal/content/invalid-utf8.hex.txt
 A tests/Architecture/fixtures/surface-removal/content/text-plain.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-bare-imagesenabled.sh.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-dynamic-call.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-fqcn-method-suffix.sh.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-fqcn-other-method.sh.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-fqcn-other-namespace.sh.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-method-suffix.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-negated.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-other-class-declaration.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-other-class-static-call.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-php-comment.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-prefix.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-same-shortname-declaration.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-same-shortname-static-call.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-self-in-other-class.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-suffix.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/negative-target-other-method.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-case-insensitive.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-class-const.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-config-key.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-config-path.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-env.sh.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-fqcn-case.yaml.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-fqcn-in-text.sh.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-fqcn-leading-backslash.sh.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-heredoc.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-method-declaration-bracketed.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-method-declaration.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-prop.svelte.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-property.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-self-call.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-alias.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-fqcn.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-groupuse-alias.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-relative.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-same-namespace.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-call-use.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-static-keyword-call.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/positive-variable.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-broken-php.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-dynamic-class-static-call.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-trait-self-call.php.txt
 A tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-trait-used-by-target.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/negative-alias-to-target-shortname.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/negative-dynamic-middleware-value.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/negative-negated.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/negative-other-middleware-class.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/negative-php-comment.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/negative-prefix.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/negative-route-name-usage.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/negative-same-shortname-fqcn.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/negative-same-shortname-import.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/negative-seo-title-map.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/negative-session-key.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/negative-suffix.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-alias-registration.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-config-management-middleware.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-css-id-selector.css.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-css-universal.css.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-kernel-property.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-arg.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-array.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-alias.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-case.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-fqcn.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-groupuse.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-relative.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-param.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-noext.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-shell.sh.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-svelte-markup.svelte.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-svelte-script.svelte.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-svelte-style.svelte.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-ts-generator.ts.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/positive-workflow.yaml.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/unresolved-broken-php.php.txt
 A tests/Architecture/fixtures/surface-removal/password-confirm/unresolved-dynamic-middleware-class.php.txt
?? tests/Architecture/fixtures/surface-removal/ocr-flag/negative-anonymous-class-method.php.txt
?? tests/Architecture/fixtures/surface-removal/ocr-flag/negative-nested-function-declaration.php.txt
?? tests/Architecture/fixtures/surface-removal/ocr-flag/positive-mixed-group-use-function.php.txt
?? tests/Architecture/fixtures/surface-removal/ocr-flag/positive-multiple-namespaces.php.txt
?? tests/Architecture/fixtures/surface-removal/ocr-flag/positive-parent-call.php.txt
?? tests/Architecture/fixtures/surface-removal/ocr-flag/positive-use-const-same-name.php.txt
?? tests/Architecture/fixtures/surface-removal/ocr-flag/positive-use-function-same-name.php.txt
?? tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-parent-without-extends.php.txt
?? tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-trait-parent-call.php.txt
?? tests/Architecture/fixtures/surface-removal/ocr-flag/unresolved-trait-static-call.php.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/negative-fqcn-bare-shortname.sh.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/negative-fqcn-other-namespace.sh.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/negative-fqcn-suffix.sh.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/negative-multiple-namespaces.php.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/positive-fqcn-noext.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/positive-fqcn-shell.sh.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/positive-fqcn-workflow.yaml.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/positive-kernel-property-middleware.php.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/positive-kernel-property-priority.php.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-append-to-group.php.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-string-escaped.php.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-class-string.php.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-group.php.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-prepend-to-group.php.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/positive-middleware-without.php.txt
?? tests/Architecture/fixtures/surface-removal/password-confirm/positive-multiple-namespaces.php.txt

```

## 修正後の実測

- `composer phpstan` (level 10, 1010 files): **No errors** (widen / baseline / ignore 無し)
- `vendor/bin/pint --test`: passed
- 触った gate 5 本の合計: **55 tests / 55 passed / 1025 assertions**
  (`PasswordConfirmSurfaceAbsenceGateTest` 18 / `OcrFeatureFlagAbsenceGateTest` 17 /
   `PasswordConfirmMiddlewareAbsenceTest` 3 / `TemplateDivergenceLedgerFormatTest` 2 /
   `TemplateDivergenceFingerprintTest` 15)
- 全体レーン (`composer test` / `pnpm test` / `pnpm test:packages`) は**再取得中**であり、
  結果が出るまでコミットしない。

## 追加の fail-first 実測 (今回の修正が本当に効いていることの裏取り)

| 修正を戻した箇所 | 赤くなった検査 |
|---|---|
| group use の要素種別保持を外す (印だけ読み飛ばす旧実装) | 「関数・定数の取り込みが同名クラスの解決へ影響しない」が `positive-mixed-group-use-function.php.txt` で赤 |
| メソッド宣言の `depthAt() === bodyDepth` 検査を外す | 「メソッド宣言は型の本体の直下だけを数える」が `negative-nested-function-declaration.php.txt:11 Declaration` で赤 |

## 意図的に採らなかった案

- `MiddlewareReference` を種別ごとの型 2 本へ割る案は採らず、gate 側で
  `resolvedFqcn === null` を未解決へ落とす形にした。値オブジェクトを 2 本に割る手間に対して
  得られる保証が同じであり、思考原則 2 (今必要なものだけ作る) に照らして過剰と判断した。
  反対意見があれば根拠を示してほしい。

## 差分 (Round 1 からの修正部分。見本ファイルの追加は上の git status を参照)

```diff
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 14198914..b7d58bba 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 36 件
+登録エントリ: 37 件
 
 ## 記録の原則
 
@@ -2259,3 +2259,62 @@ ### 関連
 
 - 実装: `tests/Architecture/PasskeyPackageContractTest.php`
 - 設計: `devnotes/20260821-2015-auth-method-change-notification/`
+
+---
+
+## D40 撤去表面の不在 gate を、走査根と走査器を共通基盤へ切り出した形で持つ
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Support/SurfaceRemoval/ContentClassification.php` / `tests/Support/SurfaceRemoval/MethodReference.php` / `tests/Support/SurfaceRemoval/MethodReferenceKind.php` / `tests/Support/SurfaceRemoval/MiddlewareReference.php` / `tests/Support/SurfaceRemoval/MiddlewareReferenceKind.php` / `tests/Support/SurfaceRemoval/Occurrence.php` / `tests/Support/SurfaceRemoval/PhpNameResolver.php` / `tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php` / `tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php` / `tests/Support/SurfaceRemoval/RemovedTerm.php` / `tests/Support/SurfaceRemoval/ScanOutcome.php` / `tests/Support/SurfaceRemoval/ScanPopulation.php` / `tests/Support/SurfaceRemoval/ScannedFile.php` / `tests/Support/SurfaceRemoval/TermMatchMode.php` / `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php` / `tests/Architecture/OcrFeatureFlagAbsenceGateTest.php` |
+| 業務要件起因の説明 | aicue が撤去した表面 (Fortify 標準のパスワード確認 step-up 機構 / OCR 機能フラグ) はテンプレートには存在しない。撤去物が 2 件あり、走査根 (`.github` と `scripts` を含む 8 本) の列挙と PHP の名前解決を 2 本持たないために共通基盤へ切り出す必要がある |
+| 揃え続ける不変条件と保証機構 | 走査根に `.github/` と `scripts/` を含み `database/migrations/` を含まないこと、実走査母集団が根・種別ごとに非空で未解決もバイナリ除外も 0 件であること、静的層が許可形を 0 個で保つこと、検出器の自己検証を正例・負例・未解決の三軸で持つこと。`PasswordConfirmSurfaceAbsenceGateTest` と `OcrFeatureFlagAbsenceGateTest` が固定する |
+| 再判定の条件 | 3 件目の撤去物が来て、撤去項目の台帳から層を機械駆動する形へ移すとき。またはテンプレートが同じ共通基盤を取り込んだとき (そのときは上積みを撤去して正典実装へ揃え直す) |
+| 決めた日 | 2026-08-22 |
+| 決めた人 | 開発者 |
+| 根拠 | T250 |
+| 状態 | 恒久 |
+| 見直し期限 | — |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 走査根の持ち方 | 撤去 1 件ごとに gate 自身のファイル内へ走査を書く (`RetiredRecoveryReferenceGateTest`) | 走査根と走査器を `tests/Support/SurfaceRemoval/` へ切り出し、許可ポリシーは撤去物ごとの gate が指定する |
+| 名前の突合 | 語彙一致中心 | クラス参照は完全修飾名へ解決してから突合する (`PhpNameResolver`)。解決できない形は未解決として gate を落とす |
+| 母集団 | 拡張子で絞った列挙 | `git ls-files` から生成し拡張子で絞らない (`scripts/` の拡張子なし実行ファイルを落とさない) |
+
+### なぜ正当な差分か (logic-driven)
+
+同じ家系正典 (`surface-removal-absence-gate` v1) を満たす形は 1 つではない。テンプレートは
+撤去物が 1 件のため gate のファイル内に走査を閉じているが、aicue は撤去物が **2 件**あり、
+両者が同じ走査根 (8 本) と同じ PHP 名前解決を要る。ここで各 gate に走査を複写すると
+「走査根の列挙を 2 本持つ」ことになり、AGENTS.md「静的検査 (gate) と走査器の共通規約」の
+**走査根の単一出典**に反する。したがって共通基盤へ切り出す側を選んだ。
+
+3 件目が来たら台帳駆動へ移す判断が要るが、2 件のために台帳機構を先回りして作るのは
+思考原則 2 (今必要なものだけ作る) に反するため v1 では作らない。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「**各 gate が列挙した静的構文**への参照は、走査根 8 本の git 追跡下の全ファイルで 0 件である。
+> 許可一覧は持たない (母集団の定義そのもので絞る)。解決できない形は未解決として gate を落とす」
+
+保証するのは**列挙した構文**についてであり、「あらゆる書き方で 0 件」ではない
+(変数・式・分割連結・定数経由・動的組み立ては母集団に入らない。下の「保証しないもの」を参照)。
+
+- 母集団の空振り (走査根の改名・ディレクトリ移動) は代表パス pin と種別検査が検出する
+- 検出力は見本 (`tests/Architecture/fixtures/surface-removal/`) の正例・負例・未解決で裏取りする
+- NUL を 1 つ入れて静的層を迂回する経路は `binaryExcluded === []` の要求が塞ぐ
+
+### 保証しないもの
+
+- 静的層が見るのは列挙した構文だけである。middleware 位置の変数・式、分割連結、定数経由、
+  動的組み立て、PHP のコメント内には沈黙する。網羅的な一覧の正本は
+  `RemovedSurfaceScanner` と各 gate の docblock であり、ここには写さない
+- 実行時層が補完するのは**テスト起動時に実体化した route** までで、環境依存で実体化しない
+  経路 (production 限定の条件分岐・未実行コード) は両層とも見えない
+
+### 関連
+
+- 実装: `tests/Support/SurfaceRemoval/` / `tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php` / `tests/Architecture/OcrFeatureFlagAbsenceGateTest.php`
+- 実行時層: `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php`
+- 設計: `devnotes/20260823-0016-password-confirm-surface-removal-gate-v1/`
diff --git a/tests/Architecture/OcrFeatureFlagAbsenceGateTest.php b/tests/Architecture/OcrFeatureFlagAbsenceGateTest.php
new file mode 100644
index 00000000..fac7ab42
--- /dev/null
+++ b/tests/Architecture/OcrFeatureFlagAbsenceGateTest.php
@@ -0,0 +1,435 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\Manual\AcceptedSourceDocumentTypes;
+use Illuminate\Support\Arr;
+use Tests\Support\SurfaceRemoval\MethodReference;
+use Tests\Support\SurfaceRemoval\MiddlewareReference;
+use Tests\Support\SurfaceRemoval\Occurrence;
+use Tests\Support\SurfaceRemoval\RemovedSurfaceScanner;
+use Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets;
+use Tests\Support\SurfaceRemoval\RemovedTerm;
+use Tests\Support\SurfaceRemoval\ScannedFile;
+use Tests\Support\SurfaceRemoval\ScanOutcome;
+use Tests\Support\SurfaceRemoval\TermMatchMode;
+
+/*
+ * 撤去した OCR 機能フラグ (`manual.ocr_analysis_enabled` / `AcceptedSourceDocumentTypes::imagesEnabled()` /
+ * props `imageSourceDocumentsEnabled`) の**不在**を固定する gate
+ * (家系正典 surface-removal-absence-gate v1。実行時層 + 静的層 + 自己検証)。
+ *
+ * 画像・スキャン SOP の OCR 対応は**オーナー決定により常時有効**で、rollout gate は撤去済み。
+ * フラグが復活すると「受理形式の唯一の情報源」が 2 つに割れ、FormRequest / Service /
+ * Inertia Props の受理形式が食い違う (T242 で撤去したのはその割れそのもの)。
+ *
+ * ★**撤去物 × 実行時観測軸** (正典 I1。該当しない軸は理由つきで宣言する):
+ *   - route 名の不在 / メソッド×URI の不在 / 実 HTTP 404 … **該当なし** (設定値とクラスメソッドであり
+ *     route を持たない)
+ *   - クラス・表の不在 … **該当なし** (`AcceptedSourceDocumentTypes` は現役で、削除された表も無い)
+ *   - 機構に対応する等価の実行時層 … 本ファイルの実行時層 2 本
+ *     (設定木にキーが無いこと / メソッドが実行時に存在しないこと)
+ *
+ * ★**消しすぎていないことの確認は二重に持たない**。画像受理が現役であることは既存テストが担保する:
+ *   - `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php`
+ *     - `画像 (jpg/jpeg/png) を含む (常時有効)`
+ *     - `前提の pin: 拡張子集合が現在値ちょうど (ずれたらラベルの見直しが必要)`
+ *   - `tests/Feature/Projects/SourceDocumentUploadOcrTest.php`
+ *     - `jpg/png アップロードが成功する`
+ *     - `公開面の一貫性: FormRequest / Service / Inertia Props (create/show) が同じ受理形式 (画像込み) を表す`
+ *
+ * ★走査対象は `RemovedSurfaceScanTargets` の走査根 8 本の git 追跡下の全ファイル
+ *   (`database/migrations` は含めない)。**許可形は全 Tier で 0 個**である。
+ *
+ * ★`imagesEnabled` を**素のトークン一致で見ない**理由: 一般名すぎて、将来 OCR と無関係な
+ *   同名メソッドが必要になったときに全 production surface を止めてしまう。よって PHP 側は
+ *   **対象クラスの完全修飾名を基準にした宣言形・静的呼び出し形だけ**を見る。
+ *   非 PHP 側で裸の `imagesEnabled` を見ないのは、非 PHP から実行可能な参照になるには
+ *   クラスの完全修飾名が要るからである (完全修飾の参照文字列のほうは 0 件固定する)。
+ *
+ * ★**trait 経由の混入 (v1 の役割分担。誇張しない)**: v1 は **trait-use graph を扱わない**。
+ *   - trait 宣言そのものの `imagesEnabled` は**対象クラスの宣言として認識しない**
+ *   - 対象クラスが trait を取り込んでいる形と、trait 内の `self` / `static` / `parent` を
+ *     受け手にした `::imagesEnabled` 参照は**未解決として落とす** (fail-closed)
+ *   - それでも trait 経由で実際に混入した場合は、**実行時層の `method_exists()` が検出する**
+ *
+ * ★**保証しないもの**の正本は `RemovedSurfaceScanner` の docblock
+ *   (分割連結・定数経由・動的組み立て・PHP のコメント内・middleware 位置の変数式)。
+ * ★自己検証は本ファイル下部の「検出器の自己検証」節が持つ
+ *   (見本: `tests/Architecture/fixtures/surface-removal/ocr-flag/`)。
+ */
+
+/** 撤去した対象クラスの完全修飾名 (静的層の基準)。 */
+function ocrFeatureFlagTargetClass(): string
+{
+    return AcceptedSourceDocumentTypes::class;
+}
+
+/** 撤去したメソッド名。 */
+function ocrFeatureFlagTargetMethod(): string
+{
+    return 'imagesEnabled';
+}
+
+/**
+ * Tier 1 / Tier 2 に共通して 0 件固定する撤去語 (語ごとに一致様式を宣言する)。
+ *
+ * @return list<RemovedTerm>
+ */
+function ocrFeatureFlagRemovedTerms(): array
+{
+    return [
+        // 設定パス表記 (`manual.ocr_analysis_enabled`) に当てるため run の segment 一致
+        new RemovedTerm('ocr_analysis_enabled', TermMatchMode::RunSegment),
+        new RemovedTerm('OCR_ANALYSIS_ENABLED', TermMatchMode::ExactRun),
+        new RemovedTerm('imageSourceDocumentsEnabled', TermMatchMode::ExactRun),
+    ];
+}
+
+/** 非 PHP に 0 件固定する完全修飾参照。 */
+function ocrFeatureFlagFqcnTerm(): RemovedTerm
+{
+    return new RemovedTerm(
+        ocrFeatureFlagTargetClass().'::'.ocrFeatureFlagTargetMethod(),
+        TermMatchMode::FqcnMethodReference,
+    );
+}
+
+/** 見本ディレクトリ。 */
+function ocrFeatureFlagFixtureDirectory(): string
+{
+    return __DIR__.'/fixtures/surface-removal/ocr-flag';
+}
+
+/** 見本を走査対象として読み込む (**PHP として扱うかは引数で明示する**)。 */
+function ocrFeatureFlagFixtureFile(string $name, bool $isPhp): ScannedFile
+{
+    $path = ocrFeatureFlagFixtureDirectory().'/'.$name;
+    $contents = file_get_contents($path);
+    if ($contents === false) {
+        throw new RuntimeException("見本を読めません: {$name}");
+    }
+
+    return new ScannedFile('fixtures', 'fixtures/'.$name, $contents, $isPhp, 'txt');
+}
+
+/**
+ * 撤去物への参照を 4 つの検出対象へ分けて返す。
+ *
+ * ★**production の検査と自己検証は必ずこの 1 本を通る** (判定を 2 本持たない)。
+ *
+ * @param  list<ScannedFile>  $files
+ * @return array{lexemes: list<string>, texts: list<string>, methods: list<string>, fqcnTexts: list<string>, unresolved: list<string>}
+ */
+function ocrFeatureFlagFindings(array $files): array
+{
+    $nonPhp = array_values(array_filter($files, static fn (ScannedFile $file): bool => ! $file->isPhp));
+
+    $lexemes = [];
+    $texts = [];
+    /** @var list<ScanOutcome<Occurrence|MiddlewareReference|MethodReference>> $outcomes */
+    $outcomes = [];
+
+    foreach (ocrFeatureFlagRemovedTerms() as $term) {
+        $php = RemovedSurfaceScanner::scanPhpLexemes($files, $term);
+        $text = RemovedSurfaceScanner::scanText($nonPhp, $term);
+        $outcomes[] = $php;
+        $outcomes[] = $text;
+        $lexemes = [...$lexemes, ...$php->descriptions()];
+        $texts = [...$texts, ...$text->descriptions()];
+    }
+
+    $methods = RemovedSurfaceScanner::scanMethodReferences(
+        $files,
+        ocrFeatureFlagTargetClass(),
+        ocrFeatureFlagTargetMethod(),
+    );
+    $fqcnTexts = RemovedSurfaceScanner::scanText($nonPhp, ocrFeatureFlagFqcnTerm());
+    $outcomes[] = $methods;
+    $outcomes[] = $fqcnTexts;
+
+    return [
+        'lexemes' => $lexemes,
+        'texts' => $texts,
+        'methods' => $methods->descriptions(),
+        'fqcnTexts' => $fqcnTexts->descriptions(),
+        'unresolved' => ScanOutcome::mergeUnresolved($outcomes),
+    ];
+}
+
+/**
+ * 見本の正例 (検出経路と、経路別の前提検査)。
+ *
+ * ★一律の `str_contains($contents, $term)` は使わない — `self::imagesEnabled()` は対象の
+ *   完全修飾名を含まず、大小違いの正例は canonical 表記を含まないため成立しない。
+ *
+ * @return list<array{file: string, php: bool, buckets: list<string>, requires: list<string>}>
+ */
+function ocrFeatureFlagPositiveFixtures(): array
+{
+    return [
+        ['file' => 'positive-config-key.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['ocr_analysis_enabled']],
+        ['file' => 'positive-config-path.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['manual.ocr_analysis_enabled']],
+        ['file' => 'positive-class-const.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['OCR_ANALYSIS_ENABLED', 'const']],
+        ['file' => 'positive-property.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['$imageSourceDocumentsEnabled']],
+        ['file' => 'positive-variable.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['$ocr_analysis_enabled']],
+        ['file' => 'positive-heredoc.php.txt', 'php' => true, 'buckets' => ['lexemes'], 'requires' => ['imageSourceDocumentsEnabled', '<<<']],
+        ['file' => 'positive-env.sh.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['OCR_ANALYSIS_ENABLED']],
+        ['file' => 'positive-prop.svelte.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['imageSourceDocumentsEnabled']],
+        ['file' => 'positive-method-declaration.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['acceptedsourcedocumenttypes', 'namespace', 'imagesenabled']],
+        ['file' => 'positive-method-declaration-bracketed.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['acceptedsourcedocumenttypes', 'namespace', 'imagesenabled']],
+        ['file' => 'positive-static-call-use.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '::']],
+        ['file' => 'positive-static-call-alias.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', ' as ']],
+        ['file' => 'positive-static-call-groupuse-alias.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '{']],
+        ['file' => 'positive-static-call-fqcn.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '::']],
+        ['file' => 'positive-static-call-relative.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'namespace\\']],
+        ['file' => 'positive-static-call-same-namespace.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'namespace']],
+        ['file' => 'positive-self-call.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'self::']],
+        ['file' => 'positive-static-keyword-call.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'static::']],
+        ['file' => 'positive-case-insensitive.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', '::']],
+        ['file' => 'positive-parent-call.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'parent::', 'extends']],
+        ['file' => 'positive-mixed-group-use-function.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'use app\\other\\{function']],
+        ['file' => 'positive-use-function-same-name.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'use function']],
+        ['file' => 'positive-use-const-same-name.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'use const']],
+        ['file' => 'positive-multiple-namespaces.php.txt', 'php' => true, 'buckets' => ['methods'], 'requires' => ['imagesenabled', 'namespace app\\support\\manual']],
+        ['file' => 'positive-fqcn-in-text.sh.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['::', 'imagesenabled']],
+        ['file' => 'positive-fqcn-leading-backslash.sh.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['::', 'imagesenabled']],
+        ['file' => 'positive-fqcn-case.yaml.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['::', 'imagesenabled']],
+    ];
+}
+
+/**
+ * 見本の負例 (反応してはならない。未解決にもならない)。
+ *
+ * @return list<array{file: string, php: bool}>
+ */
+function ocrFeatureFlagNegativeFixtures(): array
+{
+    return [
+        ['file' => 'negative-other-class-declaration.php.txt', 'php' => true],
+        ['file' => 'negative-other-class-static-call.php.txt', 'php' => true],
+        ['file' => 'negative-self-in-other-class.php.txt', 'php' => true],
+        ['file' => 'negative-target-other-method.php.txt', 'php' => true],
+        ['file' => 'negative-method-suffix.php.txt', 'php' => true],
+        ['file' => 'negative-dynamic-call.php.txt', 'php' => true],
+        ['file' => 'negative-nested-function-declaration.php.txt', 'php' => true],
+        ['file' => 'negative-anonymous-class-method.php.txt', 'php' => true],
+        ['file' => 'negative-suffix.php.txt', 'php' => true],
+        ['file' => 'negative-prefix.php.txt', 'php' => true],
+        ['file' => 'negative-negated.php.txt', 'php' => true],
+        ['file' => 'negative-php-comment.php.txt', 'php' => true],
+        ['file' => 'negative-bare-imagesenabled.sh.txt', 'php' => false],
+    ];
+}
+
+test('撤去した OCR フラグの設定キーが設定木に存在しない', function (): void {
+    $manual = config('manual');
+    // ★ is_array で絞り込む (expect()->toBeArray() は PHPStan の型を絞らない)
+    if (! is_array($manual)) {
+        throw new RuntimeException('設定木 manual を配列として解決できない');
+    }
+
+    // ★値ではなく**キーの存在**で判定する (null 値で復活しても落ちるように)
+    expect(Arr::has($manual, 'ocr_analysis_enabled'))->toBeFalse();
+
+    // ★母集団が空なのに緑になる形を作らない (設定木そのものが読めていることの確認)
+    expect(Arr::has($manual, 'source_document_mimes'))->toBeTrue();
+});
+
+test('撤去した imagesEnabled メソッドが実行時に存在しない', function (): void {
+    expect(method_exists(AcceptedSourceDocumentTypes::class, 'imagesEnabled'))->toBeFalse();
+    // ★クラス自体は現役である (消しすぎていないことの最小確認)
+    expect(method_exists(AcceptedSourceDocumentTypes::class, 'extensions'))->toBeTrue();
+});
+
+test('母集団に未解決もバイナリ除外も無い', function (): void {
+    $population = RemovedSurfaceScanTargets::population();
+
+    expect($population->unresolved)->toBe([]);
+    expect($population->binaryExcluded)->toBe([]);
+    expect(count($population->files))->toBeGreaterThan(0);
+});
+
+test('撤去した 3 語が走査根の PHP lexeme に 1 件も無い', function (): void {
+    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);
+
+    expect($findings['lexemes'])->toBe(
+        [],
+        'PHP lexeme への撤去語の再流入: '.implode(', ', $findings['lexemes']),
+    );
+});
+
+test('撤去した 3 語が走査根の非 PHP に 1 件も無い', function (): void {
+    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);
+
+    expect($findings['texts'])->toBe(
+        [],
+        '非 PHP への撤去語の再流入: '.implode(', ', $findings['texts']),
+    );
+});
+
+test('imagesEnabled の宣言と静的呼び出しが対象クラスに 1 件も無い', function (): void {
+    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);
+
+    expect($findings['methods'])->toBe(
+        [],
+        'imagesEnabled の再流入: '.implode(', ', $findings['methods']),
+    );
+});
+
+test('非 PHP に完全修飾の imagesEnabled 参照が 1 件も無い', function (): void {
+    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);
+
+    expect($findings['fqcnTexts'])->toBe(
+        [],
+        '非 PHP への完全修飾参照の再流入: '.implode(', ', $findings['fqcnTexts']),
+    );
+});
+
+test('走査で未解決が 1 件も出ていない', function (): void {
+    $findings = ocrFeatureFlagFindings(RemovedSurfaceScanTargets::population()->files);
+
+    expect($findings['unresolved'])->toBe(
+        [],
+        '解決できない形が残っている: '.implode(', ', $findings['unresolved']),
+    );
+});
+
+test('検出器の自己検証: 正例をすべて検出する', function (): void {
+    foreach (ocrFeatureFlagPositiveFixtures() as $fixture) {
+        $file = ocrFeatureFlagFixtureFile($fixture['file'], $fixture['php']);
+
+        // ★経路別の前提検査 (見本が壊れて静かに空振りするのを防ぐ)
+        foreach ($fixture['requires'] as $needle) {
+            expect(str_contains(strtolower($file->contents), strtolower($needle)))
+                ->toBeTrue("見本 {$fixture['file']} が前提の綴り「{$needle}」を含まない");
+        }
+
+        $findings = ocrFeatureFlagFindings([$file]);
+        expect($findings['unresolved'])->toBe([], "正例 {$fixture['file']} が未解決になった");
+
+        foreach ($fixture['buckets'] as $bucket) {
+            expect(count($findings[$bucket]))
+                ->toBeGreaterThan(0, "正例 {$fixture['file']} を {$bucket} で検出できない");
+        }
+    }
+});
+
+test('検出器の自己検証: 負例に反応しない', function (): void {
+    foreach (ocrFeatureFlagNegativeFixtures() as $fixture) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($fixture['file'], $fixture['php'])]);
+
+        expect($findings['lexemes'])->toBe([], "負例 {$fixture['file']} に lexeme で反応した");
+        expect($findings['texts'])->toBe([], "負例 {$fixture['file']} に text で反応した");
+        expect($findings['methods'])->toBe([], "負例 {$fixture['file']} に method で反応した");
+        expect($findings['fqcnTexts'])->toBe([], "負例 {$fixture['file']} に fqcn で反応した");
+        expect($findings['unresolved'])->toBe([], "負例 {$fixture['file']} が未解決になった");
+    }
+});
+
+test('検出器の自己検証: 同じ短名を持つ別クラスに反応しない', function (): void {
+    $fixtures = [
+        ['file' => 'negative-same-shortname-declaration.php.txt', 'php' => true],
+        ['file' => 'negative-same-shortname-static-call.php.txt', 'php' => true],
+        ['file' => 'negative-fqcn-other-namespace.sh.txt', 'php' => false],
+    ];
+
+    foreach ($fixtures as $fixture) {
+        $file = ocrFeatureFlagFixtureFile($fixture['file'], $fixture['php']);
+        // 短名一致へ退行したら赤くなる見本であること (前提検査)
+        expect(str_contains($file->contents, 'AcceptedSourceDocumentTypes'))->toBeTrue();
+
+        $findings = ocrFeatureFlagFindings([$file]);
+        expect($findings['methods'])->toBe([], "同じ短名の別クラス {$fixture['file']} に反応した");
+        expect($findings['fqcnTexts'])->toBe([], "同じ短名の別クラス {$fixture['file']} に fqcn で反応した");
+        expect($findings['unresolved'])->toBe([], "同じ短名の別クラス {$fixture['file']} が未解決になった");
+    }
+});
+
+test('検出器の自己検証: FQCN 様式の境界', function (): void {
+    $shouldMatch = [
+        'positive-fqcn-in-text.sh.txt',           // 先頭 `\` 無し
+        'positive-fqcn-leading-backslash.sh.txt', // 先頭 `\` あり
+        'positive-fqcn-case.yaml.txt',            // ASCII 大小違い
+    ];
+    $shouldNotMatch = [
+        'negative-fqcn-other-namespace.sh.txt',  // 同じ短名の別 namespace
+        'negative-fqcn-other-method.sh.txt',     // 対象クラスだが別メソッド
+        'negative-fqcn-method-suffix.sh.txt',    // メソッド名の接尾辞つき
+        'negative-bare-imagesenabled.sh.txt',    // 裸のメソッド名 (完全修飾でない)
+    ];
+
+    foreach ($shouldMatch as $name) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, false)]);
+        expect(count($findings['fqcnTexts']))->toBeGreaterThan(0, "FQCN 正例 {$name} を検出できない");
+    }
+
+    foreach ($shouldNotMatch as $name) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, false)]);
+        expect($findings['fqcnTexts'])->toBe([], "FQCN 負例 {$name} に反応した");
+    }
+});
+
+test('検出器の自己検証: 解決できないクラス参照は未解決になる', function (): void {
+    foreach (['unresolved-dynamic-class-static-call.php.txt', 'unresolved-parent-without-extends.php.txt'] as $name) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, true)]);
+
+        expect(count($findings['unresolved']))->toBeGreaterThan(0, "{$name} が未解決にならない");
+        expect($findings['methods'])->toBe([], "{$name} を解決済みの違反として数えた");
+    }
+});
+
+test('検出器の自己検証: 関数・定数の取り込みが同名クラスの解決へ影響しない', function (): void {
+    // PHP は関数・定数とクラスの取り込み空間が別。`use function A\B\X` があっても
+    // クラス `X` は現在 namespace のものへ解決される (印だけ読み飛ばすと別 namespace へ誤解決する)
+    $names = [
+        'positive-mixed-group-use-function.php.txt',
+        'positive-use-function-same-name.php.txt',
+        'positive-use-const-same-name.php.txt',
+    ];
+
+    foreach ($names as $name) {
+        $file = ocrFeatureFlagFixtureFile($name, true);
+        // 別 namespace の同名を取り込んでいる見本であること (前提検査)
+        expect(str_contains($file->contents, 'App\\Other\\'))->toBeTrue();
+
+        $findings = ocrFeatureFlagFindings([$file]);
+        expect(count($findings['methods']))->toBeGreaterThan(0, "{$name} を検出できない (誤解決)");
+        expect($findings['unresolved'])->toBe([]);
+    }
+});
+
+test('検出器の自己検証: メソッド宣言は型の本体の直下だけを数える', function (): void {
+    // メソッドの中の名前付き関数 / 型の中の無名クラスのメソッドは宣言として数えない
+    foreach (['negative-nested-function-declaration.php.txt', 'negative-anonymous-class-method.php.txt'] as $name) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, true)]);
+
+        expect($findings['methods'])->toBe([], "{$name} をメソッド宣言として誤検出した");
+        expect($findings['unresolved'])->toBe([]);
+    }
+});
+
+test('検出器の自己検証: trait 内の self/static/parent と対象クラスの trait 取り込みは未解決になる', function (): void {
+    $names = [
+        'unresolved-trait-self-call.php.txt',
+        'unresolved-trait-static-call.php.txt',
+        'unresolved-trait-parent-call.php.txt',
+        'unresolved-trait-used-by-target.php.txt',
+    ];
+
+    foreach ($names as $name) {
+        $findings = ocrFeatureFlagFindings([ocrFeatureFlagFixtureFile($name, true)]);
+
+        expect(count($findings['unresolved']))->toBeGreaterThan(0, "{$name} が未解決にならない");
+        // ★誤って「解決済みの違反」として数えていないこと (fail-open でも fail-loud でもない形を防ぐ)
+        expect($findings['methods'])->toBe([]);
+    }
+});
+
+test('検出器の自己検証: 壊れた PHP は未解決になる', function (): void {
+    $findings = ocrFeatureFlagFindings([
+        ocrFeatureFlagFixtureFile('unresolved-broken-php.php.txt', true),
+    ]);
+
+    expect(count($findings['unresolved']))->toBeGreaterThan(0);
+});
diff --git a/tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php b/tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php
new file mode 100644
index 00000000..a3557508
--- /dev/null
+++ b/tests/Architecture/PasswordConfirmSurfaceAbsenceGateTest.php
@@ -0,0 +1,480 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Auth\Middleware\RequirePassword;
+use Tests\Support\SurfaceRemoval\ContentClassification;
+use Tests\Support\SurfaceRemoval\MiddlewareReference;
+use Tests\Support\SurfaceRemoval\MiddlewareReferenceKind;
+use Tests\Support\SurfaceRemoval\RemovedSurfaceScanner;
+use Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets;
+use Tests\Support\SurfaceRemoval\RemovedTerm;
+use Tests\Support\SurfaceRemoval\ScannedFile;
+use Tests\Support\SurfaceRemoval\ScanOutcome;
+use Tests\Support\SurfaceRemoval\ScanPopulation;
+use Tests\Support\SurfaceRemoval\TermMatchMode;
+
+/*
+ * 撤去した Fortify 標準 step-up 機構 (`password.confirm` middleware) の
+ * **参照の再流入**を字句で止める gate (家系正典 surface-removal-absence-gate v1 の静的層)。
+ *
+ * ★走査対象: `Tests\Support\SurfaceRemoval\RemovedSurfaceScanTargets` の走査根 8 本
+ *   (`.github` / `app` / `bootstrap` / `config` / `lang` / `resources` / `routes` / `scripts`) の
+ *   git 追跡下の全ファイル。`database/migrations` は含めない (理由は同クラスの docblock)。
+ * ★検出対象は「撤去した middleware の**適用・登録を表す構文**」であり、
+ *   文字列 `password.confirm` の全出現ではない。したがって `config/seo.php` の
+ *   route 名対応表 (`app_titles`) は**母集団に入らず**、除外規則を持たない。
+ *   **許可一覧は 0 個**である。
+ * ★middleware 位置の定義 (M1〜M3) は
+ *   `RemovedSurfaceScanner::scanMiddlewarePositions()` の docblock が正本。
+ *
+ * ★**保証しないもの (検出力を誇張しない)**:
+ *   - 列挙した middleware 位置 (M1〜M3) の**外**は**静的層の保証外**である。
+ *     実行時層 (`PasswordConfirmMiddlewareAbsenceTest`。解決済み middleware の全数走査、
+ *     deny-by-default) が**テスト起動時に実体化した全 route について補完する**が、
+ *     **環境依存で実体化しない経路 (production 限定の条件分岐・未実行コード) までは保証しない**。
+ *   - middleware 位置の**変数・式** (`->middleware($alias)` /
+ *     `->middleware('throttle:'.$limiter)`) はクラス参照でも文字列リテラルでもないため
+ *     母集団に入らない。これは免除ではなく**規則段階の定義**であり、
+ *     見本 `negative-dynamic-middleware-value.php.txt` が「沈黙すること」を固定している。
+ *   - 分割連結・定数経由・動的組み立て・PHP のコメント内には沈黙する。
+ *   - NUL を含むファイルは母集団に入らない (ただし `binaryExcluded === []` を要求する)。
+ * ★自己検証は本ファイル下部の「検出器の自己検証」節が持つ
+ *   (見本: `tests/Architecture/fixtures/surface-removal/password-confirm/` と
+ *   `tests/Architecture/fixtures/surface-removal/content/`)。
+ */
+
+/** 撤去した alias 名 (一致様式つき)。 */
+function passwordConfirmRemovedTerm(): RemovedTerm
+{
+    return new RemovedTerm('password.confirm', TermMatchMode::ExactRun);
+}
+
+/**
+ * 撤去した実体クラスの完全修飾名 (一致様式つき)。
+ *
+ * ★middleware は**クラス名の文字列**でも指定できる (`->middleware('Illuminate\\…\\RequirePassword')`)。
+ *   また拡張子なしの PHP スクリプト・シェル・YAML など「PHP として扱わないファイル」からも
+ *   実行可能な参照になり得るので、Tier 2 でもこの様式で 0 件固定する。
+ */
+function passwordConfirmRemovedClassTerm(): RemovedTerm
+{
+    return new RemovedTerm(RequirePassword::class, TermMatchMode::FqcnReference);
+}
+
+/** 実走査母集団 (プロセス内で 1 度だけ確定する)。 */
+function passwordConfirmScanPopulation(): ScanPopulation
+{
+    return RemovedSurfaceScanTargets::population();
+}
+
+/** 見本ディレクトリ。 */
+function passwordConfirmFixtureDirectory(): string
+{
+    return __DIR__.'/fixtures/surface-removal/password-confirm';
+}
+
+/**
+ * 見本を走査対象として読み込む (**PHP として扱うかは引数で明示する**。拡張子から推定しない)。
+ */
+function passwordConfirmFixtureFile(string $name, bool $isPhp): ScannedFile
+{
+    $path = passwordConfirmFixtureDirectory().'/'.$name;
+    $contents = file_get_contents($path);
+    if ($contents === false) {
+        throw new RuntimeException("見本を読めません: {$name}");
+    }
+
+    return new ScannedFile('fixtures', 'fixtures/'.$name, $contents, $isPhp, 'txt');
+}
+
+/**
+ * 撤去した機構への参照を 3 つの検出対象へ分けて返す。
+ *
+ * ★**production の検査と自己検証は必ずこの 1 本を通る** (判定を 2 本持たない)。
+ *
+ * @param  list<ScannedFile>  $files
+ * @return array{aliases: list<string>, classes: list<string>, texts: list<string>, fqcnTexts: list<string>, unresolved: list<string>}
+ */
+function passwordConfirmSurfaceFindings(array $files): array
+{
+    $term = passwordConfirmRemovedTerm();
+    $classTerm = passwordConfirmRemovedClassTerm();
+    $nonPhp = array_values(array_filter($files, static fn (ScannedFile $file): bool => ! $file->isPhp));
+
+    $middleware = RemovedSurfaceScanner::scanMiddlewarePositions($files);
+    $text = RemovedSurfaceScanner::scanText($nonPhp, $term);
+    $fqcnText = RemovedSurfaceScanner::scanText($nonPhp, $classTerm);
+
+    $aliases = [];
+    $classes = [];
+    /** @var array<string, string> $unresolved */
+    $unresolved = [];
+    foreach ($middleware->occurrences as $reference) {
+        if (! $reference instanceof MiddlewareReference) {
+            continue;
+        }
+        if ($reference->kind === MiddlewareReferenceKind::AliasString) {
+            // D1: alias 文字列 (`password.confirm` / `password.confirm:web`)。
+            //     判定は走査器と同じ 1 本のトークン一致を通す
+            if (RemovedSurfaceScanner::textMatches($reference->value, $term)) {
+                $aliases[] = $reference->describe();
+
+                continue;
+            }
+            // D2b: middleware は**クラス名の文字列**でも指定できる。
+            //      解決済みクラス参照と同じ扱いにする (`Illuminate\…\RequirePassword`)
+            if (RemovedSurfaceScanner::textMatches($reference->value, $classTerm)) {
+                $classes[] = $reference->describe();
+            }
+
+            continue;
+        }
+        // ★「解決済みのはず」を型で守れないので、null は**非該当ではなく未解決**にする
+        //   (将来の退行が黙って通り抜ける fail-open を作らない)
+        if ($reference->resolvedFqcn === null) {
+            $unresolved[$reference->relative] = sprintf(
+                'middleware 位置のクラス参照が解決済み完全修飾名を持たない (行 %d)',
+                $reference->line,
+            );
+
+            continue;
+        }
+        // D2: 完全修飾名が撤去した実体クラスへ解決されるもの
+        if (strtolower($reference->resolvedFqcn) === strtolower(RequirePassword::class)) {
+            $classes[] = $reference->describe();
+        }
+    }
+
+    return [
+        'aliases' => $aliases,
+        'classes' => $classes,
+        'texts' => $text->descriptions(),          // D3: 非 PHP の生テキストの alias
+        'fqcnTexts' => $fqcnText->descriptions(),  // D4: 非 PHP の生テキストの完全修飾クラス名
+        'unresolved' => [
+            ...ScanOutcome::mergeUnresolved([$middleware, $text, $fqcnText]),
+            ...array_map(
+                static fn (string $relative, string $reason): string => $relative.': '.$reason,
+                array_keys($unresolved),
+                array_values($unresolved),
+            ),
+        ],
+    ];
+}
+
+/**
+ * 見本の正例 (検出経路と、見本が壊れて空振りしないための**経路別の前提検査**)。
+ *
+ * ★一律の `str_contains($contents, $term)` は使わない — 大小違いの正例は canonical 表記を
+ *   含まず、alias / group use の正例は参照位置に完全修飾名を持たないため成立しない。
+ *
+ * @return list<array{file: string, php: bool, buckets: list<string>, requires: list<string>}>
+ */
+function passwordConfirmPositiveFixtures(): array
+{
+    return [
+        ['file' => 'positive-middleware-array.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'middleware(']],
+        ['file' => 'positive-middleware-arg.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'middleware(']],
+        ['file' => 'positive-middleware-param.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm:', 'middleware(']],
+        ['file' => 'positive-middleware-class.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', 'middleware(']],
+        ['file' => 'positive-middleware-class-fqcn.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', 'middleware(']],
+        ['file' => 'positive-middleware-class-alias.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', ' as ']],
+        ['file' => 'positive-middleware-class-groupuse.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', '{']],
+        ['file' => 'positive-middleware-class-relative.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', 'namespace']],
+        ['file' => 'positive-middleware-class-case.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '::class', 'middleware(']],
+        ['file' => 'positive-config-management-middleware.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'management_middleware']],
+        ['file' => 'positive-kernel-property.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', '$middlewareGroups']],
+        ['file' => 'positive-alias-registration.php.txt', 'php' => true, 'buckets' => ['aliases', 'classes'], 'requires' => ['password.confirm', 'requirepassword', 'alias(']],
+        ['file' => 'positive-middleware-class-string.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['illuminate\\auth\\middleware\\requirepassword', 'middleware(']],
+        ['file' => 'positive-middleware-class-string-escaped.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', 'middleware(']],
+        ['file' => 'positive-middleware-without.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'withoutMiddleware(']],
+        ['file' => 'positive-middleware-group.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'middlewareGroup(']],
+        ['file' => 'positive-middleware-append-to-group.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'appendToGroup(']],
+        ['file' => 'positive-middleware-prepend-to-group.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', 'prependToGroup(']],
+        ['file' => 'positive-kernel-property-middleware.php.txt', 'php' => true, 'buckets' => ['aliases'], 'requires' => ['password.confirm', '$middleware ']],
+        ['file' => 'positive-kernel-property-priority.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', '$middlewarePriority']],
+        ['file' => 'positive-multiple-namespaces.php.txt', 'php' => true, 'buckets' => ['classes'], 'requires' => ['requirepassword', 'namespace app\\second']],
+        ['file' => 'positive-fqcn-noext.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['illuminate\\auth\\middleware\\requirepassword']],
+        ['file' => 'positive-fqcn-shell.sh.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['illuminate\\auth\\middleware\\requirepassword']],
+        ['file' => 'positive-fqcn-workflow.yaml.txt', 'php' => false, 'buckets' => ['fqcnTexts'], 'requires' => ['illuminate\\auth\\middleware\\requirepassword']],
+        ['file' => 'positive-css-id-selector.css.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-css-universal.css.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-ts-generator.ts.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-svelte-markup.svelte.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-svelte-script.svelte.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-svelte-style.svelte.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-shell.sh.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-noext.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+        ['file' => 'positive-workflow.yaml.txt', 'php' => false, 'buckets' => ['texts'], 'requires' => ['password.confirm']],
+    ];
+}
+
+/**
+ * 見本の負例 (反応してはならない。未解決にもならない)。
+ *
+ * @return list<array{file: string, php: bool}>
+ */
+function passwordConfirmNegativeFixtures(): array
+{
+    return [
+        ['file' => 'negative-seo-title-map.php.txt', 'php' => true],
+        ['file' => 'negative-route-name-usage.php.txt', 'php' => true],
+        ['file' => 'negative-suffix.php.txt', 'php' => true],
+        ['file' => 'negative-prefix.php.txt', 'php' => true],
+        ['file' => 'negative-negated.php.txt', 'php' => true],
+        ['file' => 'negative-session-key.php.txt', 'php' => true],
+        ['file' => 'negative-php-comment.php.txt', 'php' => true],
+        ['file' => 'negative-other-middleware-class.php.txt', 'php' => true],
+        ['file' => 'negative-dynamic-middleware-value.php.txt', 'php' => true],
+        ['file' => 'negative-multiple-namespaces.php.txt', 'php' => true],
+        ['file' => 'negative-fqcn-other-namespace.sh.txt', 'php' => false],
+        ['file' => 'negative-fqcn-suffix.sh.txt', 'php' => false],
+        ['file' => 'negative-fqcn-bare-shortname.sh.txt', 'php' => false],
+    ];
+}
+
+test('走査根がすべて解決でき、実走査母集団が空でない', function (): void {
+    $population = passwordConfirmScanPopulation();
+
+    expect(RemovedSurfaceScanTargets::roots())->toHaveCount(8);
+
+    foreach (array_keys(RemovedSurfaceScanTargets::roots()) as $root) {
+        expect(count($population->inRoot($root)))->toBeGreaterThan(0, "走査根 {$root} の母集団が空");
+    }
+
+    expect(count($population->php()))->toBeGreaterThan(0);
+    expect(count($population->nonPhp()))->toBeGreaterThan(0);
+});
+
+test('各走査根に代表パスが含まれる', function (): void {
+    $paths = passwordConfirmScanPopulation()->relativePaths();
+
+    foreach (RemovedSurfaceScanTargets::REPRESENTATIVE_PATHS as $root => $representative) {
+        expect(in_array($representative, $paths, true))
+            ->toBeTrue("走査根 {$root} の代表パス {$representative} が母集団に無い");
+    }
+});
+
+test('scripts と .github の実走査母集団に期待する種別が含まれる', function (): void {
+    $population = passwordConfirmScanPopulation();
+
+    $scripts = $population->inRoot('scripts');
+    $withoutExtension = array_filter($scripts, static fn (ScannedFile $f): bool => $f->extension === null);
+    $shell = array_filter($scripts, static fn (ScannedFile $f): bool => $f->extension === 'sh');
+
+    expect(count($withoutExtension))->toBeGreaterThan(0, 'scripts/ に拡張子なしの実行ファイルが 1 件も無い');
+    expect(count($shell))->toBeGreaterThan(0, 'scripts/ に .sh が 1 件も無い');
+
+    $workflows = array_filter(
+        $population->inRoot('.github'),
+        static fn (ScannedFile $f): bool => str_starts_with($f->relative, '.github/workflows/')
+            && in_array($f->extension, ['yml', 'yaml'], true),
+    );
+    expect(count($workflows))->toBeGreaterThan(0, '.github/workflows/ に YAML が 1 件も無い');
+});
+
+test('母集団に未解決もバイナリ除外も無い', function (): void {
+    $population = passwordConfirmScanPopulation();
+
+    expect($population->unresolved)->toBe([]);
+    // ★NUL を 1 つ入れて静的層を迂回する経路を塞ぐ
+    expect($population->binaryExcluded)->toBe([]);
+});
+
+test('middleware 位置に password.confirm alias が 1 件も無い', function (): void {
+    $findings = passwordConfirmSurfaceFindings(passwordConfirmScanPopulation()->files);
+
+    expect($findings['aliases'])->toBe(
+        [],
+        'password.confirm alias の再流入: '.implode(', ', $findings['aliases']),
+    );
+});
+
+test('middleware 位置に RequirePassword の参照が 1 件も無い', function (): void {
+    $findings = passwordConfirmSurfaceFindings(passwordConfirmScanPopulation()->files);
+
+    expect($findings['classes'])->toBe(
+        [],
+        'RequirePassword の再流入: '.implode(', ', $findings['classes']),
+    );
+});
+
+test('非 PHP に password.confirm が 1 件も無い', function (): void {
+    $findings = passwordConfirmSurfaceFindings(passwordConfirmScanPopulation()->files);
+
+    expect($findings['texts'])->toBe(
+        [],
+        '非 PHP への password.confirm 残留: '.implode(', ', $findings['texts']),
+    );
+});
+
+test('非 PHP に RequirePassword の完全修飾参照が 1 件も無い', function (): void {
+    $findings = passwordConfirmSurfaceFindings(passwordConfirmScanPopulation()->files);
+
+    expect($findings['fqcnTexts'])->toBe(
+        [],
+        '非 PHP への RequirePassword 完全修飾参照の残留: '.implode(', ', $findings['fqcnTexts']),
+    );
+});
+
+test('走査で未解決が 1 件も出ていない', function (): void {
+    $findings = passwordConfirmSurfaceFindings(passwordConfirmScanPopulation()->files);
+
+    expect($findings['unresolved'])->toBe(
+        [],
+        '解決できない形が残っている: '.implode(', ', $findings['unresolved']),
+    );
+});
+
+test('検出器の自己検証: 正例をすべて検出する', function (): void {
+    foreach (passwordConfirmPositiveFixtures() as $fixture) {
+        $file = passwordConfirmFixtureFile($fixture['file'], $fixture['php']);
+
+        // ★経路別の前提検査 (見本が壊れて静かに空振りするのを防ぐ)
+        foreach ($fixture['requires'] as $needle) {
+            expect(str_contains(strtolower($file->contents), strtolower($needle)))
+                ->toBeTrue("見本 {$fixture['file']} が前提の綴り「{$needle}」を含まない");
+        }
+
+        $findings = passwordConfirmSurfaceFindings([$file]);
+        expect($findings['unresolved'])->toBe([], "正例 {$fixture['file']} が未解決になった");
+
+        foreach ($fixture['buckets'] as $bucket) {
+            expect(count($findings[$bucket]))
+                ->toBeGreaterThan(0, "正例 {$fixture['file']} を {$bucket} で検出できない");
+        }
+    }
+});
+
+test('検出器の自己検証: 負例に反応しない', function (): void {
+    foreach (passwordConfirmNegativeFixtures() as $fixture) {
+        $name = $fixture['file'];
+        $findings = passwordConfirmSurfaceFindings([passwordConfirmFixtureFile($name, $fixture['php'])]);
+
+        expect($findings['aliases'])->toBe([], "負例 {$name} に alias で反応した");
+        expect($findings['classes'])->toBe([], "負例 {$name} に class で反応した");
+        expect($findings['texts'])->toBe([], "負例 {$name} に text で反応した");
+        expect($findings['fqcnTexts'])->toBe([], "負例 {$name} に fqcn で反応した");
+        expect($findings['unresolved'])->toBe([], "負例 {$name} が未解決になった");
+    }
+});
+
+test('検出器の自己検証: 同じ短名を持つ別クラスに反応しない', function (): void {
+    $names = [
+        'negative-same-shortname-import.php.txt',
+        'negative-same-shortname-fqcn.php.txt',
+        'negative-alias-to-target-shortname.php.txt',
+    ];
+
+    foreach ($names as $name) {
+        $file = passwordConfirmFixtureFile($name, true);
+        // 短名一致へ退行したら赤くなる見本であること (前提検査)
+        expect(str_contains($file->contents, 'RequirePassword'))->toBeTrue();
+
+        $findings = passwordConfirmSurfaceFindings([$file]);
+        expect($findings['classes'])->toBe([], "同じ短名の別クラス {$name} に反応した");
+        expect($findings['unresolved'])->toBe([], "同じ短名の別クラス {$name} が未解決になった");
+    }
+});
+
+test('検出器の自己検証: 解決できない middleware クラス参照は未解決になる', function (): void {
+    $findings = passwordConfirmSurfaceFindings([
+        passwordConfirmFixtureFile('unresolved-dynamic-middleware-class.php.txt', true),
+    ]);
+
+    expect(count($findings['unresolved']))->toBeGreaterThan(0);
+});
+
+test('検出器の自己検証: 壊れた PHP は未解決になる', function (): void {
+    $findings = passwordConfirmSurfaceFindings([
+        passwordConfirmFixtureFile('unresolved-broken-php.php.txt', true),
+    ]);
+
+    expect(count($findings['unresolved']))->toBeGreaterThan(0);
+});
+
+test('検出器の自己検証: 内容分類が効く', function (): void {
+    $directory = __DIR__.'/fixtures/surface-removal/content';
+
+    $decode = static function (string $name) use ($directory): string {
+        $hex = file_get_contents($directory.'/'.$name);
+        if ($hex === false) {
+            throw new RuntimeException("見本を読めません: {$name}");
+        }
+        $bytes = @hex2bin((string) preg_replace('/\s+/', '', $hex));
+        if ($bytes === false) {
+            throw new RuntimeException("見本の hex を復号できません (見本の破損): {$name}");
+        }
+
+        return $bytes;
+    };
+
+    $plain = file_get_contents($directory.'/text-plain.txt');
+    expect($plain)->toBeString();
+
+    // ★population() と**同じ関数**を通す (自己検証と実母集団の経路が切れないこと)
+    expect(RemovedSurfaceScanTargets::classifyContents($decode('binary-with-nul.hex.txt')))
+        ->toBe(ContentClassification::Binary);
+    expect(RemovedSurfaceScanTargets::classifyContents($decode('invalid-utf8.hex.txt')))
+        ->toBe(ContentClassification::InvalidUtf8);
+    expect(RemovedSurfaceScanTargets::classifyContents((string) $plain))
+        ->toBe(ContentClassification::Text);
+});
+
+test('検出器の自己検証: リポジトリ内外の判定が効く', function (): void {
+    $root = '/repo';
+
+    expect(RemovedSurfaceScanTargets::isPathInsideRepository($root, '/repo/app/X.php'))->toBeTrue();
+    expect(RemovedSurfaceScanTargets::isPathInsideRepository($root, '/elsewhere/X.php'))->toBeFalse();
+    // 接頭辞が偶然一致するだけのパスは配下ではない
+    expect(RemovedSurfaceScanTargets::isPathInsideRepository($root, '/repo-other/X.php'))->toBeFalse();
+});
+
+test('検出器の自己検証: 取り込み表が namespace 区間を跨いで漏れない', function (): void {
+    // 1 ファイル内の 2 namespace。撤去クラスを取り込んだのは 2 つ目だけなので**ちょうど 1 件**
+    $findings = passwordConfirmSurfaceFindings([
+        passwordConfirmFixtureFile('positive-multiple-namespaces.php.txt', true),
+    ]);
+
+    expect($findings['classes'])->toHaveCount(1);
+    expect($findings['unresolved'])->toBe([]);
+
+    // 逆向き: どちらの namespace も別クラスなので 0 件
+    $negative = passwordConfirmSurfaceFindings([
+        passwordConfirmFixtureFile('negative-multiple-namespaces.php.txt', true),
+    ]);
+
+    expect($negative['classes'])->toBe([]);
+    expect($negative['unresolved'])->toBe([]);
+});
+
+test('検出器の自己検証: symlink の解決判定が効く', function (): void {
+    $root = sys_get_temp_dir().'/surface-removal-symlink-'.bin2hex(random_bytes(6));
+    $outside = sys_get_temp_dir().'/surface-removal-outside-'.bin2hex(random_bytes(6));
+
+    mkdir($root, 0o700, true);
+    mkdir($outside, 0o700, true);
+
+    try {
+        file_put_contents($root.'/real.txt', 'x');
+        file_put_contents($outside.'/target.txt', 'x');
+        symlink($root.'/real.txt', $root.'/inside.link');
+        symlink($outside.'/target.txt', $root.'/outside.link');
+        symlink($root.'/missing.txt', $root.'/broken.link');
+
+        // symlink でない実ファイルは理由を持たない
+        expect(RemovedSurfaceScanTargets::symlinkUnresolvedReason($root, $root.'/real.txt'))->toBeNull();
+        // リポジトリ配下へ解決される symlink も理由を持たない
+        expect(RemovedSurfaceScanTargets::symlinkUnresolvedReason($root, $root.'/inside.link'))->toBeNull();
+        // 外へ出る symlink と壊れた symlink は理由を返す (population() は unresolved へ入れる)
+        expect(RemovedSurfaceScanTargets::symlinkUnresolvedReason($root, $root.'/outside.link'))->toBeString();
+        expect(RemovedSurfaceScanTargets::symlinkUnresolvedReason($root, $root.'/broken.link'))->toBeString();
+    } finally {
+        foreach (['inside.link', 'outside.link', 'broken.link', 'real.txt'] as $name) {
+            @unlink($root.'/'.$name);
+        }
+        @unlink($outside.'/target.txt');
+        @rmdir($root);
+        @rmdir($outside);
+    }
+});
diff --git a/tests/Support/SurfaceRemoval/ContentClassification.php b/tests/Support/SurfaceRemoval/ContentClassification.php
new file mode 100644
index 00000000..9f33d3bb
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/ContentClassification.php
@@ -0,0 +1,23 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/**
+ * 走査対象ファイルの内容の分類 (バイナリ判定と UTF-8 検証の単一出典が返す値)。
+ *
+ * ★`RemovedSurfaceScanTargets::classifyContents()` **だけ**がこの値を作る。
+ *   同じ判定を 2 本持たないための型である。
+ */
+enum ContentClassification
+{
+    /** NUL を含まず UTF-8 として妥当 (実走査母集団へ入る)。 */
+    case Text;
+
+    /** NUL バイトを含む (母集団から外すが、利用側 gate は 0 件を要求する)。 */
+    case Binary;
+
+    /** NUL は無いが UTF-8 として不正 (未解決として gate を落とす)。 */
+    case InvalidUtf8;
+}
diff --git a/tests/Support/SurfaceRemoval/MethodReference.php b/tests/Support/SurfaceRemoval/MethodReference.php
new file mode 100644
index 00000000..28b636d1
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/MethodReference.php
@@ -0,0 +1,21 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/** 指定クラスのメソッド宣言 / 静的呼び出し。 */
+final readonly class MethodReference
+{
+    public function __construct(
+        public string $relative,
+        public int $line,
+        public MethodReferenceKind $kind,
+    ) {}
+
+    /** 違反説明の 1 行 (gate の失敗メッセージ用)。 */
+    public function describe(): string
+    {
+        return sprintf('%s:%d %s', $this->relative, $this->line, $this->kind->name);
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/MethodReferenceKind.php b/tests/Support/SurfaceRemoval/MethodReferenceKind.php
new file mode 100644
index 00000000..c67c3877
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/MethodReferenceKind.php
@@ -0,0 +1,15 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/** 対象クラスのメソッドに触れる形。 */
+enum MethodReferenceKind
+{
+    /** 対象クラスの本体に書かれたメソッド宣言。 */
+    case Declaration;
+
+    /** 対象クラスを受け手にした静的呼び出し (`Types::imagesEnabled()`)。 */
+    case StaticCall;
+}
diff --git a/tests/Support/SurfaceRemoval/MiddlewareReference.php b/tests/Support/SurfaceRemoval/MiddlewareReference.php
new file mode 100644
index 00000000..7c165feb
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/MiddlewareReference.php
@@ -0,0 +1,30 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/** middleware 位置に現れた参照。alias 文字列とクラス参照を区別する。 */
+final readonly class MiddlewareReference
+{
+    public function __construct(
+        public string $relative,
+        public int $line,
+        public MiddlewareReferenceKind $kind,
+        /** alias 文字列、または `X::class` の受け手の原文。 */
+        public string $value,
+        /** `ClassReference` のときの解決済み完全修飾名 (解決できない形は未解決へ入るので常に非 null)。 */
+        public ?string $resolvedFqcn,
+    ) {}
+
+    /** 違反説明の 1 行 (gate の失敗メッセージ用)。 */
+    public function describe(): string
+    {
+        return sprintf(
+            '%s:%d %s',
+            $this->relative,
+            $this->line,
+            $this->resolvedFqcn ?? $this->value,
+        );
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/MiddlewareReferenceKind.php b/tests/Support/SurfaceRemoval/MiddlewareReferenceKind.php
new file mode 100644
index 00000000..80d845b0
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/MiddlewareReferenceKind.php
@@ -0,0 +1,15 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/** middleware 位置に現れた参照の種別。 */
+enum MiddlewareReferenceKind
+{
+    /** 文字列リテラル (alias 名。`password.confirm` / `password.confirm:web`)。 */
+    case AliasString;
+
+    /** `X::class` 形のクラス参照 (完全修飾名へ解決済み)。 */
+    case ClassReference;
+}
diff --git a/tests/Support/SurfaceRemoval/Occurrence.php b/tests/Support/SurfaceRemoval/Occurrence.php
new file mode 100644
index 00000000..c036a45a
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/Occurrence.php
@@ -0,0 +1,22 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/** 撤去語の出現 (どこに何行目で出たか)。 */
+final readonly class Occurrence
+{
+    public function __construct(
+        public string $relative,
+        public int $line,
+        /** 一致した run (診断用の原文)。 */
+        public string $matched,
+    ) {}
+
+    /** 違反説明の 1 行 (gate の失敗メッセージ用)。 */
+    public function describe(): string
+    {
+        return sprintf('%s:%d %s', $this->relative, $this->line, $this->matched);
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/PhpNameResolver.php b/tests/Support/SurfaceRemoval/PhpNameResolver.php
new file mode 100644
index 00000000..407b15f7
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/PhpNameResolver.php
@@ -0,0 +1,520 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/**
+ * PHP のクラス参照を**完全修飾名へ解決する**(AGENTS.md「静的検査の共通規約」(a))。
+ *
+ * 短名一致は別名つき取り込み 1 つで検査が黙り、末尾の要素だけの一致は同名の別クラスを拾う。
+ * 本クラスは `Tests\Support\PhpTokenScan::normalize()` が返すトークン列を 1 度走査して
+ * 「その位置での namespace / 取り込み表 / 囲んでいる型」を索引し、参照位置のトークンから
+ * 完全修飾名を返す。
+ *
+ * ★**対応する名前構文** (これ以外は解決しない = null を返す):
+ *   - `namespace A\B;` (文形) と `namespace A\B { … }` (ブロック形)、1 ファイル内の複数 namespace
+ *   - `use A\B\C;` / `use A\B\C as D;` / group use `use A\B\{C, D as E};`
+ *   - `T_NAME_FULLY_QUALIFIED` (`\A\B\C`) / `T_NAME_QUALIFIED` (`A\B\C`) /
+ *     `T_NAME_RELATIVE` (`namespace\C`) / `T_STRING` (短名)
+ *   - class / enum / interface の中の `self` (現在の宣言クラス) /
+ *     `static` (遅延静的束縛で別クラスになり得るが**現在の宣言クラスを候補として保守的に扱う**。
+ *     拾いすぎる方向は可・見逃す方向は不可) / `parent` (`extends` を解ければそれ、解けなければ**未解決**)
+ * ★**trait の中の `self` / `static` / `parent` はすべて未解決にする**。trait のメンバーは
+ *   利用クラスへ組み込まれるため `self` 等の意味は**利用クラスに依存する** (PHP の意味論)。
+ *   trait 自身の完全修飾名へ確定すると誤った解決済み結果になり、対象メソッドの呼び出しを
+ *   trait に置いて対象クラスが `use` する形が**静かに通ってしまう** (fail-open)。
+ *   v1 は trait-use graph を実装しないので fail-closed で落とす。
+ * ★**保証しないもの**: 動的なクラス名 (`$cls::` / 文字列変数) は解決しない (null を返し、
+ *   利用側 gate が未解決として落とす)。`use function` / `use const` は取り込み表に入れない
+ *   (クラス参照ではないため対象外)。取り込み表は **namespace 区間全体へ一様に適用する**
+ *   (使用位置より後ろに書かれた `use` も効く = 拾いすぎる方向)。
+ *   条件分岐の中で宣言されたクラスや、`class_alias()` による別名は扱わない。
+ *
+ * @phpstan-type NormalizedToken array{id: int|null, text: string, line: int}
+ * @phpstan-type NamespaceSegment array{start: int, namespace: string, uses: array<string, string>}
+ * @phpstan-type TypeSegment array{start: int, end: int, bodyDepth: int, fqcn: string, isTrait: bool, parentRaw: string|null, parentId: int|null, usesTraits: bool}
+ */
+final class PhpNameResolver
+{
+    /**
+     * @param  list<NamespaceSegment>  $namespaceSegments
+     * @param  list<TypeSegment>  $typeSegments
+     * @param  list<int>  $depths  トークン位置 => その位置の波括弧の深さ
+     */
+    private function __construct(
+        private readonly array $namespaceSegments,
+        private readonly array $typeSegments,
+        private readonly array $depths,
+    ) {}
+
+    /**
+     * トークン列を索引する。
+     *
+     * @param  list<NormalizedToken>  $tokens
+     */
+    public static function analyze(array $tokens): self
+    {
+        /** @var list<NamespaceSegment> $namespaceSegments */
+        $namespaceSegments = [['start' => 0, 'namespace' => '', 'uses' => []]];
+        /** @var list<TypeSegment> $typeSegments */
+        $typeSegments = [];
+        /** @var list<TypeSegment> $openTypes */
+        $openTypes = [];
+        /** @var TypeSegment|null $pendingType */
+        $pendingType = null;
+        $depth = 0;
+        $count = count($tokens);
+        /** @var list<int> $depths */
+        $depths = [];
+
+        for ($i = 0; $i < $count; $i++) {
+            $id = $tokens[$i]['id'];
+            $text = $tokens[$i]['text'];
+            // ★その位置に入った時点の深さを記録する (波括弧の増減を反映する前)
+            $depths[$i] = $depth;
+
+            if (self::isOpeningBrace($id, $text)) {
+                $depth++;
+                if ($pendingType !== null) {
+                    $pendingType['start'] = $i;
+                    $pendingType['bodyDepth'] = $depth;
+                    $openTypes[] = $pendingType;
+                    $pendingType = null;
+                }
+
+                continue;
+            }
+
+            if ($id === null && $text === '}') {
+                $last = count($openTypes) - 1;
+                if ($last >= 0 && $openTypes[$last]['bodyDepth'] === $depth) {
+                    $closed = $openTypes[$last];
+                    array_pop($openTypes);
+                    $closed['end'] = $i;
+                    $typeSegments[] = $closed;
+                }
+                $depth--;
+
+                continue;
+            }
+
+            if ($id === T_NAMESPACE) {
+                $name = '';
+                $j = $i + 1;
+                while ($j < $count && in_array($tokens[$j]['id'], [T_STRING, T_NAME_QUALIFIED], true)) {
+                    $name .= $tokens[$j]['text'];
+                    $j++;
+                }
+                $namespaceSegments[] = ['start' => $i, 'namespace' => trim($name, '\\'), 'uses' => []];
+                for ($k = $i + 1; $k < $j; $k++) {
+                    $depths[$k] = $depth;
+                }
+                $i = $j - 1;
+
+                continue;
+            }
+
+            if ($id === T_USE) {
+                $isClosureUse = isset($tokens[$i + 1]) && $tokens[$i + 1]['id'] === null && $tokens[$i + 1]['text'] === '(';
+                if ($isClosureUse) {
+                    continue;
+                }
+                if ($openTypes !== []) {
+                    // 型の本体に書かれた `use` = trait の取り込み (v1 では追跡しない)
+                    $openTypes[count($openTypes) - 1]['usesTraits'] = true;
+
+                    continue;
+                }
+                $i = self::parseImport($tokens, $i, $namespaceSegments);
+
+                continue;
+            }
+
+            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
+                if ($i > 0 && $tokens[$i - 1]['id'] === T_DOUBLE_COLON) {
+                    continue; // `Foo::class`
+                }
+                if (! isset($tokens[$i + 1]) || $tokens[$i + 1]['id'] !== T_STRING) {
+                    continue; // 無名クラス
+                }
+                $name = $tokens[$i + 1]['text'];
+                $namespace = $namespaceSegments[count($namespaceSegments) - 1]['namespace'];
+                $parent = self::readExtends($tokens, $i + 2);
+                $pendingType = [
+                    'start' => $i,
+                    'end' => 0,
+                    'bodyDepth' => 0,
+                    'fqcn' => $namespace === '' ? $name : $namespace.'\\'.$name,
+                    'isTrait' => $id === T_TRAIT,
+                    'parentRaw' => $parent['raw'],
+                    'parentId' => $parent['id'],
+                    'usesTraits' => false,
+                ];
+            }
+        }
+
+        // 閉じ括弧が足りない (構文検証済みなら起きない) 場合も型区間を捨てない
+        foreach (array_reverse($openTypes) as $open) {
+            $open['end'] = $count - 1;
+            $typeSegments[] = $open;
+        }
+
+        // `use` 文などで読み飛ばした位置にも深さを埋める (未記録の位置を残さない)
+        $current = 0;
+        for ($i = 0; $i < $count; $i++) {
+            if (isset($depths[$i])) {
+                $current = $depths[$i];
+
+                continue;
+            }
+            $depths[$i] = $current;
+        }
+        ksort($depths);
+
+        return new self($namespaceSegments, $typeSegments, array_values($depths));
+    }
+
+    /**
+     * 位置 `$index` の波括弧の深さ (その位置に入った時点の値)。
+     *
+     * ★型の本体の直下 (メソッド宣言の位置) は `TypeSegment['bodyDepth']` と一致する。
+     *   メソッドの中で宣言された名前付き関数や、型の中に置いた無名クラスのメソッドは
+     *   これより深くなるので、宣言の判定に使うと誤検出を落とせる。
+     */
+    public function depthAt(int $index): int
+    {
+        return $this->depths[$index] ?? 0;
+    }
+
+    /**
+     * 位置 `$index` を囲む型 (最も内側)。
+     *
+     * @return TypeSegment|null
+     */
+    public function typeAt(int $index): ?array
+    {
+        $innermost = null;
+        foreach ($this->typeSegments as $segment) {
+            if ($segment['start'] <= $index && $index <= $segment['end']) {
+                if ($innermost === null || $segment['start'] > $innermost['start']) {
+                    $innermost = $segment;
+                }
+            }
+        }
+
+        return $innermost;
+    }
+
+    /**
+     * 対象の完全修飾名を持つ型の宣言 (大小無視)。
+     *
+     * @return list<TypeSegment>
+     */
+    public function typeDeclarationsOf(string $fqcn): array
+    {
+        $needle = strtolower(ltrim($fqcn, '\\'));
+
+        return array_values(array_filter(
+            $this->typeSegments,
+            static fn (array $segment): bool => strtolower($segment['fqcn']) === $needle,
+        ));
+    }
+
+    /**
+     * 参照位置のトークンから完全修飾名を解決する。**解決できない形は null**。
+     *
+     * @param  list<NormalizedToken>  $tokens
+     */
+    public function resolveClassReference(array $tokens, int $index): ?string
+    {
+        if (! isset($tokens[$index])) {
+            return null;
+        }
+        $id = $tokens[$index]['id'];
+        $text = $tokens[$index]['text'];
+        $lower = strtolower($text);
+
+        if ($id === T_STATIC || ($id === T_STRING && ($lower === 'static' || $lower === 'self'))) {
+            $type = $this->typeAt($index);
+            if ($type === null || $type['isTrait']) {
+                return null;
+            }
+
+            return $type['fqcn'];
+        }
+
+        if ($id === T_STRING && $lower === 'parent') {
+            $type = $this->typeAt($index);
+            if ($type === null || $type['isTrait'] || $type['parentRaw'] === null) {
+                return null;
+            }
+
+            return $this->resolveRawName($type['parentRaw'], $type['parentId'], $index);
+        }
+
+        if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
+            return $this->resolveRawName($text, $id, $index);
+        }
+
+        return null;
+    }
+
+    /** 名前の原文を、位置 `$index` の namespace と取り込み表で解決する。 */
+    private function resolveRawName(string $raw, ?int $id, int $index): ?string
+    {
+        $namespace = $this->namespaceAt($index);
+        $uses = $this->usesAt($index);
+
+        if ($id === T_NAME_FULLY_QUALIFIED) {
+            return ltrim($raw, '\\');
+        }
+
+        if ($id === T_NAME_RELATIVE) {
+            $rest = ltrim(substr($raw, strlen('namespace')), '\\');
+
+            return $namespace === '' ? $rest : $namespace.'\\'.$rest;
+        }
+
+        if ($id === T_NAME_QUALIFIED) {
+            $parts = explode('\\', $raw);
+            $first = strtolower($parts[0]);
+            if (isset($uses[$first])) {
+                array_shift($parts);
+
+                return $parts === [] ? $uses[$first] : $uses[$first].'\\'.implode('\\', $parts);
+            }
+
+            return $namespace === '' ? $raw : $namespace.'\\'.$raw;
+        }
+
+        if ($id === T_STRING) {
+            $lower = strtolower($raw);
+            if (isset($uses[$lower])) {
+                return $uses[$lower];
+            }
+
+            return $namespace === '' ? $raw : $namespace.'\\'.$raw;
+        }
+
+        return null;
+    }
+
+    /** 位置 `$index` の namespace。 */
+    private function namespaceAt(int $index): string
+    {
+        return $this->segmentAt($index)['namespace'];
+    }
+
+    /**
+     * 位置 `$index` の取り込み表 (別名を小文字化したキー => 完全修飾名)。
+     *
+     * @return array<string, string>
+     */
+    private function usesAt(int $index): array
+    {
+        return $this->segmentAt($index)['uses'];
+    }
+
+    /** @return NamespaceSegment */
+    private function segmentAt(int $index): array
+    {
+        $current = $this->namespaceSegments[0];
+        foreach ($this->namespaceSegments as $segment) {
+            if ($segment['start'] <= $index) {
+                $current = $segment;
+            }
+        }
+
+        return $current;
+    }
+
+    /**
+     * `use` 文 (group use を含む) を取り込み表へ登録し、文末のトークン位置を返す。
+     *
+     * @param  list<NormalizedToken>  $tokens
+     * @param  list<NamespaceSegment>  $segments
+     */
+    private static function parseImport(array $tokens, int $useIndex, array &$segments): int
+    {
+        $count = count($tokens);
+        $j = $useIndex + 1;
+
+        if (isset($tokens[$j]) && in_array($tokens[$j]['id'], [T_FUNCTION, T_CONST], true)) {
+            // `use function` / `use const` はクラス参照ではないので取り込み表に入れない
+            return self::skipToStatementEnd($tokens, $j);
+        }
+
+        $segmentIndex = count($segments) - 1;
+
+        while ($j < $count) {
+            $name = self::readName($tokens, $j);
+            if ($name === null) {
+                break;
+            }
+            $j = $name['next'];
+
+            // group use は `T_NAME_QUALIFIED` + `T_NS_SEPARATOR` + `{` の 3 トークンで始まる
+            $isGroupUse = isset($tokens[$j], $tokens[$j + 1])
+                && $tokens[$j]['id'] === T_NS_SEPARATOR
+                && $tokens[$j + 1]['id'] === null
+                && $tokens[$j + 1]['text'] === '{';
+
+            if ($isGroupUse) {
+                // group use: `use A\B\{C, D as E};` と混合形 `use A\B\{function f, const C, D};`
+                $prefix = rtrim($name['text'], '\\');
+                $j += 2;
+                while ($j < $count) {
+                    if ($tokens[$j]['id'] === null && $tokens[$j]['text'] === '}') {
+                        $j++;
+                        break;
+                    }
+                    if ($tokens[$j]['id'] === null && $tokens[$j]['text'] === ',') {
+                        $j++;
+
+                        continue;
+                    }
+                    // ★要素ごとの種別を保持する。PHP は関数・定数とクラスの取り込み空間が別なので、
+                    //   `function` / `const` の要素は**その要素ごと**クラスの取り込み表へ入れない
+                    //   (印だけ読み飛ばすと後続の名前をクラスとして誤登録し、同名の対象クラス参照を
+                    //   別 namespace へ誤解決して見逃す)。
+                    $isClassImport = true;
+                    if (in_array($tokens[$j]['id'], [T_FUNCTION, T_CONST], true)) {
+                        $isClassImport = false;
+                        $j++;
+                    }
+                    $item = self::readName($tokens, $j);
+                    if ($item === null) {
+                        $j++;
+
+                        continue;
+                    }
+                    $j = $item['next'];
+                    $alias = self::readAlias($tokens, $j);
+                    $j = $alias['next'];
+                    if (! $isClassImport) {
+                        continue;
+                    }
+                    $fqcn = $prefix.'\\'.ltrim($item['text'], '\\');
+                    $segments[$segmentIndex]['uses'][strtolower($alias['name'] ?? self::shortName($fqcn))] = $fqcn;
+                }
+
+                return self::skipToStatementEnd($tokens, $j);
+            }
+
+            $alias = self::readAlias($tokens, $j);
+            $j = $alias['next'];
+            $fqcn = ltrim($name['text'], '\\');
+            $segments[$segmentIndex]['uses'][strtolower($alias['name'] ?? self::shortName($fqcn))] = $fqcn;
+
+            if (isset($tokens[$j]) && $tokens[$j]['id'] === null && $tokens[$j]['text'] === ',') {
+                $j++;
+
+                continue;
+            }
+            break;
+        }
+
+        return self::skipToStatementEnd($tokens, $j);
+    }
+
+    /**
+     * `extends` の名前を読む (`{` の手前まで)。
+     *
+     * @param  list<NormalizedToken>  $tokens
+     * @return array{raw: string|null, id: int|null}
+     */
+    private static function readExtends(array $tokens, int $from): array
+    {
+        $count = count($tokens);
+        for ($k = $from; $k < $count; $k++) {
+            if (self::isOpeningBrace($tokens[$k]['id'], $tokens[$k]['text'])) {
+                break;
+            }
+            if ($tokens[$k]['id'] === T_EXTENDS) {
+                $name = self::readName($tokens, $k + 1);
+                if ($name === null) {
+                    break;
+                }
+
+                return ['raw' => $name['text'], 'id' => $name['id']];
+            }
+        }
+
+        return ['raw' => null, 'id' => null];
+    }
+
+    /**
+     * 名前トークンを 1 つ読む。
+     *
+     * @param  list<NormalizedToken>  $tokens
+     * @return array{text: string, id: int, next: int}|null
+     */
+    private static function readName(array $tokens, int $index): ?array
+    {
+        if (! isset($tokens[$index])) {
+            return null;
+        }
+        $id = $tokens[$index]['id'];
+        if (! in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
+            return null;
+        }
+
+        /** @var int $id */
+        return ['text' => $tokens[$index]['text'], 'id' => $id, 'next' => $index + 1];
+    }
+
+    /**
+     * `as X` を読む (無ければ name = null)。
+     *
+     * @param  list<NormalizedToken>  $tokens
+     * @return array{name: string|null, next: int}
+     */
+    private static function readAlias(array $tokens, int $index): array
+    {
+        if (isset($tokens[$index], $tokens[$index + 1])
+            && $tokens[$index]['id'] === T_AS
+            && $tokens[$index + 1]['id'] === T_STRING) {
+            return ['name' => $tokens[$index + 1]['text'], 'next' => $index + 2];
+        }
+
+        return ['name' => null, 'next' => $index];
+    }
+
+    /** 完全修飾名の短名。 */
+    private static function shortName(string $fqcn): string
+    {
+        $position = strrpos($fqcn, '\\');
+
+        return $position === false ? $fqcn : substr($fqcn, $position + 1);
+    }
+
+    /**
+     * `;` までスキップする (その位置を返す)。
+     *
+     * @param  list<NormalizedToken>  $tokens
+     */
+    private static function skipToStatementEnd(array $tokens, int $from): int
+    {
+        $count = count($tokens);
+        for ($k = $from; $k < $count; $k++) {
+            if ($tokens[$k]['id'] === null && $tokens[$k]['text'] === ';') {
+                return $k;
+            }
+        }
+
+        return $count - 1;
+    }
+
+    /**
+     * 開き波括弧か (文字列補間が開く `{` を含める。閉じは素の `}` なので数が合う)。
+     */
+    private static function isOpeningBrace(?int $id, string $text): bool
+    {
+        if ($id === null) {
+            return $text === '{';
+        }
+
+        return in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php b/tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php
new file mode 100644
index 00000000..383955b4
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/RemovedSurfaceScanTargets.php
@@ -0,0 +1,264 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+use RuntimeException;
+use Symfony\Component\Process\Process;
+
+/**
+ * 撤去物の不在 gate が共有する**走査根と実走査母集団**の単一出典。
+ *
+ * ★走査根 (8 本): `.github` / `app` / `bootstrap` / `config` / `lang` / `resources` /
+ *   `routes` / `scripts`。`.github` と `scripts` は家系の正典 v1 が**必須**にしている
+ *   (撤去直後に CI 設定へ参照が残り CI ジョブが全滅した実測事故の教訓)。
+ * ★`database/migrations` は**含めない**。撤去した表の名前は移行履歴に必ず残るため、
+ *   含めると原理的に赤くなる (正典 v1 の明文)。
+ * ★母集団は**拡張子で絞らない**。`scripts/` には拡張子なしの実行ファイルが実在し、
+ *   拡張子の許可集合方式ではそれらが落ちて上記の事故をそのまま再現する。
+ * ★確定は**この 1 経路だけ**で行う (順序を固定する):
+ *     git 追跡下の列挙 → 通常ファイルとして読めるか (失敗は unresolved)
+ *     → symlink の解決先がリポジトリ内か (外なら unresolved)
+ *     → NUL 判定 (含むなら binaryExcluded) → UTF-8 検証 (不正は unresolved)
+ *     → 実走査母集団へ登録
+ *   **数える集合は本体の検査が実際に走査した集合と同一**である (別に数え直さない)。
+ * ★**fail-open を作らない**: git 追跡下にあるのに通常ファイルとして読めないパスを
+ *   `continue` で捨てない (削除途中 / 壊れた symlink に撤去語があると検査から消えるため)。
+ *   必ず `unresolved` へ理由つきで登録する。
+ * ★**バイナリ除外は無言で許容しない**: 利用側 gate は `binaryExcluded === []` を
+ *   不変条件にする (NUL を 1 つ入れて静的層を迂回する経路を塞ぐ)。
+ * ★**保証しないもの**: git 未追跡のファイルは列挙しない
+ *   (gate が守る境界は commit / CI であり、そこでは必ず追跡下にある)。
+ *   走査根の外 (`tests/` / `docs/` / `database/` 等) は見ない。
+ * ★`Tests\Support\TrackedPhpSourceFiles` との関係: あちらは拡張子 `.php` に限った
+ *   リポジトリ全体の全数列挙で、本クラスは**同じ作法 (`git ls-files`) で母集団を
+ *   全ファイルへ広げ、走査根を 8 本へ絞った兄弟**である。列挙を 2 本持つのではなく
+ *   対象の定義が違う。
+ */
+final class RemovedSurfaceScanTargets
+{
+    /** @var list<string> 走査根 (リポジトリルート相対)。 */
+    private const array ROOT_DIRECTORIES = [
+        '.github', 'app', 'bootstrap', 'config', 'lang', 'resources', 'routes', 'scripts',
+    ];
+
+    /**
+     * 各根に必ず含まれる代表パス (root 割当 / パス計算の誤りを検出する pin)。
+     *
+     * @var array<string, string>
+     */
+    public const array REPRESENTATIVE_PATHS = [
+        '.github' => '.github/workflows/ci.yml',
+        'app' => 'app/Providers/FortifyServiceProvider.php',
+        'bootstrap' => 'bootstrap/app.php',
+        'config' => 'config/seo.php',
+        'lang' => 'lang/ja/validation.php',
+        'resources' => 'resources/js/pages/Settings/Security.svelte',
+        'routes' => 'routes/web.php',
+        'scripts' => 'scripts/ci/drop-test-db.php',
+    ];
+
+    /**
+     * 確定済みの実走査母集団 (プロセス内で 1 度だけ確定する)。
+     *
+     * ★2 つの gate が同じ母集団を共有するためのメモ化であり、判定を持たない。
+     */
+    private static ?ScanPopulation $memoizedPopulation = null;
+
+    /** インスタンス化しない (純関数の置き場)。 */
+    private function __construct() {}
+
+    /** リポジトリルート (テスト実行時の base path)。 */
+    public static function repositoryRoot(): string
+    {
+        $root = realpath(__DIR__.'/../../..');
+        if (! is_string($root)) {
+            throw new RuntimeException('リポジトリルートを解決できません');
+        }
+
+        return $root;
+    }
+
+    /**
+     * 走査根 (相対 => 絶対)。**存在しない根は fail-fast**。
+     *
+     * @return array<string, string>
+     */
+    public static function roots(): array
+    {
+        $repositoryRoot = self::repositoryRoot();
+        $roots = [];
+        foreach (self::ROOT_DIRECTORIES as $relative) {
+            $absolute = realpath($repositoryRoot.'/'.$relative);
+            if (! is_string($absolute)) {
+                throw new RuntimeException("走査根を解決できません: {$relative}");
+            }
+            $roots[$relative] = $absolute;
+        }
+
+        return $roots;
+    }
+
+    /**
+     * 解決済みの絶対パスがリポジトリルート配下かどうか (純関数。自己検証の seam)。
+     *
+     * ★`population()` も自己検証も必ずこの関数を通す。symlink 判定を `population()` 内へ
+     *   閉じ込めると、`git ls-files` の母集団外から確かめる手立てが無くなる。
+     */
+    public static function isPathInsideRepository(string $repositoryRoot, string $resolvedTarget): bool
+    {
+        return str_starts_with($resolvedTarget, rtrim($repositoryRoot, '/').'/');
+    }
+
+    /**
+     * symlink の解決結果の判定 (**`population()` も自己検証も必ずここを通る**)。
+     *
+     * ★symlink でなければ null。解決できない (壊れた symlink) か、解決先がリポジトリ外なら理由を返す。
+     *   リポジトリ外のファイルを黙って走査対象へ引き込まず、走査対象からも逃がさない。
+     * ★判定は純関数 `isPathInsideRepository()` を通す (`git ls-files` の母集団の外からも
+     *   同じ経路で確かめられるようにするため)。
+     */
+    public static function symlinkUnresolvedReason(string $repositoryRoot, string $absolute): ?string
+    {
+        if (! is_link($absolute)) {
+            return null;
+        }
+
+        $target = realpath($absolute);
+        if ($target === false) {
+            return 'symlink の解決に失敗 (壊れた symlink)';
+        }
+        if (! self::isPathInsideRepository($repositoryRoot, $target)) {
+            return 'symlink がリポジトリ外へ解決される';
+        }
+
+        return null;
+    }
+
+    /**
+     * 内容の分類 (純関数。**`population()` も自己検証も必ずここを通る**)。
+     *
+     * ★同じ判定を 2 本持たない。NUL 判定と UTF-8 検証を 1 つの入口に閉じることで、
+     *   見本 (走査根の外に置く) からも実母集団からも同じ経路で確かめられる。
+     */
+    public static function classifyContents(string $contents): ContentClassification
+    {
+        if (str_contains($contents, "\0")) {
+            return ContentClassification::Binary;
+        }
+        if (! mb_check_encoding($contents, 'UTF-8')) {
+            return ContentClassification::InvalidUtf8;
+        }
+
+        return ContentClassification::Text;
+    }
+
+    /** 実走査母集団を確定する (唯一の経路)。 */
+    public static function population(): ScanPopulation
+    {
+        if (self::$memoizedPopulation instanceof ScanPopulation) {
+            return self::$memoizedPopulation;
+        }
+
+        $repositoryRoot = self::repositoryRoot();
+        $files = [];
+        $unresolved = [];
+        $binaryExcluded = [];
+
+        foreach (array_keys(self::roots()) as $root) {
+            foreach (self::trackedPaths($repositoryRoot, $root) as $relative) {
+                $absolute = $repositoryRoot.'/'.$relative;
+
+                if (! is_file($absolute)) {
+                    // ★ git 追跡下なのに通常ファイルとして無い = 無言で捨てない
+                    $unresolved[$relative] = '追跡下だが通常ファイルとして読めない';
+
+                    continue;
+                }
+
+                // ★ symlink の判定は純関数を通す (自己検証と同じ経路)
+                $symlinkReason = self::symlinkUnresolvedReason($repositoryRoot, $absolute);
+                if ($symlinkReason !== null) {
+                    $unresolved[$relative] = $symlinkReason;
+
+                    continue;
+                }
+
+                $contents = @file_get_contents($absolute);
+                if ($contents === false) {
+                    $unresolved[$relative] = 'ファイルの読み取りに失敗';
+
+                    continue;
+                }
+
+                // ★分類は必ず classifyContents() を通す (自己検証と同じ経路)
+                $classification = self::classifyContents($contents);
+                if ($classification === ContentClassification::Binary) {
+                    $binaryExcluded[] = $relative;
+
+                    continue;
+                }
+                if ($classification === ContentClassification::InvalidUtf8) {
+                    $unresolved[$relative] = 'UTF-8 として不正';
+
+                    continue;
+                }
+
+                $files[] = new ScannedFile(
+                    root: $root,
+                    relative: $relative,
+                    contents: $contents,
+                    isPhp: str_ends_with($relative, '.php') && ! str_ends_with($relative, '.blade.php'),
+                    extension: self::extensionOf($relative),
+                );
+            }
+        }
+
+        return self::$memoizedPopulation = new ScanPopulation($files, $unresolved, $binaryExcluded);
+    }
+
+    /**
+     * 拡張子 (小文字)。拡張子なしは null。
+     *
+     * ★`.github/workflows/ci.yml` → `yml` / `scripts/codex` → null。
+     *   ドットで始まるだけのファイル (`.gitignore`) は拡張子なしとして扱う。
+     */
+    public static function extensionOf(string $relative): ?string
+    {
+        $basename = basename($relative);
+        $position = strrpos($basename, '.');
+        if ($position === false || $position === 0) {
+            return null;
+        }
+
+        return strtolower(substr($basename, $position + 1));
+    }
+
+    /**
+     * git 追跡下の相対パス (root 配下)。
+     *
+     * ★`is_file()` 判定はここでは**行わない** (捨てずに `unresolved` へ入れるため
+     *   `population()` 側の責務にする)。
+     *
+     * @return list<string>
+     */
+    private static function trackedPaths(string $repositoryRoot, string $root): array
+    {
+        $process = new Process(['git', 'ls-files', '-z', '--', $root], $repositoryRoot);
+        $process->run();
+        if (! $process->isSuccessful()) {
+            throw new RuntimeException('git ls-files の実行に失敗しました: '.$process->getErrorOutput());
+        }
+
+        $paths = [];
+        foreach (explode("\0", $process->getOutput()) as $relative) {
+            if ($relative === '') {
+                continue;
+            }
+            $paths[] = $relative;
+        }
+
+        return $paths;
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php b/tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php
new file mode 100644
index 00000000..de9556e7
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/RemovedSurfaceScanner.php
@@ -0,0 +1,690 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+use ParseError;
+use Tests\Support\PhpTokenScan;
+
+/**
+ * 撤去語の出現と**構文上の形**だけを返す純関数群 (許可ポリシーを持たない)。
+ *
+ * ★語彙一致は `TOKEN_CHARACTERS` で分割した run のトークン完全一致で判定する
+ *   (正規表現の語境界にも素の部分文字列一致にも頼らない。AGENTS.md「静的検査の共通規約」(e))。
+ *   区切りは**宣言した文字集合の外のすべてのバイト**であり、UTF-8 の多バイト文字は
+ *   すべて区切りになる (ASCII 以外はトークン文字に入れていない)。
+ * ★クラス参照は完全修飾名 (ASCII 大小無視) で突き合わせる (同 (a))。解決は `PhpNameResolver`。
+ * ★PHP は「文字列リテラル」ではなく **lexeme** を見る。文字列リテラルだけに限ると
+ *   `public bool $imageSourceDocumentsEnabled;` や `const OCR_ANALYSIS_ENABLED = true;` での
+ *   復活を検出できない。
+ * ★PHP は**構文検証を先に行い**、`ParseError` を投げるファイルは未解決にする (fail-closed)。
+ *   捕まえるのは `ParseError` **だけ**である (親型 `\Error` まで捕まえると、予期しない実行時障害まで
+ *   「解析未解決」へ変換してしまい、本来テストを落とすべき異常が別の意味に化ける)。
+ *   正規化は既存の単一出典 `Tests\Support\PhpTokenScan::normalize()` を使う (挙動は変えない)。
+ *
+ * ★**保証しないもの (検出力を誇張しない)**:
+ *   - 撤去語を分割して連結する書き方・定数経由の参照・実行時に組み立てた文字列には沈黙する。
+ *   - PHP のコメント / docblock の中では沈黙する (`normalize()` が落とすため)。
+ *   - **middleware 位置に現れる変数・式** (`->middleware($alias)` /
+ *     `->middleware('throttle:'.$limiter)`) は**クラス参照でも文字列リテラルでもない**ため
+ *     母集団に入らない。これは許可一覧ではなく**規則の段階での定義**である
+ *     (`X::class` 構文だけをクラス参照として扱い、受け手が名前でないものは未解決にする)。
+ *     実体化した route については実行時層 (`PasswordConfirmMiddlewareAbsenceTest`) が補完する。
+ *   - `FqcnMethodReference` は `クラス部::メソッド名` が**空白を挟まず**並んでいる形だけを見る。
+ *   - NUL を含むファイルは母集団に入らない (`RemovedSurfaceScanTargets`。利用側は 0 件を要求する)。
+ * ★解決できない形は**未解決として分けて返す** (空配列へ混ぜない)。利用側 gate は必ず
+ *   `ScanOutcome::mergeUnresolved()` で空を要求すること。
+ */
+final class RemovedSurfaceScanner
+{
+    /**
+     * トークン文字の集合。**これ以外のバイトはすべて区切り**である。
+     * 生テキストはこの集合の**最長の連なり (run)** へ分割される。
+     */
+    private const string TOKEN_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.-';
+
+    /**
+     * 完全修飾参照専用のトークン文字集合 (`\` を含み `.` `-` を含まない)。
+     *
+     * `TOKEN_CHARACTERS` では `\` と `:` が区切りになるため、完全修飾参照は複数の run へ割れて
+     * 原理的に一致しない。専用の集合でクラス部とメソッド部を構文的に切り出す。
+     */
+    private const string FQCN_TOKEN_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_\\';
+
+    /**
+     * M1: middleware 位置を作る呼び出し名 (ASCII 大小無視の完全一致)。
+     *
+     * @var list<string>
+     */
+    private const array MIDDLEWARE_CALL_NAMES = [
+        'middleware', 'withoutmiddleware', 'middlewaregroup', 'appendtogroup', 'prependtogroup', 'alias',
+    ];
+
+    /**
+     * M3: middleware 位置を作るプロパティ名 (ASCII 大小無視の完全一致)。
+     *
+     * @var list<string>
+     */
+    private const array MIDDLEWARE_PROPERTY_NAMES = [
+        '$middleware', '$middlewaregroups', '$middlewarepriority',
+    ];
+
+    /** インスタンス化しない (純関数の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * Tier 2: 生テキストを run へ分割してトークン完全一致で走査する。
+     *
+     * @param  list<ScannedFile>  $files
+     * @return ScanOutcome<Occurrence>
+     */
+    public static function scanText(array $files, RemovedTerm $term): ScanOutcome
+    {
+        $occurrences = [];
+
+        foreach ($files as $file) {
+            if ($term->mode === TermMatchMode::FqcnMethodReference) {
+                foreach (self::fqcnMethodOccurrences($file, $term) as $occurrence) {
+                    $occurrences[] = $occurrence;
+                }
+
+                continue;
+            }
+
+            if ($term->mode === TermMatchMode::FqcnReference) {
+                foreach (self::fqcnOccurrences($file, $term) as $occurrence) {
+                    $occurrences[] = $occurrence;
+                }
+
+                continue;
+            }
+
+            foreach (self::runs($file->contents, self::TOKEN_CHARACTERS) as $run) {
+                if (! self::runMatches($run['text'], $term)) {
+                    continue;
+                }
+                $occurrences[] = new Occurrence(
+                    $file->relative,
+                    self::lineAt($file->contents, $run['offset']),
+                    $run['text'],
+                );
+            }
+        }
+
+        return new ScanOutcome($occurrences, []);
+    }
+
+    /**
+     * Tier 1: PHP の lexeme (識別子・変数・定数・文字列・heredoc・名前) を走査する。
+     *
+     * @param  list<ScannedFile>  $files
+     * @return ScanOutcome<Occurrence>
+     */
+    public static function scanPhpLexemes(array $files, RemovedTerm $term): ScanOutcome
+    {
+        $occurrences = [];
+        /** @var array<string, string> $unresolved */
+        $unresolved = [];
+
+        foreach ($files as $file) {
+            if (! $file->isPhp) {
+                continue;
+            }
+            $tokens = self::tokenize($file, $unresolved);
+            if ($tokens === null) {
+                continue;
+            }
+
+            foreach ($tokens as $token) {
+                $lexeme = self::lexemeOf($token);
+                if ($lexeme === null) {
+                    continue;
+                }
+                foreach (self::runs($lexeme, self::TOKEN_CHARACTERS) as $run) {
+                    if (! self::runMatches($run['text'], $term)) {
+                        continue;
+                    }
+                    $occurrences[] = new Occurrence($file->relative, $token['line'], $run['text']);
+                }
+            }
+        }
+
+        return new ScanOutcome($occurrences, $unresolved);
+    }
+
+    /**
+     * Tier 1: **middleware 位置**に現れる alias 文字列 / クラス参照を返す。
+     *
+     * middleware 位置の定義 (有限。これ以外は母集団に入らない):
+     *   M1 呼び出し名が `middleware` / `withoutMiddleware` / `middlewareGroup` /
+     *      `appendToGroup` / `prependToGroup` / `alias` の引数領域
+     *   M2 キー名が `middleware` を部分文字列として含む (ASCII 大小無視) 配列要素の値の領域
+     *   M3 プロパティ `$middleware` / `$middlewareGroups` / `$middlewarePriority` の初期化式の領域
+     *
+     * 領域からは **`X::class` 構文のクラス参照**と**文字列リテラル**だけを取り出す。
+     * 受け手が名前でない `X::class` (`$cls::class`) は未解決にする。
+     *
+     * @param  list<ScannedFile>  $files
+     * @return ScanOutcome<MiddlewareReference>
+     */
+    public static function scanMiddlewarePositions(array $files): ScanOutcome
+    {
+        $references = [];
+        /** @var array<string, string> $unresolved */
+        $unresolved = [];
+
+        foreach ($files as $file) {
+            if (! $file->isPhp) {
+                continue;
+            }
+            $tokens = self::tokenize($file, $unresolved);
+            if ($tokens === null) {
+                continue;
+            }
+            $resolver = PhpNameResolver::analyze($tokens);
+            $count = count($tokens);
+
+            /** @var array<int, bool> $marks */
+            $marks = [];
+            for ($i = 0; $i < $count; $i++) {
+                $id = $tokens[$i]['id'];
+                $text = $tokens[$i]['text'];
+
+                if ($id === T_STRING
+                    && in_array(strtolower($text), self::MIDDLEWARE_CALL_NAMES, true)
+                    && self::isChar($tokens, $i + 1, '(')) {
+                    $close = self::matchingBracket($tokens, $i + 1);
+                    if ($close === null) {
+                        $unresolved[$file->relative] = 'middleware 呼び出しの括弧の対応を解決できない';
+
+                        continue;
+                    }
+                    self::markRange($marks, $i + 2, $close - 1);
+
+                    continue;
+                }
+
+                if ($id === T_CONSTANT_ENCAPSED_STRING
+                    && isset($tokens[$i + 1])
+                    && $tokens[$i + 1]['id'] === T_DOUBLE_ARROW
+                    && str_contains(strtolower(self::unquote($text)), 'middleware')) {
+                    $end = self::valueEnd($tokens, $i + 2);
+                    if ($end === null) {
+                        $unresolved[$file->relative] = 'middleware キーの値の範囲を解決できない';
+
+                        continue;
+                    }
+                    self::markRange($marks, $i + 2, $end);
+
+                    continue;
+                }
+
+                if ($id === T_VARIABLE
+                    && in_array(strtolower($text), self::MIDDLEWARE_PROPERTY_NAMES, true)
+                    && self::isChar($tokens, $i + 1, '=')) {
+                    $end = self::valueEnd($tokens, $i + 2);
+                    if ($end === null) {
+                        $unresolved[$file->relative] = 'middleware プロパティの初期化式の範囲を解決できない';
+
+                        continue;
+                    }
+                    self::markRange($marks, $i + 2, $end);
+                }
+            }
+
+            for ($i = 0; $i < $count; $i++) {
+                if (! isset($marks[$i])) {
+                    continue;
+                }
+                $token = $tokens[$i];
+
+                if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
+                    $references[] = new MiddlewareReference(
+                        $file->relative,
+                        $token['line'],
+                        MiddlewareReferenceKind::AliasString,
+                        self::unquote($token['text']),
+                        null,
+                    );
+
+                    continue;
+                }
+
+                if ($token['id'] === T_DOUBLE_COLON && isset($tokens[$i + 1]) && $tokens[$i + 1]['id'] === T_CLASS) {
+                    $resolved = $resolver->resolveClassReference($tokens, $i - 1);
+                    if ($resolved === null) {
+                        $unresolved[$file->relative] = sprintf(
+                            'middleware 位置のクラス参照を完全修飾名へ解決できない (行 %d)',
+                            $token['line'],
+                        );
+
+                        continue;
+                    }
+                    $references[] = new MiddlewareReference(
+                        $file->relative,
+                        $token['line'],
+                        MiddlewareReferenceKind::ClassReference,
+                        $tokens[$i - 1]['text'],
+                        ltrim($resolved, '\\'),
+                    );
+                }
+            }
+        }
+
+        return new ScanOutcome($references, $unresolved);
+    }
+
+    /**
+     * Tier 1: 指定クラス (完全修飾名) のメソッド宣言と静的呼び出し。
+     *
+     * ★対象クラスの宣言が trait を取り込んでいたら**未解決**にする (v1 は trait-use graph を
+     *   扱わないため、メソッドが混入しているかを静的に判定できない)。
+     *
+     * @param  list<ScannedFile>  $files
+     * @return ScanOutcome<MethodReference>
+     */
+    public static function scanMethodReferences(array $files, string $fqcn, string $method): ScanOutcome
+    {
+        $targetFqcn = strtolower(ltrim($fqcn, '\\'));
+        $targetMethod = strtolower($method);
+        $references = [];
+        /** @var array<string, string> $unresolved */
+        $unresolved = [];
+
+        foreach ($files as $file) {
+            if (! $file->isPhp) {
+                continue;
+            }
+            $tokens = self::tokenize($file, $unresolved);
+            if ($tokens === null) {
+                continue;
+            }
+            $resolver = PhpNameResolver::analyze($tokens);
+            $count = count($tokens);
+
+            foreach ($resolver->typeDeclarationsOf($fqcn) as $declaration) {
+                if ($declaration['usesTraits']) {
+                    $unresolved[$file->relative] =
+                        '対象クラスが trait を取り込んでおり、メソッドの混入を静的に判定できない';
+                }
+            }
+
+            for ($i = 0; $i < $count; $i++) {
+                $token = $tokens[$i];
+
+                if ($token['id'] === T_FUNCTION) {
+                    $nameIndex = self::isChar($tokens, $i + 1, '&') ? $i + 2 : $i + 1;
+                    if (isset($tokens[$nameIndex])
+                        && $tokens[$nameIndex]['id'] === T_STRING
+                        && strtolower($tokens[$nameIndex]['text']) === $targetMethod) {
+                        $type = $resolver->typeAt($i);
+                        // ★型の**本体の直下**にある宣言だけをメソッド宣言と見なす。
+                        //   メソッドの中で宣言された名前付き関数や、型の中に置いた無名クラスの
+                        //   メソッドは深さが違うので誤検出しない。
+                        if ($type !== null
+                            && strtolower($type['fqcn']) === $targetFqcn
+                            && $resolver->depthAt($i) === $type['bodyDepth']) {
+                            $references[] = new MethodReference(
+                                $file->relative,
+                                $token['line'],
+                                MethodReferenceKind::Declaration,
+                            );
+                        }
+                    }
+
+                    continue;
+                }
+
+                if ($token['id'] === T_DOUBLE_COLON
+                    && isset($tokens[$i + 1])
+                    && $tokens[$i + 1]['id'] === T_STRING
+                    && strtolower($tokens[$i + 1]['text']) === $targetMethod) {
+                    $resolved = $resolver->resolveClassReference($tokens, $i - 1);
+                    if ($resolved === null) {
+                        $unresolved[$file->relative] = sprintf(
+                            '`::%s` を伴うクラス参照を完全修飾名へ解決できない (行 %d)',
+                            $method,
+                            $token['line'],
+                        );
+
+                        continue;
+                    }
+                    if (strtolower(ltrim($resolved, '\\')) === $targetFqcn) {
+                        $references[] = new MethodReference(
+                            $file->relative,
+                            $token['line'],
+                            MethodReferenceKind::StaticCall,
+                        );
+                    }
+                }
+            }
+        }
+
+        return new ScanOutcome($references, $unresolved);
+    }
+
+    /**
+     * 生テキストに撤去語と一致する run が含まれるか。
+     *
+     * ★利用側 gate が「middleware 位置の alias 文字列」のような**値**を絞り込むための入口で、
+     *   判定は `scanText()` / `scanPhpLexemes()` と**同じ 1 本のトークン一致**を通る
+     *   (同じ判定を 2 本持たない)。
+     */
+    public static function textMatches(string $text, RemovedTerm $term): bool
+    {
+        if ($term->mode === TermMatchMode::FqcnMethodReference) {
+            return self::fqcnMethodOccurrences(
+                new ScannedFile('memory', 'memory', $text, false, null),
+                $term,
+            ) !== [];
+        }
+
+        if ($term->mode === TermMatchMode::FqcnReference) {
+            return self::fqcnOccurrences(
+                new ScannedFile('memory', 'memory', $text, false, null),
+                $term,
+            ) !== [];
+        }
+
+        foreach (self::runs($text, self::TOKEN_CHARACTERS) as $run) {
+            if (self::runMatches($run['text'], $term)) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * 生テキストを宣言した文字集合の最長連なり (run) へ分割する。
+     *
+     * @return list<array{text: string, offset: int}>
+     */
+    private static function runs(string $text, string $tokenCharacters): array
+    {
+        $runs = [];
+        $length = strlen($text);
+        $start = null;
+
+        for ($i = 0; $i < $length; $i++) {
+            if (str_contains($tokenCharacters, $text[$i])) {
+                if ($start === null) {
+                    $start = $i;
+                }
+
+                continue;
+            }
+            if ($start !== null) {
+                $runs[] = ['text' => substr($text, $start, $i - $start), 'offset' => $start];
+                $start = null;
+            }
+        }
+        if ($start !== null) {
+            $runs[] = ['text' => substr($text, $start), 'offset' => $start];
+        }
+
+        return $runs;
+    }
+
+    /** run が撤去語と一致するか (様式ごとの完全一致)。 */
+    private static function runMatches(string $run, RemovedTerm $term): bool
+    {
+        return match ($term->mode) {
+            TermMatchMode::ExactRun => $run === $term->term,
+            TermMatchMode::RunSegment => in_array($term->term, explode('.', $run), true),
+            // 完全修飾参照は専用のトークン文字集合で判定する
+            // (fqcnMethodOccurrences / fqcnOccurrences が担当する)
+            TermMatchMode::FqcnMethodReference, TermMatchMode::FqcnReference => false,
+        };
+    }
+
+    /**
+     * `クラス部::メソッド名` の完全一致 (ASCII 大小無視・先頭 `\` は落として正規化)。
+     *
+     * @return list<Occurrence>
+     */
+    private static function fqcnMethodOccurrences(ScannedFile $file, RemovedTerm $term): array
+    {
+        $parts = explode('::', $term->term, 2);
+        if (count($parts) !== 2) {
+            return [];
+        }
+        $targetClass = self::normalizeFqcn($parts[0]);
+        $targetMethod = strtolower($parts[1]);
+
+        /** @var array<int, string> $endingAt */
+        $endingAt = [];
+        /** @var array<int, string> $startingAt */
+        $startingAt = [];
+        foreach (self::runs($file->contents, self::FQCN_TOKEN_CHARACTERS) as $run) {
+            $startingAt[$run['offset']] = $run['text'];
+            $endingAt[$run['offset'] + strlen($run['text'])] = $run['text'];
+        }
+
+        $occurrences = [];
+        $offset = 0;
+        while (($position = strpos($file->contents, '::', $offset)) !== false) {
+            $offset = $position + 2;
+            if (! isset($endingAt[$position], $startingAt[$position + 2])) {
+                continue;
+            }
+            $class = self::normalizeFqcn($endingAt[$position]);
+            $method = strtolower($startingAt[$position + 2]);
+            if ($class !== $targetClass || $method !== $targetMethod) {
+                continue;
+            }
+            $occurrences[] = new Occurrence(
+                $file->relative,
+                self::lineAt($file->contents, $position),
+                $endingAt[$position].'::'.$startingAt[$position + 2],
+            );
+        }
+
+        return $occurrences;
+    }
+
+    /**
+     * 完全修飾クラス名そのものの完全一致 (メソッド名を伴わない)。
+     *
+     * @return list<Occurrence>
+     */
+    private static function fqcnOccurrences(ScannedFile $file, RemovedTerm $term): array
+    {
+        $target = self::normalizeFqcn($term->term);
+
+        $occurrences = [];
+        foreach (self::runs($file->contents, self::FQCN_TOKEN_CHARACTERS) as $run) {
+            if (self::normalizeFqcn($run['text']) !== $target) {
+                continue;
+            }
+            $occurrences[] = new Occurrence(
+                $file->relative,
+                self::lineAt($file->contents, $run['offset']),
+                $run['text'],
+            );
+        }
+
+        return $occurrences;
+    }
+
+    /**
+     * 完全修飾名の正規化 (先頭の逆斜線を落とし、連続する逆斜線を 1 つへ畳み、ASCII 小文字化)。
+     *
+     * ★連続の畳み込みは二重引用符の文字列リテラルのエスケープ表記を吸収するためで、
+     *   **拾いすぎる方向**の正規化である (見逃す方向へは倒れない)。
+     */
+    private static function normalizeFqcn(string $name): string
+    {
+        $collapsed = preg_replace('/\\\\+/', '\\', $name);
+
+        return strtolower(ltrim($collapsed ?? $name, '\\'));
+    }
+
+    /**
+     * PHP を構文検証してから正規化トークン列を返す。`ParseError` は未解決。
+     *
+     * @param  array<string, string>  $unresolved
+     * @return list<array{id: int|null, text: string, line: int}>|null
+     */
+    private static function tokenize(ScannedFile $file, array &$unresolved): ?array
+    {
+        try {
+            token_get_all($file->contents, TOKEN_PARSE); // ★構文検証のみ (結果は捨てる)
+        } catch (ParseError $error) {                    // ★ParseError だけを捕まえる
+            $unresolved[$file->relative] = 'PHP のトークン化に失敗: '.$error->getMessage();
+
+            return null;
+        }
+
+        return PhpTokenScan::normalize($file->contents);
+    }
+
+    /**
+     * 撤去語と突き合わせる lexeme (対象外のトークンは null)。
+     *
+     * @param  array{id: int|null, text: string, line: int}  $token
+     */
+    private static function lexemeOf(array $token): ?string
+    {
+        return match ($token['id']) {
+            T_VARIABLE => substr($token['text'], 1),
+            T_CONSTANT_ENCAPSED_STRING => self::unquote($token['text']),
+            T_STRING, T_ENCAPSED_AND_WHITESPACE,
+            T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE => $token['text'],
+            default => null,
+        };
+    }
+
+    /** 文字列リテラルの引用符を落とす (エスケープの復元はしない)。 */
+    private static function unquote(string $literal): string
+    {
+        $value = $literal;
+        if ($value !== '' && (strtolower($value[0]) === 'b')) {
+            $value = substr($value, 1);
+        }
+        if (strlen($value) >= 2) {
+            $first = $value[0];
+            $last = $value[strlen($value) - 1];
+            if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
+                $value = substr($value, 1, -1);
+            }
+        }
+
+        return $value;
+    }
+
+    /** バイト位置の行番号 (1 起点)。 */
+    private static function lineAt(string $contents, int $offset): int
+    {
+        return substr_count($contents, "\n", 0, $offset) + 1;
+    }
+
+    /**
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function isChar(array $tokens, int $index, string $char): bool
+    {
+        return isset($tokens[$index]) && $tokens[$index]['id'] === null && $tokens[$index]['text'] === $char;
+    }
+
+    /**
+     * 開き括弧に対応する閉じ括弧の位置 (対応が取れなければ null)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function matchingBracket(array $tokens, int $openIndex): ?int
+    {
+        $depth = 0;
+        $count = count($tokens);
+        for ($k = $openIndex; $k < $count; $k++) {
+            $delta = self::bracketDelta($tokens[$k]);
+            if ($delta > 0) {
+                $depth++;
+
+                continue;
+            }
+            if ($delta < 0) {
+                $depth--;
+                if ($depth === 0) {
+                    return $k;
+                }
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * 値の式が終わる位置 (配列リテラルなら閉じ括弧、単一式なら深さ 0 の区切りの手前)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function valueEnd(array $tokens, int $from): ?int
+    {
+        if (! isset($tokens[$from])) {
+            return null;
+        }
+        if (self::isChar($tokens, $from, '[')) {
+            return self::matchingBracket($tokens, $from);
+        }
+        if ($tokens[$from]['id'] === T_ARRAY && self::isChar($tokens, $from + 1, '(')) {
+            return self::matchingBracket($tokens, $from + 1);
+        }
+
+        $depth = 0;
+        $count = count($tokens);
+        for ($k = $from; $k < $count; $k++) {
+            $delta = self::bracketDelta($tokens[$k]);
+            if ($delta > 0) {
+                $depth++;
+
+                continue;
+            }
+            if ($delta < 0) {
+                if ($depth === 0) {
+                    return $k - 1;
+                }
+                $depth--;
+
+                continue;
+            }
+            if ($depth === 0 && $tokens[$k]['id'] === null && in_array($tokens[$k]['text'], [',', ';'], true)) {
+                return $k - 1;
+            }
+        }
+
+        return $count - 1;
+    }
+
+    /**
+     * 括弧の深さの増減 (文字列補間が開く `{` と属性の `#[` を開き括弧として数える)。
+     *
+     * @param  array{id: int|null, text: string, line: int}  $token
+     */
+    private static function bracketDelta(array $token): int
+    {
+        if ($token['id'] === null) {
+            if (in_array($token['text'], ['(', '[', '{'], true)) {
+                return 1;
+            }
+            if (in_array($token['text'], [')', ']', '}'], true)) {
+                return -1;
+            }
+
+            return 0;
+        }
+
+        return in_array($token['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true) ? 1 : 0;
+    }
+
+    /**
+     * @param  array<int, bool>  $marks
+     */
+    private static function markRange(array &$marks, int $from, int $to): void
+    {
+        for ($i = $from; $i <= $to; $i++) {
+            $marks[$i] = true;
+        }
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/RemovedTerm.php b/tests/Support/SurfaceRemoval/RemovedTerm.php
new file mode 100644
index 00000000..05cadfcc
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/RemovedTerm.php
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/**
+ * 撤去語 (語そのものと一致様式を 1 つにまとめる)。
+ *
+ * ★語だけを渡す API にしない。様式を語と別に持ち回ると、呼び出し側ごとに
+ *   違う様式で同じ語を判定する事故が起きる。
+ */
+final readonly class RemovedTerm
+{
+    public function __construct(
+        public string $term,
+        public TermMatchMode $mode,
+    ) {}
+}
diff --git a/tests/Support/SurfaceRemoval/ScanOutcome.php b/tests/Support/SurfaceRemoval/ScanOutcome.php
new file mode 100644
index 00000000..0b6917d7
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/ScanOutcome.php
@@ -0,0 +1,62 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/**
+ * 走査結果。**出現**と**未解決**を型上区別する (未解決を空配列へ混ぜない)。
+ *
+ * ★利用側 gate は `mergeUnresolved()` で**呼んだすべての結果**の未解決を 1 つに集め、
+ *   空であることを必ず要求する (AGENTS.md (b) / (d))。
+ *
+ * @template-covariant TOccurrence of Occurrence|MiddlewareReference|MethodReference
+ */
+final readonly class ScanOutcome
+{
+    /**
+     * @param  list<TOccurrence>  $occurrences
+     * @param  array<string, string>  $unresolved  相対パス => 理由
+     */
+    public function __construct(
+        public array $occurrences,
+        public array $unresolved,
+    ) {}
+
+    /**
+     * 出現の説明行 (gate の失敗メッセージ用)。
+     *
+     * @return list<string>
+     */
+    public function descriptions(): array
+    {
+        return array_values(array_map(
+            static fn (Occurrence|MiddlewareReference|MethodReference $o): string => $o->describe(),
+            $this->occurrences,
+        ));
+    }
+
+    /**
+     * 複数の走査結果の未解決を 1 つへまとめる。
+     *
+     * ★集めるだけで判定に使わない出力を作らないため、gate は必ずこの戻り値を
+     *   「空であること」の assertion に渡す。
+     *
+     * @param  list<self<Occurrence|MiddlewareReference|MethodReference>>  $outcomes
+     * @return list<string> `相対パス: 理由` の説明行 (昇順)
+     */
+    public static function mergeUnresolved(array $outcomes): array
+    {
+        $merged = [];
+        foreach ($outcomes as $outcome) {
+            foreach ($outcome->unresolved as $relative => $reason) {
+                $merged[$relative.': '.$reason] = true;
+            }
+        }
+
+        $lines = array_keys($merged);
+        sort($lines);
+
+        return $lines;
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/ScanPopulation.php b/tests/Support/SurfaceRemoval/ScanPopulation.php
new file mode 100644
index 00000000..63674a7e
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/ScanPopulation.php
@@ -0,0 +1,51 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/**
+ * 実走査母集団 + 未解決 + バイナリ除外。
+ *
+ * ★**数える集合と走査する集合を分けない**。gate が空振り検査に使う件数は、
+ *   本体の検査が実際に走査した `$files` そのものから数える。
+ * ★`$unresolved` と `$binaryExcluded` は**利用側 gate が空を要求する**。
+ *   捨てた事実を型の上に残すことで、無言の fail-open を作らない。
+ */
+final readonly class ScanPopulation
+{
+    /**
+     * @param  list<ScannedFile>  $files  実走査母集団
+     * @param  array<string, string>  $unresolved  相対パス => 理由
+     * @param  list<string>  $binaryExcluded  NUL を含むため外した相対パス
+     */
+    public function __construct(
+        public array $files,
+        public array $unresolved,
+        public array $binaryExcluded,
+    ) {}
+
+    /** @return list<ScannedFile> PHP ソースとして扱うファイル */
+    public function php(): array
+    {
+        return array_values(array_filter($this->files, static fn (ScannedFile $f): bool => $f->isPhp));
+    }
+
+    /** @return list<ScannedFile> PHP ソースとして扱わないファイル */
+    public function nonPhp(): array
+    {
+        return array_values(array_filter($this->files, static fn (ScannedFile $f): bool => ! $f->isPhp));
+    }
+
+    /** @return list<ScannedFile> 指定した走査根に属するファイル */
+    public function inRoot(string $root): array
+    {
+        return array_values(array_filter($this->files, static fn (ScannedFile $f): bool => $f->root === $root));
+    }
+
+    /** @return list<string> 実走査母集団の相対パス */
+    public function relativePaths(): array
+    {
+        return array_values(array_map(static fn (ScannedFile $f): string => $f->relative, $this->files));
+    }
+}
diff --git a/tests/Support/SurfaceRemoval/ScannedFile.php b/tests/Support/SurfaceRemoval/ScannedFile.php
new file mode 100644
index 00000000..b439fb38
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/ScannedFile.php
@@ -0,0 +1,29 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/**
+ * 走査対象 1 ファイル (内容込みの値オブジェクト)。
+ *
+ * ★`$isPhp` は**拡張子から推定させない**。実母集団は
+ *   `RemovedSurfaceScanTargets::population()` が決め、自己検証の見本 (`*.php.txt`) は
+ *   gate 側が**引数で明示**して組み立てる (見本を `.php` で置くと
+ *   `StrictTypesDeclarationGateTest` など無関係な gate が赤くなるため)。
+ */
+final readonly class ScannedFile
+{
+    public function __construct(
+        /** 走査根 (`.github` / `app` / … / 見本は `fixtures`)。 */
+        public string $root,
+        /** リポジトリルート相対パス (見本は見本ファイルの相対パス)。 */
+        public string $relative,
+        /** NUL を含まず UTF-8 検証済みの内容。 */
+        public string $contents,
+        /** PHP ソースとして扱うか (`.blade.php` は PHP ソースではない)。 */
+        public bool $isPhp,
+        /** 拡張子 (小文字。拡張子なしは null)。 */
+        public ?string $extension,
+    ) {}
+}
diff --git a/tests/Support/SurfaceRemoval/TermMatchMode.php b/tests/Support/SurfaceRemoval/TermMatchMode.php
new file mode 100644
index 00000000..4ba26b87
--- /dev/null
+++ b/tests/Support/SurfaceRemoval/TermMatchMode.php
@@ -0,0 +1,53 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\SurfaceRemoval;
+
+/**
+ * 撤去語の一致様式 (語ごとに宣言する)。
+ *
+ * ★AGENTS.md「静的検査 (gate) と走査器の共通規約」(e) に従い、判定は
+ *   **宣言した区切りで分割したトークンの完全一致**で行う。正規表現の語境界にも
+ *   素の部分文字列一致にも頼らない。
+ */
+enum TermMatchMode
+{
+    /**
+     * トークン文字集合 `[A-Za-z0-9_.-]` の最長連なり (run) 全体と完全一致 (大小区別あり)。
+     *
+     * `password.confirm:web` は `:` が区切りなので run が `password.confirm` になり一致する。
+     * `password.confirm.store` / `x-password.confirm` は run 全体が違うので一致しない。
+     */
+    case ExactRun;
+
+    /**
+     * run を `.` で割ったいずれかの segment と完全一致 (大小区別あり)。
+     *
+     * 設定パス表記 (`manual.ocr_analysis_enabled`) に当てるための様式。
+     */
+    case RunSegment;
+
+    /**
+     * 非 PHP の生テキストに現れる**完全修飾クラス名 + `::` + メソッド名**の完全一致。
+     *
+     * ★専用のトークン文字集合 `[A-Za-z0-9_\\]` を使う。`ExactRun` の文字集合では
+     *   `\` と `:` が区切りになるため、完全修飾参照は複数の run へ割れて
+     *   **原理的に一致しない**。
+     * ★PHP のクラス参照として使われる文字列を守る様式なので、PHP の言語仕様に合わせて
+     *   クラス部・メソッド部とも **ASCII 大小を無視**して比較し、先頭の `\` は落として正規化する。
+     */
+    case FqcnMethodReference;
+
+    /**
+     * 非 PHP の生テキストに現れる**完全修飾クラス名**そのものの完全一致。
+     *
+     * ★`FqcnMethodReference` と同じトークン文字集合 `[A-Za-z0-9_\\]` を使い、
+     *   先頭の `\` を落とし、連続する `\` を 1 つへ畳んで (二重引用符内の
+     *   エスケープ表記 `A\\B` を吸収する)、ASCII 大小を無視して比べる。
+     * ★撤去した middleware の**実体クラス名**は、拡張子なしの PHP スクリプト・シェル・
+     *   YAML など「PHP として扱わないファイル」からも実行可能な参照になり得るので、
+     *   クラス名だけの様式が要る (メソッド名を伴わない)。
+     */
+    case FqcnReference;
+}

```

上記を踏まえて再レビューし、最後に全体判定 (`APPROVED` / `CHANGES_REQUESTED`) を 1 語で明記せよ。
