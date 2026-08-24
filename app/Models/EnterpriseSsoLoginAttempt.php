<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\EnterpriseSsoLoginAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 企業 SSO のログイン試行 (state の使用権の唯一性とブラウザ結合を持つ行)。
 *
 * ★state / nonce / ブラウザ結合の秘密は **指紋だけ**を保存する (原文は保存しない)。
 *   PKCE の検証子だけは token 交換でそのまま送るので暗号化して原文を保存する。
 *
 * @property int $id
 * @property int $organization_oidc_connection_id
 * @property string $state_fingerprint
 * @property string $nonce_fingerprint
 * @property string $browser_binding_fingerprint
 * @property string $pkce_verifier_encrypted
 * @property CarbonImmutable $expires_at
 */
class EnterpriseSsoLoginAttempt extends Model
{
    /** @use HasFactory<EnterpriseSsoLoginAttemptFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = [
        'pkce_verifier_encrypted',
        'state_fingerprint',
        'nonce_fingerprint',
        'browser_binding_fingerprint',
    ];

    /** @return BelongsTo<OrganizationOidcConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(OrganizationOidcConnection::class, 'organization_oidc_connection_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'pkce_verifier_encrypted' => 'encrypted',
        ];
    }
}
