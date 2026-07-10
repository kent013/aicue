<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use Carbon\CarbonImmutable;

/**
 * テイク登録リクエストの検証済み入力 (Service は連想配列を受けない。概念設計 D12)。
 * duration_ms / captured_at はクライアント申告の表示用メタデータ (課金・検証に使わない)。
 */
final readonly class TakeRegistrationInput
{
    public function __construct(
        public string $ticket,
        public string $clientTakeId,
        public ?int $durationMs,
        public ?CarbonImmutable $capturedAt,
    ) {}
}
