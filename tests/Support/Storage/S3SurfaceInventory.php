<?php

declare(strict_types=1);

namespace Tests\Support\Storage;

use App\Enums\Storage\S3OperationSurface;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Render\RenderObjectStorage;

/**
 * S3 集約 adapter の public メソッドの「面」分類目録の**正本**。
 *
 * ★グローバル定数ではなく **static メソッド**に置く。Pest の `--parallel` は
 *   ファイル単位でプロセスを分けるため、他テストファイルの定数を参照すると未定義になりうる
 *   (`QueuedJobLeaseInventoryTest` のコメントと同じ規律)。
 *   Architecture テスト (`ExternalClientTimeoutInventoryTest`) と
 *   Feature テスト (`TakeRegistrationS3SurfaceTest`) の両方がここを読む。
 * ★配列形式は `['surface' => …, 'rationale' => …]` の**キー付きで統一**する (tuple にしない)。
 */
final class S3SurfaceInventory
{
    /**
     * adapter の public メソッドの面分類 (deny-by-default)。
     *
     * @return array<class-string, array<string, array{surface: S3OperationSurface, rationale: string}>>
     */
    public static function all(): array
    {
        return [
            TakeObjectStorage::class => [
                'presignUpload' => [
                    'surface' => S3OperationSurface::NoObjectRequest,
                    'rationale' => 'presign は署名計算のみで完結し S3 のオブジェクト API へリクエストを送らない',
                ],
                'headObject' => [
                    'surface' => S3OperationSurface::BoundedControl,
                    'rationale' => 'web 同期のテイク登録から呼ぶ唯一の S3 ネットワーク操作で転送量が有界である',
                ],
                'temporaryPlaybackUrl' => [
                    'surface' => S3OperationSurface::NoObjectRequest,
                    'rationale' => '署名 URL の文字列生成のみでオブジェクト API をまったく送らない',
                ],
                'delete' => [
                    'surface' => S3OperationSurface::Bulk,
                    'rationale' => 'Flysystem 経由で per-command option を注入できない掃除ジョブ専用の操作',
                ],
                'exists' => [
                    'surface' => S3OperationSurface::Bulk,
                    'rationale' => 'Flysystem 経由で per-command option を注入できない掃除ジョブ専用の操作',
                ],
            ],
            RenderObjectStorage::class => [
                'downloadToLocal' => [
                    'surface' => S3OperationSurface::Bulk,
                    'rationale' => '本文転送であり所要時間がオブジェクトサイズに比例して伸びる掃除・レンダ経路専用',
                ],
                'upload' => [
                    'surface' => S3OperationSurface::Bulk,
                    'rationale' => '本文転送であり所要時間がオブジェクトサイズに比例して伸びる掃除・レンダ経路専用',
                ],
                'temporaryPlaybackUrl' => [
                    'surface' => S3OperationSurface::NoObjectRequest,
                    'rationale' => '署名 URL の文字列生成のみでオブジェクト API をまったく送らない',
                ],
                'temporaryDownloadUrl' => [
                    'surface' => S3OperationSurface::NoObjectRequest,
                    'rationale' => '署名 URL の文字列生成のみでオブジェクト API をまったく送らない',
                ],
                'delete' => [
                    'surface' => S3OperationSurface::Bulk,
                    'rationale' => 'Flysystem 経由で per-command option を注入できない掃除ジョブ専用の操作',
                ],
                'keyPrefixFor' => [
                    'surface' => S3OperationSurface::NoObjectRequest,
                    'rationale' => '文字列生成のみで AWS SDK をまったく呼び出さない純関数であり待ちが発生しない',
                ],
                'contentDisposition' => [
                    'surface' => S3OperationSurface::NoObjectRequest,
                    'rationale' => '文字列生成のみで AWS SDK をまったく呼び出さない純関数であり待ちが発生しない',
                ],
            ],
        ];
    }

    /**
     * 指定した面に属するメソッド名。
     *
     * @param  class-string  $class
     * @return list<string>
     */
    public static function methodsWithSurface(string $class, S3OperationSurface $surface): array
    {
        $methods = self::all()[$class] ?? [];

        $matched = [];
        foreach ($methods as $method => $entry) {
            if ($entry['surface'] === $surface) {
                $matched[] = $method;
            }
        }

        return $matched;
    }
}
