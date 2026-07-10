<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Seo\JsonLd;
use App\Support\Seo\SeoManager;
use App\Support\Seo\SeoMeta;
use App\Support\Seo\SeoUrl;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * トップページ (route: home)。SEO full 分類ページの参考実装:
 * controller が SeoManager にメタを供給すると SeoComposer が完全な SEO ヘッド
 * (canonical / og / JSON-LD) をサーバ描画する。
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly SeoManager $seo,
        private readonly SeoUrl $url,
    ) {}

    public function __invoke(): InertiaResponse
    {
        $siteName = Config::string('seo.site_name');

        $this->seo->set(
            SeoMeta::default($this->url, '/')
                ->withTitle($siteName)
                ->withJsonLd([
                    // logo はアプリ側で public/images/logo.svg を配置して差し替える (placeholder)
                    JsonLd::organization($siteName, $this->url->base(), $this->url->to('/images/logo.svg')),
                    JsonLd::website($siteName, $this->url->base()),
                    // 公開価格が確定したら lowPriceJpy を供給する (null = offers を出さない)
                    JsonLd::softwareApplication(
                        $siteName,
                        $this->url->base(),
                        Config::string('seo.default_description'),
                        null,
                    ),
                ]),
        );

        return Inertia::render('Welcome', [
            'appName' => config('app.name'),
        ]);
    }
}
