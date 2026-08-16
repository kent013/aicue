<?php

declare(strict_types=1);

namespace App\Services\Capture;

use App\DataTransferObjects\Capture\ObjectMetadataData;
use App\DataTransferObjects\Capture\PresignedUploadData;
use App\Support\ExternalClientTimeouts;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * テイク動画 S3 オブジェクト操作の集約点 (概念設計 D11)。presign / HeadObject /
 * 署名 GET / 削除はすべて本クラス経由 (Feature テストでは container mock。
 * 配線検証は TakeObjectStorageTest が実 SDK オブジェクト + 偽エンドポイントで固定)。
 *
 * presigned PUT は SDK の createPresignedRequest を直接使い **ChecksumSHA256** を署名対象に
 * 含める (temporaryUploadUrl は署名できないため)。PHP SDK は content-type/length を presign
 * 署名から除外するが、checksum が本文を一意に固定するため内容・サイズは改変不能で、
 * content-type の照合は登録時の HeadObject 三点照合が担う。S3 は本文がチェックサムと
 * 一致しない PUT を拒否するため、この URL で置ける内容は申告ハッシュの 1 通りに固定される
 * = 登録後の再 PUT 差し替え防止 (概念設計 D2b)。
 */
class TakeObjectStorage
{
    public function presignUpload(string $path, string $contentType, int $sizeBytes, string $checksumSha256, CarbonImmutable $expiresAt): PresignedUploadData
    {
        $client = $this->client();
        $command = $client->getCommand('PutObject', [
            'Bucket' => $this->bucket(),
            'Key' => $path,
            'ContentType' => $contentType,
            'ContentLength' => $sizeBytes,
            'ChecksumSHA256' => $checksumSha256, // x-amz-checksum-sha256 として署名される
        ]);
        // 期限は DateTimeInterface で渡す (int timestamp は SDK 実装依存の誤解を招くため。
        // 期限・署名クエリは TakeObjectStorageTest が固定検証する)
        $request = $client->createPresignedRequest($command, $expiresAt);

        return new PresignedUploadData(
            url: (string) $request->getUri(),
            headers: [
                'Content-Type' => $contentType,
                'x-amz-checksum-sha256' => $checksumSha256,
            ],
            expiresAt: $expiresAt,
        );
    }

    /**
     * オブジェクトが存在しなければ null (PUT 未完了)。ChecksumMode=ENABLED で
     * ChecksumSHA256 も取得する (欠落する互換実装では null = 照合スキップの二重防御位置づけ)。
     *
     * ★**web 同期経路 (テイク登録) から呼ばれる唯一の S3 ネットワーク操作**である。
     *   s3 disk のクライアント既定はデータ系 (900s) のため、ここで per-command に
     *   制御系の帯へ絞る。`@http` は `AwsClient::getCommand()` が `+=` で合成する
     *   = 渡した側が勝つ。`@retries` は RetryMiddleware / RetryMiddlewareV2 の両方が読む。
     *   面分類は App\Enums\Storage\S3OperationSurface::BoundedControl。
     */
    public function headObject(string $path): ?ObjectMetadataData
    {
        try {
            $result = $this->client()->headObject([
                'Bucket' => $this->bucket(),
                'Key' => $path,
                'ChecksumMode' => 'ENABLED',
                ...ExternalClientTimeouts::awsControlPlaneCommandOptions(),
            ]);
        } catch (S3Exception $exception) {
            if ($exception->getStatusCode() === 404) {
                return null;
            }

            throw $exception;
        }

        $contentLength = $result['ContentLength'];
        Assert::numeric($contentLength, 'HeadObject の ContentLength を取得できません');
        $contentType = $result['ContentType'] ?? null;
        $checksum = $result['ChecksumSHA256'] ?? null;

        return new ObjectMetadataData(
            contentLength: (int) $contentLength,
            contentType: is_string($contentType) ? $contentType : null,
            checksumSha256: is_string($checksum) ? $checksum : null,
        );
    }

    /** 採用テイク再生用の署名 GET URL (TTL は config capture.playback_url_ttl_minutes) */
    public function temporaryPlaybackUrl(string $path): string
    {
        return Storage::disk('s3')->temporaryUrl(
            $path,
            now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes')),
        );
    }

    /**
     * テイク動画本文をローカル一時ファイルへ取得する (サムネイル生成の入力)。
     *
     * 面分類は Bulk (本文転送 = 所要時間がサイズに比例する)。**web 同期経路から呼ばない** —
     * 呼び出し元は media queue の GenerateTakeThumbnailJob だけである。
     * 実装は RenderObjectStorage::downloadToLocal と同型 (readStream → ローカル書き込み)。
     */
    public function downloadToLocal(string $path, string $localPath): void
    {
        $stream = Storage::disk('s3')->readStream($path);
        if ($stream === null) {
            throw new RuntimeException("S3 オブジェクトを読めません: {$path}");
        }

        $this->copyStreamToLocalFile($stream, $localPath, $path);
    }

    /**
     * サーバ生成物 (サムネイル) を S3 へ PUT する。
     *
     * ★ `ContentType` を必ず指定する。指定しないと S3 が既定の binary/octet-stream を返し、
     *   署名 GET へリダイレクトした先で `<img>` が描画できない
     *   (`ContentType` は Flysystem AwsS3V3Adapter の受理オプションに含まれる)。
     * 面分類は Bulk (本文転送)。**web 同期経路から呼ばない**。
     */
    public function upload(string $localPath, string $path, string $contentType): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
        }

        try {
            Storage::disk('s3')->writeStream($path, $stream, ['ContentType' => $contentType]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * サムネイル表示用の署名 GET URL (TTL は動画再生と同じ capture.playback_url_ttl_minutes)。
     *
     * ★ `temporaryPlaybackUrl()` を流用しない。中身は同じ署名 URL 生成だが、
     *   "playback" (再生) の語を静止画へ広げると public API の名前が実体と食い違う。
     */
    public function temporaryThumbnailUrl(string $path): string
    {
        return Storage::disk('s3')->temporaryUrl(
            $path,
            now()->addMinutes(config()->integer('capture.playback_url_ttl_minutes')),
        );
    }

    /** オブジェクト削除 (存在しないキーは no-op = 冪等) */
    public function delete(string $path): void
    {
        Storage::disk('s3')->delete($path);
    }

    /** stale 掃除用の存在確認 */
    public function exists(string $path): bool
    {
        return Storage::disk('s3')->exists($path);
    }

    /**
     * 読み出しストリームをローカルファイルへ写す (downloadToLocal の共通部)。
     * fake も同じ処理を使うため protected に置く (面分類の対象は public メソッドのみ)。
     *
     * @param  resource  $stream
     */
    protected function copyStreamToLocalFile(mixed $stream, string $localPath, string $sourcePath): void
    {
        $local = fopen($localPath, 'wb');
        if ($local === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new RuntimeException("ローカルファイルを開けません: {$localPath}");
        }

        try {
            if (stream_copy_to_stream($stream, $local) === false) {
                throw new RuntimeException("S3 オブジェクトのコピーに失敗しました: {$sourcePath}");
            }
        } finally {
            fclose($local);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * s3 disk の S3Client (テストでは本メソッドを override して MockHandler client を注入する)。
     */
    protected function client(): S3Client
    {
        $disk = Storage::disk('s3');
        Assert::isInstanceOf($disk, AwsS3V3Adapter::class);

        return $disk->getClient();
    }

    protected function bucket(): string
    {
        return config()->string('filesystems.disks.s3.bucket');
    }
}
