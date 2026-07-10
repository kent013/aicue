<?php

declare(strict_types=1);

use App\Support\Seo\SeoUrl;

/*
 * SeoUrl: 公開 URL の単一経路。base_url (= APP_URL) の入力検証と絶対 URL 化。
 */

it('config の base_url から絶対 URL を組み立てる', function (): void {
    config(['seo.base_url' => 'https://app.example']);
    $url = SeoUrl::fromConfig();

    expect($url->base())->toBe('https://app.example')
        ->and($url->to('/'))->toBe('https://app.example/')
        ->and($url->to('/pricing'))->toBe('https://app.example/pricing')
        ->and($url->to('pricing'))->toBe('https://app.example/pricing');
});

it('base_url 未設定なら throw する', function (): void {
    config(['seo.base_url' => null]);
    SeoUrl::fromConfig();
})->throws(InvalidArgumentException::class);

it('base_url が空文字なら throw する', function (): void {
    config(['seo.base_url' => '']);
    SeoUrl::fromConfig();
})->throws(InvalidArgumentException::class);

it('scheme が http(s) 以外なら throw する', function (): void {
    config(['seo.base_url' => 'ftp://app.example']);
    SeoUrl::fromConfig();
})->throws(InvalidArgumentException::class);

it('host 欠落なら throw する', function (): void {
    new SeoUrl('https://');
})->throws(InvalidArgumentException::class);

it('path 付きなら throw する', function (): void {
    new SeoUrl('https://app.example/foo');
})->throws(InvalidArgumentException::class);

it('query 付きなら throw する', function (): void {
    new SeoUrl('https://app.example?x=1');
})->throws(InvalidArgumentException::class);

it('fragment 付きなら throw する', function (): void {
    new SeoUrl('https://app.example#frag');
})->throws(InvalidArgumentException::class);

it('末尾スラッシュ付き origin は fromConfig で正規化される', function (): void {
    config(['seo.base_url' => 'https://app.example/']);
    expect(SeoUrl::fromConfig()->base())->toBe('https://app.example');
});
