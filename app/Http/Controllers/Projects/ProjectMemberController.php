<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Enums\ProjectRole;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Webmozart\Assert\Assert;

/**
 * プロジェクトメンバー管理 (project_members pivot の追加・削除)。
 *
 * - 追加対象は payload の user_id で受ける。URL 上の子リソース指定ではないので
 *   404 秘匿ではなく **field error (validation failure)** に倒す。不在 id・他組織ユーザーを
 *   同一文言に揃えることで存在オラクルを閉じている (aicue:T118)
 * - 削除対象は URL の {user}。org member でなければ**認可より前に 404**
 *   (cross-tenant の存在を漏らさない)
 * - ロール変更は削除→追加でなく store の再実行 (syncWithoutDetaching + pivot 更新) で行う
 */
class ProjectMemberController extends Controller
{
    use ResolvesCurrentOrganization;

    /**
     * 追加対象が現在組織のメンバーとして解決できないときの文言。
     * 「不在 id」「他組織のユーザー」「pivot 在籍だがロール未付与の異常行」を
     * **同一文言**へ落とすことで users.id の存在オラクルを閉じる (aicue:T118)。
     */
    private const NOT_ORGANIZATION_MEMBER_MESSAGE = '追加できるのは組織のメンバーだけです。';

    /** メンバー追加 (組織メンバーのみ。既存メンバーはロール更新になる) */
    public function store(Request $request, Project $project): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // 層 2: URL 整合 guard (認可より前に 404)
        $this->resolveOrganizationProject($organization, $project);
        // 層 3: 認可。payload に触れる前に通す (順序は PayloadIdExistenceOracleTest が固定)
        Gate::authorize('update', $project);

        // 形式検証のみ (`exists:users,id` は全体実在を漏らすため使わない)
        $request->validate([
            'user_id' => ['required', 'integer'],
            'role' => ['required', 'string', Rule::enum(ProjectRole::class)],
        ]);
        $userId = $request->input('user_id');
        Assert::integerish($userId);
        $role = $request->input('role');
        Assert::string($role);

        // 追加対象は現在組織の relation から解決する。
        // pivot 在籍 (organization_user) と Laratrust ロール付与は同値ではない
        // (OrganizationMembershipService の「ロール未付与の異常行」修復契約) ため、
        // ロール判定も残す。両者の失敗は同一 field error に落とす。
        $target = $organization->users()->whereKey((int) $userId)->first();
        if (! $target instanceof User || $target->organizationRole($organization) === null) {
            throw ValidationException::withMessages([
                'user_id' => [self::NOT_ORGANIZATION_MEMBER_MESSAGE],
            ]);
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
