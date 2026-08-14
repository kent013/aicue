<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

/*
 * bfcache 検証ページ (/debug/bfcache-trial) の防御層と前提条件のテスト。
 *
 * 本ページ専用の env フラグは追加しない判断をしている (概念設計)。根拠は
 * 既存の三層防御 (route 登録ゲート / LocalOnly の env 判定 + 資格情報未設定 404 /
 * production での ProductionEnvGuard fail-fast) が既にあり、しかも本ページは
 * 同一ゲート上の /debug/login より権限が低いためである。
 * **その前提が構造的に維持されていることを、ここで実効条件として機械固定する。**
 *
 * とくに `Cache-Control: no-store` は正のコントロールである。これが付かなくなると
 * 「Safari は no-store でも bfcache に格納する」という検証したい条件そのものが崩れ、
 * **本番と違う条件を見て「確認済み」と記録する**事故になる。
 *
 * **middleware 実行順の実測 (実装時に判明)**: 本 route は `LocalOnly` グループの内側に
 * `auth` を重ねているが、解決後の実行順は **`auth` が先**である。`Authenticate` は
 * Laravel 既定の priority list に載っており、載っていない `LocalOnly` より前へソートされる
 * (bootstrap/app.php の注記どおり、priority list は「載っている middleware 同士の相対順序」
 * しか強制しない)。auth を持たない `/debug/login` とはここが非対称になる。
 *
 * 帰結として **guest は 404 ではなく /login へ 302 する**。この差は許容する:
 *   - staging / production では route 登録ゲート自体が働き **route が存在しない**ため、
 *     存在オラクルにならない
 *   - local でのみ「登録済み route に guest が触れた」ことが 302 で分かるが、
 *     これは開発者自身の環境であり、実際に到達しうる相手 (認証済みユーザー) に対しては
 *     `LocalOnly` の env / 資格情報ゲートが正しく 404 / 401 を返す
 *
 * したがって本テストは **認証済みユーザーに対する LocalOnly の実効性**を主に固定し、
 * guest に対しては 302 (= auth が効いていること) を負のコントロールとして固定する。
 * `bootstrap/app.php` の priority list は TenantBoundaryOrderingTest が固定している
 * load-bearing な宣言であり、debug ページのために順序を動かすことはしない。
 */

beforeEach(function (): void {
    config(['app.env' => 'local']);
    config(['debug.login.user' => 'testuser']);
    config(['debug.login.password' => 'testpass123']);
});

/** @return array{string, string} */
function bfcacheTrialBasicAuthHeaders(): array
{
    return [
        'PHP_AUTH_USER' => 'testuser',
        'PHP_AUTH_PW' => 'testpass123',
    ];
}

dataset('bfcache trial routes', [
    'trial (A)' => ['/debug/bfcache-trial', 'Debug/BfcacheTrial'],
    'away (B)' => ['/debug/bfcache-trial/away', 'Debug/BfcacheTrialAway'],
]);

test('認証済みでも production 環境なら 404 (LocalOnly の env ゲート)', function (string $path): void {
    [, $user] = createOrganizationWithOwner();
    config(['app.env' => 'production']);

    $this->actingAs($user)
        ->withHeaders(bfcacheTrialBasicAuthHeaders())
        ->get($path)
        ->assertNotFound();
})->with('bfcache trial routes');

test('認証済みでも DEBUG_LOGIN_* 未設定なら 404 (fail-secure。明示的な env opt-in が必須)', function (string $path): void {
    [, $user] = createOrganizationWithOwner();
    config(['debug.login.user' => '']);
    config(['debug.login.password' => '']);

    $this->actingAs($user)
        ->get($path)
        ->assertNotFound();
})->with('bfcache trial routes');

test('認証済みでも Basic 認証なしなら 401', function (string $path): void {
    [, $user] = createOrganizationWithOwner();

    $response = $this->actingAs($user)->get($path);

    $response->assertStatus(401);
    expect((string) $response->headers->get('WWW-Authenticate'))->toContain('Basic');
})->with('bfcache trial routes');

test('guest は /login へリダイレクト (auth が効いていることの負のコントロール)', function (string $path): void {
    // auth が LocalOnly より先に走るため 404 ではなく 302 になる (docblock の実行順の項)
    $this->withHeaders(bfcacheTrialBasicAuthHeaders())
        ->get($path)
        ->assertRedirect('/login');
})->with('bfcache trial routes');

test('認証済み + Basic 認証で 200。Inertia component が取り違えられていない', function (string $path, string $component): void {
    [, $user] = createOrganizationWithOwner();

    $this->actingAs($user)
        ->withHeaders(bfcacheTrialBasicAuthHeaders())
        ->get($path)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with('bfcache trial routes');

test('認証済み応答に Cache-Control: no-store が付く (検証条件の正のコントロール)', function (string $path): void {
    [, $user] = createOrganizationWithOwner();

    $response = $this->actingAs($user)
        ->withHeaders(bfcacheTrialBasicAuthHeaders())
        ->get($path);

    $response->assertOk();
    expect((string) $response->headers->get('Cache-Control'))->toContain('no-store');
})->with('bfcache trial routes');

test('controller 固有 props を渡さない (観測値はすべてクライアント側で生成する)', function (): void {
    [, $user] = createOrganizationWithOwner();

    $this->actingAs($user)
        ->withHeaders(bfcacheTrialBasicAuthHeaders())
        ->get('/debug/bfcache-trial')
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            // controller は Inertia::render にデータを渡していない。
            // **共有 props (HandleInertiaRequests) は別の話**で、そちらは載る。
            $page->component('Debug/BfcacheTrial')
                ->missing('users')
                ->missing('trial');
        });
});

test('共有 props の auth.user は載る (guard の作動条件そのもの)', function (): void {
    [, $user] = createOrganizationWithOwner();

    // bfcache-guard は Inertia 共有 props の auth.user を見て
    // 「認証済みページか」を判定する (resources/js/app.ts)。ここが欠けると
    // guard が一切作動せず、検証ページが観測対象を失う。正のコントロールとして固定する。
    $this->actingAs($user)
        ->withHeaders(bfcacheTrialBasicAuthHeaders())
        ->get('/debug/bfcache-trial')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('auth.user'));
});
