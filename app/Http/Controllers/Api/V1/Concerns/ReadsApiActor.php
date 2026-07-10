<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Auth\Context\ApiActorContext;
use App\Http\Middleware\ResolveApiActor;
use Illuminate\Http\Request;
use Webmozart\Assert\Assert;

/**
 * controller が解決済み {@see ApiActorContext} を request attribute から取り出す薄い helper。
 *
 * 再判定はしない (= actor 解決の single source は `resolve.api-actor` middleware)。
 * middleware を経ていなければ Assert で fail-secure に 500 になる (route 配線で必ず経る)。
 */
trait ReadsApiActor
{
    protected function apiActor(Request $request): ApiActorContext
    {
        $context = $request->attributes->get(ResolveApiActor::ATTRIBUTE_KEY);
        Assert::isInstanceOf(
            $context,
            ApiActorContext::class,
            'ResolveApiActor middleware must populate request attribute "api_actor".',
        );

        return $context;
    }
}
