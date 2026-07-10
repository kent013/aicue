<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DataTransferObjects\Auth\TwoFactorDisableForbiddenDto;
use App\Http\Resources\Auth\TwoFactorDisableForbiddenResource;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 2FA を必須化している組織に所属する準拠 (enabled) ユーザーが自分で 2FA を無効化
 * (`DELETE /user/two-factor-authentication`, route name `two-factor.disable`) するのを
 * サーバ側で禁止する。
 *
 * 背景: RequireTwoFactorForEnforcedOrganizations は「未準拠ユーザーの全画面ゲート」であり
 * disable route を意図的に allowlist 外に置くが、準拠ユーザーが disable を打つと action
 * 自体は通ってしまい、その直後のリクエストで初めてゲートされて詰む (= 一時的に第二要素を
 * 失う)。本 middleware は disable の到達自体を弾く。判定は現在の 2FA 状態に依存しない
 * User::firstTwoFactorRequiringOrganization() を使う (enabled でも縛りは残るため)。
 *
 * 復旧: やむを得ず外す必要がある場合は組織管理者の resetTwoFactor
 * (organizations.members.two-factor.reset) 経由のみ。これにより必須方針が個人の意思で
 * 骨抜きにならない。
 *
 * Fortify の disable route には per-route middleware slot が無いため web group append で
 * 配線する (RequireTwoFactorForEnforcedOrganizations の後)。
 *
 * 応答: XHR/JSON は 422 + { code, message } (DTO/JsonResource 経由)、HTML は flash error + back。
 */
final class BlockTwoFactorDisableForEnforcedOrganizations
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->route()?->getName() !== 'two-factor.disable') {
            return $next($request);
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $organization = $user->firstTwoFactorRequiringOrganization();
        if ($organization === null) {
            return $next($request);
        }

        $message = "組織「{$organization->name}」が 2 段階認証を必須としているため、ご自身では無効化できません。やむを得ない場合は組織管理者に解除をご依頼ください。";

        if ($request->expectsJson()) {
            return TwoFactorDisableForbiddenResource::make(new TwoFactorDisableForbiddenDto(
                message: $message,
            ))->response()->setStatusCode(422);
        }

        return redirect()->back()->with('error', $message);
    }
}
