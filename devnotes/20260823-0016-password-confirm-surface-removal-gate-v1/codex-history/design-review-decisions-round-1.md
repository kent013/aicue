# 対応マトリクス: design-review Round 1

全 6 施策のうち S7 のみ APPROVE。Critical 11 件・Warning 8 件・Suggestion 2 件。**すべて対応する**（反論・見送りは 0 件）。

---

## S5 [Critical] 4 条件の許可形は、場所の allowlist でなくても「出現を違反集合から除く許可規則」であり正典 I3 と矛盾する
- 判断: **対応する（許可形を廃止し、検出対象そのものを定義し直す）**
- 根拠: 指摘が正しい。「文字列 `password.confirm` の全出現」を母集団にした時点で、そこから除く規則はどう書いても許可規則になる。設計書 I3 の「許可一覧を持たない」と自家撞着していた。
- 対応内容: **母集団の定義を変える**。静的層の検出対象を「文字列 `password.confirm` の出現」ではなく「**撤去した middleware の適用・登録を表す構文**」にする。これで `config/seo.php` の route 名見出し表は**最初から母集団に入らない**（除外規則が不要になる）。検出対象は次の 2 つで、どちらも**許可形なしの 0 件固定**:
  - **(i) alias 文字列**: middleware 位置に現れる文字列リテラルで、その値が `password.confirm` と完全一致するか `password.confirm:` で始まるもの
  - **(ii) 実体クラス**: middleware 位置に現れるクラス参照で、完全修飾名が `Illuminate\Auth\Middleware\RequirePassword` に解決されるもの（S5 の 2 つ目の Critical への対応でもある）
- **middleware 位置の定義（有限・宣言する）**:
  - M1: 呼び出し名が `middleware` / `withoutMiddleware` / `middlewareGroup` / `appendToGroup` / `prependToGroup` / `alias` のいずれかである呼び出しの引数（直接、または引数の配列リテラルの要素）
  - M2: キー名が `middleware` を**部分文字列として含む**（ASCII 大小無視。`management_middleware` / `api_middleware` を拾う）配列要素の値、およびその値が配列リテラルならその要素
  - M3: プロパティ `$middleware` / `$middlewareGroups` / `$middlewarePriority` の初期化配列の要素
- **保証範囲の明示（AGENTS.md (b) の 2 つ目に従い、検出力の主張を狭める）**: 静的層が保証するのは**列挙した middleware 位置での再流入だけ**である。列挙外の位置からの復活は、**実行時層（全 route の解決済み middleware を走査する deny-by-default）が捕まえる**。この分担を両ファイルの docblock に書く。

## S5 [Critical] 文字列 alias だけを見ると `->middleware(RequirePassword::class)` を静的層で検出できない
- 判断: **対応する**
- 対応内容: 上記 (ii)。middleware 位置のクラス参照を `use` / group use / alias / `namespace\` / 現在 namespace を解いた**完全修飾名**（ASCII 大小無視）で `Illuminate\Auth\Middleware\RequirePassword` と突き合わせる。解決できない参照は**未解決として gate を落とす**。

## S5 [Warning] 各 `ScanOutcome::$unresolved` が必ず違反判定に使われることを構造上明示せよ（(d) 違反回避）
- 判断: **対応する**
- 対応内容: gate 側に「**その gate が呼んだすべての `ScanOutcome` の `unresolved` を 1 つの集合へ集めて空を要求する**」共通ヘルパを置き、各 gate の最後で必ず呼ぶ。母集団側の `unresolved` / `binaryExcluded` も同じ集合へ入れる。

## S5 [Warning] テスト名の「見出し対応表」が本文の「見出しとは断定しない」と矛盾
- 判断: **対応する（許可形の廃止により当該テスト自体が消える）**
- 対応内容: 「見出し」という語を設計から削除する。

---

## S4 [Critical] `gatherMiddleware()` だけでは group 展開・alias のクラス解決を保証できない
- 判断: **対応する**
- 根拠: そのとおり。alias `password.confirm`、パラメータ付き alias、group への再導入、`RequirePassword::class` の直接指定を取りこぼす。
- 対応内容: 実測で `Illuminate\Routing\Router::gatherRouteMiddleware(Route $route)` が **public** であり、alias と group を解決した最終的な middleware 文字列（`Class:params` 形）を返すことを確認した。実行時層はこの**解決済み集合**を使い、
  - `Illuminate\Auth\Middleware\RequirePassword` に解決される要素が 1 つも無いこと
  - 併せて素の `gatherMiddleware()` 側でも alias 文字列 `password.confirm` が無いこと（alias 登録自体の再流入を見る）
  の 2 本立てにする。**消しすぎ確認（recent-auth の生存）も同じ解決済み集合**で行い、alias 名のハードコードを不要にする。

## S4 [Critical] `fortify` / `fortify-options` だけの列挙は「設定木から母集団を生成」していない
- 判断: **対応する**
- 対応内容: `config()->all()` の**全設定木**を再帰走査し、キー名が厳密に `confirmPassword` の要素を母集団として生成する。母集団の下限 2 件に加えて、**既知の 2 パスが含まれること**を代表値として pin する（パッケージ設定の未ロードを検出できる）。

## S4 [Warning] `expect(...)->toBeArray()` は PHPStan の型を絞り込まない
- 判断: **対応する**
- 対応内容: `if (! is_array($tree)) { throw new RuntimeException(...); }` の明示分岐で絞り込んでから補助関数へ渡す。`config('manual')` も同じ。

---

## S3 [Critical] `token_get_all($source)` は構文検証しないので「壊れた PHP が必ず例外になる」は成立しない
- 判断: **対応する**
- 対応内容: 新走査器の中で **`token_get_all($source, TOKEN_PARSE)` による事前検証**を行い、`ParseError` / `Error` を捕捉して `unresolved` へ変換する。**共有 `PhpTokenScan::normalize()` の挙動は変更しない**（既存利用者 2 本への波及を避ける）。正規化そのものは従来どおり `PhpTokenScan::normalize()` に一本化し、事前検証の 1 パスを足す形にする（二重トークン化のコストは全数 925 本で許容範囲。docblock に理由を書く）。

## S3 [Critical] `strpos` + 前後文字集合は AGENTS.md (e) の「区切り文字で分割したトークンの完全一致」ではない
- 判断: **対応する（非対称境界の考えを捨てる）**
- 根拠: 指摘のとおり、非対称境界はトークン完全一致として説明できない。
- 対応内容: 判定を**宣言した区切りで分割したトークンの完全一致**へ置き換える:
  - **区切りの宣言**: トークン文字は `[A-Za-z0-9_.-]`。それ以外の文字をすべて区切りとし、生テキストを**最長の連なり（run）**へ分割する
  - **撤去語ごとに一致様式を宣言する**:
    - `ExactRun`（run 全体との完全一致）: `password.confirm` / `imageSourceDocumentsEnabled` / `OCR_ANALYSIS_ENABLED` / `imagesEnabled`
    - `RunSegment`（run を `.` で割った**いずれかの segment** との完全一致）: `ocr_analysis_enabled`（`manual.ocr_analysis_enabled` のような設定パス表記に当てるため）
  - これで `password.confirm.store` / `password.confirmation` / `auth.password_confirmed_at` / `ocr_analysis_enabled_at` / `legacy_ocr_analysis_enabled` / `disable_ocr_analysis_enabled` は**すべて完全一致に失敗して除外**され、`password.confirm:web` は run が `password.confirm` になって一致する
  - 非対称な継続文字集合は**廃止**する。`config.password.confirm` のような同一語への到達路は run 全体が違うため一致しなくなるが、それは**実行時層が捕まえる**ので保証範囲の記述で明示する

## S3 [Critical] PHP を文字列リテラルに限ると `public bool $imageSourceDocumentsEnabled;` / `const OCR_ANALYSIS_ENABLED = true;` を検出できない
- 判断: **対応する**
- 対応内容: `scanPhpLexemes()` を追加し、コメント / docblock を除いたトークン列のうち **`T_STRING`（識別子・定数名・メソッド名）/ `T_VARIABLE`（先頭 `$` を除く）/ `T_CONSTANT_ENCAPSED_STRING`（引用符を除いた値）/ heredoc・nowdoc の本体（`T_ENCAPSED_AND_WHITESPACE`）/ `T_NAME_QUALIFIED`・`T_NAME_FULLY_QUALIFIED`・`T_NAME_RELATIVE`** を母集団にする。OCR の 3 語はこの lexeme 走査で判定する（PHP 正例も追加する）。

## S3 [Warning] `valueLiteral` の「単独の文字列」判定が不足（`'安全な値'.SomeClass::class` を誤判定）
- 判断: **対応する（許可形の廃止により当該判定自体が消える）**
- 対応内容: S5 の許可形を廃止したので `PhpStringOccurrence::$valueLiteral` と関連する連結判定は**設計から削除**する。

## S3 [Warning] PHP のクラス名・メソッド名は大小を区別しない。`T_NAME_RELATIVE` / group-use alias / 複数 namespace / bracketed namespace が未定義
- 判断: **対応する**
- 対応内容: 完全修飾名とメソッド名の比較は先頭 `\` を落として **ASCII case-insensitive** で行う。対応する名前構文（`use` / `use ... as` / group use / group use 内 alias / `T_NAME_FULLY_QUALIFIED` / `T_NAME_QUALIFIED` / `T_NAME_RELATIVE`（`namespace\`）/ 名前空間宣言の**複数形・ブロック形**）を docblock に列挙し、**列挙外は未解決として gate を失敗させる**。正例・負例を追加する。

---

## S2 [Critical] git 追跡下なのに `is_file()` が偽のファイルを黙って除外すると fail-open になる
- 判断: **対応する**
- 対応内容: `continue` で捨てるのをやめ、**`unresolved` へ理由つきで登録**する（削除途中・壊れた symlink を無言で母集団から落とさない）。

## S2 [Critical] `binaryExcluded` を失敗条件にしていないので、NUL を 1 つ入れるだけで静的層を迂回できる
- 判断: **対応する**
- 根拠: 実測 0 件なので不変条件にできる。
- 対応内容: **`binaryExcluded === []` を gate の不変条件**にする。将来バイナリを走査根へ置く必要が出たら、そのとき理由つきで設計判断する（無言では許容しない）。

## S2 [Suggestion] 根ごとに代表パスを 1 件 pin すると root 割当・パス計算の誤りも検出できる
- 判断: **対応する**
- 対応内容: 各根につき代表パスを 1 件ずつ pin する（`app/Providers/FortifyServiceProvider.php` / `config/seo.php` / `routes/web.php` / `bootstrap/app.php` / `lang/ja/validation.php` / `resources/js/pages/Settings/Security.svelte` / `scripts/ci/drop-test-db.php` / `.github/workflows/ci.yml`）。

---

## S1 [Critical] バイナリ負例を走査器へ直接渡す計画と、NUL 判定が母集団側にある設計が接続していない
- 判断: **対応する**
- 対応内容: NUL 判定を `RemovedSurfaceScanTargets` の**純関数として切り出し**（`isBinary(string $contents): bool`）、バイナリ見本はその関数の自己検証へ移す。走査器の負例からは外す。実母集団側は `binaryExcluded === []` を要求する。

## S1 [Warning] `positive-unregistered-route-key.php.txt` は後方境界の都合で `password.confirm` に一致しない
- 判断: **対応する（許可形の廃止により当該見本が不要になる）**
- 対応内容: 当該見本を**削除**する。条件 4（登録済み route 名との突合）自体が設計から消える。

## S1 [Warning] 大小違い・`namespace\...`・group-use alias・bracketed namespace・heredoc/nowdoc の見本が無い
- 判断: **対応する**
- 対応内容: 正例へ追加する — `IMAGESENABLED()` / `AcceptedSourceDocumentTypes::IMAGESENABLED()`（大小違い）、`use App\Support\Manual\{AcceptedSourceDocumentTypes as Types};`（group use 内 alias）、`namespace\AcceptedSourceDocumentTypes::imagesEnabled()`（`T_NAME_RELATIVE`）、`namespace App\Support\Manual { … }`（bracketed namespace）、heredoc 本体に撤去語を含む形。

---

## S6 [Critical] OCR の 3 語を PHP の文字列リテラルに限るのは不十分（DTO/Resource/controller のプロパティ名・変数名で復活できる）
- 判断: **対応する**
- 対応内容: S3 の `scanPhpLexemes()` を使い、識別子・変数・定数・文字列を含む字句走査へ変更する。各語に PHP 正例（プロパティ宣言 / 変数 / クラス定数 / 配列キー）を追加する。

## S6 [Critical] `imagesEnabled` の FQCN 解決に case-insensitive 性と名前構文の網羅が不足
- 判断: **対応する**
- 対応内容: S3 の対応と同じ（ASCII 大小無視、名前構文の列挙、列挙外は未解決）。「保証対象から外すだけ」では保護対象の静的呼び出しを書けてしまう、という指摘に従い、**外すのではなく未解決で落とす**側にする。

## S6 [Warning] `config('manual')` の型は `is_array()` 分岐で絞り込め
- 判断: **対応する**（S4 の Warning と同じ）

## S6 [Warning] S6 単独実行でも fail-closed になるよう、使用した全 `unresolved` と `binaryExcluded` を S6 自身が判定せよ
- 判断: **対応する**
- 対応内容: 共通ヘルパを S5 / S6 の**両方**が各々呼ぶ（片方だけに置かない）。

## S6 [Suggestion] 消しすぎ確認で参照する既存テストは、正確な test 名と担保する assertion まで docblock に書け
- 判断: **対応する**
- 対応内容: `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php` と `tests/Feature/Projects/SourceDocumentUploadTest.php` の該当 test 名を実読して docblock に明記する。

---

## S7 [APPROVE]
- 変更なし。
