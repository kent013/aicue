<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Project;
use App\Models\VideoManual;
use App\Services\Manual\CategoryService;
use App\Services\Manual\VideoManualService;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/*
 * Service 境界防御 (route binding とは別レイヤ)。
 * 全メソッドは Project 行ロック取得後に対象の子を親 relation から再解決するため、
 * 別 Service・将来のバッチ等から cross-project の子を渡されても
 * ModelNotFoundException (→404) で拒否し、DB を一切変更しない。
 */

test('CategoryService::update は cross-project の Category を拒否し DB を変更しない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $categoryB = Category::factory()->forProject($projectB)->create(['name' => '元の名前']);
    $countBefore = Category::query()->count();

    expect(fn () => app(CategoryService::class)->update($projectA, $categoryB, '改竄名'))
        ->toThrow(ModelNotFoundException::class);

    expect($categoryB->fresh()?->name)->toBe('元の名前');
    expect(Category::query()->count())->toBe($countBefore);
});

test('CategoryService::delete は cross-project の Category を拒否し DB を変更しない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $categoryB = Category::factory()->forProject($projectB)->create();
    $countBefore = Category::query()->count();

    expect(fn () => app(CategoryService::class)->delete($projectA, $categoryB))
        ->toThrow(ModelNotFoundException::class);

    expect(Category::query()->whereKey($categoryB->id)->exists())->toBeTrue();
    expect(Category::query()->count())->toBe($countBefore);
});

test('VideoManualService::updateMeta は cross-project の VideoManual を拒否し DB を変更しない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $manualB = VideoManual::factory()->forProject($projectB)->create(['title' => '元のタイトル']);
    $countBefore = VideoManual::query()->count();

    expect(fn () => app(VideoManualService::class)->updateMeta($projectA, $manualB, '改竄タイトル', null))
        ->toThrow(ModelNotFoundException::class);

    expect($manualB->fresh()?->title)->toBe('元のタイトル');
    expect(VideoManual::query()->count())->toBe($countBefore);
});

test('VideoManualService::delete は cross-project の VideoManual を拒否し DB を変更しない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $manualB = VideoManual::factory()->forProject($projectB)->create();
    $countBefore = VideoManual::query()->count();

    expect(fn () => app(VideoManualService::class)->delete($projectA, $manualB))
        ->toThrow(ModelNotFoundException::class);

    expect(VideoManual::query()->whereKey($manualB->id)->exists())->toBeTrue();
    expect(VideoManual::query()->count())->toBe($countBefore);
});

test('VideoManualService::create は他 project の categoryId を拒否し manual を残さない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $categoryB = Category::factory()->forProject($projectB)->create();

    // FormRequest の exists をすり抜けても、保存時再解決 (二段目) が transaction ごと巻き戻す
    expect(fn () => app(VideoManualService::class)->create($projectA, 'タイトル', $categoryB->id, $owner->id))
        ->toThrow(ModelNotFoundException::class);

    expect(VideoManual::query()->count())->toBe(0);
});

test('VideoManualService::updateMeta は他 project の categoryId を拒否し変更を巻き戻す', function (): void {
    [$organization] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $categoryB = Category::factory()->forProject($projectB)->create();
    $manualA = VideoManual::factory()->forProject($projectA)->create(['title' => '元のタイトル']);

    expect(fn () => app(VideoManualService::class)->updateMeta($projectA, $manualA, '改竄タイトル', $categoryB->id))
        ->toThrow(ModelNotFoundException::class);

    $fresh = $manualA->fresh();
    expect($fresh?->title)->toBe('元のタイトル');
    expect($fresh?->category_id)->toBeNull();
});
