<?php

declare(strict_types=1);

use App\Models\Cut;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Capture\TakeObjectStorage;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/*
 * 既存の no-store 4 経路の Cache-Control 完全値ピン (施策 5)。
 *
 * no-store の存在チェックだけでは `public, no-store` のような矛盾値を検出できないため、
 * 各経路について (a) ヘッダの完全一致 と (b) directive 集合 (順序非依存) の 2 段で固定する。
 * 2 つの assert を分離しているのは、失敗時に「順序だけ変わった」のか「意味が後退した」のかを
 * 判別できるようにするため。
 *
 * これらは P3-a baseline (NoStoreCacheHeadersForAuthenticatedPages) の untouched 契約の
 * 証明でもある: baseline 適用後もこれらの値は 1 文字も変わらない。
 *
 * 完全一致ピンは「意図的な強化」も落とす。落ちたら期待値を更新する運用でよい
 * (落ちること自体が「契約が変わった」というシグナル)。
 */

/** @return list<string> Cache-Control の directive 集合 (順序非依存に正規化) */
function existingNoStoreDirectiveSet(string $value): array
{
    if (trim($value) === '') {
        return [];
    }

    $directives = array_map(
        static fn (string $part): string => trim($part),
        explode(',', $value),
    );
    sort($directives);

    return array_values($directives);
}

/**
 * Cache-Control を 2 段 (完全一致 / directive 集合) でピンする。
 * assert は分離し、それぞれ固有メッセージを付ける。
 */
function pinCacheControl(TestResponse $response, string $expected, string $label): void
{
    $actual = (string) $response->headers->get('Cache-Control');

    expect($actual)->toBe(
        $expected,
        "[{$label}] Cache-Control の完全値が変わった (順序変更 or 値の変化)。期待: {$expected} / 実際: {$actual}",
    );

    expect(existingNoStoreDirectiveSet($actual))->toBe(
        existingNoStoreDirectiveSet($expected),
        "[{$label}] Cache-Control の directive 集合が変わった (順序ではなく意味の変化)。期待: {$expected} / 実際: {$actual}",
    );
}

/* ------------------------------------------------------- 1. Fortify 登録応答 (招待 email あり) */

test('Fortify 登録応答 (招待 email あり) の Cache-Control をピンする', function (): void {
    [$organization] = createOrganizationWithOwner();
    /** @var OrganizationInvitation $invitation */
    [, $token] = OrganizationInvitation::factory()
        ->forOrganization($organization)
        ->createWithPlainToken(['email' => 'pinned-invitee@example.com']);

    $response = $this->withSession(['invitation_token' => $token])->get('/register');

    $response->assertOk();
    pinCacheControl($response, 'no-store, private', 'FortifyServiceProvider registerView (PII 含む)');
});

/* ------------------------------------------------------- 2. RequireRecentAuth の 409 */

test('RequireRecentAuth の 409 の Cache-Control をピンする', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->deleteJson('/settings/account');

    $response->assertStatus(409);
    pinCacheControl($response, 'no-store, private', 'RequireRecentAuth 409');
});

/* ------------------------------------------ 3. RequireTwoFactorForEnforcedOrganizations の 409 */

test('RequireTwoFactorForEnforcedOrganizations の 409 の Cache-Control をピンする', function (): void {
    [$organization] = createOrganizationWithOwner();
    $organization->forceFill(['two_factor_required' => true])->save();
    // 2FA 未準拠 (disabled) メンバー = ゲート対象
    $member = attachOrganizationMember($organization);

    $response = $this->actingAs($member)->getJson("/organizations/{$organization->slug}/dashboard");

    $response->assertStatus(409);
    pinCacheControl($response, 'no-store, private', 'RequireTwoFactorForEnforcedOrganizations 409');
});

/* --------------------------------------------------- 4. Capture\CaptureTakeController の 302 */

test('CaptureTakeController playback の 302 の Cache-Control をピンする', function (): void {
    // 署名 URL 生成は実 S3 に触れないよう fake storage で解決する
    enableFakeStorage();

    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create(['status' => 'ready']);

    $response = $this->actingAs($owner)->get(
        "/organizations/{$organization->slug}/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/{$take->id}/playback",
    );

    $response->assertStatus(302);
    pinCacheControl($response, 'no-store, private', 'CaptureTakeController playback 302');
});

/* --------------------------- 5. GetFakeStorageObjectController の Range 応答 (baseline 影響確認) */

test('fake storage の Range 応答は認証済みでも壊れない (206 + partial bytes)', function (): void {
    enableFakeStorage();
    $user = User::factory()->create();

    $key = 'projects/1/manuals/2/cuts/3/takes/01ABCDEF.mp4';
    $body = 'range-test-bytes-0123456789';
    $checksum = base64_encode(hash('sha256', $body, true));

    $storage = app(TakeObjectStorage::class);
    $putUrl = $storage->presignUpload(
        $key,
        'video/mp4',
        100,
        $checksum,
        CarbonImmutable::now()->addMinutes(30),
    )->url;

    $this->call('PUT', $putUrl, [], [], [], [
        'CONTENT_TYPE' => 'video/mp4',
        'HTTP_X_AMZ_CHECKSUM_SHA256' => $checksum,
    ], $body)->assertNoContent();

    $getUrl = $storage->temporaryPlaybackUrl($key);

    $full = $this->actingAs($user)->get($getUrl);
    $full->assertOk();
    expect($full->streamedContent())->toBe($body);

    $partial = $this->actingAs($user)->call('GET', $getUrl, [], [], [], ['HTTP_RANGE' => 'bytes=0-3']);
    $partial->assertStatus(206);
    expect($partial->streamedContent())->toBe(substr($body, 0, 4));
    $partial->assertHeader('Content-Range', 'bytes 0-3/'.strlen($body));
});
