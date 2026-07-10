<?php

declare(strict_types=1);

use App\Jobs\Capture\DeleteTakeObjectsJob;
use App\Services\Capture\TakeObjectStorage;

/*
 * DeleteTakeObjectsJob (施策9): media queue の S3 オブジェクト削除 (payload は S3 キーの list のみ)。
 */

test('handle は paths 分だけ delete を呼ぶ', function (): void {
    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('delete')->once()->with('takes/a.mp4');
    $mock->shouldReceive('delete')->once()->with('takes/b.mp4');

    (new DeleteTakeObjectsJob(['takes/a.mp4', 'takes/b.mp4']))->handle($mock);
});

test('database-media connection に載る (運用契約)', function (): void {
    $job = new DeleteTakeObjectsJob(['takes/a.mp4']);

    expect($job->connection)->toBe('database-media');
    expect($job->tries)->toBe(3);
});
