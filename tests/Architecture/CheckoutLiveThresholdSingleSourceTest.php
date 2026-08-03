<?php

declare(strict_types=1);

/*
 * P9 (C-1) の構造的封じ: live/stale の閾値 literal は
 * BillingCheckoutSession::staleThresholdAt() **1 箇所にしか存在しない**。
 *
 * state() / startCheckout() / 日次 sweeper が「同じ 1 日」を独自に再発明すると、
 * 片方だけを直したときに「支払えないのに新規 Checkout も作れない」詰みが生まれる。
 * 閾値の再発明を機械検出する (テストが変更の唯一の入口)。
 */

$consumers = [
    'app/Services/Billing/BillingAccess.php',
    'app/Services/Billing/SubscriptionService.php',
    'app/Console/Commands/Billing/ReconcileSubscriptionSchedules.php',
];

test('live 判定の閾値 literal は共有述語の外に現れない', function (string $relativePath): void {
    $source = file_get_contents(base_path($relativePath));
    expect($source)->toBeString();

    expect($source)->not->toContain('subDay(');
    expect($source)->not->toContain('subDays(');
})->with($consumers);

test('閾値の単一出典は BillingCheckoutSession::staleThresholdAt にある', function (): void {
    $source = file_get_contents(base_path('app/Models/Billing/BillingCheckoutSession.php'));
    expect($source)->toBeString();

    expect($source)->toContain('public static function staleThresholdAt(CarbonImmutable $now): CarbonImmutable');
    expect($source)->toContain('$now->subDay()');
    // live / stale が同一述語から導かれること (補集合の関係を壊さない)
    expect($source)->toContain('public function isLivePending(CarbonImmutable $now): bool');
    expect($source)->toContain('public function isReplayablePending(CarbonImmutable $now): bool');
});
