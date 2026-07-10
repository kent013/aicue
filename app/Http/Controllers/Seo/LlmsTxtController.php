<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use App\Support\Seo\SeoUrl;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;

/**
 * llms.txt (route: seo.llms)。llmstxt.org 形式
 * (H1 = サイト名 / blockquote = 要約 / 公開ページのリンク一覧)。
 * 公開ページ集合は seo.sitemap_routes と同一ソース (sitemap とドリフトしない)。
 */
class LlmsTxtController extends Controller
{
    public function __invoke(SeoUrl $url): Response
    {
        $siteName = Config::string('seo.site_name');
        $description = Config::string('seo.default_description');

        $lines = ['# '.$siteName];
        if ($description !== '') {
            $lines[] = '';
            $lines[] = '> '.$description;
        }
        $lines[] = '';
        $lines[] = '## 公開ページ';

        /** @var array<string, array{changefreq: string, priority: string}> $routes */
        $routes = config('seo.sitemap_routes', []);
        /** @var array<string, string> $minimalTitles */
        $minimalTitles = config('seo.minimal_titles', []);
        foreach (array_keys($routes) as $name) {
            // リンクラベル: home はサイト名、それ以外は minimal_titles の固有名 (無ければ route 名)
            $label = $name === 'home' ? $siteName : ($minimalTitles[$name] ?? $name);
            $lines[] = '- ['.$label.']('.$url->to(route($name, [], false)).')';
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
