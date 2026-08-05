<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Enums\ProjectRole;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Webmozart\Assert\Assert;

/**
 * プロジェクトメンバー管理 (project_members pivot の追加・削除)。
 *
 * - 追加対象は payload の user_id で受ける (URL 子リソースでないため 404 秘匿でなく、
 *   同一組織メンバーでなければ 403 = cross-org 招へいの禁止)
 * - 削除対象は URL の {user}。org member でなければ**認可より前に 404**
 *   (cross-tenant の存在を漏らさない)
 * - ロール変更は削除→追加でなく store の再実行 (syncWithoutDetaching + pivot 更新) で行う
 */
class ProjectMemberController extends Controller
{
    use ResolvesCurrentOrganization;

    /** メンバー追加 (組織メンバーのみ。既存メンバーはロール更新になる) */
    public function store(Request $request, Project $project): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $project);

        $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['required', 'string', Rule::enum(ProjectRole::class)],
        ]);
        $userId = $request->input('user_id');
        Assert::integerish($userId);
        $role = $request->input('role');
        Assert::string($role);

        /** @var User $target */
        $target = User::query()->findOrFail((int) $userId);

        // 追加対象が同一組織メンバーでなければ拒否 (cross-org 不変条件。payload 由来のため
        // 存在秘匿 404 でなく認可拒否 403 に倒す)
        if ($target->organizationRole($organization) === null) {
            throw new AuthorizationException('Target user is not a member of this organization.');
        }

        // pivot ロールは明示代入 (既存メンバーはロール更新)
        $project->members()->syncWithoutDetaching([
            $target->id => ['role' => ProjectRole::from($role)->value],
        ]);

        return back()->with('success', 'プロジェクトメンバーを追加しました');
    }

    /**
     * メンバー削除 (explicit member の detach。暗黙メンバー = org owner/admin は対象外)。
     *
     * {user} は **implicit binding を使わない** (action 引数を string で受ける)。
     * implicit binding だと「不在 id = binding 段の 404 / 実在の非メンバー = 後段 middleware の
     * 302/402」という差分が users.id の存在オラクルになるため
     * (audit-cycle-2 High-1 横断)、組織メンバーに閉じた relation から手動解決して
     * 両者を同一の応答に落とす。binding 段で解決されない = 不在も実在も同じ経路を辿る。
     * 型制約 (数値・18 桁上限) は RouteBindingTypes の pattern が担保する。
     */
    public function destroy(Request $request, Project $project, string $user): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 (cross-tenant の {user} の存在を漏らさない)
        $this->resolveOrganizationProject($organization, $project);

        /** @var User $member 組織メンバー以外・不在 id はここで等しく 404 */
        $member = $organization->users()->whereKey($user)->firstOrFail();

        Gate::authorize('update', $project);

        $project->members()->detach($member->getKey());

        return back()->with('success', 'プロジェクトメンバーを削除しました');
    }
}
