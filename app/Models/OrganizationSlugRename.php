<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\OrganizationSlugRenameFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 識別名の改名履歴 (家系裁定 AG-046)。
 *
 * ★**旧識別名は予約せず解放する** (不変条件 I13) ため、`from_slug` / `to_slug` に
 *   一意制約は張らない。履歴は「30 日あたり 5 回」の回数判定にのみ使う。
 * ★全列が $fillable 外である。組織と実行者は relation で associate し、
 *   それ以外はサーバ導出値を forceFill で明示代入する
 *   (tenant / actor キーを payload から受け取らない = セキュリティ不変条件 1)。
 *
 * @property int $organization_id
 * @property int|null $renamed_by_user_id
 * @property string $from_slug
 * @property string $to_slug
 * @property CarbonImmutable $renamed_at
 */
class OrganizationSlugRename extends Model
{
    /** @use HasFactory<OrganizationSlugRenameFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function renamedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'renamed_by_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'renamed_at' => 'immutable_datetime',
        ];
    }
}
