<?php

declare(strict_types=1);

use App\Enums\Manual\MaterialType;
use App\Enums\Manual\TakeStatus;
use App\Enums\Security\ExternalCallKind;
use App\Exceptions\Capture\TakeThumbnailExtractionException;
use App\Jobs\Capture\GenerateTakeThumbnailJob;
use App\Models\Cut;
use App\Models\Project;
use App\Models\Take;
use App\Models\VideoManual;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Capture\TakeThumbnailExtractor;
use App\Services\Capture\TakeThumbnailPipeline;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
 * サムネイル生成パイプライン (S3 GET → ffmpeg → S3 PUT → 条件付き UPDATE)。
 *
 * 固定する契約:
 * - 決定的 S3 キー + image/jpeg + thumbnail_size_bytes = 出力サイズ
 * - 冪等 (2 回目は extractor を呼ばない) / ready でない・削除済みは no-op
 * - **preflight の配置**: 抽出中に所有権を失うと upload() が 1 回も呼ばれない
 *   (目録 gate が保証しない「配置」を behavioral に固定する)
 * - preflight 通過後・UPDATE 前に先着されたら UPDATE は 0 行で、**オブジェクトは消さない**
 * - work dir は実行ごとに一意で、正常・異常いずれも finally で消える
 */

/** 抽出中の細工フックを持つ fake extractor (実 ffmpeg に触れない) */
final class ThumbnailPipelineFakeExtractor implements TakeThumbnailExtractor
{
    public int $calls = 0;

    /** 抽出中に呼ばれる hook (先着・削除等のインターリーブ細工用) */
    public ?Closure $duringExtract = null;

    /** 非 null なら extract がこの例外を投げる */
    public ?Throwable $throws = null;

    public string $bytes = 'jpeg-bytes-1234567890';

    /** @var list<string> 実行ごとの作業ディレクトリ */
    public array $workDirs = [];

    /** @var list<MaterialType> extract が受け取った素材種別 */
    public array $materials = [];

    public function extract(string $localSourcePath, string $localThumbnailPath, MaterialType $material): void
    {
        $this->calls++;
        $this->materials[] = $material;
        $this->workDirs[] = dirname($localThumbnailPath);
        if ($this->duringExtract !== null) {
            ($this->duringExtract)();
        }
        if ($this->throws !== null) {
            throw $this->throws;
        }
        file_put_contents($localThumbnailPath, $this->bytes);
    }
}

/** upload / downloadToLocal の呼び出しを記録する storage (実体は Storage::fake('s3')) */
final class ThumbnailPipelineRecordingStorage extends TakeObjectStorage
{
    public int $downloadCalls = 0;

    /** @var list<array{path: string, contentType: string}> */
    public array $uploads = [];

    /** upload の**直前**に呼ばれる hook (PUT〜UPDATE 間の先着を作る) */
    public ?Closure $duringUpload = null;

    public function downloadToLocal(string $path, string $localPath): void
    {
        $this->downloadCalls++;
        parent::downloadToLocal($path, $localPath);
    }

    public function upload(string $localPath, string $path, string $contentType): void
    {
        $this->uploads[] = ['path' => $path, 'contentType' => $contentType];
        if ($this->duringUpload !== null) {
            ($this->duringUpload)();
        }
        parent::upload($localPath, $path, $contentType);
    }
}

/**
 * 生成対象のテイク一式 + container へ差し込んだ fake。
 *
 * @return array{Take, Cut, VideoManual, ThumbnailPipelineFakeExtractor, ThumbnailPipelineRecordingStorage}
 */
function thumbnailPipelineContext(string $status = 'ready'): array
{
    Storage::fake('s3');
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->create(['status' => $status]);
    Storage::disk('s3')->put($take->video_path, 'fake-take-video');

    $extractor = new ThumbnailPipelineFakeExtractor;
    $storage = new ThumbnailPipelineRecordingStorage;
    app()->instance(TakeThumbnailExtractor::class, $extractor);
    app()->instance(TakeObjectStorage::class, $storage);

    return [$take, $cut, $manual, $extractor, $storage];
}

/** 対象テイクの決定的な S3 キー */
function expectedThumbnailKey(Take $take, Cut $cut, VideoManual $manual): string
{
    return "projects/{$manual->project_id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/thumbnails/{$take->id}.jpg";
}

test('成功: 決定的キーで PUT し thumbnail_path / thumbnail_size_bytes を確定する', function (): void {
    [$take, $cut, $manual, $extractor, $storage] = thumbnailPipelineContext();

    app(TakeThumbnailPipeline::class)->run($take->id);

    $key = expectedThumbnailKey($take, $cut, $manual);
    $take->refresh();
    expect($take->thumbnail_path)->toBe($key);
    expect($take->thumbnail_size_bytes)->toBe(strlen($extractor->bytes));
    expect($take->status)->toBe(TakeStatus::Ready);

    expect($storage->uploads)->toHaveCount(1);
    expect($storage->uploads[0]['path'])->toBe($key);
    expect($storage->uploads[0]['contentType'])->toBe('image/jpeg');
    expect(Storage::disk('s3')->exists($key))->toBeTrue();
});

test('冪等: 2 回目の実行は extractor も storage も呼ばず 1 回目の値を保つ', function (): void {
    [$take, , , $extractor, $storage] = thumbnailPipelineContext();

    app(TakeThumbnailPipeline::class)->run($take->id);
    $first = $take->fresh();
    expect($first?->thumbnail_path)->not->toBeNull();

    app(TakeThumbnailPipeline::class)->run($take->id);

    expect($extractor->calls)->toBe(1);
    expect($storage->downloadCalls)->toBe(1);
    expect($storage->uploads)->toHaveCount(1);
    expect($take->fresh()?->thumbnail_path)->toBe($first?->thumbnail_path);
});

test('ready でないテイクでは extractor も storage も 1 回も呼ばれない', function (string $status): void {
    [$take, , , $extractor, $storage] = thumbnailPipelineContext($status);

    app(TakeThumbnailPipeline::class)->run($take->id);

    expect($extractor->calls)->toBe(0);
    expect($storage->downloadCalls)->toBe(0);
    expect($storage->uploads)->toBe([]);
    expect($take->fresh()?->thumbnail_path)->toBeNull();
})->with(['uploading', 'processing', 'failed']);

test('テイク行が削除済みなら no-op (例外を投げない)', function (): void {
    [$take, , , $extractor, $storage] = thumbnailPipelineContext();
    $takeId = $take->id;
    $take->delete();

    app(TakeThumbnailPipeline::class)->run($takeId);

    expect($extractor->calls)->toBe(0);
    expect($storage->uploads)->toBe([]);
});

test('preflight の配置: 抽出中にテイクが消えると upload が 1 回も呼ばれず抑止ログが出る', function (): void {
    Log::spy();
    [$take, , , $extractor, $storage] = thumbnailPipelineContext();
    $takeId = $take->id;
    $extractor->duringExtract = function () use ($takeId): void {
        Take::query()->whereKey($takeId)->delete();
    };

    app(TakeThumbnailPipeline::class)->run($takeId);

    expect($extractor->calls)->toBe(1);
    expect($storage->uploads)->toBe([]); // 取り消せない S3 PUT は 1 回も起きない
    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use ($takeId): bool {
            return ($context['event'] ?? null) === ExternalCallKind::LOG_EVENT
                && ($context['job_type'] ?? null) === Take::class
                && ($context['job_id'] ?? null) === $takeId
                && ($context['expected_status'] ?? null) === 'ready'
                && array_key_exists('actual_status', $context) && $context['actual_status'] === null
                && ($context['stage'] ?? null) === 'thumbnail_upload'
                && ($context['external_call'] ?? null) === ExternalCallKind::ObjectStoragePut->value
                && ($context['thumbnail_present'] ?? null) === false;
        })
        ->once();
});

test('preflight の配置: 抽出中に先着されると upload が呼ばれず先着の値が保たれる', function (): void {
    [$take, , , $extractor, $storage] = thumbnailPipelineContext();
    $takeId = $take->id;
    $extractor->duringExtract = function () use ($takeId): void {
        Take::query()->whereKey($takeId)->update([
            'thumbnail_path' => 'winner/thumbnail.jpg',
            'thumbnail_size_bytes' => 123,
        ]);
    };

    app(TakeThumbnailPipeline::class)->run($takeId);

    expect($storage->uploads)->toBe([]);
    $take->refresh();
    expect($take->thumbnail_path)->toBe('winner/thumbnail.jpg');
    expect($take->thumbnail_size_bytes)->toBe(123);
});

test('preflight 通過後・UPDATE 前の先着では PUT は行われるが UPDATE が 0 行でオブジェクトも消さない', function (): void {
    [$take, $cut, $manual, , $storage] = thumbnailPipelineContext();
    $takeId = $take->id;
    $storage->duringUpload = function () use ($takeId): void {
        Take::query()->whereKey($takeId)->update([
            'thumbnail_path' => 'winner/thumbnail.jpg',
            'thumbnail_size_bytes' => 456,
        ]);
    };

    app(TakeThumbnailPipeline::class)->run($takeId);

    expect($storage->uploads)->toHaveCount(1);
    $take->refresh();
    // 先着の値が保たれる (0 行更新)
    expect($take->thumbnail_path)->toBe('winner/thumbnail.jpg');
    expect($take->thumbnail_size_bytes)->toBe(456);
    // ★ キーが決定的なので敗者はオブジェクトを消してはいけない (消すと勝者の実体を壊す)
    expect(Storage::disk('s3')->exists(expectedThumbnailKey($take, $cut, $manual)))->toBeTrue();
});

test('抽出失敗: take は ready のまま thumbnail_path は null で work dir が残らない', function (): void {
    [$take, , , $extractor, $storage] = thumbnailPipelineContext();
    $extractor->throws = new TakeThumbnailExtractionException('ffmpeg produced no frame (seek=0ms)');

    expect(fn () => app(TakeThumbnailPipeline::class)->run($take->id))
        ->toThrow(TakeThumbnailExtractionException::class);

    $take->refresh();
    expect($take->status)->toBe(TakeStatus::Ready);
    expect($take->thumbnail_path)->toBeNull();
    expect($storage->uploads)->toBe([]);
    expect($extractor->workDirs)->toHaveCount(1);
    expect(File::isDirectory($extractor->workDirs[0]))->toBeFalse(); // finally で消える
});

test('work dir は実行ごとに一意で、成功時も finally で消える', function (): void {
    [$take, , , $extractor] = thumbnailPipelineContext();

    app(TakeThumbnailPipeline::class)->run($take->id);
    // 2 回目は冪等短絡するため、別テイクでもう 1 本走らせて一意性を見る
    [$second, , , $secondExtractor] = thumbnailPipelineContext();
    app(TakeThumbnailPipeline::class)->run($second->id);

    expect($extractor->workDirs)->toHaveCount(1);
    expect($secondExtractor->workDirs)->toHaveCount(1);
    expect($extractor->workDirs[0])->not->toBe($secondExtractor->workDirs[0]);
    expect(File::isDirectory($extractor->workDirs[0]))->toBeFalse();
    expect(File::isDirectory($secondExtractor->workDirs[0]))->toBeFalse();
});

test('ジョブは薄い殻でパイプラインへ take id を渡すだけ', function (): void {
    [$take, $cut, $manual] = thumbnailPipelineContext();

    (new GenerateTakeThumbnailJob($take->id))->handle(app(TakeThumbnailPipeline::class));

    expect($take->fresh()?->thumbnail_path)->toBe(expectedThumbnailKey($take, $cut, $manual));
});

test('素材種別が extractor へ渡る (動画テイク)', function (): void {
    [$take, , , $extractor] = thumbnailPipelineContext();

    app(TakeThumbnailPipeline::class)->run($take->id);

    expect($extractor->materials)->toBe([MaterialType::Video]);
});

test('静止画テイクもサムネイルが生成され、Still として extractor へ渡る', function (): void {
    // 一覧に原本 (フル解像度の画像) を貼らないため、静止画も生成対象に含める。
    Storage::fake('s3');
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $cut = Cut::factory()->forManual($manual)->create();
    $take = Take::factory()->forCut($cut)->still()->create();
    Storage::disk('s3')->put($take->video_path, 'fake-take-image');
    $extractor = new ThumbnailPipelineFakeExtractor;
    app()->instance(TakeThumbnailExtractor::class, $extractor);
    app()->instance(TakeObjectStorage::class, new ThumbnailPipelineRecordingStorage);

    app(TakeThumbnailPipeline::class)->run($take->id);

    expect($extractor->materials)->toBe([MaterialType::Still]);
    expect($take->refresh()->thumbnail_path)->toBe(expectedThumbnailKey($take, $cut, $manual));
});
