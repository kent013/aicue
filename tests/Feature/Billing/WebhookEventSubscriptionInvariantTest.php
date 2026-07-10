<?php

declare(strict_types=1);

use App\Enums\Billing\HandledStripeWebhookEvent;
use App\Enums\Billing\WebhookEventStatus;
use App\Models\Billing\StripeWebhookEvent;
use Laravel\Cashier\Events\WebhookReceived;

/*
|--------------------------------------------------------------------------
| webhook イベント集合整合の CI invariant
|--------------------------------------------------------------------------
|
| 「StripeWebhookProcessor が処理するイベント集合 (Handled)」が
| 「endpoint が購読する集合 (= config('cashier.webhook.events'))」に
| 必ず含まれることを固定する。購読漏れは production で付与イベント
| (checkout.session.completed / invoice.paid) を silent に取りこぼす事故の
| 構造的原因になるため、CI で再発検知する。
|
| Cashier の DEFAULT_EVENTS (customer.* housekeeping) も購読するため
| 「config == handled 完全一致」ではなく「handled ⊆ subscribed」の包含を固定する
| (subscribed が handled の上位集合)。
*/

test('handled な webhook イベントはすべて cashier config で購読されている', function (): void {
    $subscribed = config('cashier.webhook.events');

    expect(array_values(array_diff(HandledStripeWebhookEvent::values(), $subscribed)))->toBe([]);
});

test('購読集合に重複がない', function (): void {
    $subscribed = config('cashier.webhook.events');

    expect($subscribed)->toBe(array_values(array_unique($subscribed)));
});

test('付与トリガーの重要イベントが購読集合に含まれる', function (): void {
    // production で付与が silent に発火しない事故の根本イベントが購読されることを固定
    $subscribed = config('cashier.webhook.events');

    expect($subscribed)
        ->toContain('checkout.session.completed') // 拡張点: チケット購入付与
        ->toContain('invoice.paid')               // 月次付与
        ->toContain('invoice.payment_failed');    // 拡張点: 督促・決済ゲート
});

test('enum の全 case が processor で例外なく受理される (match arm 欠落の検出)', function (): void {
    // enum に case を足したのに process() の match に arm を足し忘れると
    // UnhandledMatchError ではなく null 落ちはしない (enum match は網羅性を PHPStan が検査)。
    // ここでは実際に WebhookReceived を発火し、全 case が processed で終わることを固定する
    // (未知の customer/最小 payload のため各 handler は no-op で正常終了する)。
    foreach (HandledStripeWebhookEvent::cases() as $case) {
        event(new WebhookReceived([
            'id' => 'evt_route_'.$case->name,
            'type' => $case->value,
            'data' => ['object' => ['id' => 'obj_'.$case->name, 'customer' => 'cus_none_'.$case->name]],
        ]));

        $record = StripeWebhookEvent::query()
            ->where('event_id', 'evt_route_'.$case->name)
            ->firstOrFail();
        expect($record->status)->toBe(WebhookEventStatus::Processed);
    }
});
