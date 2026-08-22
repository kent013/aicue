<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\VideoManual;

/*
|--------------------------------------------------------------------------
| 完成動画のアプリ内再生 (T154)
|--------------------------------------------------------------------------
|
| 制作フロー最終段の「完成物の受け取り」が DL 1 本しかなく、アプリ内で観る手段が無かった。
| 実ブラウザで見るのは次の 3 点だけである:
|   E-1 published マニュアルの詳細画面に完成動画プレイヤーが見える (src が playback route)
|   E-2 再生を足しても DL 導線は残っている (同じブロックに両方見える)
|   E-3 ready へ戻った manual では完成動画プレイヤーも DL ボタンも出ない
|
| **クリックしない**: Browser lane には object storage が無く、/playback は実 S3 の
| 署名 URL 生成へ進む。preload="none" により要素描画だけでは媒体取得が走らないが、
| これは**ヒント**でありブラウザが先読みしても検査は DOM 属性の照合なので結果は変わらない。
|
| 業務 route は require-active-subscription group 内なので contractPaidPlan を通さないと
| /billing-required へ着地する。実ブラウザは public/build を読むため UI 変更後は先に pnpm build。
|
| **DOM 契約だけを検査する**: 実際に mp4 が再生されること・S3 の CORS・iOS Safari の
| インライン再生挙動はこのレーンでは確認していない (誇張しない)。
|
*/

/**
 * published マニュアル + 現行世代の succeeded render。
 *
 * @return array{Project, VideoManual, RenderJob}
 */
function finishedVideoPlaybackFixture(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization);

    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'created_by' => $owner->id,
        'status' => VideoManualStatus::Published->value,
        'scenario_version' => 2,
    ]);
    $job = RenderJob::factory()->forManual($manual)
        ->succeeded("projects/{$project->id}/manuals/{$manual->id}/renders/v2-1.mp4")->create();

    test()->actingAs($owner);

    return [$organization, $project, $manual, $job];
}

test('E-1: published マニュアルの詳細画面に完成動画プレイヤーが見える', function (): void {
    [$organization, $project, $manual, $job] = finishedVideoPlaybackFixture();

    $page = visit("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}")
        ->assertPathIs("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}")
        ->assertNoJavaScriptErrors();

    $page->assertPresent('[data-testid="final-video"]');

    // src は job id を含む playback route を指す (再レンダで URL 文字列そのものが変わる)
    expect($page->script(<<<'JS'
        (() => {
            const el = document.querySelector('[data-testid="final-video"]');
            return el === null ? null : { src: el.getAttribute('src'), preload: el.getAttribute('preload') };
        })()
    JS))->toMatchArray([
        'src' => "/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}/render-jobs/{$job->id}/playback",
        'preload' => 'none',
    ]);
});

test('E-2: 再生を足しても DL 導線は残っている (同じブロックに両方見える)', function (): void {
    [$organization, $project, $manual] = finishedVideoPlaybackFixture();

    $page = visit("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}")
        ->assertPathIs("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}");

    $page->assertVisible('[data-testid="final-video-block"]');
    $page->assertVisible('[data-testid="download-button"]');

    // 両方が同じ完成動画ブロックの内側にある (受け取り手段を 1 箇所に集める)
    expect($page->script(<<<'JS'
        (() => {
            const block = document.querySelector('[data-testid="final-video-block"]');
            if (block === null) return null;
            return {
                video: block.querySelector('[data-testid="final-video"]') !== null,
                download: block.querySelector('[data-testid="download-button"]') !== null,
            };
        })()
    JS))->toMatchArray(['video' => true, 'download' => true]);
});

test('E-3: ready へ戻った manual では完成動画プレイヤーも DL ボタンも出ない', function (): void {
    [$organization, $project, $manual] = finishedVideoPlaybackFixture();
    // シナリオ編集で ready へ戻ると完成動画は受け取れない (押すと 404 になる導線を出さない)
    $manual->forceFill(['status' => VideoManualStatus::Ready])->save();

    $page = visit("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}")
        ->assertPathIs("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}");

    $page->assertMissing('[data-testid="final-video-block"]');
    $page->assertMissing('[data-testid="download-button"]');
});
