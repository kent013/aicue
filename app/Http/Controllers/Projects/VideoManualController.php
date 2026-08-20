<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\DataTransferObjects\Manual\AnalysisJobData;
use App\DataTransferObjects\Manual\CutTakeSummaryData;
use App\DataTransferObjects\Manual\ManualListQuery;
use App\DataTransferObjects\Manual\RenderJobData;
use App\DataTransferObjects\Manual\ScenarioDocumentData;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Projects\DuplicateVideoManualRequest;
use App\Http\Requests\Projects\StoreVideoManualRequest;
use App\Http\Requests\Projects\UpdateVideoManualRequest;
use App\Models\Category;
use App\Models\Cut;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\AdoptedReadyTakeCoverage;
use App\Services\Manual\CurrentRenderArtifact;
use App\Services\Manual\ScenarioReportBuilder;
use App\Services\Manual\VideoManualService;
use App\Support\Manual\AcceptedSourceDocumentTypes;
use App\Support\Seo\SeoManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * VideoManual (Project 配下の動画マニュアル) の CRUD。
 * 一覧は Projects/Show が内包する (専用 index は持たない)。
 *
 * nested route の URL 整合は 2 層 (Item 見本と同じ):
 * 1. {project} ∈ current org (resolveOrganizationProject = inline guard)
 * 2. {manual} ∈ {project} (routes 側の Route::scopeBindings() = $project->manuals() 経由)
 * いずれも**認可より前に 404** (403 で存在を漏らさない)。
 *
 * 入力名の境界: カテゴリは payload の `category` (id 値) で受け、保護キー `category_id` は
 * 直送で 422。Controller は validated('category') のみ参照し、Service がロック済み
 * project relation から再解決して associate する (validated('category_id') は参照しない)。
 */
class VideoManualController extends Controller
{
    use ResolvesCurrentOrganization;

    /** 作成フォーム (カテゴリ選択肢を props で供給。撮影者は 403) */
    public function create(Request $request, Project $project): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('create', [VideoManual::class, $project]);

        return Inertia::render('Manuals/Create', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'categories' => $this->categoryOptions($project),
            // 作成と同時の SOP アップロードの受理形式 (画像・スキャン SOP の OCR 対応)。
            // StoreVideoManualRequest と同じ AcceptedSourceDocumentTypes を情報源にする
            // = ダイアログに出る形式とサーバが受理する形式が構造的に一致する。
            'sourceDocumentAccept' => AcceptedSourceDocumentTypes::acceptAttribute(),
            'imageSourceDocumentsEnabled' => AcceptedSourceDocumentTypes::imagesEnabled(),
            // help 文言用の受理形式ラベル (422 文言と同一の情報源)
            'sourceDocumentFormatsLabel' => AcceptedSourceDocumentTypes::formatsLabel(),
        ]);
    }

    /** VideoManual 作成。project_id / created_by はサーバ導出 (payload では 422) */
    public function store(StoreVideoManualRequest $request, Project $project, VideoManualService $manuals): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('create', [VideoManual::class, $project]);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $title = $request->validated('title');
        Assert::string($title);
        // 入力名は category (保護キー category_id とは別名)。null = 未分類
        $category = $request->validated('category');
        Assert::nullOrIntegerish($category);
        // SOP 同時アップロード (任意)
        $document = $request->validated('document');
        Assert::nullOrIsInstanceOf($document, UploadedFile::class);

        $manual = $manuals->create($project, $title, $category === null ? null : (int) $category, $user->id, $document);

        return redirect()
            ->route('projects.manuals.show', [$project, $manual])
            ->with('success', '動画マニュアルを作成しました');
    }

    /** 詳細 (撮影者も閲覧可) */
    public function show(
        Request $request,
        Project $project,
        VideoManual $manual,
        SeoManager $seo,
        VideoManualService $manuals,
        ScenarioReportBuilder $reports,
    ): Response {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('view', $manual);

        // 動的固有名の per-page タイトル (noindex 維持。projects.show の参考実装踏襲)
        $seo->setPrivateTitle($manual->title);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $category = $manual->category;

        // stale な失敗 (失敗確定後に scenario 保存が成立) は job=null で抑制する (T032 / F-1-1)
        $analysisJob = $manuals->displayAnalysisJob($manual);
        $renderJob = $manuals->displayRenderJob($manual);
        $previewJob = $manuals->displayPreviewJob($manual);
        // 再生できるプレビュー (最新 succeeded preview)。**id だけでなく行そのもの**を props に載せる:
        // 動画 URL と「黒背景が何カット分か」の注記が同一オブジェクトから出るため、
        // 最新 preview job と再生対象が別世代になる穴が構造的に消える (T148)。
        // succeeded preview のみを見るため staleness 抑制の対象外 (不変)。
        // 選択式は CurrentRenderArtifact に集約済み (route 側と同一の行を指す = T154)。
        $playbackJob = CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Preview);
        // 受け取れる完成動画。**endpoint が 302 を返す条件と 1 対 1**にする:
        // published + download ability + 現行世代。UI の canManage は表示制御であって
        // 秘匿境界ではないため、ここで ability を評価する (条件を UI 側に持たせない)。
        $finishedJob = $manual->status === VideoManualStatus::Published && $user->can('download', $manual)
            ? CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)
            : null;

        return Inertia::render('Manuals/Show', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'manual' => [
                'id' => $manual->id,
                'title' => $manual->title,
                'status' => $manual->status->value,
                'category' => $category === null
                    ? null
                    : ['id' => $category->id, 'name' => $category->name],
                'created_at' => $manual->created_at?->format('Y-m-d H:i') ?? '',
            ],
            // AI 解析パネル (最新 job + 手順書有無)。AnalysisJobData::toArray() と対
            'analysis' => [
                'job' => $analysisJob === null
                    ? null
                    : AnalysisJobData::fromJob($analysisJob, $manual)->toArray(),
                'hasDocument' => $manual->sourceDocuments()->exists(),
                // 生成結果の確認 (LLM の所見 + 現在の cuts への決定的検査)。null = 出す材料が無い。
                // 描画時点のスナップショットであり常に最新ではない (render.coverage と同じ性質)。
                'report' => $reports->build($manual)?->toArray(),
            ],
            // レンダパネル (最新 render job / 最新 preview job / 再生可能 preview / 完成動画)。RenderProps と対
            'render' => [
                'job' => $renderJob === null
                    ? null
                    : RenderJobData::fromJob($renderJob, $manual)->toArray(),
                'previewJob' => $previewJob === null
                    ? null
                    : RenderJobData::fromJob($previewJob, $manual)->toArray(),
                'playbackJob' => $playbackJob === null
                    ? null
                    : RenderJobData::fromJob($playbackJob, $manual)->toArray(),
                // 完成動画 (再生 + DL の唯一の出し分け根拠)。null = 出さない
                'finishedJob' => $finishedJob === null
                    ? null
                    : RenderJobData::fromJob($finishedJob, $manual)->toArray(),
                // 「使用できる採用テイクがない」カットの充足状況。render の 422 と**同じ述語**から出す
                // = 判断基準を 1 箇所に置く (bug-hunt F-1-01)。描画時点のスナップショットであり
                // 常に最新ではない (押下は止めないので詰みにはならない)。
                'coverage' => AdoptedReadyTakeCoverage::for($manual)->toProps(),
            ],
            'canManage' => $user->can('update', $manual),
            'categories' => $this->categoryOptions($project), // 複製ダイアログのカテゴリ選択肢 (既存 helper 再利用)
            // SOP アップロードの受理形式 (画像・スキャン SOP の OCR 対応)。
            // AcceptedSourceDocumentTypes が単一の情報源 (フラグに連動)
            'sourceDocumentAccept' => AcceptedSourceDocumentTypes::acceptAttribute(),
            'imageSourceDocumentsEnabled' => AcceptedSourceDocumentTypes::imagesEnabled(),
        ]);
    }

    /** VideoManual 複製 (別名保存)。保存済み cuts を雛形に新タイトル・カテゴリで新規作成し詳細へ遷移 */
    public function duplicate(DuplicateVideoManualRequest $request, Project $project, VideoManual $manual, VideoManualService $manuals): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('duplicate', $manual);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $copy = $manuals->duplicate($project, $manual, $request->title(), $request->categoryId(), $user->id);

        return redirect()
            ->route('projects.manuals.show', [$project, $copy])
            ->with('success', '動画マニュアルを複製しました（手順書は引き継がれません）');
    }

    /** 編集フォーム (メタデータ = title / category + シナリオ document) */
    public function edit(Request $request, Project $project, VideoManual $manual, SeoManager $seo): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $manual);

        // 複数 manual の並行編集タブを判別できるよう動的固有名 (概念レビュー合意)
        $seo->setPrivateTitle($manual->title.' の編集');

        return Inertia::render('Manuals/Edit', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
            'manual' => [
                'id' => $manual->id,
                'title' => $manual->title,
                'category' => $manual->category_id,
                'status' => $manual->status->value, // rendering / analyzing 中の警告表示用
            ],
            'categories' => $this->categoryOptions($project),
            // シナリオ document (保存成功応答 ScenarioResource と同一 shape)
            'scenario' => ScenarioDocumentData::fromManual($manual)->toArray(),
            // 動画列 (カットごとのテイク要約)。描画時点のスナップショットであり常に最新ではない
            // (採用は他タブ / 撮影 PWA からも起きる。判断はサーバの行ロックが直列化する)
            'takeSummaries' => $this->takeSummaries($manual),
        ]);
    }

    /**
     * 動画列用のカット別テイク要約。
     *
     * cut 件数に依存しない**定数本のクエリ**で取る (withCount は cuts の SELECT に畳まれ、
     * adoptedTake は eager load の 1 本。cut ごとの追加クエリ = N+1 を作らない)。
     * 並びは CutSequencer と同じ (sort_order, id) にする (同値 sort_order で揺れないため)。
     *
     * @return list<array{cut_id: int, takes_count: int,
     *   adopted: array{id: int, status: string, has_thumbnail: bool}|null}>
     */
    private function takeSummaries(VideoManual $manual): array
    {
        return array_values($manual->cuts()
            ->withCount('takes')
            ->with('adoptedTake')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (Cut $cut): array => CutTakeSummaryData::fromCut($cut)->toArray())
            ->all());
    }

    /** メタデータ更新 (title / category)。category null は未分類化 */
    public function update(UpdateVideoManualRequest $request, Project $project, VideoManual $manual, VideoManualService $manuals): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $manual);

        $title = $request->validated('title');
        Assert::string($title);
        // 入力名は category (保護キー category_id とは別名)。null = 未分類
        $category = $request->validated('category');
        Assert::nullOrIntegerish($category);

        $manuals->updateMeta($project, $manual, $title, $category === null ? null : (int) $category);

        return back()->with('success', '動画マニュアルを更新しました');
    }

    /**
     * 削除。
     *
     * 一覧の行から消したときは、削除要求に一覧の絞り込み・ページが付いてくる
     * (`?category=…&status=…&q=…&sort=…&mine=1&page=2`)。受け取った値は**一覧と同じ allowlist**
     * (ManualListQuery) を通してから着地先に載せ直す = 生のユーザー入力を Location に素通ししない。
     * クエリが無いとき (詳細画面からの削除) は現行と同じ `/projects/{project}` へ着地する。
     * 消した結果そのページが範囲外になった場合は、着地先の一覧が最終ページへ丸める。
     *
     * 付いてくるクエリは**対象の決定には一切使わない** (対象は route パラメータのみが決める)。
     */
    public function destroy(Request $request, Project $project, VideoManual $manual, VideoManualService $manuals): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('delete', $manual);

        $listQuery = ManualListQuery::fromRequest($request);

        $manuals->delete($project, $manual);

        return redirect()
            ->route('projects.show', ['project' => $project, ...$listQuery->toQueryParams()])
            ->with('success', '動画マニュアルを削除しました');
    }

    /**
     * カテゴリセレクトの選択肢 (sort_order 順)。未分類はフロント側で null 選択肢として表現する。
     *
     * @return list<array{id: int, name: string}>
     */
    private function categoryOptions(Project $project): array
    {
        return array_values($project->categories()->orderBy('sort_order')->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
            ])
            ->all());
    }
}
