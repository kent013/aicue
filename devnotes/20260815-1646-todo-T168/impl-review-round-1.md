**AGENTS.md**
- 判定: OK
- [Suggestion] 詳細設計 S6 より少し具体化されており、例外登録済みファイルも全語彙走査する点まで書けている。保証範囲も誇張していない。HTTP 境界 / frontend 変更なし。

**tests/Support/ForbiddenStatement/ForbiddenStatementKind.php**
- 判定: OK
- [Suggestion] `label()` の `ShortEchoTag` が設計案の `開始タグ付きの出力記法 (<?=)` から短くなっているが、実害はない。AGENTS.md 側では表現できている。

**tests/Support/ForbiddenStatement/ForbiddenStatementSite.php**
- 判定: OK

**tests/Support/ForbiddenStatement/ForbiddenStatementRootPolicy.php**
- 判定: OK

**tests/Support/ForbiddenStatement/ForbiddenStatementExemption.php**
- 判定: OK

**tests/Support/ForbiddenStatement/ForbiddenStatementScanner.php**
- 判定: 要注意だが承認可能
- [Warning] 読み飛ばし規則は設計 S2 と一致している一方、PHP 8.4 前提としては合法な名前位置の一部に false positive が残る可能性がある。例: typed class constants の `class A { public const string echo = 'x'; }` や参照返しメソッド `class A { public function &echo(): mixed {} }` は、禁止文ではなく名前だが、直前トークンが `T_CONST` / `T_FUNCTION` ではなくなるため検出され得る。現行コードベースで使っていないなら gate としては成立するが、「構文上あり得る名前位置をすべて誤検出しない」とまでは言えない。これは実装だけでなく詳細設計 S2 の読み飛ばし規則側にも追記検討が必要。
- [Suggestion] R6 が「直後が単独 `:` なら名前つき引数」として前文脈なしに読み飛ばすのは設計通り。ただし構文妥当性を見ない走査器なので、将来別文脈で `echo:` が合法化されると広すぎる規則になる。現行 PHP では問題なし。

**tests/Architecture/ForbiddenStatementTokenInvariantTest.php**
- 判定: OK
- [Warning] `forbiddenStatementScanResult()` は `file_get_contents()` 失敗時に `continue` しており、そのファイルは「走査済み」にも「失敗」にも数えられない。git 追跡ファイルの読み取り失敗は環境異常なので、設計 S5 の「file_get_contents() の false を is_string() で弾く」より一歩進めて例外で落とす方が fail-closed。通常 CI では発火しにくいが、silent skip 系の不変条件としては弱い。
- [Suggestion] `resources` と `routes` の間の空行は意味を持たないが、目録の視認性としては設計案通り詰めてもよい。
- 設計一致性: G1〜G12 は概ね実装されている。例外ファイル丸ごと skip ではなく `(path, kind)` 単位で差し引く点も設計通り。空振り防止も `floor` / blade 件数 / root 分類で固定できている。
- PHPStan: tests は phpstan paths 外という前提でも、型注釈と null 処理は概ね問題なし。

**tests/Unit/Architecture/ForbiddenStatementScannerTest.php**
- 判定: OK
- [Warning] S3 の正負・取りこぼし対照は設計通り 30 本あるが、上記の typed class constants / 参照返しメソッドのような PHP 8.4 合法構文は未カバー。これは本差分の主目的を壊すほどではないが、走査器の「誤検出なし」の主張を強めるなら追加したい。
- [Suggestion] 検体を `php -l` 済みとコメントに書いている点はよい。テスト本文から `php -l` を呼ばない判断も設計通り。

**全体**
設計 S1〜S6 との一致性は高いです。HTTP 境界、DTO / JsonResource、Svelte / CSS / Atomic Design は対象外で問題ありません。gate が検査対象 0 件で緑になる経路も、床値・blade 件数・root 分類・fail-first 実測でかなり潰せています。

残る主な懸念は、読み飛ばし規則が「設計通りに狭い」反面、PHP 8.4 の合法な名前位置を少し取りこぼして false positive になり得る点です。現行コードベースの禁止文侵入を止める gate としては許容可能で、修正必須のセキュリティ穴や false negative は見当たりません。

APPROVED