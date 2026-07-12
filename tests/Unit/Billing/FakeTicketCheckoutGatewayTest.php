<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\Models\Organization;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;

/*
 * runtime fake (App\Services\Billing\Fakes\FakeTicketCheckoutGateway) の不変条件:
 * - session id は idempotency key から決定的に導出 (同一 attempt の再送は同一 session に収束)
 * - 戻り URL は cancel URL ベース + 観測用 marker `fake_external=stripe` (アプリは解釈しない)
 */

function fakeRuntimeTicketCheckout(
    string $idempotencyKey,
    string $cancelUrl = 'https://app.test/purchase-tickets',
): CreatedCheckoutSession {
    return (new FakeTicketCheckoutGateway)->createTicketCheckout(
        Organization::factory()->make(),
        'price_test',
        10,
        'https://app.test/purchase-tickets?purchased=1',
        $cancelUrl,
        $idempotencyKey,
        [],
    );
}

test('同一 idempotency key からは同一 sessionId が返る (決定論収束)', function (): void {
    expect(fakeRuntimeTicketCheckout('attempt-1')->sessionId)
        ->toBe(fakeRuntimeTicketCheckout('attempt-1')->sessionId);
});

test('異なる idempotency key からは異なる sessionId が返る', function (): void {
    expect(fakeRuntimeTicketCheckout('attempt-1')->sessionId)
        ->not->toBe(fakeRuntimeTicketCheckout('attempt-2')->sessionId);
});

test('sessionId は cs_bughuntfake_ + 32 桁 hex の固定長トークン (退行検出)', function (): void {
    expect(fakeRuntimeTicketCheckout('attempt-1')->sessionId)->toMatch('/^cs_bughuntfake_[0-9a-f]{32}$/');
});

test('戻り URL は cancel URL ベースで fake_external=stripe marker が付与される', function (): void {
    // query なし → `?` で連結
    expect(fakeRuntimeTicketCheckout('a', 'https://app.test/purchase-tickets')->url)
        ->toBe('https://app.test/purchase-tickets?fake_external=stripe');

    // 既存 query あり → `&` で連結
    expect(fakeRuntimeTicketCheckout('a', 'https://app.test/purchase-tickets?foo=1')->url)
        ->toBe('https://app.test/purchase-tickets?foo=1&fake_external=stripe');
});

test('expireCheckoutSession は expired を返す (状態を持たない stub)', function (): void {
    expect((new FakeTicketCheckoutGateway)->expireCheckoutSession('cs_any'))->toBe('expired');
});
