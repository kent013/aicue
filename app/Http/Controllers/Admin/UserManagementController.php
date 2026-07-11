<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\DataTransferObjects\Admin\InvitationRowData;
use App\DataTransferObjects\Admin\MemberRowData;
use App\Enums\ProjectRole;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\Project\DefaultProjectResolver;
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
    use ResolvesCurrentOrganization;

    public function index(Request $request, DefaultProjectResolver $defaultProjects): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
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

        $members = [];
        foreach ($organization->users()->get() as $member) {
            // organizationRole null (attach 済みだが Laratrust ロール未付与の異常行) も
            // 非表示にせず「未割当」として可視化する (derive が null を Unassigned へ丸める。
            // 管理者はロール割当コマンドでこの行を修復できる = applyConsoleRole の修復経路)
            $members[] = MemberRowData::fromUser(
                $member,
                $member->organizationRole($organization),
                $pivotRoles[$member->id] ?? null,
                $user->id,
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
            // 管理メニュー nav: カテゴリ管理リンク (can 連動 + project 不在は非表示)。
            // URL は route helper で生成 (route 名変更耐性)
            'categoriesUrl' => $project !== null && $user->can('update', $project)
                ? route('projects.categories.index', $project)
                : null,
        ]);
    }
}
