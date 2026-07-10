<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Webmozart\Assert\Assert;

/**
 * アカウント削除。password.confirm (step-up) を経由して到達する。
 * 関連データは FK の cascade / nullOnDelete で掃除される。
 */
class AccountController extends Controller
{
    public function destroy(Request $request, SecurityEventRecorder $recorder): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // 削除前に記録 (user_id は削除後 nullOnDelete で null 化され、イベント自体は残る)
        $recorder->record(SecurityEventType::AccountDeleted, $user);

        Auth::logout();

        DB::transaction(function () use ($user): void {
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'アカウントを削除しました');
    }
}
