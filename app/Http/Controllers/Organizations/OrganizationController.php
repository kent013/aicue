<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\Enums\TwoFactorStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\UpdateOrganizationRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organization\OrganizationProvisioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * 組織の作成・設定。
 * メンバー操作 (招待 / ロール / 削除 / 移譲) は専用 Controller に分離している。
 * `{organization}` は MembershipScopedOrganizationBinder が membership スコープで解決するため、
 * ここに到達した時点で「認証ユーザーが所属する組織」であることは保証済み (非メンバーは 404)。
 */
class OrganizationController extends Controller
{
    /** 新規組織作成フォーム */
    public function create(): Response
    {
        return Inertia::render('Organizations/Create');
    }

    /** 新規組織作成 → provisioning (Default Team 込み) → 作成した組織へ切替 */
    public function store(Request $request, OrganizationProvisioningService $provisioning): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);
        $name = $request->input('name');
        Assert::string($name);

        $organization = $provisioning->provision($user, $name);

        // 作成した組織を current にして設定画面へ (作成直後の文脈を維持する)
        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return redirect()->route('organizations.settings', $organization)->with('success', '組織を作成しました');
    }

    /**
     * 組織設定画面 (name 編集 / 2FA 必須方針 / オーナー移譲)。
     * メンバー管理 (一覧 / 招待 / ロール / 削除 / 2FA リセット) は管理メニュー > ユーザー管理
     * (/manage/users) へ移設済み。members はオーナー移譲 select 用の最小 shape (id/name) のみ
     * (email / 2FA を出さない = PII 最小化)。
     * API キー / 接続セッションは専用画面 (ApiKeys/Index, ApiKeys/Sessions) に分離し、
     * ここからはリンク導線 (canManageApiKeys) のみ出す。
     */
    public function settings(
        Request $request,
        Organization $organization,
    ): Response {
        Gate::authorize('view', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // オーナー移譲の移譲先 select 用途のみ (最小 props。email / 2FA は Admin/Users が担う)
        $members = $organization->users()->get()
            ->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
            ])
            ->values()
            ->all();

        return Inertia::render('Organizations/Settings', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'isPersonal' => $organization->is_personal,
                'twoFactorRequired' => $organization->two_factor_required,
            ],
            'members' => $members,
            'currentUserRole' => $user->organizationRole($organization)?->value,
            // API キー / 接続セッション管理画面への導線を出すか (境界は manageApiKeys と同一)
            'canManageApiKeys' => Gate::allows('manageApiKeys', $organization),
            // ユーザー管理画面 (管理メニュー) への導線 (can 連動。URL は route helper で生成)
            'usersUrl' => Gate::allows('manageMembers', $organization) ? route('manage.users.index') : null,
        ]);
    }

    /** 組織名の更新 */
    public function update(UpdateOrganizationRequest $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('update', $organization);

        $name = $request->validated('name');
        Assert::string($name);

        $organization->fill(['name' => $name])->save();

        return back()->with('success', '組織名を更新しました');
    }

    /**
     * 組織の 2FA 必須方針のトグル (Owner 専権。route 側で recent-auth 必須)。
     *
     * 有効化は「1 つでも必須組織に所属する未準拠ユーザーのアカウント全体をゲートする」契約
     * (RequireTwoFactorForEnforcedOrganizations)。操作者自身が未準拠のまま有効化すると
     * 自己ロックアウトするため前提条件として拒否する (GitHub org 2FA requirement と同じ)。
     * 解除に前提条件は無い (ゲートされた Owner は本画面に到達できないため運用上も発生しない)。
     */
    public function updateTwoFactorRequirement(Request $request, Organization $organization): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // Owner 専権 (update = owner/admin より狭い。transferOwnership と同じ owner 境界)
        Gate::authorize('transferOwnership', $organization);

        $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);
        // '1'/'true' 等の入力表現を単一の bool に正規化 (validated 値の直接比較は誤判定し得る)
        $enabled = $request->boolean('enabled');

        if ($enabled && $user->twoFactorStatus() !== TwoFactorStatus::Enabled) {
            throw ValidationException::withMessages([
                'enabled' => ['必須化するには、先にご自身の 2 段階認証を有効にしてください。'],
            ]);
        }

        // 状態更新は org 行 lock 付き transaction で行い、並行トグルの lost update を防ぐ。
        // lock 後に同値なら並行リクエストが先に反映済み = no-op (二重 flash 防止)
        DB::transaction(function () use ($organization, $enabled): void {
            /** @var Organization $lockedOrganization */
            $lockedOrganization = Organization::query()->lockForUpdate()->findOrFail($organization->id);

            if ($lockedOrganization->two_factor_required === $enabled) {
                return;
            }

            // セキュリティ方針キーのため forceFill で明示代入 ($fillable 外)
            $lockedOrganization->forceFill(['two_factor_required' => $enabled])->save();
        });

        return redirect()
            ->route('organizations.settings', $organization)
            ->with('success', $enabled ? '2 段階認証を必須にしました' : '2 段階認証の必須設定を解除しました');
    }
}
