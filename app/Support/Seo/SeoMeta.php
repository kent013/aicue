<?php

declare(strict_types=1);

namespace App\Support\Seo;

use Illuminate\Support\Facades\Config;

/**
 * 1 ページ分の SEO メタ情報を表す不変 DTO。controller は with*() で一部だけ上書きする。
 *
 * クローラが読む正本はこの DTO を SeoRenderer がサーバ描画した <head> である。
 * 値の組み立て・エスケープは SeoRenderer の責務であり、本 DTO は生 HTML を保持しない。
 *
 * @phpstan-type JsonLdNode array<string, mixed>
 */
final readonly class SeoMeta
{
    /** @param list<array<string, mixed>> $jsonLd JSON-LD ノード (JsonLd builder の返り値) */
    public function __construct(
        public string $title,
        public string $description,
        public string $canonical,
        public string $ogType,
        public string $ogImage,
        public string $twitterCard,
        public array $jsonLd = [],
    ) {}

    /**
     * config 既定値 + path から既定メタを生成する。
     * canonical は base_url (= APP_URL) + path で構築し、Host ヘッダは使わない。
     * SeoUrl は引数で受け取る (呼び出し側が DI 済の SeoUrl を渡す。テスト差替も容易)。
     *
     * @param  list<array<string, mixed>>  $jsonLd
     */
    public static function default(SeoUrl $url, string $path, array $jsonLd = []): self
    {
        return new self(
            title: Config::string('seo.default_title'),
            description: Config::string('seo.default_description'),
            canonical: $url->to($path),
            ogType: 'website',
            ogImage: $url->to(Config::string('seo.og_default_image')),
            twitterCard: Config::string('seo.twitter_card'),
            jsonLd: $jsonLd,
        );
    }

    public function withTitle(string $title): self
    {
        // 合成は SeoTitle に一元化 (空/空白 → site のみ、site 一致 → 二重化回避)。
        $full = SeoTitle::compose($title);

        return new self($full, $this->description, $this->canonical, $this->ogType, $this->ogImage, $this->twitterCard, $this->jsonLd);
    }

    public function withDescription(string $description): self
    {
        return new self($this->title, $description, $this->canonical, $this->ogType, $this->ogImage, $this->twitterCard, $this->jsonLd);
    }

    /** @param list<array<string, mixed>> $jsonLd */
    public function withJsonLd(array $jsonLd): self
    {
        return new self($this->title, $this->description, $this->canonical, $this->ogType, $this->ogImage, $this->twitterCard, $jsonLd);
    }
}
