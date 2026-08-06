<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Tests\Support\ResponseSignature;

/*
 * payload 由来 id (POST body の user_id) の存在オラクル不成立 (aicue:T118)。
 *
 * route parameter 版 (`MemberRouteExistenceOracleTest`) と同じ主張を payload 経路で行う:
 *   「実在するが非メンバーの id」と「不在の id」の応答が観測上まったく同じであること。
 * URL 子リソースではないので 404 ではなく **validation failure (redirect back + field error)** に
 * 統一している (統一先が何かではなく、**分岐しないこと**が不変条件)。
 *
 * 併せて「層 3 (認可) は payload 検証より前」を固定する。ここが入れ替わると
 * 権限の無い actor が user_id の差を観測できるようになる。
 */

/** 実在しない user id (9 桁。テストで生成される id と衝突しない値)。 */
const PIEO_MISSING_USER_ID = 999999999;

/**
 * 応答 signature と field error の文言を 1 つにまとめた観測値。
 *
 * `session('errors')` は静的解析上 mixed なので ViewErrorBag への narrowing を明示する。
 *
 * @return array{
 *     signature: array{status: int, headers: array<string, list<string>>, body: string},
 *     user_id_errors: list<string>
 * }
 */
function pieoObserve(TestResponse $response): array
{
    $errors = session('errors');

    return [
        'signature' => ResponseSignature::of($response),
        'user_id_errors' => $errors instanceof ViewErrorBag
            ? array_values($errors->getBag('default')->get('user_id'))
            : [],
    ];
}

/**
 * 与えた id 群で順に叩き、観測値 (応答 signature + field error) を返す。
 *
 * session の errors は次のリクエストで上書きされるため、1 リクエストごとに観測する。
 *
 * @param  callable(int): TestResponse  $request
 * @param  list<int>  $userIds
 * @return list<array{
 *     signature: array{status: int, headers: array<string, list<string>>, body: string},
 *     user_id_errors: list<string>
 * }>
 */
function pieoObserveAll(callable $request, array $userIds): array
{
    $observed = [];
    foreach ($userIds as $userId) {
        $observed[] = pieoObserve($request($userId));
    }

    return $observed;
}

/**
 * 「実在するが非メンバーの id」と「不在の id」で観測値が完全一致することを表明する。
 *
 * @param  callable(int): TestResponse  $request
 */
function pieoAssertNoOracle(callable $request, int $existingNonMemberId): void
{
    [$existing, $missing] = pieoObserveAll($request, [$existingNonMemberId, PIEO_MISSING_USER_ID]);

    expect($existing)->toBe($missing, '実在の非メンバー id と 不在 id の応答が一致しない (存在オラクル)');
}

// --- 施策 A: organizations.transfer-ownership ---

test('transfer-ownership の非メンバーと不在 id は同一応答 (存在オラクル不成立)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    // 移譲先候補として実在する別組織のユーザー (この組織のメンバーではない)
    $outsider = User::factory()->create();

    $send = fn (int $userId): TestResponse => $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->from("/organizations/{$organization->slug}/settings")
        ->post("/organizations/{$organization->slug}/transfer-ownership", ['user_id' => $userId]);

    pieoAssertNoOracle($send, (int) $outsider->id);

    // 文言まで固定する (rule 既定文言に分岐して戻らないことの回帰点)
    $response = $send((int) $outsider->id);
    $response->assertRedirect("/organizations/{$organization->slug}/settings");
    $response->assertSessionHasErrors([
        'user_id' => OrganizationMembershipService::MEMBER_REQUIRED_MESSAGE,
    ]);
    expect($owner->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);
});

test('transfer-ownership は権限が無ければ user_id によらず同一 403', function (): void {
    [$organization] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $admin->forceFill(['current_organization_id' => $organization->id])->save();
    $member = attachOrganizationMember($organization);
    $outsider = User::factory()->create();

    $send = fn (int $userId): TestResponse => $this->actingAs($admin)
        ->withSession(freshRecentAuthSession())
        ->from("/organizations/{$organization->slug}/settings")
        ->post("/organizations/{$organization->slug}/transfer-ownership", ['user_id' => $userId]);

    // 実在メンバー / 実在非メンバー / 不在 の 3 パターンが同一の 403 に落ちる
    $observed = pieoObserveAll($send, [(int) $member->id, (int) $outsider->id, PIEO_MISSING_USER_ID]);

    expect($observed[0]['signature']['status'])->toBe(403);
    expect($observed[1])->toBe($observed[0], '認可拒否の応答が user_id で分岐している');
    expect($observed[2])->toBe($observed[0], '認可拒否の応答が user_id で分岐している');
});

// --- 施策 B: projects.members.store ---

test('projects.members.store の非メンバーと不在 id は同一応答 (存在オラクル不成立)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $outsider = User::factory()->create();

    $send = fn (int $userId): TestResponse => $this->actingAs($owner)
        ->from("/projects/{$project->id}")
        ->post("/projects/{$project->id}/members", [
            'user_id' => $userId,
            'role' => ProjectRole::Member->value,
        ]);

    pieoAssertNoOracle($send, (int) $outsider->id);

    $response = $send((int) $outsider->id);
    $response->assertRedirect("/projects/{$project->id}");
    $response->assertSessionHasErrors('user_id');
    expect($project->members()->count())->toBe(0);
});

test('projects.members.store は権限が無ければ user_id によらず同一 403', function (): void {
    [$organization] = createOrganizationWithOwner();
    $actor = attachOrganizationMember($organization);
    $actor->forceFill(['current_organization_id' => $organization->id])->save();
    $other = attachOrganizationMember($organization);
    $outsider = User::factory()->create();
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $actor, ProjectRole::Member);

    $send = fn (int $userId): TestResponse => $this->actingAs($actor)
        ->from("/projects/{$project->id}")
        ->post("/projects/{$project->id}/members", [
            'user_id' => $userId,
            'role' => ProjectRole::Member->value,
        ]);

    $observed = pieoObserveAll($send, [(int) $other->id, (int) $outsider->id, PIEO_MISSING_USER_ID]);

    expect($observed[0]['signature']['status'])->toBe(403);
    expect($observed[1])->toBe($observed[0], '認可拒否の応答が user_id で分岐している');
    expect($observed[2])->toBe($observed[0], '認可拒否の応答が user_id で分岐している');
});
