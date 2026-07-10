<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Jobs\Capture\DeleteTakeObjectsJob;
use App\Models\Category;
use App\Models\Cut;
use App\Models\Project;
use App\Models\SourceDocument;
use App\Models\Take;
use App\Models\VideoManual;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * VideoManual CRUD (Project 配下の動画マニュアル)。
 * - project_id / created_by は URL / Auth から導出 (payload では 422)
 * - カテゴリ入力名は保護キー category_id と別名の `category` (id 値・null = 未分類)
 * - 認可: 編集者 = write 可、撮影者 = show / 一覧のみ
 */

test('owner は動画マニュアルを作成できる (created_by はサーバ導出・作成後は詳細へ遷移)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();

    $response = $this->actingAs($owner)->post("/projects/{$project->id}/manuals", [
        'title' => 'ネジ締め作業',
        'category' => $category->id,
    ]);

    /** @var VideoManual $manual */
    $manual = $project->manuals()->firstOrFail();
    $response->assertRedirect("/projects/{$project->id}/manuals/{$manual->id}");
    $response->assertSessionHas('success');
    expect($manual->title)->toBe('ネジ締め作業');
    expect($manual->project_id)->toBe($project->id);
    expect($manual->created_by)->toBe($owner->id);
    expect($manual->category_id)->toBe($category->id);
    expect($manual->status)->toBe(VideoManualStatus::Draft);
    expect($manual->scenario_version)->toBe(0);
});

test('category 未指定 (null) は未分類で作成される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals", [
        'title' => '未分類マニュアル',
        'category' => null,
    ])->assertSessionHas('success');

    /** @var VideoManual $manual */
    $manual = $project->manuals()->firstOrFail();
    expect($manual->category_id)->toBeNull();
});

test('保護キー (category_id / created_by / project_id) の直送は 422', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals", [
        'title' => 'タイトル',
        'category_id' => $category->id,
    ])->assertSessionHasErrors('category_id');

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals", [
        'title' => 'タイトル',
        'created_by' => $owner->id,
    ])->assertSessionHasErrors('created_by');

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals", [
        'title' => 'タイトル',
        'project_id' => $project->id,
    ])->assertSessionHasErrors('project_id');

    expect($project->manuals()->count())->toBe(0);
});

test('他 project の category id は exists 検証で 422 (保存時再解決の一段目)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $otherProject = Project::factory()->forOrganization($organization)->create();
    $foreignCategory = Category::factory()->forProject($otherProject)->create();

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals", [
        'title' => 'タイトル',
        'category' => $foreignCategory->id,
    ])->assertSessionHasErrors('category');

    expect($project->manuals()->count())->toBe(0);
});

test('撮影者は create 画面 / store で 403、編集者は create 画面に categories props が供給される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Member);
    $category = Category::factory()->forProject($project)->create(['name' => '準備作業']);

    // 撮影者 (project_member) は 403
    $this->actingAs($member)->get("/projects/{$project->id}/manuals/create")->assertForbidden();
    $this->actingAs($member)->post("/projects/{$project->id}/manuals", ['title' => 'x'])
        ->assertForbidden();

    // 編集者 (owner) は categories 選択肢付きでフォームを開ける
    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/create")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manuals/Create')
            ->where('project.id', $project->id)
            ->has('categories', 1)
            ->where('categories.0.id', $category->id)
            ->where('categories.0.name', '準備作業'));
});

test('撮影者も show は閲覧できる (編集操作の可視性は canManage=false)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Member);
    $manual = VideoManual::factory()->forProject($project)->create(['title' => '閲覧テスト']);

    $this->actingAs($member)->get("/projects/{$project->id}/manuals/{$manual->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manuals/Show')
            ->where('manual.title', '閲覧テスト')
            ->where('manual.status', 'draft')
            ->where('manual.category', null)
            ->where('canManage', false));
});

test('owner はメタデータを更新できる (title / category 変更・null で未分類化)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();
    $manual = VideoManual::factory()->forProject($project)->forCategory($category)->create();

    // edit 画面 (categories props 付き)
    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/edit")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Manuals/Edit')
            ->where('manual.category', $category->id)
            ->has('categories', 1));

    // title + category null → 未分類化 (dissociate)
    $this->actingAs($owner)->patch("/projects/{$project->id}/manuals/{$manual->id}", [
        'title' => '更新後タイトル',
        'category' => null,
    ])->assertSessionHas('success');

    $manual = $manual->fresh();
    expect($manual?->title)->toBe('更新後タイトル');
    expect($manual?->category_id)->toBeNull();

    // category 再指定 → associate
    $this->actingAs($owner)->patch("/projects/{$project->id}/manuals/{$manual?->id}", [
        'title' => '更新後タイトル',
        'category' => $category->id,
    ])->assertSessionHas('success');
    expect($manual?->fresh()?->category_id)->toBe($category->id);
});

test('更新でも保護キー直送は 422・他 project category は 422', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $otherProject = Project::factory()->forOrganization($organization)->create();
    $foreignCategory = Category::factory()->forProject($otherProject)->create();
    $manual = VideoManual::factory()->forProject($project)->create();

    $this->actingAs($owner)->patch("/projects/{$project->id}/manuals/{$manual->id}", [
        'title' => 'x',
        'category_id' => $foreignCategory->id,
    ])->assertSessionHasErrors('category_id');

    $this->actingAs($owner)->patch("/projects/{$project->id}/manuals/{$manual->id}", [
        'title' => 'x',
        'category' => $foreignCategory->id,
    ])->assertSessionHasErrors('category');

    expect($manual->fresh()?->category_id)->toBeNull();
});

test('owner は動画マニュアルを削除できる (プロジェクト詳細へ遷移)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();

    $response = $this->actingAs($owner)->delete("/projects/{$project->id}/manuals/{$manual->id}");

    $response->assertRedirect("/projects/{$project->id}");
    $response->assertSessionHas('success');
    expect(VideoManual::query()->whereKey($manual->id)->exists())->toBeFalse();
});

test('manual 削除で cascade 対象の takes / source_documents の S3 キーが DeleteTakeObjectsJob に渡る', function (): void {
    Queue::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create(['thumbnail_path' => 'takes/thumb.jpg']);
    $document = SourceDocument::factory()->forManual($manual)->create();

    $this->actingAs($owner)->delete("/projects/{$project->id}/manuals/{$manual->id}")
        ->assertRedirect("/projects/{$project->id}");

    expect(VideoManual::query()->whereKey($manual->id)->exists())->toBeFalse();
    Queue::assertPushed(
        DeleteTakeObjectsJob::class,
        function (DeleteTakeObjectsJob $job) use ($take, $document): bool {
            $paths = $job->paths;
            sort($paths);
            $expected = [$take->video_path, 'takes/thumb.jpg', $document->file_path];
            sort($expected);

            return $paths === $expected && $job->connection === 'database-media';
        },
    );
});

test('takes / source_documents の無い manual 削除では DeleteTakeObjectsJob を dispatch しない', function (): void {
    Queue::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();

    $this->actingAs($owner)->delete("/projects/{$project->id}/manuals/{$manual->id}");

    Queue::assertNotPushed(DeleteTakeObjectsJob::class);
});

test('撮影者は update / destroy で 403', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Member);
    $manual = VideoManual::factory()->forProject($project)->create(['title' => '元のタイトル']);

    $this->actingAs($member)->get("/projects/{$project->id}/manuals/{$manual->id}/edit")
        ->assertForbidden();
    $this->actingAs($member)->patch("/projects/{$project->id}/manuals/{$manual->id}", ['title' => '更新'])
        ->assertForbidden();
    $this->actingAs($member)->delete("/projects/{$project->id}/manuals/{$manual->id}")
        ->assertForbidden();

    expect($manual->fresh()?->title)->toBe('元のタイトル');
});

test('cross-org の project 配下マニュアルは 404 (存在を漏らさない)', function (): void {
    [, $ownerA] = createOrganizationWithOwner('組織A');
    [$orgB] = createOrganizationWithOwner('組織B');
    $projectB = Project::factory()->forOrganization($orgB)->create();
    $manualB = VideoManual::factory()->forProject($projectB)->create(['title' => '元のタイトル']);

    $this->actingAs($ownerA)->get("/projects/{$projectB->id}/manuals/create")->assertNotFound();
    $this->actingAs($ownerA)->post("/projects/{$projectB->id}/manuals", ['title' => 'x'])->assertNotFound();
    $this->actingAs($ownerA)->get("/projects/{$projectB->id}/manuals/{$manualB->id}")->assertNotFound();
    $this->actingAs($ownerA)->patch("/projects/{$projectB->id}/manuals/{$manualB->id}", ['title' => 'x'])
        ->assertNotFound();
    $this->actingAs($ownerA)->delete("/projects/{$projectB->id}/manuals/{$manualB->id}")->assertNotFound();

    expect($manualB->fresh()?->title)->toBe('元のタイトル');
});

test('cross-org project への category id 探索は exists 検証より前に 404 (所属オラクル防止)', function (): void {
    [, $ownerA] = createOrganizationWithOwner('組織A');
    [$orgB] = createOrganizationWithOwner('組織B');
    $projectB = Project::factory()->forOrganization($orgB)->create();
    $categoryInB = Category::factory()->forProject($projectB)->create();
    $otherProjectB = Project::factory()->forOrganization($orgB)->create();
    $categoryOutOfB = Category::factory()->forProject($otherProjectB)->create();
    $manualB = VideoManual::factory()->forProject($projectB)->create(['title' => '元のタイトル']);

    // {project} に属する category id / 属さない category id のどちらも同じ 404 —
    // exists ルールの 422/404 差分で他組織の category↔project 所属関係を探索できないこと
    // (project.in-current-org middleware が FormRequest の DB ルールより前に 404 に落とす)
    $this->actingAs($ownerA)
        ->from('/projects')
        ->post("/projects/{$projectB->id}/manuals", ['title' => 'x', 'category' => $categoryInB->id])
        ->assertNotFound();
    $this->actingAs($ownerA)
        ->from('/projects')
        ->post("/projects/{$projectB->id}/manuals", ['title' => 'x', 'category' => $categoryOutOfB->id])
        ->assertNotFound();
    $this->actingAs($ownerA)
        ->from('/projects')
        ->patch("/projects/{$projectB->id}/manuals/{$manualB->id}", ['title' => 'x', 'category' => $categoryOutOfB->id])
        ->assertNotFound();

    expect($projectB->manuals()->count())->toBe(1);
    expect($manualB->fresh()?->title)->toBe('元のタイトル');
});

test('cross-project の {manual} は 404 (scopeBindings)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $manualB = VideoManual::factory()->forProject($projectB)->create(['title' => '元のタイトル']);

    $this->actingAs($owner)->get("/projects/{$projectA->id}/manuals/{$manualB->id}")->assertNotFound();
    $this->actingAs($owner)->get("/projects/{$projectA->id}/manuals/{$manualB->id}/edit")->assertNotFound();
    $this->actingAs($owner)->patch("/projects/{$projectA->id}/manuals/{$manualB->id}", ['title' => 'x'])
        ->assertNotFound();
    $this->actingAs($owner)->delete("/projects/{$projectA->id}/manuals/{$manualB->id}")->assertNotFound();

    expect($manualB->fresh()?->title)->toBe('元のタイトル');
    expect(VideoManual::query()->whereKey($manualB->id)->exists())->toBeTrue();
});

test('title は必須・200 文字以内 (バリデーション)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->post("/projects/{$project->id}/manuals", ['title' => ''])
        ->assertSessionHasErrors('title');
    $this->actingAs($owner)->post("/projects/{$project->id}/manuals", [
        'title' => str_repeat('あ', 201),
    ])->assertSessionHasErrors('title');
});
