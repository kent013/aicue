<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\Render\ComposedLocalVideo;
use App\DataTransferObjects\Manual\Render\RenderClipSource;
use App\DataTransferObjects\Manual\Render\RenderManifest;
use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\MaterialType;
use App\Enums\Manual\RenderErrorCode;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\Security\ExternalCallKind;
use App\Exceptions\Manual\RenderCompositionException;
use App\Jobs\Manual\DeleteRenderOutputsJob;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Billing\TicketReservation;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RenderJob;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use App\Notifications\InApp\ManualRenderedNotification;
use App\Services\Billing\TicketLedgerService;
use App\Services\Manual\RenderJobService;
use App\Services\Manual\RenderPipeline;
use App\Services\Render\VideoComposer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * レンダパイプライン (RenderPipeline::run の直接呼び出し。§10.8-1/-6/-8 / 概念設計 §5):
 * - 成功パス: complete + commit + succeeded の原子化 (terminal tx)
 * - version 固定 (preview トリガー後の編集は scenario_version_changed で fail)
 * - チケット 2 フェーズ (再利用 / TTL 付け替え / 失敗 release / commit-wins /
 *   preview は台帳・予約が一切動かない)
 * - stale 先勝ち・失敗後始末 (S3 に出力を残さない)・世代交代の削除 job dispatch
 */

/** テスト用の fake composer (実 ffmpeg に触れない。container swap で注入する) */
final class FakeRenderComposer implements VideoComposer
{
    public ?RenderManifest $lastManifest = null;

    /** @var array<int, string> */
    public array $lastSources = [];

    /** compose 中に呼ばれる hook (stale 競合等のインターリーブ細工用) */
    public ?Closure $duringCompose = null;

    /** 非 null なら compose がこの例外を投げる */
    public ?Throwable $throws = null;

    public function compose(RenderManifest $manifest, array $localSources, string $workDir, callable $onClipComposed): ComposedLocalVideo
    {
        $this->lastManifest = $manifest;
        $this->lastSources = $localSources;
        if ($this->duringCompose !== null) {
            ($this->duringCompose)($manifest);
        }
        if ($this->throws !== null) {
            throw $this->throws;
        }

        $durations = [];
        foreach ($manifest->clips as $index => $clip) {
            $durations[$clip->cutId] = 1_000 * ($index + 1);
            $onClipComposed($index + 1, count($manifest->clips));
        }
        $localPath = "{$workDir}/output.mp4";
        file_put_contents($localPath, 'fake-mp4');

        return new ComposedLocalVideo($localPath, $durations, (int) array_sum($durations));
    }
}

/**
 * rendering 直前まで進めた render job 一式 (trigger 経由 = 実経路)。
 * $trigger = false は preview 専用シナリオ (render trigger を経ない。job 要素は null)。
 *
 * @return array{Organization, User, Project, VideoManual, Cut, RenderJob|null, FakeRenderComposer}
 */
function renderPipelineContext(int $tickets = 3, bool $trigger = true): array
{
    Queue::fake();
    Storage::fake('s3');
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Ready->value,
        'scenario_version' => 2,
    ]);
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create(['duration_ms' => 5_000]);
    $cut->forceFill(['adopted_take_id' => $take->id])->save();
    Storage::disk('s3')->put($take->video_path, 'fake-take-video');
    if ($tickets > 0) {
        app(TicketLedgerService::class)->grant($organization, $tickets, 'テスト残高');
    }

    $job = $trigger ? app(RenderJobService::class)->trigger($project, $manual) : null;

    $fake = new FakeRenderComposer;
    app()->instance(VideoComposer::class, $fake);

    return [$organization, $owner, $project, $manual, $cut, $job, $fake];
}

/** trigger 済み前提のテストで job を non-null に絞る */
function renderTriggeredJob(?RenderJob $job): RenderJob
{
    expect($job)->not->toBeNull();
    assert($job instanceof RenderJob);

    return $job;
}

test('成功パス: ready→rendering→published / cut_length_ms・total_length_ms 反映 / commit / output_path', function (): void {
    [$organization, , , $manual, $cut, $job, $fake] = renderPipelineContext();

    app(RenderPipeline::class)->run($job->id);

    // job: succeeded + progress 100 + output_path (version 付きキー)
    $job->refresh();
    expect($job->status)->toBe(JobStatus::Succeeded);
    expect($job->progress)->toBe(100);
    expect($job->output_path)->toBe(
        "projects/{$manual->project_id}/manuals/{$manual->id}/renders/v2-{$job->id}.mp4",
    );
    expect(Storage::disk('s3')->exists((string) $job->output_path))->toBeTrue();

    // manual: published + 実測尺反映
    $manual->refresh();
    expect($manual->status)->toBe(VideoManualStatus::Published);
    expect($manual->total_length_ms)->toBe(1_000);
    expect($cut->refresh()->cut_length_ms)->toBe(1_000);

    // 課金: 予約 committed / 残高 0 (COST_RENDER=3)
    $reservation = $job->ticketReservation;
    expect($reservation)->not->toBeNull();
    expect($reservation?->status)->toBe(TicketReservationStatus::Committed);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);

    // マニフェスト: 採用テイクの S3 素材がローカルへ供給されている
    expect($fake->lastManifest?->kind)->toBe(RenderKind::Render);
    expect($fake->lastSources)->toHaveKey($cut->id);
});

test('preview 成功: manual status 不変・台帳/予約とも一切動かない・previews/ 配下へ出力', function (): void {
    [$organization, , $project, $manual, , , $fake] = renderPipelineContext(tickets: 0, trigger: false);
    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);

    app(RenderPipeline::class)->run($previewJob->id);

    $previewJob->refresh();
    expect($previewJob->status)->toBe(JobStatus::Succeeded);
    expect($previewJob->output_path)->toBe(
        "projects/{$manual->project_id}/manuals/{$manual->id}/previews/v2-{$previewJob->id}.mp4",
    );
    expect(Storage::disk('s3')->exists((string) $previewJob->output_path))->toBeTrue();
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
    expect(TicketReservation::query()->count())->toBe(0);
    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
    expect($fake->lastManifest?->kind)->toBe(RenderKind::Preview);
});

test('preview: 採用テイク欠落 cut は Placeholder として合成される', function (): void {
    [, , $project, $manual, , , $fake] = renderPipelineContext(tickets: 0, trigger: false);
    $missing = Cut::factory()->forManual($manual)->withSortOrder(1)->create();
    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);

    app(RenderPipeline::class)->run($previewJob->id);

    expect($previewJob->refresh()->status)->toBe(JobStatus::Succeeded);
    $manifest = $fake->lastManifest;
    expect($manifest)->not->toBeNull();
    $placeholder = collect($manifest?->clips ?? [])->firstWhere('cutId', $missing->id);
    expect($placeholder?->source)->toBe(RenderClipSource::Placeholder);
    // Placeholder cut にはローカル素材が供給されない
    expect($fake->lastSources)->not->toHaveKey($missing->id);
});

test('Still カット (material_type=still) は TakeStill としてマニフェストへ載る (秒指定 + 未指定 fallback)', function (): void {
    // 未指定 still の尺は StillDisplayDuration が唯一の所在で、
    // manual.preview_placeholder_seconds (プレースホルダ尺) の流用は撤去済みである。
    // 別値を入れて「流用が復活していない」ことも同時に固定する。
    config()->set('manual.preview_placeholder_seconds', 3);
    config()->set('manual.default_still_display_seconds', 5);
    [, , $project, $manual, $cut, , $fake] = renderPipelineContext(tickets: 0, trigger: false);
    // 1 本目: 秒指定あり
    $cut->forceFill([
        'material_type' => MaterialType::Still->value,
        'static_display_seconds' => 4,
    ])->save();
    // 2 本目: 秒未指定 (static_display_seconds null → config fallback)
    $fallbackCut = Cut::factory()->forManual($manual)->withSortOrder(1)->create([
        'material_type' => MaterialType::Still->value,
        'static_display_seconds' => null,
    ]);
    $fallbackTake = Take::factory()->forCut($fallbackCut)->create();
    $fallbackCut->forceFill(['adopted_take_id' => $fallbackTake->id])->save();
    Storage::disk('s3')->put($fallbackTake->video_path, 'fake-take-video-2');
    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);

    app(RenderPipeline::class)->run($previewJob->id);

    expect($previewJob->refresh()->status)->toBe(JobStatus::Succeeded);
    $clips = collect($fake->lastManifest?->clips ?? []);
    $still = $clips->firstWhere('cutId', $cut->id);
    expect($still?->source)->toBe(RenderClipSource::TakeStill);
    expect($still?->stillDisplaySeconds)->toBe(4);
    $fallback = $clips->firstWhere('cutId', $fallbackCut->id);
    expect($fallback?->source)->toBe(RenderClipSource::TakeStill);
    expect($fallback?->stillDisplaySeconds)->toBe(5); // manual.default_still_display_seconds
    // Still でも採用テイク素材 (先頭フレーム抽出元) はローカル供給される
    expect($fake->lastSources)->toHaveKey($cut->id);
    expect($fake->lastSources)->toHaveKey($fallbackCut->id);
});

test('version 固定: preview トリガー後の編集は scenario_version_changed で fail (§10.8-6)', function (): void {
    [, , $project, $manual] = renderPipelineContext(tickets: 0, trigger: false);
    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);

    // トリガー後〜開始前にシナリオ保存 (version+1) が起きた状況
    $manual->forceFill(['scenario_version' => 3])->save();

    app(RenderPipeline::class)->run($previewJob->id);

    $previewJob->refresh();
    expect($previewJob->status)->toBe(JobStatus::Failed);
    expect($previewJob->error_code)->toBe(RenderErrorCode::ScenarioVersionChanged);
    expect($previewJob->error)->toContain('作り直して');
    // 出力は S3 に残らない (compose 前に fail)
    expect(Storage::disk('s3')->allFiles())->not->toContain(
        "projects/{$manual->project_id}/manuals/{$manual->id}/previews/v2-{$previewJob->id}.mp4",
    );
});

test('再配送で二重予約しない (有効な Reserved は再利用) + queued guard の no-op', function (): void {
    [$organization, , , , , $job] = renderPipelineContext();
    // 事前に予約を付けた queued job (前回試行が dispatch 直後に死んだ状況)
    $reservation = app(TicketLedgerService::class)->reserve($organization, 3);
    $job->ticketReservation()->associate($reservation);
    $job->save();

    app(RenderPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
    expect(TicketReservation::query()->count())->toBe(1); // reserve が増えない
    expect($job->ticket_reservation_id)->toBe($reservation->id);

    // succeeded 済み job の再配送は no-op (queued guard)
    app(RenderPipeline::class)->run($job->id);
    expect(TicketReservation::query()->count())->toBe(1);
});

test('TTL 切れ Reserved は release して付け替え、新予約で完走する', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:00:00'));
    [$organization, , , , , $job] = renderPipelineContext(tickets: 6);
    $stale = app(TicketLedgerService::class)->reserve($organization, 3);
    $job->ticketReservation()->associate($stale);
    $job->save();

    // TTL (30 分) を超過させる (cron 未回収の失効 Reserved)
    $this->travelTo(CarbonImmutable::parse('2026-07-11 00:31:00'));
    app(RenderPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Succeeded);
    expect($job->ticket_reservation_id)->not->toBe($stale->id);
    expect($stale->refresh()->status)->toBe(TicketReservationStatus::Released);
    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Committed);
});

test('compose 失敗: failed + error_code=internal / rendering→ready 復帰 / release / 出力を残さない', function (): void {
    [, , , $manual, , $job, $fake] = renderPipelineContext();
    $fake->throws = new RenderCompositionException('ffmpeg failed (compose clip): boom');

    app(RenderPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error_code)->toBe(RenderErrorCode::Internal);
    expect($job->error)->toBe('書き出しに失敗しました。時間をおいて再実行してください。');
    expect($job->output_path)->toBeNull();
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
    expect($job->ticketReservation?->status)->toBe(TicketReservationStatus::Released);
    // 非共存: failed ∧ committed を作らない
    expect(TicketReservation::query()->where('status', TicketReservationStatus::Committed)->count())->toBe(0);
    // レンダ出力が S3 に残らない (アップロード前に fail)
    expect(Storage::disk('s3')->allFiles())->toBe([
        Take::query()->firstOrFail()->video_path, // 素材のみ
    ]);
});

test('残高不足で startJob が失敗 → failed (予約なし・rendering→ready 復帰)', function (): void {
    [$organization, , , $manual, , $job] = renderPipelineContext();
    // trigger 後に残高が消えた状況 (別 manual の消費等) を負の grant はできないため reserve で再現
    app(TicketLedgerService::class)->reserve($organization, 3); // 残高 0 に拘束

    app(RenderPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->error)->toContain('チケット残高が不足しています');
    expect($job->ticket_reservation_id)->toBeNull();
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
});

test('stale 先勝ち: compose 中に failJob された pipeline は succeeded/commit されず出力も残さない', function (): void {
    [, , , $manual, , $job, $fake] = renderPipelineContext();
    $fake->duringCompose = function () use ($job): void {
        // stale 回復 cron 相当が compose 中に失敗確定する (行ロックは compose 中保持されない)
        app(RenderJobService::class)->failJob(
            $job->refresh(),
            RenderErrorCode::Timeout,
            '書き出しがタイムアウトしました。再実行してください。',
        );
    };

    app(RenderPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed); // succeeded に上書きされない
    expect($job->error_code)->toBe(RenderErrorCode::Timeout);
    expect($job->output_path)->toBeNull();
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
    // 無課金 succeeded / 課金済み failed の排除: commit されない
    expect(TicketReservation::query()->where('status', TicketReservationStatus::Committed)->count())->toBe(0);
    // アップロード済み出力は後始末で削除される
    $expectedKey = "projects/{$manual->project_id}/manuals/{$manual->id}/renders/v2-{$job->id}.mp4";
    expect(Storage::disk('s3')->exists($expectedKey))->toBeFalse();
});

test('finalize 直前に released でも commit-wins で完走し課金される (無課金 succeeded を作らない)', function (): void {
    // P5 commit-wins: 守る不変条件は「succeeded ∧ released の非共存」ではなく
    // 「succeeded ∧ 無課金 (= 成果物を渡してタダ乗り) の非共存」。予約 status が Released でも
    // 台帳に消費行が立てば課金は成立する (課金の真実源は台帳。status は一方向遷移を壊さない)
    [, , , $manual, $cut, $job, $fake] = renderPipelineContext();
    $fake->duringCompose = function () use ($job): void {
        // finalize 前に予約が滞留回収で解放される競合を細工
        $reservation = $job->refresh()->ticketReservation;
        if ($reservation !== null) {
            app(TicketLedgerService::class)->release($reservation);
        }
    };

    app(RenderPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Succeeded);
    $manual->refresh();
    expect($manual->status)->toBe(VideoManualStatus::Published);
    expect($cut->refresh()->cut_length_ms)->not->toBeNull();
    // 非共存: succeeded なのに無課金、にはならない (消費行が立っている)
    $reservation = $job->ticketReservation;
    expect($reservation)->not->toBeNull();
    expect(TicketLedgerEntry::query()
        ->where('reservation_id', $reservation?->getKey())
        ->where('kind', TicketLedgerKind::ReserveCommit)
        ->count())->toBe(1);
    // 一方向遷移は壊さない (Released → Committed へは戻さない)
    expect($reservation?->refresh()->status)->toBe(TicketReservationStatus::Released);
});

test('世代交代: 再レンダ成功で旧 job id の DeleteRenderOutputsJob が dispatch される', function (): void {
    [, , $project, $manual, , $job] = renderPipelineContext(tickets: 6);
    $job = renderTriggeredJob($job);
    app(RenderPipeline::class)->run($job->id);
    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);

    // 編集で published→ready に戻し (version+1)、再レンダ
    $manual->refresh()->forceFill([
        'status' => VideoManualStatus::Ready,
        'scenario_version' => 3,
    ])->save();
    $second = app(RenderJobService::class)->trigger($project, $manual);
    app(RenderPipeline::class)->run($second->id);
    expect($second->refresh()->status)->toBe(JobStatus::Succeeded);

    Queue::assertPushed(
        DeleteRenderOutputsJob::class,
        fn (DeleteRenderOutputsJob $pushed): bool => $pushed->renderJobId === $job->id,
    );
});

test('rendering 中の scenario 保存は 409 (既存 guard との整合を再確認)', function (): void {
    [, $owner, $project, $manual] = renderPipelineContext();
    // trigger 済み = manual は rendering
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Rendering);

    $this->actingAs($owner)->putJson(
        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
        ['expected_version' => 2, 'steps' => []],
    )->assertConflict()->assertJson(['conflict_type' => 'rendering']);
});

/*
 * ─────────────────────────────────────────────────────────────────────
 * T131 / S3: 所有権再検証 (preflight suppression) + 終端後の進捗書き戻し禁止
 * (裁定 AG-082。詳細設計 devnotes/20260807-1235-job-execution-dedup)
 * ─────────────────────────────────────────────────────────────────────
 */

/** stale 回復 cron が先着で書く文言 (preflight 経路が上書きしないことの固定に使う) */
const RENDER_STALE_CRON_ERROR_MESSAGE = 'stale 回復 cron が失敗確定しました。';

test('preflight: compose 中に cron が failed 化 → S3 へ 1 件も PUT しない', function (): void {
    [, , , $manual, , $job, $fake] = renderPipelineContext();
    $job = renderTriggeredJob($job);
    $fake->duringCompose = function () use ($job): void {
        app(RenderJobService::class)->failJob(
            $job->refresh(),
            RenderErrorCode::Timeout,
            RENDER_STALE_CRON_ERROR_MESSAGE,
        );
    };

    app(RenderPipeline::class)->run($job->id);

    $expectedKey = "projects/{$manual->project_id}/manuals/{$manual->id}/renders/v2-{$job->id}.mp4";
    Storage::disk('s3')->assertMissing($expectedKey);
    // 素材以外は S3 に増えていない (アップロード → 後始末削除ですらない = そもそも PUT していない)
    expect(Storage::disk('s3')->allFiles())->toBe([Take::query()->firstOrFail()->video_path]);

    $job->refresh();
    expect($job->output_path)->toBeNull();
    expect($job->status)->toBe(JobStatus::Failed);
});

test('preflight: cron failed 後に step / progress が旧ワーカーから書き戻されない', function (): void {
    [, , , , , $job, $fake] = renderPipelineContext();
    $job = renderTriggeredJob($job);
    /** @var array{step: mixed, progress: ?int, error: ?string, error_code: mixed, updated_at: string} $snapshot */
    $snapshot = ['step' => null, 'progress' => null, 'error' => null, 'error_code' => null, 'updated_at' => ''];

    $fake->duringCompose = function () use ($job, &$snapshot): void {
        app(RenderJobService::class)->failJob(
            $job->refresh(),
            RenderErrorCode::Timeout,
            RENDER_STALE_CRON_ERROR_MESSAGE,
        );
        $after = RenderJob::query()->findOrFail($job->id);
        $snapshot = [
            'step' => $after->step,
            'progress' => $after->progress,
            'error' => $after->error,
            'error_code' => $after->error_code,
            'updated_at' => (string) $after->updated_at?->toJSON(),
        ];
    };

    app(RenderPipeline::class)->run($job->id);

    $job->refresh();
    // onClipComposed / concat の進捗書き戻しが terminal 行へ 1 バイトも入らない
    expect($job->step)->toBe($snapshot['step']);
    expect($job->progress)->toBe($snapshot['progress']);
    expect((string) $job->updated_at?->toJSON())->toBe($snapshot['updated_at']);
    // failJob が二重に走らない (error / error_code が cron の値のまま)
    expect($job->error)->toBe($snapshot['error']);
    expect($job->error_code)->toBe($snapshot['error_code']);
    expect($job->error)->toBe(RENDER_STALE_CRON_ERROR_MESSAGE);
    expect($job->error_code)->toBe(RenderErrorCode::Timeout);
});

test('preflight: 所有権喪失時に work dir が削除される (finally を通る)', function (): void {
    [, , , , , $job, $fake] = renderPipelineContext();
    $job = renderTriggeredJob($job);
    $fake->duringCompose = function () use ($job): void {
        app(RenderJobService::class)->failJob(
            $job->refresh(),
            RenderErrorCode::Timeout,
            RENDER_STALE_CRON_ERROR_MESSAGE,
        );
    };

    app(RenderPipeline::class)->run($job->id);

    expect(is_dir(storage_path("app/render/{$job->id}")))->toBeFalse();
});

test('preflight: preview (kind=preview) でも同じく PUT しない', function (): void {
    [, , $project, $manual, , , $fake] = renderPipelineContext(tickets: 0, trigger: false);
    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);
    $fake->duringCompose = function () use ($previewJob): void {
        app(RenderJobService::class)->failJob(
            $previewJob->refresh(),
            RenderErrorCode::Timeout,
            RENDER_STALE_CRON_ERROR_MESSAGE,
        );
    };

    app(RenderPipeline::class)->run($previewJob->id);

    $expectedKey = "projects/{$manual->project_id}/manuals/{$manual->id}/previews/v2-{$previewJob->id}.mp4";
    Storage::disk('s3')->assertMissing($expectedKey);
    expect($previewJob->refresh()->output_path)->toBeNull();
});

test('preflight: 所有権喪失は固定 event 名で warning ログに出る', function (): void {
    Log::spy();
    [, , , , , $job, $fake] = renderPipelineContext();
    $job = renderTriggeredJob($job);
    $fake->duringCompose = function () use ($job): void {
        app(RenderJobService::class)->failJob(
            $job->refresh(),
            RenderErrorCode::Timeout,
            RENDER_STALE_CRON_ERROR_MESSAGE,
        );
    };

    app(RenderPipeline::class)->run($job->id);

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use ($job): bool {
            return ($context['event'] ?? null) === ExternalCallKind::LOG_EVENT
                && ($context['job_type'] ?? null) === RenderJob::class
                && ($context['job_id'] ?? null) === $job->id
                && ($context['expected_status'] ?? null) === 'running'
                && ($context['actual_status'] ?? null) === 'failed'
                && ($context['stage'] ?? null) === 'concat'
                && ($context['external_call'] ?? null) === ExternalCallKind::ObjectStoragePut->value;
        })
        ->once();
});

test('preflight: 所有権喪失時に完了通知が二重に飛ばない (先着の cron 分だけ)', function (): void {
    Notification::fake();
    [, $owner, , , , $job, $fake] = renderPipelineContext();
    $job = renderTriggeredJob($job);
    // 通知宛先を実在させる (宛先ゼロだと「飛んでいない」ことの検査が空振りする)
    $job->triggeredBy()->associate($owner);
    $job->save();
    $fake->duringCompose = function () use ($job): void {
        app(RenderJobService::class)->failJob(
            $job->refresh(),
            RenderErrorCode::Timeout,
            RENDER_STALE_CRON_ERROR_MESSAGE,
        );
    };

    app(RenderPipeline::class)->run($job->id);

    Notification::assertSentTimes(ManualRenderedNotification::class, 1);
});

test('preflight: 所有権喪失時に DeleteRenderOutputsJob が dispatch されない', function (): void {
    [, , , , , $job, $fake] = renderPipelineContext();
    $job = renderTriggeredJob($job);
    $fake->duringCompose = function () use ($job): void {
        app(RenderJobService::class)->failJob(
            $job->refresh(),
            RenderErrorCode::Timeout,
            RENDER_STALE_CRON_ERROR_MESSAGE,
        );
    };

    app(RenderPipeline::class)->run($job->id);

    Queue::assertNotPushed(DeleteRenderOutputsJob::class);
});

test('preflight: 行が消えていても所有権喪失として扱う (deny-by-default)', function (): void {
    [, , , , , $job, $fake] = renderPipelineContext();
    $job = renderTriggeredJob($job);
    $fake->duringCompose = function () use ($job): void {
        RenderJob::query()->whereKey($job->id)->delete();
    };

    app(RenderPipeline::class)->run($job->id);

    expect(RenderJob::query()->whereKey($job->id)->exists())->toBeFalse();
    expect(Storage::disk('s3')->allFiles())->toBe([Take::query()->firstOrFail()->video_path]);
});

test('素材のローカル名は拡張子なし (src{index}) で落ちる', function (): void {
    // 拡張子は以前から既に嘘だった (webm / mov も .mp4 という名前で落ちていた)。
    // 画像素材を足すにあたって嘘を増やさないため、名前から拡張子ごと外している。
    // 合成が ffmpeg の内容プローブに依存している事実を名前でも表す。
    [, , $project, $manual, $cut, , $fake] = renderPipelineContext(tickets: 0, trigger: false);
    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);

    app(RenderPipeline::class)->run($previewJob->id);

    expect($fake->lastSources[$cut->id] ?? null)->not->toBeNull();
    expect(basename((string) $fake->lastSources[$cut->id]))->toBe('src0');
});
