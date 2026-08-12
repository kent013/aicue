<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\DataTransferObjects\Account\AccountDeletionAuditContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Webmozart\Assert\Assert;

/**
 * アカウント削除。password.confirm (step-up) を経由して到達する。
 * 関連データは FK の cascade / nullOnDelete で掃除される。
 */
class AccountController extends Controller
{
    public function destroy(Request $request, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // 唯一 Owner + 他メンバー有りの組織があれば ValidationException(['account'=>...]) で中断。
        // 記録 (AccountDeleted) と削除は service の単一トランザクション内・行ロック下で直列化される。
        // Auth::logout はガード通過後・削除直前のフックで呼ぶ (logout 監査イベントを user 行が
        // 存在するうちに記録するため。ブロック時はフックが実行されずログアウトされない)。
        $membership->deleteAccount(
            $user,
            static fn () => Auth::logout(),
            null,
            // 監査 metadata へ到達経路を残す (T160 / bug-hunt F-4-Q1。観測専用で分岐しない)
            AccountDeletionAuditContext::http($request->route()?->getName(), $request->method()),
        );

        // 削除成功後のみ後処理 (ブロック時は上で例外伝播し到達しない)。
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'アカウントを削除しました');
    }
}
