<?php

declare(strict_types=1);

use App\Enums\Recovery\RecoveryStream;

/*
 * 滞留回収の系列 enum の値契約 (DB に触れない)。
 */

test('全 case が実行間隔を返す (match の網羅。case 追加時に落ちる)', function (): void {
    foreach (RecoveryStream::cases() as $stream) {
        expect($stream->cadenceMinutes())->toBeGreaterThan(0, $stream->value);
    }
});

test('実行間隔は 60 の約数である (毎時同じ間隔で回る前提)', function (): void {
    foreach (RecoveryStream::cases() as $stream) {
        expect(60 % $stream->cadenceMinutes())->toBe(0,
            $stream->value.' の実行間隔が 60 の約数でないと、cron の刻み表記が毎時同じ間隔にならない');
    }
});

test('多重起動抑止の有効期限は実行間隔の 2 倍である', function (): void {
    foreach (RecoveryStream::cases() as $stream) {
        expect($stream->overlapExpiryMinutes())->toBe($stream->cadenceMinutes() * 2, $stream->value);
    }
});

test('現行の実行間隔を保存している (5 分 4 本 / 10 分 1 本)', function (): void {
    expect(RecoveryStream::AnalysisJob->cadenceMinutes())->toBe(5);
    expect(RecoveryStream::RenderJob->cadenceMinutes())->toBe(5);
    expect(RecoveryStream::TicketReservation->cadenceMinutes())->toBe(5);
    expect(RecoveryStream::WebhookEvent->cadenceMinutes())->toBe(5);
    expect(RecoveryStream::UploadReservation->cadenceMinutes())->toBe(10);
});
