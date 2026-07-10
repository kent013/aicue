<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Auth\EmailVerificationGateContext;
use App\Support\Http\SameOriginPath;
use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `verified` の web POST 向け代替。未認証時に /email/verify へ 302 する標準挙動は
 * 「ブロックの事実・理由・次アクション」を伝えないため、元ページへ戻し + error flash で
 * 戻す。ゲート強度は標準 EnsureEmailIsVerified と同等以上 (null user 遮断 + MustVerifyEmail
 * + hasVerifiedEmail)、応答だけが変わる。alias は `verified.or-back:<context>`。
 */
final class EnsureEmailIsVerifiedOrBack
{
    public function handle(Request $request, Closure $next, string $context): Response
    {
        // 不正 context は構造的に発生しない (route 定義の typo のみ)。tryFrom で null は
        // 最も保守的な Invite 既定へ倒し、report で監視に出す (fail-closed、500 にしない)。
        $gate = EmailVerificationGateContext::tryFrom($context);
        if ($gate === null) {
            report(new LogicException("Unknown email verification gate context: {$context}"));
            $gate = EmailVerificationGateContext::Invite;
        }

        $user = $request->user();

        // 標準 EnsureEmailIsVerified と同じく null user も遮断 (fail-closed)。
        if ($user === null) {
            return redirect()->guest(route('login'));
        }

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            // 評価指標: 未認証起因ブロック数のサーバ側計測 (メールアドレスは出さない)。
            Log::info('email_verification.gate_blocked', [
                'user_id' => $user->getAuthIdentifier(),
                'route' => $request->route()?->getName(),
                'context' => $gate->value,
            ]);

            return redirect()->to($this->returnTo($request, $gate))
                ->with('error', $gate->message());
        }

        $response = $next($request);
        if (! $response instanceof Response) {
            throw new LogicException('Expected Symfony Response from middleware $next, got '.get_debug_type($response));
        }

        return $response;
    }

    /**
     * referer が同一オリジンならそこへ、無ければ context の named route fallback へ。
     * 同一オリジン正規化は共有 helper (ConfirmRecentAuthController と同一 SoT)。
     */
    private function returnTo(Request $request, EmailVerificationGateContext $gate): string
    {
        return SameOriginPath::normalize(
            $request,
            $request->headers->get('referer'),
            route($gate->fallbackRouteName(), absolute: false),
        );
    }
}
