Round 1 の指摘への対応マトリクスです。修正差分と根拠を示します。

## 対応マトリクス (詳細は `codex-history/impl-review-decisions-round-1.md` にも保存済み)

### [Warning] D33 が新設されず D30 内へ取り込まれている件 → 反論する (意図的な変更)

承認済み詳細設計は D33 を `docs/template-divergence.md` の独立エントリとして新設することを
求めていましたが、これは本リポジトリの**機械強制の規約と衝突**するため、実装時に D30 への
統合へ変更しました。根拠は次のとおりです。

`docs/template-divergence.md` の登録メタ表の規約 (本ファイル冒頭に明記):

```
| 対象パス | ... **全登録の和集合で重複しないこと** |
```

これを実際に検査する `tests/Support/TemplateDivergence/DivergenceLedgerRules.php` の該当箇所:

```php
foreach ($pathOwners as $path => $owners) {
    if (count($owners) > 1) {
        $violations[] = sprintf(
            'TD4: 対象パス `%s` を %s が重複して挙げている。和集合で重複させない (片方を消しても赤にならなくなる)',
            $path,
            implode(' / ', $owners),
        );
    }
}
```

詳細設計が定めた D33 の対象パスは `scripts/ci/pgsql_test_conn.php` / `scripts/ci/ensure-test-db.php`
であり、これは D30 の対象パス (既存: `scripts/ci/drop-test-db.php` / `scripts/ci/ensure-test-db.php` /
`scripts/ci/pgsql_test_conn.php` / ...) と完全に重複します。D33 を文字通り新設すると、
この既存の deny-by-default gate (`TemplateDivergenceLedgerFormatTest.php`) が TD4 で確実に
赤くなります (実際に確認済み: D33 を一時的に追加してテストを走らせ、TD4 違反を再現しました)。

さらに、詳細設計の D33 案は `状態: 還流候補` としていましたが、これも状態の値域
(`DivergenceLedgerRules::STATES = ['恒久', '監視中']`) に存在しない値であり、TD5 でも
別途赤くなります。

詳細設計レビュー (Round 1-4) はテキストベースのレビューであり、この機械検査を実際に
走らせて確認する工程を経ていなかったため、この衝突は設計段階では検出されませんでした。

**対応**: D33 を独立エントリとして新設せず、その内容 (到達確認の強化基準・専用非キャッシュ
パスの採用理由・還流候補としての位置づけ・再判定条件・保証しないもの) を D30 の本文へ
`###` 見出しの節として折り込みました (`### 到達確認を正典より強めた基準と専用の非キャッシュ
設定パス (還流候補)`)。`###` 見出し・地の文は `DivergenceLedgerRules` の走査対象外である
ことが同ファイルの docblock に明記されています:

```php
/**
 * 逸脱の登録簿の形式違反を列挙する (純関数)。
 *
 * **保証しない範囲** (誇張しない):
 *  ...
 *  - 登録エントリ領域より前の節と、エントリの中の `###` 見出し・地の文は見ない
 *  ...
 */
```

したがってこの形であれば TD4/TD5 の対象にならず、設計が意図した情報 (強化した基準・
還流候補としての性質・保証しないもの・三者の判断単位の区別) は全て文書上に残ります。
D30 の登録メタ (対象パス・決めた日・決めた人・根拠・状態・見直し期限) は変更していません
(詳細設計自身も「D30 の登録そのものは元の逸脱=上積みについての登録であり、今回はその
『扱わない範囲』を追従で埋めるだけで、登録自体の再判定条件には当たらない」として
これらの変更を求めていませんでした)。

`docs/worktree-isolation-strategy.md` 側の dev DB 保護の参照は `aicue:D30` のままにしています
(D33 が存在しないため、これは誤りではなく正しい参照です)。

このスコープの判断 (機械強制の既存規約を優先し、設計の文書分割方針を実装時に安全な形へ
調整する) が受け入れられるかご判断ください。もし今回のように「文書だけの構造」が既存の
deny-by-default gate と衝突する場合、機械強制のある規約 (実際に `composer test` が毎回
検査する) を優先することが AGENTS.md の思考原則(フレームワーク/既存規約のレンジ内でやる、
後方互換の並走を残さない)に整合すると判断しています。

### [Warning] 実行時間の実測値が docblock に無い → 対応する

`scripts/ci/ensure-test-db.php` の docblock へ実測値を追記しました:

```
 * 実行時間の実測 (aicue、2026-08-19、devcontainer 内): 何もしないとき (migrate が
 * "Nothing to migrate" になる場合) 約 0.66 秒 / 空の DB から全 75 migration 適用のとき
 * 約 0.99 秒 (`performTestDatabaseSchemaUpdate()` の呼び出しのみを計測。正典の実測
 * 「何もしないとき約 0.53 秒 / 空の DB から全適用で約 0.66 秒」と同水準)。
```

計測は dev DB に一切触れず、allowlist に一致する使い捨ての test DB (`app_test_deadbeef`) を
作成 → 計測 → 削除して行いました (実装用の一時スクリプトはコミットしていません)。

### [Suggestion] 「実子プロセスも起動しない」の不正確さ → 対応する

`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` の docblock を
「artisan の実子プロセスは起動しない。ただし末尾の require 順検証だけは...PHP の別プロセスを
`proc_open()` で起動する (DB へは接続しない)」へ修正しました。

### [Suggestion] 副次的ソース検査の説明の不正確さ → 対応する

「コメント中に同じ文字列を書いても検出するが、文字列を分割して動的に組み立てる呼び出しは
検出できない」へ修正しました。

## 再送する差分

以下は Round 1 からの追加差分のみです (docblock 3 箇所の修正)。フルの実装差分は Round 1 で
既に送付済みで、ロジック本体に変更はありません。

```diff

## 施策2 (ensure-test-db.php docblock) の追加差分
```diff
diff --git a/scripts/ci/ensure-test-db.php b/scripts/ci/ensure-test-db.php
index bd71ca95..94da3333 100644
--- a/scripts/ci/ensure-test-db.php
+++ b/scripts/ci/ensure-test-db.php
@@ -5,26 +5,357 @@
 /*
  * scripts/ci/ensure-test-db.php
  *
- * pgsql テストの base DB (`<slug>_test_<worktree-hash>`) を不在時のみ冪等 CREATE する。
- * Laravel の ParallelTesting が base に `_test_<token>` を付した per-worker DB を作るが、
- * base DB 自体は事前に存在している必要があるため、run-test.sh / CI が test 前に本スクリプトを呼ぶ。
- *
- * dev-DB 保護:
- *   - base 名は TestDatabaseEnv::pgsqlBaseDatabase() (= 唯一のソース)。CREATE 前に
- *     isAllowedTestDatabase() 再検証 + isDevDatabase() deny で二重防御。
- *   - 接続失敗は CI / local いずれも明示エラー + exit 1 (偽グリーンを許さない)。
+ * pgsql テストの base DB (`<slug>_test_<worktree-hash>`) を「存在させ、
+ * スキーマを最新にする」ところまで担う (家系の裁定 AG-135 への追従)。
+ * Laravel の ParallelTesting は base に `_test_<token>` を付した worker DB を作るが、
+ * DB 系 trait を使わない Architecture のレーンは base DB をそのまま読むため、
+ * base DB のスキーマが古いままだと「新しい worktree でだけ落ちる」
+ * 「実行順で結果が変わる」失敗になる。
+ * run-test.sh / run-browser-test.sh / setup-worktree.sh が test 前に本スクリプトを呼ぶ
+ * (CI は run-test.sh / run-browser-test.sh 経由でのみ呼び、ワークフローから直接
+ * 本スクリプトを叩く経路は運用していない)。
+ *
+ * dev-DB 保護 (4 重。AGENTS.md 禁止事項 3):
+ *   1. 名前の出所 — 基点名は TestDatabaseEnv::pgsqlBaseDatabase() の 1 か所だけが決める
+ *   2. 名前の検査 — allowlist 一致 + dev 名 deny を、CREATE の直前と
+ *      スキーマ更新 (ensureTestDatabaseSchemaUpdated()) の先頭の 2 箇所で再確認する
+ *   3. 子プロセスの環境 — 継承せず許可リストで組み立て、DB_DATABASE を算出した基点名で固定し、
+ *      設定キャッシュも ensure 専用の非既定パスへ固定する (この devcontainer の shell には
+ *      dev DB 名が export されており、素直に継承するとスキーマ更新が dev DB に当たる)
+ *   4. 到達確認 — 更新後に基点 DB へ直接つなぎ、database/migrations の全ファイルが
+ *      適用済みであることまで確かめる (正典より 1 段強い基準。下記参照)
+ *
+ * 到達確認は正典より強い: 正典 (laravel-claude-template) は「migrations 表があり
+ * 行が 1 件以上ある」で止まるが、それでは古い基点 DB に古い migrations 表が残っている
+ * 状態を通してしまう。本スクリプトは pgsqlTestSchemaUnappliedMigrations() で
+ * 「migrations 表が存在し、database/migrations の全ファイル名がその表に含まれる」を
+ * 成功条件にする。tests/Architecture/BaseTestDatabaseSchemaTest.php の B-2 と
+ * 同じ関数を共有しており、スクリプトと検査で判定がずれない。
+ * **保証しないこと**: この到達確認は「基点 DB の最終状態がスキーマ最新である」ことの
+ * 確認であって、直前の migrate/migrate:status 子プロセスがその更新を行ったことの監査では
+ * ない (基点 DB が既に最新なら、子プロセスの環境変数解決が壊れていて別の DB を
+ * 更新していても、この確認だけでは検出できない)。dev DB 保護は、この到達確認では
+ * なく、上記 1〜3 (名前の出所の一本化・起動直前の再検証・非継承の環境固定) で成立させる。
+ *
+ * 出自の記録 (COMMENT ON DATABASE) は best-effort、スキーマ更新は fail-closed — この
+ * 非対称は意図である。出自は孤児 sweep の分類材料にすぎず権限差で偽赤を増やしたくないが、
+ * スキーマ更新の失敗を見逃すと基点 DB が古いまま「たまたま」テストが通ってしまう。
+ *
+ * 接続失敗は CI / local いずれも明示エラー + exit 1 (偽グリーンを許さない)。
+ *
+ * 保証しないこと: スキーマ更新に実行時間の見張りを持たない (子プロセスが DB のロック待ちで
+ * 止まれば本スクリプトも止まる。既存のテスト入口も同じで、待ちの仕掛けは持ち込まない)。
+ * 接続の待ちだけは PDO の ATTR_TIMEOUT 10 秒が効く。
+ *
+ * 実行時間の実測 (aicue、2026-08-19、devcontainer 内): 何もしないとき (migrate が
+ * "Nothing to migrate" になる場合) 約 0.66 秒 / 空の DB から全 75 migration 適用のとき
+ * 約 0.99 秒 (`performTestDatabaseSchemaUpdate()` の呼び出しのみを計測。正典の実測
+ * 「何もしないとき約 0.53 秒 / 空の DB から全適用で約 0.66 秒」と同水準)。
  */
 
 use Tests\Support\Ci\TestDatabaseEnv;
 use Webmozart\Assert\Assert;
 
-require __DIR__.'/../../vendor/autoload.php';
-require __DIR__.'/pgsql_test_conn.php';
+require_once __DIR__.'/../../vendor/autoload.php';
+// 同一プロセスで先に (Architecture/Unit テストなどから) pgsql_test_conn.php が
+// require_once 済みの状態で本ファイルが require_once されたとき、通常の require は
+// 同じファイルをもう一度パース・実行し、関数と TestDatabaseEnsureAction enum の再宣言で
+// fatal error になる。require_once へ統一する (drop-test-db.php も同じ行を持つため、
+// scripts/ci 配下の共有ファイルは全て require_once で読み込む規約にする)。
+require_once __DIR__.'/pgsql_test_conn.php';
+
+/** ensureTestDatabaseSchemaUpdated() が返す失敗理由。main 境界がメッセージ選定に使う。 */
+enum TestDatabaseSchemaUpdateFailure
+{
+    case UnsafeDatabaseName;
+    case ConfigCacheStale;
+    case MigrateFailed;
+    case MigrateStatusFailed;
+    case MigrationFileEnumerationFailed;
+    case NoMigrationFiles;
+    case VerificationConnectionFailed;
+    case MigrationsTableMissing;
+    case UnappliedMigrationsRemain;
+}
+
+/**
+ * 環境変数を継承しない artisan の起動 (laravel-claude-template@ccf465a7 と同名・同挙動)。
+ *
+ * shell を通さない配列形の proc_open を使う (引用の取り違えを構造的に無くす)。
+ * 出力を捨てずに取りたい場合は一時ファイルへ落とす — pipe を使うと、片方を読み切るまで
+ * もう片方が詰まる形になり、出力が増えたときに固まりうるためである (ここで必要なのは
+ * 失敗時に見せる文言だけなので、非同期に読む仕掛けは持たない)。
+ *
+ * @param  list<string>  $args
+ * @param  array<string, string>  $env
+ * @return array{status: int, output: string}
+ */
+function runTestDatabaseArtisan(string $projectRoot, array $args, array $env, bool $capture): array
+{
+    if (! $capture) {
+        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR];
+        $process = proc_open([PHP_BINARY, 'artisan', ...$args], $descriptors, $pipes, $projectRoot, $env);
+        if (! is_resource($process)) {
+            return ['status' => 1, 'output' => "failed to start: artisan {$args[0]}\n"];
+        }
+
+        return ['status' => proc_close($process), 'output' => ''];
+    }
+
+    // stdout と stderr は別々の一時ファイルへ落とす。同じファイルを 2 つの descriptor で
+    // 開くと書き込み位置が独立するため、片方がもう片方の内容を踏みつぶしうる。
+    $outPath = tempnam(sys_get_temp_dir(), 'ensure-test-db-out-');
+    $errPath = tempnam(sys_get_temp_dir(), 'ensure-test-db-err-');
+    if ($outPath === false || $errPath === false) {
+        if ($outPath !== false) {
+            @unlink($outPath);
+        }
+        if ($errPath !== false) {
+            @unlink($errPath);
+        }
+
+        return ['status' => 1, 'output' => "failed to create temporary files for output\n"];
+    }
+
+    try {
+        $descriptors = [
+            0 => ['file', '/dev/null', 'r'],
+            1 => ['file', $outPath, 'w'],
+            2 => ['file', $errPath, 'w'],
+        ];
+
+        $process = proc_open([PHP_BINARY, 'artisan', ...$args], $descriptors, $pipes, $projectRoot, $env);
+        if (! is_resource($process)) {
+            return ['status' => 1, 'output' => "failed to start: artisan {$args[0]}\n"];
+        }
+
+        $status = proc_close($process);
+
+        return [
+            'status' => $status,
+            'output' => (string) file_get_contents($outPath).(string) file_get_contents($errPath),
+        ];
+    } finally {
+        @unlink($outPath);
+        @unlink($errPath);
+    }
+}
+
+/**
+ * @return array{ok: false, failure: TestDatabaseSchemaUpdateFailure, message: string}
+ */
+function testDatabaseSchemaUpdateFailure(TestDatabaseSchemaUpdateFailure $failure, string $message): array
+{
+    return ['ok' => false, 'failure' => $failure, 'message' => $message];
+}
+
+/**
+ * base DB のスキーマ更新の**意思決定関数** (UpdateSchema action の本体)。
+ *
+ * `exit()` も `fwrite()` も行わない。実 artisan 起動・ファイル列挙・PDO 接続はすべて
+ * callable として受け取り、実行順・分岐・メッセージ選定だけをこの関数が担う。
+ * これにより `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` は実 DB・実子プロセスなしで
+ * 9 つの失敗経路と正常系、artisan へ渡る引数列そのものを固定できる。
+ *
+ * **「純粋な意思決定関数」ではない**: `TestDatabaseEnv::isDevDatabase()` /
+ * `isAllowedTestDatabase()` の静的判定、`pgsqlTestArtisanEnv()` が読む `.env.testing` 経由の
+ * 環境変数、`is_file()` による設定キャッシュパスの確認は、この関数が直接読む外部状態である。
+ * 「主要な実行境界 (子プロセス起動・ファイル列挙・DB 接続) だけを callable 注入で切り離した」
+ * という範囲に限定して主張する。
+ *
+ * 実行順: (1) dev DB 名の再検証 → (2) 設定キャッシュの残存確認 → (3) migrate →
+ * (4) 設定キャッシュの再確認 → (5) migrate:status → (6) migration ファイル列挙 →
+ * (7) 到達確認の PDO 検証 → (8) migrations 表の存在確認 → (9) 未適用差分の判定。
+ *
+ * @param  callable(list<string>, array<string, string>, bool): array{status: int, output: string}  $runArtisan
+ * @param  callable(string): (list<string>|false)  $listMigrationFiles  glob() 相当。false = 列挙失敗、[] = ファイル0件 (型で区別する)
+ * @param  callable(string, string): array{tableExists: bool, applied: list<string>}  $verifyAppliedMigrations  接続/クエリ失敗時は例外を投げる契約
+ * @return array{ok: bool, failure: TestDatabaseSchemaUpdateFailure|null, message: string}
+ */
+function ensureTestDatabaseSchemaUpdated(
+    string $projectRoot,
+    string $base,
+    callable $runArtisan,
+    callable $listMigrationFiles,
+    callable $verifyAppliedMigrations,
+): array {
+    // (1) dev DB 二重防御: pgsqlBaseDatabase() 内でも検査済みだが、
+    //     スキーマ更新という実行境界の直前にもう一度確認する (env 構築より前)。
+    if (TestDatabaseEnv::isDevDatabase($base) || ! TestDatabaseEnv::isAllowedTestDatabase($base)) {
+        return testDatabaseSchemaUpdateFailure(
+            TestDatabaseSchemaUpdateFailure::UnsafeDatabaseName,
+            "safety check failed for computed base database name: {$base}",
+        );
+    }
+
+    $env = pgsqlTestArtisanEnv($projectRoot, $base);
+    $configCachePath = pgsqlTestConfigCachePath($projectRoot);
+    $where = "db={$base} host={$env['DB_HOST']}:{$env['DB_PORT']}";
+
+    // (2) migrate 起動直前の設定キャッシュ確認。
+    if (is_file($configCachePath)) {
+        return testDatabaseSchemaUpdateFailure(
+            TestDatabaseSchemaUpdateFailure::ConfigCacheStale,
+            "ensure 専用の設定キャッシュが既に存在するため migrate を起動せず中止します: {$configCachePath}",
+        );
+    }
+
+    // 更新自体が「未適用のものだけ当てる」条件分岐なので、有無を見て分岐すると
+    // 同じ判定を二重に持つことになる (毎回無条件で実行する)。
+    $migrate = $runArtisan(['migrate', '--force', '--no-interaction'], $env, false);
+    if ($migrate['status'] !== 0) {
+        return testDatabaseSchemaUpdateFailure(
+            TestDatabaseSchemaUpdateFailure::MigrateFailed,
+            "ensure-test-db: スキーマ更新に失敗しました ({$where}, exit={$migrate['status']})",
+        );
+    }
+
+    // (4) migrate:status 起動直前にも同じ設定キャッシュを再確認する
+    //     (migrate の実行中に生成される異常も見逃さない)。
+    if (is_file($configCachePath)) {
+        return testDatabaseSchemaUpdateFailure(
+            TestDatabaseSchemaUpdateFailure::ConfigCacheStale,
+            "ensure 専用の設定キャッシュが migrate 実行後に出現したため migrate:status を起動せず中止します: {$configCachePath}",
+        );
+    }
+
+    // 未適用が残っていないことを artisan 自身の判定で確かめる。
+    // 値を渡したときだけその値が終了コードになる (値を渡さない形は未適用があっても 0 を返す)。
+    $pending = $runArtisan(['migrate:status', '--pending=1'], $env, true);
+    if ($pending['status'] !== 0) {
+        return testDatabaseSchemaUpdateFailure(
+            TestDatabaseSchemaUpdateFailure::MigrateStatusFailed,
+            "ensure-test-db: migration の状態確認に失敗、または未適用が残っています ({$where})\n{$pending['output']}",
+        );
+    }
+
+    // (6) 別経路の到達確認の準備: 基点 DB へ直接つないで
+    //     database/migrations の全ファイルが適用済みであることを確かめる。
+    $files = $listMigrationFiles($projectRoot);
+    if ($files === false) {
+        return testDatabaseSchemaUpdateFailure(
+            TestDatabaseSchemaUpdateFailure::MigrationFileEnumerationFailed,
+            'ensure-test-db: database/migrations の列挙に失敗しました (glob failure)',
+        );
+    }
+    if ($files === []) {
+        return testDatabaseSchemaUpdateFailure(
+            TestDatabaseSchemaUpdateFailure::NoMigrationFiles,
+            'ensure-test-db: database/migrations にファイルがありません (到達確認が空振りするため中止)',
+        );
+    }
+    $expected = pgsqlTestMigrationFileNames($files);
+
+    try {
+        $verification = $verifyAppliedMigrations($projectRoot, $base);
+    } catch (Throwable $e) {
+        return testDatabaseSchemaUpdateFailure(
+            TestDatabaseSchemaUpdateFailure::VerificationConnectionFailed,
+            "ensure-test-db: 更新後の確認接続に失敗しました ({$where}): {$e->getMessage()}",
+        );
+    }
+
+    if (! $verification['tableExists']) {
+        return testDatabaseSchemaUpdateFailure(
+            TestDatabaseSchemaUpdateFailure::MigrationsTableMissing,
+            "ensure-test-db: 更新後も migrations 表がありません ({$where})",
+        );
+    }
+
+    $unapplied = pgsqlTestSchemaUnappliedMigrations($verification['applied'], $expected);
+    if ($unapplied !== []) {
+        return testDatabaseSchemaUpdateFailure(
+            TestDatabaseSchemaUpdateFailure::UnappliedMigrationsRemain,
+            "ensure-test-db: 更新後も未適用の migration ファイルが残っています ({$where}): ".implode(', ', $unapplied),
+        );
+    }
+
+    return [
+        'ok' => true,
+        'failure' => null,
+        'message' => 'ensure-test-db: schema up to date: '.$base.' ('.count($verification['applied']).' migrations)',
+    ];
+}
+
+/**
+ * `performTestDatabaseSchemaUpdate()` が使う実物 callable の組み立て。
+ *
+ * 組み立てを本 factory へ切り出すことで、実 DB・実子プロセスに触れない範囲
+ * (`listMigrationFiles` の結線) だけは単体テストできるようにする。
+ *
+ * **保証しないこと**: `runArtisan` と `verifyAppliedMigrations` の結線自体は、実子プロセス起動・
+ * 実 PDO 接続を伴うため単体テストの対象にしない (呼び出す関数本体 `runTestDatabaseArtisan()` /
+ * `pgsqlTestDatabasePdo()` は正典からそのまま移植した部分である)。この 2 つの結線が
+ * 壊れていないことは、`tests/Architecture/BaseTestDatabaseSchemaTest.php` の B-1/B-2 が
+ * (監査ではなく最終状態の観測として) 間接的にしか裏取りしない。
+ *
+ * @return array{
+ *     runArtisan: callable(list<string>, array<string, string>, bool): array{status: int, output: string},
+ *     listMigrationFiles: callable(string): (list<string>|false),
+ *     verifyAppliedMigrations: callable(string, string): array{tableExists: bool, applied: list<string>},
+ * }
+ */
+function realTestDatabaseSchemaUpdateCallables(string $projectRoot): array
+{
+    return [
+        'runArtisan' => static fn (array $args, array $env, bool $capture): array => runTestDatabaseArtisan($projectRoot, $args, $env, $capture),
+        'listMigrationFiles' => static fn (string $root): array|false => glob($root.'/database/migrations/*.php'),
+        'verifyAppliedMigrations' => static function (string $root, string $db): array {
+            $pdo = pgsqlTestDatabasePdo($root, $db);
+            $table = $pdo->query("SELECT to_regclass('public.migrations')")->fetchColumn();
+            if ($table === null || $table === false) {
+                return ['tableExists' => false, 'applied' => []];
+            }
+            /** @var list<string> $applied */
+            $applied = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
+
+            return ['tableExists' => true, 'applied' => $applied];
+        },
+    ];
+}
+
+/**
+ * main 境界のラッパ。`realTestDatabaseSchemaUpdateCallables()` が組み立てた実物 callable を
+ * 注入して `ensureTestDatabaseSchemaUpdated()` を呼び、結果を stderr へ書いて非成功時のみ
+ * `exit(1)` する。
+ *
+ * ラッパ自身 (fwrite・exit の配線) は実 DB / 実子プロセスに触れるため単体テストの対象にしない
+ * (意思決定本体である `ensureTestDatabaseSchemaUpdated()` の側を単体テストする)。
+ */
+function performTestDatabaseSchemaUpdate(string $projectRoot, string $base): void
+{
+    $callables = realTestDatabaseSchemaUpdateCallables($projectRoot);
+    $result = ensureTestDatabaseSchemaUpdated(
+        $projectRoot,
+        $base,
+        $callables['runArtisan'],
+        $callables['listMigrationFiles'],
+        $callables['verifyAppliedMigrations'],
+    );
+
+    fwrite(STDERR, $result['message']."\n");
+    if (! $result['ok']) {
+        exit(1);
+    }
+}
+
+// ───────────────────────── entrypoint ─────────────────────────
+
+/*
+ * 直接実行されたときだけ main を走らせる (scripts/ci/drop-test-db.php と同じ既存パターン)。
+ *
+ * 施策4 の Unit テストは、注入可能な意思決定関数 (`ensureTestDatabaseSchemaUpdated()`) を
+ * 直接呼ぶために本ファイルを `require_once` する。このガードが無いと `require_once` だけで
+ * 実 DB へ接続する main 処理が走ってしまう。
+ */
+if (! isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
+    return;
+}
 
 $projectRoot = dirname(__DIR__, 2);
 $base = TestDatabaseEnv::pgsqlBaseDatabase($projectRoot);
 
-// dev-DB 二重防御 (pgsqlBaseDatabase 内でも検査済だが、CREATE 直前に再確認)。
+// dev-DB 二重防御 (pgsqlBaseDatabase 内でも検査済だが、CREATE の直前に再確認)。
 Assert::false(TestDatabaseEnv::isDevDatabase($base), "refusing to ensure dev DB: {$base}");
 Assert::true(TestDatabaseEnv::isAllowedTestDatabase($base), "computed base name not allowlisted: {$base}");
 
@@ -40,11 +371,12 @@
 $exists = $stmt->fetchColumn() !== false;
 
 // 出自 (worktree の realpath) を記録/更新する (非破壊の COMMENT ON DATABASE)。
-// 孤児 sweep (drop-test-db.php --orphans) の**分類材料**であって guard ではない。
+// 孤児 sweep (drop-test-db.php --orphans) の分類材料であって guard ではない。
 // 既存 DB でも必ず通す = 冪等 (ここを通さないと「ラベルの無い現役 DB」が生まれる)。
 $provenance = realpath($projectRoot);
 Assert::string($provenance, "projectRoot must resolve to a real path: {$projectRoot}");
 
+// 実行順は CREATE → 出自の記録 → スキーマ更新 (aicue:D30 の不変条件)。
 foreach (testDatabaseEnsurePlan($exists) as $action) {
     match ($action) {
         TestDatabaseEnsureAction::Create => $pdo->exec(pgsqlCreateDatabaseSql($base)),
@@ -52,6 +384,7 @@
             static fn (string $sql): mixed => $pdo->exec($sql),
             pgsqlCommentDatabaseSql($pdo, $base, $provenance),
         ),
+        TestDatabaseEnsureAction::UpdateSchema => performTestDatabaseSchemaUpdate($projectRoot, $base),
     };
 }
 
```

## 施策4 (TestDatabaseSchemaUpdateTest.php docblock 2箇所) の追加差分
```diff
diff --git a/tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php b/tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php
new file mode 100644
index 00000000..991bdc92
--- /dev/null
+++ b/tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php
@@ -0,0 +1,587 @@
+<?php
+
+declare(strict_types=1);
+
+// pgsql_test_conn.php を個別に require_once しない。ensure-test-db.php をトップレベル
+// スクリプトとして require_once すれば、その内部の require_once 経由で共有依存
+// (pgsql_test_conn.php) も一緒に読み込まれるため、ここで重複した読み込み宣言を置かない
+// (require_once 同士なので二重に require_once しても fatal にはならないが、
+// 既存の DropTestDbScriptTest.php も drop-test-db.php 1 本だけを require_once する
+// 同じスタイルに揃える)。
+require_once __DIR__.'/../../../scripts/ci/ensure-test-db.php';
+
+/*
+ * ensure-test-db.php のスキーマ更新まわりを固定する Unit テスト。
+ *
+ * 固定する不変条件:
+ *   1. pgsqlTestArtisanEnv() は環境を継承せず組み立てる (固定キーが常に勝つ / 許可した
+ *      3 キーだけ継承する / DB_URL は空で固定する / 親環境の DB_DATABASE・DB_URL・
+ *      APP_CONFIG_CACHE を上書きしても固定値が勝つ)
+ *   2. pgsqlTestConfigCachePath() は projectRoot からの一意な固定パスを返し、
+ *      Laravel の既定パス (bootstrap/cache/config.php) とは異なる
+ *   3. pgsqlTestMigrationFileNames() はパスから拡張子・ディレクトリを取り除く
+ *   4. pgsqlTestSchemaUnappliedMigrations() は「ファイル -> 表」の包含判定であり、
+ *      表側だけ余分にあっても (vendor パッケージ由来) 合格になる一方、
+ *      ファイル側にあって表に無いものは 1 件でも検出する
+ *   5. ensureTestDatabaseSchemaUpdated() の 9 失敗経路 (Round 1 レビューの 7 条件を
+ *      判定場所ごとに分解したもの) がそれぞれ独立して検出され、いずれも ok=false を返す
+ *   6. 正常系では $runArtisan に渡る引数列が
+ *      ['migrate', '--force', '--no-interaction'] → ['migrate:status', '--pending=1']
+ *      の 2 回・この順序・この内容だけであり、それ以外の引数列は 1 度も渡らない
+ *      (破壊的コマンドの主たる防御。ソース grep より強い — 文字列分割や動的組み立てで
+ *      回避できない)
+ *   7. 失敗経路のうち UnsafeDatabaseName は $runArtisan / $listMigrationFiles /
+ *      $verifyAppliedMigrations のいずれも 1 度も呼ばない (短絡)
+ *   8. ensure-test-db.php のソースが migrate:fresh / migrate:refresh / migrate:rollback /
+ *      migrate:reset / db:wipe を使っていない (副次的な防御。負例。コメント中に同じ文字列を
+ *      書いても検出するが、文字列を分割して動的に組み立てる呼び出しは検出できない —
+ *      主たる防御は 6)
+ *   9. pgsql_test_conn.php を複数の require_once エントリポイント
+ *      (pgsql_test_conn.php 自身 / drop-test-db.php / ensure-test-db.php の 3 本) 経由で
+ *      1 プロセス内で読み込んでも fatal error にならない (Round 2 レビューで発見された
+ *      Critical の回帰防止。本テストが起動する**別プロセス**の中で 3 本を実際に
+ *      require_once して検証する。fatal error が起きても本テストプロセス自体は
+ *      巻き込まれない)
+ *  10. realTestDatabaseSchemaUpdateCallables() の listMigrationFiles 結線が実際の
+ *      database/migrations ディレクトリへ正しくつながっている (実 DB・実子プロセスを
+ *      使わずに検証できる結線だけを対象にする。runArtisan・verifyAppliedMigrations の結線は
+ *      実 DB・実子プロセスに触れるため対象外 — 施策2「保証しないこと」参照)
+ *
+ * 本テストは実 DB を作らず、artisan の実子プロセスも起動しない (純関数の入出力・
+ * フェイク callable の呼び出し記録・ソース走査のみ)。ただし末尾の require 順検証だけは、
+ * fatal error が起きても本テストプロセス自体を巻き込まないために PHP の別プロセスを
+ * `proc_open()` で起動する (DB へは接続しない)。
+ */
+
+// ── pgsqlTestArtisanEnv(): 環境を継承しない子プロセス env ──
+
+it('does not leak arbitrary environment variables into the child process env', function (): void {
+    $original = getenv('SOME_SECRET');
+    putenv('SOME_SECRET=leaked');
+
+    try {
+        $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');
+        expect($env)->not->toHaveKey('SOME_SECRET');
+    } finally {
+        putenv($original === false ? 'SOME_SECRET' : "SOME_SECRET={$original}");
+    }
+});
+
+it('carries over only PATH / HOME / TMPDIR from the parent environment', function (): void {
+    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');
+
+    foreach (array_keys($env) as $key) {
+        expect(in_array($key, ['PATH', 'HOME', 'TMPDIR'], true) || array_key_exists($key, [
+            'APP_ENV' => true, 'APP_CONFIG_CACHE' => true, 'DB_CONNECTION' => true, 'DB_URL' => true,
+            'DB_HOST' => true, 'DB_PORT' => true, 'DB_USERNAME' => true, 'DB_PASSWORD' => true,
+            'DB_DATABASE' => true, 'CACHE_STORE' => true,
+        ]))->toBeTrue("unexpected key leaked into artisan env: {$key}");
+    }
+});
+
+it('forces DB_URL empty so that a URL-form connection string cannot override DB_DATABASE', function (): void {
+    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');
+
+    expect($env['DB_URL'])->toBe('');
+});
+
+it('pins the computed base name as DB_DATABASE and APP_ENV as testing', function (): void {
+    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');
+
+    expect($env['DB_DATABASE'])->toBe('app_test_8af22c44')
+        ->and($env['APP_ENV'])->toBe('testing')
+        ->and($env['DB_CONNECTION'])->toBe('pgsql');
+});
+
+it('overrides a parent environment that already sets DB_DATABASE / DB_URL / APP_CONFIG_CACHE to a dev DB', function (): void {
+    $keys = ['DB_DATABASE', 'DB_URL', 'APP_CONFIG_CACHE'];
+    $originals = array_combine($keys, array_map(getenv(...), $keys));
+
+    putenv('DB_DATABASE=app');
+    putenv('DB_URL=pgsql://postgres:postgres@127.0.0.1:5432/app');
+    putenv('APP_CONFIG_CACHE=/tmp/attacker-controlled-config.php');
+
+    try {
+        $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');
+
+        expect($env['DB_DATABASE'])->toBe('app_test_8af22c44')
+            ->and($env['DB_URL'])->toBe('')
+            ->and($env['APP_CONFIG_CACHE'])->toBe(pgsqlTestConfigCachePath(__DIR__));
+    } finally {
+        foreach ($originals as $key => $value) {
+            putenv($value === false ? $key : "{$key}={$value}");
+        }
+    }
+});
+
+// ── pgsqlTestConfigCachePath(): ensure 専用の非既定パス ──
+
+it('returns a fixed config cache path derived from the project root', function (): void {
+    expect(pgsqlTestConfigCachePath('/workspace'))->toBe('/workspace/bootstrap/cache/ensure-test-db-schema-update.config-cache.php');
+});
+
+it('does not point at the Laravel default config cache path', function (): void {
+    expect(pgsqlTestConfigCachePath('/workspace'))->not->toBe('/workspace/bootstrap/cache/config.php');
+});
+
+// ── pgsqlTestMigrationFileNames(): パス -> ファイル名 ──
+
+it('strips directory and extension from migration file paths', function (): void {
+    expect(pgsqlTestMigrationFileNames([
+        '/workspace/database/migrations/2024_01_01_000000_create_users_table.php',
+        '/workspace/database/migrations/2024_01_02_000000_create_teams_table.php',
+    ]))->toBe([
+        '2024_01_01_000000_create_users_table',
+        '2024_01_02_000000_create_teams_table',
+    ]);
+});
+
+it('returns an empty list for an empty input (does not throw)', function (): void {
+    expect(pgsqlTestMigrationFileNames([]))->toBe([]);
+});
+
+// ── pgsqlTestSchemaUnappliedMigrations(): ファイル -> 表の包含判定 (正典より強い基準) ──
+
+it('reports no unapplied migrations when every file is present in the applied set', function (): void {
+    expect(pgsqlTestSchemaUnappliedMigrations(
+        ['2024_01_01_000000_create_users_table', '2024_01_02_000000_create_teams_table'],
+        ['2024_01_01_000000_create_users_table', '2024_01_02_000000_create_teams_table'],
+    ))->toBe([]);
+});
+
+it('tolerates extra applied rows that do not correspond to a repository migration file (vendor packages)', function (): void {
+    expect(pgsqlTestSchemaUnappliedMigrations(
+        ['2024_01_01_000000_create_users_table', '2099_01_01_000000_vendor_package_table'],
+        ['2024_01_01_000000_create_users_table'],
+    ))->toBe([]);
+});
+
+it('detects a single missing migration file even when most files are applied', function (): void {
+    expect(pgsqlTestSchemaUnappliedMigrations(
+        ['2024_01_01_000000_create_users_table'],
+        ['2024_01_01_000000_create_users_table', '2024_01_02_000000_create_teams_table'],
+    ))->toBe(['2024_01_02_000000_create_teams_table']);
+});
+
+it('reports every file as unapplied when the applied set is empty (stale migrations table)', function (): void {
+    expect(pgsqlTestSchemaUnappliedMigrations(
+        [],
+        ['2024_01_01_000000_create_users_table'],
+    ))->toBe(['2024_01_01_000000_create_users_table']);
+});
+
+// ── ensureTestDatabaseSchemaUpdated(): テスト用フェイク callable ──
+
+function fakeMigrationFiles(): callable
+{
+    return static fn (string $root): array => ['/x/database/migrations/2024_01_01_000000_create_users_table.php'];
+}
+
+function fakeVerification(array $applied): callable
+{
+    return static fn (string $root, string $base): array => ['tableExists' => true, 'applied' => $applied];
+}
+
+// ── 9 失敗経路 ──
+
+it('rejects the dev database name before touching any injected boundary', function (): void {
+    $runnerCalls = 0;
+    $listCalls = 0;
+    $verifyCalls = 0;
+
+    $result = ensureTestDatabaseSchemaUpdated(
+        '/workspace',
+        'app', // dev DB
+        function () use (&$runnerCalls): array {
+            $runnerCalls++;
+
+            return ['status' => 0, 'output' => ''];
+        },
+        function () use (&$listCalls): array {
+            $listCalls++;
+
+            return [];
+        },
+        function () use (&$verifyCalls): array {
+            $verifyCalls++;
+
+            return ['tableExists' => true, 'applied' => []];
+        },
+    );
+
+    expect($result['ok'])->toBeFalse()
+        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::UnsafeDatabaseName)
+        ->and($runnerCalls)->toBe(0)
+        ->and($listCalls)->toBe(0)
+        ->and($verifyCalls)->toBe(0);
+});
+
+it('rejects a name that is not on the allowlist', function (): void {
+    $result = ensureTestDatabaseSchemaUpdated(
+        '/workspace',
+        'app_test_XYZ', // allowlist 不一致
+        static fn (): array => ['status' => 0, 'output' => ''],
+        fakeMigrationFiles(),
+        fakeVerification([]),
+    );
+
+    expect($result['ok'])->toBeFalse()
+        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::UnsafeDatabaseName);
+});
+
+/**
+ * 一時 projectRoot フィクスチャ (bootstrap/cache/... の 3 階層) を内側から後始末する。
+ * 「削除するのはキャッシュディレクトリだけで bootstrap とフィクスチャルートが
+ * /tmp に残る」を避けるための対応。
+ */
+function cleanupEnsureTestDbFixtureRoot(string $projectRoot): void
+{
+    $cachePath = pgsqlTestConfigCachePath($projectRoot);
+    @unlink($cachePath);
+    @rmdir(dirname($cachePath)); // .../bootstrap/cache
+    @rmdir(dirname($cachePath, 2)); // .../bootstrap
+    @rmdir($projectRoot);
+}
+
+it('refuses to start migrate when the dedicated config cache path already exists', function (): void {
+    $projectRoot = sys_get_temp_dir().'/ensure-test-db-fixture-'.bin2hex(random_bytes(4));
+    $cachePath = pgsqlTestConfigCachePath($projectRoot);
+    mkdir(dirname($cachePath), recursive: true);
+    file_put_contents($cachePath, '<?php return [];');
+
+    try {
+        $runnerCalls = 0;
+        $result = ensureTestDatabaseSchemaUpdated(
+            $projectRoot,
+            'app_test_8af22c44',
+            function () use (&$runnerCalls): array {
+                $runnerCalls++;
+
+                return ['status' => 0, 'output' => ''];
+            },
+            fakeMigrationFiles(),
+            fakeVerification([]),
+        );
+
+        expect($result['ok'])->toBeFalse()
+            ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::ConfigCacheStale)
+            ->and($runnerCalls)->toBe(0);
+    } finally {
+        cleanupEnsureTestDbFixtureRoot($projectRoot);
+    }
+});
+
+it('refuses to start migrate:status when the dedicated config cache path appears during migrate (second re-check point)', function (): void {
+    // 未検証だった分岐: ConfigCacheStale の判定箇所は 2 か所あるが、
+    // migrate 実行中に専用パスが出現するケースを別に固定する。
+    $projectRoot = sys_get_temp_dir().'/ensure-test-db-fixture-'.bin2hex(random_bytes(4));
+    $cachePath = pgsqlTestConfigCachePath($projectRoot);
+
+    try {
+        $calls = [];
+        $result = ensureTestDatabaseSchemaUpdated(
+            $projectRoot,
+            'app_test_8af22c44',
+            function (array $args) use (&$calls, $cachePath): array {
+                $calls[] = $args;
+                if ($args[0] === 'migrate') {
+                    // migrate の実行中に専用パスが (異常として) 出現したことを模す。
+                    mkdir(dirname($cachePath), recursive: true);
+                    file_put_contents($cachePath, '<?php return [];');
+                }
+
+                return ['status' => 0, 'output' => ''];
+            },
+            fakeMigrationFiles(),
+            fakeVerification([]),
+        );
+
+        expect($result['ok'])->toBeFalse()
+            ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::ConfigCacheStale)
+            ->and($calls)->toBe([['migrate', '--force', '--no-interaction']]); // migrate:status へは進んでいない
+    } finally {
+        cleanupEnsureTestDbFixtureRoot($projectRoot);
+    }
+});
+
+it('fails when migrate exits non-zero', function (): void {
+    $result = ensureTestDatabaseSchemaUpdated(
+        '/workspace',
+        'app_test_8af22c44',
+        static fn (array $args): array => $args[0] === 'migrate'
+            ? ['status' => 1, 'output' => 'boom']
+            : ['status' => 0, 'output' => ''],
+        fakeMigrationFiles(),
+        fakeVerification([]),
+    );
+
+    expect($result['ok'])->toBeFalse()
+        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::MigrateFailed);
+});
+
+it('fails when migrate:status exits non-zero (either connection failure or unapplied migrations)', function (): void {
+    $result = ensureTestDatabaseSchemaUpdated(
+        '/workspace',
+        'app_test_8af22c44',
+        static fn (array $args): array => $args[0] === 'migrate:status'
+            ? ['status' => 1, 'output' => 'pending: 2024_01_02_000000_create_teams_table']
+            : ['status' => 0, 'output' => ''],
+        fakeMigrationFiles(),
+        fakeVerification([]),
+    );
+
+    expect($result['ok'])->toBeFalse()
+        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::MigrateStatusFailed)
+        ->and($result['message'])->toContain('pending: 2024_01_02_000000_create_teams_table');
+});
+
+it('fails when migration file enumeration itself fails (glob returned false)', function (): void {
+    $result = ensureTestDatabaseSchemaUpdated(
+        '/workspace',
+        'app_test_8af22c44',
+        static fn (): array => ['status' => 0, 'output' => ''],
+        static fn (string $root): bool => false,
+        fakeVerification([]),
+    );
+
+    expect($result['ok'])->toBeFalse()
+        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::MigrationFileEnumerationFailed);
+});
+
+it('fails when there are zero migration files (distinct from glob failure)', function (): void {
+    $result = ensureTestDatabaseSchemaUpdated(
+        '/workspace',
+        'app_test_8af22c44',
+        static fn (): array => ['status' => 0, 'output' => ''],
+        static fn (string $root): array => [],
+        fakeVerification([]),
+    );
+
+    expect($result['ok'])->toBeFalse()
+        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::NoMigrationFiles);
+});
+
+it('fails when the verification connection throws', function (): void {
+    $result = ensureTestDatabaseSchemaUpdated(
+        '/workspace',
+        'app_test_8af22c44',
+        static fn (): array => ['status' => 0, 'output' => ''],
+        fakeMigrationFiles(),
+        static function (): array {
+            throw new RuntimeException('connection refused');
+        },
+    );
+
+    expect($result['ok'])->toBeFalse()
+        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::VerificationConnectionFailed)
+        ->and($result['message'])->toContain('connection refused');
+});
+
+it('fails when the migrations table is missing after update', function (): void {
+    $result = ensureTestDatabaseSchemaUpdated(
+        '/workspace',
+        'app_test_8af22c44',
+        static fn (): array => ['status' => 0, 'output' => ''],
+        fakeMigrationFiles(),
+        static fn (): array => ['tableExists' => false, 'applied' => []],
+    );
+
+    expect($result['ok'])->toBeFalse()
+        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::MigrationsTableMissing);
+});
+
+it('fails when an unapplied migration remains after update', function (): void {
+    $result = ensureTestDatabaseSchemaUpdated(
+        '/workspace',
+        'app_test_8af22c44',
+        static fn (): array => ['status' => 0, 'output' => ''],
+        fakeMigrationFiles(), // 期待 = ['2024_01_01_000000_create_users_table']
+        static fn (): array => ['tableExists' => true, 'applied' => []], // 未適用のまま
+    );
+
+    expect($result['ok'])->toBeFalse()
+        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::UnappliedMigrationsRemain)
+        ->and($result['message'])->toContain('2024_01_01_000000_create_users_table');
+});
+
+// ── 正常系 + 引数列そのものの検証 (破壊的コマンドの主たる防御) ──
+
+it('succeeds and invokes the artisan runner with exactly two allowed argument lists, in order, and nothing else', function (): void {
+    $calls = [];
+    $result = ensureTestDatabaseSchemaUpdated(
+        '/workspace',
+        'app_test_8af22c44',
+        function (array $args, array $env, bool $capture) use (&$calls): array {
+            $calls[] = $args;
+
+            return ['status' => 0, 'output' => ''];
+        },
+        fakeMigrationFiles(),
+        fakeVerification(['2024_01_01_000000_create_users_table']),
+    );
+
+    expect($result['ok'])->toBeTrue()
+        ->and($result['failure'])->toBeNull()
+        ->and($calls)->toBe([
+            ['migrate', '--force', '--no-interaction'],
+            ['migrate:status', '--pending=1'],
+        ]);
+});
+
+it('never calls the artisan runner with an argument list other than the two allowed forms, across every branch that reaches the runner', function (): void {
+    // これまで「正常系・全ての失敗系を通しで走らせる」と書きながら実際には
+    // 一部の分岐しか走らせないと乖離が生まれるため、データセット化して
+    // runner へ実際に到達する主要分岐 (成功 / migrate 失敗 / migrate:status 失敗 /
+    // 到達確認の 3 失敗いずれか) を明示的に列挙して回す。
+    //
+    // 対象外にした分岐とその理由:
+    //   - UnsafeDatabaseName / migrate 前の ConfigCacheStale: $runArtisan を 1 度も呼ばない
+    //     (専用のテストで呼び出し回数 0 を固定済み)
+    //   - 移行後 ConfigCacheStale (migrate 中出現): 呼び出しが ['migrate', ...] の 1 回だけに
+    //     短縮される特殊形であり、専用のテストで固定済み (this dataset の対象は
+    //     「2 回とも呼ばれる」形に絞る)
+    //   - MigrationFileEnumerationFailed / NoMigrationFiles: migrate + migrate:status が
+    //     成功した後で失敗するため、runner への呼び出し列は 'success' と構造的に同一
+    //     (どちらも重複してデータセットへ加える意味が無い)
+    $allowed = [
+        ['migrate', '--force', '--no-interaction'],
+        ['migrate:status', '--pending=1'],
+    ];
+
+    $scenarios = [
+        'success' => [
+            'artisan' => static fn (array $args): array => ['status' => 0, 'output' => ''],
+            'verify' => fakeVerification(['2024_01_01_000000_create_users_table']),
+        ],
+        'migrate failed' => [
+            'artisan' => static fn (array $args): array => $args[0] === 'migrate' ? ['status' => 1, 'output' => ''] : ['status' => 0, 'output' => ''],
+            'verify' => fakeVerification([]),
+        ],
+        'migrate:status failed' => [
+            'artisan' => static fn (array $args): array => $args[0] === 'migrate:status' ? ['status' => 1, 'output' => ''] : ['status' => 0, 'output' => ''],
+            'verify' => fakeVerification([]),
+        ],
+        'verification connection failed' => [
+            'artisan' => static fn (array $args): array => ['status' => 0, 'output' => ''],
+            'verify' => static function (): array {
+                throw new RuntimeException('connection refused');
+            },
+        ],
+        'migrations table missing' => [
+            'artisan' => static fn (array $args): array => ['status' => 0, 'output' => ''],
+            'verify' => static fn (): array => ['tableExists' => false, 'applied' => []],
+        ],
+        'unapplied migrations remain' => [
+            'artisan' => static fn (array $args): array => ['status' => 0, 'output' => ''],
+            'verify' => fakeVerification([]),
+        ],
+    ];
+
+    foreach ($scenarios as $label => $scenario) {
+        $seen = [];
+        $spy = function (array $args, array $env, bool $capture) use (&$seen, $scenario): array {
+            $seen[] = $args;
+
+            return ($scenario['artisan'])($args);
+        };
+
+        ensureTestDatabaseSchemaUpdated('/workspace', 'app_test_8af22c44', $spy, fakeMigrationFiles(), $scenario['verify']);
+
+        expect($seen)->not->toBe([], "scenario '{$label}' never called the runner (dataset entry would be vacuous)");
+        foreach ($seen as $args) {
+            // toContain() は可変長引数を「全て候補として含むこと」の判定に使うため、
+            // 第2引数をカスタム失敗メッセージとしては使えない (Pest の仕様)。
+            // 真偽判定 + toBeTrue() のメッセージ引数を使う。
+            expect(in_array($args, $allowed, true))
+                ->toBeTrue("unexpected artisan argument list in scenario '{$label}': ".implode(' ', $args));
+        }
+    }
+});
+
+// ── T-負例: 破壊的コマンドを使っていないこと (副次的な防御。主たる防御は上の引数列検証) ──
+
+it('never mentions migrate:fresh, migrate:refresh, migrate:rollback, migrate:reset, or db:wipe in the source (secondary defense)', function (): void {
+    $source = file_get_contents(__DIR__.'/../../../scripts/ci/ensure-test-db.php');
+    expect($source)->toBeString();
+
+    foreach (['migrate:fresh', 'migrate:refresh', 'migrate:rollback', 'migrate:reset', 'db:wipe'] as $forbidden) {
+        // toContain() は可変長引数を全て候補として扱うため、第2引数をメッセージには使えない
+        // (Pest の仕様。toBeFalse() のメッセージ引数を使う)。
+        expect(str_contains($source, $forbidden))->toBeFalse("ensure-test-db.php が破壊的コマンド {$forbidden} を含んでいる");
+    }
+    expect($source)->toContain("'migrate', '--force'");
+});
+
+// ── 負のコントロール: 判定関数自身が空振りしていないことの確認 ──
+
+it('negative control: the unapplied-migrations judgement actually flags a real gap', function (): void {
+    // 前提: 何も適用されていない状態でファイルが 1 件でもあれば、必ず非空を返す
+    // (この判定が定数 [] を返すだけの空振りになっていないことの確認)。
+    expect(pgsqlTestSchemaUnappliedMigrations([], ['anything']))->not->toBe([]);
+});
+
+// ── realTestDatabaseSchemaUpdateCallables(): 結線の単体テスト (実 DB・実子プロセスを使わない範囲) ──
+
+it('wires listMigrationFiles to the real database/migrations directory (no DB, no child process)', function (): void {
+    // performTestDatabaseSchemaUpdate() の結線自体を Architecture テストは検証できない
+    // (基点 DB が既に最新なら結線が壊れていても通ってしまう)。実 DB・実子プロセスを
+    // 使わずに検証できる listMigrationFiles の結線だけを、ここで直接固定する
+    // (runArtisan / verifyAppliedMigrations の結線は実 DB・実子プロセスに触れるため対象外。
+    // 施策2「保証しないこと」参照)。
+    $projectRoot = sys_get_temp_dir().'/ensure-test-db-wiring-'.bin2hex(random_bytes(4));
+    mkdir($projectRoot.'/database/migrations', recursive: true);
+    file_put_contents($projectRoot.'/database/migrations/2024_01_01_000000_create_users_table.php', '<?php');
+
+    try {
+        $callables = realTestDatabaseSchemaUpdateCallables($projectRoot);
+        $files = ($callables['listMigrationFiles'])($projectRoot);
+
+        expect($files)->toBe([$projectRoot.'/database/migrations/2024_01_01_000000_create_users_table.php']);
+    } finally {
+        // 後始末は内側から (作成した 3 階層を全て削除する)。
+        @unlink($projectRoot.'/database/migrations/2024_01_01_000000_create_users_table.php');
+        @rmdir($projectRoot.'/database/migrations');
+        @rmdir($projectRoot.'/database');
+        @rmdir($projectRoot);
+    }
+});
+
+// ── 回帰テスト (共有ファイルの二重ロードで fatal error にならないことの裏取り) ──
+
+it('requiring pgsql_test_conn.php via multiple require_once entrypoints in one process does not fatal', function (): void {
+    // ensure-test-db.php / drop-test-db.php はどちらも内部で pgsql_test_conn.php を
+    // require_once する。本テストは、それらを 1 つの別プロセスで実際に多重 require_once させ、
+    // fatal にならないことを直接確認する (別プロセスにするのは、fatal error が起きた場合に
+    // 本テストプロセス自体を巻き込まないため)。別プロセスが実際に require_once するのは
+    // pgsql_test_conn.php 自身 / drop-test-db.php / ensure-test-db.php の 3 本である。
+    $root = dirname(__DIR__, 3);
+    $script = <<<'PHP'
+    <?php
+    require_once $argv[1].'/scripts/ci/pgsql_test_conn.php';
+    require_once $argv[1].'/scripts/ci/drop-test-db.php';
+    require_once $argv[1].'/scripts/ci/ensure-test-db.php';
+    fwrite(STDOUT, 'OK');
+    PHP;
+
+    $scriptPath = tempnam(sys_get_temp_dir(), 'require-order-check-');
+    file_put_contents($scriptPath, $script);
+
+    try {
+        $process = proc_open(
+            [PHP_BINARY, $scriptPath, $root],
+            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
+            $pipes,
+        );
+        expect(is_resource($process))->toBeTrue();
+        $stdout = stream_get_contents($pipes[1]);
+        $stderr = stream_get_contents($pipes[2]);
+        fclose($pipes[1]);
+        fclose($pipes[2]);
+        $status = proc_close($process);
+
+        expect($status)->toBe(0, "require の多重ロードが fatal error になった: {$stderr}")
+            ->and($stdout)->toContain('OK');
+    } finally {
+        @unlink($scriptPath);
+    }
+});
```

## D30/D33 統合後の docs/template-divergence.md (D30 セクション全文)
```markdown
## D30 テスト DB の作成と回収に出自の記録と孤児の分類を上積みする

| 行 | 内容 |
|---|---|
| 対象パス | `scripts/ci/drop-test-db.php` / `scripts/ci/ensure-test-db.php` / `scripts/ci/pgsql_test_conn.php` / `tests/Support/Ci/TestDatabaseEnv.php` / `tests/Support/Ci/TestDatabaseCandidate.php` / `tests/Support/Ci/TestDatabaseClassification.php` / `tests/Support/Ci/TestDatabaseDecision.php` |
| 業務要件起因の説明 | 実装を必ず worktree で行う進め方のため、テスト DB 名を worktree の realpath の hash から作っている。worktree が検証なしで強制撤去されると hash を再現できず、引数なしの回収では二度と落とせない孤児 DB が積み上がる (2026-08-05 の監査時点で 17 個 / 221.9 MB) |
| 揃え続ける不変条件と保証機構 | 孤児の回収も `drop-test-db.php` の中の同じ DROP の境界へ合流すること、dev DB の拒否と allowlist の再検査が `TestDatabaseEnv` の既存実装を共有すること、テスト DB 名が worktree の realpath から決まること。`tests/Unit/Ci/DropTestDbScriptTest.php` (`--orphans --apply` の削除も通常の回収と同じ guard ループ `dropTestDbDropAll()` を通り、そこへ dev DB と allowlist 外の名前が到達しない) と `tests/Unit/Ci/TestDatabaseClassificationTest.php` (分類の優先順位と確認用の値の照合) と `tests/Unit/Ci/TestDatabaseProvenanceTest.php` (出自の記録が冪等で best-effort) と `tests/Unit/Ci/TestDatabaseEnvTest.php` (名前が worktree ごとに変わり同じ worktree では変わらない) が固定する |
| 再判定の条件 | 正典が同じ回収経路を取り込んだとき。または実装を worktree で行う進め方をやめてテスト DB 名が worktree に依存しなくなったとき |
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
