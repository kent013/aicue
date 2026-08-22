<?php

declare(strict_types=1);

use App\Enums\QuotaKey;
use App\Models\Project;
use App\Services\Billing\QuotaService;

/*
 * 多次元 Quota。既定値は config/quota.php の plan_code → limits map
 * (plan_code null は fallback_plan = free)、organization_quotas.limits が key 単位で上書き。
 * max_projects は ProjectService::createProject に配線済み (超過は back + error flash)。
 */

test('plan_code 未設定の組織には fallback_plan (free) の既定 limits が効く', function (): void {
    [$organization] = createOrganizationWithOwner();

    $limits = app(QuotaService::class)->limits($organization);

    expect($limits)->toBe(['max_projects' => 1, 'max_members' => 3, 'max_storage_bytes' => 1024 * 1024 * 1024]);
});

test('plan_code を持つ組織にはそのプランの limits が効く', function (): void {
    [$organization] = createOrganizationWithOwner();
    $organization->plan_code = 'standard';
    $organization->save();

    $limits = app(QuotaService::class)->limits($organization);

    expect($limits)->toBe(['max_projects' => 10, 'max_members' => 10, 'max_storage_bytes' => 50 * 1024 * 1024 * 1024]);
});

test('organization_quotas の override がプラン既定値より優先される (key 単位)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $organization->quota()->create(['limits' => ['max_projects' => 5]]);

    $limits = app(QuotaService::class)->limits($organization->fresh());

    // max_projects は override、max_members / max_storage_bytes はプラン既定値のまま
    expect($limits)->toBe(['max_projects' => 5, 'max_members' => 3, 'max_storage_bytes' => 1024 * 1024 * 1024]);
});

test('max_projects 上限内ならプロジェクトを作成できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/projects", ['name' => '1 つ目']);

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('success');
    expect($organization->projects()->count())->toBe(1);
});

test('max_projects 超過でプロジェクト作成が拒否され error flash が返る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    // free プランの上限 (max_projects: 1) まで作成済みにする
    Project::factory()->forOrganization($organization)->create();

    $response = $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/projects/create")
        ->post("/organizations/{$organization->slug}/projects", ['name' => '2 つ目']);

    $response->assertRedirect("/organizations/{$organization->slug}/projects/create");
    $response->assertSessionHas('error');
    expect($organization->projects()->count())->toBe(1);
});

test('quota 超過の error flash に回復先の画面名が含まれる', function (): void {
    // 失敗するのは撮影・プロジェクト作成の現場であり、そこから「どこを見れば現状と上限が
    // 分かるか」が示されないと詰みになる (/billing は課金ゲートの allowlist 内で到達できる)。
    [$organization, $owner] = createOrganizationWithOwner();
    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/projects/create")
        ->post("/organizations/{$organization->slug}/projects", ['name' => '2 つ目']);

    expect(session('error'))->toBeString()->toContain('「お支払い」画面');
});

test('override で上限を上げると超過していた作成が可能になる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    Project::factory()->forOrganization($organization)->create();
    $organization->quota()->create(['limits' => ['max_projects' => 2]]);

    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/projects", ['name' => '2 つ目']);

    $response->assertSessionHas('success');
    expect($organization->projects()->count())->toBe(2);
});

test('limits に無い key は無制限として通る', function (): void {
    [$organization] = createOrganizationWithOwner();
    // fallback_plan (free) の limits から max_projects を外し「limits に無い key」を作る
    config()->set('quota.plans.personal', ['max_members' => 3]);

    app(QuotaService::class)->check($organization, QuotaKey::MaxProjects, 9999);
})->throwsNoExceptions();
