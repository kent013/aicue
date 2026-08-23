<?php

declare(strict_types=1);

use App\Models\Project;
use Illuminate\Testing\TestResponse;

/*
 * named rate limiter のキーが **route parameter を含まない** ことの behavioral proof
 * (T108 S4 検査 4(d))。
 *
 * ThrottleRequests は SubstituteBindings より前 (pre-binding) に走る短絡であり、
 * 「全 id で同一の応答になる」ことが存在オラクル不成立の根拠になっている。
 * しかし ThrottleRequests 自身が route parameter を読まなくても、
 * `throttle:{bucket}` の limiter closure が `$request->route(...)` を読めば
 * bucket が id ごとに分かれ、「429 になるまでの回数」が id の実在を漏らす。
 * 静的検査 (TenantBoundaryOrderingTest 検査 4(a)) は closure まで届かないため、
 * ここで実挙動として固定する。
 *
 * 検証方法: **同じ actor で route parameter だけを変えた 2 連続リクエスト**の
 * `X-RateLimit-Remaining` が連続して減ること。limiter キーに route parameter が
 * 混ざっていれば 2 回目の remaining は 1 回目と同じ値に戻る (= bucket が分かれた証拠)。
 * bucket を実際に使い切る方式より少ないリクエストで同じ性質を証明できる。
 */

/** 応答の X-RateLimit-Remaining を int で取り出す。 */
function limiterRemaining(TestResponse $response): int
{
    $remaining = $response->headers->get('X-RateLimit-Remaining');
    expect($remaining)->not->toBeNull('X-RateLimit-Remaining が無い (throttle が付いていない?)');

    return (int) $remaining;
}

test('api-read bucket は route parameter ごとに分かれない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner, ['read']);
    $headers = ['Authorization' => "Bearer {$plain}"];

    $first = $this->withHeaders($headers)->getJson("/api/v1/projects/{$projectA->id}/items");
    $second = $this->withHeaders($headers)->getJson("/api/v1/projects/{$projectB->id}/items");

    expect(limiterRemaining($second))->toBe(
        limiterRemaining($first) - 1,
        'route parameter を変えたら残数が戻った = limiter キーが route parameter を含んでいる',
    );
});

test('api-write bucket は route parameter ごとに分かれない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner, ['write']);
    $headers = ['Authorization' => "Bearer {$plain}"];

    $first = $this->withHeaders($headers)->postJson("/api/v1/projects/{$projectA->id}/items", ['name' => 'A']);
    $second = $this->withHeaders($headers)->postJson("/api/v1/projects/{$projectB->id}/items", ['name' => 'B']);

    expect(limiterRemaining($second))->toBe(limiterRemaining($first) - 1);
});

test('api-read bucket は不在 project id のリクエストでも同じ bucket を消費する', function (): void {
    // 不在 id が別 bucket に落ちると「429 になるまでの回数」で実在が漏れる
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    [, $plain] = issueApiKey($organization, $owner, ['read']);
    $headers = ['Authorization' => "Bearer {$plain}"];

    $existing = $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/items");
    $missing = $this->withHeaders($headers)->getJson('/api/v1/projects/999999999/items');

    expect($missing->getStatusCode())->toBe(404);
    expect(limiterRemaining($missing))->toBe(limiterRemaining($existing) - 1);
});

test('render-trigger bucket は route parameter ごとに分かれない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();

    // 応答が 4xx でも throttle は最外周で消費される (limiter キーの検証が目的)
    $first = $this->actingAs($owner)
        ->postJson("/organizations/{$organization->slug}/projects/{$projectA->id}/manuals/999999999/render");
    $second = $this->actingAs($owner)
        ->postJson("/organizations/{$organization->slug}/projects/{$projectB->id}/manuals/999999999/render");

    expect(limiterRemaining($second))->toBe(limiterRemaining($first) - 1);
});
