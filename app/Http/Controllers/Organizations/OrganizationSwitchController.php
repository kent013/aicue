<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/**
 * 組織切替 (users.current_organization_id の更新)。
 * `{organization}` は MembershipScopedOrganizationBinder が membership スコープで解決するため、
 * 所属していない組織・不在 id は等しく 404 (存在の有無は開示しない)。
 */
class OrganizationSwitchController extends Controller
{
    public function store(Request $request, Organization $organization): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return redirect()->route('dashboard')->with('success', "「{$organization->name}」に切り替えました");
    }
}
