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

/** 母集団件数の下限 (空振り drift ガード。実測 70 に対し余裕を持たせた値)。 */
function throttleCoverageRouteFloor(): int
{
    return 60;
}

/** exemption 件数の上限 (形骸化ガード)。**現在値ちょうど** (exact fit)。 */
function throttleCoverageExemptionCap(): int
{
    // ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに
    //   免除できる枠」になる。exact fit なら次の 1 本が必ず「この数値を変える差分」
    //   として現れ、個別理由・前提テスト追加要否・そもそも貼るべきでないかの
    //   再検討を強制できる。上げる前に必ず再検討すること。
    return 25;
}

/**
 * exemption の case 別上限 (分類の偏り検出)。全体 cap とは役割が違う
 * (全体 = セレクタの広さ / case 別 = どのカテゴリが膨らんだか)。
 * ★array_sum() で全体 cap を導出しない (両方を独立に検査する)。
 *
 * @return array<string, int> ThrottleCoverageExemption::value => 上限
 */
function throttleCoverageExemptionCapByCase(): array
{
    return [
        ThrottleCoverageExemption::StaticMetadataResponse->value => 4,
        ThrottleCoverageExemption::VendorMethodNotAllowedStub->value => 2,
        ThrottleCoverageExemption::SessionTeardownOnly->value => 2,
        ThrottleCoverageExemption::LocalOnlyDebugRoute->value => 1,
        ThrottleCoverageExemption::ComponentLevelLimiter->value => 1,
        ThrottleCoverageExemption::SignatureRequiredBeforeEffect->value => 1,
        // ★ここが膨らむ = 「貼るべき route を描画系として逃がした」疑い。
        ThrottleCoverageExemption::AuthViewRenderOnly->value => 13,
        ThrottleCoverageExemption::AuthFlowInitiationWithoutOutboundCall->value => 1,
    ];
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
    $render = ThrottleCoverageExemption::AuthViewRenderOnly;
    $flowInit = ThrottleCoverageExemption::AuthFlowInitiationWithoutOutboundCall;

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

        // ─────────────────────────────────────────────────────────────
        // 認証面の非変更系 GET (T120 事後監査の是正で母集団に加わった 23 本のうち、
        // throttle を貼らないことが正しいと裁定した 14 本)。
        // 判断基準は「1 リクエストで外向き通信・重い計算・状態生成が起きるか」。
        // ─────────────────────────────────────────────────────────────

        'login' => [$render,
            'Fortify::loginView() が config(template.social_providers) のキー一覧だけを props にした '
            .'Inertia ページ (Auth/Login) を描画する。credential 検証は POST /login '
            .'(throttle:login) 側にあり、GET は DB 書込・外部呼び出し・メール送信を伴わない。'],

        'register' => [$render,
            'Fortify::registerView() の Inertia 描画。session に**自分で置いた** invitation_token が '
            .'ある場合のみ OrganizationMembershipService::resolveRegisterPrefillEmail() が招待を '
            .'1 件 read するが、token を持たない要求は DB へ到達しない。DB 書込・外部呼び出しは無い。'],

        'password.request' => [$render,
            'Fortify::requestPasswordResetLinkView() が props 無しの Inertia ページ '
            .'(Auth/ForgotPassword) を描画するだけ。メール送信は POST /forgot-password '
            .'(throttle:password-reset-request) 側で、GET は DB にも外部にも触れない。'],

        'password.reset' => [$render,
            'Fortify::resetPasswordView() が route parameter の token と query の email を props へ '
            .'写すだけの Inertia 描画。token の DB 照合は POST /reset-password '
            .'(throttle:password-reset-submit) 側で行われ、GET は token の有効性を判定しない '
            .'(応答が token に依存しないためオラクルにならない)。'],

        'two-factor.login' => [$render,
            'Fortify の TwoFactorAuthenticatedSessionController::create() が session の login.id に '
            .'対応する user の存在を read し、無ければ login へ 302 する。コード検証は '
            .'POST /two-factor-challenge (throttle:two-factor) 側。DB 書込・外部呼び出しは無い。'],

        'password.confirm' => [$render,
            'FortifyServiceProvider::configureViews() が confirmPasswordView を '
            .'recent-auth.confirm への 302 に差し替えており、応答は redirect 1 本のみ。'
            .'DB アクセス・外部呼び出し・秘密の開示を一切伴わない。'],

        'password.confirmation' => [$render,
            'Fortify の ConfirmedPasswordStatusController::show() が session の '
            .'auth.password_confirmed_at と設定値を比較した bool を返すだけ。auth 必須で '
            .'actor 自身の session 状態しか見ず、DB にも外部にも触れない。'],

        'recent-auth.confirm' => [$render,
            'auth 必須。ConfirmRecentAuthController::show() が actor 自身の recent-auth 鮮度と '
            .'利用可能な satisfier を props にした Inertia 描画を返す。password 検証は '
            .'POST /recent-auth/password (throttle:password-verify) 側にあり、GET は DB 書込を伴わない。'],

        'recent-auth.status' => [$render,
            'auth 必須の軽量プローブ。ConfirmRecentAuthController::status() が actor 自身の鮮度を '
            .'JsonResource で返し no-store を付けるだけで、DB 書込・外部呼び出し・'
            .'秘密の開示を伴わない (bfcache 再検証のため頻繁に叩かれる前提の endpoint)。'],

        'verification.notice' => [$render,
            'auth 必須。Fortify::verifyEmailView() が EmailVerificationContinuation::hasContinuation() '
            .'の bool だけを props にした Inertia 描画を返す。検証メールの再送は '
            .'POST /email/verification-notification (throttle:email-verification) 側で有界化されている。'],

        'filament.admin.auth.login' => [$render,
            'Filament panel のログインページ描画。credential 検証は Livewire の POST '
            .'(default-livewire.update) 側にあり、そこは ComponentLevelLimiter として登録済みで '
            .'Auth/Pages/Login の rateLimit(5) が実在する (ThrottleExemptionPremiseTest が固定)。'],

        'filament.admin.auth.profile' => [$render,
            'auth 必須の Filament プロフィールページ描画。パスワード変更等の実処理は Livewire POST '
            .'(default-livewire.update) 側にあり ComponentLevelLimiter で分類済み。'
            .'GET は actor 自身のフォーム描画のみで、秘密の生成も外部呼び出しも伴わない。'],

        'filament.admin.auth.multi-factor-authentication.set-up-required' => [$render,
            'auth 必須の Filament MFA 設定要求ページ描画 (SetUpRequiredMultiFactorAuthentication)。'
            .'TOTP 秘密とリカバリコードの生成は SetUpAppAuthenticationAction の mountUsing '
            .'(= Livewire POST / default-livewire.update) で起き、GET の描画では起きない '
            .'(ComponentLevelLimiter で分類済みの経路)。GET は導線リンクの描画のみ。'],

        'social.redirect' => [$flowInit,
            'SocialAuthController::redirect() は provider allowlist (config) と intent を検証し、'
            .'session へ intent と OAuth state を書いて IdP へ 302 するだけで、**その場では '
            .'外向き HTTP を発行しない**。外向き HTTP は対になる social.callback で起き、'
            .'そちらは throttle:social-callback で有界化されている (前提は Premise テストが固定)。'],
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

        // S3: credential 面 (認証済み側も含む)。
        // ★**メソッドを問わない** (GET/HEAD も母集団に入れる)。
        //   認証面は「読むだけ」の GET でも秘密の開示・外部呼び出し・状態生成を伴いうる。
        //   $isMutating を条件に残していた頃は認証面 GET が 1 本も母集団に入らず、
        //   パターン中の `social\.` は 1 件も一致しない**死んだ条件**だった
        //   (social route は 2 本とも GET)。
        // ★S1 (未認証の変更系) は $isMutating のまま残す。S1 まで GET へ広げると
        //   母集団が数百本になり、exemption 台帳に埋もれて gate が機能しなくなる。
        $s3 = $name !== '' && preg_match($pattern, $name) === 1;

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

test('exemption inventory の key は throttle を 1 本も持たない (死んだ exemption の検出)', function (): void {
    // ★既存の「ちょうど 1 本 or exemption」検査は count($entries) === 1 で先に continue するため、
    //   *throttle 済みなのに exemption にも登録されている* 状態を検出できない。
    //   stale 検出も「母集団に存在するか」しか見ないため素通りする。
    //   放置すると「もう不要な免除理由」が台帳に溜まり、次に読む人を誤らせる。
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $inventory = throttleCoverageExemptions();
    $violations = [];

    foreach (throttleCoverageProtectedRoutes() as $route) {
        $label = throttleCoverageRouteLabel($route);
        if (! array_key_exists($label, $inventory)) {
            continue;
        }

        $entries = RouteThrottleBinder::throttleEntries($router, $route);
        if ($entries !== []) {
            $violations[] = "{$label}: throttle ({$entries[0]}) が付いているのに exemption にも登録されています";
        }
    }

    expect($violations)->toBe([],
        'throttle を貼ったら exemption inventory から削除してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('認証面 GET 用の exemption case は非変更系 route にしか使われない', function (): void {
    // AuthViewRenderOnly / AuthFlowInitiationWithoutOutboundCall の適用条件 1 番目
    // (GET/HEAD のみ) を機械化する。変更系がこの箱に落ちると、
    // 「描画だから」という理由で副作用のある route が免除される。
    $getOnlyCases = [
        ThrottleCoverageExemption::AuthViewRenderOnly,
        ThrottleCoverageExemption::AuthFlowInitiationWithoutOutboundCall,
    ];
    $mutating = throttleCoverageMutatingMethods();
    $inventory = throttleCoverageExemptions();
    $violations = [];

    foreach (throttleCoverageProtectedRoutes() as $route) {
        $label = throttleCoverageRouteLabel($route);
        if (! array_key_exists($label, $inventory)) {
            continue;
        }
        if (! in_array($inventory[$label][0], $getOnlyCases, true)) {
            continue;
        }
        if (array_intersect($mutating, $route->methods()) !== []) {
            $violations[] = "{$label}: 変更系 (".implode('|', $route->methods()).') に GET 専用 case が使われています';
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('exemption の case 別件数が上限を超えない (分類の偏り検出)', function (): void {
    // ★走査対象は **enum の全 case**。使用中の case だけを見ると、
    //   「新しい case を足したが cap を決めていない」状態を検出できない
    //   (使い始めた瞬間に上限なしで通ってしまう)。
    $caps = throttleCoverageExemptionCapByCase();

    $counts = [];
    foreach (ThrottleCoverageExemption::cases() as $case) {
        $counts[$case->value] = 0;
    }
    foreach (throttleCoverageExemptions() as [$exemption, $reason]) {
        $counts[$exemption->value]++;
    }

    $violations = [];
    foreach ($counts as $case => $count) {
        if (! array_key_exists($case, $caps)) {
            $violations[] = "{$case}: throttleCoverageExemptionCapByCase() に上限が登録されていません";

            continue;
        }
        if ($count > $caps[$case]) {
            $violations[] = "{$case}: {$count} 件 (上限 {$caps[$case]})";
        }
    }

    // cap 側に enum に無い case が残っていないか (rename / 削除の stale 検出)
    foreach (array_keys($caps) as $case) {
        if (! array_key_exists($case, $counts)) {
            $violations[] = "{$case}: enum に存在しない case の上限が残っています";
        }
    }

    expect($violations)->toBe([],
        'exemption の case 別件数が上限を超えました。上限を上げる前に、'
        .'その case へ落とした route が本当に throttle 不要かを 1 本ずつ再検討してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
