<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Models\Category;
use App\Models\Cut;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\VideoManual;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Projects/Show に内包する動画マニュアル一覧 (manuals/categories/manualFilters props)。
 * GET クエリ (?category=&progress=&q=) の絞り込みと paginate の shape を固定する。
 * 状態の語彙は一覧の 3 値 (ManualProgress)。制作状態 5 値は行にも絞り込みにも出さない (T197)。
 */

test('projects.show は manuals / categories / manualFilters を供給する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create(['name' => '準備作業']);
    VideoManual::factory()->forProject($project)->forCategory($category)->create(['title' => '分類済み']);
    VideoManual::factory()->forProject($project)->create(['title' => '未分類マニュアル']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Projects/Show')
            ->has('manuals.data', 2)
            ->has('manuals.meta', fn (Assert $meta) => $meta
                ->where('current_page', 1)
                ->where('last_page', 1)
                ->where('per_page', 10)
                ->where('total', 2))
            ->has('categories', 1)
            ->where('categories.0.name', '準備作業')
            ->where('manualFilters.category', null)
            ->where('manualFilters.progress', null)
            ->where('manualFilters.q', null));
});

test('未分類 manual は category=null で返る (フロントは「未分類」を表示)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create(['title' => '未分類マニュアル']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.data.0.category', null)
            ->where('manuals.data.0.progress', 'not_started'));
});

test('category フィルタ (id / uncategorized sentinel) で絞り込める', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();
    VideoManual::factory()->forProject($project)->forCategory($category)->create(['title' => '分類済み']);
    VideoManual::factory()->forProject($project)->create(['title' => '未分類マニュアル']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?category={$category->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '分類済み')
            ->where('manualFilters.category', (string) $category->id));

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?category=uncategorized")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '未分類マニュアル')
            ->where('manualFilters.category', 'uncategorized'));
});

/**
 * 制作状態 5 値をそれぞれ 1 本ずつ持つ一覧を作る (T197 の写像を Inertia payload で見るための fixture)。
 * title は status ごとに固有にする (件数だけの assertion にしない = 対象を同定する)。
 */
function seedManualsForEachStatus(Project $project): void
{
    foreach ([
        '下書き' => VideoManualStatus::Draft,
        '解析中' => VideoManualStatus::Analyzing,
        '準備完了' => VideoManualStatus::Ready,
        '書き出し中' => VideoManualStatus::Rendering,
        '公開済み' => VideoManualStatus::Published,
    ] as $title => $status) {
        VideoManual::factory()->forProject($project)->create([
            'title' => $title,
            'status' => $status->value,
        ]);
    }
}

test('progress=in_progress は analyzing / ready / rendering の 3 件を返す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    seedManualsForEachStatus($project);

    $response = $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?progress=in_progress");

    $response->assertInertia(fn (Assert $page) => $page
        ->has('manuals.data', 3)
        ->where('manualFilters.progress', 'in_progress'));

    // 対象の同定は title の集合で行う (件数一致だけに頼らない)
    $titles = array_column($response->inertiaPage()['props']['manuals']['data'], 'title');
    sort($titles);
    expect($titles)->toBe(['書き出し中', '準備完了', '解析中']);
});

test('progress=not_started は draft のみ / progress=completed は published のみ', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    seedManualsForEachStatus($project);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?progress=not_started")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '下書き')
            ->where('manuals.data.0.progress', 'not_started'));

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?progress=completed")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '公開済み')
            ->where('manuals.data.0.progress', 'completed'));
});

test('allowlist 外の値と旧 ?status= は無視して全件になる (互換は残さない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    seedManualsForEachStatus($project);

    // 旧 5 値をそのまま渡しても progress の allowlist は通らない
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?progress=ready")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 5)
            ->where('manualFilters.progress', null));

    // **旧 URL の互換は無い** (?status=published は未知キーとして無視される)
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?status=published")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 5)
            ->where('manualFilters.progress', null)
            ->missing('manualFilters.status'));
});

test('行 payload は progress を持ち status を持たない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    // 並び順への依存を避けるため manual 1 本だけの fixture で契約を見る
    VideoManual::factory()->forProject($project)->create(['title' => '下書き']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '下書き')
            ->where('manuals.data.0.progress', 'not_started')
            ->missing('manuals.data.0.status')
            // paginator の query が外に出ないことの構造的確認 (links を props に出していない)
            ->missing('manuals.links'));
});

test('q フィルタは title 部分一致 (LIKE メタ文字はリテラル扱い)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create(['title' => 'ネジ締め作業']);
    VideoManual::factory()->forProject($project)->create(['title' => '洗浄 100% 完全版']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?q=ネジ")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', 'ネジ締め作業'));

    // "%" をリテラルとして検索できる (ワイルドカード化しない)
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?q=100%25")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '洗浄 100% 完全版'));
});

test('paginate は 10 件/ページで meta を返す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->count(12)->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 10)
            ->where('manuals.meta.total', 12)
            ->where('manuals.meta.last_page', 2));

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?page=2")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 2)
            ->where('manuals.meta.current_page', 2));
});

test('別 project の manual は一覧に混ざらない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $other = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create(['title' => '自分のマニュアル']);
    VideoManual::factory()->forProject($other)->create(['title' => '他プロジェクトのマニュアル']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '自分のマニュアル'));
});

test('manuals.data.* は creator / updated_at を供給する (正常系)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->createdBy($owner)->create([
        'title' => 'メタ確認', 'updated_at' => '2026-07-10 09:30:00',
    ]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.data.0.creator.id', $owner->id)
            ->where('manuals.data.0.creator.name', $owner->name)
            ->where('manuals.data.0.updated_at', '2026-07-10 09:30'));
});

test('sort 未指定は既定順 (created_at desc, id desc) を維持する (回帰)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $old = VideoManual::factory()->forProject($project)->create(['created_at' => '2026-07-01 00:00:00']);
    $new = VideoManual::factory()->forProject($project)->create(['created_at' => '2026-07-05 00:00:00']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.data.0.id', $new->id)
            ->where('manuals.data.1.id', $old->id)
            ->where('manualFilters.sort', null)
            ->where('manualFilters.mine', false));
});

test('sort 各値で並べ替える (updated / title × asc/desc)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $a = VideoManual::factory()->forProject($project)->create([
        'title' => 'apple', 'updated_at' => '2026-07-01 00:00:00',
    ]);
    $b = VideoManual::factory()->forProject($project)->create([
        'title' => 'banana', 'updated_at' => '2026-07-02 00:00:00',
    ]);
    $c = VideoManual::factory()->forProject($project)->create([
        'title' => 'cherry', 'updated_at' => '2026-07-03 00:00:00',
    ]);

    $order = function (string $sort) use ($owner, $project): array {
        $props = $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?sort={$sort}")
            ->inertiaPage()['props'];

        return array_column($props['manuals']['data'], 'id');
    };

    expect($order('updated_desc'))->toBe([$c->id, $b->id, $a->id]);
    expect($order('updated_asc'))->toBe([$a->id, $b->id, $c->id]);
    expect($order('title_asc'))->toBe([$a->id, $b->id, $c->id]);
    expect($order('title_desc'))->toBe([$c->id, $b->id, $a->id]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?sort=updated_desc")
        ->assertInertia(fn (Assert $page) => $page->where('manualFilters.sort', 'updated_desc'));
});

test('sort allowlist 外は既定順へフォールバック (manualFilters.sort=null)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $old = VideoManual::factory()->forProject($project)->create(['created_at' => '2026-07-01 00:00:00']);
    $new = VideoManual::factory()->forProject($project)->create(['created_at' => '2026-07-05 00:00:00']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?sort=bogus")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.data.0.id', $new->id)
            ->where('manuals.data.1.id', $old->id)
            ->where('manualFilters.sort', null));
});

test('同値 updated_at でも id tie-breaker でページ境界に重複/欠落が無い', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    // 15 件すべて同一 updated_at (tie-breaker が無いとページ間で不安定になる)
    VideoManual::factory()->forProject($project)->count(15)->create(['updated_at' => '2026-07-01 00:00:00']);

    $ids = function (int $page) use ($owner, $project): array {
        $props = $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?sort=updated_desc&page={$page}")
            ->inertiaPage()['props'];

        return array_column($props['manuals']['data'], 'id');
    };

    $page1 = $ids(1);
    $page2 = $ids(2);

    expect($page1)->toHaveCount(10);
    expect($page2)->toHaveCount(5);
    // 排他 (重複なし) かつ全 15 件を被覆 (欠落なし)
    expect(array_intersect($page1, $page2))->toBe([]);
    expect(count(array_unique(array_merge($page1, $page2))))->toBe(15);
});

test('mine=1 は自ユーザー作成分のみに絞る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $other = attachOrganizationMember($organization);
    $project = Project::factory()->forOrganization($organization)->create();
    $mine = VideoManual::factory()->forProject($project)->createdBy($owner)->create(['title' => '自作']);
    VideoManual::factory()->forProject($project)->createdBy($other)->create(['title' => '他人作']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?mine=1")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.id', $mine->id)
            ->where('manualFilters.mine', true));
});

test('mine と category/progress/q/sort の併用で結合絞り込みできる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $other = attachOrganizationMember($organization);
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();
    // 目標: 自作 + 該当カテゴリ + published + タイトル一致
    $target = VideoManual::factory()->forProject($project)->forCategory($category)->createdBy($owner)->create([
        'title' => 'ネジ締め', 'status' => VideoManualStatus::Published->value, 'updated_at' => '2026-07-05 00:00:00',
    ]);
    // ノイズ: 他人作 / 別カテゴリ / 別 status / 別タイトル
    VideoManual::factory()->forProject($project)->forCategory($category)->createdBy($other)->create([
        'title' => 'ネジ締め', 'status' => VideoManualStatus::Published->value,
    ]);
    VideoManual::factory()->forProject($project)->createdBy($owner)->create([
        'title' => 'ネジ締め', 'status' => VideoManualStatus::Published->value,
    ]);
    VideoManual::factory()->forProject($project)->forCategory($category)->createdBy($owner)->create([
        'title' => 'ネジ締め', 'status' => VideoManualStatus::Draft->value,
    ]);

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/projects/{$project->id}?mine=1&category={$category->id}&progress=completed&q=ネジ&sort=updated_desc")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.id', $target->id));
});

/*
 * T182 + T189: 行の再生時間 (duration_ms) と行内操作の可否
 * (current_finished_render_job_id / deletable)、範囲外ページの丸め、q の 200 文字上限。
 */

test('duration_ms は published の総尺のみ供給する (それ以外は null)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $published = VideoManual::factory()->forProject($project)->published(185_000)
        ->create(['title' => '公開済み']);
    // published だが総尺が記録されていない行 (duration_ms = null)
    $noLength = VideoManual::factory()->forProject($project)->published()
        ->create(['title' => '尺なし']);
    // published でない行は総尺が入っていても出さない (古い尺で語らない)
    $ready = VideoManual::factory()->forProject($project)->create([
        'title' => '準備完了',
        'status' => VideoManualStatus::Ready->value,
        'total_length_ms' => 999_000,
    ]);

    $rows = $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}")
        ->inertiaPage()['props']['manuals']['data'];
    $byId = array_column($rows, null, 'id');

    expect($byId[$published->id]['duration_ms'])->toBe(185_000);
    expect($byId[$noLength->id]['duration_ms'])->toBeNull();
    expect($byId[$ready->id]['duration_ms'])->toBeNull();
});

test('current_finished_render_job_id は published × 現行世代の succeeded render (kind=render / output_path あり) のときだけ id を返す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $ok = VideoManual::factory()->forProject($project)->published(60_000)->create(['title' => '受取可']);
    $okJob = RenderJob::factory()->forManual($ok)->succeeded('renders/ok.mp4')->create();

    // 最新 succeeded の実体が消えている (掃除済み) → 旧世代へフォールバックしない
    $stale = VideoManual::factory()->forProject($project)->published(60_000)->create(['title' => '実体なし']);
    RenderJob::factory()->forManual($stale)->succeeded('renders/old.mp4')->create();
    RenderJob::factory()->forManual($stale)->succeeded('renders/new.mp4')
        ->state(fn (): array => ['output_path' => null])->create();

    // preview の succeeded しか無い
    $previewOnly = VideoManual::factory()->forProject($project)->published(60_000)->create(['title' => 'preview のみ']);
    RenderJob::factory()->forManual($previewOnly)->preview()->succeeded('renders/preview.mp4')->create();

    // published でない (succeeded render はある)
    $notPublished = VideoManual::factory()->forProject($project)->create([
        'title' => '未公開', 'status' => VideoManualStatus::Ready->value,
    ]);
    RenderJob::factory()->forManual($notPublished)->succeeded('renders/ready.mp4')->create();

    $rows = $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}")
        ->inertiaPage()['props']['manuals']['data'];
    $byId = array_column($rows, null, 'id');

    expect($byId[$ok->id]['current_finished_render_job_id'])->toBe($okJob->id);
    expect($byId[$stale->id]['current_finished_render_job_id'])->toBeNull();
    expect($byId[$previewOnly->id]['current_finished_render_job_id'])->toBeNull();
    expect($byId[$notPublished->id]['current_finished_render_job_id'])->toBeNull();
});

test('一覧の行 props に旧キー downloadable が残っていない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->published(60_000)->create();

    $rows = $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}")
        ->inertiaPage()['props']['manuals']['data'];

    expect($rows[0])->toHaveKey('current_finished_render_job_id');
    expect(array_key_exists('downloadable', $rows[0]))->toBeFalse();
});

test('撮影者は current_finished_render_job_id=null / deletable=false、編集者は id と deletable=true', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Member);
    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
    $job = RenderJob::factory()->forManual($manual)->succeeded('renders/ok.mp4')->create();

    $this->actingAs($member)->get("/organizations/{$organization->slug}/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.data.0.current_finished_render_job_id', null)
            ->where('manuals.data.0.deletable', false));

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.data.0.current_finished_render_job_id', $job->id)
            ->where('manuals.data.0.deletable', true));
});

test('一覧が 0 件でも props が壊れない (data: [] / meta.total: 0)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 0)
            ->where('manuals.meta.total', 0)
            ->where('manuals.meta.current_page', 1));
});

test('範囲外ページは最終ページへ丸める (空の一覧に着地させない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->count(12)->create();

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?page=99")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 2)
            ->where('manuals.meta.current_page', 2)
            ->where('manuals.meta.last_page', 2));
});

test('page が数字でない / 0 のときは 1 ページ目として扱う', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->count(12)->create();

    foreach (['abc', '0', '-3'] as $raw) {
        $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?page={$raw}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('manuals.data', 10)
                ->where('manuals.meta.current_page', 1));
    }
});

test('PHP_INT_MAX 超の page でも 500 にならず最終ページへ着地する (offset の float 化なし)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->count(12)->create();

    foreach (['99999999999999999999999', (string) PHP_INT_MAX] as $raw) {
        $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?page={$raw}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('manuals.data', 2)
                ->where('manuals.meta.current_page', 2));
    }
});

test('q は先頭 200 文字で絞り込む (201 文字目以降は一致に寄与しない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $title = str_repeat('あ', 200);
    VideoManual::factory()->forProject($project)->create(['title' => $title]);
    VideoManual::factory()->forProject($project)->create(['title' => '別のマニュアル']);

    // 200 文字を超える検索語は先頭 200 文字へ切り詰められるため、上記 title に一致する
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?q=".urlencode($title.'ZZZ'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', $title)
            ->where('manualFilters.q', $title));
});

test('一覧 0 件でも範囲外ページは 1 ページ目へ丸める (meta が食い違わない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    foreach (['99', '99999999999999999999999'] as $raw) {
        $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?page={$raw}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('manuals.data', 0)
                ->where('manuals.meta.total', 0)
                ->where('manuals.meta.current_page', 1)
                ->where('manuals.meta.last_page', 1));
    }
});

test('category は正規形へ畳まれる (0003 → 3。フィルタ select の値と一致する)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();
    VideoManual::factory()->forProject($project)->forCategory($category)->create(['title' => '分類済み']);
    VideoManual::factory()->forProject($project)->create(['title' => '未分類マニュアル']);

    $padded = str_pad((string) $category->id, 6, '0', STR_PAD_LEFT);
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?category={$padded}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '分類済み')
            ->where('manualFilters.category', (string) $category->id));

    // 桁溢れする数字列は該当なしへ倒れる (全件が出る方向へは倒さない)
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?category=99999999999999999999999")
        ->assertInertia(fn (Assert $page) => $page->has('manuals.data', 0));
});

/*
 * T202: 一覧検索の対象範囲がカット本文 (scene / narration / subtitle_primary /
 * subtitle_secondary) に広がったこと。述語の正本は ManualKeywordSearch で、
 * 撮影 PWA 一覧と**同じ関数**を通る。
 */

test('q は narration に部分一致する (title に語が無くても hit する)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $target = VideoManual::factory()->forProject($project)->create(['title' => '第一工程']);
    Cut::factory()->forManual($target)->create(['narration' => 'ここでトルクレンチを使います']);
    $other = VideoManual::factory()->forProject($project)->create(['title' => '第二工程']);
    Cut::factory()->forManual($other)->create(['narration' => '清掃して終了します']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?q=".urlencode('トルクレンチ'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.id', $target->id));
});

test('q は scene / narration / subtitle_primary / subtitle_secondary のいずれに一致しても hit する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    // 4 列それぞれ「その列にしか語を持たない」manual を 1 本ずつ作る (列単位の取りこぼしを見る)
    $columns = [
        'scene' => 'ゴウセイ',
        'narration' => 'ナレゴ',
        'subtitle_primary' => 'ジマクイチ',
        'subtitle_secondary' => 'ジマクニ',
    ];
    $ids = [];
    foreach ($columns as $column => $word) {
        $manual = VideoManual::factory()->forProject($project)->create(['title' => "{$column} の手順"]);
        Cut::factory()->forManual($manual)->create([
            // 他の 3 列に語が漏れないよう、対象列だけへ固有語を置く
            'scene' => '既定のシーン',
            'narration' => '既定のナレーション',
            'subtitle_primary' => '既定の字幕',
            'subtitle_secondary' => '既定の補助字幕',
            $column => "作業で{$word}を使う",
        ]);
        $ids[$column] = $manual->id;
    }

    foreach ($columns as $column => $word) {
        $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?q=".urlencode($word))
            ->assertInertia(fn (Assert $page) => $page
                ->has('manuals.data', 1)
                ->where('manuals.data.0.id', $ids[$column]));
    }
});

test('q は shooting_point には一致しない (対象外列)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $manual = VideoManual::factory()->forProject($project)->create(['title' => '構図の手順']);
    Cut::factory()->forManual($manual)->create([
        'scene' => '既定のシーン',
        'narration' => '既定のナレーション',
        'subtitle_primary' => null,
        'subtitle_secondary' => '既定の補助字幕',
        'shooting_point' => '手元をヨリデトルコト',
    ]);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?q=".urlencode('ヨリデトルコト'))
        ->assertInertia(fn (Assert $page) => $page->has('manuals.data', 0));
});

test('q はカット本文にも title にも一致しない manual を除外する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $manual = VideoManual::factory()->forProject($project)->create(['title' => '無関係の手順']);
    Cut::factory()->forManual($manual)->create(['narration' => '無関係の本文']);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?q=".urlencode('存在しない語'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 0)
            ->where('manuals.meta.total', 0));
});

test('本文が複数カットに一致しても manual は 1 行だけ返る (join 化して行が重複していない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $manual = VideoManual::factory()->forProject($project)->create(['title' => '同語が並ぶ手順']);
    foreach (range(0, 2) as $sortOrder) {
        Cut::factory()->forManual($manual)->withSortOrder($sortOrder)
            ->create(['narration' => 'ここでカクニンゴを言う']);
    }

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?q=".urlencode('カクニンゴ'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.id', $manual->id)
            ->where('manuals.meta.total', 1));
});

test('q はカット本文でも LIKE メタ文字 (%/_/\\) をリテラル扱いする', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $percent = VideoManual::factory()->forProject($project)->create(['title' => 'パーセント']);
    Cut::factory()->forManual($percent)->create(['narration' => '洗浄 100% 完全版']);
    $notPercent = VideoManual::factory()->forProject($project)->create(['title' => '数字']);
    Cut::factory()->forManual($notPercent)->create(['narration' => '洗浄 1005 完全版']);

    $underscore = VideoManual::factory()->forProject($project)->create(['title' => 'アンダースコア']);
    Cut::factory()->forManual($underscore)->create(['narration' => '型番 A_B を使う']);
    $notUnderscore = VideoManual::factory()->forProject($project)->create(['title' => '別型番']);
    Cut::factory()->forManual($notUnderscore)->create(['narration' => '型番 AXB を使う']);

    $backslash = VideoManual::factory()->forProject($project)->create(['title' => 'バックスラッシュ']);
    Cut::factory()->forManual($backslash)->create(['narration' => '経路 C\\D を通る']);

    // % がワイルドカード化していない (1005 は hit しない)
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?q=".urlencode('100%'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.id', $percent->id));

    // _ が任意 1 文字になっていない (AXB は hit しない)
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?q=".urlencode('A_B'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.id', $underscore->id));

    // エスケープ文字自身がリテラルとして通る
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?q=".urlencode('C\\D'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.id', $backslash->id));
});

test('mine=1 / progress / category と q は AND で効く (カット本文一致でも)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $other = attachOrganizationMember($organization);
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();

    // 自作 / 分類済み / published (= progress completed) かつ本文一致
    $target = VideoManual::factory()->forProject($project)->forCategory($category)
        ->createdBy($owner)->published(60_000)->create(['title' => '対象']);
    Cut::factory()->forManual($target)->create(['narration' => 'ここでフクゴウゴを使う']);

    // 他人作 (mine で外れる)
    $byOther = VideoManual::factory()->forProject($project)->forCategory($category)
        ->createdBy($other)->published(60_000)->create(['title' => '他人作']);
    Cut::factory()->forManual($byOther)->create(['narration' => 'ここでフクゴウゴを使う']);

    // 自作だが未分類 (category で外れる)
    $uncategorized = VideoManual::factory()->forProject($project)
        ->createdBy($owner)->published(60_000)->create(['title' => '未分類']);
    Cut::factory()->forManual($uncategorized)->create(['narration' => 'ここでフクゴウゴを使う']);

    // 自作・分類済みだが draft (progress で外れる)
    $draft = VideoManual::factory()->forProject($project)->forCategory($category)
        ->createdBy($owner)->create(['title' => '下書き', 'status' => VideoManualStatus::Draft->value]);
    Cut::factory()->forManual($draft)->create(['narration' => 'ここでフクゴウゴを使う']);

    $this->actingAs($owner)
        ->get("/organizations/{$organization->slug}/projects/{$project->id}?mine=1&category={$category->id}&progress=completed&q=".urlencode('フクゴウゴ'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.id', $target->id));
});

test('q は先頭 200 文字で切られる (カット本文でも)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $body = str_repeat('あ', 200);
    $target = VideoManual::factory()->forProject($project)->create(['title' => '長文本文']);
    Cut::factory()->forManual($target)->create(['narration' => $body.'ZZZ']);
    VideoManual::factory()->forProject($project)->create(['title' => '別のマニュアル']);

    // 203 文字を渡しても先頭 200 文字で検索されるため上記 narration に一致する
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?q=".urlencode($body.'YYY'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.id', $target->id)
            ->where('manualFilters.q', $body));
});

test('検索条件付きでも範囲外ページは丸められ meta が食い違わない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    foreach (range(1, 11) as $index) {
        $manual = VideoManual::factory()->forProject($project)->create(['title' => "手順 {$index}"]);
        Cut::factory()->forManual($manual)->create(['narration' => 'すべてにマルメゴがある']);
    }
    // 一致しない manual (total に混ざらないこと)
    VideoManual::factory()->forProject($project)->create(['title' => '無関係']);

    // 丸めは (clone $baseQuery) を 2 回叩く。キーワードが片方にしか乗っていないと total が食い違う
    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?q=".urlencode('マルメゴ').'&page=999')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.meta.current_page', 2)
            ->where('manuals.meta.last_page', 2)
            ->where('manuals.meta.total', 11));
});
