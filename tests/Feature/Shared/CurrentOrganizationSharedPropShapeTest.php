<?php

declare(strict_types=1);

use App\DataTransferObjects\Organizations\CurrentOrganizationData;
use App\Enums\OrganizationRole;
use App\Models\User;

/*
 * 共有 prop `currentOrganization` の**キー集合と各値の型**を固定する (家系裁定 AG-037)。
 *
 * キーだけを比べると `role: string|null` が `string` に化けても緑のままになる。
 * TypeScript 側 (`resources/js/lib/shared-props.ts` の `CurrentOrganization`) と
 * 突き合わせるための正本は **PHP の DTO** で、ここでは
 * 「DTO のプロパティ型」と「toArray() が返す値の型」が一致することを固定する。
 */

test('toArray() のキー集合は DTO のプロパティと 1:1', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $data = CurrentOrganizationData::forMember($owner, $organization);

    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(CurrentOrganizationData::class))->getProperties(ReflectionProperty::IS_PUBLIC),
    );
    sort($properties);

    $keys = array_keys($data->toArray());
    sort($keys);

    expect($keys)->toBe($properties);
});

test('各値の型は DTO のプロパティ型と一致する (nullable も含めて)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $array = CurrentOrganizationData::forMember($owner, $organization)->toArray();

    foreach ((new ReflectionClass(CurrentOrganizationData::class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        $type = $property->getType();
        expect($type)->toBeInstanceOf(ReflectionNamedType::class);
        /** @var ReflectionNamedType $type */
        $value = $array[$property->getName()];

        if ($value === null) {
            expect($type->allowsNull())->toBeTrue("{$property->getName()} が null を許さない型なのに null");

            continue;
        }
        expect(get_debug_type($value))->toBe($type->getName());
    }
});

test('組織 route 以外では共有 prop が必ず null になる', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/settings')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('currentOrganization', null));
});

test('組織 route では URL 上の組織が出る (保持列由来ではない)', function (): void {
    [$first, $user] = createOrganizationWithOwner('あ組織');
    [$second] = createOrganizationWithOwner('い組織');
    $second->users()->attach($user);
    $user->addRole(OrganizationRole::Member->value, $second->laratrust_team_id);

    $this->actingAs($user)->get("/organizations/{$second->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('currentOrganization.slug', $second->slug)
            ->etc());

    $this->actingAs($user)->get("/organizations/{$first->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('currentOrganization.slug', $first->slug)
            ->etc());
});

test('role が null の状況でも型契約を満たす', function (): void {
    [$organization] = createOrganizationWithOwner();
    $user = User::factory()->create();
    $organization->users()->attach($user);   // role を付与しない異常行

    $array = CurrentOrganizationData::forMember($user, $organization)->toArray();

    expect($array['role'])->toBeNull();
    expect($array['id'])->toBeInt();
    expect($array['slug'])->toBeString();
});
