<?php

declare(strict_types=1);

namespace App\Support;

/**
 * `TRUSTED_PROXIES` の 1 token の妥当性判定 (config 段と validator 段で共有する純粋クラス)。
 *
 * 判定をここに一本化するのは、config 段の filter と起動時 validator が別ロジックだと
 * 「config では落ちるのに validator は通す (= silent drop)」「その逆 (= 誤 reject)」の
 * ズレが生まれるため。正規表現による緩い判定 (`999.999.999.999/999` を通す) は使わず、
 * IP 部は `filter_var(FILTER_VALIDATE_IP)`、prefix 長は数値範囲で検証する。
 */
final class TrustedProxyToken
{
    /** 「プロキシは無い」の明示宣言 (空 list に写す sentinel)。 */
    public const string NONE = 'none';

    /** 直接の接続元を信頼する予約値 (framework が REMOTE_ADDR に展開。production では禁止)。 */
    public const string REMOTE_ADDR = 'REMOTE_ADDR';

    /**
     * 「全アドレス信頼」と等価な宣言か。
     *
     * `*` / `**` だけでなく **prefix 長 0 の CIDR** (`0.0.0.0/0` / `::/0`) も
     * 全アドレスを含むため同値である。書式としては正当な CIDR なので素朴な
     * 書式検査だけでは通り抜け、`*` を禁止した意味が消える (impl-review R1 Critical)。
     */
    public static function isAllAddresses(string $token): bool
    {
        if ($token === '*' || $token === '**') {
            return true;
        }
        if (! self::isCidr($token)) {
            return false;
        }

        return (int) explode('/', $token)[1] === 0;
    }

    /**
     * framework に渡してよい値か (単一 IP / CIDR / REMOTE_ADDR)。
     *
     * 全アドレス等価の宣言は **どの環境でも** framework に渡さない (fail-secure)。
     * production での明示的な reject 理由は TrustedProxiesConfigValidator が出す。
     */
    public static function isTrustableAddress(string $token): bool
    {
        if (self::isAllAddresses($token)) {
            return false;
        }
        if ($token === self::REMOTE_ADDR) {
            return true;
        }
        if (filter_var($token, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return self::isCidr($token);
    }

    /** CIDR 書式か (IP 部は FILTER_VALIDATE_IP、prefix は IPv4 0-32 / IPv6 0-128)。 */
    public static function isCidr(string $token): bool
    {
        $parts = explode('/', $token);
        if (count($parts) !== 2) {
            return false;
        }
        [$address, $prefix] = $parts;
        if ($prefix === '' || ctype_digit($prefix) === false) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return (int) $prefix <= 32;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return (int) $prefix <= 128;
        }

        return false;
    }
}
