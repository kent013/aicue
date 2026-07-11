<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\DataTransferObjects\Manual\RenderJobData;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\TriggerRenderRequest;
use App\Http\Resources\Manual\RenderJobResource;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\RenderJobService;
use App\Services\Render\RenderObjectStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * レンダ/プレビューのトリガー (store / preview)、job 状態ポーリング (show)、
 * preview 再生 (playback)。doc/10 §10.3 / 概念設計 §2。
 * トリガーは同一オリジン XHR (JSON 応答)。409/402/422 契約のため JsonResource を返す。
 *
 * 権限分離 (概念設計 Round 1 Critical): ポーリング (view = 撮影者も可) は進捗のみで
 * **署名 URL を一切含めない**。preview 再生は playback route (render ability = 編集者専用)。
 *
 * nested route の URL 整合は ManualAnalysisController と同じ 2 層 (認可より前に 404):
 * 1. {project} ∈ current org (resolveOrganizationProject = inline guard)
 * 2. {manual} ∈ {project}、{renderJob} ∈ {manual} (routes 側の Route::scopeBindings())
 */
class ManualRenderController extends Controller
{
    use ResolvesCurrentOrganization;

    /** 完成レンダトリガー (201 + RenderJobResource)。編集者のみ。保護キー直送は 422 */
    public function store(TriggerRenderRequest $request, Project $project, VideoManual $manual, RenderJobService $render): JsonResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('render', $manual);

        // actor = ジョブ実行者 (通知宛先の導出用。Auth から明示的に渡す = payload 不信任)
        $actor = $request->user();
        $job = $render->trigger($project, $manual, $actor instanceof User ? $actor : null);
        $manual->refresh(); // trigger で rendering へ遷移済み

        return RenderJobResource::make(RenderJobData::fromJob($job, $manual))
            ->response($request)
            ->setStatusCode(201);
    }

    /** プレビュートリガー (201 + RenderJobResource)。チケット非消費・status 遷移なし */
    public function preview(TriggerRenderRequest $request, Project $project, VideoManual $manual, RenderJobService $render): JsonResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('render', $manual); // preview も編集者専用 (§10.5)

        $actor = $request->user();
        $job = $render->triggerPreview($project, $manual, $actor instanceof User ? $actor : null);
        $manual->refresh();

        return RenderJobResource::make(RenderJobData::fromJob($job, $manual))
            ->response($request)
            ->setStatusCode(201);
    }

    /** job 状態ポーリング (撮影者も read 可。成果物 URL は含めない = 権限分離) */
    public function show(Request $request, Project $project, VideoManual $manual, RenderJob $renderJob): RenderJobResource
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        // {renderJob} ∈ {manual} は scopeBindings が担保済み。inline 再検査は二重防御
        if ($renderJob->video_manual_id !== $manual->id) {
            abort(404);
        }
        Gate::authorize('view', $manual);

        return RenderJobResource::make(RenderJobData::fromJob($renderJob, $manual));
    }

    /**
     * preview 再生 (302 → S3 署名 URL)。編集者専用 (render ability)。
     * 404 条件: kind!=preview / succeeded でない / output_path NULL / 最新 succeeded でない
     * (旧世代は実体削除済みの可能性があるため。世代 1 保持の契約と整合)。
     */
    public function playback(Request $request, Project $project, VideoManual $manual, RenderJob $renderJob, RenderObjectStorage $storage): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        if ($renderJob->video_manual_id !== $manual->id) {
            abort(404);
        }
        Gate::authorize('render', $manual); // preview は編集者専用機能

        if ($renderJob->kind !== RenderKind::Preview
            || $renderJob->status !== JobStatus::Succeeded
            || $renderJob->output_path === null
            || ! $this->isLatestSucceededPreview($manual, $renderJob)) {
            abort(404);
        }

        return redirect()->away($storage->temporaryPlaybackUrl($renderJob->output_path));
    }

    /** 当該 job が同 manual の preview の最新 succeeded job か */
    private function isLatestSucceededPreview(VideoManual $manual, RenderJob $renderJob): bool
    {
        return ! $manual->renderJobs()
            ->where('kind', RenderKind::Preview->value)
            ->where('status', JobStatus::Succeeded->value)
            ->where('id', '>', $renderJob->id)
            ->exists();
    }
}
