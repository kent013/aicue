<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Cookie;
use Webmozart\Assert\Assert;

/*
 * bug-hunt F-H4 (authz_bypass) の不変条件を固定する。
 * セルフサービスのパスワード変更 (user-password.update → UpdateUserPassword) が
 * 他デバイスの session / remember-me を確実に失効させ、かつ現在デバイスを維持することを検証する。
 *
 * (a)(b)(e) = 施策2 のオーケストレーション (実 PUT /user/password)。
 * (c)(d)     = 施策1 (AuthenticateSession の password_hash 照合 = correctness の要)。
 */

/**
 * database driver 用の session 行を組み立てる (payload は serialize+base64 の文字列)。
 *
 * @return array<string, mixed>
 */
function sessionRow(string $id, int $userId): array
{
    return [
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => null,
        'user_agent' => null,
        'payload' => base64_encode(serialize([])),
        'last_activity' => time(),
    ];
}

// (a) 施策2 / 他 user 行削除・別 user 行残存
test('パスワード変更で当該ユーザーの他セッション行が削除され、別ユーザーの行は残る', function (): void {
    config(['session.driver' => 'database']);

    $user = User::factory()->create();  // password は factory 既定 'password'
    $other = User::factory()->create();

    DB::table('sessions')->insert([
        sessionRow('attacker-session', $user->id),    // 攻撃者行 (現在 id ではない) → 削除すべき
        sessionRow('other-user-session', $other->id), // 別ユーザー → 対象外・残存すべき
    ]);

    $this->actingAs($user)->put('/user/password', [
        'current_password' => 'password',
        'password' => 'NewPassword12345',
    ])->assertSessionHasNoErrors();

    expect(DB::table('sessions')->where('id', 'attacker-session')->exists())->toBeFalse();
    expect(DB::table('sessions')->where('id', 'other-user-session')->exists())->toBeTrue();
});

// (b) 施策2 / 現在デバイス維持
test('パスワード変更後も現在デバイスはログイン状態を維持する', function (): void {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();

    $this->actingAs($user)->put('/user/password', [
        'current_password' => 'password',
        'password' => 'NewPassword12345',
    ])->assertSessionHasNoErrors();

    // 変更後は再 actingAs せず、確立済みセッションのまま保護ルートに到達できること＝維持を検証。
    // (再 actingAs すると新規認証になり「維持」を検証できないため使わない)
    $this->get('/dashboard')->assertSuccessful();
    expect(Hash::check('NewPassword12345', $user->fresh()->password))->toBeTrue();
});

// (c) 施策1 / 旧 hash セッションの失効 (決定的)
test('旧 password_hash を持つ既存セッションはハッシュ変更後の次リクエストで失効する', function (): void {
    $user = User::factory()->create();
    $oldHash = $user->getAuthPassword(); // 変更前ハッシュ

    // パスワード（ハッシュ）を変更。actingAs が使う in-memory $user が新ハッシュを持つ。
    $user->forceFill(['password' => Hash::make('NewPassword12345')])->save();

    // 旧 password_hash_web を持つ既存/復活セッションを模して保護ルートへ
    $this->actingAs($user)
        ->withSession(['password_hash_web' => $oldHash])
        ->get('/dashboard')
        ->assertRedirect('/login'); // AuthenticateSession が hash 不一致で logout
});

// (d) 施策1 / recaller の viaRemember 分岐を実 cookie で検証
test('別デバイスの古い remember-me (recaller) はハッシュ変更後に失効する', function (): void {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();

    // recaller cookie 名を PHPStan L10 安全に取得
    $guard = Auth::guard('web');
    Assert::isInstanceOf($guard, SessionGuard::class);
    $recallerName = $guard->getRecallerName();

    // device B: remember 付き実ログイン → recaller cookie を capture (decrypt 既定 true = 平文)
    $login = $this->post('/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => 'on',
    ]);
    $recaller = $login->getCookie($recallerName);
    Assert::isInstanceOf($recaller, Cookie::class);

    // device A: out-of-band にハッシュ変更 (recaller の password_hash は旧のまま = viaRemember で不一致)
    User::query()->whereKey($user->id)->update(['password' => Hash::make('NewPassword12345')]);

    // 既存の guard/session を破棄して recaller 単独認証を成立させる
    $this->flushSession();
    Auth::forgetGuards();

    // device B: session cookie を送らず recaller (平文→withCookie が再暗号化) のみ提示
    // → viaRemember 経路で recaller の旧 password_hash と新 hash が不一致 → 失効 → login へ
    $this->withCookie($recallerName, $recaller->getValue())
        ->get('/dashboard')
        ->assertRedirect('/login');
});

// (e) 施策2 / driver != database では行削除を skip
test('session driver が database でない場合は行削除をスキップしエラーにならない', function (): void {
    config(['session.driver' => 'array']);
    $user = User::factory()->create();

    $this->actingAs($user)->put('/user/password', [
        'current_password' => 'password',
        'password' => 'NewPassword12345',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('NewPassword12345', $user->fresh()->password))->toBeTrue();
});
