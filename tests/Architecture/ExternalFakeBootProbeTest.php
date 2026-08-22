<?php

declare(strict_types=1);

use App\Support\ExternalFakes\ExternalFakeBinding;
use App\Support\ExternalFakes\ExternalFakeDeclaration;
use Tests\Support\ExternalFakes\FakeWiringProbeRunner;
use Tests\Support\Process\BootProbeResult;
use Tests\Support\Process\BootProbeRunner;

/*
 * 別プロセスで「宣言した差し替えが実際に効いているか」を実測する
 * (c2c: external-fakes-wiring-gate 柱 2)。
 *
 * in-process の実証 (ExternalFakeWiringInvariantTest) は provider を手で再実走させるため、
 * 「実際の起動 (遅延読み込み provider・設定の解決順) でも効いているか」までは示せない。
 * ここでは子プロセスを起こし、起動しきったアプリの container から解決して観測する。
 *
 * ★子の起こし方・回収・書き出し先の退避は共通の起動器
 *   (`Tests\Support\Process\BootProbeRunner`) が持つ
 *   (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(5))。
 *
 * ★**子プロセスへ実際の外部資格情報を渡さない**。子の環境は**4 段**で組み立てる —
 *   継承 (`PATH` / `HOME` / `TMPDIR`) → 基底 (`APP_KEY` / `QUEUE_CONNECTION` / `CACHE_STORE`) →
 *   ケース別 (`FakeWiringProbeRunner::CASE_ENV_KEYS` の 3 件) → 予約 (書き出し先 7 キー)。
 *   統制点は `proc_open` へ渡す環境配列であり、開発者ローカルの env はそこで締め出される (P-7)。
 *
 * ★**使い捨て鍵の置き場所は 2 つに分かれる**。`APP_KEY` は**ケース別上書き**、
 *   `CIPHERSWEET_KEY` は**環境ファイル**である (Laravel の環境変数リポジトリは immutable で、
 *   プロセス環境に既に在る値を Dotenv は上書きしないため)。どちらも親の実鍵の複写ではないこと、
 *   かつ**子で実際に効いた**ことを P-8 が digest で測る。
 *
 * ★**正典 v1 (5) の実働証明**は P-13 (実体) と P-14 (向き) が持つ。「書き出し先を退避した」は、
 *   退避が効いていなければ既定の場所へ書かれて観測が緑のまま嘘になるので、
 *   子が `storage_path()` 経由で置いた印が起動器の一時ディレクトリ配下に現れることまで測る。
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
 *     caseEnvValues: array<string, string>,
 *     directory: string,
 *     directoryMode: int,
 *     envFileMode: int,
 *     temporaryRoot: string,
 *     writtenRelativePaths: list<string>,
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

    /** @var array{exitCode: int, output: array<string, mixed>, envFileValues: array<string, string>, caseEnvValues: array<string, string>, directory: string, directoryMode: int, envFileMode: int, temporaryRoot: string, writtenRelativePaths: list<string>, baseDirectory: string} $entry */
    $entry = $cache[$case];

    return $entry;
}

/**
 * 書き出し先が**正規化済みの絶対パス**であることを確かめる (`.` / `..` を 1 つも含まない)。
 *
 * ★`BootProbeRunner::isInside()` の契約は「両引数とも realpath 済み」である。ところが
 *   書き出し先の多く (設定キャッシュ等) は**まだ存在しないファイル**なので realpath できず、
 *   子が返す文字列をそのまま渡すことになる。ここを素通しにすると
 *   `<一時 root>/../../<リポジトリ>/…` のような形が
 *   「一時 root の配下かつリポジトリの外」と判定され、**実際にはリポジトリ内へ解決される**のに
 *   P-11 / P-14 が緑のまま通る (fail-open)。
 *   予約パスの組み立てに `..` が混じる退行を見逃さないため、配下判定の**前に**弾く。
 */
function externalFakeProbeIsNormalizedAbsolutePath(string $path): bool
{
    if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
        return false;
    }

    foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
        if ($segment === '.' || $segment === '..') {
            return false;
        }
    }

    return true;
}

/**
 * 上の述語で書き出し先を検査する (診断文つき)。
 *
 * ★述語そのものの検出力は P-16 が**恒久の負例**で裏取りする
 *   (実データが常に正常なので、この helper を空実装にしても P-11 / P-14 は緑のままになる。
 *   AGENTS.md §静的検査の共通規約 (c) の「検出力は負例で裏取りする」に当たる)。
 */
function externalFakeProbeAssertNormalizedPath(string $path, string $label): void
{
    expect(externalFakeProbeIsNormalizedAbsolutePath($path))
        ->toBeTrue("書き出し先 {$label} が正規化された絶対パスでない: {$path}");
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

test('P-7 子が実際に受け取ったプロセス環境が 4 段の合成結果と完全一致する', function (): void {
    // (0) 4 段の定数そのものをリテラルで pin する。実装側の定数から期待値を組み立てるだけだと、
    //     実装と期待値を同時に変えたときに緑のまま通ってしまう。
    expect(BootProbeRunner::INHERITED_ENV_KEYS)->toBe(['PATH', 'HOME', 'TMPDIR'])
        ->and(BootProbeRunner::RESERVED_ENV_KEYS)->toBe([
            'LARAVEL_STORAGE_PATH',
            'VIEW_COMPILED_PATH',
            'APP_CONFIG_CACHE',
            'APP_ROUTES_CACHE',
            'APP_SERVICES_CACHE',
            'APP_PACKAGES_CACHE',
            'APP_EVENTS_CACHE',
        ])
        ->and(FakeWiringProbeRunner::CASE_ENV_KEYS)->toBe([
            'FAKE_WIRING_PROBE_ENV_DIR',
            'FAKE_WIRING_PROBE_ENV_FILE',
            'APP_KEY',
        ]);

    $run = externalFakeProbeRun('fake');
    $keys = $run['output']['process_environment_keys'] ?? null;
    expect($keys)->toBeArray();
    /** @var list<mixed> $keys */
    $actual = array_map(static fn (mixed $key): string => (string) $key, $keys);

    // (a) 危険な接頭辞が 1 件も無いこと (env -i の時代からの主張をそのまま維持する)。
    //     TESTING_FAKE_* は**プロセス環境へ載せない** (0600 の環境ファイルの中だけに置く)。
    foreach (['DB_', 'PG', 'AWS_', 'STRIPE_', 'TESTING_FAKE_', 'GOOGLE_'] as $prefix) {
        $leaked = array_values(array_filter(
            $actual,
            static fn (string $key): bool => str_starts_with($key, $prefix)
        ));
        expect($leaked)->toBe([], "禁止する接頭辞 {$prefix} のキーが子へ流れている");
    }

    // (b) 集合の完全一致 (deny-by-default)。「以下」ではないので 1 本足しただけで赤くなる。
    $inherited = array_values(array_filter(
        ['PATH', 'HOME', 'TMPDIR'],
        static function (string $key): bool {
            $value = getenv($key);

            return is_string($value) && $value !== '';
        },
    ));
    $expected = array_values(array_unique(array_merge(
        $inherited,
        ['APP_KEY', 'QUEUE_CONNECTION', 'CACHE_STORE'],
        ['FAKE_WIRING_PROBE_ENV_DIR', 'FAKE_WIRING_PROBE_ENV_FILE', 'APP_KEY'],
        ['LARAVEL_STORAGE_PATH', 'VIEW_COMPILED_PATH', 'APP_CONFIG_CACHE',
            'APP_ROUTES_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_EVENTS_CACHE'],
    )));
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

test('P-8 使い捨て鍵が子で実際に効き、親の設定値の複写ではない', function (): void {
    $run = externalFakeProbeRun('fake');

    $digests = $run['output']['key_digests'] ?? null;
    expect($digests)->toBeArray();
    /** @var array<string, mixed> $digests */

    // (a) 子で効いた APP_KEY が、起動側が生成した使い捨て値と一致する
    expect($digests['app'] ?? null)->toBe(hash('sha256', $run['caseEnvValues']['APP_KEY']));
    // (b) 子で効いた CIPHERSWEET_KEY が、環境ファイルへ書いた使い捨て値と一致する
    expect($digests['ciphersweet'] ?? null)->toBe(hash('sha256', $run['envFileValues']['CIPHERSWEET_KEY']));
    // (c) いずれも親の設定値の複写ではない
    expect($digests['app'])->not->toBe(hash('sha256', (string) config('app.key')))
        ->and($digests['ciphersweet'])
        ->not->toBe(hash('sha256', (string) config('ciphersweet.providers.string.key')));
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

test('P-10 正常終了・非ゼロ終了のいずれでも環境ファイルの置き場所が残らない', function (): void {
    foreach (['fake', 'real', 'production'] as $case) {
        $run = externalFakeProbeRun($case);

        expect(is_dir($run['directory']))->toBeFalse("一時ディレクトリが残っている: {$case}")
            ->and(array_values(array_diff(scandir($run['baseDirectory']) ?: [], ['.', '..'])))
            ->toBe([], "一時ディレクトリの親に残骸がある: {$case}");
    }
});

test('P-10b 作れない置き場所では子を起こさずに失敗し、残骸を残さない', function (): void {
    $base = sys_get_temp_dir().'/fake-wiring-probe-readonly-'.bin2hex(random_bytes(6));
    expect(mkdir($base, 0500))->toBeTrue();

    try {
        // ★失敗の**段**まで固定する。message を見ないと「子を起こしたあとで別の理由で
        //   落ちた」場合も緑になり、「子を起こさずに」の部分が主張だけになる。
        //   この message は置き場所の検査 (= 子を起こす前) だけが投げる。
        expect(fn (): array => FakeWiringProbeRunner::run('bughunt.local', true, true, false, $base))
            ->toThrow(RuntimeException::class, '観測用の置き場所を使用できない');

        expect(array_values(array_diff(scandir($base) ?: [], ['.', '..'])))->toBe([]);
    } finally {
        rmdir($base);
    }
})->skip(
    // root で走ると 0500 でも書けてしまい、負のコントロールが成立しない。
    // **成功扱いにはしない** — 測れていないことをテスト結果に出す。
    fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0,
    'root では書き込み権限の負のコントロールを作れない',
);

test('P-10c 本体が例外を投げても置き場所が中身ごと消える (制限時間超過の後始末)', function (): void {
    // 制限時間超過は interpret() が例外にする (P-15)。その例外が外側の finally を通ることを
    // ここで決定的に測る (実 timeout を作るには子を 1 秒以上眠らせる必要があり、
    // それは観測用スクリプトの責務を汚すので採らない)。
    // ★空のディレクトリではなく**中身のある**状態で測る — 実際の制限時間超過では
    //   .env.probe が既に書かれているので、再帰削除まで示さないと主張と距離がある。
    $base = sys_get_temp_dir().'/fake-wiring-probe-base-'.bin2hex(random_bytes(6));
    expect(mkdir($base, 0700))->toBeTrue();

    $created = null;

    try {
        expect(function () use ($base, &$created): mixed {
            return FakeWiringProbeRunner::withEnvironmentDirectory(
                $base,
                static function (string $directory) use (&$created): mixed {
                    $created = $directory;

                    // 実際の走行と同じく環境ファイルを置き、さらに下位ディレクトリの中にも番兵を置く。
                    expect(file_put_contents($directory.'/.env.probe', "APP_ENV=x\n"))->not->toBeFalse();
                    expect(mkdir($directory.'/nested', 0700))->toBeTrue();
                    expect(file_put_contents($directory.'/nested/sentinel.txt', 'x'))->not->toBeFalse();

                    throw new RuntimeException('本体の失敗');
                },
            );
        })->toThrow(RuntimeException::class);

        // 置き場所は作られ (= 検査が空振りしていない)、中身ごと消えている。
        expect($created)->toBeString()
            ->and(is_dir((string) $created))->toBeFalse('置き場所が残っている')
            ->and(array_values(array_diff(scandir($base) ?: [], ['.', '..'])))->toBe([]);
    } finally {
        rmdir($base);
    }
});

test('P-10d リポジトリ内の置き場所は本体を呼ばずに拒否し、残骸を残さない', function (): void {
    // 正典 v1 (5) の fail-closed を**外側**でも測る (内側は取り込んだ自己検査 S11 が持つ)。
    $base = base_path('storage/framework/testing');

    // ★このテストが作った階層を**1 つ残らず**戻す (走行が生成物を残さないため)。
    //   `mkdir(recursive)` + `rmdir($base)` だけだと、親を新規作成した環境
    //   (新しい checkout など) で `storage/framework` が残る。
    $createdAncestors = [];   // 深い順
    for ($candidate = $base; ! is_dir($candidate); $candidate = dirname($candidate)) {
        $createdAncestors[] = $candidate;
    }
    foreach (array_reverse($createdAncestors) as $directory) {
        expect(mkdir($directory, 0755))->toBeTrue("後始末の対象を作れない: {$directory}");
    }

    try {
        $before = glob($base.'/fake-wiring-probe-*');
        expect($before)->toBeArray();

        $bodyCalled = false;

        expect(function () use ($base, &$bodyCalled): mixed {
            return FakeWiringProbeRunner::withEnvironmentDirectory(
                $base,
                static function (string $directory) use (&$bodyCalled): mixed {
                    $bodyCalled = true;

                    return $directory;
                },
            );
        })->toThrow(RuntimeException::class);

        expect($bodyCalled)->toBeFalse('リポジトリ内なのに本体が呼ばれた')
            ->and(glob($base.'/fake-wiring-probe-*'))->toBe($before, '拒否経路が残骸を残している');
    } finally {
        // 深い順に戻す (作った分だけ)。
        foreach ($createdAncestors as $directory) {
            rmdir($directory);
        }
    }
});

test('P-11 設定キャッシュの退避先が一時ディレクトリ配下で、書かれていない', function (): void {
    $run = externalFakeProbeRun('fake');

    $targets = $run['output']['write_targets'] ?? null;
    expect($targets)->toBeArray();
    /** @var array<string, mixed> $targets */
    $configCache = $targets['config_cache'] ?? null;
    expect($configCache)->toBeString();
    /** @var string $configCache */
    // 配下判定の前に正規化を確かめる (`..` 経由でリポジトリへ戻る形を通さない)。
    externalFakeProbeAssertNormalizedPath($configCache, 'config_cache');

    expect(BootProbeRunner::isInside($run['temporaryRoot'], $configCache))->toBeTrue()
        // 設定キャッシュ**無し**の起動を観測している (書かれていたら前提が崩れている)。
        ->and($run['writtenRelativePaths'])->not->toContain('bootstrap-cache/config.php');
});

test('P-12 宣言の型: 観測が読む swaps() は ExternalFakeBinding の列である', function (): void {
    foreach (ExternalFakeDeclaration::swaps() as $swap) {
        expect($swap)->toBeInstanceOf(ExternalFakeBinding::class);
    }
});

test('P-13 実働証明(実体): 子が storage_path() 経由で書いた印が一時ディレクトリ配下に現れる', function (): void {
    $run = externalFakeProbeRun('fake');

    expect($run['writtenRelativePaths'])
        ->toContain('storage/'.FakeWiringProbeRunner::MARKER_RELATIVE_PATH);
});

test('P-14 実働証明(向き): 子が解決した書き出し先が 1 件残らず一時ディレクトリ配下でリポジトリの外', function (): void {
    $run = externalFakeProbeRun('fake');

    $targets = $run['output']['write_targets'] ?? null;
    expect($targets)->toBeArray();
    /** @var array<string, mixed> $targets */
    $repositoryRoot = realpath(base_path());
    expect($repositoryRoot)->toBeString();
    /** @var string $repositoryRoot */
    $expectedKeys = ['storage', 'config_cache', 'routes_cache', 'services_cache',
        'packages_cache', 'events_cache', 'view_compiled', 'log_path'];
    expect(array_keys($targets))->toBe($expectedKeys, '観測点の集合が変わっている');

    foreach ($expectedKeys as $key) {
        $path = $targets[$key];
        expect($path)->toBeString();
        /** @var string $path */

        // ★配下判定の**前に**正規化を確かめる。isInside は realpath 済みを前提にするので、
        //   `..` を含む形は「一時 root 配下かつリポジトリ外」と誤判定されうる (fail-open)。
        externalFakeProbeAssertNormalizedPath($path, $key);

        // 区切り文字を境界にした配下判定 (素の前方一致は /a と /ab を取り違える)。
        // isInside は同一パスも true にするので、base_path() 自身も「外ではない」に入る。
        expect(BootProbeRunner::isInside($run['temporaryRoot'], $path))
            ->toBeTrue("書き出し先 {$key} が一時ディレクトリの外を指している: {$path}")
            ->and(BootProbeRunner::isInside($repositoryRoot, $path))
            ->toBeFalse("書き出し先 {$key} がリポジトリ側を指している: {$path}");
    }
});

test('P-15 fail-closed: interpret() は観測が成立していない結果を沈黙させない', function (): void {
    $make = static fn (string $stdout, bool $timedOut, int $exitCode): BootProbeResult => new BootProbeResult(
        stdout: $stdout, stderr: '', exitCode: $exitCode, timedOut: $timedOut,
        elapsedSeconds: 0.1, temporaryRoot: '/tmp/boot-probe-x',
        writtenRelativePaths: [], pid: 1,
    );

    $call = static fn (BootProbeResult $result): array => FakeWiringProbeRunner::interpret(
        $result, [], [], '/tmp/dir', 0700, 0600,
    );

    // (a) 制限時間超過は通常の非ゼロ終了と区別して例外にする (fail-open 防止)
    expect(fn (): array => $call($make('{"resolved":{}}', true, 124)))->toThrow(RuntimeException::class);
    // (b) 空出力 / (c) JSON でない / (d) トップレベルが配列でない
    expect(fn (): array => $call($make('', false, 0)))->toThrow(RuntimeException::class);
    expect(fn (): array => $call($make('not json', false, 0)))->toThrow(RuntimeException::class);
    expect(fn (): array => $call($make('"scalar"', false, 0)))->toThrow(RuntimeException::class);
});

test('P-16 正規化判定の検出力: 正常な絶対パスは通り、`..` / `.` / 相対パスは弾く', function (
    string $path,
    bool $expected,
): void {
    expect(externalFakeProbeIsNormalizedAbsolutePath($path))->toBe($expected, $path);
})->with([
    // --- 正例 (実データと同じ形。これが false になると P-11 / P-14 が偽レッドになる) ---
    ['/tmp/boot-probe-abc/storage', true],
    ['/tmp/boot-probe-abc/bootstrap-cache/config.php', true],
    ['/tmp/boot-probe-abc/storage/framework/views', true],
    // --- 負例: `..` でリポジトリ側へ戻れる形 (これを通すと P-11 / P-14 が fail-open) ---
    ['/tmp/boot-probe-abc/../../workspace/bootstrap/cache/config.php', false],
    ['/tmp/boot-probe-abc/..', false],
    ['/../tmp/boot-probe-abc/storage', false],
    // --- 負例: `.` セグメント ---
    ['/tmp/boot-probe-abc/./storage', false],
    ['/tmp/./boot-probe-abc/storage', false],
    // --- 負例: 相対パス (絶対パス前提が崩れた形) ---
    ['tmp/boot-probe-abc/storage', false],
    ['./storage', false],
    ['../storage', false],
    // --- 紛らわしいが正当な形 (素の部分文字列判定なら誤って弾く 3 形) ---
    ['/tmp/boot-probe-abc/..hidden', true],
    ['/tmp/boot-probe-abc/.hidden', true],
    ['/tmp/boot-probe-abc/a..b/storage', true],
]);
