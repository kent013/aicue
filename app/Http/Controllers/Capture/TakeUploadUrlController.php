<?php

declare(strict_types=1);

namespace App\Http\Controllers\Capture;

use App\Http\Concerns\ResolvesRouteOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Capture\StoreTakeUploadUrlRequest;
use App\Http\Resources\Capture\TakeUploadTicketResource;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\VideoManual;
use App\Services\Capture\TakeUploadService;
use Illuminate\Support\Facades\Gate;

/**
 * presigned upload-url 発行 (doc/10 §10.3)。同一オリジン XHR (JSON 応答)。
 *
 * nested route の URL 整合は 2 層 (認可より前に 404):
 * 1. {project} ∈ current org (project.in-route-org middleware + resolveOrganizationProject)
 * 2. {manual} ∈ {project}, {cut} ∈ {manual} (Route::scopeBindings())
 */
class TakeUploadUrlController extends Controller
{
    use ResolvesRouteOrganization;

    public function store(
        StoreTakeUploadUrlRequest $request, Organization $organization,
        Project $project,
        VideoManual $manual,
        Cut $cut,
        TakeUploadService $uploads,
    ): TakeUploadTicketResource {
        $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
        Gate::authorize('create', [Take::class, $project]);          // TakePolicy::create → ProjectPolicy::capture

        return TakeUploadTicketResource::make(
            $uploads->issue($organization, $project, $manual, $cut, $request->toTakeUploadInput()),
        );
    }
}
