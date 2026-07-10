<?php

declare(strict_types=1);

namespace App\Support\Seo;

/**
 * schema.org JSON-LD ノードを型付きで生成する builder。
 * 返り値は SeoRenderer が json_encode する前提の連想配列。
 *
 * @phpstan-type JsonLdNode array<string, mixed>
 */
final class JsonLd
{
    /** @return JsonLdNode */
    public static function organization(string $name, string $url, string $logoUrl): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $name,
            'url' => $url,
            'logo' => $logoUrl,
        ];
    }

    /** @return JsonLdNode */
    public static function website(string $name, string $url): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $name,
            'url' => $url,
        ];
    }

    /**
     * SoftwareApplication。価格が確定しているプランがある場合のみ AggregateOffer を付す
     * (null なら offers を省略 = 誤った structured data を出さない)。
     *
     * @return JsonLdNode
     */
    public static function softwareApplication(
        string $name,
        string $url,
        string $description,
        ?int $lowPriceJpy,
    ): array {
        $node = [
            '@context' => 'https://schema.org',
            '@type' => 'SoftwareApplication',
            'name' => $name,
            'url' => $url,
            'applicationCategory' => 'BusinessApplication',
            'description' => $description,
        ];

        if ($lowPriceJpy !== null) {
            $node['offers'] = [
                '@type' => 'AggregateOffer',
                'priceCurrency' => 'JPY',
                'lowPrice' => $lowPriceJpy,
            ];
        }

        return $node;
    }
}
