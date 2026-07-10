<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Exceptions\Billing\InsufficientTicketsException;
use App\Exceptions\Billing\QuotaExceededException;
use App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations;
use App\Http\Middleware\BughuntCoverageMiddleware;
use App\Http\Middleware\EnforceMcpTransport;
use App\Http\Middleware\EnsureEmailIsVerifiedOrBack;
use App\Http\Middleware\EnsureProjectBelongsToCurrentOrganization;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\McpConsentOrganizationBinder;
use App\Http\Middleware\RedirectToHttps;
use App\Http\Middleware\RequireActiveSubscription;
use App\Http\Middleware\RequireApiKeyAbility;
use App\Http\Middleware\RequireRecentAuth;
use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
use App\Http\Middleware\ResolveApiActor;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyMcpOrigin;
use App\Http\Middleware\VerifySnsSignature;
use App\Http\Resources\Billing\InsufficientTicketsResource;
use App\Support\Http\AdminPanelPath;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // HTTPS リダイレクトは最外周 (FORCE_HTTPS_REDIRECT で有効化。LB 終端構成では off)
        $middleware->prepend(RedirectToHttps::class);

        // LB / reverse proxy 終端構成での X-Forwarded-* 信頼 (HTTPS 検出・client IP 復元)
        $middleware->trustProxies(
            at: '*',
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
        ]);

        // REST API v1 / MCP の middleware alias (routes/api.php・routes/ai.php で使う)。
        // API キー認証は auth guard ('auth:api-key') に置換済みのため alias なし。
        // recent-auth は web の機微操作 route 用 (generic step-up 再認証)。
        // require-active-subscription は業務 route の課金ゲート (判定は BillingAccess 経由のみ)
        $middleware->alias([
            'recent-auth' => RequireRecentAuth::class,
            'require-active-subscription' => RequireActiveSubscription::class,
            // `verified` の web POST 向け代替。未認証時に back + error flash で元ページへ戻す
            // (context 別文言は EmailVerificationGateContext)。organizations.store /
            // organizations.invitations.store に withoutMiddleware('verified') とセットで付与。
            'verified.or-back' => EnsureEmailIsVerifiedOrBack::class,
            // web の {project} route の URL 整合 guard。cross-org の {project} を
            // FormRequest の DB ルール (unique/exists) より前に 404 へ落とす
            // (存在オラクル防止。網羅性は ProjectRouteCurrentOrgGuardTest が固定)
            'project.in-current-org' => EnsureProjectBelongsToCurrentOrganization::class,
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

        // 課金系のドメイン例外は web では back + error flash に変換する
        // (API 経路では null を返して下の ApiExceptionRenderer に委ねる)
        $exceptions->render(function (QuotaExceededException $exception, Request $request) {
            return $request->is('api/*') ? null : back()->with('error', $exception->getMessage());
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

        // /admin (Filament 運営) 配下の error は運用者向け中立テンプレートへ分離する
        // (顧客向けマーケ文言の errors/4xx.blade.php を出さない = customer-facing と
        // operator-facing の error ページを分離)。判定は AdminPanelPath::resolve() に一本化。
        // API/JSON 経路は不変 (ApiExceptionRenderer が先に JSON 化する)。
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request) {
            $status = $response->getStatusCode();
            if ($status < 400 || $request->is('api/*') || $request->expectsJson()) {
                return $response;
            }

            $adminPath = AdminPanelPath::resolve();
            $isAdminPath = $request->is($adminPath) || $request->is($adminPath.'/*');
            if (! $isAdminPath) {
                return $response;
            }

            $adminView = $status >= 500 ? 'errors.admin.5xx' : 'errors.admin.4xx';
            if (! view()->exists($adminView)) {
                return $response;
            }

            return response()->view($adminView, [
                'status' => $status,
                'adminPath' => $adminPath,
            ], $status);
        });
    })->create();
