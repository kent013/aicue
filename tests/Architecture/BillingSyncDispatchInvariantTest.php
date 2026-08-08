<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Stripe customer 同期 job の dispatch 経路 invariant
|--------------------------------------------------------------------------
|
| SyncBillingCustomerDetails の dispatch は BillingCustomerSynchronizer 1 経路に閉じる (IV-2)。
| 窓口を単一化することで「必ず transaction の内側から発火する」(IV-3) /
| 「stripe_id 未作成は no-op」(IV-4) の契約が構造的に守られる。webhook ハンドラがこの経路を
| 通らないことが Stripe→アプリ→Stripe の同期ループを構造的に防いでいる。
|
| 【契約の反転 (AG-114 確定 1 / T137)】
| - 旧主張: SyncBillingCustomerDetails は transaction 内から afterCommit で発火する (IV-3)
| - 旧目的: commit 前の値を Stripe へ送る stale read を防ぐ
| - 新主張: transaction の内側で素直に dispatch し、jobs 行を業務 tx に乗せる
| - 新前提: worker が job を可視化できるのは commit 後 (jobs 行が同一 tx にあるため)
| - 前提を守る機構: QueueDispatchAtomicityGuard (R1〜R3) + QueueDispatchAtomicityInventoryTest (D1)
| - 反転根拠: 本 job は SerializesModels で organization を再取得するため、可視化が commit 後で
|   ある限り IV-3 は afterCommit なしで保たれる。一方 afterCommit は「commit したのに未投入」の
|   窓を残すため、確定 1 の下では有害である
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
