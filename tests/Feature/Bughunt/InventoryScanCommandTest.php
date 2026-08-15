<?php

declare(strict_types=1);

use App\DataTransferObjects\Bughunt\InventoryScanData;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\Support\SplitConsoleOutput;

/*
 * 目録の機械事実を書き出す抽出コマンド (bughunt:inventory-scan) の契約 (T176)。
 *
 * このコマンドは「事実の書き出し」だけを行う。面の判定・分類・除外は 1 つも持たない
 * (判定は生成器 scripts/bug-hunt-inventory.py に一本化してあり、同じ規則を 2 言語へ置かない)。
 * よって全 route を出力し、名前の無い route も落とさない。
 *
 * 抽出条件 (local もしくはテスト実行中) は routes/web.php の debug route 登録条件と
 * **同じ述語**であり、二重管理になっている。片方だけ変えると母集合が黙って変わるので、
 * 条件を変えるときは routes/web.php と本テストの両方を直すこと。
 */

/**
 * コマンドを実行し [終了コード, 標準出力, 標準エラー] を返す。
 *
 * @return array{0: int, 1: string, 2: string}
 */
function inventoryScanRun(): array
{
    $output = new SplitConsoleOutput;
    $exitCode = Artisan::call('bughunt:inventory-scan', [], $output);

    return [$exitCode, $output->stdout(), $output->stderr()];
}

/**
 * 抽出結果 (成功時) を配列で受け取る。
 *
 * @return array<string, mixed>
 */
function inventoryScanOutput(): array
{
    [$exitCode, $stdout] = inventoryScanRun();
    expect($exitCode)->toBe(0, '抽出コマンドが非 0 で終了した');

    $decoded = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
    expect($decoded)->toBeArray();
    /** @var array<string, mixed> $decoded */

    return $decoded;
}

/**
 * 出力から route 名で 1 件引く。
 *
 * @param  array<string, mixed>  $output
 * @return array<string, mixed>|null
 */
function inventoryScanRoute(array $output, string $name): ?array
{
    $routes = $output['routes'];
    expect($routes)->toBeArray();
    /** @var list<mixed> $routes */
    foreach ($routes as $route) {
        if (is_array($route) && ($route['name'] ?? null) === $name) {
            /** @var array<string, mixed> $route */
            return $route;
        }
    }

    return null;
}

test('抽出結果が 1 行の JSON で、宣言した形を持つこと', function (): void {
    [$exitCode, $stdout] = inventoryScanRun();
    expect($exitCode)->toBe(0);
    expect(substr_count(trim($stdout), "\n"))->toBe(0, '出力は 1 行の JSON であること (人間向けの装飾を混ぜない)');

    $output = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
    expect($output)->toBeArray();
    /** @var array<string, mixed> $output */
    expect($output['schema_version'])->toBe(InventoryScanData::SCHEMA_VERSION);
    expect($output['extraction_condition'])->toBe(InventoryScanData::EXTRACTION_CONDITION);
    expect($output['routes'])->toBeArray()->not->toBeEmpty();

    $routes = $output['routes'];
    expect($routes)->toBeArray();
    /** @var list<mixed> $routes */
    $route = $routes[0];
    expect($route)->toBeArray();
    /** @var array<string, mixed> $route */
    expect(array_keys($route))->toBe(['name', 'uri', 'methods', 'middleware', 'action', 'title']);
});

test('web group を宣言した route の middleware に文字列 web がそのまま残ること', function (): void {
    $route = inventoryScanRoute(inventoryScanOutput(), 'dashboard');

    expect($route)->not->toBeNull();
    /** @var array<string, mixed> $route */
    // gatherMiddleware() は group を展開しない (web が消えたら生成器の面の判定が壊れる)。
    expect($route['middleware'])->toContain('web');
});

test('config(seo.app_titles) にある route は題名が引け、無い route は null になること', function (): void {
    config(['seo.app_titles' => ['dashboard' => 'ダッシュボード']]);

    $output = inventoryScanOutput();

    $withTitle = inventoryScanRoute($output, 'dashboard');
    expect($withTitle)->not->toBeNull();
    /** @var array<string, mixed> $withTitle */
    expect($withTitle['title'])->toBe('ダッシュボード');

    $withoutTitle = inventoryScanRoute($output, 'login');
    expect($withoutTitle)->not->toBeNull();
    /** @var array<string, mixed> $withoutTitle */
    expect($withoutTitle['title'])->toBeNull();
});

test('名前の無い route も出力に含まれること (面の判定はコマンドの責務ではない)', function (): void {
    Route::get('bughunt-inventory-scan-anonymous', fn () => 'ok');

    $output = inventoryScanOutput();
    $routes = $output['routes'];
    expect($routes)->toBeArray();
    /** @var list<array<string, mixed>> $routes */
    $anonymous = array_values(array_filter(
        $routes,
        fn (array $route): bool => $route['uri'] === 'bughunt-inventory-scan-anonymous',
    ));

    expect($anonymous)->toHaveCount(1);
    expect($anonymous[0]['name'])->toBeNull();
});

test('抽出条件を満たさない環境では非 0 終了し、標準出力に 1 バイトも出さないこと', function (): void {
    // Laravel 12 の isLocal() は $this['env'] === 'local'、runningUnitTests() は
    // bound('env') && $this['env'] === 'testing' で、どちらも同じ束縛 env を読む。
    // よって env を差し替えれば両方を false にできる。detectEnvironment() は
    // $_SERVER['argv'] を見る経路があるので使わない。
    $original = app('env');

    try {
        app()->instance('env', 'production');

        [$exitCode, $stdout, $stderr] = inventoryScanRun();

        expect($exitCode)->not->toBe(0, '抽出条件を満たさない環境では成功にしない');
        expect($stdout)->toBe('', '壊れた入力を後段へ渡さない (標準出力へは 1 バイトも出さない)');
        expect($stderr)->not->toBe('', '理由は標準エラーへ出す');
    } finally {
        app()->instance('env', $original);
    }
});
