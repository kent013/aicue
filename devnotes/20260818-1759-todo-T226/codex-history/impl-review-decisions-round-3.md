# 対応マトリクス: impl-review Round 3

## [Warning] trait 内の `self::` を trait 自身の FQCN へ解決すると PHP の意味論と一致しない
- 判断: 対応する
- 根拠: 指摘のとおり。trait のコードは取り込んだクラスへ展開されるので `self` は trait 自身ではなく
  利用クラスを指し、同じ trait を複数のクラスが取り込めるため走査時点では 1 つに決まらない。
  trait 自身として Resolved にすると、利用側は未解決として拾えず**無言の見逃し**になる。
- 対応内容: scope の記録に「宣言が trait だったか」を持たせ、trait 本体では `self::` の
  受け手を `ReceiverName::unresolved()` にした (`ScanScopeKind` は公開している shape なので変えず、
  走査器の内部表現だけを増やした)。docblock も併せて直した。
  テストは 2 本 —
  `PhpReferenceScannerTest` の「trait 本体の self:: は未解決にする」(同じ見本の中に
  通常クラスの `self::` を並べ、片方だけが Resolved になることを固定) と、
  `ExternalClientBoundaryScannerTest` の「trait 本体の self:: 経由の大域 setter も
  fail-closed で検出する」(利用側まで伝播することの裏取り)。

## [Suggestion] `new` の直後の例外 (`self` / `static` / `parent`) を記述へ併記する
- 判断: 対応する
- 対応内容: `PhpReferenceScanner` の docblock と `docs/architecture.md` の両方へ、
  `self` / `static` / `parent` を短縮クラス名として解決しないこと、
  `static` / `parent` と trait 本体の `self` は未解決として返すことを書いた。

## 完了報告への注記
- `composer test` の全数 green を確認してから完了とする (Round 3 の指摘どおり)。
