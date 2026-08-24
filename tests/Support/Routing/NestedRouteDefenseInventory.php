<?php

declare(strict_types=1);

namespace Tests\Support\Routing;

use App\Enums\Security\NestedRouteDefenseMode;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/**
 * route parameter ごとの IDOR / 存在オラクル 防御方式の inventory (単一 source of truth)。
 *
 * 2 つの Architecture テストが同じ inventory を読むため、Pest のファイル読み込み順に
 * 依存する global 関数ではなく静的クラスに置く:
 *   - NestedRouteIdorDefenseTest    … 分類漏れ・stale・理由の突合 (deny-by-default)
 *   - TenantBoundaryOrderingTest    … モードごとの解決後 middleware 順序不変条件
 *
 * **母集団は 1 個以上の parameter を持つ named route** (旧実装は 2 個以上だった)。
 * 2+param に絞ると単独 param の route (`projects/{project}` / `user/passkeys/{passkey}` 等) が
 * 母集団から丸ごと外れ、テナント越境が分類対象にならない。audit-cycle-2 High-1 で
 * 実際に穴が残ったのはこの層である。
 *
 * **分類は route 単位ではなく parameter 単位**。同じ param 名でも route ごとに防御方式が
 * 違いうる (例: {user} は organizations.members.* が scopeBindings、
 * projects.members.destroy が手動解決)。route 単位の allowlist は param 1 つの分類漏れを
 * 丸ごと免除してしまうため使わない。
 */
final class NestedRouteDefenseInventory
{
    /**
     * route 名 => (parameter 名 => 防御方式)。
     *
     * @return array<string, array<string, NestedRouteDefenseMode>>
     */
    public static function inventory(): array
    {
        $scoped = NestedRouteDefenseMode::ScopeBindings;
        $binder = NestedRouteDefenseMode::ScopedBinder;
        $tenant = NestedRouteDefenseMode::TenantGuardMiddleware;
        $manual = NestedRouteDefenseMode::ManualOwnerScopedResolution;
        $nonRes = NestedRouteDefenseMode::NonResourceParameter;
        $publicGlobal = NestedRouteDefenseMode::PublicGlobalResource;

        // {project} は web/API とも テナント guard middleware が binding 直後に走る (T108 S2)
        $project = ['project' => $tenant];
        // 業務 route は組織 URL 配下にある (家系裁定 AG-037)。親の {organization} は
        // MembershipScopedOrganizationBinder が membership スコープで解決する
        $org = ['organization' => $binder];
        // web の {project} route は必ず {organization} を親に持つ
        $orgProject = [...$org, ...$project];

        return [
            // --- REST API v1 ---
            'api.v1.projects.show' => $project,
            'api.v1.projects.items.index' => $project,
            'api.v1.projects.items.store' => $project,
            // {item} は $project->items() 経由 (scopeBindings)
            'api.v1.projects.items.update' => [...$project, 'item' => $scoped],
            'api.v1.projects.items.destroy' => [...$project, 'item' => $scoped],

            // --- 撮影 PWA (/app/*。{manual}∈{project}, {cut}∈{manual}, {take}∈{cut}) ---
            'capture.manuals.index' => $orgProject,
            'capture.manuals.show' => [...$orgProject, 'manual' => $scoped],
            'capture.takes.upload-url' => [...$orgProject, 'manual' => $scoped, 'cut' => $scoped],
            'capture.takes.store' => [...$orgProject, 'manual' => $scoped, 'cut' => $scoped],
            'capture.takes.update' => [...$orgProject, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
            'capture.takes.destroy' => [...$orgProject, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
            'capture.takes.adopt' => [...$orgProject, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
            'capture.takes.downloaded' => [...$orgProject, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
            'capture.takes.playback' => [...$orgProject, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
            'capture.takes.thumbnail' => [...$orgProject, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],

            // --- 業務 route (web) ---
            'projects.show' => $orgProject,
            'projects.edit' => $orgProject,
            'projects.update' => $orgProject,
            'projects.destroy' => $orgProject,
            'projects.items.store' => $orgProject,
            'projects.items.update' => [...$orgProject, 'item' => $scoped],
            'projects.items.destroy' => [...$orgProject, 'item' => $scoped],
            'projects.categories.index' => $orgProject,
            'projects.categories.store' => $orgProject,
            'projects.categories.reorder' => $orgProject,
            'projects.categories.update' => [...$orgProject, 'category' => $scoped],
            'projects.categories.destroy' => [...$orgProject, 'category' => $scoped],
            'projects.manuals.create' => $orgProject,
            'projects.manuals.store' => $orgProject,
            'projects.manuals.show' => [...$orgProject, 'manual' => $scoped],
            'projects.manuals.edit' => [...$orgProject, 'manual' => $scoped],
            'projects.manuals.update' => [...$orgProject, 'manual' => $scoped],
            'projects.manuals.destroy' => [...$orgProject, 'manual' => $scoped],
            'projects.manuals.duplicate' => [...$orgProject, 'manual' => $scoped],
            // {cut} は $manual->cuts() 経由 (PC テイク選択画面)
            'projects.manuals.cuts.takes.index' => [...$orgProject, 'manual' => $scoped, 'cut' => $scoped],
            'projects.manuals.scenario.update' => [...$orgProject, 'manual' => $scoped],
            'projects.manuals.source-documents.store' => [...$orgProject, 'manual' => $scoped],
            'projects.manuals.analyze' => [...$orgProject, 'manual' => $scoped],
            // {analysisJob} は $manual->analysisJobs() 経由
            'projects.manuals.jobs.show' => [...$orgProject, 'manual' => $scoped, 'analysisJob' => $scoped],
            'projects.manuals.render' => [...$orgProject, 'manual' => $scoped],
            'projects.manuals.preview' => [...$orgProject, 'manual' => $scoped],
            'projects.manuals.download' => [...$orgProject, 'manual' => $scoped],
            // {renderJob} は $manual->renderJobs() 経由
            'projects.manuals.render-jobs.show' => [...$orgProject, 'manual' => $scoped, 'renderJob' => $scoped],
            'projects.manuals.render-jobs.playback' => [...$orgProject, 'manual' => $scoped, 'renderJob' => $scoped],
            'projects.members.store' => $orgProject,
            // {user} は ProjectMemberController::destroy が $organization->users() から手動解決する
            // (implicit binding を外して不在 id と実在の非メンバーを同一経路に落とす。T108 S3-b)
            'projects.members.destroy' => [...$orgProject, 'user' => $manual],

            // --- 組織 (親 {organization} は MembershipScopedOrganizationBinder が membership スコープで解決) ---
            'organizations.settings' => $org,
            'organizations.update' => $org,
            'organizations.slug.update' => $org,
            'organizations.onboarding.cli' => ['organization' => $binder],
            'organizations.onboarding.mcp' => ['organization' => $binder],
            'organizations.transfer-ownership' => ['organization' => $binder],
            'organizations.two-factor-requirement.update' => ['organization' => $binder],
            'organizations.invitations.store' => ['organization' => $binder],
            // {invitation} は $organization->invitations() 経由
            'organizations.invitations.revoke' => ['organization' => $binder, 'invitation' => $scoped],
            'organizations.api-keys.index' => ['organization' => $binder],
            'organizations.api-keys.store' => ['organization' => $binder],
            // {apiKey} は $organization->apiKeys() 経由
            'organizations.api-keys.revoke' => ['organization' => $binder, 'apiKey' => $scoped],
            'organizations.api-keys.sessions.index' => ['organization' => $binder],
            // {oauthSession} は $organization->oauthSessions() 経由 (controller 内の再検査は二重防御)
            'organizations.api-keys.sessions.revoke' => ['organization' => $binder, 'oauthSession' => $scoped],
            // {user} は $organization->users() 経由 (scopeBindings。T108 S3-a)
            'organizations.members.update' => ['organization' => $binder, 'user' => $scoped],
            'organizations.members.destroy' => ['organization' => $binder, 'user' => $scoped],
            'organizations.members.two-factor.reset' => ['organization' => $binder, 'user' => $scoped],

            // --- 企業 OIDC SSO 接続 (T253) ---
            // {oidcConnection} は $organization->oidcConnections() 経由 (scopeBindings)
            'organizations.sso.index' => ['organization' => $binder],
            'organizations.sso.store' => ['organization' => $binder],
            'organizations.sso.update' => ['organization' => $binder, 'oidcConnection' => $scoped],
            'organizations.sso.verify' => ['organization' => $binder, 'oidcConnection' => $scoped],
            'organizations.sso.activate' => ['organization' => $binder, 'oidcConnection' => $scoped],
            'organizations.sso.disable' => ['organization' => $binder, 'oidcConnection' => $scoped],
            'organizations.sso.destroy' => ['organization' => $binder, 'oidcConnection' => $scoped],
            // 公開のログイン導線。{connection} は**組織に属さない全体一意の識別名**であり、
            // テナント親子関係の対象にならない (理由は nonTenantReasons)
            'enterprise-sso.redirect' => ['connection' => $publicGlobal],

            // --- 通知センター (組織 URL 配下。一覧は全 org 横断だが URL は組織を持つ) ---
            'notifications.index' => $org,
            'notifications.read-all' => $org,
            // {notification} は NotificationController が $user->notifications() から手動解決する
            'notifications.open' => [...$org, 'notification' => $manual],
            'notifications.read' => [...$org, 'notification' => $manual],

            // --- 組織 URL 配下の単独 param route (親は {organization} だけ) ---
            'dashboard' => $org,
            'manage.users.index' => $org,
            'projects.index' => $org,
            'projects.create' => $org,
            'projects.store' => $org,
            'capture.home' => $org,
            'capture.account' => $org,
            'capture.csrf-cookie' => $org,
            'billing.index' => $org,
            'billing.plans' => $org,
            'billing.checkout' => $org,
            'billing.plan.change' => $org,
            'billing.portal' => $org,
            'billing.contact.update' => $org,
            'billing.auto-recharge.update' => $org,
            'billing.auto-recharge.setup' => $org,
            'billing.tickets.show' => $org,
            'billing.tickets.checkout' => $org,
            'onboarding.checkout' => $org,
            'onboarding.activate-personal' => $org,
            'onboarding.billing-required' => $org,

            // --- 個人スコープ ---
            // {passkey} は SelfScopedPasskeyBinder が認証ユーザーの passkeys() スコープで解決する
            'passkey.destroy' => ['passkey' => $binder],
            // {invitation} は AcceptInvitationInAppController が認証ユーザー宛の有効 pending 集合
            // (scopeActivePendingForEmail) から手動解決する。不在 id / 他人宛 / 期限切れ /
            // 取消済 / 削除済み組織宛はすべて同一の 404 に畳まれる (存在オラクル封じ)
            'invitations.accept-in-app' => ['invitation' => $manual],

            // --- テナント親子でない param (理由は nonTenantReasons に必須登録) ---
            'social.callback' => ['provider' => $nonRes],
            'social.redirect' => ['provider' => $nonRes, 'intent' => $nonRes],
            'verification.verify' => ['id' => $nonRes, 'hash' => $nonRes],
            'password.reset' => ['token' => $nonRes],
            'debug.login-as' => ['userId' => $nonRes],
            'mcp.oauth.authorization-server.nested' => ['path' => $nonRes],
            'mcp.oauth.protected-resource.nested' => ['path' => $nonRes],
            'storage.local' => ['path' => $nonRes],
            'storage.local.upload' => ['path' => $nonRes],
        ];
    }

    /**
     * 非テナントモード (NonResourceParameter / PublicGlobalResource) を宣言した parameter の理由。
     *
     * key は `{route名}#{parameter名}`。**非テナント宣言は必ずここに理由を持つ**
     * (逆に、ここに理由があるのに宣言がテナント防御モードなら stale として fail)。
     * これは例外機構ではなく「テナント防御が要らないと判断した根拠を残す」ための
     * deny-by-default sentinel であり、判断の誤りを人間のレビューに晒す唯一の場所である。
     *
     * @return array<string, string>
     */
    public static function nonTenantReasons(): array
    {
        return [
            'social.callback#provider' => 'config 由来の固定集合 (有効な OAuth provider 名) で検証される。テナント親子でない',
            'social.redirect#provider' => 'config 由来の固定集合で検証される。テナント親子でない',
            'social.redirect#intent' => 'ログイン/連携の 2 値 intent。リソース id ではない',
            'verification.verify#id' => 'Fortify の署名付き URL (MustVerifyEmail) の構成要素。署名検証が改ざんを閉じる',
            'verification.verify#hash' => 'email hash。署名付き URL の構成要素でリソース id ではない',
            'password.reset#token' => 'Fortify のパスワードリセットトークン。リソース id ではない',
            'debug.login-as#userId' => 'local 専用のデバッグログイン (route 登録自体が isLocal/runningUnitTests 限定 + LocalOnly middleware)。テナント親子でない',
            'mcp.oauth.authorization-server.nested#path' => 'vendor (laravel/mcp) の OAuth discovery。任意の後続セグメントでリソース id ではない',
            'mcp.oauth.protected-resource.nested#path' => 'vendor (laravel/mcp) の OAuth discovery。任意の後続セグメントでリソース id ではない',
            'storage.local#path' => 'Laravel の local disk 配信 route。署名付き URL でファイルパスを受ける (リソース id ではない)',
            'storage.local.upload#path' => 'Laravel の local disk アップロード route。署名付き URL でファイルパスを受ける',
            'enterprise-sso.redirect#connection' => '企業ログインの公開導線の識別名。組織に属さない全体一意の値で、'
                .'推測されてよい (防御は接続の状態と state / PKCE / ブラウザ結合が担う)。'
                .'不在の識別名と使えない接続は PublicOidcConnectionBinder が同じ 404 に畳む',
        ];
    }

    /**
     * parameterNames >= 1 の候補 route (パッケージ内部 route は除外)。
     *
     * パッケージ管理ルート (Filament/Livewire/Passport/Cashier 内部) はパッケージ側が防御を
     * 担うため対象外。アプリが定義するルートのみ検査する。
     *
     * @return list<RoutingRoute>
     */
    public static function candidates(): array
    {
        $candidates = [];
        foreach (Route::getRoutes() as $route) {
            if ($route->parameterNames() === []) {
                continue;
            }
            if (str_starts_with($route->uri(), 'livewire')) {
                continue;
            }
            $name = $route->getName();
            if ($name !== null && (str_starts_with($name, 'filament.')
                || str_starts_with($name, 'livewire.')
                || str_starts_with($name, 'passport.')
                || str_starts_with($name, 'cashier.'))) {
                continue;
            }

            $candidates[] = $route;
        }

        return $candidates;
    }

    /**
     * inventory に登録された名前を持つ現存 route (名前 => route)。
     *
     * @return array<string, RoutingRoute>
     */
    public static function registeredRoutes(): array
    {
        $inventory = self::inventory();
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if ($name !== null && array_key_exists($name, $inventory)) {
                $routes[$name] = $route;
            }
        }

        return $routes;
    }

    /**
     * テナント防御モードを 1 つ以上宣言した route (名前 => route)。
     *
     * @return array<string, RoutingRoute>
     */
    public static function tenantDefenseRoutes(): array
    {
        $inventory = self::inventory();

        return array_filter(
            self::registeredRoutes(),
            static function (RoutingRoute $route) use ($inventory): bool {
                foreach ($inventory[(string) $route->getName()] as $mode) {
                    if ($mode->isTenantDefense()) {
                        return true;
                    }
                }

                return false;
            },
        );
    }

    /**
     * 解決後 (priority 適用後) の middleware 列。
     *
     * `Class:param` 形式の parameter を落とし、alias 解決後の具象クラス名で返す。
     * closure 要素は分類不能を示す `(closure)` に写す。
     *
     * @return list<string>
     */
    public static function resolvedMiddleware(RoutingRoute $route): array
    {
        /** @var Router $router */
        $router = app('router');

        return array_values(array_map(
            static fn (mixed $middleware): string => is_string($middleware)
                ? explode(':', $middleware, 2)[0]
                : '(closure)',
            $router->gatherRouteMiddleware($route),
        ));
    }
}
