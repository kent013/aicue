<?php

declare(strict_types=1);

use App\Enums\ApiKeyAbility;
use App\Enums\OAuth\CliOAuthScope;
use App\Enums\Security\ApiWriteScopeExemption;
use App\Http\Controllers\Api\V1\Me\RevokeSessionController;
use App\Http\Middleware\RequireApiKeyAbility;
use App\Http\Middleware\ResolveApiActor;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * 外部向け API (REST v1) の書き込み資格と、実行時の主体再評価の invariant (既定拒否)。
 *
 * 組織の役割変更に同期した失効 ({@see \App\Services\OAuth\OrganizationAccessRevoker}) は
 * 「発行済みの資格情報を切る」側の防御である。切れなかった / 切る前の要求に対する
 * 最後の拒否線は **要求ごとの再評価** であり、その再評価の実在をここで固定する。
 *
 * 検査は 3 つ:
 *  A. `api.v1.` の変更系 route は書き込み資格をちょうど 1 本持つか、免除目録に登録されている
 *  B. 免除の**前提**が実際に成立している (空疎な免除の禁止)
 *  C. 主体の解決 (`ResolveApiActor`) の再評価が消えていない
 *
 * ★**扱わないこと** (二重管理の回避):
 *   middleware の実行順序は `TenantBoundaryOrderingTest`、認証 guard の分類は
 *   `ApiGuardAllowlistInvariantTest`、冪等キーの配線は `IdempotentRouteCoverageTest` の担当。
 *
 * ★**保証範囲を誇張しない**: 見ているのは名前が `api.v1.` で始まる route だけである。
 *   web 側の変更系・`oauth/*`・MCP transport・将来別 prefix の機械向け API には**沈黙する**
 *   (MCP 側は `McpAuthorizationChokePointTest` が別に担当する)。
 *   検査 C は字句検査なので「呼んでいるが結果を使っていない」形は落とせない。
 *   実挙動は `tests/Feature/Api/OAuthDualGuardTest.php` と
 *   `tests/Feature/Organizations/OrganizationAccessRevocationTest.php` が担う。
 */

/** 変更系 HTTP メソッド。 */
function restWriteScopeMutatingMethods(): array
{
    return ['POST', 'PUT', 'PATCH', 'DELETE'];
}

/**
 * 母集団件数 (**完全一致**)。
 *
 * 余裕を持たせるとセレクタが壊れて母集団が減っても気づけない。
 * route を増減させたらこの数値も書き換えること。
 */
function restWriteScopeRouteCount(): int
{
    return 4;
}

/** 免除の件数 (完全一致)。 */
function restWriteScopeExemptionCount(): int
{
    return 1;
}

/** 免除理由の最低文字数。 */
function restWriteScopeReasonMinLength(): int
{
    return 30;
}

/**
 * 書き込み資格を持たないことが正しいと裁定した route の目録。
 *
 * @return array<string, ApiWriteScopeExemption>
 */
function restWriteScopeExemptions(): array
{
    return [
        'api.v1.me.session.revoke' => ApiWriteScopeExemption::DedicatedSessionRevokeScope,
    ];
}

/**
 * 免除の**前提**の機械検査 (空疎な免除の禁止)。
 *
 * @return array<string, array{class: class-string, marker: string}>
 */
function restWriteScopePremises(): array
{
    return [
        'api.v1.me.session.revoke' => [
            'class' => RevokeSessionController::class,
            // 専用資格を実際に見ていること
            'marker' => 'CliOAuthScope::SessionRevoke',
        ],
    ];
}

/** 解決後 middleware 列 (文字列 entry のみ)。 */
function restWriteScopeResolvedMiddleware(RoutingRoute $route): array
{
    /** @var Router $router */
    $router = Route::getFacadeRoot();

    return array_values(array_filter(
        $router->gatherRouteMiddleware($route),
        static fn (mixed $entry): bool => is_string($entry),
    ));
}

/** 実効 middleware 列に含まれる「書き込み資格」の本数。 */
function restWriteScopeWriteAbilityCount(RoutingRoute $route): int
{
    $count = 0;
    foreach (restWriteScopeResolvedMiddleware($route) as $entry) {
        if (! is_a(Str::before($entry, ':'), RequireApiKeyAbility::class, true)) {
            continue;
        }
        if (Str::after($entry, ':') === ApiKeyAbility::Write->value) {
            $count++;
        }
    }

    return $count;
}

/** 実効 middleware 列に主体解決 (`resolve.api-actor`) があるか。 */
function restWriteScopeHasActorResolution(RoutingRoute $route): bool
{
    foreach (restWriteScopeResolvedMiddleware($route) as $entry) {
        if (is_a(Str::before($entry, ':'), ResolveApiActor::class, true)) {
            return true;
        }
    }

    return false;
}

/** @return list<RoutingRoute> 母集団 (名前が api.v1. で始まる変更系) */
function restWriteScopeRoutes(): array
{
    $mutating = restWriteScopeMutatingMethods();
    $selected = [];

    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();
        if ($name === null || ! str_starts_with($name, 'api.v1.')) {
            continue;
        }
        if (array_intersect($mutating, $route->methods()) === []) {
            continue;
        }
        $selected[] = $route;
    }

    return $selected;
}

/**
 * 違反検出の本体 (負のコントロールから再利用するため関数に切り出す)。
 *
 * @return list<string>
 */
function restWriteScopeViolations(): array
{
    $exemptions = restWriteScopeExemptions();
    $violations = [];

    foreach (restWriteScopeRoutes() as $route) {
        $name = (string) $route->getName();
        $count = restWriteScopeWriteAbilityCount($route);

        if ($count === 1) {
            continue;
        }
        if ($count === 0 && array_key_exists($name, $exemptions)) {
            continue;
        }

        $violations[] = $count === 0
            ? "{$name}: 書き込み資格 (api-key.ability:write) が無く免除目録にも未登録"
            : "{$name}: 書き込み資格が {$count} 本ある";
    }

    return $violations;
}

/** クラスのメソッド本文 (最初の `{` 以降) を素のソースとして取り出す。 */
function restWriteScopeMethodBody(string $class, string $method): string
{
    $reflection = new ReflectionMethod($class, $method);
    $file = (string) $reflection->getFileName();
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    expect($lines)->toBeArray();
    /** @var list<string> $lines */
    $source = implode(PHP_EOL, array_slice(
        $lines,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));
    $brace = strpos($source, '{');

    return $brace === false ? '' : substr($source, $brace);
}

test('母集団の件数が宣言値と一致する (セレクタの空振り検出)', function (): void {
    expect(count(restWriteScopeRoutes()))->toBe(restWriteScopeRouteCount(),
        'api.v1. の変更系 route の件数が宣言値と違います。route を増減させたら '
        .'restWriteScopeRouteCount() も書き換えてください (セレクタが空振りしても気づけるようにするため)。');
});

test('検査A: 変更系 route は書き込み資格をちょうど 1 本持つか免除目録に登録されている', function (): void {
    expect(restWriteScopeViolations())->toBe([],
        '書き込み資格を配線するか、配線しないことが正しい理由を restWriteScopeExemptions() へ'
        .'ApiWriteScopeExemption 付きで登録してください。'
        .PHP_EOL.implode(PHP_EOL, restWriteScopeViolations()));
});

test('検査A2: 免除の件数と根拠 (形骸化ガード)', function (): void {
    expect(count(restWriteScopeExemptions()))->toBe(restWriteScopeExemptionCount());
    expect(count(ApiWriteScopeExemption::cases()))->toBe(restWriteScopeExemptionCount(),
        'ApiWriteScopeExemption の case 数と目録の件数が食い違っています (死んだ case の残置)。');

    $violations = [];
    foreach (restWriteScopeExemptions() as $name => $exemption) {
        if (mb_strlen($exemption->rationale()) < restWriteScopeReasonMinLength()) {
            $violations[] = "{$name}: 根拠が ".restWriteScopeReasonMinLength().' 文字未満です';
        }
    }
    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('検査A3: 免除目録の key は現存する母集団 route (stale 検出)', function (): void {
    $names = [];
    foreach (restWriteScopeRoutes() as $route) {
        $names[(string) $route->getName()] = true;
    }

    $stale = array_values(array_filter(
        array_keys(restWriteScopeExemptions()),
        static fn (string $name): bool => ! isset($names[$name]),
    ));

    expect($stale)->toBe([], '免除目録に現存しない route があります: '.implode(', ', $stale));
});

test('検査B: 免除の前提が実際に成立している (空疎な免除の禁止)', function (): void {
    $violations = [];

    foreach (restWriteScopeExemptions() as $name => $exemption) {
        $premise = restWriteScopePremises()[$name] ?? null;
        if ($premise === null) {
            $violations[] = "{$name}: 免除の前提が宣言されていません";

            continue;
        }
        $file = (new ReflectionClass($premise['class']))->getFileName();
        $source = $file === false ? '' : (string) file_get_contents($file);
        if (! str_contains($source, $premise['marker'])) {
            $violations[] = "{$name}: {$premise['class']} が {$premise['marker']} を参照していません "
                .'(免除の根拠が実装から消えています)';
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('検査B2: 専用資格の case が実在する (前提の裏取り)', function (): void {
    // 前提の marker が指す enum case が消えたら、marker の文字列照合だけでは気づけない
    expect(CliOAuthScope::SessionRevoke->value)->toBe('session.revoke');
});

test('検査C: 主体の解決が所属とセッションを毎回再評価している', function (): void {
    $body = restWriteScopeMethodBody(ResolveApiActor::class, 'contextFromUserToken');

    expect(str_contains($body, 'isRevoked('))->toBeTrue(
        'セッションの生存の再評価が消えています (失効済みセッションの token が通るようになります)。');
    expect(str_contains($body, 'isMemberOf('))->toBeTrue(
        '所属の再評価が消えています (組織から外れた人の token が通るようになります)。');
});

test('検査C2: 母集団の変更系 route はすべて主体解決を通る', function (): void {
    $violations = [];

    foreach (restWriteScopeRoutes() as $route) {
        if (! restWriteScopeHasActorResolution($route)) {
            $violations[] = (string) $route->getName().': resolve.api-actor が無い';
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('負のコントロール: 書き込み資格の無い api.v1 変更系 route を検出する', function (): void {
    Route::post('api/v1/__write_scope_negative_control__', fn (): string => 'ok')
        ->name('api.v1.__write_scope_negative_control__');

    expect(restWriteScopeViolations())
        ->toContain('api.v1.__write_scope_negative_control__: 書き込み資格 (api-key.ability:write) が無く免除目録にも未登録');
});

test('正のコントロール: 書き込み資格つきの api.v1 変更系 route は違反にならない', function (): void {
    Route::post('api/v1/__write_scope_positive_control__', fn (): string => 'ok')
        ->middleware('api-key.ability:write')
        ->name('api.v1.__write_scope_positive_control__');

    // ★「violations 全体が空」で見ない。既存母集団側に違反があると、この対照テストまで
    //   一緒に赤くなり「追加した route が違反にならないこと」を単独で証明できないためである。
    $named = array_values(array_filter(
        restWriteScopeViolations(),
        static fn (string $violation): bool => str_starts_with($violation, 'api.v1.__write_scope_positive_control__'),
    ));

    expect($named)->toBe([]);
});
