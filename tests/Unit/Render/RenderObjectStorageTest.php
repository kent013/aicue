<?php

declare(strict_types=1);

use App\Services\Render\RenderObjectStorage;
use Illuminate\Support\Facades\Storage;

/*
 * DL 用署名 URL の filename 契約 (詳細レビュー Round 1 で明文化):
 * - CR/LF・制御文字の除去 (ヘッダ注入不能)
 * - RFC 5987 (filename*=UTF-8''...) + ASCII fallback (filename="...") の両建て
 */

test('contentDisposition: 日本語 filename は RFC 5987 + ASCII fallback の両建て', function (): void {
    $disposition = app(RenderObjectStorage::class)->contentDisposition('ネジ締め手順.mp4');

    expect($disposition)->toStartWith('attachment; ');
    // ASCII fallback: 非 ASCII は '_' 化
    expect($disposition)->toContain('filename="______.mp4"');
    // RFC 5987: UTF-8 percent-encoding
    expect($disposition)->toContain("filename*=UTF-8''".rawurlencode('ネジ締め手順.mp4'));
});

test('contentDisposition: CR/LF・制御文字は除去されヘッダ注入不能', function (): void {
    $disposition = app(RenderObjectStorage::class)->contentDisposition(
        "evil\r\nContent-Type: text/html\r\n.mp4",
    );

    expect($disposition)->not->toContain("\r");
    expect($disposition)->not->toContain("\n");
    // percent-encoded 側にも生の改行綴りが残らない (%0D%0A も生成されない = 除去済み)
    expect($disposition)->not->toContain('%0D');
    expect($disposition)->not->toContain('%0A');
});

test('contentDisposition: `"` と `\\` は quoted-string を壊さないよう ASCII fallback で無効化', function (): void {
    $disposition = app(RenderObjectStorage::class)->contentDisposition('a"b\\c.mp4');

    expect($disposition)->toContain('filename="a_b_c.mp4"');
});

test('contentDisposition: 制御文字だけの filename は既定名へ fallback', function (): void {
    $disposition = app(RenderObjectStorage::class)->contentDisposition("\r\n");

    expect($disposition)->toContain('filename="video.mp4"');
});

test('temporaryDownloadUrl: ResponseContentDisposition が署名 options に含まれる', function (): void {
    Storage::fake('s3');
    /** @var array<string, mixed> $captured */
    $captured = [];
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        function (string $path, DateTimeInterface $expiration, array $options) use (&$captured): string {
            $captured = $options;

            return "https://signed.example/{$path}";
        },
    );

    $url = app(RenderObjectStorage::class)->temporaryDownloadUrl('projects/1/manuals/1/renders/v1-1.mp4', 'マニュアル.mp4');

    expect($url)->toBe('https://signed.example/projects/1/manuals/1/renders/v1-1.mp4');
    expect($captured)->toHaveKey('ResponseContentDisposition');
    $disposition = $captured['ResponseContentDisposition'];
    expect(is_string($disposition))->toBeTrue();
    expect((string) $disposition)->toContain("filename*=UTF-8''".rawurlencode('マニュアル.mp4'));
});
