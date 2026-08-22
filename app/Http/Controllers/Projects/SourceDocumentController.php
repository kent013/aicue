<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Concerns\ResolvesRouteOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreSourceDocumentRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\VideoManual;
use App\Services\Manual\SourceDocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * SOP (SourceDocument) の後付けアップロード。追記型 immutable (更新・削除 route を持たない)。
 *
 * nested route の URL 整合は VideoManualController と同じ 2 層 (認可より前に 404):
 * 1. {project} ∈ current org (resolveOrganizationProject = inline guard)
 * 2. {manual} ∈ {project} (routes 側の Route::scopeBindings() = $project->manuals() 経由)
 */
class SourceDocumentController extends Controller
{
    use ResolvesRouteOrganization;

    /** アップロード (Inertia form。back + flash)。編集者のみ */
    public function store(
        StoreSourceDocumentRequest $request, Organization $organization,
        Project $project,
        VideoManual $manual,
        SourceDocumentService $documents,
    ): RedirectResponse {
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $manual);

        $documents->storeForManual($project, $manual, $request->validatedDocument());

        return back()->with('success', '手順書をアップロードしました');
    }
}
