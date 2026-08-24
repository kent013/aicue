<?php

declare(strict_types=1);

namespace App\ValueObjects\EnterpriseSso;

use App\Enums\EnterpriseSso\RejectionReason;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;

/**
 * issuer の値オブジェクト。**型で規則を担保する** (呼び出し側の作法に頼らない)。
 *
 * 規則: https のみ / userinfo なし / query なし / fragment なし / 絶対 URL / 長さ上限。
 *
 * ★**末尾のスラッシュを正規化しない**。OIDC の issuer は**識別子であって URL の
 *   正規化対象ではない** — `https://idp.example/tenant` と `https://idp.example/tenant/` は
 *   **別の issuer** になりうる。登録した文字列をそのまま保ち、discovery 文書の issuer と
 *   仕様どおり完全一致させる。
 *
 * ★well-known の URL は「issuer のパスの**後ろに**」付ける
 *   (`https://idp.example/tenant` → `https://idp.example/tenant/.well-known/openid-configuration`)。
 *
 * ★`config/ssrf-pin.php` は http も許している (他用途のため) が、
 *   **企業 OIDC 自身の入力規則として https を必須化する** — でなければ
 *   client secret・認可コード・トークンが平文で流れる。
 */
final readonly class OidcIssuerUrl
{
    /** DB の `issuer` 列 (varchar 255) と対。 */
    public const int MAX_LENGTH = 255;

    private function __construct(public string $value) {}

    /**
     * @throws EnterpriseSsoAttemptRejectedException 規則に合わない文字列
     */
    public static function fromString(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryInvalidEndpoint);
        }

        if (! self::isValid($value)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryInvalidEndpoint);
        }

        return new self($value);
    }

    /** 規則を満たすか (FormRequest の検査でも使う。例外を投げない述語)。 */
    public static function isValid(string $value): bool
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        $parts = parse_url($value);
        if (! is_array($parts)) {
            return false;
        }

        if (($parts['scheme'] ?? null) !== 'https') {
            return false;
        }

        if (($parts['host'] ?? '') === '') {
            return false;
        }

        // userinfo (`https://user:pass@host/`) は詐称の温床なので許さない。
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        // issuer は識別子である。query と fragment を持たせない。
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }

        return true;
    }

    /**
     * discovery 文書の URL。
     *
     * ★issuer の**パスの後ろ**に足す (host の直下ではない)。
     *   末尾のスラッシュは重ねない (issuer 自体は正規化しない)。
     */
    public function wellKnownUrl(): string
    {
        return rtrim($this->value, '/').'/.well-known/openid-configuration';
    }

    /** キャッシュキーに使う指紋 (**URL の平文をキーに残さない**)。 */
    public function cacheDigest(): string
    {
        return hash('sha256', $this->value);
    }
}
