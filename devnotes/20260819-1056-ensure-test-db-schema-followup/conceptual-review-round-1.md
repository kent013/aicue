- 全体判定: **CHANGES_REQUESTED**

### 1. 使命との整合性

- [Suggestion] AI-CUE の利用者価値を直接増やす変更ではありませんが、撮影・動画生成機能を安全に継続開発するためのテスト再現性を支える、適切な開発基盤改善です。特に worktree ごとの初回失敗と実行順依存を減らす狙いは、使命への間接貢献として妥当です。

### 2. 禁止事項違反

- [Suggestion] `migrate:fresh` 等を使わず `migrate` のみに限定し、dev DB 到達を複数層で防ぐ方針は、禁止事項 3 と整合しています。

- [Suggestion] HTTP 応答や LLM、UI に関わる変更ではないため、`response()->json()`、PromptDefense、disabled UI の禁止事項には抵触しません。

### 3. 実現可能性

- [Critical] 「更新後の直接 PDO 到達確認」が `migrations` テーブルの存在と件数 `>= 1` だけでは、基点 DB が最新であることを証明できません。基点 DB が古いままでも、子プロセスが誤って dev DB など別 DB に `migrate` を実行し、基点 DB に古い `migrations` テーブルが残っていれば、`migrate:status` と件数確認の双方を通過できます。これは設計が掲げる第 4 保護層と fail-closed 要件を満たしません。

  修正提案: `pgsqlTestDatabasePdo()` で基点 DB に接続した後、`database/migrations` の全 migration ファイル名が `migrations` テーブルに含まれることを直接確認してください。比較は提案済みの `BaseTestDatabaseSchemaTest` と同じ「ローカル migration ファイル → DB 上の適用済み migration」の包含関係にします。これにより、artisan 側の接続先解決と独立して、更新対象が基点 DB だったことを検証できます。

- [Warning] 同一 worktree から `run-test.sh`、`run-browser-test.sh`、あるいは直接の `ensure-test-db.php` 実行が重なった場合、同一基点 DB に対する `migrate` が競合し得ます。「グローバルテストロックの内側」という前提は重要ですが、4 つの呼び出し元すべてで成立することが設計上明文化・検証されていません。

  修正提案: 全呼び出し経路が同一ロックを取得してから ensure を呼ぶことを確認し、スクリプト契約または Architecture テストで固定してください。直接実行を正式に許容するなら、ensure 自身に既存ロック機構と整合する排他を持たせるか、直接実行は非並行利用のみという保証範囲を明記してください。

### 4. 期待効果の妥当性

- [Warning] 「新規 worktree の初回テストがスキーマ不整合で落ちなくなる」は、基点 DB の全 migration 適用を直接確認して初めて合理的に期待できます。現設計の件数確認のままでは、古い基点 DB を成功扱いする余地があります。

  修正提案: 上記の migration 名集合の直接照合を ensure の成功条件に含め、期待効果の根拠も「基点 DB の最新性をスクリプト実行時に検証する」と書き換えてください。

- [Suggestion] 「実行順依存を減らす」ではなく、対象範囲では「基点 DB のスキーマ状態を ensure 完了時点で決定する」と表現すると、効果と保証範囲がより明確です。

### 5. リスク

- [Warning] `setup-worktree.sh` では ensure の失敗を警告扱いで続行するため、直後に利用者が Architecture テストだけを直接実行すると、古い基点 DB を読む可能性が残ります。後続の `run-test.sh` が再実行することは通常経路の救済ですが、失敗を解消したことにはなりません。

  修正提案: setup 時の警告に「テスト実行前に `scripts/run-test.sh` を通す必要がある」ことと、失敗理由を明示してください。また、直接 Artisan 実行はこの準備保証の対象外であることを文書化してください。

- [Suggestion] 子プロセスの環境を allowlist 化する方針は強い安全策です。`PATH`、`HOME`、`TMPDIR` 以外を意図的に継承しない理由と、Laravel が必要とする値をすべて明示設定する責務を docblock に残す方針は維持すべきです。

### 6. スコープの適切さ

- [Suggestion] worker DB、孤児 DB 回収、DROP 経路、ロック待ち監視をスコープ外にした判断は適切です。AG-135 追従に必要な最小範囲に収まっています。

- [Suggestion] D30 の既存上積みを削らず、`Create → StampProvenance → UpdateSchema` の順序を純関数で表す設計は、差分の可視性とテスト可能性の両面で良い方針です。

### 7. 型安全性

- [Warning] `pgsqlTestArtisanEnv(): array` と `runTestDatabaseArtisan()` の戻り値が曖昧な配列のままだと、PHPStan level 10 で shape 不明瞭になりやすく、`status`・`output` の取り扱いを誤る余地があります。

  修正提案: 少なくとも PHPDoc で `array<string, string>`、`array{status: int, output: string}` のような配列 shape を固定してください。既存のスクリプト規約が許すなら、小さな readonly DTO を使う方がさらに明確です。これは HTTP DTO/JsonResource の対象ではありませんが、同じ「境界値を型で固定する」原則に沿います。

- [Suggestion] `TestDatabaseEnsureAction` enum と `list<TestDatabaseEnsureAction>` に順序を閉じ込める方針は型安全性に寄与します。`match` を網羅的に維持し、`UpdateSchema` 追加時に未処理分岐が検出される形を保つべきです。