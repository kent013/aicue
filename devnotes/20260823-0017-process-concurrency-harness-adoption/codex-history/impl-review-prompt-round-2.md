# 実装レビュー Round 2 (aicue / T248)

Round 1 の指摘 (Warning 5 / Suggestion 2) を**すべて対応**した。
判断と根拠は下の対応マトリクスに、実装は差分に示す。

## 対応マトリクス

Codex 全体判定: **CHANGES_REQUESTED** ([Critical] 0 / [Warning] 5 / [Suggestion] 2)

## [Warning] 子の stderr が無加工で例外へ入り、秘密を CI ログへ残せる

- 判断: **対応する**
- 根拠: 妥当な指摘である。`getTraceAsString()` は文字列引数を 15 文字まで含めるため、
  plain API キーの先頭が CI ログへ残りうる。「秘密は成否にかかわらず消す」という保証が
  ファイル経路だけに閉じていて、診断経路が未防御だったのは設計の穴である。
- 対応内容: **2 段で塞ぐ**。
  1. 子 (`idempotency-claim-probe.php`) の総括 catch は **例外クラスと file:line だけ**を書き、
     `getMessage()` / `getTraceAsString()` を出さない (段 6/7 の専用 exit は元から
     キー名・DB 名しか出さないので据え置く)。
  2. 親 (`ConcurrencyProbeRunner`) が子の stderr を例外へ載せる前に
     **既知の秘密 5 種**(plain API キー / raw body / `APP_KEY` / `CIPHERSWEET_KEY` /
     `DB_PASSWORD`) を `[redacted:…]` へ置換する。子が PHP の fatal を吐いた場合も通る経路である。
  3. 群 4 へ **sentinel 検査**を追加し、投げられた例外の全文 (previous 連鎖を含む) に
     秘密が現れないことを固定する。

## [Warning] transaction の rollback 契約がテストされていない

- 判断: **対応する**
- 根拠: 指摘のとおり、既存テストは行を作る前に例外を投げていたので
  `DB::transaction()` を外しても緑のままだった。rollback は残留防止の唯一の仕組みなので、
  「効いていること」を固定しないのは検出力の主張として成立しない。
- 対応内容: callback の中で**実際に検体を作り**、主キーを外側へ控えてから例外を投げる形にし、
  別名接続から **8 表すべてで残留 0** を検査する。既定接続名の復帰と別名接続の
  disconnect + purge も同じテストで見る。

## [Warning] 設計逸脱の核心である cache driver の負例がない

- 判断: **対応する**
- 根拠: 正例が両項目とも `array` なので、`assertAppLocksDisabled()` から driver 側の検査が
  消えても緑のままだった。今回の逸脱 (「store 名と裏打ち driver の両方を見る」) の
  検出力そのものが裏取りされていない。
- 対応内容: 群 3 へ独立した負例 2 本 (store 名だけ違う / driver だけ違う) と正例 1 本を追加する。
- 備考: Codex は `cache_store_driver` への置き換え自体を **承認** した
  (「L3 目録と D 登録を広げる必要はない」)。逸脱の判断は据え置く。

## [Warning] 回収失敗が複合した場合、一部の危険が診断から消える

- 判断: **対応する**
- 根拠: `reap()` が秘密削除失敗で即 throw していたため、同時に停止未確認の子が残っていても
  child ID と workspace が報告されなかった。`workspaceModeUnsafe()` も元の原因を上書きしていた。
  「危険が 2 つあるのに 1 つしか見えない」形は診断として不十分である。
- 対応内容: 回収の失敗を**問題の一覧**として集め、**1 つの例外に全部載せる**
  (`reapFailed()`)。元の失敗は previous に畳んで捨てない。
  例外の型を 3 つに分けていたのをやめ、既存 3 factory は撤去する (後方互換の並走を残さない)。
  群 4-40 は env / input-a / input-b の**全対象**が例外に載ることを検査し、
  「秘密削除失敗 + 停止未確認 + mode 不正」の**複合ケース**を新設する。

## [Warning] 厳格パーサが encoder の生成不能な escape を受理する

- 判断: **対応する**
- 根拠: 「唯一の書式だけを受理し、phpdotenv と同じ規則で復号する」と docblock で宣言しながら、
  `/\\(.)/` は `\q` のような未知 escape も受理してバックスラッシュを落としていた。
  宣言と実装の食い違いである。
- 対応内容: 受理する escape を `\\` / `\"` / `\$` の **3 種だけ**に絞り、
  引用符の内側の**素の `$`** も拒否する (encoder は必ず escape するため、素の `$` は
  canonical でない = phpdotenv の変数展開と食い違う経路)。
  群 2 へ負例 3 形 (未知 escape / 素の `$` / キー重複 / 書式違反) を追加する。

## [Suggestion] `uri` は必須観測なのに一度も照合されない

- 判断: **対応する**
- 根拠: fail-closed schema に「集めるが誰も参照しない項目」を残すのは
  AGENTS.md の走査規約 (d)「集めた走査結果を判定に使わない形を作らない」と同じ悪さである。
- 対応内容: runner の受理条件へ「両子の `uri` が親の `uri` と一致する」を足す。

## [Suggestion] workspace 削除が symlink 先のディレクトリを再帰する

- 判断: **対応する**
- 根拠: 削除処理としては `is_link()` を先に見るのが素直で、コストも無い。
- 対応内容: `removeDirectory()` は symlink を**辿らずに unlink** する。

## 検証結果 (Round 2 時点)

- `composer phpstan` : OK (No errors)
- `vendor/bin/pint --test` : passed
- `composer test -- "--filter=Concurrency"` : **58 tests / 57 passed / 1 skipped**
  (skip は本 PR 以前から在る D7 のプレースホルダ)。Round 1 時点の 54 件から
  **4 件増**えている (群2-13b / 群3-23b / 群4-43 / 群4-44)
- full `composer test` : Round 1 の修正前の版で 6470/6473 passed
  (唯一の赤は本 Round で直した「rollback 検査がアロー関数の値取り込みで
  主キーを外へ出せていなかった」もの。修正後の targeted 実行で緑を確認済み)。
  **本 Round の最終版での full 実行はこの後に回す**

## 追加で報告する事実 (Round 1 の指摘に関係する)

- 群 4-43 (sentinel 検査) を書いたことで、**redaction は「完全一致で現れた秘密」しか
  伏せられない**ことが明確になった。`getTraceAsString()` が文字列引数を 15 文字で切る以上、
  親の伏せ字だけでは閉じない。そこで**子の側で message と trace を出さない**ようにし、
  親の伏せ字は「PHP の fatal など子が制御できない出力」への二重防御という位置づけにした。
  この非対称は `redactSecrets()` の docblock に明記してある。
- 段 6 (`env -i` の退行) の stderr だけは `getMessage()` を出し続けている。
  このメッセージは**環境変数のキー名しか含まない** (値は 1 つも載らない) ためで、
  該当箇所にコメントを書いた。

## 変更した 6 ファイルの差分 (git diff HEAD)

```diff
diff --git a/tests/Feature/Concurrency/OutOfTransactionFixturesTest.php b/tests/Feature/Concurrency/OutOfTransactionFixturesTest.php
new file mode 100644
index 00000000..a7f1a9b8
--- /dev/null
+++ b/tests/Feature/Concurrency/OutOfTransactionFixturesTest.php
@@ -0,0 +1,169 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\Facades\DB;
+use Tests\Support\Concurrency\ConcurrencyFixtureKeys;
+use Tests\Support\Concurrency\OutOfTransactionFixtures;
+
+/*
+ * transaction 外の検体置き場の契約 (正典 v1 の要素 (2))。
+ *
+ * `RefreshDatabase` は検体を**未コミットの transaction の中**に置くため、子プロセスからは
+ * 見えない。本テストは「別名接続へ出して commit し、末尾で確実に片付ける」という契約が
+ * 実際に効いていることを固定する。
+ *
+ * ★**片付けの完全性は cleanup() 自身の契約**である (8 表の残留ゼロ検査)。
+ *   本テストはその検査器そのものが機能していること (削除前なら非ゼロを数えること) も見る —
+ *   「残留があるのに 0 と数える」退行は、残留ゼロ検査だけでは緑のまま通ってしまう。
+ *
+ * **保証しないもの**: 見ているのは cleanup が受け持つ 8 表だけである。検体の生成経路が
+ * 別の表へ行を足すようになったら、この検査は沈黙する (一覧を同じ変更で増やすこと)。
+ */
+
+/** 検体 (組織 + owner + API キー) を transaction の外に作る */
+function createOutOfTransactionFixture(): ConcurrencyFixtureKeys
+{
+    return OutOfTransactionFixtures::create(function (): ConcurrencyFixtureKeys {
+        [$organization, $owner] = createOrganizationWithOwner();
+        [$apiKey] = issueApiKey($organization, $owner);
+
+        return new ConcurrencyFixtureKeys(
+            organizationId: $organization->id,
+            laratrustTeamId: $organization->laratrust_team_id,
+            userId: $owner->id,
+            apiKeyId: $apiKey->id,
+        );
+    });
+}
+
+test('create() で作った行は別名接続から見える (テストの transaction の外に出ている)', function (): void {
+    $keys = createOutOfTransactionFixture();
+
+    try {
+        $rows = OutOfTransactionFixtures::connection()
+            ->table('organizations')
+            ->where('id', $keys->organizationId)
+            ->count();
+
+        expect($rows)->toBe(1);
+
+        // 既定接続 (テストの transaction の中) から見ても在る = 同じ DB を指している
+        expect(DB::table('api_keys')->where('id', $keys->apiKeyId)->count())->toBe(1);
+    } finally {
+        OutOfTransactionFixtures::cleanup($keys);
+    }
+});
+
+test('residueCounts() は削除前の検体を数え上げる (検査器そのものが機能している)', function (): void {
+    $keys = createOutOfTransactionFixture();
+
+    try {
+        $counts = OutOfTransactionFixtures::residueCounts($keys);
+
+        // 8 表すべてが対象で、検体を作った直後はどれも 1 件以上ある
+        expect(array_keys($counts))->toBe([
+            'idempotency_keys', 'api_keys', 'organization_user', 'custom_teams',
+            'organizations', 'role_user', 'teams', 'users',
+        ]);
+
+        // idempotency_keys だけは検体の時点で 0 件 (要求を出していないため)
+        expect($counts['idempotency_keys'])->toBe(0);
+
+        foreach (['api_keys', 'organization_user', 'custom_teams', 'organizations', 'role_user', 'teams', 'users'] as $table) {
+            expect($counts[$table])->toBeGreaterThan(0);
+        }
+    } finally {
+        OutOfTransactionFixtures::cleanup($keys);
+    }
+});
+
+test('cleanup() の後は 8 表すべてで残留が 0 (organizations は物理削除される)', function (): void {
+    $keys = createOutOfTransactionFixture();
+
+    OutOfTransactionFixtures::cleanup($keys);
+
+    // ★softDeletes を持つ organizations も query builder 経由で**物理削除**されている
+    //   (Eloquent の delete() だと deleted_at が入るだけで行は残る)。
+    expect(OutOfTransactionFixtures::residueCounts($keys))->toBe([
+        'idempotency_keys' => 0,
+        'api_keys' => 0,
+        'organization_user' => 0,
+        'custom_teams' => 0,
+        'organizations' => 0,
+        'role_user' => 0,
+        'teams' => 0,
+        'users' => 0,
+    ]);
+});
+
+test('cleanup() は冪等 (2 回呼んでも安全)', function (): void {
+    $keys = createOutOfTransactionFixture();
+
+    OutOfTransactionFixtures::cleanup($keys);
+    OutOfTransactionFixtures::cleanup($keys);
+
+    expect(OutOfTransactionFixtures::residueCounts($keys)['users'])->toBe(0);
+});
+
+test('別名接続の座標は既定接続と一致する (別の DB を向いていない)', function (): void {
+    $expected = config('database.connections.pgsql');
+
+    $keys = createOutOfTransactionFixture();
+
+    try {
+        expect(config('database.connections.'.OutOfTransactionFixtures::CONNECTION_NAME))->toBe($expected);
+        expect(OutOfTransactionFixtures::connection()->getDatabaseName())
+            ->toBe(DB::connection('pgsql')->getDatabaseName());
+    } finally {
+        OutOfTransactionFixtures::cleanup($keys);
+    }
+});
+
+test('create() の中で例外が出たら作りかけの行は rollback され、接続の後始末も済んでいる', function (): void {
+    $original = config('database.default');
+
+    // ★**行を作ってから**例外を投げる。行を作る前に投げる形だと `DB::transaction()` を
+    //   外しても緑のままで、rollback が効いていることを何も示さない
+    //   (rollback は create() 失敗時の残留を防ぐ唯一の仕組みである)。
+    $keys = null;
+
+    // ★アロー関数で包まない。アロー関数は外側の変数を**値で**取り込むため、
+    //   その内側の `use (&$keys)` は複製に束縛され、控えた主キーが外へ出てこない。
+    expect(function () use (&$keys): void {
+        OutOfTransactionFixtures::create(function () use (&$keys): never {
+            [$organization, $owner] = createOrganizationWithOwner();
+            [$apiKey] = issueApiKey($organization, $owner);
+
+            $keys = new ConcurrencyFixtureKeys(
+                organizationId: $organization->id,
+                laratrustTeamId: $organization->laratrust_team_id,
+                userId: $owner->id,
+                apiKeyId: $apiKey->id,
+            );
+
+            throw new RuntimeException('検体の生成に失敗した');
+        });
+    })->toThrow(RuntimeException::class, '検体の生成に失敗した');
+
+    expect($keys)->toBeInstanceOf(ConcurrencyFixtureKeys::class);
+
+    // 既定接続名は元へ戻り、別名接続は disconnect + purge されている
+    expect(config('database.default'))->toBe($original);
+    expect(array_key_exists(
+        OutOfTransactionFixtures::CONNECTION_NAME,
+        DB::getConnections(),
+    ))->toBeFalse();
+
+    // 作りかけの行は 8 表すべてで残っていない (cleanup では拾えない = rollback が唯一の砦)
+    expect(OutOfTransactionFixtures::residueCounts($keys))->toBe([
+        'idempotency_keys' => 0,
+        'api_keys' => 0,
+        'organization_user' => 0,
+        'custom_teams' => 0,
+        'organizations' => 0,
+        'role_user' => 0,
+        'teams' => 0,
+        'users' => 0,
+    ]);
+});
diff --git a/tests/Support/Concurrency/ConcurrencyProbeRunner.php b/tests/Support/Concurrency/ConcurrencyProbeRunner.php
new file mode 100644
index 00000000..c4e03595
--- /dev/null
+++ b/tests/Support/Concurrency/ConcurrencyProbeRunner.php
@@ -0,0 +1,690 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use Closure;
+use RuntimeException;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 実プロセス 2 本を barrier で同期させて走らせ、一次観測を回収する。
+ *
+ * 段取り:
+ *  1. 子ごとの ready を全員ぶん待ち、**中身の nonce を照合**する
+ *  2. **ここで初めて** go token をランダム生成し、go を 1 つ置く
+ *     (事前に渡さないので、go を読まずに正しい token を書くことは構造的にできない)
+ *  3. entered を待つ (割り当て済みの完成名だけを調べる。prefix の glob は使わない)
+ *  4. **反対側の out を待ち、中身を完全に検査する**
+ *  5. 検査をすべて通ったら release を置く
+ *  6. 両方の終了を待ち、exit code 0 と stdout/out の一致を確かめて観測を返す
+ *
+ * ★4 の検査を通す前に release しない。「出てきたから release して、あとから赤くする」形は
+ *   結果的に赤にはなるがプロトコルの証拠が弱い。
+ * ★3〜5 の待機中は**常に**「2 つ目の entered / 未知の完成合図 / 子の異常終了」を監視する
+ *   (単一ファイルだけを待つブロッキングにすると、二重実行の即時検出という性質が失われる)。
+ * ★締切は**単一の絶対 deadline** である。段ごとに更新すると総時間が締切を大幅に超える。
+ *
+ * **保証の言い方**: 回収について主張するのは
+ * 「bounded な回収操作 (TERM / KILL / 上限つき poll) を必ず要求し、停止を確認できなければ
+ * 失敗させる。秘密は成否にかかわらず必ず消す」までである。
+ * 実 OS プロセスが実際に消えたことは保証範囲外とする。
+ */
+final class ConcurrencyProbeRunner
+{
+    /** **作業の締切** (子の起動 + 合図 + 要求 + 通常の終了待ちを打ち切る) */
+    public const float DEFAULT_TIMEOUT_SECONDS = 60.0;
+
+    /** 子の識別子 (固定 2 本。N 本への一般化はしない) */
+    public const array CHILD_IDS = ['a', 'b'];
+
+    /**
+     * **回収専用の予算** (作業の締切とは独立に確保する)。
+     *
+     * ★作業の締切を回収にも使うと、**締切超過の瞬間に残り時間が 0** になり、
+     *   まさに回収が必要な場面で kill 後の待機ができず子が残る。
+     * ★この予算は**全子で共有する** (子ごとに 2 秒ではない)。
+     *   回収はフェーズ単位で行うので、子数が増えても総時間は変わらない。
+     */
+    public const float REAP_BUDGET_SECONDS = 2.0;
+
+    /** SIGTERM から SIGKILL までの猶予 (REAP_BUDGET_SECONDS の内側) */
+    public const float REAP_GRACE_SECONDS = 1.0;
+
+    /** 回収 poll の間隔 (マイクロ秒) */
+    private const int REAP_POLL_INTERVAL_MICROSECONDS = 5_000;
+
+    /**
+     * @param  array<string, mixed>  $requestBody
+     *
+     * @throws BarrierTimeoutException|ConcurrencyProtocolException|RuntimeException
+     */
+    public static function run(
+        string $idempotencyKey,
+        string $plainApiKey,
+        array $requestBody,
+        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
+        ?ProbeProcessFactory $factory = null,
+    ): ConcurrentProbeResult {
+        Assert::stringNotEmpty($idempotencyKey);
+        Assert::stringNotEmpty($plainApiKey);
+        Assert::greaterThan($timeoutSeconds, 0.0);
+
+        $suffix = bin2hex(random_bytes(6));
+        $uri = 'api/v1/__concurrency_probe_'.$suffix.'__';
+        $routeName = 'api.v1.__concurrency_probe_'.$suffix.'__';
+        $rawBody = json_encode($requestBody, JSON_THROW_ON_ERROR);
+
+        // ★middleware と**同一規則**で親が期待 hash を持つ (`Request::path()` は先頭の `/` を含まない)。
+        $expectedRequestHash = hash('sha256', 'POST|'.$uri.'|'.$rawBody);
+
+        // 遮断の段 1〜3 (親側)。ここで落ちたら子は 1 本も起きない。
+        $envValues = ProbeEnvironment::envFileValues();
+
+        $workspace = self::createWorkspace();
+
+        // ★診断に載せてはいけない値の一覧。子の stderr を例外へ埋める前に必ず伏せ字にする
+        //   (一時ファイルを消しても、CI のログには永続的に残るため)。
+        $secrets = array_values(array_filter([
+            $plainApiKey,
+            $rawBody,
+            $envValues['APP_KEY'] ?? '',
+            $envValues['CIPHERSWEET_KEY'] ?? '',
+            $envValues['DB_PASSWORD'] ?? '',
+        ], static fn (string $secret): bool => $secret !== ''));
+
+        /** @var list<string> $secretPaths */
+        $secretPaths = [];
+        /** @var array<string, ProbeProcess> $processes */
+        $processes = [];
+        /** @var array<string, string> $nonces */
+        $nonces = [];
+
+        try {
+            $envFilePath = $workspace.'/'.ProbeEnvironment::ENV_FILE_NAME;
+            $lines = '';
+            foreach ($envValues as $key => $value) {
+                $lines .= ProbeEnvironment::encodeLine($key, $value);
+            }
+            ProbeEnvironment::writeProtectedFile($envFilePath, $lines);
+            $secretPaths[] = $envFilePath;
+
+            $configCachePath = $workspace.'/config-cache-absent.php';
+            $factory ??= new SymfonyProbeProcessFactory(base_path());
+
+            foreach (self::CHILD_IDS as $childId) {
+                $nonces[$childId] = bin2hex(random_bytes(16));
+
+                $spec = new ProbeLaunchSpec(
+                    workspaceDirectory: $workspace,
+                    childId: $childId,
+                    nonce: $nonces[$childId],
+                    scriptPath: ProbeEnvironment::probeScriptPath(),
+                    environmentDirectory: $workspace,
+                    environmentFileName: ProbeEnvironment::ENV_FILE_NAME,
+                    inputFileName: 'input-'.$childId.'.json',
+                    configCachePath: $configCachePath,
+                );
+
+                // ★秘密 (plain API key / raw body) は argv に載せず 0600 の入力ファイルへ置く。
+                //   go token は**ここに無い** (親は ready を全部検証した後に初めて作る)。
+                ProbeEnvironment::writeProtectedFile($spec->inputFilePath(), json_encode([
+                    'child_id' => $childId,
+                    'nonce' => $nonces[$childId],
+                    'route_name' => $routeName,
+                    'uri' => $uri,
+                    'raw_body' => $rawBody,
+                    'idempotency_key' => $idempotencyKey,
+                    'plain_api_key' => $plainApiKey,
+                    'timeout_seconds' => $timeoutSeconds,
+                ], JSON_THROW_ON_ERROR));
+                $secretPaths[] = $spec->inputFilePath();
+
+                // 遮断の段 4: 起動前の権限検査 (違えば子を起こさない)
+                ProbeEnvironment::assertSafePermissions(
+                    ProbeEnvironment::mode($workspace),
+                    ProbeEnvironment::mode($envFilePath),
+                    ProbeEnvironment::mode($spec->inputFilePath()),
+                );
+
+                $processes[$childId] = $factory->create($spec);
+            }
+
+            $result = self::conduct(
+                new ProcessBarrier($workspace),
+                $processes,
+                $nonces,
+                hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000),
+                $routeName,
+                $uri,
+                $idempotencyKey,
+                $expectedRequestHash,
+                $secrets,
+            );
+        } catch (Throwable $e) {
+            // ★回収は**作業の失敗の後でも必ず**行う。回収そのものが失敗したときは
+            //   その例外を投げる (元の失敗は previous に畳んで捨てない)。
+            self::reap($processes, $workspace, $secretPaths, $e);
+
+            throw $e;
+        }
+
+        self::reap($processes, $workspace, $secretPaths, null);
+
+        return $result;
+    }
+
+    /**
+     * 合図の待ち合わせと受理条件の検査 (回収は呼び出し側の責務)。
+     *
+     * @param  array<string, ProbeProcess>  $processes
+     * @param  array<string, string>  $nonces
+     * @param  list<string>  $secrets  診断へ載せる前に伏せ字にする値
+     */
+    private static function conduct(
+        ProcessBarrier $barrier,
+        array $processes,
+        array $nonces,
+        int $workDeadlineNs,
+        string $routeName,
+        string $uri,
+        string $idempotencyKey,
+        string $expectedRequestHash,
+        array $secrets,
+    ): ConcurrentProbeResult {
+        foreach ($processes as $process) {
+            $process->start();
+        }
+
+        $abort = self::abortCondition($barrier, $processes, $secrets);
+
+        // 1. ready を全員ぶん待ち、中身の nonce を照合する
+        foreach ($processes as $childId => $process) {
+            $payload = $barrier->await(
+                SignalName::make('ready', $childId),
+                self::remainingWorkSeconds($workDeadlineNs),
+                $abort,
+            );
+
+            if ($payload !== $nonces[$childId]) {
+                throw ConcurrencyProtocolException::identityMismatch(
+                    $childId,
+                    'ready の nonce',
+                    $nonces[$childId],
+                    $payload,
+                );
+            }
+        }
+
+        // 2. **ここで初めて** go token を作る (事前に子へ渡らない)
+        $goToken = bin2hex(random_bytes(16));
+        $barrier->signal(SignalName::make('go'), $goToken);
+
+        // 3. entered をちょうど 1 子ぶん待つ
+        $winnerId = self::awaitSingleEntered($barrier, $nonces, $goToken, $workDeadlineNs, $abort);
+        $loserId = self::oppositeChild($winnerId);
+
+        // 4. 反対側の out を待ち、中身を完全に検査する
+        [$loserJson, $loser] = self::readObservation($barrier, $loserId, $workDeadlineNs, $abort);
+        $loser->assertIdentity($loserId, $nonces[$loserId], $goToken);
+        $loser->assertLost($expectedRequestHash);
+
+        // 5. 検査をすべて通ったら release を置く
+        $barrier->signal(SignalName::make('release'), $goToken);
+
+        // 6. 勝者の out を待ち、同一性を検査する
+        [$winnerJson, $winner] = self::readObservation($barrier, $winnerId, $workDeadlineNs, $abort);
+        $winner->assertIdentity($winnerId, $nonces[$winnerId], $goToken);
+
+        $rawOut = [$winnerId => $winnerJson, $loserId => $loserJson];
+
+        // 受理条件 1: 両 process の exit code が 0
+        foreach ($processes as $childId => $process) {
+            $exitCode = $process->waitFor(self::remainingWorkSeconds($workDeadlineNs));
+            if ($exitCode !== 0) {
+                throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
+                    '子 "%s" の終了コードが 0 でない (%s)。stderr: %s',
+                    $childId,
+                    $exitCode === null ? '時間内に終了しなかった' : (string) $exitCode,
+                    $process->errorOutput() === ''
+                        ? '(なし)'
+                        : self::redactSecrets($process->errorOutput(), $secrets),
+                ));
+            }
+        }
+
+        // 受理条件 2: 各子の stdout の JSON と out ファイルの中身が一致
+        foreach ($processes as $childId => $process) {
+            if (trim($process->output()) !== trim($rawOut[$childId])) {
+                throw ConcurrencyProtocolException::unexpectedObservation(
+                    "子 \"{$childId}\" の stdout と out ファイルの中身が一致しない"
+                );
+            }
+        }
+
+        // 受理条件 3: 守りたい層以外の無効化と DB 座標、および**送り先が親の決めた面**であること
+        $expectedCoordinates = ProbeDatabaseCoordinates::fromParentConfig();
+        $observations = [$winnerId => $winner, $loserId => $loser];
+        foreach ($observations as $childId => $observation) {
+            $observation->assertAppLocksDisabled();
+            $observation->assertDatabaseCoordinates($expectedCoordinates);
+
+            // ★観測項目に集めるだけで判定に使わない形を作らない (AGENTS.md 走査規約 (d))。
+            //   request_hash は path を含むので間接的には効くが、明示的に照合する。
+            if ($observation->uri !== $uri) {
+                throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
+                    '子 "%s" が叩いた面が親の決めた面と違う (期待 %s / 実際 %s)',
+                    $childId,
+                    $uri,
+                    $observation->uri,
+                ));
+            }
+        }
+
+        $result = new ConcurrentProbeResult(
+            observations: $observations,
+            routeName: $routeName,
+            uri: $uri,
+            idempotencyKey: $idempotencyKey,
+            expectedRequestHash: $expectedRequestHash,
+        );
+
+        // 受理条件 4: 勝者・敗者がちょうど 1:1 に分かれる
+        // 受理条件 5: 勝者・敗者・親の期待値の request_hash が 3 点一致する
+        [$partitionedWinner, $partitionedLoser] = $result->partition();
+        foreach ([$partitionedWinner, $partitionedLoser] as $observation) {
+            if ($observation->requestHash !== $expectedRequestHash) {
+                throw ConcurrencyProtocolException::unexpectedObservation(
+                    '2 子と親の request_hash が 3 点一致しない'
+                );
+            }
+        }
+
+        return $result;
+    }
+
+    /**
+     * 待機中に毎周回呼ぶ中断条件 (締切を待たずに抜ける)。
+     *
+     * ★**二重実行の判定を子の生死より先**に置く。探している退行を「子が死んだ」という
+     *   別の診断で隠さないためである。
+     *
+     * @param  array<string, ProbeProcess>  $processes
+     * @param  list<string>  $secrets
+     * @return Closure(): void
+     */
+    private static function abortCondition(ProcessBarrier $barrier, array $processes, array $secrets): Closure
+    {
+        return static function () use ($barrier, $processes, $secrets): void {
+            // present() は許可集合に無い完成合図があれば拒否する (無視しない)
+            $entered = array_values(array_filter(
+                self::presentValues($barrier),
+                static fn (string $value): bool => str_starts_with($value, 'entered-'),
+            ));
+
+            if (count($entered) >= 2) {
+                throw ConcurrencyProtocolException::doubleExecution($entered);
+            }
+
+            foreach ($processes as $childId => $process) {
+                if ($process->isRunning()) {
+                    continue;
+                }
+
+                // ★停止を観測した**後に**列挙し直す。子は「out を置く」→「終了する」の順で
+                //   動くので、停止の観測より前に取った一覧を使うと、正常に終わった子を
+                //   「観測を出さずに終了した」と誤診する (この順序が load-bearing)。
+                if (in_array('out-'.$childId, self::presentValues($barrier), true)) {
+                    continue;
+                }
+
+                throw ConcurrencyProtocolException::childDiedEarly(
+                    $childId,
+                    $process->exitCode(),
+                    self::redactSecrets($process->errorOutput(), $secrets),
+                );
+            }
+        };
+    }
+
+    /**
+     * 現れている完成合図の名前 (未知の完成合図があれば拒否する)。
+     *
+     * @return list<string>
+     */
+    private static function presentValues(ProcessBarrier $barrier): array
+    {
+        return array_map(
+            static fn (SignalName $name): string => $name->value,
+            $barrier->present(SignalName::all()),
+        );
+    }
+
+    /**
+     * `entered` がちょうど 1 子ぶん現れるまで待ち、その child ID を返す。
+     *
+     * @param  array<string, string>  $nonces
+     * @param  Closure(): void  $abort
+     */
+    private static function awaitSingleEntered(
+        ProcessBarrier $barrier,
+        array $nonces,
+        string $goToken,
+        int $workDeadlineNs,
+        Closure $abort,
+    ): string {
+        while (true) {
+            $abort();
+
+            $entered = self::enteredChildren($barrier);
+
+            if (count($entered) === 1) {
+                $childId = $entered[0];
+                $payload = $barrier->await(
+                    SignalName::make('entered', $childId),
+                    self::remainingWorkSeconds($workDeadlineNs),
+                );
+
+                $expected = $nonces[$childId].':'.$goToken;
+                if ($payload !== $expected) {
+                    throw ConcurrencyProtocolException::identityMismatch(
+                        $childId,
+                        'entered の nonce:go_token',
+                        $expected,
+                        $payload,
+                    );
+                }
+
+                return $childId;
+            }
+
+            if (hrtime(true) >= $workDeadlineNs) {
+                throw BarrierTimeoutException::waitingForSingleEntered();
+            }
+
+            usleep(ProcessBarrier::POLL_INTERVAL_MICROSECONDS);
+        }
+    }
+
+    /**
+     * 現れている `entered` の child ID。
+     *
+     * @return list<string>
+     */
+    private static function enteredChildren(ProcessBarrier $barrier): array
+    {
+        $children = [];
+
+        foreach (self::presentValues($barrier) as $value) {
+            if (! str_starts_with($value, 'entered-')) {
+                continue;
+            }
+
+            $children[] = substr($value, strlen('entered-'));
+        }
+
+        return $children;
+    }
+
+    /**
+     * `out` を待って観測へ変換する (生の JSON も返す = stdout との突合に使う)。
+     *
+     * @param  Closure(): void  $abort
+     * @return array{string, ConcurrentProbeObservation}
+     */
+    private static function readObservation(
+        ProcessBarrier $barrier,
+        string $childId,
+        int $workDeadlineNs,
+        Closure $abort,
+    ): array {
+        $json = $barrier->await(
+            SignalName::make('out', $childId),
+            self::remainingWorkSeconds($workDeadlineNs),
+            $abort,
+        );
+
+        $decoded = json_decode($json, true);
+        if ($decoded === null) {
+            throw ConcurrencyProtocolException::unexpectedObservation(
+                "子 \"{$childId}\" の観測を JSON として読めない"
+            );
+        }
+
+        return [$json, ConcurrentProbeObservation::fromDecodedJson($decoded)];
+    }
+
+    private static function oppositeChild(string $childId): string
+    {
+        foreach (self::CHILD_IDS as $candidate) {
+            if ($candidate !== $childId) {
+                return $candidate;
+            }
+        }
+
+        throw new RuntimeException("反対側の子が見つからない: {$childId}");
+    }
+
+    /** 作業の残り時間 (絶対 deadline から算出。0 以下なら例外) */
+    private static function remainingWorkSeconds(int $workDeadlineNs): float
+    {
+        $remaining = ($workDeadlineNs - hrtime(true)) / 1_000_000_000;
+
+        if ($remaining <= 0.0) {
+            throw BarrierTimeoutException::workDeadlineExhausted();
+        }
+
+        return $remaining;
+    }
+
+    /**
+     * 回収 (フェーズ単位)。
+     *
+     * | 段 | 内容 |
+     * |---|---|
+     * | 0 | **秘密**(env ファイル・入力ファイル) を回収の成否にかかわらず消す |
+     * | 1 | 生存する全子へ `signalTerminate()` を送る |
+     * | 2 | 単一の reap deadline のうち最大 REAP_GRACE_SECONDS、全子をまとめて poll する |
+     * | 3 | まだ生存する全子へ `signalKill()` を送る (TERM で終わった子には送らない) |
+     * | 4 | reap deadline まで全子をまとめて poll する |
+     * | 5 | 消せなかった秘密 / 停止を確認できない子 / 残置 workspace の権限を**集めて 1 つの例外**にする |
+     *
+     * ★子単位の逐次処理にしない: 「子ごとに TERM → 1 秒待つ → KILL → 残りを待つ」を
+     *   順番にやると 1 子目が予算を使い切った時点で 2 子目に回収時間が残らない。
+     * ★**先に見つかった 1 つで打ち切らない**。秘密を消せなかったことと子が残っていることは
+     *   別々の危険であり、片方だけを報告すると残りが診断から消える。
+     * ★**診断材料は残してよいが秘密は残さない**。停止を確認できない子がいるときに
+     *   workspace ごと消すと、まだ動いている子が削除済みパスへ書き込む。
+     *
+     * @param  array<string, ProbeProcess>  $processes
+     * @param  list<string>  $secretPaths
+     */
+    private static function reap(array $processes, string $workspace, array $secretPaths, ?Throwable $cause): void
+    {
+        // 段 0: **1 件目の失敗で即 throw しない** (抜けると 2 件目の削除が省略され、
+        //       消せたはずの秘密が残る)。全対象を試行してから、失敗をまとめて報告する。
+        $failedSecrets = [];
+        foreach ($secretPaths as $path) {
+            clearstatcache(true, $path);
+            if (! file_exists($path)) {
+                continue;
+            }
+
+            if (! @unlink($path)) {
+                $failedSecrets[] = $path;
+            }
+        }
+
+        $now = hrtime(true);
+        $reapDeadline = $now + (int) (self::REAP_BUDGET_SECONDS * 1_000_000_000);
+        $graceDeadline = min($reapDeadline, $now + (int) (self::REAP_GRACE_SECONDS * 1_000_000_000));
+
+        $alive = self::runningChildren($processes);
+        foreach ($alive as $childId) {
+            $processes[$childId]->signalTerminate();
+        }
+        self::reapPhase($processes, $alive, $graceDeadline);
+
+        $alive = self::runningChildren($processes);
+        foreach ($alive as $childId) {
+            $processes[$childId]->signalKill();
+        }
+        self::reapPhase($processes, $alive, $reapDeadline);
+
+        $remainingChildren = self::runningChildren($processes);
+
+        // ★**問題を集めてから 1 つの例外へ載せる**。先に見つかった 1 つで打ち切ると、
+        //   同時に起きているもう一方の危険 (消せなかった秘密 / 残った子 / 緩い権限) が
+        //   診断から消える。
+        $problems = [];
+
+        if ($failedSecrets !== []) {
+            $problems[] = '秘密を含むファイルを削除できなかった (パスから再取得できる状態で残っている): '
+                .implode(',', $failedSecrets);
+        }
+
+        if ($remainingChildren !== []) {
+            $problems[] = sprintf(
+                '停止を確認できない子が残っている (child=%s)。診断のため workspace を残置した: %s',
+                implode(',', $remainingChildren),
+                $workspace,
+            );
+
+            $mode = ProbeEnvironment::mode($workspace);
+            if ($mode !== 0700) {
+                $problems[] = sprintf('残置する workspace の権限が 0700 でない (%04o)', $mode);
+            }
+        }
+
+        if ($problems !== []) {
+            throw ConcurrencyProtocolException::reapFailed($problems, $cause);
+        }
+
+        self::removeDirectory($workspace);
+    }
+
+    /**
+     * 1 フェーズぶんの poll と、フェーズ末尾の待機要求。
+     *
+     * ★**単一のループで全子の `isRunning()` を短い間隔で確認する**。
+     *   個々の子へ残り時間いっぱいの blocking wait を順番に行わない
+     *   (それをやると 1 子目が予算を食い切り、フェーズ単位にした意味が消えて逐次処理へ戻る)。
+     *   `waitFor()` は**この poll ループの中では使わない** — フェーズの終わりに 1 回だけ、
+     *   そのフェーズで見張っていた子へ**残り予算**(0 でありうる)で要求する。
+     *
+     * @param  array<string, ProbeProcess>  $processes
+     * @param  list<string>  $watch
+     */
+    private static function reapPhase(array $processes, array $watch, int $phaseDeadlineNs): void
+    {
+        while ($watch !== []) {
+            $stillRunning = false;
+            foreach ($watch as $childId) {
+                if ($processes[$childId]->isRunning()) {
+                    $stillRunning = true;
+                }
+            }
+
+            if (! $stillRunning || hrtime(true) >= $phaseDeadlineNs) {
+                break;
+            }
+
+            usleep(self::REAP_POLL_INTERVAL_MICROSECONDS);
+        }
+
+        $remaining = max(0.0, ($phaseDeadlineNs - hrtime(true)) / 1_000_000_000);
+        foreach ($watch as $childId) {
+            $processes[$childId]->waitFor($remaining);
+        }
+    }
+
+    /**
+     * @param  array<string, ProbeProcess>  $processes
+     * @return list<string>
+     */
+    private static function runningChildren(array $processes): array
+    {
+        $running = [];
+        foreach ($processes as $childId => $process) {
+            if ($process->isRunning()) {
+                $running[] = $childId;
+            }
+        }
+
+        return $running;
+    }
+
+    /**
+     * 診断へ載せる前に既知の秘密を伏せ字にする。
+     *
+     * ★一時ファイルを消しても**CI のログは残る**ので、秘密の後始末はファイル経路だけでは
+     *   閉じない。子の stderr は PHP の fatal やライブラリの例外文をそのまま含みうるため、
+     *   親が例外へ埋める直前にここを通す。
+     * ★**長い秘密から順に**置換する (短い秘密が長い秘密の一部だったときに、
+     *   置換済みの伏せ字を壊さないため)。
+     * ★保証しないもの: 完全一致で現れた秘密だけを伏せる。切り詰められた断片
+     *   (`getTraceAsString()` は文字列引数を 15 文字で切る) は一致しないので、
+     *   **子の側で trace を出さない**ことと合わせて初めて閉じる。
+     *
+     * @param  list<string>  $secrets
+     */
+    private static function redactSecrets(string $text, array $secrets): string
+    {
+        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
+
+        foreach ($secrets as $index => $secret) {
+            $text = str_replace($secret, '[redacted:'.$index.']', $text);
+        }
+
+        return $text;
+    }
+
+    private static function createWorkspace(): string
+    {
+        $workspace = sys_get_temp_dir().'/concurrency-probe-'.bin2hex(random_bytes(8));
+
+        if (! mkdir($workspace, 0700) || ! is_dir($workspace)) {
+            throw new RuntimeException("実プロセス並行テストの workspace を作れない: {$workspace}");
+        }
+
+        chmod($workspace, 0700);
+        ProcessBarrier::prepareWorkspace($workspace);
+
+        return $workspace;
+    }
+
+    private static function removeDirectory(string $directory): void
+    {
+        if (! is_dir($directory)) {
+            return;
+        }
+
+        foreach (scandir($directory) ?: [] as $entry) {
+            if ($entry === '.' || $entry === '..') {
+                continue;
+            }
+
+            $path = $directory.'/'.$entry;
+
+            // ★symlink は**辿らずに** unlink する。`is_dir()` はディレクトリへの symlink でも
+            //   true になるため、先に見ないと workspace の外を再帰的に消しにいく。
+            if (is_link($path)) {
+                unlink($path);
+
+                continue;
+            }
+
+            if (is_dir($path)) {
+                self::removeDirectory($path);
+
+                continue;
+            }
+
+            unlink($path);
+        }
+
+        rmdir($directory);
+    }
+}
diff --git a/tests/Support/Concurrency/ConcurrencyProtocolException.php b/tests/Support/Concurrency/ConcurrencyProtocolException.php
new file mode 100644
index 00000000..3dbad96e
--- /dev/null
+++ b/tests/Support/Concurrency/ConcurrencyProtocolException.php
@@ -0,0 +1,120 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use RuntimeException;
+use Throwable;
+
+/**
+ * 実プロセス並行テストの**プロトコルが破られた**。
+ *
+ * ★{@see BarrierTimeoutException} と型を分けている。とくに {@see self::doubleExecution()} は
+ *   本ハーネスが探している退行そのものなので、締切超過という紛らわしい形では出さない。
+ */
+final class ConcurrencyProtocolException extends RuntimeException
+{
+    /**
+     * 探している退行そのもの: 本処理へ 2 本とも入った。
+     *
+     * @param  list<string>  $enteredSignals
+     */
+    public static function doubleExecution(array $enteredSignals): self
+    {
+        return new self(
+            '本処理へ 2 本とも入った (二重実行を検出): '.implode(',', $enteredSignals)
+        );
+    }
+
+    public static function childDiedEarly(string $childId, ?int $exitCode, string $stderr): self
+    {
+        return new self(sprintf(
+            '子 "%s" が観測を出さずに終了した (exit=%s)。stderr: %s',
+            $childId,
+            $exitCode === null ? 'unknown' : (string) $exitCode,
+            $stderr === '' ? '(なし)' : $stderr,
+        ));
+    }
+
+    public static function identityMismatch(string $childId, string $field, string $expected, string $actual): self
+    {
+        return new self(sprintf(
+            '子 "%s" の同一性が一致しない (%s: 期待 "%s" / 実際 "%s")',
+            $childId,
+            $field,
+            $expected,
+            $actual,
+        ));
+    }
+
+    public static function goTokenMismatch(string $childId, string $expected, string $actual): self
+    {
+        return new self(sprintf(
+            '子 "%s" の go token が一致しない (期待 "%s" / 実際 "%s")。'
+            .'go を読まずに走った可能性がある',
+            $childId,
+            $expected,
+            $actual,
+        ));
+    }
+
+    public static function unexpectedObservation(string $reason): self
+    {
+        return new self('子の観測が受理条件を満たさない: '.$reason);
+    }
+
+    /**
+     * 許可集合に無い完成合図が現れた (無視ではなく拒否する)。
+     *
+     * @param  list<string>  $names
+     */
+    public static function unknownSignal(array $names): self
+    {
+        return new self(
+            '許可集合に無い完成合図がある: '.implode(',', $names)
+        );
+    }
+
+    public static function signalUnreadable(SignalName $name): self
+    {
+        return new self("合図 \"{$name->value}\" は在るのに読めない (観測が成立していない)");
+    }
+
+    public static function signalNotWritten(SignalName $name): self
+    {
+        return new self("合図 \"{$name->value}\" の書きかけを書き切れなかった");
+    }
+
+    public static function signalNotPlaced(SignalName $name): self
+    {
+        return new self(
+            "合図 \"{$name->value}\" を配置できなかった (target は不在。権限 / I/O 障害 / "
+            .'hard link 非対応のいずれか)'
+        );
+    }
+
+    public static function duplicateSignal(SignalName $name): self
+    {
+        return new self("合図 \"{$name->value}\" を 2 回置こうとした (二重送信)");
+    }
+
+    public static function signalDirectoryUnreadable(string $directory): self
+    {
+        return new self("完成合図のディレクトリを列挙できない: {$directory}");
+    }
+
+    /**
+     * 回収に失敗した (問題が複数あればすべて 1 つの例外へ載せる)。
+     *
+     * ★**先に見つかった 1 つで打ち切らない**。秘密を消せなかったことと停止を確認できない子が
+     *   残っていることは**別々の危険**であり、片方だけを報告すると残りが診断から消える。
+     * ★元の失敗 ($previous) は畳んで捨てない (回収の失敗が原因を隠さないようにする)。
+     *
+     * @param  list<string>  $problems
+     */
+    public static function reapFailed(array $problems, ?Throwable $previous = null): self
+    {
+        return new self('回収に失敗した: '.implode(' / ', $problems), previous: $previous);
+    }
+}
diff --git a/tests/Support/Concurrency/ProbeEnvironment.php b/tests/Support/Concurrency/ProbeEnvironment.php
new file mode 100644
index 00000000..47fd32c8
--- /dev/null
+++ b/tests/Support/Concurrency/ProbeEnvironment.php
@@ -0,0 +1,346 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use RuntimeException;
+use Tests\Support\Ci\TestDatabaseEnv;
+use Tests\Support\ExternalFakes\FakeWiringProbeRunner;
+use Webmozart\Assert\Assert;
+
+/**
+ * 子プロセスの設定の出所を作る (開発 DB への到達遮断の中心)。
+ *
+ * 作法は {@see FakeWiringProbeRunner} の 6 点規約を踏襲する:
+ * `env -i` で環境を作り直す / 専用の一時 env ファイル 1 つだけを設定の出所にする /
+ * ディレクトリ 0700・env ファイル 0600 を起動前に検査して違えば子を起こさない /
+ * 締切つき実行 / 解釈できない子の出力は fail-closed / finally で必ず片付ける。
+ *
+ * ★相手 (`FakeWiringProbeRunner`) は **DB へ接続しないこと**が要件なので DB 座標を渡さない。
+ *   こちらは**接続することが要件**なので、遮断の設計を独自に持つ。
+ *   「似ているから」で共通基底へ寄せない (寄せると DB 遮断が片方の都合で緩む)。
+ * ★**相手と違う判断をした点を黙って作らない**: 相手は APP_KEY / CIPHERSWEET_KEY を
+ *   使い捨てで生成し「一時ファイルは秘密を 1 つも持たない」を達成している。
+ *   こちらは**既存行 (CipherSweet で暗号化された PII) を読む必要がある**ため親の実鍵を渡す。
+ *   そのぶん置き場所を守る (0700 / 0600 / 起動前の権限検査 /
+ *   **回収の成否にかかわらず finally で必ず unlink**)。
+ *
+ * **保証しないもの**: ここが塞ぐのは「子が親のチェックアウトの `.env` / プロセス環境を
+ * 読んで別の DB へ繋ぐ」経路だけである。子が自分でハードコードした座標へ繋ぐ形
+ * (実装ミス) は塞げないので、実効座標の一致は子の段 9 と親の
+ * {@see ConcurrentProbeObservation::assertDatabaseCoordinates()} が別に見る。
+ */
+final class ProbeEnvironment
+{
+    /**
+     * 子の env ファイルへ書いてよいキー (deny-by-default)。
+     *
+     * @var list<string>
+     */
+    public const array ALLOWED_ENV_FILE_KEYS = [
+        'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY', 'BCRYPT_ROUNDS',
+        'DB_CONNECTION', 'DB_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
+        'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET', 'DB_SSLMODE',
+        'CACHE_STORE', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'MAIL_MAILER',
+    ];
+
+    /**
+     * 子へ渡してよい**プロセス環境変数** (`env -i` で空にしたうえでこれだけ載せる)。
+     *
+     * ★この定数は「起動側が載せる分」の宣言であり、**子が実際に受け取った分**は
+     *   子自身が段 6 で観測して突き合わせる (組み立て側の配列を見ても `env -i` の退行は映らない)。
+     *
+     * @var list<string>
+     */
+    public const array ALLOWED_PROCESS_ENV_KEYS = [
+        'CONCURRENCY_PROBE_ENV_DIR',
+        'CONCURRENCY_PROBE_ENV_FILE',
+        // 設定キャッシュを無効化する (存在しない絶対パスを一時ディレクトリ配下に指す)
+        'APP_CONFIG_CACHE',
+    ];
+
+    /** env ファイルの名前 (workspace 内で固定) */
+    public const string ENV_FILE_NAME = '.env.probe';
+
+    /**
+     * env ファイルの 1 行を受理する唯一の書式。
+     *
+     * 値の中身は「引用符・バックスラッシュ・ドル記号以外の 1 文字」か
+     * 「**encoder が実際に作る 3 種の escape** (`\\` / `\"` / `\$`)」の並びだけである。
+     * 素の `$` を許さないのは、encoder が必ず escape する以上 canonical な出力には現れず、
+     * かつ phpdotenv が二重引用符の中で `${VAR}` を展開する = 実効値が食い違う経路だからである。
+     */
+    private const string ENV_LINE_PATTERN = '/^([A-Z][A-Z0-9_]*)="((?:[^"\\\\$]|\\\\[\\\\"$])*)"$/';
+
+    /**
+     * 親の**実行時の実接続設定**から子の env 値を作る。
+     *
+     * ★値の出所は `config('database.connections.pgsql')` であり env の再読解ではない
+     *   (親と子が同じ DB を見ることが構造的に保証される)。
+     * ★`DB_URL` は**空文字で固定**する。キーを消すと子の `.env` 読み込みで復活する。
+     *
+     * @return array<string, string>
+     *
+     * @throws RuntimeException 前提が崩れているとき (子を起こさせない)
+     */
+    public static function envFileValues(): array
+    {
+        Assert::same(config('database.default'), 'pgsql', 'このハーネスは pgsql レーンを前提にする');
+
+        $config = config('database.connections.pgsql');
+        Assert::isArray($config);
+
+        // ★前提検査 1: 親が DB_URL 主体で接続していると、設定配列の host/port/database は
+        //   実効座標とは限らない (URL 解析結果が優先される)。その場合は子を起こさない。
+        $url = $config['url'] ?? null;
+        if ($url !== null && $url !== '') {
+            throw new RuntimeException(
+                'このハーネスは個別キー接続のレーンを前提にする (DB_URL 主体の設定では'
+                .'設定配列の host/port/database が実効座標とは限らないため子を起こさない)'
+            );
+        }
+
+        $coordinates = ProbeDatabaseCoordinates::fromParentConfig();
+
+        // ★前提検査 2: 既存の単一点ガードを**親側でも**通す (allowlist 一致 + dev denylist)。
+        TestDatabaseEnv::assertPgsqlTestDatabaseSafe($coordinates->database);
+
+        $values = [
+            'APP_ENV' => 'testing',
+            'APP_KEY' => self::requiredString(config('app.key'), 'app.key'),
+            'APP_URL' => self::requiredString(config('app.url'), 'app.url'),
+            'APP_DEBUG' => config('app.debug') === true ? 'true' : 'false',
+            'CIPHERSWEET_KEY' => self::requiredString(
+                config('ciphersweet.providers.string.key'),
+                'ciphersweet.providers.string.key',
+            ),
+            // ★このアプリは config/hashing.php を持たない (framework 既定にまかせている) ため、
+            //   親が実際に使っている値の出所はプロセス環境だけである。
+            'BCRYPT_ROUNDS' => (string) (env('BCRYPT_ROUNDS') ?? 12),
+            'DB_CONNECTION' => 'pgsql',
+            'DB_URL' => '',
+            'DB_HOST' => $coordinates->host,
+            'DB_PORT' => (string) $coordinates->port,
+            'DB_DATABASE' => $coordinates->database,
+            'DB_USERNAME' => $coordinates->username,
+            'DB_PASSWORD' => (string) (config('database.connections.pgsql.password') ?? ''),
+            'DB_CHARSET' => $coordinates->charset,
+            'DB_SSLMODE' => $coordinates->sslmode,
+            // 守りたい層以外を無効化する (要素 (3))
+            'CACHE_STORE' => 'array',
+            'QUEUE_CONNECTION' => 'sync',
+            'SESSION_DRIVER' => 'array',
+            'MAIL_MAILER' => 'array',
+        ];
+
+        self::assertEnvFileKeys($values);
+        self::assertNoLineInjection($values);
+
+        return $values;
+    }
+
+    /**
+     * キー集合が許可一覧と**完全一致**することを検査する。
+     *
+     * 「許可外が無い」だけでは足りない — 必須の DB キーが**欠落**した場合、
+     * その穴は子の `.env` 読み込みで埋まりうる (まさに塞ぎたい形)。
+     *
+     * @param  array<string, string>  $values
+     */
+    public static function assertEnvFileKeys(array $values): void
+    {
+        $actual = array_keys($values);
+        $allowed = self::ALLOWED_ENV_FILE_KEYS;
+        sort($actual);
+        sort($allowed);
+
+        Assert::same($actual, $allowed, 'env ファイルのキー集合が許可一覧と一致しない');
+    }
+
+    /**
+     * 値に改行 / CR が入っていたら**書かずに例外**にする。
+     *
+     * env ファイルは 1 行 1 キーなので、値の改行は**別キーの注入**になる。
+     *
+     * @param  array<string, string>  $values
+     */
+    public static function assertNoLineInjection(array $values): void
+    {
+        foreach ($values as $key => $value) {
+            if (preg_match('/[\r\n]/', $value) === 1) {
+                throw new RuntimeException("env 値に改行を含むキーは書けない: {$key}");
+            }
+        }
+    }
+
+    /**
+     * 子が実際に受け取ったプロセス環境のキー集合を検査する (段 6 の純関数)。
+     *
+     * `env -i` の退行で親の `DB_URL` 等が継承されると、phpdotenv は immutable なので
+     * **環境変数が env ファイルより優先**され、遮断を迂回する。
+     *
+     * @param  list<string>  $received
+     *
+     * @throws RuntimeException 許可 3 キーとの完全一致でない
+     */
+    public static function assertProcessEnvironmentKeys(array $received): void
+    {
+        $actual = $received;
+        $allowed = self::ALLOWED_PROCESS_ENV_KEYS;
+        sort($actual);
+        sort($allowed);
+
+        if ($actual === $allowed) {
+            return;
+        }
+
+        throw new RuntimeException(
+            '継承された環境変数がある (env -i の退行): 余剰='
+            .(implode(',', array_diff($actual, $allowed)) ?: '(なし)')
+            .' / 欠落='
+            .(implode(',', array_diff($allowed, $actual)) ?: '(なし)')
+        );
+    }
+
+    /**
+     * env ファイルの 1 行を組み立てる (書式は 1 つだけ)。
+     *
+     * 形式: `KEY="value"` — 値は必ず二重引用符で囲み、**`\` / `"` / `$` の 3 文字**を
+     * バックスラッシュでエスケープする。
+     *
+     * ★`$` をエスケープするのは、**phpdotenv が二重引用符の中で `${VAR}` を変数展開するため**
+     *   である。エスケープしないと、パスワードに `$` が入っていた場合に実効値が変わる
+     *   (子が接続できない、あるいは別の値で接続する)。
+     * ★`#` と空白と空文字は引用符の内側にあるので特別扱いは要らない。
+     * ★子側の厳格パーサ ({@see self::parseEnvFile()}) は**この 1 形式だけ**を受理し、
+     *   同じ規則で復号する。
+     */
+    public static function encodeLine(string $key, string $value): string
+    {
+        $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
+
+        return $key.'="'.$escaped.'"'."\n";
+    }
+
+    /**
+     * 上の書式だけを受理する厳格パーサ (bootstrap 前の検査に使う)。
+     *
+     * ★`loadEnvironmentFrom()` は**その場では解析しない** (起動時に読む場所を指定するだけ)。
+     *   bootstrap 前に DB 名を検査するには自前解析が要る。
+     *
+     * @return array<string, string>
+     *
+     * @throws RuntimeException 受理しない行がある
+     */
+    public static function parseEnvFile(string $path): array
+    {
+        $contents = file_get_contents($path);
+        if ($contents === false) {
+            throw new RuntimeException("子の env ファイルを読めない: {$path}");
+        }
+
+        $values = [];
+        foreach (explode("\n", $contents) as $index => $line) {
+            if ($line === '') {
+                continue;
+            }
+
+            if (preg_match(self::ENV_LINE_PATTERN, $line, $matches) !== 1) {
+                throw new RuntimeException(
+                    '子の env ファイルに受理しない行がある (行 '.($index + 1).')'
+                );
+            }
+
+            $key = $matches[1];
+            if (array_key_exists($key, $values)) {
+                throw new RuntimeException("子の env ファイルにキーが重複している: {$key}");
+            }
+
+            // ★受理した 3 種の escape だけを解く (左から 1 回走査するので `\\\\$` のような
+            //   「escape されたバックスラッシュ + escape されたドル」も正しく戻る)。
+            $values[$key] = preg_replace_callback(
+                '/\\\\([\\\\"$])/',
+                static fn (array $m): string => $m[1],
+                $matches[2],
+            ) ?? '';
+        }
+
+        return $values;
+    }
+
+    /**
+     * 保護されたファイルを作る (作成時点から 0600)。
+     *
+     * `FakeWiringProbeRunner::writeEnvFile()` と同じ手順を踏む:
+     * 1. 一時的に `umask(0o077)` を設定する (**作成時の mode 自体**を 0600 にする)。
+     *    `finally` で必ず元の umask へ復元する
+     * 2. `fopen($path, 'x')` で作る (既存ファイルがあれば失敗 = 乗っ取られた置き場所へ書き足さない)
+     * 3. **秘密を書き込む前に** `chmod($path, 0600)` する
+     *    (umask に依存せず 0600 を確定させる。書いてから絞ると露出が残る)
+     * 4. 書き切れなかった / 閉じられなかったら fail-closed で例外
+     */
+    public static function writeProtectedFile(string $path, string $contents): void
+    {
+        $previousUmask = umask(0o077);
+
+        try {
+            // ★`@` を付けるのは、既存ファイルでの失敗を**自前の fail-closed 例外**で表すため。
+            //   付けないと PHP の警告が先に ErrorException へ化け、診断が「file exists」の
+            //   生メッセージに置き換わって、この経路の意図 (乗っ取られた置き場所へ書き足さない) が読めなくなる。
+            $handle = @fopen($path, 'x');
+            if ($handle === false) {
+                throw new RuntimeException("子へ渡すファイルを作れない (既存 / 権限): {$path}");
+            }
+
+            chmod($path, 0600);
+
+            $written = fwrite($handle, $contents);
+            $closed = fclose($handle);
+
+            if ($written !== strlen($contents) || $closed === false) {
+                throw new RuntimeException("子へ渡すファイルを書き切れなかった: {$path}");
+            }
+        } finally {
+            umask($previousUmask);
+        }
+    }
+
+    /**
+     * ディレクトリ 0700・env ファイル 0600・入力ファイル 0600 でなければ例外 (子を起こさない)。
+     */
+    public static function assertSafePermissions(int $directoryMode, int $envFileMode, int $inputFileMode): void
+    {
+        if ($directoryMode !== 0700 || $envFileMode !== 0600 || $inputFileMode !== 0600) {
+            throw new RuntimeException(sprintf(
+                '子へ渡すファイルの権限が想定と違うため子プロセスを起こさない (dir=%04o env=%04o input=%04o)',
+                $directoryMode,
+                $envFileMode,
+                $inputFileMode,
+            ));
+        }
+    }
+
+    /** パスの permission bits (取得できなければ -1) */
+    public static function mode(string $path): int
+    {
+        clearstatcache(true, $path);
+        $permissions = fileperms($path);
+
+        return $permissions === false ? -1 : ($permissions & 0777);
+    }
+
+    /** 子プロセスの実行スクリプトの絶対パス */
+    public static function probeScriptPath(): string
+    {
+        return __DIR__.'/idempotency-claim-probe.php';
+    }
+
+    private static function requiredString(mixed $value, string $label): string
+    {
+        Assert::string($value, "{$label} が文字列でない");
+        Assert::notEmpty($value, "{$label} が空である");
+
+        return $value;
+    }
+}
diff --git a/tests/Support/Concurrency/idempotency-claim-probe.php b/tests/Support/Concurrency/idempotency-claim-probe.php
new file mode 100644
index 00000000..483bde27
--- /dev/null
+++ b/tests/Support/Concurrency/idempotency-claim-probe.php
@@ -0,0 +1,299 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Auth\Context\ApiActorContext;
+use App\Http\Middleware\ResolveApiActor;
+use Illuminate\Contracts\Http\Kernel as HttpKernel;
+use Illuminate\Foundation\Application;
+use Illuminate\Http\JsonResponse;
+use Illuminate\Http\Request;
+use Illuminate\Support\Facades\Route;
+use Tests\Support\Ci\TestDatabaseEnv;
+use Tests\Support\Concurrency\ProbeDatabaseCoordinates;
+use Tests\Support\Concurrency\ProbeEnvironment;
+use Tests\Support\Concurrency\ProcessBarrier;
+use Tests\Support\Concurrency\SignalName;
+use Webmozart\Assert\Assert;
+
+/*
+ * 実プロセス並行テストの子 (正典 v1 の要素 (1))。
+ *
+ * ★責務は 6 つだけ: 受け取った環境を検査する / 設定の出所を固定する /
+ *   起動前に DB 座標を検査する / 起動後に「守りたい層以外の無効化」を検査してから
+ *   準備完了を告げる / 要求を 1 回だけ投げる / 観測を JSON で書く。
+ * ★禁止する文 (echo) を使わないため fwrite(STDOUT, …) で書く (AGENTS.md)。
+ * ★秘密 (plain API key / body) は argv に載せない。0600 の入力ファイルから読む。
+ * ★**マイグレーションを一切実行しない** (スキーマは親のレーンが用意済み)。`RefreshDatabase` も使わない。
+ *
+ * 終了コード:
+ *   0  正常 (観測を stdout と out 合図へ書いた)
+ *   70 継承された環境変数がある (env -i の退行)
+ *   71 既定 cache を array に固定できていない (守りたい層以外を無効化できていない)
+ *   72 実効 DB 座標が宣言と一致しない (二重解釈のずれ / 別 DB への到達)
+ *   73 それ以外の失敗 (stderr に例外を書く)
+ */
+
+require __DIR__.'/../../../vendor/autoload.php';
+
+// ─────────────────────────────────────────────────────────────────────────────
+// [段 6] bootstrap の**前**に、子が実際に受け取ったプロセス環境を検査する。
+//        組み立て側の配列を見ても env -i の退行は映らない (観測できるのは子だけ)。
+//        phpdotenv は immutable なので、環境変数が残っていると env ファイルより優先され、
+//        遮断を迂回する。
+// ─────────────────────────────────────────────────────────────────────────────
+try {
+    $received = getenv();
+    Assert::isArray($received);
+    ProbeEnvironment::assertProcessEnvironmentKeys(array_keys($received));
+} catch (Throwable $e) {
+    // ★この段のメッセージは**環境変数のキー名**しか含まない (値は 1 つも載らない) ので出してよい。
+    fwrite(STDERR, $e->getMessage()."\n");
+
+    exit(70);
+}
+
+try {
+    Assert::count($argv, 4, '引数は workspace / childId / inputFileName の 3 つである');
+    $workspaceDirectory = $argv[1];
+    $childId = $argv[2];
+    $inputFileName = $argv[3];
+
+    $environmentDirectory = getenv('CONCURRENCY_PROBE_ENV_DIR');
+    $environmentFile = getenv('CONCURRENCY_PROBE_ENV_FILE');
+    Assert::stringNotEmpty($environmentDirectory);
+    Assert::stringNotEmpty($environmentFile);
+
+    // ─────────────────────────────────────────────────────────────────────────
+    // [段 7] env ファイルを**自前の厳格パーサ**で解析し、bootstrap 前に DB 名を検査する。
+    //        `loadEnvironmentFrom()` はその場では解析しない (読む場所を指定するだけ) ので、
+    //        bootstrap 前の検査には自前解析が要る。
+    // ─────────────────────────────────────────────────────────────────────────
+    $declaredValues = ProbeEnvironment::parseEnvFile($environmentDirectory.'/'.$environmentFile);
+    ProbeEnvironment::assertEnvFileKeys($declaredValues);
+    TestDatabaseEnv::assertPgsqlTestDatabaseSafe($declaredValues['DB_DATABASE']);
+
+    $input = json_decode((string) file_get_contents($workspaceDirectory.'/'.$inputFileName), true);
+    Assert::isArray($input);
+    Assert::same($input['child_id'] ?? null, $childId, '入力ファイルの child ID が引数と違う');
+    $nonce = $input['nonce'];
+    $routeName = $input['route_name'];
+    $uri = $input['uri'];
+    $rawBody = $input['raw_body'];
+    $idempotencyKey = $input['idempotency_key'];
+    $plainApiKey = $input['plain_api_key'];
+    $timeoutSeconds = $input['timeout_seconds'];
+    Assert::stringNotEmpty($nonce);
+    Assert::stringNotEmpty($routeName);
+    Assert::stringNotEmpty($uri);
+    Assert::string($rawBody);
+    Assert::stringNotEmpty($idempotencyKey);
+    Assert::stringNotEmpty($plainApiKey);
+    // JSON の数値は int / float のどちらにもなりうる (60.0 と 0.2 で型が変わる)。
+    Assert::numeric($timeoutSeconds);
+    $timeoutSeconds = (float) $timeoutSeconds;
+    Assert::greaterThan($timeoutSeconds, 0.0);
+
+    // ★合図の締切は**単調時計**で測る (壁時計は NTP 補正で戻りうる)。
+    $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);
+    $remainingSeconds = static function () use ($deadline): float {
+        $remaining = ($deadline - hrtime(true)) / 1_000_000_000;
+        Assert::greaterThan($remaining, 0.0, '子の締切を使い切った');
+
+        return $remaining;
+    };
+
+    // ─────────────────────────────────────────────────────────────────────────
+    // [段 8] 設定の出所を専用の一時 env ファイル 1 つへ固定してから起動する。
+    //        `APP_CONFIG_CACHE` は一時ディレクトリ配下の**存在しない絶対パス**
+    //        (共有の bootstrap/cache を作らない・消さない)。
+    // ─────────────────────────────────────────────────────────────────────────
+    /** @var Application $app */
+    $app = require __DIR__.'/../../../bootstrap/app.php';
+    Assert::isInstanceOf($app, Application::class);
+
+    $app->useEnvironmentPath($environmentDirectory);
+    $app->loadEnvironmentFrom($environmentFile);
+
+    $httpKernel = $app->make(HttpKernel::class);
+    $httpKernel->bootstrap();
+
+    // ─────────────────────────────────────────────────────────────────────────
+    // [段 9] **ready を出す前に**「守りたい層以外の無効化」と実効 DB 座標を検査する。
+    //        測った後に「実は無効化できていなかった」と分かって赤くなるのでは、
+    //        正典の要素 (3) を満たしたことにならない。
+    // ─────────────────────────────────────────────────────────────────────────
+    // ★**cache API は 1 つも呼ばない**。設定だけを読む形にしてあるのは、
+    //   `tests/Architecture/CachePayloadPlainDataGateTest.php` の L3 目録
+    //   (キャッシュに触れるファイルの exact-fit) へ本スクリプトを登録すると、
+    //   同ファイルが採用時債務 (adoption-debt.tsv) に在るため乖離台帳の 3 択が発生するためである。
+    //   詳細設計は `Cache::getDefaultDriver()` を挙げていたが、その戻り値は vendor 実装上
+    //   `config('cache.default')` そのもの (`CacheManager::getDefaultDriver()`) で**同じ事実の写し**にすぎない。
+    //   代わりに「既定 store を裏打ちする driver」を見る — こちらは
+    //   「array という名前の store が実は別の driver で裏打ちされている」形まで落とせるので**より強い**。
+    $cacheDefault = config('cache.default');
+    Assert::stringNotEmpty($cacheDefault);
+    $cacheStoreDriver = config("cache.stores.{$cacheDefault}.driver");
+
+    if ($cacheDefault !== 'array' || $cacheStoreDriver !== 'array') {
+        fwrite(STDERR, 'cache が array に固定できていない (守りたい層以外を無効化できていない)'."\n");
+
+        exit(71);
+    }
+
+    $effectiveCoordinates = ProbeDatabaseCoordinates::fromParentConfig();
+    Assert::regex($declaredValues['DB_PORT'], '/^[0-9]+$/');
+    $declaredCoordinates = new ProbeDatabaseCoordinates(
+        driver: $declaredValues['DB_CONNECTION'],
+        host: $declaredValues['DB_HOST'],
+        port: (int) $declaredValues['DB_PORT'],
+        database: $declaredValues['DB_DATABASE'],
+        username: $declaredValues['DB_USERNAME'],
+        charset: $declaredValues['DB_CHARSET'],
+        sslmode: $declaredValues['DB_SSLMODE'],
+        url: $declaredValues['DB_URL'],
+    );
+
+    // ★自前パーサの結果と bootstrap 後の実効値の一致まで見る (二重解釈のずれの検出)。
+    if (! $effectiveCoordinates->equals($declaredCoordinates)) {
+        fwrite(STDERR, sprintf(
+            "実効 DB 座標が宣言と一致しない (宣言 %s / 実効 %s)\n",
+            $declaredCoordinates->describe(),
+            $effectiveCoordinates->describe(),
+        ));
+
+        exit(72);
+    }
+
+    $barrier = new ProcessBarrier($workspaceDirectory);
+
+    $handlerExecutions = 0;
+    $goToken = null;
+    $apiKeyId = null;
+
+    /** 認証結果 (ApiActorContext) から api_key_id を観測する (入力のコピーではない) */
+    $observedApiKeyId = static function (Request $request): int {
+        $actor = $request->attributes->get(ResolveApiActor::ATTRIBUTE_KEY);
+        Assert::isInstanceOf($actor, ApiActorContext::class, '認証後の actor を観測できない');
+        Assert::notNull($actor->apiKey, 'API キー actor でない');
+
+        return $actor->apiKey->id;
+    };
+
+    // probe route を**この子の app インスタンスへ**登録する。
+    // ハンドラは**テスト側コード**なので、アプリコードを 1 バイトも触らずに待たせられる。
+    //
+    // ★middleware 列は「**冪等 middleware の前提を満たす最小 probe 経路**」である。
+    //   本番の順序契約は auth → throttle → resolve.api-actor → api.project-in-org
+    //   → api-key.ability → idempotent → controller だが、throttle を挟むと 2 本の到達が
+    //   乱れて測りたいものと別の分岐になるため入れない。**「本番同等」とは主張しない**。
+    //
+    // ★`$goToken` は**参照キャプチャ**である。closure を定義する時点ではまだ go を待っておらず、
+    //   値キャプチャでは後の代入が反映されない (この closure は go の後にしか実行されないが、
+    //   値キャプチャだと**空文字を合図に書いてしまう**)。
+    //   先頭の Assert は「万一 go より先に handler へ入った」場合に**黙って空を書かず落ちる**ための門である。
+    Route::post($uri, function (Request $request) use (
+        $barrier,
+        $childId,
+        $nonce,
+        &$goToken,
+        &$apiKeyId,
+        $remainingSeconds,
+        &$handlerExecutions,
+        $observedApiKeyId,
+    ): JsonResponse {
+        Assert::stringNotEmpty($goToken);
+        $handlerExecutions++;
+        $apiKeyId = $observedApiKeyId($request);
+
+        // 勝者だけがここへ来る。入ったことを告げ、親の release を待つ。
+        // これで敗者は**勝者の claim 行が processing のまま在る間に必ず claim へ到達する**。
+        $barrier->signal(SignalName::make('entered', $childId), $nonce.':'.$goToken);
+        $barrier->await(SignalName::make('release'), $remainingSeconds());
+
+        return new JsonResponse(['data' => ['ok' => true]], 201);
+    })->middleware(['auth:api-key,api-oauth', 'resolve.api-actor', 'idempotent'])->name($routeName);
+
+    // 準備完了を告げ、go を待つ (起動コストはここまでで払い切る)。
+    $barrier->signal(SignalName::make('ready', $childId), $nonce);
+    $goToken = $barrier->await(SignalName::make('go'), $remainingSeconds());
+    Assert::stringNotEmpty($goToken);
+
+    // 要求を 1 回だけ投げる (実サーバは立てない。プロセス内の実 middleware 列を通す)。
+    //
+    // ★第 3 引数 ($parameters) は**空配列**である。ここへ body を渡すと form parameter として
+    //   扱われ `getContent()` が空になり、middleware が hash する内容が親の期待値と食い違う。
+    //   raw bytes は**第 7 引数 (content)** へ渡す。
+    $probeRequest = Request::create(
+        uri: '/'.$uri,
+        method: 'POST',
+        parameters: [],
+        cookies: [],
+        files: [],
+        server: [
+            'CONTENT_TYPE' => 'application/json',
+            'HTTP_ACCEPT' => 'application/json',
+            'HTTP_AUTHORIZATION' => "Bearer {$plainApiKey}",
+            'HTTP_IDEMPOTENCY_KEY' => $idempotencyKey,
+        ],
+        content: $rawBody,
+    );
+
+    $response = $httpKernel->handle($probeRequest);
+
+    // 敗者は handler へ入らないので、middleware が置いた attribute から認証結果を取る
+    // (`resolve.api-actor` は `idempotent` より前に走るので、409 の場合も attribute は在る)。
+    $apiKeyId ??= $observedApiKeyId($probeRequest);
+
+    $status = $response->getStatusCode();
+    $errorCode = null;
+    if ($status < 200 || $status >= 300) {
+        $decodedBody = json_decode((string) $response->getContent(), true);
+        $code = is_array($decodedBody) && is_array($decodedBody['error'] ?? null)
+            ? ($decodedBody['error']['code'] ?? null)
+            : null;
+        // ★読めなくても黙って null にしない (親の fail-closed 検査で弾かれる非空文字列を入れる)。
+        $errorCode = is_string($code) && $code !== '' ? $code : 'unreadable_error_body';
+    }
+
+    $observedRouteName = $probeRequest->route()?->getName();
+
+    $json = json_encode([
+        'child_id' => $childId,
+        'nonce' => $nonce,
+        'go_token' => $goToken,
+        'http_status' => $status,
+        'error_code' => $errorCode,
+        'handler_executions' => $handlerExecutions,
+        'entered_handler' => $handlerExecutions > 0,
+        'route_name' => is_string($observedRouteName) && $observedRouteName !== ''
+            ? $observedRouteName
+            : '(unnamed-probe-route)',
+        'uri' => $probeRequest->path(),
+        // ★middleware と同一規則で、**実際に送った Request** から計算する
+        //   (body を form parameter で渡す事故があれば親の期待値と食い違って落ちる)。
+        'request_hash' => hash(
+            'sha256',
+            $probeRequest->method().'|'.$probeRequest->path().'|'.$probeRequest->getContent()
+        ),
+        'api_key_id' => $apiKeyId,
+        'cache_default' => $cacheDefault,
+        'cache_store_driver' => $cacheStoreDriver,
+        ...$effectiveCoordinates->toObservationValues(),
+    ], JSON_THROW_ON_ERROR);
+
+    // 観測を書く。stdout と out ファイルへ**同じ JSON** を出す (親が一致を検査する)。
+    fwrite(STDOUT, $json);
+    $barrier->signal(SignalName::make('out', $childId), $json);
+
+    exit(0);
+} catch (Throwable $e) {
+    // ★**メッセージも trace も出さない**。子は plain API キー / raw body / 実鍵を握っており、
+    //   `getTraceAsString()` は文字列引数を 15 文字まで含める = 秘密の先頭が CI ログへ残る。
+    //   親は stderr を例外へ埋める前に既知の秘密を伏せ字にするが、切り詰められた断片は
+    //   完全一致では伏せられないので、**出さない側**で閉じる。
+    //   診断に要るのは「どの型の例外がどこで出たか」までである。
+    fwrite(STDERR, sprintf("%s at %s:%d\n", $e::class, $e->getFile(), $e->getLine()));
+
+    exit(73);
+}
diff --git a/tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php b/tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php
new file mode 100644
index 00000000..57b886af
--- /dev/null
+++ b/tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php
@@ -0,0 +1,1320 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\ApiErrorCode;
+use Dotenv\Dotenv;
+use Tests\Support\Concurrency\BarrierTimeoutException;
+use Tests\Support\Concurrency\ConcurrencyProbeRunner;
+use Tests\Support\Concurrency\ConcurrencyProtocolException;
+use Tests\Support\Concurrency\ConcurrentProbeObservation;
+use Tests\Support\Concurrency\ConcurrentProbeResult;
+use Tests\Support\Concurrency\ProbeDatabaseCoordinates;
+use Tests\Support\Concurrency\ProbeEnvironment;
+use Tests\Support\Concurrency\ProbeLaunchSpec;
+use Tests\Support\Concurrency\ProbeProcess;
+use Tests\Support\Concurrency\ProbeProcessFactory;
+use Tests\Support\Concurrency\ProcessBarrier;
+use Tests\Support\Concurrency\SignalName;
+use Webmozart\Assert\Assert;
+
+/*
+ * ハーネス自身が**黙って緑になる**壊れ方を塞ぐ検査 (4 群)。
+ *
+ * ★**子プロセスを 1 本も起こさない**。偽の {@see ProbeProcessFactory} を差すか、
+ *   純関数を直接叩く。起動仕様が値 ({@see ProbeLaunchSpec}) になっているから成立する。
+ *
+ * **保証の境界**: 群 4 が主張するのは「runner が {@see ProbeProcess} へ停止・強制終了・待機を
+ * それぞれ要求すること」までである。**実 OS プロセスに対するシグナルの実効性は保証範囲外**
+ * とする (実プロセスを起こすテストを増やすと正典の要素 (6) に反するため踏み込まない)。
+ * 操作を 3 つに分けているのは、この主張を**呼び出し順込みで実際に固定できる**ようにするため。
+ */
+
+// ─────────────────────────────────────────────────────────────────────────────
+// 偽の子プロセス (合図を書く側を演じる)
+// ─────────────────────────────────────────────────────────────────────────────
+
+/** 全子で共有する呼び出し記録 (順序と poll の交互性を固定するため) */
+final class HarnessCallLog
+{
+    /** @var list<array{child: string, op: string}> シグナルと待機だけ (poll は含めない) */
+    public array $operations = [];
+
+    /** @var list<string> `isRunning()` の呼び出し順 (単一ループかどうかを見る) */
+    public array $polls = [];
+
+    public function record(string $childId, string $operation): void
+    {
+        $this->operations[] = ['child' => $childId, 'op' => $operation];
+    }
+
+    public function poll(string $childId): void
+    {
+        $this->polls[] = $childId;
+    }
+
+    public function resetPolls(): void
+    {
+        $this->polls = [];
+    }
+
+    /** @return list<string> */
+    public function operationsFor(string $childId): array
+    {
+        $operations = [];
+        foreach ($this->operations as $entry) {
+            if ($entry['child'] === $childId) {
+                $operations[] = $entry['op'];
+            }
+        }
+
+        return $operations;
+    }
+
+    /** poll 記録が a と b を行き来した回数 (単一ループなら大きく、逐次処理なら 1 になる) */
+    public function pollAlternations(): int
+    {
+        $alternations = 0;
+        for ($i = 1; $i < count($this->polls); $i++) {
+            if ($this->polls[$i] !== $this->polls[$i - 1]) {
+                $alternations++;
+            }
+        }
+
+        return $alternations;
+    }
+}
+
+/**
+ * 台本で動く偽の子。
+ *
+ * ★台本は `isRunning()` の呼び出しごとに 1 歩進む。runner は待機ループの中断条件で
+ *   毎周回 `isRunning()` を呼ぶので、これが「子が動いた」ことの決定的な差し込み点になる
+ *   (実時間に依存しないので締切の検査が安定する)。
+ */
+final class ScriptedProbeProcess implements ProbeProcess
+{
+    public int $step = 0;
+
+    /** @var array<string, mixed> 台本が使う状態 */
+    public array $bag = [];
+
+    private bool $started = false;
+
+    private bool $stopped = false;
+
+    private int $finishedExitCode = 0;
+
+    private string $stdout = '';
+
+    /** @param Closure(self): void $script */
+    public function __construct(
+        public readonly ProbeLaunchSpec $spec,
+        private readonly Closure $script,
+        private readonly HarnessCallLog $log,
+        private readonly bool $ignoreTerminate = false,
+        private readonly bool $ignoreKill = false,
+        private readonly bool $exitImmediately = false,
+        private readonly string $stderr = '',
+    ) {}
+
+    public function barrier(): ProcessBarrier
+    {
+        return new ProcessBarrier($this->spec->workspaceDirectory);
+    }
+
+    /** 台本の終わり: out 合図を置き、stdout を確定して停止する */
+    public function finish(string $outJson, ?string $stdout = null, int $exitCode = 0): void
+    {
+        $this->barrier()->signal(SignalName::make('out', $this->spec->childId), $outJson);
+        $this->stdout = $stdout ?? $outJson;
+        $this->finishedExitCode = $exitCode;
+        $this->stopped = true;
+    }
+
+    public function start(): void
+    {
+        $this->started = true;
+
+        if ($this->exitImmediately) {
+            $this->stopped = true;
+            $this->finishedExitCode = 1;
+        }
+    }
+
+    public function isRunning(): bool
+    {
+        $this->log->poll($this->spec->childId);
+
+        if (! $this->started || $this->stopped) {
+            return false;
+        }
+
+        ($this->script)($this);
+
+        return ! $this->stopped;
+    }
+
+    public function exitCode(): ?int
+    {
+        return $this->stopped ? $this->finishedExitCode : null;
+    }
+
+    public function output(): string
+    {
+        return $this->stdout;
+    }
+
+    public function errorOutput(): string
+    {
+        return $this->stderr;
+    }
+
+    public function signalTerminate(): void
+    {
+        // ★回収の入口で「その時点で go / release が置かれていたか」を記録する。
+        //   workspace は回収の最後に消えるので、ここでしか観測できない。
+        $this->bag['go_at_terminate'] = harnessSignalExists($this->spec, 'go');
+        $this->bag['release_at_terminate'] = harnessSignalExists($this->spec, 'release');
+
+        $this->log->record($this->spec->childId, 'terminate');
+        $this->log->resetPolls();
+
+        if (! $this->ignoreTerminate) {
+            $this->stopped = true;
+        }
+    }
+
+    public function signalKill(): void
+    {
+        $this->log->record($this->spec->childId, 'kill');
+
+        if (! $this->ignoreKill) {
+            $this->stopped = true;
+        }
+    }
+
+    public function waitFor(float $seconds): ?int
+    {
+        Assert::greaterThanEq($seconds, 0.0);
+        $this->log->record($this->spec->childId, 'waitFor');
+
+        return $this->exitCode();
+    }
+}
+
+/** 偽の子を作る (作った子を child ID で引けるようにしておく) */
+final class ScriptedProbeProcessFactory implements ProbeProcessFactory
+{
+    /** @var array<string, ScriptedProbeProcess> */
+    public array $processes = [];
+
+    /** @param Closure(ProbeLaunchSpec, HarnessCallLog): ScriptedProbeProcess $make */
+    public function __construct(
+        private readonly Closure $make,
+        public readonly HarnessCallLog $log = new HarnessCallLog,
+    ) {}
+
+    public function create(ProbeLaunchSpec $spec): ProbeProcess
+    {
+        $process = ($this->make)($spec, $this->log);
+        $this->processes[$spec->childId] = $process;
+
+        return $process;
+    }
+
+    public function workspaceDirectory(): string
+    {
+        foreach ($this->processes as $process) {
+            return $process->spec->workspaceDirectory;
+        }
+
+        throw new RuntimeException('偽の子がまだ 1 本も作られていない');
+    }
+}
+
+// ─────────────────────────────────────────────────────────────────────────────
+// 検査用のちいさな道具
+// ─────────────────────────────────────────────────────────────────────────────
+
+function harnessWorkspace(): string
+{
+    $directory = sys_get_temp_dir().'/harness-'.bin2hex(random_bytes(8));
+    Assert::true(mkdir($directory, 0700));
+    chmod($directory, 0700);
+    ProcessBarrier::prepareWorkspace($directory);
+
+    return $directory;
+}
+
+function harnessRemoveDirectory(string $directory): void
+{
+    if (! is_dir($directory)) {
+        return;
+    }
+
+    foreach (scandir($directory) ?: [] as $entry) {
+        if ($entry === '.' || $entry === '..') {
+            continue;
+        }
+
+        $path = $directory.'/'.$entry;
+        if (is_dir($path)) {
+            harnessRemoveDirectory($path);
+
+            continue;
+        }
+
+        @unlink($path);
+    }
+
+    @rmdir($directory);
+}
+
+function harnessSignalExists(ProbeLaunchSpec $spec, string $name): bool
+{
+    $path = $spec->workspaceDirectory.'/signals/'.$name;
+    clearstatcache(true, $path);
+
+    return is_file($path);
+}
+
+function harnessSignalContents(ProbeLaunchSpec $spec, string $name): string
+{
+    return harnessSignalContentsAt($spec->workspaceDirectory, $name);
+}
+
+function harnessSignalContentsAt(string $workspace, string $name): string
+{
+    return (string) file_get_contents($workspace.'/signals/'.$name);
+}
+
+/** @return array<string, mixed> */
+function harnessInput(ProbeLaunchSpec $spec): array
+{
+    $decoded = json_decode((string) file_get_contents($spec->inputFilePath()), true);
+    Assert::isArray($decoded);
+
+    return $decoded;
+}
+
+/**
+ * 子が返す観測 JSON を組み立てる (親の受理条件をすべて満たす正例)。
+ *
+ * @param  array<string, mixed>  $overrides
+ */
+function harnessObservation(ProbeLaunchSpec $spec, string $goToken, bool $winner, array $overrides = []): string
+{
+    $input = harnessInput($spec);
+    $uri = (string) $input['uri'];
+    $rawBody = (string) $input['raw_body'];
+
+    $values = [
+        'child_id' => $spec->childId,
+        'nonce' => $spec->nonce,
+        'go_token' => $goToken,
+        'http_status' => $winner ? 201 : 409,
+        'error_code' => $winner ? null : ApiErrorCode::IdempotencyInProgress->value,
+        'handler_executions' => $winner ? 1 : 0,
+        'entered_handler' => $winner,
+        'route_name' => (string) $input['route_name'],
+        'uri' => $uri,
+        'request_hash' => hash('sha256', 'POST|'.$uri.'|'.$rawBody),
+        'api_key_id' => 4242,
+        'cache_default' => 'array',
+        'cache_store_driver' => 'array',
+        ...ProbeDatabaseCoordinates::fromParentConfig()->toObservationValues(),
+    ];
+
+    return json_encode([...$values, ...$overrides], JSON_THROW_ON_ERROR);
+}
+
+/**
+ * 正常なプロトコルを演じる台本。
+ *
+ * @param  array<string, mixed>  $observationOverrides
+ * @return Closure(ScriptedProbeProcess): void
+ */
+function harnessProtocolScript(
+    string $winnerId,
+    array $observationOverrides = [],
+    ?string $stdoutOverride = null,
+    int $exitCode = 0,
+): Closure {
+    return static function (ScriptedProbeProcess $process) use (
+        $winnerId,
+        $observationOverrides,
+        $stdoutOverride,
+        $exitCode,
+    ): void {
+        $spec = $process->spec;
+        $isWinner = $spec->childId === $winnerId;
+
+        if ($process->step === 0) {
+            // ★ready を出す時点で go が**まだ無い**ことを記録する
+            //   (go token が ready の検証より前に作られていないことの裏取り)。
+            $process->bag['go_existed_at_ready'] = harnessSignalExists($spec, 'go');
+            // 入力ファイルは回収で消えるので、読んだ内容をここで控える
+            $process->bag['input'] = harnessInput($spec);
+            $process->barrier()->signal(SignalName::make('ready', $spec->childId), $spec->nonce);
+            $process->step = 1;
+
+            return;
+        }
+
+        if ($process->step === 1) {
+            if (! harnessSignalExists($spec, 'go')) {
+                return;
+            }
+
+            $process->bag['go_token'] = harnessSignalContents($spec, 'go');
+            $process->step = $isWinner ? 2 : 3;
+
+            return;
+        }
+
+        $goToken = (string) $process->bag['go_token'];
+
+        if ($process->step === 2) {
+            $process->barrier()->signal(
+                SignalName::make('entered', $spec->childId),
+                $spec->nonce.':'.$goToken,
+            );
+            $process->step = 4;
+
+            return;
+        }
+
+        if ($process->step === 3) {
+            $process->finish(
+                harnessObservation($spec, $goToken, winner: false, overrides: $observationOverrides),
+                exitCode: $exitCode,
+            );
+
+            return;
+        }
+
+        if ($process->step === 4 && harnessSignalExists($spec, 'release')) {
+            $json = harnessObservation($spec, $goToken, winner: true, overrides: $observationOverrides);
+            $process->finish($json, stdout: $stdoutOverride, exitCode: $exitCode);
+        }
+    };
+}
+
+/**
+ * 偽 factory を差して runner を走らせる。
+ *
+ * @param  array<string, mixed>  $requestBody
+ */
+function harnessRun(
+    ScriptedProbeProcessFactory $factory,
+    float $timeoutSeconds = 5.0,
+    array $requestBody = ['title' => '並行 claim の検体'],
+    string $plainApiKey = 'harness-plain-key',
+): ConcurrentProbeResult {
+    return ConcurrencyProbeRunner::run(
+        idempotencyKey: 'harness-'.bin2hex(random_bytes(6)),
+        plainApiKey: $plainApiKey,
+        requestBody: $requestBody,
+        timeoutSeconds: $timeoutSeconds,
+        factory: $factory,
+    );
+}
+
+/** 例外の連鎖 (previous を含む) の全文 */
+function harnessThrowableText(?Throwable $e): string
+{
+    $text = '';
+    while ($e instanceof Throwable) {
+        $text .= $e::class.': '.$e->getMessage()."\n";
+        $e = $e->getPrevious();
+    }
+
+    return $text;
+}
+
+// ─────────────────────────────────────────────────────────────────────────────
+// 群 1: ProcessBarrier (合図)
+// ─────────────────────────────────────────────────────────────────────────────
+
+test('群1-1: 現れない合図を待ち続けず締切で例外になる', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        $barrier = new ProcessBarrier($workspace);
+
+        expect(fn () => $barrier->await(SignalName::make('go'), 0.05))
+            ->toThrow(BarrierTimeoutException::class);
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群1-2: 合図はあるのに読めないときは空として通さず落ちる', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        // ★偽の読み手が決定的に false を返す (chmod 000 は root 実行で不安定なので使わない)。
+        $barrier = new ProcessBarrier($workspace, static fn (string $path): string|false => false);
+        $barrier->signal(SignalName::make('go'), 'token');
+
+        expect(fn () => $barrier->await(SignalName::make('go'), 1.0))
+            ->toThrow(ConcurrencyProtocolException::class, '在るのに読めない');
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群1-3: 中断条件が成立したら締切を待たずに抜ける', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        $barrier = new ProcessBarrier($workspace);
+        $startedAt = hrtime(true);
+
+        expect(fn () => $barrier->await(
+            SignalName::make('go'),
+            30.0,
+            static function (): void {
+                throw new RuntimeException('中断条件が成立した');
+            },
+        ))->toThrow(RuntimeException::class, '中断条件が成立した');
+
+        expect((hrtime(true) - $startedAt) / 1_000_000_000)->toBeLessThan(5.0);
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群1-4: 書きかけ (partial/) を完成した合図として扱わない', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        file_put_contents($workspace.'/partial/'.bin2hex(random_bytes(8)), 'まだ書きかけ');
+
+        $barrier = new ProcessBarrier($workspace);
+        expect($barrier->present(SignalName::all()))->toBe([]);
+        expect(fn () => $barrier->await(SignalName::make('go'), 0.05))
+            ->toThrow(BarrierTimeoutException::class);
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群1-5: 未知の完成ファイルが置かれたら列挙時に拒否する (無視しない)', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        file_put_contents($workspace.'/signals/entered-c', 'unknown');
+
+        $barrier = new ProcessBarrier($workspace);
+        expect(fn () => $barrier->present(SignalName::all()))
+            ->toThrow(ConcurrencyProtocolException::class, 'entered-c');
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群1-6: global 種別に child ID を付けた合図名は作れない', function (): void {
+    expect(fn () => SignalName::make('go', 'a'))->toThrow(InvalidArgumentException::class);
+    expect(fn () => SignalName::make('release', 'b'))->toThrow(InvalidArgumentException::class);
+});
+
+test('群1-7: child ID 無しの ready / entered / out は作れない', function (): void {
+    foreach (SignalName::PER_CHILD_KINDS as $kind) {
+        expect(fn () => SignalName::make($kind))->toThrow(InvalidArgumentException::class);
+    }
+});
+
+test('群1-8: 実在しない child ID (ready-c / パス片) は作れない — 生成できるのは 8 通りだけ', function (): void {
+    expect(fn () => SignalName::make('ready', 'c'))->toThrow(InvalidArgumentException::class);
+    expect(fn () => SignalName::make('ready', '../outside'))->toThrow(InvalidArgumentException::class);
+    expect(fn () => SignalName::make('ready', 'a/b'))->toThrow(InvalidArgumentException::class);
+    expect(fn () => SignalName::make('unknown-kind', 'a'))->toThrow(InvalidArgumentException::class);
+
+    $values = array_map(static fn (SignalName $name): string => $name->value, SignalName::all());
+    sort($values);
+    expect($values)->toBe([
+        'entered-a', 'entered-b', 'go', 'out-a', 'out-b', 'ready-a', 'ready-b', 'release',
+    ]);
+});
+
+test('群1-9: 同じ合図を 2 回置こうとすると二重送信として失敗する', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        $barrier = new ProcessBarrier($workspace);
+        $barrier->signal(SignalName::make('ready', 'a'), 'nonce-1');
+
+        expect(fn () => $barrier->signal(SignalName::make('ready', 'a'), 'nonce-2'))
+            ->toThrow(ConcurrencyProtocolException::class, '2 回置こうとした');
+
+        // 上書きされていない (最初の中身が残る)
+        expect(harnessSignalContentsAt($workspace, 'ready-a'))->toBe('nonce-1');
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群1-10: target が不在のままの配置失敗は二重送信と誤分類しない', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        // ★ProcessBarrier の構築は signals/ の実在を要求するので、**構築後に**消す。
+        //   これで target が不在のまま配置だけが失敗する形を作れる。
+        $barrier = new ProcessBarrier($workspace);
+        harnessRemoveDirectory($workspace.'/signals');
+
+        expect(fn () => $barrier->signal(SignalName::make('go'), 'token'))
+            ->toThrow(ConcurrencyProtocolException::class, '配置できなかった');
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+// ─────────────────────────────────────────────────────────────────────────────
+// 群 2: ProbeEnvironment (遮断)
+// ─────────────────────────────────────────────────────────────────────────────
+
+test('群2-9: DB_URL が非空なら子を起こさない', function (): void {
+    config(['database.connections.pgsql.url' => 'pgsql://user:pass@db:5432/other']);
+
+    expect(fn () => ProbeEnvironment::envFileValues())
+        ->toThrow(RuntimeException::class, '個別キー接続のレーンを前提にする');
+});
+
+test('群2-10: dev DB 名なら子を起こさない (単一点ガードを親側でも通す)', function (): void {
+    config(['database.connections.pgsql.database' => 'app']);
+
+    expect(fn () => ProbeEnvironment::envFileValues())
+        ->toThrow(InvalidArgumentException::class);
+});
+
+test('群2-11: 許可キー以外を env ファイルへ書かない', function (): void {
+    expect(fn () => ProbeEnvironment::assertEnvFileKeys(['APP_ENV' => 'testing', 'AWS_SECRET_ACCESS_KEY' => 'x']))
+        ->toThrow(InvalidArgumentException::class);
+
+    // ★必須キーの**欠落**も落とす (穴は子の .env 読み込みで埋まりうる = まさに塞ぎたい形)
+    expect(fn () => ProbeEnvironment::assertEnvFileKeys(['APP_ENV' => 'testing']))
+        ->toThrow(InvalidArgumentException::class);
+});
+
+test('群2-12: env 値に改行 / CR があれば書かずに例外 (キー注入の拒否)', function (): void {
+    expect(fn () => ProbeEnvironment::assertNoLineInjection(['DB_PASSWORD' => "pass\nDB_DATABASE=app"]))
+        ->toThrow(RuntimeException::class, '改行を含むキーは書けない');
+
+    expect(fn () => ProbeEnvironment::assertNoLineInjection(['DB_PASSWORD' => "pass\rDB_DATABASE=app"]))
+        ->toThrow(RuntimeException::class, '改行を含むキーは書けない');
+
+    // 正規入力を誤検出しない
+    ProbeEnvironment::assertNoLineInjection(['DB_PASSWORD' => 'p a$s#s"\\']);
+});
+
+test('群2-13: encodeLine の往復は自前パーサと phpdotenv の双方で元の値に戻る', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        // ★`$` / `${NAME}` は二重引用符の中で変数展開されうるので、自前パーサとの往復だけでは
+        //   「phpdotenv が同じ値として読む」ことは言えない。**双方**に通して固定する。
+        $values = [
+            'APP_ENV' => '',
+            'APP_KEY' => 'back\\slash',
+            'APP_URL' => 'quote"inside',
+            'APP_DEBUG' => 'hash#inside',
+            'CIPHERSWEET_KEY' => '  spaced  ',
+            'BCRYPT_ROUNDS' => 'dollar$sign',
+            'DB_PASSWORD' => 'brace${NAME}brace',
+        ];
+
+        $lines = '';
+        foreach ($values as $key => $value) {
+            $lines .= ProbeEnvironment::encodeLine($key, $value);
+        }
+        file_put_contents($workspace.'/.env.roundtrip', $lines);
+
+        expect(ProbeEnvironment::parseEnvFile($workspace.'/.env.roundtrip'))->toBe($values);
+
+        // プロジェクトが実際に使っている phpdotenv の parser でも同じ値になる
+        $loaded = Dotenv::createArrayBacked($workspace, '.env.roundtrip')->load();
+        foreach ($values as $key => $value) {
+            expect($loaded[$key] ?? null)->toBe($value);
+        }
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群2-13b: 厳格パーサは encoder が作れない形を 1 つも受理しない', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        // ★encoder が作る escape は `\\` / `\"` / `\$` の 3 種だけである。
+        //   未知 escape を受理してバックスラッシュを落とす形は
+        //   「唯一の書式だけを受理し phpdotenv と同じ規則で復号する」という宣言と食い違う。
+        $rejected = [
+            'unknown-escape' => 'FOO="a\\qb"'."\n",
+            // 素の `$` は encoder が必ず escape するので canonical には現れない。
+            // 受理すると phpdotenv 側の変数展開と実効値が食い違う。
+            'bare-dollar' => 'FOO="a${NAME}b"'."\n",
+            'duplicate-key' => 'FOO="a"'."\n".'FOO="b"'."\n",
+            'unquoted-value' => 'FOO=bar'."\n",
+            'lowercase-key' => 'foo="bar"'."\n",
+            'unterminated-quote' => 'FOO="bar'."\n",
+            'trailing-garbage' => 'FOO="bar" # comment'."\n",
+        ];
+
+        foreach ($rejected as $label => $contents) {
+            $path = $workspace.'/.env.'.$label;
+            file_put_contents($path, $contents);
+
+            expect(fn () => ProbeEnvironment::parseEnvFile($path))
+                ->toThrow(RuntimeException::class);
+        }
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群2-14: 0700 / 0600 以外の権限では子を起こさない', function (): void {
+    expect(fn () => ProbeEnvironment::assertSafePermissions(0755, 0600, 0600))
+        ->toThrow(RuntimeException::class, '子プロセスを起こさない');
+    expect(fn () => ProbeEnvironment::assertSafePermissions(0700, 0644, 0600))
+        ->toThrow(RuntimeException::class, '子プロセスを起こさない');
+    expect(fn () => ProbeEnvironment::assertSafePermissions(0700, 0600, 0644))
+        ->toThrow(RuntimeException::class, '子プロセスを起こさない');
+
+    ProbeEnvironment::assertSafePermissions(0700, 0600, 0600);
+});
+
+test('群2-15: 保護ファイルは作成時点で 0600 で、既存ファイルがあれば作らない', function (): void {
+    $workspace = harnessWorkspace();
+
+    try {
+        $path = $workspace.'/secret.json';
+        ProbeEnvironment::writeProtectedFile($path, '{"secret":true}');
+
+        expect(ProbeEnvironment::mode($path))->toBe(0600);
+        expect(file_get_contents($path))->toBe('{"secret":true}');
+
+        expect(fn () => ProbeEnvironment::writeProtectedFile($path, 'x'))
+            ->toThrow(RuntimeException::class, '子へ渡すファイルを作れない');
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群2-16: 未知の DB_* / APP_* がプロセス環境に混入していたら拒否する (env -i の退行)', function (): void {
+    expect(fn () => ProbeEnvironment::assertProcessEnvironmentKeys([
+        ...ProbeEnvironment::ALLOWED_PROCESS_ENV_KEYS,
+        'DB_URL',
+    ]))->toThrow(RuntimeException::class, 'env -i の退行');
+
+    expect(fn () => ProbeEnvironment::assertProcessEnvironmentKeys([
+        ...ProbeEnvironment::ALLOWED_PROCESS_ENV_KEYS,
+        'APP_KEY',
+    ]))->toThrow(RuntimeException::class, 'env -i の退行');
+
+    // 欠落も落とす (載せ忘れは設定の出所を欠く)
+    expect(fn () => ProbeEnvironment::assertProcessEnvironmentKeys(['CONCURRENCY_PROBE_ENV_DIR']))
+        ->toThrow(RuntimeException::class, 'env -i の退行');
+
+    ProbeEnvironment::assertProcessEnvironmentKeys(array_reverse(ProbeEnvironment::ALLOWED_PROCESS_ENV_KEYS));
+});
+
+// ─────────────────────────────────────────────────────────────────────────────
+// 群 3: ConcurrentProbeObservation (観測の型)
+// ─────────────────────────────────────────────────────────────────────────────
+
+/**
+ * 受理条件をすべて満たす観測 (群 3 の基準値)。
+ *
+ * @param  array<string, mixed>  $overrides
+ * @return array<string, mixed>
+ */
+function harnessObservationArray(array $overrides = []): array
+{
+    return [
+        'child_id' => 'a',
+        'nonce' => 'nonce-a',
+        'go_token' => 'go-token',
+        'http_status' => 409,
+        'error_code' => ApiErrorCode::IdempotencyInProgress->value,
+        'handler_executions' => 0,
+        'entered_handler' => false,
+        'route_name' => 'api.v1.__probe__',
+        'uri' => 'api/v1/__probe__',
+        'request_hash' => str_repeat('0', 64),
+        'api_key_id' => 7,
+        'cache_default' => 'array',
+        'cache_store_driver' => 'array',
+        'db_driver' => 'pgsql',
+        'db_host' => '127.0.0.1',
+        'db_port' => 5432,
+        'db_database' => 'app_test_deadbeef',
+        'db_username' => 'app',
+        'db_charset' => 'utf8',
+        'db_sslmode' => 'prefer',
+        'db_url' => '',
+        ...$overrides,
+    ];
+}
+
+test('群3-17: 必須キー欠落 / 未知キー / 型違いを通さない (キャストで救わない)', function (): void {
+    $missing = harnessObservationArray();
+    unset($missing['nonce']);
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson($missing))
+        ->toThrow(ConcurrencyProtocolException::class, 'キー集合が一致しない');
+
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['unexpected_key' => 1])
+    ))->toThrow(ConcurrencyProtocolException::class, 'キー集合が一致しない');
+
+    // ★"409" のような数値文字列はキャストで救わない
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['http_status' => '409'])
+    ))->toThrow(ConcurrencyProtocolException::class, 'http_status が整数でない');
+
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['db_port' => '5432'])
+    ))->toThrow(ConcurrencyProtocolException::class, 'db_port が整数でない');
+
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['entered_handler' => 0])
+    ))->toThrow(ConcurrencyProtocolException::class, 'entered_handler が真偽値でない');
+
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson('文字列'))
+        ->toThrow(ConcurrencyProtocolException::class, '観測が配列でない');
+
+    // 正例は通る (拒否だけでなく誤検出しないことも固定する)
+    expect(ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray())->childId)->toBe('a');
+});
+
+test('群3-18: error_code が空文字なら通さない (勝者は null / 敗者は非空)', function (): void {
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['error_code' => ''])
+    ))->toThrow(ConcurrencyProtocolException::class, 'error_code は null か非空文字列');
+
+    $winner = ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray([
+        'error_code' => null,
+        'http_status' => 201,
+        'handler_executions' => 1,
+        'entered_handler' => true,
+    ]));
+    expect($winner->errorCode)->toBeNull();
+});
+
+test('群3-19: entered_handler と handler_executions の矛盾を通さない', function (): void {
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['entered_handler' => true, 'handler_executions' => 0])
+    ))->toThrow(ConcurrencyProtocolException::class, 'handler_executions が 0');
+
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['entered_handler' => false, 'handler_executions' => 1])
+    ))->toThrow(ConcurrencyProtocolException::class, 'handler_executions が 0 でない');
+});
+
+test('群3-20: assertIdentity は childId / nonce / go token の不一致を通さない', function (): void {
+    $observation = ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray());
+
+    expect(fn () => $observation->assertIdentity('b', 'nonce-a', 'go-token'))
+        ->toThrow(ConcurrencyProtocolException::class, 'child_id');
+    expect(fn () => $observation->assertIdentity('a', 'nonce-b', 'go-token'))
+        ->toThrow(ConcurrencyProtocolException::class, 'nonce');
+    expect(fn () => $observation->assertIdentity('a', 'nonce-a', 'another-token'))
+        ->toThrow(ConcurrencyProtocolException::class, 'go token が一致しない');
+
+    $observation->assertIdentity('a', 'nonce-a', 'go-token');
+});
+
+test('群3-21: assertLost は idempotency_conflict / indeterminate を通さない', function (): void {
+    foreach ([ApiErrorCode::IdempotencyConflict, ApiErrorCode::IdempotencyIndeterminate] as $code) {
+        $observation = ConcurrentProbeObservation::fromDecodedJson(
+            harnessObservationArray(['error_code' => $code->value])
+        );
+
+        expect(fn () => $observation->assertLost(str_repeat('0', 64)))
+            ->toThrow(ConcurrencyProtocolException::class, 'error_code');
+    }
+
+    // 409 以外も通さない
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['http_status' => 500])
+    )->assertLost(str_repeat('0', 64)))
+        ->toThrow(ConcurrencyProtocolException::class, '409 でない');
+
+    ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray())
+        ->assertLost(str_repeat('0', 64));
+});
+
+test('群3-22: assertLost は request_hash の不一致を通さない', function (): void {
+    $observation = ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray());
+
+    expect(fn () => $observation->assertLost(str_repeat('f', 64)))
+        ->toThrow(ConcurrencyProtocolException::class, 'request_hash');
+});
+
+test('群3-23: assertDatabaseCoordinates は host / port / username 違いと db_url 非空を通さない', function (): void {
+    $expected = new ProbeDatabaseCoordinates(
+        driver: 'pgsql',
+        host: '127.0.0.1',
+        port: 5432,
+        database: 'app_test_deadbeef',
+        username: 'app',
+        charset: 'utf8',
+        sslmode: 'prefer',
+        url: '',
+    );
+
+    ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray())
+        ->assertDatabaseCoordinates($expected);
+
+    foreach ([
+        ['db_host' => '10.0.0.1'],
+        ['db_port' => 15432],
+        ['db_username' => 'postgres'],
+        ['db_database' => 'app'],
+        ['db_charset' => 'utf8mb4'],
+        ['db_sslmode' => 'disable'],
+    ] as $override) {
+        expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+            harnessObservationArray($override)
+        )->assertDatabaseCoordinates($expected))
+            ->toThrow(ConcurrencyProtocolException::class, '実効 DB 座標が親と一致しない');
+    }
+
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['db_url' => 'pgsql://db/other'])
+    ))->toThrow(ConcurrencyProtocolException::class, 'db_url が非空');
+});
+
+test('群3-23b: assertAppLocksDisabled は store 名と裏打ち driver の両方を見る', function (): void {
+    // 正例 (両方 array) は通る
+    ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray())->assertAppLocksDisabled();
+
+    // ★2 つの負例は**独立**でなければならない。片方だけの検査に退行しても
+    //   もう片方の負例が赤くなる = 「両方を見る」という判断がテストで固定される。
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['cache_default' => 'redis'])
+    )->assertAppLocksDisabled())
+        ->toThrow(ConcurrencyProtocolException::class, 'array に固定できていない');
+
+    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
+        harnessObservationArray(['cache_store_driver' => 'redis'])
+    )->assertAppLocksDisabled())
+        ->toThrow(ConcurrencyProtocolException::class, 'array に固定できていない');
+});
+
+// ─────────────────────────────────────────────────────────────────────────────
+// 群 4: ConcurrencyProbeRunner (調停と回収)
+// ─────────────────────────────────────────────────────────────────────────────
+
+test('群4-25: 正常系 — go token は ready 検証の後に生成され、事前に子へ渡らない', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript('a'), $log),
+    );
+
+    $result = harnessRun($factory);
+
+    expect(array_keys($result->observations))->toHaveCount(2);
+    [$winner, $loser] = $result->partition();
+    expect($winner->enteredHandler)->toBeTrue();
+    expect($loser->errorCode)->toBe(ApiErrorCode::IdempotencyInProgress->value);
+
+    foreach ($factory->processes as $process) {
+        // ★ready を書いた時点で go は存在しなかった (= 検証の後に作られている)
+        expect($process->bag['go_existed_at_ready'])->toBeFalse();
+        // ★入力ファイルにも go token は入っていない (読まずに正しい値は書けない)
+        expect(array_keys(harnessInputSnapshot($process)))->not->toContain('go_token');
+    }
+});
+
+/**
+ * 入力ファイルは回収で消えるので、台本が読んだ内容を控えておく。
+ *
+ * @return array<string, mixed>
+ */
+function harnessInputSnapshot(ScriptedProbeProcess $process): array
+{
+    $snapshot = $process->bag['input'] ?? null;
+    Assert::isArray($snapshot);
+
+    return $snapshot;
+}
+
+test('群4-24: ready の nonce が割り当てと違えば go を出さない', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, static function (ScriptedProbeProcess $process): void {
+            if ($process->step !== 0) {
+                return;
+            }
+            $process->barrier()->signal(
+                SignalName::make('ready', $process->spec->childId),
+                'すり替えられた nonce',
+            );
+            $process->step = 1;
+        }, $log),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 2.0))
+        ->toThrow(ConcurrencyProtocolException::class, 'ready の nonce');
+
+    // 回収の入口 (TERM) の時点で go は 1 度も置かれていない
+    foreach ($factory->processes as $process) {
+        expect($process->bag['go_at_terminate'] ?? null)->toBeFalse();
+    }
+});
+
+test('群4-26: entered が 2 つ出たら締切を待たず二重実行として落ちる', function (): void {
+    // ★両方が勝者を演じる = 探している退行そのもの
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript($spec->childId), $log),
+    );
+
+    $startedAt = hrtime(true);
+    expect(fn () => harnessRun($factory, timeoutSeconds: 20.0))
+        ->toThrow(ConcurrencyProtocolException::class, '二重実行を検出');
+
+    // 締切 (20 秒) を待たずに抜ける
+    expect((hrtime(true) - $startedAt) / 1_000_000_000)->toBeLessThan(5.0);
+});
+
+test('群4-27: 未知 child ID の entered が現れたら拒否する', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, static function (ScriptedProbeProcess $process): void {
+            if ($process->step !== 0) {
+                return;
+            }
+            file_put_contents($process->spec->workspaceDirectory.'/signals/entered-c', 'unknown');
+            $process->step = 1;
+        }, $log),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 2.0))
+        ->toThrow(ConcurrencyProtocolException::class, 'entered-c');
+});
+
+test('群4-28: 子が観測を出さずに終わったら観測なしのまま通さない', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
+            $spec,
+            static function (ScriptedProbeProcess $process): void {},
+            $log,
+            exitImmediately: true,
+            stderr: 'fatal: 設定の出所が壊れている',
+        ),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 2.0))
+        ->toThrow(ConcurrencyProtocolException::class, '観測を出さずに終了した');
+});
+
+test('群4-29: 敗者の out が検査を通らなければ release を置かない', function (): void {
+    // ★body 違いの conflict は「勝者 1 / 敗者 1」まで成立して**緑になりうる**形である
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript('a', [
+            'error_code' => ApiErrorCode::IdempotencyConflict->value,
+        ]), $log),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 3.0))
+        ->toThrow(ConcurrencyProtocolException::class, 'error_code');
+
+    // 勝者 (a) は release を待ったまま回収された = release は置かれていない
+    expect($factory->processes['a']->bag['release_at_terminate'] ?? null)->toBeFalse();
+});
+
+test('群4-30: stdout の JSON と out ファイルが不一致なら通さない', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
+            $spec,
+            harnessProtocolScript('a', stdoutOverride: '{"child_id":"a"}'),
+            $log,
+        ),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 3.0))
+        ->toThrow(ConcurrencyProtocolException::class, 'stdout と out ファイルの中身が一致しない');
+});
+
+test('群4-31: exit code が非ゼロなら通さない', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript('a', exitCode: 3), $log),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 3.0))
+        ->toThrow(ConcurrencyProtocolException::class, '終了コードが 0 でない');
+});
+
+test('群4-32: 勝者・敗者が 1:1 に分かれないなら通さない', function (): void {
+    // 勝者側も entered_handler=false と申告する (行だけを見ると気付けない形)
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript('a', [
+            'entered_handler' => false,
+            'handler_executions' => 0,
+            'http_status' => 409,
+            'error_code' => ApiErrorCode::IdempotencyInProgress->value,
+        ]), $log),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 3.0))
+        ->toThrow(ConcurrencyProtocolException::class, '1:1 に分かれない');
+});
+
+test('群4-33: 作業の締切は段ごとに更新されない (3 段待っても総時間が締切を超えない)', function (): void {
+    // ★ready-a を 0.5 秒後、ready-b を 0.9 秒後に出し、entered は永久に出さない。
+    //   単一の絶対 deadline (1.0 秒) なら**合計 1.0 秒**で打ち切られる。
+    //   段ごとに締切を更新する実装だと 0.5 + 0.4 + 1.0 = 1.9 秒かかる。
+    $factory = new ScriptedProbeProcessFactory(
+        static function (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess {
+            $delay = $spec->childId === 'a' ? 0.5 : 0.9;
+
+            return new ScriptedProbeProcess($spec, static function (ScriptedProbeProcess $process) use ($delay): void {
+                $process->bag['started_at'] ??= hrtime(true);
+                if ($process->step !== 0) {
+                    return;
+                }
+                if ((hrtime(true) - $process->bag['started_at']) / 1_000_000_000 < $delay) {
+                    return;
+                }
+                $process->barrier()->signal(SignalName::make('ready', $process->spec->childId), $process->spec->nonce);
+                $process->step = 1;
+            }, $log);
+        },
+    );
+
+    $startedAt = hrtime(true);
+    expect(fn () => harnessRun($factory, timeoutSeconds: 1.0))
+        ->toThrow(BarrierTimeoutException::class);
+
+    expect((hrtime(true) - $startedAt) / 1_000_000_000)->toBeLessThan(1.5);
+});
+
+test('群4-34/35: 応答しない子へ TERM → 待機 → KILL → 待機 が順に要求される (締切を使い切った後でも)', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
+            $spec,
+            static function (ScriptedProbeProcess $process): void {},
+            $log,
+            ignoreTerminate: true,
+            ignoreKill: true,
+        ),
+    );
+
+    // 作業の締切をほぼ 0 にしても、回収専用の予算で回収操作は要求される
+    expect(fn () => harnessRun($factory, timeoutSeconds: 0.05))
+        ->toThrow(ConcurrencyProtocolException::class, '停止を確認できない子が残っている');
+
+    foreach (ConcurrencyProbeRunner::CHILD_IDS as $childId) {
+        expect($factory->log->operationsFor($childId))->toBe(['terminate', 'waitFor', 'kill', 'waitFor']);
+    }
+
+    harnessRemoveDirectory($factory->workspaceDirectory());
+});
+
+test('群4-36: 混在ケース — TERM は両方へ / KILL は残った子だけへ / 予算内に収まる', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
+            $spec,
+            static function (ScriptedProbeProcess $process): void {},
+            $log,
+            // b だけ TERM を無視する (KILL では止まる)
+            ignoreTerminate: $spec->childId === 'b',
+        ),
+    );
+
+    $startedAt = hrtime(true);
+    expect(fn () => harnessRun($factory, timeoutSeconds: 0.05))
+        ->toThrow(BarrierTimeoutException::class);
+    $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;
+
+    expect($factory->log->operationsFor('a'))->toBe(['terminate', 'waitFor']);
+    expect($factory->log->operationsFor('b'))->toBe(['terminate', 'waitFor', 'kill', 'waitFor']);
+
+    // ★子単位の逐次処理だと 1 子目で予算を使い切って 2 子目の回収時間が残らない。
+    //   フェーズ単位なら子数にかかわらず予算内に収まる。
+    expect($elapsed)->toBeLessThan(0.05 + ConcurrencyProbeRunner::REAP_BUDGET_SECONDS);
+});
+
+test('群4-37/38/39/41: 停止を確認できない子が残ったら workspace を残し、秘密だけ消す', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
+            $spec,
+            static function (ScriptedProbeProcess $process): void {},
+            $log,
+            ignoreTerminate: true,
+            ignoreKill: true,
+        ),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 0.05))
+        ->toThrow(ConcurrencyProtocolException::class, '停止を確認できない子が残っている');
+
+    $workspace = $factory->workspaceDirectory();
+
+    try {
+        // 37: workspace を削除していない (まだ動いている子が削除済みパスへ書くのを防ぐ)
+        expect(is_dir($workspace))->toBeTrue();
+
+        // 38: 秘密 (env ファイル / 入力ファイル) は回収の成否にかかわらず消えている
+        expect(file_exists($workspace.'/'.ProbeEnvironment::ENV_FILE_NAME))->toBeFalse();
+        foreach ($factory->processes as $process) {
+            expect(file_exists($process->spec->inputFilePath()))->toBeFalse();
+        }
+
+        // 39: 非秘密の診断材料は残っている
+        expect(is_dir($workspace.'/signals'))->toBeTrue();
+
+        // 41: 残置した workspace の mode は 0700
+        expect(ProbeEnvironment::mode($workspace))->toBe(0700);
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+/** 0500 のディレクトリでも書けてしまう実行者 (root 等) では削除失敗を再現できない */
+function harnessCanBlockUnlink(): bool
+{
+    $probe = harnessWorkspace();
+    chmod($probe, 0500);
+    $writable = @file_put_contents($probe.'/probe.txt', 'x') !== false;
+    chmod($probe, 0700);
+    harnessRemoveDirectory($probe);
+
+    return ! $writable;
+}
+
+/** workspace を書き込み不可にして秘密の unlink を失敗させる台本 */
+function harnessLockWorkspaceScript(): Closure
+{
+    return static function (ScriptedProbeProcess $process): void {
+        chmod($process->spec->workspaceDirectory, 0500);
+        $process->step = 1;
+    };
+}
+
+test('群4-40: 秘密ファイルを消せなかったら黙って通らない (全対象のパスを明示した例外)', function (): void {
+    if (! harnessCanBlockUnlink()) {
+        $this->markTestSkipped('この実行者は 0500 のディレクトリでも削除できるため検査できない');
+    }
+
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessLockWorkspaceScript(), $log),
+    );
+
+    $thrown = null;
+    try {
+        harnessRun($factory, timeoutSeconds: 0.1);
+    } catch (Throwable $e) {
+        $thrown = $e;
+    }
+
+    $workspace = $factory->workspaceDirectory();
+    chmod($workspace, 0700);
+
+    try {
+        expect($thrown)->toBeInstanceOf(ConcurrencyProtocolException::class);
+        expect($thrown?->getMessage())->toContain('秘密を含むファイルを削除できなかった');
+
+        // ★**1 件目の失敗で抜けない**ことを固定する。抜けると 2 件目以降の削除が省略され、
+        //   消せたはずの秘密が残る。3 つの対象がすべて例外に載っていることで裏を取る。
+        expect($thrown?->getMessage())->toContain(ProbeEnvironment::ENV_FILE_NAME);
+        expect($thrown?->getMessage())->toContain('input-a.json');
+        expect($thrown?->getMessage())->toContain('input-b.json');
+
+        // ★元の失敗は畳んで捨てない
+        expect($thrown?->getPrevious())->not->toBeNull();
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群4-44: 秘密削除失敗 + 停止未確認 + 権限不正 が 1 つの例外へまとめて載る', function (): void {
+    if (! harnessCanBlockUnlink()) {
+        $this->markTestSkipped('この実行者は 0500 のディレクトリでも削除できるため検査できない');
+    }
+
+    // ★先に見つかった 1 つで打ち切ると、同時に起きているもう一方の危険が診断から消える。
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
+            $spec,
+            harnessLockWorkspaceScript(),
+            $log,
+            ignoreTerminate: true,
+            ignoreKill: true,
+        ),
+    );
+
+    $thrown = null;
+    try {
+        harnessRun($factory, timeoutSeconds: 0.05);
+    } catch (Throwable $e) {
+        $thrown = $e;
+    }
+
+    $workspace = $factory->workspaceDirectory();
+    chmod($workspace, 0700);
+
+    try {
+        expect($thrown)->toBeInstanceOf(ConcurrencyProtocolException::class);
+        expect($thrown?->getMessage())->toContain('秘密を含むファイルを削除できなかった');
+        expect($thrown?->getMessage())->toContain('停止を確認できない子が残っている');
+        expect($thrown?->getMessage())->toContain('残置する workspace の権限が 0700 でない');
+        expect($thrown?->getPrevious())->not->toBeNull();
+    } finally {
+        harnessRemoveDirectory($workspace);
+    }
+});
+
+test('群4-43: 子の stderr に秘密が現れても例外へは伏せ字でしか載らない', function (): void {
+    // ★一時ファイルを消しても CI のログは残る。秘密の後始末はファイル経路だけでは閉じない。
+    $sentinelKey = 'sentinel-plain-api-key-'.bin2hex(random_bytes(8));
+    $requestBody = ['title' => 'sentinel-body-'.bin2hex(random_bytes(8))];
+    $rawBody = json_encode($requestBody, JSON_THROW_ON_ERROR);
+    $appKey = config('app.key');
+    expect($appKey)->toBeString();
+
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
+            $spec,
+            static function (ScriptedProbeProcess $process): void {},
+            $log,
+            exitImmediately: true,
+            // 子が握っている値が丸ごと stderr へ出た最悪ケースを再現する
+            stderr: "fatal: {$sentinelKey} / {$rawBody} / {$appKey}",
+        ),
+    );
+
+    $thrown = null;
+    try {
+        harnessRun($factory, timeoutSeconds: 2.0, requestBody: $requestBody, plainApiKey: $sentinelKey);
+    } catch (Throwable $e) {
+        $thrown = $e;
+    }
+
+    expect($thrown)->toBeInstanceOf(ConcurrencyProtocolException::class);
+
+    $text = harnessThrowableText($thrown);
+    expect($text)->toContain('観測を出さずに終了した');
+    expect($text)->not->toContain($sentinelKey);
+    expect($text)->not->toContain($rawBody);
+    expect($text)->not->toContain($appKey);
+    expect($text)->toContain('[redacted:');
+});
+
+test('群4-42: 回収の poll は単一ループで全子を確認する (逐次の blocking wait ではない)', function (): void {
+    $factory = new ScriptedProbeProcessFactory(
+        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
+            $spec,
+            static function (ScriptedProbeProcess $process): void {},
+            $log,
+            ignoreTerminate: true,
+        ),
+    );
+
+    expect(fn () => harnessRun($factory, timeoutSeconds: 0.05))
+        ->toThrow(BarrierTimeoutException::class);
+
+    // TERM 送出で poll 記録が初期化されるので、ここに残るのは回収フェーズの poll だけ。
+    // 逐次の blocking wait なら「a を延々 poll → b を延々 poll」で行き来は 1 回しか起きない。
+    expect($factory->log->pollAlternations())->toBeGreaterThan(10);
+});
```

## 質問

上記の対応で Round 1 の 7 件は解消したか。残る懸念があれば指摘し、無ければ全体判定を APPROVED と書け。
