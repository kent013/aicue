<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\ReorderCategoriesRequest;
use App\Http\Requests\Projects\StoreCategoryRequest;
use App\Http\Requests\Projects\UpdateCategoryRequest;
use App\Models\Category;
use App\Models\Project;
use App\Services\Manual\CategoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Webmozart\Assert\Assert;

/**
 * Category (Project 配下の動画マニュアル分類) の書き込み操作。
 * 一覧表示は Projects/Show が担うため Index / Show アクションは持たない。
 *
 * nested route の URL 整合は 2 層 (Item 見本と同じ):
 * 1. {project} ∈ current org (resolveOrganizationProject = inline guard)
 * 2. {category} ∈ {project} (routes 側の Route::scopeBindings() = $project->categories() 経由)
 * いずれも**認可より前に 404** (403 で存在を漏らさない)。
 *
 * sort_order は Service 専有 (作成時末尾採番 + reorder のみ)。payload からは受けない。
 */
class CategoryController extends Controller
{
    use ResolvesCurrentOrganization;

    /** Category 作成。project_id は URL から導出し relation 経由で代入する (payload では 422) */
    public function store(StoreCategoryRequest $request, Project $project, CategoryService $categories): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('create', [Category::class, $project]);

        $name = $request->validated('name');
        Assert::string($name);

        $categories->create($project, $name);

        return back()->with('success', 'カテゴリを追加しました');
    }

    /** Category 更新 (name のみ) */
    public function update(UpdateCategoryRequest $request, Project $project, Category $category, CategoryService $categories): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({category} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $category);

        $name = $request->validated('name');
        Assert::string($name);

        $categories->update($project, $category, $name);

        return back()->with('success', 'カテゴリを更新しました');
    }

    /** Category 削除 (所属 manual は FK nullOnDelete で未分類化) */
    public function destroy(Request $request, Project $project, Category $category, CategoryService $categories): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({category} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('delete', $category);

        $categories->delete($project, $category);

        return back()->with('success', 'カテゴリを削除しました');
    }

    /**
     * Category 並べ替え (payload = 当該 project の category id 順序配列)。
     * 集合一致検証は Service (Project 行ロック後) が行い、不一致は 422。
     */
    public function reorder(ReorderCategoriesRequest $request, Project $project, CategoryService $categories): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('reorder', [Category::class, $project]);

        $order = $request->validated('order');
        Assert::isArray($order);
        // 'order.*' => integer 検証済み。数値文字列も許容されるため int へ正規化する
        $orderedIds = [];
        foreach ($order as $id) {
            Assert::integerish($id);
            $orderedIds[] = (int) $id;
        }

        $categories->reorder($project, $orderedIds);

        return back()->with('success', 'カテゴリの並び順を更新しました');
    }
}
