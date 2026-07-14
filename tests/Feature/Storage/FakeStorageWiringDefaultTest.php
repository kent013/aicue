<?php

declare(strict_types=1);

use App\Services\Capture\Fakes\FakeTakeObjectStorage;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Render\Fakes\FakeRenderObjectStorage;
use App\Services\Render\RenderObjectStorage;
use Illuminate\Support\Facades\Route;

/*
 * provider 統合 (fake OFF = 既定): fake_storage 未設定では実クラスが解決され
 * fake route が一切生えないことを固定する (本番安全側の既定 = 完全 no-op)。
 */

test('既定 (fake_storage off) では実 storage クラスが解決され fake route は存在しない', function (): void {
    expect(config('testing.fake_storage'))->toBeFalse();

    $take = app(TakeObjectStorage::class);
    $render = app(RenderObjectStorage::class);
    expect($take)->not->toBeInstanceOf(FakeTakeObjectStorage::class);
    expect($render)->not->toBeInstanceOf(FakeRenderObjectStorage::class);
    expect($take::class)->toBe(TakeObjectStorage::class);
    expect($render::class)->toBe(RenderObjectStorage::class);

    expect(Route::has('bughunt.storage.put'))->toBeFalse();
    expect(Route::has('bughunt.storage.get'))->toBeFalse();
});
