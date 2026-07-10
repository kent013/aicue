<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\DataTransferObjects\OAuth\OAuthSessionListItemDto;
use App\Http\Controllers\Controller;
use App\Models\OauthSession;
use App\Models\Organization;
use App\Services\OAuth\OauthSessionListService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 組織の OAuth セッション (CLI/MCP 接続) の一覧・失効 (組織管理経路)。
 *
 * API キー管理画面と同一の管理ドメインの「接続セッション」タブとして提供する
 * (`/organizations/{slug}/api-keys/sessions`)。認可 = OauthSessionPolicy::manageForOrganization
 * (API キー管理と同一境界)。クロステナント秘匿 (非メンバー / 他組織 = 404) は
 * MembershipScopedOrganizationBinder + scopeBindings の routing 層が担う。
 *
 * 一覧は CLI セッション (失効可) と legacy MCP token (session 無し = 棚卸し表示のみ) を
 * 併記する。
 */
class OrganizationOauthSessionController extends Controller
{
    /** 接続セッション一覧 (manageForOrganization = owner / admin または直接付与メンバー)。 */
    public function index(Organization $organization, OauthSessionListService $sessionList): Response
    {
        Gate::authorize('manageForOrganization', [OauthSession::class, $organization]);

        $items = $sessionList->listForOrganization($organization);

        $sessions = array_values(array_filter(
            array_map(static fn (OAuthSessionListItemDto $dto): array => $dto->toArray(), $items),
            static fn (array $row): bool => $row['isLegacy'] === false,
        ));
        $legacyTokens = array_values(array_filter(
            array_map(static fn (OAuthSessionListItemDto $dto): array => $dto->toArray(), $items),
            static fn (array $row): bool => $row['isLegacy'] === true,
        ));

        return Inertia::render('Organizations/ApiKeys/Sessions', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'sessions' => $sessions,
            'legacyTokens' => $legacyTokens,
        ]);
    }

    public function destroy(Organization $organization, OauthSession $oauthSession): RedirectResponse
    {
        Gate::authorize('manageForOrganization', [OauthSession::class, $organization]);

        // 二重防御: scopeBindings が子→親不一致を routing 層で 404 にするが、
        // 将来の配線 revert に備え controller でも fail-secure 404 + report で検出する。
        if ($oauthSession->organization_id !== $organization->id) {
            report(new \LogicException(
                "Route::scopeBindings invariant violated: session {$oauthSession->id} not in organization {$organization->id}",
            ));
            abort(404);
        }

        $oauthSession->revoke();

        return back()->with('success', 'セッションを失効しました。配下のトークンは以降拒否されます。');
    }
}
