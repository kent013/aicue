<?php

declare(strict_types=1);

namespace App\Security;

/**
 * recent-auth (step-up 再認証) の鮮度ウィンドウ判定。
 *
 * session の `recent_auth_at` (unix timestamp / int) が
 * `config('auth.recent_auth_timeout')` 秒以内かを判定する **単一ソース**。
 * RequireRecentAuth middleware と recent-auth status endpoint の双方がこれを
 * 参照することで、強制点 (middleware) と UI 補助 (status) の判定ドリフトを防ぐ。
 *
 * 境界は `(now - confirmedAt) <= timeout` を fresh とする。解釈不能・未設定・
 * timeout<=0 は false (= 常に未確認扱い、同秒内 true を避ける)。
 */
final class RecentAuthWindow
{
    /**
     * session 値 (mixed) が timeout 秒以内 (fresh) か。
     */
    public static function isFresh(mixed $recentAuthAt, ?int $timeoutSeconds = null): bool
    {
        if (! is_int($recentAuthAt)) {
            return false;
        }

        $timeout = $timeoutSeconds ?? self::configuredTimeout();
        if ($timeout <= 0) {
            return false;
        }

        $elapsed = time() - $recentAuthAt;

        // 未来 timestamp (clock skew / 改竄) は fresh 扱いしない。
        if ($elapsed < 0) {
            return false;
        }

        return $elapsed <= $timeout;
    }

    public static function configuredTimeout(): int
    {
        /** @var mixed $raw */
        $raw = config('auth.recent_auth_timeout', 900);

        return is_numeric($raw) ? (int) $raw : 900;
    }
}
