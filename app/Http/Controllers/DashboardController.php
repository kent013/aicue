<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * ダッシュボード (組織配下の着地点。概念設計 20260712-0029)。
 *
 * - 組織は **URL の binding が確定する** (家系裁定 AG-037: 組織文脈は URL だけで決まる)。
 *   組織文脈を持たない入口 (`/go`) は OrganizationEntryController が分岐する
 *   (所属 0 件なら組織作成へ、複数なら選ぶ画面へ)。
 * - 課金ゲート外 (未契約でも状況把握と復帰導線を提供。CTA は billing.index /
 *   billing.tickets.show = どちらも課金ゲート外 route に固定)
 */
final class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        Organization $organization,
        DashboardService $dashboard,
    ): Response {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // 役割分担: binder = 所属整合 (membership の構造的確認)、Policy = 最終認可。
        // 現状 OrganizationPolicy::view は所属と同値だが、Policy が将来厳格化しても
        // ここが最終判定である (binder 側の所属確認を認可とみなさない)
        Gate::authorize('view', $organization);

        return Inertia::render('Dashboard', [
            'dashboard' => $dashboard->build($user, $organization)->toArray(),
        ]);
    }
}
