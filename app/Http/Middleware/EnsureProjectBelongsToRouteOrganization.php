<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Webmozart\Assert\Assert;

/**
 * web の `{project}` route の URL 整合 guard (middleware 層)。alias: project.in-route-org。
 *
 * cross-org の {project} を「FormRequest の DB ルールを含むあらゆるアプリコードより前に 404」
 * へ構造的に落とす。controller の inline guard (resolveOrganizationProject) は認可より前の
 * 404 を担うが、FormRequest のバリデーションは controller メソッド解決時 = inline guard より
 * **前**に走るため、project スコープの DB ルール (categories.name の unique / category の
 * exists 等) が cross-org プロジェクトに対する 422/404 差分の存在オラクルになる。
 * middleware は FormRequest 解決より前 (SubstituteBindings の後) に走るため、
 * この順序ハザードを route group 単位で構造的に閉じる。
 *
 * ★組織は **URL の binding からのみ**取る (家系裁定 AG-037)。保持列は撤去済みである。
 *   組織が URL に無いのに `{project}` がある状態は**配線ミス**なので fail-closed (500) にする —
 *   黙って素通しすると cross-org が開く。
 *
 * 適用境界:
 *  - routes/web.php の組織配下の業務 route group (require-active-subscription とセット) に
 *    付与する。{project} param を持たない route では no-op (group 一括付与を許容し、将来の
 *    project 配下 route 追加時の guard 漏れを防ぐ)。
 *  - 網羅性は tests/Architecture/ProjectRouteCurrentOrgGuardTest が deny-by-default で固定する
 *    (web の {project} route は必ず本 middleware と `{organization}` param を持つ / API は持たない)。
 *  - API v1 は org を API キーから確定する別レイヤー (ResolvesApiOrganization) の責務のため
 *    対象外 (web の URL 由来の組織を前提とする本 middleware を付けてはならない)。
 *  - controller の inline guard は二重防御として残す (oauthSessions の controller 内再検査と
 *    同じ位置づけ。middleware の付け漏れ・withoutMiddleware への最終防衛線)。
 */
class EnsureProjectBelongsToRouteOrganization
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        if ($project instanceof Project) {
            $organization = $request->route('organization');
            // 組織が URL に無いのに {project} がある = 配線ミス。fail-closed (500)。
            Assert::isInstanceOf($organization, Organization::class);
            abort_unless($organization->projects()->whereKey($project->getKey())->exists(), 404);
        }

        return $next($request);
    }
}
