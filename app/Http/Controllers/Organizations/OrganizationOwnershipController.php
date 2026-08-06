<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Webmozart\Assert\Assert;

/**
 * オーナー移譲。password.confirm (step-up) を経由して到達する。
 */
class OrganizationOwnershipController extends Controller
{
    public function store(Request $request, Organization $organization, OrganizationMembershipService $membership): RedirectResponse
    {
        // 層 3 (認可) を payload に触れる前に通す。ここが後段へ移ると、
        // 権限の無い actor が user_id の応答差を観測できるようになる
        // (PayloadIdExistenceOracleTest が固定)
        Gate::authorize('transferOwnership', $organization);

        $from = $request->user();
        Assert::isInstanceOf($from, User::class);

        // 形式検証のみ。`exists:users,id` は「その id が全体で実在するか」を
        // 組織の非メンバーにも validation error の文言差で答えてしまうため使わない (aicue:T118)
        $request->validate([
            'user_id' => ['required', 'integer'],
        ]);
        $userId = $request->input('user_id');
        Assert::integerish($userId);

        // 移譲先は組織 relation から解決する。不在 id も実在の非メンバー id も
        // ここで同一の field error になる (存在オラクル不成立)
        $to = $organization->users()->whereKey((int) $userId)->first();
        if (! $to instanceof User) {
            throw ValidationException::withMessages([
                'user_id' => [OrganizationMembershipService::MEMBER_REQUIRED_MESSAGE],
            ]);
        }

        $membership->transferOwnership($organization, $from, $to);

        return back()->with('success', 'オーナーを移譲しました');
    }
}
