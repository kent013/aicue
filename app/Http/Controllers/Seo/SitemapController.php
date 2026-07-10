<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use App\Support\Seo\SeoUrl;
use Illuminate\Http\Response;

/**
 * sitemap.xml (route: seo.sitemap)。掲載集合は config('seo.sitemap_routes') 駆動
 * (公開 HTML ページのみ。認証配下は載せない)。
 */
class SitemapController extends Controller
{
    public function __invoke(SeoUrl $url): Response
    {
        /** @var array<string, array{changefreq: string, priority: string}> $routes */
        $routes = config('seo.sitemap_routes', []);

        $entries = [];
        foreach ($routes as $name => $meta) {
            // route 名 → 相対 path を route() で解決し SeoUrl で絶対化する
            // (route name と URL path が一致しない将来変更でも壊れない)。
            $relative = route($name, [], false); // 例 '/', '/contact'
            $loc = $url->to($relative);
            $entries[] = sprintf(
                '  <url><loc>%s</loc><changefreq>%s</changefreq><priority>%s</priority></url>',
                e($loc),
                e($meta['changefreq']),
                e($meta['priority']),
            );
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n"
            .implode("\n", $entries)."\n"
            .'</urlset>'."\n";

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
