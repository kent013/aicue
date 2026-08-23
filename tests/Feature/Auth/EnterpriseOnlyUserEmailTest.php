<?php

declare(strict_types=1);

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Auth\EncryptedUserProvider;
use App\DataTransferObjects\Admin\MemberRowData;
use App\Models\User;
use App\Notifications\EmailChangedSecurityNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * 企業 SSO でしか入れない利用者のメールアドレス (A3)。
 *
 * ★`email = null` かつ `email_verified_at != null` は
 *   「IdP が本人確認した。**確認すべきメールが無い**」の意味である。
 *   既存の `verified` middleware の意味論を変えずに通すための形である。
 */

function enterpriseOnlyUser(): User
{
    $user = User::factory()->create();
    $user->forceFill(['email' => null, 'email_verified_at' => now(), 'password' => null])->save();

    return $user->fresh() ?? $user;
}

test('メールを持たない利用者を複数作れる (blind index が衝突しない)', function (): void {
    $first = enterpriseOnlyUser();
    $second = enterpriseOnlyUser();

    expect($first->email)->toBeNull();
    expect($second->email)->toBeNull();
    expect($first->id)->not->toBe($second->id);
});

test('メールを持たない利用者は email_index の blind index 行を持たない', function (): void {
    $user = enterpriseOnlyUser();

    expect(DB::table('blind_indexes')
        ->where('indexable_type', User::class)
        ->where('indexable_id', $user->id)
        ->where('name', User::EMAIL_BLIND_INDEX)
        ->count())->toBe(0);
});

test('メールを null へ戻すと古い blind index 行が消える (旧メールで引けない)', function (): void {
    $user = User::factory()->create(['email' => 'old@corp.example']);

    expect(DB::table('blind_indexes')
        ->where('indexable_id', $user->id)
        ->where('name', User::EMAIL_BLIND_INDEX)
        ->count())->toBe(1);

    $user->forceFill(['email' => null])->save();

    expect(DB::table('blind_indexes')
        ->where('indexable_id', $user->id)
        ->where('name', User::EMAIL_BLIND_INDEX)
        ->count())->toBe(0);
});

test('メールを持たない利用者も verified middleware を通る', function (): void {
    $user = enterpriseOnlyUser();

    expect($user->hasVerifiedEmail())->toBeTrue();

    // ★**メール確認の催促画面へ送られない**ことが要点である
    //   (所属組織が無ければ組織作成へ導かれるが、それは verified とは別の関門である)。
    $this->actingAs($user)->get(route('app.entry'))
        ->assertRedirectContains(route('organizations.create'));
});

test('メールでのログイン解決が null 行に当たらない', function (): void {
    enterpriseOnlyUser();

    /** @var EncryptedUserProvider $provider */
    $provider = auth()->createUserProvider('users');

    expect($provider->retrieveByCredentials(['email' => '']))->toBeNull();
    expect($provider->retrieveByCredentials(['email' => 'nobody@corp.example']))->toBeNull();
});

test('メールを持たない利用者にはメール通知の宛先が無い', function (): void {
    $user = enterpriseOnlyUser();

    expect($user->routeNotificationFor('mail'))->toBeNull();
});

test('昇格以外の経路で email を入れると email_verified_at が消える', function (): void {
    $user = enterpriseOnlyUser();

    app(UpdateUserProfileInformation::class)->update($user, [
        'name' => $user->name,
        'email' => 'new@corp.example',
    ]);

    $fresh = $user->fresh();
    expect($fresh?->email)->toBe('new@corp.example');
    // ★自動で確認済みにならない (新しいメールは確認し直す)
    expect($fresh?->email_verified_at)->toBeNull();
});

test('旧メールが無くても プロフィール更新が落ちない (旧アドレスへの通知だけが送られない)', function (): void {
    Notification::fake();
    $user = enterpriseOnlyUser();

    app(UpdateUserProfileInformation::class)->update($user, [
        'name' => '新しい名前',
        'email' => 'new@corp.example',
    ]);

    expect($user->fresh()?->name)->toBe('新しい名前');

    // ★旧アドレスが無いので「メールアドレスが変わりました」は送れない
    //   (新アドレスへの確認メールは通常どおり送られる = 確認し直す形が保たれている)。
    Notification::assertNotSentTo(
        new AnonymousNotifiable,
        EmailChangedSecurityNotification::class,
    );
    Notification::assertSentTo($user->fresh(), VerifyEmail::class);
});

test('管理画面のメンバー一覧が null のメールで壊れない', function (): void {
    $user = enterpriseOnlyUser();

    $row = MemberRowData::fromUser($user, null, null, $user->id, null);

    expect($row->email)->toBeNull();
});
