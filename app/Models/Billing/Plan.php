<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\PlanPriceKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * プラン定義 (PlanSeeder が真実源)。
 *
 * 規約: コードにプラン名 (code) で分岐を書かない。能力は monthly_ticket_grant と
 * config/quota.php の limits の「値」で表現する (docs 07 ガイド §4)。
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int $monthly_ticket_grant
 * @property int $sort_order
 * @property bool $is_active
 */
class Plan extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code',
        'name',
        'monthly_ticket_grant',
        'sort_order',
        'is_active',
    ];

    /**
     * @return HasMany<PlanPrice, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    /** 指定 kind の現行 (is_current) Price。Checkout 開始時に参照する */
    public function currentPrice(PlanPriceKind $kind): ?PlanPrice
    {
        return $this->prices()
            ->where('kind', $kind->value)
            ->where('is_current', true)
            ->first();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'monthly_ticket_grant' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
