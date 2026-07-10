<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\DataTransferObjects\Manual\ScenarioDocumentData;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreVideoManualRequest;
use App\Http\Requests\Projects\UpdateVideoManualRequest;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\VideoManualService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * VideoManual (Project 配下の動画マニュアル) の CRUD。
 * 一覧は Projects/Show が内包する (専用 index は持たない)。
 *
 * nested route の URL 整合は 2 層 (Item 見本と同じ):
 * 1. {project} ∈ current org (resolveOrganizationProject = inline guard)
 * 2. {manual} ∈ {project} (routes 側の Route::scopeBindings() = $project->manuals() 経由)
 * いずれも**認可より前に 404** (403 で存在を漏らさない)。
 *
 * 入力名の境界: カテゴリは payload の `category` (id 値) で受け、保護キー `category_id` は
 * 直送で 422。Controller は validated('category') のみ参照し、Service がロック済み
 * project relation から再解決して associate する (validated('category_id') は参照しない)。
 */
class VideoManualController extends Controller
{
    use ResolvesCurrentOrganization;

    /** 作成フォーム (カテゴリ選択肢を props で供給。撮影者は 403) */
    public function create(Request $request, Project $project): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('create', [VideoManual::class, $project]);

        return Inertia::render('Manuals/Create', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'categories' => $this->categoryOptions($project),
        ]);
    }

    /** VideoManual 作成。project_id / created_by はサーバ導出 (payload では 422) */
    public function store(StoreVideoManualRequest $request, Project $project, VideoManualService $manuals): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('create', [VideoManual::class, $project]);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $title = $request->validated('title');
        Assert::string($title);
        // 入力名は category (保護キー category_id とは別名)。null = 未分類
        $category = $request->validated('category');
        Assert::nullOrIntegerish($category);

        $manual = $manuals->create($project, $title, $category === null ? null : (int) $category, $user->id);

        return redirect()
            ->route('projects.manuals.show', [$project, $manual])
            ->with('success', '動画マニュアルを作成しました');
    }

    /** 詳細 (撮影者も閲覧可) */
    public function show(Request $request, Project $project, VideoManual $manual): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('view', $manual);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $category = $manual->category;

        return Inertia::render('Manuals/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'manual' => [
                'id' => $manual->id,
                'title' => $manual->title,
                'status' => $manual->status->value,
                'category' => $category === null
                    ? null
                    : ['id' => $category->id, 'name' => $category->name],
                'created_at' => $manual->created_at?->format('Y-m-d H:i') ?? '',
            ],
            'canManage' => $user->can('update', $manual),
        ]);
    }

    /** 編集フォーム (メタデータ = title / category + シナリオ document) */
    public function edit(Request $request, Project $project, VideoManual $manual): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $manual);

        return Inertia::render('Manuals/Edit', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'manual' => [
                'id' => $manual->id,
                'title' => $manual->title,
                'category' => $manual->category_id,
                'status' => $manual->status->value, // rendering / analyzing 中の警告表示用
            ],
            'categories' => $this->categoryOptions($project),
            // シナリオ document (保存成功応答 ScenarioResource と同一 shape)
            'scenario' => ScenarioDocumentData::fromManual($manual)->toArray(),
        ]);
    }

    /** メタデータ更新 (title / category)。category null は未分類化 */
    public function update(UpdateVideoManualRequest $request, Project $project, VideoManual $manual, VideoManualService $manuals): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $manual);

        $title = $request->validated('title');
        Assert::string($title);
        // 入力名は category (保護キー category_id とは別名)。null = 未分類
        $category = $request->validated('category');
        Assert::nullOrIntegerish($category);

        $manuals->updateMeta($project, $manual, $title, $category === null ? null : (int) $category);

        return back()->with('success', '動画マニュアルを更新しました');
    }

    /** 削除 */
    public function destroy(Request $request, Project $project, VideoManual $manual, VideoManualService $manuals): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('delete', $manual);

        $manuals->delete($project, $manual);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', '動画マニュアルを削除しました');
    }

    /**
     * カテゴリセレクトの選択肢 (sort_order 順)。未分類はフロント側で null 選択肢として表現する。
     *
     * @return list<array{id: int, name: string}>
     */
    private function categoryOptions(Project $project): array
    {
        return array_values($project->categories()->orderBy('sort_order')->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
            ])
            ->all());
    }
}
