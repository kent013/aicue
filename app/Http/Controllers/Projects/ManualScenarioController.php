<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\UpdateScenarioRequest;
use App\Http\Resources\Manual\ScenarioResource;
use App\Models\Project;
use App\Models\VideoManual;
use App\Services\Manual\ScenarioService;
use Illuminate\Support\Facades\Gate;

/**
 * シナリオ (Cut 群) の document 一括保存 (doc/09 §9.4 / doc/10 §10.3)。
 * 同一オリジン XHR (JSON 応答)。409 契約のため Inertia でなく JsonResource を返す。
 *
 * nested route の URL 整合は VideoManualController と同じ 2 層 (認可より前に 404):
 * 1. {project} ∈ current org (resolveOrganizationProject = inline guard)
 * 2. {manual} ∈ {project} (routes 側の Route::scopeBindings() = $project->manuals() 経由)
 */
class ManualScenarioController extends Controller
{
    use ResolvesCurrentOrganization;

    public function update(
        UpdateScenarioRequest $request,
        Project $project,
        VideoManual $manual,
        ScenarioService $scenarios,
    ): ScenarioResource {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $manual);

        $document = $scenarios->save($project, $manual, $request->toScenarioSaveInput());

        return ScenarioResource::make($document);
    }
}
