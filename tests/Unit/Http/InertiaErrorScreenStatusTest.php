<?php

declare(strict_types=1);

use App\Enums\Http\InertiaErrorScreenStatus;

/*
 * 差し替え対象 status の文言・分岐フラグの固定 (DB 不使用)。
 * 文言未定義で白画面になる退行と、D1 (419 だけログインへ倒す) の退行を検出する。
 */

test('全 case が空でない title と message を持つ', function (InertiaErrorScreenStatus $status): void {
    expect($status->title())->not->toBe('');
    expect($status->message())->not->toBe('');
})->with(InertiaErrorScreenStatus::cases());

test('待ち時間を出す status は 429 と 503 だけ', function (): void {
    $showing = array_values(array_map(
        static fn (InertiaErrorScreenStatus $status): int => $status->value,
        array_filter(
            InertiaErrorScreenStatus::cases(),
            static fn (InertiaErrorScreenStatus $status): bool => $status->showsRetryAfter(),
        ),
    ));

    expect($showing)->toBe([429, 503]);
});

test('D1 (認証状態を問わずログインへ) が適用されるのは 419 だけ', function (): void {
    $forcing = array_values(array_map(
        static fn (InertiaErrorScreenStatus $status): int => $status->value,
        array_filter(
            InertiaErrorScreenStatus::cases(),
            static fn (InertiaErrorScreenStatus $status): bool => $status->forcesGuestDestinations(),
        ),
    ));

    expect($forcing)->toBe([419]);
});

test('isServerError は 500 以上でだけ真', function (InertiaErrorScreenStatus $status): void {
    expect($status->isServerError())->toBe($status->value >= 500);
})->with(InertiaErrorScreenStatus::cases());

test('401 は目録に無い (AuthenticationException の履歴鍵破棄契約と競合するため)', function (): void {
    expect(InertiaErrorScreenStatus::tryFrom(401))->toBeNull();
});
