<?php

declare(strict_types=1);

use App\Enums\Organization\SlugReservationReason;
use App\Exceptions\Organization\ReservedOrganizationSlugException;
use App\Support\Organization\AssignableOrganizationSlug;
use App\Support\Organization\OrganizationSlug;
use App\Support\Organization\OrganizationSlugReservedWords;

/*
 * 昇格 (構文型 → 保存可能型) は非予約語でだけ成立する (家系裁定 AG-039 / 不変条件 I11)。
 */

test('非予約語は昇格できる', function (): void {
    $slug = AssignableOrganizationSlug::promote(
        OrganizationSlug::fromString('acme'),
        OrganizationSlugReservedWords::load(),
    );

    expect($slug->value)->toBe('acme');
});

test('予約語の昇格は例外で、理由 (3 分類) が例外に載る', function (): void {
    try {
        AssignableOrganizationSlug::promote(
            OrganizationSlug::fromString('admin'),
            OrganizationSlugReservedWords::load(),
        );
        $this->fail('予約語が昇格できてしまった');
    } catch (ReservedOrganizationSlugException $e) {
        expect($e->reason)->toBe(SlugReservationReason::AuthorityImpersonation);
        expect($e->slug->value)->toBe('admin');
    }
});

test('大文字で書いた予約語も正規化後に拒否される (一意性は大小を区別しない)', function (): void {
    expect(fn (): AssignableOrganizationSlug => AssignableOrganizationSlug::promote(
        OrganizationSlug::fromString('ADMIN'),
        OrganizationSlugReservedWords::load(),
    ))->toThrow(ReservedOrganizationSlugException::class);
});
