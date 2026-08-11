<?php

declare(strict_types=1);

use App\Enums\Manual\RenderKind;
use App\Models\RenderJob;
use App\Models\VideoManual;
use App\Services\Manual\CurrentRenderArtifact;
use App\Services\Manual\RenderJobService;

/*
 * 「いま受け取れるレンダ成果物はどれか」の唯一の選択式 (T154)。
 *
 * 定義は保持ポリシー (RenderJobService::newerSucceededExists / DeleteRenderOutputsJob) と
 * **同じ世代定義**であり、最新 succeeded の output_path が NULL のときに
 * 旧世代へフォールバックしない (削除済みオブジェクトの署名 URL を出さないため)。
 */

test('同 kind の最新 succeeded を返す (kind をまたがない)', function (): void {
    $manual = VideoManual::factory()->create();
    RenderJob::factory()->forManual($manual)->succeeded('renders/v1.mp4')->create();
    $latestRender = RenderJob::factory()->forManual($manual)->succeeded('renders/v2.mp4')->create();
    $latestPreview = RenderJob::factory()->forManual($manual)->preview()
        ->succeeded('previews/v3.mp4')->create();

    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)?->id)
        ->toBe($latestRender->id);
    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Preview)?->id)
        ->toBe($latestPreview->id);
});

test('別 manual の最新 succeeded に引っ張られない (選択の境界は manual × kind)', function (): void {
    $manual = VideoManual::factory()->create();
    $own = RenderJob::factory()->forManual($manual)->succeeded('renders/own.mp4')->create();

    $other = VideoManual::factory()->create();
    RenderJob::factory()->forManual($other)->succeeded('renders/other.mp4')->create();

    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)?->id)->toBe($own->id);
});

test('最新 succeeded の output_path が NULL なら null (旧世代へフォールバックしない)', function (): void {
    $manual = VideoManual::factory()->create();
    RenderJob::factory()->forManual($manual)->succeeded('renders/v1.mp4')->create();
    // 世代交代後に実体が掃除された (DeleteRenderOutputsJob が output_path を CAS で NULL 化) 形
    RenderJob::factory()->forManual($manual)->create(['status' => 'succeeded', 'output_path' => null]);

    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render))->toBeNull();
});

test('succeeded が 1 件も無ければ null (queued / running / failed は選ばない)', function (): void {
    $manual = VideoManual::factory()->create();
    RenderJob::factory()->forManual($manual)->create();
    RenderJob::factory()->forManual($manual)->running()->create();
    RenderJob::factory()->forManual($manual)->failed()->create();

    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render))->toBeNull();
});

test('返した行は保持ポリシーの削除対象ではない (newerSucceededExists が false)', function (): void {
    $manual = VideoManual::factory()->create();
    RenderJob::factory()->forManual($manual)->succeeded('renders/v1.mp4')->create();
    RenderJob::factory()->forManual($manual)->succeeded('renders/v2.mp4')->create();

    $current = CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render);

    expect($current)->not->toBeNull();
    // 選択式と保持ポリシーの世代定義が一致すること (選んだ行の実体は消されない)
    expect(app(RenderJobService::class)->newerSucceededExists($current))->toBeFalse();
});
