# 対応マトリクス: impl-review Round 2

## [Warning] `--apply` の終了コード分岐がテストで固定されていない

- 判断: **対応する**
- 根拠: 指摘のとおり。Round 1 の元指摘は「失敗しても exit 0」そのものなので、
  `dropped !== targets` を確かめるだけでは**その不一致を実際に exit code へ変換するコード**が
  未検証のまま残る。回帰は「終了コードを選ぶ場所」に置かないと意味がない。
- 対応内容:
  - 終了コード判定を純関数 `dropTestDbApplyExitCode(array $outcome, int $targets): int` へ抽出し、
    entrypoint は `exit(dropTestDbApplyExitCode($outcome, $targets))` を呼ぶだけにした
    (判定ロジックが entrypoint に埋まっていない = テストと実行が同じ関数を通る)。
  - **全成功のときだけ 0**。`failed` / `skipped` が 1 件でもあれば 1。
  - テストを 4 本追加:
    1. 全件成功 → 0 / 対象 0 件 → 0
    2. 失敗・skip・件数不足の 4 データセット → 1
    3. **結合テスト**: `dropTestDbDropAll()` の実結果を `dropTestDbApplyExitCode()` に流し、
       全成功 → 0 / 部分失敗 → 1 (ループと終了コードの結線を固定)
    4. 承認リストに dev DB が紛れ込んだ場合、末端 guard が skip し apply は成功を名乗らない

---

## 修正後の差分 (`drop-test-db.php` / `DropTestDbScriptTest.php` の cumulative diff)

```diff
diff --git a/scripts/ci/drop-test-db.php b/scripts/ci/drop-test-db.php
index cff81b6..422cad8 100644
--- a/scripts/ci/drop-test-db.php
+++ b/scripts/ci/drop-test-db.php
@@ -9,59 +9,581 @@
  * を回収する。ensure と接続 resolver を共有 (pgsql_test_conn.php) し、
  * 同一 PostgreSQL を見る (stale DB 排除)。
  *
+ * 2 つの入口を持つが、**DROP DDL を実行するのは本ファイルの 1 本だけ** (責務を分散させない):
+ *   - 引数なし    : 自 worktree の DB を回収する (teardown / CI cleanup。従来どおり)
+ *   - `--orphans` : 生存 worktree に紐づかない孤児 DB を **列挙する** (既定 dry-run。列挙は SELECT のみ)
+ *
+ * ⚠ `--apply` (実 DROP) は **LLM / エージェントが実行してはならない**。
+ *   ユーザー自身が実行するか、ユーザーが明示的に承認した場合のみ実行できる
+ *   (AGENTS.md 禁止事項 3 の趣旨。同じ文言を usage / AGENTS.md / scripts/README.md に置く)。
+ *
  * dev-DB 保護 (NON-NEGOTIABLE):
  *   - base 名は TestDatabaseEnv::pgsqlBaseDatabase() (唯一のソース)。
  *   - pg_database を `datname = base OR datname LIKE base||'\_test\_%'` で列挙し、
  *     1 件ずつ isAllowedTestDatabase() で再検証。一致したものだけ DROP する。
  *   - isDevDatabase() true は無条件 skip + 警告 (理論上到達しないが防壁)。
  *   - best-effort: 接続失敗は skip + exit 0 (teardown を止めない)。失敗 DB 名は stderr に明示。
+ *     ただし明示的に呼ばれた `--orphans` は黙って成功にせず exit 1 する。
  */
 
+use Tests\Support\Ci\TestDatabaseCandidate;
+use Tests\Support\Ci\TestDatabaseClassification;
+use Tests\Support\Ci\TestDatabaseDecision;
 use Tests\Support\Ci\TestDatabaseEnv;
+use Webmozart\Assert\Assert;
 
 require __DIR__.'/../../vendor/autoload.php';
 require __DIR__.'/pgsql_test_conn.php';
 
-$projectRoot = dirname(__DIR__, 2);
-$base = TestDatabaseEnv::pgsqlBaseDatabase($projectRoot);
+// ───────────────────── DROP 実装 (両経路が共有する唯一の DDL 実行点) ─────────────────────
 
-try {
-    $pdo = pgsqlTestMaintenancePdo($projectRoot);
-} catch (Throwable $e) {
-    fwrite(STDERR, "drop-test-db: maintenance DB connect failed; skipping (best-effort): {$e->getMessage()}\n");
-    exit(0);
+/**
+ * 渡された DB 名を 1 件ずつ再検証して DROP する。
+ *
+ * **どちらの入口 (従来経路 / --orphans --apply) もこの関数だけを通る**
+ * = DROP DDL を組み立てる場所は本ファイルの 1 箇所に閉じている。
+ * 呼び出し側で既に分類済みでも、DDL 直前に isDevDatabase / isAllowedTestDatabase を
+ * もう一度通す (防壁は末端に置く)。
+ *
+ * `$exec` を注入するのは、この **guard ループを実 DB 無しで単体テストできる**ようにするため
+ * (`--apply` は LLM が実行してはならないので、実走で検証する経路を持てない)。
+ *
+ * 失敗件数を返すのは、**呼び出し側で終了コードを分けるため**:
+ * 従来経路 (teardown) は best-effort で exit 0 のままにするが、
+ * 明示的に呼ばれた `--apply` は「一部が残ったのに成功扱い」= 偽グリーンにしない。
+ *
+ * @param  callable(string): mixed  $exec  SQL 実行境界 (実運用は `$pdo->exec(...)`)
+ * @param  list<string>  $names
+ * @return array{dropped: int, failed: int, skipped: int}
+ */
+function dropTestDbDropAll(callable $exec, array $names): array
+{
+    $result = ['dropped' => 0, 'failed' => 0, 'skipped' => 0];
+    foreach ($names as $name) {
+        if (TestDatabaseEnv::isDevDatabase($name)) {
+            fwrite(STDERR, "drop-test-db: refusing to drop dev DB (skipped): {$name}\n");
+            $result['skipped']++;
+
+            continue;
+        }
+        if (! TestDatabaseEnv::isAllowedTestDatabase($name)) {
+            fwrite(STDERR, "drop-test-db: name not allowlisted (skipped): {$name}\n");
+            $result['skipped']++;
+
+            continue;
+        }
+        try {
+            if ($exec(pgsqlDropDatabaseSql($name)) === false) {
+                throw new RuntimeException('DROP DATABASE returned false');
+            }
+            $result['dropped']++;
+        } catch (Throwable $e) {
+            fwrite(STDERR, "drop-test-db: failed to drop {$name} (manual cleanup may be needed): {$e->getMessage()}\n");
+            $result['failed']++;
+        }
+    }
+
+    return $result;
+}
+
+/**
+ * `--apply` の終了コードを決める (純関数)。
+ *
+ * **全件 DROP できたときだけ 0**。1 件でも failed / skipped があれば 1 を返す
+ * (明示的に呼ばれた回収なので「一部が残ったのに成功扱い」= 偽グリーンを作らない)。
+ * 従来経路 (teardown) は best-effort なので本関数を通さず exit 0 のままにする。
+ *
+ * @param  array{dropped: int, failed: int, skipped: int}  $outcome
+ * @param  int  $targets  DROP 対象として承認された件数
+ */
+function dropTestDbApplyExitCode(array $outcome, int $targets): int
+{
+    return $outcome['dropped'] === $targets && $outcome['failed'] === 0 && $outcome['skipped'] === 0 ? 0 : 1;
+}
+
+// ───────────────────────── usage ─────────────────────────
+
+function dropTestDbUsage(): string
+{
+    return <<<'TXT'
+    使い方:
+      php scripts/ci/drop-test-db.php                       # 従来どおり (この worktree の DB を回収)
+      php scripts/ci/drop-test-db.php --orphans             # dry-run (既定。DROP しない)
+      php scripts/ci/drop-test-db.php --orphans --include-hash=3a7d6b4e --include-hash=823cbbd2
+      php scripts/ci/drop-test-db.php --orphans --apply --confirm=<token> \
+          [--include-hash=<hash> ...] [--protect-hash=<hash> ...]
+
+    オプション (--orphans モード):
+      --apply                実際に DROP する。--confirm=<token> が必須
+      --confirm=<token>      dry-run が表示した SHA-256 (64 桁)。apply 時に lock 下で再計算して照合する
+      --include-hash=<hash>  Orphan / Unlabeled の DROP を hash 単位で許可する (複数指定可)。
+                             **一括フラグは意図的に用意していない** = 名指しの無い hash は 1 件も落ちない
+      --protect-hash=<hash>  hash を明示保護する (別クローンの DB を守る。複数指定可)
+
+    ⚠ --apply は **LLM / エージェントが実行してはならない**。
+       ユーザー自身が実行するか、ユーザーが明示的に承認した場合のみ実行できる
+       (AGENTS.md 禁止事項 3)。
+    TXT;
+}
+
+// ───────────────────── 引数解析 (fail-closed) ─────────────────────
+
+/**
+ * @param  list<string>  $args
+ * @return array{orphans: bool, apply: bool, confirm: ?string, protect: list<string>, include: list<string>}
+ */
+function dropTestDbParseArgs(array $args): array
+{
+    $parsed = ['orphans' => false, 'apply' => false, 'confirm' => null, 'protect' => [], 'include' => []];
+
+    foreach ($args as $arg) {
+        if ($arg === '--orphans') {
+            $parsed['orphans'] = true;
+
+            continue;
+        }
+        if ($arg === '--apply') {
+            $parsed['apply'] = true;
+
+            continue;
+        }
+        if (str_starts_with($arg, '--confirm=')) {
+            $parsed['confirm'] = substr($arg, strlen('--confirm='));
+
+            continue;
+        }
+        if (str_starts_with($arg, '--protect-hash=')) {
+            $parsed['protect'][] = substr($arg, strlen('--protect-hash='));
+
+            continue;
+        }
+        if (str_starts_with($arg, '--include-hash=')) {
+            $parsed['include'][] = substr($arg, strlen('--include-hash='));
+
+            continue;
+        }
+        // 未知の引数は fail-closed (typo した `--include-hasch=...` を黙って無視しない)。
+        throw new InvalidArgumentException("unknown argument: {$arg}");
+    }
+
+    // hash 形式は分類 / token 計算と同一の正規表現で先に弾く。
+    foreach ([...$parsed['protect'], ...$parsed['include']] as $hash) {
+        Assert::regex($hash, TestDatabaseCandidate::HASH_PATTERN, "hash must be 8 lowercase hex chars: {$hash}");
+    }
+    if ($parsed['apply'] && ! $parsed['orphans']) {
+        throw new InvalidArgumentException('--apply は --orphans と併用してください');
+    }
+    if ($parsed['apply'] && ($parsed['confirm'] === null || $parsed['confirm'] === '')) {
+        throw new InvalidArgumentException('--apply には --confirm=<token> が必須です (dry-run の出力から転記してください)');
+    }
+
+    return $parsed;
 }
 
-// base 完全一致 OR base_test_<token>。LIKE の _ / % を ESCAPE でリテラル化。
-$pattern = str_replace(['_', '%'], ['\_', '\%'], $base).'\_test\_%';
-$stmt = $pdo->prepare("SELECT datname FROM pg_database WHERE datname = :base OR datname LIKE :pat ESCAPE '\\'");
-$stmt->execute(['base' => $base, 'pat' => $pattern]);
+// ───────────────────── 入力の収集 (境界で正規化) ─────────────────────
+
+/**
+ * 生存 worktree の hash 集合。`git worktree list --porcelain -z` の各 path の realpath から算出する。
+ * 自分自身 ($projectRoot) は git の出力に関わらず必ず含める (自分の DB を孤児と誤判定しないため)。
+ *
+ * @return list<string>
+ */
+function dropTestDbLiveHashes(string $projectRoot): array
+{
+    $hashes = [TestDatabaseEnv::workrootHash($projectRoot)];
+
+    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
+    $process = proc_open(['git', 'worktree', 'list', '--porcelain', '-z'], $descriptors, $pipes, $projectRoot);
+    if (! is_resource($process)) {
+        throw new RuntimeException('git worktree list を起動できませんでした (生存 worktree を確認できないため中止します)');
+    }
+    $stdout = stream_get_contents($pipes[1]);
+    $stderr = stream_get_contents($pipes[2]);
+    fclose($pipes[1]);
+    fclose($pipes[2]);
+    $exitCode = proc_close($process);
 
-/** @var list<string> $names */
-$names = array_values(array_filter(
-    array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $stmt->fetchAll(PDO::FETCH_COLUMN)),
-    static fn (string $v): bool => $v !== '',
-));
+    // 生存判定を落とすと「生きている worktree の DB を孤児扱いする」経路になるため fail-closed。
+    Assert::same(0, $exitCode, 'git worktree list が失敗しました: '.(is_string($stderr) ? $stderr : ''));
+    Assert::string($stdout, 'git worktree list の出力を取得できませんでした');
 
-$dropped = 0;
-foreach ($names as $name) {
-    if (TestDatabaseEnv::isDevDatabase($name)) {
-        fwrite(STDERR, "drop-test-db: refusing to drop dev DB (skipped): {$name}\n");
+    foreach (explode("\0", $stdout) as $line) {
+        if (! str_starts_with($line, 'worktree ')) {
+            continue;
+        }
+        $path = substr($line, strlen('worktree '));
+        $real = realpath($path);
+        if ($real === false) {
+            // git が知っているのに path が無い = prune 前の残骸。生存扱いしない。
+            fwrite(STDERR, "drop-test-db: worktree path が解決できません (生存扱いしません): {$path}\n");
 
-        continue;
+            continue;
+        }
+        $hashes[] = TestDatabaseEnv::workrootHash($real);
     }
-    if (! TestDatabaseEnv::isAllowedTestDatabase($name)) {
-        fwrite(STDERR, "drop-test-db: name not allowlisted (skipped): {$name}\n");
 
-        continue;
+    return array_values(array_unique($hashes));
+}
+
+/**
+ * pg_database を **SELECT だけ**で列挙し `<name, provenance, size>` へ正規化する。
+ *
+ * @return list<array{name: string, provenance: ?string, size: int}>
+ */
+function dropTestDbInventory(PDO $pdo): array
+{
+    $sql = <<<'SQL'
+    SELECT d.datname AS name,
+           shobj_description(d.oid, 'pg_database') AS provenance,
+           pg_database_size(d.oid) AS size
+      FROM pg_database d
+     WHERE d.datistemplate = false
+     ORDER BY d.datname
+    SQL;
+
+    $statement = $pdo->query($sql);
+    Assert::isInstanceOf($statement, PDOStatement::class, 'pg_database の列挙に失敗しました');
+    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
+
+    $inventory = [];
+    foreach ($rows as $row) {
+        Assert::isArray($row);
+        $name = $row['name'] ?? null;
+        Assert::string($name, 'pg_database.datname が文字列ではありません');
+        $provenance = $row['provenance'] ?? null;
+        Assert::nullOrString($provenance, 'shobj_description が文字列でも null でもありません');
+        $size = $row['size'] ?? 0;
+        Assert::numeric($size, 'pg_database_size が数値ではありません');
+
+        $inventory[] = ['name' => $name, 'provenance' => $provenance, 'size' => (int) $size];
     }
+
+    return $inventory;
+}
+
+/** 表示幅で右詰めパディングする (printf の %-Ns はバイト数で数えるため日本語で崩れる)。 */
+function dropTestDbPad(string $text, int $width): string
+{
+    return $text.str_repeat(' ', max(1, $width - mb_strwidth($text, 'UTF-8')));
+}
+
+/** バイト数を人間可読へ (dry-run 出力用)。 */
+function dropTestDbHumanBytes(int $bytes): string
+{
+    if ($bytes < 1024 * 1024) {
+        return sprintf('%.1f kB', $bytes / 1024);
+    }
+
+    return sprintf('%.1f MB', $bytes / 1024 / 1024);
+}
+
+/**
+ * 孤児判定のスナップショット (同じ入力から何度でも再計算できる)。
+ *
+ * `--apply` はこの関数を **lock 取得後にもう一度呼んで** token を照合する
+ * (token は「指紋」ではなく **lock 下のスナップショット照合**である)。
+ *
+ * @param  list<string>  $protectedHashes
+ * @param  list<string>  $includeHashes
+ * @return array{
+ *     decisions: list<TestDatabaseDecision>,
+ *     skipped: list<array{name: string, reason: string}>,
+ *     sizes: array<string, int>,
+ *     liveHashes: list<string>,
+ *     dropTargets: list<string>,
+ *     token: string,
+ * }
+ */
+function dropTestDbOrphanSnapshot(PDO $pdo, string $projectRoot, array $protectedHashes, array $includeHashes): array
+{
+    $liveHashes = dropTestDbLiveHashes($projectRoot);
+
+    $candidates = [];
+    $skipped = [];
+    $sizes = [];
+    foreach (dropTestDbInventory($pdo) as $row) {
+        $sizes[$row['name']] = $row['size'];
+
+        if (TestDatabaseEnv::isDevDatabase($row['name'])) {
+            $skipped[] = ['name' => $row['name'], 'reason' => 'dev DB denylist (絶対に触らない)'];
+
+            continue;
+        }
+        if (! TestDatabaseEnv::isAllowedTestDatabase($row['name'])) {
+            $skipped[] = ['name' => $row['name'], 'reason' => 'allowlist 外 (テスト DB ではない)'];
+
+            continue;
+        }
+        $candidates[] = TestDatabaseCandidate::fromDatabaseName($row['name'], $row['provenance']);
+    }
+
+    $decisions = TestDatabaseEnv::classifyTestDatabases($candidates, $liveHashes, $protectedHashes, $includeHashes);
+
+    $dropTargets = array_values(array_map(
+        static fn (TestDatabaseDecision $d): string => $d->candidate->name,
+        array_filter($decisions, static fn (TestDatabaseDecision $d): bool => $d->shouldDrop),
+    ));
+
+    return [
+        'decisions' => $decisions,
+        'skipped' => $skipped,
+        'sizes' => $sizes,
+        'liveHashes' => $liveHashes,
+        'dropTargets' => $dropTargets,
+        'token' => TestDatabaseEnv::orphanConfirmToken($dropTargets, $liveHashes, $protectedHashes, $includeHashes),
+    ];
+}
+
+/**
+ * dry-run レポートを stdout へ出す。
+ * **人間がこれを読んで hash を転記しない限り 1 件も落ちない**ので、判断材料を省略しない。
+ *
+ * @param  array{decisions: list<TestDatabaseDecision>, skipped: list<array{name: string, reason: string}>, sizes: array<string, int>, liveHashes: list<string>, dropTargets: list<string>, token: string}  $snapshot
+ * @param  list<string>  $protectedHashes
+ * @param  list<string>  $includeHashes
+ */
+function dropTestDbPrintReport(array $snapshot, array $protectedHashes, array $includeHashes): void
+{
+    /** @var array<string, TestDatabaseClassification> $hashClass */
+    $hashClass = [];
+    /** @var array<string, string|null> $hashProvenance */
+    $hashProvenance = [];
+    foreach ($snapshot['decisions'] as $decision) {
+        $hash = $decision->candidate->hash;
+        $hashClass[$hash] ??= $decision->classification;
+        if (! $decision->candidate->isWorker && $decision->candidate->provenancePath !== null) {
+            $hashProvenance[$hash] = $decision->candidate->provenancePath;
+        }
+        $hashProvenance[$hash] ??= null;
+    }
+    ksort($hashClass);
+
+    echo "== hash 対応表 (人間が cross-clone を判断するための材料) ==\n";
+    if ($hashClass === []) {
+        echo "  (テスト DB はありません)\n";
+    }
+    foreach ($hashClass as $hash => $classification) {
+        echo '  '.dropTestDbPad($hash, 10).dropTestDbPad($hashProvenance[$hash] ?? '(ラベルなし)', 46).$classification->name."\n";
+    }
+
+    echo "\n== 保護 (--protect-hash) ==\n";
+    echo $protectedHashes === [] ? "  (なし)\n" : '  '.implode(' ', $protectedHashes)."\n";
+
+    $unlabeled = array_values(array_unique(array_map(
+        static fn (TestDatabaseDecision $d): string => $d->candidate->hash,
+        array_filter(
+            $snapshot['decisions'],
+            static fn (TestDatabaseDecision $d): bool => $d->classification === TestDatabaseClassification::Unlabeled,
+        ),
+    )));
+    sort($unlabeled, SORT_STRING);
+
+    echo "\n== 所有元を確認できない hash (unlabeled) ==\n";
+    if ($unlabeled === []) {
+        echo "  (なし)\n";
+    } else {
+        echo '  '.implode(' ', $unlabeled)."\n";
+        echo "  → これらは本機能より前に作られた DB か、base が既に消えた worker のみの群です。\n";
+        echo "     同一 PostgreSQL を共有する別クローン / 別チェックアウトがある場合、その生存 DB が\n";
+        echo "     ここに含まれます。apply する前に、別チェックアウトが無いことを必ず確認してください。\n";
+        echo "     落とすには --include-hash=<hash> で 1 つずつ明示してください\n";
+        echo "     (一括指定のフラグは意図的に用意していません)。\n";
+    }
+
+    echo "\n== 分類 ==\n";
+    foreach ($snapshot['skipped'] as $skip) {
+        printf("  %-26s %-6s %s\n", $skip['name'], 'skip', $skip['reason']);
+    }
+    foreach ($snapshot['decisions'] as $decision) {
+        printf(
+            "  %-26s %-6s %s (%s)\n",
+            $decision->candidate->name,
+            $decision->shouldDrop ? 'DROP' : 'keep',
+            $decision->classification->name,
+            $decision->reason,
+        );
+    }
+
+    $dropBytes = 0;
+    foreach ($snapshot['dropTargets'] as $name) {
+        $dropBytes += $snapshot['sizes'][$name] ?? 0;
+    }
+
+    echo "\n== 集計 ==\n";
+    printf(
+        "  DROP 対象: %d (%s) / 保持: %d / skip: %d\n",
+        count($snapshot['dropTargets']),
+        dropTestDbHumanBytes($dropBytes),
+        count($snapshot['decisions']) - count($snapshot['dropTargets']),
+        count($snapshot['skipped']),
+    );
+    echo '  生存 worktree hash: '.implode(' ', $snapshot['liveHashes'])."\n";
+    if ($includeHashes !== []) {
+        echo '  --include-hash: '.implode(' ', $includeHashes)."\n";
+    }
+
+    echo "\n--confirm={$snapshot['token']}\n";
+    echo "  (token は classifier_version / drop_targets / live_hashes / protected / include_hashes の\n";
+    echo "   canonical JSON から算出しています。--apply は lock 取得後に同じ入力を再計算して\n";
+    echo "   token を照合し、一致した場合だけ実行します)\n";
+    echo "\n⚠ --apply は LLM / エージェントが実行してはなりません。\n";
+    echo "   ユーザー自身が実行するか、ユーザーが明示的に承認した場合のみ実行できます。\n";
+}
+
+/**
+ * setup/teardown と同一の lock (`<main-clone>/.claude/worktrees/.setup.lock`) を取得する。
+ *
+ * **排他の適用範囲を誇張しない**: この lock が閉じるのは
+ * **同一クローンの協調スクリプト (setup / teardown / sweep) 間の TOCTOU だけ**である。
+ * `.setup.lock` は 1 クローンに閉じており別クローンとは共有されない。cross-clone の防御は
+ * lock ではなく **Foreign 分類 + --protect-hash + 人間承認**の 3 段で行う。
+ *
+ * @return resource 保持し続けるためのハンドル (全 DROP 完了まで閉じない)
+ */
+function dropTestDbAcquireSetupLock(string $projectRoot)
+{
+    // worktree からは `git rev-parse --git-common-dir` がメインクローンの .git を指す
+    // (worktree 内で --show-toplevel を使うと worktree 自身になり、別 lock を掴んでしまう)。
+    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
+    $process = proc_open(
+        ['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'],
+        $descriptors,
+        $pipes,
+        $projectRoot,
+    );
+    if (! is_resource($process)) {
+        throw new RuntimeException('git rev-parse を起動できませんでした');
+    }
+    $stdout = stream_get_contents($pipes[1]);
+    fclose($pipes[1]);
+    fclose($pipes[2]);
+    Assert::same(0, proc_close($process), 'git rev-parse --git-common-dir が失敗しました');
+    Assert::string($stdout);
+
+    $lockDir = dirname(trim($stdout)).'/.claude/worktrees';
+    if (! is_dir($lockDir) && ! mkdir($lockDir, 0o775, true) && ! is_dir($lockDir)) {
+        throw new RuntimeException("lock ディレクトリを作成できません: {$lockDir}");
+    }
+
+    $handle = fopen($lockDir.'/.setup.lock', 'c');
+    if ($handle === false) {
+        throw new RuntimeException("lock ファイルを開けません: {$lockDir}/.setup.lock");
+    }
+    if (! flock($handle, LOCK_EX | LOCK_NB)) {
+        throw new RuntimeException(
+            "別の setup/teardown が実行中です ({$lockDir}/.setup.lock)。完了を待って再実行してください。",
+        );
+    }
+
+    return $handle;
+}
+
+// ───────────────────────── entrypoint ─────────────────────────
+
+/*
+ * 直接実行されたときだけ main を走らせる。
+ * こうしておくと単体テストから require して guard ループ / 引数解析を検証できる
+ * (`--apply` は LLM が実行してはならないため、実走で検証する経路を持てない)。
+ */
+if (! isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
+    return;
+}
+
+$projectRoot = dirname(__DIR__, 2);
+
+try {
+    /** @var list<string> $argvRest */
+    $argvRest = array_values(array_slice($argv, 1));
+    $options = dropTestDbParseArgs($argvRest);
+} catch (Throwable $e) {
+    fwrite(STDERR, "drop-test-db: {$e->getMessage()}\n\n".dropTestDbUsage()."\n");
+    exit(1);
+}
+
+if (! $options['orphans']) {
+    // ── 従来経路 (自 worktree の DB 回収)。挙動は一切変えない ──
+    $base = TestDatabaseEnv::pgsqlBaseDatabase($projectRoot);
+
     try {
-        $pdo->exec(pgsqlDropDatabaseSql($name));
-        $dropped++;
+        $pdo = pgsqlTestMaintenancePdo($projectRoot);
     } catch (Throwable $e) {
-        fwrite(STDERR, "drop-test-db: failed to drop {$name} (manual cleanup may be needed): {$e->getMessage()}\n");
+        fwrite(STDERR, "drop-test-db: maintenance DB connect failed; skipping (best-effort): {$e->getMessage()}\n");
+        exit(0);
     }
+
+    // base 完全一致 OR base_test_<token>。LIKE の _ / % を ESCAPE でリテラル化。
+    $pattern = str_replace(['_', '%'], ['\_', '\%'], $base).'\_test\_%';
+    $stmt = $pdo->prepare("SELECT datname FROM pg_database WHERE datname = :base OR datname LIKE :pat ESCAPE '\\'");
+    $stmt->execute(['base' => $base, 'pat' => $pattern]);
+
+    /** @var list<string> $names */
+    $names = array_values(array_filter(
+        array_map(static fn (mixed $v): string => is_string($v) ? $v : '', $stmt->fetchAll(PDO::FETCH_COLUMN)),
+        static fn (string $v): bool => $v !== '',
+    ));
+
+    // 従来経路は best-effort (teardown を止めない) なので、失敗があっても exit 0 のまま。
+    $outcome = dropTestDbDropAll(static fn (string $sql): mixed => $pdo->exec($sql), $names);
+    fwrite(STDERR, "drop-test-db: dropped {$outcome['dropped']} test DB(s) for base {$base}\n");
+    exit(0);
+}
+
+// ── --orphans 経路 ──
+
+try {
+    $pdo = pgsqlTestMaintenancePdo($projectRoot);
+} catch (Throwable $e) {
+    // teardown の best-effort と違い、明示的に呼ばれた sweep は黙って成功にしない。
+    fwrite(STDERR, "drop-test-db: maintenance DB connect failed: {$e->getMessage()}\n");
+    exit(1);
+}
+
+$snapshot = dropTestDbOrphanSnapshot($pdo, $projectRoot, $options['protect'], $options['include']);
+
+if (! $options['apply']) {
+    dropTestDbPrintReport($snapshot, $options['protect'], $options['include']);
+    exit(0);
+}
+
+// ── apply: lock を取ってから判定入力を再取得し、token を照合する ──
+// lock は **全 DROP が完了するまで保持する** ($lockHandle をスコープに残す)。
+
+try {
+    $lockHandle = dropTestDbAcquireSetupLock($projectRoot);
+} catch (Throwable $e) {
+    fwrite(STDERR, "drop-test-db: {$e->getMessage()}\n");
+    exit(1);
 }
 
-fwrite(STDERR, "drop-test-db: dropped {$dropped} test DB(s) for base {$base}\n");
-exit(0);
+$verified = dropTestDbOrphanSnapshot($pdo, $projectRoot, $options['protect'], $options['include']);
+
+if (! hash_equals($verified['token'], (string) $options['confirm'])) {
+    fwrite(STDERR, "drop-test-db: --confirm が lock 下のスナップショットと一致しません (中止しました)\n");
+    fwrite(STDERR, "  受領: {$options['confirm']}\n");
+    fwrite(STDERR, "  現在: {$verified['token']}\n");
+    fwrite(STDERR, "  DB / worktree の状態が変わっています。dry-run をやり直して内容を確認してください。\n");
+    flock($lockHandle, LOCK_UN);
+    exit(1);
+}
+
+if ($verified['dropTargets'] === []) {
+    fwrite(STDERR, "drop-test-db: DROP 対象がありません (--include-hash で hash を名指ししてください)\n");
+    flock($lockHandle, LOCK_UN);
+    exit(0);
+}
+
+$outcome = dropTestDbDropAll(static fn (string $sql): mixed => $pdo->exec($sql), $verified['dropTargets']);
+$targets = count($verified['dropTargets']);
+fwrite(
+    STDERR,
+    "drop-test-db: dropped {$outcome['dropped']} / {$targets} orphan test DB(s)"
+    ." (failed={$outcome['failed']} skipped={$outcome['skipped']})\n",
+);
+flock($lockHandle, LOCK_UN);
+
+// 明示的に呼ばれた apply は「一部が残ったのに成功扱い」にしない (偽グリーン禁止)。
+$exitCode = dropTestDbApplyExitCode($outcome, $targets);
+if ($exitCode !== 0) {
+    fwrite(STDERR, "drop-test-db: 一部の DROP が完了していません。手動での確認・回収が必要です。\n");
+}
+exit($exitCode);
diff --git a/tests/Unit/Ci/DropTestDbScriptTest.php b/tests/Unit/Ci/DropTestDbScriptTest.php
new file mode 100644
index 0000000..366e9d2
--- /dev/null
+++ b/tests/Unit/Ci/DropTestDbScriptTest.php
@@ -0,0 +1,230 @@
+<?php
+
+declare(strict_types=1);
+
+// drop-test-db.php は「直接実行されたときだけ main を走らせる」ので、require しても
+// DB へは接続しない (関数定義だけが読み込まれる)。
+require_once __DIR__.'/../../../scripts/ci/drop-test-db.php';
+
+/*
+ * `scripts/ci/drop-test-db.php` の guard ループと引数解析の Unit テスト。
+ *
+ * **なぜ実走ではなく単体テストなのか**: `--apply` は LLM / エージェントが
+ * 実行してはならない契約なので、実 DROP を伴う経路を実走で検証できない。
+ * そこで DDL 実行境界 (`$exec`) を注入し、
+ *   1. dev DB (`app` / `bug_hunt*`) と allowlist 外の名前が **executor に一切到達しない**
+ *   2. 失敗を握りつぶさず failed として数える (呼び出し側が exit code を分けられる)
+ *   3. 引数解析が fail-closed (未知の引数 / 不正 hash / --confirm 無しの --apply を拒否)
+ * を実 DB 無しで固定する。
+ *
+ * 本テストは DB を触らない。
+ */
+
+// ── guard ループ: 危険な名前は executor に到達しない ──
+
+it('never passes the dev database to the SQL executor', function (): void {
+    $seen = [];
+    $outcome = dropTestDbDropAll(function (string $sql) use (&$seen): int {
+        $seen[] = $sql;
+
+        return 1;
+    }, ['app', 'app_test_8af22c44']);
+
+    expect($seen)->toHaveCount(1)
+        ->and($seen[0])->toBe('DROP DATABASE IF EXISTS "app_test_8af22c44" WITH (FORCE)')
+        ->and($outcome)->toBe(['dropped' => 1, 'failed' => 0, 'skipped' => 1]);
+});
+
+it('never passes bug-hunt databases to the SQL executor', function (string $name): void {
+    $seen = [];
+    $outcome = dropTestDbDropAll(function (string $sql) use (&$seen): int {
+        $seen[] = $sql;
+
+        return 1;
+    }, [$name]);
+
+    expect($seen)->toBe([])
+        ->and($outcome['skipped'])->toBe(1)
+        ->and($outcome['dropped'])->toBe(0);
+})->with(['bug_hunt', 'bug_hunt_1', 'bug_hunt_8']);
+
+it('never passes non-allowlisted names to the SQL executor', function (string $name): void {
+    $seen = [];
+    dropTestDbDropAll(function (string $sql) use (&$seen): int {
+        $seen[] = $sql;
+
+        return 1;
+    }, [$name]);
+
+    expect($seen)->toBe([]);
+})->with(['postgres', 'app_test_XYZ', 'app_test_8af22c44_backup', 'app_test_8AF22C44', '']);
+
+it('drops every allowlisted database exactly once', function (): void {
+    $seen = [];
+    $outcome = dropTestDbDropAll(function (string $sql) use (&$seen): int {
+        $seen[] = $sql;
+
+        return 1;
+    }, ['app_test_3a7d6b4e', 'app_test_3a7d6b4e_test_1', 'app_test_3a7d6b4e_test_2']);
+
+    expect($seen)->toHaveCount(3)
+        ->and($outcome)->toBe(['dropped' => 3, 'failed' => 0, 'skipped' => 0]);
+});
+
+// ── 失敗を握りつぶさない (呼び出し側が exit code を分けられる) ──
+
+it('counts a thrown executor error as a failure without aborting the loop', function (): void {
+    $outcome = dropTestDbDropAll(static function (string $sql): int {
+        if (str_contains($sql, '_test_1')) {
+            throw new RuntimeException('database is being accessed by other users');
+        }
+
+        return 1;
+    }, ['app_test_3a7d6b4e', 'app_test_3a7d6b4e_test_1', 'app_test_3a7d6b4e_test_2']);
+
+    expect($outcome)->toBe(['dropped' => 2, 'failed' => 1, 'skipped' => 0]);
+});
+
+it('counts a false return value as a failure (PDO::exec can return false instead of throwing)', function (): void {
+    $outcome = dropTestDbDropAll(static fn (string $sql): bool => false, ['app_test_3a7d6b4e']);
+
+    expect($outcome)->toBe(['dropped' => 0, 'failed' => 1, 'skipped' => 0]);
+});
+
+// ── --apply の終了コード判定 (元の指摘「失敗しても exit 0」の直接の回帰) ──
+
+it('exits zero from --apply only when every approved target was dropped', function (): void {
+    expect(dropTestDbApplyExitCode(['dropped' => 3, 'failed' => 0, 'skipped' => 0], 3))->toBe(0)
+        ->and(dropTestDbApplyExitCode(['dropped' => 0, 'failed' => 0, 'skipped' => 0], 0))->toBe(0);
+});
+
+it('exits non-zero from --apply when any target failed or was skipped', function (array $outcome, int $targets): void {
+    expect(dropTestDbApplyExitCode($outcome, $targets))->toBe(1);
+})->with([
+    'DROP が例外で失敗した' => [['dropped' => 2, 'failed' => 1, 'skipped' => 0], 3],
+    'guard で skip された' => [['dropped' => 2, 'failed' => 0, 'skipped' => 1], 3],
+    '全件失敗した' => [['dropped' => 0, 'failed' => 3, 'skipped' => 0], 3],
+    '対象が減っていた' => [['dropped' => 2, 'failed' => 0, 'skipped' => 0], 3],
+]);
+
+it('wires the drop outcome into the --apply exit code end to end', function (): void {
+    // 実 DROP を伴わずに「guard ループの結果 → 終了コード」の結合を固定する。
+    $targets = ['app_test_3a7d6b4e', 'app_test_3a7d6b4e_test_1'];
+
+    $allOk = dropTestDbDropAll(static fn (string $sql): int => 1, $targets);
+    $partial = dropTestDbDropAll(static function (string $sql): int {
+        if (str_contains($sql, '_test_1')) {
+            throw new RuntimeException('database is being accessed by other users');
+        }
+
+        return 1;
+    }, $targets);
+
+    expect(dropTestDbApplyExitCode($allOk, count($targets)))->toBe(0)
+        ->and(dropTestDbApplyExitCode($partial, count($targets)))->toBe(1);
+});
+
+it('exits non-zero from --apply if a dev database somehow reached the approved target list', function (): void {
+    // 分類側が壊れても、末端 guard が skip し、apply は成功を名乗らない (二重防御)。
+    $seen = [];
+    $outcome = dropTestDbDropAll(function (string $sql) use (&$seen): int {
+        $seen[] = $sql;
+
+        return 1;
+    }, ['app', 'app_test_3a7d6b4e']);
+
+    expect($seen)->toHaveCount(1)
+        ->and(dropTestDbApplyExitCode($outcome, 2))->toBe(1);
+});
+
+// ── 引数解析 (fail-closed) ──
+
+it('defaults to the legacy mode with no arguments', function (): void {
+    expect(dropTestDbParseArgs([]))->toBe([
+        'orphans' => false, 'apply' => false, 'confirm' => null, 'protect' => [], 'include' => [],
+    ]);
+});
+
+it('defaults --orphans to dry-run (apply stays false)', function (): void {
+    $parsed = dropTestDbParseArgs(['--orphans']);
+
+    expect($parsed['orphans'])->toBeTrue()
+        ->and($parsed['apply'])->toBeFalse();
+});
+
+it('collects repeatable hash options', function (): void {
+    $parsed = dropTestDbParseArgs([
+        '--orphans',
+        '--include-hash=3a7d6b4e',
+        '--include-hash=823cbbd2',
+        '--protect-hash=91c7197b',
+    ]);
+
+    expect($parsed['include'])->toBe(['3a7d6b4e', '823cbbd2'])
+        ->and($parsed['protect'])->toBe(['91c7197b']);
+});
+
+it('rejects unknown arguments instead of silently ignoring them', function (): void {
+    // `--include-hasch=...` のような typo が「対象 0 件」として黙って通ると危険。
+    dropTestDbParseArgs(['--orphans', '--include-hasch=3a7d6b4e']);
+})->throws(InvalidArgumentException::class);
+
+it('rejects a bulk flag that was intentionally never implemented', function (): void {
+    dropTestDbParseArgs(['--orphans', '--include-unlabeled']);
+})->throws(InvalidArgumentException::class);
+
+it('rejects malformed hash options', function (string $arg): void {
+    dropTestDbParseArgs(['--orphans', $arg]);
+})->with([
+    '--include-hash=ZZZZZZZZ',
+    '--include-hash=3a7d6b4',
+    '--include-hash=3A7D6B4E',
+    '--protect-hash=',
+    '--protect-hash=not-a-hash',
+])->throws(InvalidArgumentException::class);
+
+it('requires --confirm for --apply', function (): void {
+    dropTestDbParseArgs(['--orphans', '--apply']);
+})->throws(InvalidArgumentException::class);
+
+it('rejects an empty --confirm for --apply', function (): void {
+    dropTestDbParseArgs(['--orphans', '--apply', '--confirm=']);
+})->throws(InvalidArgumentException::class);
+
+it('rejects --apply without --orphans', function (): void {
+    dropTestDbParseArgs(['--apply', '--confirm=deadbeef']);
+})->throws(InvalidArgumentException::class);
+
+it('accepts --apply with --orphans and a confirm token', function (): void {
+    $parsed = dropTestDbParseArgs(['--orphans', '--apply', '--confirm=abc123']);
+
+    expect($parsed['apply'])->toBeTrue()
+        ->and($parsed['confirm'])->toBe('abc123');
+});
+
+// ── usage に運用契約が書かれていること (3 箇所のうちの 1 つ) ──
+
+it('states the LLM-must-not-apply contract in the usage text', function (): void {
+    expect(dropTestDbUsage())
+        ->toContain('--apply')
+        ->toContain('LLM')
+        ->toContain('ユーザー自身が実行するか、ユーザーが明示的に承認した場合のみ')
+        ->toContain('--include-hash');
+});
+
+// ── 表示ヘルパ ──
+
+it('pads to display width so multibyte columns stay aligned', function (): void {
+    expect(mb_strwidth(dropTestDbPad('(ラベルなし)', 20), 'UTF-8'))->toBe(20)
+        ->and(mb_strwidth(dropTestDbPad('/workspace', 20), 'UTF-8'))->toBe(20)
+        // 幅を超える入力でも最低 1 スペースは入れて列が潰れないようにする
+        ->and(dropTestDbPad('0123456789', 5))->toBe('0123456789 ');
+});
+
+it('formats byte counts for humans', function (): void {
+    expect(dropTestDbHumanBytes(0))->toBe('0.0 kB')
+        ->and(dropTestDbHumanBytes(512 * 1024))->toBe('512.0 kB')
+        ->and(dropTestDbHumanBytes(14 * 1024 * 1024))->toBe('14.0 MB')
+        // 1 MiB がちょうど境界 (kB 側に落ちない)
+        ->and(dropTestDbHumanBytes(1024 * 1024))->toBe('1.0 MB');
+});
```

## 再検証

```
vendor/bin/pest tests/Unit/Ci/ : 105 passed / 0 failed
composer phpstan               : level 10 No errors
vendor/bin/pint                : passed
php scripts/ci/drop-test-db.php --orphans : exit 0 (dry-run。DROP 対象 0)
```

**孤児 DB の実 DROP (`--apply`) は依然として実行していない。**

残る [Critical] / [Warning] があれば指摘し、無ければ全体判定を 1 行で示してほしい。
