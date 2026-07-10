<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DataTransferObjects\Auth\TwoFactorRequiredDto;
use App\Enums\TwoFactorStatus;
use App\Http\Resources\Auth\TwoFactorRequiredResource;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 「2FA 必須」組織に所属する未準拠ユーザーのアカウント全体ゲート。
 *
 * 契約: 1 つでも two_factor_required な組織に所属する 2FA 未完了 (disabled/pending)
 * ユーザーは ALLOWED_ROUTE_NAMES 以外の web 経路すべてから 2FA 設定ページ
 * (settings.security) へ 302 (XHR は 409 + {code, message, redirect}) される。
 * 組織スコープの部分制限は採らない (2FA はアカウント全体の属性のため)。
 *
 * 評価コスト: 準拠 (enabled) ユーザーは attribute 判定のみで追加クエリゼロ。未準拠
 * ユーザーのみ所属組織の 1 クエリ (flash 用に組織名が要るため first)。
 *
 * web group append (= StartSession 後)。auth は route middleware だが session guard は
 * lazy 解決のため $request->user() はここで利用可能。未認証は素通し (login 等は対象外)。
 */
final class RequireTwoFactorForEnforcedOrganizations
{
    /**
     * ゲート中でも到達可能な route name => 必要理由。
     * この表が正であり、(a) 全 name の実在 + 理由非空 (TwoFactorEnforcementAllowlistTest)、
     * (b) ゲート中の到達可能性 (TwoFactorEnforcementTest dataset) を同表から検証する。
     * two-factor.disable は意図的に含めない (ゲート解除手段にならず、pending 巻き戻しの
     * 濫用面になる。self-disable は BlockTwoFactorDisableForEnforcedOrganizations も参照)。
     *
     * @var array<string, string>
     */
    public const ALLOWED_ROUTE_NAMES = [
        'settings.security' => '準拠達成の入口 (2FA 設定ページ)',
        'settings' => '設定 index (2FA 設定ページへの導線)',
        'two-factor.enable' => 'enrollment 開始 (POST /user/two-factor-authentication)',
        'two-factor.confirm' => 'TOTP 確認 = 準拠達成 (POST /user/confirmed-two-factor-authentication)',
        'two-factor.qr-code' => 'QR 表示 (設定ページの fetch)',
        'two-factor.secret-key' => '手動入力キー表示 (設定ページの fetch)',
        'two-factor.recovery-codes' => 'リカバリコード表示 (設定完了直後の保存)',
        'two-factor.regenerate-recovery-codes' => 'リカバリコード再生成',
        'recent-auth.confirm' => '機微操作前の step-up 画面 (2FA 設定動線が要求し得る)',
        'recent-auth.status' => 'step-up 状態の確認 (XHR precheck)',
        'recent-auth.password' => 'password による step-up 完了',
        // {intent} は login/register/link/step-up 共用だが、認証済みユーザーの主用途は
        // step-up (SSO-only ユーザーの再認証)。link を許してもゲート解除にはならない
        'social.redirect' => 'SSO step-up の開始 (SSO-only ユーザーの再認証)',
        'social.callback' => 'SSO step-up の callback',
        'logout' => '離脱は常に可能',
        'verification.notice' => 'verified middleware との redirect 競合回避',
        'verification.verify' => 'メール検証リンクの踏破',
        'verification.send' => '検証メール再送',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->twoFactorStatus() === TwoFactorStatus::Enabled) {
            return $this->proceed($request, $next);
        }

        $routeName = $request->route()?->getName();
        if ($routeName !== null && array_key_exists($routeName, self::ALLOWED_ROUTE_NAMES)) {
            return $this->proceed($request, $next);
        }

        // ここに到達するのは未準拠 (disabled/pending) ユーザーのみ。
        // 状態非依存の単一述語 firstTwoFactorRequiringOrganization() で必須組織を引く
        $enforcingOrganization = $user->firstTwoFactorRequiringOrganization();
        if ($enforcingOrganization === null) {
            return $this->proceed($request, $next);
        }

        $message = "組織「{$enforcingOrganization->name}」は 2 段階認証を必須としています。設定が完了するまで他のページはご利用いただけません。";

        // XHR/JSON は RequireRecentAuth と同形の 409 + { code, message, redirect } (no-store)。
        // SPA の非画面 fetch に HTML リダイレクトを返さない
        if ($request->expectsJson()) {
            return TwoFactorRequiredResource::make(new TwoFactorRequiredDto(
                message: $message,
                redirect: route('settings.security'),
            ))
                ->response()
                ->setStatusCode(409)
                ->withHeaders(['Cache-Control' => 'no-store']);
        }

        return redirect()
            ->route('settings.security')
            ->with('info', $message);
    }

    /** @param  Closure(Request): mixed  $next */
    private function proceed(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof Response) {
            throw new LogicException('Expected Symfony Response from middleware $next, got '.get_debug_type($response));
        }

        return $response;
    }
}
