<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/**
 * アプリ内からの招待受諾 (POST invitations.accept-in-app)。裁定 AG-113 標準形 v1。
 *
 * 受諾の根拠は URL の所持ではなく「auth 済み (middleware) ∧ email 確認済み (middleware) ∧
 * ログイン者 email = 招待の宛先 (Service の scope)」。
 *
 * **認可 (Gate) を持たない**のは意図的である:
 * 受諾前の user は対象組織の非メンバーで組織 Policy は構造的に必ず拒否になるうえ、
 * 403 を返すこと自体が「その招待は実在する」を教える口になる。代わりに
 * OrganizationMembershipService::acceptPendingInvitation() が
 * $user 宛の有効 pending 集合からしか解決せず、**すべての不成立を 404 に畳む**
 * (ControllerAuthorizationGateTest に SelfScopedResource として理由付きで登録済み)。
 *
 * **{invitation} は string で受ける**。型を Model にすると implicit binding が復活し、
 * 不在 id だけが binding 段で 404 になって存在オラクルが生まれる
 * (TenantBoundaryOrderingTest 検査 3a が action 引数型を機械検証する)。
 */
class AcceptInvitationInAppController extends Controller
{
    public function __invoke(Request $request, string $invitation, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $organization = $membership->acceptPendingInvitation($user, $invitation);

        // 業務上の受諾不能はすべて 404 (403 を返さない = 存在を教えない)。
        // back() も flash も出さない (文脈依存の戻り先そのものが手掛かりになるため)。
        // abort_if ではなく if + abort にする (abort() は never を返すため PHPStan が
        // 以降で $organization を非 null に narrow できる)。
        if ($organization === null) {
            abort(404);
        }

        // 組織文脈は URL だけで決まる (家系裁定 AG-037) ので、参加先組織の URL へ着地させる
        // (既存 invitations.accept.store の成功応答と同形。intended は使わない = 禁止事項 7)。
        return redirect()->route('dashboard', ['organization' => $organization->slug])
            ->with('success', "「{$organization->name}」に参加しました");
    }
}
