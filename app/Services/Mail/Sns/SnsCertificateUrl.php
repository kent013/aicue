<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

/**
 * **検証済み**の SNS 証明書 URL (値オブジェクト)。
 *
 * 「呼び出し側が検査してから渡す」という**契約ではなく型**で担保する。外部取得の防御は
 * 取得口のクラスの中にも閉じていなければ、経路が 1 本であることを検査で保証しても
 * 「経路の中で検査が抜けた」ことに気付けない。
 * `SnsCertificateFetcher` はこの型しか受け取らない。
 *
 * 検証内容 (`AwsSnsSignatureVerifier::isValidSnsCertUrl()` をここへ移設。二重実装を作らない):
 *  - scheme は https 固定
 *  - credential (user / pass) を持たない
 *  - port 未指定 or 443
 *  - query / fragment を持たない
 *  - host は `sns.{region}.amazonaws.com` (`sns.` 接頭辞必須、region の区間あり)
 *  - path は `/SimpleNotificationService-*.pem`
 *
 * **vendor (`MessageValidator::validateUrl`) より厳しい**ことがこの型の価値である
 * (vendor は `.pem` 終端 + `sns.<region>.amazonaws.com(.cn)` しか見ないため、
 * 同一 host 上の任意の `.pem` と中国パーティションを許してしまう)。
 *
 * 中国パーティション (amazonaws.com.cn) は対象外。利用予定が出たら明示的に広げる。
 */
final readonly class SnsCertificateUrl
{
    private function __construct(public string $value) {}

    /**
     * @throws SnsSignatureInvalidException 書式が SNS 証明書 URL でない (恒久 = 403)
     */
    public static function fromString(string $url): self
    {
        if (! self::isValid($url)) {
            throw new SnsSignatureInvalidException('untrusted SigningCertURL');
        }

        return new self($url);
    }

    private static function isValid(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        // parse_url の要素は string になるはずだが、想定外の型は**拒否側へ倒す**
        // (値オブジェクトなので「読めなかったら通さない」が正しい)。
        $scheme = $parts['scheme'] ?? null;
        if (! is_string($scheme) || $scheme !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (($parts['port'] ?? 443) !== 443) {
            return false;
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $host = $parts['host'] ?? null;
        if (! is_string($host) || preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host) !== 1) {
            return false;
        }
        $path = $parts['path'] ?? null;

        return is_string($path)
            && preg_match('#^/SimpleNotificationService-[A-Za-z0-9]+\.pem$#', $path) === 1;
    }
}
