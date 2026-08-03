<?php

declare(strict_types=1);

use App\Services\Billing\CashierStripeGateway;

/*
 * F-3-01: subscription swap (プラン変更) payload の invariant。**payload 変更の唯一の入口**。
 *
 * - `items[0]` は **既存 item id を指定**して price を差し替える (id 無指定は item の二重化)。
 * - `proration_behavior = create_prorations` — 日割り明細を作り **次回請求に反映**する。
 *   `always_invoice` にしない (= 即時請求 → 与信失敗の状態遷移を持ち込まない)。
 * - `billing_cycle_anchor` / `trial_end` / `payment_behavior` / `default_tax_rates` は **送らない**
 *   (即時請求・trial 再開の誘発を構造的に避ける)。
 */

test('payload は既存 item id と price / quantity=1 と create_prorations だけを返す', function (): void {
    $payload = (new CashierStripeGateway)->buildSwapPayload('si_existing_1', 'price_standard');

    expect($payload)->toBe([
        'items' => [
            ['id' => 'si_existing_1', 'price' => 'price_standard', 'quantity' => 1],
        ],
        'proration_behavior' => 'create_prorations',
    ]);
    // キー集合を厳密一致で固定する (増やすなら本テストを通す = 意図的な変更のみ)
    expect(array_keys($payload))->toBe(['items', 'proration_behavior']);
});

test('payload に即時請求・trial 再開を誘発するパラメータを含めない', function (): void {
    $payload = (new CashierStripeGateway)->buildSwapPayload('si_existing_1', 'price_standard');

    expect($payload)->not->toHaveKey('billing_cycle_anchor');
    expect($payload)->not->toHaveKey('trial_end');
    expect($payload)->not->toHaveKey('payment_behavior');
    expect($payload)->not->toHaveKey('default_tax_rates');
    expect($payload['proration_behavior'])->not->toBe('always_invoice');
});

test('空の item id は fail-fast する', function (): void {
    (new CashierStripeGateway)->buildSwapPayload('', 'price_standard');
})->throws(InvalidArgumentException::class);

test('空の price id は fail-fast する', function (): void {
    (new CashierStripeGateway)->buildSwapPayload('si_existing_1', '');
})->throws(InvalidArgumentException::class);
