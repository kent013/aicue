<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;

/**
 * 2FA 無効化後のレスポンス (Fortify contract bind)。
 *
 * Fortify 既定は `back()->with('status', ...)` を返すが、flash-to-toast は
 * status を意図的に gating (toast 化しない)。設定変更の完了を toast でフィードバック
 * するため、web のみ `success` キーへ寄せる。expectsJson (XHR / API) は
 * Fortify 既定どおり JSON 200 を維持する。
 */
final class TwoFactorDisabledResponse implements TwoFactorDisabledResponseContract
{
    private const string SUCCESS_MESSAGE = '二要素認証を無効化しました。';

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse('', 200);
        }

        return back()->with('success', self::SUCCESS_MESSAGE);
    }
}
