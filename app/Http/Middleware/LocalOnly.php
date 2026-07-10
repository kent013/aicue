<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * local 環境専用経路のゲート (debug login 等)。
 *
 * - local 以外は 404 (経路の存在自体を秘匿)
 * - DEBUG_LOGIN_* 未設定時も 404 (fail-secure。設定するまで経路は完全閉鎖)
 * - 資格情報は Basic 認証で受け、hash_equals で timing-safe 比較する
 *
 * routes/web.php 側の「isLocal / runningUnitTests のときだけ route を登録する」
 * ゲートとの二重防御 (staging / production では route 自体が存在しない)。
 */
class LocalOnly
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // app()->environment() は bootstrap 時にキャッシュされた値を見るため、
        // テストで config(['app.env' => ...]) を上書きしても切り替わらない。
        // 実装とテストの判定経路を一本化するため config('app.env') を直接読む。
        if (config('app.env') !== 'local') {
            abort(404);
        }

        $expectedUserValue = config('debug.login.user');
        $expectedPasswordValue = config('debug.login.password');
        $expectedUser = is_string($expectedUserValue) ? $expectedUserValue : '';
        $expectedPassword = is_string($expectedPasswordValue) ? $expectedPasswordValue : '';

        // env 未設定時は debug login 経路を完全閉鎖 (fail-secure 404)
        if ($expectedUser === '' || $expectedPassword === '') {
            abort(404);
        }

        $userValue = $request->getUser();
        $passwordValue = $request->getPassword();
        $user = is_string($userValue) ? $userValue : '';
        $password = is_string($passwordValue) ? $passwordValue : '';

        // user / password とも hash_equals で timing-safe 比較。local 限定経路 +
        // gitignore .env 平文保持で「資格情報を晒さない」不変条件は満たされるため
        // bcrypt (password_verify) は採用しない (config/debug.php の注記参照)。
        if (! hash_equals($expectedUser, $user) || ! hash_equals($expectedPassword, $password)) {
            return new Response('Unauthorized.', 401, [
                'WWW-Authenticate' => 'Basic realm="Debug"',
            ]);
        }

        return $next($request);
    }
}
