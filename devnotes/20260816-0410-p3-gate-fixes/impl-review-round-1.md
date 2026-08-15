仮説: 2 本の gate は「既存検査の検出精度是正」に閉じており、真値照合・負/正対照・空振り検知が揃っていれば承認可能。提供 diff 上、その条件は満たしています。

**ファイル別判定**

- `docs/design-system.md`: 指摘なし。allowlist の新しい意味と運用が実装に追従している。
- `tests/js/support/ds-purity.ts`: 指摘なし。部分文字列除去から class token 完全一致へ変わっており、設計どおり fail-open を塞いでいる。
- `tests/js/architecture/ds-purity.test.ts`: 指摘なし。負の対照 3 形、正の対照、allowlist 登録の自己検査、走査空振り検知が揃っている。
- `tests/Support/GlobalUse/NonCompoundGlobalUseScanner.php`: 指摘なし。alias 除外、bracketed global namespace、statement line 共有、unresolved fail-closed が設計と一致している。
- `tests/Support/GlobalUse/PhpLintOracle.php`: 指摘なし。`PHP_BINARY` / `-n` / exit code 主判定 / `getExitCode()` null fail-closed が設計どおり。
- `tests/Architecture/NoNonCompoundGlobalUseTest.php`: 指摘なし。fixture 一覧 pin、真値空振り検知、syntaxValid 分離、実ツリー母集団確認が入っている。
- `tests/Architecture/fixtures/global-use/*.php.txt`: 指摘なし。検出 7 / 無違反 5 の構成が設計の真値ケースを網羅している。

**全体判定: APPROVED**