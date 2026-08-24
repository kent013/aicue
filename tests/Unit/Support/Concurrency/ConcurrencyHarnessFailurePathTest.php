<?php

declare(strict_types=1);

use App\Enums\ApiErrorCode;
use Dotenv\Dotenv;
use Tests\Support\Concurrency\BarrierTimeoutException;
use Tests\Support\Concurrency\ConcurrencyProbeRunner;
use Tests\Support\Concurrency\ConcurrencyProtocolException;
use Tests\Support\Concurrency\ConcurrentProbeObservation;
use Tests\Support\Concurrency\ConcurrentProbeResult;
use Tests\Support\Concurrency\ProbeDatabaseCoordinates;
use Tests\Support\Concurrency\ProbeEnvironment;
use Tests\Support\Concurrency\ProbeLaunchSpec;
use Tests\Support\Concurrency\ProbeProcess;
use Tests\Support\Concurrency\ProbeProcessFactory;
use Tests\Support\Concurrency\ProcessBarrier;
use Tests\Support\Concurrency\SignalName;
use Webmozart\Assert\Assert;

/*
 * ハーネス自身が**黙って緑になる**壊れ方を塞ぐ検査 (4 群)。
 *
 * ★**子プロセスを 1 本も起こさない**。偽の {@see ProbeProcessFactory} を差すか、
 *   純関数を直接叩く。起動仕様が値 ({@see ProbeLaunchSpec}) になっているから成立する。
 *
 * **保証の境界**: 群 4 が主張するのは「runner が {@see ProbeProcess} へ停止・強制終了・待機を
 * それぞれ要求すること」までである。**実 OS プロセスに対するシグナルの実効性は保証範囲外**
 * とする (実プロセスを起こすテストを増やすと正典の要素 (6) に反するため踏み込まない)。
 * 操作を 3 つに分けているのは、この主張を**呼び出し順込みで実際に固定できる**ようにするため。
 */

// ─────────────────────────────────────────────────────────────────────────────
// 偽の子プロセス (合図を書く側を演じる)
// ─────────────────────────────────────────────────────────────────────────────

/** 全子で共有する呼び出し記録 (順序と poll の交互性を固定するため) */
final class HarnessCallLog
{
    /** @var list<array{child: string, op: string}> シグナルと待機だけ (poll は含めない) */
    public array $operations = [];

    /** @var list<string> `isRunning()` の呼び出し順 (単一ループかどうかを見る) */
    public array $polls = [];

    public function record(string $childId, string $operation): void
    {
        $this->operations[] = ['child' => $childId, 'op' => $operation];
    }

    public function poll(string $childId): void
    {
        $this->polls[] = $childId;
    }

    public function resetPolls(): void
    {
        $this->polls = [];
    }

    /** @return list<string> */
    public function operationsFor(string $childId): array
    {
        $operations = [];
        foreach ($this->operations as $entry) {
            if ($entry['child'] === $childId) {
                $operations[] = $entry['op'];
            }
        }

        return $operations;
    }

    /** poll 記録が a と b を行き来した回数 (単一ループなら大きく、逐次処理なら 1 になる) */
    public function pollAlternations(): int
    {
        $alternations = 0;
        for ($i = 1; $i < count($this->polls); $i++) {
            if ($this->polls[$i] !== $this->polls[$i - 1]) {
                $alternations++;
            }
        }

        return $alternations;
    }
}

/**
 * 台本で動く偽の子。
 *
 * ★台本は `isRunning()` の呼び出しごとに 1 歩進む。runner は待機ループの中断条件で
 *   毎周回 `isRunning()` を呼ぶので、これが「子が動いた」ことの決定的な差し込み点になる
 *   (実時間に依存しないので締切の検査が安定する)。
 */
final class ScriptedProbeProcess implements ProbeProcess
{
    public int $step = 0;

    /** @var array<string, mixed> 台本が使う状態 */
    public array $bag = [];

    private bool $started = false;

    private bool $stopped = false;

    private int $finishedExitCode = 0;

    private string $stdout = '';

    /** @param Closure(self): void $script */
    public function __construct(
        public readonly ProbeLaunchSpec $spec,
        private readonly Closure $script,
        private readonly HarnessCallLog $log,
        private readonly bool $ignoreTerminate = false,
        private readonly bool $ignoreKill = false,
        private readonly bool $exitImmediately = false,
        #[SensitiveParameter] private readonly string $stderr = '',
    ) {}

    public function barrier(): ProcessBarrier
    {
        return new ProcessBarrier($this->spec->workspaceDirectory);
    }

    /** 台本の終わり: out 合図を置き、stdout を確定して停止する */
    public function finish(string $outJson, ?string $stdout = null, int $exitCode = 0): void
    {
        $this->barrier()->signal(SignalName::make('out', $this->spec->childId), $outJson);
        $this->stdout = $stdout ?? $outJson;
        $this->finishedExitCode = $exitCode;
        $this->stopped = true;
    }

    public function start(): void
    {
        $this->started = true;

        if ($this->exitImmediately) {
            $this->stopped = true;
            $this->finishedExitCode = 1;
        }
    }

    public function isRunning(): bool
    {
        $this->log->poll($this->spec->childId);

        if (! $this->started || $this->stopped) {
            return false;
        }

        ($this->script)($this);

        return ! $this->stopped;
    }

    public function exitCode(): ?int
    {
        return $this->stopped ? $this->finishedExitCode : null;
    }

    public function output(): string
    {
        return $this->stdout;
    }

    public function errorOutput(): string
    {
        return $this->stderr;
    }

    public function signalTerminate(): void
    {
        // ★回収の入口で「その時点で go / release が置かれていたか」を記録する。
        //   workspace は回収の最後に消えるので、ここでしか観測できない。
        $this->bag['go_at_terminate'] = harnessSignalExists($this->spec, 'go');
        $this->bag['release_at_terminate'] = harnessSignalExists($this->spec, 'release');

        $this->log->record($this->spec->childId, 'terminate');
        $this->log->resetPolls();

        if (! $this->ignoreTerminate) {
            $this->stopped = true;
        }
    }

    public function signalKill(): void
    {
        $this->log->record($this->spec->childId, 'kill');

        if (! $this->ignoreKill) {
            $this->stopped = true;
        }
    }

    public function waitFor(float $seconds): ?int
    {
        Assert::greaterThanEq($seconds, 0.0);
        $this->log->record($this->spec->childId, 'waitFor');

        return $this->exitCode();
    }
}

/** 偽の子を作る (作った子を child ID で引けるようにしておく) */
final class ScriptedProbeProcessFactory implements ProbeProcessFactory
{
    /** @var array<string, ScriptedProbeProcess> */
    public array $processes = [];

    /** @param Closure(ProbeLaunchSpec, HarnessCallLog): ScriptedProbeProcess $make */
    public function __construct(
        private readonly Closure $make,
        public readonly HarnessCallLog $log = new HarnessCallLog,
    ) {}

    public function create(ProbeLaunchSpec $spec): ProbeProcess
    {
        $process = ($this->make)($spec, $this->log);
        $this->processes[$spec->childId] = $process;

        return $process;
    }

    public function workspaceDirectory(): string
    {
        foreach ($this->processes as $process) {
            return $process->spec->workspaceDirectory;
        }

        throw new RuntimeException('偽の子がまだ 1 本も作られていない');
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// 検査用のちいさな道具
// ─────────────────────────────────────────────────────────────────────────────

function harnessWorkspace(): string
{
    $directory = sys_get_temp_dir().'/harness-'.bin2hex(random_bytes(8));
    Assert::true(mkdir($directory, 0700));
    chmod($directory, 0700);
    ProcessBarrier::prepareWorkspace($directory);

    return $directory;
}

function harnessRemoveDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory.'/'.$entry;
        if (is_dir($path)) {
            harnessRemoveDirectory($path);

            continue;
        }

        @unlink($path);
    }

    @rmdir($directory);
}

function harnessSignalExists(ProbeLaunchSpec $spec, string $name): bool
{
    $path = $spec->workspaceDirectory.'/signals/'.$name;
    clearstatcache(true, $path);

    return is_file($path);
}

function harnessSignalContents(ProbeLaunchSpec $spec, string $name): string
{
    return harnessSignalContentsAt($spec->workspaceDirectory, $name);
}

function harnessSignalContentsAt(string $workspace, string $name): string
{
    return (string) file_get_contents($workspace.'/signals/'.$name);
}

/** @return array<string, mixed> */
function harnessInput(ProbeLaunchSpec $spec): array
{
    $decoded = json_decode((string) file_get_contents($spec->inputFilePath()), true);
    Assert::isArray($decoded);

    return $decoded;
}

/**
 * 子が返す観測 JSON を組み立てる (親の受理条件をすべて満たす正例)。
 *
 * @param  array<string, mixed>  $overrides
 */
function harnessObservation(ProbeLaunchSpec $spec, string $goToken, bool $winner, array $overrides = []): string
{
    $input = harnessInput($spec);
    $uri = (string) $input['uri'];
    $rawBody = (string) $input['raw_body'];

    $values = [
        'child_id' => $spec->childId,
        'nonce' => $spec->nonce,
        'go_token' => $goToken,
        'http_status' => $winner ? 201 : 409,
        'error_code' => $winner ? null : ApiErrorCode::IdempotencyInProgress->value,
        'handler_executions' => $winner ? 1 : 0,
        'entered_handler' => $winner,
        'route_name' => (string) $input['route_name'],
        'uri' => $uri,
        'request_hash' => hash('sha256', 'POST|'.$uri.'|'.$rawBody),
        'api_key_id' => 4242,
        'cache_default' => 'array',
        'cache_store_driver' => 'array',
        ...ProbeDatabaseCoordinates::fromParentConfig()->toObservationValues(),
    ];

    return json_encode([...$values, ...$overrides], JSON_THROW_ON_ERROR);
}

/**
 * 正常なプロトコルを演じる台本。
 *
 * @param  array<string, mixed>  $observationOverrides
 * @return Closure(ScriptedProbeProcess): void
 */
function harnessProtocolScript(
    string $winnerId,
    array $observationOverrides = [],
    ?string $stdoutOverride = null,
    int $exitCode = 0,
): Closure {
    return static function (ScriptedProbeProcess $process) use (
        $winnerId,
        $observationOverrides,
        $stdoutOverride,
        $exitCode,
    ): void {
        $spec = $process->spec;
        $isWinner = $spec->childId === $winnerId;

        if ($process->step === 0) {
            // ★ready を出す時点で go が**まだ無い**ことを記録する
            //   (go token が ready の検証より前に作られていないことの裏取り)。
            $process->bag['go_existed_at_ready'] = harnessSignalExists($spec, 'go');
            // 入力ファイルは回収で消えるので、読んだ内容をここで控える
            $process->bag['input'] = harnessInput($spec);
            $process->barrier()->signal(SignalName::make('ready', $spec->childId), $spec->nonce);
            $process->step = 1;

            return;
        }

        if ($process->step === 1) {
            if (! harnessSignalExists($spec, 'go')) {
                return;
            }

            $process->bag['go_token'] = harnessSignalContents($spec, 'go');
            $process->step = $isWinner ? 2 : 3;

            return;
        }

        $goToken = (string) $process->bag['go_token'];

        if ($process->step === 2) {
            $process->barrier()->signal(
                SignalName::make('entered', $spec->childId),
                $spec->nonce.':'.$goToken,
            );
            $process->step = 4;

            return;
        }

        if ($process->step === 3) {
            $process->finish(
                harnessObservation($spec, $goToken, winner: false, overrides: $observationOverrides),
                exitCode: $exitCode,
            );

            return;
        }

        if ($process->step === 4 && harnessSignalExists($spec, 'release')) {
            $json = harnessObservation($spec, $goToken, winner: true, overrides: $observationOverrides);
            $process->finish($json, stdout: $stdoutOverride, exitCode: $exitCode);
        }
    };
}

/**
 * 偽 factory を差して runner を走らせる。
 *
 * @param  array<string, mixed>  $requestBody
 */
function harnessRun(
    ScriptedProbeProcessFactory $factory,
    float $timeoutSeconds = 5.0,
    #[SensitiveParameter] array $requestBody = ['title' => '並行 claim の検体'],
    #[SensitiveParameter] string $plainApiKey = 'harness-plain-key',
): ConcurrentProbeResult {
    return ConcurrencyProbeRunner::run(
        idempotencyKey: 'harness-'.bin2hex(random_bytes(6)),
        plainApiKey: $plainApiKey,
        requestBody: $requestBody,
        timeoutSeconds: $timeoutSeconds,
        factory: $factory,
    );
}

/**
 * 例外の連鎖 (previous を含む) の全文。
 *
 * ★**メッセージと trace の両方**を集める。メッセージの伏せ字だけでは trace の引数に残った
 *   秘密を捕まえられない (`zend.exception_ignore_args=0` の環境では文字列引数が出る)。
 */
function harnessThrowableText(?Throwable $e): string
{
    $text = '';
    while ($e instanceof Throwable) {
        $text .= $e::class.': '.$e->getMessage()."\n".$e->getTraceAsString()."\n";
        $e = $e->getPrevious();
    }

    return $text;
}

// ─────────────────────────────────────────────────────────────────────────────
// 群 1: ProcessBarrier (合図)
// ─────────────────────────────────────────────────────────────────────────────

test('群1-1: 現れない合図を待ち続けず締切で例外になる', function (): void {
    $workspace = harnessWorkspace();

    try {
        $barrier = new ProcessBarrier($workspace);

        expect(fn () => $barrier->await(SignalName::make('go'), 0.05))
            ->toThrow(BarrierTimeoutException::class);
    } finally {
        harnessRemoveDirectory($workspace);
    }
});

test('群1-2: 合図はあるのに読めないときは空として通さず落ちる', function (): void {
    $workspace = harnessWorkspace();

    try {
        // ★偽の読み手が決定的に false を返す (chmod 000 は root 実行で不安定なので使わない)。
        $barrier = new ProcessBarrier($workspace, static fn (string $path): string|false => false);
        $barrier->signal(SignalName::make('go'), 'token');

        expect(fn () => $barrier->await(SignalName::make('go'), 1.0))
            ->toThrow(ConcurrencyProtocolException::class, '在るのに読めない');
    } finally {
        harnessRemoveDirectory($workspace);
    }
});

test('群1-3: 中断条件が成立したら締切を待たずに抜ける', function (): void {
    $workspace = harnessWorkspace();

    try {
        $barrier = new ProcessBarrier($workspace);
        $startedAt = hrtime(true);

        expect(fn () => $barrier->await(
            SignalName::make('go'),
            30.0,
            static function (): void {
                throw new RuntimeException('中断条件が成立した');
            },
        ))->toThrow(RuntimeException::class, '中断条件が成立した');

        expect((hrtime(true) - $startedAt) / 1_000_000_000)->toBeLessThan(5.0);
    } finally {
        harnessRemoveDirectory($workspace);
    }
});

test('群1-4: 書きかけ (partial/) を完成した合図として扱わない', function (): void {
    $workspace = harnessWorkspace();

    try {
        file_put_contents($workspace.'/partial/'.bin2hex(random_bytes(8)), 'まだ書きかけ');

        $barrier = new ProcessBarrier($workspace);
        expect($barrier->present(SignalName::all()))->toBe([]);
        expect(fn () => $barrier->await(SignalName::make('go'), 0.05))
            ->toThrow(BarrierTimeoutException::class);
    } finally {
        harnessRemoveDirectory($workspace);
    }
});

test('群1-5: 未知の完成ファイルが置かれたら列挙時に拒否する (無視しない)', function (): void {
    $workspace = harnessWorkspace();

    try {
        file_put_contents($workspace.'/signals/entered-c', 'unknown');

        $barrier = new ProcessBarrier($workspace);
        expect(fn () => $barrier->present(SignalName::all()))
            ->toThrow(ConcurrencyProtocolException::class, 'entered-c');
    } finally {
        harnessRemoveDirectory($workspace);
    }
});

test('群1-6: global 種別に child ID を付けた合図名は作れない', function (): void {
    expect(fn () => SignalName::make('go', 'a'))->toThrow(InvalidArgumentException::class);
    expect(fn () => SignalName::make('release', 'b'))->toThrow(InvalidArgumentException::class);
});

test('群1-7: child ID 無しの ready / entered / out は作れない', function (): void {
    foreach (SignalName::PER_CHILD_KINDS as $kind) {
        expect(fn () => SignalName::make($kind))->toThrow(InvalidArgumentException::class);
    }
});

test('群1-8: 実在しない child ID (ready-c / パス片) は作れない — 生成できるのは 8 通りだけ', function (): void {
    expect(fn () => SignalName::make('ready', 'c'))->toThrow(InvalidArgumentException::class);
    expect(fn () => SignalName::make('ready', '../outside'))->toThrow(InvalidArgumentException::class);
    expect(fn () => SignalName::make('ready', 'a/b'))->toThrow(InvalidArgumentException::class);
    expect(fn () => SignalName::make('unknown-kind', 'a'))->toThrow(InvalidArgumentException::class);

    $values = array_map(static fn (SignalName $name): string => $name->value, SignalName::all());
    sort($values);
    expect($values)->toBe([
        'entered-a', 'entered-b', 'go', 'out-a', 'out-b', 'ready-a', 'ready-b', 'release',
    ]);
});

test('群1-9: 同じ合図を 2 回置こうとすると二重送信として失敗する', function (): void {
    $workspace = harnessWorkspace();

    try {
        $barrier = new ProcessBarrier($workspace);
        $barrier->signal(SignalName::make('ready', 'a'), 'nonce-1');

        expect(fn () => $barrier->signal(SignalName::make('ready', 'a'), 'nonce-2'))
            ->toThrow(ConcurrencyProtocolException::class, '2 回置こうとした');

        // 上書きされていない (最初の中身が残る)
        expect(harnessSignalContentsAt($workspace, 'ready-a'))->toBe('nonce-1');
    } finally {
        harnessRemoveDirectory($workspace);
    }
});

test('群1-10: target が不在のままの配置失敗は二重送信と誤分類しない', function (): void {
    $workspace = harnessWorkspace();

    try {
        // ★ProcessBarrier の構築は signals/ の実在を要求するので、**構築後に**消す。
        //   これで target が不在のまま配置だけが失敗する形を作れる。
        $barrier = new ProcessBarrier($workspace);
        harnessRemoveDirectory($workspace.'/signals');

        expect(fn () => $barrier->signal(SignalName::make('go'), 'token'))
            ->toThrow(ConcurrencyProtocolException::class, '配置できなかった');
    } finally {
        harnessRemoveDirectory($workspace);
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 群 2: ProbeEnvironment (遮断)
// ─────────────────────────────────────────────────────────────────────────────

test('群2-9: DB_URL が非空なら子を起こさない', function (): void {
    config(['database.connections.pgsql.url' => 'pgsql://user:pass@db:5432/other']);

    expect(fn () => ProbeEnvironment::envFileValues())
        ->toThrow(RuntimeException::class, '個別キー接続のレーンを前提にする');
});

test('群2-10: dev DB 名なら子を起こさない (単一点ガードを親側でも通す)', function (): void {
    config(['database.connections.pgsql.database' => 'app']);

    expect(fn () => ProbeEnvironment::envFileValues())
        ->toThrow(InvalidArgumentException::class);
});

test('群2-11: 許可キー以外を env ファイルへ書かない', function (): void {
    expect(fn () => ProbeEnvironment::assertEnvFileKeys(['APP_ENV' => 'testing', 'AWS_SECRET_ACCESS_KEY' => 'x']))
        ->toThrow(InvalidArgumentException::class);

    // ★必須キーの**欠落**も落とす (穴は子の .env 読み込みで埋まりうる = まさに塞ぎたい形)
    expect(fn () => ProbeEnvironment::assertEnvFileKeys(['APP_ENV' => 'testing']))
        ->toThrow(InvalidArgumentException::class);
});

test('群2-12: env 値に改行 / CR があれば書かずに例外 (キー注入の拒否)', function (): void {
    expect(fn () => ProbeEnvironment::assertNoLineInjection(['DB_PASSWORD' => "pass\nDB_DATABASE=app"]))
        ->toThrow(RuntimeException::class, '改行を含むキーは書けない');

    expect(fn () => ProbeEnvironment::assertNoLineInjection(['DB_PASSWORD' => "pass\rDB_DATABASE=app"]))
        ->toThrow(RuntimeException::class, '改行を含むキーは書けない');

    // 正規入力を誤検出しない
    ProbeEnvironment::assertNoLineInjection(['DB_PASSWORD' => 'p a$s#s"\\']);
});

test('群2-13: encodeLine の往復は自前パーサと phpdotenv の双方で元の値に戻る', function (): void {
    $workspace = harnessWorkspace();

    try {
        // ★`$` / `${NAME}` は二重引用符の中で変数展開されうるので、自前パーサとの往復だけでは
        //   「phpdotenv が同じ値として読む」ことは言えない。**双方**に通して固定する。
        $values = [
            'APP_ENV' => '',
            'APP_KEY' => 'back\\slash',
            'APP_URL' => 'quote"inside',
            'APP_DEBUG' => 'hash#inside',
            'CIPHERSWEET_KEY' => '  spaced  ',
            'BCRYPT_ROUNDS' => 'dollar$sign',
            'DB_PASSWORD' => 'brace${NAME}brace',
        ];

        $lines = '';
        foreach ($values as $key => $value) {
            $lines .= ProbeEnvironment::encodeLine($key, $value);
        }
        file_put_contents($workspace.'/.env.roundtrip', $lines);

        expect(ProbeEnvironment::parseEnvFile($workspace.'/.env.roundtrip'))->toBe($values);

        // プロジェクトが実際に使っている phpdotenv の parser でも同じ値になる
        $loaded = Dotenv::createArrayBacked($workspace, '.env.roundtrip')->load();
        foreach ($values as $key => $value) {
            expect($loaded[$key] ?? null)->toBe($value);
        }
    } finally {
        harnessRemoveDirectory($workspace);
    }
});

test('群2-13b: 厳格パーサは encoder が作れない形を 1 つも受理しない', function (): void {
    $workspace = harnessWorkspace();

    try {
        // ★encoder が作る escape は `\\` / `\"` / `\$` の 3 種だけである。
        //   未知 escape を受理してバックスラッシュを落とす形は
        //   「唯一の書式だけを受理し phpdotenv と同じ規則で復号する」という宣言と食い違う。
        $rejected = [
            'unknown-escape' => 'FOO="a\\qb"'."\n",
            // 素の `$` は encoder が必ず escape するので canonical には現れない。
            // 受理すると phpdotenv 側の変数展開と実効値が食い違う。
            'bare-dollar' => 'FOO="a${NAME}b"'."\n",
            'duplicate-key' => 'FOO="a"'."\n".'FOO="b"'."\n",
            'unquoted-value' => 'FOO=bar'."\n",
            'lowercase-key' => 'foo="bar"'."\n",
            'unterminated-quote' => 'FOO="bar'."\n",
            'trailing-garbage' => 'FOO="bar" # comment'."\n",
        ];

        foreach ($rejected as $label => $contents) {
            $path = $workspace.'/.env.'.$label;
            file_put_contents($path, $contents);

            expect(fn () => ProbeEnvironment::parseEnvFile($path))
                ->toThrow(RuntimeException::class);
        }
    } finally {
        harnessRemoveDirectory($workspace);
    }
});

test('群2-14: 0700 / 0600 以外の権限では子を起こさない', function (): void {
    expect(fn () => ProbeEnvironment::assertSafePermissions(0755, 0600, 0600))
        ->toThrow(RuntimeException::class, '子プロセスを起こさない');
    expect(fn () => ProbeEnvironment::assertSafePermissions(0700, 0644, 0600))
        ->toThrow(RuntimeException::class, '子プロセスを起こさない');
    expect(fn () => ProbeEnvironment::assertSafePermissions(0700, 0600, 0644))
        ->toThrow(RuntimeException::class, '子プロセスを起こさない');

    ProbeEnvironment::assertSafePermissions(0700, 0600, 0600);
});

test('群2-15: 保護ファイルは作成時点で 0600 で、既存ファイルがあれば作らない', function (): void {
    $workspace = harnessWorkspace();

    try {
        $path = $workspace.'/secret.json';
        ProbeEnvironment::writeProtectedFile($path, '{"secret":true}');

        expect(ProbeEnvironment::mode($path))->toBe(0600);
        expect(file_get_contents($path))->toBe('{"secret":true}');

        expect(fn () => ProbeEnvironment::writeProtectedFile($path, 'x'))
            ->toThrow(RuntimeException::class, '子へ渡すファイルを作れない');
    } finally {
        harnessRemoveDirectory($workspace);
    }
});

test('群2-16: 未知の DB_* / APP_* がプロセス環境に混入していたら拒否する (env -i の退行)', function (): void {
    expect(fn () => ProbeEnvironment::assertProcessEnvironmentKeys([
        ...ProbeEnvironment::ALLOWED_PROCESS_ENV_KEYS,
        'DB_URL',
    ]))->toThrow(RuntimeException::class, 'env -i の退行');

    expect(fn () => ProbeEnvironment::assertProcessEnvironmentKeys([
        ...ProbeEnvironment::ALLOWED_PROCESS_ENV_KEYS,
        'APP_KEY',
    ]))->toThrow(RuntimeException::class, 'env -i の退行');

    // 欠落も落とす (載せ忘れは設定の出所を欠く)
    expect(fn () => ProbeEnvironment::assertProcessEnvironmentKeys(['CONCURRENCY_PROBE_ENV_DIR']))
        ->toThrow(RuntimeException::class, 'env -i の退行');

    ProbeEnvironment::assertProcessEnvironmentKeys(array_reverse(ProbeEnvironment::ALLOWED_PROCESS_ENV_KEYS));
});

// ─────────────────────────────────────────────────────────────────────────────
// 群 3: ConcurrentProbeObservation (観測の型)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * 受理条件をすべて満たす観測 (群 3 の基準値)。
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function harnessObservationArray(array $overrides = []): array
{
    return [
        'child_id' => 'a',
        'nonce' => 'nonce-a',
        'go_token' => 'go-token',
        'http_status' => 409,
        'error_code' => ApiErrorCode::IdempotencyInProgress->value,
        'handler_executions' => 0,
        'entered_handler' => false,
        'route_name' => 'api.v1.__probe__',
        'uri' => 'api/v1/__probe__',
        'request_hash' => str_repeat('0', 64),
        'api_key_id' => 7,
        'cache_default' => 'array',
        'cache_store_driver' => 'array',
        'db_driver' => 'pgsql',
        'db_host' => '127.0.0.1',
        'db_port' => 5432,
        'db_database' => 'app_test_deadbeef',
        'db_username' => 'app',
        'db_charset' => 'utf8',
        'db_sslmode' => 'prefer',
        'db_url' => '',
        ...$overrides,
    ];
}

test('群3-17: 必須キー欠落 / 未知キー / 型違いを通さない (キャストで救わない)', function (): void {
    $missing = harnessObservationArray();
    unset($missing['nonce']);
    expect(fn () => ConcurrentProbeObservation::fromDecodedJson($missing))
        ->toThrow(ConcurrencyProtocolException::class, 'キー集合が一致しない');

    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
        harnessObservationArray(['unexpected_key' => 1])
    ))->toThrow(ConcurrencyProtocolException::class, 'キー集合が一致しない');

    // ★"409" のような数値文字列はキャストで救わない
    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
        harnessObservationArray(['http_status' => '409'])
    ))->toThrow(ConcurrencyProtocolException::class, 'http_status が整数でない');

    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
        harnessObservationArray(['db_port' => '5432'])
    ))->toThrow(ConcurrencyProtocolException::class, 'db_port が整数でない');

    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
        harnessObservationArray(['entered_handler' => 0])
    ))->toThrow(ConcurrencyProtocolException::class, 'entered_handler が真偽値でない');

    expect(fn () => ConcurrentProbeObservation::fromDecodedJson('文字列'))
        ->toThrow(ConcurrencyProtocolException::class, '観測が配列でない');

    // 正例は通る (拒否だけでなく誤検出しないことも固定する)
    expect(ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray())->childId)->toBe('a');
});

test('群3-18: error_code が空文字なら通さない (勝者は null / 敗者は非空)', function (): void {
    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
        harnessObservationArray(['error_code' => ''])
    ))->toThrow(ConcurrencyProtocolException::class, 'error_code は null か非空文字列');

    $winner = ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray([
        'error_code' => null,
        'http_status' => 201,
        'handler_executions' => 1,
        'entered_handler' => true,
    ]));
    expect($winner->errorCode)->toBeNull();
});

test('群3-19: entered_handler と handler_executions の矛盾を通さない', function (): void {
    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
        harnessObservationArray(['entered_handler' => true, 'handler_executions' => 0])
    ))->toThrow(ConcurrencyProtocolException::class, 'handler_executions が 0');

    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
        harnessObservationArray(['entered_handler' => false, 'handler_executions' => 1])
    ))->toThrow(ConcurrencyProtocolException::class, 'handler_executions が 0 でない');
});

test('群3-20: assertIdentity は childId / nonce / go token の不一致を通さない', function (): void {
    $observation = ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray());

    expect(fn () => $observation->assertIdentity('b', 'nonce-a', 'go-token'))
        ->toThrow(ConcurrencyProtocolException::class, 'child_id');
    expect(fn () => $observation->assertIdentity('a', 'nonce-b', 'go-token'))
        ->toThrow(ConcurrencyProtocolException::class, 'nonce');
    expect(fn () => $observation->assertIdentity('a', 'nonce-a', 'another-token'))
        ->toThrow(ConcurrencyProtocolException::class, 'go token が一致しない');

    $observation->assertIdentity('a', 'nonce-a', 'go-token');
});

test('群3-21: assertLost は idempotency_conflict / indeterminate を通さない', function (): void {
    foreach ([ApiErrorCode::IdempotencyConflict, ApiErrorCode::IdempotencyIndeterminate] as $code) {
        $observation = ConcurrentProbeObservation::fromDecodedJson(
            harnessObservationArray(['error_code' => $code->value])
        );

        expect(fn () => $observation->assertLost(str_repeat('0', 64)))
            ->toThrow(ConcurrencyProtocolException::class, 'error_code');
    }

    // 409 以外も通さない
    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
        harnessObservationArray(['http_status' => 500])
    )->assertLost(str_repeat('0', 64)))
        ->toThrow(ConcurrencyProtocolException::class, '409 でない');

    ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray())
        ->assertLost(str_repeat('0', 64));
});

test('群3-22: assertLost は request_hash の不一致を通さない', function (): void {
    $observation = ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray());

    expect(fn () => $observation->assertLost(str_repeat('f', 64)))
        ->toThrow(ConcurrencyProtocolException::class, 'request_hash');
});

test('群3-23: assertDatabaseCoordinates は host / port / username 違いと db_url 非空を通さない', function (): void {
    $expected = new ProbeDatabaseCoordinates(
        driver: 'pgsql',
        host: '127.0.0.1',
        port: 5432,
        database: 'app_test_deadbeef',
        username: 'app',
        charset: 'utf8',
        sslmode: 'prefer',
        url: '',
    );

    ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray())
        ->assertDatabaseCoordinates($expected);

    foreach ([
        ['db_host' => '10.0.0.1'],
        ['db_port' => 15432],
        ['db_username' => 'postgres'],
        ['db_database' => 'app'],
        ['db_charset' => 'utf8mb4'],
        ['db_sslmode' => 'disable'],
    ] as $override) {
        expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
            harnessObservationArray($override)
        )->assertDatabaseCoordinates($expected))
            ->toThrow(ConcurrencyProtocolException::class, '実効 DB 座標が親と一致しない');
    }

    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
        harnessObservationArray(['db_url' => 'pgsql://db/other'])
    ))->toThrow(ConcurrencyProtocolException::class, 'db_url が非空');
});

test('群3-23b: assertAppLocksDisabled は store 名と裏打ち driver の両方を見る', function (): void {
    // 正例 (両方 array) は通る
    ConcurrentProbeObservation::fromDecodedJson(harnessObservationArray())->assertAppLocksDisabled();

    // ★2 つの負例は**独立**でなければならない。片方だけの検査に退行しても
    //   もう片方の負例が赤くなる = 「両方を見る」という判断がテストで固定される。
    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
        harnessObservationArray(['cache_default' => 'redis'])
    )->assertAppLocksDisabled())
        ->toThrow(ConcurrencyProtocolException::class, 'array に固定できていない');

    expect(fn () => ConcurrentProbeObservation::fromDecodedJson(
        harnessObservationArray(['cache_store_driver' => 'redis'])
    )->assertAppLocksDisabled())
        ->toThrow(ConcurrencyProtocolException::class, 'array に固定できていない');
});

// ─────────────────────────────────────────────────────────────────────────────
// 群 4: ConcurrencyProbeRunner (調停と回収)
// ─────────────────────────────────────────────────────────────────────────────

test('群4-25: 正常系 — go token は ready 検証の後に生成され、事前に子へ渡らない', function (): void {
    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript('a'), $log),
    );

    $result = harnessRun($factory);

    expect(array_keys($result->observations))->toHaveCount(2);
    [$winner, $loser] = $result->partition();
    expect($winner->enteredHandler)->toBeTrue();
    expect($loser->errorCode)->toBe(ApiErrorCode::IdempotencyInProgress->value);

    foreach ($factory->processes as $process) {
        // ★ready を書いた時点で go は存在しなかった (= 検証の後に作られている)
        expect($process->bag['go_existed_at_ready'])->toBeFalse();
        // ★入力ファイルにも go token は入っていない (読まずに正しい値は書けない)
        expect(array_keys(harnessInputSnapshot($process)))->not->toContain('go_token');
    }
});

/**
 * 入力ファイルは回収で消えるので、台本が読んだ内容を控えておく。
 *
 * @return array<string, mixed>
 */
function harnessInputSnapshot(ScriptedProbeProcess $process): array
{
    $snapshot = $process->bag['input'] ?? null;
    Assert::isArray($snapshot);

    return $snapshot;
}

test('群4-24: ready の nonce が割り当てと違えば go を出さない', function (): void {
    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, static function (ScriptedProbeProcess $process): void {
            if ($process->step !== 0) {
                return;
            }
            $process->barrier()->signal(
                SignalName::make('ready', $process->spec->childId),
                'すり替えられた nonce',
            );
            $process->step = 1;
        }, $log),
    );

    expect(fn () => harnessRun($factory, timeoutSeconds: 2.0))
        ->toThrow(ConcurrencyProtocolException::class, 'ready の nonce');

    // 回収の入口 (TERM) の時点で go は 1 度も置かれていない
    foreach ($factory->processes as $process) {
        expect($process->bag['go_at_terminate'] ?? null)->toBeFalse();
    }
});

test('群4-26: entered が 2 つ出たら締切を待たず二重実行として落ちる', function (): void {
    // ★両方が勝者を演じる = 探している退行そのもの
    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript($spec->childId), $log),
    );

    $startedAt = hrtime(true);
    expect(fn () => harnessRun($factory, timeoutSeconds: 20.0))
        ->toThrow(ConcurrencyProtocolException::class, '二重実行を検出');

    // 締切 (20 秒) を待たずに抜ける
    expect((hrtime(true) - $startedAt) / 1_000_000_000)->toBeLessThan(5.0);
});

test('群4-27: 未知 child ID の entered が現れたら拒否する', function (): void {
    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, static function (ScriptedProbeProcess $process): void {
            if ($process->step !== 0) {
                return;
            }
            file_put_contents($process->spec->workspaceDirectory.'/signals/entered-c', 'unknown');
            $process->step = 1;
        }, $log),
    );

    expect(fn () => harnessRun($factory, timeoutSeconds: 2.0))
        ->toThrow(ConcurrencyProtocolException::class, 'entered-c');
});

test('群4-28: 子が観測を出さずに終わったら観測なしのまま通さない', function (): void {
    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
            $spec,
            static function (ScriptedProbeProcess $process): void {},
            $log,
            exitImmediately: true,
            stderr: 'fatal: 設定の出所が壊れている',
        ),
    );

    expect(fn () => harnessRun($factory, timeoutSeconds: 2.0))
        ->toThrow(ConcurrencyProtocolException::class, '観測を出さずに終了した');
});

test('群4-29: 敗者の out が検査を通らなければ release を置かない', function (): void {
    // ★body 違いの conflict は「勝者 1 / 敗者 1」まで成立して**緑になりうる**形である
    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript('a', [
            'error_code' => ApiErrorCode::IdempotencyConflict->value,
        ]), $log),
    );

    expect(fn () => harnessRun($factory, timeoutSeconds: 3.0))
        ->toThrow(ConcurrencyProtocolException::class, 'error_code');

    // 勝者 (a) は release を待ったまま回収された = release は置かれていない
    expect($factory->processes['a']->bag['release_at_terminate'] ?? null)->toBeFalse();
});

test('群4-30: stdout の JSON と out ファイルが不一致なら通さない', function (): void {
    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
            $spec,
            harnessProtocolScript('a', stdoutOverride: '{"child_id":"a"}'),
            $log,
        ),
    );

    expect(fn () => harnessRun($factory, timeoutSeconds: 3.0))
        ->toThrow(ConcurrencyProtocolException::class, 'stdout と out ファイルの中身が一致しない');
});

test('群4-31: exit code が非ゼロなら通さない', function (): void {
    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript('a', exitCode: 3), $log),
    );

    expect(fn () => harnessRun($factory, timeoutSeconds: 3.0))
        ->toThrow(ConcurrencyProtocolException::class, '終了コードが 0 でない');
});

test('群4-32: 勝者・敗者が 1:1 に分かれないなら通さない', function (): void {
    // 勝者側も entered_handler=false と申告する (行だけを見ると気付けない形)
    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript('a', [
            'entered_handler' => false,
            'handler_executions' => 0,
            'http_status' => 409,
            'error_code' => ApiErrorCode::IdempotencyInProgress->value,
        ]), $log),
    );

    expect(fn () => harnessRun($factory, timeoutSeconds: 3.0))
        ->toThrow(ConcurrencyProtocolException::class, '1:1 に分かれない');
});

test('群4-33: 作業の締切は段ごとに更新されない (3 段待っても総時間が締切を超えない)', function (): void {
    // ★ready-a を 0.5 秒後、ready-b を 0.9 秒後に出し、entered は永久に出さない。
    //   単一の絶対 deadline (1.0 秒) なら**合計 1.0 秒**で打ち切られる。
    //   段ごとに締切を更新する実装だと 0.5 + 0.4 + 1.0 = 1.9 秒かかる。
    $factory = new ScriptedProbeProcessFactory(
        static function (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess {
            $delay = $spec->childId === 'a' ? 0.5 : 0.9;

            return new ScriptedProbeProcess($spec, static function (ScriptedProbeProcess $process) use ($delay): void {
                $process->bag['started_at'] ??= hrtime(true);
                if ($process->step !== 0) {
                    return;
                }
                if ((hrtime(true) - $process->bag['started_at']) / 1_000_000_000 < $delay) {
                    return;
                }
                $process->barrier()->signal(SignalName::make('ready', $process->spec->childId), $process->spec->nonce);
                $process->step = 1;
            }, $log);
        },
    );

    $startedAt = hrtime(true);
    expect(fn () => harnessRun($factory, timeoutSeconds: 1.0))
        ->toThrow(BarrierTimeoutException::class);

    expect((hrtime(true) - $startedAt) / 1_000_000_000)->toBeLessThan(1.5);
});

test('群4-34/35: 応答しない子へ TERM → 待機 → KILL → 待機 が順に要求される (締切を使い切った後でも)', function (): void {
    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
            $spec,
            static function (ScriptedProbeProcess $process): void {},
            $log,
            ignoreTerminate: true,
            ignoreKill: true,
        ),
    );

    // 作業の締切をほぼ 0 にしても、回収専用の予算で回収操作は要求される
    expect(fn () => harnessRun($factory, timeoutSeconds: 0.05))
        ->toThrow(ConcurrencyProtocolException::class, '停止を確認できない子が残っている');

    foreach (ConcurrencyProbeRunner::CHILD_IDS as $childId) {
        expect($factory->log->operationsFor($childId))->toBe(['terminate', 'waitFor', 'kill', 'waitFor']);
    }

    harnessRemoveDirectory($factory->workspaceDirectory());
});

test('群4-36: 混在ケース — TERM は両方へ / KILL は残った子だけへ / 予算内に収まる', function (): void {
    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
            $spec,
            static function (ScriptedProbeProcess $process): void {},
            $log,
            // b だけ TERM を無視する (KILL では止まる)
            ignoreTerminate: $spec->childId === 'b',
        ),
    );

    $startedAt = hrtime(true);
    expect(fn () => harnessRun($factory, timeoutSeconds: 0.05))
        ->toThrow(BarrierTimeoutException::class);
    $elapsed = (hrtime(true) - $startedAt) / 1_000_000_000;

    expect($factory->log->operationsFor('a'))->toBe(['terminate', 'waitFor']);
    expect($factory->log->operationsFor('b'))->toBe(['terminate', 'waitFor', 'kill', 'waitFor']);

    // ★子単位の逐次処理だと 1 子目で予算を使い切って 2 子目の回収時間が残らない。
    //   フェーズ単位なら子数にかかわらず予算内に収まる。
    expect($elapsed)->toBeLessThan(0.05 + ConcurrencyProbeRunner::REAP_BUDGET_SECONDS);
});

test('群4-37/38/39/41: 停止を確認できない子が残ったら workspace を残し、秘密だけ消す', function (): void {
    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
            $spec,
            static function (ScriptedProbeProcess $process): void {},
            $log,
            ignoreTerminate: true,
            ignoreKill: true,
        ),
    );

    expect(fn () => harnessRun($factory, timeoutSeconds: 0.05))
        ->toThrow(ConcurrencyProtocolException::class, '停止を確認できない子が残っている');

    $workspace = $factory->workspaceDirectory();

    try {
        // 37: workspace を削除していない (まだ動いている子が削除済みパスへ書くのを防ぐ)
        expect(is_dir($workspace))->toBeTrue();

        // 38: 秘密 (env ファイル / 入力ファイル) は回収の成否にかかわらず消えている
        expect(file_exists($workspace.'/'.ProbeEnvironment::ENV_FILE_NAME))->toBeFalse();
        foreach ($factory->processes as $process) {
            expect(file_exists($process->spec->inputFilePath()))->toBeFalse();
        }

        // 39: 非秘密の診断材料は残っている
        expect(is_dir($workspace.'/signals'))->toBeTrue();

        // 41: 残置した workspace の mode は 0700
        expect(ProbeEnvironment::mode($workspace))->toBe(0700);
    } finally {
        harnessRemoveDirectory($workspace);
    }
});

/** 0500 のディレクトリでも書けてしまう実行者 (root 等) では削除失敗を再現できない */
function harnessCanBlockUnlink(): bool
{
    $probe = harnessWorkspace();
    chmod($probe, 0500);
    $writable = @file_put_contents($probe.'/probe.txt', 'x') !== false;
    chmod($probe, 0700);
    harnessRemoveDirectory($probe);

    return ! $writable;
}

/** workspace を書き込み不可にして秘密の unlink を失敗させる台本 */
function harnessLockWorkspaceScript(): Closure
{
    return static function (ScriptedProbeProcess $process): void {
        chmod($process->spec->workspaceDirectory, 0500);
        $process->step = 1;
    };
}

test('群4-40: 秘密ファイルを消せなかったら黙って通らない (全対象のパスを明示した例外)', function (): void {
    if (! harnessCanBlockUnlink()) {
        $this->markTestSkipped('この実行者は 0500 のディレクトリでも削除できるため検査できない');
    }

    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessLockWorkspaceScript(), $log),
    );

    $thrown = null;
    try {
        harnessRun($factory, timeoutSeconds: 0.1);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    $workspace = $factory->workspaceDirectory();
    chmod($workspace, 0700);

    try {
        expect($thrown)->toBeInstanceOf(ConcurrencyProtocolException::class);
        expect($thrown?->getMessage())->toContain('秘密を含むファイルを削除できなかった');

        // ★**1 件目の失敗で抜けない**ことを固定する。抜けると 2 件目以降の削除が省略され、
        //   消せたはずの秘密が残る。3 つの対象がすべて例外に載っていることで裏を取る。
        expect($thrown?->getMessage())->toContain(ProbeEnvironment::ENV_FILE_NAME);
        expect($thrown?->getMessage())->toContain('input-a.json');
        expect($thrown?->getMessage())->toContain('input-b.json');

        // ★元の失敗は畳んで捨てない
        expect($thrown?->getPrevious())->not->toBeNull();
    } finally {
        harnessRemoveDirectory($workspace);
    }
});

test('群4-44: 秘密削除失敗 + 停止未確認 + 権限不正 が 1 つの例外へまとめて載る', function (): void {
    if (! harnessCanBlockUnlink()) {
        $this->markTestSkipped('この実行者は 0500 のディレクトリでも削除できるため検査できない');
    }

    // ★先に見つかった 1 つで打ち切ると、同時に起きているもう一方の危険が診断から消える。
    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
            $spec,
            harnessLockWorkspaceScript(),
            $log,
            ignoreTerminate: true,
            ignoreKill: true,
        ),
    );

    $thrown = null;
    try {
        harnessRun($factory, timeoutSeconds: 0.05);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    $workspace = $factory->workspaceDirectory();
    chmod($workspace, 0700);

    try {
        expect($thrown)->toBeInstanceOf(ConcurrencyProtocolException::class);
        expect($thrown?->getMessage())->toContain('秘密を含むファイルを削除できなかった');
        expect($thrown?->getMessage())->toContain('停止を確認できない子が残っている');
        expect($thrown?->getMessage())->toContain('残置する workspace の権限が 0700 でない');
        expect($thrown?->getPrevious())->not->toBeNull();
    } finally {
        harnessRemoveDirectory($workspace);
    }
});

test('群4-43: 子が書いた文字列に秘密が現れても例外へは伏せ字でしか載らない (既知の 5 種すべて)', function (): void {
    // ★一時ファイルを消しても CI のログは残る。秘密の後始末はファイル経路だけでは閉じない。
    // ★**子は untrusted** なので、stderr だけでなく**子が書いた合図の中身**も同じ扱いにする。
    $sentinelKey = 'sentinel-plain-api-key-'.bin2hex(random_bytes(8));
    $sentinelCipherSweet = 'sentinel-ciphersweet-'.bin2hex(random_bytes(8));
    $sentinelDbPassword = 'sentinel-db-password-'.bin2hex(random_bytes(8));
    $requestBody = ['title' => 'sentinel-body-'.bin2hex(random_bytes(8))];
    $rawBody = json_encode($requestBody, JSON_THROW_ON_ERROR);

    // 実鍵とパスワードは環境依存 (空のこともある) なので、検査のために sentinel へ差し替える。
    // 偽の子しか使わないので実プロセスへは 1 バイトも渡らない。
    config([
        'ciphersweet.providers.string.key' => $sentinelCipherSweet,
        'database.connections.pgsql.password' => $sentinelDbPassword,
    ]);

    $appKey = config('app.key');
    expect($appKey)->toBeString();

    $allSecrets = [$sentinelKey, $rawBody, $appKey, $sentinelCipherSweet, $sentinelDbPassword];

    // (a) stderr 経路 — 子が握っている値が丸ごと stderr へ出た最悪ケース
    $stderrFactory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
            $spec,
            static function (ScriptedProbeProcess $process): void {},
            $log,
            exitImmediately: true,
            stderr: 'fatal: '.implode(' / ', $allSecrets),
        ),
    );

    $thrown = null;
    try {
        harnessRun($stderrFactory, timeoutSeconds: 2.0, requestBody: $requestBody, plainApiKey: $sentinelKey);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(ConcurrencyProtocolException::class);
    $text = harnessThrowableText($thrown);
    expect($text)->toContain('観測を出さずに終了した');
    expect($text)->toContain('[redacted:');
    foreach ($allSecrets as $secret) {
        expect($text)->not->toContain($secret);
    }

    // (b) 合図の中身の経路 — 子が ready へ秘密を書いた場合も同じ保証が要る
    //     (stderr だけを伏せる実装だと、この経路が素通しで CI ログへ残る)
    $payloadFactory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, static function (ScriptedProbeProcess $process) use ($allSecrets): void {
            if ($process->step !== 0) {
                return;
            }
            $process->barrier()->signal(
                SignalName::make('ready', $process->spec->childId),
                implode(' / ', $allSecrets),
            );
            $process->step = 1;
        }, $log),
    );

    $thrown = null;
    try {
        harnessRun($payloadFactory, timeoutSeconds: 2.0, requestBody: $requestBody, plainApiKey: $sentinelKey);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(ConcurrencyProtocolException::class);
    $text = harnessThrowableText($thrown);
    expect($text)->toContain('ready の nonce');
    expect($text)->toContain('[redacted:');
    foreach ($allSecrets as $secret) {
        expect($text)->not->toContain($secret);
    }
});

test('群4-45: 親が決めた面と違う uri を申告した観測は受理しない', function (): void {
    // ★request_hash は親の期待値と一致させたまま uri だけをすり替える。
    //   uri の照合を外すと緑になる形を作って、照合が効いていることを固定する。
    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, harnessProtocolScript('a', [
            'uri' => 'api/v1/__tampered_probe__',
        ]), $log),
    );

    expect(fn () => harnessRun($factory, timeoutSeconds: 3.0))
        ->toThrow(ConcurrencyProtocolException::class, '親の決めた面と違う');
});

test('群4-46: workspace の後始末は symlink を辿らない (外側を消さない)', function (): void {
    $outside = harnessWorkspace();
    file_put_contents($outside.'/sentinel.txt', '消えてはいけない');

    try {
        $factory = new ScriptedProbeProcessFactory(
            static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess($spec, static function (ScriptedProbeProcess $process) use ($outside): void {
                if ($process->step !== 0) {
                    return;
                }
                $process->step = 1;

                // ★張るのは 1 本だけ (2 子が同じパスへ張ると 2 本目が失敗して
                //   台本の側が例外を投げ、測りたいものと別の分岐になる)。
                if ($process->spec->childId !== 'a') {
                    return;
                }

                // workspace の中から外側のディレクトリへ link を張る。
                // `is_dir()` は symlink でも true になるので、辿ると外側ごと消える。
                symlink($outside, $process->spec->workspaceDirectory.'/outside-link');
            }, $log),
        );

        $workspace = null;
        try {
            harnessRun($factory, timeoutSeconds: 0.3);
        } catch (Throwable $e) {
            $workspace = $factory->workspaceDirectory();
        }

        // 回収は完遂している (子は TERM で止まる) ので workspace ごと消えている
        expect($workspace)->toBeString();
        clearstatcache(true, (string) $workspace);
        expect(is_dir((string) $workspace))->toBeFalse();

        // ★外側は無傷である (symlink を辿っていたらここが消えている)
        expect(is_dir($outside))->toBeTrue();
        expect(file_get_contents($outside.'/sentinel.txt'))->toBe('消えてはいけない');
    } finally {
        harnessRemoveDirectory($outside);
    }
});

test('群4-42: 回収の poll は単一ループで全子を確認する (逐次の blocking wait ではない)', function (): void {
    $factory = new ScriptedProbeProcessFactory(
        static fn (ProbeLaunchSpec $spec, HarnessCallLog $log): ScriptedProbeProcess => new ScriptedProbeProcess(
            $spec,
            static function (ScriptedProbeProcess $process): void {},
            $log,
            ignoreTerminate: true,
        ),
    );

    expect(fn () => harnessRun($factory, timeoutSeconds: 0.05))
        ->toThrow(BarrierTimeoutException::class);

    // TERM 送出で poll 記録が初期化されるので、ここに残るのは回収フェーズの poll だけ。
    // 逐次の blocking wait なら「a を延々 poll → b を延々 poll」で行き来は 1 回しか起きない。
    expect($factory->log->pollAlternations())->toBeGreaterThan(10);
});
