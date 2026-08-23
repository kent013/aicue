<?php

declare(strict_types=1);

use App\DataTransferObjects\EnterpriseSso\VerifiedIdTokenClaims;
use App\Enums\OrganizationRole;
use App\Models\EnterpriseIdentity;
use App\Models\Organization;
use App\Models\OrganizationOidcConnection;
use App\Models\User;
use App\Services\EnterpriseSso\EnterpriseUserProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * 初回ログインでの利用者の自動作成 (C1 / always-JIT)。
 *
 * ★本サービスは **C2 が張った接続の行ロックの中**で呼ばれる前提なので、
 *   テストも同じ形 (トランザクションの中) で呼ぶ。
 */

function provision(OrganizationOidcConnection $connection, string $subject, ?string $email = null, ?string $name = null): User
{
    $claims = VerifiedIdTokenClaims::of(
        issuer: $connection->issuer,
        subject: $subject,
        claimedEmail: $email,
        name: $name,
        maxSubjectLength: 255,
    );

    return DB::transaction(fn (): User => app(EnterpriseUserProvisioner::class)->resolve($connection, $claims));
}

test('初回で利用者・身元・所属が 1 件ずつできる', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();

    $user = provision($connection, 'sub-1', 'worker@corp.example', '現場 太郎');

    expect($connection->identities()->count())->toBe(1);
    expect($user->name)->toBe('現場 太郎');
    expect(DB::table('organization_user')
        ->where('organization_id', $connection->organization_id)
        ->where('user_id', $user->id)
        ->count())->toBe(1);
});

test('作られた利用者はメールを持たず、確認済みで、パスワードも持たない', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();

    $user = provision($connection, 'sub-1', 'worker@corp.example');

    expect($user->email)->toBeNull();
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->password)->toBeNull();
    // 既存の verified middleware の意味論を変えずに通る
    expect($user->hasVerifiedEmail())->toBeTrue();
});

test('name claim が無ければ表示用の既定値になる', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();

    expect(provision($connection, 'sub-1')->name)->toBe('未設定');
});

test('役割は Member で、接続が属する組織の team id で付与される', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();
    /** @var Organization $organization */
    $organization = $connection->organization;

    $user = provision($connection, 'sub-1');

    expect($user->fresh()?->organizationRole($organization))->toBe(OrganizationRole::Member);
});

test('別組織の役割が参照されない (team id を明示していることの実挙動)', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();
    $otherOrganization = Organization::factory()->create();

    $user = provision($connection, 'sub-1');

    expect($user->fresh()?->organizationRole($otherOrganization))->toBeNull();
});

test('2 回目のログインでは同じ利用者へ結び付き、身元は増えない', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();

    $first = provision($connection, 'sub-1');
    $second = provision($connection, 'sub-1');

    expect($second->id)->toBe($first->id);
    expect($connection->identities()->count())->toBe(1);
    expect(User::query()->count())->toBe(1);
});

test('2 回目のログインで最終ログイン時刻が打刻される', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();

    provision($connection, 'sub-1');
    /** @var EnterpriseIdentity $identity */
    $identity = $connection->identities()->firstOrFail();
    $identity->forceFill(['last_login_at' => null])->save();

    provision($connection, 'sub-1');

    expect($connection->identities()->firstOrFail()->last_login_at)->not->toBeNull();
});

test('同じ申告メールでも subject が違えば別の利用者になる (メールで引かない)', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();

    $first = provision($connection, 'sub-1', 'shared@corp.example');
    $second = provision($connection, 'sub-2', 'shared@corp.example');

    expect($second->id)->not->toBe($first->id);
    expect(User::query()->count())->toBe(2);
});

test('大文字小文字が違う subject は別の利用者になる', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();

    $lower = provision($connection, 'alice');
    $upper = provision($connection, 'Alice');

    expect($upper->id)->not->toBe($lower->id);
});

test('接続が違えば同じ subject でも別の利用者になる (身元の名前空間は接続ごと)', function (): void {
    $first = OrganizationOidcConnection::factory()->create();
    $second = OrganizationOidcConnection::factory()->create();

    expect(provision($second, 'sub-1')->id)->not->toBe(provision($first, 'sub-1')->id);
});

test('一意制約違反を握り潰さない (負のコントロール)', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();
    $user = User::factory()->create();

    // 先に同じ (接続, subject) の身元を別の利用者で作っておく
    EnterpriseIdentity::factory()->create([
        'organization_oidc_connection_id' => $connection->id,
        'user_id' => $user->id,
        'subject' => 'sub-1',
    ]);

    // 引き当てが効くので通常は例外にならない
    expect(provision($connection, 'sub-1')->id)->toBe($user->id);

    // ★引き当てを潰した状態で作らせると、例外が**そのまま伝播する**
    //   (握り潰すと「直列化が壊れた」という重大な事実が競合として隠れる)
    expect(fn () => DB::transaction(function () use ($connection, $user): void {
        DB::table('enterprise_identities')->insert([
            'organization_oidc_connection_id' => $connection->id,
            'user_id' => $user->id,
            'subject' => 'sub-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }))->toThrow(QueryException::class);
});

test('失敗した側に孤児の利用者が残らない (同一トランザクションで巻き戻る)', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();
    $usersBefore = User::query()->count();

    try {
        DB::transaction(function () use ($connection): void {
            provisionInsideFailingTransaction($connection);
        });
    } catch (RuntimeException) {
        // 途中で落ちる
    }

    expect(User::query()->count())->toBe($usersBefore);
    expect($connection->identities()->count())->toBe(0);
});

function provisionInsideFailingTransaction(OrganizationOidcConnection $connection): void
{
    $claims = VerifiedIdTokenClaims::of(
        issuer: $connection->issuer,
        subject: 'sub-fail',
        claimedEmail: null,
        name: null,
        maxSubjectLength: 255,
    );

    app(EnterpriseUserProvisioner::class)->resolve($connection, $claims);

    throw new RuntimeException('後段で失敗した');
}
