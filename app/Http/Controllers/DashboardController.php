<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\Organization\CurrentOrganizationResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * ダッシュボード (ログイン直後の着地点。概念設計 20260712-0029)。
 *
 * - ResolvesCurrentOrganization は使わない (current org なしを 404 にせず setup 表示に倒す)
 * - 表示組織は CurrentOrganizationResolver (所属再確認つき + 自己修復) で解決
 * - 課金ゲート外 (未契約でも状況把握と復帰導線を提供。CTA は billing.index /
 *   billing.tickets.show = どちらも課金ゲート外 route に固定)
 * - route param なし・payload なし = NestedRouteIdorDefenseTest inventory 対象外
 */
final class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentOrganizationResolver $organizations,
        DashboardService $dashboard,
    ): Response {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $organization = $organizations->resolve($user);
        if ($organization !== null) {
            // 役割分担: resolver = 所属整合 (membership の構造的確認)、Policy = 最終認可。
            // 現状 OrganizationPolicy::view は所属と同値だが、Policy が将来厳格化しても
            // ここが最終判定である (resolver 側の所属確認を認可とみなさない)
            Gate::authorize('view', $organization);
        }

        return Inertia::render('Dashboard', [
            'dashboard' => $dashboard->build($user, $organization)->toArray(),
        ]);
    }
}
