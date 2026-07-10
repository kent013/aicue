<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\DownloadManualRequest;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\VideoManual;
use App\Services\Render\RenderObjectStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * 完成 mp4 のダウンロード (302 → S3 署名 URL。attachment disposition)。download ability。
 * v1 の完成動画取得は本 route のみ (published のインライン再生はスコープ外 = 概念設計 §2)。
 * JSON を返さないため DTO/JsonResource 規約の対象外 (redirect のみ)。
 */
class ManualDownloadController extends Controller
{
    use ResolvesCurrentOrganization;

    public function show(DownloadManualRequest $request, Project $project, VideoManual $manual, RenderObjectStorage $storage): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('download', $manual);

        // 完成物が存在しない (published でない / succeeded render なし) は 404 (409 系ではない)
        if ($manual->status !== VideoManualStatus::Published) {
            abort(404);
        }
        /** @var RenderJob|null $job */
        $job = $manual->renderJobs()
            ->where('kind', RenderKind::Render->value)
            ->where('status', JobStatus::Succeeded->value)
            ->whereNotNull('output_path')
            ->latest('id')
            ->first();
        if ($job === null || $job->output_path === null) {
            abort(404);
        }

        // filename の sanitize (CR/LF 除去・RFC 5987 + ASCII fallback) は Storage 側 helper が担う
        $filename = $manual->title.'.mp4';

        return redirect()->away($storage->temporaryDownloadUrl($job->output_path, $filename));
    }
}
