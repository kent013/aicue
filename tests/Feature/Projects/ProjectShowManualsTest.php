<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Models\Category;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\VideoManual;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Projects/Show に内包する動画マニュアル一覧 (manuals/categories/manualFilters props)。
 * GET クエリ (?category=&status=&q=) の絞り込みと paginate の shape を固定する。
 */

test('projects.show は manuals / categories / manualFilters を供給する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create(['name' => '準備作業']);
    VideoManual::factory()->forProject($project)->forCategory($category)->create(['title' => '分類済み']);
    VideoManual::factory()->forProject($project)->create(['title' => '未分類マニュアル']);

    $this->actingAs($owner)->get("/projects/{$project->id}")
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
            ->where('manualFilters.status', null)
            ->where('manualFilters.q', null));
});

test('未分類 manual は category=null で返る (フロントは「未分類」を表示)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create(['title' => '未分類マニュアル']);

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.data.0.category', null)
            ->where('manuals.data.0.status', 'draft'));
});

test('category フィルタ (id / uncategorized sentinel) で絞り込める', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();
    VideoManual::factory()->forProject($project)->forCategory($category)->create(['title' => '分類済み']);
    VideoManual::factory()->forProject($project)->create(['title' => '未分類マニュアル']);

    $this->actingAs($owner)->get("/projects/{$project->id}?category={$category->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '分類済み')
            ->where('manualFilters.category', (string) $category->id));

    $this->actingAs($owner)->get("/projects/{$project->id}?category=uncategorized")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '未分類マニュアル')
            ->where('manualFilters.category', 'uncategorized'));
});

test('status フィルタで絞り込める (不正値は無視)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create(['title' => '下書き']);
    VideoManual::factory()->forProject($project)->create([
        'title' => '公開済み',
        'status' => VideoManualStatus::Published->value,
    ]);

    $this->actingAs($owner)->get("/projects/{$project->id}?status=published")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '公開済み')
            ->where('manualFilters.status', 'published'));

    // enum に無い値は無視 (全件)
    $this->actingAs($owner)->get("/projects/{$project->id}?status=bogus")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 2)
            ->where('manualFilters.status', null));
});

test('q フィルタは title 部分一致 (LIKE メタ文字はリテラル扱い)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->create(['title' => 'ネジ締め作業']);
    VideoManual::factory()->forProject($project)->create(['title' => '洗浄 100% 完全版']);

    $this->actingAs($owner)->get("/projects/{$project->id}?q=ネジ")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', 'ネジ締め作業'));

    // "%" をリテラルとして検索できる (ワイルドカード化しない)
    $this->actingAs($owner)->get("/projects/{$project->id}?q=100%25")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '洗浄 100% 完全版'));
});

test('paginate は 10 件/ページで meta を返す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    VideoManual::factory()->forProject($project)->count(12)->create();

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 10)
            ->where('manuals.meta.total', 12)
            ->where('manuals.meta.last_page', 2));

    $this->actingAs($owner)->get("/projects/{$project->id}?page=2")
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

    $this->actingAs($owner)->get("/projects/{$project->id}")
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

    $this->actingAs($owner)->get("/projects/{$project->id}")
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

    $this->actingAs($owner)->get("/projects/{$project->id}")
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
        $props = $this->actingAs($owner)->get("/projects/{$project->id}?sort={$sort}")
            ->inertiaPage()['props'];

        return array_column($props['manuals']['data'], 'id');
    };

    expect($order('updated_desc'))->toBe([$c->id, $b->id, $a->id]);
    expect($order('updated_asc'))->toBe([$a->id, $b->id, $c->id]);
    expect($order('title_asc'))->toBe([$a->id, $b->id, $c->id]);
    expect($order('title_desc'))->toBe([$c->id, $b->id, $a->id]);

    $this->actingAs($owner)->get("/projects/{$project->id}?sort=updated_desc")
        ->assertInertia(fn (Assert $page) => $page->where('manualFilters.sort', 'updated_desc'));
});

test('sort allowlist 外は既定順へフォールバック (manualFilters.sort=null)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $old = VideoManual::factory()->forProject($project)->create(['created_at' => '2026-07-01 00:00:00']);
    $new = VideoManual::factory()->forProject($project)->create(['created_at' => '2026-07-05 00:00:00']);

    $this->actingAs($owner)->get("/projects/{$project->id}?sort=bogus")
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
        $props = $this->actingAs($owner)->get("/projects/{$project->id}?sort=updated_desc&page={$page}")
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

    $this->actingAs($owner)->get("/projects/{$project->id}?mine=1")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.id', $mine->id)
            ->where('manualFilters.mine', true));
});

test('mine と category/status/q/sort の併用で結合絞り込みできる', function (): void {
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
        ->get("/projects/{$project->id}?mine=1&category={$category->id}&status=published&q=ネジ&sort=updated_desc")
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

    $rows = $this->actingAs($owner)->get("/projects/{$project->id}")
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

    $rows = $this->actingAs($owner)->get("/projects/{$project->id}")
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

    $rows = $this->actingAs($owner)->get("/projects/{$project->id}")
        ->inertiaPage()['props']['manuals']['data'];

    expect($rows[0])->toHaveKey('current_finished_render_job_id');
    expect(array_key_exists('downloadable', $rows[0]))->toBeFalse();
});

test('撮影者は current_finished_render_job_id=null / deletable=false、編集者は id と deletable=true', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $member, ProjectRole::Member);
    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
    $job = RenderJob::factory()->forManual($manual)->succeeded('renders/ok.mp4')->create();

    $this->actingAs($member)->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.data.0.current_finished_render_job_id', null)
            ->where('manuals.data.0.deletable', false));

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('manuals.data.0.current_finished_render_job_id', $job->id)
            ->where('manuals.data.0.deletable', true));
});

test('一覧が 0 件でも props が壊れない (data: [] / meta.total: 0)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $this->actingAs($owner)->get("/projects/{$project->id}")
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

    $this->actingAs($owner)->get("/projects/{$project->id}?page=99")
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
        $this->actingAs($owner)->get("/projects/{$project->id}?page={$raw}")
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
        $this->actingAs($owner)->get("/projects/{$project->id}?page={$raw}")
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
    $this->actingAs($owner)->get("/projects/{$project->id}?q=".urlencode($title.'ZZZ'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', $title)
            ->where('manualFilters.q', $title));
});

test('一覧 0 件でも範囲外ページは 1 ページ目へ丸める (meta が食い違わない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    foreach (['99', '99999999999999999999999'] as $raw) {
        $this->actingAs($owner)->get("/projects/{$project->id}?page={$raw}")
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
    $this->actingAs($owner)->get("/projects/{$project->id}?category={$padded}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.title', '分類済み')
            ->where('manualFilters.category', (string) $category->id));

    // 桁溢れする数字列は該当なしへ倒れる (全件が出る方向へは倒さない)
    $this->actingAs($owner)->get("/projects/{$project->id}?category=99999999999999999999999")
        ->assertInertia(fn (Assert $page) => $page->has('manuals.data', 0));
});
