<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * render-trigger の rate limiter が使う組織は URL から取れる (家系裁定 AG-037)。
 *
 * `AppServiceProvider::configureRenderRateLimiter` は組織を `$request->route('organization')`
 * から取り、無ければ **fail-closed (Assert が落ちる)**。`'none'` へ倒さないのは、
 * 配線不良を黙って許すと利用者間でキーが合流し、レート制限が意味を失うからである。
 * したがって `throttle:render-trigger` を持つ route は必ず `{organization}` を持つ。
 *
 * 母集団が空なら fail する (limiter 名の改名・route 削除で空振りしても気付ける)。
 */

test('throttle:render-trigger を持つ route は必ず {organization} を持つ', function (): void {
    $checked = 0;
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('throttle:render-trigger', $route->gatherMiddleware(), true)) {
            continue;
        }
        $checked++;
        if (! in_array('organization', $route->parameterNames(), true)) {
            $violations[] = ($route->getName() ?? $route->uri()).' が {organization} を持たない';
        }
    }

    expect($violations)->toBe([]);
    expect($checked)->toBeGreaterThan(0);
});
