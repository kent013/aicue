<?php

declare(strict_types=1);

use App\Models\Cut;
use App\Models\Project;
use App\Models\Take;
use App\Models\VideoManual;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * /billing の quota カード (T090-b): 上限だけでなく **使用量と超過次元**を届ける。
 *
 * 実際に止まるのは max_projects (ProjectService::create) と
 * max_storage_bytes (TakeUploadService) の 2 次元だけなので、使用量と超過判定も
 * その 2 次元に閉じる。max_members は QuotaService::check の呼び出し元が無く
 * 実効的に未強制のため、上限のみを出し「超えると止まる」と読める表示をしない。
 *
 * 超過判定は **`>` (厳密超過)**。`>=` にすると max_projects=1 の starter / personal で
 * 全組織に恒常警告が出て、本当の超過が埋もれる (「上限に達した」ことへの気づきは
 * 警告ではなく「使用量 / 上限」の併記が担う)。
 */

test('/organizations/{slug}/billing の quotas は 6 キー厳密一致で届く', function (): void {
    // Inertia props は連想配列なので、DTO rename の波及漏れ (キー名の取りこぼし) は
    // phpstan / typecheck では捕まらない。キー集合そのものをここで固定する。
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // 過不足の両方を見る: hasAll で不足を、count で余剰を検出する
            ->hasAll([
                'page.quotas.maxProjects',
                'page.quotas.maxMembers',
                'page.quotas.maxStorageGb',
                'page.quotas.projectsUsed',
                'page.quotas.storageUsedBytes',
                'page.quotas.exceededLabels',
            ])
            ->count('page.quotas', 6));
});

test('上限内なら exceededLabels は空で、使用量が実値で届く', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $cut = Cut::factory()->forManual($manual)->create();
    Take::factory()->forCut($cut)->create(['size_bytes' => 1_024]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.quotas.projectsUsed', 1)
            ->where('page.quotas.storageUsedBytes', 1_024)
            ->where('page.quotas.exceededLabels', []));
});

test('上限ちょうど (1/1) では警告を出さない (恒常警告の回帰防止)', function (): void {
    // personal / starter の max_projects は 1。プロジェクトを 1 つ作った状態は
    // プランの設計どおりの正常状態であり、超過ではない。
    [$organization, $owner] = createOrganizationWithOwner();
    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.quotas.maxProjects', 1)
            ->where('page.quotas.projectsUsed', 1)
            ->where('page.quotas.exceededLabels', []));
});

test('プロジェクト数が上限を超えていれば exceededLabels に載る', function (): void {
    // Plan 行を手組みせず、既存の organization_quotas override で超過状態を作る。
    [$organization, $owner] = createOrganizationWithOwner();
    Project::factory()->forOrganization($organization)->create();
    $organization->quota()->create(['limits' => ['max_projects' => 0]]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.quotas.exceededLabels', ['プロジェクト数']));
});

test('保存容量が上限を超えていれば exceededLabels に載る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $cut = Cut::factory()->forManual($manual)->create();
    Take::factory()->forCut($cut)->create(['size_bytes' => 2_000]);
    $organization->quota()->create(['limits' => ['max_storage_bytes' => 1_000]]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.quotas.storageUsedBytes', 2_000)
            ->where('page.quotas.exceededLabels', ['保存容量']));
});

test('メンバー数は上限のみで、超過次元には決して現れない (未強制の明示)', function (): void {
    // max_members を 0 に落としてもメンバーは存在する。それでも exceededLabels に
    // 載らないことで「表示があるのに強制が無い」次元を UI が警告しないことを固定する。
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->quota()->create(['limits' => ['max_members' => 0]]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.quotas.maxMembers', 0)
            ->where('page.quotas.exceededLabels', []));
});
