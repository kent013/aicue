<?php

declare(strict_types=1);

use App\Http\Routing\NormalizesRouteBindingInput;
use App\Http\Routing\RouteBindingTypes;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Tests\Support\Routing\SlugKeyedFixtureModel;

/*
|--------------------------------------------------------------------------
| route binding param の型制約 total inventory gate (deny-by-default)
|--------------------------------------------------------------------------
|
| 不変条件: **全 route (vendor 含む)** に現れる**全ての** binding param が
| RouteBindingTypes の 5 分類のいずれかに登録され、分類に応じた制約を持つ。
| 未登録 param の出現は fail させる (未知 param を数値と推測しない)。
|
| 守る事故: pgsql の型不一致 22P02 / 桁あふれ 22003 → QueryException →
| **404 ではなく生 500**。
| 実挙動 (非適合→404) と custom binder の入力正規化の実効性は
| tests/Feature/Routing/RouteBindingTypeConstraintTest が担保する
| (本 gate は分類の網羅と制約の適用のみを見る)。
|
| 各検査は「routes と inventory を引数で受ける純関数」として実装し、
| **負のコントロールは実ファイルを書き換えず fixture route / fixture inventory** に
| 対して同じ関数を走らせて検証する。
*/

/**
 * route identity: name を第一とし、name 無し route は `method:uri` signature。
 * HTTP method は昇順ソートし、暗黙の HEAD は除外する (GET 登録で自動付与されるため)。
 *
 * uri は **Livewire の endpoint prefix を正規化**してから使う。Livewire は
 * `EndpointResolver::prefix()` で `/livewire-<APP_KEY 由来 8 桁ハッシュ>` を生成するため、
 * 生の uri を identity にすると **APP_KEY ごとに inventory が壊れる**
 * (dev と testing で別ハッシュになる)。正規化するのは prefix だけで、
 * route の同一性 (path 構造 + method) は失わない。
 */
function routeBindingIdentity(RoutingRoute $route): string
{
    $name = $route->getName();
    if (is_string($name) && $name !== '') {
        return $name;
    }

    $methods = array_values(array_filter($route->methods(), static fn (string $m): bool => $m !== 'HEAD'));
    sort($methods);

    $uri = (string) preg_replace('#^livewire-[0-9a-f]{8}/#', 'livewire/', $route->uri());

    return implode('|', $methods).':'.$uri;
}

/**
 * アプリの全 route (vendor 含む)。
 *
 * @return list<RoutingRoute>
 */
function routeBindingAllRoutes(): array
{
    return array_values(Route::getRoutes()->getRoutes());
}

/**
 * fixture 用の隔離 Router (実 route collection を汚さない)。
 */
function routeBindingFixtureRouter(): Router
{
    return new Router(new Dispatcher, app());
}

/**
 * IV-1: 5 分類のいずれにも登録されていない param を列挙する。
 *
 * @param  list<RoutingRoute>  $routes
 * @param  list<string>  $registered
 * @return list<string> 違反メッセージ (param / route identity / action)
 */
function routeBindingUnregisteredParams(array $routes, array $registered): array
{
    $violations = [];

    foreach ($routes as $route) {
        foreach ($route->parameterNames() as $param) {
            if (in_array($param, $registered, true)) {
                continue;
            }

            $violations[] = sprintf(
                '{%s} @ %s (action: %s)',
                $param,
                routeBindingIdentity($route),
                $route->getActionName(),
            );
        }
    }

    return array_values(array_unique($violations));
}

/**
 * IV-2: inventory に登録済みだが routes に現れない param (陳腐化した登録)。
 *
 * @param  list<RoutingRoute>  $routes
 * @param  list<string>  $registered
 * @return list<string>
 */
function routeBindingStaleRegistrations(array $routes, array $registered): array
{
    $inUse = [];
    foreach ($routes as $route) {
        foreach ($route->parameterNames() as $param) {
            $inUse[$param] = true;
        }
    }

    return array_values(array_filter(
        array_unique($registered),
        static fn (string $param): bool => ! isset($inUse[$param]),
    ));
}

/**
 * IV-3 / IV-4: 指定 param 群が全ての出現 route で期待 pattern を持つこと。
 *
 * `Route::pattern` は route 登録時に `$route->wheres` へ merge されるため、
 * **実際に制約が効いているか**は wheres を見るのが正本 (登録順の事故も検出できる)。
 *
 * @param  list<RoutingRoute>  $routes
 * @param  list<string>  $params
 * @return list<string>
 */
function routeBindingMissingPatterns(array $routes, array $params, string $expectedPattern): array
{
    $violations = [];

    foreach ($routes as $route) {
        foreach ($route->parameterNames() as $param) {
            if (! in_array($param, $params, true)) {
                continue;
            }

            $actual = $route->wheres[$param] ?? null;
            if ($actual === $expectedPattern) {
                continue;
            }

            $violations[] = sprintf(
                '{%s} @ %s: pattern=%s (expected %s)',
                $param,
                routeBindingIdentity($route),
                $actual ?? '(none)',
                $expectedPattern,
            );
        }
    }

    return array_values(array_unique($violations));
}

/**
 * IV-5: CUSTOM_BINDER の param に pattern が適用されていないこと。
 *
 * @param  list<RoutingRoute>  $routes
 * @param  list<string>  $params
 * @return list<string>
 */
function routeBindingUnexpectedPatterns(array $routes, array $params): array
{
    $violations = [];

    foreach ($routes as $route) {
        foreach ($route->parameterNames() as $param) {
            if (! in_array($param, $params, true)) {
                continue;
            }

            $actual = $route->wheres[$param] ?? null;
            if ($actual === null) {
                continue;
            }

            $violations[] = sprintf('{%s} @ %s: pattern=%s', $param, routeBindingIdentity($route), $actual);
        }
    }

    return array_values(array_unique($violations));
}

/**
 * IV-7: EXTERNAL 宣言と実 route の突合。
 *
 * (a) route identity が実在する / (b) 登録 params と実 params が完全一致する /
 * (c) 登録 param が BIGINT / UUID と同名でない (同名なら global pattern が外部 route を壊す)。
 *
 * @param  list<RoutingRoute>  $routes
 * @param  array<string, list<string>>  $external
 * @param  list<string>  $typedParams  BIGINT / UUID の param 名
 * @return list<string>
 */
function routeBindingExternalViolations(array $routes, array $external, array $typedParams): array
{
    $byIdentity = [];
    foreach ($routes as $route) {
        $byIdentity[routeBindingIdentity($route)] = $route;
    }

    $violations = [];

    foreach ($external as $identity => $params) {
        $collisions = array_values(array_intersect($params, $typedParams));
        if ($collisions !== []) {
            $violations[] = sprintf(
                'EXTERNAL[%s]: param %s が BIGINT/UUID と同名 (global pattern が外部 route を破壊する)。'
                .'param 名を分離するか、当該 param を Route::pattern の適用対象から外して個別 where へ切り替えること',
                $identity,
                implode(', ', $collisions),
            );

            continue;
        }

        $route = $byIdentity[$identity] ?? null;
        if (! $route instanceof RoutingRoute) {
            $violations[] = sprintf('EXTERNAL[%s]: 該当 route が存在しない (陳腐化した登録)', $identity);

            continue;
        }

        $actual = $route->parameterNames();
        sort($actual);
        $declared = $params;
        sort($declared);

        if ($actual !== $declared) {
            $violations[] = sprintf(
                'EXTERNAL[%s]: params 不一致 (declared: %s / actual: %s)',
                $identity,
                implode(', ', $declared),
                implode(', ', $actual),
            );
        }
    }

    return $violations;
}

/**
 * IV-9: binding 解決の一致。
 *
 * (a) action 引数が宣言モデル型であること (MANUALLY_RESOLVED は除外) /
 * (b) binding field が未指定か ALLOWED_BINDING_FIELDS に列挙された field であること /
 * (c) field 未指定なら宣言モデルの getRouteKeyName() が PK かつ型区分が宣言と一致すること。
 *
 * DB へは触れず、モデル metadata (getRouteKeyName / getKeyName / getKeyType /
 * getIncrementing) だけで判定する。
 *
 * @param  list<RoutingRoute>  $routes
 * @param  array<string, class-string<Model>>  $bigint
 * @param  array<string, class-string<Model>>  $uuid
 * @param  array<string, list<string>>  $allowedFields
 * @param  array<string, string>  $manuallyResolved
 * @return list<string>
 */
function routeBindingResolutionViolations(
    array $routes,
    array $bigint,
    array $uuid,
    array $allowedFields,
    array $manuallyResolved,
): array {
    $violations = [];

    foreach ($routes as $route) {
        foreach ($route->parameterNames() as $param) {
            $declared = $bigint[$param] ?? $uuid[$param] ?? null;
            if ($declared === null) {
                continue;
            }

            $identity = routeBindingIdentity($route);

            // (a) action 引数の型
            if (! array_key_exists($param, $manuallyResolved)) {
                $signature = null;
                foreach ($route->signatureParameters() as $parameter) {
                    if ($parameter->getName() === $param) {
                        $signature = $parameter;
                        break;
                    }
                }

                $type = $signature?->getType();
                $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;

                if ($typeName === null || ! is_a($typeName, $declared, true)) {
                    $violations[] = sprintf(
                        'IV-9(a) {%s} @ %s: action 引数の型が %s でない (actual: %s)。'
                        .'手動解決なら RouteBindingTypes::MANUALLY_RESOLVED に理由付きで登録すること',
                        $param,
                        $identity,
                        $declared,
                        $typeName ?? '(none)',
                    );
                }
            }

            // (b) binding field
            $field = $route->bindingFieldFor($param);
            if ($field !== null && ! in_array($field, $allowedFields[$param] ?? [], true)) {
                $violations[] = sprintf(
                    'IV-9(b) {%s:%s} @ %s: 非 PK field 指定は型制約と両立しない。'
                    .'ALLOWED_BINDING_FIELDS へ登録するか、param を BIGINT/UUID から外すこと',
                    $param,
                    $field,
                    $identity,
                );

                continue;
            }

            if ($field !== null) {
                continue;
            }

            // (c) PK 解決であること + 型区分の一致 (DB 非依存の metadata のみ)
            $model = new $declared;
            if (! $model instanceof Model) {
                $violations[] = sprintf('IV-9(c) {%s} @ %s: %s は Eloquent Model でない', $param, $identity, $declared);

                continue;
            }

            if ($model->getRouteKeyName() !== $model->getKeyName()) {
                $violations[] = sprintf(
                    'IV-9(c) {%s} @ %s: %s の getRouteKeyName() が PK でない (%s)',
                    $param,
                    $identity,
                    $declared,
                    $model->getRouteKeyName(),
                );

                continue;
            }

            $isBigint = array_key_exists($param, $bigint);
            $matches = $isBigint
                ? ($model->getKeyType() === 'int' && $model->getIncrementing())
                : ($model->getKeyType() === 'string' && ! $model->getIncrementing());

            if (! $matches) {
                $violations[] = sprintf(
                    'IV-9(c) {%s} @ %s: %s の PK 型区分が宣言 (%s) と一致しない (keyType=%s / incrementing=%s)',
                    $param,
                    $identity,
                    $declared,
                    $isBigint ? 'BIGINT' : 'UUID',
                    $model->getKeyType(),
                    $model->getIncrementing() ? 'true' : 'false',
                );
            }
        }
    }

    return array_values(array_unique($violations));
}

/*
|--------------------------------------------------------------------------
| IV-1 〜 IV-9 (本番 inventory に対する検証)
|--------------------------------------------------------------------------
*/

it('IV-1: 全 route の binding param が 5 分類のいずれかに登録されている', function (): void {
    $violations = routeBindingUnregisteredParams(
        routeBindingAllRoutes(),
        RouteBindingTypes::allRegistered(),
    );

    expect($violations)->toBe(
        [],
        '未登録の binding param がある。RouteBindingTypes の 5 分類 '
        .'(BIGINT / UUID / CUSTOM_BINDER / NON_MODEL / EXTERNAL) のいずれかへ '
        .'型・解決方式・除外理由を登録すること: '.implode(' / ', $violations),
    );
});

it('IV-2: inventory に routes へ現れない param が残っていない', function (): void {
    $violations = routeBindingStaleRegistrations(
        routeBindingAllRoutes(),
        RouteBindingTypes::allRegistered(),
    );

    expect($violations)->toBe(
        [],
        'routes に現れない param が inventory に残っている (陳腐化した登録は削除する): '
        .implode(', ', $violations),
    );
});

it('IV-3: BIGINT の全 param が数値制約を持つ', function (): void {
    $violations = routeBindingMissingPatterns(
        routeBindingAllRoutes(),
        array_keys(RouteBindingTypes::BIGINT),
        RouteBindingTypes::BIGINT_PATTERN,
    );

    expect($violations)->toBe([], 'bigint param に数値制約が掛かっていない: '.implode(' / ', $violations));
});

it('IV-4: UUID の全 param が UUID 制約を持つ', function (): void {
    $violations = routeBindingMissingPatterns(
        routeBindingAllRoutes(),
        array_keys(RouteBindingTypes::UUID),
        RouteBindingTypes::UUID_PATTERN,
    );

    expect($violations)->toBe([], 'uuid param に UUID 制約が掛かっていない: '.implode(' / ', $violations));
});

it('IV-5: CUSTOM_BINDER の binder が実在し分類を宣言している (かつ pattern 未適用)', function (): void {
    foreach (RouteBindingTypes::CUSTOM_BINDER as $param => $binder) {
        expect(class_exists($binder))->toBeTrue("{$param} の binder クラス {$binder} が存在しない");
        expect(is_a($binder, NormalizesRouteBindingInput::class, true))->toBeTrue(
            "{$binder} は NormalizesRouteBindingInput を実装して分類を宣言すること "
            .'(入力正規化の実効性の正本は Feature テスト)',
        );
    }

    $violations = routeBindingUnexpectedPatterns(
        routeBindingAllRoutes(),
        array_keys(RouteBindingTypes::CUSTOM_BINDER),
    );

    expect($violations)->toBe(
        [],
        'CUSTOM_BINDER の param に pattern を適用してはいけない ({organization:slug} が全滅する): '
        .implode(' / ', $violations),
    );
});

it('IV-6: 同一 param が複数分類に重複登録されていない', function (): void {
    // EXTERNAL は route identity ごとの登録なので、同じ param が複数 route に現れるのは正常。
    // 検査するのは**分類をまたいだ**重複 (どの分類の制約が効くのか曖昧になる)。
    $byCategory = [
        'BIGINT' => array_keys(RouteBindingTypes::BIGINT),
        'UUID' => array_keys(RouteBindingTypes::UUID),
        'CUSTOM_BINDER' => array_keys(RouteBindingTypes::CUSTOM_BINDER),
        'NON_MODEL' => RouteBindingTypes::NON_MODEL,
        'EXTERNAL' => array_values(array_unique(RouteBindingTypes::externalParams())),
    ];

    $duplicates = [];
    foreach ($byCategory as $category => $params) {
        foreach ($byCategory as $otherCategory => $otherParams) {
            if ($category >= $otherCategory) {
                continue;
            }

            foreach (array_intersect($params, $otherParams) as $param) {
                $duplicates[] = "{$param} ({$category} / {$otherCategory})";
            }
        }
    }

    expect($duplicates)->toBe([], '複数分類に重複登録された param がある: '.implode(', ', $duplicates));
});

it('IV-7: EXTERNAL 宣言が実 route と一致し BIGINT/UUID と衝突しない', function (): void {
    $violations = routeBindingExternalViolations(
        routeBindingAllRoutes(),
        RouteBindingTypes::EXTERNAL,
        [...array_keys(RouteBindingTypes::BIGINT), ...array_keys(RouteBindingTypes::UUID)],
    );

    expect($violations)->toBe([], implode(' / ', $violations));
});

it('IV-8: BIGINT_PATTERN の値が固定されている (桁あふれ 22003 の再発防止)', function (): void {
    // `[0-9]+` へ戻すと 19 桁以上が regex を通過して 22003 → 500 が復活する。
    expect(RouteBindingTypes::BIGINT_PATTERN)->toBe('[0-9]{1,18}');
});

it('IV-9: BIGINT/UUID param の binding 解決が宣言と一致する', function (): void {
    $violations = routeBindingResolutionViolations(
        routeBindingAllRoutes(),
        RouteBindingTypes::BIGINT,
        RouteBindingTypes::UUID,
        RouteBindingTypes::ALLOWED_BINDING_FIELDS,
        RouteBindingTypes::MANUALLY_RESOLVED,
    );

    expect($violations)->toBe([], implode(' / ', $violations));
});

it('IV-9 補: MANUALLY_RESOLVED は BIGINT/UUID 宣言済み param にのみ理由付きで登録される', function (): void {
    $typed = [...array_keys(RouteBindingTypes::BIGINT), ...array_keys(RouteBindingTypes::UUID)];

    foreach (RouteBindingTypes::MANUALLY_RESOLVED as $param => $reason) {
        expect(in_array($param, $typed, true))->toBeTrue("{$param} は BIGINT/UUID に宣言されていない");
        expect(trim($reason))->not->toBe('', "{$param} の手動解決理由を記述すること");
    }
});

/*
|--------------------------------------------------------------------------
| 負のコントロール (fixture route / fixture inventory に対して同じ関数を走らせる)
|--------------------------------------------------------------------------
*/

it('負のコントロール IV-1: 未登録 param は fail する', function (): void {
    $router = routeBindingFixtureRouter();
    $router->get('/fixture/{gadget}', static fn (): string => 'ok');

    $violations = routeBindingUnregisteredParams(
        array_values($router->getRoutes()->getRoutes()),
        RouteBindingTypes::allRegistered(),
    );

    expect($violations)->not->toBe([]);
});

it('負のコントロール IV-3: pattern 未適用なら fail する', function (): void {
    $router = routeBindingFixtureRouter();
    $router->get('/fixture/projects/{project}', static fn (): string => 'ok');

    $violations = routeBindingMissingPatterns(
        array_values($router->getRoutes()->getRoutes()),
        ['project'],
        RouteBindingTypes::BIGINT_PATTERN,
    );

    expect($violations)->not->toBe([]);
});

it('負のコントロール IV-7: BIGINT と同名の EXTERNAL 宣言は fail する', function (): void {
    $violations = routeBindingExternalViolations(
        routeBindingAllRoutes(),
        ['vendor.some.route' => ['user']],
        [...array_keys(RouteBindingTypes::BIGINT), ...array_keys(RouteBindingTypes::UUID)],
    );

    expect($violations)->not->toBe([]);
});

it('負のコントロール IV-7: 実在しない route identity の宣言は fail する', function (): void {
    $violations = routeBindingExternalViolations(
        routeBindingAllRoutes(),
        ['vendor.route.that.does.not.exist' => ['token'],
        ],
        [...array_keys(RouteBindingTypes::BIGINT), ...array_keys(RouteBindingTypes::UUID)],
    );

    expect($violations)->not->toBe([]);
});

it('負のコントロール IV-9(a): モデル型 typehint 無しの {user} は fail する', function (): void {
    $router = routeBindingFixtureRouter();
    $router->get('/fixture/users/{user}', static fn (string $user): string => $user);

    $violations = routeBindingResolutionViolations(
        array_values($router->getRoutes()->getRoutes()),
        RouteBindingTypes::BIGINT,
        RouteBindingTypes::UUID,
        RouteBindingTypes::ALLOWED_BINDING_FIELDS,
        [],
    );

    expect($violations)->not->toBe([]);
});

it('負のコントロール IV-9(b): {user:slug} は fail する', function (): void {
    $router = routeBindingFixtureRouter();
    $router->get('/fixture/users/{user:slug}', static fn (User $user): string => (string) $user->id);

    $violations = routeBindingResolutionViolations(
        array_values($router->getRoutes()->getRoutes()),
        RouteBindingTypes::BIGINT,
        RouteBindingTypes::UUID,
        RouteBindingTypes::ALLOWED_BINDING_FIELDS,
        RouteBindingTypes::MANUALLY_RESOLVED,
    );

    expect($violations)->not->toBe([]);
});

it('負のコントロール IV-9(c): getRouteKeyName() が非 PK のモデルは fail する', function (): void {
    $router = routeBindingFixtureRouter();
    $router->get('/fixture/things/{thing}', static fn (SlugKeyedFixtureModel $thing): string => 'ok');

    $violations = routeBindingResolutionViolations(
        array_values($router->getRoutes()->getRoutes()),
        ['thing' => SlugKeyedFixtureModel::class],
        [],
        [],
        [],
    );

    expect($violations)->not->toBe([]);
});

it('負のコントロール IV-8: pattern 値を [0-9]+ に戻すと 18 桁上限が失われる', function (): void {
    // IV-8 は定数値そのものの pin。等価性の負のコントロールとして
    // 「[0-9]+ は pin 値と異なる」ことと「19 桁を通してしまう」ことの両方を示す。
    expect('[0-9]+')->not->toBe(RouteBindingTypes::BIGINT_PATTERN);
    expect((bool) preg_match('/^[0-9]+$/', '9223372036854775808'))->toBeTrue();
    expect((bool) preg_match('/^'.RouteBindingTypes::BIGINT_PATTERN.'$/', '9223372036854775808'))->toBeFalse();
});
