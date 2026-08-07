<?php

declare(strict_types=1);

use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * 裁定 AG-113 必須要素 (c) の behavioral proof:
 * 未ログイン / 未 verified / email 空は **DB を一切引かない**。
 *
 * 共有 prop は全リクエストで評価されるため、この early return が実効的な負荷契約になる。
 * 最後の 1 本は**負のコントロール** (guard が常に null を返す実装退行の検出)。
 */

/**
 * organization_invitations に触れた SQL の本数を数えながら callback を実行する。
 *
 * @param  Closure(): void  $callback
 */
function countInvitationQueries(Closure $callback): int
{
    $count = 0;
    DB::listen(function (QueryExecuted $query) use (&$count): void {
        if (str_contains($query->sql, 'organization_invitations')) {
            $count++;
        }
    });

    $callback();

    return $count;
}

test('未ログインは organization_invitations を引かない', function (): void {
    $membership = app(OrganizationMembershipService::class);

    $queries = countInvitationQueries(function () use ($membership): void {
        expect($membership->pendingInvitationCountFor(null))->toBe(0);
        expect($membership->pendingInvitationsFor(null))->toBe([]);
    });

    expect($queries)->toBe(0);
});

test('未 verified は organization_invitations を引かない', function (): void {
    $membership = app(OrganizationMembershipService::class);
    $user = User::factory()->unverified()->create(['email' => 'unverified@example.com']);

    $queries = countInvitationQueries(function () use ($membership, $user): void {
        expect($membership->pendingInvitationCountFor($user))->toBe(0);
        expect($membership->pendingInvitationsFor($user))->toBe([]);
    });

    expect($queries)->toBe(0);
});

test('email 空は organization_invitations を引かない', function (): void {
    $membership = app(OrganizationMembershipService::class);
    $user = User::factory()->create();
    $user->forceFill(['email' => ''])->save();

    $queries = countInvitationQueries(function () use ($membership, $user): void {
        expect($membership->pendingInvitationCountFor($user))->toBe(0);
        expect($membership->pendingInvitationsFor($user))->toBe([]);
    });

    expect($queries)->toBe(0);
});

test('verified かつ email 非空のときだけ引く (負のコントロール)', function (): void {
    $membership = app(OrganizationMembershipService::class);
    [$organization] = createOrganizationWithOwner();
    $user = User::factory()->create(['email' => 'invitee@example.com']);
    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'invitee@example.com']);

    $queries = countInvitationQueries(function () use ($membership, $user): void {
        expect($membership->pendingInvitationCountFor($user))->toBe(1);
        expect($membership->pendingInvitationsFor($user))->toHaveCount(1);
    });

    expect($queries)->toBeGreaterThan(0);
});
