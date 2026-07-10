<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

/**
 * テイク更新 (PATCH) の検証済み入力。
 * comment は「キー送信の有無」で更新要否を区別する (null 送信 = コメント削除)。
 * position は cut 内 0 始まりの挿入位置 (sort_order はサーバ再採番)。
 */
final readonly class CaptureTakeUpdateInput
{
    public function __construct(
        public bool $hasComment,
        public ?string $comment,
        public ?int $position,
    ) {}
}
