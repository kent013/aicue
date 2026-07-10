<?php

declare(strict_types=1);

use App\Auth\Guards\ApiKeyGuard;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\User;
use Database\Factories\ApiKeyFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
 * auth:api-key guard の契約:
 * - Bearer "{slug}_{prefix8}_{secret40}" を prefix lookup + Argon2id 検証で認証する
 * - 認証成功時に request attributes へ api_key / organization を注入する
 * - 無効 (形式不正 / 期限切れ / 不一致) は 401 unauthenticated envelope
 * - last_used_at は 5 分間引きで更新する
 */

beforeEach(function (): void {
    // guard が配線した api_key / organization コンテキストを検証するための probe route。
    // 統一エラー envelope (ApiExceptionRenderer) の対象になるよう api/* 配下に置く。
    Route::middleware('auth:api-key')->get('/api/__test__/api-key-probe', function (Request $request): array {
        /** @var ApiKey|null $apiKey */
        $apiKey = $request->attributes->get('api_key');
        /** @var Organization|null $organization */
        $organization = $request->attributes->get('organization');

        return [
            'api_key_id' => $apiKey?->id,
            'organization_id' => $organization?->id,
        ];
    });
});

/**
 * @return array{ApiKey, string, Organization}
 */
function createApiKeyForGuardTest(?Carbon $expiresAt = null, ?Carbon $lastUsedAt = null): array
{
    /** @var Organization $org */
    $org = Organization::factory()->create();
    /** @var User $user */
    $user = User::factory()->create(['current_organization_id' => $org->id]);

    [$factory, $plainToken] = ApiKeyFactory::withPlainToken();
    /** @var ApiKey $apiKey */
    $apiKey = $factory->create([
        'organization_id' => $org->id,
        'created_by_user_id' => $user->id,
        'expires_at' => $expiresAt,
        'last_used_at' => $lastUsedAt,
    ]);

    return [$apiKey, $plainToken, $org];
}

test('有効な API キーで api_key / organization コンテキストが解決される', function (): void {
    [$apiKey, $plainToken, $org] = createApiKeyForGuardTest(
        expiresAt: Carbon::now()->addDays(30),
    );

    $this->withHeader('Authorization', "Bearer {$plainToken}")
        ->getJson('/api/__test__/api-key-probe')
        ->assertOk()
        ->assertJson([
            'api_key_id' => $apiKey->id,
            'organization_id' => $org->id,
        ]);
});

test('Authorization ヘッダなしは 401 (unauthenticated envelope)', function (): void {
    $this->getJson('/api/__test__/api-key-probe')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertJsonPath('error.status', 401);
});

test('Bearer 以外の Authorization ヘッダは 401', function (): void {
    $this->withHeader('Authorization', 'Basic dXNlcjpwYXNz')
        ->getJson('/api/__test__/api-key-probe')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('形式不正トークンは 401', function (): void {
    $slug = config('template.slug');

    $this->withHeader('Authorization', "Bearer {$slug}_invalidtokenvalue")
        ->getJson('/api/__test__/api-key-probe')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('正しい形式でも secret 不一致は 401', function (): void {
    createApiKeyForGuardTest(expiresAt: Carbon::now()->addDays(30));

    // 実在しない prefix + secret (形式は正しい)
    $fake = ApiKey::generatePlainKey();

    $this->withHeader('Authorization', "Bearer {$fake['plain']}")
        ->getJson('/api/__test__/api-key-probe')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('期限切れの API キーは 401', function (): void {
    [, $plainToken] = createApiKeyForGuardTest(
        expiresAt: Carbon::now()->subDay(),
    );

    $this->withHeader('Authorization', "Bearer {$plainToken}")
        ->getJson('/api/__test__/api-key-probe')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated')
        ->assertJsonPath('error.status', 401);
});

test('失効済みの API キーは 401', function (): void {
    [$apiKey, $plainToken] = createApiKeyForGuardTest();
    $apiKey->forceFill(['revoked_at' => now()])->save();

    $this->withHeader('Authorization', "Bearer {$plainToken}")
        ->getJson('/api/__test__/api-key-probe')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

test('last_used_at は 5 分以内の再アクセスでは書き換えない (間引き)', function (): void {
    $lastUsedAt = Carbon::now()->subMinutes(2);
    [$apiKey, $plainToken] = createApiKeyForGuardTest(
        expiresAt: Carbon::now()->addDays(30),
        lastUsedAt: $lastUsedAt,
    );

    $this->withHeader('Authorization', "Bearer {$plainToken}")
        ->getJson('/api/__test__/api-key-probe')
        ->assertOk();

    $fresh = $apiKey->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->last_used_at?->timestamp)->toBe($lastUsedAt->timestamp);
});

test('last_used_at は 5 分経過後のアクセスで書き換わる', function (): void {
    $lastUsedAt = Carbon::now()->subMinutes(10);
    [$apiKey, $plainToken] = createApiKeyForGuardTest(
        expiresAt: Carbon::now()->addDays(30),
        lastUsedAt: $lastUsedAt,
    );

    $this->withHeader('Authorization', "Bearer {$plainToken}")
        ->getJson('/api/__test__/api-key-probe')
        ->assertOk();

    $fresh = $apiKey->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->last_used_at)->not->toBeNull();
    expect($fresh->last_used_at?->timestamp)->toBeGreaterThan($lastUsedAt->timestamp);
});

test('last_used_at 境界: ちょうど 5 分で更新され、4 分 59 秒では更新されない', function (): void {
    Carbon::setTestNow('2026-07-01 12:00:00');

    try {
        // 5 分ちょうどで更新される (>= 5 の境界)
        $fiveMinAgo = Carbon::now()->subMinutes(5);
        [$apiKey5, $plainToken5] = createApiKeyForGuardTest(
            expiresAt: Carbon::now()->addDays(30),
            lastUsedAt: $fiveMinAgo,
        );

        $this->withHeader('Authorization', "Bearer {$plainToken5}")
            ->getJson('/api/__test__/api-key-probe')
            ->assertOk();

        $fresh5 = $apiKey5->fresh();
        expect($fresh5)->not->toBeNull();
        expect($fresh5->last_used_at?->timestamp)->toBe(Carbon::now()->timestamp);

        // 4 分 59 秒では更新されない
        $justUnder = Carbon::now()->subSeconds(4 * 60 + 59);
        [$apiKeyJust, $plainTokenJust] = createApiKeyForGuardTest(
            expiresAt: Carbon::now()->addDays(30),
            lastUsedAt: $justUnder,
        );

        $this->withHeader('Authorization', "Bearer {$plainTokenJust}")
            ->getJson('/api/__test__/api-key-probe')
            ->assertOk();

        $freshJust = $apiKeyJust->fresh();
        expect($freshJust)->not->toBeNull();
        expect($freshJust->last_used_at?->timestamp)->toBe($justUnder->timestamp);
    } finally {
        Carbon::setTestNow();
    }
});

test('last_used_at が null なら初回アクセスで記録される', function (): void {
    [$apiKey, $plainToken] = createApiKeyForGuardTest(
        expiresAt: Carbon::now()->addDays(30),
        lastUsedAt: null,
    );

    $this->withHeader('Authorization', "Bearer {$plainToken}")
        ->getJson('/api/__test__/api-key-probe')
        ->assertOk();

    $fresh = $apiKey->fresh();
    expect($fresh)->not->toBeNull();
    expect($fresh->last_used_at)->not->toBeNull();
});

test('DUMMY_HASH は妥当な Argon2id ハッシュ (timing 緩和 verify が実際に走る形式)', function (): void {
    $info = password_get_info(ApiKeyGuard::DUMMY_HASH);

    expect($info['algo'])->toBe(PASSWORD_ARGON2ID);
});
