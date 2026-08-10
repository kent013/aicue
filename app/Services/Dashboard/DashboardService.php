<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\DataTransferObjects\Dashboard\BillingSummaryData;
use App\DataTransferObjects\Dashboard\DashboardPageData;
use App\DataTransferObjects\Dashboard\InProgressManualData;
use App\DataTransferObjects\Dashboard\RecentManualData;
use App\DataTransferObjects\Dashboard\ShootingTargetData;
use App\Enums\Dashboard\DashboardRole;
use App\Enums\Dashboard\DashboardState;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\QuotaKey;
use App\Models\AnalysisJob;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\BillingAccess;
use App\Services\Billing\QuotaService;
use App\Services\Billing\TicketLedgerService;
use App\Services\Capture\StorageUsageService;
use App\Services\Project\DefaultProjectResolver;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Webmozart\Assert\Assert;

/**
 * ダッシュボードのサーバ集計 (読み取り専用。固定本数のクエリで N+1 なし)。
 * 集計対象はすべて $organization / $project の relation 経由 = cross-org 構造的不可。
 * $organization は CurrentOrganizationResolver が所属再確認済みのものだけが渡される契約。
 */
class DashboardService
{
    private const int LIST_LIMIT = 5;

    public function __construct(
        private readonly DefaultProjectResolver $defaultProjects,
        private readonly TicketLedgerService $tickets,
        private readonly QuotaService $quota,
        private readonly StorageUsageService $storage,
        private readonly BillingAccess $billingAccess,
    ) {}

    public function build(User $user, ?Organization $organization): DashboardPageData
    {
        if ($organization === null) {
            return new DashboardPageData(
                state: DashboardState::NoOrganization, role: null, canCreateProject: false,
                organizationName: null, projectId: null, projectName: null,
                inProgress: [], recentManuals: [], shootingTargets: [], billing: null,
            );
        }

        $billing = $this->billingSummary($organization);
        $project = $this->defaultProjects->resolve($organization);
        if ($project === null) {
            return new DashboardPageData(
                state: DashboardState::NoProject, role: null,
                canCreateProject: $user->can('create', [Project::class, $organization]),
                organizationName: $organization->name, projectId: null, projectName: null,
                inProgress: [], recentManuals: [], shootingTargets: [], billing: $billing,
            );
        }

        $role = $this->resolveRole($user, $project);

        return new DashboardPageData(
            state: DashboardState::Ready, role: $role, canCreateProject: false,
            organizationName: $organization->name,
            projectId: $project->id, projectName: $project->name,
            inProgress: $this->inProgress($project),
            recentManuals: $this->recentManuals($project),
            shootingTargets: $this->shootingTargets($project),
            billing: $billing,
        );
    }

    /** ProjectPolicy へ委譲した結果の写像 (laratrust_team_id 明示判定は Policy 内) */
    private function resolveRole(User $user, Project $project): DashboardRole
    {
        if ($user->can('update', $project)) {
            return DashboardRole::Editor;
        }
        if ($user->can('capture', $project)) {
            return DashboardRole::Shooter;
        }

        return DashboardRole::Viewer;
    }

    /**
     * 進行中ジョブ: analyzing/rendering の manual + 進行中 job (queued/running)。
     * job の引き当ては「relation subquery で manual id を絞った standalone クエリ」
     * (VideoManualService::delete の既存パターンと同型 = 構造的に project スコープ)。
     * 3 クエリ固定。in-flight は manual×操作種別あたり 1 本の既存不変条件 (doc/10 §10.8-8)
     * があり、keyBy はその防御的な決定化 (orderBy('id') 昇順 + keyBy 後勝ちで
     * 万一の複数行も最新 1 本に確定)。
     *
     * @return list<InProgressManualData>
     */
    private function inProgress(Project $project): array
    {
        $manuals = $project->manuals()
            ->whereIn('status', [VideoManualStatus::Analyzing, VideoManualStatus::Rendering])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        /** @var list<int> $manualIds */
        $manualIds = $manuals->pluck('id')->all();

        // 進行中マニュアルが 0 件なら job クエリ 2 本を省略する
        if ($manualIds === []) {
            return [];
        }

        // keyBy('video_manual_id') + orderBy('id') 昇順取得 → 最終要素が最新 (keyBy は後勝ち)
        /** @var Collection<int, AnalysisJob> $analysisJobs */
        $analysisJobs = AnalysisJob::query()
            ->whereIn('video_manual_id', $manualIds)
            ->whereIn('status', [JobStatus::Queued, JobStatus::Running])
            ->orderBy('id')
            ->get()
            ->keyBy('video_manual_id');
        /** @var Collection<int, RenderJob> $renderJobs */
        $renderJobs = RenderJob::query()
            ->whereIn('video_manual_id', $manualIds)
            ->where('kind', RenderKind::Render)
            ->whereIn('status', [JobStatus::Queued, JobStatus::Running])
            ->orderBy('id')
            ->get()
            ->keyBy('video_manual_id');

        return array_values($manuals->map(function (VideoManual $manual) use ($analysisJobs, $renderJobs): InProgressManualData {
            $job = $manual->status === VideoManualStatus::Analyzing
                ? $analysisJobs->get($manual->id)
                : $renderJobs->get($manual->id);

            // progress は外部状態由来のため 0-100 に clamp してから UI 契約に載せる
            $progress = $job?->progress;

            return new InProgressManualData(
                manualId: $manual->id,
                title: $manual->title,
                manualStatus: $manual->status,
                jobStatus: $job?->status,
                progress: $progress === null ? null : max(0, min(100, $progress)),
                jobUpdatedAt: $job?->updated_at?->format('Y-m-d H:i'),
            );
        })->all());
    }

    /** @return list<RecentManualData> */
    private function recentManuals(Project $project): array
    {
        return array_values($project->manuals()->with('category')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(function (VideoManual $manual): RecentManualData {
                // timestamps は DB 不変条件として非 null (欠損は '' で隠さず顕在化させる)
                Assert::notNull($manual->updated_at);

                return new RecentManualData(
                    id: $manual->id,
                    title: $manual->title,
                    status: $manual->status,
                    categoryName: $manual->category?->name,
                    updatedAt: $manual->updated_at->format('Y-m-d H:i'),
                );
            })->all());
    }

    /**
     * 撮影対象: ready/published かつ採用テイクなしの cut を持つ manual。
     * 採用判定は relation 経由 (adoptedTake) = CaptureManualController::index の既存規約踏襲。
     *
     * @return list<ShootingTargetData>
     */
    private function shootingTargets(Project $project): array
    {
        return array_values($project->manuals()
            ->whereIn('status', [VideoManualStatus::Ready, VideoManualStatus::Published])
            // 未採用 cut を持つ manual の絞り込み。whereHas + 閉包内 whereDoesntHave は
            // lv10 で TRelatedModel が解決できないため、標準の「relation subquery で
            // 親 id を絞る standalone クエリ」パターン (VideoManualService::delete と同型) を使う。
            // 外側が $project->manuals() のため構造的に project スコープは維持される
            ->whereIn('id', Cut::query()->whereDoesntHave('adoptedTake')->select('video_manual_id'))
            ->withCount([
                'cuts',
                'cuts as pending_cuts_count' => fn (Builder $query) => $query->whereDoesntHave('adoptedTake'),
            ])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(function (VideoManual $manual): ShootingTargetData {
                // withCount のエイリアス属性は魔法プロパティを避け getAttribute + 絞り込みで読む
                $cutsCount = $manual->getAttribute('cuts_count');
                $pendingCutsCount = $manual->getAttribute('pending_cuts_count');
                Assert::integerish($cutsCount);
                Assert::integerish($pendingCutsCount);

                return new ShootingTargetData(
                    manualId: $manual->id,
                    title: $manual->title,
                    cutsCount: (int) $cutsCount,
                    pendingCutsCount: (int) $pendingCutsCount,
                );
            })->all());
    }

    private function billingSummary(Organization $organization): BillingSummaryData
    {
        $balance = $this->tickets->balance($organization)->totalAvailable();
        $used = $this->storage->occupiedBytes($organization);
        $limit = $this->quota->limits($organization)[QuotaKey::MaxStorageBytes->value] ?? null;
        $percent = ($limit === null || $limit <= 0)
            ? null
            : (int) max(0, min(100, floor($used / $limit * 100)));

        return new BillingSummaryData(
            ticketBalance: $balance,
            isLowBalance: $balance < config()->integer('billing.ticket_low_balance_threshold'),
            storageUsedBytes: $used,
            storageLimitBytes: $limit,
            storageUsagePercent: $percent,
            // 真偽値へ潰さず state をそのまま渡す (画面が未契約と支払い不健全を区別するため)。
            // hasActiveAccess() は state()->grantsAccess() の 1 行なのでクエリ本数は変わらない。
            billingState: $this->billingAccess->state($organization),
        );
    }
}
