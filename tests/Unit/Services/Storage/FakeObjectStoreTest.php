<?php

declare(strict_types=1);

use App\Services\Storage\Fakes\FakeObjectStore;
use App\Services\Storage\Fakes\FakeStorageChecksumMismatch;
use App\Services\Storage\Fakes\FakeStorageOverCapacity;
use Illuminate\Support\Facades\Storage;

/*
 * FakeObjectStore: s3_fake disk 上の stream 保存 / head / delete と
 * checksum 三者一致・容量上限・completion marker (sidecar) を固定する。
 */

beforeEach(function (): void {
    Storage::fake(FakeObjectStore::DISK);
});

/** @return resource */
function streamOf(string $body)
{
    $stream = fopen('php://temp', 'r+b');
    expect($stream)->not->toBeFalse();
    fwrite($stream, $body);
    rewind($stream);

    return $stream;
}

function checksumOf(string $body): string
{
    return base64_encode(hash('sha256', $body, true));
}

test('storeStreamed 正常: head が size / content_type / checksum を返す', function (): void {
    $store = new FakeObjectStore;
    $body = 'video-bytes';
    $key = 'projects/1/manuals/2/cuts/3/takes/01ABC.mp4';

    $store->storeStreamed($key, streamOf($body), 'video/mp4', checksumOf($body));

    $meta = $store->head($key);
    expect($meta)->not->toBeNull();
    expect($meta?->contentLength)->toBe(strlen($body));
    expect($meta?->contentType)->toBe('video/mp4');
    expect($meta?->checksumSha256)->toBe(checksumOf($body));
});

test('storeStreamed checksum 不一致: 例外 + object 未確定 (head null)', function (): void {
    $store = new FakeObjectStore;
    $key = 'projects/1/manuals/2/cuts/3/takes/01ABC.mp4';

    expect(fn () => $store->storeStreamed($key, streamOf('real-bytes'), 'video/mp4', checksumOf('other-bytes')))
        ->toThrow(FakeStorageChecksumMismatch::class);

    expect($store->head($key))->toBeNull();
    expect($store->exists($key))->toBeFalse();
});

test('storeStreamed 容量超過: 例外 + 一時ファイル残存なし', function (): void {
    config()->set('capture.max_take_bytes', 8);
    $store = new FakeObjectStore;
    $key = 'projects/1/manuals/2/cuts/3/takes/01ABC.mp4';
    $body = str_repeat('x', 64);

    expect(fn () => $store->storeStreamed($key, streamOf($body), 'video/mp4', checksumOf($body)))
        ->toThrow(FakeStorageOverCapacity::class);

    expect($store->head($key))->toBeNull();
    // .uploading-* 一時ファイルが残っていない (disk 直下を走査)
    $leftovers = collect(Storage::disk(FakeObjectStore::DISK)->allFiles())
        ->filter(fn (string $p): bool => str_contains($p, '.uploading-'));
    expect($leftovers)->toBeEmpty();
});

test('putStreamWithMeta: 容量上限なしで content_type=video/mp4 の sidecar を生成する', function (): void {
    config()->set('capture.max_take_bytes', 4); // storeStreamed なら超過するサイズ
    $store = new FakeObjectStore;
    $key = 'projects/1/manuals/2/renders/v1-1.mp4';
    $body = str_repeat('y', 64);

    $store->putStreamWithMeta($key, streamOf($body), 'video/mp4');

    $meta = $store->head($key);
    expect($meta?->contentType)->toBe('video/mp4');
    expect($meta?->contentLength)->toBe(64);
});

test('object あり sidecar なし: head は null (PUT 未完了 = crash 途中扱い)', function (): void {
    $store = new FakeObjectStore;
    $key = 'projects/1/manuals/2/cuts/3/takes/01ABC.mp4';
    // sidecar を書かず object だけ置く
    Storage::disk(FakeObjectStore::DISK)->put($key, 'orphan');

    expect($store->head($key))->toBeNull();
});

test('sidecar 破損 (不正 JSON / 未知 schema / checksum 形式不正) は fail-loud', function (string $sidecar): void {
    $store = new FakeObjectStore;
    $key = 'projects/1/manuals/2/cuts/3/takes/01ABC.mp4';
    $disk = Storage::disk(FakeObjectStore::DISK);
    $disk->put($key, 'bytes');
    $disk->put($key.'.meta.json', $sidecar);

    expect(fn () => $store->head($key))->toThrow(RuntimeException::class);
})->with([
    '不正 JSON' => ['{not json'],
    '未知 schema' => ['{"schema_version":99,"content_type":"video/mp4","checksum_sha256":"'.'A'.'"}'],
    'content_type 欠損' => ['{"schema_version":1,"checksum_sha256":"'.str_repeat('A', 43).'="}'],
    'checksum 形式不正' => ['{"schema_version":1,"content_type":"video/mp4","checksum_sha256":"short"}'],
]);

test('上書き PUT: head は新 meta を返す (旧 meta 混同なし)', function (): void {
    $store = new FakeObjectStore;
    $key = 'projects/1/manuals/2/cuts/3/takes/01ABC.mp4';

    $store->storeStreamed($key, streamOf('old'), 'video/mp4', checksumOf('old'));
    $store->storeStreamed($key, streamOf('brand-new'), 'video/webm', checksumOf('brand-new'));

    $meta = $store->head($key);
    expect($meta?->contentType)->toBe('video/webm');
    expect($meta?->checksumSha256)->toBe(checksumOf('brand-new'));
    expect($meta?->contentLength)->toBe(strlen('brand-new'));
});

test('delete は object + sidecar を消し、二重 delete でも例外を出さない (冪等)', function (): void {
    $store = new FakeObjectStore;
    $key = 'projects/1/manuals/2/cuts/3/takes/01ABC.mp4';

    $store->storeStreamed($key, streamOf('bytes'), 'video/mp4', checksumOf('bytes'));
    expect($store->exists($key))->toBeTrue();

    $store->delete($key);
    expect($store->exists($key))->toBeFalse();
    expect($store->head($key))->toBeNull();
    expect(Storage::disk(FakeObjectStore::DISK)->exists($key.'.meta.json'))->toBeFalse();

    // 冪等: 不在 key の delete は no-op
    $store->delete($key);
    expect($store->exists($key))->toBeFalse();
});
