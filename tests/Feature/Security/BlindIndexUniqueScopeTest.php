<?php

declare(strict_types=1);

use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/*
|--------------------------------------------------------------------------
| blind_indexes の partial unique index のスコープ検証
|--------------------------------------------------------------------------
|
| unique 対象は「name = 'email_index' かつ User」のみ。blanket unique にすると
| 非ユニークな blind index (OrganizationInvitation.email = 同一 email の複数組織への
| 招待が正当) の 2 件目を誤って弾く regression になる (派生アプリが実際に踏んだバグ)。
|
*/

test('User の email blind index は DB 層で一意 (INSERT race の最終防衛線)', function (): void {
    User::factory()->create(['email' => 'unique@example.com']);

    expect(fn () => User::factory()->create(['email' => 'unique@example.com']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('OrganizationInvitation の email blind index は非ユニーク (同一 email を別組織へ招待できる)', function (): void {
    [$org1, $owner1] = createOrganizationWithOwner('組織1');
    [$org2, $owner2] = createOrganizationWithOwner('組織2');

    $createInvitation = function ($org, $owner): OrganizationInvitation {
        $invitation = new OrganizationInvitation([
            'email' => 'invitee@example.com',
            'role' => 'member',
        ]);
        $invitation->forceFill([
            'organization_id' => $org->id,
            'invited_by_user_id' => $owner->id,
            'token_hash' => hash('sha256', uniqid('', true)),
            'expires_at' => now()->addDays(7),
        ])->save();

        return $invitation;
    };

    $createInvitation($org1, $owner1);
    $createInvitation($org2, $owner2);

    expect(OrganizationInvitation::whereBlind('email', 'email_index', 'invitee@example.com')->count())
        ->toBe(2);
});
