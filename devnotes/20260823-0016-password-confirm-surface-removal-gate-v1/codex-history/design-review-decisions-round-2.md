# 対応マトリクス: design-review Round 2

Critical 2 件・Warning 8 件・Suggestion 1 件。**すべて対応する**（反論・見送りは 0 件）。

## [Critical] 非 PHP 用 FQCN の `ExactRun` は宣言した `TOKEN_CHARACTERS` では絶対に一致しない（S3 / S6）
- 判断: **対応する**
- 根拠: 指摘のとおり。`\` と `:` は区切りなので `App\Support\Manual\AcceptedSourceDocumentTypes::imagesEnabled` は 5 つの run へ割れ、`ExactRun` は原理的に成立しない。S6 の非 PHP FQCN 検査と `positive-fqcn-in-text.sh.txt` が机上で破綻していた。
- 対応内容: **FQCN メソッド参照専用の一致様式 `TermMatchMode::FqcnMethodReference` を追加**する。
  - 専用のトークン文字集合を宣言する: `[A-Za-z0-9_\]`（`\` を**含む**）。生テキストをこの集合の最長の連なりへ割り、直後が `::` でその次が `[A-Za-z0-9_]` の連なりであるときに「クラス部 + `::` + メソッド名」として構文的に切り出す
  - 比較は**先頭 `\` を落として正規化**したうえで、クラス部・メソッド名とも **ASCII case-insensitive** の完全一致（PHP のクラス名・メソッド名の言語仕様に合わせる。「PHP クラス参照として使われる文字列を守るなら専用様式も case-insensitive にするのが整合的」という指摘に従う）
  - 正例・負例で固定する組み合わせ: 先頭 `\` の有無 / ASCII 大小違い / **同じ短名を持つ別 namespace** / メソッド名の接尾辞つき（`imagesEnabledAt`）/ 対象クラスだが別メソッド / 別クラスだが同じメソッド

## [Critical→対応済みの派生] S6 の非 PHP 完全修飾参照の見本を作り直す
- 判断: **対応する**
- 対応内容: `positive-fqcn-in-text.sh.txt` を上記様式に合わせて作り直し、負例 `negative-fqcn-other-namespace.sh.txt`（`App\Other\AcceptedSourceDocumentTypes::imagesEnabled`）/ `negative-fqcn-other-method.sh.txt` / `negative-fqcn-method-suffix.sh.txt` を追加する。

## [Warning] `catch (\ParseError|\Error $e)` は親型と子型の同時指定で冗長（S3）
- 判断: **対応する**
- 対応内容: **`catch (\ParseError $e)` の 1 つだけ**にする。`TOKEN_PARSE` の失敗は `ParseError` で表現されるため十分であり、予期しない実行時障害まで「解析未解決」へ変換しない（そこは素直に例外を伝播させ、テストを落とす）。この方針を docblock に書く。

## [Warning] `self` / `static` を一律未解決とする説明が不正確（S3）
- 判断: **対応する**
- 根拠: 現在クラスを追跡していれば `self::imagesEnabled()` は対象クラスへ解決できる。「解決しても意味がない」は誤り。
- 対応内容:
  - `self` → **現在のクラス**（宣言中の class / trait / enum 名 + 現在 namespace）へ解決する
  - `static` → **現在の宣言クラス**を候補として保守的に扱う（遅延静的束縛で別クラスになり得るが、拾いすぎる方向は可、見逃す方向は不可という AGENTS.md (b) の原則に従う）
  - `parent` → `extends` の参照を解ければそれへ、**解けなければ未解決**にする
  - 正例に `self::imagesEnabled()` を、負例に別クラス内の `self::imagesEnabled()` を置く

## [Warning] `config()->all()` は契約上すでに配列で、`is_array()` が「常に true」と報告され得る（S4）
- 判断: **対応する**
- 対応内容: `config()->all()` に対する `is_array()` 分岐を**外す**。要素型が足りない箇所だけ局所的に `array<string, mixed>` を注釈し、**実型は緩めない**。`config('manual')`（`mixed` 戻り）側の `is_array()` は**維持する**。

## [Warning] 「M1〜M3 外の穴は実行時層が塞ぐ」は保証の誇張（S5）
- 判断: **対応する**
- 根拠: 実行時テストが観測できるのはテスト環境で実体化した route だけであり、production 限定の条件分岐や未実行コードからの再導入は両層を通過し得る。
- 対応内容: docblock の表現を次へ改める。
  > M1〜M3 の外は**静的層の保証外**である。実行時層はテスト起動時に実体化した全 route について補完するが、**環境依存で実体化しない経路までは保証しない**。

## [Warning] FQCN 解決の自己検証に「同じ短名を持つ別クラス」の負例が無い（S1 / S5 / S6）
- 判断: **対応する**
- 根拠: 現状の負例（`RequireRecentAuth` / `Thing`）は短名が違うため、短名一致へ退行しても検出できない。AGENTS.md (a)「同名の別クラスを拾わない」の直接の裏取りが欠けていた。
- 対応内容: 負例に次を追加する。
  - `use App\Other\RequirePassword;` → `->middleware(RequirePassword::class)`（短名一致）
  - `->middleware(\App\Other\RequirePassword::class)`（FQCN）
  - `use App\Other\RequirePassword as RP;` → `->middleware(RP::class)`（alias を対象と同じ短名に寄せた形も別途置く: `use App\Other\Foo as RequirePassword;`）
  - `\App\Other\AcceptedSourceDocumentTypes::imagesEnabled()`（PHP 側）と `App\Other\AcceptedSourceDocumentTypes::imagesEnabled`（非 PHP 側）

## [Warning] UTF-8 不正判定が `population()` 内に閉じており、自己検証へ接続できない（S1 / S2）
- 判断: **対応する**
- 対応内容: **`RemovedSurfaceScanTargets::classifyContents(string $contents): ContentClassification`** を切り出し、`enum ContentClassification { case Text; case Binary; case InvalidUtf8; }` を返す。`population()` も自己検証も**必ずこの 1 関数を通す**。`isBinary()` は分類関数へ吸収する（同じ判定を 2 本持たない）。

## [Suggestion] 追跡下 symlink の方針を明示すべき（S2）
- 判断: **対応する**
- 根拠: `is_file()` + `file_get_contents()` は symlink 先が通常ファイルならリポジトリ外も読み得る。
- 対応内容: 追跡下のパスが symlink である場合、**`realpath()` がリポジトリルート配下へ解決されることを検証**し、外へ出るものは `unresolved` にする。方針を docblock に明記する。

## [Warning] PHP 側にも `App\Other\AcceptedSourceDocumentTypes::imagesEnabled()` の負例が必要（S6）
- 判断: **対応する**（上記「同じ短名を持つ別クラス」の対応に含む）

## [APPROVE] S7
- 変更なし。
