<?php

declare(strict_types=1);

use App\Models\Project;
use App\Models\User;

/*
 * URL 整合 guard (D2 不変条件): {project} が current org に属さない場合、
 * 認可より前に 404 を返す (403 で存在を漏らさない)。
 */

test('他組織のプロジェクトへのアクセスはすべて 404 (認可より前)', function (): void {
    [, $ownerA] = createOrganizationWithOwner('組織A');
    [$orgB] = createOrganizationWithOwner('組織B');
    $projectB = Project::factory()->forOrganization($orgB)->create();

    $this->actingAs($ownerA)->get("/projects/{$projectB->id}")->assertNotFound();
    $this->actingAs($ownerA)->get("/projects/{$projectB->id}/edit")->assertNotFound();
    $this->actingAs($ownerA)->patch("/projects/{$projectB->id}", ['name' => '乗っ取り'])
        ->assertNotFound();
    $this->actingAs($ownerA)->delete("/projects/{$projectB->id}")->assertNotFound();

    expect($projectB->fresh())->not->toBeNull();
});

test('権限のないメンバーでも cross-org 対象なら 403 ではなく 404', function (): void {
    [$orgA] = createOrganizationWithOwner('組織A');
    $memberA = attachOrganizationMember($orgA);
    $memberA->forceFill(['current_organization_id' => $orgA->id])->save();
    [$orgB] = createOrganizationWithOwner('組織B');
    $projectB = Project::factory()->forOrganization($orgB)->create();

    // memberA は update 権限を持たないが、cross-org 不整合が先に 404 になる
    $this->actingAs($memberA)->patch("/projects/{$projectB->id}", ['name' => '乗っ取り'])
        ->assertNotFound();
});

test('存在しないプロジェクト ID は 404', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/projects/999999')->assertNotFound();
});

test('current organization 未設定なら 404 (組織の有無を露出しない)', function (): void {
    $user = User::factory()->create();
    // current_organization_id が null のまま

    $this->actingAs($user)->get('/projects')->assertNotFound();
});
