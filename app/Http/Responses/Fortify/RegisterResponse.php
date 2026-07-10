<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

/**
 * 登録直後のレスポンス (Fortify contract bind)。
 *
 * Fortify 標準は config('fortify.home') (= /dashboard) へ intended redirect するが、
 * dashboard は `verified` middleware で結局 verification.notice へ弾かれる。
 * 登録直後にメール認証を促す導線を明確にするため、未認証ユーザーが必ず到達できる
 * verification.notice (「認証してください」画面) へ直接誘導する。
 * XHR(201) は Fortify 標準と同じ後方互換を維持する。
 */
final class RegisterResponse implements RegisterResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 201);
        }

        return redirect()->route('verification.notice');
    }
}
