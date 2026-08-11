<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\DataTransferObjects\Manual\RenderJobData;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\TriggerRenderRequest;
use App\Http\Resources\Manual\RenderJobResource;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\CurrentRenderArtifact;
use App\Services\Manual\RenderJobService;
use App\Services\Render\RenderObjectStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * レンダ/プレビューのトリガー (store / preview)、job 状態ポーリング (show)、
 * 成果物再生 (playback = preview と完成動画の両方)。doc/10 §10.3 / 概念設計 §2。
 * トリガーは同一オリジン XHR (JSON 応答)。409/402/422 契約のため JsonResource を返す。
 *
 * 権限分離 (概念設計 Round 1 Critical): ポーリング (view = 撮影者も可) は進捗のみで
 * **署名 URL を一切含めない**。再生は playback route が成果物の性質に合う ability を評価する
 * (preview → render / 完成動画 → download。どちらも編集者専用 = T154)。
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
     * 成果物再生 (302 → S3 署名 URL)。preview と完成動画の**両方**を扱う。
     *
     * 層は 3 段で、**すべて認可より前に 404** (AGENTS.md セキュリティ不変条件 2/10):
     *   1. {project} ∈ current org … project.in-current-org middleware + inline guard
     *   2. {manual}  ∈ {project}   … routes 側 Route::scopeBindings()
     *   3. {renderJob} ∈ {manual}  … scopeBindings + 下の inline 再検査 (二重防御)
     * その後に **成果物の性質に合う ability** を評価する:
     *   kind=preview → render ability / kind=render → download ability
     *   (現行はどちらも ProjectPolicy::update に落ちるため**可否は完全に同値**。
     *    UI 側の canManage が自動追従するという意味ではない = 誇張しない)
     * 完成動画だけ published を要求するのは download と同一条件にするため (順序も download と同じ
     * = authorize の後)。最後に「いま受け取れる成果物」と同一行かを照合する
     * (旧世代 job id の直叩き・未完了・実体削除済みはここで 404)。
     */
    public function playback(Request $request, Project $project, VideoManual $manual, RenderJob $renderJob, RenderObjectStorage $storage): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        if ($renderJob->video_manual_id !== $manual->id) {
            abort(404);
        }
        // 2 値 enum の網羅 match (到達不能な fallback を作らない)
        Gate::authorize(match ($renderJob->kind) {
            RenderKind::Preview => 'render',
            RenderKind::Render => 'download',
        }, $manual);

        // 完成動画は「公開中のマニュアルの現行版」だけ (download と同条件・同順序)
        if ($renderJob->kind === RenderKind::Render && $manual->status !== VideoManualStatus::Published) {
            abort(404);
        }

        $current = CurrentRenderArtifact::currentSucceeded($manual, $renderJob->kind);
        if ($current === null || $current->id !== $renderJob->id) {
            abort(404); // 未完了 / 旧世代 / 実体削除済み
        }
        $path = $current->output_path;
        if ($path === null) {
            abort(404); // currentSucceeded の契約上到達しないが、型を締めるため明示する
        }

        return redirect()->away($storage->temporaryPlaybackUrl($path));
    }
}
