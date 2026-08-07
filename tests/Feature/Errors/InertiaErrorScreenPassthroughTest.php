<?php

declare(strict_types=1);

use App\Enums\Http\InertiaErrorScreenPassthrough;
use App\Exceptions\InertiaExceptionRenderer;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\Seo\SeoManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\Support\ResponseSignature;

/*
 * **素通し契約** (deny-by-default) の振る舞い固定。
 *
 * 差し替えてはいけない応答 (Inertia 手順上の 409 / Location 保持 / 3xx / api / expectsJson /
 * admin / stale version / 未登録 status / debug 5xx) を 1 件も巻き込まないことを確認し、
 * 併せて「素通し理由 enum の全 case が実際に生成される」= 死んだ分類を作らないことを固定する。
 */

/**
 * 素通し理由を直接評価する (HTTP テストと同じ条件を再現し、判定そのものを観測する)。
 *
 * @param  array<string, string>  $headers  リクエストヘッダ
 * @param  array<string, string>  $responseHeaders  原応答のヘッダ
 */
function passthroughReasonFor(
    int $status,
    string $uri,
    array $headers = [],
    array $responseHeaders = [],
): ?InertiaErrorScreenPassthrough {
    $request = Request::create($uri, 'GET');
    foreach ($headers as $name => $value) {
        $request->headers->set($name, $value);
    }
    $response = new SymfonyResponse('', $status, $responseHeaders);

    return InertiaExceptionRenderer::passthroughReason($response, $request);
}

/**
 * 現 build と一致する X-Inertia ヘッダ一式。
 *
 * @return array{'X-Inertia': string, 'X-Inertia-Version': string}
 */
function passthroughInertiaHeaders(): array
{
    config(['app.asset_url' => 'https://assets.test']);
    $version = app(HandleInertiaRequests::class)->version(request());

    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version];
}

test('X-Inertia なしのフルロードは Blade のまま', function (): void {
    $response = $this->get('/definitely-not-a-real-route-xyz');

    $response->assertNotFound();
    expect((string) $response->getContent())->toContain('<style>');

    expect(passthroughReasonFor(404, '/definitely-not-a-real-route-xyz'))
        ->toBe(InertiaErrorScreenPassthrough::NonInertiaRequest);
});

test('stale version の X-Inertia は差し替えない', function (): void {
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => 'stale-version'];
    config(['app.asset_url' => 'https://assets.test']);

    $response = $this->withHeaders($headers)->get('/definitely-not-a-real-route-xyz');

    $response->assertNotFound();
    expect((string) $response->getContent())->toContain('<style>');

    expect(passthroughReasonFor(404, '/definitely-not-a-real-route-xyz', $headers))
        ->toBe(InertiaErrorScreenPassthrough::StaleAssetVersion);
});

test('version ヘッダ欠落の X-Inertia は差し替えない', function (): void {
    config(['app.asset_url' => 'https://assets.test']);

    $response = $this->withHeaders(['X-Inertia' => 'true'])->get('/definitely-not-a-real-route-xyz');

    $response->assertNotFound();
    expect((string) $response->getContent())->toContain('<style>');

    expect(passthroughReasonFor(404, '/definitely-not-a-real-route-xyz', ['X-Inertia' => 'true']))
        ->toBe(InertiaErrorScreenPassthrough::StaleAssetVersion);
});

test('version ヘッダが空文字の X-Inertia は差し替えない', function (): void {
    config(['app.asset_url' => 'https://assets.test']);
    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => ''];

    $response = $this->withHeaders($headers)->get('/definitely-not-a-real-route-xyz');

    $response->assertNotFound();
    expect((string) $response->getContent())->toContain('<style>');
});

test('現 version が空 (version resolver が null) なら差し替えない', function (): void {
    // manifest / asset_url の有無に依存させず、version() が null を返す実装へ差し替えて固定する。
    $this->app->instance(HandleInertiaRequests::class, new class(app(SeoManager::class)) extends HandleInertiaRequests
    {
        public function version(Request $request): ?string
        {
            return null;
        }
    });

    $headers = ['X-Inertia' => 'true', 'X-Inertia-Version' => 'anything'];
    $response = $this->withHeaders($headers)->get('/definitely-not-a-real-route-xyz');

    $response->assertNotFound();
    expect((string) $response->getContent())->toContain('<style>');

    expect(passthroughReasonFor(404, '/definitely-not-a-real-route-xyz', $headers))
        ->toBe(InertiaErrorScreenPassthrough::StaleAssetVersion);
});

test('409 + X-Inertia-Location は差し替えない', function (): void {
    Route::middleware('web')->get(
        '/__passthrough/inertia-location',
        static fn () => Inertia::location('https://billing.example.test/checkout'),
    );

    $response = $this->withHeaders(passthroughInertiaHeaders())->get('/__passthrough/inertia-location');

    $response->assertStatus(409);
    $response->assertHeader('X-Inertia-Location', 'https://billing.example.test/checkout');

    expect(passthroughReasonFor(
        409,
        '/__passthrough/inertia-location',
        passthroughInertiaHeaders(),
        ['X-Inertia-Location' => 'https://billing.example.test/checkout'],
    ))->toBe(InertiaErrorScreenPassthrough::InertiaProtocolRedirect);
});

test('302 + Location は差し替えない', function (): void {
    Route::middleware('web')->get('/__passthrough/redirect', static fn () => redirect('/dashboard'));

    $response = $this->withHeaders(passthroughInertiaHeaders())->get('/__passthrough/redirect');

    $response->assertStatus(302);
    $response->assertHeader('Location', url('/dashboard'));

    expect(passthroughReasonFor(302, '/__passthrough/redirect', passthroughInertiaHeaders()))
        ->toBe(InertiaErrorScreenPassthrough::SuccessOrRedirectStatus);
});

test('4xx + Location ヘッダを持つ応答は差し替えない', function (): void {
    Route::middleware('web')->get(
        '/__passthrough/404-with-location',
        static fn () => abort(404, 'Not Found', ['Location' => '/somewhere']),
    );

    $response = $this->withHeaders(passthroughInertiaHeaders())->get('/__passthrough/404-with-location');

    $response->assertNotFound();
    expect((string) $response->getContent())->toContain('<style>');
});

test('422 (バリデーション) は差し替えない', function (): void {
    Route::middleware('web')->post('/__passthrough/validate', static function (Request $request): string {
        $request->validate(['name' => ['required']]);

        return 'ok';
    });

    $response = $this->withHeaders(passthroughInertiaHeaders())
        ->from('/__passthrough/form')
        ->post('/__passthrough/validate', []);

    // web (Inertia) のバリデーション失敗は 302 + errors が既定挙動
    $response->assertStatus(302);
    $response->assertSessionHasErrors('name');

    expect(passthroughReasonFor(422, '/__passthrough/validate', passthroughInertiaHeaders()))
        ->toBe(InertiaErrorScreenPassthrough::UnlistedStatus);
});

test('api/* は封筒 JSON のまま', function (): void {
    $response = $this->withHeaders(passthroughInertiaHeaders())->get('/api/v1/definitely-missing');

    $response->assertNotFound();
    $response->assertJsonPath('error.status', 404);

    expect(passthroughReasonFor(404, '/api/v1/definitely-missing', passthroughInertiaHeaders()))
        ->toBe(InertiaErrorScreenPassthrough::MachineReadableEnvelope);
});

test('X-Inertia + Accept: application/json は JSON のまま (expectsJson が優先)', function (): void {
    $headers = [...passthroughInertiaHeaders(), 'Accept' => 'application/json'];

    $response = $this->withHeaders($headers)->get('/definitely-not-a-real-route-xyz');

    $response->assertNotFound();
    expect((string) $response->getContent())->not->toContain('"component":"Error"');

    expect(passthroughReasonFor(404, '/definitely-not-a-real-route-xyz', $headers))
        ->toBe(InertiaErrorScreenPassthrough::MachineReadableEnvelope);
});

test('実 Inertia client のヘッダ (Accept: text/html) では差し替わる', function (): void {
    $headers = [...passthroughInertiaHeaders(), 'Accept' => 'text/html, application/xhtml+xml'];

    $response = $this->withHeaders($headers)->get('/definitely-not-a-real-route-xyz');

    $response->assertNotFound();
    $response->assertHeader('X-Inertia', 'true');

    // 正のコントロール: 素通し理由が付かない (= 差し替える)
    expect(passthroughReasonFor(404, '/definitely-not-a-real-route-xyz', $headers))->toBeNull();
});

test('admin 配下は運営者向け中立テンプレートのまま', function (): void {
    $response = $this->withHeaders(passthroughInertiaHeaders())->get('/admin/definitely-missing');

    $response->assertNotFound();
    expect((string) $response->getContent())->toContain('管理パネルに戻る');

    expect(passthroughReasonFor(404, '/admin/definitely-missing', passthroughInertiaHeaders()))
        ->toBe(InertiaErrorScreenPassthrough::OperatorFacingSurface);
});

test('5xx は app.debug=true のとき差し替えない', function (): void {
    config(['app.debug' => true]);
    Route::middleware('web')->get('/__passthrough/500-debug', static fn () => abort(503));

    $response = $this->withHeaders(passthroughInertiaHeaders())->get('/__passthrough/500-debug');

    $response->assertStatus(503);
    expect((string) $response->getContent())->not->toContain('"component":"Error"');

    expect(passthroughReasonFor(503, '/__passthrough/500-debug', passthroughInertiaHeaders()))
        ->toBe(InertiaErrorScreenPassthrough::DebugServerError);
});

test('version resolver が throw しても原応答が完全一致で残り、例外は report される', function (): void {
    Exceptions::fake();

    $headers = passthroughInertiaHeaders();
    $baseline = $this->get('/definitely-not-a-real-route-xyz'); // X-Inertia なし = 素通し (Blade)

    $this->app->instance(HandleInertiaRequests::class, new class(app(SeoManager::class)) extends HandleInertiaRequests
    {
        public function version(Request $request): ?string
        {
            throw new RuntimeException('manifest が読めない');
        }
    });

    $response = $this->withHeaders($headers)->get('/definitely-not-a-real-route-xyz');

    $response->assertNotFound();
    expect(ResponseSignature::of($response))->toBe(ResponseSignature::of($baseline));

    Exceptions::assertReported(function (RuntimeException $e): bool {
        return $e->getMessage() === 'manifest が読めない';
    });
});

test('未認証でも認証済みでも api 経路は封筒 JSON (レーン分離の確認)', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeaders(passthroughInertiaHeaders())
        ->get('/api/v1/definitely-missing');

    $response->assertNotFound();
    $response->assertJsonPath('error.status', 404);
});

test('素通し理由 enum の全 case が実際に生成される (死んだ分類を作らない)', function (): void {
    config(['app.debug' => false]);
    $stale = ['X-Inertia' => 'true', 'X-Inertia-Version' => 'stale-version'];
    $fresh = passthroughInertiaHeaders();

    $observed = [];
    foreach ([
        passthroughReasonFor(302, '/anything'),
        passthroughReasonFor(404, '/api/v1/x'),
        passthroughReasonFor(404, '/admin/x'),
        passthroughReasonFor(404, '/anything'),
        passthroughReasonFor(404, '/anything', $stale),
        passthroughReasonFor(404, '/anything', $fresh, ['Location' => '/elsewhere']),
        passthroughReasonFor(422, '/anything', $fresh),
    ] as $reason) {
        expect($reason)->not->toBeNull();
        /** @var InertiaErrorScreenPassthrough $reason */
        $observed[] = $reason->value;
    }

    config(['app.debug' => true]);
    $debugReason = passthroughReasonFor(500, '/anything', $fresh);
    expect($debugReason)->not->toBeNull();
    /** @var InertiaErrorScreenPassthrough $debugReason */
    $observed[] = $debugReason->value;

    sort($observed);
    $all = array_map(
        static fn (InertiaErrorScreenPassthrough $case): string => $case->value,
        InertiaErrorScreenPassthrough::cases(),
    );
    sort($all);

    expect($observed)->toBe($all);
});
