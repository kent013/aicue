<?php

declare(strict_types=1);

namespace Tests\Support\Routing;

use App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations;
use App\Http\Middleware\BughuntCoverageMiddleware;
use App\Http\Middleware\BughuntExecutedRouteMiddleware;
use App\Http\Middleware\EnforceMcpTransport;
use App\Http\Middleware\EnsureAccountNotPendingDeletion;
use App\Http\Middleware\EnsureEmailIsVerifiedOrBack;
use App\Http\Middleware\EnsureLoginMethodRemains;
use App\Http\Middleware\EnsureProjectBelongsToApiOrganization;
use App\Http\Middleware\EnsureProjectBelongsToCurrentOrganization;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\LocalOnly;
use App\Http\Middleware\McpConsentOrganizationBinder;
use App\Http\Middleware\NoIndex;
use App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages;
use App\Http\Middleware\NoStoreResponse;
use App\Http\Middleware\RequireActiveSubscription;
use App\Http\Middleware\RequireApiKeyAbility;
use App\Http\Middleware\RequireRecentAuth;
use App\Http\Middleware\RequireRecentAuthOnEmailChange;
use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
use App\Http\Middleware\ResolveApiActor;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyMcpOrigin;
use App\Http\Middleware\VerifySnsSignature;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Inertia\Middleware\EncryptHistory;
use Livewire\Mechanisms\HandleRequests\RequireLivewireHeaders;

/**
 * 解決済み middleware クラス => 短絡しうるか (由来を問わず全件分類必須) の単一 source of truth。
 *
 * `true` = 3xx/4xx を返して $next を呼ばない分岐を持つ。
 * **既定は true 側に倒す** (疑わしきは短絡扱い)。`false` を宣言してよいのは
 * 「$next を必ず呼び、応答の加工しかしない」ことを実装で確認したときだけ。
 * 未登録クラスの既定も true 扱い (消費側は `?? true` で読む) なので、
 * 分類漏れが偽陰性にはならない。
 *
 * 同じ分類を 2 か所に持たないため、以下の Architecture テストがここを読む:
 *   - TenantBoundaryOrderingTest        … テナント境界 404 の位置 (存在オラクル防止)
 *   - BughuntExecutedRouteOrderingTest  … 実行済み route の記録器の位置 (偽陽性防止)
 */
final class MiddlewareShortCircuitInventory
{
    /**
     * @return array<class-string, bool>
     */
    public static function classification(): array
    {
        return [
            // --- 短絡しうる ---
            Authenticate::class => true,
            RedirectIfAuthenticated::class => true,
            EnsureEmailIsVerified::class => true,
            ThrottleRequests::class => true,
            ValidateSignature::class => true,
            PreventRequestForgery::class => true,
            AuthenticateSession::class => true,
            // binding 失敗そのものが 404 (短絡の基準点)
            SubstituteBindings::class => true,
            // Inertia の asset version mismatch は 409 で短絡する
            HandleInertiaRequests::class => true,
            RequireActiveSubscription::class => true,
            // 退会予約中の凍結。302 (web) / 409 (XHR) で短絡する
            EnsureAccountNotPendingDeletion::class => true,
            RequireTwoFactorForEnforcedOrganizations::class => true,
            BlockTwoFactorDisableForEnforcedOrganizations::class => true,
            RequireRecentAuth::class => true,
            RequireRecentAuthOnEmailChange::class => true,
            RequireApiKeyAbility::class => true,
            ResolveApiActor::class => true,
            IdempotentRequest::class => true,
            EnsureProjectBelongsToCurrentOrganization::class => true,
            EnsureProjectBelongsToApiOrganization::class => true,
            EnsureEmailIsVerifiedOrBack::class => true,
            EnsureLoginMethodRemains::class => true,
            LocalOnly::class => true,
            McpConsentOrganizationBinder::class => true,
            VerifyMcpOrigin::class => true,
            EnforceMcpTransport::class => true,
            VerifySnsSignature::class => true,
            // vendor (Livewire)。X-Livewire ヘッダ / JSON でない要求を 404 で短絡する
            RequireLivewireHeaders::class => true,
            // --- 透過 (必ず $next を呼び、応答の加工のみ) ---
            EncryptCookies::class => false,
            AddQueuedCookiesToResponse::class => false,
            StartSession::class => false,
            ShareErrorsFromSession::class => false,
            EncryptHistory::class => false,
            SecurityHeaders::class => false,
            NoStoreCacheHeadersForAuthenticatedPages::class => false,
            NoStoreResponse::class => false,
            // X-Robots-Tag: noindex を足すだけ
            NoIndex::class => false,
            BughuntCoverageMiddleware::class => false,
            // 観測器。必ず $next を呼び、応答を加工しない (= 短絡しない)
            BughuntExecutedRouteMiddleware::class => false,
        ];
    }

    /**
     * 短絡しうると分類された middleware クラスの一覧。
     *
     * @return list<class-string>
     */
    public static function shortCircuiting(): array
    {
        return array_values(array_keys(array_filter(self::classification())));
    }
}
