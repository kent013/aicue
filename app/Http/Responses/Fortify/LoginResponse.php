<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Webmozart\Assert\Assert;

/**
 * ログイン成功レスポンス (Fortify contract bind)。
 *
 * 挙動は Fortify 既定 (XHR=204 / web=intended → fortify.home) と同一だが、
 * アプリ側でリダイレクト先・flash を拡張する差し替え点として明示 bind する
 * (例: admin パス隔離、オンボーディング誘導)。
 */
final class LoginResponse implements LoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        $home = config('fortify.home');
        Assert::string($home);

        return redirect()->intended($home);
    }
}
