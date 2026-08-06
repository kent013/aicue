<?php

declare(strict_types=1);

use App\Enums\Security\ThrottleCoverageExemption;
use App\Support\Http\RouteThrottleBinder;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * 流量制限 (throttle) の付与漏れ invariant (deny-by-default)。
 *
 * 「保護対象群に属する route は throttle をちょうど 1 本持つ」を機械強制する。
 * 持たないものは理由付きで exemption inventory へ明示登録させる。
 *
 * ★保護対象群 (S1 ∪ S2 ∪ S3) は意図的に**過大に**取る:
 *   S1 は「未認証で本体に到達する」ことを主張しない。signed / 定数 405 スタブ /
 *   LocalOnly / 署名検証など、Authenticate 以外で本体到達を閉じる route も S1 に入る。
 *   **exemption の役割は「本体到達しない根拠を固定すること」**である
 *   (過小なセレクタはすり抜けを生むが、過大なセレクタは exemption 理由という形で
 *    根拠が文書化されるだけで済む)。
 *
 * ★実効 middleware 列は Router::gatherRouteMiddleware() で取得する
 *   (`route:list --json` は group 名 'web' が展開されず誤判定するため使わない)。
 *   throttle 判定は RouteThrottleBinder::isThrottleEntry() を唯一の判定点として共有する。
 */

/** 変更系 HTTP メソッド。 */
function throttleCoverageMutatingMethods(): array
{
    return ['POST', 'PUT', 'PATCH', 'DELETE'];
}

/** 認証面の route 名パターン (S3)。 */
function throttleCoverageAuthSurfacePattern(): string
{
    return '#^(login|logout|register|password\.|user-password\.|two-factor\.|passkey\.|verification\.'
        .'|recent-auth\.|invitations\.|settings\.password\.|social\.|filament\.admin\.auth\.)#';
}

/** 母集団件数の下限 (空振り drift ガード。実測 47 に対し余裕を持たせた値)。 */
function throttleCoverageRouteFloor(): int
{
    return 40;
}

/** exemption 件数の上限 (形骸化ガード)。 */
function throttleCoverageExemptionCap(): int
{
    return 14;
}

/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
function throttleCoverageReasonMinLength(): int
{
    return 30;
}

/**
 * throttle を持たないことが正しいと裁定した route の inventory (型付き + 具体的根拠必須)。
 *
 * @return array<string, array{ThrottleCoverageExemption, string}>
 */
function throttleCoverageExemptions(): array
{
    $metadata = ThrottleCoverageExemption::StaticMetadataResponse;
    $stub = ThrottleCoverageExemption::VendorMethodNotAllowedStub;
    $teardown = ThrottleCoverageExemption::SessionTeardownOnly;
    $localOnly = ThrottleCoverageExemption::LocalOnlyDebugRoute;
    $component = ThrottleCoverageExemption::ComponentLevelLimiter;
    $signature = ThrottleCoverageExemption::SignatureRequiredBeforeEffect;

    return [
        'mcp.oauth.authorization-server' => [$metadata,
            'Laravel\Mcp\Server\Registrar::authorizationServerMetadata() が config と url() と route() だけで'
            .'組む定数 JSON を返す。DB アクセス・暗号処理・外部呼び出し・メール送信を一切伴わないため、'
            .'連打しても増幅する処理コストが存在しない。前提は ThrottleExemptionPremiseTest が固定する。'],

        'mcp.oauth.authorization-server.nested' => [$metadata,
            '上記 authorization-server と同一ハンドラ。{path} は応答内容に影響せず (RFC 8414 の'
            .'path-insertion 形式に対応するためだけの別 URI)、定数 JSON を返す点も同じ。'],

        'mcp.oauth.protected-resource' => [$metadata,
            'Laravel\Mcp\Server\Registrar::protectedResourceMetadata() が同様に config と url() だけで'
            .'組む定数 JSON を返す。DB アクセス・暗号処理・外部呼び出しを伴わない。'],

        'mcp.oauth.protected-resource.nested' => [$metadata,
            '上記 protected-resource と同一ハンドラ。{path} は resource フィールドへ url() で'
            .'echo されるだけで、DB アクセス・暗号処理・外部呼び出しは一切起きない'
            .'(ThrottleExemptionPremiseTest が DB クエリ 0 件と resource 以外の不変を固定する)。'],

        'GET /api/v1/mcp' => [$stub,
            'Laravel\Mcp\Server\Registrar::web() が登録する response(\'\', 405)->header(\'Allow\', \'POST\') の'
            .'固定応答。MCP 仕様上の SSE 非対応表明であり、ハンドラは本体処理へ一切到達しない。'],

        'DELETE /api/v1/mcp' => [$stub,
            'GET と同じく Registrar::web() の定数 405 スタブ (Allow: POST)。session 終了 API 非対応の'
            .'表明であり本体処理へ到達しない。'],

        'logout' => [$teardown,
            'auth:web 必須。セッション破棄と Inertia::clearHistory() のみを行い、'
            .'推測可能な秘密を一切扱わないため失敗しても攻撃者が得る情報が無い。'],

        'filament.admin.auth.logout' => [$teardown,
            'Filament panel の logout。認証済みでのみ到達でき、セッション破棄以外の副作用が無い。'
            .'秘密の推測に使えないため連打しても攻撃者の利得が無い。'],

        'debug.login-as' => [$localOnly,
            'routes/web.php の if (app()->isLocal() || app()->runningUnitTests()) により'
            .'**production では route 登録自体が起きない** (testing では登録されるため母集団に現れる)。'
            .'加えて LocalOnly middleware (local 以外 404 + Basic 認証 + 未設定 404) が二重防御。'],

        'default-livewire.update' => [$component,
            'Filament 管理画面の全 Livewire 操作が相乗りする単一 endpoint。route 単位の bucket を貼ると'
            .'無関係な管理操作を巻き添えにする。実際の制限は component 内にあり'
            .'(Auth/Pages/Login.php の $this->rateLimit(5) / Auth/Pages/EditProfile.php の同 5)、'
            .'panel が公開する credential 面はそこで有界化されている。'
            .'この前提 (rateLimit の実在 + 公開される auth ページの集合) は'
            .'ThrottleExemptionPremiseTest が固定する。'],

        'storage.local.upload' => [$signature,
            'Illuminate\Filesystem\ReceiveFile::__invoke() が本体到達前に abort_unless('
            .'$request->boolean(\'upload\') && $request->hasValidRelativeSignature(), ...) で短絡し、'
            .'署名が無ければファイル書込を含む副作用がゼロになる。前提は ThrottleExemptionPremiseTest が固定する。'],
    ];
}

/** 解決後 middleware 列 (Closure を除いた文字列 entry のみ)。 */
function throttleCoverageResolvedMiddleware(RoutingRoute $route): array
{
    /** @var Router $router */
    $router = Route::getFacadeRoot();

    return array_values(array_filter(
        $router->gatherRouteMiddleware($route),
        static fn (mixed $entry): bool => is_string($entry),
    ));
}

/** 解決後 middleware 列に指定クラス (パラメータ付き entry を含む) があるか。 */
function throttleCoverageHasMiddlewareClass(RoutingRoute $route, string $class): bool
{
    foreach (throttleCoverageResolvedMiddleware($route) as $entry) {
        if (is_a(Str::before($entry, ':'), $class, true)) {
            return true;
        }
    }

    return false;
}

/**
 * route の inventory キー (名前があれば名前、無ければ `{METHOD} /{uri}`)。
 * HEAD は methods() から除外して主メソッドを使う。
 */
function throttleCoverageRouteLabel(RoutingRoute $route): string
{
    $name = $route->getName();
    if ($name !== null && $name !== '') {
        return $name;
    }

    $methods = array_values(array_diff($route->methods(), ['HEAD']));

    return implode('|', $methods).' /'.$route->uri();
}

/** @return list<RoutingRoute> 保護対象群 (S1 ∪ S2 ∪ S3)。 */
function throttleCoverageProtectedRoutes(): array
{
    $mutating = throttleCoverageMutatingMethods();
    $pattern = throttleCoverageAuthSurfacePattern();
    $protected = [];

    foreach (Route::getRoutes() as $route) {
        $isMutating = array_intersect($mutating, $route->methods()) !== [];
        $uri = $route->uri();
        $name = $route->getName() ?? '';

        // S1: 未認証で到達可能な可能性がある変更系
        $s1 = $isMutating
            && ! throttleCoverageHasMiddlewareClass($route, Authenticate::class);

        // S2: ステートレスな機械向け経路
        $s2 = (str_starts_with($uri, 'api/') || str_starts_with($uri, 'oauth/')
                || str_starts_with($uri, '.well-known/oauth-'))
            && ! throttleCoverageHasMiddlewareClass($route, StartSession::class);

        // S3: 認証済み側も含む credential 面
        $s3 = $isMutating && $name !== '' && preg_match($pattern, $name) === 1;

        if ($s1 || $s2 || $s3) {
            $protected[] = $route;
        }
    }

    return $protected;
}

test('保護対象 route の母集団が下限を下回らない (セレクタの空振り検出)', function (): void {
    $count = count(throttleCoverageProtectedRoutes());

    expect($count)->toBeGreaterThanOrEqual(
        throttleCoverageRouteFloor(),
        "保護対象 route が {$count} 件しか検出されませんでした。"
        .'セレクタ (S1/S2/S3) が空振りしている可能性があります。',
    );
});

test('保護対象 route は throttle をちょうど 1 本持つか exemption inventory に明示分類されている (未知は fail)', function (): void {
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $inventory = throttleCoverageExemptions();
    $violations = [];

    foreach (throttleCoverageProtectedRoutes() as $route) {
        $label = throttleCoverageRouteLabel($route);
        $entries = RouteThrottleBinder::throttleEntries($router, $route);

        if (count($entries) === 1) {
            continue;
        }

        if ($entries === [] && array_key_exists($label, $inventory)) {
            continue;
        }

        $violations[] = $entries === []
            ? "{$label}: throttle が 1 本も無く exemption inventory にも未登録"
            : "{$label}: throttle が ".count($entries).' 本ある ('.implode(', ', $entries).')';
    }

    expect($violations)->toBe([],
        '保護対象 route の throttle 付与が不正です。throttle を貼るか、'
        .'貼らないことが正しい理由を throttleCoverageExemptions() に'
        .'ThrottleCoverageExemption + 具体的根拠付きで登録してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('exemption inventory の key は現存する保護対象 route (stale 検出)', function (): void {
    $labels = [];
    foreach (throttleCoverageProtectedRoutes() as $route) {
        $labels[throttleCoverageRouteLabel($route)] = true;
    }

    $stale = [];
    foreach (array_keys(throttleCoverageExemptions()) as $key) {
        if (! isset($labels[$key])) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([],
        'exemption inventory に現存しない route ラベル (削除/rename 済、または throttle 付与済で'
        .'exemption が不要になったもの) があります: '.implode(', ', $stale));
});

test('exemption inventory の値は enum + 実質的な理由文字列', function (): void {
    $minLength = throttleCoverageReasonMinLength();
    $violations = [];

    foreach (throttleCoverageExemptions() as $label => [$exemption, $reason]) {
        if (! $exemption instanceof ThrottleCoverageExemption) {
            $violations[] = "{$label}: 第 1 要素が ThrottleCoverageExemption ではありません";
        }
        if (mb_strlen($reason) < $minLength) {
            $violations[] = "{$label}: 理由が {$minLength} 文字未満です (「同上」「N/A」で埋める運用を止めます)";
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('exemption 件数が上限を超えない (形骸化ガード)', function (): void {
    $count = count(throttleCoverageExemptions());

    expect($count)->toBeLessThanOrEqual(
        throttleCoverageExemptionCap(),
        "exemption が {$count} 件あります。セレクタが広すぎるか、throttle を貼るべき route を"
        .'exemption で逃がしている可能性があります (上限を上げる前に必ず再検討すること)。',
    );
});
