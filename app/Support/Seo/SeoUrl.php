<?php

declare(strict_types=1);

namespace App\Support\Seo;

use InvalidArgumentException;

/**
 * 公開 URL の単一経路。canonical / og:url / sitemap / JSON-LD すべてが
 * config('seo.base_url') (= APP_URL) を正本とする (Host ヘッダ汚染防止)。
 */
final readonly class SeoUrl
{
    public function __construct(private string $baseUrl)
    {
        // scheme だけでなく host 必須・path/query/fragment 禁止も検証する。
        // parse_url が false (malformed URL) を返すケースを先に弾く。
        $parts = parse_url($baseUrl);
        if ($parts === false) {
            throw new InvalidArgumentException("seo.base_url is a malformed URL: {$baseUrl}");
        }
        $scheme = $parts['scheme'] ?? null;
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException("seo.base_url must be http(s) URL, got: {$baseUrl}");
        }
        if (! isset($parts['host']) || $parts['host'] === '') {
            throw new InvalidArgumentException("seo.base_url must have a host, got: {$baseUrl}");
        }
        $path = $parts['path'] ?? '';
        if (! in_array($path, ['', '/'], true) || isset($parts['query']) || isset($parts['fragment'])) {
            // path 付き / query / fragment 付き APP_URL は canonical を壊すため構成不備として弾く
            throw new InvalidArgumentException("seo.base_url must be origin only (no path/query/fragment), got: {$baseUrl}");
        }
    }

    public static function fromConfig(): self
    {
        $base = config('seo.base_url');
        if (! is_string($base) || $base === '') {
            throw new InvalidArgumentException('seo.base_url is not configured');
        }

        return new self(rtrim($base, '/'));
    }

    public function to(string $path = '/'): string
    {
        return $this->baseUrl.'/'.ltrim($path, '/');
    }

    public function base(): string
    {
        return $this->baseUrl;
    }
}
