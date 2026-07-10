<?php

declare(strict_types=1);

namespace App\Support\Seo;

use Illuminate\Support\Facades\Config;

/**
 * ページ固有名から完成タイトルを組み立てる単一経路。
 *
 * 「サイト名 + セパレータ」は config('seo') に固定してあり、APP_NAME
 * (session / mail / cache prefix 用途) とは独立。合成ロジックを本ヘルパへ一元化し、
 * SeoMeta::withTitle / SeoRenderer::renderPrivate / SeoManager がこれを共有する。
 */
final class SeoTitle
{
    /**
     * ページ固有名 → `{fragment}{separator}{site}` 形式の完成タイトル。
     *
     * - null / 空 / 空白のみ → サイト名のみ (先頭セパレータ残りを防ぐ)
     * - fragment がサイト名と一致 → サイト名のみ (二重化を防ぐ。トップページ等)
     */
    public static function compose(?string $fragment): string
    {
        $fragment = $fragment !== null ? trim($fragment) : '';
        $site = Config::string('seo.site_name');

        if ($fragment === '' || $fragment === $site) {
            return $site;
        }

        return $fragment.Config::string('seo.title_separator').$site;
    }
}
