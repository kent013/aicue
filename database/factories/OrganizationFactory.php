<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomTeam;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\PersonalPlanService;
use App\Support\Organization\AssignableOrganizationSlug;
use App\Support\Organization\OrganizationSlug;
use App\Support\Organization\OrganizationSlugReservedWords;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();
        $team = new Team;
        $team->name = 'org-'.Str::lower(Str::random(12));
        $team->display_name = $name;
        $team->save();

        return [
            'name' => $name,
            // slug は保存可能型を経由してのみ書く (家系裁定 AG-039。
            // OrganizationSlugWritePathTest が「この型を受ける経路以外は 0 件」を固定する)
            'slug' => AssignableOrganizationSlug::promote(
                OrganizationSlug::fromString('org-'.Str::lower(Str::random(12))),
                OrganizationSlugReservedWords::load(),
            )->value,
            'laratrust_team_id' => $team->id,
        ];
    }

    /**
     * 不変条件「どの Organization にも Default Team がちょうど 1 つ」を Factory でも担保する
     * (docs/default-team-pattern.md)。
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Organization $organization): void {
            if (! $organization->customTeams()->where('is_default', true)->exists()) {
                $defaultTeam = new CustomTeam(['name' => $organization->name]);
                $defaultTeam->organization()->associate($organization);
                $defaultTeam->forceFill(['is_default' => true]);
                $defaultTeam->save();
            }
        });
    }

    /**
     * 識別名を指定した組織 (衝突・改名のテストで**特定の識別名**が要るとき用)。
     *
     * ★指定値も**必ず保存可能型を通す** (`AssignableOrganizationSlug`)。テストが
     *   `forceFill(['slug' => …])` で型を迂回すると、保存経路 1 本の不変条件が
     *   テスト側から崩れる (`OrganizationSlugWritePathTest` が deny-by-default で拾う)。
     */
    public function withSlug(string $slug): static
    {
        $assignable = AssignableOrganizationSlug::promote(
            OrganizationSlug::fromString($slug),
            OrganizationSlugReservedWords::load(),
        );

        return $this->state(fn (): array => ['slug' => $assignable->value]);
    }

    /**
     * パーソナルプラン (free) 有効化済みの組織 (declarer は自己申告した user)。
     * PersonalPlanService::activate() の結果状態を Factory で再現する
     * (partial unique index `organizations_personal_free_declarer_unique` の対象になる)。
     */
    public function freePersonal(User $declarer): static
    {
        return $this->state(fn (): array => [
            'free_plan_code' => PersonalPlanService::FREE_PLAN_CODE,
            'free_plan_activated_at' => CarbonImmutable::now(),
            'personal_declared_at' => CarbonImmutable::now(),
            'personal_declared_by_user_id' => $declarer->getKey(),
        ]);
    }

    /**
     * declarer 不在の free personal 組織 (自己申告の記録より前から free だった既存組織)。
     * personal_declared_by_user_id が NULL のため partial unique index の対象外になる。
     */
    public function grandfathered(): static
    {
        return $this->state(fn (): array => [
            'free_plan_code' => PersonalPlanService::FREE_PLAN_CODE,
            'free_plan_activated_at' => CarbonImmutable::now(),
            'personal_declared_at' => null,
            'personal_declared_by_user_id' => null,
        ]);
    }

    /**
     * P9: 請求先連絡先を設定済みの組織。
     *
     * 両列とも $fillable 外 (PII) だが Factory の state は forceFill 相当で通る。
     * email は保存時と同じ正規化 (小文字化) を通す — blind index の検索契約と揃える。
     */
    public function withBillingContact(?string $email = null, ?string $name = null): static
    {
        return $this->state(fn (): array => [
            'billing_contact_email' => Str::lower(trim($email ?? fake()->unique()->safeEmail())),
            'billing_contact_name' => $name,
        ]);
    }

    /**
     * 決済事業者側 customer を持つ組織 (Cashier の `stripe_id` が入っている状態)。
     *
     * redaction 記録 (T141) は `stripe_id` の写しを残すため、記録対象の組織は
     * customer を持っていることが前提になる (持たない組織は fail-closed で記録不可)。
     */
    public function withStripeCustomer(?string $customerId = null): static
    {
        return $this->state(fn (): array => [
            'stripe_id' => $customerId ?? 'cus_'.Str::lower(Str::random(14)),
        ]);
    }

    /** 初回無償チケット付与済み (org 単位 1 回マーカーが立っている) 組織 */
    public function signupGranted(): static
    {
        return $this->state(fn (): array => [
            'signup_tickets_granted_at' => CarbonImmutable::now(),
        ]);
    }
}
