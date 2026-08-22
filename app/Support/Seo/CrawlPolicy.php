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
            // 業務 route はすべて組織 URL 配下にある (家系裁定 AG-037)。個別の業務 prefix を
            // 並べる必要はなく、`/organizations` 1 本で配下ごと閉じる。
            '/organizations',
            // 組織文脈を持たない入口 (所属数で分岐するだけだが認証必須)
            '/app',
            '/go',
            '/settings',
            '/invitations',
            '/recent-auth',
            '/api/',
            '/admin',
            '/oauth/',
        ];
    }
}
