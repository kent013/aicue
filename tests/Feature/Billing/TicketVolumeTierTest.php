<?php

declare(strict_types=1);

use App\Exceptions\Billing\TicketVolumeTierUnavailableException;
use App\Models\Billing\TicketVolumePrice;
use Carbon\CarbonImmutable;
use Database\Seeders\TicketVolumePriceSeeder;
use Illuminate\Database\QueryException;

/*
 * スポット購入の数量逐減 (volume tier) 単価。
 * - tier 解決: min_count <= count を満たす最大 min_count の current 行
 * - 該当なしは fail-closed (TicketVolumeTierUnavailableException)
 * - floor (config billing.ticket_unit_price_floor) 未満の単価は設定異常として停止
 * - TicketVolumePriceSeeder はオプトインのサンプル段 (DatabaseSeeder 非登録)
 */

/** current の tier 行を明示投入する */
function seedVolumeTier(int $minCount, int $unitAmount): TicketVolumePrice
{
    return TicketVolumePrice::query()->create([
        'lookup_key' => "app_ticket_volume_{$minCount}",
        'stripe_price_id' => "price_test_tier_{$minCount}",
        'min_count' => $minCount,
        'unit_amount' => $unitAmount,
        'currency' => 'jpy',
        'livemode' => false,
        'synced_at' => null,
        'active_from' => CarbonImmutable::now(),
        'active_to' => null,
        'is_current' => true,
    ]);
}

test('min_count <= count を満たす最大 min_count の段が解決される', function (): void {
    seedVolumeTier(1, 100);
    seedVolumeTier(20, 80);
    seedVolumeTier(50, 70);

    expect(TicketVolumePrice::currentTierFor(1)->unitAmount)->toBe(100);
    expect(TicketVolumePrice::currentTierFor(19)->unitAmount)->toBe(100);
    expect(TicketVolumePrice::currentTierFor(20)->unitAmount)->toBe(80);
    expect(TicketVolumePrice::currentTierFor(49)->unitAmount)->toBe(80);

    $tier = TicketVolumePrice::currentTierFor(1000);
    expect($tier->unitAmount)->toBe(70);
    expect($tier->minCount)->toBe(50);
    expect($tier->stripePriceId)->toBe('price_test_tier_50');
});

test('適用できる段が無い場合は fail-closed で例外 (別段へ黙って落とさない)', function (): void {
    seedVolumeTier(20, 80); // min_count=1 の spot 段が無い

    expect(fn () => TicketVolumePrice::currentTierFor(10))
        ->toThrow(TicketVolumeTierUnavailableException::class);
});

test('current でない履歴行は tier 解決の対象にならない', function (): void {
    TicketVolumePrice::query()->create([
        'lookup_key' => 'app_ticket_volume_1',
        'stripe_price_id' => 'price_test_old_1',
        'min_count' => 1,
        'unit_amount' => 120,
        'currency' => 'jpy',
        'livemode' => false,
        'active_from' => CarbonImmutable::now()->subYear(),
        'active_to' => CarbonImmutable::now()->subDay(), // 履歴行 (is_current=false)
        'is_current' => false,
    ]);

    expect(fn () => TicketVolumePrice::currentTierFor(1))
        ->toThrow(TicketVolumeTierUnavailableException::class);
});

test('floor を下回る単価は設定異常として停止する', function (): void {
    config()->set('billing.ticket_unit_price_floor', 50);
    seedVolumeTier(500, 40); // floor 割れの設定異常

    expect(fn () => TicketVolumePrice::currentTierFor(600))
        ->toThrow(InvalidArgumentException::class, 'floor');
});

test('購入枚数の範囲外 (0 / 上限超) は入力検証で弾かれる', function (): void {
    seedVolumeTier(1, 100);

    expect(fn () => TicketVolumePrice::currentTierFor(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => TicketVolumePrice::currentTierFor(1001))->toThrow(InvalidArgumentException::class);
});

test('current は min_count あたり 1 行 (DB invariant で二重 current を拒否)', function (): void {
    seedVolumeTier(20, 80);

    expect(fn () => seedVolumeTier(20, 75))->toThrow(QueryException::class);
});

test('TicketVolumePriceSeeder はサンプル段を投入し再実行しても二重投入しない (オプトイン)', function (): void {
    $seeder = new TicketVolumePriceSeeder;
    $seeder->run();
    $seeder->run(); // 再実行安全

    // サンプル段 7 段 (1/20/50/100/200/300/500)。current は各段 1 行
    expect(TicketVolumePrice::query()->count())->toBe(7);
    expect(TicketVolumePrice::query()->where('is_current', true)->count())->toBe(7);

    // lookup_key は {slug}_ticket_volume_{min_count} 規約 (slug は config/template.php)
    $slug = config()->string('template.slug');
    expect(
        TicketVolumePrice::query()->where('min_count', 20)->firstOrFail()->lookup_key,
    )->toBe("{$slug}_ticket_volume_20");

    // spot (min_count=1) から floor 段 (500 枚〜) まで逐減で解決できる
    expect(TicketVolumePrice::currentTierFor(1)->unitAmount)->toBe(100);
    expect(TicketVolumePrice::currentTierFor(500)->unitAmount)
        ->toBe(config('billing.ticket_unit_price_floor'));
});

test('TicketVolumePriceSeeder は sync 済 row を触らない (bootstrap 専用)', function (): void {
    // sync 済 (synced_at あり) の段を先に置く
    TicketVolumePrice::query()->create([
        'lookup_key' => 'app_ticket_volume_20',
        'stripe_price_id' => 'price_live_20',
        'min_count' => 20,
        'unit_amount' => 90, // seeder のサンプル値 (80) と異なる
        'currency' => 'jpy',
        'livemode' => false,
        'synced_at' => CarbonImmutable::now(),
        'active_from' => CarbonImmutable::now(),
        'active_to' => null,
        'is_current' => true,
    ]);

    (new TicketVolumePriceSeeder)->run();

    // sync 済の段は上書きされない
    $row = TicketVolumePrice::query()->where('min_count', 20)->where('is_current', true)->firstOrFail();
    expect($row->unit_amount)->toBe(90);
    expect($row->stripe_price_id)->toBe('price_live_20');
});
