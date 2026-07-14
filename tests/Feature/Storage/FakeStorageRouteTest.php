<?php

declare(strict_types=1);

use App\Services\Capture\TakeObjectStorage;
use App\Services\Render\RenderObjectStorage;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;

/*
 * fake storage signed route (PUT 受け口 / GET serve) の E2E。
 * gate 有効 (enableFakeStorage) で provider が bind + route を実配線し、
 * 実 S3 に一切触れず bytes 往復・checksum 三点照合・容量上限・Range 応答を固定する。
 */

beforeEach(fn () => enableFakeStorage());

const FAKE_KEY = 'projects/1/manuals/2/cuts/3/takes/01ABCDEF.mp4';

/** 実 S3 に触れたら即例外になるよう region 未設定を明示する (negative 担保) */
function unsetRealS3Region(): void
{
    config()->set('filesystems.disks.s3.region', null);
    config()->set('filesystems.disks.s3.bucket', null);
}

/** fake storage は container で解決される (provider 実配線) */
function fakeTakeStorage(): TakeObjectStorage
{
    return app(TakeObjectStorage::class);
}

function presignPut(string $key, string $checksum): string
{
    return fakeTakeStorage()->presignUpload(
        $key,
        'video/mp4',
        100,
        $checksum,
        CarbonImmutable::now()->addMinutes(30),
    )->url;
}

/** signed PUT を実行する (raw body + checksum ヘッダ) */
function putObject(string $url, string $body, ?string $checksumHeader = null): TestResponse
{
    $checksumHeader ??= base64_encode(hash('sha256', $body, true));

    return test()->call('PUT', $url, [], [], [], [
        'CONTENT_TYPE' => 'video/mp4',
        'HTTP_X_AMZ_CHECKSUM_SHA256' => $checksumHeader,
    ], $body);
}

test('presignUpload → signed PUT → headObject が実 S3 非依存で往復する', function (): void {
    unsetRealS3Region();
    $body = 'fake-video-bytes';
    $checksum = base64_encode(hash('sha256', $body, true));

    $url = presignPut(FAKE_KEY, $checksum);
    expect($url)->toContain('/_fake-storage/object');
    expect($url)->toContain('signature=');

    putObject($url, $body)->assertNoContent();

    $meta = fakeTakeStorage()->headObject(FAKE_KEY);
    expect($meta)->not->toBeNull();
    expect($meta?->contentLength)->toBe(strlen($body));
    expect($meta?->contentType)->toBe('video/mp4');
    expect($meta?->checksumSha256)->toBe($checksum);
});

test('署名なし PUT は 403 (signed middleware)', function (): void {
    // 署名クエリを外した素の route path
    putObject('/_fake-storage/object?key='.rawurlencode(FAKE_KEY).'&checksum=x', 'body')
        ->assertForbidden();
});

test('ヘッダ欠落 / ヘッダ != 署名 checksum は 400', function (): void {
    $body = 'abc';
    $checksum = base64_encode(hash('sha256', $body, true));
    $url = presignPut(FAKE_KEY, $checksum);

    // ヘッダ欠落
    test()->call('PUT', $url, [], [], [], ['CONTENT_TYPE' => 'video/mp4'], $body)
        ->assertStatus(400);

    // ヘッダ != 署名 checksum
    putObject($url, $body, checksumHeader: base64_encode(hash('sha256', 'other', true)))
        ->assertStatus(400);
});

test('body の checksum が署名値と不一致なら 400 (三点照合 3/3)', function (): void {
    $signedChecksum = base64_encode(hash('sha256', 'declared', true));
    $url = presignPut(FAKE_KEY, $signedChecksum);

    // ヘッダは署名値と一致するが body が異なる
    putObject($url, 'tampered-body', checksumHeader: $signedChecksum)
        ->assertStatus(400);

    expect(fakeTakeStorage()->exists(FAKE_KEY))->toBeFalse();
});

test('容量超過は 413', function (): void {
    config()->set('capture.max_take_bytes', 4);
    $body = str_repeat('z', 64);
    $checksum = base64_encode(hash('sha256', $body, true));
    $url = presignPut(FAKE_KEY, $checksum);

    putObject($url, $body)->assertStatus(413);
});

test('temporaryPlaybackUrl の signed GET が bytes を返し Range に応答する', function (): void {
    unsetRealS3Region();
    $body = 'range-test-bytes-0123456789';
    $checksum = base64_encode(hash('sha256', $body, true));
    putObject(presignPut(FAKE_KEY, $checksum), $body)->assertNoContent();

    $getUrl = fakeTakeStorage()->temporaryPlaybackUrl(FAKE_KEY);
    $full = test()->get($getUrl);
    $full->assertOk();
    expect($full->streamedContent())->toBe($body);
    $full->assertHeader('Content-Type', 'video/mp4');

    // Range: 先頭 4 バイト → 206 partial
    $partial = test()->call('GET', $getUrl, [], [], [], ['HTTP_RANGE' => 'bytes=0-3']);
    $partial->assertStatus(206);
    expect($partial->streamedContent())->toBe(substr($body, 0, 4));
});

test('未登録 object の GET は 404 (sidecar 欠損=未完了も 404)', function (): void {
    $getUrl = fakeTakeStorage()->temporaryPlaybackUrl('projects/1/manuals/2/cuts/3/takes/MISSING.mp4');
    test()->get($getUrl)->assertNotFound();
});

test('不正 key (traversal) の PUT/GET は 400', function (): void {
    // 署名は通るが key 検証で 400 (多層防御)
    $badKey = 'projects/../etc/passwd';
    $checksum = base64_encode(hash('sha256', 'x', true));
    $putUrl = presignPut($badKey, $checksum);
    putObject($putUrl, 'x', checksumHeader: $checksum)->assertStatus(400);

    $getUrl = fakeTakeStorage()->temporaryPlaybackUrl($badKey);
    test()->get($getUrl)->assertStatus(400);
});

test('render DL: temporaryDownloadUrl の GET は contentDisposition() 生成のヘッダを返す (注入不能)', function (): void {
    unsetRealS3Region();
    $render = app(RenderObjectStorage::class);
    $key = 'projects/1/manuals/2/renders/v1-1.mp4';

    // render 出力を fake disk へ upload (ローカル一時ファイル経由)
    $local = tempnam(sys_get_temp_dir(), 'render');
    expect($local)->not->toBeFalse();
    file_put_contents((string) $local, 'rendered-mp4-bytes');
    $render->upload((string) $local, $key);
    @unlink((string) $local);

    // 改行を含む filename でも Content-Disposition にそのまま流れない
    $url = $render->temporaryDownloadUrl($key, "evil\r\nInjected: x.mp4");
    $response = test()->get($url);
    $response->assertOk();
    $disposition = (string) $response->headers->get('Content-Disposition');
    expect($disposition)->toStartWith('attachment; ');
    expect($disposition)->not->toContain("\r");
    expect($disposition)->not->toContain("\n");
});

test('AWS 設定が空でも fake の主要ユースケースは実 S3 に触れず成功する (drift E2E)', function (): void {
    unsetRealS3Region();
    $take = fakeTakeStorage();
    $render = app(RenderObjectStorage::class);
    $body = 'contract-bytes';
    $checksum = base64_encode(hash('sha256', $body, true));

    // take: presign→PUT→head→playback→delete
    putObject(presignPut(FAKE_KEY, $checksum), $body)->assertNoContent();
    expect($take->headObject(FAKE_KEY))->not->toBeNull();
    test()->get($take->temporaryPlaybackUrl(FAKE_KEY))->assertOk();
    $take->delete(FAKE_KEY);
    expect($take->exists(FAKE_KEY))->toBeFalse();

    // render: upload→downloadToLocal→temporaryDownloadUrl→delete
    $renderKey = 'projects/1/manuals/2/renders/v1-1.mp4';
    $local = tempnam(sys_get_temp_dir(), 'render');
    file_put_contents((string) $local, 'render-bytes');
    $render->upload((string) $local, $renderKey);
    @unlink((string) $local);

    $dlTarget = tempnam(sys_get_temp_dir(), 'dl');
    $render->downloadToLocal($renderKey, (string) $dlTarget);
    expect(file_get_contents((string) $dlTarget))->toBe('render-bytes');
    @unlink((string) $dlTarget);

    test()->get($render->temporaryDownloadUrl($renderKey, 'manual.mp4'))->assertOk();
    $render->delete($renderKey);
});
