<?php

declare(strict_types=1);

use Tests\Support\EnterpriseSso\EnterpriseSsoSourceScanner;

/*
 * G1: 企業 SSO は**メールアドレスで利用者を引かない**。
 *
 * 引き当ての鍵は **接続 × `COLLATE "C"` の subject** だけである。
 *
 * ## 二層で固定する
 *
 *  1. **記法の走査 (本 gate)** — 走査根に「メールで引く」記法が無い
 *  2. **スキーマの検査** (`tests/Feature/EnterpriseSso/EnterpriseIdentityIsolationTest.php`) —
 *     申告メールの列を含む索引が 0 本であること (**スキーマの読み取りだけ**。
 *     `migrate:fresh` 等の破壊操作を伴わない = 禁止事項 3)
 *
 * ## 保証しないもの (誇張しない)
 *
 * - 走査根の外 (`App\Services\Auth\EmailPromotionService` = メール昇格) は母集団に入らない。
 *   そちらは G5 が別に固定する (**メール文字列を正当に扱う唯一の場所**を
 *   禁止語の走査へ巻き込まないための意図的な配置である)
 * - 文字列で組み立てた列名 (`where($column, …)`) は見ない
 */

function enterpriseSsoIdentityRoots(): array
{
    return [
        'app/Services/EnterpriseSso',
        'app/Http/Controllers/Auth/EnterpriseSsoLoginController.php',
        'app/Models/EnterpriseIdentity.php',
        'app/Models/OrganizationOidcConnection.php',
    ];
}

test('G1-1: メールで利用者を引く記法が無い', function (): void {
    $sources = EnterpriseSsoSourceScanner::sources(enterpriseSsoIdentityRoots());

    // ★`whereBlind` は CipherSweet の「暗号化列で引く」唯一の記法である。
    //   企業 SSO の経路にこれが現れたら、メールでの引き当てが復活している。
    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, ['whereBlind', 'orWhereBlind']))
        ->toBe([], '企業 SSO の経路でメールから利用者を引かないこと');
});

test('G1-2: 身元モデルが申告メールへ blind index を張らない', function (): void {
    $sources = EnterpriseSsoSourceScanner::sources(['app/Models/EnterpriseIdentity.php']);

    // ★`addBlindIndex` を**呼ばない**ことが不変条件の実体である。
    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, ['addBlindIndex']))->toBe([]);
});

test('G1-3: 昇格の確認待ちモデルも blind index を張らない', function (): void {
    $sources = EnterpriseSsoSourceScanner::sources(['app/Models/EmailPromotion.php']);

    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, ['addBlindIndex']))->toBe([]);
});

test('G1-4: 走査が空振りしていない (走査根がそれぞれ生きている)', function (string $root): void {
    expect(EnterpriseSsoSourceScanner::sources([$root]))->not->toBe([]);
})->with(enterpriseSsoIdentityRoots());
