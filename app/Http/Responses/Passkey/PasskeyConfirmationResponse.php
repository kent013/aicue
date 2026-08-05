<?php

declare(strict_types=1);

namespace App\Http\Responses\Passkey;

use Illuminate\Http\Request;
use Laravel\Passkeys\Contracts\PasskeyConfirmationResponse as PasskeyConfirmationResponseContract;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * passkey による step-up 確認の応答 (vendor contract の差し替え)。
 *
 * vendor の PasskeyConfirmationController::store() は
 * `$session->passwordConfirmed()` を呼び **Fortify の auth.password_confirmed_at を書く**。
 * 本アプリは RecentAuthState の契約で「Fortify の鍵には書かない (意味汚染・権限漏れ回避)」
 * としており、将来 password.confirm を使う route が生えると passkey confirm が
 * それを黙って満たす潜在的な権限漏れになる。
 *
 * controller 実行後・session 保存前である本メソッドで確実に除去する
 * (Response 差し替えがアプリ責務である理由がこの継ぎ目)。
 * 鮮度そのものは StampRecentAuthOnPasskeyVerified が recent_auth_at へ既に打っている。
 *
 * 応答契約は recent-auth.password (ConfirmRecentAuthController::confirmPassword) と揃える
 * (Inertia = intended redirect / 素の XHR = 204 + no-store)。
 */
final class PasskeyConfirmationResponse implements PasskeyConfirmationResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): SymfonyResponse
    {
        $request->session()->forget('auth.password_confirmed_at');

        // RequireRecentAuth の 302 fallback が積んだ one-shot flag は必ず消費する
        // (両経路で消費し、次回 step-up に持ち越さない。ConfirmRecentAuthController と同契約)。
        $droppedMutation = $request->session()->pull('recent_auth.dropped_mutation') === true;

        if ($request->hasHeader('X-Inertia')) {
            $redirect = redirect()->intended(route('dashboard'));
            if ($droppedMutation) {
                $redirect->with('info', '再認証が完了しました。先ほどの操作はまだ実行されていません。お手数ですがもう一度操作してください。');
            }

            return $redirect;
        }

        return response()->noContent()->withHeaders(['Cache-Control' => 'no-store, private']);
    }
}
