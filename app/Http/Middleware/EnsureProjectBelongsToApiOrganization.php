<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiOrganization;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST API v1 の `{project}` route の URL 整合 guard (middleware 層)。alias: api.project-in-org。
 *
 * web の {@see EnsureProjectBelongsToRouteOrganization} と同じ順序ハザードを API 側で閉じる。
 * cross-org の {project} を「FormRequest のバリデーションを含むあらゆるアプリコードより前に
 * 404」へ落とす。controller の inline guard (resolveOrganizationProject) は認可より前の 404 を
 * 担うが、**FormRequest は controller メソッド解決時 = inline guard より前**に走るため、
 * middleware が無いと「cross-org の実在 project + 不正 payload = 422」「不在 project = 404」の
 * 差分が存在オラクルになる (不変条件 3)。
 *
 * web 版との違いは組織の解決元だけ:
 *  - web: セッションの current org (ResolvesRouteOrganization)
 *  - API: API キー / OAuth token から確定した request attribute 'organization'
 *         (ApiKeyGuard / ResolveApiActor が注入。ResolvesApiOrganization::resolveOrganization)
 *
 * 順序契約 (**宣言順ではなく bootstrap/app.php の priority list が正本**):
 *   auth → throttle → resolve.api-actor → SubstituteBindings
 *     → **api.project-in-org** → api-key.ability → idempotent → controller
 * `organization` attribute が前提のため **resolve.api-actor より後**、
 * ability 不足時に cross-org の実在を 403 で漏らさないため **api-key.ability より前**、
 * cross-org リクエストで idempotency 行を作らせないため **idempotent より前**に置く。
 * とりわけ **SubstituteBindings の直後**であることが本質で、間に 404 以外で短絡する
 * middleware があると「他組織に実在 = その短絡の応答 / 不在 = binding の 404」という
 * 1 bit の存在オラクルになる (audit-cycle-2 High-1)。
 * {project} を持たない route では no-op (group 一括付与を許容し、将来の route 追加時の
 * guard 漏れを防ぐ)。
 *
 * 網羅性と順序は tests/Architecture/ProjectRouteCurrentOrgGuardTest と
 * tests/Architecture/TenantBoundaryOrderingTest が deny-by-default で固定する。
 * controller の inline guard は二重防御として残す (middleware の付け漏れ・
 * withoutMiddleware への最終防衛線)。
 */
class EnsureProjectBelongsToApiOrganization
{
    use ResolvesApiOrganization;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        if ($project instanceof Project) {
            $organization = $this->resolveOrganization($request);
            $this->resolveOrganizationProject($organization, $project);
        }

        return $next($request);
    }
}
