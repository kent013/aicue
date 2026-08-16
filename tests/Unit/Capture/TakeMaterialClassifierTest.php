<?php

declare(strict_types=1);

use App\Enums\Manual\MaterialType;
use App\Models\Take;
use App\Support\Capture\TakeMaterialClassifier;

/*
 * 申告 Content-Type → 素材種別の写像 (この写像を書いてよい唯一の場所)。
 * 許可集合の正本は config/capture.php の 2 キーであり、本クラスは
 * 「許可集合のどちら側か」だけを答える (許可・不許可の判断はしない)。
 */

test('許可済みの動画 Content-Type は Video に分類される', function (string $contentType): void {
    expect(TakeMaterialClassifier::fromContentType($contentType))->toBe(MaterialType::Video);
})->with(['video/mp4', 'video/webm', 'video/quicktime']);

test('許可済みの静止画 Content-Type は Still に分類される', function (string $contentType): void {
    expect(TakeMaterialClassifier::fromContentType($contentType))->toBe(MaterialType::Still);
})->with(['image/jpeg', 'image/png']);

test('未許可の Content-Type は例外 (到達したら整合性異常なので fail-loud)', function (): void {
    expect(fn (): MaterialType => TakeMaterialClassifier::fromContentType('image/webp'))
        ->toThrow(InvalidArgumentException::class, '未許可の Content-Type です: image/webp');
});

test('拡張子は許可集合と 1 対 1 で、未許可は例外', function (): void {
    expect(TakeMaterialClassifier::extensionFor('video/mp4'))->toBe('mp4');
    expect(TakeMaterialClassifier::extensionFor('video/webm'))->toBe('webm');
    expect(TakeMaterialClassifier::extensionFor('video/quicktime'))->toBe('mov');
    expect(TakeMaterialClassifier::extensionFor('image/jpeg'))->toBe('jpg');
    expect(TakeMaterialClassifier::extensionFor('image/png'))->toBe('png');
    expect(fn (): string => TakeMaterialClassifier::extensionFor('image/webp'))
        ->toThrow(InvalidArgumentException::class);
});

test('分類できる Content-Type は必ず拡張子も持つ (2 つの写像の母集団が一致する)', function (): void {
    $allowed = [
        ...config()->array('capture.allowed_video_content_types'),
        ...config()->array('capture.allowed_still_content_types'),
    ];
    foreach ($allowed as $contentType) {
        expect(is_string($contentType))->toBeTrue();
        /** @var string $contentType */
        expect(TakeMaterialClassifier::extensionFor($contentType))->not->toBe('');
    }
});

test('TakeFactory の既定は Video / still() 状態は Still で尺を持たない', function (): void {
    $video = Take::factory()->make();
    expect($video->material_type)->toBe(MaterialType::Video);

    $still = Take::factory()->still()->make();
    expect($still->material_type)->toBe(MaterialType::Still);
    expect($still->duration_ms)->toBeNull();
});

test('material_type は $fillable 外 (サーバ確定値なので payload から入らない)', function (): void {
    expect((new Take)->getFillable())->not->toContain('material_type');
});
