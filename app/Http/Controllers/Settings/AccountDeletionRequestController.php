<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/**
 * 退会の予約 (猶予期間つき削除) と、その取消。
 *
 * 対象は常に `$request->user()` **自身**である。route に他者を指せる parameter が 1 つも無く、
 * 他人のアカウントへ到達する経路がコード上存在しない (`ControllerAuthorizationGateTest` の
 * `SelfScopedResource` で登録済み)。
 *
 * ★**予約 (store) には step-up (recent-auth) を課し、取消 (destroy) には課さない**。
 *   取消は**誤操作救済の本体**であり、救済経路に関門を足すと「取り消せない」詰みの再生産になる
 *   (取消は権限を増やす操作ではない)。
 *   受け入れるリスク: セッション奪取者が予約を取り消せる。しかし奪取者が取り消しても
 *   失われるのは「退会の意思」だけで、本人は再度予約できる。逆に取消に関門を付けると
 *   **本人が救済できない**方が重い被害になる。これは設計判断である。
 */
final class AccountDeletionRequestController extends Controller
{
    public function store(Request $request, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // ブロッカーは評価しない (予約は意思表示であって削除ではない)。権威判定は執行時。
        $state = $membership->requestAccountDeletion($user);

        // 操作系 POST は back() で完結させる (禁止事項 7: intended() を使わない)
        return back()->with(
            'success',
            "退会を予約しました。{$state->purgeAfterLabel()}までは取り消せます。",
        );
    }

    public function destroy(Request $request, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $membership->cancelAccountDeletion($user);

        return back()->with('success', '退会の予約を取り消しました。');
    }
}
