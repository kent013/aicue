<?php

declare(strict_types=1);

namespace App\Http\Controllers\Capture;

use App\DataTransferObjects\Capture\CaptureManualDetailData;
use App\DataTransferObjects\Capture\CaptureManualSummaryData;
use App\Enums\Manual\VideoManualStatus;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Capture\UploadTicketCodec;
use App\Services\Project\DefaultProjectResolver;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * 撮影 PWA の画面シェル (doc/10 §10.3 / 概念設計 D1/D9)。GET は Inertia、書き込みは XHR JSON。
 * PC 管理 UI (/projects/...) とは URL 空間ごと分離 (/app/... = 撮影 PWA 専用)。
 */
class CaptureManualController extends Controller
{
    use ResolvesCurrentOrganization;

    /**
     * PWA エントリ (manifest start_url)。current org の先頭 project の一覧へ redirect
     * (v1 は単一 Default Project 前提。複数 project 化の際は選択画面へ差し替え)。
     */
    public function home(Request $request, DefaultProjectResolver $defaultProjects): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        $project = $defaultProjects->resolve($organization);
        abort_if($project === null, 404);

        return redirect()->route('capture.manuals.index', ['project' => $project]);
    }

    /** 撮影対象 (ready/published) の manual 一覧。category / q で絞り込み */
    public function index(Request $request, Project $project): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
        Gate::authorize('view', $project);

        $categoryId = $request->filled('category') ? (int) $request->string('category')->value() : null;
        $search = $request->filled('q') ? $request->string('q')->value() : null;

        $manuals = $project->manuals()
            ->whereIn('status', [VideoManualStatus::Ready, VideoManualStatus::Published])
            ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
            ->when($search !== null, fn (Builder $query) => $query->where('title', 'like', '%'.$search.'%'))
            ->with('category')
            ->withCount([
                'cuts',
                // 採用済み cut 数 (relation 経由 = 'adopted_take_id' リテラルを撮影経路に増やさない)
                'cuts as cuts_adopted_count' => fn (Builder $query) => $query->whereHas('adoptedTake'),
                'cuts as cuts_with_takes_count' => fn (Builder $query) => $query->whereHas('takes'),
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(static fn (VideoManual $manual): array => CaptureManualSummaryData::fromManual($manual)->toArray())
            ->all();

        return Inertia::render('Capture/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'manuals' => array_values($manuals),
            'categories' => $project->categories()
                ->orderBy('sort_order')
                ->get()
                ->map(static fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
                ->all(),
            'filters' => ['category' => $categoryId, 'q' => $search],
        ]);
    }

    /** 撮影ナビ (cuts + 全 take メタ + 採用テイク署名 DL URL / ACK トークン) */
    public function show(
        Request $request,
        Project $project,
        VideoManual $manual,
        TakeObjectStorage $storage,
        UploadTicketCodec $codec,
    ): Response {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
        Gate::authorize('view', $manual); // 読み取りは撮影者含む org member

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        return Inertia::render('Capture/Show', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'manual' => CaptureManualDetailData::fromManual($manual, $user, $storage, $codec)->toArray(),
        ]);
    }
}
