<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\Billing\TicketLedgerService;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

/*
 * 組織招待 (送信 / 受諾 / 拒否系)。
 * 招待送信は back + success flash で完結すること (画面遷移しない。
 * devnotes/20260611-template-extraction/14 §4)。
 * 招待のロールは**組織ロール 2 値** (管理者 / メンバー)。役割付き招待 (project_role) は
 * 裁定 AG-079 で撤去済みで、編集者 / 撮影者は参加後に管理画面のロール割当コマンドで付与する。
 */

/**
 * service 経由で招待を送り、メールに載った平文 token を取り出す。
 */
function inviteAndCaptureToken(Organization $organization, User $invitedBy, string $email, OrganizationRole $role): string
{
    Notification::fake();
    app(OrganizationMembershipService::class)->inviteMember($organization, $invitedBy, $email, $role);

    $plainToken = null;
    Notification::assertSentOnDemand(
        OrganizationInvitationNotification::class,
        function (OrganizationInvitationNotification $notification) use (&$plainToken): bool {
            parse_str((string) parse_url($notification->acceptUrl, PHP_URL_QUERY), $query);
            $plainToken = $query['token'] ?? null;

            return is_string($plainToken);
        },
    );
    assert(is_string($plainToken));

    return $plainToken;
}

test('招待送信は redirect back + success flash で完結する (画面遷移しない)', function (): void {
    Notification::fake();
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/settings")
        ->post("/organizations/{$organization->slug}/invitations", [
            'email' => 'invitee@example.com',
            'role' => OrganizationRole::Admin->value,
        ]);

    // back (= 元画面の組織設定) へ戻ること。intended は使わない
    $response->assertRedirect("/organizations/{$organization->slug}/settings");
    $response->assertSessionHas('success', '招待メールを送信しました');

    Notification::assertSentOnDemand(
        OrganizationInvitationNotification::class,
        fn (OrganizationInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool => ($notifiable->routes['mail'] ?? null) === 'invitee@example.com',
    );

    // 平文 token は保存されない (sha256 のみ)
    $invitation = OrganizationInvitation::query()->sole();
    expect($invitation->getAttribute('token_hash'))->toHaveLength(64);
    expect($invitation->email)->toBe('invitee@example.com');
});

test('token 受諾でメンバーシップ + 招待ロールが付与される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = inviteAndCaptureToken($organization, $owner, 'invitee@example.com', OrganizationRole::Admin);

    // 受諾するユーザーは別組織を現在組織に持つ (POST 受諾が現在組織を切り替えないことを固定するため)
    [$otherOrg, $invitee] = createOrganizationWithOwner('受諾者の既存組織');
    $invitee->forceFill(['current_organization_id' => $otherOrg->id])->save();
    $before = $invitee->current_organization_id;

    $response = $this->actingAs($invitee)->post('/invitations/accept', ['token' => $token]);

    $response->assertRedirect('/dashboard');
    $response->assertSessionHas('success');
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeTrue();
    expect($invitee->organizationRole($organization))->toBe(OrganizationRole::Admin);
    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeTrue();

    // [register 専用前提の保護] POST 受諾 (ログイン後経路) は現在組織を切り替えない。
    // 将来 joinOrganization へ現在組織確定を誤って昇格させる回帰を検知する。
    expect($invitee->refresh()->current_organization_id)->toBe($before);
});

test('受諾画面 (GET) は組織名と token を表示する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('受諾テスト組織');
    $token = inviteAndCaptureToken($organization, $owner, 'invitee@example.com', OrganizationRole::Admin);

    $invitee = User::factory()->create();
    $response = $this->actingAs($invitee)->get('/invitations/accept?token='.$token);

    $response->assertOk();
    $response->assertInertia(
        fn ($page) => $page->component('Invitations/Accept')
            ->where('organizationName', '受諾テスト組織')
            ->where('token', $token),
    );
});

test('失効した招待は受諾できない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitation = new OrganizationInvitation(['email' => 'expired@example.com']);
    $invitation->organization()->associate($organization);
    $invitation->forceFill([
        'role' => OrganizationRole::Member->value,
        'token_hash' => hash('sha256', 'expired-token'),
        'expires_at' => now()->subDay(),
    ]);
    $invitation->save();

    $invitee = User::factory()->create();
    $response = $this->actingAs($invitee)->post('/invitations/accept', ['token' => 'expired-token']);

    $response->assertRedirect('/dashboard');
    $response->assertSessionHas('error');
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
});

test('受諾済みの招待は再受諾できない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitation = new OrganizationInvitation(['email' => 'used@example.com']);
    $invitation->organization()->associate($organization);
    $invitation->forceFill([
        'role' => OrganizationRole::Member->value,
        'token_hash' => hash('sha256', 'used-token'),
        'expires_at' => now()->addDay(),
        'accepted_at' => now(),
    ]);
    $invitation->save();

    $invitee = User::factory()->create();
    $response = $this->actingAs($invitee)->post('/invitations/accept', ['token' => 'used-token']);

    $response->assertRedirect('/dashboard');
    $response->assertSessionHas('error');
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
});

test('既存メンバーへの再招待は中立メッセージで拒否される', function (): void {
    Notification::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $response = $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/settings")
        ->post("/organizations/{$organization->slug}/invitations", [
            'email' => $member->email,
            'role' => OrganizationRole::Admin->value,
        ]);

    // 既存メンバーであることを開示しない中立メッセージ
    $response->assertSessionHasErrors(['email' => 'このメールアドレスには招待を送信できません。']);
    Notification::assertNothingSent();
});

test('有効な既存招待がある email への再招待も中立メッセージで拒否される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    inviteAndCaptureToken($organization, $owner, 'pending@example.com', OrganizationRole::Admin);

    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/invitations", [
        'email' => 'pending@example.com',
        'role' => OrganizationRole::Admin->value,
    ]);

    $response->assertSessionHasErrors(['email' => 'このメールアドレスには招待を送信できません。']);
    expect(OrganizationInvitation::query()->count())->toBe(1);
});

test('manageMembers 権限がない member は招待できない (403)', function (): void {
    Notification::fake();
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $response = $this->actingAs($member)->post("/organizations/{$organization->slug}/invitations", [
        'email' => 'someone@example.com',
        'role' => OrganizationRole::Admin->value,
    ]);

    $response->assertForbidden();
    Notification::assertNothingSent();
});

test('Owner ロールでの招待は指定できない (transferOwnership のみが正規経路)', function (): void {
    Notification::fake();
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/invitations", [
        'email' => 'someone@example.com',
        'role' => OrganizationRole::Owner->value,
    ]);

    $response->assertSessionHasErrors('role');
    Notification::assertNothingSent();
});

/*
 * 招待取り消し (論理失効)。
 */

test('Owner は招待を取り消せる (行削除ではなく revoked_at で失効)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)->create();

    $response = $this->actingAs($owner)
        ->from("/organizations/{$organization->slug}/settings")
        ->delete("/organizations/{$organization->slug}/invitations/{$invitation->id}");

    $response->assertRedirect("/organizations/{$organization->slug}/settings");
    $response->assertSessionHas('success', '招待を取り消しました');

    // 行は残り revoked_at が立つ (監査痕跡)
    $invitation->refresh();
    expect($invitation->isRevoked())->toBeTrue();
});

test('取り消した招待は受諾できない (無効扱い、取り消しは開示しない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$invitation, $token] = OrganizationInvitation::factory()
        ->forOrganization($organization)
        ->createWithPlainToken();

    // 取り消し
    $this->actingAs($owner)->delete("/organizations/{$organization->slug}/invitations/{$invitation->id}");

    // 別ユーザーが取り消し済み token で受諾を試みる
    $invitee = User::factory()->create();
    $response = $this->actingAs($invitee)->post('/invitations/accept', ['token' => $token]);

    $response->assertRedirect('/dashboard');
    $response->assertSessionHas('error', 'この招待は無効です。');
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeFalse();
});

test('manageMembers 権限がない member は招待を取り消せない (403)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)->create();

    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $response = $this->actingAs($member)
        ->delete("/organizations/{$organization->slug}/invitations/{$invitation->id}");

    $response->assertForbidden();
    expect($invitation->refresh()->isRevoked())->toBeFalse();
});

test('組織を跨いだ招待の取り消しは認可より前に 404 (scopeBindings)', function (): void {
    [$orgA] = createOrganizationWithOwner('組織A');
    [$orgB, $ownerB] = createOrganizationWithOwner('組織B');
    // 招待は組織 A に属する
    $invitation = OrganizationInvitation::factory()->forOrganization($orgA)->create();

    // 組織 B の owner が B の slug 経由で A の招待を取り消そうとする → relation 不整合で 404
    $response = $this->actingAs($ownerB)
        ->delete("/organizations/{$orgB->slug}/invitations/{$invitation->id}");

    $response->assertNotFound();
    expect($invitation->refresh()->isRevoked())->toBeFalse();
});

/*
 * 未ログインの招待リンク → session 保存 → register 誘導。
 */

test('未ログインの有効招待リンクは token を session 保存し register へ誘導する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = inviteAndCaptureToken($organization, $owner, 'guest@example.com', OrganizationRole::Admin);

    $response = $this->get('/invitations/accept?token='.$token);

    $response->assertRedirect(route('register'));
    $response->assertSessionHas('invitation_token', $token);
});

test('無効な招待リンクは理由非開示の専用ページを返す (guest)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)->revoked()->create();
    // token_hash から逆算できないため、既知の平文を持つ取り消し済み招待を用意する
    $invitation->forceFill(['token_hash' => hash('sha256', 'revoked-guest-token')])->save();

    $response = $this->get('/invitations/accept?token=revoked-guest-token');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('Invitations/Invalid'));
    // 組織名を props に含めない (組織の実在を開示しない)
    $response->assertInertia(fn ($page) => $page->missing('organizationName'));
});

test('無効な招待リンクはログイン済みでも専用ページを返す (理由・組織名非開示)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $invitation = OrganizationInvitation::factory()->forOrganization($organization)->expired()->create();
    $invitation->forceFill(['token_hash' => hash('sha256', 'expired-auth-token')])->save();

    $invitee = User::factory()->create();
    $response = $this->actingAs($invitee)->get('/invitations/accept?token=expired-auth-token');

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page->component('Invitations/Invalid'));
});

/*
 * T101: 無効分岐は同じ route (invitations.accept) で別ページを返すため、config('seo.app_titles')
 * (route 名でしか引けない) では表現できない。controller の SeoManager::setPrivateTitle() で
 * 上書きする契約を仕様固定する。秘匿契約 (理由・組織名を出さない) はタイトルにも及ぶ。
 *
 * **有効/無効を 1 テスト内で連続 GET しない**: SeoManager は scoped 束縛で、
 * forgetScopedInstances() を呼ぶのは queue worker と Octane だけ。Feature テストは
 * 1 つの application インスタンスを複数リクエストで使い回すため、先行リクエストの
 * setPrivateTitle が後続へ漏れる (php-fpm は毎リクエスト新しいコンテナ、Octane は
 * リクエスト間で scoped を破棄するので本番挙動ではない = テスト実行形態固有の制約)。
 * よって分岐ごとに独立したテストへ分け、それぞれ期待する固有名を直接固定する。
 */
test('無効な招待リンクは専用タイトルを返す (組織名は漏らさない)', function (): void {
    $organizationName = '秘匿対象組織';
    [$organization] = createOrganizationWithOwner($organizationName);

    $invitation = OrganizationInvitation::factory()->forOrganization($organization)->revoked()->create();
    $invitation->forceFill(['token_hash' => hash('sha256', 'invalid-title-token')])->save();

    $this->get('/invitations/accept?token=invalid-title-token')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($organizationName): void {
            $page->component('Invitations/Invalid');
            $title = $page->toArray()['props']['title'] ?? null;
            expect($title)->toBeString();
            // 無効分岐の固有タイトル (h1 から指示語「この」を落とした形)。
            // route 既定の「組織への招待」のままになる退行を落とす。
            expect($title)->toStartWith('招待リンクは使用できません');
            // 秘匿契約: タイトルにも組織名を混ぜない
            expect($title)->not->toContain($organizationName);
        });
});

test('有効な招待リンクの受諾確認画面は route 既定タイトルのまま', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = inviteAndCaptureToken($organization, $owner, 'valid-title@example.com', OrganizationRole::Admin);
    $invitee = User::factory()->create();

    $this->actingAs($invitee)
        ->get('/invitations/accept?token='.$token)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $page->component('Invitations/Accept');
            $title = $page->toArray()['props']['title'] ?? null;
            expect($title)->toBeString();
            // config('seo.app_titles')['invitations.accept'] = 「組織への招待」。
            // 無効分岐の上書きが有効分岐まで及ぶ退行を落とす。
            expect($title)->toStartWith('組織への招待');
        });
});

/*
 * register 経由の招待受諾 (session の invitation_token + email 照合)。
 */

test('招待 email で register すると個人組織を作らず招待組織へ参加する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('招待組織');
    $token = inviteAndCaptureToken($organization, $owner, 'newbie@example.com', OrganizationRole::Admin);

    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
        'name' => '新人 花子',
        'email' => 'newbie@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ]);

    $response->assertRedirect(route('verification.notice'));

    $user = User::whereBlind('email', 'email_index', 'newbie@example.com')->firstOrFail();
    // 招待組織へ参加し、招待ロールが付与される
    expect($organization->users()->whereKey($user->id)->exists())->toBeTrue();
    expect($user->organizationRole($organization))->toBe(OrganizationRole::Admin);
    // 個人組織は生成しない (招待組織を主所属にする)
    expect($user->organizations()->where('is_personal', true)->exists())->toBeFalse();
    // 招待は受諾済みになる & session の token は落ちる
    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeTrue();
    $response->assertSessionMissing('invitation_token');

    // [回帰固定] 招待成立で現在組織が招待先組織に確定する (登録直後・自己修復非依存)
    expect($user->current_organization_id)->toBe($organization->id);
});

test('招待経由登録の直後、dashboard 自己修復を経ずに共有プロップ currentOrganization が招待先を指す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('招待組織');
    $token = inviteAndCaptureToken($organization, $owner, 'header@example.com', OrganizationRole::Admin);

    $this->withSession(['invitation_token' => $token])->post('/register', [
        'name' => 'ヘッダー 確認',
        'email' => 'header@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ])->assertRedirect(route('verification.notice'));

    // verification.notice は未検証ユーザーが到達でき、CurrentOrganizationResolver の自己修復を
    // 通さない (dashboard 専用)。ここで共有プロップが招待先組織を指せば、ヘッダーが登録直後の
    // 全ページで一貫することを保証できる。
    $this->get(route('verification.notice'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('currentOrganization.id', $organization->id)
            ->where('currentOrganization.slug', $organization->slug)
            ->where('currentOrganization.role', OrganizationRole::Admin->value)
            // 共有プロップ間の整合: organizations 一覧にも招待先が載る
            ->where('organizations.0.id', $organization->id));
});

test('招待経由登録では個人組織を作らず signup grant を付与しない (増幅防止)', function (): void {
    // 招待経由は個人組織を作らず所属組織の残高を共有する。ここで付与すると招待 N 人 = N×10 の
    // 増幅になるため、signup grant は「個人組織を作る新規登録」時のみに限定する (LP CTA も同じ意図)。
    [$organization, $owner] = createOrganizationWithOwner('招待組織');
    $token = inviteAndCaptureToken($organization, $owner, 'nofree@example.com', OrganizationRole::Admin);

    $this->withSession(['invitation_token' => $token])->post('/register', [
        'name' => '無償なし 花子',
        'email' => 'nofree@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ])->assertRedirect(route('verification.notice'));

    $user = User::whereBlind('email', 'email_index', 'nofree@example.com')->firstOrFail();
    // 個人組織は生成されない
    expect($user->organizations()->where('is_personal', true)->exists())->toBeFalse();
    // 招待組織の残高に signup grant は乗らない
    // (P6/F2: 付与契機はプラン有効化時であり、登録では誰にも付与されない)
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
    expect(
        $organization->ticketLedgerEntries()
            ->where('idempotency_key', 'like', 'signup_grant:%')
            ->count(),
    )->toBe(0);
});

test('招待 email と異なる email で register すると email エラーになる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = inviteAndCaptureToken($organization, $owner, 'invited@example.com', OrganizationRole::Admin);

    $response = $this->withSession(['invitation_token' => $token])
        ->from('/register')
        ->post('/register', [
            'name' => '別人',
            'email' => 'someone-else@example.com',
            'password' => 'SecurePass1234',
            'terms_accepted' => '1',
        ]);

    $response->assertSessionHasErrors('email');
    $this->assertGuest();
    // 招待は未受諾のまま
    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeFalse();
});

test('取り消し済みの招待 token で register すると通常登録 (個人組織生成) に fallback する', function (): void {
    [$organization] = createOrganizationWithOwner();
    [$invitation, $token] = OrganizationInvitation::factory()
        ->forOrganization($organization)
        ->createWithPlainToken(['email' => 'fallback@example.com']);
    // 取り消し済み状態にする (HTTP 経路の acting user が register POST に漏れないよう直接失効)
    $invitation->forceFill(['revoked_at' => now()])->save();

    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
        'name' => 'フォールバック',
        'email' => 'fallback@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ]);

    $response->assertRedirect(route('verification.notice'));

    $user = User::whereBlind('email', 'email_index', 'fallback@example.com')->firstOrFail();
    // 招待組織へは参加せず、個人組織が生成される
    expect($organization->users()->whereKey($user->id)->exists())->toBeFalse();
    expect($user->organizations()->where('is_personal', true)->exists())->toBeTrue();

    // [分岐 B(fallback) 固定] 無効 token の fallback でも現在組織は個人組織 (招待先ではない)
    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
    expect($user->current_organization_id)->toBe($personalOrg->id);
});

/*
 * 役割付き招待の撤去 (裁定 AG-079)。招待は「組織に入れる」ことだけを意味する。
 */

test('招待送信の role が不正値ならカスタムメッセージ付き error bag になる (Enum ルールキー解決の回帰防止)', function (): void {
    Notification::fake();
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/invitations", [
        'email' => 'someone@example.com',
        'role' => 'superuser',
    ]);

    // messages() の 'role.'.Enum::class キーが実際に解決されることを固定する
    $response->assertSessionHasErrors([
        'role' => 'ロールの指定が不正です。画面を再読み込みしてやり直してください。',
    ]);
    Notification::assertNothingSent();
    expect(OrganizationInvitation::query()->count())->toBe(0);
});

test('招待の受諾は org 参加のみで Default Project の pivot を作らない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $token = inviteAndCaptureToken($organization, $owner, 'member@example.com', OrganizationRole::Member);

    $invitee = User::factory()->create();
    $this->actingAs($invitee)->post('/invitations/accept', ['token' => $token])
        ->assertRedirect('/dashboard');

    expect($invitee->organizationRole($organization))->toBe(OrganizationRole::Member);
    // 編集者 / 撮影者は参加後にロール割当コマンドで付与する (招待では付かない)
    expect($project->memberRole($invitee))->toBeNull();
});

test('招待は Default Project が無くても送信できる (撤去で消えた前提検査の回帰封じ)', function (): void {
    Notification::fake();
    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/invitations", [
        'email' => 'no-project@example.com',
        'role' => OrganizationRole::Member->value,
    ]);

    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('success');
    expect($organization->projects()->count())->toBe(0);
    expect(OrganizationInvitation::query()->count())->toBe(1);
});

/*
 * 受諾の冪等性 / 直列化 (ロック下再検証 + 原子的 INSERT の契約)。
 */

test('受諾済み招待で joinOrganization 相当に到達しても no-op (ロック下再検証の契約)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $token = inviteAndCaptureToken($organization, $owner, 'idempotent@example.com', OrganizationRole::Admin);

    $first = User::factory()->create();
    $this->actingAs($first)->post('/invitations/accept', ['token' => $token]);

    // 2 人目は事前検証 (isAccepted) で拒否される。受諾状態・membership が変化しないこと
    $second = User::factory()->create();
    $response = $this->actingAs($second)->post('/invitations/accept', ['token' => $token]);

    $response->assertSessionHas('error');
    expect($organization->users()->whereKey($second->id)->exists())->toBeFalse();
    expect($organization->users()->whereKey($first->id)->exists())->toBeTrue();
});

test('既 attach 状態での受諾は unique 違反にならず role を変更しない (insertOrIgnore 契約)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $token = inviteAndCaptureToken($organization, $owner, 'already@example.com', OrganizationRole::Member);

    // 招待送信後に別経路で org へ参加済み (organization_user 行あり + Admin ロール)
    $invitee = User::factory()->create();
    $organization->users()->attach($invitee);
    $invitee->addRole(OrganizationRole::Admin->value, $organization->laratrust_team_id);

    // Controller 経路は「既にメンバー」で弾かれるため、Service の joinOrganization 契約を直接検証する
    $invitation = OrganizationInvitation::query()->sole();
    $method = new ReflectionMethod(OrganizationMembershipService::class, 'joinOrganization');
    $joined = $method->invoke(
        app(OrganizationMembershipService::class),
        $invitation,
        $organization,
        $invitee,
        OrganizationRole::Member,
    );

    // ロック下再検証を通ったので true (既 join の冪等 no-op も「変換完了」に含む)
    expect($joined)->toBeTrue();
    // 500 (unique 違反) にならず、既存 role は温存・pivot も付与されない
    expect($invitee->fresh()->organizationRole($organization))->toBe(OrganizationRole::Admin);
    expect($project->memberRole($invitee))->toBeNull();
    // 招待は受諾済みになる (再利用不能)
    expect($invitation->refresh()->isAccepted())->toBeTrue();
});
