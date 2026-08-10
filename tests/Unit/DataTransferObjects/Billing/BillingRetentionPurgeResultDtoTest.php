<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
use App\Enums\Billing\BillingRetentionTarget;

/*
 * 保持期間 purge の結果 DTO の判定規則。
 *
 * ★`isPublicationReady()` は**規約文面を公開してよいか**の判定であり、
 *   「安全のため残した (failClosed)」を免除しない。免除すると、規約が宣言した年数を
 *   超えた記録が残ったまま「準拠した」と言えてしまう。
 */

/** 指定の件数だけを差し替えた結果を作る。 */
function billingRetentionResult(
    int $failClosed = 0,
    int $unexpectedFailures = 0,
    int $expiredRemaining = 0,
): BillingRetentionPurgeResultDto {
    return new BillingRetentionPurgeResultDto(
        target: BillingRetentionTarget::Subscription,
        candidates: 10,
        processed: 10,
        failClosed: $failClosed,
        unexpectedFailures: $unexpectedFailures,
        expiredRemaining: $expiredRemaining,
    );
}

test('すべて 0 なら公開してよい', function (): void {
    expect(billingRetentionResult()->isPublicationReady())->toBeTrue();
});

test('fail-closed が残っていれば公開できない (安全に残した = 規約準拠ではない)', function (): void {
    $result = billingRetentionResult(failClosed: 1);

    expect($result->hasFailClosedRecords())->toBeTrue();
    expect($result->isPublicationReady())->toBeFalse();
});

test('想定外失敗があれば公開できない', function (): void {
    $result = billingRetentionResult(unexpectedFailures: 1);

    expect($result->hasUnexpectedFailures())->toBeTrue();
    expect($result->isPublicationReady())->toBeFalse();
});

test('期限超過が残っていれば公開できない', function (): void {
    expect(billingRetentionResult(expiredRemaining: 1)->isPublicationReady())->toBeFalse();
});

test('dry-run の結果は「何も処理せず候補がそのまま残っている」形になる', function (): void {
    $result = BillingRetentionPurgeResultDto::dryRun(
        target: BillingRetentionTarget::StripeWebhookEvent,
        candidates: 3,
        failClosed: 2,
    );

    expect($result->target)->toBe(BillingRetentionTarget::StripeWebhookEvent);
    expect($result->candidates)->toBe(3);
    expect($result->processed)->toBe(0);
    expect($result->failClosed)->toBe(2);
    expect($result->unexpectedFailures)->toBe(0);
    expect($result->expiredRemaining)->toBe(3); // 消していないのだから残っている
    expect($result->isPublicationReady())->toBeFalse();
});
