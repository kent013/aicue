<?php

declare(strict_types=1);

// ブランドアセット (favicon / apple-touch / PWA icon / og-image / webmanifest) の
// 存在・妥当性。静的ファイルは Laravel test kernel が配信しないため HTTP 200 ではなく
// filesystem を検証する。プレースホルダはニュートラル (アプリ初期化時に差し替え)。

it('ships a non-empty favicon.ico', function (): void {
    $path = public_path('favicon.ico');

    expect(file_exists($path))->toBeTrue();
    expect(filesize($path))->toBeGreaterThan(0);
});

it('ships every raster brand asset (non-empty)', function (string $file): void {
    expect(file_exists(public_path($file)))->toBeTrue("{$file} must exist");
    expect(filesize(public_path($file)))->toBeGreaterThan(0, "{$file} must be non-empty");
})->with([
    'favicon-16x16.png',
    'favicon-32x32.png',
    'apple-touch-icon.png',
    'icon-192.png',
    'icon-512.png',
    'images/og-default.png',
]);

it('og-default.png is 1200x630', function (): void {
    $size = getimagesize(public_path('images/og-default.png'));

    expect($size)->not->toBeFalse();
    assert(is_array($size));
    expect($size[0])->toBe(1200);
    expect($size[1])->toBe(630);
});

it('ships a valid web app manifest with 192/512 icons', function (): void {
    $path = public_path('site.webmanifest');
    expect(file_exists($path))->toBeTrue();

    $manifest = json_decode((string) file_get_contents($path), true);
    expect($manifest)->toBeArray();
    assert(is_array($manifest));

    expect($manifest)->toHaveKeys(['name', 'short_name', 'start_url', 'display', 'icons']);

    // Web App Manifest 仕様は色を具体 hex で要求する (DS token CSS 変数は使えない正当な例外)。
    expect($manifest['theme_color'])->toMatch('/^#[0-9a-f]{6}$/i');
    expect($manifest['background_color'])->toMatch('/^#[0-9a-f]{6}$/i');

    $icons = $manifest['icons'];
    assert(is_array($icons));
    $sizes = array_column($icons, 'sizes');
    expect($sizes)->toContain('192x192')->toContain('512x512');
});

it('webmanifest theme_color matches seo config (drift guard)', function (): void {
    $manifest = json_decode((string) file_get_contents(public_path('site.webmanifest')), true);
    assert(is_array($manifest));

    expect($manifest['theme_color'])->toBe(config('seo.theme_color'));
});

it('links the brand assets from the app document head', function (): void {
    $head = (string) file_get_contents(resource_path('views/app.blade.php'));

    expect($head)->toContain('rel="icon" href="/favicon.ico"')
        ->toContain('rel="apple-touch-icon" href="/apple-touch-icon.png"')
        ->toContain('rel="manifest" href="/site.webmanifest"')
        ->toContain('name="theme-color"');
});
