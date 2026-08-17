<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\DataTransferObjects\Manual\ManualListItemData;
use App\DataTransferObjects\Manual\ManualListQuery;
use App\Enums\Manual\ManualSortOption;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\Category;
use App\Models\Item;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\ManualKeywordSearch;
use App\Services\Manual\ManualRowAbilities;
use App\Services\Project\ProjectService;
use App\Support\Seo\SeoManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * プロジェクト CRUD (Project 配下リソース追加の見本となる Controller 流儀)。
 *
 * - current org スコープ: ResolvesCurrentOrganization で解決 (URL に org セグメントを持たない)
 * - URL 整合 guard: {project} が current org に属さなければ**認可より前に 404**
 * - teams_visible=false の既定では Team 概念を UI に出さない (Default Team は Service が自動割当)
 *
 * @phpstan-import-type ManualOrdering from ManualSortOption
 */
class ProjectController extends Controller
{
    use ResolvesCurrentOrganization;

    /** プロジェクト一覧 (current org の全プロジェクト) */
    public function index(Request $request): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('viewAny', [Project::class, $organization]);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $projects = $organization->projects()->orderBy('projects.created_at')->get()
            ->map(fn (Project $project): array => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
            ])
            ->values()
            ->all();

        return Inertia::render('Projects/Index', [
            'projects' => $projects,
            'canCreate' => $user->can('create', [Project::class, $organization]),
        ]);
    }

    /** 新規プロジェクト作成フォーム (Team 選択は出さない = Default Team パターン) */
    public function create(Request $request): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('create', [Project::class, $organization]);

        return Inertia::render('Projects/Create');
    }

    /** 新規プロジェクト作成。custom_team_id は受け取らず Service が Default Team を割当する */
    public function store(StoreProjectRequest $request, ProjectService $projects): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('create', [Project::class, $organization]);

        $name = $request->validated('name');
        Assert::string($name);
        $description = $request->validated('description');
        Assert::nullOrString($description);

        $project = $projects->createProject($organization, $name, $description);

        return redirect()->route('projects.show', $project)->with('success', 'プロジェクトを作成しました');
    }

    /** プロジェクト詳細 (Item 一覧・メンバー一覧を内包する) */
    public function show(Request $request, Project $project, SeoManager $seo): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 (cross-org の存在を漏らさない)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('view', $project);

        // 動的固有名の per-page タイトル供給の参考実装 (noindex は維持。app_titles 既定より優先)
        $seo->setPrivateTitle($project->name);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $canManage = $user->can('update', $project);

        $items = $project->items()->orderBy('created_at')->get()
            ->map(fn (Item $item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'note' => $item->note,
            ])
            ->values()
            ->all();

        $listQuery = ManualListQuery::fromRequest($request);

        // memberRows は members prop と assignableUsers 導出の双方で使うため 1 度だけ算出する
        $memberRows = $this->memberRows($organization, $project, $canManage);

        return Inertia::render('Projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
            ],
            'items' => $items,
            'members' => $memberRows,
            'canManage' => $canManage,
            // メンバー email 可視性の単一根拠 (can('update', $project))。members[].email の
            // 実値有無と常に一致する (ProjectShowEmailVisibilityTest が契約を固定)
            'canViewMemberEmails' => $canManage,
            // メンバー追加フォームの候補。canManage=false のときは [] (name も PII のため
            // payload 生成時点で絞る = canViewMemberEmails と同じ流儀)
            'assignableUsers' => $this->assignableUserRows($organization, $memberRows, $canManage),
            // 動画マニュアル一覧 (専用 index は持たず本画面に内包。GET クエリで絞り込み + paginate)
            'manuals' => $this->manualRows($project, $listQuery, $user),
            'categories' => $this->categoryRows($project),
            'manualFilters' => $listQuery->toProps(),
            // 管理メニュー導線 (doc/04: 管理者のみサイドバー表示)。単一根拠は Gate
            'canManageMembers' => $user->can('manageMembers', $organization),
        ]);
    }

    /**
     * 動画マニュアル一覧 rows (paginate + DTO で shape を固定)。
     * 未分類は category => null (フロントは「未分類」を表示する)。
     * creator は退会/削除で解決不可のとき null (実運用では FK RESTRICT で常に解決)。
     *
     * @return array{
     *   data: list<array{id: int, title: string, progress: string,
     *     category: array{id: int, name: string}|null,
     *     creator: array{id: int, name: string}|null,
     *     created_at: string, updated_at: string,
     *     duration_ms: int|null, current_finished_render_job_id: int|null, deletable: bool}>,
     *   meta: array{current_page: int, last_page: int, per_page: int, total: int}
     * }
     */
    private function manualRows(Project $project, ManualListQuery $listQuery, User $user): array
    {
        // latestSucceededRender も eager load する (行ごとの現行世代判定で N+1 を作らない)
        $baseQuery = $project->manuals()->with(['category', 'creator', 'latestSucceededRender']);

        // 並べ替え (allowlist enum 由来のカラム名のみ。既定は現行踏襲 created_at desc, id desc)
        $orderings = $listQuery->sort?->orderings() ?? ManualSortOption::defaultOrderings();
        foreach ($orderings as $ordering) {
            /** @var ManualOrdering $ordering */
            $baseQuery->orderBy($ordering['column'], $ordering['direction']);
        }

        if ($listQuery->mine) {
            // 自ユーザー id のみ (payload 非受領 = tenant/actor キー不信)
            $baseQuery->where('created_by', $user->id);
        }
        if ($listQuery->category === 'uncategorized') {
            $baseQuery->whereNull('category_id');
        } elseif ($listQuery->category !== null) {
            $baseQuery->where('category_id', (int) $listQuery->category);
        }
        if ($listQuery->progress !== null) {
            // 3 値 → 制作状態の集合は ManualProgress が唯一の正本 (ここに写像表を書かない)
            $baseQuery->whereIn('status', $listQuery->progress->statusValues());
        }
        if ($listQuery->keyword !== null) {
            // title + カット本文 (scene / narration / subtitle_*) の部分一致。
            // 述語の正本は ManualKeywordSearch (撮影 PWA 一覧と同じ関数を通る)。
            // **入れ子 group で括られる**ため、上で積んだ mine / category / progress と
            // relation の project_id 制約は OR に押し出されない
            ManualKeywordSearch::apply($baseQuery, $listQuery->keyword);
        }

        $paginated = (clone $baseQuery)
            ->paginate(perPage: ManualListQuery::PER_PAGE, page: $listQuery->page)
            // 生クエリをそのまま拾う withQueryString ではなく、**allowlist を通った値だけ**を載せる
            // (未知キー・旧 `?status=` を paginator の query に持ち込まない)。
            // `page` は AbstractPaginator::appends() が pageName として除外するため衝突しない
            ->appends($listQuery->toQueryParams());

        // 範囲外ページ (行内削除で件数が減った / 古いブックマーク) は最終ページへ丸める。
        // 「空の一覧」に着地させない (行き先のない詰みを作らない)。
        // **0 件のときも丸める**: 一覧が空でも lastPage() は 1 なので、丸めないと
        // current_page=99 / last_page=1 という食い違った meta を渡すことになる。
        // URL の ?page=99 と meta.current_page は食い違うが、ページ送り UI は
        // meta.current_page を見る (**props が正本**であり redirect はしない)。
        if ($paginated->currentPage() > $paginated->lastPage()) {
            $paginated = (clone $baseQuery)
                ->paginate(perPage: ManualListQuery::PER_PAGE, page: $paginated->lastPage())
                ->appends($listQuery->toQueryParams());
        }

        /** @var list<VideoManual> $manuals */
        $manuals = [];
        foreach ($paginated->items() as $manual) {
            Assert::isInstanceOf($manual, VideoManual::class);
            $manuals[] = $manual;
        }

        // ability はページで 1 回だけ評価する (理由は ManualRowAbilities の docblock)
        $abilities = ManualRowAbilities::forPage($user, $project, $manuals);

        return [
            'data' => array_map(
                fn (VideoManual $manual): array => ManualListItemData::fromManual($manual, $abilities)->toArray(),
                $manuals,
            ),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }

    /**
     * カテゴリ一覧 (sort_order 順。フィルタ選択肢 + カテゴリ管理 UI の共通 props)。
     *
     * @return list<array{id: int, name: string}>
     */
    private function categoryRows(Project $project): array
    {
        return array_values($project->categories()->orderBy('sort_order')->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
            ])
            ->all());
    }

    /**
     * メンバー一覧 rows (PII 最小化契約)。
     *
     * - email キーは**常在**させ、値は $canViewEmails (= can('update', $project)) のときのみ実値。
     *   閲覧のみのメンバーへ他メンバーの email を露出しない (キー欠落でなく null で返し、
     *   フロントの型を可視性で分岐させない)
     * - 明示メンバー (project_members pivot) と暗黙メンバー (org owner/admin の管理継承 =
     *   ProjectPolicy の継承規則) の両方を含める。重複時は明示側を優先
     *
     * @return list<array{id: int, name: string, email: string|null, role: string|null, implicit: bool}>
     */
    private function memberRows(Organization $organization, Project $project, bool $canViewEmails): array
    {
        $rows = [];

        // 明示メンバー (pivot ロール付き)
        foreach ($project->members()->get() as $member) {
            $rows[$member->id] = [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $canViewEmails ? $member->email : null,
                'role' => $project->memberRole($member)?->value,
                'implicit' => false,
            ];
        }

        // 暗黙メンバー (org owner/admin。project 非所属でも管理アクセスを継承する)
        foreach ($organization->users()->get() as $orgUser) {
            if (isset($rows[$orgUser->id])) {
                continue;
            }
            if (! ($orgUser->organizationRole($organization)?->canManage() ?? false)) {
                continue;
            }
            $rows[$orgUser->id] = [
                'id' => $orgUser->id,
                'name' => $orgUser->name,
                'email' => $canViewEmails ? $orgUser->email : null,
                'role' => null,
                'implicit' => true,
            ];
        }

        return array_values($rows);
    }

    /**
     * メンバー追加フォームの候補 (id/name のみ・PII 最小)。
     *
     * - $canManage=false のときは [] (name も PII。UI 非表示に頼らず payload 生成時点で絞る =
     *   canViewMemberEmails と同じ流儀)。
     * - 候補 = current org のメンバーのうち $memberRows に含まれない者 (明示メンバーも
     *   暗黙メンバー = org owner/admin も除外)。暗黙メンバーは既に管理アクセスを持つため追加が無意味。
     *
     * @param  list<array{id: int, name: string, email: string|null, role: string|null, implicit: bool}>  $memberRows
     * @return list<array{id: int, name: string}>
     */
    private function assignableUserRows(Organization $organization, array $memberRows, bool $canManage): array
    {
        if (! $canManage) {
            return [];
        }

        // memberRows の shape (id:int) から list<int> を明示 (PHPStan L10 で in_array の推論を固定)
        /** @var list<int> $memberIds */
        $memberIds = array_column($memberRows, 'id');

        // NOTE: memberRows でも org->users() を読むため org あたり 2 クエリ。org メンバー数 N は
        // 通常小さく許容。将来 N が大きくなれば単一クエリ化を検討する (現時点で最適化はしない)。
        return array_values(
            $organization->users()->orderBy('users.name')->get()
                ->reject(fn (User $user): bool => in_array($user->id, $memberIds, true))
                ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
                ->all()
        );
    }

    /** プロジェクト編集フォーム */
    public function edit(Request $request, Project $project): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $project);

        return Inertia::render('Projects/Edit', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
            ],
        ]);
    }

    /** プロジェクト更新 (name / description) */
    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $project);

        $name = $request->validated('name');
        Assert::string($name);
        $description = $request->validated('description');
        Assert::nullOrString($description);

        $project->fill(['name' => $name, 'description' => $description])->save();

        return redirect()->route('projects.show', $project)->with('success', 'プロジェクトを更新しました');
    }

    /** プロジェクト削除 (items は FK cascade で削除される) */
    public function destroy(Request $request, Project $project): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('delete', $project);

        $project->delete();

        return redirect()->route('projects.index')->with('success', 'プロジェクトを削除しました');
    }
}
