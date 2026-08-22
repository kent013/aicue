# 実装レビュー Round 4 (aicue / T248)

Round 3 の指摘 2 件 (どちらも「メッセージと trace は別経路」という 1 点の裏表) を対応した。

## 対応マトリクス

Codex 全体判定: **CHANGES_REQUESTED** ([Critical] 0 / [Warning] 2)

Round 3 の指摘は「**例外メッセージ**と**例外 trace** は別経路であり、
メッセージの伏せ字 (choke point) は trace の引数には効かない」という 1 点に集約される。

## [Warning] 親プロセスの trace に `run()` の引数 (plain API キー等) が残る

- 判断: **対応する**
- 根拠: 妥当である。`zend.exception_ignore_args=0` の環境 (php.ini-development の既定) では
  `getTraceAsString()` が文字列引数を出す。メッセージを作り直しても trace の引数は変わらない。
  Round 1 で「子は trace を出さない」と決めたのと**同じ理由が親側にも当てはまる**のに、
  親側は塞いでいなかった = 非対称が残っていた。
- 対応内容: 秘密を運ぶ引数へ `#[\SensitiveParameter]` を付けた (**20 箇所**)。
  対象は「秘密そのもの」と「子が書いた untrusted な文字列」の両方である:
  - `ConcurrencyProbeRunner::run()` の `$plainApiKey` / `$requestBody`
  - `ConcurrencyProbeRunner::redactedForDiagnostics()` / `redactSecrets()` の `$secrets`
  - `ConcurrencyProtocolException` の `childDiedEarly($stderr)` / `identityMismatch($actual)` /
    `goTokenMismatch($actual)` / `unexpectedObservation($reason)` / `unknownSignal($names)`
    (メッセージに秘密が無く choke point が**元の例外をそのまま返す**場合、
    元の例外の trace が残るため)
  - `ConcurrentProbeObservation::fromDecodedJson()` / `stringValue()` / `intValue()` と
    `ProbeDatabaseCoordinates::fromDecodedJson()` (子の観測 JSON がまるごと引数に載る)
  - `ProcessBarrier::signal()` の `$payload`
  - `ProbeEnvironment::encodeLine()` の `$value` / `writeProtectedFile()` の `$contents`
    (env ファイルの本文は APP_KEY / CIPHERSWEET_KEY / DB パスワードを**全部**含む)
  - テスト側の `harnessRun()` の `$plainApiKey` / `$requestBody` と
    `ScriptedProbeProcess::__construct()` の `$stderr`

## [Warning] 群4-43 が trace を検査していない

- 判断: **対応する**
- 根拠: 同上。メッセージだけを見る検査では、上の穴が開いていても緑のままだった。
- 対応内容: `harnessThrowableText()` が **`getMessage()` と `getTraceAsString()` の両方**を
  連鎖の各段ぶん集めるようにした。群 4-43 の 2 経路 (stderr / 合図の中身) は
  この全文に対して 5 種の sentinel が現れないことを検査する。

## 検証結果

- `composer phpstan` : OK (No errors)
- `vendor/bin/pint --test` : passed
- `composer test -- "--filter=Concurrency"` : 60 tests / 59 passed / 1 skipped (直前の版で確認済み)
- 本 Round の最終版での full `composer test` + `pnpm test` + `pnpm test:packages` は実行中

## 注記 (誇張しない)

`#[\SensitiveParameter]` が隠すのは**引数**である。`zend.exception_ignore_args=1` の環境では
そもそも引数が出ないので、本対応は「引数が出る設定でも漏れない」ことを構造的に保証する側の手当てである。
`getTraceAsString()` は文字列引数を 15 文字で切るため、**完全一致の伏せ字では拾えない断片**が
出る経路でもあり、そこは「出さない」側 (子は message / trace を出さない + 親は引数を隠す) で閉じている。

## 支援クラスの現在の全文差分 (git diff HEAD)

```diff
diff --git a/tests/Support/Concurrency/ConcurrencyProbeRunner.php b/tests/Support/Concurrency/ConcurrencyProbeRunner.php
new file mode 100644
index 00000000..5bea5af3
--- /dev/null
+++ b/tests/Support/Concurrency/ConcurrencyProbeRunner.php
@@ -0,0 +1,716 @@
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
+        // ★`#[\SensitiveParameter]` は**例外の trace 側**の穴を塞ぐ。メッセージの伏せ字
+        //   (`redactedForDiagnostics()`) は trace の引数には効かず、`zend.exception_ignore_args=0`
+        //   の環境では文字列引数がそのまま `getTraceAsString()` へ出る (= 別経路である)。
+        #[\SensitiveParameter] string $plainApiKey,
+        #[\SensitiveParameter] array $requestBody,
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
+            );
+        } catch (Throwable $e) {
+            // ★**唯一の出口で 1 回だけ**伏せ字にする (choke point)。
+            $e = self::redactedForDiagnostics($e, $secrets);
+
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
+    ): ConcurrentProbeResult {
+        foreach ($processes as $process) {
+            $process->start();
+        }
+
+        $abort = self::abortCondition($barrier, $processes);
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
+                    $process->errorOutput() === '' ? '(なし)' : $process->errorOutput(),
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
+     * @return Closure(): void
+     */
+    private static function abortCondition(ProcessBarrier $barrier, array $processes): Closure
+    {
+        return static function () use ($barrier, $processes): void {
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
+                    $process->errorOutput(),
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
+     * 診断へ出る前に、例外メッセージの中の既知の秘密を伏せ字にする (**唯一の choke point**)。
+     *
+     * ★子は untrusted である。合図の中身・観測の値・未知の完成合図の名前・stderr など、
+     *   **子が書いた文字列は例外メッセージのどこにでも入りうる**。生成箇所ごとに伏せ字を撒くと
+     *   必ず撒き漏らすので、**唯一の出口 (`run()`) で 1 回だけ**通す。
+     * ★**型は保つ** (呼び出し側とテストは型で分岐する)。作り直すのは本ハーネスの 2 型だけで、
+     *   それ以外の型 (`JsonException` 等) は本ハーネスがメッセージを組み立てていないので触らない。
+     * ★**previous は引き継がない**。previous 側のメッセージまでは作り直せないので、
+     *   伏せ字にできない文字列を連鎖に残すほうが危ない (作業の失敗は回収側の
+     *   `reapFailed()` が伏せ字済みの実体を previous に持つ)。
+     * ★**保証しないもの**: 伏せられるのは完全一致で現れた既知の秘密だけである。
+     *   切り詰められた断片は一致しないので、**子が message / trace を出さない**ことと
+     *   合わせて初めて閉じる。
+     *
+     * @param  list<string>  $secrets
+     */
+    private static function redactedForDiagnostics(Throwable $e, #[\SensitiveParameter] array $secrets): Throwable
+    {
+        if (! $e instanceof ConcurrencyProtocolException && ! $e instanceof BarrierTimeoutException) {
+            return $e;
+        }
+
+        $redacted = self::redactSecrets($e->getMessage(), $secrets);
+        if ($redacted === $e->getMessage()) {
+            return $e;
+        }
+
+        return new ($e::class)($redacted);
+    }
+
+    /**
+     * 既知の秘密を伏せ字にする。
+     *
+     * ★一時ファイルを消しても**CI のログは残る**ので、秘密の後始末はファイル経路だけでは閉じない。
+     * ★**長い秘密から順に**置換する (短い秘密が長い秘密の一部だったときに、
+     *   置換済みの伏せ字を壊さないため)。
+     *
+     * @param  list<string>  $secrets
+     */
+    private static function redactSecrets(string $text, #[\SensitiveParameter] array $secrets): string
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
index 00000000..4ff836b8
--- /dev/null
+++ b/tests/Support/Concurrency/ConcurrencyProtocolException.php
@@ -0,0 +1,124 @@
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
+    public static function childDiedEarly(string $childId, ?int $exitCode, #[\SensitiveParameter] string $stderr): self
+    {
+        return new self(sprintf(
+            '子 "%s" が観測を出さずに終了した (exit=%s)。stderr: %s',
+            $childId,
+            $exitCode === null ? 'unknown' : (string) $exitCode,
+            $stderr === '' ? '(なし)' : $stderr,
+        ));
+    }
+
+    public static function identityMismatch(
+        string $childId,
+        string $field,
+        string $expected,
+        #[\SensitiveParameter] string $actual,
+    ): self {
+        return new self(sprintf(
+            '子 "%s" の同一性が一致しない (%s: 期待 "%s" / 実際 "%s")',
+            $childId,
+            $field,
+            $expected,
+            $actual,
+        ));
+    }
+
+    public static function goTokenMismatch(string $childId, string $expected, #[\SensitiveParameter] string $actual): self
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
+    public static function unexpectedObservation(#[\SensitiveParameter] string $reason): self
+    {
+        return new self('子の観測が受理条件を満たさない: '.$reason);
+    }
+
+    /**
+     * 許可集合に無い完成合図が現れた (無視ではなく拒否する)。
+     *
+     * @param  list<string>  $names
+     */
+    public static function unknownSignal(#[\SensitiveParameter] array $names): self
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
diff --git a/tests/Support/Concurrency/ConcurrentProbeObservation.php b/tests/Support/Concurrency/ConcurrentProbeObservation.php
new file mode 100644
index 00000000..c42b87e5
--- /dev/null
+++ b/tests/Support/Concurrency/ConcurrentProbeObservation.php
@@ -0,0 +1,248 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use App\Enums\ApiErrorCode;
+
+/**
+ * 子プロセス 1 本ぶんの一次観測。
+ *
+ * ★勝者の判定は**行の最終状態ではなくこの一次観測**で行う (正典・家系の作法)。
+ *   行だけを見ると「2 本とも本処理を実行したが後着が上書きした」形と区別がつかない。
+ * ★{@see self::fromDecodedJson()} は **fail-closed**。必須キーの欠落・型違い・**未知キー**の
+ *   いずれでも例外にする (子と親のプロトコル退行を黙って受け入れない)。
+ * ★**キャストで救わない**。整数 cast の飽和で別の値が通る穴を家系が実際に踏んでいる。
+ */
+final readonly class ConcurrentProbeObservation
+{
+    /**
+     * 受理する JSON のキー (deny-by-default。過不足があれば例外)。
+     *
+     * @var list<string>
+     */
+    public const array REQUIRED_KEYS = [
+        // 同一性 (起動時の割り当て・親が出した go token との突合)
+        'child_id', 'nonce', 'go_token',
+        // 何が起きたか (一次観測)
+        'http_status', 'error_code', 'handler_executions', 'entered_handler',
+        // 何を送ったか (2 子が同一要求だったことの証明)
+        'route_name', 'uri', 'request_hash', 'api_key_id',
+        // 守りたい層以外が無効化されていたか (要素 (3))
+        'cache_default', 'cache_store_driver',
+        // どこへ繋いだか (開発 DB 到達の検出)
+        'db_driver', 'db_host', 'db_port', 'db_database', 'db_username', 'db_charset', 'db_sslmode', 'db_url',
+    ];
+
+    private function __construct(
+        public string $childId,
+        public string $nonce,
+        public string $goToken,
+        public int $httpStatus,
+        /** ★勝者は null、敗者は 'idempotency_in_progress' (409 は 3 コードあるので必須) */
+        public ?string $errorCode,
+        public int $handlerExecutions,
+        public bool $enteredHandler,
+        public string $routeName,
+        public string $uri,
+        public string $requestHash,
+        /** ★入力のコピーではなく、認証後の ApiActorContext から観測した値 */
+        public int $apiKeyId,
+        public string $cacheDefault,
+        /** ★既定 store を**裏打ちする driver** (store 名だけでは名前と実体のずれを落とせない) */
+        public string $cacheStoreDriver,
+        public ProbeDatabaseCoordinates $database,
+    ) {}
+
+    /**
+     * @throws ConcurrencyProtocolException 解釈できない観測は通さない
+     */
+    public static function fromDecodedJson(#[\SensitiveParameter] mixed $value): self
+    {
+        if (! is_array($value)) {
+            throw ConcurrencyProtocolException::unexpectedObservation('観測が配列でない');
+        }
+
+        $actual = array_keys($value);
+        sort($actual);
+        $expected = self::REQUIRED_KEYS;
+        sort($expected);
+        if ($actual !== $expected) {
+            throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
+                'キー集合が一致しない (欠落: %s / 余剰: %s)',
+                implode(',', array_diff($expected, $actual)) ?: '(なし)',
+                implode(',', array_diff($actual, $expected)) ?: '(なし)',
+            ));
+        }
+
+        /** @var array<string, mixed> $value */
+        $childId = self::stringValue($value, 'child_id');
+        $httpStatus = self::intValue($value, 'http_status');
+        if ($httpStatus < 100 || $httpStatus > 599) {
+            throw ConcurrencyProtocolException::unexpectedObservation("http_status が範囲外: {$httpStatus}");
+        }
+
+        $errorCode = $value['error_code'];
+        if ($errorCode !== null && (! is_string($errorCode) || $errorCode === '')) {
+            throw ConcurrencyProtocolException::unexpectedObservation(
+                'error_code は null か非空文字列でなければならない (空文字は通さない)'
+            );
+        }
+
+        $handlerExecutions = self::intValue($value, 'handler_executions');
+        if ($handlerExecutions < 0) {
+            throw ConcurrencyProtocolException::unexpectedObservation('handler_executions が負');
+        }
+
+        $enteredHandler = $value['entered_handler'];
+        if (! is_bool($enteredHandler)) {
+            throw ConcurrencyProtocolException::unexpectedObservation('entered_handler が真偽値でない');
+        }
+
+        // ★矛盾する組合せを通さない (true なら >= 1、false なら 0)
+        if ($enteredHandler && $handlerExecutions < 1) {
+            throw ConcurrencyProtocolException::unexpectedObservation(
+                'entered_handler=true なのに handler_executions が 0'
+            );
+        }
+        if (! $enteredHandler && $handlerExecutions !== 0) {
+            throw ConcurrencyProtocolException::unexpectedObservation(
+                'entered_handler=false なのに handler_executions が 0 でない'
+            );
+        }
+
+        return new self(
+            childId: $childId,
+            nonce: self::stringValue($value, 'nonce'),
+            goToken: self::stringValue($value, 'go_token'),
+            httpStatus: $httpStatus,
+            errorCode: $errorCode,
+            handlerExecutions: $handlerExecutions,
+            enteredHandler: $enteredHandler,
+            routeName: self::stringValue($value, 'route_name'),
+            uri: self::stringValue($value, 'uri'),
+            requestHash: self::stringValue($value, 'request_hash'),
+            apiKeyId: self::intValue($value, 'api_key_id'),
+            cacheDefault: self::stringValue($value, 'cache_default'),
+            cacheStoreDriver: self::stringValue($value, 'cache_store_driver'),
+            database: ProbeDatabaseCoordinates::fromDecodedJson($value),
+        );
+    }
+
+    /** 起動時の割り当て・親が出した go token と食い違ったら通さない */
+    public function assertIdentity(string $childId, string $nonce, string $goToken): void
+    {
+        if ($this->childId !== $childId) {
+            throw ConcurrencyProtocolException::identityMismatch($childId, 'child_id', $childId, $this->childId);
+        }
+
+        if ($this->nonce !== $nonce) {
+            throw ConcurrencyProtocolException::identityMismatch($childId, 'nonce', $nonce, $this->nonce);
+        }
+
+        if ($this->goToken !== $goToken) {
+            throw ConcurrencyProtocolException::goTokenMismatch($childId, $goToken, $this->goToken);
+        }
+    }
+
+    /**
+     * 敗者としての条件 (release の前提)。満たさなければ例外。
+     *
+     * ★`idempotency_conflict` / `idempotency_indeterminate` は通さない。
+     *   409 は 3 コードあり、body 違いの conflict でも「勝者 1 / 敗者 1」は成立して
+     *   **緑になってしまう**ためである。
+     */
+    public function assertLost(string $expectedRequestHash): void
+    {
+        if ($this->httpStatus !== 409) {
+            throw ConcurrencyProtocolException::unexpectedObservation(
+                "敗者の応答が 409 でない: {$this->httpStatus}"
+            );
+        }
+
+        if ($this->errorCode !== ApiErrorCode::IdempotencyInProgress->value) {
+            throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
+                '敗者の error_code が %s でない: %s',
+                ApiErrorCode::IdempotencyInProgress->value,
+                $this->errorCode ?? '(null)',
+            ));
+        }
+
+        if ($this->enteredHandler || $this->handlerExecutions !== 0) {
+            throw ConcurrencyProtocolException::unexpectedObservation('敗者が本処理へ入っている');
+        }
+
+        if ($this->requestHash !== $expectedRequestHash) {
+            throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
+                '敗者の request_hash が親の期待値と違う (期待 %s / 実際 %s)',
+                $expectedRequestHash,
+                $this->requestHash,
+            ));
+        }
+    }
+
+    /**
+     * 守りたい層以外が無効化されていたか (要素 (3))。
+     *
+     * ★言えるのは「Laravel の既定 cache を経由するプロセス間共有ロックが使えない」までである
+     *   (「アプリ側ロックが 1 つも無い」とは言えない)。
+     * ★**store 名と driver の 2 つ**を見る。名前だけだと「array という名前の store が
+     *   実は別の driver で裏打ちされている」形を落とせない。
+     *   (詳細設計は 2 つ目に `Cache::getDefaultDriver()` を挙げていたが、その戻り値は
+     *   `config('cache.default')` そのもので同じ事実の写しにすぎず、
+     *   かつ cache API を呼ぶと `CachePayloadPlainDataGateTest` の L3 目録への登録が要る。
+     *   採用時債務のファイルを触ることになるため、より強い設定側の観測へ置き換えた)
+     */
+    public function assertAppLocksDisabled(): void
+    {
+        if ($this->cacheDefault !== 'array' || $this->cacheStoreDriver !== 'array') {
+            throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
+                '子の既定 cache が array に固定できていない (store=%s driver=%s)',
+                $this->cacheDefault,
+                $this->cacheStoreDriver,
+            ));
+        }
+    }
+
+    /** 親が渡した DB 座標と完全一致するか (開発 DB 到達の検出) */
+    public function assertDatabaseCoordinates(ProbeDatabaseCoordinates $expected): void
+    {
+        if ($this->database->equals($expected)) {
+            return;
+        }
+
+        throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
+            '子の実効 DB 座標が親と一致しない (親 %s / 子 %s)',
+            $expected->describe(),
+            $this->database->describe(),
+        ));
+    }
+
+    /**
+     * @param  array<string, mixed>  $value
+     */
+    private static function stringValue(#[\SensitiveParameter] array $value, string $key): string
+    {
+        $raw = $value[$key];
+        if (! is_string($raw)) {
+            throw ConcurrencyProtocolException::unexpectedObservation("{$key} が文字列でない");
+        }
+
+        return $raw;
+    }
+
+    /**
+     * @param  array<string, mixed>  $value
+     */
+    private static function intValue(#[\SensitiveParameter] array $value, string $key): int
+    {
+        $raw = $value[$key];
+        // ★`is_int` を要求する。"409" のような数値文字列はキャストで救わない。
+        if (! is_int($raw)) {
+            throw ConcurrencyProtocolException::unexpectedObservation("{$key} が整数でない");
+        }
+
+        return $raw;
+    }
+}
diff --git a/tests/Support/Concurrency/ProbeDatabaseCoordinates.php b/tests/Support/Concurrency/ProbeDatabaseCoordinates.php
new file mode 100644
index 00000000..5a987b08
--- /dev/null
+++ b/tests/Support/Concurrency/ProbeDatabaseCoordinates.php
@@ -0,0 +1,198 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use Webmozart\Assert\Assert;
+
+/**
+ * DB 接続座標 (親の期待値も子の観測も同じ型で持ち、同型どうしで厳密比較する)。
+ *
+ * ★`db_port` は `int`、他は `string` である。`array<string, string>` で持つと
+ *   厳密比較のために暗黙のキャストが要り、「外部観測をキャストで救わない」という
+ *   本設計の方針と矛盾する。
+ */
+final readonly class ProbeDatabaseCoordinates
+{
+    /** 観測 JSON でのキー名 (親子で同じ綴りを使うための唯一の正本) */
+    public const array OBSERVATION_KEYS = [
+        'db_driver', 'db_host', 'db_port', 'db_database',
+        'db_username', 'db_charset', 'db_sslmode', 'db_url',
+    ];
+
+    public function __construct(
+        public string $driver,
+        public string $host,
+        public int $port,
+        public string $database,
+        public string $username,
+        public string $charset,
+        public string $sslmode,
+        /** ★空文字のみ許可 (非空は fail-closed) */
+        public string $url,
+    ) {
+        Assert::same($url, '', 'DB_URL 主体の設定は本ハーネスの前提外である');
+    }
+
+    /**
+     * **実行中のアプリの実接続設定**から作る (信頼済み設定の正規化)。
+     *
+     * 親も子も同じ経路で観測する — 値が違えば「別の DB を向いている」ことがそのまま差になる
+     * (同じ抽出規則で読むからこそ、比較が座標の差だけを映す)。
+     *
+     * ★`config` の port は数値文字列でありうる。**黙ってキャストせず**、
+     *   数値文字列であることと **1〜65535 の範囲**を明示的に検証してから int 化する。
+     *   これは「信頼済みの設定を正規化する」経路であり、外部 JSON とは扱いが違う。
+     */
+    public static function fromParentConfig(): self
+    {
+        Assert::same(config('database.default'), 'pgsql', 'このハーネスは pgsql レーンを前提にする');
+
+        $config = config('database.connections.pgsql');
+        Assert::isArray($config);
+
+        return new self(
+            driver: self::stringValue($config, 'driver'),
+            host: self::stringValue($config, 'host'),
+            port: self::portValue($config['port'] ?? null),
+            database: self::stringValue($config, 'database'),
+            username: self::stringValue($config, 'username'),
+            charset: self::stringValue($config, 'charset'),
+            sslmode: self::stringValue($config, 'sslmode'),
+            url: (string) ($config['url'] ?? ''),
+        );
+    }
+
+    /**
+     * 子側の観測 JSON から作る (**外部入力なので fail-closed**)。
+     *
+     * ★こちらは `is_int()` を要求し、**キャストで救わない**
+     *   (数値文字列 "5432" は通さない。整数 cast の飽和で別の値が通る穴を家系が踏んでいる)。
+     *
+     * @param  array<string, mixed>  $value
+     *
+     * @throws ConcurrencyProtocolException
+     */
+    public static function fromDecodedJson(#[\SensitiveParameter] array $value): self
+    {
+        foreach (self::OBSERVATION_KEYS as $key) {
+            if (! array_key_exists($key, $value)) {
+                throw ConcurrencyProtocolException::unexpectedObservation("DB 座標のキーが欠けている: {$key}");
+            }
+        }
+
+        $port = $value['db_port'];
+        if (! is_int($port)) {
+            throw ConcurrencyProtocolException::unexpectedObservation(
+                'db_port が整数でない (数値文字列をキャストで救わない)'
+            );
+        }
+        if ($port < 1 || $port > 65535) {
+            throw ConcurrencyProtocolException::unexpectedObservation("db_port が範囲外: {$port}");
+        }
+
+        $strings = [];
+        foreach (['db_driver', 'db_host', 'db_database', 'db_username', 'db_charset', 'db_sslmode', 'db_url'] as $key) {
+            $raw = $value[$key];
+            if (! is_string($raw)) {
+                throw ConcurrencyProtocolException::unexpectedObservation("{$key} が文字列でない");
+            }
+            $strings[$key] = $raw;
+        }
+
+        if ($strings['db_url'] !== '') {
+            throw ConcurrencyProtocolException::unexpectedObservation(
+                'db_url が非空 (DB_URL 主体の設定で起動した子は受理しない)'
+            );
+        }
+
+        return new self(
+            driver: $strings['db_driver'],
+            host: $strings['db_host'],
+            port: $port,
+            database: $strings['db_database'],
+            username: $strings['db_username'],
+            charset: $strings['db_charset'],
+            sslmode: $strings['db_sslmode'],
+            url: $strings['db_url'],
+        );
+    }
+
+    /** 全項目の厳密比較 */
+    public function equals(self $other): bool
+    {
+        return $this->driver === $other->driver
+            && $this->host === $other->host
+            && $this->port === $other->port
+            && $this->database === $other->database
+            && $this->username === $other->username
+            && $this->charset === $other->charset
+            && $this->sslmode === $other->sslmode
+            && $this->url === $other->url;
+    }
+
+    /**
+     * 観測 JSON へ載せる形 (キーの綴りを 1 か所に閉じる)。
+     *
+     * @return array<string, string|int>
+     */
+    public function toObservationValues(): array
+    {
+        return [
+            'db_driver' => $this->driver,
+            'db_host' => $this->host,
+            'db_port' => $this->port,
+            'db_database' => $this->database,
+            'db_username' => $this->username,
+            'db_charset' => $this->charset,
+            'db_sslmode' => $this->sslmode,
+            'db_url' => $this->url,
+        ];
+    }
+
+    /** 人が読める形 (不一致の診断に使う) */
+    public function describe(): string
+    {
+        return sprintf(
+            '%s://%s@%s:%d/%s (charset=%s sslmode=%s url=%s)',
+            $this->driver,
+            $this->username,
+            $this->host,
+            $this->port,
+            $this->database,
+            $this->charset,
+            $this->sslmode,
+            $this->url === '' ? '(空)' : $this->url,
+        );
+    }
+
+    /**
+     * @param  array<string, mixed>  $config
+     */
+    private static function stringValue(array $config, string $key): string
+    {
+        $value = $config[$key] ?? null;
+        Assert::string($value, "database.connections.pgsql.{$key} が文字列でない");
+        Assert::notEmpty($value, "database.connections.pgsql.{$key} が空である");
+
+        return $value;
+    }
+
+    private static function portValue(mixed $port): int
+    {
+        if (is_int($port)) {
+            Assert::range($port, 1, 65535, 'DB port が範囲外である');
+
+            return $port;
+        }
+
+        Assert::string($port, 'DB port が整数でも文字列でもない');
+        Assert::regex($port, '/^[0-9]+$/', 'DB port が数値文字列でない (黙ってキャストしない)');
+
+        $normalized = (int) $port;
+        Assert::range($normalized, 1, 65535, 'DB port が範囲外である');
+
+        return $normalized;
+    }
+}
diff --git a/tests/Support/Concurrency/ProbeEnvironment.php b/tests/Support/Concurrency/ProbeEnvironment.php
new file mode 100644
index 00000000..39b084d4
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
+    public static function encodeLine(string $key, #[\SensitiveParameter] string $value): string
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
+    public static function writeProtectedFile(string $path, #[\SensitiveParameter] string $contents): void
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
diff --git a/tests/Support/Concurrency/ProcessBarrier.php b/tests/Support/Concurrency/ProcessBarrier.php
new file mode 100644
index 00000000..6c0476ff
--- /dev/null
+++ b/tests/Support/Concurrency/ProcessBarrier.php
@@ -0,0 +1,225 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Concurrency;
+
+use Closure;
+use Webmozart\Assert\Assert;
+
+/**
+ * 実プロセス並行テストの合図の待ち合わせ (正典 v1 の要素 (1)(4)(5))。
+ *
+ * 規律 7 点:
+ * 1. ready は**子ごと**に分ける (共有 ready だと片方だけ準備できた状態で go を出せてしまい、
+ *    「全員の準備を確認してから同一の合図で解き放つ」という最重要前提が**緑のまま**壊れる)
+ * 2. 存在だけでなく**中身を照合**する (空・別 child・誤 nonce を通さない。照合は呼び出し側が行う)
+ * 3. 待ちのループでは**毎回 clearstatcache()** する — 捨てないと合図に気付くのが遅れ、
+ *    2 本の実行が重ならず並行テストの意味が消える (正典が名指しする作法)
+ * 4. 締切は**単調時計** (hrtime) で測る (壁時計は補正で戻りうる)
+ * 5. 合図は書きかけ用ディレクトリへ書いてから `link()` で配置する (書きかけを相手に見せない)
+ * 6. 名前は {@see SignalName} でしか作れない (このクラスは string の名前を受け取らないし、
+ *    名前を作る二重入口も持たない)
+ * 7. **同じ合図を 2 回置けない** (`rename()` は既存を上書きするので `link()` を使う。
+ *    ready や out の二重送信が黙って隠れるのを塞ぐ)
+ *
+ * ★**置き場所を 2 つに分ける**: 完成合図は signals/、書きかけは partial/。
+ *   同じディレクトリに置くと、完成ファイルの列挙が書きかけを拾って
+ *   二重実行の判定が壊れる。列挙を安全にするための分離である。
+ * ★読み取りは**注入可能な読み手**越しに行う。`file_get_contents() === false` を
+ *   決定的に再現するためで、権限 (chmod 000) に依存する検査は root 実行で不安定になる。
+ *
+ * **保証しないもの**: 合図の順序関係だけを保証する。実際に処理が重なったかどうかは
+ * 呼び出し側 ({@see ConcurrencyProbeRunner}) が entered / release の 3 段で構成する。
+ */
+final class ProcessBarrier
+{
+    /** 待ちのポーリング間隔 (マイクロ秒) */
+    public const int POLL_INTERVAL_MICROSECONDS = 1_000;
+
+    private readonly ?Closure $reader;
+
+    /**
+     * @param  (callable(string): string|false)|null  $reader  既定は file_get_contents
+     */
+    public function __construct(
+        private readonly string $workspaceDirectory,
+        ?callable $reader = null,
+    ) {
+        Assert::directory($workspaceDirectory);
+        Assert::directory($this->signalDirectory());
+        Assert::directory($this->partialDirectory());
+
+        $this->reader = $reader === null ? null : Closure::fromCallable($reader);
+    }
+
+    /**
+     * 合図の置き場所 (signals/ と partial/) を作る。既に在れば何もしない。
+     */
+    public static function prepareWorkspace(string $workspaceDirectory): void
+    {
+        foreach ([$workspaceDirectory.'/signals', $workspaceDirectory.'/partial'] as $directory) {
+            if (is_dir($directory)) {
+                continue;
+            }
+
+            Assert::true(mkdir($directory, 0700), "合図の置き場所を作れない: {$directory}");
+        }
+    }
+
+    /**
+     * 合図を置く (partial/ へ書いてから signals/ へ配置)。
+     *
+     * ★配置に `rename()` を使わない。POSIX の `rename()` は**既存ファイルを上書きする**ので、
+     *   同じ合図の 2 回目の送信が黙って隠れる (ready や out の二重送信を見逃す)。
+     *   `link()` は **target が既に在れば失敗する**ので、TOCTOU のある `is_file()` 判定を
+     *   挟まずに二重配置を弾ける。同一 FS 内なので hard link が使える。
+     */
+    public function signal(SignalName $name, #[\SensitiveParameter] string $payload): void
+    {
+        $temporary = $this->partialDirectory().'/'.bin2hex(random_bytes(8));
+
+        if (file_put_contents($temporary, $payload) !== strlen($payload)) {
+            @unlink($temporary);
+
+            throw ConcurrencyProtocolException::signalNotWritten($name);
+        }
+
+        try {
+            // 既に在れば false。原子的に「無ければ置く」を実現する。
+            if (@link($temporary, $this->path($name))) {
+                return;
+            }
+
+            // ★失敗の**分類**を target の存在で行う。すべてを二重配置に倒すと、
+            //   権限・I/O 障害・hard link 非対応まで「二重送信を検出した」という
+            //   嘘の診断になる。
+            clearstatcache(true, $this->path($name));
+
+            throw is_file($this->path($name))
+                ? ConcurrencyProtocolException::duplicateSignal($name)
+                : ConcurrencyProtocolException::signalNotPlaced($name);
+        } finally {
+            @unlink($temporary);
+        }
+    }
+
+    /**
+     * 合図が現れるまで待ち、その中身を返す。
+     *
+     * @param  float  $remainingSeconds  呼び出し側が持つ**絶対 deadline** からの残り時間
+     * @param  (callable(): void)|null  $abortIf  待機中に毎周回呼ぶ中断条件
+     *                                            (二重実行の検出・子の異常終了など。
+     *                                            呼び先が例外を投げれば締切を待たずに抜ける)
+     *
+     * @throws BarrierTimeoutException 締切を超えた
+     * @throws ConcurrencyProtocolException 合図はあるのに読めない
+     */
+    public function await(SignalName $name, float $remainingSeconds, ?callable $abortIf = null): string
+    {
+        Assert::greaterThan($remainingSeconds, 0.0);
+
+        $deadline = hrtime(true) + (int) ($remainingSeconds * 1_000_000_000);
+
+        while (true) {
+            if ($abortIf !== null) {
+                $abortIf();
+            }
+
+            // ★毎周回捨てる。捨てないと合図に気付くのが遅れ、2 本の実行が重ならない。
+            clearstatcache(true, $this->path($name));
+
+            if (is_file($this->path($name))) {
+                return $this->read($name);
+            }
+
+            if (hrtime(true) >= $deadline) {
+                throw BarrierTimeoutException::waitingFor($name, $remainingSeconds);
+            }
+
+            usleep(self::POLL_INTERVAL_MICROSECONDS);
+        }
+    }
+
+    /**
+     * 完成合図のディレクトリを**列挙**し、現れている名前を返す。
+     *
+     * ★prefix の glob は採らない。書きかけは別ディレクトリなので、ここでの列挙は
+     *   完成ファイルだけを見る。
+     * ★**許可集合に無い完成ファイルが 1 つでもあれば例外**にする
+     *   (未知の child ID の合図を「無視」ではなく「拒否」にする)。
+     *
+     * @param  list<SignalName>  $allowed  許可される完成合図の全集合
+     * @return list<SignalName> 現れている合図
+     *
+     * @throws ConcurrencyProtocolException 未知の完成ファイルがある
+     */
+    public function present(array $allowed): array
+    {
+        clearstatcache(true, $this->signalDirectory());
+
+        $entries = scandir($this->signalDirectory());
+        if ($entries === false) {
+            throw ConcurrencyProtocolException::signalDirectoryUnreadable($this->signalDirectory());
+        }
+
+        $allowedValues = array_map(static fn (SignalName $name): string => $name->value, $allowed);
+
+        $present = [];
+        $unknown = [];
+
+        foreach ($entries as $entry) {
+            if ($entry === '.' || $entry === '..') {
+                continue;
+            }
+
+            $index = array_search($entry, $allowedValues, true);
+            if ($index === false) {
+                $unknown[] = $entry;
+
+                continue;
+            }
+
+            $present[] = $allowed[$index];
+        }
+
+        if ($unknown !== []) {
+            throw ConcurrencyProtocolException::unknownSignal($unknown);
+        }
+
+        return $present;
+    }
+
+    public function path(SignalName $name): string
+    {
+        return $this->signalDirectory().'/'.$name->value;
+    }
+
+    /**
+     * 合図を読む。**読めない合図は空として通さず例外**にする (fail-closed)。
+     *
+     * 合図はあるのに読めない = 観測が成立していない。空として通すと後続の照合が
+     * 別の理由で落ちて原因が隠れる。
+     */
+    private function read(SignalName $name): string
+    {
+        $reader = $this->reader ?? file_get_contents(...);
+        $contents = $reader($this->path($name));
+
+        if ($contents === false) {
+            throw ConcurrencyProtocolException::signalUnreadable($name);
+        }
+
+        return $contents;
+    }
+
+    private function signalDirectory(): string
+    {
+        return $this->workspaceDirectory.'/signals';
+    }
+
+    private function partialDirectory(): string
+    {
+        return $this->workspaceDirectory.'/partial';
+    }
+}
```

## テスト側の変更点 (抜粋)

```diff
diff --git a/tests/Support/Concurrency/BarrierTimeoutException.php b/tests/Support/Concurrency/BarrierTimeoutException.php
@@ -0,0 +1,37 @@
+/**
+ * 締切を超えた (合図が現れなかった / 作業全体の締切を使い切った)。
+ *
+ * ★プロトコルが破られたこと ({@see ConcurrencyProtocolException}) と**型で分ける**。
+ *   探している退行 (二重実行) を「締切超過」という紛らわしい形で出さないためである。
+ */
+    /** 作業の締切を使い切った (次の待ちに入れない) */
+    /** どの子も本処理へ入らないまま作業の締切を超えた */
diff --git a/tests/Support/Concurrency/ConcurrencyFixtureKeys.php b/tests/Support/Concurrency/ConcurrencyFixtureKeys.php
@@ -0,0 +1,22 @@
+/**
+ * 作った検体の主キー (cleanup の対象を推測させないために持ち回る)。
+ *
+ * ★route 名は**持たない**。route 名を決めるのは {@see ConcurrencyProbeRunner} であり、
+ *   検体の生成時にはまだ存在しない。掃除は `api_key_id` で足りる
+ *   (`idempotency_keys` は cascade 対象)。
+ */
diff --git a/tests/Support/Concurrency/ConcurrencyProbeRunner.php b/tests/Support/Concurrency/ConcurrencyProbeRunner.php
@@ -0,0 +1,716 @@
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
+    /** **作業の締切** (子の起動 + 合図 + 要求 + 通常の終了待ちを打ち切る) */
+    /** 子の識別子 (固定 2 本。N 本への一般化はしない) */
+    /**
+     * **回収専用の予算** (作業の締切とは独立に確保する)。
+     *
+     * ★作業の締切を回収にも使うと、**締切超過の瞬間に残り時間が 0** になり、
+     *   まさに回収が必要な場面で kill 後の待機ができず子が残る。
+     * ★この予算は**全子で共有する** (子ごとに 2 秒ではない)。
+     *   回収はフェーズ単位で行うので、子数が増えても総時間は変わらない。
+     */
+    /** SIGTERM から SIGKILL までの猶予 (REAP_BUDGET_SECONDS の内側) */
+    /** 回収 poll の間隔 (マイクロ秒) */
+    /**
+     * @param  array<string, mixed>  $requestBody
+     *
+     * @throws BarrierTimeoutException|ConcurrencyProtocolException|RuntimeException
+     */
+        // ★`#[\SensitiveParameter]` は**例外の trace 側**の穴を塞ぐ。メッセージの伏せ字
+        //   の環境では文字列引数がそのまま `getTraceAsString()` へ出る (= 別経路である)。
+        #[\SensitiveParameter] string $plainApiKey,
+        #[\SensitiveParameter] array $requestBody,
+        // ★middleware と**同一規則**で親が期待 hash を持つ (`Request::path()` は先頭の `/` を含まない)。
+        /** @var list<string> $secretPaths */
+        /** @var array<string, ProbeProcess> $processes */
+        /** @var array<string, string> $nonces */
+                //   go token は**ここに無い** (親は ready を全部検証した後に初めて作る)。
+                hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000),
+            // ★**唯一の出口で 1 回だけ**伏せ字にする (choke point)。
+            // ★回収は**作業の失敗の後でも必ず**行う。回収そのものが失敗したときは
+    /**
+     * 合図の待ち合わせと受理条件の検査 (回収は呼び出し側の責務)。
+     *
+     * @param  array<string, ProbeProcess>  $processes
+     * @param  array<string, string>  $nonces
+     */
+        // 2. **ここで初めて** go token を作る (事前に子へ渡らない)
+        // 受理条件 3: 守りたい層以外の無効化と DB 座標、および**送り先が親の決めた面**であること
+    /**
+     * 待機中に毎周回呼ぶ中断条件 (締切を待たずに抜ける)。
+     *
+     * ★**二重実行の判定を子の生死より先**に置く。探している退行を「子が死んだ」という
+     *   別の診断で隠さないためである。
+     *
+     * @param  array<string, ProbeProcess>  $processes
+     * @return Closure(): void
+     */
+                // ★停止を観測した**後に**列挙し直す。子は「out を置く」→「終了する」の順で
+    /**
+     * 現れている完成合図の名前 (未知の完成合図があれば拒否する)。
+     *
+     * @return list<string>
+     */
+    /**
+     * `entered` がちょうど 1 子ぶん現れるまで待ち、その child ID を返す。
+     *
+     * @param  array<string, string>  $nonces
+     * @param  Closure(): void  $abort
+     */
+    /**
+     * 現れている `entered` の child ID。
+     *
+     * @return list<string>
+     */
+    /**
+     * `out` を待って観測へ変換する (生の JSON も返す = stdout との突合に使う)。
+     *
+     * @param  Closure(): void  $abort
+     * @return array{string, ConcurrentProbeObservation}
+     */
+    /** 作業の残り時間 (絶対 deadline から算出。0 以下なら例外) */
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
+        // 段 0: **1 件目の失敗で即 throw しない** (抜けると 2 件目の削除が省略され、
+        $reapDeadline = $now + (int) (self::REAP_BUDGET_SECONDS * 1_000_000_000);
+        $graceDeadline = min($reapDeadline, $now + (int) (self::REAP_GRACE_SECONDS * 1_000_000_000));
+        // ★**問題を集めてから 1 つの例外へ載せる**。先に見つかった 1 つで打ち切ると、
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
+    /**
+     * @param  array<string, ProbeProcess>  $processes
+     * @return list<string>
+     */
+    /**
+     * 診断へ出る前に、例外メッセージの中の既知の秘密を伏せ字にする (**唯一の choke point**)。
+     *
+     * ★子は untrusted である。合図の中身・観測の値・未知の完成合図の名前・stderr など、
+     *   **子が書いた文字列は例外メッセージのどこにでも入りうる**。生成箇所ごとに伏せ字を撒くと
+     *   必ず撒き漏らすので、**唯一の出口 (`run()`) で 1 回だけ**通す。
+     * ★**型は保つ** (呼び出し側とテストは型で分岐する)。作り直すのは本ハーネスの 2 型だけで、
+     *   それ以外の型 (`JsonException` 等) は本ハーネスがメッセージを組み立てていないので触らない。
+     * ★**previous は引き継がない**。previous 側のメッセージまでは作り直せないので、
+     *   伏せ字にできない文字列を連鎖に残すほうが危ない (作業の失敗は回収側の
+     *   `reapFailed()` が伏せ字済みの実体を previous に持つ)。
+     * ★**保証しないもの**: 伏せられるのは完全一致で現れた既知の秘密だけである。
+     *   切り詰められた断片は一致しないので、**子が message / trace を出さない**ことと
+     *   合わせて初めて閉じる。
+     *
+     * @param  list<string>  $secrets
+     */
+    private static function redactedForDiagnostics(Throwable $e, #[\SensitiveParameter] array $secrets): Throwable
+    /**
+     * 既知の秘密を伏せ字にする。
+     *
+     * ★一時ファイルを消しても**CI のログは残る**ので、秘密の後始末はファイル経路だけでは閉じない。
+     * ★**長い秘密から順に**置換する (短い秘密が長い秘密の一部だったときに、
+     *   置換済みの伏せ字を壊さないため)。
+     *
+     * @param  list<string>  $secrets
+     */
+    private static function redactSecrets(string $text, #[\SensitiveParameter] array $secrets): string
+            // ★symlink は**辿らずに** unlink する。`is_dir()` はディレクトリへの symlink でも
diff --git a/tests/Support/Concurrency/ConcurrencyProtocolException.php b/tests/Support/Concurrency/ConcurrencyProtocolException.php
@@ -0,0 +1,124 @@
+/**
+ * 実プロセス並行テストの**プロトコルが破られた**。
+ *
+ * ★{@see BarrierTimeoutException} と型を分けている。とくに {@see self::doubleExecution()} は
+ *   本ハーネスが探している退行そのものなので、締切超過という紛らわしい形では出さない。
+ */
+    /**
+     * 探している退行そのもの: 本処理へ 2 本とも入った。
+     *
+     * @param  list<string>  $enteredSignals
+     */
+    public static function childDiedEarly(string $childId, ?int $exitCode, #[\SensitiveParameter] string $stderr): self
+        #[\SensitiveParameter] string $actual,
+    public static function goTokenMismatch(string $childId, string $expected, #[\SensitiveParameter] string $actual): self
+    public static function unexpectedObservation(#[\SensitiveParameter] string $reason): self
+    /**
+     * 許可集合に無い完成合図が現れた (無視ではなく拒否する)。
+     *
+     * @param  list<string>  $names
+     */
+    public static function unknownSignal(#[\SensitiveParameter] array $names): self
+    /**
+     * 回収に失敗した (問題が複数あればすべて 1 つの例外へ載せる)。
+     *
+     * ★**先に見つかった 1 つで打ち切らない**。秘密を消せなかったことと停止を確認できない子が
+     *   残っていることは**別々の危険**であり、片方だけを報告すると残りが診断から消える。
+     * ★元の失敗 ($previous) は畳んで捨てない (回収の失敗が原因を隠さないようにする)。
+     *
+     * @param  list<string>  $problems
+     */
diff --git a/tests/Support/Concurrency/ConcurrentProbeObservation.php b/tests/Support/Concurrency/ConcurrentProbeObservation.php
@@ -0,0 +1,248 @@
+/**
+ * 子プロセス 1 本ぶんの一次観測。
+ *
+ * ★勝者の判定は**行の最終状態ではなくこの一次観測**で行う (正典・家系の作法)。
+ *   行だけを見ると「2 本とも本処理を実行したが後着が上書きした」形と区別がつかない。
+ * ★{@see self::fromDecodedJson()} は **fail-closed**。必須キーの欠落・型違い・**未知キー**の
+ *   いずれでも例外にする (子と親のプロトコル退行を黙って受け入れない)。
+ * ★**キャストで救わない**。整数 cast の飽和で別の値が通る穴を家系が実際に踏んでいる。
+ */
+    /**
+     * 受理する JSON のキー (deny-by-default。過不足があれば例外)。
+     *
+     * @var list<string>
+     */
+        /** ★勝者は null、敗者は 'idempotency_in_progress' (409 は 3 コードあるので必須) */
+        /** ★入力のコピーではなく、認証後の ApiActorContext から観測した値 */
+        /** ★既定 store を**裏打ちする driver** (store 名だけでは名前と実体のずれを落とせない) */
+    /**
+     * @throws ConcurrencyProtocolException 解釈できない観測は通さない
+     */
+    public static function fromDecodedJson(#[\SensitiveParameter] mixed $value): self
+        /** @var array<string, mixed> $value */
+    /** 起動時の割り当て・親が出した go token と食い違ったら通さない */
+    /**
+     * 敗者としての条件 (release の前提)。満たさなければ例外。
+     *
+     * ★`idempotency_conflict` / `idempotency_indeterminate` は通さない。
+     *   409 は 3 コードあり、body 違いの conflict でも「勝者 1 / 敗者 1」は成立して
+     *   **緑になってしまう**ためである。
+     */
+    /**
+     * 守りたい層以外が無効化されていたか (要素 (3))。
+     *
+     * ★言えるのは「Laravel の既定 cache を経由するプロセス間共有ロックが使えない」までである
+     *   (「アプリ側ロックが 1 つも無い」とは言えない)。
+     * ★**store 名と driver の 2 つ**を見る。名前だけだと「array という名前の store が
+     *   実は別の driver で裏打ちされている」形を落とせない。
+     *   (詳細設計は 2 つ目に `Cache::getDefaultDriver()` を挙げていたが、その戻り値は
+     *   `config('cache.default')` そのもので同じ事実の写しにすぎず、
+     *   かつ cache API を呼ぶと `CachePayloadPlainDataGateTest` の L3 目録への登録が要る。
+     *   採用時債務のファイルを触ることになるため、より強い設定側の観測へ置き換えた)
+     */
+    /** 親が渡した DB 座標と完全一致するか (開発 DB 到達の検出) */
+    /**
+     * @param  array<string, mixed>  $value
+     */
+    private static function stringValue(#[\SensitiveParameter] array $value, string $key): string
+    /**
+     * @param  array<string, mixed>  $value
+     */
+    private static function intValue(#[\SensitiveParameter] array $value, string $key): int
diff --git a/tests/Support/Concurrency/ConcurrentProbeResult.php b/tests/Support/Concurrency/ConcurrentProbeResult.php
@@ -0,0 +1,60 @@
+/**
+ * runner の結果。
+ *
+ * ★nonce / go token は**持たない**。同一性の検査 (`assertIdentity`) は runner の中で
+ *   完結しており、内部プロトコルをテストへ漏らさない。
+ * ★代わりに、行の裏取り (`idempotency_keys` のスコープと request_hash) に要る値だけを渡す。
+ */
+    /**
+     * @param  array<string, ConcurrentProbeObservation>  $observations  childId => 観測
+     */
+        /** 親が middleware と同一規則で計算した期待 hash */
+    /**
+     * `entered_handler` で勝者・敗者に分ける (ちょうど 1:1 でなければ例外)。
+     *
+     * @return array{ConcurrentProbeObservation, ConcurrentProbeObservation} [勝者, 敗者]
+     *
+     * @throws ConcurrencyProtocolException
+     */
diff --git a/tests/Support/Concurrency/OutOfTransactionFixtures.php b/tests/Support/Concurrency/OutOfTransactionFixtures.php
@@ -0,0 +1,208 @@
+/**
+ * テストの transaction の外に検体を作る (正典 v1 の要素 (2))。
+ *
+ * `RefreshDatabase` が検体を**未コミットの transaction の中**に置くため、子プロセスからは
+ * 見えない。既定接続の設定を**複製した別名接続**を作り、**閉じた区間だけ**既定接続を
+ * そこへ差し替えて生成し、その接続の**明示トランザクションで commit** する。
+ *
+ * ★**片付けは呼び出し側の責任**である。ここで作った行は `RefreshDatabase` の
+ *   rollback では消えない。放置すると同一 worker の後続テストへ漏れる。
+ * ★既定接続の差し替えは**閉じた区間だけ**で、finally で必ず元へ戻す。
+ *   **失敗時は別名接続を disconnect + purge** し、成功時だけ後続の読み取り・cleanup 用に維持する。
+ *
+ * **保証しないもの**: 掃除するのは下の 8 表だけである。検体の生成経路が別の表へ
+ * 行を足すようになったら、この一覧を同じ変更で増やす必要がある
+ * (増やし忘れは {@see self::residueCounts()} では映らない = 8 表の外は見ていない)。
+ */
+    /**
+     * 削除と残留検査の対象 (FK 安全な順序。表名 => 絞り込む列)。
+     *
+     * 順序が load-bearing である理由 (FK を全数実読した結果):
+     * - `organizations.laratrust_team_id` は **restrictOnDelete** なので
+     *   「組織を消せば全部消える」は成り立たない。**組織 → teams の順**でなければ削除できない
+     * - `role_user.user_id` には FK が無い (polymorphic) ので、利用者を消しても連鎖しない
+     *   (`teams` 削除の cascade で消える経路に依存する)
+     * - `organizations` は softDeletes を持つので Eloquent の `delete()` では物理削除されない
+     *   (本クラスは query builder で物理削除する)
+     *
+     * @var array<string, string>
+     */
+    /**
+     * 検体を transaction の外へ作る。
+     *
+     * @template T
+     *
+     * @param  Closure(): T  $callback
+     * @return T
+     */
+    /** 別名接続で読む (親の裏取り用。既定接続の transaction の中を見に行かない) */
+    /**
+     * 呼び出し側が finally で呼ぶ。冪等 (何度呼んでも安全)。
+     *
+     * ★**削除したあと、自分で残留ゼロを検査する**。呼び出し側のテストだけに任せると、
+     *   見本テストの後始末の完全性が「別のテストが緑であること」に依存してしまう。
+     *   1 行でも残っていれば例外にする (後続テストを汚した状態で静かに通らない)。
+     */
+    /**
+     * 8 表それぞれの残留件数を返す (表名 => 件数)。
+     *
+     * ★`cleanup()` から切り出して**公開**しているのは、検査器そのものを検査できるようにするため。
+     *   `cleanup()` の中に埋め込むと「削除してから数える」経路でしか叩けず、
+     *   「残留があるのに 0 と数える」退行を検出できない。
+     *
+     * @return array<string, int>
+     */
+    /** 別名接続を登録する (既定接続設定の**完全な複製**。座標は 1 文字も変えない) */
+    /**
+     * 別名接続の設定が無ければ既定接続から複製する (cleanup / connection の入口で使う)。
+     */
+    /** 表ごとの絞り込みに使う主キー / 外部キーの値 */
diff --git a/tests/Support/Concurrency/ProbeDatabaseCoordinates.php b/tests/Support/Concurrency/ProbeDatabaseCoordinates.php
@@ -0,0 +1,198 @@
+/**
+ * DB 接続座標 (親の期待値も子の観測も同じ型で持ち、同型どうしで厳密比較する)。
+ *
+ * ★`db_port` は `int`、他は `string` である。`array<string, string>` で持つと
+ *   厳密比較のために暗黙のキャストが要り、「外部観測をキャストで救わない」という
+ *   本設計の方針と矛盾する。
+ */
+    /** 観測 JSON でのキー名 (親子で同じ綴りを使うための唯一の正本) */
+        /** ★空文字のみ許可 (非空は fail-closed) */
+    /**
+     * **実行中のアプリの実接続設定**から作る (信頼済み設定の正規化)。
+     *
+     * 親も子も同じ経路で観測する — 値が違えば「別の DB を向いている」ことがそのまま差になる
+     * (同じ抽出規則で読むからこそ、比較が座標の差だけを映す)。
+     *
+     * ★`config` の port は数値文字列でありうる。**黙ってキャストせず**、
+     *   数値文字列であることと **1〜65535 の範囲**を明示的に検証してから int 化する。
+     *   これは「信頼済みの設定を正規化する」経路であり、外部 JSON とは扱いが違う。
+     */
+    /**
+     * 子側の観測 JSON から作る (**外部入力なので fail-closed**)。
+     *
+     * ★こちらは `is_int()` を要求し、**キャストで救わない**
+     *   (数値文字列 "5432" は通さない。整数 cast の飽和で別の値が通る穴を家系が踏んでいる)。
+     *
+     * @param  array<string, mixed>  $value
+     *
+     * @throws ConcurrencyProtocolException
+     */
+    public static function fromDecodedJson(#[\SensitiveParameter] array $value): self
+    /** 全項目の厳密比較 */
+    /**
+     * 観測 JSON へ載せる形 (キーの綴りを 1 か所に閉じる)。
+     *
+     * @return array<string, string|int>
+     */
+    /** 人が読める形 (不一致の診断に使う) */
+    /**
+     * @param  array<string, mixed>  $config
+     */
diff --git a/tests/Support/Concurrency/ProbeEnvironment.php b/tests/Support/Concurrency/ProbeEnvironment.php
@@ -0,0 +1,346 @@
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
+    /**
+     * 子の env ファイルへ書いてよいキー (deny-by-default)。
+     *
+     * @var list<string>
+     */
+    /**
+     * 子へ渡してよい**プロセス環境変数** (`env -i` で空にしたうえでこれだけ載せる)。
+     *
+     * ★この定数は「起動側が載せる分」の宣言であり、**子が実際に受け取った分**は
+     *   子自身が段 6 で観測して突き合わせる (組み立て側の配列を見ても `env -i` の退行は映らない)。
+     *
+     * @var list<string>
+     */
+    /** env ファイルの名前 (workspace 内で固定) */
+    /**
+     * env ファイルの 1 行を受理する唯一の書式。
+     *
+     * 値の中身は「引用符・バックスラッシュ・ドル記号以外の 1 文字」か
+     * 「**encoder が実際に作る 3 種の escape** (`\\` / `\"` / `\$`)」の並びだけである。
+     * 素の `$` を許さないのは、encoder が必ず escape する以上 canonical な出力には現れず、
+     * かつ phpdotenv が二重引用符の中で `${VAR}` を展開する = 実効値が食い違う経路だからである。
+     */
+    private const string ENV_LINE_PATTERN = '/^([A-Z][A-Z0-9_]*)="((?:[^"\\\\$]|\\\\[\\\\"$])*)"$/';
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
+        // ★前提検査 2: 既存の単一点ガードを**親側でも**通す (allowlist 一致 + dev denylist)。
+    /**
+     * キー集合が許可一覧と**完全一致**することを検査する。
+     *
+     * 「許可外が無い」だけでは足りない — 必須の DB キーが**欠落**した場合、
+     * その穴は子の `.env` 読み込みで埋まりうる (まさに塞ぎたい形)。
+     *
+     * @param  array<string, string>  $values
+     */
+    /**
+     * 値に改行 / CR が入っていたら**書かずに例外**にする。
+     *
+     * env ファイルは 1 行 1 キーなので、値の改行は**別キーの注入**になる。
+     *
+     * @param  array<string, string>  $values
+     */
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
+    public static function encodeLine(string $key, #[\SensitiveParameter] string $value): string
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
+    public static function writeProtectedFile(string $path, #[\SensitiveParameter] string $contents): void
+            // ★`@` を付けるのは、既存ファイルでの失敗を**自前の fail-closed 例外**で表すため。
+    /**
+     * ディレクトリ 0700・env ファイル 0600・入力ファイル 0600 でなければ例外 (子を起こさない)。
+     */
+    /** パスの permission bits (取得できなければ -1) */
+    /** 子プロセスの実行スクリプトの絶対パス */
diff --git a/tests/Support/Concurrency/ProbeLaunchSpec.php b/tests/Support/Concurrency/ProbeLaunchSpec.php
@@ -0,0 +1,37 @@
+/**
+ * 子 1 本の起動仕様 (偽物も同じものを受け取る)。
+ *
+ * ★起動仕様を**値**にしてあるのが、失敗経路の検査で子プロセスを 1 本も起こさずに
+ *   runner の調停と回収を固定できる理由である (偽の {@see ProbeProcessFactory} が
+ *   同じ仕様を受け取り、合図を書く側を演じられる)。
+ */
+        /** 合図・出力・env ファイルの置き場 */
diff --git a/tests/Support/Concurrency/ProbeProcess.php b/tests/Support/Concurrency/ProbeProcess.php
@@ -0,0 +1,42 @@
+/**
+ * 子プロセス 1 本の抽象。
+ *
+ * ★**操作を分けている**のは、失敗経路の検査が「runner が停止・強制終了・待機を
+ *   それぞれ要求したこと」を**順序込みで固定できる**ようにするためである。
+ *   1 メソッドに束ねると、検査は「何かを呼んだ」しか言えない。
+ *
+ * **保証の境界**: 失敗経路の検査が主張するのは「runner がこの抽象へ要求すること」までである。
+ * 実 OS プロセスに対するシグナルの実効性は**保証範囲外**とする
+ * (実プロセスを起こすテストを増やすと正典の要素 (6) に反するため踏み込まない)。
+ */
+    /** SIGTERM */
+    /** SIGKILL */
+    /**
+     * 上限つきで終了を待ち、終了コードを返す (時間内に終わらなければ null)。
+     *
+     * @param  float  $seconds  0 以上。0 は「1 度だけ状態を確かめる」を意味する
+     */
diff --git a/tests/Support/Concurrency/ProbeProcessFactory.php b/tests/Support/Concurrency/ProbeProcessFactory.php
@@ -0,0 +1,17 @@
+/**
+ * 子プロセスの作り手。
+ *
+ * 本番経路の実装は {@see SymfonyProbeProcessFactory} ただ 1 本で、
+ * {@see ConcurrencyProbeRunner::run()} は引数が `null` のときだけそれを作る
+ * (偽物を差す注入点と本番経路の分岐を 1 か所に留める)。
+ */
diff --git a/tests/Support/Concurrency/ProcessBarrier.php b/tests/Support/Concurrency/ProcessBarrier.php
@@ -0,0 +1,225 @@
+/**
+ * 実プロセス並行テストの合図の待ち合わせ (正典 v1 の要素 (1)(4)(5))。
+ *
+ * 規律 7 点:
+ * 1. ready は**子ごと**に分ける (共有 ready だと片方だけ準備できた状態で go を出せてしまい、
+ *    「全員の準備を確認してから同一の合図で解き放つ」という最重要前提が**緑のまま**壊れる)
+ * 2. 存在だけでなく**中身を照合**する (空・別 child・誤 nonce を通さない。照合は呼び出し側が行う)
+ * 3. 待ちのループでは**毎回 clearstatcache()** する — 捨てないと合図に気付くのが遅れ、
+ *    2 本の実行が重ならず並行テストの意味が消える (正典が名指しする作法)
+ * 4. 締切は**単調時計** (hrtime) で測る (壁時計は補正で戻りうる)
+ * 5. 合図は書きかけ用ディレクトリへ書いてから `link()` で配置する (書きかけを相手に見せない)
+ * 6. 名前は {@see SignalName} でしか作れない (このクラスは string の名前を受け取らないし、
+ *    名前を作る二重入口も持たない)
+ * 7. **同じ合図を 2 回置けない** (`rename()` は既存を上書きするので `link()` を使う。
+ *    ready や out の二重送信が黙って隠れるのを塞ぐ)
+ *
+ * ★**置き場所を 2 つに分ける**: 完成合図は signals/、書きかけは partial/。
+ *   同じディレクトリに置くと、完成ファイルの列挙が書きかけを拾って
+ *   二重実行の判定が壊れる。列挙を安全にするための分離である。
+ * ★読み取りは**注入可能な読み手**越しに行う。`file_get_contents() === false` を
+ *   決定的に再現するためで、権限 (chmod 000) に依存する検査は root 実行で不安定になる。
+ *
+ * **保証しないもの**: 合図の順序関係だけを保証する。実際に処理が重なったかどうかは
+ * 呼び出し側 ({@see ConcurrencyProbeRunner}) が entered / release の 3 段で構成する。
+ */
+    /** 待ちのポーリング間隔 (マイクロ秒) */
+    /**
+     * @param  (callable(string): string|false)|null  $reader  既定は file_get_contents
+     */
+    /**
+     * 合図の置き場所 (signals/ と partial/) を作る。既に在れば何もしない。
+     */
+    /**
+     * 合図を置く (partial/ へ書いてから signals/ へ配置)。
+     *
+     * ★配置に `rename()` を使わない。POSIX の `rename()` は**既存ファイルを上書きする**ので、
+     *   同じ合図の 2 回目の送信が黙って隠れる (ready や out の二重送信を見逃す)。
+     *   `link()` は **target が既に在れば失敗する**ので、TOCTOU のある `is_file()` 判定を
+     *   挟まずに二重配置を弾ける。同一 FS 内なので hard link が使える。
+     */
+    public function signal(SignalName $name, #[\SensitiveParameter] string $payload): void
+            // ★失敗の**分類**を target の存在で行う。すべてを二重配置に倒すと、
+    /**
+     * 合図が現れるまで待ち、その中身を返す。
+     *
+     * @param  float  $remainingSeconds  呼び出し側が持つ**絶対 deadline** からの残り時間
+     * @param  (callable(): void)|null  $abortIf  待機中に毎周回呼ぶ中断条件
+     *                                            (二重実行の検出・子の異常終了など。
+     *                                            呼び先が例外を投げれば締切を待たずに抜ける)
+     *
+     * @throws BarrierTimeoutException 締切を超えた
+     * @throws ConcurrencyProtocolException 合図はあるのに読めない
+     */
+        $deadline = hrtime(true) + (int) ($remainingSeconds * 1_000_000_000);
+    /**
+     * 完成合図のディレクトリを**列挙**し、現れている名前を返す。
+     *
+     * ★prefix の glob は採らない。書きかけは別ディレクトリなので、ここでの列挙は
+     *   完成ファイルだけを見る。
+     * ★**許可集合に無い完成ファイルが 1 つでもあれば例外**にする
+     *   (未知の child ID の合図を「無視」ではなく「拒否」にする)。
+     *
+     * @param  list<SignalName>  $allowed  許可される完成合図の全集合
+     * @return list<SignalName> 現れている合図
+     *
+     * @throws ConcurrencyProtocolException 未知の完成ファイルがある
+     */
+    /**
+     * 合図を読む。**読めない合図は空として通さず例外**にする (fail-closed)。
+     *
+     * 合図はあるのに読めない = 観測が成立していない。空として通すと後続の照合が
+     * 別の理由で落ちて原因が隠れる。
+     */
diff --git a/tests/Support/Concurrency/SignalName.php b/tests/Support/Concurrency/SignalName.php
@@ -0,0 +1,97 @@
+/**
+ * 合図の名前 (これ以外の形は作れない)。
+ *
+ * ★{@see self::make()} が**唯一の生成口**である ({@see ProcessBarrier} に name() のような
+ *   二重入口は置かない)。`ProcessBarrier` のメソッドはすべて `SignalName` を受け取り
+ *   `string` を受けない。これで `/` や `..` を含む名前は**型の段階で作れない**
+ *   (入口ごとの再検証が要らない)。
+ * ★種別ごとに child ID の要否が違う。`go-a` や `ready` (child ID 無し) のような
+ *   語彙としては正しいがプロトコル上は不正な組合せも作れない。
+ * ★child ID は**実在する 2 つに限定**する (正規表現で 26 文字を許すと `ready-c` が作れてしまい、
+ *   「生成できるのは 8 通りだけ」という保証が実体と食い違う)。
+ *
+ * 生成できるのは次の **8 通りだけ**である:
+ *   go / release / ready-a / ready-b / entered-a / entered-b / out-a / out-b
+ */
+    /**
+     * child ID を**取らない**種別 (プロセス全体で 1 つの合図)。
+     *
+     * @var list<string>
+     */
+    /**
+     * child ID を**必ず取る**種別 (子ごとの合図)。
+     *
+     * @var list<string>
+     */
+    /**
+     * 実在する child ID (固定 2 本。N 本への一般化はしない)。
+     *
+     * @var list<string>
+     */
+    /** @param non-empty-string $value */
+    /**
+     * 唯一の生成口。
+     *
+     * @throws \InvalidArgumentException 種別と child ID の組合せが 8 通りの外
+     */
+    /**
+     * 許可される完成合図の全集合 (未知の完成ファイルの検出に使う)。
+     *
+     * @return list<self> ちょうど 8 件
+     */
diff --git a/tests/Support/Concurrency/SymfonyProbeProcess.php b/tests/Support/Concurrency/SymfonyProbeProcess.php
@@ -0,0 +1,97 @@
+/**
+ * {@see Process} を包む唯一の実装。
+ *
+ * ★**`waitFor()` は Symfony の `wait()` を包まない**。`Process::wait()` は秒数を受け取る
+ *   API ではない (`waitUntil()` は述語を取るがタイムアウトは Process 自身の設定に依る)。
+ *   ここでは **`isRunning()` と単調時計 (`hrtime`) で bounded wait を自前実装する**
+ *   (ポーリング + 上限)。`$seconds` に 0 を渡した場合は 1 度だけ状態を確かめて返す。
+ * ★シグナル送出は生存しているときだけ行う (`Process::signal()` は停止済みに投げると例外)。
+ *   既に止まっている子へ送らないことは回収の契約を弱めない (止まっているのが目的だから)。
+ */
+    /** 終了待ちのポーリング間隔 (マイクロ秒) */
+        $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
diff --git a/tests/Support/Concurrency/SymfonyProbeProcessFactory.php b/tests/Support/Concurrency/SymfonyProbeProcessFactory.php
@@ -0,0 +1,46 @@
+/**
+ * 実プロセスを起こす唯一の実装。
+ *
+ * 起動コマンドは `env -i` で環境を作り直し、許可 3 キー
+ * ({@see ProbeEnvironment::ALLOWED_PROCESS_ENV_KEYS}) だけを載せる (遮断の段 5)。
+ *
+ * ★秘密 (plain API key / request body) は **argv に載せない** (プロセス一覧から読める)。
+ *   0700 のディレクトリ配下の 0600 の入力ファイルへ置き、そのファイル名だけを argv に載せる。
+ * ★Symfony 側のタイムアウトは無効化する (`null`)。締切は runner が単一の絶対 deadline で
+ *   持っており、2 か所に締切を置くと「どちらで落ちたか」が読めなくなる。
+ */
diff --git a/tests/Support/Concurrency/idempotency-claim-probe.php b/tests/Support/Concurrency/idempotency-claim-probe.php
@@ -0,0 +1,299 @@
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
+// [段 6] bootstrap の**前**に、子が実際に受け取ったプロセス環境を検査する。
+    // ★この段のメッセージは**環境変数のキー名**しか含まない (値は 1 つも載らない) ので出してよい。
+    // [段 7] env ファイルを**自前の厳格パーサ**で解析し、bootstrap 前に DB 名を検査する。
+    // ★合図の締切は**単調時計**で測る (壁時計は NTP 補正で戻りうる)。
+    $deadline = hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000);
+    //        `APP_CONFIG_CACHE` は一時ディレクトリ配下の**存在しない絶対パス**
+    /** @var Application $app */
+    // [段 9] **ready を出す前に**「守りたい層以外の無効化」と実効 DB 座標を検査する。
+    // ★**cache API は 1 つも呼ばない**。設定だけを読む形にしてあるのは、
+    //   `config('cache.default')` そのもの (`CacheManager::getDefaultDriver()`) で**同じ事実の写し**にすぎない。
+    //   「array という名前の store が実は別の driver で裏打ちされている」形まで落とせるので**より強い**。
+    /** 認証結果 (ApiActorContext) から api_key_id を観測する (入力のコピーではない) */
+    // probe route を**この子の app インスタンスへ**登録する。
+    // ハンドラは**テスト側コード**なので、アプリコードを 1 バイトも触らずに待たせられる。
+    // ★middleware 列は「**冪等 middleware の前提を満たす最小 probe 経路**」である。
+    //   乱れて測りたいものと別の分岐になるため入れない。**「本番同等」とは主張しない**。
+    // ★`$goToken` は**参照キャプチャ**である。closure を定義する時点ではまだ go を待っておらず、
+    //   値キャプチャだと**空文字を合図に書いてしまう**)。
+    //   先頭の Assert は「万一 go より先に handler へ入った」場合に**黙って空を書かず落ちる**ための門である。
+        // これで敗者は**勝者の claim 行が processing のまま在る間に必ず claim へ到達する**。
+    // ★第 3 引数 ($parameters) は**空配列**である。ここへ body を渡すと form parameter として
+    //   raw bytes は**第 7 引数 (content)** へ渡す。
+        // ★middleware と同一規則で、**実際に送った Request** から計算する
+    // 観測を書く。stdout と out ファイルへ**同じ JSON** を出す (親が一致を検査する)。
+    // ★**メッセージも trace も出さない**。子は plain API キー / raw body / 実鍵を握っており、
+    //   `getTraceAsString()` は文字列引数を 15 文字まで含める = 秘密の先頭が CI ログへ残る。
+    //   完全一致では伏せられないので、**出さない側**で閉じる。
diff --git a/tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php b/tests/Unit/Support/Concurrency/ConcurrencyHarnessFailurePathTest.php
@@ -0,0 +1,1424 @@
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
+/** 全子で共有する呼び出し記録 (順序と poll の交互性を固定するため) */
+    /** @var list<array{child: string, op: string}> シグナルと待機だけ (poll は含めない) */
+    /** @var list<string> `isRunning()` の呼び出し順 (単一ループかどうかを見る) */
+    /** @return list<string> */
+    /** poll 記録が a と b を行き来した回数 (単一ループなら大きく、逐次処理なら 1 になる) */
+/**
+ * 台本で動く偽の子。
+ *
+ * ★台本は `isRunning()` の呼び出しごとに 1 歩進む。runner は待機ループの中断条件で
+ *   毎周回 `isRunning()` を呼ぶので、これが「子が動いた」ことの決定的な差し込み点になる
+ *   (実時間に依存しないので締切の検査が安定する)。
+ */
+    /** @var array<string, mixed> 台本が使う状態 */
+    /** @param Closure(self): void $script */
+        #[SensitiveParameter] private readonly string $stderr = '',
+    /** 台本の終わり: out 合図を置き、stdout を確定して停止する */
+/** 偽の子を作る (作った子を child ID で引けるようにしておく) */
+    /** @var array<string, ScriptedProbeProcess> */
+    /** @param Closure(ProbeLaunchSpec, HarnessCallLog): ScriptedProbeProcess $make */
+/** @return array<string, mixed> */
+/**
+ * 子が返す観測 JSON を組み立てる (親の受理条件をすべて満たす正例)。
+ *
+ * @param  array<string, mixed>  $overrides
+ */
+/**
+ * 正常なプロトコルを演じる台本。
+ *
+ * @param  array<string, mixed>  $observationOverrides
+ * @return Closure(ScriptedProbeProcess): void
+ */
+            // ★ready を出す時点で go が**まだ無い**ことを記録する
+/**
+ * 偽 factory を差して runner を走らせる。
+ *
+ * @param  array<string, mixed>  $requestBody
+ */
+    #[SensitiveParameter] array $requestBody = ['title' => '並行 claim の検体'],
+    #[SensitiveParameter] string $plainApiKey = 'harness-plain-key',
+/**
+ * 例外の連鎖 (previous を含む) の全文。
+ *
+ * ★**メッセージと trace の両方**を集める。メッセージの伏せ字だけでは trace の引数に残った
+ *   秘密を捕まえられない (`zend.exception_ignore_args=0` の環境では文字列引数が出る)。
+ */
+function harnessThrowableText(?Throwable $e): string
+        $text .= $e::class.': '.$e->getMessage()."\n".$e->getTraceAsString()."\n";
+        // ★ProcessBarrier の構築は signals/ の実在を要求するので、**構築後に**消す。
+    // ★必須キーの**欠落**も落とす (穴は子の .env 読み込みで埋まりうる = まさに塞ぎたい形)
+        //   「phpdotenv が同じ値として読む」ことは言えない。**双方**に通して固定する。
+test('群2-16: 未知の DB_* / APP_* がプロセス環境に混入していたら拒否する (env -i の退行)', function (): void {
+/**
+ * 受理条件をすべて満たす観測 (群 3 の基準値)。
+ *
+ * @param  array<string, mixed>  $overrides
+ * @return array<string, mixed>
+ */
+    // ★2 つの負例は**独立**でなければならない。片方だけの検査に退行しても
+/**
+ * 入力ファイルは回収で消えるので、台本が読んだ内容を控えておく。
+ *
+ * @return array<string, mixed>
+ */
+    // ★body 違いの conflict は「勝者 1 / 敗者 1」まで成立して**緑になりうる**形である
+    //   単一の絶対 deadline (1.0 秒) なら**合計 1.0 秒**で打ち切られる。
+/** 0500 のディレクトリでも書けてしまう実行者 (root 等) では削除失敗を再現できない */
+/** workspace を書き込み不可にして秘密の unlink を失敗させる台本 */
+        // ★**1 件目の失敗で抜けない**ことを固定する。抜けると 2 件目以降の削除が省略され、
+    // ★**子は untrusted** なので、stderr だけでなく**子が書いた合図の中身**も同じ扱いにする。
+    $text = harnessThrowableText($thrown);
+    $text = harnessThrowableText($thrown);
```

## 質問

Round 3 の 2 件は解消したか。残る懸念があれば指摘し、無ければ全体判定を APPROVED と書け。
