<?php

declare(strict_types=1);

use App\Support\Auth\SessionEpoch;

/*
 * セッション世代の印の導出と照合 (App\Support\Auth\SessionEpoch)。
 *
 * 印は「いまのセッションを一意に指す短い文字列」で、**セッション ID そのものは
 * 画面側へ出さない** (世代 cookie は画面側から読めるため)。
 * 照合は fail-closed = どちらかが無い / 書式が違うときは一致としない。
 */

test('同じセッション ID からは同じ印、違う ID からは違う印になる', function (): void {
    $first = SessionEpoch::forSession('session-id-one');
    $second = SessionEpoch::forSession('session-id-two');

    expect($first)->toBe(SessionEpoch::forSession('session-id-one'))
        ->and($first)->not->toBe($second);
});

test('印は 32 文字の 16 進小文字である', function (): void {
    expect(SessionEpoch::isWellFormed(SessionEpoch::forSession('any-session-id')))->toBeTrue();
});

test('印はセッション ID を部分文字列として含まない (生の ID を出さない)', function (): void {
    $sessionId = 'ZGxCa1ppTm9vUlR4a2RvVWJoWkVFeXBnRlRyd2NkVGs';

    expect(SessionEpoch::forSession($sessionId))->not->toContain($sessionId);
});

test('matches は一致で true', function (): void {
    $epoch = SessionEpoch::forSession('session-id-one');

    expect(SessionEpoch::matches($epoch, $epoch))->toBeTrue();
});

test('matches は不一致・欠落・書式違いで false (fail-closed)', function (string $description, ?string $submitted, ?string $current): void {
    expect(SessionEpoch::matches($submitted, $current))->toBeFalse($description);
})->with([
    ['別の印', 'a1b2c3d4e5f60718293a4b5c6d7e8f90', '0123456789abcdef0123456789abcdef'],
    ['提出側が null', null, '0123456789abcdef0123456789abcdef'],
    ['現世代が null', '0123456789abcdef0123456789abcdef', null],
    ['両方 null', null, null],
    ['提出側が空文字', '', '0123456789abcdef0123456789abcdef'],
    ['33 文字', '0123456789abcdef0123456789abcdef0', '0123456789abcdef0123456789abcdef0'],
    ['31 文字', '0123456789abcdef0123456789abcde', '0123456789abcdef0123456789abcde'],
    ['大文字', '0123456789ABCDEF0123456789ABCDEF', '0123456789ABCDEF0123456789ABCDEF'],
    ['非 16 進', '0123456789abcdefg123456789abcdef', '0123456789abcdefg123456789abcdef'],
]);

test('isWellFormed は書式違いを拒否する', function (): void {
    expect(SessionEpoch::isWellFormed(null))->toBeFalse()
        ->and(SessionEpoch::isWellFormed(''))->toBeFalse()
        ->and(SessionEpoch::isWellFormed('0123456789abcdef0123456789abcdef'))->toBeTrue()
        ->and(SessionEpoch::isWellFormed("0123456789abcdef0123456789abcdef\n"))->toBeFalse();
});
