<?php

declare(strict_types=1);

use App\Enums\Http\InertiaErrorScreenStatus;
use App\Enums\ProjectRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
 * Inertia XHR (X-Inertia + version 一致) の 4xx/5xx が Error 画面へ差し替わることの
 * 振る舞い固定。素通し契約は InertiaErrorScreenPassthroughTest。
 *
 * テストデータは Factory (RefreshDatabase はグローバル適用)。
 */

/**
 * 現 build と一致する X-Inertia ヘッダ一式。
 * asset_url を固定して version を決定的にする (public/build/manifest.json の有無に依存させない)。
 *
 * @return array{'X-Inertia': string, 'X-Inertia-Version': string}
 */
function inertiaErrorScreenHeaders(): array
{
    config(['app.asset_url' => 'https://assets.test']);
    $version = app(HandleInertiaRequests::class)->version(request());

    return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) $version];
}

/**
 * Inertia page object (JSON) を配列で取り出す。
 *
 * @return array<string, mixed>
 */
function inertiaErrorScreenPage(TestResponse $response): array
{
    $decoded = json_decode((string) $response->getContent(), true);
    expect($decoded)->toBeArray();
    /** @var array<string, mixed> $decoded */

    return $decoded;
}

/**
 * Error ページの props。
 *
 * @return array<string, mixed>
 */
function inertiaErrorScreenProps(TestResponse $response): array
{
    $page = inertiaErrorScreenPage($response);
    expect($page['component'])->toBe('Error');
    /** @var array<string, mixed> $props */
    $props = $page['props'];

    return $props;
}

test('403 が Inertia の Error ページになる', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $shooter = attachOrganizationMember($organization);
    attachProjectMember($project, $shooter, ProjectRole::Member);

    $response = $this->actingAs($shooter)
        ->withHeaders(inertiaErrorScreenHeaders())
        ->get("/organizations/{$organization->slug}/projects/{$project->id}/categories");

    $response->assertForbidden();
    $props = inertiaErrorScreenProps($response);
    expect($props['status'])->toBe(403);
    expect($props['title'])->toBe(InertiaErrorScreenStatus::Forbidden->title());
});

test('404 が Inertia の Error ページになる', function (): void {
    $response = $this->withHeaders(inertiaErrorScreenHeaders())
        ->get('/definitely-not-a-real-route-xyz');

    $response->assertNotFound();
    expect(inertiaErrorScreenProps($response)['status'])->toBe(404);
});

test('419 が Inertia の Error ページになりログイン導線を返す', function (): void {
    Route::middleware('web')->get('/__error-screen/419', static fn () => abort(419));

    $response = $this->withHeaders(inertiaErrorScreenHeaders())->get('/__error-screen/419');

    $response->assertStatus(419);
    $props = inertiaErrorScreenProps($response);
    expect($props['status'])->toBe(419);
    /** @var list<array{label: string, href: string}> $destinations */
    $destinations = $props['destinations'];
    expect($destinations[0]['href'])->toBe(route('login', absolute: false));
});

test('認証済みでも 419 はログイン導線になる (D1)', function (): void {
    Route::middleware('web')->get('/__error-screen/419-auth', static fn () => abort(419));
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeaders(inertiaErrorScreenHeaders())
        ->get('/__error-screen/419-auth');

    $response->assertStatus(419);
    /** @var list<array{label: string, href: string}> $destinations */
    $destinations = inertiaErrorScreenProps($response)['destinations'];
    expect($destinations[0]['href'])->toBe(route('login', absolute: false));
});

test('419 は user resolver が例外を投げても Error 画面になる (認証状態を評価しない)', function (): void {
    Exceptions::fake();

    // user() が必ず throw する guard を web guard に差し替える (セッション不整合の再現)。
    Auth::extend('error-screen-throwing', static fn (): Guard => new class implements Guard
    {
        public function check(): bool
        {
            return false;
        }

        public function guest(): bool
        {
            return true;
        }

        public function user(): ?Authenticatable
        {
            throw new RuntimeException('user resolver が壊れている');
        }

        public function id(): null
        {
            throw new RuntimeException('user resolver が壊れている');
        }

        /** @param  array<string, mixed>  $credentials */
        public function validate(array $credentials = []): bool
        {
            return false;
        }

        public function hasUser(): bool
        {
            return false;
        }

        public function setUser(Authenticatable $user): void {}
    });
    config(['auth.guards.web.driver' => 'error-screen-throwing']);

    // HandleInertiaRequests::share() も $request->user() を読むため、
    // 「middleware より前で例外が出る経路 (テナント guard 404 等)」を模して web group を付けない。
    Route::get('/__error-screen/419-broken-guard', static fn () => abort(419));

    $response = $this->withHeaders(inertiaErrorScreenHeaders())->get('/__error-screen/419-broken-guard');

    $response->assertStatus(419);
    /** @var list<array{label: string, href: string}> $destinations */
    $destinations = inertiaErrorScreenProps($response)['destinations'];
    expect($destinations[0]['href'])->toBe(route('login', absolute: false));

    // fail-safe (catch → report → 原応答) に落ちていないこと = user resolver を呼んでいない証拠
    Exceptions::assertNothingReported();
});

test('429 は retryAfterSeconds を props に載せ Retry-After ヘッダも保持する', function (): void {
    Route::middleware('web')->get(
        '/__error-screen/429',
        static fn () => abort(429, 'Too Many Requests', ['Retry-After' => '30']),
    );

    $response = $this->withHeaders(inertiaErrorScreenHeaders())->get('/__error-screen/429');

    $response->assertStatus(429);
    expect(inertiaErrorScreenProps($response)['retryAfterSeconds'])->toBe(30);
    $response->assertHeader('Retry-After', '30');
});

test('429 の Retry-After が解釈不能なら retryAfterSeconds は null でヘッダも載らない', function (): void {
    Route::middleware('web')->get(
        '/__error-screen/429-http-date',
        static fn () => abort(429, 'Too Many Requests', ['Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT']),
    );

    $response = $this->withHeaders(inertiaErrorScreenHeaders())->get('/__error-screen/429-http-date');

    $response->assertStatus(429);
    expect(inertiaErrorScreenProps($response)['retryAfterSeconds'])->toBeNull();
    expect($response->headers->has('Retry-After'))->toBeFalse();
});

test('500 は app.debug=false のとき Error ページになる', function (): void {
    config(['app.debug' => false]);
    Route::middleware('web')->get(
        '/__error-screen/500',
        static fn () => throw new RuntimeException('boom'),
    );

    $response = $this->withHeaders(inertiaErrorScreenHeaders())->get('/__error-screen/500');

    $response->assertStatus(500);
    expect(inertiaErrorScreenProps($response)['status'])->toBe(500);
});

test('503 は app.debug=false のとき Error ページになる', function (): void {
    config(['app.debug' => false]);
    Route::middleware('web')->get('/__error-screen/503', static fn () => abort(503));

    $response = $this->withHeaders(inertiaErrorScreenHeaders())->get('/__error-screen/503');

    $response->assertStatus(503);
    expect(inertiaErrorScreenProps($response)['status'])->toBe(503);
});

test('Error 応答は x-inertia ヘッダを持つ (クライアントがモーダルに落とさない条件)', function (): void {
    $response = $this->withHeaders(inertiaErrorScreenHeaders())
        ->get('/definitely-not-a-real-route-xyz');

    $response->assertNotFound();
    $response->assertHeader('X-Inertia', 'true');
});

test('Error 応答のキャッシュ表現契約 (no-store + private + Vary) を満たす', function (): void {
    $response = $this->withHeaders(inertiaErrorScreenHeaders())
        ->get('/definitely-not-a-real-route-xyz');

    $response->assertNotFound();

    $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->toContain('private');
    expect($cacheControl)->not->toContain('public');

    $vary = array_map(strtolower(...), $response->headers->all('Vary'));
    $varyText = implode(',', $vary);
    expect($varyText)->toContain('x-inertia');
    expect($varyText)->toContain('x-inertia-version');
    expect($varyText)->toContain('accept');
});

test('認証済みでも同じキャッシュ表現契約を満たす', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->withHeaders(inertiaErrorScreenHeaders())
        ->get('/definitely-not-a-real-route-xyz');

    $response->assertNotFound();

    $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
    expect($cacheControl)->toContain('no-store');
    expect($cacheControl)->toContain('private');
    expect($cacheControl)->not->toContain('public');
});

test('戻り先が全 status で 1 件以上ある', function (InertiaErrorScreenStatus $status): void {
    config(['app.debug' => false]);
    Route::middleware('web')->get(
        "/__error-screen/destinations/{$status->value}",
        static fn () => abort($status->value),
    );

    $response = $this->withHeaders(inertiaErrorScreenHeaders())
        ->get("/__error-screen/destinations/{$status->value}");

    $response->assertStatus($status->value);
    /** @var list<array{label: string, href: string}> $destinations */
    $destinations = inertiaErrorScreenProps($response)['destinations'];
    expect(count($destinations))->toBeGreaterThanOrEqual(1);
})->with(InertiaErrorScreenStatus::cases());

test('cross-org 実在と不在で Error 応答が分岐しない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$otherOrg] = createOrganizationWithOwner('他組織');
    $foreign = Project::factory()->forOrganization($otherOrg)->create();

    $headers = inertiaErrorScreenHeaders();

    $existing = $this->actingAs($owner)->withHeaders($headers)->get("/organizations/{$organization->slug}/projects/{$foreign->id}");
    $missing = $this->actingAs($owner)->withHeaders($headers)->get("/organizations/{$organization->slug}/projects/999999999");

    $existing->assertNotFound();
    $missing->assertNotFound();

    // props (戻り先・文言・status) が完全一致 = 存在オラクル不成立。
    // url は要求 path そのものなので比較から外す (観測者が元から知っている情報)。
    expect(inertiaErrorScreenProps($existing))->toBe(inertiaErrorScreenProps($missing));
    expect($existing->getStatusCode())->toBe($missing->getStatusCode());
});
