<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * プロフィール設定画面 (GET /settings)。
 * 削除前警告用に「唯一 Owner で他メンバーが残る組織」のスナップショットを props で返す。
 */
class ProfileController extends Controller
{
    public function index(Request $request, OrganizationMembershipService $membership): Response
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        return Inertia::render('Settings/Index', [
            // 削除前警告用。唯一 Owner で他メンバーが残る組織 (name + 各組織設定への導線 slug)。
            // 表示時点のスナップショット (最終判定は削除時にサーバーが再評価)。
            'soleOwnedOrganizations' => $membership->organizationsBlockingDeletion($user)
                ->map(fn (Organization $organization): array => [
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                ])
                ->values()
                ->all(),
            // パスワードカードの出し分け。password 未設定ユーザーに current_password 必須の
            // 変更フォームを出すと必ず失敗する (踏破不能 UI) ため、初回設定フォームへ切り替える。
            'hasPassword' => $user->hasPassword(),
        ]);
    }
}
