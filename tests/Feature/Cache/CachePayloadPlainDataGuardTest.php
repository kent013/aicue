<?php

declare(strict_types=1);

/*
 * 実行時層 (キャッシュ素データ規約) の振る舞い検査。
 *
 * 静的層 (tests/Architecture/CachePayloadPlainDataGateTest.php) が保証するのは
 * 「申告なしに書き込み経路を増やせない」ことだけで、目録の payload 欄は人間の申告である。
 * ここで固定するのは「**テストが実行した書き込みの値が実際に素データである**」ことを
 * 受け皿 (Illuminate\Cache\Repository) の側で機械的に検査できている、という実体である。
 *
 * ★意図的に違反を起こす検査は必ず CachePayloadViolationAssertions::expectViolation() を通す。
 *   accumulator を drain しないと global afterEach の flushAndFailIfStray() が二重に落ちる。
 */

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Tests\Support\Cache\CachePayloadViolation;
use Tests\Support\Cache\CachePayloadViolationAssertions;
use Tests\Support\Cache\GuardedBoundaryProbe;
use Tests\Support\Cache\IsolatedApplicationProbe;
use Tests\Support\Cache\PlainDataCacheGuard;
use Tests\Support\Cache\PlainDataGuardedCacheManager;
use Tests\Support\Cache\PlainDataGuardedRepository;
use Tests\Support\Cache\PlainDataInspector;

/**
 * guard 付き受け皿へ**実 API 経由**で書き込む (合流の実証用)。
 *
 * remember / rememberForever / sear / set / setMultiple / flexible /
 * rememberWithWarmth / ArrayAccess は vendor 実装が put / add / forever / putMany へ
 * 合流する。その合流が将来変わったら本テストが落ちる (guard の被覆が静かに減らない)。
 *
 * ★受け皿は**型宣言の引数**で受ける。ローカル変数へ代入する書き方だと静的層が
 *   受け手名を解決できず、書き込みが L2 目録に現れなくなる。
 */
function cachePayloadGuardWrite(Repository $cache, string $method, string $key, mixed $value): void
{
    match ($method) {
        'put' => $cache->put($key, $value, 60),
        'add' => $cache->add($key, $value, 60),
        'forever' => $cache->forever($key, $value),
        'putMany' => $cache->putMany([$key => $value], 60),
        'set' => $cache->set($key, $value, 60),
        'setMultiple' => $cache->setMultiple([$key => $value], 60),
        'remember' => $cache->remember($key, 60, fn (): mixed => $value),
        'rememberForever' => $cache->rememberForever($key, fn (): mixed => $value),
        'sear' => $cache->sear($key, fn (): mixed => $value),
        'flexible' => $cache->flexible($key, [60, 120], fn (): mixed => $value),
        'rememberWithWarmth' => $cache->rememberWithWarmth($key, 60, fn (): mixed => $value),
        'offsetSet' => $cache[$key] = $value,
        'offsetCoalesce' => $cache[$key] ??= $value,
        default => throw new InvalidArgumentException("未知の書き込みメソッド: {$method}"),
    };
}

/**
 * `put()` の**配列キー形** (vendor が putMany へ回す分岐) を実 API 経由で叩く。
 *
 * @param  array<string, mixed>  $values
 */
function cachePayloadGuardPutMap(Repository $cache, array $values): void
{
    $cache->put($values, 60);
}

/**
 * 名指しで分類した排他の素通し (`PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS`) を
 * 実 API 経由で叩く。受け皿は**型宣言の引数**で受ける (静的層の受け手解決のため)。
 */
function cachePayloadGuardLock(Repository $cache, string $method): Lock
{
    return match ($method) {
        'lock' => $cache->lock('guard-passthrough-lock', 1),
        'restoreLock' => $cache->restoreLock('guard-passthrough-lock', 'guard-owner'),
        default => throw new InvalidArgumentException("未知の排他メソッド: {$method}"),
    };
}

/** guard 付き受け皿を具体クラスへ絞って取り出す (ArrayAccess を使うため契約型では足りない)。 */
function cachePayloadGuardedRepository(): Repository
{
    $repository = Cache::store('array');
    expect($repository)->toBeInstanceOf(PlainDataGuardedRepository::class);
    assert($repository instanceof Repository);

    return $repository;
}

// ---------------------------------------------------------------------------
// 検査 1-7: 実 API 経由の値検査 (合流の実証を含む)
// ---------------------------------------------------------------------------

test('検査 1: Event::fake() の後でも guard が効く', function (): void {
    Event::fake();

    CachePayloadViolationAssertions::expectViolation(
        fn () => cachePayloadGuardWrite(cachePayloadGuardedRepository(), 'put', 'guard-event-fake', new stdClass),
        ['put', 'guard-event-fake', 'OBJECT_FOUND(stdClass)'],
    );
});

test('検査 2: 値の末端 4 メソッドがオブジェクトを落とす', function (string $method): void {
    CachePayloadViolationAssertions::expectViolation(
        fn () => cachePayloadGuardWrite(cachePayloadGuardedRepository(), $method, "guard-terminal-{$method}", new stdClass),
        ['OBJECT_FOUND(stdClass)'],
    );
})->with(['put', 'add', 'forever', 'putMany']);

test('検査 3: 糖衣 API も末端へ合流して落ちる', function (string $method): void {
    CachePayloadViolationAssertions::expectViolation(
        fn () => cachePayloadGuardWrite(cachePayloadGuardedRepository(), $method, "guard-sugar-{$method}", new stdClass),
        ['OBJECT_FOUND(stdClass)'],
    );
})->with(['set', 'setMultiple', 'remember', 'rememberForever', 'sear', 'flexible', 'rememberWithWarmth']);

test('検査 4: ArrayAccess 書き込みも末端へ合流して落ちる', function (string $method): void {
    CachePayloadViolationAssertions::expectViolation(
        fn () => cachePayloadGuardWrite(cachePayloadGuardedRepository(), $method, "guard-offset-{$method}", new stdClass),
        ['OBJECT_FOUND(stdClass)'],
    );
})->with(['offsetSet', 'offsetCoalesce']);

test('検査 4b: put() の配列キー形 (putMany 相当) も末端として検査される', function (): void {
    // ★vendor の put() は `$key` が配列なら putMany へ回す。値の実体は $key 側にあるので、
    //   override はこの分岐を専用に検査する。負例と正例の両方で固定する。
    CachePayloadViolationAssertions::expectViolation(
        fn () => cachePayloadGuardPutMap(cachePayloadGuardedRepository(), ['guard-put-map' => new stdClass]),
        ['put', '(many)', "value['guard-put-map'] = OBJECT_FOUND(stdClass)"],
    );

    $cache = cachePayloadGuardedRepository();
    cachePayloadGuardPutMap($cache, ['guard-put-map-ok' => ['a' => 1]]);

    expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
    expect($cache->get('guard-put-map-ok'))->toBe(['a' => 1]);
});

test('検査 5: クロージャも違反になる', function (): void {
    CachePayloadViolationAssertions::expectViolation(
        fn () => cachePayloadGuardWrite(cachePayloadGuardedRepository(), 'put', 'guard-closure', fn (): int => 1),
        ['OBJECT_FOUND(Closure)'],
    );
});

test('検査 6: 素のデータは通る', function (mixed $value): void {
    $cache = cachePayloadGuardedRepository();
    $key = 'guard-plain-'.md5(serialize($value));

    cachePayloadGuardWrite($cache, 'put', $key, $value);

    expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
    expect($cache->get($key))->toBe($value);
})->with([
    [['a' => 1, 'b' => [true, false]]],
    ['文字列'],
    [42],
    [1.5],
    [true],
    [null],
    [[[[['深い']]]]],
]);

test('検査 7: 違反メッセージが method / key / 違反パスと種別 / 規約参照を持つ', function (): void {
    $cache = cachePayloadGuardedRepository();

    try {
        cachePayloadGuardWrite($cache, 'add', 'guard-message', ['dto' => new stdClass]);
        $this->fail('違反が検出されませんでした');
    } catch (CachePayloadViolation $exception) {
        expect($exception->getMessage())
            ->toContain('add')
            ->toContain('guard-message')
            ->toContain("value['dto'] = OBJECT_FOUND(stdClass)")
            ->toContain('AGENTS.md');
    } finally {
        PlainDataCacheGuard::drainForAssertion();
    }
});

// ---------------------------------------------------------------------------
// 検査 8-12: 値検査器そのもの (正負コントロールと境界)
// ---------------------------------------------------------------------------

test('検査 8: 値検査器が素データでない値を違反にする', function (): void {
    expect(PlainDataInspector::violations(new stdClass))->toBe(['value = OBJECT_FOUND(stdClass)']);
    expect(PlainDataInspector::violations(fn (): int => 1))->toBe(['value = OBJECT_FOUND(Closure)']);
    expect(PlainDataInspector::violations(Carbon::parse('2026-08-18')))
        ->toBe(['value = OBJECT_FOUND(Illuminate\Support\Carbon)']);
    expect(PlainDataInspector::violations(new Collection([1, 2])))
        ->toBe(['value = OBJECT_FOUND(Illuminate\Support\Collection)']);

    $open = fopen('php://memory', 'r');
    expect(PlainDataInspector::violations($open))->toBe(['value = RESOURCE_FOUND(stream)']);
    if (is_resource($open)) {
        fclose($open);
    }

    // 閉じた resource は is_resource() が false・is_scalar() も false =
    // どの許可分岐にも当たらない。fail-closed で UNKNOWN_TYPE になる。
    expect(PlainDataInspector::violations($open))->toBe(['value = UNKNOWN_TYPE(resource (closed))']);

    // 入れ子の中の違反もパス付きで出る
    expect(PlainDataInspector::violations(['a' => [0 => new stdClass]]))
        ->toBe(["value['a'][0] = OBJECT_FOUND(stdClass)"]);
});

test('検査 9: 値検査器は素データを違反にしない', function (): void {
    expect(PlainDataInspector::violations(['a' => 1, 'b' => 'x', 'c' => [true, null, 1.5]]))->toBe([]);
    expect(PlainDataInspector::violations(null))->toBe([]);
    expect(PlainDataInspector::violations([]))->toBe([]);
});

test('検査 10: 深さの境界 (32 は通り 33 は LIMIT_EXCEEDED)', function (): void {
    $build = function (int $depth): array {
        $value = ['leaf'];
        for ($i = 1; $i < $depth; $i++) {
            $value = [$value];
        }

        return $value;
    };

    expect(PlainDataInspector::violations($build(PlainDataInspector::MAX_DEPTH)))->toBe([]);
    expect(PlainDataInspector::violations($build(PlainDataInspector::MAX_DEPTH + 1)))
        ->toHaveCount(1)
        ->and(PlainDataInspector::violations($build(PlainDataInspector::MAX_DEPTH + 1))[0])
        ->toContain('LIMIT_EXCEEDED(depth)');
});

test('検査 11: ノード数の境界 (根を含む 10000 は通り 10001 は LIMIT_EXCEEDED)', function (): void {
    // 根 (配列そのもの) を 1 と数えるので、要素数は MAX_NODES - 1 まで通る。
    $ok = range(1, PlainDataInspector::MAX_NODES - 1);
    $ng = range(1, PlainDataInspector::MAX_NODES);

    expect(PlainDataInspector::violations($ok))->toBe([]);
    expect(PlainDataInspector::violations($ng))->toBe(['value[9999] = LIMIT_EXCEEDED(nodes)']);
});

test('検査 12: 自己参照配列は停止して LIMIT_EXCEEDED になる', function (): void {
    $value = ['a' => 1];
    $value['self'] = &$value;

    $violations = PlainDataInspector::violations($value);

    expect($violations)->not->toBe([]);
    expect(implode(' / ', $violations))->toContain('LIMIT_EXCEEDED');
});

// ---------------------------------------------------------------------------
// 検査 13-16: 境界迂回の hard fail
// ---------------------------------------------------------------------------

test('検査 13: tags() は境界迂回として落ちる', function (): void {
    CachePayloadViolationAssertions::expectViolation(
        fn () => GuardedBoundaryProbe::callTags(cachePayloadGuardedRepository()),
        ['BOUNDARY_BYPASS(tags)'],
    );
});

test('検査 14: setStore() は境界迂回として落ちる', function (): void {
    CachePayloadViolationAssertions::expectViolation(
        fn () => GuardedBoundaryProbe::callSetStore(cachePayloadGuardedRepository()),
        ['BOUNDARY_BYPASS(setStore)'],
    );
});

test('検査 15: macro は使用時点で境界迂回として落ちる', function (): void {
    CachePayloadViolationAssertions::expectViolation(
        fn () => GuardedBoundaryProbe::callMacro(cachePayloadGuardedRepository()),
        ['BOUNDARY_BYPASS(macro)', 'guardProbeMacro'],
    );
});

test('検査 15b: macro でない未知メソッド (store 素通し) も境界迂回として落ちる', function (): void {
    CachePayloadViolationAssertions::expectViolation(
        fn () => GuardedBoundaryProbe::callUnknownMethod(cachePayloadGuardedRepository()),
        ['BOUNDARY_BYPASS(storePassthrough)', 'guardProbeUnknownMethod'],
    );
});

test('検査 15c: 名指しで分類した排他 2 語彙の素通しは通る', function (string $method): void {
    // ★正のコントロール。`Illuminate\Cache\Repository` は lock() / restoreLock() を宣言せず、
    //   `Cache::lock(...)` は __call() の素通しで保管先へ届く (vendor 実読)。
    //   ここを塞ぐと role=lock-only の 6 ファイルが全滅する (S8 の計測で実測済み)。
    //   排他は payload を運ばないので名指しで分類し、それ以外の素通しは検査 15b が落とす。
    $lock = cachePayloadGuardLock(cachePayloadGuardedRepository(), $method);

    expect($lock)->toBeInstanceOf(Lock::class);
    expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
})->with(['lock', 'restoreLock']);

test('検査 16: flush が残存 macro を検出して既定へ戻す', function (): void {
    GuardedBoundaryProbe::registerMacroWithoutUsing();

    expect(fn () => PlainDataCacheGuard::flushAndFailIfStray())
        ->toThrow(RuntimeException::class, 'MACRO_REGISTERED');

    // flush の finally が reset() を通るので accumulator も macro も既定へ戻っている。
    expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
    expect(Repository::hasMacro('guardProbeResidualMacro'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// 検査 17-19: 握り潰しと結線の実体
// ---------------------------------------------------------------------------

test('検査 17: 起動 (bootstrap) 中の書き込みは provider が握り潰しても accumulator に残る', function (): void {
    // ★afterEach で flush が呼ばれること自体は CacheGuardWiringGateTest の担当。
    //   ここが固定するのは「結線がアプリ起動の前に入っているので起動中の書き込みも見える」ことである。
    $original = Facade::getFacadeApplication();

    $drained = IsolatedApplicationProbe::run(
        fn (ApplicationContract $app): array => PlainDataCacheGuard::drainForAssertion()
    );

    expect(implode(' / ', $drained))->toContain('OBJECT_FOUND(stdClass)');

    // 検査 22 (第 2 アプリの後始末) を同じ場所で固定する。
    expect(Facade::getFacadeApplication())->toBe($original);
    expect(Cache::store('array'))->toBeInstanceOf(PlainDataGuardedRepository::class);
    expect(app('cache'))->toBeInstanceOf(PlainDataGuardedCacheManager::class);
});

test('検査 18: アプリ側が握り潰しても accumulator に残る', function (): void {
    $cache = cachePayloadGuardedRepository();

    try {
        cachePayloadGuardWrite($cache, 'forever', 'guard-swallowed', new stdClass);
    } catch (Throwable) {
        // FxRateService と同じく握り潰す形を再現する
    }

    $drained = PlainDataCacheGuard::drainForAssertion();
    expect($drained)->toHaveCount(1);
    expect($drained[0])->toContain('OBJECT_FOUND(stdClass)');
});

test('検査 19: 独自 creator は CacheManager::repository() を通らない', function (): void {
    // ★これは trip-wire である。通るようになったら L4 で extend を 0 件 pin する根拠が変わる。
    $manager = app('cache');
    expect($manager)->toBeInstanceOf(PlainDataGuardedCacheManager::class);
    assert($manager instanceof PlainDataGuardedCacheManager);

    $resolved = GuardedBoundaryProbe::resolveCustomDriver($manager);

    expect($resolved)->toBeInstanceOf(Repository::class);
    expect($resolved)->not->toBeInstanceOf(PlainDataGuardedRepository::class);
});

// ---------------------------------------------------------------------------
// 検査 20-21: 後始末と空振り検知
// ---------------------------------------------------------------------------

test('検査 20: reset() は冪等で、drain 後は次テストへ漏れない', function (): void {
    $cache = cachePayloadGuardedRepository();
    cachePayloadGuardWrite($cache, 'put', 'guard-reset', ['ok']);

    PlainDataCacheGuard::reset();
    PlainDataCacheGuard::reset();

    expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
    expect(PlainDataCacheGuard::inspectedCount())->toBe(0);
});

test('検査 21: guard が実際に値を見ている (空振り検知)', function (): void {
    $before = PlainDataCacheGuard::inspectedCount();

    cachePayloadGuardWrite(cachePayloadGuardedRepository(), 'put', 'guard-inspected', ['ok']);

    expect(PlainDataCacheGuard::inspectedCount())->toBeGreaterThan($before);
});
