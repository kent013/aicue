<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;

/*
 * 招待元組織の論理削除は「無効招待」へ同一畳み込みされる (正典 v1 i7 / aicue:T263 施策 A)。
 *
 * - 論理削除済み組織宛の招待は GET/POST/register のどの経路でも 500 にならず、
 *   不在・取消済みと**同一の応答** (Invitations/Invalid / 中立メッセージ / fallback) に畳む
 *   (理由の出し分けを増やさない = 存在オラクルを作らない)。
 * - guest GET では畳み込みを session 保存より**前**に行う (token が session に入ると
 *   register prefill に宛先が出た上で登録 POST が失敗する二段障害になる)。
 *
 * ## ロック下最終再検証 (joinOrganization 1c) の検証は 3 分割 (保証範囲を混同しない)
 * 1. **状態注入テスト**: 事前検証の通過後 (招待行 FOR UPDATE の直前) に組織を論理削除する
 *    one-shot 注入で、最終再検証が削除を受諾不能へ畳むこと (= false の消費契約) を固定する。
 * 2. **SQL 形状 assert**: 注入後に実行される organizations への問い合わせが
 *    「SoftDeletes 条件 + FOR UPDATE + 対象 organization id」を満たすことを固定する。
 *    状態注入だけでは 1c を非ロック読みへ退行させても自トランザクションの更新が見えて
 *    緑になるため、ロック読みであることは形状で固定する。
 * 3. **保証外**: 別接続を使った DB エンジン固有の MVCC スケジュールの完全再現は保証しない
 *    (RefreshDatabase 下では別接続からテストデータが見えず構造的に不可能。また one-shot の
 *    注入時点では組織行ロックが取得済みのため実際の競合順序の再現でもない —
 *    これは「消費契約の決定的検証」であって「競合の再現」ではない)。
 */

/**
 * 指定組織宛の active 招待を作り、平文 token とともに返す (factory ベース。
 * メール送信経路は InvitationTest 側が固定済みのためここでは通さない)。
 *
 * @return array{0: OrganizationInvitation, 1: string}
 */
function makeDeletableOrgInvitation(Organization $organization, string $email, OrganizationRole $role = OrganizationRole::Member): array
{
    return OrganizationInvitation::factory()
        ->forOrganization($organization)
        ->createWithPlainToken(['email' => $email, 'role' => $role->value]);
}

/**
 * joinOrganization がロック下再検証のために発行する招待行の SELECT ... FOR UPDATE を検出し、
 * その直前に招待元組織を論理削除する (one-shot 注入)。
 *
 * 状態は**二段階**で管理する (InvitationAcceptRaceTest の家風の拡張):
 *  - $injected: 注入は 1 回だけ。ただし注入後も callback を inert にせず、
 *    続けて実行される organizations への SELECT を $organizationQueries へ記録する
 *    (1c の SQL 形状検査用。注入で callback 全体を inert にすると 1c の SQL を記録できない)。
 *  - id は必ず placeholder になるため bindings 側で対象 id を確認する。
 *
 * **DB::beforeExecuting() の callback は解除できない**ため、注入は $injected で恒久的に
 * 一度きりになる設計にしてある (記録側は追記のみで副作用なし)。
 *
 * @param  list<array{sql: string, bindings: array<int, mixed>}>  $organizationQueries
 */
function deleteOrganizationOnLockedInvitationRead(int $invitationId, int $organizationId, array &$organizationQueries): void
{
    $injected = false;
    DB::beforeExecuting(function (string $query, array $bindings) use ($invitationId, $organizationId, &$injected, &$organizationQueries): void {
        $lower = strtolower($query);

        if ($injected) {
            // 注入後: organizations への SELECT を記録する (1c のロック読み形状の検査用)。
            // 注入 UPDATE 自身や users のロック読みは対象外 (select + "organizations" で絞る)
            if (str_starts_with($lower, 'select') && str_contains($lower, '"organizations"')) {
                $organizationQueries[] = ['sql' => $lower, 'bindings' => $bindings];
            }

            return;
        }

        if (! str_contains($lower, 'organization_invitations') || ! str_contains($lower, 'for update')) {
            return;
        }
        $stringBindings = array_map(static fn (mixed $b): string => is_scalar($b) ? (string) $b : '', $bindings);
        if (! in_array((string) $invitationId, $stringBindings, true)) {
            return;
        }

        // 記録より先に立てる (自分の UPDATE による再入を注入分岐へ入れない)
        $injected = true;
        // 同一接続・同一トランザクション内なので自分のロックと競合しない
        DB::table('organizations')->where('id', $organizationId)->update(['deleted_at' => now()]);
    });
}

test('guest + 論理削除組織の招待リンク GET は Invalid を返し token を session に入れない', function (): void {
    [$organization] = createOrganizationWithOwner('消える組織');
    [, $token] = makeDeletableOrgInvitation($organization, 'ghost@example.com');

    $organization->delete();

    $response = $this->get('/invitations/accept?token='.$token);

    // 不在・取消済みと同一の専用ページ (理由は出し分けない)。register への誘導もしない
    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('Invitations/Invalid'));
    // 畳み込みは guest 分岐より前 = token は session に入らない (二段障害の再発防止)
    $response->assertSessionMissing('invitation_token');
});

test('ログイン済み (宛先一致) + 論理削除組織の GET は 500 ではなく Invalid を返す', function (): void {
    [$organization] = createOrganizationWithOwner('消える組織');
    [, $token] = makeDeletableOrgInvitation($organization, 'invitee@example.com');
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);

    $organization->delete();

    $response = $this->actingAs($invitee)->get('/invitations/accept?token='.$token);

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page) => $page->component('Invitations/Invalid'));
});

test('ログイン済み (宛先一致) + 論理削除組織の POST 受諾は不在 token と同一の中立メッセージで差し戻す', function (): void {
    [$organization] = createOrganizationWithOwner('消える組織');
    [, $token] = makeDeletableOrgInvitation($organization, 'invitee@example.com');
    $invitee = User::factory()->create(['email' => 'invitee@example.com']);

    $organization->delete();

    $response = $this->actingAs($invitee)->post('/invitations/accept', ['token' => $token]);

    // 500 にせず、不在 token と同一の文言で app.entry へ差し戻す (理由を開示しない)
    $response->assertRedirect(route('app.entry'));
    $response->assertSessionHas('error', 'この招待は無効です。');
    expect(DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $invitee->id)->exists())->toBeFalse();
    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeFalse();
});

test('論理削除組織の招待 token を session に持つ register POST は個人組織へ fallback し招待は未受諾のまま', function (): void {
    [$organization] = createOrganizationWithOwner('消える組織');
    [, $token] = makeDeletableOrgInvitation($organization, 'fallback@example.com');

    $organization->delete();

    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
        'name' => 'フォールバック 花子',
        'email' => 'fallback@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ]);

    // 登録そのものは成功する (500 にしない)
    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();

    $user = User::whereBlind('email', 'email_index', 'fallback@example.com')->firstOrFail();
    // 削除済み組織へは参加せず、個人組織が 1 件だけ作られる
    expect(DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $user->getKey())->exists())->toBeFalse();
    expect($user->organizations()->count())->toBe(1);
    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeFalse();
});

test('acceptInvitation: 事前検証通過後の論理削除はロック下再検証 1c が受諾不能へ畳む (SQL 形状も固定)', function (): void {
    [$organization] = createOrganizationWithOwner('消える組織');
    [$invitation, $token] = makeDeletableOrgInvitation($organization, 'race@example.com');
    $invitee = User::factory()->create(['email' => 'race@example.com']);

    /** @var list<array{sql: string, bindings: array<int, mixed>}> $organizationQueries */
    $organizationQueries = [];
    deleteOrganizationOnLockedInvitationRead($invitation->id, $organization->id, $organizationQueries);

    $thrown = null;
    try {
        app(OrganizationMembershipService::class)->acceptInvitation($token, $invitee);
    } catch (ValidationException $exception) {
        $thrown = $exception;
    }

    // 事前検証 (findActiveByPlainToken / 早期照合) は生存組織で通過し、1c が中立メッセージへ畳む
    expect($thrown)->not->toBeNull();
    expect($thrown?->errors()['token'][0] ?? null)->toBe('この招待は無効です。');
    expect(DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $invitee->id)->exists())->toBeFalse();
    expect($invitation->refresh()->isAccepted())->toBeFalse();

    // 1c の SQL 形状: 非ロック読みへ退行させると自トランザクションの更新が見えて状態注入では
    // 緑のままになるため、「SoftDeletes 条件 + FOR UPDATE + 対象 organization id」を形状で固定する
    $lockedRead = array_filter(
        $organizationQueries,
        function (array $query) use ($organization): bool {
            $bindings = array_map(static fn (mixed $b): string => is_scalar($b) ? (string) $b : '', $query['bindings']);

            return str_contains($query['sql'], '"deleted_at" is null')
                && str_contains($query['sql'], 'for update')
                && in_array((string) $organization->id, $bindings, true);
        },
    );
    expect($lockedRead)->not->toBeEmpty();
});

test('register POST: 事前検証通過後の論理削除は fallback 登録になり verified を与えない', function (): void {
    [$organization] = createOrganizationWithOwner('消える組織');
    [$invitation, $token] = makeDeletableOrgInvitation($organization, 'race-register@example.com');

    /** @var list<array{sql: string, bindings: array<int, mixed>}> $organizationQueries */
    $organizationQueries = [];
    deleteOrganizationOnLockedInvitationRead($invitation->id, $organization->id, $organizationQueries);

    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
        'name' => 'レース 太郎',
        'email' => 'race-register@example.com',
        'password' => 'SecurePass1234',
        'terms_accepted' => '1',
    ]);

    // acceptInvitationIfValid は 1c の敗北で null → 登録は個人組織 fallback で成立する
    $response->assertSessionHasNoErrors();
    $this->assertAuthenticated();

    $user = User::whereBlind('email', 'email_index', 'race-register@example.com')->firstOrFail();
    expect(DB::table('organization_user')->where('organization_id', $organization->id)->where('user_id', $user->getKey())->exists())->toBeFalse();
    expect($user->organizations()->count())->toBe(1);
    expect($invitation->refresh()->isAccepted())->toBeFalse();
    // 受諾不能の fallback 登録は unverified のまま (i16 後段の fail-closed と対称)
    expect($user->email_verified_at)->toBeNull();
});

test('負のコントロール: 生存組織では同条件で受諾が成立する (畳み込みの誤爆がない)', function (): void {
    [$organization] = createOrganizationWithOwner('生きている組織');
    [, $token] = makeDeletableOrgInvitation($organization, 'alive@example.com');
    $invitee = User::factory()->create(['email' => 'alive@example.com']);

    // GET は受諾確認画面 (Invalid に誤爆しない)
    $this->actingAs($invitee)->get('/invitations/accept?token='.$token)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Invitations/Accept'));

    // POST は参加が成立する
    $response = $this->actingAs($invitee)->post('/invitations/accept', ['token' => $token]);

    $response->assertRedirect(route('dashboard', ['organization' => $organization->slug]));
    expect($organization->users()->whereKey($invitee->id)->exists())->toBeTrue();
    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeTrue();
});
