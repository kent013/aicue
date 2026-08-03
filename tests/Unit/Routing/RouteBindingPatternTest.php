<?php

declare(strict_types=1);

use App\Http\Routing\RouteBindingTypes;

/*
|--------------------------------------------------------------------------
| route binding pattern の regex 単体検証
|--------------------------------------------------------------------------
|
| Feature テストでは 18 桁も 19 桁も最終結果が 404 で**区別できない**ため、
| 「route にマッチしたか / しなかったか」の証明はこの層で行う。
| BIGINT_PATTERN が 18 桁上限であることが 22003 (numeric_value_out_of_range) を
| regex 段で塞ぐ唯一の根拠 (PHP_INT_MAX = 9223372036854775807 は 19 桁)。
*/

/** route pattern は route 全体に対する完全一致で評価される。 */
function matchesRouteBindingPattern(string $pattern, string $value): bool
{
    return (bool) preg_match('/^(?:'.$pattern.')$/', $value);
}

test('BIGINT_PATTERN は 1〜18 桁の数値にマッチする', function (string $value): void {
    expect(matchesRouteBindingPattern(RouteBindingTypes::BIGINT_PATTERN, $value))->toBeTrue();
})->with([
    '1 桁' => '1',
    '先頭ゼロ (pgsql は正常解釈するため制約しない)' => '007',
    '通常の ID' => '123456',
    '18 桁上限値' => '999999999999999999',
]);

test('BIGINT_PATTERN は 19 桁以上・非数値にマッチしない', function (string $value): void {
    expect(matchesRouteBindingPattern(RouteBindingTypes::BIGINT_PATTERN, $value))->toBeFalse();
})->with([
    '19 桁 (PHP_INT_MAX と同幅 = 22003 の危険域)' => '9223372036854775807',
    '19 桁 (PHP_INT_MAX + 1)' => '9223372036854775808',
    '30 桁' => '123456789012345678901234567890',
    '非数値' => 'abc',
    '数値混じり' => '12ab',
    '負数' => '-1',
    '空文字' => '',
]);

test('18 桁の最大値は bigint / PHP_INT_MAX の範囲内 (桁数だけで範囲内を保証できる)', function (): void {
    expect(PHP_INT_MAX)->toBe(9223372036854775807)
        ->and(999999999999999999 < PHP_INT_MAX)->toBeTrue()
        ->and(strlen((string) PHP_INT_MAX))->toBe(19);
});

test('UUID_PATTERN は UUID にのみマッチする', function (string $value, bool $expected): void {
    expect(matchesRouteBindingPattern(RouteBindingTypes::UUID_PATTERN, $value))->toBe($expected);
})->with([
    'v4 UUID' => ['9b7f2f1e-4b1a-4a2e-9b3c-2f8a1d5e6c7d', true],
    '大文字 UUID' => ['9B7F2F1E-4B1A-4A2E-9B3C-2F8A1D5E6C7D', true],
    'ハイフン無し' => ['9b7f2f1e4b1a4a2e9b3c2f8a1d5e6c7d', false],
    '非適合文字列' => ['abc', false],
    '数値' => ['12345', false],
]);
