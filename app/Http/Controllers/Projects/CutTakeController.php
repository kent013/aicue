<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\DataTransferObjects\Manual\TakeSelectionPageData;
use App\Http\Concerns\ResolvesRouteOrganization;
use App\Http\Controllers\Controller;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\VideoManual;
use App\Support\Seo\SeoManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * テイク選択・採用画面 (doc/04)。編集者がカットごとのテイクを見て採用を確定する面。
 *
 * nested route の URL 整合は 2 層 (認可より前に 404):
 * 1. {project} ∈ current org (project.in-route-org middleware + resolveOrganizationProject)
 * 2. {manual} ∈ {project}, {cut} ∈ {manual} (Route::scopeBindings())
 *
 * 本 controller は**読み取りのみ**である。採用・削除・アップロード・再生は
 * capture.takes.* (撮影 PWA と共用の API 面) が担い、cuts の採用テイク外部キーを書くのは
 * 従来どおり Capture/CaptureTakeService::adopt() だけである
 * (AGENTS.md ドメイン固有規約 1 / ScenarioWritePathInventoryTest 検出 4)。
 */
class CutTakeController extends Controller
{
    use ResolvesRouteOrganization;

    /** テイク選択画面 (編集者のみ。撮影者は 403 = PWA 側に採用導線がある) */
    public function index(
        Request $request, Organization $organization,
        Project $project,
        VideoManual $manual,
        Cut $cut,
        SeoManager $seo,
    ): Response {
        // URL 整合 guard: 認可より前に 404 ({manual}∈{project}, {cut}∈{manual} は scopeBindings)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $manual); // VideoManualPolicy::update = 編集者

        $page = TakeSelectionPageData::fromCut($project, $manual, $cut);
        // 並行編集タブを判別できる動的固有名 (noindex 維持。既存 edit/show と同方針)
        $seo->setPrivateTitle($manual->title.' / '.$page->label.' のテイク選択');

        return Inertia::render('Manuals/Takes', $page->toArray());
    }
}
