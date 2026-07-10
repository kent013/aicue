<?php

declare(strict_types=1);

namespace App\Support\OAuth;

use DateInterval;

/**
 * CLI public client の per-client token TTL。
 *
 * `Passport::tokensExpireIn` の global 設定は使わない (global 波及で MCP 経路の TTL を
 * 壊すため)。grant は CLI 経路 (session_id 継承あり) を解決した後、本ヘルパの
 * DateInterval を **ローカル変数として** 都度算出して league の発行点に渡す。
 * grant の mutable プロパティには残さない (Octane / 長寿命プロセスで前リクエストの
 * TTL が残留しないように)。
 *
 * 値は config('template.cli_oauth.*') 駆動。設定ミス (負値・極端な値・非数値) で
 * 「無期限 CLI token」等の事故を作らないよう、安全域に clamp する。
 */
final class CliTokenTtl
{
    /** access token TTL の既定値 (分)。 */
    private const ACCESS_DEFAULT_MINUTES = 60;

    /** access token TTL の下限/上限 (分)。上限 24 時間。 */
    private const ACCESS_MIN_MINUTES = 5;

    private const ACCESS_MAX_MINUTES = 1440;

    /** refresh token TTL の既定値 (日)。 */
    private const REFRESH_DEFAULT_DAYS = 30;

    /** refresh token TTL の下限/上限 (日)。上限は Passport global refresh (90 日) と同値。 */
    private const REFRESH_MIN_DAYS = 1;

    private const REFRESH_MAX_DAYS = 90;

    /** CLI access token TTL (既定 1 時間)。 */
    public static function accessTokenTtl(): DateInterval
    {
        $minutes = self::clampedConfig(
            'template.cli_oauth.access_ttl_minutes',
            self::ACCESS_DEFAULT_MINUTES,
            self::ACCESS_MIN_MINUTES,
            self::ACCESS_MAX_MINUTES,
        );

        return new DateInterval("PT{$minutes}M");
    }

    /** CLI refresh token TTL (既定 30 日)。 */
    public static function refreshTokenTtl(): DateInterval
    {
        $days = self::clampedConfig(
            'template.cli_oauth.refresh_ttl_days',
            self::REFRESH_DEFAULT_DAYS,
            self::REFRESH_MIN_DAYS,
            self::REFRESH_MAX_DAYS,
        );

        return new DateInterval("P{$days}D");
    }

    /**
     * config 値を int 化し [min, max] に clamp する。非数値は default に落とす。
     */
    private static function clampedConfig(string $key, int $default, int $min, int $max): int
    {
        $raw = config($key, $default);
        $value = is_numeric($raw) ? (int) $raw : $default;

        return max($min, min($max, $value));
    }
}
