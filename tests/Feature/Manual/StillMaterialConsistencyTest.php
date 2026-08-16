<?php

declare(strict_types=1);

use App\DataTransferObjects\Capture\ObjectMetadataData;
use App\DataTransferObjects\Capture\PresignedUploadData;
use App\DataTransferObjects\Capture\UploadTicketClaims;
use App\DataTransferObjects\Manual\Render\ComposedLocalVideo;
use App\DataTransferObjects\Manual\Render\RenderClipSource;
use App\DataTransferObjects\Manual\Render\RenderManifest;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\MaterialType;
use App\Enums\Manual\VideoManualStatus;
use App\Models\Cut;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Take;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\TicketLedgerService;
use App\Services\Capture\CaptureTakeService;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Capture\UploadTicketCodec;
use App\Services\Manual\RenderJobService;
use App\Services\Manual\RenderPipeline;
use App\Services\Render\VideoComposer;
use Carbon\CarbonImmutable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
 * 静止画素材の通し (詳細設計 S8 の組み合わせ表)。
 *
 * 本ファイルが固定するもの:
 * - **C1 (still/still) の通し**: presign が .jpg のキーを作る → 登録が still で確定する →
 *   採用 → マニフェストが TakeStill になる (S1 / S2 / S3 の接続点。末尾のテスト)
 * - **C5**: 採用後に cut.material_type を video へ戻しても、実体が画像なら
 *   (a) マニフェストは TakeStill (b) 尺ゲートも静止画の尺で数える
 * - **誤申告** (video と申告して画像を置く) は ffprobe が尺を取れず**失敗ジョブ**になる。
 *   壊れた成果物を出さず、後続ジョブは処理できる
 *
 * C2 (still/video) と C3 (video/video) は既存挙動そのままなので、既存の
 * RenderPipelineTest / RenderTriggerTest が持つ回帰テストに委ねる (ここでは重複させない)。
 *
 * 誤申告の帰結は**向きによって非対称**である。「still と申告して動画を置いた」場合は
 * 先頭フレーム抽出で成功しうる (C2 と同じ経路で害が無い) ため、題材にしない。
 */

/** 実 ffmpeg に触れない composer (container swap で注入する。本ファイル専用) */
final class StillConsistencyComposer implements VideoComposer
{
    public ?RenderManifest $lastManifest = null;

    public function compose(RenderManifest $manifest, array $localSources, string $workDir, callable $onClipComposed): ComposedLocalVideo
    {
        $this->lastManifest = $manifest;
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
 * cut の計画と take の実体を任意に組める文脈 (ticket 付与済み)。
 *
 * @return array{Organization, User, Project, VideoManual, Cut, Take}
 */
function stillConsistencyContext(?MaterialType $planned, MaterialType $actual): array
{
    Queue::fake();
    Storage::fake('s3');
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Ready->value,
        'scenario_version' => 2,
    ]);
    $cut = Cut::factory()->forManual($manual)->create([
        'material_type' => $planned?->value,
        'static_display_seconds' => null,
    ]);
    $take = $actual === MaterialType::Still
        ? Take::factory()->forCut($cut)->still()->create()
        : Take::factory()->forCut($cut)->create(['duration_ms' => 5_000]);
    $cut->forceFill(['adopted_take_id' => $take->id])->save();
    Storage::disk('s3')->put($take->video_path, 'fake-take-bytes');
    app(TicketLedgerService::class)->grant($organization, 5, 'テスト残高');

    return [$organization, $owner, $project, $manual, $cut, $take];
}

test('C5: cut=video / take=still でもマニフェストは TakeStill になり、尺は既定の静止画尺になる', function (): void {
    config()->set('manual.default_still_display_seconds', 5);
    [, , $project, $manual, $cut] = stillConsistencyContext(MaterialType::Video, MaterialType::Still);
    $fake = new StillConsistencyComposer;
    app()->instance(VideoComposer::class, $fake);
    $job = app(RenderJobService::class)->trigger($project, $manual);

    app(RenderPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
    $clip = collect($fake->lastManifest?->clips ?? [])->firstWhere('cutId', $cut->id);
    expect($clip?->source)->toBe(RenderClipSource::TakeStill);
    expect($clip?->stillDisplaySeconds)->toBe(5);
});

test('C5: 尺ゲートも静止画の尺で数える (duration_ms 欠落の既定 60 秒に落ちない)', function (): void {
    // 上限を 10 秒に絞る。旧実装は cut.material_type が video なので
    // `duration_ms ?? render_default_take_duration_ms` = 60 秒として数え、ここで 422 になっていた。
    // 実効判定を通す今は 5 秒として数えるためトリガーできる = レンダの実尺と一致する。
    config()->set('manual.default_still_display_seconds', 5);
    config()->set('manual.render_max_total_source_ms', 10_000);
    config()->set('manual.render_default_take_duration_ms', 60_000);
    [, , $project, $manual, $cut, $take] = stillConsistencyContext(MaterialType::Video, MaterialType::Still);
    expect($take->duration_ms)->toBeNull();
    expect($cut->material_type)->toBe(MaterialType::Video);
    app()->instance(VideoComposer::class, new StillConsistencyComposer);

    $job = app(RenderJobService::class)->trigger($project, $manual);

    expect($job->status)->toBe(JobStatus::Queued);
});

test('尺ゲートの回帰: 動画テイクは従来どおり duration_ms で数える', function (): void {
    config()->set('manual.render_max_total_source_ms', 4_000);
    [, , $project, $manual] = stillConsistencyContext(MaterialType::Video, MaterialType::Video);
    app()->instance(VideoComposer::class, new StillConsistencyComposer);

    expect(fn () => app(RenderJobService::class)->trigger($project, $manual))
        ->toThrow(ValidationException::class, '合計尺が上限を超えています');
});

test('video と申告して画像を置いたテイクは失敗ジョブになり、壊れた成果物を残さない', function (): void {
    // material_type=video のまま実体が画像 → planTakeVideo → probeDurationMs の ffprobe が
    // format=duration を数値で返せない。実バイナリには依存せず Process::fake で再現する。
    [, , $project, $manual] = stillConsistencyContext(MaterialType::Video, MaterialType::Video);
    Process::fake(function (PendingProcess $process) {
        $command = $process->command;
        $line = is_array($command) ? implode(' ', array_map(strval(...), $command)) : (string) $command;
        if (str_contains($line, '-show_entries')) {
            return Process::result(output: "N/A\n"); // 画像には尺が無い
        }

        return Process::result(output: '');
    });
    $job = app(RenderJobService::class)->trigger($project, $manual);

    app(RenderPipeline::class)->run($job->id);

    $job->refresh();
    expect($job->status)->toBe(JobStatus::Failed);
    expect($job->output_path)->toBeNull();
    // compose 失敗地点では upload() 自体が未実行 = 出力オブジェクトはそもそも生まれない
    // (孤児削除は finalize 失敗の別契約であり、ここでは期待しない)
    expect(Storage::disk('s3')->allFiles())->not->toContain(
        "projects/{$manual->project_id}/manuals/{$manual->id}/renders/v2-{$job->id}.mp4",
    );
    // rendering に取り残さない (編集をブロックし続けない)
    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
});

test('失敗ジョブの後でも別のレンダジョブは正常に完了できる', function (): void {
    [, , $project, $manual] = stillConsistencyContext(MaterialType::Video, MaterialType::Video);
    Process::fake(fn (PendingProcess $process) => Process::result(output: "N/A\n"));
    $failing = app(RenderJobService::class)->trigger($project, $manual);
    app(RenderPipeline::class)->run($failing->id);
    expect($failing->refresh()->status)->toBe(JobStatus::Failed);

    // 2 本目は正常な composer で走らせる (キューが詰まらないことの確認)
    app()->instance(VideoComposer::class, new StillConsistencyComposer);
    $second = app(RenderJobService::class)->trigger($project, $manual->refresh());

    app(RenderPipeline::class)->run($second->id);

    expect($second->refresh()->status)->toBe(JobStatus::Succeeded);
    expect($second->output_path)->not->toBeNull();
});

test('C1 通し: 静止画の presign → 登録 → 採用 → マニフェストが TakeStill になる', function (): void {
    // S1 (material_type 列) / S2 (受け入れと確定) / S3 (実効判定と尺) の接続点を 1 本で通す。
    // presigned PUT の実体は持てないので、HeadObject 照合だけを予約行と一致させて模す。
    config()->set('manual.default_still_display_seconds', 5);
    Queue::fake();
    Storage::fake('s3');
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create([
        'status' => VideoManualStatus::Ready->value,
        'scenario_version' => 2,
    ]);
    $cut = Cut::factory()->forManual($manual)->create([
        'material_type' => MaterialType::Still->value,
        'static_display_seconds' => null,
    ]);
    app(TicketLedgerService::class)->grant($organization, 5, 'テスト残高');

    // 1) presign: still カットへ image/jpeg → 予約行の S3 キーが .jpg になる
    $storage = Mockery::mock(TakeObjectStorage::class);
    app()->instance(TakeObjectStorage::class, $storage);
    $storage->shouldReceive('presignUpload')->andReturn(new PresignedUploadData(
        url: 'https://s3.fake.test/bucket/key?X-Amz-Signature=sig',
        headers: ['Content-Type' => 'image/jpeg'],
        expiresAt: CarbonImmutable::now()->addMinutes(30),
    ));
    $clientTakeId = strtoupper((string) Str::ulid());
    $checksum = base64_encode(hash('sha256', 'jpeg-bytes', true));
    $this->actingAs($owner)->postJson(
        "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/upload-url",
        [
            'client_take_id' => $clientTakeId,
            'size_bytes' => 120_000,
            'content_type' => 'image/jpeg',
            'checksum_sha256' => $checksum,
        ],
    )->assertOk();
    $reservation = $cut->uploadReservations()->sole();
    expect($reservation->video_path)->toEndWith('.jpg');

    // 2) 登録: HeadObject を予約行と一致させ、素材を置いてから確定させる
    Storage::disk('s3')->put($reservation->video_path, 'jpeg-bytes');
    $storage->shouldReceive('headObject')->with($reservation->video_path)->andReturn(
        new ObjectMetadataData(
            contentLength: $reservation->size_bytes,
            contentType: $reservation->content_type,
            checksumSha256: $reservation->checksum_sha256,
        ),
    );
    $ticket = app(UploadTicketCodec::class)->seal(
        UploadTicketClaims::fromReservation($reservation->refresh()),
    );
    $this->actingAs($owner)->postJson(
        "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes",
        ['ticket' => $ticket, 'client_take_id' => $clientTakeId, 'duration_ms' => 3_000],
    )->assertCreated();
    $take = $cut->takes()->sole();
    expect($take->material_type)->toBe(MaterialType::Still);
    expect($take->duration_ms)->toBeNull();

    // 3) 採用 → レンダ: マニフェストが TakeStill + 既定の静止画尺になる
    app(CaptureTakeService::class)->adopt($project, $manual, $cut, $take);
    $fake = new StillConsistencyComposer;
    app()->instance(VideoComposer::class, $fake);
    $job = app(RenderJobService::class)->trigger($project, $manual->refresh());

    app(RenderPipeline::class)->run($job->id);

    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
    $clip = collect($fake->lastManifest?->clips ?? [])->firstWhere('cutId', $cut->id);
    expect($clip?->source)->toBe(RenderClipSource::TakeStill);
    expect($clip?->takeSourcePath)->toBe($take->video_path);
    expect($clip?->stillDisplaySeconds)->toBe(5);
});
