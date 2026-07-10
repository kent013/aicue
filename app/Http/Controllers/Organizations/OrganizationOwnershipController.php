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
use Webmozart\Assert\Assert;

/**
 * オーナー移譲。password.confirm (step-up) を経由して到達する。
 */
class OrganizationOwnershipController extends Controller
{
    public function store(Request $request, Organization $organization, OrganizationMembershipService $membership): RedirectResponse
    {
        Gate::authorize('transferOwnership', $organization);

        $from = $request->user();
        Assert::isInstanceOf($from, User::class);

        $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $userId = $request->input('user_id');
        Assert::integerish($userId);

        /** @var User $to */
        $to = User::query()->findOrFail((int) $userId);

        $membership->transferOwnership($organization, $from, $to);

        return back()->with('success', 'オーナーを移譲しました');
    }
}
