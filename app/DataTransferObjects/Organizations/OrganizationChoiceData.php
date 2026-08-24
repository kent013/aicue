<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Organizations;

use App\Models\Organization;
use Illuminate\Support\Collection;

/**
 * 組織を選ぶ画面へ渡す 1 件分 (Eloquent collection を素で Inertia へ渡さない)。
 *
 * ★URL の組み立てに使うのは **slug の文字列**である
 *   (モデルを route helper へ渡すと `getRouteKeyName()` = id により URL に id が入る)。
 */
final readonly class OrganizationChoiceData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
    ) {}

    public static function fromModel(Organization $organization): self
    {
        return new self(
            id: $organization->id,
            name: $organization->name,
            slug: $organization->slug,
        );
    }

    /**
     * @param  Collection<int, Organization>  $organizations
     * @return list<array{id: int, name: string, slug: string}>
     */
    public static function collect(Collection $organizations): array
    {
        return array_values($organizations
            ->map(fn (Organization $organization): array => self::fromModel($organization)->toArray())
            ->all());
    }

    /**
     * @return array{id: int, name: string, slug: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
        ];
    }
}
