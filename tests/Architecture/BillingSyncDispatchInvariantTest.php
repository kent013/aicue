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
|
| 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
| 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**母集団の非空が不変条件**である。
| dispatch 記法の改名・走査根の移動で母集団が 0 件になると、窓口が消えても違反ゼロで緑になる。
| 末尾の「空振り検査」ケースが母集団を allowlist と完全一致で pin し (= 非空)、
| その直後の負のコントロールが「一致するもののない根では母集団が空になる」ことを示す。
*/

/** 走査で母集団に入る唯一のファイル (窓口)。 */
const BILLING_SYNC_DISPATCH_ALLOWLIST = [
    'app/Services/Billing/BillingCustomerSynchronizer.php',
];

/**
 * 走査根配下で `SyncBillingCustomerDetails::dispatch` を持つファイル (リポジトリ相対パス)。
 *
 * @param  string  $absoluteRoot  走査根の絶対パス (負のコントロールで差し替えるため引数化)
 * @return list<string>
 */
function billingSyncDispatchFiles(string $absoluteRoot): array
{
    if (! is_dir($absoluteRoot)) {
        return [];
    }

    $finder = Finder::create()
        ->in($absoluteRoot)
        ->files()
        ->name('*.php')
        ->contains('/SyncBillingCustomerDetails::dispatch/');

    $files = [];
    foreach ($finder as $file) {
        $files[] = str_replace(base_path().'/', '', (string) $file->getRealPath());
    }
    sort($files);

    return $files;
}

test('app/ 内の SyncBillingCustomerDetails::dispatch は BillingCustomerSynchronizer に閉じる', function (): void {
    $violations = array_values(array_diff(
        billingSyncDispatchFiles(base_path('app')),
        BILLING_SYNC_DISPATCH_ALLOWLIST,
    ));

    expect($violations)->toBe([], 'SyncBillingCustomerDetails の dispatch は BillingCustomerSynchronizer 経由に限定してください: '.implode(', ', $violations));
});

test('空振り検査: 走査の母集団が窓口 1 本と完全一致する (走査根が生きている)', function (): void {
    // 母集団が空 = 窓口そのものを検出できていない状態であり、上の検査は無条件に緑になる。
    // 完全一致で pin することで「非空」と「窓口が母集団に居ること」を同時に固定する。
    expect(billingSyncDispatchFiles(base_path('app')))->toBe(BILLING_SYNC_DISPATCH_ALLOWLIST);
});

test('負のコントロール: 走査根を差し替えると母集団が空になる', function (): void {
    // 上の pin が空振りしていないことの裏取り。走査根の改名・移動を模して
    // 一致するもののないディレクトリ / 実在しないパスを渡すと母集団が 0 件になる。
    expect(billingSyncDispatchFiles(base_path('config')))->toBe([]);
    expect(billingSyncDispatchFiles(base_path('app-renamed')))->toBe([]);
});
