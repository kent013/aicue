<?php

declare(strict_types=1);

use App\Models\Project;

/*
 * URL 上の `{project}` が URL 上の組織に属さなければ**入力検証より前に 404**
 * (家系裁定 AG-036 / 不変条件 I1。組織の取得元だけが URL の binding へ変わった)。
 */

test('cross-org の {project} は FormRequest の DB ルールより前に 404 (422 にならない)', function (): void {
    [$mine, $owner] = createOrganizationWithOwner('自分の組織');
    [$other] = createOrganizationWithOwner('他人の組織');
    $foreignProject = Project::factory()->forOrganization($other)->create();

    // payload は意図的に不正 (name 欠落)。422 が返るなら FormRequest が先に走っている =
    // 存在オラクルになる。404 でなければならない。
    $this->actingAs($owner)
        ->post("/organizations/{$mine->slug}/projects/{$foreignProject->id}/items", [])
        ->assertNotFound();
});

test('不在 project も cross-org と同じ 404 (差分を作らない)', function (): void {
    [$mine, $owner] = createOrganizationWithOwner('自分の組織');

    $this->actingAs($owner)
        ->post("/organizations/{$mine->slug}/projects/999999999/items", [])
        ->assertNotFound();
});

test('自組織の {project} は通る (guard が効きすぎていない)', function (): void {
    [$mine, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($mine)->create();

    $this->actingAs($owner)
        ->get("/organizations/{$mine->slug}/projects/{$project->id}")
        ->assertOk();
});
