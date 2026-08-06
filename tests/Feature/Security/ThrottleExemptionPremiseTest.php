<?php

declare(strict_types=1);

use App\Http\Middleware\LocalOnly;
use Filament\Auth\MultiFactor\App\Actions\DisableAppAuthenticationAction;
use Filament\Auth\MultiFactor\App\Actions\RegenerateAppAuthenticationRecoveryCodesAction;
use Filament\Auth\MultiFactor\App\Actions\SetUpAppAuthenticationAction;
use Filament\Auth\Pages\EditProfile as FilamentEditProfile;
use Filament\Auth\Pages\Login as FilamentLogin;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
 * ThrottleCoverageInventoryTest の exemption が依拠する**前提**の behavioral proof。
 *
 * exemption は「throttle を持たないことが**正しい**」という主張であり、
 * その根拠 (署名で短絡する / 定数応答である / production には存在しない) が
 * vendor 更新やリファクタで崩れたら検出できなければならない。
 * 崩れたのに気づけない = 「対処済みに見える無防備」であり最悪の失敗モードになる。
 */

test('署名なしの PUT /storage/{path} は本体に到達しない (副作用ゼロで短絡する)', function (): void {
    // storage.local.upload の exemption 根拠 = SignatureRequiredBeforeEffect
    $disk = config('filesystems.default');
    expect($disk)->toBeString();
    Storage::fake($disk);

    $response = $this->call('PUT', '/storage/probe.txt', content: 'payload');

    // 非 production では 403 (production は 404)。いずれにせよ本体へ到達しない
    expect($response->getStatusCode())->toBe(403);
    Storage::disk($disk)->assertMissing('probe.txt');
});

test('GET /api/v1/mcp は定数 405 スタブ (Allow: POST) を返す', function (): void {
    $response = $this->get('/api/v1/mcp');

    expect($response->getStatusCode())->toBe(405);
    expect($response->headers->get('Allow'))->toBe('POST');
});

test('DELETE /api/v1/mcp は定数 405 スタブ (Allow: POST) を返す', function (): void {
    $response = $this->delete('/api/v1/mcp');

    expect($response->getStatusCode())->toBe(405);
    expect($response->headers->get('Allow'))->toBe('POST');
});

/** OAuth メタデータ route の URI 一覧 (定数応答であることの検証対象)。 */
function throttlePremiseMetadataUris(): array
{
    return [
        '/.well-known/oauth-authorization-server',
        '/.well-known/oauth-authorization-server/mcp',
        '/.well-known/oauth-protected-resource',
        '/.well-known/oauth-protected-resource/mcp',
    ];
}

test('.well-known/oauth-* の 4 route はいずれも DB クエリ 0 件で応答する', function (): void {
    // StaticMetadataResponse の exemption 根拠 = 「DB アクセスを伴わない定数 JSON」
    foreach (throttlePremiseMetadataUris() as $uri) {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->getJson($uri);

        expect($response->getStatusCode())->toBe(200, "{$uri} が 200 を返しません");
        expect($queries)->toBe([], "{$uri} が DB クエリを発行しました: ".implode(' / ', $queries));
    }
});

test('.well-known/oauth-*/{path} は path 由来の処理をしない (route parameter 非依存)', function (): void {
    // authorization-server: 値まで完全に同一 ({path} は RFC 8414 の URI 形式のためだけに存在する)
    $a1 = $this->getJson('/.well-known/oauth-authorization-server/mcp');
    $a2 = $this->getJson('/.well-known/oauth-authorization-server/some/other/path');
    expect($a2->getStatusCode())->toBe($a1->getStatusCode());
    expect($a2->json())->toBe($a1->json(), 'authorization-server の応答が path に依存しています');

    // protected-resource: `resource` だけが url() でリクエスト path を echo する。
    // これは文字列組み立てであって「path 由来の処理」ではない
    // (DB クエリ 0 件は上のテストが固定しており、定数メタデータという主張は保たれる)。
    $p1 = $this->getJson('/.well-known/oauth-protected-resource/mcp');
    $p2 = $this->getJson('/.well-known/oauth-protected-resource/some/other/path');
    expect($p2->getStatusCode())->toBe($p1->getStatusCode());
    expect($p2->json('resource'))->toBe(url('/some/other/path'));
    expect(Arr::except($p2->json(), ['resource']))->toBe(
        Arr::except($p1->json(), ['resource']),
        'protected-resource の応答が resource 以外でも path に依存しています',
    );
});

/*
 * `default-livewire.update` (ComponentLevelLimiter) の前提。
 *
 * 「防御は route ではなく component 内にある」という主張は、Filament 側の
 * `$this->rateLimit(...)` が実在することに全面的に依存している。vendor 更新で消えると
 * **広い Livewire POST が無防備なまま inventory は通り続ける** (deny-by-default の最悪失敗)。
 */
/**
 * 指定メソッドの**本体**に `->rateLimit(...)` 呼び出しがあるか (token 走査)。
 *
 * ファイル全体の文字列検索では、コメント化 / 別メソッドへの移動 / 文字列リテラル中の記述でも
 * 合格してしまう (deny-by-default では誤合格が最悪の失敗モード)。
 * ReflectionMethod で**対象メソッドの本体だけ**を切り出し、コメント / 文字列を
 * token 段階で除去してから `-> rateLimit (` の並びを探す。
 *
 * @param  class-string  $class
 */
function throttlePremiseMethodRateLimits(string $class, string $method): bool
{
    $reflection = new ReflectionMethod($class, $method);
    $file = $reflection->getFileName();
    if ($file === false) {
        return false;
    }
    $lines = file($file);
    if ($lines === false) {
        return false;
    }

    $start = $reflection->getStartLine();
    $end = $reflection->getEndLine();
    if ($start === false || $end === false) {
        return false;
    }

    $ignored = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_WHITESPACE];
    $tokens = [];
    foreach (token_get_all('<?php '.implode('', array_slice($lines, $start - 1, $end - $start + 1))) as $token) {
        if (is_array($token)) {
            if (! in_array($token[0], $ignored, true)) {
                $tokens[] = $token[1];
            }

            continue;
        }
        $tokens[] = $token;
    }

    $count = count($tokens);
    for ($i = 0; $i < $count - 2; $i++) {
        if ($tokens[$i] === '->' && $tokens[$i + 1] === 'rateLimit' && $tokens[$i + 2] === '(') {
            return true;
        }
    }

    return false;
}

test('default-livewire.update の前提: Filament の credential 操作が component 内で rateLimit を掛けている', function (): void {
    // panel が公開する credential 面 (login / profile / MFA 管理) の**実行メソッド**に
    // rate limit があること。1 つでも消えたら route 側の防御を設計し直す必要がある。
    $targets = [
        [FilamentLogin::class, 'authenticate'],
        [FilamentEditProfile::class, 'save'],
        [SetUpAppAuthenticationAction::class, 'make'],
        [DisableAppAuthenticationAction::class, 'make'],
        [RegenerateAppAuthenticationRecoveryCodesAction::class, 'make'],
    ];

    foreach ($targets as [$class, $method]) {
        expect(throttlePremiseMethodRateLimits($class, $method))->toBeTrue(
            "{$class}::{$method}() から component 内 rate limit が消えています。"
            .'default-livewire.update の exemption 根拠が崩れているため、route 側の防御を設計し直すこと。',
        );
    }

    // negative control: 走査器が「どのメソッドでも true」になっていないこと
    // (常に true を返す検査は deny-by-default を無意味にする)
    expect(throttlePremiseMethodRateLimits(FilamentLogin::class, 'mount'))->toBeFalse(
        '走査器がメソッド本体を絞れていません (ファイル全体を見ている可能性)',
    );
});

test('default-livewire.update の前提: panel が公開する auth ページの集合が変わっていない', function (): void {
    // 新しい credential ページ (register / password-reset 等) が有効化されると
    // exemption の射程が黙って広がる。集合を固定して再検討を強制する。
    // multi-factor-authentication.set-up-required は AppAuthentication (TOTP) の
    // セットアップ画面で、実操作は SetUp/Disable/Regenerate の各 Action が担う
    // (それらの rateLimit は上のテストが固定している)。
    $expected = [
        'filament.admin.auth.login',
        'filament.admin.auth.logout',
        'filament.admin.auth.multi-factor-authentication.set-up-required',
        'filament.admin.auth.profile',
    ];

    $actual = [];
    foreach (Route::getRoutes() as $route) {
        $name = $route->getName();
        if ($name !== null && str_starts_with($name, 'filament.admin.auth.')) {
            $actual[$name] = true;
        }
    }
    $actual = array_keys($actual);
    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected,
        'Filament panel が公開する auth ページの集合が変わりました。'
        .'default-livewire.update の exemption は「公開される credential 面が component 内で'
        .'有界化されている」ことに依存するため、増えたページの rate limit を確認してから集合を更新すること。');
});

/*
 * `logout` / `filament.admin.auth.logout` (SessionTeardownOnly) の前提。
 * 「認証済みでのみ到達でき、失敗しても攻撃者が得る情報が無い」ことを実挙動で固定する。
 */
test('logout 系の前提: 認証必須であり、未認証では本体に到達しない', function (): void {
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    foreach (['logout', 'filament.admin.auth.logout'] as $name) {
        $route = $routes->getByName($name);
        expect($route)->not->toBeNull("route [{$name}] が存在しない");

        // 「認証済みでのみ到達できる」= 実効列に Authenticate があること (構造)
        $hasAuthenticate = false;
        foreach ($router->gatherRouteMiddleware($route) as $entry) {
            if (is_string($entry) && is_a(explode(':', $entry, 2)[0], Authenticate::class, true)) {
                $hasAuthenticate = true;
            }
        }
        expect($hasAuthenticate)->toBeTrue("route [{$name}] に Authenticate がありません (SessionTeardownOnly の前提が崩れています)");
    }

    // 未認証は本体へ到達せず差し戻される (実挙動)
    $this->post('/logout')->assertRedirect();
    $this->post('/admin/logout')->assertRedirect();
});

test('debug.login-as は testing 環境では登録される (母集団に現れる前提の固定)', function (): void {
    // LocalOnlyDebugRoute の exemption 根拠は「production では登録自体が起きない」であり、
    // 「テストから見えない」ではない。testing で登録されること自体が前提の一部。
    $routes = Route::getRoutes();
    $routes->refreshNameLookups();

    expect($routes->getByName('debug.login-as'))->not->toBeNull();
});

test('debug.login-as の登録は isLocal || runningUnitTests で囲われている (production 不在の根拠)', function (): void {
    $source = file_get_contents(base_path('routes/web.php'));
    expect($source)->toBeString();

    // 登録条件そのものをソース上で固定する (条件が外れれば production にも生える)
    expect($source)->toContain('if (app()->isLocal() || app()->runningUnitTests()) {');
    expect($source)->toContain("->name('debug.login-as')");

    // 二重防御 (LocalOnly middleware) が実効列に残っていること
    $routes = Route::getRoutes();
    $routes->refreshNameLookups();
    $route = $routes->getByName('debug.login-as');
    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain(LocalOnly::class);
});
