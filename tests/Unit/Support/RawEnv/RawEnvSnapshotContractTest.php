<?php

declare(strict_types=1);

use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Support\Env;
use Tests\Support\RawEnv\RawEnvChannels;
use Tests\Support\RawEnv\RawEnvGuardStructure;
use Tests\Support\RawEnv\RawEnvSnapshot;

/*
 * `Tests\Support\RawEnv\RawEnvSnapshot` の契約テスト
 * (家系の正典 raw-env-snapshot-restore v1 の i11)。
 *
 * ★**このテストは検査対象である部品を使わずに 3 面を触る**。部品で作った状態を部品で
 *   確かめると同語反復になるためである。したがって前後の掃除も自前で持つ
 *   (`beforeEach` でプローブキーの 3 面の**元の存在状態と値**を素の `array_key_exists()` /
 *   `getenv()` で退避し、`afterEach` でその状態へ戻す)。
 *   これが `RawEnvDirectWriteGateTest` の許可 3 か所のうち「部品の契約テスト」に当たる。
 *
 * ★プローブキーは `.env.testing` / `phpunit.xml` / 実 shell が定義しない専用の接頭辞
 *   (`RAW_ENV_PROBE_`) を使い、**値に phpdotenv の予約語** (`true` / `false` / `null` /
 *   `(true)` 等) **を使わない** (`env()` がこれらを bool / null / '' へ変換するため
 *   「文字列がそのまま返る」前提が崩れる)。
 *   例外は i10 の機序を通すための `APP_LOCALE` で、これは `.env.testing` が実際に宣言している
 *   ことが load-bearing である (g-0 が前提として pin する)。config は既にロード済みなので
 *   env を触ってもアプリの振る舞いには影響しない。`DB_*` は部品の拒否対象なので使えない。
 *
 * ★`LoadEnvironmentVariables` の再実行で `DB_DATABASE` が `.env.testing` の
 *   フォールバック値へ変わることは無い — `tests/bootstrap.php` が Laravel boot 前に
 *   3 面へ注入しており、phpdotenv の immutable writer から見て「外部で定義済み」だからである
 *   (writer の `$loaded` に載っていない)。
 *
 * ── この契約テストが保証するもの / しないもの ────────────────────────────────
 *
 * | 契約 | 担保の手段 |
 * |---|---|
 * | 第 1 段で拒否されたときは 1 面も書き換わらない | 動的テスト (d-4) |
 * | 本体が throw しても 3 面が復元される | 動的テスト (c-1) |
 * | 適用ループの途中で throw してもそこまでの変更が巻き戻る | **構造テストのみ (h-1 / h-2)。動的には未検証** |
 * | 復元が最初の失敗で止まらず、全キーを戻してからまとめて例外になる | **構造テストのみ (h-3)。動的には未検証** |
 * | 読み出しの優先順が `$_SERVER` → `$_ENV` → `putenv` である | 動的テスト (f-1〜f-3) |
 * | `forgetLaravelEnvRepository()` が env の読み直しでの上書きを防ぐ | 動的テスト (g-1〜g-3) |
 *
 * 動的に検査していない 2 行は、`putenv()` を検証通過後に失敗させる状況をテストから作れず、
 * 失敗を注入する差し替え口を新設しない (本番では誰も使わない差し替え口が増えるため) という
 * 判断による (正典の未決論点 q2)。**動的に保証されたとは書かない**。
 */

/**
 * 3 面の状態を退避・復元するプローブキー (宣言が正本)。
 *
 * @return list<non-empty-string>
 */
function rawEnvContractProbeKeys(): array
{
    return ['RAW_ENV_PROBE_ONE', 'RAW_ENV_PROBE_TWO', 'RAW_ENV_PROBE_THREE', 'APP_LOCALE'];
}

/**
 * 1 キーの 3 面の状態を素の言語機能で読み出す (部品を使わない)。
 *
 * @return array{serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}
 */
function rawEnvContractRead(string $key): array
{
    return [
        'serverExists' => array_key_exists($key, $_SERVER),
        'server' => $_SERVER[$key] ?? null,
        'envExists' => array_key_exists($key, $_ENV),
        'env' => $_ENV[$key] ?? null,
        'process' => getenv($key),
    ];
}

/**
 * プローブキー全数の 3 面を退避する。
 *
 * @return array<string, array{serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}>
 */
function rawEnvContractCaptureProbes(): array
{
    $state = [];
    foreach (rawEnvContractProbeKeys() as $key) {
        $state[$key] = rawEnvContractRead($key);
    }

    return $state;
}

/**
 * 退避した 3 面へ戻す (部品を使わない)。
 *
 * @param  array<string, array{serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}>  $state
 */
function rawEnvContractRestoreProbes(array $state): void
{
    foreach ($state as $key => $saved) {
        if ($saved['serverExists']) {
            $_SERVER[$key] = $saved['server'];
        } else {
            unset($_SERVER[$key]);
        }

        if ($saved['envExists']) {
            $_ENV[$key] = $saved['env'];
        } else {
            unset($_ENV[$key]);
        }

        if (is_string($saved['process'])) {
            putenv($key.'='.$saved['process']);
        } else {
            putenv($key);
        }
    }
}

/**
 * ケース間で退避を持ち回る入れ物 (Pest の TestCase へ動的プロパティを生やさない)。
 *
 * @param  array<string, array{serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}>|null  $store
 * @return array<string, array{serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}>
 */
function rawEnvContractProbeSlot(?array $store = null): array
{
    /** @var array<string, array{serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}> $slot */
    static $slot = [];

    if ($store !== null) {
        $slot = $store;
    }

    return $slot;
}

/**
 * 3 面を直接埋める (部品を使わずに検査の前提状態を作る)。
 */
function rawEnvContractSeed(string $key, mixed $server, mixed $env, string $process): void
{
    $_SERVER[$key] = $server;
    $_ENV[$key] = $env;
    putenv($key.'='.$process);
}

/** 3 面を直接すべて未設定にする (部品を使わない)。 */
function rawEnvContractClear(string $key): void
{
    unset($_SERVER[$key], $_ENV[$key]);
    putenv($key);
}

/**
 * 変数キーで change set を作る (数値だけのキーが整数へ畳まれる様子をそのまま入力にする)。
 *
 * @return array<string, RawEnvChannels>
 */
function rawEnvContractChangeWithKey(string $key): array
{
    return [$key => RawEnvChannels::none()];
}

/** env の読み直しだけを起こす (`refreshApplication()` は RefreshDatabase の tx を壊すので使わない)。 */
function rawEnvContractReloadEnv(): void
{
    (new LoadEnvironmentVariables)->bootstrap(app());
}

/**
 * i10 の機序を決定的に作る priming。
 *
 * immutable writer の `$loaded` に `APP_LOCALE` が載っている状態を**各ケースの中で**作る
 * (載っているかどうかは直前のテストが repository を捨てたかに依存するため、
 *  priming をしないと実行順で結果が変わる)。
 */
function rawEnvContractPrimeLoadedLocale(): void
{
    // 退避は使い捨てにしてよい (外側の afterEach が元の 3 面へ戻す)。
    RawEnvSnapshot::captureAndClear(['APP_LOCALE']);
    RawEnvSnapshot::forgetLaravelEnvRepository();
    rawEnvContractReloadEnv();

    expect(env('APP_LOCALE'))->toBe('en');
}

beforeEach(function (): void {
    rawEnvContractProbeSlot(rawEnvContractCaptureProbes());
    foreach (['RAW_ENV_PROBE_ONE', 'RAW_ENV_PROBE_TWO', 'RAW_ENV_PROBE_THREE'] as $key) {
        rawEnvContractClear($key);
    }
});

afterEach(function (): void {
    rawEnvContractRestoreProbes(rawEnvContractProbeSlot());
    // repository の `$loaded` の状態を次のケースへ持ち越さない。
    RawEnvSnapshot::forgetLaravelEnvRepository();
});

// ── (a) 3 面の存在状態と値が食い違う状態の往復 ──

test('a-1: 3 面の存在状態が食い違う状態を面ごとに独立して戻す', function (): void {
    $key = 'RAW_ENV_PROBE_ONE';
    $_SERVER[$key] = 'server-only';
    putenv($key.'=');   // 空文字で設定 ($_ENV は未設定のまま)

    RawEnvSnapshot::with([$key => RawEnvChannels::sameOnAllSurfaces('replaced')], function () use ($key): void {
        expect($_SERVER[$key])->toBe('replaced')
            ->and($_ENV[$key])->toBe('replaced')
            ->and(getenv($key))->toBe('replaced');
    });

    expect(rawEnvContractRead($key))->toBe([
        'serverExists' => true,
        'server' => 'server-only',
        'envExists' => false,
        'env' => null,
        'process' => '',
    ]);
});

test('a-2: 「存在するが値が null」を「存在しない」へ潰さない', function (): void {
    $key = 'RAW_ENV_PROBE_TWO';
    $_SERVER[$key] = null;

    RawEnvSnapshot::with([$key => RawEnvChannels::sameOnAllSurfaces('x')], function (): void {
        // 本体は何もしない (往復そのものが検査対象)。
    });

    expect(array_key_exists($key, $_SERVER))->toBeTrue()
        ->and($_SERVER[$key])->toBeNull();
});

test('a-3: 非文字列 (配列) を入れた面が同じ値のまま戻る', function (): void {
    $key = 'RAW_ENV_PROBE_THREE';
    $_ENV[$key] = ['nested' => ['deep']];

    RawEnvSnapshot::with([$key => RawEnvChannels::none()->withServer('only-server')], function () use ($key): void {
        expect(array_key_exists($key, $_ENV))->toBeFalse();
    });

    expect($_ENV[$key])->toBe(['nested' => ['deep']]);
});

// ── (b) 空文字・等号を含む値・未設定の往復 ──

test('b-1: 等号を含む値と空文字の往復 (putenv の値が壊れない)', function (): void {
    $key = 'RAW_ENV_PROBE_ONE';
    putenv($key.'=a=b');

    RawEnvSnapshot::with([$key => RawEnvChannels::sameOnAllSurfaces('')], function () use ($key): void {
        expect(getenv($key))->toBe('');
    });

    expect(getenv($key))->toBe('a=b');
});

test('b-2: 元から未設定のキーは実行後も 3 面とも未設定へ戻る', function (): void {
    $key = 'RAW_ENV_PROBE_TWO';

    RawEnvSnapshot::with([$key => RawEnvChannels::sameOnAllSurfaces('temp')], function () use ($key): void {
        expect(getenv($key))->toBe('temp');
    });

    expect(rawEnvContractRead($key))->toBe([
        'serverExists' => false,
        'server' => null,
        'envExists' => false,
        'env' => null,
        'process' => false,
    ]);
});

// ── (c) 本体の例外 ──

test('c-1: 本体が例外を投げても 3 面が復元される', function (): void {
    $key = 'RAW_ENV_PROBE_ONE';
    rawEnvContractSeed($key, 'orig-server', 'orig-env', 'orig-process');

    $thrown = null;

    try {
        RawEnvSnapshot::with([$key => RawEnvChannels::sameOnAllSurfaces('temp')], function (): void {
            throw new DomainException('body failed');
        });
    } catch (DomainException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(DomainException::class)
        ->and(rawEnvContractRead($key))->toBe([
            'serverExists' => true,
            'server' => 'orig-server',
            'envExists' => true,
            'env' => 'orig-env',
            'process' => 'orig-process',
        ]);
});

// ── (d) 検証で拒否する ──

test('d-1: 不正なキーは第 1 段で拒否される', function (string $key): void {
    expect(fn (): mixed => RawEnvSnapshot::with(
        rawEnvContractChangeWithKey($key),
        fn (): int => 1,
    ))->toThrow(InvalidArgumentException::class);
})->with([
    'empty' => [''],
    'contains equals' => ['RAW_ENV_PROBE_ONE=x'],
    'contains NUL' => ["RAW_ENV_PROBE_ONE\0X"],
    'numeric string folded into an integer key' => ['0'],
]);

test('d-2: 単一点の守りが前提にするキーは拒否される', function (string $key): void {
    expect(fn (): mixed => RawEnvSnapshot::with(
        rawEnvContractChangeWithKey($key),
        fn (): int => 1,
    ))->toThrow(InvalidArgumentException::class);

    expect(fn (): RawEnvSnapshot => RawEnvSnapshot::captureAndClear([$key]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'DB_DATABASE' => ['DB_DATABASE'],
    'DB_CONNECTION' => ['DB_CONNECTION'],
    'DB_URL' => ['DB_URL'],
    'TEST_TOKEN' => ['TEST_TOKEN'],
    'APP_CONFIG_CACHE' => ['APP_CONFIG_CACHE'],
]);

test('d-3: process 面の値に NUL があれば第 1 段で拒否される', function (): void {
    expect(fn (): mixed => RawEnvSnapshot::with(
        ['RAW_ENV_PROBE_ONE' => RawEnvChannels::none()->withProcess("a\0b")],
        fn (): int => 1,
    ))->toThrow(InvalidArgumentException::class);
});

test('d-4: 拒否キーを 2 番目に置いても先行キーの 3 面が 1 面も変わらない (閉包の口)', function (): void {
    $key = 'RAW_ENV_PROBE_ONE';
    rawEnvContractSeed($key, 'orig-server', 'orig-env', 'orig-process');

    $changes = [
        $key => RawEnvChannels::sameOnAllSurfaces('should-not-be-applied'),
        'DB_DATABASE' => RawEnvChannels::sameOnAllSurfaces('app'),
    ];

    expect(fn (): mixed => RawEnvSnapshot::with($changes, fn (): int => 1))
        ->toThrow(InvalidArgumentException::class);

    expect(rawEnvContractRead($key))->toBe([
        'serverExists' => true,
        'server' => 'orig-server',
        'envExists' => true,
        'env' => 'orig-env',
        'process' => 'orig-process',
    ]);
});

test('d-4b: 拒否キーを 2 番目に置いても先行キーの 3 面が 1 面も変わらない (持ち回りの口)', function (): void {
    $key = 'RAW_ENV_PROBE_ONE';
    rawEnvContractSeed($key, 'orig-server', 'orig-env', 'orig-process');

    expect(fn (): RawEnvSnapshot => RawEnvSnapshot::captureAndClear([$key, 'DB_DATABASE']))
        ->toThrow(InvalidArgumentException::class);

    expect(rawEnvContractRead($key))->toBe([
        'serverExists' => true,
        'server' => 'orig-server',
        'envExists' => true,
        'env' => 'orig-env',
        'process' => 'orig-process',
    ]);
});

test('d-5: 拒否されたとき本体は 1 度も呼ばれない', function (): void {
    $calls = 0;

    expect(fn (): mixed => RawEnvSnapshot::with(
        ['DB_DATABASE' => RawEnvChannels::sameOnAllSurfaces('app')],
        function () use (&$calls): int {
            $calls++;

            return 1;
        },
    ))->toThrow(InvalidArgumentException::class);

    expect($calls)->toBe(0);
});

// ── (e) 入れ子 ──

test('e-1: 同一キーの入れ子で内側の復元が外側の適用値へ戻る', function (): void {
    $key = 'RAW_ENV_PROBE_ONE';
    rawEnvContractSeed($key, 'orig', 'orig', 'orig');

    RawEnvSnapshot::with([$key => RawEnvChannels::sameOnAllSurfaces('outer')], function () use ($key): void {
        RawEnvSnapshot::with([$key => RawEnvChannels::sameOnAllSurfaces('inner')], function () use ($key): void {
            expect(getenv($key))->toBe('inner');
        });

        expect($_SERVER[$key])->toBe('outer')
            ->and($_ENV[$key])->toBe('outer')
            ->and(getenv($key))->toBe('outer');
    });

    expect(getenv($key))->toBe('orig');
});

// ── (f) 読み出しの優先順 ──

test('f-1: 3 面とも設定なら env() は $_SERVER を読む', function (): void {
    RawEnvSnapshot::with([
        'RAW_ENV_PROBE_ONE' => RawEnvChannels::none()
            ->withServer('from-server')
            ->withEnv('from-env')
            ->withProcess('from-process'),
    ], function (): void {
        expect(env('RAW_ENV_PROBE_ONE'))->toBe('from-server');
    });
});

test('f-2: $_SERVER だけ未設定なら env() は $_ENV を読む', function (): void {
    RawEnvSnapshot::with([
        'RAW_ENV_PROBE_ONE' => RawEnvChannels::none()
            ->withEnv('from-env')
            ->withProcess('from-process'),
    ], function (): void {
        expect(env('RAW_ENV_PROBE_ONE'))->toBe('from-env');
    });
});

test('f-3: $_SERVER と $_ENV が未設定なら env() は putenv 面を読む', function (): void {
    RawEnvSnapshot::with([
        'RAW_ENV_PROBE_ONE' => RawEnvChannels::none()->withProcess('from-process'),
    ], function (): void {
        expect(env('RAW_ENV_PROBE_ONE'))->toBe('from-process');
    });
});

test('f-4: 指定しなかった面は明示的に未設定になる', function (): void {
    $key = 'RAW_ENV_PROBE_ONE';
    rawEnvContractSeed($key, 'orig', 'orig', 'orig');

    RawEnvSnapshot::with([$key => RawEnvChannels::none()->withServer('only-server')], function () use ($key): void {
        expect($_SERVER[$key])->toBe('only-server')
            ->and(array_key_exists($key, $_ENV))->toBeFalse()
            ->and(getenv($key))->toBeFalse();
    });
});

// ── (g) env 読み出し口の作り直し (i10 / 正典 q3) ──

test('g-0: 前提の pin — .env.testing が APP_LOCALE を宣言している', function (): void {
    $declaration = file_get_contents(base_path('.env.testing'));

    expect($declaration)->toBeString();
    expect(preg_match('/^APP_LOCALE=en$/m', (string) $declaration))->toBe(1);
});

test('g-1: 口の前後で Env の repository のインスタンス同一性が変わる', function (): void {
    $before = Env::getRepository();
    RawEnvSnapshot::forgetLaravelEnvRepository();
    $after = Env::getRepository();

    expect($after)->not->toBe($before);
});

test('g-2: 口を呼ばずに読み直すと .env.testing の値で上書きされる (機序の観測)', function (): void {
    rawEnvContractPrimeLoadedLocale();

    RawEnvSnapshot::with(['APP_LOCALE' => RawEnvChannels::sameOnAllSurfaces('zz')], function (): void {
        rawEnvContractReloadEnv();

        expect(env('APP_LOCALE'))->toBe('en');
    });
});

test('g-3: 口を呼んでから読み直すと 3 面へ入れた値が維持される', function (): void {
    rawEnvContractPrimeLoadedLocale();

    RawEnvSnapshot::with(['APP_LOCALE' => RawEnvChannels::sameOnAllSurfaces('zz')], function (): void {
        RawEnvSnapshot::forgetLaravelEnvRepository();
        rawEnvContractReloadEnv();

        expect(env('APP_LOCALE'))->toBe('zz');
    });
});

// ── (i) 持ち回りの口 ──

test('i-1: captureAndClear() が 3 面を未設定にし restore() で元へ戻る', function (): void {
    $key = 'RAW_ENV_PROBE_ONE';
    rawEnvContractSeed($key, 'orig-server', 'orig-env', 'orig-process');

    $snapshot = RawEnvSnapshot::captureAndClear([$key]);

    expect(rawEnvContractRead($key))->toBe([
        'serverExists' => false,
        'server' => null,
        'envExists' => false,
        'env' => null,
        'process' => false,
    ]);

    $snapshot->restore();

    expect(rawEnvContractRead($key))->toBe([
        'serverExists' => true,
        'server' => 'orig-server',
        'envExists' => true,
        'env' => 'orig-env',
        'process' => 'orig-process',
    ]);
});

test('i-2: $changes に現れないキーには一切触れない', function (): void {
    rawEnvContractSeed('RAW_ENV_PROBE_TWO', 'untouched', 'untouched', 'untouched');

    RawEnvSnapshot::with(
        ['RAW_ENV_PROBE_ONE' => RawEnvChannels::sameOnAllSurfaces('x')],
        function (): void {
            expect(rawEnvContractRead('RAW_ENV_PROBE_TWO'))->toBe([
                'serverExists' => true,
                'server' => 'untouched',
                'envExists' => true,
                'env' => 'untouched',
                'process' => 'untouched',
            ]);
        },
    );

    expect(rawEnvContractRead('RAW_ENV_PROBE_TWO'))->toBe([
        'serverExists' => true,
        'server' => 'untouched',
        'envExists' => true,
        'env' => 'untouched',
        'process' => 'untouched',
    ]);
});

test('with() は本体の戻り値をそのまま返す', function (): void {
    $result = RawEnvSnapshot::with(
        ['RAW_ENV_PROBE_ONE' => RawEnvChannels::sameOnAllSurfaces('x')],
        fn (): array => ['value' => getenv('RAW_ENV_PROBE_ONE')],
    );

    expect($result)->toBe(['value' => 'x']);
});

// ── (h) 構造の固定 (正典の未決論点 q2 の代替。動的には検査できない性質を構造で pin する) ──

test('h-1: 閉包の口は「適用が try の中・復元が finally・本体の例外を連結して再送出」の構造である', function (): void {
    $tokens = RawEnvGuardStructure::methodTokens(RawEnvSnapshot::class, 'with');
    $try = RawEnvGuardStructure::soleBlockRange($tokens, T_TRY);
    $catch = RawEnvGuardStructure::soleBlockRange($tokens, T_CATCH);
    $finally = RawEnvGuardStructure::soleBlockRange($tokens, T_FINALLY);

    $loops = RawEnvGuardStructure::foreachOverExpression($tokens, ['$changes']);
    expect($loops)->toHaveCount(1)
        ->and(RawEnvGuardStructure::isWithin($catch, $loops[0]))->toBeFalse()
        ->and(RawEnvGuardStructure::isWithin($finally, $loops[0]))->toBeFalse();

    expect(RawEnvGuardStructure::applyLoopIsGuarded($tokens, ['$changes'], $try, 'apply'))->toBeTrue()
        ->and(RawEnvGuardStructure::methodCallArgumentMatches($tokens, $finally, '$snapshot', 'restore', 0, ['$bodyError']))->toBeTrue()
        ->and(RawEnvGuardStructure::variableAssignmentMatches($tokens, $catch, '$bodyError', ['$e']))->toBeTrue()
        ->and(RawEnvGuardStructure::soleThrowMatches($tokens, $catch, ['$e']))->toBeTrue();
});

test('h-2: 持ち回りの口は「未設定化が try の中・復元と再送出が catch」の構造である', function (): void {
    $tokens = RawEnvGuardStructure::methodTokens(RawEnvSnapshot::class, 'captureAndClear');
    $try = RawEnvGuardStructure::soleBlockRange($tokens, T_TRY);
    $catch = RawEnvGuardStructure::soleBlockRange($tokens, T_CATCH);

    expect(RawEnvGuardStructure::findTokens($tokens, T_FINALLY))->toBe([])
        ->and(RawEnvGuardStructure::applyLoopIsGuarded($tokens, ['$keys'], $try, 'apply'))->toBeTrue()
        ->and(RawEnvGuardStructure::methodCallArgumentMatches($tokens, $catch, '$snapshot', 'restore', 0, ['$e']))->toBeTrue()
        ->and(RawEnvGuardStructure::indexesWithin(RawEnvGuardStructure::controlFlowTokens($tokens, T_THROW), $catch))->toHaveCount(1);
});

test('h-3: 復元は「途中終了せず蓄積し、ループの後で 1 度だけ送出する」構造で例外を連結する', function (): void {
    $tokens = RawEnvGuardStructure::methodTokens(RawEnvSnapshot::class, 'restore');

    expect(RawEnvGuardStructure::restoreStructureIsDeferred($tokens, ['$this', '->', 'state'], '$failed', '$applied'))->toBeTrue()
        ->and(RawEnvGuardStructure::constructionArgumentMatches($tokens, RawEnvSnapshot::class, RuntimeException::class, 2, ['$previous']))->toBeTrue();
});
