<?php

declare(strict_types=1);

use App\Support\ExternalFakes\ExternalFakeBinding;
use App\Support\ExternalFakes\ExternalFakeDeclaration;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Tests\Support\ExternalFakes\FakeWiringProbeRunner;

/*
 * 別プロセスで「宣言した差し替えが実際に効いているか」を実測する
 * (c2c: external-fakes-wiring-gate 柱 2)。
 *
 * in-process の実証 (ExternalFakeWiringInvariantTest) は provider を手で再実走させるため、
 * 「実際の起動 (遅延読み込み provider・設定の解決順) でも効いているか」までは示せない。
 * ここでは子プロセスを起こし、起動しきったアプリの container から解決して観測する。
 *
 * ★子プロセスへ実際の外部資格情報を渡さない。プロセスの環境変数は `env -i` で空にし、
 *   設定は専用の一時環境ファイル 1 つだけから読む。書いてよいキーに外部サービスの
 *   資格情報は 1 つも無く、鍵の 2 つは使い捨ての生成値である (P-6 / P-7 / P-8)。
 *
 * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
 * キャッシュが古いときの本番事故は ProductionEnvGuard の二重判定が受け持つ。
 */

/**
 * 一時ディレクトリの親の登録簿 (走行後の後片付けに使う)。
 *
 * @return list<string>
 */
function externalFakeProbeBaseDirectories(?string $add = null): array
{
    /** @var list<string> $bases */
    static $bases = [];

    if ($add !== null) {
        $bases[] = $add;
    }

    return $bases;
}

afterAll(function (): void {
    foreach (externalFakeProbeBaseDirectories() as $base) {
        if (is_dir($base)) {
            @rmdir($base);
        }
    }
});

/**
 * 観測を 1 回だけ走らせて使い回す (子プロセスの起動は高価なため)。
 *
 * 一時ディレクトリの親をケースごとに用意し、走行後に空であることを P-10 が確かめる。
 *
 * @return array{
 *     exitCode: int,
 *     output: array<string, mixed>,
 *     envFileValues: array<string, string>,
 *     directory: string,
 *     directoryMode: int,
 *     envFileMode: int,
 *     configCachePath: string,
 *     configCacheExists: bool,
 *     baseDirectory: string,
 * }
 */
function externalFakeProbeRun(string $case): array
{
    /** @var array<string, array<string, mixed>> $cache */
    static $cache = [];

    if (! array_key_exists($case, $cache)) {
        $base = sys_get_temp_dir().'/fake-wiring-probe-base-'.bin2hex(random_bytes(6));
        if (! mkdir($base, 0700) || ! is_dir($base)) {
            throw new RuntimeException("観測用の親ディレクトリを作れない: {$base}");
        }
        externalFakeProbeBaseDirectories($base);

        $result = match ($case) {
            // 偽物側: storage も含めて宣言の全件を偽物にする
            'fake' => FakeWiringProbeRunner::run('bughunt.local', true, true, false, $base),
            // 対照: フラグを全部落とすと本物が解決される
            'real' => FakeWiringProbeRunner::run('bughunt.local', false, false, false, $base),
            // 対照: production はフラグが立っていると起動そのものが失敗する
            'production' => FakeWiringProbeRunner::run('production', true, false, false, $base),
            default => throw new InvalidArgumentException("未知の観測ケース: {$case}"),
        };

        $cache[$case] = [...$result, 'baseDirectory' => $base];
    }

    /** @var array{exitCode: int, output: array<string, mixed>, envFileValues: array<string, string>, directory: string, directoryMode: int, envFileMode: int, configCachePath: string, configCacheExists: bool, baseDirectory: string} $entry */
    $entry = $cache[$case];

    return $entry;
}

/**
 * 観測結果の `resolved` を「解決キー => 実際に解決されたクラス」として取り出す。
 *
 * @param  array<string, mixed>  $output
 * @return array<string, string>
 */
function externalFakeProbeResolved(array $output): array
{
    $resolved = $output['resolved'] ?? null;
    expect($resolved)->toBeArray('観測結果に resolved が無い: '.json_encode($output));

    /** @var array<string, mixed> $resolved */
    $result = [];
    foreach ($resolved as $abstract => $class) {
        expect($class)->toBeString();
        /** @var string $class */
        $result[(string) $abstract] = $class;
    }

    return $result;
}

test('P-1 実測: bughunt.local + フラグ有効なら宣言の全件が偽物のクラスで厳密一致する', function (): void {
    $run = externalFakeProbeRun('fake');

    expect($run['exitCode'])->toBe(0, '観測が失敗した: '.json_encode($run['output']));

    $expected = [];
    foreach (ExternalFakeDeclaration::swaps() as $swap) {
        $expected[$swap->abstract] = $swap->fake;
    }

    expect(externalFakeProbeResolved($run['output']))->toBe($expected);
});

test('P-2 実測: 外部ログインの転送先ホストが自ホストである (実 IdP でない)', function (): void {
    $run = externalFakeProbeRun('fake');

    expect($run['output']['redirect_host'] ?? null)->toBe(FakeWiringProbeRunner::probeAppHost());
});

test('P-3 対照: フラグ無効なら宣言の全件が本物のクラスで厳密一致する', function (): void {
    $run = externalFakeProbeRun('real');

    expect($run['exitCode'])->toBe(0, '観測が失敗した: '.json_encode($run['output']));

    $expected = [];
    foreach (ExternalFakeDeclaration::swaps() as $swap) {
        $expected[$swap->abstract] = $swap->real;
    }

    // 転送先は偽物が有効なときだけ観測する (本物向けの URL を組み立てない)。
    // `??` は null を「不在」と同一視するため array_key_exists で存在を先に確かめる。
    expect(externalFakeProbeResolved($run['output']))->toBe($expected)
        ->and(array_key_exists('redirect_host', $run['output']))->toBeTrue()
        ->and($run['output']['redirect_host'])->toBeNull();
});

test('P-4 対照: production + フラグ有効は起動が失敗し、出力にフラグ名が現れる', function (): void {
    $run = externalFakeProbeRun('production');

    // (a) 順序に依存しない表明
    expect($run['exitCode'])->not->toBe(0);

    // (b) 順序に依存する表明。AppServiceProvider::boot() は ProductionEnvGuard::enforce() を
    //     最初に呼ぶため、他の起動時検査より先にこの違反が出る。
    //     落ちたら「起動時検査の順序が変わった可能性」を疑うこと。
    $error = $run['output']['error'] ?? '';
    expect($error)->toBeString();
    /** @var string $error */
    expect(str_contains($error, 'TESTING_FAKE_EXTERNALS'))
        ->toBeTrue('起動時検査の順序が変わった可能性がある (出力: '.$error.')');
});

test('P-5 fail-closed: 宣言集合も観測結果も空でない', function (): void {
    expect(ExternalFakeDeclaration::swaps())->not->toBeEmpty()
        ->and(externalFakeProbeResolved(externalFakeProbeRun('fake')['output']))->not->toBeEmpty();
});

test('P-6 一時環境ファイルのキー集合が許可集合の部分集合である', function (): void {
    $keys = array_keys(externalFakeProbeRun('fake')['envFileValues']);

    expect($keys)->not->toBeEmpty()
        ->and(array_values(array_diff($keys, FakeWiringProbeRunner::ALLOWED_ENV_FILE_KEYS)))->toBe([]);
});

test('P-7 子が実際に受け取ったプロセス環境が許可した 3 件ちょうどである', function (): void {
    $keys = externalFakeProbeRun('fake')['output']['process_environment_keys'] ?? null;
    expect($keys)->toBeArray();
    /** @var list<mixed> $keys */
    $actual = array_map(static fn (mixed $key): string => (string) $key, $keys);

    // (b) 危険な接頭辞が 1 件も無いこと
    foreach (['DB_', 'PG', 'AWS_', 'STRIPE_', 'TESTING_FAKE_', 'GOOGLE_'] as $prefix) {
        $leaked = array_values(array_filter(
            $actual,
            static fn (string $key): bool => str_starts_with($key, $prefix)
        ));
        expect($leaked)->toBe([], "禁止する接頭辞 {$prefix} のキーが子へ流れている");
    }

    // (a)(c) 許可した 3 件がすべて存在し、それ以外の余りが無いこと (deny-by-default)
    $expected = FakeWiringProbeRunner::ALLOWED_PROCESS_ENV_KEYS;
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

test('P-8 一時環境ファイルの鍵は親の設定値の複写ではない', function (): void {
    $values = externalFakeProbeRun('fake')['envFileValues'];

    expect($values['APP_KEY'] ?? null)->not->toBe(config('app.key'))
        ->and($values['CIPHERSWEET_KEY'] ?? null)->not->toBe(config('ciphersweet.providers.string.key'));
});

test('P-9 一時ディレクトリ 0700 / 環境ファイル 0600 であり、違えば子を起こさない', function (): void {
    $run = externalFakeProbeRun('fake');

    expect($run['directoryMode'])->toBe(0700)
        ->and($run['envFileMode'])->toBe(0600);

    // 権限が緩い状態では子を起こさずに失敗すること (負のコントロール)。
    expect(fn () => FakeWiringProbeRunner::assertSafePermissions(0755, 0600))
        ->toThrow(RuntimeException::class);
    expect(fn () => FakeWiringProbeRunner::assertSafePermissions(0700, 0644))
        ->toThrow(RuntimeException::class);
});

test('P-10 正常終了・非ゼロ終了・timeout のいずれでも一時ディレクトリが残らない', function (): void {
    foreach (['fake', 'real', 'production'] as $case) {
        $run = externalFakeProbeRun($case);

        expect(is_dir($run['directory']))->toBeFalse("一時ディレクトリが残っている: {$case}")
            ->and(array_values(array_diff(scandir($run['baseDirectory']) ?: [], ['.', '..'])))
            ->toBe([], "一時ディレクトリの親に残骸がある: {$case}");
    }

    // timeout でも finally を必ず通ること。
    $base = sys_get_temp_dir().'/fake-wiring-probe-base-'.bin2hex(random_bytes(6));
    expect(mkdir($base, 0700))->toBeTrue();

    try {
        expect(fn (): array => FakeWiringProbeRunner::run('bughunt.local', true, true, false, $base, 0.01))
            ->toThrow(ProcessTimedOutException::class);

        expect(array_values(array_diff(scandir($base) ?: [], ['.', '..'])))->toBe([]);
    } finally {
        rmdir($base);
    }
});

test('P-11 設定キャッシュの指し先は一時ディレクトリ配下の絶対パスで、存在しない', function (): void {
    $run = externalFakeProbeRun('fake');

    expect(str_starts_with($run['configCachePath'], '/'))->toBeTrue()
        ->and(str_starts_with($run['configCachePath'], $run['directory'].'/'))->toBeTrue()
        ->and($run['configCacheExists'])->toBeFalse();
});

test('P-12 宣言の型: 観測が読む swaps() は ExternalFakeBinding の列である', function (): void {
    foreach (ExternalFakeDeclaration::swaps() as $swap) {
        expect($swap)->toBeInstanceOf(ExternalFakeBinding::class);
    }
});
