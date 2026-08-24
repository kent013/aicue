<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * 課金ゲート配下の route はすべて `{organization}` を持つ (家系裁定 AG-037)。
 *
 * `RequireActiveSubscription` は組織を **URL の binding だけ**から取る (保持列は撤去済み)。
 * binding が無ければ fail-closed (500) にしてあるので、組織を持たない route が
 * ゲート配下に紛れ込むと**その route が丸ごと 500 になる**。宣言時点で落とす。
 *
 * ## 走査根と保証範囲
 *
 * `Route::getRoutes()` のうち `require-active-subscription` を持つもの全数。
 * 母集団が空なら fail する (alias 改名・group 解体で空振りしても気付ける)。
 * **middleware を後付けする経路 (RouteMiddlewareBinder 等) は route 一覧に現れた時点で
 * 見えるが、cached 起動そのものは再現しない** (AGENTS.md の route:cache 運用要件と同じ限界)。
 */

test('require-active-subscription を持つ route は必ず {organization} を持つ', function (): void {
    $checked = 0;
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('require-active-subscription', $route->gatherMiddleware(), true)) {
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
