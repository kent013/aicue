<?php

declare(strict_types=1);

use App\DataTransferObjects\Invitations\PendingInvitationForUserDto;
use App\Enums\OrganizationRole;
use App\Models\OrganizationInvitation;

/*
 * 受信者視点 DTO の開示面。管理者視点 (InvitationRowData) とは別契約であり、
 * email / token_hash / 生の日時 / 招待者 id / 組織 id を出さない。
 */

test('開示項目は 4 つだけ (キー追加を機械検出する)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => 'me@example.com']);

    $dto = PendingInvitationForUserDto::fromInvitation($invitation);

    expect(array_keys($dto->toArray()))->toBe(['id', 'organizationName', 'roleLabel', 'expiresAt']);
});

test('email / token_hash を含まない (値ベースの negative control)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => 'secret-invitee@example.com']);

    $json = json_encode(PendingInvitationForUserDto::fromInvitation($invitation)->toArray());
    $tokenHash = $invitation->getAttribute('token_hash');

    expect($json)->not->toContain('secret-invitee@example.com');
    expect(is_string($tokenHash) && $tokenHash !== '')->toBeTrue();
    expect($json)->not->toContain((string) $tokenHash);
});

test('roleLabel は org ロールのラベル', function (string $role, string $label): void {
    [$organization] = createOrganizationWithOwner();
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => 'me@example.com', 'role' => $role]);

    expect(PendingInvitationForUserDto::fromInvitation($invitation)->roleLabel)->toBe($label);
})->with([
    [OrganizationRole::Admin->value, '管理者'],
    [OrganizationRole::Member->value, 'メンバー'],
]);

test('expiresAt は Y-m-d の文字列', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => 'me@example.com', 'expires_at' => '2026-09-30 13:45:00']);

    $dto = PendingInvitationForUserDto::fromInvitation($invitation);

    expect($dto->expiresAt)->toBe('2026-09-30');
    expect($dto->organizationName)->toBe($organization->name);
    expect($dto->id)->toBe($invitation->id);
});
