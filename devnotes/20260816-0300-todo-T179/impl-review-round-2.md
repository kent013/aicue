`docs/template-divergence.md`

- [Suggestion] 「誤登録は 1 行消せば済む」は、実際にはメタ表と本文を含むエントリ全体を削除するため不正確です。「誤登録はエントリを削除すれば是正できる」などが適切です。判定を妨げる問題ではありません。
- Round 1 の D22 / D23 に関する [Critical] は閉じています。外部の台帳リポジトリから届いた判定を根拠として明示し、追加機能との区別が不確かなまま登録する理由も説明されています。
- D23 の対象パス不足も、掃除器を実ファイルへ展開したことで閉じています。

`tests/Support/TemplateDivergence/DivergenceLedgerParser.php`

- Round 1 の囲み解析に関する [Warning] は閉じています。言語名付き囲みを P3 として明示的に拒否し、閉じ側も同じ条件に統一されています。
- 10 行目の列数に依存せず、9 行直後の表行を拒否するため、メタ表の抜け道も閉じています。
- 解析不能を違反として返す fail-closed の性質は維持されています。

`tests/Unit/Architecture/DivergenceLedgerRulesTest.php`

- Round 1 のテスト不足に関する [Warning] は閉じています。
- 言語名付き囲みと、2 列・3 列双方の余分なメタ行について負例が追加され、修正内容を直接固定しています。

`tests/Support/TemplateDivergence/DivergenceLedgerRules.php`

- 新たな指摘はありません。Round 1 以降の変更による判定器との不整合もありません。

`tests/Architecture/TemplateDivergenceLedgerFormatTest.php`

- 新たな指摘はありません。現物検査が追加された対象パスの実在・重複も確認します。

`EntryMetadata.php` / `LedgerContext.php` / `ParsedEntry.php` / `ParsedLedger.php` / `TodoLedgerReference.php`

- 新たな指摘はありません。

`AGENTS.md` / `docs/app-integration-guide.md`

- 新たな指摘はありません。

APPROVED