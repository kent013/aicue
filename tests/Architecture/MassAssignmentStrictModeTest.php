<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;

/**
 * mass assignment strict mode の wiring を固定する。
 *
 * AppServiceProvider が Model::preventSilentlyDiscardingAttributes を config 駆動で有効化し、
 * 非 production を既定 ON にする backstop が誤って外れていないことを invariant 化する。
 * 入口契約 (ProhibitsProtectedKeys trait) とは独立した出口側の多層防御の一枚。
 */
test('mass_assignment_strict は非 production を既定 ON にする', function (): void {
    // config 値そのもの (env 上書きが無い CI/test では true)。
    expect(config('app.mass_assignment_strict'))->toBeTrue();
});

test('strict mode 有効時、非 fillable キーの mass-assign は例外化する', function (): void {
    try {
        Model::preventSilentlyDiscardingAttributes(true);

        // stripe_id は内部 Cashier キーで非 fillable。
        expect(fn (): Organization => (new Organization)->fill(['stripe_id' => 'cus_x']))
            ->toThrow(MassAssignmentException::class);
    } finally {
        // 後続テストへ漏らさないよう config 由来の値へ戻す。
        Model::preventSilentlyDiscardingAttributes((bool) config('app.mass_assignment_strict'));
    }
});
