<?php

declare(strict_types=1);

namespace App\Services\Storage\Fakes;

use RuntimeException;

/**
 * fake storage の PUT で絶対容量上限 (capture.max_take_bytes) を超過した。
 * controller が catch して 413 に写像する。
 */
final class FakeStorageOverCapacity extends RuntimeException
{
    public function __construct(public readonly int $maxBytes)
    {
        parent::__construct("fake storage: アップロードサイズが上限 ({$maxBytes} bytes) を超えています");
    }
}
