<?php

declare(strict_types=1);

use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * 共有 prop invitationInbox.pendingCount の契約。
 *
 * 件数は受諾の解決・一覧と**同一 scope** から算出する (ずれると
 * 「件数は出るのに受諾できない」が起きる)。未ログイン / 未 verified は DB を引かない。
 */

/**
 * organization_invitations に触れた SQL の本数を数えながら callback を実行する。
 *
 * @param  Closure(): void  $callback
 */
function countInvitationQueriesDuringRequest(Closure $callback): int
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

test('未ログインのページでは pendingCount が 0 で DB を引かない', function (): void {
    $queries = countInvitationQueriesDuringRequest(function (): void {
        $this->get('/login')->assertInertia(
            fn (AssertableInertia $page) => $page->where('invitationInbox.pendingCount', 0),
        );
    });

    expect($queries)->toBe(0);
});

test('未 verified では 0 で DB を引かない', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'unverified@example.com']);
    [$organization] = createOrganizationWithOwner();
    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'unverified@example.com']);

    // 未 verified が到達できる Inertia 画面 (メール確認案内) で共有 prop を検証する
    $queries = countInvitationQueriesDuringRequest(function () use ($user): void {
        $this->actingAs($user)->get(route('verification.notice'))->assertInertia(
            fn (AssertableInertia $page) => $page->where('invitationInbox.pendingCount', 0),
        );
    });

    expect($queries)->toBe(0);
});

test('verified かつ自分宛 active 招待 2 件で pendingCount = 2 (負のコントロール)', function (): void {
    [$firstOrganization] = createOrganizationWithOwner('組織 A');
    [$secondOrganization] = createOrganizationWithOwner('組織 B');
    $user = User::factory()->create(['email' => 'invitee@example.com']);
    OrganizationInvitation::factory()->forOrganization($firstOrganization)->create(['email' => 'invitee@example.com']);
    OrganizationInvitation::factory()->forOrganization($secondOrganization)->create(['email' => 'invitee@example.com']);

    $this->actingAs($user)->get('/notifications')->assertInertia(
        fn (AssertableInertia $page) => $page->where('invitationInbox.pendingCount', 2),
    );
});

test('他人宛の招待は数えない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $user = User::factory()->create(['email' => 'invitee@example.com']);
    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'someone-else@example.com']);

    $this->actingAs($user)->get('/notifications')->assertInertia(
        fn (AssertableInertia $page) => $page->where('invitationInbox.pendingCount', 0),
    );
});

test('件数と一覧が一致する (scope 再利用の behavioral proof)', function (): void {
    [$firstOrganization] = createOrganizationWithOwner('組織 A');
    [$secondOrganization] = createOrganizationWithOwner('組織 B');
    $user = User::factory()->create(['email' => 'invitee@example.com']);
    OrganizationInvitation::factory()->forOrganization($firstOrganization)->create(['email' => 'invitee@example.com']);
    OrganizationInvitation::factory()->forOrganization($secondOrganization)->create(['email' => 'invitee@example.com']);
    // 数えてはいけないもの (取消済 / 他人宛) を混ぜる
    OrganizationInvitation::factory()->forOrganization($firstOrganization)->revoked()->create(['email' => 'invitee@example.com']);
    OrganizationInvitation::factory()->forOrganization($firstOrganization)->create(['email' => 'other@example.com']);

    $this->actingAs($user)->get('/notifications')->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('invitationInbox.pendingCount', 2)
            ->has('pendingInvitations', 2),
    );
});
