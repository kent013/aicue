<?php

declare(strict_types=1);

/*
 * SecurityHeaders / RedirectToHttps の挙動検証。
 */

use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;

test('全レスポンスに baseline セキュリティヘッダが付く', function (): void {
    $response = $this->get('/');

    $response->assertHeader('X-Frame-Options', 'DENY');
    $response->assertHeader('X-Content-Type-Options', 'nosniff');
    $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    $response->assertHeader('Content-Security-Policy');
    $response->assertHeader(
        'Permissions-Policy',
        'geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")',
    );
});

test('CSP は directive 連想配列から組み立てられる', function (): void {
    config()->set('security.csp.directives', [
        'default-src' => "'self'",
        'frame-ancestors' => "'none'",
    ]);

    $this->get('/')->assertHeader('Content-Security-Policy', "default-src 'self'; frame-ancestors 'none'");
});

test('Permissions-Policy は空文字 (opt-out) で非送出になる', function (): void {
    config()->set('security.permissions_policy', '');

    $this->get('/')->assertHeaderMissing('Permissions-Policy');
});

test('HSTS は有効化時のみ付く', function (): void {
    config()->set('security.hsts.enabled', false);
    $this->get('/')->assertHeaderMissing('Strict-Transport-Security');

    config()->set('security.hsts.enabled', true);
    config()->set('security.hsts.max_age', 300);
    $this->get('/')->assertHeader('Strict-Transport-Security', 'max-age=300; includeSubDomains');
});

test('HSTS preload knob 有効時は preload directive が付く', function (): void {
    config()->set('security.hsts.enabled', true);
    config()->set('security.hsts.max_age', 31536000);
    config()->set('security.hsts.preload', true);

    $this->get('/')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
});

/*
 * `.well-known/oauth-*` (OAuth/MCP discovery metadata) は最小ヘッダ subset のみ。
 * フル baseline (CSP / X-Frame-Options / Permissions-Policy) は付けない。
 */

test('OAuth metadata endpoint には最小 subset のみ付く (CSP / X-Frame-Options / Permissions-Policy なし)', function (): void {
    $response = $this->get('/.well-known/oauth-authorization-server');

    $response->assertOk();
    expect((string) $response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and((string) $response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse()
        ->and($response->headers->has('X-Frame-Options'))->toBeFalse()
        ->and($response->headers->has('Permissions-Policy'))->toBeFalse();
});

test('protected-resource metadata endpoint にも subset が付く', function (): void {
    $response = $this->get('/.well-known/oauth-protected-resource');

    $response->assertOk();
    expect((string) $response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

test('metadata subset は config (security.metadata_headers) の上書きに従う', function (): void {
    config()->set('security.metadata_headers', ['X-Content-Type-Options' => 'nosniff']);

    $response = $this->get('/.well-known/oauth-authorization-server');

    expect((string) $response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        // override したので Referrer-Policy は付かない
        ->and($response->headers->has('Referrer-Policy'))->toBeFalse();
});

test('metadata endpoint の HSTS は metadata_hsts_enabled で個別 gate される', function (): void {
    config()->set('security.metadata_hsts_enabled', false);
    $this->get('/.well-known/oauth-authorization-server')->assertHeaderMissing('Strict-Transport-Security');

    config()->set('security.metadata_hsts_enabled', true);
    config()->set('security.hsts.max_age', 300);
    $this->get('/.well-known/oauth-authorization-server')
        ->assertHeader('Strict-Transport-Security', 'max-age=300; includeSubDomains');
});

test('FORCE_HTTPS_REDIRECT 有効時は HTTP を 308 で HTTPS へ転送する', function (): void {
    config()->set('security.force_https_redirect', true);

    $response = $this->get('http://localhost/');

    $response->assertStatus(308);
    $response->assertRedirect('https://localhost');
});

test('FORCE_HTTPS_REDIRECT 無効時 (既定) は HTTP のまま通す', function (): void {
    config()->set('security.force_https_redirect', false);

    $this->get('http://localhost/')->assertStatus(200);
});

test('production:preflight --strict が非 production 環境を検出して fail する', function (): void {
    // APP_ENV=testing のため --strict では必ず fail する (CI/CD の設定ミス検出)。
    // guard の各検査項目は tests/Feature/Support/ProductionEnvGuardTest.php が網羅する
    $this->artisan('production:preflight', ['--strict' => true])->assertFailed();
});

/*
 * 撮影 PWA のカメラ許可 (T057): 撮影 document route (capture.manuals.show) のみ
 * Permissions-Policy で camera/microphone を (self) に緩め、他ルート・404 は baseline 厳格値を維持する。
 */

/**
 * @return array{User, Project, VideoManual}
 */
function captureShowContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);

    return [$organization, $owner, $project, $manual];
}

test('撮影 document route は camera/microphone を (self) に緩める', function (): void {
    [$organization, $owner, $project, $manual] = captureShowContext();

    // 完全一致で検証: camera/microphone のみ (self)、geolocation / payment は baseline のまま (drift 検出)
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}")
        ->assertOk()
        ->assertHeader(
            'Permissions-Policy',
            'geolocation=(), microphone=(self), camera=(self), payment=(self "https://js.stripe.com")',
        );
});

test('capture 内の非 recorder ルート (index) は厳格な baseline を維持する', function (): void {
    [$organization, $owner, $project] = captureShowContext();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals")
        ->assertOk()
        ->assertHeader(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")',
        );
});

test('binding 失敗 404 には Permissions-Policy が一切付かない (緩和の漏れなし)', function (): void {
    [$organization, $owner, $project] = captureShowContext();

    // 存在しない manual id → scopeBindings 失敗で 404。SecurityHeaders は SubstituteBindings より
    // 内側 (append) のため到達せず、ヘッダは付かない (fail-safe)。
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/999999999")
        ->assertNotFound()
        ->assertHeaderMissing('Permissions-Policy');
});

test('capture 用 config が空文字 (opt-out) なら撮影 route でも非送出になる', function (): void {
    [$organization, $owner, $project, $manual] = captureShowContext();

    config()->set('security.capture_permissions_policy', '');

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}")
        ->assertOk()
        ->assertHeaderMissing('Permissions-Policy');
});

test('allowlist の非文字列要素は無視される (型安全 fail-safe)', function (): void {
    [$organization, $owner, $project, $manual] = captureShowContext();

    config()->set('security.capture_permissions_policy_routes', ['capture.manuals.show', 123, null]);

    // 撮影 route は capture 値 (非文字列要素を落としても route は生き残る)
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}")
        ->assertOk()
        ->assertHeader(
            'Permissions-Policy',
            'geolocation=(), microphone=(self), camera=(self), payment=(self "https://js.stripe.com")',
        );

    // 非 recorder は baseline のまま
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals")
        ->assertOk()
        ->assertHeader(
            'Permissions-Policy',
            'geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")',
        );
});
