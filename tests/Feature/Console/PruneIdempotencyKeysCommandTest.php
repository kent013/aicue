<?php

declare(strict_types=1);

use App\Models\IdempotencyKey;
use App\Models\McpIdempotencyKey;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Mockery\MockInterface;

/*
 * 冪等キーの保持期間 purge (idempotency:prune)。
 *
 * lazy delete (claim 時の期限切れ行削除) は「再送されたキー」しか回収しないため、
 * 二度と再送されなかったキーを日次で物理削除する。
 *
 * 報告契約: processing のまま期限切れになった行 (= 確定できなかった claim) が
 * 1 件でもあれば report() する。載せるのは件数のみ (キー値・body は載せない)。
 */

/** report() 経路 (運用アラート) を観測する spy を差し込む */
function spyOnPruneExceptionHandler(): MockInterface
{
    $handler = Mockery::spy(ExceptionHandler::class);
    app()->instance(ExceptionHandler::class, $handler);

    return $handler;
}

test('期限切れの REST 冪等キーを state 横断で削除する', function (): void {
    IdempotencyKey::factory()->expired()->create(['key' => 'expired-completed']);
    IdempotencyKey::factory()->processing()->expired()->create(['key' => 'expired-processing']);
    IdempotencyKey::factory()->indeterminate()->expired()->create(['key' => 'expired-indeterminate']);
    IdempotencyKey::factory()->create(['key' => 'alive']);

    $this->artisan('idempotency:prune')->assertExitCode(0);

    expect(IdempotencyKey::query()->pluck('key')->all())->toBe(['alive']);
});

test('期限切れの MCP 冪等キーも削除する', function (): void {
    // idempotency_key は uuid 列のため値は UUID で作る
    $alive = McpIdempotencyKey::factory()->create();
    McpIdempotencyKey::factory()->expired()->create();

    $this->artisan('idempotency:prune')->assertExitCode(0);

    expect(McpIdempotencyKey::query()->pluck('idempotency_key')->all())
        ->toBe([$alive->idempotency_key]);
});

test('未期限の行は 1 件も削除しない (負のコントロール)', function (): void {
    // cutoff 条件が抜けたら全消しになり、このテストが赤くなる
    IdempotencyKey::factory()->create(['key' => 'alive-completed']);
    IdempotencyKey::factory()->processing()->create(['key' => 'alive-processing']);
    IdempotencyKey::factory()->indeterminate()->create(['key' => 'alive-indeterminate']);
    McpIdempotencyKey::factory()->create();

    $this->artisan('idempotency:prune')->assertExitCode(0);

    expect(IdempotencyKey::query()->count())->toBe(3);
    expect(McpIdempotencyKey::query()->count())->toBe(1);
});

test('processing のまま期限切れになった行があれば report する', function (): void {
    IdempotencyKey::factory()->processing()->expired()->create();
    $handler = spyOnPruneExceptionHandler();

    $this->artisan('idempotency:prune')->assertExitCode(0);

    $handler->shouldHaveReceived('report')->once();
});

test('processing の期限切れが 0 件なら report しない', function (): void {
    IdempotencyKey::factory()->expired()->create();
    IdempotencyKey::factory()->indeterminate()->expired()->create();
    IdempotencyKey::factory()->processing()->create(); // 未期限の processing は対象外
    $handler = spyOnPruneExceptionHandler();

    $this->artisan('idempotency:prune')->assertExitCode(0);

    $handler->shouldNotHaveReceived('report');
});

test('削除件数を state 別に出力する', function (): void {
    IdempotencyKey::factory()->expired()->create();
    IdempotencyKey::factory()->indeterminate()->expired()->create();

    $this->artisan('idempotency:prune')
        ->expectsOutputToContain('rest completed: 1 件削除')
        ->expectsOutputToContain('rest indeterminate: 1 件削除')
        ->expectsOutputToContain('rest processing: 0 件削除')
        ->expectsOutputToContain('mcp: 0 件削除')
        ->assertExitCode(0);
});
