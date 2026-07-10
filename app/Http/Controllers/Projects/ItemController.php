<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreItemRequest;
use App\Http\Requests\Projects\UpdateItemRequest;
use App\Models\Item;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Webmozart\Assert\Assert;

/**
 * Item (Project 配下サンプルリソース) の書き込み操作。
 * 一覧表示は Projects/Show が担うため Index / Show アクションは持たない。
 *
 * nested route (/projects/{project}/items/{item}) の URL 整合は 2 層:
 * 1. {project} が current org に属するか (resolveOrganizationProject = inline guard)
 * 2. {item} が {project} に属するか (routes 側の Route::scopeBindings() =
 *    $project->items() 経由で解決。不整合は routing 層で 404)
 * いずれも**認可より前に 404** (403 で存在を漏らさない)。
 */
class ItemController extends Controller
{
    use ResolvesCurrentOrganization;

    /** Item 作成。project_id は URL から導出し relation 経由で代入する (payload では 422) */
    public function store(StoreItemRequest $request, Project $project): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('create', [Item::class, $project]);

        $name = $request->validated('name');
        Assert::string($name);
        $note = $request->validated('note');
        Assert::nullOrString($note);

        // 親 FK は relation 経由で明示代入 (mass assignment しない)
        $project->items()->create(['name' => $name, 'note' => $note]);

        return back()->with('success', 'アイテムを追加しました');
    }

    /** Item 更新 (name / note) */
    public function update(UpdateItemRequest $request, Project $project, Item $item): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({item} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $item);

        $name = $request->validated('name');
        Assert::string($name);
        $note = $request->validated('note');
        Assert::nullOrString($note);

        $item->fill(['name' => $name, 'note' => $note])->save();

        return back()->with('success', 'アイテムを更新しました');
    }

    /** Item 削除 */
    public function destroy(Request $request, Project $project, Item $item): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({item} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('delete', $item);

        $item->delete();

        return back()->with('success', 'アイテムを削除しました');
    }
}
