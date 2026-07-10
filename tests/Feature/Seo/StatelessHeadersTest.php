<?php

declare(strict_types=1);

/*
 * 機械可読 SEO route (robots/sitemap/llms/ai) の stateless 検証。
 * routes/web.php の withoutMiddleware (cookie/session/CSRF/Inertia 除外) が
 * class rename 等で無効化されていないことの最終防波堤 (Set-Cookie 不在で検出する)。
 */

it('機械可読 route は Set-Cookie を一切出さず 200 を返す', function (string $path): void {
    config(['seo.base_url' => 'https://app.example']);

    $response = $this->get($path);

    $response->assertOk();
    expect($response->headers->has('Set-Cookie'))->toBeFalse("{$path} は cookie を発行してはならない");
})->with([
    'robots' => ['/robots.txt'],
    'sitemap' => ['/sitemap.xml'],
    'llms' => ['/llms.txt'],
    'ai' => ['/ai.txt'],
]);
