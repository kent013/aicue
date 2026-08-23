<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\ManualListQuery;
use App\Enums\ProjectRole;
use App\Models\Category;
use App\Models\Project;
use App\Models\VideoManual;

/*
 * T182: 一覧の行から削除したときの着地 (絞り込み・ページを維持する)。
 *
 * 削除要求に付くクエリは**対象の決定には使わない** (対象は route パラメータのみ)。
 * 着地先の組み立てだけに使い、一覧と同じ allowlist (ManualListQuery) を通す。
 */

test('絞り込み付きの削除は同じ絞り込み・同じページへ着地する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();
    $manual = VideoManual::factory()->forProject($project)->forCategory($category)->create();

    $query = "category={$category->id}&progress=completed&q=".urlencode('ネジ')
        .'&sort=title_asc&mine=1&page=2';

    $response = $this->actingAs($owner)
        ->delete("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}?{$query}");

    $response->assertRedirect(
        "/organizations/{$organization->slug}/projects/{$project->id}?category={$category->id}&progress=completed&q=".urlencode('ネジ')
        .'&sort=title_asc&mine=1&page=2'
    );
    $response->assertSessionHas('success');
    $this->assertDatabaseMissing('video_manuals', ['id' => $manual->id]);
});

test('クエリ無しの削除は /organizations/{slug}/projects/{project} へ着地する (詳細画面からの削除の非退行)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();

    $this->actingAs($owner)->delete("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}")
        ->assertRedirect("/organizations/{$organization->slug}/projects/{$project->id}");
});

test('allowlist 外のクエリは着地先の URL に載らない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();

    $this->actingAs($owner)
        // 旧 `?status=` (制作状態 5 値) も allowlist 外なので着地先には載らない (互換を残さない)
        ->delete("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}?sort=".urlencode(';DROP')
            .'&category=abc&progress=bogus&status=published')
        ->assertRedirect("/organizations/{$organization->slug}/projects/{$project->id}");
});

test('page は 1 以下なら着地先の URL に載せない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    foreach (['abc', '0', '1'] as $raw) {
        $manual = VideoManual::factory()->forProject($project)->create();
        $this->actingAs($owner)->delete("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}?page={$raw}")
            ->assertRedirect("/organizations/{$organization->slug}/projects/{$project->id}");
    }
});

test('極端な page の削除でも 500 にならず正規化後の値へ丸まる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();

    $this->actingAs($owner)
        ->delete("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}?page=99999999999999999999999")
        ->assertRedirect("/organizations/{$organization->slug}/projects/{$project->id}?page=".ManualListQuery::maxPage());
});

test('q が 200 文字超のとき着地先の q は先頭 200 文字 (一覧の絞り込みと同じ値)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $keyword = str_repeat('あ', 200);

    $this->actingAs($owner)
        ->delete("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}?q=".urlencode($keyword.'ZZZ'))
        ->assertRedirect("/organizations/{$organization->slug}/projects/{$project->id}?q=".urlencode($keyword));
});

test('撮影者の行内削除はサーバでも 403 (導線を出さないだけに頼らない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Member);
    $manual = VideoManual::factory()->forProject($project)->create();

    $this->actingAs($member)->delete("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}?page=2")
        ->assertForbidden();
    $this->assertDatabaseHas('video_manuals', ['id' => $manual->id]);
});

test('他プロジェクトの manual を指す削除は認可より前に 404 (scopeBindings の非退行)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $other = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($other)->create();

    $this->actingAs($owner)->delete("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}?page=2")
        ->assertNotFound();
    $this->assertDatabaseHas('video_manuals', ['id' => $manual->id]);
});

test('着地先の category は正規形になる (生の入力を Location に素通ししない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $padded = str_pad((string) $category->id, 6, '0', STR_PAD_LEFT);

    $this->actingAs($owner)->delete("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}?category={$padded}")
        ->assertRedirect("/organizations/{$organization->slug}/projects/{$project->id}?category={$category->id}");
});
