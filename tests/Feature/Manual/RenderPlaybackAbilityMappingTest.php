<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\User;
use App\Models\VideoManual;
use App\Policies\VideoManualPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Policies\DivergentVideoManualPolicy;

/*
 * playback の kind→ability 写像そのものを behavioral に固定する (T154)。
 *
 *   kind=preview → render ability / kind=render → download ability
 *
 * 本番 policy は render と download がどちらも ProjectPolicy::update に落ちるため
 * **可否が同値で観測差が出ない**。写像を 'render' 固定へ変異させても本番 policy 下では
 * 全テストが緑のままになる。そこで `Gate::policy()` でテスト専用 policy を差し込み、
 * ability ごとに可否を分岐させて写像を直接観測する。
 *
 * **本番挙動として両者の差が存在するとは言えない** — ここが固定するのは
 * 「写像が実際に kind で分岐していること」までである (誇張しない)。
 */

/**
 * @return array{Organization, User, Project, VideoManual, RenderJob, RenderJob}
 */
function abilityMappingContext(): array
{
    Storage::fake('s3');
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn (string $path): string => "https://signed.example/{$path}",
    );
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        // 完成動画の再生は published を要求するため published で用意する
        'status' => VideoManualStatus::Published->value,
        'scenario_version' => 2,
    ]);
    $preview = RenderJob::factory()->forManual($manual)->preview()
        ->succeeded('projects/x/previews/v2-1.mp4')->create();
    $render = RenderJob::factory()->forManual($manual)
        ->succeeded('projects/x/renders/v2-1.mp4')->create();

    Gate::policy(VideoManual::class, DivergentVideoManualPolicy::class);

    return [$organization, $owner, $project, $manual, $preview, $render];
}

function abilityMappingPlaybackUrl(Organization $organization, Project $project, VideoManual $manual, RenderJob $job): string
{
    return "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback";
}

afterEach(function (): void {
    // 残留を実行順に依存させない (Application はテストごとに作り直されるが、それに依存しない)
    DivergentVideoManualPolicy::reset();
    Gate::policy(VideoManual::class, VideoManualPolicy::class);
});

test('写像: download を拒否する policy では kind=render の playback が 403 になる', function (): void {
    [$organization, $owner, $project, $manual, , $render] = abilityMappingContext();
    DivergentVideoManualPolicy::$allowDownload = false;

    $this->actingAs($owner)->get(abilityMappingPlaybackUrl($organization, $project, $manual, $render))
        ->assertForbidden();
});

test('写像: download を拒否しても kind=preview の playback は 302 のまま (render ability で通る)', function (): void {
    [$organization, $owner, $project, $manual, $preview] = abilityMappingContext();
    DivergentVideoManualPolicy::$allowDownload = false;

    $this->actingAs($owner)->get(abilityMappingPlaybackUrl($organization, $project, $manual, $preview))
        ->assertRedirect('https://signed.example/projects/x/previews/v2-1.mp4');
});

test('写像: render を拒否する policy では kind=preview の playback が 403 になる', function (): void {
    [$organization, $owner, $project, $manual, $preview] = abilityMappingContext();
    DivergentVideoManualPolicy::$allowRender = false;

    $this->actingAs($owner)->get(abilityMappingPlaybackUrl($organization, $project, $manual, $preview))
        ->assertForbidden();
});

test('写像: render を拒否しても kind=render の playback は 302 のまま (download ability で通る)', function (): void {
    [$organization, $owner, $project, $manual, , $render] = abilityMappingContext();
    DivergentVideoManualPolicy::$allowRender = false;

    $this->actingAs($owner)->get(abilityMappingPlaybackUrl($organization, $project, $manual, $render))
        ->assertRedirect('https://signed.example/projects/x/renders/v2-1.mp4');
});

test('写像: 認可 403 はテナント境界 404 より後 (他組織からは policy 差替えに関係なく 404)', function (): void {
    [$organization, , $project, $manual, , $render] = abilityMappingContext();
    // policy は両方許可のまま。それでも他組織の利用者には存在が漏れない
    [, $stranger] = createOrganizationWithOwner('別組織');

    $this->actingAs($stranger)->get(abilityMappingPlaybackUrl($organization, $project, $manual, $render))
        ->assertNotFound();
});
