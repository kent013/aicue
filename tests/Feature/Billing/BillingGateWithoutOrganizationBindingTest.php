<?php

declare(strict_types=1);

use App\Http\Middleware\RequireActiveSubscription;
use Illuminate\Support\Facades\Route;
use Webmozart\Assert\InvalidArgumentException;

/*
 * 課金ゲートは組織 binding が無ければ fail-closed (家系裁定 AG-037)。
 *
 * `RequireActiveSubscription` は組織を **URL の binding だけ**から取る (保持列は撤去済み)。
 * 組織を持たない route がゲート配下に紛れ込んだら、**黙って通さず落ちる**ことを固定する。
 * 宣言時点の網羅は `BillingGateRouteOrganizationParamTest` が担当する (2 層)。
 */

test('組織 binding の無い route にゲートを掛けると fail-closed になる', function (): void {
    [, $owner] = createOrganizationWithOwner();

    Route::middleware(['web', 'auth', RequireActiveSubscription::class])
        ->get('/synthetic-gate-without-organization', fn (): string => 'ok')
        ->name('synthetic.gate-without-organization');

    $this->withoutExceptionHandling();

    expect(fn () => $this->actingAs($owner)->get('/synthetic-gate-without-organization'))
        ->toThrow(InvalidArgumentException::class);
});
