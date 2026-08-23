<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Organization;
use App\Models\OrganizationSlugRename;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrganizationSlugRename>
 */
class OrganizationSlugRenameFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'renamed_by_user_id' => User::factory(),
            'from_slug' => 'org-'.Str::lower(Str::random(12)),
            'to_slug' => 'org-'.Str::lower(Str::random(12)),
            'renamed_at' => CarbonImmutable::now(),
        ];
    }

    /** 窓の判定を組み立てるための時刻指定 state。 */
    public function renamedAt(CarbonImmutable $at): static
    {
        return $this->state(fn (): array => ['renamed_at' => $at]);
    }
}
