<?php

declare(strict_types=1);

// 自己完結エラーページ: Vite/Inertia/DB が壊れた 500 経路でも確実に描画できるよう、
// error blade は @vite / build asset / Inertia に依存せず inline CSS で自己完結すること。

it('renders every customer error view self-contained (no build/vite/inertia deps)', function (string $view): void {
    $html = view($view)->render();

    expect($html)->toContain('<style>')            // inline CSS で自己完結
        ->toContain('name="robots" content="noindex"')
        ->not->toContain('/build/')                // ビルド済み asset に依存しない
        ->not->toContain('@vite')
        ->not->toContain('data-page');             // Inertia マウントに依存しない
})->with([
    'errors.401',
    'errors.403',
    'errors.404',
    'errors.419',
    'errors.429',
    'errors.500',
    'errors.503',
]);

it('serves the custom 404 page for unknown routes', function (): void {
    $response = $this->get('/definitely-not-a-real-route-xyz');

    $response->assertNotFound();
    expect((string) $response->getContent())
        ->toContain('ページが見つかりません')
        ->toContain('name="robots" content="noindex"')
        ->not->toContain('/build/');
});

it('renders the admin error layout with a neutral operator tone (no customer branding)', function (): void {
    $html = view('errors.admin.4xx', ['status' => 404, 'adminPath' => 'admin'])->render();

    expect($html)->toContain('<style>')
        ->toContain('管理パネルに戻る')
        ->toContain('name="robots" content="noindex"')
        ->toContain('href="/admin"');
});

/*
 * 上 3 ケースは view()->render() 直叩きのため bootstrap/app.php の respond callback を通らない。
 * respond のスロットは Handler の $finalizeResponseCallback への単純代入 (単一スロット・
 * last-write-wins) であり、2 本目の登録が現れると admin 分離が**黙って**無効化される。
 * その退行を振る舞い側から捕まえるため HTTP 経路のケースを 1 本置く
 * (静的側の検出は tests/Architecture/InertiaErrorScreenContractTest)。
 */
it('serves the operator-facing template for admin 404 over HTTP (respond callback regression guard)', function (): void {
    $response = $this->get('/admin/definitely-not-a-real-admin-route-xyz');

    $response->assertNotFound();
    expect((string) $response->getContent())
        ->toContain('管理パネルに戻る')
        ->toContain('name="robots" content="noindex"');
});
