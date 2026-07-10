<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

/*
 * REST API v1 route の guard 分類を宣言表で固定し drift を検出する (WP24)。
 *
 * 分類:
 *   - 'dual'   : auth:api-key,api-oauth + resolve.api-actor。API キーと OAuth user token の
 *                双方が叩ける全 endpoint。
 *   - 'oauth'  : auth:api-oauth 単独 + resolve.api-actor (API キーにセッション概念なし)。
 *                me.session.revoke。
 *   - 'public' : 認証なし。version。
 *
 * deny-by-default: 新 route 追加時に分類漏れ / 想定外 guard を fail させる。
 * MCP endpoint (/api/v1/mcp, auth:mcp-oauth) は別経路のため対象外。
 */

/**
 * @return array<string, 'dual'|'oauth'|'public'>
 */
function expectedApiGuardClass(): array
{
    return [
        'api.v1.version' => 'public',

        // dual guard (API キー + OAuth user token)
        'api.v1.me' => 'dual',
        'api.v1.projects.index' => 'dual',
        'api.v1.projects.show' => 'dual',
        'api.v1.projects.items.index' => 'dual',
        'api.v1.projects.items.store' => 'dual',
        'api.v1.projects.items.update' => 'dual',
        'api.v1.projects.items.destroy' => 'dual',

        // OAuth 単独
        'api.v1.me.session.revoke' => 'oauth',
    ];
}

/**
 * @return list<string>
 */
function namedApiV1Routes(): array
{
    $names = [];
    foreach (RouteFacade::getRoutes() as $route) {
        /** @var Route $route */
        $name = $route->getName();
        if ($name === null || ! str_starts_with($name, 'api.v1.')) {
            continue;
        }
        // MCP は別経路 (auth:mcp-oauth)、分類対象外。
        if (str_contains($route->uri(), 'mcp')) {
            continue;
        }
        $names[] = $name;
    }

    return $names;
}

it('classifies every api.v1 route in the guard allowlist (drift detection)', function (): void {
    $expected = expectedApiGuardClass();
    $undeclared = [];

    foreach (namedApiV1Routes() as $name) {
        if (! array_key_exists($name, $expected)) {
            $undeclared[] = $name;
        }
    }

    expect($undeclared)->toBe([], 'api.v1 routes not classified in expectedApiGuardClass(): '.implode(', ', $undeclared));
});

/**
 * @return array<string, array{method: string, uri: string}>
 */
function expectedApiRouteSignature(): array
{
    return [
        'api.v1.version' => ['method' => 'GET', 'uri' => 'api/v1/version'],
        'api.v1.me' => ['method' => 'GET', 'uri' => 'api/v1/me'],
        'api.v1.projects.index' => ['method' => 'GET', 'uri' => 'api/v1/projects'],
        'api.v1.projects.show' => ['method' => 'GET', 'uri' => 'api/v1/projects/{project}'],
        'api.v1.projects.items.index' => ['method' => 'GET', 'uri' => 'api/v1/projects/{project}/items'],
        'api.v1.projects.items.store' => ['method' => 'POST', 'uri' => 'api/v1/projects/{project}/items'],
        'api.v1.projects.items.update' => ['method' => 'PATCH', 'uri' => 'api/v1/projects/{project}/items/{item}'],
        'api.v1.projects.items.destroy' => ['method' => 'DELETE', 'uri' => 'api/v1/projects/{project}/items/{item}'],
        'api.v1.me.session.revoke' => ['method' => 'DELETE', 'uri' => 'api/v1/me/session'],
    ];
}

it('fixes each route method + URI (drift detection beyond route name)', function (): void {
    foreach (expectedApiRouteSignature() as $name => $sig) {
        $route = RouteFacade::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route not found: {$name}");
        expect($route->uri())->toBe($sig['uri'], "URI drift on {$name}");
        expect(in_array($sig['method'], $route->methods(), true))->toBeTrue("method drift on {$name}");
    }

    // 逆方向: 宣言 map と guard-class map のキー集合が一致 (どちらかの追加漏れ検出)。
    expect(array_keys(expectedApiRouteSignature()))
        ->toEqualCanonicalizing(array_keys(expectedApiGuardClass()));
});

it('every declared route exists and matches its expected guard class', function (): void {
    foreach (expectedApiGuardClass() as $name => $class) {
        $route = RouteFacade::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("route not found: {$name}");

        $middlewares = collect($route->gatherMiddleware())
            ->filter(fn ($m): bool => is_string($m))
            ->all();

        $hasDualGuard = in_array('auth:api-key,api-oauth', $middlewares, true);
        $hasOauthOnly = in_array('auth:api-oauth', $middlewares, true);
        $hasAnyAuth = collect($middlewares)->contains(
            fn (string $m): bool => $m === 'auth' || str_starts_with($m, 'auth:'),
        );
        $hasResolveActor = in_array('resolve.api-actor', $middlewares, true);

        match ($class) {
            'public' => expect($hasAnyAuth)->toBeFalse("{$name} should be public (no auth middleware)"),
            'dual' => expect($hasDualGuard && $hasResolveActor)
                ->toBeTrue("{$name} should be auth:api-key,api-oauth with resolve.api-actor"),
            'oauth' => expect($hasOauthOnly && ! $hasDualGuard && $hasResolveActor)
                ->toBeTrue("{$name} should be auth:api-oauth (single guard) with resolve.api-actor"),
        };
    }
});
