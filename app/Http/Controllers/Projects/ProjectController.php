<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreProjectRequest;
use App\Http\Requests\Projects\UpdateProjectRequest;
use App\Models\Item;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
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

        return Inertia::render('Projects/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'description' => $project->description,
            ],
            'items' => $items,
            'members' => $this->memberRows($organization, $project, $canManage),
            'canManage' => $canManage,
            // メンバー email 可視性の単一根拠 (can('update', $project))。members[].email の
            // 実値有無と常に一致する (ProjectShowEmailVisibilityTest が契約を固定)
            'canViewMemberEmails' => $canManage,
        ]);
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
