<?php

declare(strict_types=1);

namespace App\Services\Capture\Fakes;

use App\DataTransferObjects\Capture\ObjectMetadataData;
use App\DataTransferObjects\Capture\PresignedUploadData;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Storage\Fakes\FakeObjectStore;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * TakeObjectStorage の fake (実 S3 非依存)。presigned PUT を signed route + s3_fake disk で emulate。
 * 契約 (PresignedUploadData / ObjectMetadataData / checksum 照合の趣旨) は実装と同一。
 */
final class FakeTakeObjectStorage extends TakeObjectStorage
{
    public function __construct(private readonly FakeObjectStore $store) {}

    public function presignUpload(string $path, string $contentType, int $sizeBytes, string $checksumSha256, CarbonImmutable $expiresAt): PresignedUploadData
    {
        // 実 S3 presign の代替: signed route。checksum を署名パラメータに固定 (D2b 再PUT差し替え防止)。
        $url = URL::temporarySignedRoute('bughunt.storage.put', $expiresAt, [
            'key' => $path,
            'checksum' => $checksumSha256,
        ]);

        return new PresignedUploadData(
            url: $url,
            headers: [
                'Content-Type' => $contentType,
                'x-amz-checksum-sha256' => $checksumSha256,
            ],
            expiresAt: $expiresAt,
        );
    }

    public function headObject(string $path): ?ObjectMetadataData
    {
        return $this->store->head($path);
    }

    public function temporaryPlaybackUrl(string $path): string
    {
        return URL::temporarySignedRoute(
            'bughunt.storage.get',
            now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes')),
            ['key' => $path],
        );
    }

    /**
     * s3_fake disk 上の実体をローカルへコピーする
     * (親と同じ readStream → ローカル書き込みの経路を fake disk で通す)。
     */
    public function downloadToLocal(string $path, string $localPath): void
    {
        $stream = Storage::disk(FakeObjectStore::DISK)->readStream($path);
        if ($stream === null) {
            throw new RuntimeException("fake storage にオブジェクトがありません: {$path}");
        }

        $this->copyStreamToLocalFile($stream, $localPath, $path);
    }

    /** sidecar (content_type) を必ず書く = fake の GET 配信 contract を満たす */
    public function upload(string $localPath, string $path, string $contentType): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
        }

        try {
            $this->store->putStreamWithMeta($path, $stream, $contentType);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public function temporaryThumbnailUrl(string $path): string
    {
        return URL::temporarySignedRoute(
            'bughunt.storage.get',
            now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes')),
            ['key' => $path],
        );
    }

    public function delete(string $path): void
    {
        $this->store->delete($path);
    }

    public function exists(string $path): bool
    {
        return $this->store->exists($path);
    }

    /** fake モードで実 S3 経路に落ちたら fail-loud (LSP drift 検知) */
    protected function client(): S3Client
    {
        throw new RuntimeException('FakeTakeObjectStorage は実 S3 クライアントを構築しません');
    }
}
