<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DataTransferObjects\Account\AccountDeletionStateDto;
use App\Enums\Account\AccountDeletionFreezeAllowance;
use App\Models\User;
use App\Support\Http\FlashNotificationRelay;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 退会予約中 (猶予期間つき削除・**凍結方式**) のアクセス制限。alias: `not-pending-deletion`。
 *
 * users 行の生死は変えず (SoftDeletes を使わない)、予約中は業務面を止めて
 * **取消と、退会ブロッカーの解消**だけを通す。通す route は
 * {@see AccountDeletionFreezeAllowance} の **exact case** のみ (deny-by-default・wildcard 禁止)。
 *
 * ★**403 で突き放さない**。遮断時は取消ボタンのある `/settings` へ 302 する
 *   (AGENTS.md ドメイン規約 4 と同じ思想 = 行き先のない詰みを作らない)。
 *   遮断理由の flash は積まない — 理由は着地ページ (/settings の予約バナー) が持つ
 *   (課金ゲート `RequireActiveSubscription` と同じ契約)。
 *
 * ★**実行位置は `bootstrap/app.php` の priority list が正本**で、テナント境界 404
 *   (`EnsureProjectBelongsToRouteOrganization`) より**必ず後**に置く。前に置くと
 *   「他組織に実在 = 302 / 不在 = 404」の 1 bit 存在オラクルになる
 *   (AGENTS.md セキュリティ不変条件 10)。
 *
 * ★配線は `routes/web.php` の group への**直付け**である。`RouteMiddlewareBinder` の後付けは
 *   使わない (route cache 済みの起動では 1 本も効かず、無音で保護が外れる = T135)。
 *
 * ★母集団の外 (= 凍結されない): Fortify / Passkeys が登録するログイン・ログアウト・
 *   パスワード再設定・メール確認・2FA challenge・passkey ログインと `session.status`。
 *   **認証回復と離脱の手段は構造的に凍結されない**。
 */
final class EnsureAccountNotPendingDeletion
{
    /** 凍結中に遮断されたことを機械可読に伝える文言 (JSON/XHR の 409 本文)。 */
    public const FROZEN_MESSAGE = '退会予約中のため、この操作はできません。設定画面から退会を取り消してください。';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request); // 未認証は auth middleware の責務 (ここでは判定しない)
        }

        if (! AccountDeletionStateDto::fromUser($user)->isPending()) {
            return $next($request);
        }

        $name = $request->route()?->getName();
        if ($name !== null && AccountDeletionFreezeAllowance::tryFrom($name) !== null) {
            return $next($request);
        }

        // JSON/XHR は 409 Conflict (状態が操作と矛盾している)。課金ゲートの 402 とは別事由。
        if ($request->expectsJson()) {
            abort(Response::HTTP_CONFLICT, self::FROZEN_MESSAGE);
        }

        // 直前の通知 (他画面の success/error) を着地先まで保つ。理由の flash は積まない。
        // **通知だけ**を延命する (reflash() にしない): API キー平文のような
        // 1 度きり表示の内部状態まで持ち越さないため。
        FlashNotificationRelay::relayTo($request->session());

        return redirect()->route('settings');
    }
}
