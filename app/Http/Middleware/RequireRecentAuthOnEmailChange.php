<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Webmozart\Assert\Assert;

/**
 * profile 更新 (PUT /user/profile-information) のうち **メールアドレス変更を伴う場合のみ**
 * recent-auth (step-up) を要求する条件付きゲート。alias: `recent-auth.on-email-change`。
 *
 * 氏名のみの変更は乗っ取りベクタではないため素通しし、日常操作の摩擦を増やさない。
 * email 変更は「認証要素変更」であり、UpdateUserProfileInformation が旧アドレス通知 +
 * email_verified_at null 化を行う経路。ここを stale セッション (remember-me 復元で
 * recent_auth_at 未 stamp) から素通しさせない。
 *
 * 判定契約 (UpdateUserProfileInformation::update の early-return と同一の raw 比較):
 *   - submitted email が is_string かつ現行 email と `!==` の時のみ RequireRecentAuth へ委譲。
 *   - 欠落 / 非 string は gate せず後続 (Validator の required/email 422) に委ねる。
 *     非 string は email 変更を起こせないため fail-safe (bypass 不可)。
 *
 * 応答 (409 + RecentAuthRequiredResource / 302 → recent-auth.confirm) は委譲先が生成する。
 */
final class RequireRecentAuthOnEmailChange
{
    public function __construct(private readonly RequireRecentAuth $requireRecentAuth) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->changesEmail($request)) {
            return $this->requireRecentAuth->handle($request, $next);
        }

        $response = $next($request);
        Assert::isInstanceOf($response, Response::class);

        return $response;
    }

    /**
     * 送信 email が現行 email を変更するか (action の early-return と同一の raw 比較)。
     */
    private function changesEmail(Request $request): bool
    {
        $submitted = $request->input('email');
        if (! is_string($submitted)) {
            return false; // 欠落 / 非 string → 変更を起こせない。Validator に委ねる
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return false; // auth 前段は 'auth' middleware が担保。非 User なら gate 対象外
        }

        return $submitted !== $user->email;
    }
}
