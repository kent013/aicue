<?php

declare(strict_types=1);

use App\Enums\Security\NestedRouteDefenseMode;
use App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations;
use App\Http\Middleware\BughuntExecutedRouteMiddleware;
use App\Http\Middleware\EnsureAccountNotPendingDeletion;
use App\Http\Middleware\EnsureProjectBelongsToApiOrganization;
use App\Http\Middleware\EnsureProjectBelongsToCurrentOrganization;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\IssueSessionEpochCookie;
use App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages;
use App\Http\Middleware\RequireActiveSubscription;
use App\Http\Middleware\RequireApiKeyAbility;
use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
use App\Http\Middleware\ResolveApiActor;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Routing\RouteBindingTypes;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Inertia\Middleware\EncryptHistory;
use Tests\Support\Routing\MiddlewareShortCircuitInventory;
use Tests\Support\Routing\NestedRouteDefenseInventory;

/**
 * テナント境界 404 の位置に関する順序不変条件 (audit-cycle-2 High-1 / T108 S4)。
 *
 * 不在 id は SubstituteBindings が 404 にする。したがって **binding より後・テナント
 * 境界 404 より前**に 404 以外で短絡する middleware があると、「他組織に実在 = その短絡の
 * 応答 / 不在 = 404」という 1 bit の存在オラクルになる。監査時点では
 * 課金ゲート 402/302・verified 302・2FA 強制 302・Inertia version mismatch 409・
 * api-key.ability 403 のすべてがテナント境界より先に走っていた。
 *
 * 本テストは **解決後 (priority 適用後) の実行順** を測る。宣言順 (gatherMiddleware) を
 * 見ていたことが、audit-cycle-2 で実測されるまで穴が見えなかった直接の原因である。
 * 順序の正本は bootstrap/app.php の priority list であり、route の宣言順ではない。
 *
 * **例外機構は設けない (違反は無条件 fail)**。allowlist を作ると、そこへ逃がした route から
 * 存在オラクルが再発する。将来やむを得ない例外が必要になったら、その時点で設計判断として
 * 本テスト自体を変更すること (= 人間のレビューを必ず通す)。
 *
 * 正規化の仕様: {@see NestedRouteDefenseInventory::resolvedMiddleware()} が
 * `Class:param` の parameter を落とし、alias 解決後の具象クラス名で返す。
 * Inertia の middleware はアプリの具象 class (App\Http\Middleware\HandleInertiaRequests) と
 * vendor class (Inertia\Middleware\EncryptHistory) の両方が現れる。
 * closure 要素は分類不能として fail させる。
 */

/**
 * 解決済み middleware クラス => 短絡しうるか (由来を問わず全件分類必須)。
 *
 * 分類の正本は {@see MiddlewareShortCircuitInventory} へ移した
 * (BughuntExecutedRouteOrderingTest も同じ表を読むため、同じ分類を 2 か所に持たない)。
 * 本関数はその薄い委譲であり、以下の検査の意味は移設前と変わらない。
 *
 * @return array<class-string, bool>
 */
function middlewareShortCircuitInventory(): array
{
    return MiddlewareShortCircuitInventory::classification();
}

/**
 * SubstituteBindings より前に走る短絡 middleware => 「生 route parameter を読まない」宣言。
 *
 * pre-binding の短絡は全 id で同一の応答を返すため存在オラクルにならない。
 * その前提が「route parameter を読まない」ことなので、宣言 + 静的検査で固定する。
 *
 * @return array<class-string, string>
 */
function preBindingShortCircuitInventory(): array
{
    return [
        Authenticate::class => '認証状態のみで判定。route param を読まない',
        ThrottleRequests::class => 'limiter キーは actor / IP。route param を読まない',
        PreventRequestForgery::class => 'CSRF token のみ',
        AuthenticateSession::class => 'session の password_hash のみ',
        ResolveApiActor::class => 'api_key attribute / api-oauth guard のみ',
    ];
}

/** テナント guard middleware の具象クラス (web / API の 2 本立て)。 */
function tenantGuardMiddlewareClasses(): array
{
    return [
        EnsureProjectBelongsToCurrentOrganization::class,
        EnsureProjectBelongsToApiOrganization::class,
    ];
}

/** route の inventory 宣言に指定モードが含まれるか。 */
function tenantBoundaryHasMode(string $routeName, NestedRouteDefenseMode $mode): bool
{
    foreach (NestedRouteDefenseInventory::inventory()[$routeName] ?? [] as $declared) {
        if ($declared === $mode) {
            return true;
        }
    }

    return false;
}

// --- 検査 1: 解決済み middleware の deny-by-default 分類 ---

test('検査1: 検査対象 route の解決済み middleware は全件が短絡分類 inventory にある', function (): void {
    $inventory = middlewareShortCircuitInventory();
    $violations = [];

    foreach (NestedRouteDefenseInventory::tenantDefenseRoutes() as $name => $route) {
        foreach (NestedRouteDefenseInventory::resolvedMiddleware($route) as $middleware) {
            if ($middleware === '(closure)') {
                $violations[] = "{$name}: 解決後の middleware に closure がある (短絡するか分類不能)";

                continue;
            }
            if (! array_key_exists($middleware, $inventory)) {
                $violations[] = "{$name}: {$middleware} が未分類";
            }
        }
    }

    $violations = array_values(array_unique($violations));
    expect($violations)->toBe([],
        '新しい middleware は必ず middlewareShortCircuitInventory() に分類してください '
        .'(短絡しうるなら true。疑わしきは true 側に倒すこと)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('検査1 補: 短絡分類 inventory に実在しないクラスを残さない', function (): void {
    $stale = [];
    foreach (array_keys(middlewareShortCircuitInventory()) as $class) {
        if (! class_exists($class)) {
            $stale[] = $class;
        }
    }

    expect($stale)->toBe([], '実在しないクラスが分類 inventory に残っています: '.implode(', ', $stale));
});

// --- 検査 2: テナント guard は binding の直後 (間に短絡なし) ---

test('検査2: TenantGuardMiddleware の route は binding とテナント guard の間に短絡が無い', function (): void {
    $shortCircuits = middlewareShortCircuitInventory();
    $violations = [];
    $checked = 0;

    foreach (NestedRouteDefenseInventory::tenantDefenseRoutes() as $name => $route) {
        if (! tenantBoundaryHasMode($name, NestedRouteDefenseMode::TenantGuardMiddleware)) {
            continue;
        }

        $resolved = NestedRouteDefenseInventory::resolvedMiddleware($route);
        $bindingIndex = array_search(SubstituteBindings::class, $resolved, true);
        if ($bindingIndex === false) {
            $violations[] = "{$name}: SubstituteBindings が解決後の列に無い";

            continue;
        }

        $guardIndex = false;
        foreach (tenantGuardMiddlewareClasses() as $guard) {
            $index = array_search($guard, $resolved, true);
            if ($index !== false) {
                $guardIndex = $index;

                break;
            }
        }
        if ($guardIndex === false) {
            $violations[] = "{$name}: テナント guard middleware が解決後の列に無い";

            continue;
        }
        if ($guardIndex < $bindingIndex) {
            $violations[] = "{$name}: テナント guard が SubstituteBindings より前 (binding 済みモデルを読めない)";

            continue;
        }

        foreach (array_slice($resolved, $bindingIndex + 1, $guardIndex - $bindingIndex - 1) as $between) {
            if (($shortCircuits[$between] ?? true) === true) {
                $violations[] = "{$name}: binding とテナント guard の間に短絡しうる {$between} がある"
                    .' (他組織に実在 = その短絡の応答 / 不在 = 404 の存在オラクルになります)';
            }
        }
        $checked++;
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
    expect($checked)->toBeGreaterThan(0); // 空振り drift ガード
});

// --- 検査 3a: 手動解決 param は binding 段で解決されない ---

test('検査3a: ManualOwnerScopedResolution の param は binding 段で解決されない', function (): void {
    $inventory = NestedRouteDefenseInventory::inventory();
    /** @var Router $router */
    $router = app('router');
    $violations = [];
    $checked = 0;

    foreach (NestedRouteDefenseInventory::registeredRoutes() as $name => $route) {
        foreach ($inventory[$name] as $param => $mode) {
            if ($mode !== NestedRouteDefenseMode::ManualOwnerScopedResolution) {
                continue;
            }

            // 条件 1: controller action の対応引数の型が Eloquent Model 派生でないこと
            // (Model 型だと ImplicitRouteBinding が binding 段で解決してしまう)
            $signature = null;
            foreach ($route->signatureParameters() as $parameter) {
                if ($parameter->getName() === $param) {
                    $signature = $parameter;

                    break;
                }
            }
            $type = $signature?->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            if ($typeName !== null && is_a($typeName, Model::class, true)) {
                $violations[] = "{$name} の {{$param}}: action 引数が Model 型 ({$typeName}) = implicit binding が"
                    .'復活しており、不在 id だけが binding 段で 404 になる (存在オラクル)';
            }

            // 条件 2: RouteBindingTypes の手動解決 exclusion に route identity ごと登録済みであること
            $registered = RouteBindingTypes::MANUALLY_RESOLVED[$param]['routes'] ?? [];
            if (! in_array($name, $registered, true)) {
                $violations[] = "{$name} の {{$param}}: RouteBindingTypes::MANUALLY_RESOLVED に未登録";
            }

            // 条件 3: explicit binder (Route::bind / Route::model) が登録されていないこと
            if ($router->getBindingCallback($param) !== null) {
                $violations[] = "{$name} の {{$param}}: explicit binder が登録されている = binding 段で解決される";
            }

            $checked++;
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
    expect($checked)->toBeGreaterThan(0);
});

// --- 検査 3b: inline guard route は binding より後に短絡が無い ---

test('検査3b: UrlIntegrityGuard の route は binding より後に短絡が無い', function (): void {
    $shortCircuits = middlewareShortCircuitInventory();
    $violations = [];

    foreach (NestedRouteDefenseInventory::registeredRoutes() as $name => $route) {
        if (! tenantBoundaryHasMode($name, NestedRouteDefenseMode::UrlIntegrityGuard)) {
            continue;
        }

        $resolved = NestedRouteDefenseInventory::resolvedMiddleware($route);
        $bindingIndex = array_search(SubstituteBindings::class, $resolved, true);
        if ($bindingIndex === false) {
            continue;
        }

        foreach (array_slice($resolved, $bindingIndex + 1) as $after) {
            if (($shortCircuits[$after] ?? true) === true) {
                $violations[] = "{$name}: inline guard は controller まで到達して初めて 404 になるのに、"
                    ."binding より後に短絡しうる {$after} がある (存在オラクル)";
            }
        }
    }

    // S3 完了後は該当 route が 0 件になる見込みだが、将来の再導入を落とすためテストは残す
    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

// --- 検査 4: pre-binding 短絡の性質固定 ---

test('検査4: binding より前に走る短絡 middleware は inventory 登録済み', function (): void {
    $shortCircuits = middlewareShortCircuitInventory();
    $preBinding = preBindingShortCircuitInventory();
    $violations = [];

    foreach (NestedRouteDefenseInventory::tenantDefenseRoutes() as $name => $route) {
        $resolved = NestedRouteDefenseInventory::resolvedMiddleware($route);
        $bindingIndex = array_search(SubstituteBindings::class, $resolved, true);
        if ($bindingIndex === false) {
            continue;
        }

        foreach (array_slice($resolved, 0, $bindingIndex) as $before) {
            if (($shortCircuits[$before] ?? true) !== true) {
                continue;
            }
            if (! array_key_exists($before, $preBinding)) {
                $violations[] = "{$name}: binding より前に走る短絡 {$before} が"
                    .' preBindingShortCircuitInventory() に未登録';
            }
        }
    }

    $violations = array_values(array_unique($violations));
    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

/*
 * 検査 4(a): 「生 route parameter を読まない」ことの静的検査。
 *
 * **限界の明示**: 呼び出し先クラス経由の間接参照は静的には証明できない
 * (例: ThrottleRequests 自体は param を読まないが、named limiter の closure が読みうる)。
 * そのため二段構えにしている:
 *   - 静的: 本テスト (直接の $request->route(...) 参照を落とす)
 *   - 振る舞い: tests/Feature/Security/TenantBoundaryPrecedenceTest (実在 id と不在 id の
 *     応答同一性) と tests/Feature/Security/NamedRateLimiterKeyTest (bucket 共有の証明)
 */
test('検査4(a): pre-binding 短絡 middleware のソースは生 route parameter を読まない', function (): void {
    /*
     | 禁じるのは「route **parameter** の読み取り」であって Route オブジェクトの参照ではない。
     | 例: ThrottleRequests は `$request->route()` を取得して `getDomain()` だけを読む。
     | これは URL 上の id と無関係なので存在オラクルにならない。
     | したがって引数付きの `route('x')` / `parameter(` / `input(` / `segment(` を検出する。
     */
    $forbidden = [
        "->route('",
        '->route("',
        // 変数引数 ($request->route($param)) も落とす。文字列リテラルだけを見ていると
        // 「param 名を変数で渡す」書き方で素通りする (impl-review R1 Warning)
        '->route($',
        '->parameter(',
        '->parameters(',
        'Route::input(',
        '->segment(',
        '->segments(',
    ];
    $violations = [];

    foreach (array_keys(preBindingShortCircuitInventory()) as $class) {
        $file = (new ReflectionClass($class))->getFileName();
        expect($file)->not->toBeFalse("{$class} のソースを取得できない");
        $raw = file_get_contents((string) $file);
        expect($raw)->not->toBeFalse();

        // コメント / docblock を除いた実行コードだけを対象にする
        // (「読まない」と説明する docblock 自身が偽陽性を出さないようにする)
        $code = '';
        foreach (token_get_all((string) $raw) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];

                continue;
            }
            $code .= $token;
        }

        foreach ($forbidden as $needle) {
            if (str_contains($code, $needle)) {
                $violations[] = "{$class} が `{$needle}` を使っている"
                    .' (binding 前に route parameter を読むと存在オラクルになりうる)';
            }
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

// --- 検査 5: 完全順序の pin ---

test('検査5: 代表 route の解決後 middleware 列を完全一致で固定する', function (): void {
    $webHead = [
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
        Authenticate::class,
        AuthenticateSession::class,
        SubstituteBindings::class,
    ];
    $webAppend = [
        HandleInertiaRequests::class,
        SecurityHeaders::class,
        RequireTwoFactorForEnforcedOrganizations::class,
        BlockTwoFactorDisableForEnforcedOrganizations::class,
        NoStoreCacheHeadersForAuthenticatedPages::class,
        IssueSessionEpochCookie::class,
        EncryptHistory::class,
        EnsureEmailIsVerified::class,
    ];
    // bug-hunt の実行済み route 記録器は web 鎖の**最後** (遮断 middleware より内側)。
    $recorder = BughuntExecutedRouteMiddleware::class;
    $guard = EnsureProjectBelongsToCurrentOrganization::class;
    $billing = RequireActiveSubscription::class;
    // 退会予約中の凍結は**課金ゲートの直後**。テナント境界 404 より必ず後 (302 短絡のため)。
    $freeze = EnsureAccountNotPendingDeletion::class;

    $apiHead = [
        Authenticate::class,
        ThrottleRequests::class,
        ResolveApiActor::class,
        SubstituteBindings::class,
        EnsureProjectBelongsToApiOrganization::class,
        RequireApiKeyAbility::class,
    ];

    $expected = [
        // API: actor 解決 → binding → テナント境界 404 → ability 403 → idempotency
        'api.v1.projects.items.store' => [...$apiHead, IdempotentRequest::class],
        'api.v1.projects.items.index' => $apiHead,
        // {project} を持たない route でも guard は列に載る (no-op。group 一括付与の許容)
        'api.v1.me' => $apiHead,
        // web: テナント境界 404 が Inertia / 2FA / verified / 課金ゲートより前。
        // 記録器は列の最後 (= 到達が「遮断をすべて通過した」証拠になる)
        'projects.update' => [...$webHead, $guard, ...$webAppend, $billing, $freeze, $recorder],
        'capture.manuals.show' => [...$webHead, $guard, ...$webAppend, $billing, $freeze, $recorder],
        // guard を持たない web route の列は変化しない (priority 追加の副作用が無いことの pin)
        'organizations.settings' => [...$webHead, ...$webAppend, $freeze, $recorder],
    ];

    $routes = app('router')->getRoutes();
    $routes->refreshNameLookups();

    foreach ($expected as $name => $expectedChain) {
        $route = $routes->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' が存在しない");
        expect(NestedRouteDefenseInventory::resolvedMiddleware($route))
            ->toBe($expectedChain, "route '{$name}' の解決後 middleware 列");
    }
});
