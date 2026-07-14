<?php

declare(strict_types=1);

use App\Services\Storage\Fakes\FakeStorageKey;

/*
 * FakeStorageKey: signed route の多層防御 key 検証 (segment 単位)。
 */

test('projects/ prefix + 2 segment 以上の正規 key を許可する', function (): void {
    expect(FakeStorageKey::isAllowed('projects/1/manuals/2/cuts/3/takes/01ABC.mp4'))->toBeTrue();
    expect(FakeStorageKey::isAllowed('projects/1/manuals/2/renders/v1-1.mp4'))->toBeTrue();
});

test('projects/ 以外の prefix を拒否する', function (string $key): void {
    expect(FakeStorageKey::isAllowed($key))->toBeFalse();
})->with([
    'other prefix' => ['secrets/1/a.mp4'],
    'no slash' => ['projects'],
    'projects のみ' => ['projects/'],
    'empty' => [''],
    '絶対パス' => ['/projects/1/a.mp4'],
]);

test('.. / . / バックスラッシュ / NUL を含む segment を拒否する (traversal 防御)', function (string $key): void {
    expect(FakeStorageKey::isAllowed($key))->toBeFalse();
})->with([
    'parent traversal' => ['projects/../etc/passwd'],
    'current dir' => ['projects/./1/a.mp4'],
    '空 segment' => ['projects//a.mp4'],
    'バックスラッシュ' => ['projects/1\\..\\a.mp4'],
    'NUL' => ["projects/1/a\0.mp4"],
]);

test('segment 内の .. を含む文字列だが独立 segment でないものは誤検知しない', function (): void {
    // 'a..b' は '..' segment ではないため許可される (単純 str_contains との差分)
    expect(FakeStorageKey::isAllowed('projects/1/a..b.mp4'))->toBeTrue();
});
