# 使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# 役割・タスク

あなたはシニア Laravel + Svelte 5 のコードレビュアー。以下は TODO T009「ダッシュボード(進行中ジョブ/最近のマニュアル/残高)」の実装差分(worktree `/workspace/.claude/worktrees/tasks/T009`、ブランチ `todo/T009`、main との diff)の**最終マージ前レビュー**である。

設計ドキュメント: `/workspace/devnotes/20260712-0029-dashboard/detailed-design.md`(必要なら読んでよい)。

観点:
1. **セキュリティ**: cross-org 読み出し漏れ、tenant キー不信違反、認可漏れ、N+1 でない org-scope の抜け
2. **正確性**: DashboardService の集計ロジック(進行中ジョブ/最近のマニュアル/残高)、状態遷移・edge case(組織なし/マニュアル 0 件/ジョブ失敗)
3. **規約準拠**: 上記禁止事項、DTO 経由の Inertia props、Svelte 5 runes、DS token 準拠
4. **テスト妥当性**: Feature/Unit/JS テストが不変条件を実際に固定しているか

判定は必ず以下の形式で出力:
- `## Critical` — マージをブロックすべき欠陥(なければ「なし」と明記)
- `## Warning` — 修正推奨だがブロックしない
- `## Suggestion` — 任意改善

各指摘には該当ファイル・行の根拠と、具体的な失敗シナリオを付けること。全 7 検証コマンド(composer test 1507 passed / phpstan 0 errors / pint / eslint / tsc / vitest 427 passed / build)は green 済み。

---

# 実装差分 (git diff main)

```diff
diff --git a/app/DataTransferObjects/Dashboard/BillingSummaryData.php b/app/DataTransferObjects/Dashboard/BillingSummaryData.php
new file mode 100644
index 0000000..6c085e3
--- /dev/null
+++ b/app/DataTransferObjects/Dashboard/BillingSummaryData.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Dashboard;
+
+/**
+ * チケット残高 + 容量 Quota (低残高警告と高使用率警告は別個のフラグ)。
+ * TS 側 types/dashboard.ts の BillingSummary と対で保守する。
+ */
+final readonly class BillingSummaryData
+{
+    public function __construct(
+        public int $ticketBalance,
+        public bool $isLowBalance,          // balance < billing.ticket_low_balance_threshold
+        public int $storageUsedBytes,       // StorageUsageService::occupiedBytes
+        public ?int $storageLimitBytes,     // QuotaService::limits[max_storage_bytes] (無制限は null)
+        public ?int $storageUsagePercent,   // 0-100 に clamp (limit null なら null)
+        public bool $hasActiveSubscription, // BillingAccess::hasActiveAccess
+    ) {}
+
+    /**
+     * @return array{ticket_balance: int, is_low_balance: bool, storage_used_bytes: int,
+     *   storage_limit_bytes: int|null, storage_usage_percent: int|null,
+     *   has_active_subscription: bool}
+     */
+    public function toArray(): array
+    {
+        return [
+            'ticket_balance' => $this->ticketBalance,
+            'is_low_balance' => $this->isLowBalance,
+            'storage_used_bytes' => $this->storageUsedBytes,
+            'storage_limit_bytes' => $this->storageLimitBytes,
+            'storage_usage_percent' => $this->storageUsagePercent,
+            'has_active_subscription' => $this->hasActiveSubscription,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Dashboard/DashboardPageData.php b/app/DataTransferObjects/Dashboard/DashboardPageData.php
new file mode 100644
index 0000000..50f43ee
--- /dev/null
+++ b/app/DataTransferObjects/Dashboard/DashboardPageData.php
@@ -0,0 +1,77 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Dashboard;
+
+use App\Enums\Dashboard\DashboardRole;
+use App\Enums\Dashboard\DashboardState;
+
+/**
+ * ダッシュボード props の頂点 DTO。state で 3 状態を明示:
+ * - no_organization: 所属組織 0 件 (organization/project/billing すべて null)
+ * - no_project: org はあるが project なし (billing のみ非 null)
+ * - ready: 通常表示
+ *
+ * TS 側 types/dashboard.ts の DashboardData と対で保守する。
+ */
+final readonly class DashboardPageData
+{
+    /**
+     * @param  list<InProgressManualData>  $inProgress
+     * @param  list<RecentManualData>  $recentManuals
+     * @param  list<ShootingTargetData>  $shootingTargets
+     */
+    public function __construct(
+        public DashboardState $state,
+        public ?DashboardRole $role,
+        public bool $canCreateProject,
+        public ?string $organizationName, // no_project の依頼先表示等 (org null のとき null)
+        public ?int $projectId,
+        public ?string $projectName,
+        public array $inProgress,
+        public array $recentManuals,
+        public array $shootingTargets,
+        public ?BillingSummaryData $billing,
+    ) {}
+
+    /**
+     * @return array{state: 'no_organization'|'no_project'|'ready', role: string|null,
+     *   can_create_project: bool, organization_name: string|null,
+     *   project: array{id: int, name: string}|null,
+     *   in_progress: list<array{manual_id: int, title: string, manual_status: string,
+     *     job_status: string|null, progress: int|null, job_updated_at: string|null}>,
+     *   recent_manuals: list<array{id: int, title: string, status: string,
+     *     category_name: string|null, updated_at: string}>,
+     *   shooting_targets: list<array{manual_id: int, title: string, cuts_count: int,
+     *     pending_cuts_count: int}>,
+     *   billing: array{ticket_balance: int, is_low_balance: bool, storage_used_bytes: int,
+     *     storage_limit_bytes: int|null, storage_usage_percent: int|null,
+     *     has_active_subscription: bool}|null}
+     */
+    public function toArray(): array
+    {
+        return [
+            'state' => $this->state->value,
+            'role' => $this->role?->value,
+            'can_create_project' => $this->canCreateProject,
+            'organization_name' => $this->organizationName,
+            'project' => ($this->projectId !== null && $this->projectName !== null)
+                ? ['id' => $this->projectId, 'name' => $this->projectName]
+                : null,
+            'in_progress' => array_map(
+                static fn (InProgressManualData $row): array => $row->toArray(),
+                $this->inProgress,
+            ),
+            'recent_manuals' => array_map(
+                static fn (RecentManualData $row): array => $row->toArray(),
+                $this->recentManuals,
+            ),
+            'shooting_targets' => array_map(
+                static fn (ShootingTargetData $row): array => $row->toArray(),
+                $this->shootingTargets,
+            ),
+            'billing' => $this->billing?->toArray(),
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Dashboard/InProgressManualData.php b/app/DataTransferObjects/Dashboard/InProgressManualData.php
new file mode 100644
index 0000000..17b2dd5
--- /dev/null
+++ b/app/DataTransferObjects/Dashboard/InProgressManualData.php
@@ -0,0 +1,40 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Dashboard;
+
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\VideoManualStatus;
+
+/**
+ * 進行中ジョブ 1 行 (analyzing/rendering の manual + 進行中 job のスナップショット)。
+ * TS 側 types/dashboard.ts の InProgressManual と対で保守する。
+ */
+final readonly class InProgressManualData
+{
+    public function __construct(
+        public int $manualId,
+        public string $title,
+        public VideoManualStatus $manualStatus,
+        public ?JobStatus $jobStatus,     // null = job 行が見つからない過渡状態 (表示は「準備中」)
+        public ?int $progress,
+        public ?string $jobUpdatedAt,     // 「最終更新」表示 (Y-m-d H:i)
+    ) {}
+
+    /**
+     * @return array{manual_id: int, title: string, manual_status: string,
+     *   job_status: string|null, progress: int|null, job_updated_at: string|null}
+     */
+    public function toArray(): array
+    {
+        return [
+            'manual_id' => $this->manualId,
+            'title' => $this->title,
+            'manual_status' => $this->manualStatus->value,
+            'job_status' => $this->jobStatus?->value,
+            'progress' => $this->progress,
+            'job_updated_at' => $this->jobUpdatedAt,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Dashboard/RecentManualData.php b/app/DataTransferObjects/Dashboard/RecentManualData.php
new file mode 100644
index 0000000..0047c6e
--- /dev/null
+++ b/app/DataTransferObjects/Dashboard/RecentManualData.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Dashboard;
+
+use App\Enums\Manual\VideoManualStatus;
+
+/**
+ * 最近のマニュアル 1 行。TS 側 types/dashboard.ts の RecentManual と対で保守する。
+ */
+final readonly class RecentManualData
+{
+    public function __construct(
+        public int $id,
+        public string $title,
+        public VideoManualStatus $status,
+        public ?string $categoryName,
+        public string $updatedAt,
+    ) {}
+
+    /**
+     * @return array{id: int, title: string, status: string,
+     *   category_name: string|null, updated_at: string}
+     */
+    public function toArray(): array
+    {
+        return [
+            'id' => $this->id,
+            'title' => $this->title,
+            'status' => $this->status->value,
+            'category_name' => $this->categoryName,
+            'updated_at' => $this->updatedAt,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Dashboard/ShootingTargetData.php b/app/DataTransferObjects/Dashboard/ShootingTargetData.php
new file mode 100644
index 0000000..217f02b
--- /dev/null
+++ b/app/DataTransferObjects/Dashboard/ShootingTargetData.php
@@ -0,0 +1,32 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Dashboard;
+
+/**
+ * 撮影対象 1 行 (採用待ち cut がある ready/published manual)。
+ * TS 側 types/dashboard.ts の ShootingTarget と対で保守する。
+ */
+final readonly class ShootingTargetData
+{
+    public function __construct(
+        public int $manualId,
+        public string $title,
+        public int $cutsCount,
+        public int $pendingCutsCount, // 採用テイクなしの cut 数
+    ) {}
+
+    /**
+     * @return array{manual_id: int, title: string, cuts_count: int, pending_cuts_count: int}
+     */
+    public function toArray(): array
+    {
+        return [
+            'manual_id' => $this->manualId,
+            'title' => $this->title,
+            'cuts_count' => $this->cutsCount,
+            'pending_cuts_count' => $this->pendingCutsCount,
+        ];
+    }
+}
diff --git a/app/Enums/Dashboard/DashboardRole.php b/app/Enums/Dashboard/DashboardRole.php
new file mode 100644
index 0000000..1c984fc
--- /dev/null
+++ b/app/Enums/Dashboard/DashboardRole.php
@@ -0,0 +1,17 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Dashboard;
+
+/**
+ * ダッシュボード表示ロール (概念設計「ロール差」)。判定はサーバ側で
+ * ProjectPolicy へ委譲した結果の写像 (フロントは表示分岐のみ、権限判定を持たない)。
+ * TS 側 types/dashboard.ts の DashboardRole literal union と対で保守する。
+ */
+enum DashboardRole: string
+{
+    case Editor = 'editor';   // ProjectPolicy::update 可 (org owner/admin または project_admin)
+    case Shooter = 'shooter'; // update 不可 + ProjectPolicy::capture 可 (project_member)
+    case Viewer = 'viewer';   // どちらも不可の組織メンバー
+}
diff --git a/app/Enums/Dashboard/DashboardState.php b/app/Enums/Dashboard/DashboardState.php
new file mode 100644
index 0000000..8b6ae67
--- /dev/null
+++ b/app/Enums/Dashboard/DashboardState.php
@@ -0,0 +1,16 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Dashboard;
+
+/**
+ * ダッシュボードの表示状態 (TS 側 DashboardState literal union と対)。
+ * ログイン直後の着地点はどの状態でも 404 / redirect ループにしない (概念設計 状態分岐)。
+ */
+enum DashboardState: string
+{
+    case NoOrganization = 'no_organization';
+    case NoProject = 'no_project';
+    case Ready = 'ready';
+}
diff --git a/app/Http/Controllers/DashboardController.php b/app/Http/Controllers/DashboardController.php
new file mode 100644
index 0000000..fa82350
--- /dev/null
+++ b/app/Http/Controllers/DashboardController.php
@@ -0,0 +1,47 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers;
+
+use App\Models\User;
+use App\Services\Dashboard\DashboardService;
+use App\Services\Organization\CurrentOrganizationResolver;
+use Illuminate\Http\Request;
+use Illuminate\Support\Facades\Gate;
+use Inertia\Inertia;
+use Inertia\Response;
+use Webmozart\Assert\Assert;
+
+/**
+ * ダッシュボード (ログイン直後の着地点。概念設計 20260712-0029)。
+ *
+ * - ResolvesCurrentOrganization は使わない (current org なしを 404 にせず setup 表示に倒す)
+ * - 表示組織は CurrentOrganizationResolver (所属再確認つき + 自己修復) で解決
+ * - 課金ゲート外 (未契約でも状況把握と復帰導線を提供。CTA は billing.index /
+ *   billing.tickets.show = どちらも課金ゲート外 route に固定)
+ * - route param なし・payload なし = NestedRouteIdorDefenseTest inventory 対象外
+ */
+final class DashboardController extends Controller
+{
+    public function __invoke(
+        Request $request,
+        CurrentOrganizationResolver $organizations,
+        DashboardService $dashboard,
+    ): Response {
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        $organization = $organizations->resolve($user);
+        if ($organization !== null) {
+            // 役割分担: resolver = 所属整合 (membership の構造的確認)、Policy = 最終認可。
+            // 現状 OrganizationPolicy::view は所属と同値だが、Policy が将来厳格化しても
+            // ここが最終判定である (resolver 側の所属確認を認可とみなさない)
+            Gate::authorize('view', $organization);
+        }
+
+        return Inertia::render('Dashboard', [
+            'dashboard' => $dashboard->build($user, $organization)->toArray(),
+        ]);
+    }
+}
diff --git a/app/Services/Dashboard/DashboardService.php b/app/Services/Dashboard/DashboardService.php
new file mode 100644
index 0000000..d13d1eb
--- /dev/null
+++ b/app/Services/Dashboard/DashboardService.php
@@ -0,0 +1,232 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Dashboard;
+
+use App\DataTransferObjects\Dashboard\BillingSummaryData;
+use App\DataTransferObjects\Dashboard\DashboardPageData;
+use App\DataTransferObjects\Dashboard\InProgressManualData;
+use App\DataTransferObjects\Dashboard\RecentManualData;
+use App\DataTransferObjects\Dashboard\ShootingTargetData;
+use App\Enums\Dashboard\DashboardRole;
+use App\Enums\Dashboard\DashboardState;
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\RenderKind;
+use App\Enums\Manual\VideoManualStatus;
+use App\Enums\QuotaKey;
+use App\Models\AnalysisJob;
+use App\Models\Cut;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Billing\BillingAccess;
+use App\Services\Billing\QuotaService;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Capture\StorageUsageService;
+use App\Services\Project\DefaultProjectResolver;
+use Illuminate\Contracts\Database\Eloquent\Builder;
+use Illuminate\Support\Collection;
+use Webmozart\Assert\Assert;
+
+/**
+ * ダッシュボードのサーバ集計 (読み取り専用。固定本数のクエリで N+1 なし)。
+ * 集計対象はすべて $organization / $project の relation 経由 = cross-org 構造的不可。
+ * $organization は CurrentOrganizationResolver が所属再確認済みのものだけが渡される契約。
+ */
+class DashboardService
+{
+    private const int LIST_LIMIT = 5;
+
+    public function __construct(
+        private readonly DefaultProjectResolver $defaultProjects,
+        private readonly TicketLedgerService $tickets,
+        private readonly QuotaService $quota,
+        private readonly StorageUsageService $storage,
+        private readonly BillingAccess $billingAccess,
+    ) {}
+
+    public function build(User $user, ?Organization $organization): DashboardPageData
+    {
+        if ($organization === null) {
+            return new DashboardPageData(
+                state: DashboardState::NoOrganization, role: null, canCreateProject: false,
+                organizationName: null, projectId: null, projectName: null,
+                inProgress: [], recentManuals: [], shootingTargets: [], billing: null,
+            );
+        }
+
+        $billing = $this->billingSummary($organization);
+        $project = $this->defaultProjects->resolve($organization);
+        if ($project === null) {
+            return new DashboardPageData(
+                state: DashboardState::NoProject, role: null,
+                canCreateProject: $user->can('create', [Project::class, $organization]),
+                organizationName: $organization->name, projectId: null, projectName: null,
+                inProgress: [], recentManuals: [], shootingTargets: [], billing: $billing,
+            );
+        }
+
+        $role = $this->resolveRole($user, $project);
+
+        return new DashboardPageData(
+            state: DashboardState::Ready, role: $role, canCreateProject: false,
+            organizationName: $organization->name,
+            projectId: $project->id, projectName: $project->name,
+            inProgress: $this->inProgress($project),
+            recentManuals: $this->recentManuals($project),
+            shootingTargets: $this->shootingTargets($project),
+            billing: $billing,
+        );
+    }
+
+    /** ProjectPolicy へ委譲した結果の写像 (laratrust_team_id 明示判定は Policy 内) */
+    private function resolveRole(User $user, Project $project): DashboardRole
+    {
+        if ($user->can('update', $project)) {
+            return DashboardRole::Editor;
+        }
+        if ($user->can('capture', $project)) {
+            return DashboardRole::Shooter;
+        }
+
+        return DashboardRole::Viewer;
+    }
+
+    /**
+     * 進行中ジョブ: analyzing/rendering の manual + 進行中 job (queued/running)。
+     * job の引き当ては「relation subquery で manual id を絞った standalone クエリ」
+     * (VideoManualService::delete の既存パターンと同型 = 構造的に project スコープ)。
+     * 3 クエリ固定。in-flight は manual×操作種別あたり 1 本の既存不変条件 (doc/10 §10.8-8)
+     * があり、keyBy はその防御的な決定化 (orderBy('id') 昇順 + keyBy 後勝ちで
+     * 万一の複数行も最新 1 本に確定)。
+     *
+     * @return list<InProgressManualData>
+     */
+    private function inProgress(Project $project): array
+    {
+        $manuals = $project->manuals()
+            ->whereIn('status', [VideoManualStatus::Analyzing, VideoManualStatus::Rendering])
+            ->orderByDesc('updated_at')->orderByDesc('id')
+            ->limit(self::LIST_LIMIT)
+            ->get();
+
+        /** @var list<int> $manualIds */
+        $manualIds = $manuals->pluck('id')->all();
+
+        // keyBy('video_manual_id') + orderBy('id') 昇順取得 → 最終要素が最新 (keyBy は後勝ち)
+        /** @var Collection<int, AnalysisJob> $analysisJobs */
+        $analysisJobs = AnalysisJob::query()
+            ->whereIn('video_manual_id', $manualIds)
+            ->whereIn('status', [JobStatus::Queued, JobStatus::Running])
+            ->orderBy('id')
+            ->get()
+            ->keyBy('video_manual_id');
+        /** @var Collection<int, RenderJob> $renderJobs */
+        $renderJobs = RenderJob::query()
+            ->whereIn('video_manual_id', $manualIds)
+            ->where('kind', RenderKind::Render)
+            ->whereIn('status', [JobStatus::Queued, JobStatus::Running])
+            ->orderBy('id')
+            ->get()
+            ->keyBy('video_manual_id');
+
+        return array_values($manuals->map(function (VideoManual $manual) use ($analysisJobs, $renderJobs): InProgressManualData {
+            $job = $manual->status === VideoManualStatus::Analyzing
+                ? $analysisJobs->get($manual->id)
+                : $renderJobs->get($manual->id);
+
+            // progress は外部状態由来のため 0-100 に clamp してから UI 契約に載せる
+            $progress = $job?->progress;
+
+            return new InProgressManualData(
+                manualId: $manual->id,
+                title: $manual->title,
+                manualStatus: $manual->status,
+                jobStatus: $job?->status,
+                progress: $progress === null ? null : max(0, min(100, $progress)),
+                jobUpdatedAt: $job?->updated_at?->format('Y-m-d H:i'),
+            );
+        })->all());
+    }
+
+    /** @return list<RecentManualData> */
+    private function recentManuals(Project $project): array
+    {
+        return array_values($project->manuals()->with('category')
+            ->orderByDesc('updated_at')->orderByDesc('id')
+            ->limit(self::LIST_LIMIT)
+            ->get()
+            ->map(function (VideoManual $manual): RecentManualData {
+                // timestamps は DB 不変条件として非 null (欠損は '' で隠さず顕在化させる)
+                Assert::notNull($manual->updated_at);
+
+                return new RecentManualData(
+                    id: $manual->id,
+                    title: $manual->title,
+                    status: $manual->status,
+                    categoryName: $manual->category?->name,
+                    updatedAt: $manual->updated_at->format('Y-m-d H:i'),
+                );
+            })->all());
+    }
+
+    /**
+     * 撮影対象: ready/published かつ採用テイクなしの cut を持つ manual。
+     * 採用判定は relation 経由 (adoptedTake) = CaptureManualController::index の既存規約踏襲。
+     *
+     * @return list<ShootingTargetData>
+     */
+    private function shootingTargets(Project $project): array
+    {
+        return array_values($project->manuals()
+            ->whereIn('status', [VideoManualStatus::Ready, VideoManualStatus::Published])
+            // 未採用 cut を持つ manual の絞り込み。whereHas + 閉包内 whereDoesntHave は
+            // lv10 で TRelatedModel が解決できないため、標準の「relation subquery で
+            // 親 id を絞る standalone クエリ」パターン (VideoManualService::delete と同型) を使う。
+            // 外側が $project->manuals() のため構造的に project スコープは維持される
+            ->whereIn('id', Cut::query()->whereDoesntHave('adoptedTake')->select('video_manual_id'))
+            ->withCount([
+                'cuts',
+                'cuts as pending_cuts_count' => fn (Builder $query) => $query->whereDoesntHave('adoptedTake'),
+            ])
+            ->orderByDesc('updated_at')->orderByDesc('id')
+            ->limit(self::LIST_LIMIT)
+            ->get()
+            ->map(function (VideoManual $manual): ShootingTargetData {
+                // withCount のエイリアス属性は魔法プロパティを避け getAttribute + 絞り込みで読む
+                $cutsCount = $manual->getAttribute('cuts_count');
+                $pendingCutsCount = $manual->getAttribute('pending_cuts_count');
+                Assert::integerish($cutsCount);
+                Assert::integerish($pendingCutsCount);
+
+                return new ShootingTargetData(
+                    manualId: $manual->id,
+                    title: $manual->title,
+                    cutsCount: (int) $cutsCount,
+                    pendingCutsCount: (int) $pendingCutsCount,
+                );
+            })->all());
+    }
+
+    private function billingSummary(Organization $organization): BillingSummaryData
+    {
+        $balance = $this->tickets->balance($organization);
+        $used = $this->storage->occupiedBytes($organization);
+        $limit = $this->quota->limits($organization)[QuotaKey::MaxStorageBytes->value] ?? null;
+        $percent = ($limit === null || $limit <= 0)
+            ? null
+            : (int) min(100, floor($used / $limit * 100));
+
+        return new BillingSummaryData(
+            ticketBalance: $balance,
+            isLowBalance: $balance < config()->integer('billing.ticket_low_balance_threshold'),
+            storageUsedBytes: $used,
+            storageLimitBytes: $limit,
+            storageUsagePercent: $percent,
+            hasActiveSubscription: $this->billingAccess->hasActiveAccess($organization),
+        );
+    }
+}
diff --git a/app/Services/Organization/CurrentOrganizationResolver.php b/app/Services/Organization/CurrentOrganizationResolver.php
new file mode 100644
index 0000000..2570ad9
--- /dev/null
+++ b/app/Services/Organization/CurrentOrganizationResolver.php
@@ -0,0 +1,100 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Organization;
+
+use App\Models\Organization;
+use App\Models\User;
+use Illuminate\Contracts\Database\Eloquent\Builder;
+use Illuminate\Support\Facades\Log;
+use Webmozart\Assert\Assert;
+
+/**
+ * current organization の「所属再確認つき」解決 + 自己修復 (概念設計 表示組織の解決規則)。
+ *
+ * removeMember は current org からの除名時に current_organization_id を null 化するが
+ * 「選び直す」実装は本 Service が初出。v1 の呼び出し元は DashboardController のみ
+ * (他画面への展開は後続。ResolvesCurrentOrganization trait は従来どおり null=404)。
+ *
+ * 競合契約 (概念レビュー Round 2-4 で確定):
+ * - 表示の安全性は「読み出し時の所属再確認」で担保する。current が指す org は常に
+ *   pivot relation で所属を再確認してから返す = 非所属 org (dangling) を描画に出さない
+ * - 書き込みは best-effort の冪等修復。単一の条件付き UPDATE
+ *   (current IS NULL または観測した dangling 値のまま、かつ所属 pivot が存続) のみ
+ * - UPDATE 成否によらず fresh 再取得 → 所属再確認 1 回のみ。解決不能なら null (無限再試行しない)
+ */
+class CurrentOrganizationResolver
+{
+    /** 表示組織を解決する。null = 所属組織 0 件 (または競合で解決不能) */
+    public function resolve(User $user): ?Organization
+    {
+        // 1. current の所属再確認つき読み出し (dangling は null 扱いに倒す)
+        $current = $this->membershipVerified($user, $user->current_organization_id);
+        if ($current !== null) {
+            return $current;
+        }
+
+        // 2. 自己修復: 決定的候補 (organizations.id 昇順の先頭)
+        $observed = $user->current_organization_id; // null または dangling 値
+        $candidateId = $user->organizations()->orderBy('organizations.id')->value('organizations.id');
+        if ($candidateId === null) {
+            return null; // 所属 0 件 → setup 表示
+        }
+        Assert::integerish($candidateId);
+
+        $this->heal($user, $observed, (int) $candidateId);
+
+        // 3. 成否によらず relation キャッシュ破棄 + fresh 再取得 → 所属再確認 (1 回のみ)
+        $user->refresh();
+
+        return $this->membershipVerified($user, $user->current_organization_id);
+    }
+
+    /**
+     * 原子的条件付き UPDATE による自己修復 (内部 API。テストが競合分岐を直接固定できる seam)。
+     * 観測値のまま + 所属存続のときのみ設定:
+     * - 除名 tx が先に commit していれば whereHas (EXISTS) が偽 → 0 件更新 = 修復しない
+     * - 観測後に別 org へ変更済みなら WHERE 不一致 → 上書きしない
+     *
+     * current_organization_id は保護キーだが、この UPDATE は fillable を経由しない
+     * サーバ導出のみの書き込み (payload 値は一切使わない)。
+     *
+     * @return int 更新行数 (0 = 競合により不発。正常系の一種)
+     */
+    public function heal(User $user, ?int $observed, int $candidateId): int
+    {
+        $updated = User::query()
+            ->whereKey($user->getKey())
+            ->where(function (Builder $query) use ($observed): void {
+                $query->whereNull('current_organization_id');
+                if ($observed !== null) {
+                    $query->orWhere('current_organization_id', $observed);
+                }
+            })
+            ->whereHas('organizations', fn (Builder $query) => $query->whereKey($candidateId))
+            ->update(['current_organization_id' => $candidateId]);
+
+        // 監査ログ (GET 内の自己修復を追跡可能にする)。更新 0 件は正常な競合のため
+        // debug に落としログ量を抑える (詳細レビュー Round 2 対応)
+        Log::log($updated > 0 ? 'info' : 'debug', 'current organization self-heal', [
+            'user_id' => $user->getKey(),
+            'observed' => $observed,
+            'candidate' => $candidateId,
+            'updated_rows' => $updated,
+        ]);
+
+        return $updated;
+    }
+
+    /** 所属再確認つき読み出し (pivot relation 経由 = cross-org を構造的に排除) */
+    private function membershipVerified(User $user, ?int $organizationId): ?Organization
+    {
+        if ($organizationId === null) {
+            return null;
+        }
+
+        /** @var Organization|null */
+        return $user->organizations()->whereKey($organizationId)->first();
+    }
+}
diff --git a/resources/js/pages/Dashboard.svelte b/resources/js/pages/Dashboard.svelte
index ab0142a..f46024c 100644
--- a/resources/js/pages/Dashboard.svelte
+++ b/resources/js/pages/Dashboard.svelte
@@ -1,16 +1,34 @@
 <script lang="ts">
     import { page, router } from "@inertiajs/svelte";
-    import { Inbox } from "@lucide/svelte";
+    import { Bell, Building, Camera, FolderPlus, HardDrive, Loader, Ticket } from "@lucide/svelte";
+    import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
     import TextLink from "@/components/atoms/TextLink.svelte";
     import EmptyState from "@/components/molecules/EmptyState.svelte";
+    import StatCard from "@/components/molecules/StatCard.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import type { SharedProps } from "@/lib/shared-props";
+    import type { DashboardProps } from "@/types/dashboard";
+    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
+
+    /**
+     * ダッシュボード (ログイン直後の着地点)。PHP: DashboardController / DashboardPageData と対。
+     * state (no_organization / no_project / ready) とロール (editor / shooter / viewer) で
+     * 表示を分岐する。権限がない導線は非描画 (disabled ボタンは一切作らない)。
+     */
+    let { dashboard }: DashboardProps = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const user = $derived(shared.auth?.user ?? null);
     const appName = $derived(shared.appName ?? "");
+    // 未読数は shared props (T008 ベルと同源。サーバ二重集計なし)
+    const unreadCount = $derived(shared.notifications?.unreadCount ?? 0);
+
+    const billing = $derived(dashboard.billing);
+    const project = $derived(dashboard.project);
+    const isEditor = $derived(dashboard.role === "editor");
+    const isShooter = $derived(dashboard.role === "shooter");
 
     let loggingOut = $state(false);
 
@@ -28,8 +46,111 @@
             },
         );
     }
+
+    /** バイト数の可読表記 (残容量タイルの subtext 用) */
+    function formatBytes(bytes: number): string {
+        if (bytes >= 1024 ** 3) return `${(bytes / 1024 ** 3).toFixed(1)} GB`;
+        if (bytes >= 1024 ** 2) return `${(bytes / 1024 ** 2).toFixed(1)} MB`;
+        if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)} KB`;
+        return `${bytes} B`;
+    }
 </script>
 
+{#snippet shootingCard()}
+    <Card class="mt-6" testId="shooting-card">
+        <h2 class="text-h3 text-text">撮影対象</h2>
+        {#if dashboard.shooting_targets.length === 0}
+            <p class="mt-3 text-body text-text-secondary" data-testid="shooting-empty">
+                撮影対象はまだありません。
+            </p>
+        {:else}
+            <ul class="mt-3 divide-y divide-border">
+                {#each dashboard.shooting_targets as target (target.manual_id)}
+                    <li
+                        class="flex flex-wrap items-center justify-between gap-3 py-3"
+                        data-testid="shooting-item"
+                    >
+                        <div class="min-w-0">
+                            <p class="truncate text-body text-text">{target.title}</p>
+                            <p class="mt-1 text-caption text-text-secondary">
+                                残り {target.pending_cuts_count}/{target.cuts_count} カット
+                            </p>
+                        </div>
+                        {#if project}
+                            <Button
+                                size="sm"
+                                href={`/app/projects/${project.id}/manuals/${target.manual_id}`}
+                                inertia
+                                testId="shoot-button"
+                            >
+                                撮影する
+                            </Button>
+                        {/if}
+                    </li>
+                {/each}
+            </ul>
+        {/if}
+    </Card>
+{/snippet}
+
+{#snippet recentCard()}
+    <Card class="mt-6" testId="recent-card">
+        <h2 class="text-h3 text-text">最近のマニュアル</h2>
+        {#if dashboard.recent_manuals.length === 0}
+            {#if isEditor && project}
+                <EmptyState
+                    description="最初のマニュアルを作成して、AI にシナリオを設計させましょう。"
+                    cta={{
+                        kind: "link",
+                        label: "最初のマニュアルを作成",
+                        href: `/projects/${project.id}/manuals/create`,
+                    }}
+                    testId="recent-empty"
+                />
+            {:else}
+                <p class="mt-3 text-body text-text-secondary" data-testid="recent-empty">
+                    マニュアルはまだありません。編集者が作成すると、ここに表示されます。
+                </p>
+            {/if}
+        {:else}
+            <ul class="mt-3 divide-y divide-border">
+                {#each dashboard.recent_manuals as manual (manual.id)}
+                    <li
+                        class="flex flex-wrap items-center justify-between gap-3 py-3"
+                        data-testid="recent-item"
+                    >
+                        <div class="min-w-0">
+                            {#if project}
+                                <TextLink href={`/projects/${project.id}/manuals/${manual.id}`}>
+                                    {manual.title}
+                                </TextLink>
+                            {:else}
+                                <p class="truncate text-body text-text">{manual.title}</p>
+                            {/if}
+                            <p class="mt-1 text-caption text-text-secondary">
+                                {manual.category_name ?? "未分類"} ・ {manual.updated_at}
+                            </p>
+                        </div>
+                        <div class="flex shrink-0 items-center gap-3">
+                            <Badge tone={STATUS_TONES[manual.status]}>
+                                {VIDEO_MANUAL_STATUS_LABELS[manual.status]}
+                            </Badge>
+                            {#if isEditor && project}
+                                <TextLink
+                                    href={`/projects/${project.id}/manuals/${manual.id}/edit`}
+                                    testId="recent-edit-link"
+                                >
+                                    編集
+                                </TextLink>
+                            {/if}
+                        </div>
+                    </li>
+                {/each}
+            </ul>
+        {/if}
+    </Card>
+{/snippet}
+
 <AppLayout {appName}>
     {#snippet headerActions()}
         <TextLink href="/settings">設定</TextLink>
@@ -41,12 +162,182 @@
     <h1 class="text-h2">{user?.name ?? ""} さん、ようこそ</h1>
     <p class="mt-1 text-caption text-text-secondary">今日のアクティビティを確認しましょう。</p>
 
-    <Card padding="none" class="mt-6">
-        <EmptyState
-            title="まだコンテンツがありません"
-            description="このテンプレートにアプリ固有の機能を追加すると、ここに表示されます。"
-            icon={Inbox}
-            testId="dashboard-empty"
-        />
-    </Card>
+    {#if dashboard.state === "no_organization"}
+        <Card padding="none" class="mt-6">
+            <EmptyState
+                title="まずは組織を作成しましょう"
+                description="組織を作成すると、プロジェクトとマニュアルの管理を始められます。"
+                icon={Building}
+                cta={{ kind: "link", label: "組織を作成", href: "/organizations/create" }}
+                testId="dashboard-setup-org"
+            />
+        </Card>
+    {:else}
+        <!-- スタットタイル (org があれば billing は非 null) -->
+        {#if billing}
+            <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
+                <div>
+                    <StatCard
+                        label="チケット残高"
+                        value={billing.ticket_balance}
+                        subtext={billing.is_low_balance ? "残高が少なくなっています" : undefined}
+                        icon={Ticket}
+                        testId="stat-tickets"
+                    />
+                    {#if billing.is_low_balance}
+                        <p class="mt-2 text-caption">
+                            <TextLink href="/purchase-tickets" testId="purchase-link">
+                                チケットを購入
+                            </TextLink>
+                        </p>
+                    {/if}
+                </div>
+                <StatCard
+                    label="容量使用率"
+                    value={billing.storage_usage_percent === null
+                        ? "無制限"
+                        : `${billing.storage_usage_percent}%`}
+                    subtext={billing.storage_limit_bytes === null
+                        ? `${formatBytes(billing.storage_used_bytes)} 使用中`
+                        : `${formatBytes(billing.storage_used_bytes)} / ${formatBytes(billing.storage_limit_bytes)}`}
+                    icon={HardDrive}
+                    testId="stat-storage"
+                />
+                <div>
+                    <StatCard label="未読通知" value={unreadCount} icon={Bell} testId="stat-unread" />
+                    <p class="mt-2 text-caption">
+                        <TextLink href="/notifications">通知を確認</TextLink>
+                    </p>
+                </div>
+                <StatCard
+                    label="進行中ジョブ"
+                    value={dashboard.in_progress.length}
+                    icon={Loader}
+                    testId="stat-inprogress"
+                />
+            </div>
+
+            {#if !billing.has_active_subscription}
+                <Card class="mt-6" testId="billing-callout">
+                    <p class="text-body text-text">
+                        有効なサブスクリプションがありません。プランを契約すると、マニュアルの作成・撮影を再開できます。
+                    </p>
+                    <div class="mt-4">
+                        <Button href="/billing" inertia>プランを見る</Button>
+                    </div>
+                </Card>
+            {/if}
+        {/if}
+
+        {#if dashboard.state === "no_project"}
+            <Card padding="none" class="mt-6">
+                {#if dashboard.can_create_project}
+                    <EmptyState
+                        title="プロジェクトを作成しましょう"
+                        description="プロジェクトを作成すると、マニュアルの管理を始められます。"
+                        icon={FolderPlus}
+                        cta={{ kind: "link", label: "プロジェクトを作成", href: "/projects/create" }}
+                        testId="dashboard-setup-project"
+                    />
+                {:else}
+                    <EmptyState
+                        title="プロジェクトがまだありません"
+                        description={`「${dashboard.organization_name ?? ""}」の管理者にプロジェクト作成を依頼してください。`}
+                        icon={FolderPlus}
+                        testId="no-project-guidance"
+                    />
+                {/if}
+            </Card>
+        {:else if dashboard.state === "ready"}
+            {#if dashboard.in_progress.length > 0}
+                <Card class="mt-6" testId="inprogress-card">
+                    <h2 class="text-h3 text-text">進行中ジョブ</h2>
+                    <ul class="mt-3 divide-y divide-border">
+                        {#each dashboard.in_progress as row (row.manual_id)}
+                            <li class="py-3" data-testid="inprogress-item">
+                                <div class="flex flex-wrap items-center justify-between gap-3">
+                                    <p class="min-w-0 truncate text-body text-text">{row.title}</p>
+                                    <Badge tone={STATUS_TONES[row.manual_status]}>
+                                        {VIDEO_MANUAL_STATUS_LABELS[row.manual_status]}
+                                    </Badge>
+                                </div>
+                                {#if row.job_status !== null && row.progress !== null}
+                                    <div
+                                        class="mt-2 h-2 w-full overflow-hidden rounded-sm bg-neutral"
+                                        role="progressbar"
+                                        aria-valuenow={row.progress}
+                                        aria-valuemin={0}
+                                        aria-valuemax={100}
+                                        data-testid="inprogress-bar"
+                                    >
+                                        <div
+                                            class="h-full bg-primary"
+                                            style="width: {row.progress}%"
+                                        ></div>
+                                    </div>
+                                {:else}
+                                    <p class="mt-2 text-caption text-text-secondary">準備中</p>
+                                {/if}
+                                <div
+                                    class="mt-2 flex flex-wrap items-center justify-between gap-2 text-caption text-text-secondary"
+                                >
+                                    <span>
+                                        {row.job_updated_at !== null
+                                            ? `最終更新 ${row.job_updated_at}`
+                                            : ""}
+                                    </span>
+                                    {#if project}
+                                        <TextLink
+                                            href={`/projects/${project.id}/manuals/${row.manual_id}`}
+                                            testId="inprogress-detail-link"
+                                        >
+                                            詳細で最新の進捗を確認
+                                        </TextLink>
+                                    {/if}
+                                </div>
+                            </li>
+                        {/each}
+                    </ul>
+                </Card>
+            {/if}
+
+            {#if isShooter}
+                <!-- 撮影者は撮影対象を先頭に -->
+                {@render shootingCard()}
+                {@render recentCard()}
+            {:else}
+                {@render recentCard()}
+                {@render shootingCard()}
+            {/if}
+
+            {#if project && (isEditor || isShooter)}
+                <Card class="mt-6" testId="quick-actions">
+                    <h2 class="text-h3 text-text">クイックアクション</h2>
+                    <div class="mt-3 flex flex-wrap gap-3">
+                        {#if isEditor}
+                            <Button
+                                href={`/projects/${project.id}/manuals/create`}
+                                inertia
+                                testId="qa-create-manual"
+                            >
+                                新規マニュアル作成
+                            </Button>
+                            <Button
+                                variant="neutral"
+                                href={`/projects/${project.id}/categories`}
+                                inertia
+                                testId="qa-categories"
+                            >
+                                カテゴリ管理
+                            </Button>
+                        {/if}
+                        <Button variant="neutral" href="/app" inertia testId="qa-capture">
+                            <Camera class="size-4" aria-hidden="true" />
+                            撮影アプリを開く
+                        </Button>
+                    </div>
+                </Card>
+            {/if}
+        {/if}
+    {/if}
 </AppLayout>
diff --git a/resources/js/pages/Manuals/Show.svelte b/resources/js/pages/Manuals/Show.svelte
index 700e947..fac458f 100644
--- a/resources/js/pages/Manuals/Show.svelte
+++ b/resources/js/pages/Manuals/Show.svelte
@@ -12,7 +12,7 @@
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import type { SharedProps } from "@/lib/shared-props";
     import type { AnalysisProps, RenderProps, VideoManualStatus } from "@/types/manual";
-    import { VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
+    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
 
     /**
      * 動画マニュアル詳細 (メタデータ + AI 解析パネル)。撮影者も閲覧可
@@ -37,17 +37,6 @@
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
 
-    const STATUS_TONES: Record<
-        VideoManualStatus,
-        "primary" | "tertiary" | "success" | "warning" | "neutral"
-    > = {
-        draft: "neutral",
-        analyzing: "tertiary",
-        ready: "success",
-        rendering: "warning",
-        published: "primary",
-    };
-
     /* ---- 削除 ---- */
     let deleteDialogOpen = $state(false);
     let deleting = $state(false);
diff --git a/resources/js/pages/Projects/Show.svelte b/resources/js/pages/Projects/Show.svelte
index 9fe9e90..735dcad 100644
--- a/resources/js/pages/Projects/Show.svelte
+++ b/resources/js/pages/Projects/Show.svelte
@@ -20,9 +20,8 @@
         ManualFilters,
         ManualListItem,
         PaginationMeta,
-        VideoManualStatus,
     } from "@/types/manual";
-    import { VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
+    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
 
     /**
      * プロジェクト詳細。動画マニュアル一覧 (フィルタ + paginate)・カテゴリ管理・
@@ -51,18 +50,6 @@
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
 
-    /* ---- 動画マニュアル: 状態バッジの tone (結果表示の意味色) ---- */
-    const STATUS_TONES: Record<
-        VideoManualStatus,
-        "primary" | "tertiary" | "success" | "warning" | "neutral"
-    > = {
-        draft: "neutral",
-        analyzing: "tertiary",
-        ready: "success",
-        rendering: "warning",
-        published: "primary",
-    };
-
     /* ---- 動画マニュアル: フィルタ (GET クエリで manuals のみ部分更新) ---- */
     let filterCategory = $state(manualFilters.category ?? "");
     let filterStatus = $state(manualFilters.status ?? "");
diff --git a/resources/js/types/dashboard.ts b/resources/js/types/dashboard.ts
new file mode 100644
index 0000000..95d7f92
--- /dev/null
+++ b/resources/js/types/dashboard.ts
@@ -0,0 +1,59 @@
+/**
+ * ダッシュボードの Inertia props 型。
+ * PHP 側 App\DataTransferObjects\Dashboard\DashboardPageData::toArray() と対で保守する。
+ */
+import type { VideoManualStatus } from "@/types/manual";
+
+export type DashboardState = "no_organization" | "no_project" | "ready";
+export type DashboardRole = "editor" | "shooter" | "viewer";
+export type DashboardJobStatus = "queued" | "running"; // 進行中のみ (terminal は出ない)
+
+export interface InProgressManual {
+    manual_id: number;
+    title: string;
+    manual_status: Extract<VideoManualStatus, "analyzing" | "rendering">;
+    job_status: DashboardJobStatus | null; // null = 過渡状態 (「準備中」表示)
+    progress: number | null;
+    job_updated_at: string | null;
+}
+
+export interface RecentManual {
+    id: number;
+    title: string;
+    status: VideoManualStatus;
+    category_name: string | null;
+    updated_at: string;
+}
+
+export interface ShootingTarget {
+    manual_id: number;
+    title: string;
+    cuts_count: number;
+    pending_cuts_count: number;
+}
+
+export interface BillingSummary {
+    ticket_balance: number;
+    is_low_balance: boolean;
+    storage_used_bytes: number;
+    storage_limit_bytes: number | null;
+    storage_usage_percent: number | null;
+    has_active_subscription: boolean;
+}
+
+export interface DashboardData {
+    state: DashboardState;
+    role: DashboardRole | null;
+    can_create_project: boolean;
+    organization_name: string | null;
+    project: { id: number; name: string } | null;
+    in_progress: InProgressManual[];
+    recent_manuals: RecentManual[];
+    shooting_targets: ShootingTarget[];
+    billing: BillingSummary | null;
+}
+
+/** ページ props (Inertia)。共有 props は SharedProps を合成して参照する (契約 1 本化) */
+export interface DashboardProps {
+    dashboard: DashboardData;
+}
diff --git a/resources/js/types/manual.ts b/resources/js/types/manual.ts
index 5264085..94a2091 100644
--- a/resources/js/types/manual.ts
+++ b/resources/js/types/manual.ts
@@ -5,6 +5,8 @@
  * (literal union で UI 分岐漏れを検出する。乖離検知は当面手動確認)。
  */
 
+import type { BadgeTone } from "@/components/atoms/Badge.types";
+
 export type VideoManualStatus = "draft" | "analyzing" | "ready" | "rendering" | "published";
 
 /** VideoManualStatus の表示ラベル (UI 共通) */
@@ -16,6 +18,18 @@ export const VIDEO_MANUAL_STATUS_LABELS: Record<VideoManualStatus, string> = {
     published: "公開済み",
 };
 
+/**
+ * 状態バッジの tone (結果表示の意味色。UI 共通)。
+ * satisfies でキー漏れ (status 追加時) をコンパイル時検出する。
+ */
+export const STATUS_TONES = {
+    draft: "neutral",
+    analyzing: "tertiary",
+    ready: "success",
+    rendering: "warning",
+    published: "primary",
+} as const satisfies Record<VideoManualStatus, BadgeTone>;
+
 export interface PaginationMeta {
     current_page: number;
     last_page: number;
diff --git a/routes/web.php b/routes/web.php
index cd1e29c..c640e61 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -12,6 +12,7 @@
 use App\Http\Controllers\Capture\CaptureTakeController;
 use App\Http\Controllers\Capture\TakeUploadUrlController;
 use App\Http\Controllers\ContactController;
+use App\Http\Controllers\DashboardController;
 use App\Http\Controllers\DebugLoginController;
 use App\Http\Controllers\HomeController;
 use App\Http\Controllers\Marketing\PricingController;
@@ -150,9 +151,8 @@
 |--------------------------------------------------------------------------
 */
 Route::middleware(['auth', 'verified'])->group(function (): void {
-    Route::get('/dashboard', function () {
-        return Inertia::render('Dashboard');
-    })->name('dashboard');
+    // ログイン直後の着地点 (課金ゲート外のまま。未契約でも状況把握と復帰導線を提供)
+    Route::get('/dashboard', DashboardController::class)->name('dashboard');
 
     /*
     | recent-auth (generic step-up 再認証)。機微操作 route の `recent-auth` middleware が
diff --git a/tests/Feature/DashboardTest.php b/tests/Feature/DashboardTest.php
new file mode 100644
index 0000000..98e97bd
--- /dev/null
+++ b/tests/Feature/DashboardTest.php
@@ -0,0 +1,397 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\VideoManualStatus;
+use App\Enums\OrganizationRole;
+use App\Enums\ProjectRole;
+use App\Models\AnalysisJob;
+use App\Models\Category;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\Take;
+use App\Models\TakeUploadReservation;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Billing\TicketLedgerService;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * ダッシュボード (ログイン直後の着地点) の集計正当性・状態分岐・cross-org 分離・
+ * ロール写像・current org 自己修復を固定する (概念設計 20260712-0029)。
+ */
+
+/** 採用テイクを cut に紐づける (adopted_take_id は保護キーのため forceFill) */
+function adoptTakeFor(Cut $cut): Take
+{
+    $take = Take::factory()->forCut($cut)->create();
+    $cut->forceFill(['adopted_take_id' => $take->id])->save();
+
+    return $take;
+}
+
+test('進行中ジョブ: analyzing/rendering manual と進行中 job が progress 付きで出る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $analyzing = VideoManual::factory()->forProject($project)->create([
+        'title' => '解析中マニュアル',
+        'status' => VideoManualStatus::Analyzing->value,
+        'updated_at' => now()->subMinutes(2),
+    ]);
+    AnalysisJob::factory()->forManual($analyzing)->running()->create(['progress' => 40]);
+
+    $rendering = VideoManual::factory()->forProject($project)->create([
+        'title' => '書き出し中マニュアル',
+        'status' => VideoManualStatus::Rendering->value,
+        'updated_at' => now()->subMinute(),
+    ]);
+    RenderJob::factory()->forManual($rendering)->running()->create(['progress' => 60]);
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Dashboard')
+            ->where('dashboard.state', 'ready')
+            ->has('dashboard.in_progress', 2)
+            ->where('dashboard.in_progress.0.manual_id', $rendering->id)
+            ->where('dashboard.in_progress.0.manual_status', 'rendering')
+            ->where('dashboard.in_progress.0.job_status', 'running')
+            ->where('dashboard.in_progress.0.progress', 60)
+            ->where('dashboard.in_progress.1.manual_id', $analyzing->id)
+            ->where('dashboard.in_progress.1.manual_status', 'analyzing')
+            ->where('dashboard.in_progress.1.progress', 40));
+});
+
+test('進行中ジョブ: job_updated_at が Y-m-d H:i で出る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'status' => VideoManualStatus::Analyzing->value,
+    ]);
+    $job = AnalysisJob::factory()->forManual($manual)->running()->create();
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.in_progress.0.job_updated_at', $job->fresh()?->updated_at?->format('Y-m-d H:i')));
+});
+
+test('進行中ジョブ: preview の RenderJob は引き当てない (job_status null = 準備中)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $rendering = VideoManual::factory()->forProject($project)->create([
+        'status' => VideoManualStatus::Rendering->value,
+    ]);
+    RenderJob::factory()->forManual($rendering)->preview()->running()->create();
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('dashboard.in_progress', 1)
+            ->where('dashboard.in_progress.0.job_status', null)
+            ->where('dashboard.in_progress.0.progress', null));
+});
+
+test('進行中ジョブ: progress は 0-100 に clamp される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $over = VideoManual::factory()->forProject($project)->create([
+        'status' => VideoManualStatus::Analyzing->value,
+        'updated_at' => now()->subMinute(),
+    ]);
+    AnalysisJob::factory()->forManual($over)->running()->create(['progress' => 150]);
+
+    $under = VideoManual::factory()->forProject($project)->create([
+        'status' => VideoManualStatus::Analyzing->value,
+        'updated_at' => now(),
+    ]);
+    AnalysisJob::factory()->forManual($under)->running()->create(['progress' => -10]);
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.in_progress.0.manual_id', $under->id)
+            ->where('dashboard.in_progress.0.progress', 0)
+            ->where('dashboard.in_progress.1.manual_id', $over->id)
+            ->where('dashboard.in_progress.1.progress', 100));
+});
+
+test('最近のマニュアル: updated_at 降順 5 件・category_name / status が正しい', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $category = Category::factory()->forProject($project)->create(['name' => '準備作業']);
+
+    foreach (range(1, 5) as $i) {
+        VideoManual::factory()->forProject($project)->create([
+            'title' => "マニュアル{$i}",
+            'updated_at' => now()->subMinutes(10 - $i), // 5 が最新
+        ]);
+    }
+    VideoManual::factory()->forProject($project)->forCategory($category)->create([
+        'title' => '最新の分類済み',
+        'updated_at' => now(),
+    ]);
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('dashboard.recent_manuals', 5)
+            ->where('dashboard.recent_manuals.0.title', '最新の分類済み')
+            ->where('dashboard.recent_manuals.0.category_name', '準備作業')
+            ->where('dashboard.recent_manuals.0.status', 'draft')
+            ->where('dashboard.recent_manuals.1.title', 'マニュアル5')
+            // 6 件目 (最古 = マニュアル1) は出ない
+            ->where('dashboard.recent_manuals.4.title', 'マニュアル2'));
+});
+
+test('撮影対象: 未採用 cut を持つ ready/published manual だけが未採用数付きで出る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    // ready + cut 2 本中 1 本採用済み → 残り 1/2
+    $target = VideoManual::factory()->forProject($project)->create([
+        'title' => '撮影対象',
+        'status' => VideoManualStatus::Ready->value,
+    ]);
+    $adopted = Cut::factory()->forManual($target)->create();
+    adoptTakeFor($adopted);
+    Cut::factory()->forManual($target)->create();
+
+    // 全 cut 採用済み → 出ない
+    $done = VideoManual::factory()->forProject($project)->create([
+        'title' => '採用完了',
+        'status' => VideoManualStatus::Published->value,
+    ]);
+    adoptTakeFor(Cut::factory()->forManual($done)->create());
+
+    // draft は未採用 cut があっても出ない
+    $draft = VideoManual::factory()->forProject($project)->create([
+        'title' => '下書き',
+        'status' => VideoManualStatus::Draft->value,
+    ]);
+    Cut::factory()->forManual($draft)->create();
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('dashboard.shooting_targets', 1)
+            ->where('dashboard.shooting_targets.0.manual_id', $target->id)
+            ->where('dashboard.shooting_targets.0.cuts_count', 2)
+            ->where('dashboard.shooting_targets.0.pending_cuts_count', 1));
+});
+
+test('残高/容量: grant 済み残高・低残高フラグ・使用率が正しい', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    Project::factory()->forOrganization($organization)->create();
+    app(TicketLedgerService::class)->grant($organization, 10, 'テスト付与');
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.billing.ticket_balance', 10)
+            ->where('dashboard.billing.is_low_balance', false)
+            ->where('dashboard.billing.storage_used_bytes', 0)
+            ->where('dashboard.billing.has_active_subscription', true));
+});
+
+test('残高/容量: threshold 未満で is_low_balance=true', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    app(TicketLedgerService::class)->grant($organization, 3, 'テスト付与'); // threshold 既定 5
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.billing.ticket_balance', 3)
+            ->where('dashboard.billing.is_low_balance', true));
+});
+
+test('容量: takes.size_bytes 合計と limit から storage_usage_percent が出る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->quota()->create(['limits' => ['max_storage_bytes' => 1_000]]);
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $cut = Cut::factory()->forManual($manual)->create();
+    Take::factory()->forCut($cut)->create(['size_bytes' => 250]);
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.billing.storage_used_bytes', 250)
+            ->where('dashboard.billing.storage_limit_bytes', 1_000)
+            ->where('dashboard.billing.storage_usage_percent', 25));
+});
+
+test('容量: pending 予約 (未失効) の bytes_pending が加算される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->quota()->create(['limits' => ['max_storage_bytes' => 1_000]]);
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $cut = Cut::factory()->forManual($manual)->create();
+    Take::factory()->forCut($cut)->create(['size_bytes' => 250]);
+    TakeUploadReservation::factory()->forCut($cut)->create(['size_bytes' => 250]);
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertInertia(fn (Assert $page) => $page
+            // occupiedBytes = bytes_used (250) + bytes_pending (250) の契約固定
+            ->where('dashboard.billing.storage_used_bytes', 500)
+            ->where('dashboard.billing.storage_usage_percent', 50));
+});
+
+test('cross-org 分離: 別 org の manual / job / take が一切混入しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('自分の組織');
+    $organization->quota()->create(['limits' => ['max_storage_bytes' => 1_000]]);
+    $project = Project::factory()->forOrganization($organization)->create();
+    VideoManual::factory()->forProject($project)->create(['title' => '自組織マニュアル']);
+
+    [$foreignOrg] = createOrganizationWithOwner('他人の組織');
+    $foreignProject = Project::factory()->forOrganization($foreignOrg)->create();
+    $foreignAnalyzing = VideoManual::factory()->forProject($foreignProject)->create([
+        'title' => '他組織の解析中',
+        'status' => VideoManualStatus::Analyzing->value,
+    ]);
+    AnalysisJob::factory()->forManual($foreignAnalyzing)->running()->create();
+    $foreignReady = VideoManual::factory()->forProject($foreignProject)->create([
+        'title' => '他組織の撮影対象',
+        'status' => VideoManualStatus::Ready->value,
+    ]);
+    $foreignCut = Cut::factory()->forManual($foreignReady)->create();
+    Take::factory()->forCut($foreignCut)->create(['size_bytes' => 999]);
+
+    $response = $this->actingAs($owner)->get('/dashboard');
+
+    $response->assertInertia(fn (Assert $page) => $page
+        ->where('dashboard.state', 'ready')
+        ->has('dashboard.in_progress', 0)
+        ->has('dashboard.recent_manuals', 1)
+        ->where('dashboard.recent_manuals.0.title', '自組織マニュアル')
+        ->has('dashboard.shooting_targets', 0)
+        ->where('dashboard.billing.storage_used_bytes', 0));
+    $response->assertDontSee('他組織の解析中');
+    $response->assertDontSee('他組織の撮影対象');
+});
+
+test('ロール: org owner は editor', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.role', 'editor'));
+});
+
+test('ロール: project member (撮影のみ) は shooter', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $shooter = attachOrganizationMember($organization, OrganizationRole::Member);
+    attachProjectMember($project, $shooter, ProjectRole::Member);
+
+    $this->actingAs($shooter)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.role', 'shooter'));
+});
+
+test('ロール: project 非所属の org member は viewer', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    Project::factory()->forOrganization($organization)->create();
+    $viewer = attachOrganizationMember($organization, OrganizationRole::Member);
+
+    $this->actingAs($viewer)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.role', 'viewer'));
+});
+
+test('空状態: 所属 org なしは state=no_organization で 200', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.state', 'no_organization')
+            ->where('dashboard.billing', null)
+            ->where('dashboard.project', null));
+});
+
+test('空状態: org あり project なしは state=no_project (owner は can_create_project=true)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('プロジェクト待ち組織');
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.state', 'no_project')
+            ->where('dashboard.can_create_project', true)
+            ->where('dashboard.organization_name', 'プロジェクト待ち組織')
+            ->has('dashboard.billing'));
+});
+
+test('空状態: member は can_create_project=false', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization, OrganizationRole::Member);
+
+    $this->actingAs($member)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.state', 'no_project')
+            ->where('dashboard.can_create_project', false));
+});
+
+test('空状態: project あり manual 0 件は ready + 空 list', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.state', 'ready')
+            ->has('dashboard.in_progress', 0)
+            ->has('dashboard.recent_manuals', 0)
+            ->has('dashboard.shooting_targets', 0));
+});
+
+test('current org 自己修復: org はあるが current null でも 200 + 当該 org のデータ + 修復', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    VideoManual::factory()->forProject($project)->create(['title' => '自組織マニュアル']);
+    $owner->forceFill(['current_organization_id' => null])->save();
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.state', 'ready')
+            ->where('dashboard.recent_manuals.0.title', '自組織マニュアル'));
+
+    expect($owner->fresh()->current_organization_id)->toBe($organization->id);
+});
+
+test('dangling current の cross-org 防御: 他 org のデータは一切出ず所属 org へ自己修復', function (): void {
+    [$foreignOrg] = createOrganizationWithOwner('他人の組織');
+    $foreignProject = Project::factory()->forOrganization($foreignOrg)->create();
+    VideoManual::factory()->forProject($foreignProject)->create(['title' => '他組織の機密マニュアル']);
+
+    [$organization] = createOrganizationWithOwner('自分の組織');
+    $member = attachOrganizationMember($organization, OrganizationRole::Member);
+    $member->forceFill(['current_organization_id' => $foreignOrg->id])->save();
+
+    $response = $this->actingAs($member)->get('/dashboard');
+
+    $response->assertOk();
+    $response->assertDontSee('他組織の機密マニュアル');
+    $response->assertDontSee('他人の組織');
+    $response->assertInertia(fn (Assert $page) => $page
+        ->where('dashboard.organization_name', '自分の組織'));
+    expect($member->fresh()->current_organization_id)->toBe($organization->id);
+});
+
+test('未契約 org: dashboard 200 + has_active_subscription=false + CTA 遷移先も 200', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(subscribed: false);
+    Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)->get('/dashboard')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('dashboard.billing.has_active_subscription', false));
+
+    // CTA 遷移先は課金ゲート外 (redirect loop なし不変条件)
+    $this->actingAs($owner)->get('/purchase-tickets')->assertOk();
+    $this->actingAs($owner)->get('/billing')->assertOk();
+});
+
+test('ゲストは /login へ redirect (既存挙動維持)', function (): void {
+    $this->get('/dashboard')->assertRedirect('/login');
+});
diff --git a/tests/Feature/Organization/CurrentOrganizationResolverTest.php b/tests/Feature/Organization/CurrentOrganizationResolverTest.php
new file mode 100644
index 0000000..a371957
--- /dev/null
+++ b/tests/Feature/Organization/CurrentOrganizationResolverTest.php
@@ -0,0 +1,119 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Organization\CurrentOrganizationResolver;
+
+/*
+ * CurrentOrganizationResolver の解決契約 (概念設計 表示組織の解決規則)。
+ * Service を直接駆動して契約を固定する (HTTP 経由は DashboardTest が担う)。
+ * 競合分岐は resolve() 単体では割り込みタイミングを再現できないため、
+ * seam である heal() を直接呼んで UPDATE の WHERE/EXISTS 契約を実分岐で固定する。
+ */
+
+test('current 非 null + 所属あり: その org を返す (書き込みなし)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $resolved = app(CurrentOrganizationResolver::class)->resolve($owner);
+
+    expect($resolved?->id)->toBe($organization->id);
+    expect($owner->fresh()->current_organization_id)->toBe($organization->id);
+});
+
+test('所属 0 件: null を返し current は変更されない', function (): void {
+    $user = User::factory()->create();
+
+    $resolved = app(CurrentOrganizationResolver::class)->resolve($user);
+
+    expect($resolved)->toBeNull();
+    expect($user->fresh()->current_organization_id)->toBeNull();
+});
+
+test('org はあるが current null: 候補 (organizations.id 昇順先頭) へ自己修復して返す', function (): void {
+    [$first] = createOrganizationWithOwner('先頭組織');
+    [$second] = createOrganizationWithOwner('二番目組織');
+    $member = attachOrganizationMember($second);
+    $first->users()->attach($member);
+    expect($member->current_organization_id)->toBeNull();
+
+    $resolved = app(CurrentOrganizationResolver::class)->resolve($member);
+
+    $expectedId = min($first->id, $second->id);
+    expect($resolved?->id)->toBe($expectedId);
+    expect($member->fresh()->current_organization_id)->toBe($expectedId);
+});
+
+test('current が非所属 org を指す (dangling): 当該 org を返さず所属 org へ自己修復する', function (): void {
+    [$foreign] = createOrganizationWithOwner('他人の組織');
+    [$organization] = createOrganizationWithOwner('自分の組織');
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $foreign->id])->save();
+
+    $resolved = app(CurrentOrganizationResolver::class)->resolve($member);
+
+    expect($resolved?->id)->toBe($organization->id);
+    expect($resolved?->id)->not->toBe($foreign->id);
+    expect($member->fresh()->current_organization_id)->toBe($organization->id);
+});
+
+test('heal: 候補 membership 消失 (EXISTS 偽) は 0 件更新で current は null のまま', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $organization->users()->detach($member);
+
+    $updated = app(CurrentOrganizationResolver::class)->heal($member, null, $organization->id);
+
+    expect($updated)->toBe(0);
+    expect($member->fresh()->current_organization_id)->toBeNull();
+});
+
+test('heal: 観測後に current が別 org へ変更済みなら上書きしない (WHERE 不一致)', function (): void {
+    [$orgA] = createOrganizationWithOwner('組織A');
+    [$orgB] = createOrganizationWithOwner('組織B');
+    $member = attachOrganizationMember($orgA);
+    $orgB->users()->attach($member);
+    // 観測 (null) の後に別セッションが current=B へ変更した状況を再現
+    $member->forceFill(['current_organization_id' => $orgB->id])->save();
+
+    $updated = app(CurrentOrganizationResolver::class)->heal($member, null, $orgA->id);
+
+    expect($updated)->toBe(0);
+    expect($member->fresh()->current_organization_id)->toBe($orgB->id);
+});
+
+test('heal: dangling 観測値は所属 org へ置換される (observed 一致分岐)', function (): void {
+    [$foreign] = createOrganizationWithOwner('他人の組織');
+    [$organization] = createOrganizationWithOwner('自分の組織');
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $foreign->id])->save();
+
+    $updated = app(CurrentOrganizationResolver::class)
+        ->heal($member, $foreign->id, $organization->id);
+
+    expect($updated)->toBe(1);
+    expect($member->fresh()->current_organization_id)->toBe($organization->id);
+});
+
+test('UPDATE 0 件後の resolve: fresh 再取得した最新状態で解決する (解決不能なら null)', function (): void {
+    // 所属 0 件だが current が dangling を指す状態 → heal は EXISTS 偽で不発 →
+    // fresh 再取得後の所属再確認でも解決できず null (無限再試行しない)
+    [$foreign] = createOrganizationWithOwner('他人の組織');
+    $user = User::factory()->create();
+    $user->forceFill(['current_organization_id' => $foreign->id])->save();
+
+    $resolved = app(CurrentOrganizationResolver::class)->resolve($user);
+
+    expect($resolved)->toBeNull();
+    // 修復対象候補が無いため dangling 値は残るが、描画には決して出ない (所属再確認)
+    expect($user->fresh()->current_organization_id)->toBe($foreign->id);
+});
+
+test('resolve は Organization モデルを pivot relation 経由で返す (型契約)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $resolved = app(CurrentOrganizationResolver::class)->resolve($owner);
+
+    expect($resolved)->toBeInstanceOf(Organization::class);
+});
diff --git a/tests/js/pages/Dashboard.test.ts b/tests/js/pages/Dashboard.test.ts
new file mode 100644
index 0000000..f1328c9
--- /dev/null
+++ b/tests/js/pages/Dashboard.test.ts
@@ -0,0 +1,267 @@
+import { afterEach, describe, expect, it } from "vitest";
+import { cleanup, render, screen } from "@testing-library/svelte";
+import Dashboard from "@/pages/Dashboard.svelte";
+import type { BillingSummary, DashboardData } from "@/types/dashboard";
+
+/**
+ * ダッシュボードの表示分岐 (state / ロール / 空状態 / 課金 callout) を固定する。
+ * shared props (未読数等) は page store 未設定でも安全に fallback する。
+ */
+
+function billingData(overrides: Partial<BillingSummary> = {}): BillingSummary {
+    return {
+        ticket_balance: 10,
+        is_low_balance: false,
+        storage_used_bytes: 250 * 1024 * 1024,
+        storage_limit_bytes: 1024 * 1024 * 1024,
+        storage_usage_percent: 25,
+        has_active_subscription: true,
+        ...overrides,
+    };
+}
+
+function dashboardData(overrides: Partial<DashboardData> = {}): DashboardData {
+    return {
+        state: "ready",
+        role: "editor",
+        can_create_project: false,
+        organization_name: "テスト組織",
+        project: { id: 1, name: "テストプロジェクト" },
+        in_progress: [],
+        recent_manuals: [],
+        shooting_targets: [],
+        billing: billingData(),
+        ...overrides,
+    };
+}
+
+function fullData(): DashboardData {
+    return dashboardData({
+        in_progress: [
+            {
+                manual_id: 10,
+                title: "解析中マニュアル",
+                manual_status: "analyzing",
+                job_status: "running",
+                progress: 40,
+                job_updated_at: "2026-07-12 00:30",
+            },
+            {
+                manual_id: 11,
+                title: "準備中マニュアル",
+                manual_status: "rendering",
+                job_status: null,
+                progress: null,
+                job_updated_at: null,
+            },
+        ],
+        recent_manuals: [
+            {
+                id: 20,
+                title: "最近のマニュアル",
+                status: "ready",
+                category_name: "準備作業",
+                updated_at: "2026-07-11 12:00",
+            },
+        ],
+        shooting_targets: [
+            { manual_id: 30, title: "撮影対象マニュアル", cuts_count: 4, pending_cuts_count: 2 },
+        ],
+    });
+}
+
+afterEach(() => {
+    cleanup();
+});
+
+describe("Dashboard", () => {
+    it("ready: スタットタイルが値どおり描画される", () => {
+        render(Dashboard, { props: { dashboard: fullData() } });
+
+        expect(screen.getByTestId("stat-tickets")).toHaveTextContent("10");
+        expect(screen.getByTestId("stat-storage")).toHaveTextContent("25%");
+        expect(screen.getByTestId("stat-storage")).toHaveTextContent("250.0 MB / 1.0 GB");
+        expect(screen.getByTestId("stat-unread")).toHaveTextContent("0");
+        expect(screen.getByTestId("stat-inprogress")).toHaveTextContent("2");
+    });
+
+    it("容量 limit null は「無制限」を表示する", () => {
+        render(Dashboard, {
+            props: {
+                dashboard: dashboardData({
+                    billing: billingData({
+                        storage_limit_bytes: null,
+                        storage_usage_percent: null,
+                    }),
+                }),
+            },
+        });
+
+        expect(screen.getByTestId("stat-storage")).toHaveTextContent("無制限");
+    });
+
+    it("進行中ジョブ行: progress bar / 最終更新 / 詳細導線 href が正しい", () => {
+        render(Dashboard, { props: { dashboard: fullData() } });
+
+        const items = screen.getAllByTestId("inprogress-item");
+        expect(items).toHaveLength(2);
+
+        const bar = screen.getByTestId("inprogress-bar");
+        expect(bar).toHaveAttribute("aria-valuenow", "40");
+        expect(items[0]).toHaveTextContent("最終更新 2026-07-12 00:30");
+        expect(items[0]).toHaveTextContent("解析中");
+
+        // job/progress null の行は progressbar を描画せず「準備中」表示
+        expect(items[1]).toHaveTextContent("準備中");
+        expect(items[1].querySelector('[role="progressbar"]')).toBeNull();
+
+        const links = screen.getAllByTestId("inprogress-detail-link");
+        expect(links[0].getAttribute("href")).toMatch(/\/projects\/1\/manuals\/10$/);
+    });
+
+    it("role=editor: 新規作成・カテゴリ管理・編集導線が描画される", () => {
+        render(Dashboard, { props: { dashboard: fullData() } });
+
+        expect(screen.getByTestId("qa-create-manual").getAttribute("href")).toMatch(
+            /\/projects\/1\/manuals\/create$/,
+        );
+        expect(screen.getByTestId("qa-categories").getAttribute("href")).toMatch(
+            /\/projects\/1\/categories$/,
+        );
+        expect(screen.getByTestId("qa-capture").getAttribute("href")).toMatch(/\/app$/);
+        expect(screen.getByTestId("recent-edit-link").getAttribute("href")).toMatch(
+            /\/projects\/1\/manuals\/20\/edit$/,
+        );
+    });
+
+    it("role=shooter: 編集者専用導線が存在せず、撮影対象が最近のマニュアルより先頭", () => {
+        const data = fullData();
+        data.role = "shooter";
+        render(Dashboard, { props: { dashboard: data } });
+
+        expect(screen.queryByTestId("qa-create-manual")).toBeNull();
+        expect(screen.queryByTestId("qa-categories")).toBeNull();
+        expect(screen.queryByTestId("recent-edit-link")).toBeNull();
+        expect(screen.getByTestId("qa-capture")).toBeInTheDocument();
+        expect(screen.getByTestId("shoot-button").getAttribute("href")).toMatch(
+            /\/app\/projects\/1\/manuals\/30$/,
+        );
+
+        // DOM 順: shooting-card が recent-card より前
+        const shooting = screen.getByTestId("shooting-card");
+        const recent = screen.getByTestId("recent-card");
+        expect(
+            shooting.compareDocumentPosition(recent) & Node.DOCUMENT_POSITION_FOLLOWING,
+        ).toBeTruthy();
+    });
+
+    it("role=viewer: クイックアクション自体が非描画", () => {
+        const data = fullData();
+        data.role = "viewer";
+        render(Dashboard, { props: { dashboard: data } });
+
+        expect(screen.queryByTestId("quick-actions")).toBeNull();
+        expect(screen.queryByTestId("recent-edit-link")).toBeNull();
+    });
+
+    it("空状態: no_organization は組織作成 CTA", () => {
+        render(Dashboard, {
+            props: {
+                dashboard: dashboardData({
+                    state: "no_organization",
+                    role: null,
+                    organization_name: null,
+                    project: null,
+                    billing: null,
+                }),
+            },
+        });
+
+        expect(screen.getByTestId("dashboard-setup-org")).toBeInTheDocument();
+        expect(screen.getByText("組織を作成")).toBeInTheDocument();
+    });
+
+    it("空状態: no_project (can_create_project=true) はプロジェクト作成 CTA", () => {
+        render(Dashboard, {
+            props: {
+                dashboard: dashboardData({
+                    state: "no_project",
+                    role: null,
+                    can_create_project: true,
+                    project: null,
+                }),
+            },
+        });
+
+        expect(screen.getByTestId("dashboard-setup-project")).toBeInTheDocument();
+        expect(screen.getByText("プロジェクトを作成")).toBeInTheDocument();
+    });
+
+    it("空状態: no_project (can_create_project=false) は組織名入りの案内文 (CTA 非描画)", () => {
+        render(Dashboard, {
+            props: {
+                dashboard: dashboardData({
+                    state: "no_project",
+                    role: null,
+                    can_create_project: false,
+                    organization_name: "依頼先組織",
+                    project: null,
+                }),
+            },
+        });
+
+        const guidance = screen.getByTestId("no-project-guidance");
+        expect(guidance).toHaveTextContent("依頼先組織");
+        expect(guidance).toHaveTextContent("プロジェクト作成を依頼してください");
+        expect(screen.queryByText("プロジェクトを作成")).toBeNull();
+    });
+
+    it("空状態: manual 0 件は editor に作成 CTA / shooter に案内文", () => {
+        render(Dashboard, { props: { dashboard: dashboardData() } });
+        expect(screen.getByTestId("recent-empty")).toHaveTextContent("最初のマニュアルを作成");
+        expect(screen.getByTestId("shooting-empty")).toHaveTextContent(
+            "撮影対象はまだありません",
+        );
+        cleanup();
+
+        render(Dashboard, { props: { dashboard: dashboardData({ role: "shooter" }) } });
+        expect(screen.getByTestId("recent-empty")).toHaveTextContent(
+            "編集者が作成すると、ここに表示されます",
+        );
+    });
+
+    it("is_low_balance=true で購入導線が出る", () => {
+        render(Dashboard, {
+            props: {
+                dashboard: dashboardData({
+                    billing: billingData({ ticket_balance: 2, is_low_balance: true }),
+                }),
+            },
+        });
+
+        expect(screen.getByTestId("purchase-link").getAttribute("href")).toMatch(
+            /\/purchase-tickets$/,
+        );
+        expect(screen.getByTestId("stat-tickets")).toHaveTextContent("残高が少なくなっています");
+    });
+
+    it("has_active_subscription=false で billing callout が出る", () => {
+        render(Dashboard, {
+            props: {
+                dashboard: dashboardData({
+                    billing: billingData({ has_active_subscription: false }),
+                }),
+            },
+        });
+
+        const callout = screen.getByTestId("billing-callout");
+        expect(callout).toBeInTheDocument();
+        expect(screen.getByText("プランを見る").getAttribute("href")).toMatch(/\/billing$/);
+    });
+
+    it("disabled 属性を持つ要素が 1 つも存在しない", () => {
+        const { container } = render(Dashboard, { props: { dashboard: fullData() } });
+
+        expect(container.querySelectorAll("[disabled]")).toHaveLength(0);
+    });
+});
```
