<?php

declare(strict_types=1);

use App\Services\Capture\Fakes\FakeTakeObjectStorage;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Render\Fakes\FakeRenderObjectStorage;
use App\Services\Render\RenderObjectStorage;
use Illuminate\Support\Facades\Route;

/*
 * provider 統合 (fake ON): fake_storage だけで (fake_externals=false・fake_llm=false)
 * storage の bind と signed route が provider 実配線で確立することを固定する
 * (capability 別 early return が storage を巻き込まないことの回帰)。
 */

beforeEach(fn () => enableFakeStorage());

test('fake_storage だけで storage fake が bind され signed route が登録される', function (): void {
    // 他 capability が off のまま storage だけ有効 (独立性)
    expect(config('testing.fake_externals'))->toBeFalse();
    expect(config('testing.fake_llm'))->toBeFalse();
    expect(config('testing.fake_storage'))->toBeTrue();

    expect(app(TakeObjectStorage::class))->toBeInstanceOf(FakeTakeObjectStorage::class);
    expect(app(RenderObjectStorage::class))->toBeInstanceOf(FakeRenderObjectStorage::class);

    expect(Route::has('bughunt.storage.put'))->toBeTrue();
    expect(Route::has('bughunt.storage.get'))->toBeTrue();
});
