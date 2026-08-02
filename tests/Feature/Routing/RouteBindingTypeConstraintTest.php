<?php

declare(strict_types=1);

use App\Models\Cut;
use App\Models\OauthSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\VideoManual;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| route binding 型制約の実挙動 (非適合セグメント → 404 / 500 でない)
|--------------------------------------------------------------------------
|
| pgsql は型不一致の比較で 22P02 (invalid_text_representation)、bigint 範囲外で
| 22003 (numeric_value_out_of_range) を投げるため、非適合セグメントが implicit
| binding へ届くと QueryException → **404 ではなく生 500** になる。
| RouteBindingTypes の型制約 (Route::pattern) がその手前 = route マッチ段階で
| 弾いていることを実挙動で固定する。
|
| 各ケースは「非適合だけが 404 になる」ことを示すため、**適合値の対比ケース**を
| 併記する (認証 / CSRF / 認可に吸われた 404 と区別するため)。
*/

/** 19 桁 = PHP_INT_MAX + 1 (bigint 範囲外)。 */
const BIGINT_OVERFLOW = '9223372036854775808';

/** 30 桁の極長数値 (regex が [0-9]+ だと通過して 22003 → 500 になる)。 */
const BIGINT_TOO_LONG = '123456789012345678901234567890';

/** 18 桁上限値 (制約が過剰に狭くないことの確認用)。 */
const BIGINT_MAX_18 = '999999999999999999';

test('テスト環境の DB driver は pgsql である (22P02 / 22003 を再現できる方言)', function (): void {
    // 本件は pgsql 固有の事故。SQLite 等へ切り替わると非適合値でも例外にならず
    // テストが空振りで green になるため、方言そのものを固定する。
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

/*
| ケース 1〜5: bigint param ({project})
*/

test('ケース 1: bigint param に非数値 → 404 (500 でない)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/projects/abc')->assertNotFound();
});

test("ケース 1': 対比 — 実在 ID は 200 (認可 / 課金ゲートに吸われていない)", function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get("/projects/{$project->id}")->assertOk();
});

test('ケース 2: bigint param に 19 桁 (PHP_INT_MAX+1) → 404 (22003 由来の 500 でない)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/projects/'.BIGINT_OVERFLOW)->assertNotFound();
});

test('ケース 3: bigint param に 30 桁 → 404', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/projects/'.BIGINT_TOO_LONG)->assertNotFound();
});

test('ケース 4: bigint param に 18 桁上限値 → 404 (route にはマッチする = 制約が過剰に狭くない)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/projects/'.BIGINT_MAX_18)->assertNotFound();
});

test('ケース 5: bigint param に先頭ゼロ → 404 (pgsql は 007 を正常解釈するため 500 にならない)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/projects/007')->assertNotFound();
});

/*
| ケース 6: uuid param ({oauthSession})
*/

test('ケース 6: uuid param に非適合値 → 404 (500 でない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('セッション組織');

    $this->actingAs($owner)->withSession(freshRecentAuthSession())
        ->delete("/organizations/{$organization->slug}/api-keys/sessions/abc")
        ->assertNotFound();
});

test("ケース 6': 対比 — 実在 UUID は 302 (認可 / recent-auth に吸われていない)", function (): void {
    [$organization, $owner] = createOrganizationWithOwner('セッション組織');
    /** @var OauthSession $session */
    $session = OauthSession::factory()->cli()->create([
        'user_id' => $owner->id,
        'organization_id' => $organization->id,
    ]);

    $this->actingAs($owner)->withSession(freshRecentAuthSession())
        ->delete("/organizations/{$organization->slug}/api-keys/sessions/{$session->id}")
        ->assertStatus(302);
});

/*
| ケース 7: 全 bigint param の代表 route
*/

test('ケース 7: 全 bigint param の代表 route が非数値セグメントを 404 にする', function (
    string $method,
    string $urlTemplate,
): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    /** @var VideoManual $manual */
    $manual = VideoManual::factory()->forProject($project)->create();
    /** @var Cut $cut */
    $cut = Cut::factory()->forManual($manual)->create();

    $url = strtr($urlTemplate, [
        '{project}' => (string) $project->id,
        '{manual}' => (string) $manual->id,
        '{cut}' => (string) $cut->id,
        '{organizationSlug}' => $organization->slug,
    ]);

    $this->actingAs($owner)->withSession(freshRecentAuthSession())
        ->call($method, $url)
        ->assertNotFound();
})->with([
    'analysisJob' => ['GET', '/projects/{project}/manuals/{manual}/jobs/abc'],
    'apiKey' => ['DELETE', '/organizations/{organizationSlug}/api-keys/abc'],
    'category' => ['PATCH', '/projects/{project}/categories/abc'],
    'cut' => ['POST', '/app/projects/{project}/manuals/{manual}/cuts/abc/takes'],
    'invitation' => ['DELETE', '/organizations/{organizationSlug}/invitations/abc'],
    'item' => ['PATCH', '/projects/{project}/items/abc'],
    'manual' => ['GET', '/projects/{project}/manuals/abc'],
    'project' => ['GET', '/projects/abc'],
    'renderJob' => ['GET', '/projects/{project}/manuals/{manual}/render-jobs/abc'],
    'take' => ['PATCH', '/app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/abc'],
    'user' => ['PATCH', '/organizations/{organizationSlug}/members/abc'],
]);

/*
| ケース 8・13: {organization:slug} の回帰確認
*/

test('ケース 8/13: {organization:slug} は実在 slug で 200 (数値制約を掛けていない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/settings")
        ->assertOk();
});

/*
| ケース 9〜12: custom binder ({organization}) の入力正規化 = 実効性の正本。
| marker interface は分類の宣言に過ぎず何も保証しないため、ここで固定する。
*/

test('ケース 9: {organization} の id binding に非数値 → 404', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->post('/organizations/abc/switch')->assertNotFound();
});

test('ケース 10: {organization} の id binding に 19 桁 → 404', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->post('/organizations/'.BIGINT_OVERFLOW.'/switch')->assertNotFound();
});

test('ケース 11: {organization} の id binding に 30 桁 → 404', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->post('/organizations/'.BIGINT_TOO_LONG.'/switch')->assertNotFound();
});

test('ケース 12: {organization:未許可 field} は 404 (500 でない)', function (): void {
    // 本番 inventory (IV-1 の走査対象) を汚さないため routes/ には置かず、
    // binder の未許可 field フォールバックを検証する route をテスト内で登録する。
    Route::middleware('web')->get(
        '/__test__/organizations/{organization:uuid}',
        static fn (Organization $organization): string => (string) $organization->id,
    );

    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/__test__/organizations/anything')->assertNotFound();
});
