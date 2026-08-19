# 対応マトリクス: impl-review Round 1

## [Warning] D33 が新設されず、到達確認強化と専用設定キャッシュパスが D30 内へ取り込まれている
- 判断: 反論する (意図的な変更。理由を Round 2 プロンプトで説明する)
- 根拠: 詳細設計書どおりに D33 を独立エントリとして `docs/template-divergence.md` へ追加すると、
  D33 の対象パス (`scripts/ci/pgsql_test_conn.php` / `scripts/ci/ensure-test-db.php`) が
  D30 の対象パスと完全に重複する。本リポジトリの `docs/template-divergence.md` は
  「対象パスは全登録の和集合で重複しないこと」という機械強制の規約を持ち、
  `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` (実体は
  `tests/Support/TemplateDivergence/DivergenceLedgerRules.php` の TD4) が
  「同じパスを 2 つのエントリが挙げている」ことを deny-by-default で検出して赤くする。
  詳細設計の Round 1〜4 レビューはテキストレベルのレビューであり、この機械検査を
  実際に走らせて確認してはいなかった (走らせれば TD4 で確実に落ちる)。
  また、D33 で提示されていた `状態: 還流候補` も、同ファイルの状態の値域
  (`恒久` / `監視中` の 2 値のみ。`DivergenceLedgerRules::STATES`) に無い値であり、
  そのままでは TD5 でも落ちる。
- 対応内容: D33 を独立エントリとして新設せず、その内容 (到達確認の強化基準・専用非キャッシュ
  パスの採用理由・還流候補としての位置づけ・再判定条件・保証しないもの) を D30 の本文
  (`### 到達確認を正典より強めた基準と専用の非キャッシュ設定パス (還流候補)` という `###`
  見出しの节) へ**プロース (自由記述) として**折り込んだ。`###` 見出し・地の文は
  `DivergenceLedgerRules` の走査対象外 (docblock に明記済み: 「登録エントリ領域より前の節と、
  エントリの中の `###` 見出し・地の文は見ない」) なので、この形であれば TD4/TD5 の対象にならず、
  かつ設計が意図した情報(強化した基準・還流候補としての性質・保証しないもの)は全て文書上に残る。
  D30 の登録メタ (対象パス・決めた日・決めた人・根拠・状態・見直し期限) は変更していない
  (詳細設計自身も「D30 の登録そのものは...再判定の条件には当たらない」として変更しないと
  明記していた方針と一致する)。
  `docs/worktree-isolation-strategy.md` 側の参照も `aicue:D30` のままにした
  (D33 が存在しないため、これは誤りではなく正しい参照である)。

## [Warning] dev DB 保護の説明が aicue:D30 を参照しているが、承認済み設計では aicue:D33
- 判断: 見送る (上の対応により解消済み。D33 は存在しないため D30 参照が正しい)
- 根拠: 上記のとおり D33 を独立登録しない判断をしたため、この指摘は前提が変わった。
- 対応内容: 変更なし (既に `aicue:D30` を参照している)。

## [Warning] 実行時間の実測値が docblock に無い
- 判断: 対応する
- 根拠: 詳細設計の制約・前提が「実装フェーズで aicue でも実測し、docblock に記録する」と
  明示していた。
- 対応内容: `scripts/ci/ensure-test-db.php` の docblock へ実測値を追記した。
  - 何もしないとき (migrate が "Nothing to migrate" になる場合): 約 0.66 秒
  - 空の DB から全 75 migration 適用のとき: 約 0.99 秒
  (`performTestDatabaseSchemaUpdate()` の呼び出しのみを計測。dev DB には触れず、
  allowlist に一致する使い捨ての test DB `app_test_deadbeef` を作成・計測後に削除して測定した)。

## [Suggestion] 「実子プロセスも起動しない」という説明が不正確 (require 順検査は proc_open を使う)
- 判断: 対応する
- 根拠: 指摘のとおり正確でない。
- 対応内容: `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` の docblock を
  「artisan の実子プロセスは起動しない。ただし末尾の require 順検証だけは...PHP の別プロセスを
  `proc_open()` で起動する」へ修正した。

## [Suggestion] 副次的ソース検査 (str_contains) はコメント中の禁止語も検出するので説明が不正確
- 判断: 対応する
- 根拠: 指摘のとおり正確でない (`file_get_contents()` の生ソースを見ているのでコメントも含む)。
- 対応内容: 「コメント中に同じ文字列を書いても検出するが、文字列を分割して動的に組み立てる
  呼び出しは検出できない」へ修正した。
