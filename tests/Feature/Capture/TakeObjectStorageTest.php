<?php

declare(strict_types=1);

use App\Services\Capture\TakeObjectStorage;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Storage;

/*
 * TakeObjectStorage (施策3): presign の署名パラメータ配線と HeadObject の
 * ChecksumMode=ENABLED を実 SDK オブジェクトで固定する (mock のみでは配線ミスを
 * 見逃すため。S3Client は偽エンドポイント設定・ネットワーク非到達)。
 */

/** 偽エンドポイントの s3 disk 設定 (ネットワークに一切到達しない) */
function fakeS3DiskConfig(): void
{
    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'key' => 'test-key',
        'secret' => 'test-secret',
        'region' => 'us-east-1',
        'bucket' => 'test-bucket',
        'endpoint' => 'http://s3.invalid.test',
        'use_path_style_endpoint' => true,
        'throw' => false,
        'report' => false,
    ]);
    Storage::forgetDisk('s3');
}

/** MockHandler 注入済みの TakeObjectStorage (headObject の配線検証用) */
function storageWithMockHandler(MockHandler $handler): TakeObjectStorage
{
    $client = new S3Client([
        'region' => 'us-east-1',
        'version' => 'latest',
        'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
        'handler' => $handler,
    ]);

    return new class($client) extends TakeObjectStorage
    {
        public function __construct(private readonly S3Client $mockClient) {}

        protected function client(): S3Client
        {
            return $this->mockClient;
        }
    };
}

test('presignUpload は checksum / content-type を署名対象に含む presigned PUT URL を返す', function (): void {
    fakeS3DiskConfig();
    $expiresAt = CarbonImmutable::now()->addMinutes(30);
    $checksum = base64_encode(hash('sha256', 'video-blob', true));

    $presigned = app(TakeObjectStorage::class)->presignUpload(
        'projects/1/manuals/2/cuts/3/takes/01TEST.mp4',
        'video/mp4',
        12_345,
        $checksum,
        $expiresAt,
    );

    // URL: 偽エンドポイント + サーバ生成キー + SigV4 署名クエリ
    expect($presigned->url)->toContain('s3.invalid.test');
    expect($presigned->url)->toContain('projects/1/manuals/2/cuts/3/takes/01TEST.mp4');
    expect($presigned->url)->toContain('X-Amz-Signature=');
    expect($presigned->url)->toContain('X-Amz-Expires=1800');
    // D2b: checksum が署名に固定される (query パラメータ + SignedHeaders の両方。
    // content-type/length は PHP SDK が presign 署名から除外するため、その照合は
    // HeadObject 三点照合が担う = checksum が内容とサイズを一意に固定する)
    $decoded = urldecode($presigned->url);
    expect($decoded)->toContain('x-amz-checksum-sha256='.$checksum);
    expect($decoded)->toContain('X-Amz-SignedHeaders=host;x-amz-checksum-sha256');
    // クライアントが PUT に付けるヘッダ (presign 署名と一致する値)
    expect($presigned->headers)->toBe([
        'Content-Type' => 'video/mp4',
        'x-amz-checksum-sha256' => $checksum,
    ]);
    expect($presigned->expiresAt->getTimestamp())->toBe($expiresAt->getTimestamp());
});

test('headObject は ChecksumMode=ENABLED を渡し ContentLength/ContentType/ChecksumSHA256 を DTO 化する', function (): void {
    fakeS3DiskConfig();
    $handler = new MockHandler;
    $handler->append(new Result([
        'ContentLength' => 12_345,
        'ContentType' => 'video/mp4',
        'ChecksumSHA256' => 'abc123checksum=',
    ]));
    $storage = storageWithMockHandler($handler);

    $meta = $storage->headObject('projects/1/manuals/2/cuts/3/takes/01TEST.mp4');

    expect($meta)->not->toBeNull();
    expect($meta?->contentLength)->toBe(12_345);
    expect($meta?->contentType)->toBe('video/mp4');
    expect($meta?->checksumSha256)->toBe('abc123checksum=');

    $command = $handler->getLastCommand();
    expect($command->getName())->toBe('HeadObject');
    expect($command['ChecksumMode'])->toBe('ENABLED');
    expect($command['Key'])->toBe('projects/1/manuals/2/cuts/3/takes/01TEST.mp4');
});

test('headObject はオブジェクト不存在 (404) で null を返す (PUT 未完了)', function (): void {
    fakeS3DiskConfig();
    $handler = new MockHandler;
    $handler->append(static fn ($command) => new S3Exception('not found', $command, [
        'response' => new Response(404),
    ]));
    $storage = storageWithMockHandler($handler);

    expect($storage->headObject('missing/key.mp4'))->toBeNull();
});

test('headObject は 404 以外の S3 エラーを握り潰さない', function (): void {
    fakeS3DiskConfig();
    $handler = new MockHandler;
    $handler->append(static fn ($command) => new S3Exception('forbidden', $command, [
        'response' => new Response(403),
    ]));
    $storage = storageWithMockHandler($handler);

    expect(fn () => $storage->headObject('denied/key.mp4'))->toThrow(S3Exception::class);
});

test('temporaryPlaybackUrl は config TTL の署名 GET URL を返す', function (): void {
    fakeS3DiskConfig();

    $url = app(TakeObjectStorage::class)->temporaryPlaybackUrl('projects/1/manuals/2/cuts/3/takes/01TEST.mp4');

    expect($url)->toContain('projects/1/manuals/2/cuts/3/takes/01TEST.mp4');
    expect($url)->toContain('X-Amz-Signature=');
});

test('config capture の値が typed accessor で読める', function (): void {
    expect(config()->integer('capture.upload_ticket_ttl_minutes'))->toBe(30);
    expect(config()->integer('capture.max_take_bytes'))->toBe(500 * 1024 * 1024);
    expect(config()->array('capture.allowed_video_content_types'))->toBe(['video/mp4', 'video/webm', 'video/quicktime']);
    expect(config()->integer('capture.playback_url_ttl_minutes'))->toBe(60);
    expect(config()->integer('capture.released_reservation_retention_days'))->toBe(30);
    expect(config()->integer('capture.stale_verifying_minutes'))->toBe(15);
});
