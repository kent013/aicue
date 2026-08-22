<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use App\Http\Concerns\ResolvesRouteOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\DownloadManualRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\VideoManual;
use App\Services\Manual\CurrentRenderArtifact;
use App\Services\Render\RenderObjectStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * 完成 mp4 のダウンロード (302 → S3 署名 URL。attachment disposition)。download ability。
 * アプリ内再生 (inline disposition) は playback route が同一条件で担う (T154)。
 * 受け取り対象の選択は CurrentRenderArtifact に集約済み (playback と同一式)。
 * JSON を返さないため DTO/JsonResource 規約の対象外 (redirect のみ)。
 */
class ManualDownloadController extends Controller
{
    use ResolvesRouteOrganization;

    public function show(DownloadManualRequest $request, Organization $organization, Project $project, VideoManual $manual, RenderObjectStorage $storage): RedirectResponse
    {
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('download', $manual);

        // 完成物が存在しない (published でない / succeeded render なし) は 404 (409 系ではない)
        if ($manual->status !== VideoManualStatus::Published) {
            abort(404);
        }
        // 「いま受け取れる完成動画」の選択は CurrentRenderArtifact ただ 1 箇所 (playback と同一式)
        $job = CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render);
        if ($job === null || $job->output_path === null) {
            abort(404); // 完成物が無い / 実体が消えている
        }

        // filename の sanitize (CR/LF 除去・RFC 5987 + ASCII fallback) は Storage 側 helper が担う
        $filename = $manual->title.'.mp4';

        return redirect()->away($storage->temporaryDownloadUrl($job->output_path, $filename));
    }
}
