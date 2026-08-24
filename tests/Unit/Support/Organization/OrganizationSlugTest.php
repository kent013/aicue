<?php

declare(strict_types=1);

use App\Exceptions\Organization\InvalidOrganizationSlugException;
use App\Support\Organization\OrganizationSlug;

/*
 * 識別名の**構文** (家系裁定 AG-039b / AG-039c / 不変条件 I6・I7)。
 *
 * 正規化は「大文字を小文字へ倒すこと」だけである。前後の空白除去・記号の除去・連結は
 * 一切しない (矯正すると、利用者が入れた値と保存される値が黙って食い違う)。
 */

test('正例: 構文を満たす値は受け付ける', function (string $input): void {
    expect(OrganizationSlug::fromString($input)->value)->toBe($input);
})->with([
    'acme',
    'acme-corp',
    'a1-b2',
    '0',
    'a-b-c-d',
]);

test('正例: 255 文字ちょうどは通る (上限は列に由来する)', function (): void {
    $max = str_repeat('a', OrganizationSlug::MAX_LENGTH);

    expect(OrganizationSlug::fromString($max)->value)->toBe($max);
});

test('大文字は小文字へ正規化される (一意性は大文字小文字を区別しない)', function (): void {
    expect(OrganizationSlug::fromString('Acme')->value)->toBe('acme');
    expect(OrganizationSlug::fromString('ACME-Corp')->value)->toBe('acme-corp');
});

test('負例: 構文違反は例外', function (string $input): void {
    expect(fn (): OrganizationSlug => OrganizationSlug::fromString($input))
        ->toThrow(InvalidOrganizationSlugException::class);
})->with([
    '空' => '',
    '先頭ハイフン' => '-acme',
    '末尾ハイフン' => 'acme-',
    '連続ハイフン' => 'ac--me',
    'アンダースコア' => 'acme_corp',
    '日本語' => '日本語',
    '前後空白' => ' acme ',
    'スラッシュ' => 'acme/corp',
    'ドット' => 'acme.corp',
]);

test('負例: 末尾改行は通らない (`$` の行末一致に頼らない)', function (): void {
    expect(fn (): OrganizationSlug => OrganizationSlug::fromString("acme\n"))
        ->toThrow(InvalidOrganizationSlugException::class);
});

test('負例: 256 文字は上限超過で例外', function (): void {
    expect(fn (): OrganizationSlug => OrganizationSlug::fromString(str_repeat('a', OrganizationSlug::MAX_LENGTH + 1)))
        ->toThrow(InvalidOrganizationSlugException::class);
});
