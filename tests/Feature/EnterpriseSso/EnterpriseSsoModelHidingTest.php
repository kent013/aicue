<?php

declare(strict_types=1);

use App\Models\EmailPromotion;
use App\Models\EnterpriseIdentity;
use App\Models\EnterpriseSsoLoginAttempt;
use App\Models\OrganizationOidcConnection;
use App\ValueObjects\EnterpriseSso\ConnectionSecret;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/*
 * 4 モデルの `$hidden` と `$fillable` の実効 (A2 / E1)。
 */

test('接続の toArray に暗号文も秘密の値型も出ない', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();

    $array = $connection->toArray();

    expect($array)->not->toHaveKey('client_secret_encrypted');
    expect(json_encode($array, JSON_THROW_ON_ERROR))->not->toContain('secret-');
});

test('身元の toArray に申告メールの暗号文が出ない', function (): void {
    $identity = EnterpriseIdentity::factory()->create(['claimed_email_encrypted' => 'worker@corp.example']);

    expect($identity->toArray())->not->toHaveKey('claimed_email_encrypted');
});

test('試行の toArray に指紋も検証子も出ない', function (): void {
    $attempt = EnterpriseSsoLoginAttempt::factory()->create();

    $array = $attempt->toArray();

    foreach (['pkce_verifier_encrypted', 'state_fingerprint', 'nonce_fingerprint', 'browser_binding_fingerprint'] as $key) {
        expect($array)->not->toHaveKey($key);
    }
});

test('昇格の toArray にトークンの指紋もメールも出ない', function (): void {
    $promotion = EmailPromotion::factory()->create();

    $array = $promotion->toArray();

    expect($array)->not->toHaveKey('token_fingerprint');
    expect($array)->not->toHaveKey('email_encrypted');
});

test('4 モデルとも $fillable が空である (mass assignment を作らない)', function (string $model): void {
    /** @var Model $instance */
    $instance = new $model;

    expect($instance->getFillable())->toBe([]);
})->with([
    OrganizationOidcConnection::class,
    EnterpriseIdentity::class,
    EnterpriseSsoLoginAttempt::class,
    EmailPromotion::class,
]);

test('client secret は暗号化して保存され、値型でしか読み出せない', function (): void {
    $connection = OrganizationOidcConnection::factory()->create([
        'client_secret_encrypted' => ConnectionSecret::fromPlaintext('plain-secret-value'),
    ]);

    /** @var object{client_secret_encrypted: string} $raw */
    $raw = DB::table('organization_oidc_connections')->where('id', $connection->id)->first();

    expect($raw->client_secret_encrypted)->not->toContain('plain-secret-value');
    expect($connection->fresh()?->clientSecret()->revealForTokenExchange())->toBe('plain-secret-value');
});

test('秘密の有無は復号せずに判定できる', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();

    expect($connection->hasClientSecret())->toBeTrue();
    expect($connection->clientSecretCiphertextDigest())->toMatch('/\A[0-9a-f]{64}\z/');
});

test('PKCE の検証子は暗号化して保存され、読み出しで平文に戻る', function (): void {
    $attempt = EnterpriseSsoLoginAttempt::factory()->create(['pkce_verifier_encrypted' => 'verifier-value']);

    /** @var object{pkce_verifier_encrypted: string} $raw */
    $raw = DB::table('enterprise_sso_login_attempts')->where('id', $attempt->id)->first();

    expect($raw->pkce_verifier_encrypted)->not->toContain('verifier-value');
    expect($attempt->fresh()?->pkce_verifier_encrypted)->toBe('verifier-value');
});
