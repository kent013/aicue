<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\Billing\PlanPriceKind;

/**
 * Stripe Price Catalog の lookup_key 宣言の単一出典 (desired state)。
 *
 * lookup_key は `{app_slug}_{plan_code}_{kind}` 規約 (slug は config/template.php)。
 * `stripe/fixtures/*.json` 側の lookup_key はリテラルのため、slug 変更・プラン追加時は
 * fixture も合わせて更新する (tests/Architecture/StripePriceCatalogFixtureInvariantTest
 * が集合一致を CI で固定する)。
 *
 * 注: ここはプラン能力の分岐ではなく「どのプランがどの Price を持つか」という
 * カタログ宣言 (PlanSeeder と同格のデータ)。lookup_key に金額を含めない
 * (価格改定で名前が嘘になるため)。
 */
final class StripePriceLookupKeys
{
    /**
     * Checkout 経路を持つプラン → 価格 kind の宣言。
     * free (未契約の既定) と personal (activate 経由の無料プラン = Checkout を
     * 通らない) は Price を持たないため含めない。
     *
     * @var array<string, list<PlanPriceKind>>
     */
    private const CATALOG = [
        'starter' => [PlanPriceKind::Base],
        'standard' => [PlanPriceKind::Base],
    ];

    /**
     * lookup_key => {plan_code, kind} の全マップ。
     *
     * @return array<string, array{plan_code: string, kind: PlanPriceKind}>
     */
    public static function map(): array
    {
        $map = [];
        foreach (self::CATALOG as $planCode => $kinds) {
            foreach ($kinds as $kind) {
                $map[self::key($planCode, $kind)] = ['plan_code' => $planCode, 'kind' => $kind];
            }
        }

        return $map;
    }

    /** lookup_key を規約 (`{app_slug}_{plan_code}_{kind}`) から導出する */
    public static function key(string $planCode, PlanPriceKind $kind): string
    {
        $slug = config()->string('template.slug');

        return "{$slug}_{$planCode}_{$kind->value}";
    }
}
