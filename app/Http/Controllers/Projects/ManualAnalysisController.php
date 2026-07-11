<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\DataTransferObjects\Manual\AnalysisJobData;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\AnalyzeManualRequest;
use App\Http\Resources\Manual\AnalysisJobResource;
use App\Models\AnalysisJob;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\AnalysisJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * AI 解析のトリガー (store) と job 状態ポーリング (show)。doc/10 §10.3。
 * 同一オリジン XHR (JSON 応答)。409/402 契約のため Inertia でなく JsonResource を返す。
 *
 * nested route の URL 整合は ManualScenarioController と同じ 2 層 (認可より前に 404):
 * 1. {project} ∈ current org (resolveOrganizationProject = inline guard)
 * 2. {manual} ∈ {project}、{analysisJob} ∈ {manual} (routes 側の Route::scopeBindings())
 */
class ManualAnalysisController extends Controller
{
    use ResolvesCurrentOrganization;

    /** AI 解析トリガー (201 + AnalysisJobResource)。編集者のみ。保護キー直送は 422 */
    public function store(AnalyzeManualRequest $request, Project $project, VideoManual $manual, AnalysisJobService $analysis): JsonResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('analyze', $manual);

        // actor = ジョブ実行者 (通知宛先の導出用。Auth から明示的に渡す = payload 不信任)
        $actor = $request->user();
        $job = $analysis->trigger($project, $manual, $actor instanceof User ? $actor : null);
        $manual->refresh(); // trigger で analyzing へ遷移済み

        return AnalysisJobResource::make(AnalysisJobData::fromJob($job, $manual))
            ->response($request)
            ->setStatusCode(201);
    }

    /** job 状態ポーリング (撮影者も read 可) */
    public function show(Request $request, Project $project, VideoManual $manual, AnalysisJob $analysisJob): AnalysisJobResource
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        // {analysisJob} ∈ {manual} は scopeBindings が担保済み。inline 再検査は二重防御
        // (oauthSessions controller の organization_id 再検査と同じ位置づけ)
        if ($analysisJob->video_manual_id !== $manual->id) {
            abort(404);
        }
        Gate::authorize('view', $manual);

        return AnalysisJobResource::make(AnalysisJobData::fromJob($analysisJob, $manual));
    }
}
