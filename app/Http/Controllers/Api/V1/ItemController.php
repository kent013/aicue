<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ReadsApiActor;
use App\Http\Controllers\Api\V1\Concerns\ResolvesApiOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\StoreItemRequest;
use App\Http\Requests\Projects\UpdateItemRequest;
use App\Http\Resources\Api\V1\ItemResource;
use App\Models\Item;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;
use Webmozart\Assert\Assert;

/**
 * REST API v1 の Item CRUD (nested route: /projects/{project}/items)。
 *
 * FormRequest は web と同じ StoreItemRequest / UpdateItemRequest を再利用する
 * (ProhibitsProtectedKeys = project_id を payload で送ると 422)。
 * URL 整合 guard 2 段 ({project} ∈ org, {item} ∈ project) はいずれも認可より前に 404。
 *
 * 変更系 (store/update/destroy) の認可は `Gate::forUser(...)` 経由で、web 側
 * {@see \App\Http\Controllers\Projects\ItemController} と同一の ItemPolicy 境界に揃える。
 * ★`Gate::authorize` は使えない: dual guard (auth:api-key,api-oauth) は通過した guard を
 *   default に昇格させるため、API キー経路では Auth::user() が App\Models\ApiKey を返し
 *   ItemPolicy::create(User $user, ...) が TypeError = HTTP 500 になる。
 *   認可主体は resolve.api-actor が解決済みの ApiActorContext::$user
 *   (API キー = 発行者 / OAuth = トークン所有者。非 null 保証) を明示的に渡す。
 * 契約は tests/Feature/Api/V1/ItemAuthorizationTest と
 * tests/Architecture/ControllerAuthorizationGateTest が固定する。
 */
class ItemController extends Controller
{
    use ReadsApiActor;
    use ResolvesApiOrganization;

    /** GET /api/v1/projects/{project}/items */
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        $organization = $this->resolveOrganization($request);
        $this->resolveOrganizationProject($organization, $project);

        $items = $project->items()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return ItemResource::collection($items);
    }

    /** POST /api/v1/projects/{project}/items — 親 FK は URL から導出し relation 経由で代入 */
    public function store(StoreItemRequest $request, Project $project): JsonResponse
    {
        $organization = $this->resolveOrganization($request);
        // URL 整合 guard: 認可より前に 404 (cross-org を 403 で漏らさない)
        $this->resolveOrganizationProject($organization, $project);
        Gate::forUser($this->apiActor($request)->user)
            ->authorize('create', [Item::class, $project]);

        $name = $request->validated('name');
        Assert::string($name);
        $note = $request->validated('note');
        Assert::nullOrString($note);

        $item = $project->items()->create(['name' => $name, 'note' => $note]);

        return ItemResource::make($item)->response()->setStatusCode(201);
    }

    /** PATCH /api/v1/projects/{project}/items/{item} */
    public function update(UpdateItemRequest $request, Project $project, Item $item): JsonResponse
    {
        $organization = $this->resolveOrganization($request);
        // URL 整合 guard 2 段: いずれも認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        $this->resolveProjectItem($project, $item);
        Gate::forUser($this->apiActor($request)->user)->authorize('update', $item);

        $name = $request->validated('name');
        Assert::string($name);
        $note = $request->validated('note');
        Assert::nullOrString($note);

        $item->fill(['name' => $name, 'note' => $note])->save();

        return ItemResource::make($item)->response();
    }

    /**
     * DELETE /api/v1/projects/{project}/items/{item}
     * Idempotency-Key の再生対象にするため 204 ではなく JSON body を返す。
     */
    public function destroy(Request $request, Project $project, Item $item): JsonResponse
    {
        $organization = $this->resolveOrganization($request);
        $this->resolveOrganizationProject($organization, $project);
        $this->resolveProjectItem($project, $item);
        Gate::forUser($this->apiActor($request)->user)->authorize('delete', $item);

        $item->delete();

        return JsonResource::make(['deleted' => true])->response();
    }
}
