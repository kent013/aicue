<?php

declare(strict_types=1);

use App\DataTransferObjects\Http\ErrorScreenData;
use App\Enums\Http\InertiaErrorScreenStatus;
use App\Support\Http\ErrorScreenDestination;
use Webmozart\Assert\InvalidArgumentException;

/*
 * Error 画面 props の DTO 契約 (DB 不使用)。
 * TS 側 resources/js/types/error-screen.ts と 1:1 の shape であることを固定する。
 */

test('戻り先が空だと構築を拒否する', function (): void {
    // 型 (non-empty-list) は静的な約束にすぎないため、実行時ガードが働くことを確認する
    new ErrorScreenData(
        status: InertiaErrorScreenStatus::NotFound,
        retryAfterSeconds: null,
        destinations: [],
    );
})->throws(InvalidArgumentException::class);

test('toInertiaProps が固定の shape を返す', function (): void {
    $data = new ErrorScreenData(
        status: InertiaErrorScreenStatus::TooManyRequests,
        retryAfterSeconds: 30,
        destinations: [new ErrorScreenDestination('ログインへ', '/login')],
    );

    $props = $data->toInertiaProps();

    expect(array_keys($props))->toBe(['status', 'title', 'message', 'retryAfterSeconds', 'destinations']);
    expect($props['status'])->toBe(429);
    expect($props['title'])->toBe(InertiaErrorScreenStatus::TooManyRequests->title());
    expect($props['message'])->toBe(InertiaErrorScreenStatus::TooManyRequests->message());
    expect($props['retryAfterSeconds'])->toBe(30);
    expect($props['destinations'])->toHaveCount(1);
    expect(array_keys($props['destinations'][0]))->toBe(['label', 'href']);
    expect($props['destinations'][0])->toBe(['label' => 'ログインへ', 'href' => '/login']);
});

test('retryAfterSeconds は null を保持する', function (): void {
    $data = new ErrorScreenData(
        status: InertiaErrorScreenStatus::NotFound,
        retryAfterSeconds: null,
        destinations: [new ErrorScreenDestination('トップへ', '/')],
    );

    expect($data->toInertiaProps()['retryAfterSeconds'])->toBeNull();
});
