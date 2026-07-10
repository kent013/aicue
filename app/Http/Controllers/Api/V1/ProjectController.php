<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiOrganization;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * REST API v1 の Project 読み取りエンドポイント。
 *
 * 解決は常に API キーの組織 relation から始める (org-scoped 解決)。
 * cross-org の project は URL 整合 guard で **認可より前に 404**
 * (403 で存在を漏らさない)。
 */
class ProjectController extends Controller
{
    use ResolvesApiOrganization;

    /** GET /api/v1/projects — 自組織のプロジェクト一覧 */
    public function index(Request $request): AnonymousResourceCollection
    {
        $organization = $this->resolveOrganization($request);

        // HasManyThrough (CustomTeam 経由) のため両テーブルに存在するカラムは修飾する
        $projects = $organization->projects()
            ->orderByDesc('projects.created_at')
            ->orderByDesc('projects.id')
            ->paginate(20);

        return ProjectResource::collection($projects);
    }

    /** GET /api/v1/projects/{project} — org-scoped 解決 (cross-org は 404) */
    public function show(Request $request, Project $project): JsonResponse
    {
        $organization = $this->resolveOrganization($request);
        $this->resolveOrganizationProject($organization, $project);

        return ProjectResource::make($project)->response();
    }
}
