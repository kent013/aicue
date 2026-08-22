<?php

declare(strict_types=1);

use App\Http\Middleware\BughuntExecutedRouteMiddleware;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * 実行済み route の記録器 (BughuntExecutedRouteMiddleware) の実挙動 (T164)。
 *
 * **実 HTTP 要求で検証する** (terminate() を直接呼ぶ形にしない)。それでは
 * bootstrap/app.php の配線 (web グループ登録 + priority list の位置) を検証したことにならない。
 *
 * 出力先は storage_path() なので、テストごとに固有の run 名を使い afterEach で掃除する。
 */

const BUGHUNT_TEST_RUN = 'testrun-20260815';
const BUGHUNT_TEST_SHARD = '0';

/** 記録を有効化する (config の 3 キーが揃って初めて有効)。 */
function bughuntEnableCapture(string $run = BUGHUNT_TEST_RUN, string $shard = BUGHUNT_TEST_SHARD): void
{
    config([
        'bughunt.executed.enabled' => true,
        'bughunt.executed.run' => $run,
        'bughunt.executed.shard' => $shard,
    ]);
}

/**
 * 記録された行 (JSONL) を配列で読む。ファイルが無ければ空配列。
 *
 * @return list<array<string, mixed>>
 */
function bughuntCapturedRows(string $run = BUGHUNT_TEST_RUN, string $shard = BUGHUNT_TEST_SHARD): array
{
    $path = BughuntExecutedRouteMiddleware::outputPath($run, $shard);
    if (! is_file($path)) {
        return [];
    }

    $rows = [];
    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $decoded = json_decode($line, true);
        expect($decoded)->toBeArray("記録行が JSON として読めない: {$line}");
        /** @var array<string, mixed> $decoded */
        $rows[] = $decoded;
    }

    return $rows;
}

/** 記録ファイルと失敗マーカーを消す。 */
function bughuntForgetCapture(string $run = BUGHUNT_TEST_RUN, string $shard = BUGHUNT_TEST_SHARD): void
{
    foreach ([
        BughuntExecutedRouteMiddleware::outputPath($run, $shard),
        BughuntExecutedRouteMiddleware::failurePath($run, $shard),
    ] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

beforeEach(function (): void {
    bughuntForgetCapture();
    bughuntForgetCapture('other-run', '9');
});

afterEach(function (): void {
    bughuntForgetCapture();
    bughuntForgetCapture('other-run', '9');
});

test('既定 (config off) では 1 バイトも書かない', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    expect(is_file(BughuntExecutedRouteMiddleware::outputPath(BUGHUNT_TEST_RUN, BUGHUNT_TEST_SHARD)))
        ->toBeFalse('config off なのに記録ファイルが作られた');
});

test('認証済みユーザーの 200 GET が route 名つきで ok として記録される', function (): void {
    bughuntEnableCapture();
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    $rows = bughuntCapturedRows();
    expect($rows)->toHaveCount(1);
    expect($rows[0])->toMatchArray([
        'run_id' => BUGHUNT_TEST_RUN,
        'shard' => BUGHUNT_TEST_SHARD,
        'route_name' => 'dashboard',
        'method' => 'GET',
        'path' => '/dashboard',
        'status' => 'ok',
        'http_status' => 200,
    ]);
});

test('FormRequest 不合格の 302 は blocked として記録される', function (): void {
    bughuntEnableCapture();

    $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
        ->assertRedirect();

    $rows = bughuntCapturedRows();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['route_name'])->toBe('login.store');
    expect($rows[0]['status'])->toBe('blocked');
    expect($rows[0]['http_status'])->toBe(302);
});

test('未認証の変更系要求は 1 行も記録されない (auth が上流で短絡する)', function (): void {
    bughuntEnableCapture();

    // 遮断したのが auth であることを着地で固定する (別の理由で 302 になる空振りを防ぐ)
    $this->post('/settings/password', [])->assertRedirect(route('login'));

    expect(bughuntCapturedRows())->toBe([]);
});

test('課金ゲートに遮断された変更系要求は 1 行も記録されない', function (): void {
    bughuntEnableCapture();
    // 未契約組織 (free_plan_code NULL) = require-active-subscription が遮断する
    [$organization, $owner] = createOrganizationWithOwner('未契約組織', grandfatherFreePlan: false);

    // owner は manageBilling を持つので checkout へ倒れる (遮断したのが課金ゲートである証拠)
    $this->actingAs($owner)->post("/organizations/{$organization->slug}/projects", ['name' => 'テスト'])
        ->assertRedirect(route('onboarding.checkout', ['organization' => $organization->slug]));

    expect(bughuntCapturedRows())->toBe([]);
});

test('recent-auth に遮断された要求は 1 行も記録されない (route 個別の短絡 middleware)', function (): void {
    bughuntEnableCapture();
    $user = User::factory()->create();

    // step-up 未充足のまま機微操作 route を叩く (RequireRecentAuth が 302 で短絡する)
    $this->actingAs($user)->post('/settings/password', [
        'current_password' => 'password',
        'password' => 'new-password-1234',
        'password_confirmation' => 'new-password-1234',
    ])->assertRedirect(route('recent-auth.confirm'));

    expect(bughuntCapturedRows())->toBe([]);
});

test('403 / 500 は blocked として記録される', function (): void {
    bughuntEnableCapture();
    Route::middleware('web')->get('/__bughunt-test/forbidden', fn () => abort(403))
        ->name('bughunt-test.forbidden');
    Route::middleware('web')->get('/__bughunt-test/boom', fn () => abort(500))
        ->name('bughunt-test.boom');

    $this->get('/__bughunt-test/forbidden')->assertForbidden();
    $this->get('/__bughunt-test/boom')->assertStatus(500);

    $rows = bughuntCapturedRows();
    expect($rows)->toHaveCount(2);
    expect($rows[0]['status'])->toBe('blocked');
    expect($rows[0]['http_status'])->toBe(403);
    expect($rows[1]['status'])->toBe('blocked');
    expect($rows[1]['http_status'])->toBe(500);
});

test('成功した変更系の 302 (PRG) は ok として記録される', function (): void {
    bughuntEnableCapture();
    Route::middleware('web')->post('/__bughunt-test/prg', fn () => redirect('/'))
        ->name('bughunt-test.prg');

    $this->post('/__bughunt-test/prg')->assertRedirect('/');

    $rows = bughuntCapturedRows();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['status'])->toBe('ok');
    expect($rows[0]['http_status'])->toBe(302);
});

test('直前のバリデーション不合格に引きずられず、次の成功 302 は ok になる', function (): void {
    bughuntEnableCapture();
    Route::middleware('web')->post('/__bughunt-test/prg', fn () => redirect('/'))
        ->name('bughunt-test.prg');

    // (1) 不合格 302 (errors を flash する)
    $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong-password'])
        ->assertRedirect();
    // (2) 同じセッションで成功 302
    $this->post('/__bughunt-test/prg')->assertRedirect('/');

    $rows = bughuntCapturedRows();
    expect($rows)->toHaveCount(2);
    expect($rows[0]['status'])->toBe('blocked');
    expect($rows[1]['status'])->toBe('ok');
});

test('名前の無い route への要求は route_name null で記録される', function (): void {
    bughuntEnableCapture();
    Route::middleware('web')->get('/__bughunt-test/anonymous', fn () => response('ok'));

    $this->get('/__bughunt-test/anonymous')->assertOk();

    $rows = bughuntCapturedRows();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['route_name'])->toBeNull();
    expect($rows[0]['status'])->toBe('ok');
});

test('production 環境では config が真でも書かない', function (): void {
    bughuntEnableCapture();
    $this->app->detectEnvironment(fn (): string => 'production');
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')->assertOk();

    expect(bughuntCapturedRows())->toBe([]);
});

test('run / shard が書式違反なら書かない', function (): void {
    $user = User::factory()->create();

    foreach ([['../etc', '0'], ['', '0'], [BUGHUNT_TEST_RUN, ''], [BUGHUNT_TEST_RUN, 'a/b']] as [$run, $shard]) {
        bughuntEnableCapture($run, $shard);
        $this->actingAs($user)->get('/dashboard')->assertOk();
    }

    // 書式検査を通らないので、正常な run/shard 名のファイルも当然できない
    expect(bughuntCapturedRows())->toBe([]);
    expect(glob(storage_path('bughunt-executed').DIRECTORY_SEPARATOR.'*etc*'))->toBe([]);
});

test('enabled() は 3 キーが揃ったときだけ真になる', function (): void {
    config(['bughunt.executed.enabled' => false, 'bughunt.executed.run' => 'r', 'bughunt.executed.shard' => '0']);
    expect(BughuntExecutedRouteMiddleware::enabled())->toBeFalse();

    config(['bughunt.executed.enabled' => true, 'bughunt.executed.run' => null, 'bughunt.executed.shard' => '0']);
    expect(BughuntExecutedRouteMiddleware::enabled())->toBeFalse();

    config(['bughunt.executed.enabled' => true, 'bughunt.executed.run' => 'r', 'bughunt.executed.shard' => null]);
    expect(BughuntExecutedRouteMiddleware::enabled())->toBeFalse();

    bughuntEnableCapture();
    expect(BughuntExecutedRouteMiddleware::enabled())->toBeTrue();
});
