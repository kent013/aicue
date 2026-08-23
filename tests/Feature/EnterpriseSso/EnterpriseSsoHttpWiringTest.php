<?php

declare(strict_types=1);

use App\Services\EnterpriseSso\EnterpriseIdTokenVerifier;
use App\Services\EnterpriseSso\OidcDiscoveryService;
use App\Services\EnterpriseSso\OidcTokenExchanger;
use Kent013\SsrfPin\PinnedHttpClient;

/*
 * 「外向きは pin 済み経路だけである」の**主証明の 1 本目** (DI の結線)。
 *
 * ★G2 (静的走査) は「禁止型の参照が無い」までしか主張しない。
 *   **実際に注入される担い手が PinnedHttpClient だけである**ことは、
 *   構築子の型を実物で確かめる本テストが証明する。
 *   主証明のもう 1 本は実挙動テスト (偽の transport に要求が届くこと) である。
 */

test('企業 SSO の外向きサービスへ注入される HTTP の担い手が PinnedHttpClient だけである', function (string $class): void {
    $constructor = (new ReflectionClass($class))->getConstructor();
    expect($constructor)->not->toBeNull();

    $httpParameters = [];
    foreach ($constructor?->getParameters() ?? [] as $parameter) {
        $type = (string) $parameter->getType();

        // HTTP の担い手になりうる型だけを拾う (cache / 他サービスは対象外)
        if (str_contains($type, 'Http') || str_contains($type, 'Client')) {
            $httpParameters[] = $type;
        }
    }

    foreach ($httpParameters as $type) {
        expect($type)->toBe(PinnedHttpClient::class);
    }
})->with([
    OidcDiscoveryService::class,
    OidcTokenExchanger::class,
]);

test('検証サービスは自分では外向き取得を持たず、取得口だけを受け取る', function (): void {
    $constructor = (new ReflectionClass(EnterpriseIdTokenVerifier::class))->getConstructor();

    $types = array_map(
        static fn (ReflectionParameter $parameter): string => (string) $parameter->getType(),
        $constructor?->getParameters() ?? [],
    );

    // ★取得は discovery 経由の 1 本道である (自前の HTTP 担い手を持たない)。
    expect($types)->toBe([OidcDiscoveryService::class]);
});

test('実際に解決されるインスタンスも PinnedHttpClient を持つ', function (): void {
    $service = app(OidcDiscoveryService::class);

    $property = (new ReflectionClass($service))->getProperty('pinned');

    expect($property->getValue($service))->toBeInstanceOf(PinnedHttpClient::class);
});
