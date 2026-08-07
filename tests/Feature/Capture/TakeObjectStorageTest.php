<?php

declare(strict_types=1);

use App\Services\Capture\TakeObjectStorage;
use App\Support\ExternalClientTimeouts;
use Aws\CommandInterface;
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
        // ★波及変更 (T126): 本 helper は disks.s3 を**丸ごと差し替える**ため、
        //   実 config と同じ http / retries を入れないと pin の配線が素通しになる。
        ...ExternalClientTimeouts::awsS3ClientOptions(),
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
        // ★client 既定を**データ系 (900s)** にしておく。per-command 上書きの負のコントロールは
        //   「捕捉した timeout がデータ系ではなく制御系である」ことで初めて意味を持つ。
        ...ExternalClientTimeouts::awsS3ClientOptions(),
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
    // 失効時刻 = X-Amz-Date + X-Amz-Expires が渡した expiresAt と正確に一致する
    // (SDK は Expires 秒数を内部 time() 基準で算出するため、テスト側 now() との間に
    // クライアント初回ビルド等の遅延が入ると固定値 1800 の照合は秒境界で flake する。
    // 署名日時基準で失効時刻そのものを検証する方が厳密かつ決定的)
    expect(preg_match('/X-Amz-Date=(\d{8}T\d{6}Z)/', $presigned->url, $dateMatch))->toBe(1);
    expect(preg_match('/X-Amz-Expires=(\d+)/', $presigned->url, $expiresMatch))->toBe(1);
    $signedAt = CarbonImmutable::createFromFormat('Ymd\THis\Z', $dateMatch[1], 'UTC');
    expect($signedAt->getTimestamp() + (int) $expiresMatch[1])->toBe($expiresAt->getTimestamp());
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

test('headObject は制御系の @http / @retries を per-command で積む', function (): void {
    // web 同期経路 (テイク登録) から呼ぶ唯一の S3 ネットワーク操作。s3 disk のクライアント既定は
    // データ系 (900s) なので、ここで制御系の帯へ絞れていることを実物で確認する。
    fakeS3DiskConfig();
    $captured = null;
    $handler = new MockHandler;
    $handler->append(function (CommandInterface $command) use (&$captured): Result {
        $captured = ['@http' => $command['@http'], '@retries' => $command['@retries']];

        return new Result(['ContentLength' => 1, 'ContentType' => 'video/mp4']);
    });

    storageWithMockHandler($handler)->headObject('projects/1/manuals/2/cuts/3/takes/01TEST.mp4');

    expect($captured)->not->toBeNull();
    // ★SDK が既定で足す他キー (decode_content 等) には触れず、pin した 2 キーだけを固定する。
    expect($captured['@http']['connect_timeout'])->toBe(ExternalClientTimeouts::AWS_CONTROL_CONNECT_TIMEOUT_SECONDS);
    expect($captured['@http']['timeout'])->toBe(ExternalClientTimeouts::AWS_CONTROL_TIMEOUT_SECONDS);
    expect($captured['@retries'])->toBe(ExternalClientTimeouts::AWS_CONTROL_PLANE_RETRIES);
});

test('負のコントロール: headObject の @http は s3 disk の既定 (データ系) を上書きする', function (): void {
    fakeS3DiskConfig();
    $captured = null;
    $handler = new MockHandler;
    $handler->append(function (CommandInterface $command) use (&$captured): Result {
        $captured = $command['@http'];

        return new Result(['ContentLength' => 1]);
    });

    storageWithMockHandler($handler)->headObject('projects/1/manuals/2/cuts/3/takes/01TEST.mp4');

    // per-command 上書きが実は効いていないのに green、を防ぐ。
    expect($captured['timeout'])->not->toBe(ExternalClientTimeouts::AWS_S3_TIMEOUT_SECONDS);
    expect($captured['connect_timeout'])->not->toBe(ExternalClientTimeouts::AWS_S3_CONNECT_TIMEOUT_SECONDS);
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
