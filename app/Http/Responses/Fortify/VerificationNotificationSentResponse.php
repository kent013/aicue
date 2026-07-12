<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse as EmailVerificationNotificationSentResponseContract;

/**
 * 認証メール再送信後のレスポンス (Fortify contract bind)。
 *
 * Fortify 既定は `back()->with('status', ...)` を返すが、flash-to-toast は
 * status を意図的に gating (toast 化しない)。再送信の完了を toast でフィードバック
 * するため、web は `success` キーへ寄せる (flash キー統一ポリシー:
 * web 向け操作成功 flash は success に統一する。FortifyResponseTest が正本)。
 *
 * wantsJson (XHR / API) の raw JSON は「Fortify 固定契約の互換維持」であり
 * 禁止事項 4 (response()->json() 直書き) の例外に該当する。このパターンは
 * app/Http/Responses/Fortify/ に閉じ、通常のアプリ endpoint へ波及させない。
 */
final class VerificationNotificationSentResponse implements EmailVerificationNotificationSentResponseContract
{
    private const string SUCCESS_MESSAGE = '認証メールを再送信しました。';

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        // JSON 分岐は差し替え元の Fortify 既定 (wantsJson / 202) をそのまま踏襲する
        // (既存 3 クラスは expectsJson だが、本クラスは挙動互換を最優先する)
        if ($request->wantsJson()) {
            return new JsonResponse('', 202);
        }

        return back()->with('success', self::SUCCESS_MESSAGE);
    }
}
