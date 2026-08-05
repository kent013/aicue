<?php

declare(strict_types=1);

use App\Enums\AdminConsoleRole;
use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Support\ResponseSignature;

/*
 * メンバー route の `{user}` 実在性オラクルの解消 (audit-cycle-2 High-1 横断 / T108 S3)。
 *
 * `{user}` はグローバル implicit binding のため、素のままだと
 *   - 不在 id            → SubstituteBindings が 404
 *   - 実在するが非メンバー → binding 成功 → 後段の短絡 (recent-auth 302 / 課金 302) or controller 404
 * と**分岐する** = users.id の実在オラクルになる。
 *
 * S2 の priority pin は「テナント guard を持つ route」にしか効かないため、
 *   - organizations.members.*  → Route::scopeBindings() で binding 段に閉じる (S3-a)
 *   - projects.members.destroy → implicit binding を外し controller で手動解決する (S3-b)
 * の 2 方式で個別に閉じる。
 *
 * 本テストの主張は一貫して「**2 ケースの応答が一致すること**」であり、
 * 特定の status を要求するものではない (未契約組織なら両方 302 が正解)。
 */

/** 不在の {user} id (18 桁 pattern 内・実在しない)。 */
const MREO_MISSING_USER_ID = '999999999';

/** 組織に属さない実在ユーザー。 */
function mreoOutsider(): User
{
    return User::factory()->create();
}

/**
 * 「実在するが非メンバーの id」と「不在 id」で応答が完全一致することを表明する。
 *
 * @param  callable(string): TestResponse  $request
 */
function mreoAssertNoOracle(callable $request, User $outsider, ?int $expectedStatus = null): void
{
    $existing = $request((string) $outsider->id);
    $missing = $request(MREO_MISSING_USER_ID);

    if ($expectedStatus !== null) {
        expect($existing->getStatusCode())->toBe($expectedStatus);
    }
    expect(ResponseSignature::of($existing))->toBe(
        ResponseSignature::of($missing),
        '実在の非メンバー id と 不在 id の応答が一致しない (存在オラクル)',
    );
}

// --- S3-a: organizations.members.* (scopeBindings) ---

test('stale recent-auth でも members.two-factor.reset の非メンバーと不在 id は同一 404', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $outsider = mreoOutsider();

    // recent-auth を満たさないセッション (302 で短絡する状態) で叩く
    mreoAssertNoOracle(
        fn (string $id) => $this->actingAs($owner)
            ->delete("/organizations/{$organization->slug}/members/{$id}/two-factor", ['reason' => 'ロックアウト救済のため']),
        $outsider,
        404,
    );
});

test('organizations.members.update の非メンバーと不在 id は同一 404', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $outsider = mreoOutsider();

    mreoAssertNoOracle(
        fn (string $id) => $this->actingAs($owner)
            ->patch("/organizations/{$organization->slug}/members/{$id}", ['role' => AdminConsoleRole::Admin->value]),
        $outsider,
        404,
    );
});

test('organizations.members.destroy の非メンバーと不在 id は同一 404', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $outsider = mreoOutsider();

    mreoAssertNoOracle(
        fn (string $id) => $this->actingAs($owner)
            ->delete("/organizations/{$organization->slug}/members/{$id}"),
        $outsider,
        404,
    );
});

test('organizations.members.update の正常系は従来どおり成功する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $this->actingAs($owner)
        ->patch("/organizations/{$organization->slug}/members/{$member->id}", [
            'role' => AdminConsoleRole::Admin->value,
        ])
        ->assertRedirect();

    expect($member->fresh()?->organizationRole($organization))->toBe(OrganizationRole::Admin);
});

test('organizations.members.destroy の正常系は従来どおり成功する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $this->actingAs($owner)
        ->delete("/organizations/{$organization->slug}/members/{$member->id}")
        ->assertRedirect();

    expect($organization->users()->whereKey($member->id)->exists())->toBeFalse();
});

test('members.two-factor.reset の正常系 (メンバー + fresh recent-auth) は従来どおり通る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill([
        'two_factor_secret' => encrypt('test-totp-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-one'], JSON_THROW_ON_ERROR)),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->delete("/organizations/{$organization->slug}/members/{$member->id}/two-factor", [
            'reason' => 'ロックアウト救済のため解除する',
        ])
        ->assertRedirect();

    expect($member->fresh()?->two_factor_confirmed_at)->toBeNull();
});

// --- S3-b: projects.members.destroy (手動解決) ---

test('未契約組織でも projects.members.destroy の非メンバーと不在 id は完全同一応答', function (): void {
    // 未契約組織 = 課金ゲートが 302 で短絡する状態。両ケースとも 302 に落ちれば分岐しない
    [$organization, $owner] = createOrganizationWithOwner('未契約組織', grandfatherFreePlan: false);
    $project = Project::factory()->forOrganization($organization)->create();
    $outsider = mreoOutsider();

    mreoAssertNoOracle(
        fn (string $id) => $this->actingAs($owner)
            ->delete("/projects/{$project->id}/members/{$id}"),
        $outsider,
        302,
    );
});

test('契約済み組織では projects.members.destroy の非メンバーと不在 id はともに 404', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $outsider = mreoOutsider();

    mreoAssertNoOracle(
        fn (string $id) => $this->actingAs($owner)
            ->delete("/projects/{$project->id}/members/{$id}"),
        $outsider,
        404,
    );
});

test('projects.members.destroy の正常系 (明示メンバーの削除) は従来どおり成功する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $member = attachOrganizationMember($organization);
    attachProjectMember($project, $member, ProjectRole::Member);

    $this->actingAs($owner)
        ->delete("/projects/{$project->id}/members/{$member->id}")
        ->assertRedirect();

    expect($project->members()->whereKey($member->id)->exists())->toBeFalse();
});

test('projects.members.destroy に暗黙メンバー (pivot 無しの org admin) を指定しても従来どおり成功応答', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);

    // pivot が無い = detach は no-op。挙動非退行 (404 に変わっていない) の確認
    $this->actingAs($owner)
        ->delete("/projects/{$project->id}/members/{$admin->id}")
        ->assertRedirect();
});
