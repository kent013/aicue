Round 2 の指摘への対応です。

## 対応マトリクス (詳細は codex-history/impl-review-decisions-round-2.md にも保存済み)

### [Warning] D30 統合内容がメタ表に反映されていない → 対応する

D30 のメタ表 (業務要件起因の説明 / 揃え続ける不変条件と保証機構 / 再判定の条件) を、
統合後の内容を表すよう更新しました。状態 (`恒久`) と見直し期限 (`—`) は既に値域内で
一貫しているため変更していません (追加した内容も「還流候補ではあるが期限付きで能動的に
見直す根拠は無い」という性質のため、既存の `恒久` の枠内に収まります)。決めた日・決めた人・
根拠は変更していません (今回の追記は既存逸脱の延伸であり、新規逸脱の決定ではないため)。

`tests/Architecture/TemplateDivergenceLedgerFormatTest.php` を単体実行し green を確認しました。

## 更新後の D30 セクション全文
```markdown
## D30 テスト DB の作成と回収に出自の記録と孤児の分類を上積みする

| 行 | 内容 |
|---|---|
| 対象パス | `scripts/ci/drop-test-db.php` / `scripts/ci/ensure-test-db.php` / `scripts/ci/pgsql_test_conn.php` / `tests/Support/Ci/TestDatabaseEnv.php` / `tests/Support/Ci/TestDatabaseCandidate.php` / `tests/Support/Ci/TestDatabaseClassification.php` / `tests/Support/Ci/TestDatabaseDecision.php` |
| 業務要件起因の説明 | 実装を必ず worktree で行う進め方のため、テスト DB 名を worktree の realpath の hash から作っている。worktree が検証なしで強制撤去されると hash を再現できず、引数なしの回収では二度と落とせない孤児 DB が積み上がる (2026-08-05 の監査時点で 17 個 / 221.9 MB)。加えて、worktree ごとに基点 DB を新規作成するため、正典の到達確認 (「migrations 表があり行が 1 件以上ある」) では古い基点 DB に古い migrations 表が残っている状態を見逃す頻度が正典の想定より高い (2026-08-19 追記) |
| 揃え続ける不変条件と保証機構 | 孤児の回収も `drop-test-db.php` の中の同じ DROP の境界へ合流すること、dev DB の拒否と allowlist の再検査が `TestDatabaseEnv` の既存実装を共有すること、テスト DB 名が worktree の realpath から決まること。`tests/Unit/Ci/DropTestDbScriptTest.php` (`--orphans --apply` の削除も通常の回収と同じ guard ループ `dropTestDbDropAll()` を通り、そこへ dev DB と allowlist 外の名前が到達しない) と `tests/Unit/Ci/TestDatabaseClassificationTest.php` (分類の優先順位と確認用の値の照合) と `tests/Unit/Ci/TestDatabaseProvenanceTest.php` (出自の記録が冪等で best-effort) と `tests/Unit/Ci/TestDatabaseEnvTest.php` (名前が worktree ごとに変わり同じ worktree では変わらない) が固定する。加えて (2026-08-19 追記)、基点 DB のスキーマ更新 (家系の裁定 AG-135 への追従) は「`database/migrations` の全ファイル名が migrations 表に含まれる」という正典より強い包含判定 (`pgsqlTestSchemaUnappliedMigrations()`) で成功を判定すること、スキーマ更新の子プロセスへは ensure 専用の非既定設定キャッシュパス (`pgsqlTestConfigCachePath()`) を渡し各 artisan 起動の直前にその残存を確認すること、出自の記録 (StampProvenance) はスキーマ更新 (UpdateSchema) より先に実行することを `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` と `tests/Architecture/BaseTestDatabaseSchemaTest.php` (B-2) が固定する |
| 再判定の条件 | 正典が同じ回収経路を取り込んだとき。または実装を worktree で行う進め方をやめてテスト DB 名が worktree に依存しなくなったとき。または (2026-08-19 追記) 正典が同水準以上の到達確認 (ファイル→表の包含判定) を採用したとき、または専用非キャッシュパスと同等の TOCTOU 対策を採用したとき (この場合は該当する上積みだけを撤去し正典実装へ揃え直す) |
| 決めた日 | 2026-08-05 |
| 決めた人 | 開発者 |
| 根拠 | T114 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 基点 DB の作成 | 不在なら CREATE する | 同じ |
| 出自の記録 | 持たない | `COMMENT ON DATABASE` へ worktree の realpath を作成時・既存時の両方で記録する (非破壊 DDL。付与失敗は無視する) |
| 回収の入口 | 引数なしの 1 経路だけ (現 worktree の基点と worker DB) | それに加えて `--orphans` の列挙と `--apply` |
| 孤児の扱い | 経路が無い (hash を再現できないので落とせない) | SELECT だけで `Protected` `Live` `Foreign` `Orphan` `Unlabeled` の順に分類し dry-run で列挙する |
| 削除の決め方 | 名前の一致で自動 | 分類だけでは決めない。`--include-hash` で人が 1 つずつ名指しし、`--confirm` の値を lock 取得後に再計算して照合する |
| DROP DDL の実行点 | `drop-test-db.php` の 1 本 | 同じ (`--orphans` は入口を足すだけ) |
| 基点 DB のスキーマ更新 | 正典 HEAD は `migrate` まで担う (家系の裁定 AG-135) | 追従済み (`devnotes/20260819-1056-ensure-test-db-schema-followup/`)。到達確認は正典より強い基準を採用し、専用の非キャッシュ設定パスを使う (下記「到達確認を正典より強めた基準」参照) |

### なぜ正当な差分か (logic-driven)

本アプリの実装は必ず worktree で行う (AGENTS.md §worktree 運用ルール)。テスト DB 名は
`TestDatabaseEnv::workrootHash()` = worktree root の realpath の sha1 先頭 8 桁から作るので、
**worktree が消えると名前を再現できない**。teardown が `doc/reference/` の NFC/NFD 問題で
常時失敗していた時期に `git worktree remove --force` での迂回が常態化し、
回収経路を通らない孤児 DB が単調増加した (2026-08-05 の監査時点で 17 個 / 221.9 MB)。

テンプレートの `drop-test-db.php` は「今いる worktree の基点と worker DB を落とす」だけなので、
この事象に手が届かない。届かせるには DB 自身に出自を持たせるしかなく、
非破壊の `COMMENT ON DATABASE` を選んだ。分類は SELECT だけで行い、DROP DDL の実行点は
1 本のまま据え置いた — **危険な操作の入口を増やさずに、判断材料だけを増やす**形である。

### 揃えている不変条件 (これは保証し続ける)

> 「孤児の回収も `drop-test-db.php` の中の同じ DROP の境界へ合流する。dev DB の拒否
> (`isDevDatabase()`) と allowlist の再検査 (`isAllowedTestDatabase()`) と DROP 文の組み立て
> (`pgsqlDropDatabaseSql()`) は既存実装をそのまま共有する」

- 分類の優先順位は `Protected` `Live` `Foreign` `Orphan` `Unlabeled` の順で、
  **`Live` が `Foreign` や `Orphan` より先**である。出自のコメントを細工しても生存 DB は落とせない
- 削除可否を分類だけで決めない。`Orphan` も `Unlabeled` も `--include-hash` で
  人が 1 つずつ名指ししない限り 1 件も落ちない (一括の指定は意図的に用意していない)
- `--apply` は確認用の値を `.claude/worktrees/.setup.lock` の取得後に再計算して照合する
  (指紋ではなく lock 下のスナップショット照合)
- 合流を固定しているのは `tests/Unit/Ci/DropTestDbScriptTest.php` の次のケースである。
  `--apply` の削除は `dropTestDbDropAll()` (通常の回収と同じ guard ループ) を必ず通り、
  その結果から終了コードが決まる (`wires the drop outcome into the --apply exit code end to end`)。
  承認済みの一覧に dev DB が紛れても実行境界へは 1 件も到達しない
  (`exits non-zero from --apply if a dev database somehow reached the approved target list`)。
  実行境界へ何が渡るかを見るケース群 (`never passes the dev database to the SQL executor` ほか 2 件) は
  この 1 本の guard ループを対象にしている

併せて、家系の裁定 AG-135 への追従で「出自の記録 (StampProvenance) はスキーマ更新
(UpdateSchema) より先に実行する」を不変条件へ加える (スキーマ更新の失敗時に
「ラベルの無い現役 DB」を残さないため)。`tests/Unit/Ci/TestDatabaseProvenanceTest.php` の
`always plans the schema update last, after the provenance stamp` が固定する。
到達確認の基準そのもの・専用非キャッシュ設定パスの採用理由は次の節を参照。

### 追従の記録

正典 HEAD の `ensure-test-db.php` が担う基点 DB のスキーマ更新 (家系の裁定 AG-135) に、
`devnotes/20260819-1056-ensure-test-db-schema-followup/` の設計で追従した
(オーナー決定 2026-08-19)。追従の実装は `tests/Architecture/BaseTestDatabaseSchemaTest.php` と
`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` が固定する。
`docs/worktree-isolation-strategy.md` の「既知のギャップ」から該当項を削除した。

### 到達確認を正典より強めた基準と専用の非キャッシュ設定パス (還流候補)

正典の到達確認 (「migrations 表があり行が 1 件以上ある」) は、古い基点 DB に古い
migrations 表が残っている状態を通してしまう。実装を必ず worktree で行う進め方
(AGENTS.md §worktree 運用ルール) は worktree ごとに基点 DB を新規作成するため、
この見逃しを踏む頻度が正典の想定より高い。本アプリはこの追従にあたり、次の 2 点を
正典より強くした。

1. 到達確認は `database/migrations` の全ファイル名が migrations 表に含まれることを要求する
   (`pgsqlTestSchemaUnappliedMigrations()`)。集合の一致は求めない (vendor パッケージ由来の
   migration が表に増えても許容する)。
2. スキーマ更新の子プロセスへ渡す設定キャッシュパスは Laravel の既定パスではなく ensure
   専用の非既定パス (`pgsqlTestConfigCachePath()`) を使い、各 artisan 起動の直前にこのパスの
   残存を確認する。

`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` (到達確認の判定関数・専用パスの値・
各失敗経路) と `tests/Architecture/BaseTestDatabaseSchemaTest.php` (B-2。同じ判定関数を
共有する到達確認の実地観測) が固定する。**正典より強い基準であるため、家系の機能台帳への
還流候補として扱う**。正典が同水準以上の到達確認 (ファイル→表の包含判定) を採用したとき、
または正典が専用非キャッシュパスと同等の TOCTOU 対策を採用したときに、この上積みを
撤去して正典実装へ揃え直す (再判定の条件)。

### 保証しないもの

- 出自の記録は best-effort である。付与に失敗した DB は `Unlabeled` に落ち、
  `--include-hash` で人が名指ししない限り 1 件も回収されない
  (回収経路があることは「孤児が自動で片づく」ことを意味しない)
- 排他が閉じるのは**同一クローンの協調スクリプト間**の競合だけである。
  別クローンとの競合は `Foreign` の分類と `--protect-hash` と人の承認の 3 段で扱う
- 「`--apply` を LLM が実行しない」は運用契約であり、機械では強制していない
- **リポジトリ全体で DROP の実行点が 1 本であることを走査する検査は持たない**。
  上の不変条件が言っているのは「孤児の回収経路が既存の境界へ合流している」ことだけで、
  別のファイルに新しい DROP の実行点が増えたことは検出できない
- スキーマ更新の到達確認は「基点 DB の最終状態がスキーマ最新である」ことの確認であって、
  直前の migrate/migrate:status 子プロセスがその更新を行ったことの監査ではない
  (基点 DB が既に最新なら、子プロセスの環境変数解決が壊れていて別の DB を
  更新していても、この確認だけでは検出できない。dev DB 保護は名前の出所の一本化・
  起動直前の再検証・非継承の環境固定で成立させている)
- 専用非キャッシュパスの残存チェックは「多重起動が絶対に起きない」ことを前提にしない。
  `scripts/setup-worktree.sh` はグローバルテストロックの**外**で本スクリプトを呼ぶため
  (worktree 作成そのものを壊さないための意図的な設計)、多重起動は理論上ゼロではない。
  このチェックが担うのは「専用パスが原因を問わず既に存在していたら、通常の
  `config:cache` はこの専用パスを絶対に書かないという前提が崩れているとみなして
  fail-closed で停止する」ことだけである

### 関連

- 実装: `scripts/ci/drop-test-db.php` / `scripts/ci/ensure-test-db.php` /
  `scripts/ci/pgsql_test_conn.php` / `tests/Support/Ci/TestDatabaseEnv.php` /
  `tests/Support/Ci/TestDatabaseCandidate.php` /
  `tests/Support/Ci/TestDatabaseClassification.php` /
  `tests/Support/Ci/TestDatabaseDecision.php`
- 検査: `tests/Unit/Ci/DropTestDbScriptTest.php` /
  `tests/Unit/Ci/TestDatabaseClassificationTest.php` /
  `tests/Unit/Ci/TestDatabaseProvenanceTest.php` /
  `tests/Unit/Ci/TestDatabaseEnvTest.php` /
  `tests/Architecture/BaseTestDatabaseSchemaTest.php` /
  `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php`
- 背景: `docs/worktree-isolation-strategy.md` の「孤児テスト DB の回収」と「既知のギャップ」
- 設計: `devnotes/20260805-2017-todo-T114/` /
  `devnotes/20260818-1755-template-divergence-ledger-ci-db-and-launcher/` /
  `devnotes/20260819-1056-ensure-test-db-schema-followup/`

---
```

上記のとおり対応しました。全体判定 (APPROVED / CHANGES_REQUESTED) をお願いします。
