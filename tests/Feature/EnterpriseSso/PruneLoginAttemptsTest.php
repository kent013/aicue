<?php

declare(strict_types=1);

use App\Models\EmailPromotion;
use App\Models\EnterpriseSsoLoginAttempt;
use App\Models\OrganizationOidcConnection;
use Illuminate\Support\Facades\Config;

/*
 * 期限切れの一時状態の掃除 (B4 / E1)。
 */

test('期限切れのログイン試行だけが消え、進行中の試行を巻き込まない', function (): void {
    $expired = EnterpriseSsoLoginAttempt::factory()->expired()->create();
    $live = EnterpriseSsoLoginAttempt::factory()->create();

    $this->artisan('enterprise-sso:prune-login-attempts')->assertSuccessful();

    expect(EnterpriseSsoLoginAttempt::query()->whereKey($expired->id)->exists())->toBeFalse();
    expect(EnterpriseSsoLoginAttempt::query()->whereKey($live->id)->exists())->toBeTrue();
});

test('期限切れが無ければ何も消さない', function (): void {
    EnterpriseSsoLoginAttempt::factory()->count(2)->create();

    $this->artisan('enterprise-sso:prune-login-attempts')->assertSuccessful();

    expect(EnterpriseSsoLoginAttempt::query()->count())->toBe(2);
});

test('1 回の実行で消す件数に上限がある (長いトランザクションを作らない)', function (): void {
    Config::set('enterprise-sso.login_attempt.prune_chunk', 1);
    EnterpriseSsoLoginAttempt::factory()->expired()->count(3)->create();

    $this->artisan('enterprise-sso:prune-login-attempts')->assertSuccessful();

    // 上限に達したので残りは次回の実行が消す (単調増加はしない)
    expect(EnterpriseSsoLoginAttempt::query()->count())->toBe(2);
});

test('期限切れのメール昇格だけが消え、進行中を巻き込まない', function (): void {
    $expired = EmailPromotion::factory()->expired()->create();
    $live = EmailPromotion::factory()->create();

    $this->artisan('auth:prune-email-promotions')->assertSuccessful();

    expect(EmailPromotion::query()->whereKey($expired->id)->exists())->toBeFalse();
    expect(EmailPromotion::query()->whereKey($live->id)->exists())->toBeTrue();
});

test('掃除は接続そのものを消さない (親を巻き込まない)', function (): void {
    $attempt = EnterpriseSsoSsoLoginAttemptFixture();

    $this->artisan('enterprise-sso:prune-login-attempts')->assertSuccessful();

    expect(OrganizationOidcConnection::query()->whereKey($attempt->organization_oidc_connection_id)->exists())
        ->toBeTrue();
});

function EnterpriseSsoSsoLoginAttemptFixture(): EnterpriseSsoLoginAttempt
{
    return EnterpriseSsoLoginAttempt::factory()->expired()->create();
}
