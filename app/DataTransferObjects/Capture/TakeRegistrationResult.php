<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use App\Models\Take;

/**
 * テイク登録の結果 (新規作成 201 / 冪等再送の既存返却 200 を Controller が判別する)。
 */
final readonly class TakeRegistrationResult
{
    private function __construct(
        public Take $take,
        public bool $wasCreated,
    ) {}

    public static function created(Take $take): self
    {
        return new self($take, wasCreated: true);
    }

    public static function existing(Take $take): self
    {
        return new self($take, wasCreated: false);
    }
}
