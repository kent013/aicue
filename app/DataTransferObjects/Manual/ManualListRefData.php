<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

/**
 * 一覧行が参照する {id, name} の対 (カテゴリ / 作成者)。
 * 「id と name は必ず揃う」ことを型で保つ (片方だけ null になる状態を作らない)。
 */
final readonly class ManualListRefData
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    /**
     * @return array{id: int, name: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
