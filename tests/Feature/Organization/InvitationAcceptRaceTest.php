<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
 * joinOrganization() の戻り値 (false = ロック下再検証で受諾不能) の**消費契約**。
 *
 * ★目的は競合の完全再現ではなく、`joinOrganization() === false` を各呼び出し元が
 *   正しく消費することを**決定的に**検証することである (スレッド競合は再現しない)。
 *
 * 決定的な作り方は **SQL の形で当てる**。取得回数では当てない —
 * acceptPendingInvitation は「下見 → ロック下再解決 → joinOrganization 内のロック取得」で
 * 回数が経路ごとに変わり、回数依存の細工は実装変更に脆いため。
 */

/**
 * joinOrganization がロック下再検証のために発行する SELECT ... FOR UPDATE を検出し、
 * その直前に「取り消しが割り込んだ」のと同じ状態を作る (one-shot)。
 *
 * **DB::beforeExecuting() の callback は解除できない** (Laravel に unregister API が無い)。
 * したがって「後始末する」とは書かない。代わりに callback 自身が one-shot で恒久的に
 * inert になる設計にしてある ($fired を立てた後は即 return するだけ)。
 */
function revokeOnLockedRead(int $invitationId): void
{
    $fired = false; // one-shot。自分の UPDATE による再入も止める
    DB::beforeExecuting(function (string $query, array $bindings) use ($invitationId, &$fired): void {
        if ($fired) {
            return;
        }
        $lower = strtolower($query);
        if (! str_contains($lower, 'organization_invitations') || ! str_contains($lower, 'for update')) {
            return;
        }
        // id は必ず placeholder になるため **bindings 側で対象 id を確認する**
        // (SQL 文字列だけでは対象 id を判定できない)。別の招待行のロック読取には干渉しない
        $stringBindings = array_map(static fn (mixed $b): string => is_scalar($b) ? (string) $b : '', $bindings);
        if (! in_array((string) $invitationId, $stringBindings, true)) {
            return;
        }
        $fired = true;
        // 同一接続・同一トランザクション内なので自分のロックと競合しない
        DB::table('organization_invitations')->where('id', $invitationId)->update(['revoked_at' => now()]);
    });
}

test('acceptInvitation はロック下再検証の敗北を事前検証と同一の中立メッセージへ畳む', function (): void {
    [$organization] = createOrganizationWithOwner();
    [$invitation, $plainToken] = OrganizationInvitation::factory()->forOrganization($organization)
        ->createWithPlainToken(['email' => 'invitee@example.com']);
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);

    revokeOnLockedRead($invitation->id);

    $thrown = null;
    try {
        app(OrganizationMembershipService::class)->acceptInvitation($plainToken, $invitee);
    } catch (ValidationException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown?->errors()['token'][0] ?? null)->toBe('この招待は無効です。');
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
    expect($invitation->refresh()->isAccepted())->toBeFalse();
});

test('acceptInvitationIfValid はロック下再検証の敗北で null を返し現在組織を書き換えない', function (): void {
    [$organization] = createOrganizationWithOwner();
    [$ownOrganization, $invitee] = createOrganizationWithOwner('自分の組織');
    [$invitation, $plainToken] = OrganizationInvitation::factory()->forOrganization($organization)
        ->createWithPlainToken(['email' => $invitee->email]);

    revokeOnLockedRead($invitation->id);

    $result = app(OrganizationMembershipService::class)->acceptInvitationIfValid($plainToken, $invitee);

    expect($result)->toBeNull();
    // 受諾できていないので所属も増えない (組織文脈は URL だけで決まるため保持列は無い)
    expect($invitee->organizations()->pluck('organizations.id')->all())->toBe([$ownOrganization->id]);
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
});

test('acceptPendingInvitation はロック下再検証の敗北で null を返す', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => 'invitee@example.com']);

    revokeOnLockedRead($invitation->id);

    $result = app(OrganizationMembershipService::class)->acceptPendingInvitation($invitee, (string) $invitation->id);

    expect($result)->toBeNull();
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
    expect($invitation->refresh()->isAccepted())->toBeFalse();
});

test('helper は one-shot で、同一テスト内の後続受諾に干渉しない (inert 化の behavioral proof)', function (): void {
    [$firstOrganization] = createOrganizationWithOwner('組織 A');
    [$secondOrganization] = createOrganizationWithOwner('組織 B');
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
    $blocked = OrganizationInvitation::factory()->forOrganization($firstOrganization)
        ->create(['email' => 'invitee@example.com']);
    $normal = OrganizationInvitation::factory()->forOrganization($secondOrganization)
        ->create(['email' => 'invitee@example.com']);

    revokeOnLockedRead($blocked->id);

    $membership = app(OrganizationMembershipService::class);
    expect($membership->acceptPendingInvitation($invitee, (string) $blocked->id))->toBeNull();

    // helper 発火後は inert。別の有効な招待は普通に受諾できる
    $joined = $membership->acceptPendingInvitation($invitee, (string) $normal->id);

    expect($joined)->toBeInstanceOf(Organization::class);
    expect($secondOrganization->users()->whereKey($invitee->id)->exists())->toBeTrue();
});
