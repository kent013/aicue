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
use App\Services\Manual\ManualKeywordSearch;
use App\Services\Project\DefaultProjectResolver;
use App\Support\Seo\SeoManager;
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

        $user = $request->user();
        Assert::isInstanceOf($user, User::class); // view 認可済み = 認証済み。早期に int を確定
        $userId = $user->id;

        $categoryId = $request->filled('category') ? (int) $request->string('category')->value() : null;
        // 検索語の正規化 (trim + 先頭 200 文字) の正本は ManualKeywordSearch。
        // PC 一覧 (ManualListQuery 経由) と**同じ関数**を通す
        $rawSearch = $request->query('q');
        $search = ManualKeywordSearch::normalize(is_string($rawSearch) ? $rawSearch : null);
        $mine = $request->boolean('mine'); // "1"/"true" を bool 正規化

        // 代表サムネイルの可視性は **project 単位に 1 回**だけ決める (行ごとに評価しない)。
        // 一覧の閲覧は組織メンバーなら可 (view) だが、サムネイル endpoint は
        // ProjectPolicy::capture (project メンバー以上) を要求する。この差を props 側で吸収し、
        // 撮れない利用者には 403 になる <img> を 1 つも描かせない (秘匿境界は props 側)。
        // Gate::allows は例外を投げないため、撮れない利用者の一覧表示は現状どおり成功する。
        $canViewCover = Gate::allows('capture', $project);

        $manuals = $project->manuals()
            ->whereIn('status', [VideoManualStatus::Ready, VideoManualStatus::Published])
            ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
            // title + カット本文 (scene / narration / subtitle_*) の部分一致。
            // 述語の正本は ManualKeywordSearch (PC 一覧と同じ関数を通る)。
            // **入れ子 group で括られる**ため、ready/published の母集団制限と
            // category / mine の絞り込みは OR に押し出されない
            ->when($search !== null, function (Builder $query) use ($search): void {
                Assert::string($search);
                ManualKeywordSearch::apply($query, $search);
            })
            // 自作フィルタ: 自ユーザー id のみ (payload 非受領 = tenant/actor キー不信)
            ->when($mine, fn (Builder $query) => $query->where('created_by', $userId))
            ->with(['category', 'creator'])
            // 代表サムネイル: 候補カットと**その採用テイクまで**入れ子で eager load する。
            // adoptedTake を載せ忘れると AdoptedReadyTakeCoverage::readyTakeId() が
            // 行ごとに lazy load して N+1 になる。見せない利用者には積まない。
            ->when($canViewCover, fn (Builder $query) => $query->with(['coverCut.adoptedTake']))
            ->withCount([
                'cuts',
                // 採用済み cut 数 (relation 経由 = 'adopted_take_id' リテラルを撮影経路に増やさない)
                'cuts as cuts_adopted_count' => fn (Builder $query) => $query->whereHas('adoptedTake'),
                'cuts as cuts_with_takes_count' => fn (Builder $query) => $query->whereHas('takes'),
            ])
            ->orderByDesc('updated_at')
            ->get()
            ->map(static fn (VideoManual $manual): array => CaptureManualSummaryData::fromManual($manual, $canViewCover)->toArray())
            ->all();

        return Inertia::render('Capture/Index', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'manuals' => array_values($manuals),
            'categories' => $project->categories()
                ->orderBy('sort_order')
                ->get()
                ->map(static fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
                ->all(),
            'filters' => ['category' => $categoryId, 'q' => $search, 'mine' => $mine],
        ]);
    }

    /** 撮影ナビ (cuts + 全 take メタ + 採用テイク署名 DL URL / ACK トークン) */
    public function show(
        Request $request,
        Project $project,
        VideoManual $manual,
        TakeObjectStorage $storage,
        UploadTicketCodec $codec,
        SeoManager $seo,
    ): Response {
        $organization = $this->resolveCurrentOrganization($request);
        $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
        Gate::authorize('view', $manual); // 読み取りは撮影者含む org member

        // 撮影 PWA であることをタブ上で判別可能にする動的固有名
        $seo->setPrivateTitle($manual->title.' の撮影');

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // メタ情報 (カテゴリ名 / 作成者名) の解決を DTO 側の lazy load に任せない。
        // 対象は 1 行なので追加は最大 2 クエリ (既にロード済みなら 0)。
        $manual->loadMissing(['category', 'creator']);

        return Inertia::render('Capture/Show', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'manual' => CaptureManualDetailData::fromManual($manual, $user, $storage, $codec)->toArray(),
            // 通し再生でプレースホルダを表示する秒数。サーバ生成プレビューの黒背景尺と
            // **同じ設定値**を使う (2 つのプレビューの構造を揃える。単位は秒・正の整数)。
            'previewPlaceholderSeconds' => config()->integer('manual.preview_placeholder_seconds'),
        ]);
    }
}
