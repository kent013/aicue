<?php

declare(strict_types=1);

use App\DataTransferObjects\Recovery\StreamSweepResultDto;
use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;

test('count() は未登録の結果の種類に 0 を返す', function (): void {
    $result = new StreamSweepResultDto(
        stream: RecoveryStream::AnalysisJob,
        applied: true,
        candidates: 3,
        outcomes: [RecoveryOutcome::Recovered->value => 2],
        failures: 1,
        limitReached: false,
    );

    expect($result->count(RecoveryOutcome::Recovered))->toBe(2);
    expect($result->count(RecoveryOutcome::Skipped))->toBe(0);
    expect($result->count(RecoveryOutcome::Deferred))->toBe(0);
});

test('limitReached は構築値をそのまま保持する (打ち切りの有無を推測しない)', function (): void {
    $reached = new StreamSweepResultDto(
        stream: RecoveryStream::UploadReservation,
        applied: false,
        candidates: 500,
        outcomes: [],
        failures: 0,
        limitReached: true,
    );
    $notReached = new StreamSweepResultDto(
        stream: RecoveryStream::UploadReservation,
        applied: false,
        candidates: 500,
        outcomes: [],
        failures: 0,
        limitReached: false,
    );

    expect($reached->limitReached)->toBeTrue();
    expect($notReached->limitReached)->toBeFalse();
});
