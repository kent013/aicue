<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 組織別 Quota override (organizations 1:1)。
 *
 * limits は config/quota.php のプラン既定値を key 単位で上書きする JSON。
 * organization_id は所有権キーのため $fillable 外 ($organization->quota() relation 経由で生成する)。
 *
 * @property int $id
 * @property int $organization_id
 * @property array<mixed> $limits
 */
class OrganizationQuota extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'limits',
    ];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'limits' => 'array',
        ];
    }
}
