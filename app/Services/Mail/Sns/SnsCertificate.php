<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

/**
 * 証明書取得の結果。
 *
 * `fromCache` を持つのは、**署名検証が通ったあとに昇格させるのは新しく取得した PEM だけ**
 * だからである (キャッシュから返ってきたものを再度書き戻すと、寿命が伸びるだけでなく
 * 「新しく取得したものだけを昇格させる」という説明とコードが食い違う)。
 *
 * 生成口は名前つきの 2 つだけにする (真偽値を渡し間違えた不整合な値を作れないようにする)。
 */
final readonly class SnsCertificate
{
    private function __construct(
        public string $pem,
        public bool $fromCache,
    ) {}

    /** キャッシュから返した証明書 (昇格させない) */
    public static function fromCache(string $pem): self
    {
        return new self($pem, true);
    }

    /** 新しく取りに行って得た証明書 (署名検証が通れば昇格させる) */
    public static function fetched(string $pem): self
    {
        return new self($pem, false);
    }
}
