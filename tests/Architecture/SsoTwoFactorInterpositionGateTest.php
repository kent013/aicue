<?php

declare(strict_types=1);

use Tests\Support\EnterpriseSso\EnterpriseSsoSourceScanner;

/*
 * G4: **共通ログイン経路に 2 要素認証を挟まない** (家系の裁定 AG-200)。
 *
 * 企業・ソーシャルの**両方**の戻り口に、待機ログインを作る記述と
 * 2 要素の入力画面への転送が無いことを固定する。
 *
 * ★**主たる証明は実挙動側にある** —
 *   `tests/Feature/Auth/EnterpriseSsoLoginTest.php` の
 *   「2 要素認証が有効な利用者もそのままログインが確定する」
 *   「組織が義務づけていても確定したうえで設定ページへ導かれる」の 2 本である。
 *   本 gate はその形を**静的に裏当てする**だけで、挙動そのものを証明しない。
 *
 * ## 保証しないもの (誇張しない)
 *
 * - 別名の route 名や別の controller で第二要素を挟む形には沈黙する
 * - middleware で挟む形は見ない (route の宣言は本 gate の母集団に入らない)
 */

function ssoCallbackRoots(): array
{
    return [
        'app/Http/Controllers/Auth/EnterpriseSsoLoginController.php',
        'app/Http/Controllers/Auth/SocialAuthController.php',
        'app/Services/EnterpriseSso/EnterpriseCallbackAuthenticator.php',
    ];
}

test('G4-1: 戻り口に 2 要素の待機ログインを作る記述が無い', function (): void {
    $sources = EnterpriseSsoSourceScanner::sources(ssoCallbackRoots());

    // ★Fortify の待機ログインの実体 (`login.id` のセッションへの退避) と、
    //   2 要素の入力画面への転送の記法をトークン完全一致で禁じる。
    foreach ($sources as $path => $source) {
        expect(str_contains($source, 'two-factor.login'))
            ->toBeFalse("{$path} は 2 要素の入力画面へ転送しないこと");
        expect(str_contains($source, 'two_factor_login'))
            ->toBeFalse("{$path} は待機ログインを作らないこと");
    }
});

test('G4-2: 戻り口が確定のログイン (Auth::login) を持つ', function (): void {
    // ★「挟まない」が「そもそもログインしない」で満たされてしまう形を排除する
    //   (空振りの否定側だけを固定しない)。
    foreach (['app/Http/Controllers/Auth/EnterpriseSsoLoginController.php'] as $path) {
        $sources = EnterpriseSsoSourceScanner::sources([$path]);
        expect(str_contains($sources[$path], 'Auth::login('))->toBeTrue();
    }
});

test('G4-3: 企業ログインが remember cookie を使わない', function (): void {
    $sources = EnterpriseSsoSourceScanner::sources(['app/Http/Controllers/Auth/EnterpriseSsoLoginController.php']);
    $source = $sources['app/Http/Controllers/Auth/EnterpriseSsoLoginController.php'];

    // ★接続を無効化したあとに cookie から新しいセッションを開始できてしまう形にしない。
    expect(str_contains($source, 'remember: false'))->toBeTrue();
});

test('G4-4: 走査が空振りしていない (走査根がそれぞれ生きている)', function (string $root): void {
    expect(EnterpriseSsoSourceScanner::sources([$root]))->not->toBe([]);
})->with(ssoCallbackRoots());
