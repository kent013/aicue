<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\VideoManual;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * 撮影 PWA → PC 側マニュアル詳細の復路 (T155)。
 *
 * 固定する契約 (片方向の含意):
 * - 撮影ナビ (capture.manuals.show) を開ける利用者は、復路の行き先
 *   (projects.manuals.show) も開ける = UI に出す無条件リンクが 403 にならない。
 * - もっとも弱い principal である**撮影者 (project_member)** で確認する
 *   (編集者で通っても撮影者で通る保証にならないため)。
 * - 片側だけの検査では復路到達の含意を確認できないため、1 本で両方を叩く。
 * - **status に依らない**。復路リンクは status で出し分けないため、全 status で固定する
 *   (往路の isCaptureNavigable は rendering で消えるが、それは別の述語である)。
 * - 200 だけでなく**着地した画面 (Inertia component)** まで見る。200 を返す別画面へ
 *   逃がす実装に置き換わったとき、200 だけの検査は沈黙するため。
 *
 * 何を証明しないか: 構造そのものの同一性は証明しない。固定できるのは、下記の principal と
 * Factory 既定データについての到達可否と着地 component までである (層の対応は設計根拠)。
 *
 * 両 route が同じく通る層 (省略形で書かない):
 *   auth / verified / not-pending-deletion (外側 group)
 *   → require-active-subscription / project.in-current-org (内側 group)
 *   → Route::scopeBindings() ($project->manuals() 経由)
 *   → controller の resolveOrganizationProject() (認可より前に 404)
 *   → Gate::authorize('view', $manual)
 * 詳細 GET はどちらも status による絞り込みを持たない (一覧 index だけが持つ)。
 */

test('最弱 principal (撮影者) は全 status で撮影ナビと PC 側マニュアル詳細の両方へ到達できる', function (VideoManualStatus $status): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => $status->value]);

    $member = attachOrganizationMember($organization);
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    attachProjectMember($project, $member, ProjectRole::Member);

    // 現在地 (復路リンクを描画する画面)
    $this->actingAs($member)
        ->get("/app/projects/{$project->id}/manuals/{$manual->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Capture/Show'));

    // 復路の行き先。ここが 403/404/別画面になるなら、ヘッダーの無条件リンクは詰みの導線になる
    $this->actingAs($member)
        ->get("/projects/{$project->id}/manuals/{$manual->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Manuals/Show'));
})->with(VideoManualStatus::cases());
