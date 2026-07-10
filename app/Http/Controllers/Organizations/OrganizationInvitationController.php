<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Enums\OrganizationRole;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Webmozart\Assert\Assert;

/**
 * メンバー招待の送信。
 * 応答は back + success flash で完結する (画面遷移しない。
 * devnotes/20260611-template-extraction/14 §4: 操作系 POST で intended を使わない)。
 */
class OrganizationInvitationController extends Controller
{
    public function store(Request $request, Organization $organization, OrganizationMembershipService $membership): RedirectResponse
    {
        Gate::authorize('manageMembers', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            // Owner 招待は不可 (Owner は transferOwnership のみが正規経路)
            'role' => ['required', 'string', Rule::in([
                OrganizationRole::Admin->value,
                OrganizationRole::Member->value,
            ])],
        ]);
        $email = $request->input('email');
        $role = $request->input('role');
        Assert::string($email);
        Assert::string($role);

        $membership->inviteMember($organization, $user, $email, OrganizationRole::from($role));

        return back()->with('success', '招待メールを送信しました');
    }

    /**
     * 招待の取り消し (論理失効)。{invitation} は scopeBindings で $organization->invitations()
     * 経由に解決されるため、組織を跨ぐ取り消しは認可より前に 404 になる
     * (NestedRouteIdorDefenseTest 登録済み)。取り消しは行削除ではなく revoked_at で行う。
     */
    public function destroy(Request $request, Organization $organization, OrganizationInvitation $invitation, OrganizationMembershipService $membership): RedirectResponse
    {
        Gate::authorize('manageMembers', $organization);

        $membership->revokeInvitation($invitation);

        return back()->with('success', '招待を取り消しました');
    }
}
