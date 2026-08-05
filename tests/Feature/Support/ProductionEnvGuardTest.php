<?php

declare(strict_types=1);

use App\Support\ProductionEnvGuard;

beforeEach(function (): void {
    // production 必須項目の baseline (すべて有効値)。各テストで 1 項目ずつ崩す。
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    config(['ciphersweet.providers.string.key' => str_repeat('a', 64)]);
    config(['cashier.webhook.secret' => 'whsec_valid']);
    config(['session.secure' => true]);
    config(['app.debug' => false]);
    config(['security.hsts.enabled' => true]);
    config(['security.csp.enabled' => true]);
    config(['debug.login.user' => '']);
    config(['debug.login.password' => '']);
    config(['testing.fake_externals' => false]);
    config(['testing.fake_llm' => false]);
    config(['testing.fake_storage' => false]);
    config(['trusted_hosts.exact_hosts' => ['app.example.com']]);
    config(['trusted_hosts.wildcard_suffixes' => []]);
    config(['trusted_hosts.raw_wildcard_suffixes' => []]);
    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
    config(['trustedproxy.raw_proxies' => ['10.0.0.0/8']]);
});

test('全 production 必須項目が埋まっていれば violations は空', function (): void {
    expect((new ProductionEnvGuard)->violations())->toBe([]);
});

test('APP_KEY が空なら violation', function (): void {
    config(['app.key' => '']);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('APP_KEY');
});

test('CIPHERSWEET_KEY が空なら violation', function (): void {
    config(['ciphersweet.providers.string.key' => '']);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('CIPHERSWEET_KEY');
});

test('STRIPE_WEBHOOK_SECRET が空なら violation', function (): void {
    config(['cashier.webhook.secret' => '']);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('STRIPE_WEBHOOK_SECRET');
});

test('SESSION_SECURE_COOKIE が true でないなら violation', function (): void {
    config(['session.secure' => false]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('SESSION_SECURE_COOKIE');
});

test('SESSION_SECURE_COOKIE が null なら violation (auto は許容しない)', function (): void {
    config(['session.secure' => null]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors[0])->toContain('SESSION_SECURE_COOKIE');
});

test('APP_DEBUG が true なら violation', function (): void {
    config(['app.debug' => true]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('APP_DEBUG must be false');
});

test('SECURITY_HSTS_ENABLED が true でないなら violation', function (): void {
    config(['security.hsts.enabled' => false]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('SECURITY_HSTS_ENABLED');
});

test('SECURITY_CSP_ENABLED が true でないなら violation', function (): void {
    config(['security.csp.enabled' => false]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('SECURITY_CSP_ENABLED');
});

test('DEBUG_LOGIN_USER が production で残っていたら violation', function (): void {
    config(['debug.login.user' => 'admin']);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('DEBUG_LOGIN_USER');
});

test('DEBUG_LOGIN_PASSWORD が production で残っていたら violation', function (): void {
    config(['debug.login.password' => 'plain-pass']);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('DEBUG_LOGIN');
});

test('TESTING_FAKE_EXTERNALS が true なら violation', function (): void {
    config(['testing.fake_externals' => true]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('TESTING_FAKE_EXTERNALS');
});

test('TESTING_FAKE_LLM が true なら violation', function (): void {
    config(['testing.fake_llm' => true]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('TESTING_FAKE_LLM');
});

test('TESTING_FAKE_STORAGE が true なら violation', function (): void {
    config(['testing.fake_storage' => true]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('TESTING_FAKE_STORAGE');
});

test('TrustHosts allowlist が空なら violation', function (): void {
    config(['trusted_hosts.exact_hosts' => []]);
    config(['trusted_hosts.wildcard_suffixes' => []]);
    config(['trusted_hosts.raw_wildcard_suffixes' => []]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('TrustHosts allowlist is empty');
});

test('TrustHosts の不正 wildcard 書式なら violation', function (): void {
    config(['trusted_hosts.exact_hosts' => ['app.example.com']]);
    config(['trusted_hosts.wildcard_suffixes' => []]);
    config(['trusted_hosts.raw_wildcard_suffixes' => ['preview.example.com']]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('leading dot');
});

test('複数 violation を全件返す', function (): void {
    config(['cashier.webhook.secret' => '']);
    config(['session.secure' => false]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(2);
});

test('enforce() は violations あれば RuntimeException', function (): void {
    config(['cashier.webhook.secret' => '']);
    expect(fn () => (new ProductionEnvGuard)->enforce())
        ->toThrow(RuntimeException::class, 'Production env baseline violations');
});

test('enforce() は violations なしなら例外なし', function (): void {
    expect(fn () => (new ProductionEnvGuard)->enforce())->not->toThrow(RuntimeException::class);
});

// --- production:preflight コマンド (guard へ委譲 + --strict) ---

test('production:preflight は非 production では skip して成功する', function (): void {
    $this->artisan('production:preflight')->assertSuccessful();
});

test('production:preflight --strict は非 production で fail する', function (): void {
    $this->artisan('production:preflight', ['--strict' => true])->assertFailed();
});

test('production:preflight は production で violations があれば fail する', function (): void {
    config(['app.env' => 'production']);
    config(['cashier.webhook.secret' => '']);
    $this->artisan('production:preflight')->assertFailed();
});

test('production:preflight は production で全項目通過なら成功する', function (): void {
    config(['app.env' => 'production']);
    // preflight は operations:check-mail-config も呼ぶため mail baseline も有効値にする
    config(['mail.default' => 'ses']);
    config(['mail.from.address' => 'noreply@app.example.com']);
    config(['app.url' => 'https://app.example.com']);
    $this->artisan('production:preflight')->assertSuccessful();
});

test('production:preflight は production で mail 設定不備 (MAIL_MAILER=log) なら fail する', function (): void {
    config(['app.env' => 'production']);
    config(['mail.default' => 'log']);
    config(['mail.from.address' => 'noreply@app.example.com']);
    config(['app.url' => 'https://app.example.com']);
    $this->artisan('production:preflight')->assertFailed();
});

/*
 * TrustProxies allowlist (client IP の信頼境界。audit-cycle-2 High-2 / T108 S5)。
 * 未宣言のまま production を起動すると fail-fast するのは **意図した破壊的変更**。
 */

test('TRUSTED_PROXIES が未設定なら violation', function (): void {
    config(['trustedproxy.proxies' => []]);
    config(['trustedproxy.raw_proxies' => ['']]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('TRUSTED_PROXIES is not set');
});

test('TRUSTED_PROXIES に * が含まれるなら violation (XFF 偽装が通る)', function (): void {
    config(['trustedproxy.proxies' => []]);
    config(['trustedproxy.raw_proxies' => ['*']]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('Trusting every address');
});

test('TRUSTED_PROXIES に REMOTE_ADDR が含まれるなら violation', function (): void {
    config(['trustedproxy.proxies' => ['REMOTE_ADDR']]);
    config(['trustedproxy.raw_proxies' => ['REMOTE_ADDR']]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('REMOTE_ADDR');
});

test('TRUSTED_PROXIES に書式不正が含まれるなら violation (config 段の silent drop を表面化)', function (): void {
    // config 段では落ちるので proxies には現れない = raw を見ないと検知できない
    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
    config(['trustedproxy.raw_proxies' => ['10.0.0.0/8', '999.999.999.999/99']]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('invalid value');
});

test('TRUSTED_PROXIES に none と他の値を併記したら violation (曖昧宣言)', function (): void {
    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
    config(['trustedproxy.raw_proxies' => ['none', '10.0.0.0/8']]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('must be declared alone');
});

test('TRUSTED_PROXIES=none 単独なら violations は空 (プロキシ無し構成の明示宣言)', function (): void {
    config(['trustedproxy.proxies' => []]);
    config(['trustedproxy.raw_proxies' => ['none']]);
    expect((new ProductionEnvGuard)->violations())->toBe([]);
});

test('TRUSTED_PROXIES 未設定なら enforce() が起動を止める (production の fail-fast)', function (): void {
    config(['trustedproxy.proxies' => []]);
    config(['trustedproxy.raw_proxies' => ['']]);

    expect(fn () => (new ProductionEnvGuard)->enforce())
        ->toThrow(RuntimeException::class, 'TRUSTED_PROXIES is not set');
});
