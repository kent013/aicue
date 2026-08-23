<?php

declare(strict_types=1);

use App\Enums\EnterpriseSso\OidcConnectionStatus;
use App\Enums\OrganizationRole;
use App\Models\EnterpriseIdentity;
use App\Models\Organization;
use App\Models\OrganizationOidcConnection;
use App\Models\User;
use App\ValueObjects\EnterpriseSso\ConnectionSecret;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnterpriseSso\FakeIdentityProvider;

/*
 * 組織側の接続管理 (D2)。
 *
 * ★登録・更新フォームが**接続の秘密を扱う唯一の前面**である (正典 v1 / I4)。
 * ★一覧の生成は**秘密を一度も復号しない**。
 */

function ssoUrl(Organization $organization, string $suffix = ''): string
{
    return "/organizations/{$organization->slug}/sso".$suffix;
}

function ssoConnection(Organization $organization, ?FakeIdentityProvider $idp = null): OrganizationOidcConnection
{
    return OrganizationOidcConnection::factory()->create([
        'organization_id' => $organization->id,
        'issuer' => $idp?->issuer ?? 'https://idp.example.test',
        'client_secret_encrypted' => ConnectionSecret::fromPlaintext('very-secret-value'),
    ]);
}

test('owner は一覧を見られる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    ssoConnection($organization);

    $this->actingAs($owner)->get(ssoUrl($organization))
        ->assertSuccessful()
        ->assertInertia(fn ($page) => $page->component('Organizations/Sso/Index'));
});

test('権限のないメンバーは 7 route すべてで 403', function (string $method, string $suffix, array $payload): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization, OrganizationRole::Member);
    $connection = ssoConnection($organization);

    $path = ssoUrl($organization, str_replace('{id}', (string) $connection->id, $suffix));

    // ★入力は**妥当なもの**を送る (validation で先に落ちると認可へ到達せず、検査が空振りする)。
    $this->actingAs($member)
        ->withSession(freshRecentAuthSession())
        ->call($method, $path, $payload)
        ->assertForbidden();
})->with([
    ['GET', '', []],
    ['POST', '', [
        'login_slug' => 'acme-idp',
        'display_name' => 'ACME 社',
        'issuer' => 'https://idp.example.test',
        'client_id' => 'client-1',
        'client_secret' => 'secret',
    ]],
    ['PATCH', '/{id}', ['display_name' => '書き換え']],
    ['POST', '/{id}/verify', []],
    ['POST', '/{id}/activate', []],
    ['POST', '/{id}/disable', []],
    ['DELETE', '/{id}', []],
]);

test('他組織の接続 id は 403 ではなく 404 (存在オラクルを作らない)', function (string $method, string $suffix): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $otherOrganization = Organization::factory()->create();
    $foreign = ssoConnection($otherOrganization);

    $path = ssoUrl($organization, str_replace('{id}', (string) $foreign->id, $suffix));

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->call($method, $path, ['display_name' => '書き換え'])
        ->assertNotFound();
})->with([
    ['PATCH', '/{id}'],
    ['POST', '/{id}/verify'],
    ['POST', '/{id}/activate'],
    ['POST', '/{id}/disable'],
    ['DELETE', '/{id}'],
]);

test('更新系 6 route は再認証なしで弾かれる', function (string $method, string $suffix): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $connection = ssoConnection($organization);

    $path = ssoUrl($organization, str_replace('{id}', (string) $connection->id, $suffix));

    $this->actingAs($owner)->call($method, $path)
        ->assertRedirect(route('recent-auth.confirm'));
})->with([
    ['POST', ''],
    ['PATCH', '/{id}'],
    ['POST', '/{id}/verify'],
    ['POST', '/{id}/activate'],
    ['POST', '/{id}/disable'],
    ['DELETE', '/{id}'],
]);

test('登録できる (常に Draft から始まる)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->post(ssoUrl($organization), [
            'login_slug' => 'acme-idp',
            'display_name' => 'ACME 社',
            'issuer' => 'https://idp.example.test',
            'client_id' => 'client-1',
            'client_secret' => 'secret-value',
        ])
        ->assertRedirect();

    $connection = $organization->oidcConnections()->firstOrFail();
    expect($connection->status)->toBe(OidcConnectionStatus::Draft);
    expect($connection->clientSecret()->revealForTokenExchange())->toBe('secret-value');
});

test('validation 失敗時に client secret がセッションへ残らない (dontFlash)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->post(ssoUrl($organization), [
            'login_slug' => '',   // 失敗させる
            'display_name' => 'ACME 社',
            'issuer' => 'https://idp.example.test',
            'client_id' => 'client-1',
            'client_secret' => 'super-secret-value',
        ])
        ->assertSessionHasErrors('login_slug');

    /** @var array<string, mixed> $old */
    $old = session()->get('_old_input', []);
    expect($old)->not->toHaveKey('client_secret');
    expect(json_encode($old, JSON_THROW_ON_ERROR))->not->toContain('super-secret-value');
});

test('issuer の規則に合わない登録は拒否される', function (string $issuer): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->post(ssoUrl($organization), [
            'login_slug' => 'acme-idp',
            'display_name' => 'ACME 社',
            'issuer' => $issuer,
            'client_id' => 'client-1',
            'client_secret' => 'secret',
        ])
        ->assertSessionHasErrors('issuer');

    expect($organization->oidcConnections()->count())->toBe(0);
})->with([
    'http' => 'http://idp.example.test',
    'query つき' => 'https://idp.example.test?a=1',
    'userinfo つき' => 'https://u:p@idp.example.test',
]);

test('識別名は全体で一意である', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    OrganizationOidcConnection::factory()->create(['login_slug' => 'acme-idp']);

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->post(ssoUrl($organization), [
            'login_slug' => 'acme-idp',
            'display_name' => 'ACME 社',
            'issuer' => 'https://idp.example.test',
            'client_id' => 'client-1',
            'client_secret' => 'secret',
        ])
        ->assertSessionHasErrors('login_slug');
});

test('client secret を空で送ると据え置きになる (伏字が保存される事故を作らない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $connection = ssoConnection($organization);

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->patch(ssoUrl($organization, "/{$connection->id}"), [
            'display_name' => '新しい表示名',
            'client_secret' => '',
        ])
        ->assertRedirect();

    $fresh = $connection->fresh();
    expect($fresh?->clientSecret()->revealForTokenExchange())->toBe('very-secret-value');
    expect($fresh?->credentials_revision)->toBe(1);
});

test('client secret を更新すると一覧の状態が Draft になる (D1 との結線)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $connection = ssoConnection($organization);
    $connection->forceFill(['status' => OidcConnectionStatus::Active, 'verified_at' => now()])->save();

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->patch(ssoUrl($organization, "/{$connection->id}"), ['client_secret' => 'rotated'])
        ->assertRedirect();

    expect($connection->fresh()?->status)->toBe(OidcConnectionStatus::Draft);
});

test('一覧の応答に client secret の原文も暗号文も出ない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $connection = ssoConnection($organization);

    $response = $this->actingAs($owner)->get(ssoUrl($organization));

    expect($response->getContent())->not->toContain('very-secret-value');
    expect($response->getContent())->not->toContain((string) $connection->getRawOriginal('client_secret_encrypted'));

    $response->assertInertia(fn ($page) => $page
        ->where('connections.0.hasClientSecret', true)
        ->missing('connections.0.clientSecret'));
});

test('一覧の生成が秘密を一度も復号しない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    ssoConnection($organization);

    // ★復号の唯一の入口 (`clientSecret()`) を通ったかどうかを、
    //   通ったら必ず失敗する形にして観測する。
    //   cast の setter を迂回して**生の属性**を壊す (復号すれば必ず例外になる)。
    OrganizationOidcConnection::retrieved(function (OrganizationOidcConnection $connection): void {
        $connection->setRawAttributes(
            [...$connection->getAttributes(), 'client_secret_encrypted' => 'broken-ciphertext'],
            sync: true,
        );
    });

    try {
        // 復号していれば DecryptException になる。しないので 200 のままである。
        $this->actingAs($owner)->get(ssoUrl($organization))->assertSuccessful();
    } finally {
        OrganizationOidcConnection::flushEventListeners();
    }
});

test('身元がある接続の削除は押せるが拒否され、理由が画面に出る (ボタンを disabled にしない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $connection = ssoConnection($organization);
    EnterpriseIdentity::factory()->create(['organization_oidc_connection_id' => $connection->id]);

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->delete(ssoUrl($organization, "/{$connection->id}"))
        ->assertRedirect()
        ->assertSessionHasErrors('sso_connection');

    expect(OrganizationOidcConnection::query()->whereKey($connection->id)->exists())->toBeTrue();
});

test('身元がある接続の issuer 変更は押せるが拒否され、理由が画面に出る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $connection = ssoConnection($organization);
    EnterpriseIdentity::factory()->create(['organization_oidc_connection_id' => $connection->id]);

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->patch(ssoUrl($organization, "/{$connection->id}"), ['issuer' => 'https://new.example.test'])
        ->assertSessionHasErrors('sso_connection');

    expect($connection->fresh()?->issuer)->toBe('https://idp.example.test');
});

test('確認は成功すると Verified になる', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    [$organization, $owner] = createOrganizationWithOwner();
    $connection = ssoConnection($organization, $idp);

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->post(ssoUrl($organization, "/{$connection->id}/verify"))
        ->assertSessionHas('success');

    expect($connection->fresh()?->status)->toBe(OidcConnectionStatus::Verified);
});

test('確認が材料の変更で捨てられたとき、やり直す旨が具体的に画面へ出る (一様な応答にしない)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    [$organization, $owner] = createOrganizationWithOwner();
    $connection = ssoConnection($organization, $idp);

    $idp->beforeRespond(function () use ($connection): void {
        DB::table('organization_oidc_connections')
            ->where('id', $connection->id)
            ->update(['credentials_revision' => 99]);
    });

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->post(ssoUrl($organization, "/{$connection->id}/verify"))
        ->assertSessionHasErrors('sso_connection');

    expect($connection->fresh()?->status)->toBe(OidcConnectionStatus::Draft);
});

test('確認の action が外向き取得を包むトランザクションを張らない', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    [$organization, $owner] = createOrganizationWithOwner();
    $connection = ssoConnection($organization, $idp);

    $baseline = DB::transactionLevel();
    /** @var list<int> $observed */
    $observed = [];
    $idp->beforeRespond(function () use (&$observed): void {
        $observed[] = DB::transactionLevel();
    });

    $this->actingAs($owner)
        ->withSession(freshRecentAuthSession())
        ->post(ssoUrl($organization, "/{$connection->id}/verify"));

    expect($observed)->not->toBe([]);
    foreach ($observed as $level) {
        expect($level)->toBe($baseline);
    }
});

test('有効化と無効化ができる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $connection = ssoConnection($organization);
    $connection->forceFill(['status' => OidcConnectionStatus::Verified, 'verified_at' => now()])->save();

    $this->actingAs($owner)->withSession(freshRecentAuthSession())
        ->post(ssoUrl($organization, "/{$connection->id}/activate"))->assertSessionHas('success');
    expect($connection->fresh()?->status)->toBe(OidcConnectionStatus::Active);

    $this->actingAs($owner)->withSession(freshRecentAuthSession())
        ->post(ssoUrl($organization, "/{$connection->id}/disable"))->assertSessionHas('success');
    expect($connection->fresh()?->status)->toBe(OidcConnectionStatus::Disabled);
});

test('未認証では一覧に到達できない', function (): void {
    [$organization] = createOrganizationWithOwner();

    $this->get(ssoUrl($organization))->assertRedirect(route('login'));
});

test('非メンバーは 403 ではなく 404 (組織そのものの存在を漏らさない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->get(ssoUrl($organization))->assertNotFound();
});
