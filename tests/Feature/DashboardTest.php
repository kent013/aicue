<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\AnalysisJob;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Category;
use App\Models\Cut;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\Take;
use App\Models\TakeUploadReservation;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * ダッシュボード (ログイン直後の着地点) の集計正当性・状態分岐・cross-org 分離・
 * ロール写像・current org 自己修復を固定する (概念設計 20260712-0029)。
 */

/** 採用テイクを cut に紐づける (adopted_take_id は保護キーのため forceFill) */
function adoptTakeFor(Cut $cut): Take
{
    $take = Take::factory()->forCut($cut)->create();
    $cut->forceFill(['adopted_take_id' => $take->id])->save();

    return $take;
}

test('進行中ジョブ: analyzing/rendering manual と進行中 job が progress 付きで出る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $analyzing = VideoManual::factory()->forProject($project)->create([
        'title' => '解析中マニュアル',
        'status' => VideoManualStatus::Analyzing->value,
        'updated_at' => now()->subMinutes(2),
    ]);
    AnalysisJob::factory()->forManual($analyzing)->running()->create(['progress' => 40]);

    $rendering = VideoManual::factory()->forProject($project)->create([
        'title' => '書き出し中マニュアル',
        'status' => VideoManualStatus::Rendering->value,
        'updated_at' => now()->subMinute(),
    ]);
    RenderJob::factory()->forManual($rendering)->running()->create(['progress' => 60]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->where('dashboard.state', 'ready')
            ->has('dashboard.in_progress', 2)
            ->where('dashboard.in_progress.0.manual_id', $rendering->id)
            ->where('dashboard.in_progress.0.manual_status', 'rendering')
            ->where('dashboard.in_progress.0.job_status', 'running')
            ->where('dashboard.in_progress.0.progress', 60)
            ->where('dashboard.in_progress.1.manual_id', $analyzing->id)
            ->where('dashboard.in_progress.1.manual_status', 'analyzing')
            ->where('dashboard.in_progress.1.progress', 40));
});

test('進行中ジョブ: job_updated_at が Y-m-d H:i で出る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Analyzing->value,
    ]);
    $job = AnalysisJob::factory()->forManual($manual)->running()->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.in_progress.0.job_updated_at', $job->fresh()?->updated_at?->format('Y-m-d H:i')));
});

test('進行中ジョブ: preview の RenderJob は引き当てない (job_status null = 準備中)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $rendering = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Rendering->value,
    ]);
    RenderJob::factory()->forManual($rendering)->preview()->running()->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->has('dashboard.in_progress', 1)
            ->where('dashboard.in_progress.0.job_status', null)
            ->where('dashboard.in_progress.0.progress', null));
});

test('進行中ジョブ: failed の job は引き当てない (queued/running のみが契約)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $analyzing = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Analyzing->value,
    ]);
    AnalysisJob::factory()->forManual($analyzing)->failed()->create();

    // manual 行は残る (状態カード) が、failed job は進行中扱いにしない
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->has('dashboard.in_progress', 1)
            ->where('dashboard.in_progress.0.manual_id', $analyzing->id)
            ->where('dashboard.in_progress.0.job_status', null)
            ->where('dashboard.in_progress.0.progress', null));
});

test('進行中ジョブ: progress は 0-100 に clamp される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $over = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Analyzing->value,
        'updated_at' => now()->subMinute(),
    ]);
    AnalysisJob::factory()->forManual($over)->running()->create(['progress' => 150]);

    $under = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Analyzing->value,
        'updated_at' => now(),
    ]);
    AnalysisJob::factory()->forManual($under)->running()->create(['progress' => -10]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.in_progress.0.manual_id', $under->id)
            ->where('dashboard.in_progress.0.progress', 0)
            ->where('dashboard.in_progress.1.manual_id', $over->id)
            ->where('dashboard.in_progress.1.progress', 100));
});

test('最近のマニュアル: updated_at 降順 5 件・category_name / status が正しい', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create(['name' => '準備作業']);

    foreach (range(1, 5) as $i) {
        VideoManual::factory()->forProject($project)->create([
            'title' => "マニュアル{$i}",
            'updated_at' => now()->subMinutes(10 - $i), // 5 が最新
        ]);
    }
    VideoManual::factory()->forProject($project)->forCategory($category)->create([
        'title' => '最新の分類済み',
        'updated_at' => now(),
    ]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->has('dashboard.recent_manuals', 5)
            ->where('dashboard.recent_manuals.0.title', '最新の分類済み')
            ->where('dashboard.recent_manuals.0.category_name', '準備作業')
            ->where('dashboard.recent_manuals.0.status', 'draft')
            ->where('dashboard.recent_manuals.1.title', 'マニュアル5')
            // 6 件目 (最古 = マニュアル1) は出ない
            ->where('dashboard.recent_manuals.4.title', 'マニュアル2'));
});

test('撮影対象: 未採用 cut を持つ ready/published manual だけが未採用数付きで出る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    // ready + cut 2 本中 1 本採用済み → 残り 1/2
    $target = VideoManual::factory()->forProject($project)->create([
        'title' => '撮影対象',
        'status' => VideoManualStatus::Ready->value,
    ]);
    $adopted = Cut::factory()->forManual($target)->create();
    adoptTakeFor($adopted);
    Cut::factory()->forManual($target)->create();

    // 全 cut 採用済み → 出ない
    $done = VideoManual::factory()->forProject($project)->create([
        'title' => '採用完了',
        'status' => VideoManualStatus::Published->value,
    ]);
    adoptTakeFor(Cut::factory()->forManual($done)->create());

    // draft は未採用 cut があっても出ない
    $draft = VideoManual::factory()->forProject($project)->create([
        'title' => '下書き',
        'status' => VideoManualStatus::Draft->value,
    ]);
    Cut::factory()->forManual($draft)->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->has('dashboard.shooting_targets', 1)
            ->where('dashboard.shooting_targets.0.manual_id', $target->id)
            ->where('dashboard.shooting_targets.0.cuts_count', 2)
            ->where('dashboard.shooting_targets.0.pending_cuts_count', 1));
});

test('残高/容量: grant 済み残高・低残高フラグ・使用率が正しい', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    Project::factory()->forOrganization($organization)->create();
    app(TicketLedgerService::class)->grant($organization, 10, 'テスト付与');

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.billing.ticket_balance', 10)
            ->where('dashboard.billing.is_low_balance', false)
            ->where('dashboard.billing.storage_used_bytes', 0)
            ->where('dashboard.billing.billing_state', 'active_free_plan'));
});

test('残高/容量: threshold 未満で is_low_balance=true', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    app(TicketLedgerService::class)->grant($organization, 3, 'テスト付与'); // threshold 既定 5

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.billing.ticket_balance', 3)
            ->where('dashboard.billing.is_low_balance', true));
});

test('容量: takes.size_bytes 合計と limit から storage_usage_percent が出る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->quota()->create(['limits' => ['max_storage_bytes' => 1_000]]);
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $cut = Cut::factory()->forManual($manual)->create();
    Take::factory()->forCut($cut)->create(['size_bytes' => 250]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.billing.storage_used_bytes', 250)
            ->where('dashboard.billing.storage_limit_bytes', 1_000)
            ->where('dashboard.billing.storage_usage_percent', 25));
});

test('容量: storage_usage_percent は 0-100 に clamp される (不正データで負値にしない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->quota()->create(['limits' => ['max_storage_bytes' => 1_000]]);
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $cut = Cut::factory()->forManual($manual)->create();
    // データ不整合 (負の size_bytes) が起きても percent は 0 に丸める防御的契約
    Take::factory()->forCut($cut)->create(['size_bytes' => -500]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.billing.storage_usage_percent', 0));
});

test('容量: pending 予約 (未失効) の bytes_pending が加算される', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $organization->quota()->create(['limits' => ['max_storage_bytes' => 1_000]]);
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $cut = Cut::factory()->forManual($manual)->create();
    Take::factory()->forCut($cut)->create(['size_bytes' => 250]);
    TakeUploadReservation::factory()->forCut($cut)->create(['size_bytes' => 250]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            // occupiedBytes = bytes_used (250) + bytes_pending (250) の契約固定
            ->where('dashboard.billing.storage_used_bytes', 500)
            ->where('dashboard.billing.storage_usage_percent', 50));
});

test('cross-org 分離: 別 org の manual / job / take が一切混入しない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('自分の組織');
    $organization->quota()->create(['limits' => ['max_storage_bytes' => 1_000]]);
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create(['title' => '自組織マニュアル']);

    [$foreignOrg] = createOrganizationWithOwner('他人の組織');
    $foreignProject = Project::factory()->forOrganization($foreignOrg)->create();
    $foreignAnalyzing = VideoManual::factory()->forProject($foreignProject)->create([
        'title' => '他組織の解析中',
        'status' => VideoManualStatus::Analyzing->value,
    ]);
    AnalysisJob::factory()->forManual($foreignAnalyzing)->running()->create();
    $foreignReady = VideoManual::factory()->forProject($foreignProject)->create([
        'title' => '他組織の撮影対象',
        'status' => VideoManualStatus::Ready->value,
    ]);
    $foreignCut = Cut::factory()->forManual($foreignReady)->create();
    Take::factory()->forCut($foreignCut)->create(['size_bytes' => 999]);

    $response = $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard");

    $response->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.state', 'ready')
        ->has('dashboard.in_progress', 0)
        ->has('dashboard.recent_manuals', 1)
        ->where('dashboard.recent_manuals.0.title', '自組織マニュアル')
        ->has('dashboard.shooting_targets', 0)
        ->where('dashboard.billing.storage_used_bytes', 0));
    $response->assertDontSee('他組織の解析中');
    $response->assertDontSee('他組織の撮影対象');
});

test('ロール: org owner は editor', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.role', 'editor'));
});

test('ロール: project member (撮影のみ) は shooter', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $shooter = attachOrganizationMember($organization, OrganizationRole::Member);
    attachProjectMember($project, $shooter, ProjectRole::Member);

    $this->actingAs($shooter)->get("/organizations/{$organization->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.role', 'shooter'));
});

test('ロール: project 非所属の org member は viewer', function (): void {
    [$organization] = createOrganizationWithOwner();
    Project::factory()->forOrganization($organization)->create();
    $viewer = attachOrganizationMember($organization, OrganizationRole::Member);

    $this->actingAs($viewer)->get("/organizations/{$organization->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.role', 'viewer'));
});

test('所属していない組織の dashboard は 404 (組織の有無を露出しない)', function (): void {
    // 組織文脈は URL だけで決まる (家系裁定 AG-037)。所属 0 件の利用者は
    // そもそも組織 URL を持たず、分岐入口 (/go) が組織作成へ倒す。
    [$organization] = createOrganizationWithOwner('他人の組織');
    $user = User::factory()->create();

    $this->actingAs($user)->get("/organizations/{$organization->slug}/dashboard")->assertNotFound();
    $this->actingAs($user)->get('/go')->assertRedirect('/organizations/create');
});

test('空状態: org あり project なしは state=no_project (owner は can_create_project=true)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('プロジェクト待ち組織');

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.state', 'no_project')
            ->where('dashboard.can_create_project', true)
            ->where('dashboard.organization_name', 'プロジェクト待ち組織')
            ->has('dashboard.billing'));
});

test('空状態: member は can_create_project=false', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization, OrganizationRole::Member);

    $this->actingAs($member)->get("/organizations/{$organization->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.state', 'no_project')
            ->where('dashboard.can_create_project', false));
});

test('空状態: project あり manual 0 件は ready + 空 list', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.state', 'ready')
            ->has('dashboard.in_progress', 0)
            ->has('dashboard.recent_manuals', 0)
            ->has('dashboard.shooting_targets', 0));
});

test('current org 自己修復: org はあるが current null でも 200 + 当該 org のデータ + 修復', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create(['title' => '自組織マニュアル']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.state', 'ready')
            ->where('dashboard.recent_manuals.0.title', '自組織マニュアル'));
});

test('cross-org 防御: URL 上の組織のデータだけが出る (他 org は一切出ない)', function (): void {
    [$foreignOrg] = createOrganizationWithOwner('他人の組織');
    $foreignProject = Project::factory()->forOrganization($foreignOrg)->create();
    VideoManual::factory()->forProject($foreignProject)->create(['title' => '他組織の機密マニュアル']);

    [$organization] = createOrganizationWithOwner('自分の組織');
    $member = attachOrganizationMember($organization, OrganizationRole::Member);

    $response = $this->actingAs($member)->get("/organizations/{$organization->slug}/dashboard");

    $response->assertOk();
    $response->assertDontSee('他組織の機密マニュアル');
    $response->assertDontSee('他人の組織');
    $response->assertInertia(fn (Assert $page) => $page
        ->where('dashboard.organization_name', '自分の組織'));
});

test('Free (grandfathered) org: dashboard 200 + billing_state=active_free_plan + 業務 route 開通', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.billing.billing_state', 'active_free_plan'));

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects")->assertOk();
});

test('有償契約 + 支払い不健全 org: billing_state=expired_checkout + CTA 遷移先 200 (redirect loop なし)', function (): void {
    // 有償 org は grandfatherFreePlan: false (backfill 対象は plan_code/free_plan_code とも
    // NULL の org に閉じるため、有償 org に grandfather マーカーが付くのは非現実な fixture。
    // 付くと state() の ActiveFreePlan が支払い健全性判定を覆い隠す)
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    // P2 の判定モデル置換で past_due は cohort D として許可へ反転したため、
    // 遮断側の不変条件 (redirect loop なし) は canceled (cohort G) で保持する。
    contractPaidPlan($organization, status: 'canceled');
    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.billing.billing_state', 'expired_checkout'));

    // CTA 遷移先は課金ゲート外 (redirect loop なし不変条件)
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing/purchase-tickets")->assertOk();
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/billing")->assertOk();
});

test('有償契約 + past_due org: billing_state=subscribed (cohort D。dunning 中も利用継続)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization, status: 'past_due');
    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.billing.billing_state', 'subscribed'));
});

/*
 * F-2-01 (bug-hunt 20260811-003230): 一度も契約していない組織に「支払いが確認できない」
 * 文言が出ていた。原因は props が hasBillingAccess の真偽値で「未契約」と「支払い不健全」を
 * 潰していたこと。ここで固定するのは「state が画面まで届くこと」であり、state 導出そのものは
 * tests/Feature/Billing/BillingAccessStateTest.php が担当する (重複させない)。
 *
 * fixture 注意: createOrganizationWithOwner() は既定で free_plan_code='personal' を立てる
 * (= ActiveFreePlan で先に返る)。未契約系 state を作るテストは grandfatherFreePlan: false 必須。
 */
test('新規登録相当 (未契約) org: billing_state=no_subscription (F-2-01 再現)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.billing.billing_state', 'no_subscription')
            // 旧 prop を並走させていないこと (思考原則 3 の機械固定)
            ->missing('dashboard.billing.has_billing_access'));
});

test('未契約 org の CTA 着地 /onboarding/checkout が 200 (行き先のない詰みを作らない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/onboarding/checkout")->assertOk();
});

test('未契約 org の非 manageBilling メンバーは CTA 着地で /billing-required へ捌かれる', function (): void {
    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
    $member = attachOrganizationMember($organization, OrganizationRole::Member);
    // current org は保護キーのため forceFill (attachOrganizationMember は設定しない)

    // CTA の行き先はサーバが決める (フロントで認可を二重実装しない) ことの behavioral な裏付け
    $this->actingAs($member)->get("/organizations/{$organization->slug}/onboarding/checkout")
        ->assertRedirect(route('onboarding.billing-required', ['organization' => $organization->slug]));

    $this->actingAs($member)->get("/organizations/{$organization->slug}/onboarding/billing-required")->assertOk();
});

test('live pending checkout org: billing_state=pending_checkout', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    BillingCheckoutSession::factory()->for($organization)->create(); // 既定 = live pending

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.billing.billing_state', 'pending_checkout'));
});

test('expired checkout org: billing_state=expired_checkout', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    BillingCheckoutSession::factory()->for($organization)->expired()->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/dashboard")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.billing.billing_state', 'expired_checkout'));
});

test('ゲストは /login へ redirect (既存挙動維持)', function (): void {
    $this->get('/organizations/guest-org/dashboard')->assertRedirect('/login');
});
