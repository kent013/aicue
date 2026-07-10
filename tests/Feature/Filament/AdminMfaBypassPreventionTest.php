<?php

declare(strict_types=1);

use App\Models\AdminUser;

/*
 * MFA 必須化 invariant の検証。
 *
 * MFA 未設定 admin が `actingAs($admin, 'admin')` 経由で admin panel 内の任意 URL に
 * アクセスしても、`EnsureMultiFactorAuthenticationIsEnabled` middleware が
 * setUpRequired URL に redirect する (= バイパスされない)。
 *
 * 注意: `actingAs()` は login flow をスキップするが、middleware 自体は通常通り走るため
 * MFA 検証は bypass されない (= 「MFA 必須化は session の有無に依存しない」)。
 * また、テスト環境では ADMIN_MFA_REQUIRED 未設定 = 既定 true で boot するため、
 * 本テストは「既定で必須」であること自体も検証している。
 */

test('MFA 未設定 admin は admin resource URL にアクセスすると setUpRequired URL に redirect される', function (): void {
    $admin = AdminUser::factory()->create();

    $this->actingAs($admin, 'admin');

    // Filament panel root `/admin` はダッシュボードを返すため、
    // resource URL を直接叩いて MFA middleware の判定を踏む。
    $response = $this->get('/admin/organizations');

    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toContain('multi-factor-authentication/set-up');
});

test('MFA 未設定 admin は panel root /admin でも setUpRequired URL に redirect される', function (): void {
    $admin = AdminUser::factory()->create();

    $this->actingAs($admin, 'admin');

    $response = $this->get('/admin');

    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toContain('multi-factor-authentication/set-up');
});

test('MFA 設定済 admin は admin resource URL にアクセスできる (resource 経路の通過)', function (): void {
    $admin = AdminUser::factory()->withMfa()->create();

    $this->actingAs($admin, 'admin');

    $response = $this->get('/admin/organizations');

    // 200 (= 表示) or 302 (= 他 URL へ redirect)。いずれにせよ setUpRequired には飛ばない。
    expect($response->getStatusCode())->toBeIn([200, 302]);
    if ($response->getStatusCode() === 302) {
        expect($response->headers->get('Location'))->not->toContain('multi-factor-authentication/set-up');
    }
});
