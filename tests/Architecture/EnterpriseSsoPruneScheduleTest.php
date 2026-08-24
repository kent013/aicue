<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
 * 一時状態の掃除が **scheduler へ日次で登録されている** ことの固定 (F3)。
 *
 * ★コマンドが在るだけでは日次の掃除は成立しない。**登録そのもの**を固定する
 *   (登録を消しても掃除コマンドのテストは緑のままなので、それだけでは気付けない)。
 */

/** @return list<string> scheduler に登録された全コマンドの式 */
function enterpriseSsoScheduledCommands(): array
{
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    return array_map(
        static fn (Event $event): string => (string) $event->command,
        $schedule->events(),
    );
}

test('掃除コマンド 2 本が scheduler へ登録されている', function (string $command): void {
    $registered = array_values(array_filter(
        enterpriseSsoScheduledCommands(),
        static fn (string $expression): bool => str_contains($expression, $command),
    ));

    expect($registered)->toHaveCount(1, "{$command} が scheduler に 1 本だけ登録されていること");
})->with([
    'enterprise-sso:prune-login-attempts',
    'auth:prune-email-promotions',
]);

test('掃除コマンド 2 本が日次で走る', function (string $command): void {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $events = array_values(array_filter(
        $schedule->events(),
        static fn (Event $event): bool => str_contains((string) $event->command, $command),
    ));

    expect($events)->toHaveCount(1);
    // 日次 (`->daily()`) は 0 0 * * * である
    expect($events[0]->expression)->toBe('0 0 * * *');
})->with([
    'enterprise-sso:prune-login-attempts',
    'auth:prune-email-promotions',
]);

test('掃除コマンド 2 本が 1 台だけで走る (多重起動で二重に消さない)', function (string $command): void {
    /** @var Schedule $schedule */
    $schedule = app(Schedule::class);

    $events = array_values(array_filter(
        $schedule->events(),
        static fn (Event $event): bool => str_contains((string) $event->command, $command),
    ));

    expect($events[0]->onOneServer)->toBeTrue();
})->with([
    'enterprise-sso:prune-login-attempts',
    'auth:prune-email-promotions',
]);

test('走査が空振りしていない (scheduler に 1 件以上の登録がある)', function (): void {
    expect(count(enterpriseSsoScheduledCommands()))->toBeGreaterThan(0);
});
