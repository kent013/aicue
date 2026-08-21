<?php

declare(strict_types=1);

namespace App\Http\Responses\Fortify;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse as ProfileInformationUpdatedResponseContract;

/**
 * プロフィール更新後のレスポンス (Fortify contract bind)。
 *
 * Fortify 既定は `back()->with('status', ...)` を返すが、flash-to-toast は
 * status を意図的に gating (toast 化しない)。更新完了を toast でフィードバック
 * するため web のみ `success` キーへ寄せる。expectsJson (XHR / API) は
 * Fortify 既定どおり JSON 200 を維持する。
 */
final class ProfileUpdatedResponse implements ProfileInformationUpdatedResponseContract
{
    private const string SUCCESS_MESSAGE = 'プロフィールを更新しました。';

    /**
     * メール変更時の成功メッセージ。着地は /email/verify (verification.notice) で、
     * そこで「変更は成功した・次は認証」を明示する。新アドレス文字列は載せない
     * (画面の auth.user.email が既に新アドレスを保持しており、メッセージへの埋め込みは冗長)。
     */
    private const string EMAIL_CHANGED_MESSAGE = 'メールアドレスを変更しました。新しいアドレスに認証メールを送信しましたので、認証を完了してください。';

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return new JsonResponse('', 200);
        }

        // メール変更時は UpdateUserProfileInformation が email_verified_at を null 化する。
        // その状態で back() (= /settings) へ戻すと、/settings の 'verified' (素の
        // EnsureEmailIsVerified) が verification.notice へもう一段 302 し、素の verified は
        // flash を keep しないため success flash がこの中間ホップで期限切れ廃棄される
        // (bug-hunt F-4-01)。着地画面 (/email/verify、auth のみで verified ゲート外) へ
        // 直接寄せ、そこで成功を明示する。$request->user() はこのリクエストで action が
        // save() した同一インスタンスを memo 返しするため wasChanged('email') が読める。
        $user = $request->user();
        if ($user instanceof User && $user->wasChanged('email')) {
            return redirect()->route('verification.notice')
                ->with('success', self::EMAIL_CHANGED_MESSAGE);
        }

        return back()->with('success', self::SUCCESS_MESSAGE);
    }
}
