<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

use Aws\Sns\Exception\InvalidSnsMessageException;
use Aws\Sns\Message;
use Aws\Sns\MessageValidator;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * AWS SDK ベースの SNS 署名検証実装。
 *
 * 署名の暗号検証 (canonical string / SignatureVersion / 証明書検証) は AWS SDK の
 * `MessageValidator` に委譲し、自前再実装しない。wrapper の責務は 2 点:
 *  1. 証明書 URL を SNS 証明書 URL の厳格パターンに限定 (不正 = 恒久 → Invalid、SSRF 遮断)
 *  2. 証明書取得を自前 HTTP client に差し込み (`certClient`)、取得失敗を
 *     `SnsVerificationUnavailableException` (一時障害 → 503) に正規化する
 *
 * `MessageValidator` は cert 取得を `certClient` callable に委譲できる。これを使い
 * **取得失敗 (一時障害) と署名不一致 (恒久) を確実に分離**する: certClient が投げた
 * Unavailable は validate() を素通りして伝播し、validate() が投げる
 * `InvalidSnsMessageException` は cert 取得後の検証失敗 = 署名不一致のみとなる。
 * これにより SDK 既定の `file_get_contents` 再取得や例外メッセージ判定に依存しない。
 */
final class AwsSnsSignatureVerifier implements SnsSignatureVerifier
{
    public function __construct(private readonly HttpFactory $http) {}

    public function verify(Message $message): void
    {
        // 1) 証明書 URL を SNS 証明書 URL に限定。不正 = 恒久 → 403。
        if (! $this->isValidSnsCertUrl($this->certUrl($message))) {
            throw new SnsSignatureInvalidException('untrusted SigningCertURL');
        }

        // 2) cert 取得は certClient に差し込む。取得失敗は certClient 内で Unavailable に
        //    正規化され validate() を伝播 → 503。validate() の InvalidSnsMessageException は
        //    cert 取得済の検証失敗 = 署名不一致 = 恒久 → 403。
        $validator = new MessageValidator($this->certClient());
        try {
            $validator->validate($message);
        } catch (InvalidSnsMessageException $e) {
            throw new SnsSignatureInvalidException('signature mismatch', 0, $e);
        }
    }

    /**
     * MessageValidator に渡す証明書取得 callable。
     * 取得失敗 (ネットワーク / HTTP エラー) は一時障害として SnsVerificationUnavailableException に。
     *
     * @return callable(string): string
     */
    private function certClient(): callable
    {
        return function (string $url): string {
            try {
                return $this->http
                    ->connectTimeout(5)
                    ->timeout(10)
                    ->withoutRedirecting()
                    ->get($url)
                    ->throw()
                    ->body();
            } catch (\Throwable $e) {
                throw new SnsVerificationUnavailableException('certificate fetch failed', 0, $e);
            }
        };
    }

    private function certUrl(Message $message): string
    {
        // SDK バージョン差で大文字小文字が揺れるため両対応。
        $url = $message['SigningCertURL'] ?? $message['SigningCertUrl'] ?? '';

        return is_string($url) ? $url : '';
    }

    /**
     * SNS 証明書 URL の厳格検証:
     *  - scheme は https 固定
     *  - port 未指定 or 443
     *  - query / fragment を持たない
     *  - host は `sns.{region}.amazonaws.com` (`sns.` prefix 必須、region セグメントあり)
     *  - path は `/SimpleNotificationService-*.pem`
     *
     * China partition (amazonaws.com.cn) は対象外。利用予定が出たら allowlist を明示拡張する。
     */
    private function isValidSnsCertUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }
        if (($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (($parts['port'] ?? 443) !== 443) {
            return false;
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        $host = $parts['host'] ?? '';
        if (preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com$/', $host) !== 1) {
            return false;
        }
        $path = $parts['path'] ?? '';

        return preg_match('#^/SimpleNotificationService-[A-Za-z0-9]+\.pem$#', $path) === 1;
    }
}
