<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Enums\OrganizationRole;
use App\Enums\SecurityEventType;
use App\Enums\TwoFactorStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\UpdateOrganizationMemberRoleRequest;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\User\TwoFactorResetSecurityNotification;
use App\Services\Organization\OrganizationMembershipService;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Webmozart\Assert\Assert;

/**
 * メンバーのロール変更・削除。
 * Owner への昇格・Owner の削除は不可 (オーナー移譲のみが正規経路。Service 側でも防御)。
 * `{organization}` は MembershipScopedOrganizationBinder が membership スコープで解決する。
 */
class OrganizationMemberController extends Controller
{
    public function update(UpdateOrganizationMemberRoleRequest $request, Organization $organization, User $user, OrganizationMembershipService $membership): RedirectResponse
    {
        // URL 整合 guard: 認可より前に 404 (cross-tenant の存在を漏らさない)
        $this->resolveOrganizationMember($organization, $user);
        Gate::authorize('manageMembers', $organization);

        $actor = $request->user();
        Assert::isInstanceOf($actor, User::class);

        // 3 値遷移コマンド (admin/editor/shooter)。Owner 指定は enum 外 = 構造的に不可能
        $membership->applyConsoleRole($organization, $user, $request->role(), $actor);

        return back()->with('success', 'ロールを変更しました');
    }

    public function destroy(Request $request, Organization $organization, User $user, OrganizationMembershipService $membership): RedirectResponse
    {
        // URL 整合 guard: 認可より前に 404 (cross-tenant の存在を漏らさない)
        $this->resolveOrganizationMember($organization, $user);
        Gate::authorize('manageMembers', $organization);

        $actor = $request->user();
        Assert::isInstanceOf($actor, User::class);

        $membership->removeMember($organization, $user, $actor);

        return back()->with('success', 'メンバーを削除しました');
    }

    /**
     * メンバーの 2FA を解除する (ロックアウト救済)。route 側で recent-auth 必須。
     *
     * 2FA は User アカウント全体の属性のため、本操作は対象の他組織利用にも波及する。
     * 緩和: recent-auth (route) + 理由必須 + security_audit_events 記録 + 本人セキュリティ通知。
     * 状態遷移は Fortify 純正 DisableTwoFactorAuthentication に委譲 (self disable と同一実装)。
     */
    public function resetTwoFactor(
        Request $request,
        Organization $organization,
        User $user,
        DisableTwoFactorAuthentication $disableTwoFactorAuthentication,
        SecurityEventRecorder $recorder,
    ): RedirectResponse {
        // URL 整合 guard: 認可より前に 404 (cross-tenant の存在を漏らさない)
        $this->resolveOrganizationMember($organization, $user);
        Gate::authorize('manageMembers', $organization);

        $currentUser = $request->user();
        Assert::isInstanceOf($currentUser, User::class);

        // 自分の 2FA はセキュリティ設定画面から (誤操作・なりすまし解除の構造排除)
        abort_if($user->id === $currentUser->id, 403);

        // 空白のみ入力は Laravel 既定の TrimStrings が trim 済み ('' → required で拒否)
        /** @var array{reason: string} $validated */
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        // TOCTOU 対策: 対象行を lock した上で、認可前提 (actor ロール / 対象の所属・ロール) と
        // 2FA 状態の判定〜解除を同一トランザクションで行い、判定と実行の乖離を残さない
        DB::transaction(function () use ($organization, $user, $currentUser, $disableTwoFactorAuthentication): void {
            /** @var User $lockedUser */
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);

            // 第 2 層のメンバーシップ検証 (lock 後の再確認)
            abort_unless(
                $organization->users()->whereKey($lockedUser->getKey())->exists(),
                404,
            );

            // Admin は同格以上 (Admin / Owner) を対象にできない (removeMember と同じ境界)
            $actorRole = $currentUser->organizationRole($organization);
            $targetRole = $lockedUser->organizationRole($organization);
            if ($actorRole !== OrganizationRole::Owner && $targetRole !== OrganizationRole::Member) {
                abort(403);
            }

            // 2FA が確定 (Enabled) しているメンバーのみ解除対象。未設定 (Disabled) と
            // 設定途中 (Pending) はともに「2FA 無効」として明示拒否する。UI (解除ボタンは
            // enabled のみ表示) と API の意味論を揃え、未確認 secret のクリアに対して
            // 誤解を招く監査記録・本人へのセキュリティ通知が発生するのを防ぐ (F-2-03)。
            if ($lockedUser->twoFactorStatus() !== TwoFactorStatus::Enabled) {
                throw ValidationException::withMessages([
                    'two_factor' => ['このメンバーは 2 段階認証を設定していません。'],
                ]);
            }

            $disableTwoFactorAuthentication($lockedUser);
        });

        // 監査記録 (理由込み) + 本人への検知導線 (乗っ取り・誤操作をすり抜けさせない)
        $recorder->record(SecurityEventType::OrgMemberTwoFactorReset, $user, [
            'organization_id' => $organization->id,
            'actor_user_id' => $currentUser->id,
            'reason' => $validated['reason'],
        ]);
        $user->notify(new TwoFactorResetSecurityNotification($organization->name));

        return back()->with('success', "「{$user->name}」の 2 段階認証を解除しました");
    }

    /**
     * URL 整合 guard (D2 不変条件): URL 上の {user} が {organization} のメンバーで
     * なければ**認可より前に 404** (403 で存在を漏らさない / cross-tenant は 404)。
     * Service 層のメンバーシップ検証は第 2 層として残す。
     */
    private function resolveOrganizationMember(Organization $organization, User $member): User
    {
        abort_unless(
            $organization->users()->whereKey($member->getKey())->exists(),
            404,
        );

        return $member;
    }
}
