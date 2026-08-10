<?php

declare(strict_types=1);

use App\Support\BughuntDatabaseGuard;

/*
 * bug-hunt DB 名判定の SSOT (施策 4)。判定表は DB 接続なしに固定できる純関数で持つ。
 *
 * regex は並列 cap (4) と**同期させない**。狭めると残留 bug_hunt_5 を bughunt DB と
 * 認識できず「dev DB 扱い」になってしまう (= 検出漏れ)。
 */

it('bug-hunt の DB 名を検出する', function (string $name): void {
    expect(BughuntDatabaseGuard::matches($name))->toBeTrue();
})->with(['bug_hunt', 'bug_hunt_1', 'bug_hunt_4', 'bug_hunt_8']);

it('bug-hunt でない DB 名を検出しない', function (string $name): void {
    expect(BughuntDatabaseGuard::matches($name))->toBeFalse();
})->with(['bug_hunt_9', 'bug_hunt_', 'bug_hunt_0', 'aicue', 'app', 'bug_hunt_1x', 'xbug_hunt', '']);
