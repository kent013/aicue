<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Enums\SecurityEventType;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Project;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use PragmaRX\Google2FA\Google2FA;
use Webmozart\Assert\Assert;

/*
 * 管理メニュー > ユーザー管理 (GET /manage/users)。
 * 読み取り専用画面 (書き込みは既存 organizations.* endpoint)。
 * PII (email) の可視性契約: manageMembers 権限者しか画面自体に到達できない (403 境界)。
 *
 * T203: 各行の lastLoginAt (最終ログイン日時) は users の列ではなく
 * security_audit_events の login 行から導出する (App\Services\Security\LastLoginLookup)。
 * 「何を数えるか」の主張は 1 つずつテストに対応させる (詳細設計 §数える経路の確定)。
 */

/**
 * owner として /manage/users を開き、利用者 id → lastLoginAt (ISO8601 or null) の写像を返す。
 *
 * @return array<int, string|null>
 */
function fetchMemberLastLogins(Organization $organization, User $viewer): array
{
    $response = test()->actingAs($viewer)->get("/organizations/{$organization->slug}/manage/users");
    $response->assertOk();

    /** @var list<array{id: int, lastLoginAt: string|null}> $members */
    $members = AssertableInertia::fromTestResponse($response)->toArray()['props']['members'];

    $map = [];
    foreach ($members as $row) {
        $map[$row['id']] = $row['lastLoginAt'];
    }

    return $map;
}

/** ある利用者の login 行の件数 */
function loginRowCountFor(User $user): int
{
    return SecurityAuditEvent::query()
        ->where('user_id', $user->id)
        ->where('event_type', SecurityEventType::Login->value)
        ->count();
}

test('org Owner は 200 + Admin/Users component で members/invitations shape を受け取る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    OrganizationInvitation::factory()->forOrganization($organization)
        ->create(['email' => 'pending-member@example.com', 'role' => OrganizationRole::Member->value]);

    $response = $this->actingAs($owner)->get("/organizations/{$organization->slug}/manage/users");

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Admin/Users')
        ->where('organizationSlug', $organization->slug)
        ->where('members.0.roleState', 'owner')
        ->where('members.0.isSelf', true)
        ->where('invitations.0.email', 'pending-member@example.com')
        ->where('invitations.0.role', OrganizationRole::Member->value)
        ->where('invitations.0.roleLabel', 'メンバー')
        ->where('hasDefaultProject', false)
        // T071: 独自二次左メニュー(AdminMenuNav)撤去に伴い categoriesUrl prop は廃止 → 存在しない
        ->missing('categoriesUrl'));
});

test('org Admin も閲覧できる (200)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);

    $this->actingAs($admin)->get("/organizations/{$organization->slug}/manage/users")->assertOk();
});

test('org Member (編集者 = project_admin でも org は Member) は 403', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $editor = attachOrganizationMember($organization);
    attachProjectMember($project, $editor, ProjectRole::Admin);

    $this->actingAs($editor)->get("/organizations/{$organization->slug}/manage/users")->assertForbidden();
});

// CTA 導線の到達性 (T067): ユーザー管理注記の「プロジェクトを作成」リンクが指す
// projects.create に、ユーザー管理を見られる owner/admin は到達でき、見られない member は
// 到達できない (403) ことを HTTP レベルで固定する。CTA が 403 で詰まらない不変条件を守る。
test('CTA 導線: Owner/Admin は projects.create に到達できる (200)', function (): void {
    // createOrganizationWithOwner は無償プラン (plan_code null) = 課金ゲート通過
    [$organization, $owner] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/create")->assertOk();
    $this->actingAs($admin)->get("/organizations/{$organization->slug}/projects/create")->assertOk();
});

test('CTA 導線: org Member は projects.create で 403 (権限境界が非退化)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization, OrganizationRole::Member);

    $this->actingAs($member)->get("/organizations/{$organization->slug}/projects/create")->assertForbidden();
});

test('未ログインは login へ redirect される', function (): void {
    $this->get('/organizations/guest-org/manage/users')->assertRedirect('/login');
});

test('roleState 導出: owner/admin/editor/shooter/unassigned の 5 状態が rows に正しく出る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $editor = attachOrganizationMember($organization);
    attachProjectMember($project, $editor, ProjectRole::Admin);
    $shooter = attachOrganizationMember($organization);
    attachProjectMember($project, $shooter, ProjectRole::Member);
    $unassigned = attachOrganizationMember($organization);
    // Laratrust ロール未付与の異常行 (attach のみ) も「未割当」として表示される
    $broken = User::factory()->create();
    $organization->users()->attach($broken);

    $response = $this->actingAs($owner)->get("/organizations/{$organization->slug}/manage/users");

    $response->assertOk();
    $response->assertInertia(function ($page) use ($owner, $admin, $editor, $shooter, $unassigned, $broken): void {
        /** @var list<array{id: int, roleState: string}> $members */
        $members = $page->toArray()['props']['members'];
        $states = [];
        foreach ($members as $row) {
            $states[$row['id']] = $row['roleState'];
        }
        expect($states[$owner->id])->toBe('owner');
        expect($states[$admin->id])->toBe('admin');
        expect($states[$editor->id])->toBe('editor');
        expect($states[$shooter->id])->toBe('shooter');
        expect($states[$unassigned->id])->toBe('unassigned');
        expect($states[$broken->id])->toBe('unassigned');
    });
});

test('categoriesUrl prop は撤去済み (T071: カテゴリ導線はプロジェクト詳細へ移設)。hasDefaultProject は維持', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    // AdminMenuNav 撤去に伴い categoriesUrl prop は存在しない (project 有無に関わらず)
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/manage/users")
        ->assertInertia(fn ($page) => $page->missing('categoriesUrl')->where('hasDefaultProject', false));

    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/manage/users")
        ->assertInertia(fn ($page) => $page
            ->where('hasDefaultProject', true)
            ->missing('categoriesUrl'));
});

test('招待一覧は active のみ (失効・受諾済・取消済は出ない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    OrganizationInvitation::factory()->forOrganization($organization)->create(['email' => 'active@example.com']);
    OrganizationInvitation::factory()->forOrganization($organization)->expired()->create(['email' => 'expired@example.com']);
    OrganizationInvitation::factory()->forOrganization($organization)->accepted()->create(['email' => 'accepted@example.com']);
    OrganizationInvitation::factory()->forOrganization($organization)->revoked()->create(['email' => 'revoked@example.com']);

    $response = $this->actingAs($owner)->get("/organizations/{$organization->slug}/manage/users");

    $response->assertInertia(fn ($page) => $page
        ->count('invitations', 1)
        ->where('invitations.0.email', 'active@example.com')
        // 招待は org ロールで表示される (役割付き招待は AG-079 で撤去)
        ->where('invitations.0.role', OrganizationRole::Member->value)
        ->where('invitations.0.roleLabel', 'メンバー'));
});

test('非所属の組織 URL は 404 (組織の有無を露出しない)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $user = User::factory()->create();

    $this->actingAs($user)->get("/organizations/{$organization->slug}/manage/users")->assertNotFound();
});

// ─────────────────── T203: 最終ログイン日時 (lastLoginAt) ───────────────────

test('login 記録のあるメンバーは lastLoginAt に ISO8601 (オフセット付き) が載る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    // occurred_at はタイムゾーンを持たない timestamp 列なので、期待値もアプリ既定 tz で作る
    // (この検査の対象は「props にオフセット付きで出るか」であって列の tz 保持ではない)
    $at = CarbonImmutable::parse('2026-05-04 10:08:00');
    SecurityAuditEvent::factory()->forUser($member)->occurredAt($at)->create();

    $lastLogins = fetchMemberLastLogins($organization, $owner);

    // toDateTimeString() への退行 (オフセット欠落 = 端末側で 9 時間ずれる) を検出する
    expect($lastLogins[$member->id])->toBe($at->toIso8601String());
    expect($lastLogins[$member->id])->toMatch('/(Z|[+-]\d{2}:\d{2})$/');
});

test('login 記録の無いメンバーは lastLoginAt が null', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    expect(fetchMemberLastLogins($organization, $owner)[$member->id])->toBeNull();
});

test('複数の login 行があれば最新が選ばれる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    $latest = CarbonImmutable::now()->subDay();
    SecurityAuditEvent::factory()->forUser($member)->occurredAt(CarbonImmutable::now()->subMonthsNoOverflow(3))->create();
    SecurityAuditEvent::factory()->forUser($member)->occurredAt($latest)->create();
    SecurityAuditEvent::factory()->forUser($member)->occurredAt(CarbonImmutable::now()->subYearNoOverflow())->create();

    expect(fetchMemberLastLogins($organization, $owner)[$member->id])->toBe($latest->toIso8601String());
});

test('login 以外の種別は数えない (logout / login_failed / password_changed)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    foreach ([SecurityEventType::Logout, SecurityEventType::LoginFailed, SecurityEventType::PasswordChanged] as $type) {
        SecurityAuditEvent::factory()->forUser($member)->ofType($type)->create();
    }

    expect(fetchMemberLastLogins($organization, $owner)[$member->id])->toBeNull();
});

test('他組織のメンバーの login 行は混ざらない (cross-org)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    [$otherOrganization, $otherOwner] = createOrganizationWithOwner('別の組織');
    $otherMember = attachOrganizationMember($otherOrganization);
    $otherAt = CarbonImmutable::now()->subDays(2);
    SecurityAuditEvent::factory()->forUser($otherMember)->occurredAt($otherAt)->create();
    SecurityAuditEvent::factory()->forUser($otherOwner)->occurredAt($otherAt)->create();

    $lastLogins = fetchMemberLastLogins($organization, $owner);

    // 当組織の一覧に他組織の利用者は現れず、当組織の行も他組織の値を貰わない
    expect(array_keys($lastLogins))->not->toContain($otherMember->id);
    expect(array_keys($lastLogins))->not->toContain($otherOwner->id);
    expect($lastLogins[$member->id])->toBeNull();
});

test('実際のログイン (POST /login) で lastLoginAt に値が入る (記録経路の通し確認)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    expect(loginRowCountFor($member))->toBe(0);

    $this->post('/login', ['email' => $member->email, 'password' => 'password'])
        ->assertRedirect();
    $this->assertAuthenticatedAs($member);

    expect(loginRowCountFor($member))->toBe(1);

    // 閲覧は owner として行う (member は manageMembers を持たず 403 になるため)
    $this->flushSession();
    expect(fetchMemberLastLogins($organization, $owner)[$member->id])->not->toBeNull();
});

test('remember me による自動復元も数える (StampRecentAuthOnLogin とは逆の扱い)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);

    // 1 回目: 資格情報を提示したログイン (remember 付き)
    $this->post('/login', [
        'email' => $member->email,
        'password' => 'password',
        'remember' => 'on',
    ])->assertRedirect();
    expect(loginRowCountFor($member))->toBe(1);

    $rememberToken = $member->fresh()?->getRememberToken();
    expect($rememberToken)->toBeString();

    // セッションを捨て、recaller cookie だけで戻る (viaRemember の実経路)。
    // forgetGuards も要る — テストプロセスでは guard が解決済み User を保持したままで、
    // session を空にしただけでは SessionGuard::user() が早期 return して recaller を踏まない
    $recallerName = Auth::guard('web')->getRecallerName();
    $this->flushSession();
    Auth::forgetGuards();

    // 2 回目を別時刻にする。同時刻だと「recaller 行を除外しても 1 回目の値が残って緑」に
    // なり、除外への退行を検出できない (値が recaller 行のものであることまで固定する)
    $this->travel(30)->minutes();
    $this->withCookie(
        $recallerName,
        $member->id.'|'.$rememberToken.'|'.$member->getAuthPassword(),
    )->get("/organizations/{$organization->slug}/dashboard");
    $this->travelBack();

    $this->assertAuthenticatedAs($member);
    // recaller 復元でも監査行が増える = 「最後に入ったのはいつか」に反映される
    expect(loginRowCountFor($member))->toBe(2);

    /** @var list<SecurityAuditEvent> $rows */
    $rows = SecurityAuditEvent::query()
        ->where('user_id', $member->id)
        ->where('event_type', SecurityEventType::Login->value)
        ->orderBy('id')
        ->get()
        ->all();
    $credentialRow = $rows[0]->getAttribute('occurred_at');
    $recallerRow = $rows[1]->getAttribute('occurred_at');
    Assert::isInstanceOf($credentialRow, Carbon::class);
    Assert::isInstanceOf($recallerRow, Carbon::class);
    expect($recallerRow->toIso8601String())->not->toBe($credentialRow->toIso8601String());

    // props は **recaller 行の時刻**になる (1 回目の時刻のままなら除外への退行)
    $this->flushSession();
    Auth::forgetGuards();
    expect(fetchMemberLastLogins($organization, $owner)[$member->id])->toBe($recallerRow->toIso8601String());
});

test('2FA 未完了 (challenge 手前) では数えず、完了させると数える', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    /** 2FA 準拠のメンバーを 1 人作る (パスワードは UserFactory 既定) */
    $addTwoFactorMember = function () use ($organization): User {
        $member = User::factory()->withTwoFactor()->create();
        $organization->users()->attach($member);
        $member->addRole(OrganizationRole::Member->value, $organization->laratrust_team_id);

        return $member;
    };

    $pending = $addTwoFactorMember();
    $completed = $addTwoFactorMember();

    // パスワードだけ通過した時点ではセッションが確立していない = まだ入っていない
    $this->post('/login', ['email' => $pending->email, 'password' => 'password'])
        ->assertRedirect('/two-factor-challenge');
    expect(loginRowCountFor($pending))->toBe(0);

    // 対照: チャレンジまで完了させると login 行が生まれる
    $this->flushSession();
    Auth::forgetGuards();
    $this->post('/login', ['email' => $completed->email, 'password' => 'password'])
        ->assertRedirect('/two-factor-challenge');
    $secret = decrypt((string) $completed->fresh()?->two_factor_secret);
    $this->post('/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
        ->assertRedirect();
    expect(loginRowCountFor($completed))->toBe(1);

    $this->flushSession();
    Auth::forgetGuards();
    $lastLogins = fetchMemberLastLogins($organization, $owner);
    expect($lastLogins[$pending->id])->toBeNull();
    expect($lastLogins[$completed->id])->not->toBeNull();
});

test('Filament 管理画面 (admin guard) のログインは混ざらない', function (): void {
    [, $owner] = createOrganizationWithOwner();
    $adminUser = AdminUser::factory()->create();

    $before = SecurityAuditEvent::query()
        ->where('event_type', SecurityEventType::Login->value)
        ->whereNotNull('user_id')
        ->count();

    Auth::guard('admin')->login($adminUser);

    // AdminUser は App\Models\User の派生ではないため asUser() が null に丸める =
    // user_id の付いた login 行は 1 件も増えない (構造での保証)
    expect(SecurityAuditEvent::query()
        ->where('event_type', SecurityEventType::Login->value)
        ->whereNotNull('user_id')
        ->count())->toBe($before);

    // owner 自身の行にも影響しない
    expect(fetchMemberLastLogins($organization, $owner)[$owner->id])->toBeNull();
});

test('招待経由で参加した利用者は参加 (登録の自動ログイン) 時刻が最終ログインになる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [, $token] = OrganizationInvitation::factory()
        ->forOrganization($organization)
        ->createWithPlainToken(['email' => 'invitee@example.com']);

    // 未ログインで招待 URL を開くと token が session へ退避され register へ誘導される
    $this->get('/invitations/accept?token='.$token)->assertRedirect(route('register'));
    $this->assertGuest();

    // 登録 = 自動ログイン。受諾そのものは Login を発火しないので、ここが「参加した時刻」になる
    $this->post('/register', [
        'name' => '招待 花子',
        'email' => 'invitee@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ])->assertRedirect();

    $invitee = User::whereBlind('email', 'email_index', 'invitee@example.com')->firstOrFail();
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeTrue();

    // 参加直後の本人は org Member = /manage/users は 403 なので owner として確認する
    $this->flushSession();
    expect(fetchMemberLastLogins($organization, $owner)[$invitee->id])->not->toBeNull();
});
