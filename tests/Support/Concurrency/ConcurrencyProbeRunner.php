<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Closure;
use RuntimeException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 実プロセス 2 本を barrier で同期させて走らせ、一次観測を回収する。
 *
 * 段取り:
 *  1. 子ごとの ready を全員ぶん待ち、**中身の nonce を照合**する
 *  2. **ここで初めて** go token をランダム生成し、go を 1 つ置く
 *     (事前に渡さないので、go を読まずに正しい token を書くことは構造的にできない)
 *  3. entered を待つ (割り当て済みの完成名だけを調べる。prefix の glob は使わない)
 *  4. **反対側の out を待ち、中身を完全に検査する**
 *  5. 検査をすべて通ったら release を置く
 *  6. 両方の終了を待ち、exit code 0 と stdout/out の一致を確かめて観測を返す
 *
 * ★4 の検査を通す前に release しない。「出てきたから release して、あとから赤くする」形は
 *   結果的に赤にはなるがプロトコルの証拠が弱い。
 * ★3〜5 の待機中は**常に**「2 つ目の entered / 未知の完成合図 / 子の異常終了」を監視する
 *   (単一ファイルだけを待つブロッキングにすると、二重実行の即時検出という性質が失われる)。
 * ★締切は**単一の絶対 deadline** である。段ごとに更新すると総時間が締切を大幅に超える。
 *
 * **保証の言い方**: 回収について主張するのは
 * 「bounded な回収操作 (TERM / KILL / 上限つき poll) を必ず要求し、停止を確認できなければ
 * 失敗させる。秘密は成否にかかわらず必ず消す」までである。
 * 実 OS プロセスが実際に消えたことは保証範囲外とする。
 */
final class ConcurrencyProbeRunner
{
    /** **作業の締切** (子の起動 + 合図 + 要求 + 通常の終了待ちを打ち切る) */
    public const float DEFAULT_TIMEOUT_SECONDS = 60.0;

    /** 子の識別子 (固定 2 本。N 本への一般化はしない) */
    public const array CHILD_IDS = ['a', 'b'];

    /**
     * **回収専用の予算** (作業の締切とは独立に確保する)。
     *
     * ★作業の締切を回収にも使うと、**締切超過の瞬間に残り時間が 0** になり、
     *   まさに回収が必要な場面で kill 後の待機ができず子が残る。
     * ★この予算は**全子で共有する** (子ごとに 2 秒ではない)。
     *   回収はフェーズ単位で行うので、子数が増えても総時間は変わらない。
     */
    public const float REAP_BUDGET_SECONDS = 2.0;

    /** SIGTERM から SIGKILL までの猶予 (REAP_BUDGET_SECONDS の内側) */
    public const float REAP_GRACE_SECONDS = 1.0;

    /** 回収 poll の間隔 (マイクロ秒) */
    private const int REAP_POLL_INTERVAL_MICROSECONDS = 5_000;

    /**
     * @param  array<string, mixed>  $requestBody
     *
     * @throws BarrierTimeoutException|ConcurrencyProtocolException|RuntimeException
     */
    public static function run(
        string $idempotencyKey,
        // ★`#[\SensitiveParameter]` は**例外の trace 側**の穴を塞ぐ。メッセージの伏せ字
        //   (`redactedForDiagnostics()`) は trace の引数には効かず、`zend.exception_ignore_args=0`
        //   の環境では文字列引数がそのまま `getTraceAsString()` へ出る (= 別経路である)。
        #[\SensitiveParameter] string $plainApiKey,
        #[\SensitiveParameter] array $requestBody,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?ProbeProcessFactory $factory = null,
    ): ConcurrentProbeResult {
        Assert::stringNotEmpty($idempotencyKey);
        Assert::stringNotEmpty($plainApiKey);
        Assert::greaterThan($timeoutSeconds, 0.0);

        $suffix = bin2hex(random_bytes(6));
        $uri = 'api/v1/__concurrency_probe_'.$suffix.'__';
        $routeName = 'api.v1.__concurrency_probe_'.$suffix.'__';
        $rawBody = json_encode($requestBody, JSON_THROW_ON_ERROR);

        // ★middleware と**同一規則**で親が期待 hash を持つ (`Request::path()` は先頭の `/` を含まない)。
        $expectedRequestHash = hash('sha256', 'POST|'.$uri.'|'.$rawBody);

        // 遮断の段 1〜3 (親側)。ここで落ちたら子は 1 本も起きない。
        $envValues = ProbeEnvironment::envFileValues();

        $workspace = self::createWorkspace();

        // ★診断に載せてはいけない値の一覧。子の stderr を例外へ埋める前に必ず伏せ字にする
        //   (一時ファイルを消しても、CI のログには永続的に残るため)。
        $secrets = array_values(array_filter([
            $plainApiKey,
            $rawBody,
            $envValues['APP_KEY'] ?? '',
            $envValues['CIPHERSWEET_KEY'] ?? '',
            $envValues['DB_PASSWORD'] ?? '',
        ], static fn (string $secret): bool => $secret !== ''));

        /** @var list<string> $secretPaths */
        $secretPaths = [];
        /** @var array<string, ProbeProcess> $processes */
        $processes = [];
        /** @var array<string, string> $nonces */
        $nonces = [];

        try {
            $envFilePath = $workspace.'/'.ProbeEnvironment::ENV_FILE_NAME;
            $lines = '';
            foreach ($envValues as $key => $value) {
                $lines .= ProbeEnvironment::encodeLine($key, $value);
            }
            ProbeEnvironment::writeProtectedFile($envFilePath, $lines);
            $secretPaths[] = $envFilePath;

            $configCachePath = $workspace.'/config-cache-absent.php';
            $factory ??= new SymfonyProbeProcessFactory(base_path());

            foreach (self::CHILD_IDS as $childId) {
                $nonces[$childId] = bin2hex(random_bytes(16));

                $spec = new ProbeLaunchSpec(
                    workspaceDirectory: $workspace,
                    childId: $childId,
                    nonce: $nonces[$childId],
                    scriptPath: ProbeEnvironment::probeScriptPath(),
                    environmentDirectory: $workspace,
                    environmentFileName: ProbeEnvironment::ENV_FILE_NAME,
                    inputFileName: 'input-'.$childId.'.json',
                    configCachePath: $configCachePath,
                );

                // ★秘密 (plain API key / raw body) は argv に載せず 0600 の入力ファイルへ置く。
                //   go token は**ここに無い** (親は ready を全部検証した後に初めて作る)。
                ProbeEnvironment::writeProtectedFile($spec->inputFilePath(), json_encode([
                    'child_id' => $childId,
                    'nonce' => $nonces[$childId],
                    'route_name' => $routeName,
                    'uri' => $uri,
                    'raw_body' => $rawBody,
                    'idempotency_key' => $idempotencyKey,
                    'plain_api_key' => $plainApiKey,
                    'timeout_seconds' => $timeoutSeconds,
                ], JSON_THROW_ON_ERROR));
                $secretPaths[] = $spec->inputFilePath();

                // 遮断の段 4: 起動前の権限検査 (違えば子を起こさない)
                ProbeEnvironment::assertSafePermissions(
                    ProbeEnvironment::mode($workspace),
                    ProbeEnvironment::mode($envFilePath),
                    ProbeEnvironment::mode($spec->inputFilePath()),
                );

                $processes[$childId] = $factory->create($spec);
            }

            $result = self::conduct(
                new ProcessBarrier($workspace),
                $processes,
                $nonces,
                hrtime(true) + (int) ($timeoutSeconds * 1_000_000_000),
                $routeName,
                $uri,
                $idempotencyKey,
                $expectedRequestHash,
            );
        } catch (Throwable $e) {
            // ★**唯一の出口で 1 回だけ**伏せ字にする (choke point)。
            $e = self::redactedForDiagnostics($e, $secrets);

            // ★回収は**作業の失敗の後でも必ず**行う。回収そのものが失敗したときは
            //   その例外を投げる (元の失敗は previous に畳んで捨てない)。
            self::reap($processes, $workspace, $secretPaths, $e);

            throw $e;
        }

        self::reap($processes, $workspace, $secretPaths, null);

        return $result;
    }

    /**
     * 合図の待ち合わせと受理条件の検査 (回収は呼び出し側の責務)。
     *
     * @param  array<string, ProbeProcess>  $processes
     * @param  array<string, string>  $nonces
     */
    private static function conduct(
        ProcessBarrier $barrier,
        array $processes,
        array $nonces,
        int $workDeadlineNs,
        string $routeName,
        string $uri,
        string $idempotencyKey,
        string $expectedRequestHash,
    ): ConcurrentProbeResult {
        foreach ($processes as $process) {
            $process->start();
        }

        $abort = self::abortCondition($barrier, $processes);

        // 1. ready を全員ぶん待ち、中身の nonce を照合する
        foreach ($processes as $childId => $process) {
            $payload = $barrier->await(
                SignalName::make('ready', $childId),
                self::remainingWorkSeconds($workDeadlineNs),
                $abort,
            );

            if ($payload !== $nonces[$childId]) {
                throw ConcurrencyProtocolException::identityMismatch(
                    $childId,
                    'ready の nonce',
                    $nonces[$childId],
                    $payload,
                );
            }
        }

        // 2. **ここで初めて** go token を作る (事前に子へ渡らない)
        $goToken = bin2hex(random_bytes(16));
        $barrier->signal(SignalName::make('go'), $goToken);

        // 3. entered をちょうど 1 子ぶん待つ
        $winnerId = self::awaitSingleEntered($barrier, $nonces, $goToken, $workDeadlineNs, $abort);
        $loserId = self::oppositeChild($winnerId);

        // 4. 反対側の out を待ち、中身を完全に検査する
        [$loserJson, $loser] = self::readObservation($barrier, $loserId, $workDeadlineNs, $abort);
        $loser->assertIdentity($loserId, $nonces[$loserId], $goToken);
        $loser->assertLost($expectedRequestHash);

        // 5. 検査をすべて通ったら release を置く
        $barrier->signal(SignalName::make('release'), $goToken);

        // 6. 勝者の out を待ち、同一性を検査する
        [$winnerJson, $winner] = self::readObservation($barrier, $winnerId, $workDeadlineNs, $abort);
        $winner->assertIdentity($winnerId, $nonces[$winnerId], $goToken);

        $rawOut = [$winnerId => $winnerJson, $loserId => $loserJson];

        // 受理条件 1: 両 process の exit code が 0
        foreach ($processes as $childId => $process) {
            $exitCode = $process->waitFor(self::remainingWorkSeconds($workDeadlineNs));
            if ($exitCode !== 0) {
                throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
                    '子 "%s" の終了コードが 0 でない (%s)。stderr: %s',
                    $childId,
                    $exitCode === null ? '時間内に終了しなかった' : (string) $exitCode,
                    $process->errorOutput() === '' ? '(なし)' : $process->errorOutput(),
                ));
            }
        }

        // 受理条件 2: 各子の stdout の JSON と out ファイルの中身が一致
        foreach ($processes as $childId => $process) {
            if (trim($process->output()) !== trim($rawOut[$childId])) {
                throw ConcurrencyProtocolException::unexpectedObservation(
                    "子 \"{$childId}\" の stdout と out ファイルの中身が一致しない"
                );
            }
        }

        // 受理条件 3: 守りたい層以外の無効化と DB 座標、および**送り先が親の決めた面**であること
        $expectedCoordinates = ProbeDatabaseCoordinates::fromParentConfig();
        $observations = [$winnerId => $winner, $loserId => $loser];
        foreach ($observations as $childId => $observation) {
            $observation->assertAppLocksDisabled();
            $observation->assertDatabaseCoordinates($expectedCoordinates);

            // ★観測項目に集めるだけで判定に使わない形を作らない (AGENTS.md 走査規約 (d))。
            //   request_hash は path を含むので間接的には効くが、明示的に照合する。
            if ($observation->uri !== $uri) {
                throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
                    '子 "%s" が叩いた面が親の決めた面と違う (期待 %s / 実際 %s)',
                    $childId,
                    $uri,
                    $observation->uri,
                ));
            }
        }

        $result = new ConcurrentProbeResult(
            observations: $observations,
            routeName: $routeName,
            uri: $uri,
            idempotencyKey: $idempotencyKey,
            expectedRequestHash: $expectedRequestHash,
        );

        // 受理条件 4: 勝者・敗者がちょうど 1:1 に分かれる
        // 受理条件 5: 勝者・敗者・親の期待値の request_hash が 3 点一致する
        [$partitionedWinner, $partitionedLoser] = $result->partition();
        foreach ([$partitionedWinner, $partitionedLoser] as $observation) {
            if ($observation->requestHash !== $expectedRequestHash) {
                throw ConcurrencyProtocolException::unexpectedObservation(
                    '2 子と親の request_hash が 3 点一致しない'
                );
            }
        }

        return $result;
    }

    /**
     * 待機中に毎周回呼ぶ中断条件 (締切を待たずに抜ける)。
     *
     * ★**二重実行の判定を子の生死より先**に置く。探している退行を「子が死んだ」という
     *   別の診断で隠さないためである。
     *
     * @param  array<string, ProbeProcess>  $processes
     * @return Closure(): void
     */
    private static function abortCondition(ProcessBarrier $barrier, array $processes): Closure
    {
        return static function () use ($barrier, $processes): void {
            // present() は許可集合に無い完成合図があれば拒否する (無視しない)
            $entered = array_values(array_filter(
                self::presentValues($barrier),
                static fn (string $value): bool => str_starts_with($value, 'entered-'),
            ));

            if (count($entered) >= 2) {
                throw ConcurrencyProtocolException::doubleExecution($entered);
            }

            foreach ($processes as $childId => $process) {
                if ($process->isRunning()) {
                    continue;
                }

                // ★停止を観測した**後に**列挙し直す。子は「out を置く」→「終了する」の順で
                //   動くので、停止の観測より前に取った一覧を使うと、正常に終わった子を
                //   「観測を出さずに終了した」と誤診する (この順序が load-bearing)。
                if (in_array('out-'.$childId, self::presentValues($barrier), true)) {
                    continue;
                }

                throw ConcurrencyProtocolException::childDiedEarly(
                    $childId,
                    $process->exitCode(),
                    $process->errorOutput(),
                );
            }
        };
    }

    /**
     * 現れている完成合図の名前 (未知の完成合図があれば拒否する)。
     *
     * @return list<string>
     */
    private static function presentValues(ProcessBarrier $barrier): array
    {
        return array_map(
            static fn (SignalName $name): string => $name->value,
            $barrier->present(SignalName::all()),
        );
    }

    /**
     * `entered` がちょうど 1 子ぶん現れるまで待ち、その child ID を返す。
     *
     * @param  array<string, string>  $nonces
     * @param  Closure(): void  $abort
     */
    private static function awaitSingleEntered(
        ProcessBarrier $barrier,
        array $nonces,
        string $goToken,
        int $workDeadlineNs,
        Closure $abort,
    ): string {
        while (true) {
            $abort();

            $entered = self::enteredChildren($barrier);

            if (count($entered) === 1) {
                $childId = $entered[0];
                $payload = $barrier->await(
                    SignalName::make('entered', $childId),
                    self::remainingWorkSeconds($workDeadlineNs),
                );

                $expected = $nonces[$childId].':'.$goToken;
                if ($payload !== $expected) {
                    throw ConcurrencyProtocolException::identityMismatch(
                        $childId,
                        'entered の nonce:go_token',
                        $expected,
                        $payload,
                    );
                }

                return $childId;
            }

            if (hrtime(true) >= $workDeadlineNs) {
                throw BarrierTimeoutException::waitingForSingleEntered();
            }

            usleep(ProcessBarrier::POLL_INTERVAL_MICROSECONDS);
        }
    }

    /**
     * 現れている `entered` の child ID。
     *
     * @return list<string>
     */
    private static function enteredChildren(ProcessBarrier $barrier): array
    {
        $children = [];

        foreach (self::presentValues($barrier) as $value) {
            if (! str_starts_with($value, 'entered-')) {
                continue;
            }

            $children[] = substr($value, strlen('entered-'));
        }

        return $children;
    }

    /**
     * `out` を待って観測へ変換する (生の JSON も返す = stdout との突合に使う)。
     *
     * @param  Closure(): void  $abort
     * @return array{string, ConcurrentProbeObservation}
     */
    private static function readObservation(
        ProcessBarrier $barrier,
        string $childId,
        int $workDeadlineNs,
        Closure $abort,
    ): array {
        $json = $barrier->await(
            SignalName::make('out', $childId),
            self::remainingWorkSeconds($workDeadlineNs),
            $abort,
        );

        $decoded = json_decode($json, true);
        if ($decoded === null) {
            throw ConcurrencyProtocolException::unexpectedObservation(
                "子 \"{$childId}\" の観測を JSON として読めない"
            );
        }

        return [$json, ConcurrentProbeObservation::fromDecodedJson($decoded)];
    }

    private static function oppositeChild(string $childId): string
    {
        foreach (self::CHILD_IDS as $candidate) {
            if ($candidate !== $childId) {
                return $candidate;
            }
        }

        throw new RuntimeException("反対側の子が見つからない: {$childId}");
    }

    /** 作業の残り時間 (絶対 deadline から算出。0 以下なら例外) */
    private static function remainingWorkSeconds(int $workDeadlineNs): float
    {
        $remaining = ($workDeadlineNs - hrtime(true)) / 1_000_000_000;

        if ($remaining <= 0.0) {
            throw BarrierTimeoutException::workDeadlineExhausted();
        }

        return $remaining;
    }

    /**
     * 回収 (フェーズ単位)。
     *
     * | 段 | 内容 |
     * |---|---|
     * | 0 | **秘密**(env ファイル・入力ファイル) を回収の成否にかかわらず消す |
     * | 1 | 生存する全子へ `signalTerminate()` を送る |
     * | 2 | 単一の reap deadline のうち最大 REAP_GRACE_SECONDS、全子をまとめて poll する |
     * | 3 | まだ生存する全子へ `signalKill()` を送る (TERM で終わった子には送らない) |
     * | 4 | reap deadline まで全子をまとめて poll する |
     * | 5 | 消せなかった秘密 / 停止を確認できない子 / 残置 workspace の権限を**集めて 1 つの例外**にする |
     *
     * ★子単位の逐次処理にしない: 「子ごとに TERM → 1 秒待つ → KILL → 残りを待つ」を
     *   順番にやると 1 子目が予算を使い切った時点で 2 子目に回収時間が残らない。
     * ★**先に見つかった 1 つで打ち切らない**。秘密を消せなかったことと子が残っていることは
     *   別々の危険であり、片方だけを報告すると残りが診断から消える。
     * ★**診断材料は残してよいが秘密は残さない**。停止を確認できない子がいるときに
     *   workspace ごと消すと、まだ動いている子が削除済みパスへ書き込む。
     *
     * @param  array<string, ProbeProcess>  $processes
     * @param  list<string>  $secretPaths
     */
    private static function reap(array $processes, string $workspace, array $secretPaths, ?Throwable $cause): void
    {
        // 段 0: **1 件目の失敗で即 throw しない** (抜けると 2 件目の削除が省略され、
        //       消せたはずの秘密が残る)。全対象を試行してから、失敗をまとめて報告する。
        $failedSecrets = [];
        foreach ($secretPaths as $path) {
            clearstatcache(true, $path);
            if (! file_exists($path)) {
                continue;
            }

            if (! @unlink($path)) {
                $failedSecrets[] = $path;
            }
        }

        $now = hrtime(true);
        $reapDeadline = $now + (int) (self::REAP_BUDGET_SECONDS * 1_000_000_000);
        $graceDeadline = min($reapDeadline, $now + (int) (self::REAP_GRACE_SECONDS * 1_000_000_000));

        $alive = self::runningChildren($processes);
        foreach ($alive as $childId) {
            $processes[$childId]->signalTerminate();
        }
        self::reapPhase($processes, $alive, $graceDeadline);

        $alive = self::runningChildren($processes);
        foreach ($alive as $childId) {
            $processes[$childId]->signalKill();
        }
        self::reapPhase($processes, $alive, $reapDeadline);

        $remainingChildren = self::runningChildren($processes);

        // ★**問題を集めてから 1 つの例外へ載せる**。先に見つかった 1 つで打ち切ると、
        //   同時に起きているもう一方の危険 (消せなかった秘密 / 残った子 / 緩い権限) が
        //   診断から消える。
        $problems = [];

        if ($failedSecrets !== []) {
            $problems[] = '秘密を含むファイルを削除できなかった (パスから再取得できる状態で残っている): '
                .implode(',', $failedSecrets);
        }

        if ($remainingChildren !== []) {
            $problems[] = sprintf(
                '停止を確認できない子が残っている (child=%s)。診断のため workspace を残置した: %s',
                implode(',', $remainingChildren),
                $workspace,
            );

            $mode = ProbeEnvironment::mode($workspace);
            if ($mode !== 0700) {
                $problems[] = sprintf('残置する workspace の権限が 0700 でない (%04o)', $mode);
            }
        }

        if ($problems !== []) {
            throw ConcurrencyProtocolException::reapFailed($problems, $cause);
        }

        self::removeDirectory($workspace);
    }

    /**
     * 1 フェーズぶんの poll と、フェーズ末尾の待機要求。
     *
     * ★**単一のループで全子の `isRunning()` を短い間隔で確認する**。
     *   個々の子へ残り時間いっぱいの blocking wait を順番に行わない
     *   (それをやると 1 子目が予算を食い切り、フェーズ単位にした意味が消えて逐次処理へ戻る)。
     *   `waitFor()` は**この poll ループの中では使わない** — フェーズの終わりに 1 回だけ、
     *   そのフェーズで見張っていた子へ**残り予算**(0 でありうる)で要求する。
     *
     * @param  array<string, ProbeProcess>  $processes
     * @param  list<string>  $watch
     */
    private static function reapPhase(array $processes, array $watch, int $phaseDeadlineNs): void
    {
        while ($watch !== []) {
            $stillRunning = false;
            foreach ($watch as $childId) {
                if ($processes[$childId]->isRunning()) {
                    $stillRunning = true;
                }
            }

            if (! $stillRunning || hrtime(true) >= $phaseDeadlineNs) {
                break;
            }

            usleep(self::REAP_POLL_INTERVAL_MICROSECONDS);
        }

        $remaining = max(0.0, ($phaseDeadlineNs - hrtime(true)) / 1_000_000_000);
        foreach ($watch as $childId) {
            $processes[$childId]->waitFor($remaining);
        }
    }

    /**
     * @param  array<string, ProbeProcess>  $processes
     * @return list<string>
     */
    private static function runningChildren(array $processes): array
    {
        $running = [];
        foreach ($processes as $childId => $process) {
            if ($process->isRunning()) {
                $running[] = $childId;
            }
        }

        return $running;
    }

    /**
     * 診断へ出る前に、例外メッセージの中の既知の秘密を伏せ字にする (**唯一の choke point**)。
     *
     * ★子は untrusted である。合図の中身・観測の値・未知の完成合図の名前・stderr など、
     *   **子が書いた文字列は例外メッセージのどこにでも入りうる**。生成箇所ごとに伏せ字を撒くと
     *   必ず撒き漏らすので、**唯一の出口 (`run()`) で 1 回だけ**通す。
     * ★**型は保つ** (呼び出し側とテストは型で分岐する)。作り直すのは本ハーネスの 2 型だけで、
     *   それ以外の型 (`JsonException` 等) は本ハーネスがメッセージを組み立てていないので触らない。
     *   作り直しは**型ごとの明示の入口** (`withRedactedMessage()`) で行う —
     *   `new ($e::class)(…)` のような動的 new は走査器から生成クラスが見えなくなるため使わない
     *   (`CachePayloadPlainDataGateTest` の検査 L4h が deny-by-default で拒否する)。
     * ★**previous は引き継がない**。previous 側のメッセージまでは作り直せないので、
     *   伏せ字にできない文字列を連鎖に残すほうが危ない (作業の失敗は回収側の
     *   `reapFailed()` が伏せ字済みの実体を previous に持つ)。
     * ★**保証しないもの**: 伏せられるのは完全一致で現れた既知の秘密だけである。
     *   切り詰められた断片は一致しないので、**子が message / trace を出さない**ことと
     *   合わせて初めて閉じる。
     *
     * @param  list<string>  $secrets
     */
    private static function redactedForDiagnostics(Throwable $e, #[\SensitiveParameter] array $secrets): Throwable
    {
        if ($e instanceof ConcurrencyProtocolException) {
            $redacted = self::redactSecrets($e->getMessage(), $secrets);

            return $redacted === $e->getMessage()
                ? $e
                : ConcurrencyProtocolException::withRedactedMessage($redacted);
        }

        if ($e instanceof BarrierTimeoutException) {
            $redacted = self::redactSecrets($e->getMessage(), $secrets);

            return $redacted === $e->getMessage()
                ? $e
                : BarrierTimeoutException::withRedactedMessage($redacted);
        }

        return $e;
    }

    /**
     * 既知の秘密を伏せ字にする。
     *
     * ★一時ファイルを消しても**CI のログは残る**ので、秘密の後始末はファイル経路だけでは閉じない。
     * ★**長い秘密から順に**置換する (短い秘密が長い秘密の一部だったときに、
     *   置換済みの伏せ字を壊さないため)。
     *
     * @param  list<string>  $secrets
     */
    private static function redactSecrets(string $text, #[\SensitiveParameter] array $secrets): string
    {
        usort($secrets, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($secrets as $index => $secret) {
            $text = str_replace($secret, '[redacted:'.$index.']', $text);
        }

        return $text;
    }

    private static function createWorkspace(): string
    {
        $workspace = sys_get_temp_dir().'/concurrency-probe-'.bin2hex(random_bytes(8));

        if (! mkdir($workspace, 0700) || ! is_dir($workspace)) {
            throw new RuntimeException("実プロセス並行テストの workspace を作れない: {$workspace}");
        }

        chmod($workspace, 0700);
        ProcessBarrier::prepareWorkspace($workspace);

        return $workspace;
    }

    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            // ★symlink は**辿らずに** unlink する。`is_dir()` はディレクトリへの symlink でも
            //   true になるため、先に見ないと workspace の外を再帰的に消しにいく。
            if (is_link($path)) {
                unlink($path);

                continue;
            }

            if (is_dir($path)) {
                self::removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
