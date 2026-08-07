<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\Billing\InsufficientTicketsException;
use App\Exceptions\Billing\QuotaExceededException;
use App\Exceptions\InertiaExceptionRenderer;
use App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations;
use App\Http\Middleware\BughuntCoverageMiddleware;
use App\Http\Middleware\EnforceMcpTransport;
use App\Http\Middleware\EnsureEmailIsVerifiedOrBack;
use App\Http\Middleware\EnsureLoginMethodRemains;
use App\Http\Middleware\EnsureProjectBelongsToApiOrganization;
use App\Http\Middleware\EnsureProjectBelongsToCurrentOrganization;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\McpConsentOrganizationBinder;
use App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages;
use App\Http\Middleware\NoStoreResponse;
use App\Http\Middleware\RedirectToHttps;
use App\Http\Middleware\RequireActiveSubscription;
use App\Http\Middleware\RequireApiKeyAbility;
use App\Http\Middleware\RequireRecentAuth;
use App\Http\Middleware\RequireRecentAuthOnEmailChange;
use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
use App\Http\Middleware\ResolveApiActor;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyMcpOrigin;
use App\Http\Middleware\VerifySnsSignature;
use App\Http\Resources\Billing\InsufficientTicketsResource;
use App\Http\Resources\Billing\QuotaExceededResource;
use App\Support\Http\AdminPanelPath;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Inertia\Inertia;
use Inertia\Middleware\EncryptHistory;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         | HTTPS リダイレクト (FORCE_HTTPS_REDIRECT で有効化。LB 終端構成では off)。
         |
         | **prepend にしない**: Middleware::getGlobalMiddleware() は
         | array_merge($prepends, $global, $appends) を返すため、prepend すると
         | TrustProxies **より前**に走り、$request->isSecure() が X-Forwarded-Proto を
         | 見られない。LB 終端 + FORCE_HTTPS_REDIRECT=true で 308 の無限ループになる。
         | append することで TrustProxies の後・route group より前で走る。
         */
        $middleware->append(RedirectToHttps::class);

        /*
         | LB / reverse proxy 終端構成での X-Forwarded-* 信頼 (HTTPS 検出・client IP 復元)。
         |
         | `at:` は **渡さない**。Laravel の TrustProxies は
         |   $this->proxies() ?: config('trustedproxy.proxies')
         | の順で解決し、`TrustProxies::at()` を呼ばなければ config へ落ちる
         | (vendor/laravel/framework/src/Illuminate/Http/Middleware/TrustProxies.php)。
         | withMiddleware の callback は config 読込より前に走るため `at:` に closure は渡せず
         | (trustHosts と違い array|string|null のみ)、env 由来の allowlist は config 経由が唯一の道。
         |
         | かつて `at: '*'` だった。これは全アドレスを trusted proxy 扱いにするため
         | $request->ip() が XFF 最左 = **クライアントが自由に書ける値**になり、
         | IP ベースの rate limit / reCAPTCHA / 監査ログがすべて無効化されていた
         | (audit-cycle-2 High-2)。
         |
         | 設定は TRUSTED_PROXIES (config/trustedproxy.php)。production で未宣言なら
         | ProductionEnvGuard が起動時 fail-fast する。運用契約は docs/trusted-proxies-runbook.md。
         */
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Host header injection 防御。`config/trusted_hosts.php` で env 由来の許可 host を
        // 集約し、ここで regex pattern (`^host$`) に変換して TrustHosts middleware に渡す。
        // `preg_quote` で `.` を任意文字として誤ヒットさせない。production で allowlist が
        // 空のときは AppServiceProvider 側で ProductionEnvGuard (TrustedHostsConfigValidator)
        // が起動時 fail-fast する二重防御。
        $middleware->trustHosts(at: function (): array {
            /** @var array<int, mixed> $exact */
            $exact = (array) config('trusted_hosts.exact_hosts', []);
            /** @var array<int, mixed> $wildcard */
            $wildcard = (array) config('trusted_hosts.wildcard_suffixes', []);

            $patterns = [];
            foreach ($exact as $host) {
                if (! is_string($host) || $host === '') {
                    continue;
                }
                $patterns[] = '^'.preg_quote($host, '#').'$';
            }
            foreach ($wildcard as $suffix) {
                if (! is_string($suffix) || $suffix === '') {
                    continue;
                }
                $patterns[] = '^.+'.preg_quote($suffix, '#').'$';
            }

            return $patterns;
        });

        $middleware->web(append: [
            HandleInertiaRequests::class,
            SecurityHeaders::class,
            // 組織単位 2FA 強制: (1) 未準拠ユーザーの全画面ゲート → (2) 準拠ユーザーの
            // self-disable 禁止、の順 (disable route はゲートの allowlist 外のため、
            // 未準拠者の disable は (1) が先に弾く)
            RequireTwoFactorForEnforcedOrganizations::class,
            BlockTwoFactorDisableForEnforcedOrganizations::class,
            // 認証済み応答の no-store baseline。
            // 契約: $next から返った (= 下流の) 応答を確認し、既に `no-store` を持つなら変更しない。
            // (位置関係ではなくこの契約が正本。実効性は Feature テストが固定する)
            NoStoreCacheHeadersForAuthenticatedPages::class,
            // Inertia の履歴 state を AES-GCM で暗号化する (Inertia 公式のグローバル適用手順)。
            // ログアウト時に LogoutResponse が Inertia::clearHistory() で鍵を捨てるため、
            // ログアウト後の「戻る」は復号に失敗し、**コンポーネントを描画しないまま**
            // サーバへ再問い合わせ → /login に倒れる (bug-hunt F-4-01)。
            //
            // Inertia 面の認証済み画面が復元されうる経路と担当 (docs/supported-browsers.md が正本):
            //   A: HTTP/disk/proxy cache + Chrome/Firefox の bfcache → NoStoreCacheHeaders...
            //   B: Safari の真の bfcache (pagehide/pageshow)        → resources/js/lib/bfcache-guard.ts
            //   C: Inertia SPA の history 復元 (popstate)           → 本 middleware + Inertia::clearHistory()
            //
            // 認証済み route への限定適用にしない: 認証済み route は ['auth','verified'] グループの
            // 外にも複数あり (招待受諾 POST 等)、限定適用は inventory ドリフトを生む。
            // 公開ページの履歴も暗号化されるが PII は無く、コストはログアウト前エントリの
            // 再取得と remember/scroll 喪失に限られる。
            EncryptHistory::class,
        ]);

        // パスワード変更/リセット時に他デバイスのセッション・remember-me を確実に失効させるため、
        // web グループで AuthenticateSession (alias 'auth.session') を有効化する。
        // 各認証済みリクエストで session 保存の password_hash と現在ハッシュを照合し、不一致なら
        // 現在デバイスを logout する (guest は no-op)。Auth::logoutOtherDevices() の実効性はこの
        // middleware が担保する (Laravel 標準の "Log Out Other Browser Sessions" 構成)。
        // Filament panel は独自 middleware stack を持ち web グループを経由しないため二重適用にならない。
        $middleware->authenticateSessions();

        // REST API v1 / MCP の middleware alias (routes/api.php・routes/ai.php で使う)。
        // API キー認証は auth guard ('auth:api-key') に置換済みのため alias なし。
        // recent-auth は web の機微操作 route 用 (generic step-up 再認証)。
        // require-active-subscription は業務 route の課金ゲート (判定は BillingAccess 経由のみ)
        $middleware->alias([
            'recent-auth' => RequireRecentAuth::class,
            // profile 更新の email 変更時のみ step-up を課す条件付きゲート
            'recent-auth.on-email-change' => RequireRecentAuthOnEmailChange::class,
            // ログイン手段を減らす操作の関門 (投影後評価 + User 行ロックによる直列化)。
            // 付与対象は LoginMethodRemovalRouteTest が deny-by-default で強制する
            // (allowlist 外への付与も fail。$next を transaction 内で実行するため)
            'ensure-login-method' => EnsureLoginMethodRemains::class,
            // guest route の応答に no-store を保証する (認証済み baseline の対象外を補う)。
            // 現在の付与先は passkey.login-options (WebAuthn challenge を載せる guest route)
            'no-store' => NoStoreResponse::class,
            'require-active-subscription' => RequireActiveSubscription::class,
            // `verified` の web POST 向け代替。未認証時に back + error flash で元ページへ戻す
            // (context 別文言は EmailVerificationGateContext)。organizations.store /
            // organizations.invitations.store に withoutMiddleware('verified') とセットで付与。
            'verified.or-back' => EnsureEmailIsVerifiedOrBack::class,
            // web の {project} route の URL 整合 guard。cross-org の {project} を
            // FormRequest の DB ルール (unique/exists) より前に 404 へ落とす
            // (存在オラクル防止。網羅性は ProjectRouteCurrentOrgGuardTest が固定)。
            // **実行位置は上の priority list が正本** (SubstituteBindings 直後)。
            'project.in-current-org' => EnsureProjectBelongsToCurrentOrganization::class,
            // REST API v1 用の同等 guard (組織は API キー / OAuth token から確定するため
            // web セッションの current org とは解決元が違う = 別 alias)。
            // resolve.api-actor より後・api-key.ability より前・idempotent より前
            // (順序契約は routes/api.php / ProjectRouteCurrentOrgGuardTest /
            // TenantBoundaryOrderingTest。実行位置の正本は上の priority list)
            'api.project-in-org' => EnsureProjectBelongsToApiOrganization::class,
            'resolve.api-actor' => ResolveApiActor::class,
            'api-key.ability' => RequireApiKeyAbility::class,
            'idempotent' => IdempotentRequest::class,
            'mcp.origin' => VerifyMcpOrigin::class,
            'mcp.transport' => EnforceMcpTransport::class,
            'sns.signature' => VerifySnsSignature::class,
        ]);

        // McpConsentOrganizationBinder は /oauth/authorize の approve POST body の
        // organization_id を検証する際 $request->user() (web セッション由来) を読む。
        // config('passport.middleware') 経由の付与だと route の先頭 (web=StartSession /
        // Authenticate より前) に並び、実ブラウザでは user=null になるため、
        // priority list で Authenticate の後ろに置き session + auth 解決後に走らせる。
        $middleware->appendToPriorityList(
            AuthenticatesRequests::class,
            McpConsentOrganizationBinder::class,
        );

        /*
         | テナント境界 404 の位置を priority list で確定させる (audit-cycle-2 High-1)。
         |
         | 不在 id は SubstituteBindings が 404 にする。したがって **binding より後・テナント
         | guard より前**に 404 以外で短絡する middleware があると、「他組織に実在 = その短絡の
         | 応答 / 不在 = 404」という 1 bit の存在オラクルになる (課金ゲート 402/302・
         | verified 302・2FA 強制 302・Inertia version mismatch 409・ability 403 が該当した)。
         |
         | 対処は 2 段:
         |   1. ResolveApiActor を **binding より前**へ。actor 解決失敗 (401/403) を
         |      「不在 404 がまだ起きていない時点」で返す。同 middleware は route binding に
         |      依存しない ($request->route(...) を読まない) ため前倒し可能。
         |   2. テナント guard を **binding の直後**へ。以降のすべての短絡より前になる。
         |
         | 副作用: guard が 404 で短絡すると内側 (HandleInertiaRequests / SecurityHeaders /
         | NoStoreCacheHeaders / EncryptHistory) は走らない。これは binding 失敗 404 と同じ
         | 扱いであり、既存契約 (SecurityHeadersTest「binding 失敗 404 には Permissions-Policy が
         | 一切付かない」) と一致する = 不在と cross-org が応答ヘッダまで同一になる。
         |
         | 適用順序: ApplicationBuilder は appends → prepends の順に反映するため、
         | 鎖状 append (SubstituteBindings → API guard → web guard → …) の anchor は解決可能。
         |
         | ⚠ **priority list は「載っている middleware 同士の相対順序」しか強制しない**
         | (SortedMiddleware::sortMiddleware は priority map に無い要素を一切動かさない)。
         | したがって「guard を binding の直後に置く」には、現に両者の間に挟まっている
         | web グループの middleware も **guard より後**として priority list に載せる必要がある。
         | 載せずに guard だけ登録しても、guard は列の末尾に留まったまま何も動かない
         | (実測で確認済み。この落とし穴が audit-cycle-2 High-1 の再発経路そのもの)。
         | 下の web 鎖はそのための宣言であり、順序は §唯一の順序契約 と一致する。
         | 実測は TenantBoundaryOrderingTest が解決後の middleware 列で固定する。
         */
        $middleware->appendToPriorityList(
            SubstituteBindings::class,
            EnsureProjectBelongsToApiOrganization::class,
        );
        $middleware->appendToPriorityList(
            EnsureProjectBelongsToApiOrganization::class,
            EnsureProjectBelongsToCurrentOrganization::class,
        );
        // テナント guard より後に走ることを確定させる web グループの鎖
        // (guard を binding 直後まで引き上げるための「後続」宣言)。
        foreach ([
            [EnsureProjectBelongsToCurrentOrganization::class, HandleInertiaRequests::class],
            [HandleInertiaRequests::class, SecurityHeaders::class],
            [SecurityHeaders::class, RequireTwoFactorForEnforcedOrganizations::class],
            [RequireTwoFactorForEnforcedOrganizations::class, BlockTwoFactorDisableForEnforcedOrganizations::class],
            [BlockTwoFactorDisableForEnforcedOrganizations::class, NoStoreCacheHeadersForAuthenticatedPages::class],
            [NoStoreCacheHeadersForAuthenticatedPages::class, EncryptHistory::class],
            [EncryptHistory::class, EnsureEmailIsVerified::class],
            [EnsureEmailIsVerified::class, RequireActiveSubscription::class],
        ] as [$after, $append]) {
            $middleware->appendToPriorityList($after, $append);
        }
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            ResolveApiActor::class,
        );

        // Stripe webhook は署名検証 (Cashier middleware)、SES/SNS webhook は
        // SNS 署名検証 (VerifySnsSignature) で保護されるため CSRF 対象外
        $middleware->validateCsrfTokens(except: [
            'stripe/*',
            'ses/*',
        ]);

        // bug-hunt (LLM 探索的バグハント) 用コード到達カバレッジ観測器。
        // env(BUGHUNT_PCOV) と function_exists('\pcov\start') の二重 guard を通らない限り
        // 完全 no-op (handle は $next をそのまま返し、terminate は即 return)。pcov 未導入の
        // 本番/CI/dev には一切影響しない。有効化は scripts/bug-hunt-shard.sh provision --coverage 経由。
        $middleware->append(BughuntCoverageMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // api/* は Accept ヘッダに依らず常に JSON envelope を返す。加えて、XHR / fetch など
        // JSON を期待するリクエスト (expectsJson) では Laravel 既定どおり JSON でレンダリングする
        // (例: /recent-auth/password の postJson バリデーションエラーは 302 ではなく 422 JSON)。
        // ここで既定を api/* だけに狭めると web 外の JSON クライアントが redirect を受け取り破綻する。
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         | セッション終了を検知した契機で Inertia の履歴暗号鍵を捨てさせる (経路 C の拡張)。
         |
         | ログアウト (App\Http\Responses\Fortify\LogoutResponse) は「利用者が明示的に
         | 終わらせた」契機しか拾えない。セッション期限切れと、パスワード変更による
         | 他デバイスの強制ログアウト (Auth::logoutOtherDevices → web グループの
         | AuthenticateSession) は、どちらも AuthenticationException として現れる。
         | ここでフラグを積むと、着地の /login (Inertia 応答) が
         | session()->pull で消費し、そのタブの sessionStorage の履歴鍵が消える。
         | = **認証失敗を契機に、以後の「戻る」による復元を無効化する**
         |   (過去に遡って無効化するのではない。docs/supported-browsers.md が正本)。
         |
         | 応答自体は既定の unauthenticated() 処理に委ねる (**null を返して素通し**)。
         | Handler::render() は renderViaCallbacks() を AuthenticationException の既定分岐より
         | 先に呼び、callback が null を返せば既定処理へ進む (Laravel 12 実装)。
         | この「null で素通し」に依存するため、**Laravel の major 更新時に再確認する**
         | (壊れた場合は InertiaHistoryGuardTest が落ちる)。
         |
         | 積まない条件は 2 つだけ:
         |   - expectsJson(): Inertia 応答が返らないためフラグが宙に浮く
         |   - session 不在: そもそもフラグを置けない
         | `api/*` の明示判定は**置かない**。api グループ (withRouting の api:) は
         | StartSession を含まないため hasSession() が偽で既に抑止され、到達不能条件になる。
         | guards() では面を判別しない (web の auth は [null]、AuthenticateSession は ['web']、
         | Filament の Authenticate は override により [] になり、実装詳細に依存するため)。
         | その結果 /admin の認証失敗でもフラグは積まれるが、**安全側の偽陽性として許容**する
         | (影響は Inertia 面の履歴が 1 度だけ再キーされることだけ)。この偽陽性は
         | InertiaHistoryGuardTest が仕様として固定しており、Filament の認証失敗の実装が
         | 変わったら本コメントとテストを**一緒に**更新する。
         */
        $exceptions->render(function (AuthenticationException $exception, Request $request): ?Response {
            if ($request->expectsJson() || ! $request->hasSession()) {
                return null;
            }

            Inertia::clearHistory();

            return null;
        });

        // 課金系のドメイン例外は web では back + error flash に変換する
        // (API 経路では null を返して下の ApiExceptionRenderer に委ねる)
        $exceptions->render(function (QuotaExceededException $exception, Request $request) {
            if ($request->is('api/*')) {
                return null; // ApiExceptionRenderer に委譲
            }
            if ($request->expectsJson()) {
                // 撮影 PWA の XHR (upload-url 等) は 422 + JsonResource (back() の 302 を返さない)
                return QuotaExceededResource::make($exception)
                    ->response($request)
                    ->setStatusCode(422);
            }

            return back()->with('error', $exception->getMessage()); // 既存の web 挙動を維持
        });
        $exceptions->render(function (InsufficientTicketsException $exception, Request $request) {
            if ($request->is('api/*')) {
                return null; // ApiExceptionRenderer に委譲 (既存)
            }
            if ($request->expectsJson()) {
                // XHR (analyze 等) は 402 + JsonResource (response()->json() 直書きはしない)
                return InsufficientTicketsResource::make($exception)
                    ->response($request)
                    ->setStatusCode(402);
            }

            return back()->with('error', $exception->getMessage()); // 既存の web 挙動を維持
        });

        // REST API v1 の統一エラー envelope ({error: {code, message, status, details?}})。
        // api/* 以外 (web / Inertia) は null を返して既定レンダリングを保つ
        $exceptions->render(function (Throwable $exception, Request $request) {
            return ApiExceptionRenderer::render($exception, $request);
        });

        /*
         | 例外応答の最終整形。**このアプリで唯一の respond callback**。
         |
         | ⚠ Illuminate\Foundation\Exceptions\Handler の respondUsing() は
         |   $finalizeResponseCallback への**単純代入** (単一スロット・last-write-wins)。
         |   2 本目の respond callback を足すと、この callback が黙って無効化される。
         |   同じ理由で Inertia の handleExceptionsUsing (内部で respondUsing を呼ぶ) も使わない。
         |   分岐を増やすときは**必ずこの 1 本の中に足す**こと。
         |   (tests/Architecture/InertiaErrorScreenContractTest が登録箇所 1 件を機械強制する)
         |
         | 適用順:
         |   1. status < 400 / api/* / expectsJson … 触らない (ApiExceptionRenderer の封筒 JSON を守る)
         |   2. /admin 配下 … 運営者向け中立テンプレート (customer-facing と operator-facing の分離)
         |   3. Inertia XHR … Error 画面へ差し替え (InertiaExceptionRenderer が deny-by-default で判定)
         |   4. それ以外 … 自己完結 Blade (resources/views/errors/*.blade.php) のまま
         */
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();
            if ($status < 400 || $request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            $adminPath = AdminPanelPath::resolve();
            $isAdminPath = $request->is($adminPath) || $request->is($adminPath.'/*');
            if ($isAdminPath) {
                $adminView = $status >= 500 ? 'errors.admin.5xx' : 'errors.admin.4xx';
                if (! view()->exists($adminView)) {
                    return $response;
                }

                return response()->view($adminView, [
                    'status' => $status,
                    'adminPath' => $adminPath,
                ], $status);
            }

            return InertiaExceptionRenderer::render($response, $request) ?? $response;
        });
    })->create();
