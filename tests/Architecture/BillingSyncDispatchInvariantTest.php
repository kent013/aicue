<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Stripe customer 同期 job の dispatch 経路 invariant
|--------------------------------------------------------------------------
|
| SyncBillingCustomerDetails の dispatch は BillingCustomerSynchronizer 1 経路に閉じる (IV-2)。
| 窓口を単一化することで「必ず transaction 内から afterCommit で発火する」(IV-3) /
| 「stripe_id 未作成は no-op」(IV-4) の契約が構造的に守られる。webhook ハンドラがこの経路を
| 通らないことが Stripe→アプリ→Stripe の同期ループを構造的に防いでいる。
*/

test('app/ 内の SyncBillingCustomerDetails::dispatch は BillingCustomerSynchronizer に閉じる', function (): void {
    $allowlist = [
        'app/Services/Billing/BillingCustomerSynchronizer.php',
    ];

    $finder = Finder::create()
        ->in(base_path('app'))
        ->files()
        ->name('*.php')
        ->contains('/SyncBillingCustomerDetails::dispatch/');

    $violations = [];
    foreach ($finder as $file) {
        $relative = str_replace(base_path().'/', '', (string) $file->getRealPath());
        if (! in_array($relative, $allowlist, true)) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([], 'SyncBillingCustomerDetails の dispatch は BillingCustomerSynchronizer 経由に限定してください: '.implode(', ', $violations));
});
