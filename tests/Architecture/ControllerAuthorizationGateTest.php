<?php

declare(strict_types=1);

use App\Enums\Security\ControllerAuthorizationExemption;
use Illuminate\Support\Facades\Route;
use Tests\Support\AuthorizationMarkerScanner;

/*
 * 変更系 route の認可 invariant (deny-by-default)。
 *
 * 「状態を変える route (POST/PUT/PATCH/DELETE) のハンドラは、必ず認可判断を 1 回通る」
 * を機械強制する。通らないものは理由付きで exemption inventory へ明示登録させる。
 *
 * ★本テストの核心は「何を認可と認めないか」:
 *   membership binder (MembershipScopedOrganizationBinder) / resolveOrganization 系 /
 *   auth・verified・recent-auth・require-active-subscription・api-key.ability middleware /
 *   FormRequest::authorize() は **合格条件に数えない**。
 *   これらはテナント境界 (層 2) や認証・契約状態であって認可 (層 3) ではなく、
 *   数えると gate が形骸化する。
 *
 * ★受理する認可手段は Gate ファサード 1 系統のみ:
 *   - can: middleware は Controller より前に走るため、inline guard 方式の route で
 *     「認可より前に 404」(不変条件 2) を壊す (cross-org が 403 になり存在が漏れる)。
 *   - $this->authorize() は base Controller が AuthorizesRequests trait を持たず呼べない。
 *   いずれも使用実績 0 件のため受理しない (使いたくなったら本テストごと設計し直す)。
 *
 * 本テストは「認可判断の入口が存在しない route を作らせない」役割に限定する。
 * 認可の**内容**の正当性 (対象が正しいか / Policy が妥当か / actor が正しいか) は
 * 各 Feature / Policy テストの責務 (NestedRouteIdorDefenseTest と同じ責務設計)。
 *
 * 字句解析は tests/Support/AuthorizationMarkerScanner に切り出し、解析器自体の
 * positive/negative は tests/Unit/Architecture/AuthorizationMarkerScannerTest が固定する。
 */

/** 変更系 HTTP メソッド。 */
function controllerAuthorizationMutatingMethods(): array
{
    return ['POST', 'PUT', 'PATCH', 'DELETE'];
}

/** 候補数の下限 (空振り drift ガード。実測に対し余裕を持たせた値。上限は設けない)。 */
function controllerAuthorizationRouteFloor(): int
{
    return 40;
}

/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
function controllerAuthorizationReasonMinLength(): int
{
    return 30;
}

/** inline URL 整合 guard とみなすメソッド名 (認可より前に 404 を返す層 2b)。 */
function controllerAuthorizationInlineGuards(): array
{
    return ['resolveOrganizationProject', 'resolveProjectItem', 'resolveOrganizationMember'];
}

/**
 * 認可を持たないことが正しいと裁定した route の inventory (型付き + 具体的根拠必須)。
 *
 * @return array<string, array{ControllerAuthorizationExemption, string}>
 */
function controllerAuthorizationExemptions(): array
{
    $membership = ControllerAuthorizationExemption::MembershipIsTheAuthorization;
    $noSubject = ControllerAuthorizationExemption::NoAuthorizableSubject;
    $selfScoped = ControllerAuthorizationExemption::SelfScopedResource;
    $tokenBearer = ControllerAuthorizationExemption::TokenBearerIsTheSubject;
    $scope = ControllerAuthorizationExemption::ScopeIsTheAuthorization;
    $public = ControllerAuthorizationExemption::PublicUnauthenticated;
    $signature = ControllerAuthorizationExemption::SignatureVerified;
    $localOnly = ControllerAuthorizationExemption::LocalOnlyDebugRoute;

    return [
        'organizations.store' => [$noSubject,
            '新規組織の作成。判定対象となる既存リソースも親テナントも存在しない'
            .'(誰でも自分の組織を作れる)。制約は verified.or-back middleware と'
            .'StoreOrganizationRequest のバリデーションのみ。'],

        'invitations.accept.store' => [$tokenBearer,
            '認可主体は「有効な招待トークンの保持者」。OrganizationMembershipService::acceptInvitation が'
            .'token hash 照合と失効/期限/受諾済み判定を行う。受諾前の user は対象組織の非メンバーであり、'
            .'組織 Policy を通すと構造的に必ず拒否になる (機能が成立しない)。'],

        'invitations.accept-in-app' => [$selfScoped,
            '対象は認証ユーザー自身宛の招待のみ。acceptPendingInvitation が '
            .'scopeActivePendingForEmail($user->email) の集合からしか解決せず、宛先不一致・不在・'
            .'期限切れ・取消済・削除済み組織宛はすべて 404 に畳まれる。受諾前の user は対象組織の'
            .'非メンバーであり組織 Policy は構造的に必ず拒否になるうえ、403 を返すこと自体が'
            .'招待の存在を教える口になるため Gate を置かない (層 2 の 404 が層 3 より前という不変条件)。'],

        'settings.account.destroy' => [$selfScoped,
            '対象は $request->user() 自身のみ。route に他者を指せる parameter が 1 つも無く、'
            .'他人のアカウントへ到達する経路がコード上存在しない。'
            .'別軸の防御として recent-auth (step-up) middleware を必須にしている。'],

        'settings.account.deletion-request.store' => [$selfScoped,
            '対象は $request->user() 自身の退会予約のみ。route に他者を指せる parameter が 1 つも無く、'
            .'他人のアカウントへ到達する経路がコード上存在しない。即時削除と同水準の機微操作のため'
            .'別軸の防御として recent-auth (step-up) middleware を必須にしている。'],

        'settings.account.deletion-request.destroy' => [$selfScoped,
            '対象は $request->user() 自身の退会予約の取消のみ。route に他者を指せる parameter が無く、'
            .'他人の予約へ到達する経路が存在しない。**誤操作救済の本体**であり権限を増やす操作でもないため、'
            .'step-up も含めて関門を置かない (救済経路に関門を足すと「取り消せない」詰みの再生産になる)。'],

        'settings.password.store' => [$selfScoped,
            '対象は $request->user() 自身のパスワード初回設定のみ。route に他者を指せる parameter が'
            .'無く、他人の credential へ到達する経路がコード上存在しない。'
            .'別軸の防御として recent-auth (step-up) middleware を必須にし、password 設定済みの'
            .'迂回は PasswordCredentialService が lock 下で fail-closed 拒否する。総当り防御は throttle:password-set。'],

        'recent-auth.password' => [$selfScoped,
            '自分の再認証鮮度 (RecentAuthState) の更新。route に他者を指せる parameter が無く、'
            .'認証そのものが主体判定であるため Policy による再判定に意味がない。'
            .'総当り防御は throttle:password-verify。'],

        'notifications.open' => [$selfScoped,
            'NotificationCenterService::findOwnOrFail($user, ...) が $user->notifications() 経由で'
            .'解決するため cross-user は構造的に 404 (存在オラクル封じ)。controller docblock が'
            .'「open は認可判断 (Gate) を一切複製しない」と明示し、遷移先 projects.manuals.show が'
            .'唯一の判断点、という設計を意図的に採っている。'],

        'notifications.read' => [$selfScoped,
            'notifications.open と同じく findOwnOrFail による自通知限定解決 (cross-user は 404)。'
            .'既読化は自分の通知状態の変更に閉じ、他者に影響しない。'],

        'notifications.read-all' => [$selfScoped,
            'markAllRead($user) で自分宛の通知のみを対象にする。route に parameter が無く、'
            .'他者の通知へ到達する経路が存在しない。'],

        'api.v1.me.session.revoke' => [$scope,
            '失効対象は actor 自身の OAuth session (ApiActorContext::$oauthSessionId と一致する 1 件) のみ。'
            .'加えて abort_unless($actor->hasScope(SessionRevoke), 403) という明示的な 403 判定が既にあり、'
            .'Policy 対象となる他者リソースが存在しない。'],

        'contact.store' => [$public,
            '公開問い合わせフォーム (auth 不要 = 認可すべき主体が存在しない)。'
            .'防御は throttle:inquiry (IP 単独 + IP+email の 2 系統) + honeypot + reCAPTCHA。'],

        'webhooks.ses' => [$signature,
            'SNS 署名検証 (sns.signature = VerifySnsSignature middleware) が唯一の防御線で、'
            .'TopicArn allowlist は空なら全拒否の fail-closed。人間の actor が存在しない'
            .'machine-to-machine 経路のため Policy 判定の主体を定義できない。'],

        'debug.login-as' => [$localOnly,
            'local / unit test 実行時のみ route が登録され staging/production では登録自体が起きない'
            .'(routes/web.php の if (app()->isLocal() || app()->runningUnitTests()) による fail-safe)。'
            .'加えて LocalOnly middleware (local 以外 404 + Basic 認証 + 未設定 404) が二重防御。'],
    ];
}

/** @return list<Illuminate\Routing\Route> 変更系 HTTP メソッドを持つ全 route。 */
function controllerAuthorizationMutatingRoutes(): array
{
    $methods = controllerAuthorizationMutatingMethods();
    $routes = [];

    foreach (Route::getRoutes() as $route) {
        if (array_intersect($methods, $route->methods()) !== []) {
            $routes[] = $route;
        }
    }

    return $routes;
}

/** route の識別子 (violation メッセージ用: 名前 / URI / HTTP メソッド)。 */
function controllerAuthorizationRouteLabel(Illuminate\Routing\Route $route): string
{
    $methods = implode('|', array_intersect($route->methods(), controllerAuthorizationMutatingMethods()));

    return ($route->getName() ?? '(無名)').' ['.$methods.' '.$route->uri().']';
}

/**
 * route ハンドラのソース断片を fail-secure に解決する。
 *
 * 解決できない場合は「認可なし」ではなく **解決失敗** として返す (合格側に倒さない)。
 *
 * @return array{status: 'ok', file: string, fragment: string}|array{status: 'vendor'}|array{status: 'fail', reason: string}
 */
function controllerAuthorizationHandlerSource(Illuminate\Routing\Route $route): array
{
    $uses = $route->getAction('uses');

    try {
        if (is_string($uses)) {
            $parts = explode('@', $uses);
            if (count($parts) !== 2) {
                return ['status' => 'fail', 'reason' => "action 'uses' が Class@method 形式でない: {$uses}"];
            }
            $reflection = new ReflectionMethod($parts[0], $parts[1]);
        } elseif ($uses instanceof Closure) {
            $reflection = new ReflectionFunction($uses);
        } else {
            return ['status' => 'fail', 'reason' => "action 'uses' が string でも Closure でもない: ".get_debug_type($uses)];
        }
    } catch (Throwable $e) {
        return ['status' => 'fail', 'reason' => 'Reflection の生成に失敗: '.$e->getMessage()];
    }

    $file = $reflection->getFileName();
    if ($file === false) {
        return ['status' => 'fail', 'reason' => 'getFileName() が false (内部関数)'];
    }
    $real = realpath($file);
    if ($real === false) {
        return ['status' => 'fail', 'reason' => "realpath() が false (ファイル不在): {$file}"];
    }

    // パッケージ所有 route はパッケージ側が防御を担う。パス境界込みで判定し
    // `vendor-foo/` のような prefix 一致の誤判定を防ぐ
    $vendorDir = realpath(base_path('vendor'));
    if (is_string($vendorDir) && str_starts_with($real, $vendorDir.DIRECTORY_SEPARATOR)) {
        return ['status' => 'vendor'];
    }

    $lines = file($real);
    if ($lines === false) {
        return ['status' => 'fail', 'reason' => "file() が false (読み取り不可): {$real}"];
    }

    $start = $reflection->getStartLine();
    $end = $reflection->getEndLine();
    if ($start === false || $end === false) {
        return ['status' => 'fail', 'reason' => 'getStartLine()/getEndLine() が false'];
    }

    $fragment = implode('', array_slice($lines, $start - 1, $end - $start + 1));
    if (trim($fragment) === '') {
        return ['status' => 'fail', 'reason' => "切り出したハンドラ断片が空: {$real}:{$start}-{$end}"];
    }

    return ['status' => 'ok', 'file' => $real, 'fragment' => $fragment];
}

test('変更系 route の候補は下限を下回らない (空振り drift ガード)', function (): void {
    $candidates = 0;

    foreach (controllerAuthorizationMutatingRoutes() as $route) {
        if (controllerAuthorizationHandlerSource($route)['status'] === 'vendor') {
            continue;
        }
        $candidates++;
    }

    expect($candidates)->toBeGreaterThanOrEqual(
        controllerAuthorizationRouteFloor(),
        "アプリ所有の変更系 route が {$candidates} 件しか検出されませんでした。"
        .'route 走査/解決ロジックが空振りしている可能性があります。',
    );
});

test('変更系 route のハンドラはすべて Reflection で解決できる (fail-secure)', function (): void {
    $violations = [];

    foreach (controllerAuthorizationMutatingRoutes() as $route) {
        $resolved = controllerAuthorizationHandlerSource($route);
        if ($resolved['status'] === 'fail') {
            $violations[] = controllerAuthorizationRouteLabel($route).': '.$resolved['reason'];
        }
    }

    expect($violations)->toBe([],
        'ハンドラを解決できない変更系 route があります (認可の有無を判定できないため fail させます)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('変更系 route は認可を持つか exemption inventory に明示分類されている (未知は fail)', function (): void {
    $inventory = controllerAuthorizationExemptions();
    $violations = [];
    $checked = 0;

    foreach (controllerAuthorizationMutatingRoutes() as $route) {
        $resolved = controllerAuthorizationHandlerSource($route);
        if ($resolved['status'] === 'vendor') {
            continue;
        }
        if ($resolved['status'] === 'fail') {
            // 解決失敗は専用テストが詳細を出す。ここでは合格に倒さないことだけ担保する
            $violations[] = controllerAuthorizationRouteLabel($route).': ハンドラを解決できませんでした';

            continue;
        }
        $checked++;

        $name = $route->getName();

        if (AuthorizationMarkerScanner::hasAuthorizationMarker($resolved['fragment'])) {
            // 同名の別クラスによる誤合格を防ぐ: Facade の名前空間 import を必須にする
            $source = file_get_contents($resolved['file']);
            if ($source === false || ! AuthorizationMarkerScanner::importsGateFacade($source)) {
                $violations[] = controllerAuthorizationRouteLabel($route)
                    .': Gate:: の認可マーカーはあるが use Illuminate\Support\Facades\Gate; の'
                    .' import がありません (同名の別クラスの可能性があるため合格にしません)';
            }

            continue;
        }

        if ($name !== null && array_key_exists($name, $inventory)) {
            continue;
        }

        $violations[] = controllerAuthorizationRouteLabel($route).' が未分類';
    }

    expect($violations)->toBe([],
        '認可判断 (Gate::authorize / Gate::forUser(...)->authorize) を持たない変更系 route があります。'
        .'ハンドラに認可を足すか、認可が不要な理由を controllerAuthorizationExemptions() に'
        .'ControllerAuthorizationExemption + 具体的根拠付きで登録してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));

    expect($checked)->toBeGreaterThan(0);
});

test('exemption inventory の key は現存 named route (逆方向整合・stale 検出)', function (): void {
    $named = [];
    foreach (Route::getRoutes() as $route) {
        $n = $route->getName();
        if ($n !== null) {
            $named[$n] = true;
        }
    }

    $stale = [];
    foreach (array_keys(controllerAuthorizationExemptions()) as $key) {
        if (! isset($named[$key])) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([],
        'exemption inventory に現存しない route 名 (削除/rename 済) があります: '.implode(', ', $stale));
});

test('exemption inventory の値は enum + 実質的な理由文字列', function (): void {
    $minLength = controllerAuthorizationReasonMinLength();
    $violations = [];

    foreach (controllerAuthorizationExemptions() as $name => [$exemption, $reason]) {
        if (! $exemption instanceof ControllerAuthorizationExemption) {
            $violations[] = "{$name}: 第 1 要素が ControllerAuthorizationExemption ではありません";
        }
        if (mb_strlen($reason) < $minLength) {
            $violations[] = "{$name}: 理由が {$minLength} 文字未満です (「同上」「N/A」で埋める運用を止めます)";
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

/*
 * 順序契約: **すべての URL 整合 guard は、すべての認可判断より前**に置く。
 *
 * 「最初の認可より後ろに guard が 1 つでもあれば違反」という厳格な形にしてある。
 * 認可を 2 回以上呼ぶハンドラで「1 回目の認可 → guard → 2 回目の認可」という配置は、
 * 1 回目の認可が cross-org を 403 で弾いてしまい存在が漏れるため、意図的に違反とする
 * (テナント境界の確定は必ず全認可に先行する = 層 2 → 層 3 の順序は不可侵)。
 */
test('URL 整合 guard は認可より前に置かれている (不変条件 2)', function (): void {
    $guards = controllerAuthorizationInlineGuards();
    $violations = [];
    $checked = 0;

    foreach (controllerAuthorizationMutatingRoutes() as $route) {
        $resolved = controllerAuthorizationHandlerSource($route);
        if ($resolved['status'] !== 'ok') {
            continue;
        }

        $guardOffsets = AuthorizationMarkerScanner::guardMarkerOffsets($resolved['fragment'], $guards);
        $authOffset = AuthorizationMarkerScanner::authorizationMarkerOffset($resolved['fragment']);
        if ($guardOffsets === [] || $authOffset === null) {
            continue;
        }
        $checked++;

        // guard が 2 段ある route では **全件** が認可より前でなければならない
        // (片方だけ後ろに移動した壊れ方を見逃さない)
        if (max($guardOffsets) > $authOffset) {
            $violations[] = controllerAuthorizationRouteLabel($route)
                .': URL 整合 guard が認可 (Gate) より後に置かれています'
                .' (cross-org が 404 ではなく 403 を返し、リソースの存在が漏れます)';
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
    // guard と認可の両方を持つ route が 1 本も無い = 順序検証が空振りしている
    expect($checked)->toBeGreaterThan(0);
});
