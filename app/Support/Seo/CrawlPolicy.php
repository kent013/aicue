<?php

declare(strict_types=1);

namespace App\Support\Seo;

/**
 * クローラに対する Disallow 集合の単一ソース (robots.txt と ai.txt がドリフトしない)。
 * テンプレート既存の非公開 (認証配下・機械用) prefix で初期化している。
 * アプリ固有の非公開 prefix を追加するときはここに追記する。
 */
final class CrawlPolicy
{
    /** @return list<string> 非公開 (クロール禁止) パス prefix */
    public function disallowedPaths(): array
    {
        return [
            '/dashboard',
            '/projects',
            '/organizations',
            '/settings',
            '/billing',
            '/invitations',
            '/recent-auth',
            '/api/',
            '/admin',
            '/oauth/',
        ];
    }
}
