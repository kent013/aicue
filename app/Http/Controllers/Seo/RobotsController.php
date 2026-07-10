<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use App\Support\Seo\CrawlPolicy;
use App\Support\Seo\SeoUrl;
use Illuminate\Http\Response;

/**
 * robots.txt (route: seo.robots)。Disallow 集合は CrawlPolicy 単一ソース、
 * Sitemap 行は SeoUrl (= APP_URL 正本) 基準で Host ヘッダ非依存。
 */
class RobotsController extends Controller
{
    public function __invoke(CrawlPolicy $policy, SeoUrl $url): Response
    {
        $lines = ['User-agent: *'];
        foreach ($policy->disallowedPaths() as $path) {
            $lines[] = 'Disallow: '.$path;
        }
        $lines[] = '';
        $lines[] = 'Sitemap: '.$url->to('/sitemap.xml');

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
