<?php

declare(strict_types=1);

use App\Enums\Http\InertiaErrorScreenStatus;
use App\Support\Http\ErrorScreenDestination;
use App\Support\Http\ErrorScreenDestinations;

/*
 * 戻り先のサーバ固定許可一覧の契約 (DB 不使用)。
 * リクエスト入力を一切読まないため、open redirect が構造的に不成立であることも併せて固定する。
 */

/** @return list<InertiaErrorScreenStatus> 419 以外の全 status */
function errorScreenNonExpiredStatuses(): array
{
    return array_values(array_filter(
        InertiaErrorScreenStatus::cases(),
        static fn (InertiaErrorScreenStatus $status): bool => ! $status->forcesGuestDestinations(),
    ));
}

test('419 は認証状態にかかわらずログインへ倒れる (D1 が D2 より先)', function (bool $authenticated): void {
    $destinations = ErrorScreenDestinations::for(InertiaErrorScreenStatus::PageExpired, $authenticated);

    expect($destinations[0]->href)->toBe(route('login', absolute: false));
})->with([[true], [false]]);

test('419 以外は認証済みならダッシュボードへ倒れる', function (InertiaErrorScreenStatus $status): void {
    $destinations = ErrorScreenDestinations::for($status, authenticated: true);

    expect($destinations[0]->href)->toBe(route('dashboard', absolute: false));
})->with(errorScreenNonExpiredStatuses());

test('419 以外は未認証ならログインへ倒れる', function (InertiaErrorScreenStatus $status): void {
    $destinations = ErrorScreenDestinations::for($status, authenticated: false);

    expect($destinations[0]->href)->toBe(route('login', absolute: false));
})->with(errorScreenNonExpiredStatuses());

test('全 status × 認証状態で戻り先が 1 件以上ある', function (): void {
    foreach (InertiaErrorScreenStatus::cases() as $status) {
        foreach ([true, false] as $authenticated) {
            expect(count(ErrorScreenDestinations::for($status, $authenticated)))
                ->toBeGreaterThanOrEqual(1, "status {$status->value} / authenticated={$authenticated} で戻り先が空");
        }
    }
});

test('href が相対 path で同一オリジンに閉じている', function (): void {
    foreach (InertiaErrorScreenStatus::cases() as $status) {
        foreach ([true, false] as $authenticated) {
            foreach (ErrorScreenDestinations::for($status, $authenticated) as $destination) {
                expect($destination->href)->toStartWith('/');
                expect($destination->href)->not->toStartWith('//');
            }
        }
    }
});

test('戻り先の href が重複しない', function (): void {
    foreach (InertiaErrorScreenStatus::cases() as $status) {
        foreach ([true, false] as $authenticated) {
            $hrefs = array_map(
                static fn (ErrorScreenDestination $destination): string => $destination->href,
                ErrorScreenDestinations::for($status, $authenticated),
            );

            expect(array_values(array_unique($hrefs)))->toBe($hrefs);
        }
    }
});
