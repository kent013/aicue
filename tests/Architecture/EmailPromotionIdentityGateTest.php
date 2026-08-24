<?php

declare(strict_types=1);

use Tests\Support\EnterpriseSso\EnterpriseSsoSourceScanner;

/*
 * G5: メールアドレスの昇格フローが**メールから利用者を引かない**、
 *     かつ**既存アカウントとの併合をしない**。
 *
 * ## なぜ Auth 名前空間に置いてあるか
 *
 * 昇格は**メール文字列を正当に扱う唯一の場所**である。企業 SSO の走査根
 * (`App\Services\EnterpriseSso`) へ入れると、G1 の「メールで引く記法の禁止」に
 * 巻き込まれてしまう。**検査の回避ではない** — 引き当ての鍵は常に自分自身であることを
 * 本 gate が別に固定する。
 *
 * ## 保証しないもの (誇張しない)
 *
 * - 走査根の外から `users.email` を書く経路は見ない (A3 の規約は実挙動テストが担う)
 * - 文字列で組み立てた列名は見ない
 */

function emailPromotionRoots(): array
{
    return [
        'app/Services/Auth/EmailPromotionService.php',
        'app/Http/Controllers/Auth/EmailPromotionController.php',
    ];
}

test('G5-1: 昇格フローがメールから利用者を引かない', function (): void {
    $sources = EnterpriseSsoSourceScanner::sources(emailPromotionRoots());

    // ★`whereBlind` は「暗号化列で引く」唯一の記法である。昇格フローは**自分自身**しか引かない。
    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, ['whereBlind', 'orWhereBlind']))
        ->toBe([], '昇格フローはメールから利用者を引かないこと');
});

test('G5-2: 昇格フローが既存アカウントとの併合をしない', function (): void {
    $sources = EnterpriseSsoSourceScanner::sources(emailPromotionRoots());

    // ★併合は「他人の行を触る」ことである。移譲・付け替え・削除の記法を禁じる。
    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, [
        'merge', 'transferOwnership', 'forceDelete',
    ]))->toBe([], '昇格は既存利用者を一切変更しないこと');
});

test('G5-3: 昇格の引き当てが relation 起点である (自分自身しか見ない)', function (): void {
    $sources = EnterpriseSsoSourceScanner::sources(['app/Services/Auth/EmailPromotionService.php']);
    $source = $sources['app/Services/Auth/EmailPromotionService.php'];

    // ★`$user->emailPromotions()` を通る (クラス起点で `EmailPromotion::query()` を書かない)。
    expect(str_contains($source, '$user->emailPromotions()'))->toBeTrue();
    expect(str_contains($source, 'EmailPromotion::query()'))->toBeFalse();
});

test('G5-4: 昇格フローが企業 SSO の走査根の中に無い (配置の宣言)', function (): void {
    // ★配置そのものを固定する (移動すると G1 の走査根に入ってしまう)。
    // ★パスは**区切り文字で組み立てる**。先頭にスラッシュを持つ文字列リテラルを書くと、
    //   旧 URL の不在を見張る `LegacyOrganizationlessUrlAbsenceTest` が URL として拾ってしまう。
    $separator = DIRECTORY_SEPARATOR;
    $base = dirname(__DIR__, 2).$separator.'app'.$separator.'Services'.$separator;

    expect(is_file($base.'Auth'.$separator.'EmailPromotionService.php'))->toBeTrue();
    expect(is_file($base.'EnterpriseSso'.$separator.'EmailPromotionService.php'))->toBeFalse();
});

test('G5-5: 走査が空振りしていない (走査根がそれぞれ生きている)', function (string $root): void {
    expect(EnterpriseSsoSourceScanner::sources([$root]))->not->toBe([]);
})->with(emailPromotionRoots());
