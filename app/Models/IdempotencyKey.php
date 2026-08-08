<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Idempotency\IdempotencyState;
use Carbon\CarbonInterface;
use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Idempotency-Key の保存行 (REST API v1 write エンドポイント用)。
 *
 * actor 単位の UNIQUE で同一 actor × route の再送を検知する: API キー actor は
 * (api_key_id, route_name, key)、OAuth user-token actor は (user_id, route_name, key)。
 * expires_at 超過エントリは未使用扱い (保持期間は config/idempotency.php が正本)。
 *
 * 行は本処理の**前**に `processing` として claim され、決着時に `completed` /
 * `indeterminate` へ確定する (release 経路は持たない)。状態遷移は
 * `IdempotentRequest` の insertOrIgnore と条件付き UPDATE だけが行うため、
 * **`state` は $fillable に入れない** (Eloquent 経由の mass assign 経路を作らない)。
 *
 * api_key_id / user_id は所有権キーのため $fillable 外。書き込みは
 * IdempotentRequest::ownershipColumns() の単一構築点のみ。
 *
 * @property int $id
 * @property int|null $api_key_id
 * @property int|null $user_id
 * @property string $route_name
 * @property string $key
 * @property string $request_hash
 * @property IdempotencyState $state
 * @property int|null $response_status
 * @property array<array-key, mixed>|null $response_body
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 */
class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'route_name',
        'key',
        'request_hash',
        'response_status',
        'response_body',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => IdempotencyState::class,
            'response_status' => 'integer',
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * TTL 超過か。超過エントリは未使用扱い (再送時に削除して作り直す)。
     *
     * 引数は `CarbonInterface`。middleware は基準時刻に `CarbonImmutable` を使うが、
     * `Illuminate\Support\Carbon` (mutable) は `CarbonImmutable` の親ではないため、
     * `?Carbon` に狭めると実行時 TypeError になる。
     */
    public function isExpired(?CarbonInterface $now = null): bool
    {
        return $this->expires_at->lessThanOrEqualTo($now ?? Carbon::now());
    }

    /**
     * @return BelongsTo<ApiKey, $this>
     */
    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class);
    }

    /**
     * OAuth user-token actor の保存行の帰属先 (api_key_id とは排他)。
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
