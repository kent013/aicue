<?php

declare(strict_types=1);

namespace App\Http\Controllers\Capture;

use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Capture\SyncCaptureTakesRequest;
use App\Http\Resources\Capture\CaptureSyncResultResource;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Capture\CaptureSyncService;
use Illuminate\Support\Facades\Gate;
use Webmozart\Assert\Assert;

/**
 * 一括同期 (照合専用。doc/10 §10.3)。同一オリジン XHR (JSON 応答)。書き込みしない。
 */
class CaptureSyncController extends Controller
{
    use ResolvesCurrentOrganization;

    public function store(
        SyncCaptureTakesRequest $request,
        Project $project,
        VideoManual $manual,
        CaptureSyncService $sync,
    ): CaptureSyncResultResource {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
        Gate::authorize('view', $manual);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        return CaptureSyncResultResource::make(
            $sync->reconcile($user, $manual, $request->toCaptureSyncInput()),
        );
    }
}
