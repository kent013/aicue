<?php

declare(strict_types=1);

use App\Models\Passkey;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/*
 * EnsureLoginMethodRemains の実挙動 (投影後評価・transport 別の拒否契約・直列化機構)。
 *
 * 分類 invariant (どの route に guard が必要か) は
 * tests/Architecture/LoginMethodRemovalRouteTest.php が担う。
 */

/** password / social を持たず passkey だけでログインするユーザー */
function passkeyOnlyUser(int $passkeys = 1): User
{
    $user = User::factory()->ssoOnly()->create();
    Passkey::factory()->count($passkeys)->for($user)->create();

    return $user;
}

function linkGoogleTo(User $user): void
{
    $account = new SocialAccount(['provider' => 'google', 'provider_user_id' => 'g-'.$user->getKey()]);
    $account->user()->associate($user);
    $account->save();
}

/* ------------------------------------------------------------ 拒否 (手段が 0 になる) */

/*
 * Inertia には **422 JSON を返さない** (protocol 違反で router が解釈できず無言失敗する)。
 * 302 + errors を返し、Inertia が DELETE の 302 を 303 へ変換する。
 * 次の Inertia 訪問で `$page.props.errors.login_method` として読めることまで固定する
 * (Svelte 側の表示契約そのもの)。
 */
test('唯一の passkey の削除は Inertia に redirect + errors.login_method で拒否される', function (): void {
    $user = passkeyOnlyUser();
    $passkey = $user->passkeys()->firstOrFail();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings.security'))
        ->withHeaders(['X-Inertia' => 'true'])
        ->delete("/user/passkeys/{$passkey->getKey()}")
        ->assertStatus(303)
        ->assertRedirect(route('settings.security'));

    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();

    // withHeaders はテスト内で永続するため明示的に捨てる。
    // GET は素の HTML 訪問で検査する (X-Inertia を付けると asset version 不一致で 409 になる)
    $this->flushHeaders();

    $this->actingAs($user)
        ->get(route('settings.security'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Security')
            ->where('errors.login_method', 'この操作を行うと、ログインする手段がなくなります。先に別のログイン手段（パスワードの設定、ソーシャル連携、他のパスキー）を追加してください。'));
});

test('唯一の passkey の削除は純 XHR に 422 + login_method_required で拒否される', function (): void {
    $user = passkeyOnlyUser();
    $passkey = $user->passkeys()->firstOrFail();

    $response = $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->deleteJson("/user/passkeys/{$passkey->getKey()}");

    $response->assertStatus(422)
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('code', 'login_method_required')
        ->assertJsonPath('settingsUrl', route('settings.security'));
    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();
});

test('唯一の passkey の削除は通常フォーム POST に back + errors で拒否される', function (): void {
    $user = passkeyOnlyUser();
    $passkey = $user->passkeys()->firstOrFail();

    $response = $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings.security'))
        ->delete("/user/passkeys/{$passkey->getKey()}");

    $response->assertRedirect(route('settings.security'));
    $response->assertSessionHasErrors('login_method');
});

test('TOTP confirmed ユーザーは passkey が 2 件あっても手段に数えないため削除が拒否される', function (): void {
    $user = User::factory()->ssoOnly()->withTwoFactor()->create();
    Passkey::factory()->count(2)->for($user)->create();
    $passkey = $user->passkeys()->firstOrFail();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings.security'))
        ->delete("/user/passkeys/{$passkey->getKey()}")
        ->assertSessionHasErrors('login_method');

    expect($user->passkeys()->count())->toBe(2);
});

/* ------------------------------------------------------------ 許可 (手段が残る) */

test('passkey が 2 件あれば 1 件削除できる', function (): void {
    $user = passkeyOnlyUser(passkeys: 2);
    $passkey = $user->passkeys()->firstOrFail();

    $response = $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings.security'))
        ->delete("/user/passkeys/{$passkey->getKey()}");

    $response->assertRedirect(route('settings.security'));
    $response->assertSessionHasNoErrors();
    $response->assertSessionHas('success');
    expect($user->passkeys()->count())->toBe(1);
});

test('password があれば唯一の passkey を削除できる', function (): void {
    $user = User::factory()->create();
    $passkey = Passkey::factory()->for($user)->create();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings.security'))
        ->delete("/user/passkeys/{$passkey->getKey()}")
        ->assertSessionHasNoErrors();

    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeFalse();
});

test('google 連携があれば唯一の passkey を削除できる', function (): void {
    $user = User::factory()->ssoOnly()->create();
    linkGoogleTo($user);
    $passkey = Passkey::factory()->for($user)->create();

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings.security'))
        ->delete("/user/passkeys/{$passkey->getKey()}")
        ->assertSessionHasNoErrors();

    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeFalse();
});

/* ------------------------------------------------------------ 直列化規約 (SQL レベル) */

/*
 * **このテストの限界を明記する**:
 *   RefreshDatabase がテスト全体を 1 トランザクションで包むため、独立 connection による
 *   実レース (passkey 2 件を同時削除して 0 件になる) は再現できない。
 *   ここで固定するのは **機構**:
 *     (a) 削除より前に users への `for update` select が発行される
 *     (b) 両者が同一の transaction level で観測される
 *     (c) その level がリクエスト開始前の level より大きい (middleware が新たに開いた証明)
 *   ロックの **効果** (競合トランザクションの待機) は DB に委ねる。
 */
test('passkey 削除は users 行の for update ロック取得後に同一トランザクションで実行される', function (): void {
    $user = passkeyOnlyUser(passkeys: 2);
    $passkey = $user->passkeys()->firstOrFail();

    $baseLevel = DB::transactionLevel();

    /** @var list<array{sql: string, level: int}> $observed */
    $observed = [];
    DB::listen(function ($query) use (&$observed): void {
        $observed[] = ['sql' => strtolower($query->sql), 'level' => DB::transactionLevel()];
    });

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings.security'))
        ->delete("/user/passkeys/{$passkey->getKey()}")
        ->assertSessionHasNoErrors();

    $lockIndex = null;
    $deleteIndex = null;
    foreach ($observed as $index => $entry) {
        if ($lockIndex === null && str_contains($entry['sql'], 'from "users"') && str_contains($entry['sql'], 'for update')) {
            $lockIndex = $index;
        }
        if ($deleteIndex === null && str_starts_with($entry['sql'], 'delete from "passkeys"')) {
            $deleteIndex = $index;
        }
    }

    expect($lockIndex)->not->toBeNull('users 行の lockForUpdate が発行されていない');
    expect($deleteIndex)->not->toBeNull('passkeys の delete が発行されていない');
    expect($lockIndex)->toBeLessThan($deleteIndex, 'ロック取得より前に削除が走っている (TOCTOU)');

    $lockLevel = $observed[$lockIndex]['level'];
    expect($observed[$deleteIndex]['level'])->toBe($lockLevel, 'ロックと削除が別トランザクション');
    // RefreshDatabase がテスト全体を包むため level は 1 から始まらない。必ず相対比較する
    expect($lockLevel)->toBeGreaterThan($baseLevel, 'middleware が新しいトランザクションを開いていない');
});

test('拒否時には passkeys の delete が発行されない', function (): void {
    $user = passkeyOnlyUser();
    $passkey = $user->passkeys()->firstOrFail();

    /** @var list<string> $statements */
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    $this->actingAs($user)
        ->withSession(freshRecentAuthSession())
        ->from(route('settings.security'))
        ->delete("/user/passkeys/{$passkey->getKey()}")
        ->assertSessionHasErrors('login_method');

    $deletes = array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'delete from "passkeys"'));
    expect($deletes)->toBe([]);
});
