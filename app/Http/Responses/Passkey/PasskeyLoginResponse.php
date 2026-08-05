<?php

declare(strict_types=1);

namespace App\Http\Responses\Passkey;

use App\DataTransferObjects\Auth\PasskeyLoginRedirectDto;
use App\Http\Resources\Auth\PasskeyLoginRedirectResource;
use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyLoginResponse as PasskeyLoginResponseContract;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * passkey ログイン完了 (vendor contract の差し替え)。
 *
 * vendor 既定は `config('passkeys.redirect')` へ redirect するだけで、
 * transport 契約 (詳細設計 4-d) の「client は fetch で送り着地 URL を受け取る」に合わない。
 * **JSON `{redirect}` を返す**のが主経路で、client は受け取った URL へ
 * window.location.assign する。
 *
 * **ログイン直後フロー**のため `redirect()->intended()` が許される数少ない経路
 * (禁止事項 7 の例外条件)。着地は Fortify 標準ログイン
 * (App\Http\Responses\Fortify\LoginResponse) と揃える。
 * DTO + JsonResource 経由にして `response()->json()` 直書きを避ける (禁止事項 4)。
 */
final class PasskeyLoginResponse implements PasskeyLoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): SymfonyResponse
    {
        // intended() は session の url.intended を pull するため 1 度しか読めない。
        // JSON / redirect のどちらの分岐でも同じ着地になるよう先に確定させる。
        $target = redirect()->intended(config()->string('fortify.home'))->getTargetUrl();

        if ($request->expectsJson()) {
            return PasskeyLoginRedirectResource::make(new PasskeyLoginRedirectDto(redirect: $target))
                ->response($request)
                ->withHeaders(['Cache-Control' => 'no-store, private']);
        }

        return redirect()->to($target);
    }
}
