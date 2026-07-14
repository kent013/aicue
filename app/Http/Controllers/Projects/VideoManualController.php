<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\DataTransferObjects\Manual\AnalysisJobData;
use App\DataTransferObjects\Manual\RenderJobData;
use App\DataTransferObjects\Manual\ScenarioDocumentData;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\DuplicateVideoManualRequest;
use App\Http\Requests\Projects\StoreVideoManualRequest;
use App\Http\Requests\Projects\UpdateVideoManualRequest;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\VideoManualService;
use App\Support\Seo\SeoManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
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
        // SOP 同時アップロード (任意)
        $document = $request->validated('document');
        Assert::nullOrIsInstanceOf($document, UploadedFile::class);

        $manual = $manuals->create($project, $title, $category === null ? null : (int) $category, $user->id, $document);

        return redirect()
            ->route('projects.manuals.show', [$project, $manual])
            ->with('success', '動画マニュアルを作成しました');
    }

    /** 詳細 (撮影者も閲覧可) */
    public function show(Request $request, Project $project, VideoManual $manual, SeoManager $seo, VideoManualService $manuals): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('view', $manual);

        // 動的固有名の per-page タイトル (noindex 維持。projects.show の参考実装踏襲)
        $seo->setPrivateTitle($manual->title);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $category = $manual->category;

        // stale な失敗 (失敗確定後に scenario 保存が成立) は job=null で抑制する (T032 / F-1-1)
        $analysisJob = $manuals->displayAnalysisJob($manual);
        $renderJob = $manuals->displayRenderJob($manual);
        $previewJob = $manuals->displayPreviewJob($manual);

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
            // AI 解析パネル (最新 job + 手順書有無)。AnalysisJobData::toArray() と対
            'analysis' => [
                'job' => $analysisJob === null
                    ? null
                    : AnalysisJobData::fromJob($analysisJob, $manual)->toArray(),
                'hasDocument' => $manual->sourceDocuments()->exists(),
            ],
            // レンダパネル (最新 render job / 最新 preview job / 再生可能 preview)。RenderProps と対
            'render' => [
                'job' => $renderJob === null
                    ? null
                    : RenderJobData::fromJob($renderJob, $manual)->toArray(),
                'previewJob' => $previewJob === null
                    ? null
                    : RenderJobData::fromJob($previewJob, $manual)->toArray(),
                // playbackJobId は succeeded preview のみを見るため staleness 抑制の対象外 (不変)
                'playbackJobId' => $manual->renderJobs()
                    ->where('kind', RenderKind::Preview->value)
                    ->where('status', JobStatus::Succeeded->value)
                    ->whereNotNull('output_path')
                    ->latest('id')
                    ->value('id'),
            ],
            'canManage' => $user->can('update', $manual),
            'categories' => $this->categoryOptions($project), // 複製ダイアログのカテゴリ選択肢 (既存 helper 再利用)
        ]);
    }

    /** VideoManual 複製 (別名保存)。保存済み cuts を雛形に新タイトル・カテゴリで新規作成し詳細へ遷移 */
    public function duplicate(DuplicateVideoManualRequest $request, Project $project, VideoManual $manual, VideoManualService $manuals): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('duplicate', $manual);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $copy = $manuals->duplicate($project, $manual, $request->title(), $request->categoryId(), $user->id);

        return redirect()
            ->route('projects.manuals.show', [$project, $copy])
            ->with('success', '動画マニュアルを複製しました（手順書は引き継がれません）');
    }

    /** 編集フォーム (メタデータ = title / category + シナリオ document) */
    public function edit(Request $request, Project $project, VideoManual $manual, SeoManager $seo): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $manual);

        // 複数 manual の並行編集タブを判別できるよう動的固有名 (概念レビュー合意)
        $seo->setPrivateTitle($manual->title.' の編集');

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
