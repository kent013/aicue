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

/**
 * CSP ヘッダから 1 directive の source token 列を取り出す。
 *
 * 区切りの宣言:
 *   directive の区切り … `;`
 *   token の区切り     … ASCII 空白 (半角空白 / タブ)
 *
 * 部分文字列一致に頼らない。`/img-src[^;]*\bdata:/` のような正規表現は
 * `https://data:443` のような**別の source の部分列**にも一致するため、
 * `img-src` が `data:` scheme-source を失っても緑になってしまう。
 *
 * @return list<string> source token 列 (directive 名は含まない)。directive が無ければ空配列
 */
function cspDirectiveSources(string $csp, string $directive): array
{
    foreach (explode(';', $csp) as $segment) {
        $tokens = preg_split('/[ \t]+/', trim($segment), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false || $tokens === []) {
            continue;
        }

        if ($tokens[0] === $directive) {
            return array_values(array_slice($tokens, 1));
        }
    }

    return [];
}

/*
 * QrCodeImage (components/atoms/QrCodeImage.svelte) は
 * サーバ生成の SVG を data URI の <img> として描く。
 * これは raw HTML 挿入構文 ({@html}) を使わずに QR を表示するための唯一の手段であり、
 * **img-src が data: を失うと 2 要素認証の設定画面が壊れる**。
 * よって既定構成と GTM 有効構成の **両方**で data: の存在を固定する。
 * (CSP を配る仕組み自体の検査ではない。依存している 1 点だけを pin する)
 */
test('CSP の img-src は data: を許す (QrCodeImage の前提。既定 / GTM 有効の 2 構成)', function (): void {
    // 既定構成
    $sources = cspDirectiveSources(
        (string) $this->get('/')->headers->get('Content-Security-Policy'),
        'img-src',
    );

    // 母集団が空 = directive ごと消えた場合も落とす (fail-closed)
    expect($sources)->not->toBe([])
        ->and($sources)->toContain('data:');

    // GTM 有効構成 (production + container id の二重ゲート)
    config([
        'app.env' => 'production',
        'services.google_tag_manager.container_id' => 'GTM-TEST',
    ]);
    $gtmSources = cspDirectiveSources(
        (string) $this->get('/')->headers->get('Content-Security-Policy'),
        'img-src',
    );

    expect($gtmSources)->not->toBe([])
        ->and($gtmSources)->toContain('data:');
});

/*
 * helper の検出力を合成入力で裏取りする。
 * 「img-src 'self' に data: が無い」だけを見る負のコントロールでは
 * **素朴な部分文字列実装でも同じく落ちる**ので、防ぎたい誤検出を区別できない。
 */
test('cspDirectiveSources() は directive を選び分け、別 source の部分列を拾わない', function (): void {
    expect(cspDirectiveSources("img-src 'self' data:", 'img-src'))
        ->toBe(["'self'", 'data:']);

    // 部分列を拾わない裏取り (素朴な部分文字列一致ならここで data: を「在る」と誤答する)
    expect(cspDirectiveSources("img-src 'self' https://data:443", 'img-src'))
        ->toBe(["'self'", 'https://data:443'])
        ->not->toContain('data:');

    // 正しい directive を選ぶ
    expect(cspDirectiveSources("script-src 'self'; img-src 'self' data:", 'img-src'))
        ->toBe(["'self'", 'data:']);

    // 区切りの宣言どおりタブでも token 化できる
    expect(cspDirectiveSources("img-src\t'self'\tdata:", 'img-src'))
        ->toBe(["'self'", 'data:']);

    // 存在しない directive は空配列 (呼び出し側の fail-closed 判定に使う)
    expect(cspDirectiveSources("img-src 'self' data:", 'font-src'))->toBe([]);
});
