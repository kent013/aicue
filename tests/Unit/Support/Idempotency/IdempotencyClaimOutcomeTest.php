<?php

declare(strict_types=1);

use App\Enums\Idempotency\IdempotencyClaimStatus;
use App\Models\IdempotencyKey;
use App\Support\Idempotency\IdempotencyClaimOutcome;

/*
 * claim 結果の DTO。status と row の無効な組合せを**構築できなくする**境界。
 * named constructor 以外の生成経路を作らない (呼び出し側に null 判定を書かせない)。
 */

test('claimed() は row を持たない (rowOrFail は失敗する)', function (): void {
    $outcome = IdempotencyClaimOutcome::claimed();

    expect($outcome->status)->toBe(IdempotencyClaimStatus::Claimed);

    $outcome->rowOrFail();
})->throws(InvalidArgumentException::class);

test('row を伴う named constructor は status と row の組合せを固定する', function (): void {
    $row = new IdempotencyKey;

    $cases = [
        [IdempotencyClaimOutcome::replay($row), IdempotencyClaimStatus::Replay],
        [IdempotencyClaimOutcome::conflict($row), IdempotencyClaimStatus::Conflict],
        [IdempotencyClaimOutcome::inProgress($row), IdempotencyClaimStatus::InProgress],
        [IdempotencyClaimOutcome::indeterminate($row), IdempotencyClaimStatus::Indeterminate],
    ];

    foreach ($cases as [$outcome, $expectedStatus]) {
        expect($outcome->status)->toBe($expectedStatus);
        expect($outcome->rowOrFail())->toBe($row);
    }
});

test('__construct は private である (named constructor 以外で作れない)', function (): void {
    $constructor = (new ReflectionClass(IdempotencyClaimOutcome::class))->getConstructor();

    expect($constructor)->not->toBeNull();
    expect($constructor?->isPrivate())->toBeTrue();
});
