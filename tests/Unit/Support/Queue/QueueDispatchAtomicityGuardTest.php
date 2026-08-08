<?php

declare(strict_types=1);

use App\DataTransferObjects\Support\QueueDispatchAtomicityViolation;
use App\Enums\Support\QueueAtomicityRule;
use App\Support\QueueDispatchAtomicityGuard;

/*
|--------------------------------------------------------------------------
| QueueDispatchAtomicityGuard の規則 R1〜R5 (AG-114 確定 2)
|--------------------------------------------------------------------------
|
| guard は config の値だけを見る純関数である。ここでは `config()->set()` で構成を
| 組み替え、規則ごとに「違反として報告されること」「例外ではなく違反を返すこと」を固定する。
|
| ★ 本番相当の構成 (baseline) を毎テストの起点にする。テストレーンは
|   phpunit.xml が QUEUE_CONNECTION=sync を force しているため、実行時 config を
|   そのまま起点にすると R1〜R3 が空振りする。
*/

/**
 * 本番相当のキュー構成を config へ流し込む (baseline)。
 */
function guardBaselineConfig(): void
{
    config()->set('database.default', 'pgsql');
    config()->set('queue.default', 'database');
    config()->set('queue.connections', [
        'sync' => ['driver' => 'sync', 'after_commit' => true],
        'database' => ['driver' => 'database', 'connection' => null, 'after_commit' => false],
        'database-analysis' => ['driver' => 'database', 'connection' => null, 'after_commit' => false],
        'database-render' => ['driver' => 'database', 'connection' => null, 'after_commit' => false],
        'database-media' => ['driver' => 'database', 'connection' => null, 'after_commit' => false],
    ]);
}

/**
 * 違反の規則 (enum) だけを取り出す。
 *
 * @return list<QueueAtomicityRule>
 */
function guardViolationRules(bool $isProduction = false): array
{
    return array_values(array_map(
        static fn (QueueDispatchAtomicityViolation $violation): QueueAtomicityRule => $violation->rule,
        (new QueueDispatchAtomicityGuard)->violations($isProduction),
    ));
}

beforeEach(function (): void {
    guardBaselineConfig();
});

test('既定構成では違反が 0 件である', function (): void {
    expect(guardViolationRules())->toBe([]);
    expect(guardViolationRules(isProduction: true))->toBe([]);
});

test('R1: 参照接続の driver が redis なら違反する', function (): void {
    config()->set('queue.connections.database.driver', 'redis');

    expect(guardViolationRules())->toContain(QueueAtomicityRule::DatabaseDriver);
});

test('R2: 参照接続の connection が業務 DB と異なれば違反する', function (): void {
    config()->set('queue.connections.database-render.connection', 'pgsql_queue');

    expect(guardViolationRules())->toContain(QueueAtomicityRule::SameDatabaseConnection);
});

test('R3: 参照接続の after_commit が true なら違反する', function (): void {
    config()->set('queue.connections.database.after_commit', true);

    expect(guardViolationRules())->toContain(QueueAtomicityRule::AfterCommitDisabled);
});

test('R3: 参照接続に after_commit キーが無ければ違反する (fail-closed)', function (): void {
    config()->set('queue.connections.database-media', ['driver' => 'database', 'connection' => null]);

    expect(guardViolationRules())->toContain(QueueAtomicityRule::AfterCommitDisabled);
});

test('R4: sync の after_commit が true でなければ違反する', function (): void {
    config()->set('queue.connections.sync', ['driver' => 'sync']);

    expect(guardViolationRules())->toContain(QueueAtomicityRule::SyncAfterCommitEnabled);
});

test('R4: sync 接続の定義自体が無ければ違反する', function (): void {
    $connections = config('queue.connections');
    expect($connections)->toBeArray();
    unset($connections['sync']);
    config()->set('queue.connections', $connections);

    expect(guardViolationRules())->toContain(QueueAtomicityRule::SyncAfterCommitEnabled);
});

test('R5: production で既定接続が sync なら違反する', function (): void {
    config()->set('queue.default', 'sync');

    expect(guardViolationRules(isProduction: true))->toContain(QueueAtomicityRule::ProductionAsyncDriver);
});

test('R5: production で既定接続が redis なら違反する', function (): void {
    config()->set('queue.connections.redis', ['driver' => 'redis', 'after_commit' => false]);
    config()->set('queue.default', 'redis');

    expect(guardViolationRules(isProduction: true))->toContain(QueueAtomicityRule::ProductionAsyncDriver);
});

test('R5: production で既定接続が未定義なら違反する', function (): void {
    config()->set('queue.default', 'nonexistent');

    expect(guardViolationRules(isProduction: true))->toContain(QueueAtomicityRule::ProductionAsyncDriver);
});

test('R5: production で既定接続が database なら違反しない', function (): void {
    expect(guardViolationRules(isProduction: true))->not->toContain(QueueAtomicityRule::ProductionAsyncDriver);
});

test('R5 は非 production では評価されない (テストレーンの sync が通る)', function (): void {
    config()->set('queue.default', 'sync');

    expect(guardViolationRules())->toBe([]);
});

test('pin 済み 3 接続はいずれも検査対象に入る (既定接続だけを見ていない)', function (): void {
    config()->set('queue.default', 'sync'); // 既定接続を検査対象から外す

    foreach (QueueDispatchAtomicityGuard::PINNED_CONNECTIONS as $pinned) {
        guardBaselineConfig();
        config()->set('queue.default', 'sync');
        config()->set('queue.connections.'.$pinned.'.after_commit', true);

        expect(guardViolationRules())->toContain(QueueAtomicityRule::AfterCommitDisabled);
        expect(array_column((new QueueDispatchAtomicityGuard)->violations(false), 'connection'))
            ->toContain($pinned);
    }
});

test('queue.connections が配列でない場合は違反として報告する (例外を投げない)', function (): void {
    config()->set('queue.connections', 'not-an-array');

    expect(guardViolationRules())->toBe([QueueAtomicityRule::ConfigUnreadable]);
});

test('queue.default が非 string / 空文字 / 未定義なら違反する (fail-closed)', function (): void {
    foreach ([123, '', null] as $bad) {
        guardBaselineConfig();
        config()->set('queue.default', $bad);

        expect(guardViolationRules())->toContain(QueueAtomicityRule::ConfigUnreadable);
    }
});

test('database.default が非空 string でなければ違反する (fail-closed)', function (): void {
    $original = config('database.default');

    try {
        foreach (['', 123, null] as $bad) {
            config()->set('database.default', $bad);

            expect(guardViolationRules())->toContain(QueueAtomicityRule::ConfigUnreadable);
        }
    } finally {
        // ★ database.default を壊したままにすると RefreshDatabase の後片付けが落ちる
        config()->set('database.default', $original);
    }
});

test('参照接続の定義が欠落 / 非配列なら違反する (fail-closed)', function (): void {
    config()->set('queue.connections.database-analysis', 'nope');

    expect(guardViolationRules())->toContain(QueueAtomicityRule::DatabaseDriver);
});

test('参照接続の connection が null なら許可される (既定 DB 接続)', function (): void {
    config()->set('queue.connections.database.connection', null);

    expect(guardViolationRules())->not->toContain(QueueAtomicityRule::SameDatabaseConnection);
});

test('参照接続の connection が非 string / 空文字なら違反する (fail-closed)', function (): void {
    foreach ([123, '', ['pgsql']] as $bad) {
        guardBaselineConfig();
        config()->set('queue.connections.database.connection', $bad);

        expect(guardViolationRules())->toContain(QueueAtomicityRule::SameDatabaseConnection);
    }
});

test('sync 接続が非配列なら違反する (fail-closed)', function (): void {
    config()->set('queue.connections.sync', 'sync');

    expect(guardViolationRules())->toContain(QueueAtomicityRule::SyncAfterCommitEnabled);
});

test("sync 接続の driver が欠落 / 非 string / 'database' なら違反する", function (): void {
    foreach ([null, 123, 'database'] as $bad) {
        guardBaselineConfig();
        config()->set('queue.connections.sync', ['driver' => $bad, 'after_commit' => true]);

        expect(guardViolationRules())->toContain(QueueAtomicityRule::SyncAfterCommitEnabled);
    }
});

test('pin 済み接続 (database-analysis) の driver が sync なら R1 違反になる', function (): void {
    // ★ sync の除外を driver ではなく**接続名**で行っていることの固定。
    //   driver で除外する実装だと、この構成が R1〜R3 を全部 skip して通ってしまう。
    config()->set('queue.connections.database-analysis', ['driver' => 'sync', 'after_commit' => true]);

    expect(guardViolationRules())->toContain(QueueAtomicityRule::DatabaseDriver);
});

test('enforce() は違反があれば RuntimeException を投げ、無ければ何も起きない', function (): void {
    (new QueueDispatchAtomicityGuard)->enforce(false);

    config()->set('queue.connections.database.after_commit', true);

    expect(fn () => (new QueueDispatchAtomicityGuard)->enforce(false))
        ->toThrow(RuntimeException::class, 'Queue dispatch atomicity violations');
});
