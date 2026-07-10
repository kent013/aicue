<?php

declare(strict_types=1);

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use App\Support\Seo\CrawlPolicy;
use Illuminate\Http\Response;

/**
 * ai.txt (route: seo.ai)。AI クローラ向けのクロール方針。
 * Disallow 集合は CrawlPolicy 単一ソース (robots.txt とドリフトしない)。
 */
class AiTxtController extends Controller
{
    public function __invoke(CrawlPolicy $policy): Response
    {
        $lines = ['User-agent: *'];
        foreach ($policy->disallowedPaths() as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
