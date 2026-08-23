<?php

declare(strict_types=1);

use App\Enums\Manual\TakeStatus;
use App\Enums\Manual\VideoManualStatus;
use App\Models\Cut;
use App\Models\Project;
use App\Models\Take;
use App\Models\VideoManual;

/*
|--------------------------------------------------------------------------
| プレビューの事前告知 (T148 / bug-hunt F-1-01)
|--------------------------------------------------------------------------
|
| 採用テイクが揃っていない状態で「プレビュー生成」を押すと、警告なしで全編黒画面の
| 動画が生成完了していた (完成動画生成は同条件を 422 でブロックする)。
| 修正の要点は「preview を止めること」ではなく「押す前に同じ基準で知らせること」なので、
| 実ブラウザで見るのは次の 2 点だけである:
|   E-1 注記が押す前に見えている
|   E-2 注記が出ていてもボタンは押下可能である (禁止事項 8: disabled にしない)
|
| **クリックしない**: Browser lane には ffmpeg も object storage も無く、押すと
| RunManualRender の実行経路へ進みうる。押下可能性は disabled / aria-disabled の
| 不在と可視性で判定する。生成物の説明 (placeholder_cut_count の注記) は vitest 側で固定する。
|
| 撮影 PWA と同じく業務 route は require-active-subscription group 内なので
| contractPaidPlan を通さないと /billing-required へ着地する。
|
| 実ブラウザは public/build のビルド済アセットを読むため、UI 変更後は先に pnpm build する。
|
*/

/**
 * 採用テイクが揃っていない ready manual (3 カット中 2 カットが未充足)。
 *
 * @return array{Project, VideoManual}
 */
function previewCoverageNoticeFixture(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    contractPaidPlan($organization);

    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'created_by' => $owner->id,
        'status' => VideoManualStatus::Ready->value,
        'scenario_version' => 2,
    ]);

    // 1 枚目: 採用済み ready (充足)
    $adopted = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($adopted)->create(['duration_ms' => 5_000]);
    $adopted->forceFill(['adopted_take_id' => $take->id])->save();

    // 2 枚目: 未採用 / 3 枚目: 採用済みだが processing (「未撮影」だけではないことの実例)
    Cut::factory()->forManual($manual)->withSortOrder(1)->create();
    $processing = Cut::factory()->forManual($manual)->withSortOrder(2)->create();
    $processingTake = Take::factory()->forCut($processing)->create([
        'duration_ms' => 5_000,
        'status' => TakeStatus::Processing->value,
    ]);
    $processing->forceFill(['adopted_take_id' => $processingTake->id])->save();

    test()->actingAs($owner);

    return [$organization, $project, $manual];
}

test('E-1: 採用テイクが揃っていないマニュアルの詳細画面で、プレビュー生成前に注記が見える', function (): void {
    [$organization, $project, $manual] = previewCoverageNoticeFixture();

    $page = visit("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}")
        ->assertPathIs("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}")
        ->assertNoJavaScriptErrors();

    $page->assertVisible('[data-testid="preview-coverage-note"]');

    $note = $page->text('[data-testid="preview-coverage-note"]');
    // 件数は述語どおり 2 / 3 (未採用 1 + 採用済みだが processing 1)
    expect($note)->toContain('2');
    expect($note)->toContain('3');
    // TakeStatus は 4 値あるため「未撮影」と断定せず述語の意味をそのまま言う
    expect($note)->toContain('撮影・処理が完了した採用テイクがありません');
    expect($note)->toContain('黒背景');
});

test('E-2: 注記が出ていてもプレビュー生成ボタンは押下可能である (禁止事項 8)', function (): void {
    [$organization, $project, $manual] = previewCoverageNoticeFixture();

    $page = visit("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}")
        ->assertPathIs("/organizations/{$organization->slug}/projects/{$project->id}/manuals/{$manual->id}");

    // クリックはしない (ffmpeg / storage が無いレーンで実行経路へ進めない)。
    // 押下可能性は「可視 + disabled 属性・aria-disabled の不在」で判定する。
    $page->assertVisible('[data-testid="preview-button"]');

    expect($page->script(<<<'JS'
        (() => {
            const el = document.querySelector('[data-testid="preview-button"]');
            if (el === null) return null;
            return {
                disabledProp: el.disabled === true,
                disabledAttr: el.hasAttribute('disabled'),
                ariaDisabled: el.getAttribute('aria-disabled') === 'true',
            };
        })()
    JS))->toMatchArray([
        'disabledProp' => false,
        'disabledAttr' => false,
        'ariaDisabled' => false,
    ]);
});
