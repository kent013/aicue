<?php

declare(strict_types=1);

use App\Enums\Organization\SlugReservationReason;
use App\Exceptions\Organization\InvalidOrganizationSlugException;
use App\Support\Organization\OrganizationSlug;
use App\Support\Organization\OrganizationSlugReservedWords;

/*
 * 予約語の読み込みは fail-closed (家系裁定 AG-039 / 不変条件 I9)。
 * **理由 3 分類の記載は必須**であり、分類の無い語・未知の分類は読み込み時に落ちる。
 */

test('設定の語は型付きの分類へ変換される', function (): void {
    $reserved = OrganizationSlugReservedWords::load();

    expect($reserved->reservationFor(OrganizationSlug::fromString('create')))
        ->toBe(SlugReservationReason::RouteConflict);
    expect($reserved->reservationFor(OrganizationSlug::fromString('admin')))
        ->toBe(SlugReservationReason::AuthorityImpersonation);
    expect($reserved->reservationFor(OrganizationSlug::fromString('www')))
        ->toBe(SlugReservationReason::SyntaxConflict);
    expect($reserved->reservationFor(OrganizationSlug::fromString('acme')))->toBeNull();
});

test('分類が無い語は読み込みで落ちる', function (): void {
    expect(fn (): OrganizationSlugReservedWords => OrganizationSlugReservedWords::load(['admin' => '']))
        ->toThrow(RuntimeException::class);
});

test('未知の分類は読み込みで落ちる', function (): void {
    expect(fn (): OrganizationSlugReservedWords => OrganizationSlugReservedWords::load(['admin' => 'because_i_said_so']))
        ->toThrow(RuntimeException::class);
});

test('構文違反の語が設定に紛れたら読み込みで落ちる (照合が黙って外れない)', function (): void {
    expect(fn (): OrganizationSlugReservedWords => OrganizationSlugReservedWords::load([
        'Admin' => SlugReservationReason::AuthorityImpersonation->value,
    ]))->not->toThrow(RuntimeException::class);   // 大文字は正規化されて通る

    expect(fn (): OrganizationSlugReservedWords => OrganizationSlugReservedWords::load([
        'ad min' => SlugReservationReason::AuthorityImpersonation->value,
    ]))->toThrow(InvalidOrganizationSlugException::class);
});

test('空の設定は読み込みで落ちる (設定の読み込み失敗を黙って許さない)', function (): void {
    expect(fn (): OrganizationSlugReservedWords => OrganizationSlugReservedWords::load([]))
        ->toThrow(RuntimeException::class);
});
