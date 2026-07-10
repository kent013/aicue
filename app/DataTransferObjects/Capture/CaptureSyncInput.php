<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Capture;

use Webmozart\Assert\Assert;

/**
 * sync リクエストの検証済み入力 (概念設計 D8。Service は連想配列を受けない)。
 */
final readonly class CaptureSyncInput
{
    /**
     * @param  list<ClientTakeFingerprint>  $fingerprints
     */
    public function __construct(
        public array $fingerprints,
    ) {}

    /**
     * FormRequest の validated 配列 → 型確定 (mixed を Assert で確定する唯一の境界)。
     *
     * @param  array<array-key, mixed>  $takes
     */
    public static function fromValidated(array $takes): self
    {
        $fingerprints = [];
        foreach ($takes as $row) {
            Assert::isArray($row);
            Assert::keyExists($row, 'cut');
            Assert::integer($row['cut']);
            Assert::keyExists($row, 'client_take_id');
            Assert::stringNotEmpty($row['client_take_id']);
            $fingerprints[] = new ClientTakeFingerprint(
                cutId: $row['cut'],
                clientTakeId: strtoupper($row['client_take_id']),
            );
        }

        return new self($fingerprints);
    }
}
