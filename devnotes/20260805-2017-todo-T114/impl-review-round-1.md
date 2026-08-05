前提: コマンド実行は禁止されているため、提供 diff と実測ログの整合だけでレビューしています。UI/API 変更はないため DESIGN.md / Atomic Design 観点は該当なしです。

**scripts/ci/drop-test-db.php**
[Warning] `dropTestDbDropAll()` が DROP 失敗を握りつぶして件数だけ返し、`--orphans --apply` 経路も最終的に `exit(0)` します。従来 teardown の best-effort では妥当ですが、明示 apply は手動回収の成功/失敗を判定する入口なので、1 件でも DROP 失敗したら非 0 終了にすべきです。現状だと「一部 DB が残ったが成功扱い」という偽グリーンになります。従来経路は exit 0 維持、apply 経路だけ失敗件数で exit 1 がよいです。

その他、dev DB / bug-hunt DB への DROP 経路は末端で `isDevDatabase()` + allowlist 再検証があり、設計意図と一致しています。lock 後の再スナップショット/token 照合も満たしています。

**scripts/ci/pgsql_test_conn.php**
[Suggestion] `pgsqlStampProvenance()` は `$exec()` が例外を投げた場合だけ失敗扱いですが、`PDO::exec()` は設定次第で `false` を返す可能性があります。`@return bool 成功したか` の契約に合わせるなら、戻り値 `false` も warning + `false` にした方が実装意図が明確です。

**scripts/ci/ensure-test-db.php**
判定: 問題なし。作成時・既存時の両方で provenance stamp を通す設計になっており、C2 の「ラベル無し現役 DB を増やさない」条件と一致しています。

**tests/Support/Ci/TestDatabase*.php / tests/Unit/Ci/TestDatabaseClassificationTest.php**
判定: 問題なし。分類優先順位 `Protected → Live → Foreign → Orphan → Unlabeled`、`Orphan` / `Unlabeled` の `--include-hash` 明示指定制、Protected/Live 優先、worker の base 分類継承はテストで固定されています。

**tests/Architecture/GitIndexNormalizationTest.php**
判定: 問題なし。git/intl 不在を skip せず fail、正/負コントロールもあり、C1 の再発防止ゲートとして妥当です。

**AGENTS.md / scripts/README.md / docs/worktree-isolation-strategy.md / scripts/teardown-worktree.sh**
判定: 問題なし。`--apply` は LLM が実行しない契約が usage / AGENTS.md / scripts/README.md に入り、排他範囲の限界も明記されています。

全体判定: CHANGES_REQUESTED