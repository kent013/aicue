<?php

declare(strict_types=1);

use App\Http\Middleware\LocalOnly;
use App\Models\AdminUser;
use App\Support\Http\RouteThrottleBinder;
use Filament\Auth\MultiFactor\App\Actions\DisableAppAuthenticationAction;
use Filament\Auth\MultiFactor\App\Actions\RegenerateAppAuthenticationRecoveryCodesAction;
use Filament\Auth\MultiFactor\App\Actions\SetUpAppAuthenticationAction;
use Filament\Auth\Pages\EditProfile as FilamentEditProfile;
use Filament\Auth\Pages\Login as FilamentLogin;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\SocialiteManager;
use Webmozart\Assert\Assert;

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

/*
 |--------------------------------------------------------------------------
 | T121: 認証面 GET の exemption (AuthViewRenderOnly /
 |       AuthFlowInitiationWithoutOutboundCall) が依拠する前提
 |--------------------------------------------------------------------------
 |
 | この 2 case は「1 リクエストで外向き通信・重い計算・状態生成が起きない」という
 | 主張であり、崩れると「throttle 不要と裁定した route が実は増幅装置だった」になる。
 | 主張の中身を実挙動で固定する。
 |
 | ★母集団 13 本すべてには広げない。filament.admin.auth.* は panel 権限の用意が要り、
 |   password.reset/{token} / two-factor.login は分岐条件を満たさないと
 |   「描画されなかっただけ」の空振り green になる。実効する 3 本の網 +
 |   auth_view_render_only の exact-fit cap (13) の方が deny-by-default として強い
 |   (14 本目が必ず再レビューを強制する)。
 */

/** AuthViewRenderOnly の代表 GET (未認証で到達でき、分岐条件なしに本体が描画される 3 本)。 */
function throttlePremiseRenderOnlyUris(): array
{
    return ['/login', '/register', '/forgot-password'];
}

/**
 * SQL が書込文か (deny-by-default = 迷ったら write 扱いにする)。
 *
 * ★SQL パーサは導入しない。対象 route が発行するのは Eloquent / query builder 生成の
 *   SQL のみで先頭コメントが付かないため、先頭動詞の判定で足りる。
 * ★ただし CTE (`with ... as (...) insert ...`) は先頭動詞が `with` になり、
 *   単純な前方一致では**書込を見逃す**。deny-by-default では見逃しが最悪の失敗なので、
 *   `with` で始まる文に insert/update/delete が現れたら**保守的に write 扱い**にする
 *   (過検出は「exemption を諦めて throttle を貼る」方向にしか倒れないので安全)。
 * ★検出器が黙って壊れると「DB 書込があるのに exemption は通り続ける」= 最悪失敗になるため、
 *   判定関数自身の単体ケースを同ファイルに置く。
 */
function throttlePremiseIsWriteStatement(string $sql): bool
{
    $normalized = mb_strtolower(ltrim($sql));

    foreach (['insert', 'update', 'delete', 'truncate'] as $verb) {
        if (str_starts_with($normalized, $verb)) {
            return true;
        }
    }

    // CTE は先頭動詞が with になるため、本体の動詞を保守的に拾う
    if (str_starts_with($normalized, 'with')) {
        foreach (['insert', 'update', 'delete'] as $verb) {
            if (str_contains($normalized, $verb)) {
                return true;
            }
        }
    }

    return false;
}

test('SQL 書込判定の検出器そのものが機能する (見逃しと過検出の両方を固定)', function (string $sql, bool $expected): void {
    expect(throttlePremiseIsWriteStatement($sql))->toBe($expected);
})->with([
    '先頭空白の insert' => ['  insert into "users" ("id") values (1)', true],
    '大文字の UPDATE' => ['UPDATE "users" SET "name" = ?', true],
    'select は write ではない' => ['select * from "users" where "id" = ?', false],
    'CTE + insert は保守的に write' => ['with recent as (select 1) insert into "logs" ("id") values (1)', true],
    'CTE + select は write ではない' => ['with recent as (select 1) select * from recent', false],
]);

test('AuthViewRenderOnly の代表 GET は外向き HTTP もメール送信も起こさない', function (): void {
    // ★この 1 行は tests/Pest.php のレーン既定と**同値の重複宣言**であり、後方互換の並走ではない
    //  (Factory::preventStrayRequests は冪等。allowStrayRequests は呼んでいないので
    //   レーン既定の loopback 許可集合を置換しない)。このテストの意図
    //   「ここで外向き HTTP が起きないこと」を呼び出し側に明示する目的で残す。
    Http::preventStrayRequests();
    Mail::fake();

    foreach (throttlePremiseRenderOnlyUris() as $uri) {
        $response = $this->get($uri);
        expect($response->getStatusCode())->toBe(200, "{$uri} が 200 を返しません (描画されず空振りしている可能性)");
    }

    Mail::assertNothingSent();
});

test('AuthViewRenderOnly の代表 GET は DB 書込を 1 件も発行しない (read は許す)', function (): void {
    // ★前提: phpunit.xml が SESSION_DRIVER=array を force="true" で固定しているため、
    //   session 書き込みが DB クエリとして観測されない。**driver を変えるときは本テストを見直すこと**。
    foreach (throttlePremiseRenderOnlyUris() as $uri) {
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $response = $this->get($uri);
        expect($response->getStatusCode())->toBe(200, "{$uri} が 200 を返しません");

        $writes = array_values(array_filter($queries, throttlePremiseIsWriteStatement(...)));
        expect($writes)->toBe([], "{$uri} が DB 書込を発行しました: ".implode(' / ', $writes));
    }
});

test('register の invitation token 分岐も DB 書込を発行しない (read 1 件で済むことの固定)', function (): void {
    // 上の代表 GET は token 無しの経路しか通らない。register は session に
    // invitation_token があると OrganizationMembershipService::resolveRegisterPrefillEmail() が
    // 招待を 1 件 read する **別の分岐**を持つため、そちらも読み取りに留まることを固定する
    // (「prefill のついでに何かを書く」実装へ変わったら exemption 理由が崩れる)。
    $this->withSession(['invitation_token' => 'probe-token-that-does-not-exist']);

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->get('/register');
    expect($response->getStatusCode())->toBe(200);

    // ★分岐に入ったことの確認 (入っていなければ token 無しのテストと同じものを 2 回
    //   走らせているだけ = 空振り green になる)。
    $invitationReads = array_values(array_filter(
        $queries,
        static fn (string $sql): bool => str_contains($sql, 'organization_invitations'),
    ));
    expect($invitationReads)->not->toBe([], 'invitation token 分岐に入っていません (テストが空振りしています)');

    $writes = array_values(array_filter($queries, throttlePremiseIsWriteStatement(...)));
    expect($writes)->toBe([], '/register (token あり分岐) が DB 書込を発行しました: '.implode(' / ', $writes));
});

test('social.redirect は外向き HTTP を発行しない (Socialite の redirect は URL 組み立てのみ)', function (): void {
    // AuthFlowInitiationWithoutOutboundCall の適用条件 2 番目。
    // ★DB 書込 0 件は要求しない: redirect は session へ intent と OAuth state を書く。
    //   本 case の適用条件は「外向き HTTP を発行しない」「状態が自セッションに閉じる」
    //   「完了経路が throttle 済み」であって DB 書込の有無ではない。
    //   条件に無いものを検査すると、session driver を変えただけで green/red が動く。
    // ★この 1 行は tests/Pest.php のレーン既定と**同値の重複宣言**であり、後方互換の並走ではない
    //  (Factory::preventStrayRequests は冪等。allowStrayRequests は呼んでいないので
    //   レーン既定の loopback 許可集合を置換しない)。このテストの意図
    //   「ここで外向き HTTP が起きないこと」を呼び出し側に明示する目的で残す。
    Http::preventStrayRequests();
    $requests = [];
    throttlePremiseInstallSocialiteHttpSpy($requests);

    $response = $this->get('/auth/google/redirect/login');

    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toContain('accounts.google.com');
    expect($requests)->toBe([], 'social.redirect が外向き HTTP を発行しました');
});

test('social.redirect の exemption 前提: 対になる social.callback が throttle:social-callback をちょうど 1 本持つ', function (): void {
    // 適用条件 4 番目 (完了経路が throttle 済み)。callback の throttle を外す /
    // 別 limiter に差し替えると、social.redirect を免除している根拠が崩れるためここで fail する。
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    $callback = $routes->getByName('social.callback');
    // ★expect()->not->toBeNull() は PHPStan の型を絞らない。
    //   level 10 で throttleEntries(Router, Route) に渡すため Assert で narrowing する。
    Assert::isInstanceOf($callback, RoutingRoute::class);

    // ★throttleEntries() は gatherRouteMiddleware() の**解決後**の実効 middleware 列を
    //   filter する (第 3 段の付与台帳ではないため、routes/web.php 直書きの第 1 段も現れる)。
    $entries = RouteThrottleBinder::throttleEntries($router, $callback);

    expect($entries)->toHaveCount(1);
    expect(Str::after($entries[0], ':'))->toBe('social-callback');
});

/**
 * 実 Socialite driver に mock HTTP client を仕込み、発行された外向き要求を `$requests` に記録する。
 *
 * ★Socialite ファサードごと mock しない (state 照合の実装まで消えてしまい、
 *   「外向き HTTP へ進まないこと」の証明が空振りになる)。実 provider を使い、
 *   Guzzle の handler だけを差し替える。
 *
 * ★差し替えは **config 経由** (`services.google.guzzle`) で行う。
 *   `Socialite::driver()->setHttpClient()` をテスト側で先に呼ぶ方式は使えない:
 *   SocialiteManager::buildProvider() が構築時点の `Request` を provider に焼き込むため
 *   (Manager がインスタンスをキャッシュする)、テスト側で先に解決すると
 *   **session を持たない Request** を掴んで 500 になる。
 *   config 経由なら provider はリクエスト処理中に構築され、正しい Request を持つ。
 *
 * ★`$requests` は**参照渡し**でなければならない (Guzzle の history middleware は
 *   コンテナへの参照を保持する。値で返すと記録が呼び出し側に届かず、
 *   常に空配列 = 何も検査していないテストになる)。
 *
 * @param  array<int, mixed>  $requests  Guzzle の history (呼ばれた分だけ積まれる)
 */
function throttlePremiseInstallSocialiteHttpSpy(array &$requests): void
{
    // client id/secret は .env に無くてよい (driver 構築のためだけのダミー)。
    config()->set('services.google.client_id', 'probe-client-id');
    config()->set('services.google.client_secret', 'probe-client-secret');

    // token 交換 → user 取得の 2 発分を用意する (negative control が最後まで進めるように)。
    $stack = HandlerStack::create(new MockHandler([
        new GuzzleResponse(200, [], '{"access_token":"probe-token"}'),
        new GuzzleResponse(200, [], '{"sub":"probe-subject","email":"probe@example.com"}'),
    ]));
    $stack->push(Middleware::history($requests));
    config()->set('services.google.guzzle', ['handler' => $stack]);

    throttlePremiseForgetSocialiteDrivers();
}

/**
 * Socialite の driver キャッシュを捨てる (次の解決で provider が作り直される)。
 *
 * ★本番では 1 リクエスト = 1 プロセスなので provider は毎回作り直され、
 *   常に**そのリクエストの** Request を持つ。テストでは 1 つの app インスタンスで
 *   複数リクエストを流すため、捨てないと provider が最初のリクエストを掴んだままになり、
 *   callback の `code` / `state` を読めず「state 照合以外の理由で止まった」空振り green になる。
 *   各リクエストの前に呼んで本番と同じ条件を作る。
 */
function throttlePremiseForgetSocialiteDrivers(): void
{
    $manager = Socialite::getFacadeRoot();
    Assert::isInstanceOf($manager, SocialiteManager::class);
    $manager->forgetDrivers();
}

/** redirect 応答の Location から OAuth state を取り出す。 */
function throttlePremiseStateFromRedirect(TestResponse $response): string
{
    $location = $response->headers->get('Location');
    Assert::string($location);
    $query = parse_url($location, PHP_URL_QUERY);
    Assert::string($query);
    parse_str($query, $params);
    Assert::keyExists($params, 'state');
    Assert::string($params['state']);

    return $params['state'];
}

test('別セッションで発行した state では callback が外向き HTTP へ進まない (state が自セッションに閉じる)', function (): void {
    // AuthFlowInitiationWithoutOutboundCall の適用条件 3 番目の behavioral proof。
    // ソース走査 (stateless( の不在) だけでは表記ゆれ / helper 経由を検出できないため実挙動で示す。
    // ★成立条件: セッション B 側に正しい social_auth_intent を持たせること。
    //   intent があれば controller は短絡せず Socialite::driver()->user() まで進み、
    //   止まるのは AbstractProvider::hasInvalidState() **だけ**になる。
    // ★この 1 行は tests/Pest.php のレーン既定と**同値の重複宣言**であり、後方互換の並走ではない
    //  (Factory::preventStrayRequests は冪等。allowStrayRequests は呼んでいないので
    //   レーン既定の loopback 許可集合を置換しない)。このテストの意図
    //   「ここで外向き HTTP が起きないこと」を呼び出し側に明示する目的で残す。
    Http::preventStrayRequests();
    $requests = [];
    throttlePremiseInstallSocialiteHttpSpy($requests);

    // --- セッション A: state を 1 つ発行して控える ---
    $stateA = throttlePremiseStateFromRedirect($this->get('/auth/google/redirect/login'));

    // --- セッション B: 別セッションを作り、B 自身の state と intent を持たせる ---
    $this->flushSession();
    throttlePremiseForgetSocialiteDrivers();
    $this->get('/auth/google/redirect/login');

    // --- B のセッションで A の state を使って callback ---
    throttlePremiseForgetSocialiteDrivers();
    $response = $this->get('/auth/google/callback?code=dummy&state='.$stateA);

    // ★核心: 外向き HTTP が 1 件も出ていない (state 照合が token 交換より前で止めた)
    expect($requests)->toBe([], '別セッションの state で外向き HTTP が発生しました');

    // ログイン成立経路へ進んでいない
    expect((string) $response->headers->get('Location'))->not->toContain('/dashboard');
    expect(auth()->check())->toBeFalse();
});

test('negative control: 自セッションの state なら callback は実際に外向き HTTP へ進む (spy が機能している証明)', function (): void {
    // ★上のテストが「外向き HTTP 0 件」で green になるのは、
    //   (a) state 照合が止めたから か (b) spy / driver 差し替えが壊れて何も観測していないから
    //   のどちらでもありうる。(b) を排除する対照実験がこれ。
    //   この対照が落ちたら上のテストの green は無意味になっているので、両方を必ず一緒に直すこと。
    // ★この 1 行は tests/Pest.php のレーン既定と**同値の重複宣言**であり、後方互換の並走ではない
    //  (Factory::preventStrayRequests は冪等。allowStrayRequests は呼んでいないので
    //   レーン既定の loopback 許可集合を置換しない)。このテストの意図
    //   「ここで外向き HTTP が起きないこと」を呼び出し側に明示する目的で残す。
    Http::preventStrayRequests();
    $requests = [];
    throttlePremiseInstallSocialiteHttpSpy($requests);

    throttlePremiseForgetSocialiteDrivers();
    $state = throttlePremiseStateFromRedirect($this->get('/auth/google/redirect/login'));

    // 同一セッションの state をそのまま返す = hasInvalidState() が成立せず token 交換へ進む
    throttlePremiseForgetSocialiteDrivers();
    $this->get('/auth/google/callback?code=dummy&state='.$state);

    expect($requests)->not->toBe([],
        '自セッションの state でも外向き HTTP が観測されません。spy か driver 差し替えが壊れており、'
        .'「別セッションの state では進まない」テストが空振り green になっています。');
});

test('SocialAuthController は stateless() を使わない (state 照合を無効化する最短経路の封鎖)', function (): void {
    // ソース走査は**補助**。単独の根拠にはしない (上の実挙動テストが本体)。
    // stateless() 化は state 照合を丸ごと無効化する最短経路なので二重に塞ぐ。
    $source = file_get_contents(app_path('Http/Controllers/Auth/SocialAuthController.php'));
    expect($source)->toBeString();
    expect($source)->not->toContain('stateless(');
});

test('filament.admin.auth.multi-factor-authentication.set-up-required の GET は MFA 秘密を生成・永続化しない', function (): void {
    // AuthViewRenderOnly の適用条件「秘密を開示・生成しない」の behavioral proof。
    // vendor (Filament) は現状 SetUpAppAuthenticationAction::mountUsing() = Livewire POST 側で
    // generateSecret() / generateRecoveryCodes() を呼ぶが、将来 mount() 側へ移ると
    // **GET が秘密生成 endpoint に変わる**。そのとき inventory は無音で通り続けるため固定する。
    // ★DB 書込 0 件検査は phpunit.xml の SESSION_DRIVER=array 固定に依存する (上記と同じ前提)。
    $admin = AdminUser::factory()->create();   // MFA 未設定
    $this->actingAs($admin, 'admin');

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->get('/admin/multi-factor-authentication/set-up');
    expect($response->getStatusCode())->toBe(200);

    $writes = array_values(array_filter($queries, throttlePremiseIsWriteStatement(...)));
    expect($writes)->toBe([], 'GET が DB 書込を発行しました: '.implode(' / ', $writes));

    $fresh = $admin->fresh();
    Assert::isInstanceOf($fresh, AdminUser::class);
    expect($fresh->app_authentication_secret)->toBeNull();
    expect($fresh->app_authentication_recovery_codes)->toBeNull();
});
