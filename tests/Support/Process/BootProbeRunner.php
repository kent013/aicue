<?php

declare(strict_types=1);

namespace Tests\Support\Process;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 起動順序を子プロセスで実測するための probe 起動器 (lctl feature: subprocess-boot-probe-harness)。
 *
 * アプリの壊れ方には「どの順番で組み立てられたか」に由来するものがあり、テストが走る時点で
 * そのプロセスの起動は終わっているため同じプロセスの中では再現できない。ここは
 * **小さな子プロセスを 1 つ起こして観測結果を回収する**、その起こし方と後始末だけを持つ。
 * 何を観測するかは呼び出し側 (gate) の責務である。
 *
 * ## 正典 v1 の 6 要素と本実装
 *
 *  1. 親と同じ実行体で起こす — `PHP_BINARY` を先頭に固定し、`$phpArguments` はその後ろに置く
 *  2. 環境変数は 3 段 — 継承 (許可一覧) → 基底 → ケース別上書き。子は `proc_open` に渡した
 *     配列だけを受け取るので、ここが開発者ローカルの env を締め出す唯一の統制点になる
 *  3. 出力は非ブロッキングで逐次読み、制限時間を超えたら SIGTERM → 猶予 → SIGKILL で落とし、
 *     全ての管を閉じてから必ず `proc_close` する
 *  4. 終了コードは実行中フラグが初めて false になった時点の非負値を保持し、`proc_close` の
 *     戻り値で上書きしない。強制終了で取れなければ 124 へ正規化する
 *  5. 子の書き出し先は**リポジトリ外の一時ディレクトリ**へ逃がす (下記 RESERVED_ENV_KEYS)。
 *     一時ディレクトリがリポジトリ内になったら子を起こす前に例外にする (fail-closed)
 *  6. 自己検査を持つ — `tests/Unit/Support/Process/BootProbeRunnerTest.php`
 *
 * ## 正典 v1 との差分 (1 点だけ)
 *
 * 書き出し先の 7 キー (RESERVED_ENV_KEYS) は runner が作った一時ディレクトリから導く
 * **予約鍵**であり、呼び出し側から渡せない (渡したら例外)。黙って無視すると結果の
 * `temporaryRoot` / `writtenRelativePaths` が嘘になり、正典 (5) の保証が空洞化するためである。
 * 環境変数の**順序**は正典と同じで、ケース別上書きが最後に効く。
 * 「固定鍵を呼び出し側より後ろに置いて上書き不能にする」テンプレート固有の作法は、その理由を
 * 持つ呼び出し側 (`tests/Architecture/BughuntFakeWiringTest.php`) が `array_merge($env, [...])`
 * で表現する (runner へ持ち上げると、逆の契約を持つ検査 — 呼び出し側が `APP_KEY` を 2 通り
 * 与えて観測差を測る `BugHuntInventoryCheckInvariantTest` の CT-3 — が載らなくなる)。
 *
 * ## 保証しないこと
 *
 *  - **孫プロセスは回収しない**。`proc_terminate()` が届くのは直接の子だけである
 *    (probe が孫を起こさないことは probe 側の前提)
 *  - **子が書く先を全部押さえること**は保証しない。退避できるのは Laravel が環境変数で受ける
 *    既知の書き出し先までで、独自パスへの書き込みは閉じない
 *  - **子が外部へ通信しないこと**は本クラスの主張ではない (probe の中身の責務)
 *  - **Unix 系 (Linux / macOS) 前提**である。段階的な強制終了は POSIX のシグナル意味論に依存する
 *  - **回収不能だった場合の振る舞いは実測していない**。子を落とせなかったときは一時ディレクトリを
 *    消さずに場所を例外へ書いて残す (生きている子の足元を壊さないため) が、この分岐は
 *    `SIGKILL` を無視できない以上作り出せないので自己検査で覆えていない
 *
 * **`tests/` 専用**である。`app` / `routes` / `config` / `database` / `bootstrap` へ持ち出すと
 * 外部到達統制の subprocess 0 件 pin に触れる (AGENTS.md セキュリティ不変条件 15)。
 * 同じ扱いの先例は `tests/Support/Architecture/GlobalUse/PhpLintOracle.php`。
 */
final class BootProbeRunner
{
    /** 強制終了で終了コードが取れなかったときの正規化値 (GNU timeout(1) と同じ)。 */
    public const int TIMEOUT_EXIT_CODE = 124;

    /** 既定の制限時間 (秒)。実測では probe 1 本が 1 秒前後で終わる。 */
    public const int DEFAULT_TIMEOUT_SECONDS = 60;

    /** 終了要求から強制終了までの猶予 (秒)。 */
    public const int TERMINATION_GRACE_SECONDS = 2;

    /** 子の終了を検知してから管を読み切るまでの上限 (秒。孫が管を持っていても回収を止めない)。 */
    public const int FINAL_DRAIN_SECONDS = 2;

    /** 強制終了を送ってから諦めるまでの最終期限 (秒)。超えたら例外にする。 */
    public const int KILL_WAIT_SECONDS = 5;

    /**
     * 親から継承する環境変数 (文字列かつ非空のときだけ継承する。既定値へ差し替えない)。
     *
     *  - `PATH`: 子が外部コマンドを解決するため (`PHP_BINARY` は絶対パスなので必須ではない)
     *  - `HOME`: composer / vendor が HOME 依存の場所を引く
     *  - `TMPDIR`: 子自身が一時ファイルを作るときの置き場所
     *
     * `LC_*` / `TZ` / `LANG` は継承しない (入力集合を広げる。時間帯は `config/app.php` が決める)。
     *
     * @var list<non-empty-string>
     */
    public const array INHERITED_ENV_KEYS = ['PATH', 'HOME', 'TMPDIR'];

    /**
     * runner が予約する環境変数 (書き出し先)。呼び出し側が渡したら例外にする。
     *
     * @var list<non-empty-string>
     */
    public const array RESERVED_ENV_KEYS = [
        'LARAVEL_STORAGE_PATH',
        'VIEW_COMPILED_PATH',
        'APP_CONFIG_CACHE',
        'APP_ROUTES_CACHE',
        'APP_SERVICES_CACHE',
        'APP_PACKAGES_CACHE',
        'APP_EVENTS_CACHE',
    ];

    /** ext-pcntl に依存しないためシグナル番号を直接持つ。 */
    private const int SIGNAL_TERMINATE = 15;

    private const int SIGNAL_KILL = 9;

    /** 出力を 1 回に読む上限 (バイト)。パイプバッファ (64KiB 程度) に合わせる。 */
    private const int READ_CHUNK_BYTES = 65536;

    /** 読む管が 1 本も無いときに眠る時間 (マイクロ秒)。回転で CPU を焼かないための休符。 */
    private const int IDLE_SLEEP_MICROSECONDS = 20000;

    /** 出力を待つ 1 回の上限 (マイクロ秒)。 */
    private const int SELECT_WAIT_MICROSECONDS = 50000;

    /** 基底の暗号鍵の種 (値そのものは観測に影響しない。CI の素の `.env` が空鍵であることへの備え)。 */
    private const string BASE_APP_KEY_SEED = 'laravel-claude-template:boot-probe';

    /**
     * probe を 1 本起こして結果を回収する。
     *
     * @param  list<non-empty-string>  $phpArguments  `PHP_BINARY` の後ろに置く引数
     *                                                (`['-r', $code]` / `[$scriptPath]`)
     * @param  array<non-empty-string, string>  $env  ケース別上書き (基底より後に効く)
     * @param  positive-int  $timeoutSeconds
     * @param  ?non-empty-string  $temporaryBase  一時ディレクトリの置き場所。既定は
     *                                            `sys_get_temp_dir()`。**退避を無効化する口ではない**
     *                                            (リポジトリ配下を渡すと例外になる。自己検査が
     *                                            その fail-closed を確かめるための場所指定である)
     */
    public static function run(
        array $phpArguments,
        array $env = [],
        int $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?string $temporaryBase = null,
    ): BootProbeResult {
        Assert::notEmpty($phpArguments, 'probe の引数が空である');
        Assert::allStringNotEmpty($phpArguments);
        Assert::allStringNotEmpty(array_keys($env));
        Assert::allString($env);
        Assert::positiveInteger($timeoutSeconds);

        $reserved = array_values(array_intersect(self::RESERVED_ENV_KEYS, array_keys($env)));
        if ($reserved !== []) {
            throw new RuntimeException(
                '書き出し先は runner が決める (呼び出し側から渡せない): '.implode(', ', $reserved),
            );
        }

        $repositoryRoot = self::repositoryRoot();
        $temporaryRoot = self::createTemporaryRoot($temporaryBase ?? sys_get_temp_dir(), $repositoryRoot);

        // 「消してよいか」= 子が生存し得ないか。子がいないうちは消してよい (残骸を残さない)。
        // 遷移: 一時ディレクトリ作成直後 = true → `proc_open` 成功直後 = false
        //       → 回収成功後 = true / 回収不能 = false のまま
        $safeToRemove = true;

        try {
            $result = self::spawn(
                $phpArguments,
                self::composeEnv($env, $temporaryRoot),
                $repositoryRoot,
                $temporaryRoot,
                $timeoutSeconds,
                $safeToRemove,
            );
        } catch (Throwable $failure) {
            // 生きているかもしれない子の足元は消さない (残った場所は spawn() が投げる例外に書く)。
            if ($safeToRemove) {
                try {
                    self::removeDirectory($temporaryRoot);
                } catch (Throwable $removalFailure) {
                    // 後片付けの失敗で**本来の例外を捨てない** (previous に残す)
                    throw new RuntimeException(
                        '一時ディレクトリを消せなかった: '.$temporaryRoot
                        .' / 削除の失敗: '.$removalFailure->getMessage(),
                        0,
                        $failure,
                    );
                }
            }

            throw $failure;
        }

        self::removeDirectory($temporaryRoot);   // 正常経路。削除の失敗は例外のまま伝播させる

        return $result;
    }

    /**
     * `$candidate` が `$root` の配下か。
     *
     * 素の前方一致だと `/repo` が `/repository` を配下と誤判定するので、区切り文字を境界にする。
     * 自己検査が境界の振る舞いを直接 pin できるよう公開する。
     *
     * **両引数とも `realpath` 済みの絶対パス**であること (相対パスや `..` を含む形は受け付けない。
     * 正規化は呼び出し側の責務であり、ここでは絶対パスであることだけを `Assert` で確かめる)。
     */
    public static function isInside(string $root, string $candidate): bool
    {
        Assert::startsWith($root, DIRECTORY_SEPARATOR);
        Assert::startsWith($candidate, DIRECTORY_SEPARATOR);

        $normalizedRoot = rtrim($root, DIRECTORY_SEPARATOR);

        return $candidate === $normalizedRoot
            || str_starts_with($candidate, $normalizedRoot.DIRECTORY_SEPARATOR);
    }

    /**
     * 基底 (呼び出し側が上書きできる hermetic な既定)。**この 3 本しか置かない**。
     *
     *  - `APP_KEY`: CI の素の `.env` は `APP_KEY` が空で、encrypter を引いた瞬間に
     *    `MissingAppKeyException` で死ぬ (ローカル緑 / CI 赤の実測退行)。観測値は鍵に依存しない
     *  - `QUEUE_CONNECTION`: 開発機の `.env` が `redis` だと観測が変わる
     *  - `CACHE_STORE`: 1 プロセスで完結させ、DB / redis を張らせない
     *
     * **`APP_ENV` は置かない**。「渡さない実行では素の `.env` を読む」という観測が
     * 呼び出し側 (`BughuntFakeWiringTest`) の複数ケースの前提になっているためである。
     * ロケール系 (`LANG` / `LC_*`) も置かない (誰も依存せず、置くほど入力集合が広がる)。
     *
     * @return array<non-empty-string, string>
     */
    private static function baseEnv(): array
    {
        return [
            'APP_KEY' => 'base64:'.base64_encode(hash('sha256', self::BASE_APP_KEY_SEED, true)),
            'QUEUE_CONNECTION' => 'database',
            'CACHE_STORE' => 'array',
        ];
    }

    /** リポジトリ root (このファイルは `tests/Support/Process/` に居る)。 */
    private static function repositoryRoot(): string
    {
        $root = realpath(dirname(__DIR__, 3));

        if (! is_string($root)) {
            throw new RuntimeException('リポジトリ root を解決できなかった');
        }

        return $root;
    }

    /**
     * 一時ディレクトリを作り、リポジトリ外であることを確かめて子が書く下位を掘る。
     *
     * 途中のどの失敗でも作った root を消してから元の例外を投げ直す (作りかけを残さない)。
     *
     * @return non-empty-string
     */
    private static function createTemporaryRoot(string $base, string $repositoryRoot): string
    {
        Assert::startsWith($base, DIRECTORY_SEPARATOR, '一時ディレクトリの置き場所は絶対パスであること');
        Assert::directory($base);
        Assert::writable($base);

        $created = rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'boot-probe-'.bin2hex(random_bytes(8));

        if (! mkdir($created, 0o700, true)) {
            throw new RuntimeException('一時ディレクトリを作れなかった: '.$created);
        }

        try {
            $temporaryRoot = realpath($created);

            if (! is_string($temporaryRoot) || $temporaryRoot === '') {
                throw new RuntimeException('一時ディレクトリを正規化できなかった: '.$created);
            }

            if (self::isInside($repositoryRoot, $temporaryRoot)) {
                // 正典 (5) の fail-closed。ここを緩めると probe の書き出しがリポジトリへ落ちる。
                throw new RuntimeException(
                    'probe の一時ディレクトリがリポジトリ内にある (書き出し先を退避できない): '.$temporaryRoot,
                );
            }

            foreach ([
                'storage/framework/views',
                'storage/framework/cache/data',
                'storage/framework/sessions',
                'storage/logs',
                'storage/app/private',
                'bootstrap-cache',
            ] as $relative) {
                $directory = $temporaryRoot.DIRECTORY_SEPARATOR.$relative;
                if (! mkdir($directory, 0o700, true)) {
                    throw new RuntimeException('一時ディレクトリの下位を作れなかった: '.$directory);
                }
            }

            return $temporaryRoot;
        } catch (Throwable $failure) {
            self::removeDirectory($created);

            throw $failure;
        }
    }

    /**
     * 環境変数の 3 段合成 + 予約鍵 (正典 v1 の (2) と (5))。
     *
     * @param  array<non-empty-string, string>  $caseEnv
     * @return array<non-empty-string, string>
     */
    private static function composeEnv(array $caseEnv, string $temporaryRoot): array
    {
        $inherited = [];
        foreach (self::INHERITED_ENV_KEYS as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $inherited[$key] = $value;
            }
        }

        $storage = $temporaryRoot.'/storage';
        $bootstrapCache = $temporaryRoot.'/bootstrap-cache';

        $reserved = [
            'LARAVEL_STORAGE_PATH' => $storage,
            'VIEW_COMPILED_PATH' => $storage.'/framework/views',
            'APP_CONFIG_CACHE' => $bootstrapCache.'/config.php',
            'APP_ROUTES_CACHE' => $bootstrapCache.'/routes-v7.php',
            'APP_SERVICES_CACHE' => $bootstrapCache.'/services.php',
            'APP_PACKAGES_CACHE' => $bootstrapCache.'/packages.php',
            'APP_EVENTS_CACHE' => $bootstrapCache.'/events.php',
        ];

        // 予約鍵の宣言 (公開定数) と実体が食い違ったら、S4 の pin も run() の拒否も嘘になる。
        Assert::same(array_keys($reserved), self::RESERVED_ENV_KEYS, '予約鍵の宣言と実体が食い違っている');

        return array_merge($inherited, self::baseEnv(), $caseEnv, $reserved);
    }

    /**
     * 子を起こし、逐次読み・制限時間・回収まで面倒を見る。
     *
     * @param  list<non-empty-string>  $phpArguments
     * @param  array<non-empty-string, string>  $env
     * @param  non-empty-string  $temporaryRoot
     * @param  positive-int  $timeoutSeconds
     */
    private static function spawn(
        array $phpArguments,
        array $env,
        string $repositoryRoot,
        string $temporaryRoot,
        int $timeoutSeconds,
        bool &$safeToRemove,
    ): BootProbeResult {
        $startedAt = microtime(true);

        // 標準入力は /dev/null に向ける。probe が誤って読んでも即 EOF になり、止まる面が 1 つ減る
        // (管にすると読み手が現れたときに待ち続ける)。
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open([PHP_BINARY, ...$phpArguments], $descriptors, $pipes, $repositoryRoot, $env);

        if (! is_resource($process)) {
            throw new RuntimeException('probe の子プロセスを起動できなかった: '.implode(' ', $phpArguments));
        }

        // ここから先は子が生存しうる。回収できるまで一時ディレクトリを消さない。
        $safeToRemove = false;

        // 回収の状態は `try` の**前**に置く (`try` 内のどの例外点からも catch が回収を試みられるように)。
        $state = ['processClosed' => false, 'closeCode' => null];

        try {
            // pid の取得も回収対象の `try` の中に置く (ここで落ちても子・管・一時ディレクトリを
            // 一体で回収する = 「proc_open 成功後はどの例外点からも一体で回収する」)。
            $pid = proc_get_status($process)['pid'];

            foreach ([1, 2] as $descriptor) {
                $pipe = $pipes[$descriptor] ?? null;
                if (! is_resource($pipe)) {
                    throw new RuntimeException('probe の出力管を開けなかった');
                }
                if (! stream_set_blocking($pipe, false)) {
                    throw new RuntimeException('probe の出力を非ブロッキングにできなかった');
                }
            }

            $output = [1 => '', 2 => ''];
            $exitCode = null;          // 実行中フラグが初めて false になった時点の非負値
            $timedOut = false;
            $deadline = $startedAt + $timeoutSeconds;
            $killAt = null;            // 強制終了を送る時刻 (未設定は null)
            $giveUpAt = null;          // 落とせないと諦める時刻 ($killAt と同時に必ず入る)

            while (true) {
                self::readAvailable($pipes, $output);   // 詰まらせない (パイプバッファは 64KiB 程度)

                $status = proc_get_status($process);
                if (! $status['running']) {
                    if ($exitCode === null && $status['exitcode'] >= 0) {
                        $exitCode = $status['exitcode'];   // ここで確定させ、以後は上書きしない
                    }
                    break;
                }

                $now = microtime(true);

                // 最終期限は**再送の時刻とは独立**に見る (再送のたびに $killAt を先送りするので、
                // 期限の確認を再送分岐の中に置くと $giveUpAt を猶予ぶん超過できてしまう)。
                if ($giveUpAt !== null && $now >= $giveUpAt) {
                    throw new RuntimeException('probe の子プロセスを強制終了できなかった');
                }

                if ($killAt === null && $now >= $deadline) {
                    $timedOut = true;
                    if (! proc_terminate($process, self::SIGNAL_TERMINATE)) {
                        throw new RuntimeException('probe の子プロセスへ終了要求を送れなかった');
                    }
                    $killAt = $now + self::TERMINATION_GRACE_SECONDS;
                    $giveUpAt = $killAt + self::KILL_WAIT_SECONDS;
                } elseif ($killAt !== null && $now >= $killAt) {
                    // 送信失敗でも即座には諦めない (最終期限 $giveUpAt が唯一の打ち切り点)。
                    proc_terminate($process, self::SIGNAL_KILL);
                    $killAt = $now + self::TERMINATION_GRACE_SECONDS;
                }
            }

            // 終了検知後の最終読み取り (上限つき)。孫が管を持ったままでも回収を止めない。
            $drainUntil = microtime(true) + self::FINAL_DRAIN_SECONDS;
            while (microtime(true) < $drainUntil && self::hasReadablePipe($pipes)) {
                self::readAvailable($pipes, $output);
            }

            $closed = self::reclaim($process, $pipes, $state);
            $safeToRemove = true;

            if ($exitCode === null) {
                // シグナルで落ちた子は exitcode が -1 になる → 124 へ正規化する
                $exitCode = $timedOut
                    ? self::TIMEOUT_EXIT_CODE
                    : ($closed >= 0 ? $closed : throw new RuntimeException('probe の終了コードを回収できなかった'));
            }

            return new BootProbeResult(
                stdout: $output[1],
                stderr: $output[2],
                exitCode: $exitCode,
                timedOut: $timedOut,
                elapsedSeconds: microtime(true) - $startedAt,
                temporaryRoot: $temporaryRoot,
                writtenRelativePaths: self::collectWritten($temporaryRoot),   // 消す前に採取する
                pid: $pid,
            );
        } catch (Throwable $failure) {
            // 本来の例外を優先しつつ、回収は最後まで試みる。
            try {
                self::reclaim($process, $pipes, $state);   // 2 回目以降は保持値を返すだけ
                $safeToRemove = true;
            } catch (Throwable $cleanupFailure) {
                // **回収できなかった** — 一時ディレクトリは残す (場所を例外に書く)
                throw new RuntimeException(
                    'probe の子を回収できなかったため一時ディレクトリを残した: '.$temporaryRoot
                    .' / 回収の失敗: '.$cleanupFailure->getMessage(),
                    0,
                    $failure,
                );
            }

            throw $failure;
        }
    }

    /**
     * 読める管が 1 本でも残っているか (EOF 済みは数えない)。
     *
     * @param  array<int, resource>  $pipes
     */
    private static function hasReadablePipe(array $pipes): bool
    {
        foreach ([1, 2] as $descriptor) {
            $pipe = $pipes[$descriptor] ?? null;
            if (is_resource($pipe) && ! feof($pipe)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 読めるだけ読む (非ブロッキング)。
     *
     * `feof()` の管は `stream_select` の対象から**外す** — EOF 済みの管を残すと即時 ready になり
     * 回転し続けるためである。読む対象が 1 本も無ければ少し眠って戻る。
     *
     * @param  array<int, resource>  $pipes
     * @param  array<int, string>  $output
     */
    private static function readAvailable(array $pipes, array &$output): void
    {
        $read = [];
        foreach ([1, 2] as $descriptor) {
            $pipe = $pipes[$descriptor] ?? null;
            if (is_resource($pipe) && ! feof($pipe)) {
                $read[$descriptor] = $pipe;
            }
        }

        if ($read === []) {
            usleep(self::IDLE_SLEEP_MICROSECONDS);

            return;
        }

        $write = null;
        $except = null;
        $ready = stream_select($read, $write, $except, 0, self::SELECT_WAIT_MICROSECONDS);

        if ($ready === false) {
            throw new RuntimeException('probe の出力を待てなかった (stream_select が失敗した)');
        }

        if ($ready === 0) {
            return;
        }

        foreach ($read as $descriptor => $pipe) {
            $chunk = fread($pipe, self::READ_CHUNK_BYTES);
            if ($chunk === false) {
                throw new RuntimeException('probe の出力を読めなかった');
            }
            $output[(int) $descriptor] .= $chunk;
        }
    }

    /**
     * 子・管・終了コードを回収する (冪等)。
     *
     * `proc_close()` は子が生きているあいだ待つ。だから本 runner は「子の終了を確認する」か
     * 「確実に落とす」かのどちらかを済ませてからしか呼ばない。
     *
     * @param  resource  $process
     * @param  array<int, resource>  $pipes  閉じた管はその場で unset する (部分完了を表現するため)
     * @param  array{processClosed: bool, closeCode: int|null}  $state
     */
    private static function reclaim($process, array &$pipes, array &$state): int
    {
        if ($state['processClosed']) {
            Assert::integer($state['closeCode']);

            return $state['closeCode'];
        }

        if (proc_get_status($process)['running']) {
            // シグナル送信が失敗しても即座には諦めない (自然終了を最終期限まで待つ)。
            proc_terminate($process, self::SIGNAL_TERMINATE);
            $killAt = microtime(true) + self::TERMINATION_GRACE_SECONDS;
            $giveUpAt = $killAt + self::KILL_WAIT_SECONDS;

            while (proc_get_status($process)['running']) {
                $now = microtime(true);
                if ($now >= $giveUpAt) {
                    throw new RuntimeException('probe の子プロセスを落とせなかった (最終期限を超えた)');
                }
                if ($now >= $killAt) {
                    proc_terminate($process, self::SIGNAL_KILL);
                    $killAt = $now + self::TERMINATION_GRACE_SECONDS;
                }
                usleep(self::IDLE_SLEEP_MICROSECONDS);
            }
        }

        foreach ([1, 2] as $descriptor) {
            $pipe = $pipes[$descriptor] ?? null;
            if (is_resource($pipe)) {
                fclose($pipe);
            }
            unset($pipes[$descriptor]);
        }

        // `proc_close()` は -1 を返す場合も資源を閉じている。戻ってきた時点で閉じ済みとして扱う
        // (「非負のときだけ完了」にすると閉じ済みの資源へ 2 度目を呼ぶ危険がある)。
        $closeCode = proc_close($process);
        $state['processClosed'] = true;
        $state['closeCode'] = $closeCode;

        return $closeCode;
    }

    /**
     * 一時ディレクトリ配下に書かれたファイルを相対パスの昇順で採取する。
     *
     * @return list<non-empty-string>
     */
    private static function collectWritten(string $temporaryRoot): array
    {
        $prefix = rtrim($temporaryRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $written = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temporaryRoot, FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if (! str_starts_with($path, $prefix)) {
                // 黙って捨てない (追えないものが出たら設計の前提が崩れている)。
                throw new RuntimeException('一時ディレクトリ外のファイルを採取した: '.$path);
            }

            $relative = substr($path, strlen($prefix));
            Assert::stringNotEmpty($relative);
            $written[] = $relative;
        }

        sort($written);

        return $written;
    }

    /** 再帰削除 (存在しなければ何もしない)。**失敗したら例外**にする (黙って残さない)。 */
    private static function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var SplFileInfo $entry */
        foreach ($iterator as $entry) {
            $removed = $entry->isDir() && ! $entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());

            if (! $removed) {
                throw new RuntimeException('一時ディレクトリの中身を消せなかった: '.$entry->getPathname());
            }
        }

        if (! rmdir($path)) {
            throw new RuntimeException('一時ディレクトリを消せなかった: '.$path);
        }
    }
}
