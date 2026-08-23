<?php

declare(strict_types=1);

use App\Enums\EnterpriseSso\ConnectionTransitionRejection;
use App\Enums\EnterpriseSso\OidcConnectionStatus;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use App\Exceptions\EnterpriseSso\OidcConnectionTransitionException;
use App\Models\EnterpriseIdentity;
use App\Models\Organization;
use App\Models\OrganizationOidcConnection;
use App\Services\EnterpriseSso\OidcConnectionTransitionService;
use App\ValueObjects\EnterpriseSso\ConnectionSecret;
use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\EnterpriseSso\FakeIdentityProvider;

/*
 * 接続の状態遷移 (D1)。
 */

function transitions(): OidcConnectionTransitionService
{
    return app(OidcConnectionTransitionService::class);
}

test('登録は常に Draft から始まる', function (): void {
    $organization = Organization::factory()->create();

    $connection = transitions()->create(
        $organization,
        'acme-idp',
        'ACME 社',
        OidcIssuerUrl::fromString('https://idp.example.test'),
        'client-1',
        ConnectionSecret::fromPlaintext('secret'),
    );

    expect($connection->status)->toBe(OidcConnectionStatus::Draft);
    expect($connection->verified_at)->toBeNull();
    expect($connection->credentials_revision)->toBe(1);
});

test('表示名だけの更新では状態も版も変わらない', function (): void {
    $connection = OrganizationOidcConnection::factory()->active()->create();
    /** @var Organization $organization */
    $organization = $connection->organization;

    transitions()->update($organization, $connection->id, '新しい表示名', null, null, null);

    $fresh = $connection->fresh();
    expect($fresh?->display_name)->toBe('新しい表示名');
    expect($fresh?->status)->toBe(OidcConnectionStatus::Active);
    expect($fresh?->credentials_revision)->toBe(1);
    expect($fresh?->verified_at)->not->toBeNull();
});

test('client secret の更新は Draft へ戻り verified_at が消え、版が +1 される', function (): void {
    $connection = OrganizationOidcConnection::factory()->active()->create();
    /** @var Organization $organization */
    $organization = $connection->organization;

    transitions()->update($organization, $connection->id, null, null, null, ConnectionSecret::fromPlaintext('new'));

    $fresh = $connection->fresh();
    expect($fresh?->status)->toBe(OidcConnectionStatus::Draft);
    expect($fresh?->verified_at)->toBeNull();
    expect($fresh?->credentials_revision)->toBe(2);
});

test('身元が 0 件なら issuer / client_id を変更できるが Draft へ戻る', function (): void {
    $connection = OrganizationOidcConnection::factory()->active()->create();
    /** @var Organization $organization */
    $organization = $connection->organization;

    transitions()->update(
        $organization,
        $connection->id,
        null,
        OidcIssuerUrl::fromString('https://new.example.test'),
        'client-2',
        null,
    );

    $fresh = $connection->fresh();
    expect($fresh?->issuer)->toBe('https://new.example.test');
    expect($fresh?->client_id)->toBe('client-2');
    expect($fresh?->status)->toBe(OidcConnectionStatus::Draft);
    expect($fresh?->verified_at)->toBeNull();
    expect($fresh?->credentials_revision)->toBe(2);
});

test('身元がある接続の issuer / client_id は変更できない', function (?string $issuer, ?string $clientId): void {
    $connection = OrganizationOidcConnection::factory()->active()->create();
    EnterpriseIdentity::factory()->create(['organization_oidc_connection_id' => $connection->id]);
    /** @var Organization $organization */
    $organization = $connection->organization;

    try {
        transitions()->update(
            $organization,
            $connection->id,
            null,
            $issuer === null ? null : OidcIssuerUrl::fromString($issuer),
            $clientId,
            null,
        );
        expect(false)->toBeTrue('拒否されるはず');
    } catch (OidcConnectionTransitionException $e) {
        expect($e->rejection)->toBe(ConnectionTransitionRejection::IdentitiesExistCannotChangeNamespace);
    }

    // ★拒否された後も旧接続はそのまま使える (既存の利用者が締め出されない)
    $fresh = $connection->fresh();
    expect($fresh?->status)->toBe(OidcConnectionStatus::Active);
    expect($fresh?->credentials_revision)->toBe(1);
})->with([
    'issuer を変える' => ['https://new.example.test', null],
    'client_id を変える' => [null, 'client-2'],
]);

test('身元があっても client secret は更新できる (Draft へ戻る)', function (): void {
    $connection = OrganizationOidcConnection::factory()->active()->create();
    EnterpriseIdentity::factory()->create(['organization_oidc_connection_id' => $connection->id]);
    /** @var Organization $organization */
    $organization = $connection->organization;

    transitions()->update($organization, $connection->id, null, null, null, ConnectionSecret::fromPlaintext('new'));

    expect($connection->fresh()?->status)->toBe(OidcConnectionStatus::Draft);
});

test('同じ値を渡しても名前空間の変更とはみなさない (版が増えない)', function (): void {
    $connection = OrganizationOidcConnection::factory()->active()->create();
    EnterpriseIdentity::factory()->create(['organization_oidc_connection_id' => $connection->id]);
    /** @var Organization $organization */
    $organization = $connection->organization;

    transitions()->update(
        $organization,
        $connection->id,
        null,
        OidcIssuerUrl::fromString($connection->issuer),
        $connection->client_id,
        null,
    );

    expect($connection->fresh()?->credentials_revision)->toBe(1);
});

test('有効化は Verified / verified_at つきの Disabled からだけできる', function (string $state, bool $allowed): void {
    $connection = OrganizationOidcConnection::factory()->create();
    /** @var Organization $organization */
    $organization = $connection->organization;

    match ($state) {
        'draft' => null,
        'verified' => $connection->forceFill(['status' => OidcConnectionStatus::Verified, 'verified_at' => now()])->save(),
        'disabled' => $connection->forceFill(['status' => OidcConnectionStatus::Disabled, 'verified_at' => now()])->save(),
        'disabled-never-verified' => $connection->forceFill(['status' => OidcConnectionStatus::Disabled, 'verified_at' => null])->save(),
        default => null,
    };

    if ($allowed) {
        transitions()->activate($organization, $connection->id);
        expect($connection->fresh()?->status)->toBe(OidcConnectionStatus::Active);

        return;
    }

    expect(fn () => transitions()->activate($organization, $connection->id))
        ->toThrow(OidcConnectionTransitionException::class);
})->with([
    ['draft', false],
    ['verified', true],
    ['disabled', true],
    ['disabled-never-verified', false],
]);

test('無効化は Active からだけできる', function (): void {
    $connection = OrganizationOidcConnection::factory()->active()->create();
    /** @var Organization $organization */
    $organization = $connection->organization;

    transitions()->disable($organization, $connection->id);
    expect($connection->fresh()?->status)->toBe(OidcConnectionStatus::Disabled);

    // 2 回目は定義外の遷移
    expect(fn () => transitions()->disable($organization, $connection->id))
        ->toThrow(OidcConnectionTransitionException::class);
});

test('無効化しても身元は残る (再び有効にすれば同じ利用者へ戻る)', function (): void {
    $connection = OrganizationOidcConnection::factory()->active()->create();
    $identity = EnterpriseIdentity::factory()->create(['organization_oidc_connection_id' => $connection->id]);
    /** @var Organization $organization */
    $organization = $connection->organization;

    transitions()->disable($organization, $connection->id);
    transitions()->activate($organization, $connection->id);

    expect($connection->identities()->whereKey($identity->id)->exists())->toBeTrue();
});

test('身元が 0 件なら削除できる', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();
    /** @var Organization $organization */
    $organization = $connection->organization;

    transitions()->destroy($organization, $connection->id);

    expect(OrganizationOidcConnection::query()->whereKey($connection->id)->exists())->toBeFalse();
});

test('身元がある接続は削除できない (アカウントの分裂を作らない)', function (): void {
    $connection = OrganizationOidcConnection::factory()->active()->create();
    EnterpriseIdentity::factory()->create(['organization_oidc_connection_id' => $connection->id]);
    /** @var Organization $organization */
    $organization = $connection->organization;

    try {
        transitions()->destroy($organization, $connection->id);
        expect(false)->toBeTrue('拒否されるはず');
    } catch (OidcConnectionTransitionException $e) {
        expect($e->rejection)->toBe(ConnectionTransitionRejection::IdentitiesExistCannotDelete);
    }

    expect(OrganizationOidcConnection::query()->whereKey($connection->id)->exists())->toBeTrue();
});

test('他組織の接続 id では 1 件も触れない (relation 起点であることの証明)', function (): void {
    $connection = OrganizationOidcConnection::factory()->active()->create();
    $otherOrganization = Organization::factory()->create();

    foreach ([
        fn () => transitions()->disable($otherOrganization, $connection->id),
        fn () => transitions()->activate($otherOrganization, $connection->id),
        fn () => transitions()->destroy($otherOrganization, $connection->id),
        fn () => transitions()->update($otherOrganization, $connection->id, '乗っ取り', null, null, null),
    ] as $operation) {
        expect($operation)->toThrow(ModelNotFoundException::class);
    }

    expect($connection->fresh()?->status)->toBe(OidcConnectionStatus::Active);
    expect($connection->fresh()?->display_name)->toBe($connection->display_name);
});

test('取得の失敗で接続の状態が変わらない (可用性の後退を作らない)', function (): void {
    $idp = (new FakeIdentityProvider)->withStatus(503)->install();
    $connection = OrganizationOidcConnection::factory()->create(['issuer' => $idp->issuer]);
    /** @var Organization $organization */
    $organization = $connection->organization;

    expect(fn () => transitions()->verify($organization, $connection))
        ->toThrow(EnterpriseSsoAttemptRejectedException::class);

    $fresh = $connection->fresh();
    expect($fresh?->status)->toBe(OidcConnectionStatus::Draft);
    expect($fresh?->verified_at)->toBeNull();
});

test('新しい接続で同じ subject が来ても旧接続の利用者へは結合されない', function (): void {
    $organization = Organization::factory()->create();
    $old = OrganizationOidcConnection::factory()->active()->create(['organization_id' => $organization->id]);
    $identity = EnterpriseIdentity::factory()->create([
        'organization_oidc_connection_id' => $old->id,
        'subject' => 'sub-1',
    ]);

    $new = OrganizationOidcConnection::factory()->active()->create(['organization_id' => $organization->id]);

    // 身元の名前空間は接続ごとなので、新しい接続からは旧身元が見えない
    expect($new->identities()->where('subject', 'sub-1')->exists())->toBeFalse();
    expect($old->identities()->whereKey($identity->id)->exists())->toBeTrue();
});
