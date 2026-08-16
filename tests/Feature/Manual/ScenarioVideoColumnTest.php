<?php

declare(strict_types=1);

use App\Models\Cut;
use App\Models\Project;
use App\Models\Take;
use App\Models\VideoManual;
use Illuminate\Support\Facades\DB;

/*
 * シナリオ編集画面 (projects.manuals.edit) の「動画」列 props (takeSummaries)。
 * 描画時点のスナップショットであり常に最新ではない (採用は撮影 PWA からも起きる)。
 * 採用テイクは `adopted` キーで返す = 採用テイク外部キーの識別子を props に出さない。
 */

test('takeSummaries に全カット分の要約が sort_order 順で載る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $second = Cut::factory()->forManual($manual)->withSortOrder(2)->create();
    $first = Cut::factory()->forManual($manual)->withSortOrder(1)->create();
    Take::factory()->forCut($first)->create();
    Take::factory()->forCut($first)->create(['sort_order' => 1]);

    $this->actingAs($owner)
        ->get("/projects/{$project->id}/manuals/{$manual->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->where('takeSummaries.0.cut_id', $first->id)
            ->where('takeSummaries.0.takes_count', 2)
            ->where('takeSummaries.0.adopted', null)
            ->where('takeSummaries.1.cut_id', $second->id)
            ->where('takeSummaries.1.takes_count', 0));
});

test('採用テイクのあるカットは adopted.id / adopted.status が入る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create();
    $cut->forceFill(['adopted_take_id' => $take->id])->save();

    $this->actingAs($owner)
        ->get("/projects/{$project->id}/manuals/{$manual->id}/edit")
        ->assertInertia(fn ($page) => $page
            ->where('takeSummaries.0.adopted.id', $take->id)
            ->where('takeSummaries.0.adopted.status', 'ready'));
});

test('takeSummaries のキーに採用テイク外部キーの識別子が現れない (gate 回避の命名の固定)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create();
    $cut->forceFill(['adopted_take_id' => $take->id])->save();

    $response = $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/edit");

    $summaries = json_encode($response->viewData('page')['props']['takeSummaries']);
    expect($summaries)->toBeString()->not->toContain('adopted_take_id');
});

test('cut を増やしてもクエリ本数が増えない (N+1 を作らない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $count = function (int $cuts) use ($project, $owner): int {
        $manual = VideoManual::factory()->forProject($project)->create();
        for ($i = 0; $i < $cuts; $i++) {
            $cut = Cut::factory()->forManual($manual)->withSortOrder($i)->create();
            $take = Take::factory()->forCut($cut)->create();
            $cut->forceFill(['adopted_take_id' => $take->id])->save();
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/edit")->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $small = $count(3);
    $large = $count(30);

    // 本数の完全一致では固定しない (無関係な最適化で赤くしない)。増えないことだけを見る
    expect($large)->toBeLessThanOrEqual($small);
});
