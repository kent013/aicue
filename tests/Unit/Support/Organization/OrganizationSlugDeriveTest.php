<?php

declare(strict_types=1);

use App\Support\Organization\OrganizationSlug;

/*
 * 組織名からの導出 (家系裁定 AG-039)。
 *
 * ★**値オブジェクトは値を捏造しない**。導出できなければ null を返し、代替を決めるのは
 *   Service の責務である ('org' のような値をここで作らない)。
 */

test('英字の組織名からは導出できる', function (): void {
    expect(OrganizationSlug::deriveFromName('Acme Corp')?->value)->toBe('acme-corp');
});

test('日本語の組織名は導出できず null (代替は Service が決める)', function (): void {
    expect(OrganizationSlug::deriveFromName('テスト組織'))->toBeNull();
    expect(OrganizationSlug::deriveFromName('山田太郎 の組織'))->toBeNull();
});

test('記号だけの組織名も null', function (): void {
    expect(OrganizationSlug::deriveFromName('---'))->toBeNull();
    expect(OrganizationSlug::deriveFromName('   '))->toBeNull();
});

test('切り詰め後の候補も同じ検査点を通る (末尾ハイフンで終わらない)', function (): void {
    $derived = OrganizationSlug::deriveFromName(str_repeat('a', 300));

    expect($derived)->not->toBeNull();
    expect(mb_strlen((string) $derived?->value))->toBeLessThanOrEqual(OrganizationSlug::MAX_LENGTH);
});

test('切り詰め位置がハイフンになる名前でも構文違反を返さない', function (): void {
    // "aaa…a b" のように、切り詰め境界がハイフンへ落ちる名前を作る
    $name = str_repeat('a', OrganizationSlug::MAX_LENGTH).' b';
    $derived = OrganizationSlug::deriveFromName($name);

    expect($derived)->not->toBeNull();
    expect(preg_match(OrganizationSlug::PATTERN, (string) $derived?->value))->toBe(1);
});
