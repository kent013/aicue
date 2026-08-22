<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\DataTransferObjects\Admin\InvitationRowData;
use App\DataTransferObjects\Admin\MemberRowData;
use App\Enums\ProjectRole;
use App\Http\Concerns\ResolvesRouteOrganization;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Project\DefaultProjectResolver;
use App\Services\Security\LastLoginLookup;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * 管理メニュー > ユーザー管理 (doc/04 §4.2。GET のみ)。
 * 書き込みは既存 organizations.* endpoint (招待 / ロール変更 / 削除 / 2FA リセット) を使う。
 * URL は /manage/* (Filament panel が /admin/* を占有しているため。詳細設計 §リファレンス)。
 * current org スコープ解決のみで org URL param を持たない = cross-org 越境不能。
 */
class UserManagementController extends Controller
{
    use ResolvesRouteOrganization;

    public function index(
        Request $request, Organization $organization,
        DefaultProjectResolver $defaultProjects,
        LastLoginLookup $lastLogins,
    ): Response {
        Gate::authorize('manageMembers', $organization); // 撮影者・一般メンバーは 403

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $project = $defaultProjects->resolve($organization);

        // Default Project の pivot ロールを 1 クエリで引く (user_id => ProjectRole)
        /** @var array<int, ProjectRole> $pivotRoles */
        $pivotRoles = [];
        if ($project !== null) {
            foreach ($project->members()->get() as $member) {
                $pivot = $member->getRelationValue('pivot');
                $role = $pivot instanceof Pivot ? $pivot->getAttribute('role') : null;
                if (is_string($role)) {
                    $pivotRoles[$member->id] = ProjectRole::from($role);
                }
            }
        }

        // メンバー集合は org relation 経由でのみ解決する (cross-org 越境不能)
        $organizationMembers = $organization->users()->get();

        // 最終ログインは行ごとに引かず、id 集合に対して 1 クエリで写像を作る (N+1 を作らない)。
        // 渡す id 集合は上の relation の結果そのものなので、他組織の利用者は構造的に入らない。
        // pluck() は Collection<int, mixed> に落ちて list<int> の narrowing が自己申告になるため、
        // 型が閉じる array_map + array_values で作る (型を緩めて黙らせない = 禁止事項 2)
        $memberIds = array_values(array_map(
            static fn (User $member): int => $member->id,
            $organizationMembers->all(),
        ));
        $lastLoginMap = $lastLogins->forUserIds($memberIds);

        $members = [];
        foreach ($organizationMembers as $member) {
            // organizationRole null (attach 済みだが Laratrust ロール未付与の異常行) も
            // 非表示にせず「未割当」として可視化する (derive が null を Unassigned へ丸める。
            // 管理者はロール割当コマンドでこの行を修復できる = applyConsoleRole の修復経路)
            $members[] = MemberRowData::fromUser(
                $member,
                $member->organizationRole($organization),
                $pivotRoles[$member->id] ?? null,
                $user->id,
                $lastLoginMap[$member->id] ?? null,
            );
        }

        $invitations = $organization->invitations()->active()->get()
            ->map(fn (OrganizationInvitation $invitation): InvitationRowData => InvitationRowData::fromInvitation($invitation))
            ->values()
            ->all();

        return Inertia::render('Admin/Users', [
            'organizationSlug' => $organization->slug,
            'members' => $members,         // list<MemberRowData>
            'invitations' => $invitations, // list<InvitationRowData>
            'hasDefaultProject' => $project !== null,
        ]);
    }
}
