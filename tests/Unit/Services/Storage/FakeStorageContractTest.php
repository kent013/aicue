<?php

declare(strict_types=1);

use App\Services\Capture\Fakes\FakeTakeObjectStorage;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Render\Fakes\FakeRenderObjectStorage;
use App\Services\Render\RenderObjectStorage;

/*
 * drift 検知 (reflection): fake が「S3 到達性を持つメソッド」の明示 inventory を override し、
 * 親に S3 依存メソッドが増えても未 override で実 S3 に落ちないことを固定する。
 */

/** 指定メソッドが $fakeClass で宣言されている (= override 済) か */
function isOverriddenOn(string $fakeClass, string $method): bool
{
    $reflection = new ReflectionMethod($fakeClass, $method);

    return $reflection->getDeclaringClass()->getName() === $fakeClass;
}

test('FakeTakeObjectStorage は S3 到達メソッドをすべて override する', function (string $method): void {
    expect(isOverriddenOn(FakeTakeObjectStorage::class, $method))->toBeTrue();
})->with([
    'presignUpload',
    'headObject',
    'temporaryPlaybackUrl',
    'downloadToLocal', // サムネイル生成の入力取得 (T183)
    'upload',          // サムネイルの PUT (T183)
    'temporaryThumbnailUrl',
    'delete',
    'exists',
    'client', // 実 S3 client を構築しない (fail-loud)
]);

test('FakeTakeObjectStorage::client は実 S3 client を構築せず fail-loud する', function (): void {
    $fake = app(FakeTakeObjectStorage::class);
    $client = new ReflectionMethod($fake, 'client');
    $client->setAccessible(true);

    expect(fn () => $client->invoke($fake))->toThrow(RuntimeException::class);
});

test('FakeRenderObjectStorage は disk 直叩きメソッドを override する', function (string $method): void {
    expect(isOverriddenOn(FakeRenderObjectStorage::class, $method))->toBeTrue();
})->with([
    'disk', // downloadToLocal は disk() override 経由で fake disk を読む
    'upload',
    'temporaryPlaybackUrl',
    'temporaryDownloadUrl',
    'delete',
]);

test('意図的継承 (contentDisposition / keyPrefixFor) は override 不要 (inventory 外)', function (): void {
    // 継承のままでも S3 に到達しない純粋メソッド = fake 側で宣言していないことを固定
    expect(isOverriddenOn(FakeRenderObjectStorage::class, 'contentDisposition'))->toBeFalse();
    expect(isOverriddenOn(FakeRenderObjectStorage::class, 'keyPrefixFor'))->toBeFalse();
    // downloadToLocal も fake 側で再宣言せず親実装 (disk() 経由) を継承する
    expect(isOverriddenOn(FakeRenderObjectStorage::class, 'downloadToLocal'))->toBeFalse();
});

test('fake は親クラスの subtype である (container bind の LSP 前提)', function (): void {
    expect(is_subclass_of(FakeTakeObjectStorage::class, TakeObjectStorage::class))->toBeTrue();
    expect(is_subclass_of(FakeRenderObjectStorage::class, RenderObjectStorage::class))->toBeTrue();
});
