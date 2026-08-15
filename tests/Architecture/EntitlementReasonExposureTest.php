<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| entitlement の否定理由が画面 props に露出していないことの固定
|--------------------------------------------------------------------------
|
| **これは恒久の禁止ではなく現時点の設計判断の固定である**。露出させるときは本テストの契約を
| 変え、TypeScript の union と表示テストを同時に足すこと。
|
| 現状、遮断時の文言は RequireActiveSubscription::BLOCKED_MESSAGE と着地ページが持っており、
| EntitlementDeniedReason / SubscriptionEntitlementDto は app/Services/Billing 配下だけで
| 使われている。理由を props に足すと画面が理由別の出し分けを持つことになり、
| TypeScript の union と表示の網羅が要る (足さないまま露出すると未知値で画面が壊れる)。
|
| **保証範囲を誇張しない**: 走査するのは app/Http/ と resources/js/ の 2 根だけで、
| 別経路 (Console command の出力 / 通知本文) には沈黙する。
*/

/**
 * 指定ディレクトリ配下で語を含むファイルの相対パス一覧。
 *
 * @param  list<string>  $needles
 * @return list<string>
 */
function entitlementReasonHits(string $relativeRoot, array $needles): array
{
    $absolute = base_path($relativeRoot);
    if (! is_dir($absolute)) {
        return [];
    }

    $finder = Finder::create()->in($absolute)->files();
    foreach ($needles as $needle) {
        $finder->contains($needle);
    }

    $hits = [];
    foreach ($finder as $file) {
        $hits[] = str_replace(base_path().'/', '', $file->getPathname());
    }
    sort($hits);

    return $hits;
}

test('EntitlementDeniedReason は app/Http/ に現れない (props へ出していない)', function (): void {
    expect(entitlementReasonHits('app/Http', ['EntitlementDeniedReason']))->toBe([]);
});

test('SubscriptionEntitlementDto は app/Http/ に現れない (DTO をそのまま props にしていない)', function (): void {
    expect(entitlementReasonHits('app/Http', ['SubscriptionEntitlementDto']))->toBe([]);
});

test('否定理由の値は resources/js/ に現れない (画面が理由別の出し分けを持っていない)', function (string $needle): void {
    expect(entitlementReasonHits('resources/js', [$needle]))->toBe([]);
})->with([
    'EntitlementDeniedReason',
    'payment_grace_expired',
    'trial_ended_without_payment_method',
]);

test('負のコントロール: app/Services/Billing/ では検出される (走査が空振りしていない)', function (): void {
    expect(entitlementReasonHits('app/Services/Billing', ['EntitlementDeniedReason']))
        ->toContain('app/Services/Billing/SubscriptionService.php');
});
