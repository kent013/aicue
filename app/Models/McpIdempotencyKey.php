<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Mcp\McpIdempotencyService;
use Carbon\CarbonImmutable;
use Database\Factories\McpIdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MCP 書き込み系 tool の冪等性レコード。
 *
 * 既存 `idempotency_keys` (REST 向け) とは独立。caller 境界は
 * `(organization_id, user_id, tool_name, idempotency_key)`
 * (利用側は {@see McpIdempotencyService})。
 *
 * @property int $id
 * @property int $organization_id
 * @property int $user_id
 * @property string $tool_name
 * @property string $idempotency_key
 * @property string $payload_hash
 * @property array<string, mixed>|null $response_body
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $expires_at
 */
class McpIdempotencyKey extends Model
{
    /** @use HasFactory<McpIdempotencyKeyFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * ownership キー (organization_id / user_id) は $fillable から除外し、
     * named creation method (`recordForOrgAndUser`) で明示代入する (二層防御)。
     *
     * @var list<string>
     */
    protected $fillable = [
        'tool_name',
        'idempotency_key',
        'payload_hash',
        'response_body',
        'created_at',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'created_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Org + user スコープで MCP 冪等性レコードを生成する named creation method。
     *
     * organization_id (ownership) と user_id (user-scoped ownership) は $fillable から
     * 除外しているため、本 method が両 key を明示代入する唯一の正規経路。
     *
     * 引数を int で受けるのは McpIdempotencyService の API と整合させるため
     * (Model に解決し直すと余分な DB 往復が発生する)。
     *
     * @param  array<string, mixed>  $responseBody
     */
    public static function recordForOrgAndUser(
        int $organizationId,
        int $userId,
        string $toolName,
        string $idempotencyKey,
        string $payloadHash,
        array $responseBody,
        CarbonImmutable $createdAt,
        CarbonImmutable $expiresAt,
    ): self {
        $key = new self;
        $key->fill([
            'tool_name' => $toolName,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => $payloadHash,
            'response_body' => $responseBody,
            'created_at' => $createdAt,
            'expires_at' => $expiresAt,
        ]);
        $key->organization_id = $organizationId;
        $key->user_id = $userId;
        $key->save();

        return $key;
    }
}
