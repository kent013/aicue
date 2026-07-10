<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OAuth\OAuthClientKind;
use App\Http\Middleware\ResolveApiActor;
use Database\Factories\OauthSessionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OAuth セッション (CLI ログインの「認可承認 1 回 = 1 セッション」集約ルート)。
 *
 * refresh token rotation で oauth_access_tokens / oauth_refresh_tokens の行が増えても、
 * chain 全体が `session_id` で本テーブル 1 行に集約される。一覧表示・失効はセッション単位。
 *
 * - `client_kind` は作成時点の `oauth_clients.client_kind` snapshot
 *   ({@see OAuthClientKind} の値)。REST dual guard の actor 解決
 *   ({@see ResolveApiActor}) は 'cli' のみ受理する。
 * - 失効の実体は {@see revoke()} による配下 token の revoke
 *   (Passport guard / refresh grant が既存機構で拒否する)。
 *
 * @property string $id
 * @property int $user_id
 * @property int $organization_id
 * @property string $client_id
 * @property string $client_kind
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Organization $organization
 */
class OauthSession extends Model
{
    /** @use HasFactory<OauthSessionFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * `last_used_at` 更新の間引き間隔 (分)。
     *
     * 認証ヒット毎に UPDATE を走らせると高頻度アクセス時に write hot spot になるため、
     * 最後の更新から下記分数経っていない場合は UPDATE をスキップする (ApiKeyGuard と同方針)。
     */
    private const LAST_USED_UPDATE_THRESHOLD_MINUTES = 5;

    /**
     * 所有権キー (user_id / organization_id / client_id) は発行経路で明示代入し、
     * mass assignment からは除外する (ApiKey と同じ二層防御方針)。
     *
     * @var list<string>
     */
    protected $fillable = [
        'client_kind',
        'last_used_at',
        'revoked_at',
    ];

    /** @var list<string> */
    protected $guarded = [
        'id',
        'user_id',
        'organization_id',
        'client_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * 発行経路 (McpAuthCodeRepository) 用の named creation method。
     *
     * 所有権キー (user_id / organization_id / client_id) は $fillable 外のため、
     * 本 method が forceFill で明示代入する唯一の正規経路。id (uuid) は
     * auth_code へ同一トランザクションで書き込むため caller が採番して渡す。
     */
    public static function createForClient(
        User $user,
        Organization $organization,
        string $clientId,
        OAuthClientKind $kind,
        string $sessionId,
    ): self {
        $session = new self;
        $session->fill(['client_kind' => $kind->value]);
        $session->forceFill([
            'id' => $sessionId,
            'user_id' => $user->id,
            'organization_id' => $organization->id,
            'client_id' => $clientId,
        ]);
        $session->save();

        return $session;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * 未失効セッションに絞る query scope。
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * `last_used_at` を間引き更新する (監査用 best-effort。イベント不要のため quietly)。
     */
    public function touchLastUsedAt(): void
    {
        $now = Carbon::now();
        $lastUsed = $this->last_used_at;

        if ($lastUsed !== null
            && $lastUsed->diffInMinutes($now, absolute: true) < self::LAST_USED_UPDATE_THRESHOLD_MINUTES) {
            return;
        }

        $this->forceFill(['last_used_at' => $now])->saveQuietly();
    }

    /**
     * セッションを失効させる: 配下の全 access token と、その access token に紐づく
     * refresh token を revoke する。
     *
     * - 冪等 + 競合安全: transaction 内で行ロックを取ってから `revoked_at` を再判定する
     *   (同時実行でも初回失効時刻が上書きされない)
     * - token の revoke は Passport の既存検証 (guard / refresh grant) がそのまま
     *   拒否に使うため、guard 側の追加実装は不要
     */
    public function revoke(): void
    {
        DB::transaction(function (): void {
            /** @var self $locked */
            $locked = self::query()->lockForUpdate()->findOrFail($this->getKey());
            if ($locked->revoked_at !== null) {
                return;
            }

            $locked->forceFill(['revoked_at' => Carbon::now()])->save();

            /** @var list<string> $tokenIds */
            $tokenIds = DB::table('oauth_access_tokens')
                ->where('session_id', $this->getKey())
                ->pluck('id')
                ->all();

            if ($tokenIds === []) {
                return;
            }

            DB::table('oauth_access_tokens')
                ->whereIn('id', $tokenIds)
                ->update(['revoked' => true]);
            DB::table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $tokenIds)
                ->update(['revoked' => true]);
        });

        $this->refresh();
    }
}
