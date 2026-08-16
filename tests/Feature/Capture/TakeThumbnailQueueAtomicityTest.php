<?php

declare(strict_types=1);

use App\DataTransferObjects\Capture\ObjectMetadataData;
use App\DataTransferObjects\Capture\TakeRegistrationInput;
use App\DataTransferObjects\Capture\UploadTicketClaims;
use App\Jobs\Capture\GenerateTakeThumbnailJob;
use App\Models\Cut;
use App\Models\Project;
use App\Models\Take;
use App\Models\TakeUploadReservation;
use App\Models\VideoManual;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Capture\TakeRegistrationService;
use App\Services\Capture\UploadTicketCodec;
use Illuminate\Support\Facades\DB;
use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;

/*
|--------------------------------------------------------------------------
| キュー投入の原子性 (サムネイル生成の投入経路。AGENTS.md ドメイン固有規約 11)
|--------------------------------------------------------------------------
|
| 主契約は tx level 観測 (baseline + 1 以上)。rollback テストは補助であり、
| dispatch の移設は検出しない (TakeDeletionQueueAtomicityTest と同じ但し書き)。
| 保証するのは「take 行を作ったのに生成 job が投入されない窓」の解消だけで、
| worker 停止 / ffmpeg 失敗 / S3 失敗ではサムネイルは付かない (誇張しない)。
*/

/**
 * 登録直前まで整えた一式 (Service を直接呼ぶ = HTTP 層を挟まず tx level を観測する)。
 *
 * @return array{Project, VideoManual, Cut, TakeUploadReservation, TakeRegistrationInput}
 */
function thumbnailQueueAtomicityContext(): array
{
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
    $cut = Cut::factory()->forManual($manual)->create();
    $reservation = TakeUploadReservation::factory()->forCut($cut)->create();
    $reservation->refresh();
    $ticket = app(UploadTicketCodec::class)->seal(UploadTicketClaims::fromReservation($reservation));

    $mock = Mockery::mock(TakeObjectStorage::class);
    $mock->shouldReceive('headObject')->andReturn(new ObjectMetadataData(
        contentLength: $reservation->size_bytes,
        contentType: $reservation->content_type,
        checksumSha256: $reservation->checksum_sha256,
    ));
    app()->instance(TakeObjectStorage::class, $mock);

    $input = new TakeRegistrationInput(
        ticket: $ticket,
        clientTakeId: $reservation->client_take_id,
        durationMs: 5_000,
        capturedAt: now()->toImmutable(),
    );

    return [$project, $manual, $cut, $reservation, $input];
}

test('テイク登録の GenerateTakeThumbnailJob は業務 tx の内側で投入される', function (): void {
    config()->set('queue.default', 'database');
    expect(config('queue.connections.database-media.after_commit'))->toBeFalse();

    [$project, $manual, $cut, , $input] = thumbnailQueueAtomicityContext();

    $baseline = DB::transactionLevel();
    $collector = RecordsJobQueueingTransactionLevel::capture(
        static fn () => app(TakeRegistrationService::class)->register($project, $manual, $cut, $input),
    );
    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), GenerateTakeThumbnailJob::class);

    expect($target)->toHaveCount(1);
    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
});

test('登録の外側 tx が rollback すると take 行も生成 job も残らない', function (): void {
    config()->set('queue.default', 'database');
    [$project, $manual, $cut, , $input] = thumbnailQueueAtomicityContext();
    $jobsBefore = DB::table('jobs')->count();

    try {
        DB::transaction(function () use ($project, $manual, $cut, $input): void {
            app(TakeRegistrationService::class)->register($project, $manual, $cut, $input);

            throw new RuntimeException('意図的な rollback');
        });
    } catch (RuntimeException) {
        // 期待どおり
    }

    expect(Take::query()->where('cut_id', $cut->id)->exists())->toBeFalse();
    expect(DB::table('jobs')->count())->toBe($jobsBefore);
});
