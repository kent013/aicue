前提: 提供された差分テキストだけでレビューしています。コマンド実行・ファイル読み込みはしていません。

**docs/template-divergence.md**

- [Critical] D22 / D23 が、登録簿の定義と施策 5 の判定規則に対して矛盾しています。  
  施策 5 は「テンプレートに無い機能を足しただけなら逸脱ではない」と明記していますが、D22 / D23 の比較表はどちらもテンプレート側を「相当する仕組みを持たない」としています。これは「テンプレート構造からの逸脱」ではなく追加機能に見えます。登録するなら、テンプレートのどの相当構造を置き換えたのか、または外部の巡回所見がそれを逸脱として扱う根拠を本文に出す必要があります。現状のままでは登録簿が過剰登録になります。

- [Warning] D23 の対象パスが実体を十分に表しているか不明です。判断ログでは `app/Services/Billing/Retention/` 配下の掃除器群や検査 2 本にも触れていますが、メタ表の対象パスは enum と registry だけです。対象パス欄は重複検査と実在検査の入力なので、逸脱の実体が掃除器群にもあるなら実ファイルへ展開して載せるべきです。

**tests/Support/TemplateDivergence/DivergenceLedgerParser.php**

- [Warning] 囲みコード区画の仕様より実装が広く受けています。  
  設計は「行頭のバッククォート 3 個ちょうど」だけを許す方針ですが、実装は `str_starts_with($text, '```')` と `/^`{3}(?!`)/` により ` ```php` や ` ``` anything` も開閉として扱います。これは未対応 Markdown を明示拒否する fail-closed 方針とズレます。`/^```$/` のように閉じるのが安全です。

- [Warning] メタ表 9 行ちょうどの検査に抜けがあります。  
  9 行を読んだ直後の余分な行が 2 列なら検出されますが、3 列以上の table row は `splitRow()` が `null` を返すため、比較表の開始と区別されず通ります。つまり `| 備考 | extra | hidden |` のような「10 行目」を黙って通せます。9 行後は空行、または既知の比較表ヘッダだけを許す形に寄せるべきです。

**tests/Unit/Architecture/DivergenceLedgerRulesTest.php**

- [Warning] 上記 2 点の負例が不足しています。  
  ` ```php` / ` ``` markdown` を拒否するケースと、9 行後に 3 列以上の余分な表行を置いたケースを追加すると、設計の fail-closed と「9 行ちょうど」を固定できます。

**tests/Support/TemplateDivergence/DivergenceLedgerRules.php**

- [Suggestion] 判定器自体の構造は概ね設計どおりです。解析時違反の伝播、解析不能時の打ち切り、日付の往復検証、T 番号の境界付き照合への委譲は妥当です。

**tests/Architecture/TemplateDivergenceLedgerFormatTest.php**

- [Suggestion] 薄い検査層としての責務分離は設計どおりです。固定件数 23 も、設計追記の「最終 23 件」と一致しています。

**tests/Support/TemplateDivergence/EntryMetadata.php**

- [Suggestion] 問題ありません。生文字列を保持して Rules 側で判定する設計と一致しています。

**tests/Support/TemplateDivergence/ParsedEntry.php**

- [Suggestion] 問題ありません。

**tests/Support/TemplateDivergence/ParsedLedger.php**

- [Suggestion] 問題ありません。

**tests/Support/TemplateDivergence/LedgerContext.php**

- [Suggestion] 問題ありません。実在判定を注入して単体テストを純粋化する設計と一致しています。

**tests/Support/TemplateDivergence/TodoLedgerReference.php**

- [Suggestion] 問題ありません。`T1` が `T10` に一致する抜け道は避けられています。

**AGENTS.md**

- [Suggestion] 問題ありません。書式の中身を複写せず、正本と検査への参照に留めている点は設計どおりです。

**docs/app-integration-guide.md**

- [Suggestion] 問題ありません。古い兄弟比較への参照を `docs/template-divergence.md` の規約節へ寄せる変更として妥当です。

CHANGES_REQUESTED