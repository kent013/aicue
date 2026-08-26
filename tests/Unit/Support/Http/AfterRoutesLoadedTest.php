<?php

declare(strict_types=1);

use App\Support\Http\AfterRoutesLoaded;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Mockery\MockInterface;

/*
|--------------------------------------------------------------------------
| 経路後付けの実行点の契約 (route:cache 済み起動を壊さないこと)
|--------------------------------------------------------------------------
|
| `php artisan route:cache` した状態では framework の `RouteServiceProvider` が
| **起動完了フックの中で**経路キャッシュを require する。経路へ middleware を後付けする側が
| 素の `Application::booted()` を使うと、その前に「経路が 1 本も無い状態」で走り、
| 名前解決の fail-fast が誤爆して **cached 起動が丸ごと落ちる** (T120 の事故。
| `php artisan route:list` も `route:clear` も落ちて復旧手段まで失う)。
|
| 本テストが固定するのは `AfterRoutesLoaded::schedule()` の**分岐の契約**だけである。
| 「`app/` 配下に素の起動完了フックで経路を触るコードが無いこと」は静的検査の側
| (`tests/Architecture/PostBootRouteMutationInventoryTest.php`) が持つ。
|
| ★DB には触らないが Unit レーンの既定 (TestCase + RefreshDatabase) に乗せる
|   (レーンごとの結線を分岐させない。`RateLimiterKeysTest` と同じ扱い)。
*/

/**
 * 経路キャッシュの状態を申告する `Application` の替え玉。
 *
 * `Application` の契約は `routesAreCached()` を宣言しないので `CachesRoutes` を併せて実装させる
 * (実装は `Illuminate\Foundation\Application` の 1 本だけであり、そこは両方を実装している)。
 *
 * @return Application&CachesRoutes&MockInterface
 */
function afterRoutesLoadedApp(bool $routesAreCached, int $bootedCalls = 1): Application
{
    /** @var Application&CachesRoutes&MockInterface $app */
    $app = Mockery::mock(Application::class, CachesRoutes::class);

    $app->shouldReceive('booted')->times($bootedCalls)->andReturnUsing(function (Closure $callback): void {
        $callback();
    });
    $app->shouldReceive('routesAreCached')->andReturn($routesAreCached);

    return $app;
}

test('経路が cached でなければ起動完了フックで実行される', function (): void {
    $executed = false;

    AfterRoutesLoaded::schedule(afterRoutesLoadedApp(routesAreCached: false), function () use (&$executed): void {
        $executed = true;
    });

    expect($executed)->toBeTrue();
});

test('経路が cached なら実行されない (空の経路一覧で fail-fast を誤爆させない)', function (): void {
    $executed = false;

    AfterRoutesLoaded::schedule(afterRoutesLoadedApp(routesAreCached: true), function () use (&$executed): void {
        $executed = true;
    });

    expect($executed)->toBeFalse();
});

test('cached 起動では例外を投げる callback へ到達しない (route:list を落とさない)', function (): void {
    $thrown = null;

    try {
        AfterRoutesLoaded::schedule(afterRoutesLoadedApp(routesAreCached: true), static function (): void {
            throw new RuntimeException('cached 起動で後付けが実行された');
        });
    } catch (Throwable $failure) {
        $thrown = $failure;
    }

    expect($thrown)->toBeNull(
        'cached 起動で callback へ到達した (この形が route:list / route:clear を落とす)',
    );
});

test('cached の判定は起動完了フックの中で行う (登録時点ではない)', function (): void {
    // 登録時点で判定すると、経路キャッシュの状態がまだ確定していない場面で誤判定しうる。
    $order = [];

    /** @var Application&CachesRoutes&MockInterface $app */
    $app = Mockery::mock(Application::class, CachesRoutes::class);
    $app->shouldReceive('booted')->once()->andReturnUsing(function (Closure $callback) use (&$order): void {
        $order[] = 'booted-fired';
        $callback();
    });
    $app->shouldReceive('routesAreCached')->once()->andReturnUsing(function () use (&$order): bool {
        $order[] = 'routesAreCached';

        return false;
    });

    AfterRoutesLoaded::schedule($app, static function () use (&$order): void {
        $order[] = 'callback';
    });

    expect($order)->toBe(['booted-fired', 'routesAreCached', 'callback']);
});

test('経路キャッシュの概念を持たない容器では実行する (黙って無保護へ倒れない)', function (): void {
    // `CachesRoutes` でない容器では「cached ではない」= 実行する側へ倒す。
    // 経路が引けなければ後付け側が起動時に落ちるので、無保護のまま公開される側へは倒れない。
    $executed = false;

    /** @var Application&MockInterface $app */
    $app = Mockery::mock(Application::class);
    $app->shouldReceive('booted')->once()->andReturnUsing(function (Closure $callback): void {
        $callback();
    });

    AfterRoutesLoaded::schedule($app, function () use (&$executed): void {
        $executed = true;
    });

    expect($executed)->toBeTrue();
});

test('実アプリの状態でも同じ分岐になる (替え玉と実装の食い違いを検出する)', function (bool $cached, bool $expected): void {
    // `Illuminate\Foundation\Application::routesAreCached()` は容器の束縛 `routes.cached` を
    // 先に見るため、束縛を差し替えるだけで cached 起動を再現できる (cache ファイルを作らない)。
    // 既に boot 済みのアプリでは `booted()` が callback を**その場で**発火する。
    app()->instance('routes.cached', $cached);

    $executed = false;
    AfterRoutesLoaded::schedule(app(), function () use (&$executed): void {
        $executed = true;
    });

    expect($executed)->toBe($expected);
})->with([
    'cached 起動' => [true, false],
    '非 cached 起動' => [false, true],
]);
